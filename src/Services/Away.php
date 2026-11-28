<?php
/**
 * "Away until…": a member's holiday or days off.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

namespace NovaWork\Services;

use NovaWork\Access\Capabilities;
use NovaWork\Data\Db;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * A member is away until a date (inclusive, in the site's time zone), with
 * an optional short note ("Holiday in Lisbon"). It ends by itself after that
 * day. Each member sets their own; administrators can set anyone's (owner,
 * 2026-11-28). The app shows it on the avatar and in Team today, and add-ons
 * read it from each member's "away" (1.3.0).
 */
final class Away {

	/**
	 * User meta: { until: Y-m-d, note: string, by: user id, at: epoch seconds }.
	 */
	public const META = 'novawork_away';

	/**
	 * The note's length at most.
	 */
	public const NOTE_MAX = 80;

	/**
	 * How far ahead "until" may be, in days.
	 */
	public const MAX_DAYS = 366;

	/**
	 * A member's away status, or null when they aren't away (or it ended).
	 *
	 * @param int $user_id Member.
	 * @return array{until: string, note: string}|null
	 */
	public static function get( int $user_id ): ?array {
		$saved = get_user_meta( $user_id, self::META, true );
		if ( ! is_array( $saved ) || ! Db::is_date( (string) ( $saved['until'] ?? '' ) ) || (string) $saved['until'] < wp_date( 'Y-m-d' ) ) {
			return null;
		}
		return array(
			'until' => (string) $saved['until'],
			'note'  => (string) ( $saved['note'] ?? '' ),
		);
	}

	/**
	 * Set a member away until a date.
	 *
	 * @param int    $user_id Member.
	 * @param string $until   Last day away (Y-m-d), today or later.
	 * @param string $note    Optional note.
	 * @param int    $by      Who set it.
	 * @return array{until: string, note: string}|WP_Error
	 */
	public static function set( int $user_id, string $until, string $note, int $by ): array|WP_Error {
		if ( ! Capabilities::is_member( $user_id ) ) {
			return new WP_Error( 'novawork_not_member', __( 'That person isn’t a member of the workspace.', 'novawork' ), array( 'status' => 400 ) );
		}
		$today = wp_date( 'Y-m-d' );
		$last  = wp_date( 'Y-m-d', time() + self::MAX_DAYS * DAY_IN_SECONDS );
		if ( ! Db::is_date( $until ) || $until < $today || $until > $last ) {
			return new WP_Error( 'novawork_bad_away', __( 'Pick a day from today to a year from now.', 'novawork' ), array( 'status' => 400 ) );
		}
		$note = sanitize_text_field( $note );
		if ( mb_strlen( $note ) > self::NOTE_MAX ) {
			return new WP_Error( 'novawork_too_long', sprintf( /* translators: %d: a number of characters. */ __( 'The note can be %d characters at most.', 'novawork' ), self::NOTE_MAX ), array( 'status' => 400 ) );
		}
		$before = self::get( $user_id );
		update_user_meta(
			$user_id,
			self::META,
			array(
				'until' => $until,
				'note'  => $note,
				'by'    => $by,
				'at'    => time(),
			)
		);
		$away = (array) self::get( $user_id );
		self::changed( $user_id, $away, $before, $by );
		return $away;
	}

	/**
	 * End a member's away status ("I'm back").
	 *
	 * @param int $user_id Member.
	 * @param int $by      Who ended it.
	 */
	public static function clear( int $user_id, int $by ): void {
		$before = self::get( $user_id );
		delete_user_meta( $user_id, self::META );
		if ( null !== $before ) {
			self::changed( $user_id, null, $before, $by );
		}
	}

	/**
	 * Tell add-ons.
	 *
	 * @param int                                     $user_id Member.
	 * @param array{until: string, note: string}|null $away    Now (null: back).
	 * @param array{until: string, note: string}|null $before  Before.
	 * @param int                                     $by      Who changed it.
	 */
	private static function changed( int $user_id, ?array $away, ?array $before, int $by ): void {
		/**
		 * Fires when a member's "Away until…" status is set, changed or ended.
		 *
		 * @since 1.3.0
		 *
		 * @param int        $user_id The member.
		 * @param array|null $away    { until: Y-m-d, note } now, or null when they are back.
		 * @param array|null $before  The same before the change.
		 * @param int        $by      Who changed it (the member, or an administrator).
		 */
		do_action( 'novawork_away_changed', $user_id, $away, $before, $by );
	}
}
