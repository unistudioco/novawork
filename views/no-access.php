<?php
/**
 * Shown to a signed-in user who isn't a NovaWork member.
 *
 * @package NovaWork
 */

use NovaWork\App\Assets;

defined( 'ABSPATH' ) || exit;
?><!doctype html>
<html <?php language_attributes(); ?> class="nw-doc">
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( \NovaWork\Settings::workspace_name() ); ?></title>
<?php
wp_print_inline_script_tag( 'try{var t=localStorage.getItem("nw.theme");if(t==="light"||t==="dark")document.documentElement.dataset.theme=t}catch(e){}' );
wp_print_styles( Assets::styles() );
?>
</head>
<body class="nw-body">
<main class="nw-wrap nw-gate">
	<div class="nw-empty">
		<h1><?php echo esc_html( sprintf( /* translators: %s: the workspace's name. */ __( 'You’re not in %s yet', 'novawork' ), \NovaWork\Settings::workspace_name() ) ); ?></h1>
		<p><?php esc_html_e( 'Members are added by a site administrator in WordPress, under Users. Ask them to add you, then reload this page.', 'novawork' ); ?></p>
		<p><a class="nw-btn" href="<?php echo esc_url( wp_logout_url() ); ?>"><?php esc_html_e( 'Sign out', 'novawork' ); ?></a></p>
	</div>
</main>
</body>
</html>
