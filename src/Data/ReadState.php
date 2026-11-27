<?php
/**
 * Per-member read position in a channel.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.

namespace NovaWork\Data;

defined( 'ABSPATH' ) || exit;

/**
 * How far each member has read the team chat. Sync reports the unread count
 * on every poll, so this table is not in the change log.
 */
final class ReadState {

	/**
	 * Last message id a member has read in a channel.
	 *
	 * @param int    $user_id Member.
	 * @param string $channel Channel key, e.g. "chat".
	 */
	public static function get( int $user_id, string $channel = 'chat' ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT last_read_id FROM %i WHERE user_id = %d AND channel = %s', Db::table( 'reads' ), $user_id, $channel )
		);
	}

	/**
	 * Move a member's read position forward (never back).
	 *
	 * @param int    $user_id Member.
	 * @param int    $last_id Last message id read.
	 * @param string $channel Channel key.
	 */
	public static function set( int $user_id, int $last_id, string $channel = 'chat' ): void {
		global $wpdb;
		$before = self::get( $user_id, $channel );
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i ( user_id, channel, last_read_id, updated_at ) VALUES ( %d, %s, %d, %s ) ON DUPLICATE KEY UPDATE last_read_id = GREATEST( last_read_id, VALUES( last_read_id ) ), updated_at = VALUES( updated_at )',
				Db::table( 'reads' ),
				$user_id,
				$channel,
				$last_id,
				Db::now()
			)
		);
		if ( $last_id > $before ) {
			/**
			 * Fires when a member's read position moves forward: they opened
			 * the chat, read new messages, or posted one.
			 *
			 * @since 1.3.0
			 *
			 * @param int    $user_id The member.
			 * @param int    $last_id The last message id they have read now.
			 * @param int    $before  The last one they had read before (0 for none).
			 * @param string $channel The channel ("chat").
			 */
			do_action( 'novawork_read_moved', $user_id, $last_id, $before, $channel );
		}
	}
}
