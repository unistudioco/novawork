<?php
/**
 * The change log that drives sync.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; the log is read once per poll and must never be stale.

namespace NovaWork\Data;

/**
 * Every write appends one row here. Clients poll with the last id they saw
 * and get back what changed since, so the log id is the sync cursor.
 *
 * A row with user_id NULL is visible to every member; a row with a user_id
 * (notifications, read state) only to that member.
 */
final class ChangeLog {

	/**
	 * Record that an object changed.
	 *
	 * @param string   $type   Object type, e.g. "task".
	 * @param int      $id     Object id.
	 * @param string   $action "upsert" or "delete".
	 * @param int|null $user   Only this member sees the change; null for everyone.
	 */
	public static function record( string $type, int $id, string $action = 'upsert', ?int $user = null ): void {
		global $wpdb;
		$wpdb->insert(
			Db::table( 'changes' ),
			array(
				'object_type' => $type,
				'object_id'   => $id,
				'action'      => $action,
				'user_id'     => $user,
				'created_at'  => Db::now(),
			),
			array( '%s', '%d', '%s', '%d', '%s' )
		);
	}

	/**
	 * The newest change id, the cursor a full snapshot starts from.
	 */
	public static function latest_id(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(id) FROM %i', Db::table( 'changes' ) ) );
	}

	/**
	 * Option holding the highest change id ever pruned.
	 */
	public const FLOOR_OPTION = 'novawork_changes_floor';

	/**
	 * The highest change id pruned so far. A cursor below it may have missed
	 * rows, so it needs a full snapshot; any cursor at or above it is safe.
	 */
	public static function floor(): int {
		return (int) get_option( self::FLOOR_OPTION, 0 );
	}

	/**
	 * Changes in ( $since, $until ] that a member may see, oldest first.
	 *
	 * The caller reads latest_id() first and passes it as $until, so a write
	 * that lands mid-request is picked up by the next poll instead of being
	 * skipped by a cursor that already moved past it.
	 *
	 * @param int $since Last change id the client has.
	 * @param int $until Upper bound, inclusive.
	 * @param int $user  The member asking.
	 * @param int $limit Most rows to return.
	 * @return list<array{id:int,type:string,object_id:int,action:string}>
	 */
	public static function since( int $since, int $until, int $user, int $limit ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, object_type, object_id, action FROM %i WHERE id > %d AND id <= %d AND ( user_id IS NULL OR user_id = %d ) ORDER BY id ASC LIMIT %d',
				Db::table( 'changes' ),
				$since,
				$until,
				$user,
				$limit
			),
			ARRAY_A
		);
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'id'        => (int) $row['id'],
				'type'      => (string) $row['object_type'],
				'object_id' => (int) $row['object_id'],
				'action'    => (string) $row['action'],
			);
		}
		return $out;
	}

	/**
	 * Delete log rows older than a number of days.
	 *
	 * Clients whose cursor falls before the oldest kept row get a full
	 * snapshot instead, so pruning never loses data.
	 *
	 * @param int $days Age in days.
	 */
	public static function prune( int $days ): void {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$last   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(id) FROM %i WHERE created_at < %s', Db::table( 'changes' ), $cutoff ) );
		if ( 0 === $last ) {
			return;
		}
		// Raise the floor first: a poll between the two steps then gets a
		// full snapshot rather than a gap.
		update_option( self::FLOOR_OPTION, max( self::floor(), $last ), false );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id <= %d', Db::table( 'changes' ), $last ) );
	}
}
