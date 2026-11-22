<?php
/**
 * Runs when NovaWork is deleted from wp-admin → Plugins.
 *
 * Data is removed only if "Delete everything when NovaWork is deleted" is on
 * (NovaWork → Settings → Data). Otherwise tasks, chat, time and settings stay,
 * ready if NovaWork is installed again.
 *
 * @package NovaWork
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	return;
}
require_once __DIR__ . '/vendor/autoload.php';

NovaWork\Install\Uninstaller::run();
