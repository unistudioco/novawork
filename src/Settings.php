<?php
/**
 * NovaWork settings and their defaults.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

namespace NovaWork;

defined( 'ABSPATH' ) || exit;

/**
 * One option holds every setting; NovaWork → Settings in wp-admin edits it
 * (Rest\SettingsController). Every value is checked against SCHEMA when it
 * is saved, so code reading a setting can trust its type and range.
 */
final class Settings {

	public const OPTION = 'novawork_settings';

	/**
	 * Whether the settings screen has offered NovaWork's typefaces and the
	 * administrator answered ('done'). Fontshare loads only once they opt in.
	 */
	public const FONTS_OFFER_OPTION = 'novawork_fonts_offer';

	/**
	 * Every setting with its default.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'workspace_name'  => '',
			'location'        => 'path',
			'workspace_only'  => false,
			'brand_icon'      => 0,
			'brand_logo'      => 0,
			'brand_logo_dark' => 0,
			'accent'          => '#2065f5',
			'accent_text'     => 'auto',
			'invite_days'     => 7,
			'app_slug'        => 'novawork',
			'idle_minutes'    => 10,
			'poll_seconds'    => 4,
			'week_start'      => (int) get_option( 'start_of_week', 1 ),
			'upload_max_mb'   => 10,
			'upload_types'    => array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'txt', 'csv', 'zip', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'mp4', 'mov', 'mp3', 'm4a' ),
			'load_fontshare'  => false,
			'delete_data'     => false,
			'now_limit'       => 3,
			'done_shown'      => 8,
			'due_soon_days'   => 3,
			'idle_grace'      => 60,
			'log_days'        => 30,
			'read_note_days'  => 90,
			'team_order'      => 'dynamic',
			'decide_rule'     => 'named',
			'edit_rule'       => 'own',
			'recover_time'    => true,
			'decision_votes'  => true,
			'vote_order'      => 'votes',
			'desktop_alerts'  => true,
			'show_tour'       => true,
			'installable'     => true,
			'message_edits'   => 'always',
			'edit_minutes'    => 15,
			'activity_days'   => 30,
		);
	}

	/**
	 * How each setting is checked when saved: an int range, a list of
	 * choices, a boolean, text, the app's address, or a list of file types.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public const SCHEMA = array(
		'workspace_name'  => array(
			'type' => 'text',
			'max'  => 60,
		),
		'workspace_only'  => array( 'type' => 'bool' ),
		'brand_icon'      => array( 'type' => 'image' ),
		'brand_logo'      => array( 'type' => 'image' ),
		'brand_logo_dark' => array( 'type' => 'image' ),
		'accent'          => array( 'type' => 'color' ),
		'accent_text'     => array(
			'type'    => 'choice',
			'choices' => array( 'auto', 'white', 'dark' ),
		),
		'invite_days'     => array(
			'type' => 'int',
			'min'  => 1,
			'max'  => 90,
		),
		'location'        => array(
			'type'    => 'choice',
			'choices' => array( 'path', 'home' ),
		),
		'app_slug'        => array( 'type' => 'slug' ),
		'idle_minutes'    => array(
			'type' => 'int',
			'min'  => 1,
			'max'  => 240,
		),
		'idle_grace'      => array(
			'type' => 'int',
			'min'  => 15,
			'max'  => 600,
		),
		'poll_seconds'    => array(
			'type' => 'int',
			'min'  => 2,
			'max'  => 60,
		),
		'week_start'      => array(
			'type' => 'int',
			'min'  => 0,
			'max'  => 6,
		),
		'upload_max_mb'   => array(
			'type' => 'int',
			'min'  => 1,
			'max'  => 2048,
		),
		'upload_types'    => array( 'type' => 'types' ),
		'load_fontshare'  => array( 'type' => 'bool' ),
		'delete_data'     => array( 'type' => 'bool' ),
		'now_limit'       => array(
			'type' => 'int',
			'min'  => 1,
			'max'  => 20,
		),
		'done_shown'      => array(
			'type' => 'int',
			'min'  => 1,
			'max'  => 100,
		),
		'due_soon_days'   => array(
			'type' => 'int',
			'min'  => 0,
			'max'  => 30,
		),
		'log_days'        => array(
			'type' => 'int',
			'min'  => 1,
			'max'  => 365,
		),
		'read_note_days'  => array(
			'type' => 'int',
			'min'  => 7,
			'max'  => 3650,
		),
		'team_order'      => array(
			'type'    => 'choice',
			'choices' => self::TEAM_ORDERS,
		),
		'decide_rule'     => array(
			'type'    => 'choice',
			'choices' => array( 'named', 'anyone' ),
		),
		'edit_rule'       => array(
			'type'    => 'choice',
			'choices' => array( 'own', 'anyone' ),
		),
		'recover_time'    => array( 'type' => 'bool' ),
		'decision_votes'  => array( 'type' => 'bool' ),
		'vote_order'      => array(
			'type'    => 'choice',
			'choices' => array( 'votes', 'written' ),
		),
		'desktop_alerts'  => array( 'type' => 'bool' ),
		'show_tour'       => array( 'type' => 'bool' ),
		'installable'     => array( 'type' => 'bool' ),
		'message_edits'   => array(
			'type'    => 'choice',
			'choices' => array( 'always', 'window', 'never' ),
		),
		'edit_minutes'    => array(
			'type' => 'int',
			'min'  => 1,
			'max'  => 1440,
		),
		'activity_days'   => array(
			'type' => 'int',
			'min'  => 1,
			'max'  => 3650,
		),
	);

	/**
	 * Addresses the app may not take: WordPress's own, and the login page's.
	 */
	public const RESERVED_SLUGS = array( 'wp-admin', 'wp-content', 'wp-includes', 'wp-json', 'wp-login', 'wp-login-php', 'xmlrpc', 'feed', 'login', 'logout', 'register', 'admin', 'dashboard', 'embed', 'page', 'comments', 'search', 'author', 'category', 'tag', 'type' );

