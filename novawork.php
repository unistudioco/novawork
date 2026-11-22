<?php
/**
 * Plugin Name:       NovaWork
 * Plugin URI:        https://github.com/unistudioco/novawork
 * Description:       A calm team workspace inside WordPress: projects, a board, a roadmap, decisions, time tracking and team chat.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            UniStudio
 * Author URI:        https://unistudio.co
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       novawork
 * Domain Path:       /languages
 *
 * @package NovaWork
 */

defined( 'ABSPATH' ) || exit;

// Keep this file parseable on any PHP: WordPress refuses activation below
// "Requires PHP", but a site that already runs NovaWork can still be moved to
// an older PHP. Typed code starts in src/.
if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
	return;
}

define( 'NOVAWORK_VERSION', '1.0.0' );
define( 'NOVAWORK_FILE', __FILE__ );
define( 'NOVAWORK_PATH', plugin_dir_path( __FILE__ ) );
define( 'NOVAWORK_URL', plugin_dir_url( __FILE__ ) );

if ( ! is_readable( NOVAWORK_PATH . 'vendor/autoload.php' ) ) {
	return;
}

require_once NOVAWORK_PATH . 'vendor/autoload.php';

NovaWork\Plugin::boot();
