<?php
/**
 * Invite links to join the workspace.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.

namespace NovaWork\Data;

/**
 * Owner decision: no open sign-up; an administrator invites people. Each
 * invite is single-use and expires (setting invite_days). Only a hash of the
 * token is stored, so the table never holds a working link. Invites are an
 * administrator's business: they are not synced to the app.
 */
final class InviteRepository extends Repository {

	/**
	 * Table name without prefix.
	 */
	protected function table(): string {
		return 'invites';
	}

	/**
	 * Object type.
	 */
	public function type(): string {
		return 'invite';
	}

	/**
	 * Not in the change log (members never sync invites).
	 */
	protected function logged(): bool {
		return false;
	}

	/**
	 * Column formats.
	 *
	 * @return array<string, string>
	 */
	protected function formats(): array {
		return array(
			'token_hash' => '%s',
			'email'      => '%s',
			'created_by' => '%d',
			'created_at' => '%s',
			'expires_at' => '%s',
			'used_by'    => '%d',
			'used_at'    => '%s',
		);
	}

	/**
	 * API shape (for the settings screen).
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return array<string, mixed>
	 */
	public function present( array $row ): array {
		$used    = (int) $row['used_by'];
		$expires = (int) Db::to_ms( (string) $row['expires_at'] );
		return array(
			'id'         => (int) $row['id'],
			'email'      => (string) $row['email'],
			'createdBy'  => (int) $row['created_by'],
			'createdAt'  => Db::to_ms( (string) $row['created_at'] ),
			'expiresAt'  => $expires,
			'usedBy'     => $used,
			'usedByName' => $used ? (string) ( get_userdata( $used )->display_name ?? '' ) : '',
			'usedAt'     => empty( $row['used_at'] ) ? 0 : Db::to_ms( (string) $row['used_at'] ),
			'status'     => $used ? 'used' : ( $expires < time() * 1000 ? 'expired' : 'open' ),
		);
	}

	/**
	 * Hash a token the way it is stored.
	 *
	 * @param string $token Token from the link.
	 */
	public static function hash( string $token ): string {
		return hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
	}

	/**
	 * The open invite a token belongs to, or null (unknown, used or expired).
	 *
	 * @param string $token Token from the link.
	 * @return array<string, mixed>|null
	 */
	public function find_open( string $token ): ?array {
		global $wpdb;
		if ( ! preg_match( '/^[A-Za-z0-9]{32}$/', $token ) ) {
			return null;
		}
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE token_hash = %s AND used_by = 0 AND expires_at > %s', $this->table_name(), self::hash( $token ), Db::now() ),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * The newest invites, open ones first.
	 *
	 * @param int $limit Most rows.
	 * @return list<array<string, mixed>>
	 */
	public function recent( int $limit = 50 ): array {
		global $wpdb;
		return self::rows(
			$wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM %i ORDER BY ( used_by = 0 AND expires_at > %s ) DESC, id DESC LIMIT %d', $this->table_name(), Db::now(), $limit ),
				ARRAY_A
			)
		);
	}
}
