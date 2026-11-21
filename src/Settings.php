<?php
/**
 * NovaWork settings and their defaults.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

namespace NovaWork;

/**
 * One option holds every setting. Phase 5 adds the wp-admin screen; until
 * then the defaults apply.
 */
final class Settings {

	public const OPTION = 'novawork_settings';

	/**
	 * Every setting with its default.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'app_slug'       => 'novawork',
			'idle_minutes'   => 10,
			'poll_seconds'   => 4,
			'week_start'     => (int) get_option( 'start_of_week', 1 ),
			'upload_max_mb'  => 10,
			'upload_types'   => array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'txt', 'csv', 'zip', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'mp4', 'mov', 'mp3', 'm4a' ),
			'load_fontshare' => true,
			'delete_data'    => false,
			'now_limit'      => 3,
			'due_soon_days'  => 3,
			'idle_grace'     => 60,
			'log_days'       => 30,
			'read_note_days' => 90,
			'team_order'     => 'dynamic',
			'decide_rule'    => 'named',
			'decision_votes' => true,
			'message_edits'  => 'always',
			'edit_minutes'   => 15,
		);
	}

	/**
	 * Every setting, stored values over defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting name.
	 */
	public static function get( string $key ): mixed {
		return self::all()[ $key ] ?? null;
	}

	/**
	 * Orders for "Team today": dynamic (here first, most tracked first; away
	 * last), tracked (most tracked today), online (first to come online;
	 * away last), fixed (account order).
	 */
	public const TEAM_ORDERS = array( 'dynamic', 'tracked', 'online', 'fixed' );

	/**
	 * The "Team today" order, falling back to dynamic.
	 */
	public static function team_order(): string {
		$order = (string) self::get( 'team_order' );
		return in_array( $order, self::TEAM_ORDERS, true ) ? $order : 'dynamic';
	}

	/**
	 * Who may record a decision: "named" (the person named in Who decides;
	 * anyone when no one is named) or "anyone". Administrators always may.
	 */
	public static function decide_rule(): string {
		return 'anyone' === self::get( 'decide_rule' ) ? 'anyone' : 'named';
	}

	/**
	 * Whether members may edit and delete their own chat messages and
	 * comments: "always", "window" (for edit_minutes after posting) or
	 * "never". Administrators may always delete anyone's.
	 */
	public static function message_edits(): string {
		$rule = (string) self::get( 'message_edits' );
		return in_array( $rule, array( 'always', 'window', 'never' ), true ) ? $rule : 'always';
	}

	/**
	 * A whole-number setting, never below a floor.
	 *
	 * @param string $key   Setting name.
	 * @param int    $floor Smallest allowed value.
	 */
	public static function int( string $key, int $floor = 1 ): int {
		return max( $floor, (int) self::get( $key ) );
	}

	/**
	 * Largest upload in bytes: the setting, capped by what PHP accepts.
	 */
	public static function upload_max_bytes(): int {
		$mb = max( 1, (int) self::get( 'upload_max_mb' ) );
		return (int) min( $mb * MB_IN_BYTES, wp_max_upload_size() );
	}

	/**
	 * Allowed upload extensions.
	 *
	 * @return list<string>
	 */
	public static function upload_types(): array {
		$types = self::get( 'upload_types' );
		$out   = array();
		foreach ( is_array( $types ) ? $types : array() as $type ) {
			$type = strtolower( sanitize_key( (string) $type ) );
			if ( '' !== $type ) {
				$out[] = $type;
			}
		}
		return $out;
	}
}
