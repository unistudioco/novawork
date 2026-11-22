<?php
/**
 * Per-member notifications.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.

namespace NovaWork\Data;

defined( 'ABSPATH' ) || exit;

/**
 * One row per recipient. The client writes the sentence from the type, the
 * object and the small data snapshot, so it stays translatable and current.
 *
 * The dedupe_key column keeps repeat checks (cron and sync both look for due dates)
 * from notifying twice: it is UNIQUE per recipient.
 */
final class NotificationRepository extends Repository {

	public const TYPES = array( 'assign', 'comment', 'chat', 'due', 'decision', 'vote', 'mention', 'link' );

	/**
	 * Table name without prefix.
	 */
	protected function table(): string {
		return 'notifications';
	}

	/**
	 * Object type.
	 */
	public function type(): string {
		return 'notification';
	}

	/**
	 * Only the recipient sees the change.
	 *
	 * @param array<string, mixed> $row Database row.
	 */
	protected function scope( array $row ): int {
		return (int) $row['user_id'];
	}

	/**
	 * Column formats.
	 *
	 * @return array<string, string>
	 */
	protected function formats(): array {
		return array(
			'user_id'     => '%d',
			'type'        => '%s',
			'actor_id'    => '%d',
			'agent'       => '%s',
			'object_type' => '%s',
			'object_id'   => '%d',
			'data'        => '%s',
			'dedupe_key'  => '%s',
			'created_at'  => '%s',
			'read_at'     => '%s',
		);
	}

	/**
	 * Defaults for a new row.
	 *
	 * @return array<string, mixed>
	 */
	protected function defaults(): array {
		return array(
			'actor_id'    => 0,
			'agent'       => '',
			'object_type' => '',
			'object_id'   => 0,
			'data'        => '{}',
		);
	}

	/**
	 * API shape.
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return array<string, mixed>
	 */
	public function present( array $row ): array {
		$data = json_decode( (string) $row['data'], true );
		return array(
			'id'         => (int) $row['id'],
			'type'       => (string) $row['type'],
			'at'         => Db::to_ms( $row['created_at'] ),
			'read'       => null !== $row['read_at'],
			'actor'      => (int) $row['actor_id'],
			'agent'      => (string) $row['agent'],
			'objectType' => (string) $row['object_type'],
			'objectId'   => (int) $row['object_id'],
			'data'       => is_array( $data ) ? $data : array(),
		);
	}

	/**
	 * Notify a member once per dedupe key.
	 *
	 * @param array<string, mixed> $data Column values, including user_id and dedupe_key.
	 * @return int New id, or 0 when it already existed.
	 */
	public function insert_once( array $data ): int {
		global $wpdb;
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE user_id = %d AND dedupe_key = %s',
				$this->table_name(),
				(int) $data['user_id'],
				(string) $data['dedupe_key']
			)
		);
		if ( $exists ) {
			return 0;
		}
		// The UNIQUE index settles a race between cron and a poll; a lost race is not an error.
		$suppress = $wpdb->suppress_errors( true );
		$id       = $this->insert( $data );
		$wpdb->suppress_errors( $suppress );
		return $id;
	}

	/**
	 * A member's newest notifications.
	 *
	 * @param int $user_id Member.
	 * @param int $limit   Most rows.
	 * @return list<array<string, mixed>>
	 */
	public function recent_for( int $user_id, int $limit ): array {
		global $wpdb;
		return array_reverse(
			self::rows(
				$wpdb->get_results(
					$wpdb->prepare( 'SELECT * FROM %i WHERE user_id = %d ORDER BY id DESC LIMIT %d', $this->table_name(), $user_id, $limit ),
					ARRAY_A
				)
			)
		);
	}

	/**
	 * Mark a member's notifications read.
	 *
	 * @param int         $user_id Member.
	 * @param int[]|null  $ids     These ids, or null for all.
	 * @param string|null $type    Only this type (e.g. "chat").
	 * @return int How many changed.
	 */
	public function mark_read( int $user_id, ?array $ids = null, ?string $type = null ): int {
		global $wpdb;
		$unread  = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM %i WHERE user_id = %d AND read_at IS NULL AND ( %s = '' OR type = %s )",
				$this->table_name(),
				$user_id,
				(string) $type,
				(string) $type
			)
		);
		$targets = self::ids( (array) $unread );
		if ( null !== $ids ) {
			$targets = array_values( array_intersect( $targets, self::ids( $ids ) ) );
		}
		$now = Db::now();
		foreach ( $targets as $id ) {
			$this->update( $id, array( 'read_at' => $now ) );
		}
		return count( $targets );
	}

	/**
	 * Delete every notification about one object (a deleted chat message).
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object id.
	 */
	public function delete_for_object( string $object_type, int $object_id ): void {
		global $wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare( 'SELECT id FROM %i WHERE object_type = %s AND object_id = %d', $this->table_name(), $object_type, $object_id )
		);
		foreach ( self::ids( (array) $ids ) as $id ) {
			$this->delete( $id );
		}
	}

	/**
	 * Delete read notifications older than a number of days. Unread ones stay,
	 * and so do their dedupe keys, so a long-late task is never re-announced.
	 *
	 * @param int $days Age in days.
	 */
	public function prune_read( int $days ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE read_at IS NOT NULL AND created_at < %s',
				$this->table_name(),
				gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS )
			)
		);
	}
}
