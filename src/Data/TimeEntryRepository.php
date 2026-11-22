<?php
/**
 * Tracked time.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.

namespace NovaWork\Data;

defined( 'ABSPATH' ) || exit;

/**
 * A running timer is an entry with no end. Its running_user column holds the
 * member's id while it runs and NULL afterwards; a UNIQUE index on that column
 * makes "one running timer per member" a database guarantee, not a hope.
 */
final class TimeEntryRepository extends Repository {

	/**
	 * Table name without prefix.
	 */
	protected function table(): string {
		return 'time_entries';
	}

	/**
	 * Object type.
	 */
	public function type(): string {
		return 'time_entry';
	}

	/**
	 * Column formats.
	 *
	 * @return array<string, string>
	 */
	protected function formats(): array {
		return array(
			'user_id'      => '%d',
			'task_id'      => '%d',
			'started_at'   => '%s',
			'ended_at'     => '%s',
			'running_user' => '%d',
			'created_at'   => '%s',
			'updated_at'   => '%s',
		);
	}

	/**
	 * API shape.
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return array<string, mixed>
	 */
	public function present( array $row ): array {
		return array(
			'id'      => (int) $row['id'],
			'by'      => (int) $row['user_id'],
			'taskId'  => (int) $row['task_id'],
			'start'   => Db::to_ms( $row['started_at'] ),
			'end'     => Db::to_ms( $row['ended_at'] ),
			'running' => null === $row['ended_at'],
		);
	}

	/**
	 * A member's running entry.
	 *
	 * @param int $user_id Member.
	 * @return array<string, mixed>|null
	 */
	public function running_for( int $user_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE running_user = %d', $this->table_name(), $user_id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Every running entry.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function running(): array {
		global $wpdb;
		return self::rows( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE running_user IS NOT NULL', $this->table_name() ), ARRAY_A ) );
	}

	/**
	 * Entries that overlap a time range, optionally for one member.
	 *
	 * @param string   $from    UTC DATETIME, inclusive.
	 * @param string   $to      UTC DATETIME, exclusive.
	 * @param int|null $user_id Only this member, or null for everyone.
	 * @return list<array<string, mixed>>
	 */
	public function overlapping( string $from, string $to, ?int $user_id = null ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE started_at < %s AND ( ended_at IS NULL OR ended_at > %s ) AND ( %d = 0 OR user_id = %d ) ORDER BY started_at ASC, id ASC',
				$this->table_name(),
				$to,
				$from,
				$user_id ?? 0,
				$user_id ?? 0
			),
			ARRAY_A
		);
		return self::rows( $rows );
	}

	/**
	 * Total finished seconds for each of some tasks.
	 *
	 * @param int[] $task_ids Task ids.
	 * @return array<int, int>
	 */
	public function seconds_by_task( array $task_ids ): array {
		global $wpdb;
		$task_ids = self::ids( $task_ids );
		if ( ! $task_ids ) {
			return array();
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT task_id, SUM( TIMESTAMPDIFF( SECOND, started_at, ended_at ) ) AS seconds FROM %i WHERE ended_at IS NOT NULL AND task_id IN (' . implode( ',', array_fill( 0, count( $task_ids ), '%d' ) ) . ') GROUP BY task_id',
				array_merge( array( $this->table_name() ), $task_ids )
			),
			ARRAY_A
		);
		$out  = array();
		foreach ( self::rows( $rows ) as $row ) {
			$out[ (int) $row['task_id'] ] = (int) $row['seconds'];
		}
		return $out;
	}
}