	/**
	 * Check one sent value. Numbers outside their range are clamped; a value
	 * that cannot be used returns a WP_Error saying why.
	 *
	 * @param string $key   Setting name.
	 * @param mixed  $value Sent value.
	 */
	public static function clean( string $key, mixed $value ): mixed {
		$rule = self::schema()[ $key ] ?? null;
		if ( null === $rule ) {
			return new \WP_Error( 'novawork_unknown_setting', __( 'Unknown setting.', 'novawork' ) );
		}
		return self::check( $rule, $value );
	}

	/**
	 * NovaWork's rules plus the ones add-ons add (1.3.0). An add-on never
	 * changes a rule of NovaWork's own.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function schema(): array {
		/**
		 * Filters the rules settings are checked against, so an add-on's own
		 * settings are checked exactly like NovaWork's (see Settings::clean()
		 * and Settings::clean_values()). Each rule is keyed by the setting's
		 * name and has a "type": int (with min and max), bool, choice (with
		 * choices), text (with max), types, image or color. Keys NovaWork
		 * already uses are ignored. The add-on stores its own values.
		 *
		 * @since 1.3.0
		 *
		 * @param array<mixed> $rules Add-ons' rules by setting name.
		 */
		$extra = (array) apply_filters( 'novawork_settings_schema', array() );
		$rules = self::SCHEMA;
		foreach ( $extra as $key => $rule ) {
			if ( is_string( $key ) && '' !== $key && ! isset( self::SCHEMA[ $key ] ) && is_array( $rule ) && isset( $rule['type'] ) ) {
				$rules[ $key ] = $rule;
			}
		}
		return $rules;
	}

	/**
	 * Check several values at once. Unknown names are skipped; every bad
	 * value comes back by name, so a caller can save nothing when one is
	 * wrong and show each problem next to its field.
	 *
	 * @since 1.3.0
	 *
	 * @param array<mixed>  $values Values by setting name.
	 * @param string[]|null $only   Only these names (null: every name in the schema).
	 * @return array{0: array<string, mixed>, 1: array<string, string>} Clean values, and error messages by name.
	 */
	public static function clean_values( array $values, ?array $only = null ): array {
		$rules  = self::schema();
		$clean  = array();
		$errors = array();
		foreach ( $values as $key => $value ) {
			$key = (string) $key;
			if ( ! isset( $rules[ $key ] ) || ( null !== $only && ! in_array( $key, $only, true ) ) ) {
				continue;
			}
			$value = self::check( $rules[ $key ], $value );
			if ( is_wp_error( $value ) ) {
				$errors[ $key ] = $value->get_error_message();
			} else {
				$clean[ $key ] = $value;
			}
		}
		return array( $clean, $errors );
	}

	/**
	 * Check one value against a rule.
	 *
	 * @param array<string, mixed> $rule  Rule.
	 * @param mixed                $value Value.
	 * @return mixed The clean value, or a WP_Error.
	 */
	private static function check( array $rule, mixed $value ): mixed {
		switch ( $rule['type'] ) {
			case 'int':
				if ( ! is_numeric( $value ) ) {
					return new \WP_Error( 'novawork_bad_number', __( 'Enter a number.', 'novawork' ) );
				}
				return max( (int) $rule['min'], min( (int) $rule['max'], (int) $value ) );
			case 'bool':
				// An explicit value is always sent; "0", "false", "off" and "" mean off.
				return is_string( $value ) ? ! in_array( strtolower( $value ), array( '', '0', 'false', 'off', 'no' ), true ) : (bool) $value;
			case 'choice':
				return in_array( $value, (array) $rule['choices'], true ) ? $value : new \WP_Error( 'novawork_bad_choice', __( 'Choose one of the options.', 'novawork' ) );
			case 'text':
				return mb_substr( sanitize_text_field( (string) $value ), 0, (int) $rule['max'] );
			case 'types':
				$list = is_array( $value ) ? $value : preg_split( '/[\s,]+/', (string) $value );
				$out  = array();
				foreach ( (array) $list as $type ) {
					$type = strtolower( (string) preg_replace( '/[^a-z0-9]/i', '', (string) $type ) );
					if ( '' !== $type && ! in_array( $type, $out, true ) && ! in_array( $type, array( 'php', 'phtml', 'phar', 'js', 'html', 'htm', 'svg', 'exe', 'sh' ), true ) ) {
						$out[] = $type;
					}
				}
				return $out ? $out : new \WP_Error( 'novawork_no_types', __( 'Allow at least one type of file.', 'novawork' ) );
			case 'slug':
				$slug = sanitize_title( (string) $value );
				if ( '' === $slug ) {
					return new \WP_Error( 'novawork_bad_slug', __( 'Enter an address, such as “work”.', 'novawork' ) );
				}
				if ( in_array( $slug, self::RESERVED_SLUGS, true ) ) {
					return new \WP_Error( 'novawork_reserved_slug', __( 'WordPress already uses this address. Choose another.', 'novawork' ) );
				}
				$page = get_page_by_path( $slug, OBJECT, array( 'page', 'post' ) );
				if ( $page ) {
					return new \WP_Error( 'novawork_taken_slug', __( 'A page of this site already uses this address. Choose another.', 'novawork' ) );
				}
				return $slug;
			case 'image':
				$id = absint( $value );
				if ( 0 !== $id && ! wp_attachment_is_image( $id ) ) {
					return new \WP_Error( 'novawork_not_image', __( 'Choose an image from the media library.', 'novawork' ) );
				}
				return $id;
			case 'color':
				$color = \NovaWork\App\Branding::normalize( (string) $value );
				return null === $color ? new \WP_Error( 'novawork_bad_color', __( 'Enter a colour as #hex or rgb(r, g, b).', 'novawork' ) ) : $color;
			default:
				return new \WP_Error( 'novawork_unknown_setting', __( 'Unknown setting.', 'novawork' ) );
		}
	}

	/**
	 * The workspace's name: the setting, else the site's title (owner: the
	 * plugin is NovaWork, the workspace is yours).
	 */
	public static function workspace_name(): string {
		$name = trim( (string) self::get( 'workspace_name' ) );
		if ( '' === $name ) {
			$name = trim( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) );
		}
		return '' === $name ? 'NovaWork' : $name;
	}

	/**
	 * Where the workspace lives: "path" (site.com/{app_slug}) or "home" (the
	 * site's home page).
	 */
	public static function location(): string {
		return 'home' === self::get( 'location' ) ? 'home' : 'path';
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
	 * How a decision's vote options are ordered (owner, 2026-11-23): "votes"
	 * (most votes first; ties keep the written order) or "written".
	 */
	public static function vote_order(): string {
		return 'written' === self::get( 'vote_order' ) ? 'written' : 'votes';
	}

	/**
	 * Who may change work (owner, 1.2.0): "own" (default) lets members change
	 * tasks they created or are assigned to, and projects and decisions they
	 * created; "anyone" lets every member change everything. Administrators
	 * always may.
	 */
	public static function edit_rule(): string {
		return 'anyone' === self::get( 'edit_rule' ) ? 'anyone' : 'own';
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
