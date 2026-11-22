<?php
/**
 * Removing NovaWork's data when the plugin is deleted.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Dropping the plugin's own tables.

namespace NovaWork\Install;

use NovaWork\Access\Capabilities;
use NovaWork\Data\Db;
use NovaWork\Services\Cron;
use NovaWork\Services\Files;
use NovaWork\Settings;

/**
 * Deleting the plugin removes its data only when the administrator turned on
 * "Delete everything when NovaWork is deleted" (setting `delete_data`, off by
 * default). Deactivating never removes anything. On a network every site is
 * handled on its own.
 */
final class Uninstaller {

	/**
	 * Run for this site, or for every site on a network.
	 */
	public static function run(): void {
		if ( ! is_multisite() ) {
			self::site();
			return;
		}
		foreach ( get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		) as $blog_id ) {
			switch_to_blog( (int) $blog_id );
			self::site();
			restore_current_blog();
		}
	}

	/**
	 * Remove everything on the current site, if the setting asks for it.
	 *
	 * @return bool Whether anything was removed.
	 */
	public static function site(): bool {
		if ( ! Settings::get( 'delete_data' ) ) {
			return false;
		}
		global $wpdb;

		wp_clear_scheduled_hook( Cron::HOOK );

		// Members lose access: those who had it on their own get it taken
		// away, and NovaWork Members move to the site's default role so their
		// accounts keep working.
		foreach ( get_users( array( 'capability' => Capabilities::ACCESS ) ) as $user ) {
			$user->remove_cap( Capabilities::ACCESS );
			if ( in_array( Capabilities::ROLE, $user->roles, true ) ) {
				$user->remove_role( Capabilities::ROLE );
				if ( ! $user->roles ) {
					$user->set_role( (string) get_option( 'default_role', 'subscriber' ) );
				}
			}
		}
		Capabilities::remove();

		foreach ( Db::TABLES as $table ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', Db::table( $table ) ) );
		}

		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s', $wpdb->options, $wpdb->esc_like( 'novawork_' ) . '%', $wpdb->esc_like( '_transient_novawork_' ) . '%', $wpdb->esc_like( '_transient_timeout_novawork_' ) . '%' ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE meta_key LIKE %s', $wpdb->usermeta, $wpdb->esc_like( 'novawork_' ) . '%' ) );
		wp_cache_flush();

		self::remove_folder( Files::dir() );
		return true;
	}

	/**
	 * Delete NovaWork's private uploads folder and everything in it.
	 *
	 * @param string $dir Folder.
	 */
	private static function remove_folder( string $dir ): void {
		global $wp_filesystem;
		if ( ! is_dir( $dir ) || basename( $dir ) !== 'novawork' ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( WP_Filesystem() && $wp_filesystem ) {
			$wp_filesystem->delete( $dir, true );
		}
	}
}
