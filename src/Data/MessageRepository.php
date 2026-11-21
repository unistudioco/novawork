<?php
/**
 * Team chat messages.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.

namespace NovaWork\Data;

/**
 * One team channel. Like comments, a message is written by a member or by an
 * agent. Attachments are presented inline as "files".
 */
final class MessageRepository extends Repository {

	/**
	 * Table name without prefix.
	 */
	protected function table(): string {
		return 'messages';
	}

	/**
	 * Object type.
	 */
	public function type(): string {
		return 'message';
	}

	/**
	 * Column formats.
	 *
	 * @return array<string, string>
	 */
	protected function formats(): array {
		return array(
			'user_id'    => '%d',
			'agent'      => '%s',
			'body'       => '%s',
			'created_at' => '%s',
			'updated_at' => '%s',
		);
	}

	/**
	 * Defaults for a new row.
	 *
	 * @return array<string, mixed>
	 */
	protected function defaults(): array {
		return array(
			'user_id' => 0,
			'agent'   => '',
			'body'    => '',
		);
	}

	/**
	 * API shape.
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return array<string, mixed>
	 */
	public function present( array $row ): array {
		return $this->present_many( array( $row ) )[0];
	}

	/**
	 * API shapes with attachments loaded in one query.
	 *
	 * @param list<array<string, mixed>> $rows Database rows.
	 * @return list<array<string, mixed>>
	 */
	public function present_many( array $rows ): array {
		$files = ( new AttachmentRepository() )->for_messages( array_map( static fn( $row ) => (int) $row['id'], $rows ) );
		$out   = array();
		foreach ( $rows as $row ) {
			$id    = (int) $row['id'];
			$out[] = array(
				'id'    => $id,
				'by'    => (int) $row['user_id'],
				'agent' => (string) $row['agent'],
				'text'  => (string) $row['body'],
				'at'    => Db::to_ms( $row['created_at'] ),
				'files' => $files[ $id ] ?? array(),
			);
		}
		return $out;
	}

	/**
	 * A page of history, oldest first, optionally filtered.
	 *
	 * @param int         $before_id Only messages older than this id; 0 for the newest.
	 * @param int         $limit     Most rows.
	 * @param string      $phrase    Only messages containing this text.
	 * @param string|null $from      Only messages at or after this UTC DATETIME.
	 * @param string|null $to        Only messages before this UTC DATETIME.
	 * @return list<array<string, mixed>>
	 */
	public function page( int $before_id, int $limit, string $phrase = '', ?string $from = null, ?string $to = null ): array {
		global $wpdb;
		$like = '' === $phrase ? '%' : '%' . $wpdb->esc_like( $phrase ) . '%';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE ( %d = 0 OR id < %d ) AND body LIKE %s AND created_at >= %s AND created_at < %s ORDER BY id DESC LIMIT %d',
				$this->table_name(),
				$before_id,
				$before_id,
				$like,
				$from ?? '1000-01-01 00:00:00',
				$to ?? '9999-12-31 23:59:59',
				$limit
			),
			ARRAY_A
		);
		return array_reverse( self::rows( $rows ) );
	}

	/**
	 * Messages after an id not written by a member: their unread count.
	 *
	 * @param int $after_id Last message the member read.
	 * @param int $user_id  The member.
	 */
	public function count_unread( int $after_id, int $user_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE id > %d AND user_id <> %d', $this->table_name(), $after_id, $user_id )
		);
	}

	/**
	 * The newest message id, or 0.
	 */
	public function latest_id(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(id) FROM %i', $this->table_name() ) );
	}

	/**
	 * Delete a message and its files.
	 *
	 * @param int $id Message id.
	 */
	public function delete( int $id ): void {
		( new AttachmentRepository() )->delete_for( 'message', $id );
		parent::delete( $id );
	}
}
