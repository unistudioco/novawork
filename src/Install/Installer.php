<?php
/**
 * Activation, upgrades and deactivation.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

namespace NovaWork\Install;

use NovaWork\Access\Capabilities;
use NovaWork\App\Route;
use NovaWork\Services\Cron;
use NovaWork\Services\Files;

/**
 * Activation hooks do not run when a plugin is updated in place, so the same
 * install routine also runs whenever the stored schema version is behind.
 */
final class Installer {

	/**
	 * Option holding the installed schema version.
	 */
	public const VERSION_OPTION = 'novawork_db_version';

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		register_activation_hook( NOVAWORK_FILE, array( self::class, 'activate' ) );
		register_deactivation_hook( NOVAWORK_FILE, array( self::class, 'deactivate' ) );
		add_action( 'plugins_loaded', array( self::class, 'maybe_upgrade' ), 5 );
	}

	/**
	 * Plugin activated.
	 */
	public static function activate(): void {
		self::install();
	}

	/**
	 * Plugin deactivated. Data stays; uninstall.php removes it if asked to.
	 */
	public static function deactivate(): void {
		Cron::unschedule();
		// Our rewrite rule is still registered during this request, so rather
		// than flush now, let WordPress rebuild the rules on the next load.
		delete_option( 'rewrite_rules' );
	}

	/**
	 * Install or upgrade when the stored version is behind.
	 */
	public static function maybe_upgrade(): void {
		if ( (int) get_option( self::VERSION_OPTION, 0 ) < Schema::VERSION ) {
			self::install();
		}
	}

	/**
	 * Create or upgrade tables, role, capabilities and the files folder.
	 */
	public static function install(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$from = (int) get_option( self::VERSION_OPTION, 0 );
		dbDelta( Schema::statements() );
		// Changes dbDelta cannot express (renames, data moves) go here, guarded
		// by the version they belong to: `if ( $from < 2 ) { … }`.
		unset( $from );

		Capabilities::install();
		Files::ensure_folder();
		Cron::schedule();
		update_option( Route::FLUSH_OPTION, 1, false );

		update_option( self::VERSION_OPTION, Schema::VERSION, false );
	}
}
