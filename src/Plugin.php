<?php
/**
 * Plugin bootstrap.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

namespace NovaWork;

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
	 * Start the plugin. Safe to call more than once.
	 */
	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		add_action( 'plugins_loaded', array( self::class, 'register' ) );
	}

	/**
	 * Register every part of the plugin.
	 *
	 * Phase 2 adds the schema, roles and REST API here; Phase 3 the app route.
	 */
	public static function register(): void {
		/**
		 * Fires once NovaWork has registered its own parts.
		 *
		 * Add-ons hook here to extend NovaWork.
		 *
		 * @since 0.1.0
		 */
		do_action( 'novawork_loaded' );
	}
}
