<?php
/**
 * Base class for a table with an id column.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables; sync reads must never be served stale from an object cache.

namespace NovaWork\Data;

/**
 * Reads and writes one table, and records every write in the change log so
 * clients pick it up on their next poll.
 *
 * Each subclass says which columns it has (and their wpdb formats) and how a
 * row is presented to the API. All SQL in NovaWork goes through
 * $wpdb->prepare() or the $wpdb insert/update/delete helpers.
 */
abstract class Repository {

	/**
	 * Gap between neighbours when ordering, so most moves touch one row.
	 */
	public const ORDER_STEP = 1024;

	/**
	 * Table name without prefix.
	 */
	abstract protected function table(): string;

	/**
	 * Object type in the change log and the sync payload.
	 */
	abstract public function type(): string;

	/**
	 * Writable columns and their wpdb formats, without "id".
	 *
	 * @return array<string, string>
	 */
	abstract protected function formats(): array;

	/**
	 * A database row as the API presents it.
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return array<string, mixed>
	 */
	abstract public function present( array $row ): array;

	/**
	 * Values a new row starts with (TEXT columns have no SQL default).
	 *
	 * @return array<string, mixed>
	 */
	protected function defaults(): array {
		return array();
	}

	/**
	 * Which member a change is visible to; null means every member.
	 *
	 * @param array<string, mixed> $row Database row.
	 */
	protected function scope( array $row ): ?int {
		unset( $row );
		return null;
	}

	/**
	 * Whether writes go to the change log.
	 */
	protected function logged(): bool {
		return true;
	}

	/**
	 * Present several rows. Subclasses override this to batch related lookups.
	 *
	 * @param list<array<string, mixed>> $rows Database rows.
	 * @return list<array<string, mixed>>
	 */
	public function present_many( array $rows ): array {
		$out = array();
		foreach ( $rows as $row ) {
			$out[] = $this->present( $row );
		}
		return $out;
	}

	/**
	 * Full table name.
	 */
	public function table_name(): string {
		return Db::table( $this->table() );
	}

