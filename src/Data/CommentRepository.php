<?php
/**
 * Comments on tasks and decisions.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.

namespace NovaWork\Data;

/**
 * A comment is written by a member (user_id) or by an agent (agent key, for
 * add-ons such as an AI teammate); exactly one of the two is set.
 */
final class CommentRepository extends Repository {

	public const OBJECTS = array( 'task', 'decision' );

	/**
	 * Table name without prefix.
	 */
	protected function table(): string {
		return 'comments';
	}

	/**
	 * Object type.
	 */
	public function type(): string {
		return 'comment';
	}

	/**
	 * Column formats.
	 *
	 * @return array<string, string>
	 */
	protected function formats(): array {
		return array(
			'object_type' => '%s',
			'object_id'   => '%d',
			'user_id'     => '%d',
			'agent'       => '%s',
			'body'        => '%s',
			'mentions'    => '%s',
			'refs'        => '%s',
			'reply_to'    => '%d',
			'edited_at'   => '%s',
			'created_at'  => '%s',
			'updated_at'  => '%s',
		);
	}

	/**
	 * Defaults for a new row.
	 *
	 * @return array<string, mixed>
	 */
	protected function defaults(): array {
		return array(
			'user_id'  => 0,
			'agent'    => '',
			'mentions' => '[]',
			'refs'     => '[]',
			'reply_to' => 0,
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
			'id'         => (int) $row['id'],
			'objectType' => (string) $row['object_type'],
			'objectId'   => (int) $row['object_id'],
			'by'         => (int) $row['user_id'],
			'agent'      => (string) $row['agent'],
			'text'       => (string) $row['body'],
			'mentions'   => \NovaWork\Services\References::decode( $row['mentions'] ?? '[]' ),
			'refs'       => \NovaWork\Services\References::decode( $row['refs'] ?? '[]' ),
			'replyTo'    => (int) ( $row['reply_to'] ?? 0 ),
			'editedAt'   => empty( $row['edited_at'] ) ? 0 : Db::to_ms( (string) $row['edited_at'] ),
			'at'         => Db::to_ms( $row['created_at'] ),
		);
	}

	/**
	 * Every comment, oldest first.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function all(): array {
		global $wpdb;
		return self::rows( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id ASC', $this->table_name() ), ARRAY_A ) );
	}

	/**
	 * Comments on one object, oldest first.
	 *
	 * @param string $object_type "task" or "decision".
	 * @param int    $object_id   Object id.
	 * @return list<array<string, mixed>>
	 */
	public function for_object( string $object_type, int $object_id ): array {
		global $wpdb;
		return self::rows(
			$wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM %i WHERE object_type = %s AND object_id = %d ORDER BY id ASC', $this->table_name(), $object_type, $object_id ),
				ARRAY_A
			)
		);
	}

	/**
	 * Delete every comment on an object.
	 *
	 * @param string $object_type "task" or "decision".
	 * @param int    $object_id   Object id.
	 */
	public function delete_for( string $object_type, int $object_id ): void {
		foreach ( $this->for_object( $object_type, $object_id ) as $row ) {
			$this->delete( (int) $row['id'] );
		}
	}
}
