<?php
/**
 * Plugin bootstrap.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

namespace NovaWork;

use NovaWork\Access\Capabilities;
use NovaWork\Install\Installer;
use NovaWork\Services\Cron;

/**
 * Boots NovaWork once and registers its parts.
 *
 * Each part registers its own hooks inside a register() method, never in a
 * constructor, so loading a class never has side effects.
 */
final class Plugin {

	/**
	 * Whether boot() has already run.
	 *
	 * @var bool
	 */
	private static bool $booted = false;

	/**
	 * REST controllers.
	 */
	private const CONTROLLERS = array(
		Rest\SyncController::class,
		Rest\ProjectsController::class,
		Rest\TasksController::class,
		Rest\DecisionsController::class,
		Rest\CommentsController::class,
		Rest\ChatController::class,
		Rest\TimeController::class,
		Rest\NotificationsController::class,
		Rest\SearchController::class,
		Rest\ActivityController::class,
		Rest\SettingsController::class,
	);

	/**
	 * Start the plugin. Safe to call more than once.
	 *
	 * Runs while the main plugin file loads, so activation hooks can register.
	 */
	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		Installer::register();
		add_action( 'plugins_loaded', array( self::class, 'register' ) );
	}

	/**
	 * Register every part of the plugin.
	 */
	public static function register(): void {
		Cron::register();
		Services\Activity::register();
		App\Route::register();
		Admin\Menu::register();
		Admin\SettingsPage::register();
		Access\Profile::register();

		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		add_action( 'deleted_user', array( Members::class, 'deleted' ) );
		add_filter( 'gettext_with_context', array( Capabilities::class, 'translate_role_name' ), 10, 4 );

		/**
		 * Fires once NovaWork has registered its own parts.
		 *
		 * Add-ons hook here to extend NovaWork.
		 *
		 * @since 0.1.0
		 */
		do_action( 'novawork_loaded' );
	}

	/**
	 * Register the REST API (novawork/v1).
	 */
	public static function register_routes(): void {
		foreach ( self::CONTROLLERS as $controller ) {
			( new $controller() )->register_routes();
		}
	}
}