	/**
	 * One row by id.
	 *
	 * @param int $id Row id.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;
		if ( $id <= 0 ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table_name(), $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Several rows by id, in id order.
	 *
	 * @param int[] $ids Row ids.
	 * @return list<array<string, mixed>>
	 */
	public function find_many( array $ids ): array {
		global $wpdb;
		$ids = self::ids( $ids );
		if ( ! $ids ) {
			return array();
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') ORDER BY id ASC',
				array_merge( array( $this->table_name() ), $ids )
			),
			ARRAY_A
		);
		return self::rows( $rows );
	}

	/**
	 * Insert a row and log it.
	 *
	 * @param array<string, mixed> $data Column values.
	 * @return int New row id, or 0 on failure.
	 */
	public function insert( array $data ): int {
		global $wpdb;
		$formats = $this->formats();
		$now     = Db::now();
		$data    = array_merge( $this->defaults(), $data );
		if ( isset( $formats['created_at'] ) && ! isset( $data['created_at'] ) ) {
			$data['created_at'] = $now;
		}
		if ( isset( $formats['updated_at'] ) && ! isset( $data['updated_at'] ) ) {
			$data['updated_at'] = $now;
		}
		list( $values, $fmt ) = $this->only_columns( $data );
		if ( ! $wpdb->insert( $this->table_name(), $values, $fmt ) ) {
			return 0;
		}
		$id = (int) $wpdb->insert_id;
		if ( $this->logged() ) {
			ChangeLog::record( $this->type(), $id, 'upsert', $this->scope( $values ) );
		}
		return $id;
	}

	/**
	 * Update a row and log it.
	 *
	 * @param int                  $id   Row id.
	 * @param array<string, mixed> $data Column values to change.
	 */
	public function update( int $id, array $data ): void {
		global $wpdb;
		$formats = $this->formats();
		if ( isset( $formats['updated_at'] ) && ! isset( $data['updated_at'] ) ) {
			$data['updated_at'] = Db::now();
		}
		list( $values, $fmt ) = $this->only_columns( $data );
		if ( $values ) {
			$wpdb->update( $this->table_name(), $values, array( 'id' => $id ), $fmt, array( '%d' ) );
		}
		$this->touch( $id );
	}

	/**
	 * Log a row as changed without writing it (e.g. its assignees moved).
	 *
	 * @param int $id Row id.
	 */
	public function touch( int $id ): void {
		if ( ! $this->logged() ) {
			return;
		}
		$row = $this->find( $id );
		ChangeLog::record( $this->type(), $id, 'upsert', $row ? $this->scope( $row ) : null );
	}

	/**
	 * Delete a row and log it.
	 *
	 * @param int $id Row id.
	 */
	public function delete( int $id ): void {
		global $wpdb;
		$row = $this->find( $id );
		if ( ! $row ) {
			return;
		}
		$wpdb->delete( $this->table_name(), array( 'id' => $id ), array( '%d' ) );
		if ( $this->logged() ) {
			ChangeLog::record( $this->type(), $id, 'delete', $this->scope( $row ) );
		}
	}

	/**
	 * The sort_order that puts a new row at the end of its list.
	 *
	 * @param string|null $scope_column Column that splits the list (e.g. "status").
	 * @param string|null $scope_value  Value of that column.
	 */
	public function append_order( ?string $scope_column = null, ?string $scope_value = null ): int {
		global $wpdb;
		if ( null === $scope_column ) {
			$max = $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(sort_order) FROM %i', $this->table_name() ) );
		} else {
			$max = $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(sort_order) FROM %i WHERE %i = %s', $this->table_name(), $scope_column, (string) $scope_value ) );
		}
		return null === $max ? self::ORDER_STEP : (int) $max + self::ORDER_STEP;
	}

	/**
	 * Move a row so it sits just before another one, or at the end.
	 *
	 * Takes the midpoint between the neighbours. When there is no room left,
	 * renumbers the whole list in steps of ORDER_STEP. Callers change the
	 * scope column (e.g. a task's status) before calling this.
	 *
	 * @param int         $id           Row to move.
	 * @param int|null    $before_id    Row it should come before; null for the end.
	 * @param string|null $scope_column Column that splits the list.
	 * @param string|null $scope_value  Value of that column.
	 */
	public function place( int $id, ?int $before_id, ?string $scope_column = null, ?string $scope_value = null ): void {
		global $wpdb;
		if ( null === $scope_column ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, sort_order FROM %i WHERE id <> %d ORDER BY sort_order ASC, id ASC', $this->table_name(), $id ), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, sort_order FROM %i WHERE %i = %s AND id <> %d ORDER BY sort_order ASC, id ASC', $this->table_name(), $scope_column, (string) $scope_value, $id ), ARRAY_A );
		}
		$list = array();
		foreach ( self::rows( $rows ) as $row ) {
			$list[] = array(
				'id'    => (int) $row['id'],
				'order' => (int) $row['sort_order'],
			);
		}

		$index = null;
		if ( null !== $before_id ) {
			foreach ( $list as $i => $item ) {
				if ( $item['id'] === $before_id ) {
					$index = $i;
					break;
				}
			}
		}

		if ( null === $index ) {
			$order = $list ? $list[ count( $list ) - 1 ]['order'] + self::ORDER_STEP : self::ORDER_STEP;
			$this->update( $id, array( 'sort_order' => $order ) );
			return;
		}

		$next = $list[ $index ]['order'];
		$prev = $index > 0 ? $list[ $index - 1 ]['order'] : $next - 2 * self::ORDER_STEP;
		if ( $next - $prev >= 2 ) {
			$this->update( $id, array( 'sort_order' => intdiv( $prev + $next, 2 ) ) );
			return;
		}

		// No room between the neighbours: renumber the list with the row in place.
		$ids = array_column( $list, 'id' );
		array_splice( $ids, $index, 0, array( $id ) );
		$current = array_column( $list, 'order', 'id' );
		foreach ( $ids as $i => $row_id ) {
			$order = ( $i + 1 ) * self::ORDER_STEP;
			if ( $row_id === $id || ( $current[ $row_id ] ?? null ) !== $order ) {
				$this->update( $row_id, array( 'sort_order' => $order ) );
			}
		}
	}

	/**
	 * Keep only known columns, with their formats in the same order.
	 *
	 * @param array<string, mixed> $data Column values.
	 * @return array{0: array<string, mixed>, 1: list<string>}
	 */
	private function only_columns( array $data ): array {
		$formats = $this->formats();
		$values  = array();
		$fmt     = array();
		foreach ( $data as $column => $value ) {
			if ( ! isset( $formats[ $column ] ) ) {
				continue;
			}
			// wpdb writes a PHP null as SQL NULL whatever the format says.
			$values[ $column ] = $value;
			$fmt[]             = $formats[ $column ];
		}
		return array( $values, $fmt );
	}

	/**
	 * Normalise a wpdb result set.
	 *
	 * @param mixed $rows Result of get_results( ARRAY_A ).
	 * @return list<array<string, mixed>>
	 */
	protected static function rows( mixed $rows ): array {
		$out = array();
		foreach ( (array) $rows as $row ) {
			if ( is_array( $row ) ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/**
	 * Positive, unique integer ids.
	 *
	 * @param array<mixed> $ids Candidate ids.
	 * @return list<int>
	 */
	public static function ids( array $ids ): array {
		$out = array();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 && ! in_array( $id, $out, true ) ) {
				$out[] = $id;
			}
		}
		return $out;
	}
}
