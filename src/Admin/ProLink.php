<?php
/**
 * NovaWork Pro on the settings screen: the waitlist today, an upgrade later.
 *
 * @package NovaWork
 */

declare( strict_types = 1 );

namespace NovaWork\Admin;

use NovaWork\Access\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Owner decision 2026-11-25: one button in the settings screen's header,
 * seen only by administrators (the screen needs `novawork_manage`). With
 * kind "waitlist" it opens a short dialog that links to the waitlist page on
 * NovaWork's website; nothing is sent from the plugin. Guidelines 11 and 9:
 * only on the plugin's own settings page, never opens by itself, and says
 * that everything free stays free. When Pro ships, the add-on turns it into
 * "Upgrade to Pro" (or hides it) through the `novawork_pro_link` filter.
 *
 * The same link sits on NovaWork's row in Plugins (owner, 2026-11-26), for
 * those who can manage NovaWork, as plugins on WordPress.org commonly do
 * (guideline 11: an upgrade prompt where it belongs, used sparingly).
 */
final class ProLink {

	/**
	 * The waitlist page on NovaWork's website.
	 */
	public const WAITLIST_URL = 'https://novawork.unistudio.co/pro-waitlist/';

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_filter( 'plugin_action_links_' . plugin_basename( NOVAWORK_FILE ), array( self::class, 'action_links' ) );
	}

	/**
	 * The link on NovaWork's row in Plugins, after WordPress's own (Deactivate).
	 * It opens the page in a new tab; nothing is sent from the site.
	 *
	 * @param array<int|string, string> $links The row's links.
	 * @return array<int|string, string>
	 */
	public static function action_links( array $links ): array {
		$link = current_user_can( Capabilities::MANAGE ) ? self::get() : null;
		if ( null === $link ) {
			return $links;
		}
		$links['novawork_pro'] = sprintf(
			'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s<span class="screen-reader-text"> %3$s</span></a>',
			esc_url( $link['url'] ),
			esc_html( $link['label'] ),
			/* translators: Accessibility text after a link that opens in a new browser tab. */
			esc_html__( '(opens in a new tab)', 'novawork' )
		);
		return $links;
	}

	/**
	 * The button, or null when it is hidden.
	 *
	 * @return array{label: string, url: string, kind: string}|null
	 */
	public static function get(): ?array {
		$link = array(
			'label' => __( 'Pro waitlist', 'novawork' ),
			'url'   => self::WAITLIST_URL,
			'kind'  => 'waitlist',
		);

		/**
		 * Filters the NovaWork Pro button in the settings screen's header, which
		 * only administrators see.
		 *
		 * With 'kind' => 'waitlist' the button opens a short dialog that links to
		 * 'url'; any other kind links straight to 'url' in a new tab. Return false
		 * to hide the button (for example once Pro is installed).
		 *
		 * @since 1.1.0
		 *
		 * @param array<string, string>|false $link The button: label, url and kind.
		 */
		$link = apply_filters( 'novawork_pro_link', $link );
		if ( ! is_array( $link ) || empty( $link['label'] ) || empty( $link['url'] ) ) {
			return null;
		}
		$url = esc_url_raw( (string) $link['url'] );
		if ( '' === $url ) {
			return null;
		}
		return array(
			'label' => sanitize_text_field( (string) $link['label'] ),
			'url'   => $url,
			'kind'  => 'waitlist' === ( $link['kind'] ?? '' ) ? 'waitlist' : 'link',
		);
	}
}
