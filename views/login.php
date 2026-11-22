<?php
/**
 * The members' sign-in page ({workspace}/login). Variables come from
 * App\Login::render() in $view.
 *
 * @package NovaWork
 *
 * @var array<string, mixed> $view
 */

use NovaWork\App\Assets;

defined( 'ABSPATH' ) || exit;
?><!doctype html>
<html <?php language_attributes(); ?> class="nw-doc">
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>
<?php
/* translators: %s: the workspace's name. */
echo esc_html( sprintf( __( 'Sign in · %s', 'novawork' ), (string) $view['name'] ) );
?>
</title>
<?php
wp_print_inline_script_tag( 'try{var t=localStorage.getItem("nw.theme");if(t==="light"||t==="dark")document.documentElement.dataset.theme=t}catch(e){}' );
if ( in_array( Assets::FONTS, Assets::styles(), true ) ) {
	echo '<link rel="preconnect" href="https://api.fontshare.com" crossorigin>' . "\n";
}
wp_print_styles( Assets::styles() );
?>
</head>
<body class="nw-body">
<main class="nw-wrap nw-gate nw-login">
	<form class="nw-login-card" method="post" action="" novalidate>
		<div class="nw-login-head">
			<span class="nw-login-mark" aria-hidden="true">
				<svg class="nw-ic" style="--nw-is:24px" viewBox="0 0 256 256" fill="currentColor"><path d="M131.79,69.65l-43.63,96A4,4,0,0,1,84.52,168H28.23a8.2,8.2,0,0,1-6.58-3.13,8,8,0,0,1,.43-10.25L57.19,116,22.08,77.38a8,8,0,0,1-.43-10.26A8.22,8.22,0,0,1,28.23,64h99.92A4,4,0,0,1,131.79,69.65ZM237.56,42.24A8.3,8.3,0,0,0,231.77,40H168a8,8,0,0,0-7.28,4.69l-42.57,93.65a4,4,0,0,0,3.64,5.66h57.79l-34.86,76.69a8,8,0,1,0,14.56,6.62l80-176A8,8,0,0,0,237.56,42.24Z"/></svg>
			</span>
			<h1><?php echo esc_html( (string) $view['name'] ); ?></h1>
			<p>
			<?php
			if ( 'join' === $view['mode'] ) {
				esc_html_e( 'You’re invited to join the workspace.', 'novawork' );
			} elseif ( $view['invite'] ) {
				esc_html_e( 'Sign in to join the workspace.', 'novawork' );
			} elseif ( 'lost' === $view['mode'] ) {
				esc_html_e( 'Forgot your password? Enter your email or username and we’ll send a link to set a new one.', 'novawork' );
			} elseif ( 'reset' === $view['mode'] ) {
				esc_html_e( 'Choose a new password.', 'novawork' );
			} else {
				esc_html_e( 'Sign in to your workspace.', 'novawork' );
			}
			?>
			</p>
		</div>

		<?php if ( '' !== $view['error'] ) : ?>
			<p class="nw-login-msg nw-bad" role="alert"><?php echo esc_html( (string) $view['error'] ); ?></p>
		<?php elseif ( '' !== $view['sent'] ) : ?>
			<p class="nw-login-msg nw-good" role="status"><?php echo esc_html( (string) $view['sent'] ); ?></p>
		<?php elseif ( $view['wasReset'] ) : ?>
			<p class="nw-login-msg nw-good" role="status"><?php esc_html_e( 'Your new password is set. Sign in with it.', 'novawork' ); ?></p>
		<?php elseif ( $view['signedOut'] ) : ?>
			<p class="nw-login-msg" role="status"><?php esc_html_e( 'You’ve signed out.', 'novawork' ); ?></p>
		<?php endif; ?>

		<?php if ( 'join' === $view['mode'] ) : ?>
			<?php if ( $view['badInvite'] ) : ?>
				<a class="nw-login-back" href="<?php echo esc_url( (string) $view['loginUrl'] ); ?>"><?php esc_html_e( 'Go to sign in', 'novawork' ); ?></a>
			<?php elseif ( '' !== $view['me'] ) : ?>
				<?php wp_nonce_field( (string) $view['nonce'], '_novawork_login', false ); ?>
				<button class="nw-btn nw-primary nw-login-go" type="submit">
					<?php
					/* translators: %s: the signed-in person's name. */
					echo esc_html( sprintf( __( 'Join as %s', 'novawork' ), (string) $view['me'] ) );
					?>
				</button>
			<?php else : ?>
				<?php $novawork_fixed = (string) ( $view['invite']['email'] ?? '' ); ?>
				<div class="nw-field">
					<label for="nw-join-name"><?php esc_html_e( 'Your name', 'novawork' ); ?></label>
					<input class="nw-input" id="nw-join-name" name="name" type="text" autocomplete="name" required autofocus maxlength="60" value="<?php echo esc_attr( (string) $view['joinName'] ); ?>">
				</div>
				<div class="nw-field">
					<label for="nw-join-email"><?php esc_html_e( 'Email', 'novawork' ); ?></label>
					<input class="nw-input" id="nw-join-email" name="email" type="email" autocomplete="email" required value="<?php echo esc_attr( '' !== $novawork_fixed ? $novawork_fixed : (string) $view['joinEmail'] ); ?>" <?php echo '' !== $novawork_fixed ? 'readonly' : ''; ?>>
				</div>
				<div class="nw-field">
					<label for="nw-login-pass"><?php esc_html_e( 'Choose a password', 'novawork' ); ?></label>
					<div class="nw-login-pass">
						<input class="nw-input" id="nw-login-pass" name="pass1" type="password" autocomplete="new-password" required>
						<button type="button" class="nw-login-eye" id="nw-login-eye" aria-controls="nw-login-pass" aria-pressed="false"><?php esc_html_e( 'Show', 'novawork' ); ?></button>
					</div>
				</div>
				<?php wp_nonce_field( (string) $view['nonce'], '_novawork_login', false ); ?>
				<button class="nw-btn nw-primary nw-login-go" type="submit"><?php esc_html_e( 'Join', 'novawork' ); ?></button>
				<a class="nw-login-back" href="<?php echo esc_url( add_query_arg( 'invite', (string) $view['token'], (string) $view['loginUrl'] ) ); ?>"><?php esc_html_e( 'Already have an account? Sign in to join', 'novawork' ); ?></a>
			<?php endif; ?>
		<?php elseif ( 'lost' === $view['mode'] ) : ?>
			<?php if ( '' === $view['sent'] ) : ?>
				<div class="nw-field">
					<label for="nw-login-user"><?php esc_html_e( 'Email or username', 'novawork' ); ?></label>
					<input class="nw-input" id="nw-login-user" name="user_login" type="text" autocomplete="username" autocapitalize="off" spellcheck="false" required autofocus>
				</div>
				<?php
				/** Fires inside the lost-password form, as on wp-login.php. */
				do_action( 'lostpassword_form' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own hook, fired on purpose.
				wp_nonce_field( (string) $view['nonce'], '_novawork_login', false );
				?>
				<button class="nw-btn nw-primary nw-login-go" type="submit"><?php esc_html_e( 'Send the link', 'novawork' ); ?></button>
			<?php endif; ?>
			<a class="nw-login-back" href="<?php echo esc_url( (string) $view['loginUrl'] ); ?>"><?php esc_html_e( 'Back to sign in', 'novawork' ); ?></a>
		<?php elseif ( 'reset' === $view['mode'] ) : ?>
			<?php if ( $view['badKey'] ) : ?>
				<a class="nw-btn nw-primary nw-login-go" href="<?php echo esc_url( (string) $view['lost'] ); ?>"><?php esc_html_e( 'Get a new link', 'novawork' ); ?></a>
			<?php else : ?>
				<input type="text" name="log" value="<?php echo esc_attr( (string) $view['user'] ); ?>" autocomplete="username" hidden>
				<div class="nw-field">
					<label for="nw-login-pass"><?php esc_html_e( 'New password', 'novawork' ); ?></label>
					<div class="nw-login-pass">
						<input class="nw-input" id="nw-login-pass" name="pass1" type="password" autocomplete="new-password" required autofocus>
						<button type="button" class="nw-login-eye" id="nw-login-eye" aria-controls="nw-login-pass" aria-pressed="false"><?php esc_html_e( 'Show', 'novawork' ); ?></button>
					</div>
				</div>
				<input type="hidden" name="key" value="<?php echo esc_attr( (string) $view['key'] ); ?>">
				<input type="hidden" name="login" value="<?php echo esc_attr( (string) $view['user'] ); ?>">
				<?php wp_nonce_field( (string) $view['nonce'], '_novawork_login', false ); ?>
				<button class="nw-btn nw-primary nw-login-go" type="submit"><?php esc_html_e( 'Save the new password', 'novawork' ); ?></button>
			<?php endif; ?>
			<a class="nw-login-back" href="<?php echo esc_url( (string) $view['loginUrl'] ); ?>"><?php esc_html_e( 'Back to sign in', 'novawork' ); ?></a>
		<?php else : ?>
			<div class="nw-field">
				<label for="nw-login-user"><?php esc_html_e( 'Email or username', 'novawork' ); ?></label>
				<input class="nw-input" id="nw-login-user" name="log" type="text" autocomplete="username" autocapitalize="off" spellcheck="false" required value="<?php echo esc_attr( (string) $view['login'] ); ?>" <?php echo '' === $view['login'] ? 'autofocus' : ''; ?>>
			</div>
			<div class="nw-field">
				<div class="nw-login-row"><label for="nw-login-pass"><?php esc_html_e( 'Password', 'novawork' ); ?></label><a href="<?php echo esc_url( (string) $view['lost'] ); ?>"><?php esc_html_e( 'Forgot password?', 'novawork' ); ?></a></div>
				<div class="nw-login-pass">
					<input class="nw-input" id="nw-login-pass" name="pwd" type="password" autocomplete="current-password" required <?php echo '' !== $view['login'] ? 'autofocus' : ''; ?>>
					<button type="button" class="nw-login-eye" id="nw-login-eye" aria-controls="nw-login-pass" aria-pressed="false"><?php esc_html_e( 'Show', 'novawork' ); ?></button>
				</div>
			</div>
			<label class="nw-login-keep"><input type="checkbox" name="rememberme" value="1" <?php checked( (bool) $view['remember'] ); ?>> <?php esc_html_e( 'Keep me signed in', 'novawork' ); ?></label>
			<?php
			/** Fires inside the sign-in form, as on wp-login.php (captcha and security plugins add fields here). */
			do_action( 'login_form' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own hook, fired on purpose.
			?>
			<?php wp_nonce_field( (string) $view['nonce'], '_novawork_login', false ); ?>
			<input type="hidden" name="hash" id="nw-login-hash" value="">
			<button class="nw-btn nw-primary nw-login-go" type="submit"><?php esc_html_e( 'Sign in', 'novawork' ); ?></button>
		<?php endif; ?>
	</form>
</main>
<?php
// Keep the app view you were heading to (#board…), and Show/Hide the password.
wp_print_inline_script_tag(
	'(function(){var h=document.getElementById("nw-login-hash");if(h)h.value=(location.hash||"").replace(/[^a-z0-9_-]/gi,"");' .
	'var b=document.getElementById("nw-login-eye"),p=document.getElementById("nw-login-pass");if(!b||!p)return;' .
	'b.addEventListener("click",function(){var s=p.type==="password";p.type=s?"text":"password";b.setAttribute("aria-pressed",String(s));b.textContent=s?' . wp_json_encode( __( 'Hide', 'novawork' ) ) . ':' . wp_json_encode( __( 'Show', 'novawork' ) ) . ';p.focus();});})();'
);
?>
</body>
</html>
