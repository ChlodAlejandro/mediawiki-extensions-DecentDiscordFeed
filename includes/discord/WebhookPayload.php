<?php
/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 *
 * @file
 */

namespace MediaWiki\Extension\DecentDiscordFeed\Discord;

use MediaWiki\Extension\DecentDiscordFeed\Utils;
use MediaWiki\MediaWikiServices;
use RecentChange;
use Title;

class WebhookPayload {

	/** @var string */
	private $content = null;
	/** @var \MediaWiki\Extension\DecentDiscordFeed\Discord\Embed[] */
	private $embeds;

	/**
	 * @param \RecentChange $rc
	 * @return \MediaWiki\Extension\DecentDiscordFeed\Discord\WebhookPayload
	 */
	public static function recentChangeToPayload( RecentChange $rc ): WebhookPayload {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'decentdiscordfeed' );

		$payload = new WebhookPayload();
		$embed = new Embed();

		$page = $rc->getPage();
		$title = Title::newFromPageReference( $page );
		$titleString = $title->getPrefixedText();
		$titleUrl = $title->getFullUrl();
		$comment = empty( $rc->getAttribute( 'rc_comment_text' ) )
			? $rc->mExtra[ 'actionComment' ] ?? ''
			: $rc->getAttribute( 'rc_comment_text' );

		if ( $rc->getAttribute( 'rc_type' ) === RC_LOG ) {
			$id = $rc->getAttribute( 'rc_logid' );
			$logType = $rc->getAttribute( 'rc_log_type' );
			$logAction = $rc->getAttribute( 'rc_log_action' );
			$logUrl = Title::newFromText( "Special:Redirect/logid/$id" )->getFullUrl();

			$embed
				->setColor( $config->get( 'DecentDiscordFeedLogColor' ) )
				->setAuthorIconUrl( $config->get( 'DecentDiscordFeedLogIcon' ) )
				->setAuthor( $titleString )
				->setAuthorUrl( $titleUrl )
				->setDescription(
					"([log]($logUrl)) . . ("
					. ( $logType == $logAction ? $logType : "$logType . . $logAction" )
					. ") . . "
					. Utils::getUserLinkMarkdown( $rc->getAttribute( 'rc_user_text' ) )
					. ( !empty( $comment ) ? " . . (*"
						. Utils::wikitextToMarkdown( $rc, $comment )
						. "*)" : '' )
				)
				->setFooterText( date(
					'l, F j, Y g:i A',
					strtotime( $rc->getAttribute( 'rc_timestamp' ) )
				) );

			$rawData = $rc->parseParams();
			$data = [];
			foreach ( $rawData as $key => $value ) {
				$data[ preg_replace( '/^[0-9]+:+/', '', $key ) ] = is_array( $value ) ?
					json_encode( $value ) : "$value";
			}

			if ( $config->get( 'DecentDiscordFeedShowLogParameters' ) ) {
				if ( $logType == "move" ) {
					$embed
						->addField( new EmbedField( 'Target', Utils::codeBlock(
							$data["target"]
						), true ) )
						->addField( new EmbedField( 'Redirect?', Utils::codeBlock(
							$data["noredir"] == 0 ? "Yes" : "No"
						), true ) );
				} elseif ( Utils::isArray( $data ) && count( $data ) > 0 ) {
					$embed
						->addField( new EmbedField( 'Parameters', Utils::codeBlock(
							implode( "\n", $data )
						), true ) );
				} elseif ( is_array( $data ) && !Utils::isArray( $data ) ) {
					$embed
						->addField( new EmbedField( 'Parameters', Utils::codeBlock(
							json_encode( $data, JSON_PRETTY_PRINT )
						), true ) );
				} elseif ( is_string( $data ) || is_numeric( $data ) || is_bool( $data ) ) {
					$embed
						->addField( new EmbedField( 'Parameters', Utils::codeBlock(
							$data
						), true ) );
				}
			}
		} elseif (
			$rc->getAttribute( 'rc_type' ) === RC_EDIT
			|| $rc->getAttribute( 'rc_type' ) === RC_NEW
		) {
			$newId = $rc->getAttribute( 'rc_this_oldid' );
			$oldId = $rc->getAttribute( 'rc_last_oldid' );
			$diffUrl = Title::newFromText( "Special:Diff/$oldId/$newId" )->getFullUrl();
			$histUrl = Title::newFromText( "Special:PageHistory/$title" )->getFullUrl();

			$byteDiff = ( $rc->getAttribute( 'rc_new_len' ) ?? 0 )
				- ( $rc->getAttribute( 'rc_old_len' ) ?? 0 );
			if ( $byteDiff > 0 ) {
				$diffType = "Add";
			} elseif ( $byteDiff < 0 ) {
				$diffType = "Remove";
			} else {
				$diffType = "Neutral";
			}
			$byteDiffText = abs( $byteDiff ) > 500
				? "**" . ( $byteDiff > 0 ? "+$byteDiff" : $byteDiff ) . "**"
				: ( $byteDiff > 0 ? "+$byteDiff" : $byteDiff );

			$diffText = !empty( $oldId ) ? 'diff' : '**new**';

			$markdownSummary = Utils::wikitextToMarkdown( $rc, $comment );
			$embed
				->setColor( $config->get( "DecentDiscordFeedEdit{$diffType}Color" ) )
				->setAuthorIconUrl( $config->get( "DecentDiscordFeedEdit{$diffType}Icon" ) )
				->setAuthor( $titleString )
				->setAuthorUrl( $titleUrl )
				->setDescription(
					"([$diffText]($diffUrl) | [hist]($histUrl)) . . ($byteDiffText) . . "
					. Utils::getUserLinkMarkdown( $rc->getAttribute( 'rc_user_text' ) )
					. ( !empty( $comment ) ? " . . (*"
						. ( empty( $markdownSummary ) ? "No summary." : $markdownSummary )
						. "*)" : '' )
				)
				->setFooterText( date(
					'l, F j, Y g:i A',
					strtotime( $rc->getAttribute( 'rc_timestamp' ) )
				) );
		}

		$payload->addEmbed( $embed );
		$payload->setContent( null );
		return $payload;
	}

	/**
	 * @return string
	 */
	public function getContent(): string {
		return $this->content;
	}

	/**
	 * @param ?string $content
	 * @return WebhookPayload
	 */
	public function setContent( ?string $content ): WebhookPayload {
		$this->content = $content;

		return $this;
	}

	/**
	 * @param \MediaWiki\Extension\DecentDiscordFeed\Discord\Embed $embed
	 * @return $this
	 */
	public function addEmbed( Embed $embed ): WebhookPayload {
		if ( !isset( $this->embeds ) ) {
			$this->embeds = [];
		}
		$this->embeds[] = $embed;

		return $this;
	}

	/**
	 * Returns the embed fields as an associative array, to later be encoded in JSON.
	 * @return array
	 */
	public function toArray(): array {
		return array_filter( [
			'content' => $this->content,
			'embeds' => $this->embeds != null ? array_map( static function ( Embed $field ) {
				return $field->toArray();
			}, $this->embeds ) : null
		], static function ( $v, $k ) {
			return $k == 'content' || !empty( $v );
		}, ARRAY_FILTER_USE_BOTH );
	}

}
