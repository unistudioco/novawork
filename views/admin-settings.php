<?php
/**
 * NovaWork → Settings (wp-admin). Labels are printed here; values, saving
 * and errors are handled by assets/src/js/settings.js (Alpine).
 *
 * @package NovaWork
 */

defined( 'ABSPATH' ) || exit;

global $wp_locale;

$novawork_members = count( \NovaWork\Members::ids() );
$novawork_users   = admin_url( 'users.php' );
$novawork_days    = array();
for ( $novawork_i = 0; $novawork_i < 7; $novawork_i++ ) {
	$novawork_days[ $novawork_i ] = $wp_locale->get_weekday( $novawork_i );
}

/**
 * One labelled row: the label and help on the left, the control on the right.
 *
 * @param string $novawork_key   Setting the row edits (for the error line).
 * @param string $novawork_label Label text.
 * @param string $novawork_help  Help text ('' for none).
 * @param string $novawork_for   Id of the control the label names ('' for a group).
 */
$novawork_row_start = static function ( string $novawork_key, string $novawork_label, string $novawork_help, string $novawork_for = '' ): void {
	echo '<div class="nw-srow"><div class="nw-srow-l">';
	if ( '' !== $novawork_for ) {
		echo '<label class="nw-slabel" for="' . esc_attr( $novawork_for ) . '">' . esc_html( $novawork_label ) . '</label>';
	} else {
		echo '<span class="nw-slabel" id="' . esc_attr( 'nw-l-' . $novawork_key ) . '">' . esc_html( $novawork_label ) . '</span>';
	}
	if ( '' !== $novawork_help ) {
		echo '<p class="nw-shelp">' . esc_html( $novawork_help ) . '</p>';
	}
	echo '</div><div class="nw-srow-c">';
};
$novawork_row_end   = static function ( string $novawork_key ): void {
	echo '<p class="nw-serr" role="alert" x-show="errors.' . esc_attr( $novawork_key ) . '" x-text="errors.' . esc_attr( $novawork_key ) . '"></p></div></div>';
};
?>
<div class="wrap nw-admin" id="nw-settings" x-data="nwSettings" x-cloak>
	<h1 class="screen-reader-text"><?php esc_html_e( 'NovaWork settings', 'novawork' ); ?></h1>

	<header class="nw-shead">
		<div class="nw-shead-t">
			<span class="nw-smark" aria-hidden="true" x-html="icon('flag-banner-fold', 22, true)"></span>
			<div>
				<h2 x-text="name()"></h2>
				<p><?php esc_html_e( 'Settings for your team’s workspace. Changes apply the next time each member’s app checks in, within seconds.', 'novawork' ); ?></p>
			</div>
		</div>
		<div class="nw-shead-acts">
			<button type="button" class="nw-btn nw-pro-btn" x-show="site.pro" @click="openPro()"><span x-html="icon('sparkle', 15, true)"></span><span x-text="site.pro ? site.pro.label : ''"></span></button>
			<a class="nw-btn nw-primary" :href="site.appUrl" target="_blank" rel="noopener"><?php esc_html_e( 'Open the workspace', 'novawork' ); ?></a>
		</div>
	</header>

	<?php // NovaWork Pro (1.1.0): opens only when the Pro waitlist button is clicked. ?>
	<dialog class="nw-pro-dlg" x-ref="proDlg" aria-labelledby="nw-pro-title" @pointerdown="proDown = $event.target === $el" @click="if ( proDown && $event.target === $el ) { $el.close(); }">
		<div class="nw-pro-in">
			<button type="button" class="nw-pro-x" aria-label="<?php esc_attr_e( 'Close', 'novawork' ); ?>" x-html="icon('x', 18)" @click="$refs.proDlg.close()"></button>
			<span class="nw-pro-mark" aria-hidden="true" x-html="icon('sparkle', 22, true)"></span>
			<h2 id="nw-pro-title"><?php esc_html_e( 'NovaWork Pro is on its way', 'novawork' ); ?></h2>
			<p class="nw-pro-lead"><?php esc_html_e( 'Everything in NovaWork is free and stays free. Pro will add more for growing teams:', 'novawork' ); ?></p>
			<ul class="nw-pro-list">
				<li><span x-html="icon('sparkle', 16)"></span><span><b><?php esc_html_e( 'Spike, an AI teammate', 'novawork' ); ?></b> <?php esc_html_e( 'that checks in on what’s stuck, sums up long threads and weighs in on decisions.', 'novawork' ); ?></span></li>
				<li><span x-html="icon('bell-ringing', 16)"></span><span><b><?php esc_html_e( 'Alerts with the app closed', 'novawork' ); ?></b> <?php esc_html_e( 'and daily or weekly digests by email.', 'novawork' ); ?></span></li>
				<li><span x-html="icon('list-checks', 16)"></span><span><b><?php esc_html_e( 'Recurring tasks', 'novawork' ); ?></b> <?php esc_html_e( 'and time reports for clients.', 'novawork' ); ?></span></li>
				<li><span x-html="icon('calendar-blank', 16)"></span><span><b><?php esc_html_e( 'Slack, Google Calendar and GitHub', 'novawork' ); ?></b> <?php esc_html_e( 'where your team already looks.', 'novawork' ); ?></span></li>
			</ul>
			<p class="nw-pro-note"><?php esc_html_e( 'Join the waitlist for the launch price, kept for life. One email when Pro is ready, nothing else.', 'novawork' ); ?></p>
			<div class="nw-pro-acts">
				<button type="button" class="nw-btn nw-ghost" @click="$refs.proDlg.close()"><?php esc_html_e( 'Not now', 'novawork' ); ?></button>
				<a class="nw-btn nw-primary" :href="site.pro ? site.pro.url : '#'" target="_blank" rel="noopener" @click="$refs.proDlg.close()"><?php esc_html_e( 'Join the waitlist', 'novawork' ); ?> <span x-html="icon('arrow-right', 15)"></span></a>
			</div>
		</div>
	</dialog>

	<section class="nw-scard nw-offer" aria-labelledby="nw-s-offer" x-show="site.fontsOffer">
		<h3 id="nw-s-offer"><?php esc_html_e( 'Use NovaWork’s typefaces?', 'novawork' ); ?></h3>
		<?php $novawork_row_start( 'fonts_offer', __( 'Satoshi and Supreme', 'novawork' ), __( 'NovaWork is designed with these two typefaces. Fontshare (Indian Type Foundry) serves them from its own servers, so each visitor’s browser would share its IP address with Fontshare. Until you turn them on, the workspace uses each device’s own fonts and loads nothing from outside your site. You can change this later under Fonts and privacy.', 'novawork' ) ); ?>
			<div class="nw-offer-acts">
				<button type="button" class="nw-btn nw-primary" :disabled="offerBusy" @click="answerFonts( true )"><?php esc_html_e( 'Turn them on', 'novawork' ); ?></button>
				<button type="button" class="nw-btn nw-ghost" :disabled="offerBusy" @click="answerFonts( false )"><?php esc_html_e( 'No thanks', 'novawork' ); ?></button>
			</div>
		<?php $novawork_row_end( 'fonts_offer' ); ?>
	</section>

	<section class="nw-scard" aria-labelledby="nw-s-workspace">
		<h3 id="nw-s-workspace"><?php esc_html_e( 'Workspace', 'novawork' ); ?></h3>
		<?php $novawork_row_start( 'workspace_name', __( 'Name', 'novawork' ), __( 'Shown in the header, the browser tab and on the sign-in page. Leave it empty to use your site’s title.', 'novawork' ), 'nw-f-name' ); ?>
			<input class="nw-input" id="nw-f-name" maxlength="60" x-model="values.workspace_name" :placeholder="site.title">
		<?php $novawork_row_end( 'workspace_name' ); ?>
		<?php $novawork_row_start( 'location', __( 'Where it lives', 'novawork' ), __( 'Use the home page for a site that is only the workspace, such as work.example.com: visitors see the workspace instead of your theme. wp-admin stays where it is.', 'novawork' ) ); ?>
			<div class="nw-seg" role="radiogroup" aria-labelledby="nw-l-location">
				<button type="button" role="radio" :aria-checked="String(values.location === 'path')" :class="{ 'nw-on': values.location === 'path' }" @click="values.location = 'path'"><?php esc_html_e( 'A page of this site', 'novawork' ); ?></button>
				<button type="button" role="radio" :aria-checked="String(values.location === 'home')" :class="{ 'nw-on': values.location === 'home' }" @click="values.location = 'home'"><?php esc_html_e( 'The site’s home page', 'novawork' ); ?></button>
			</div>
		<?php $novawork_row_end( 'location' ); ?>
		<?php $novawork_row_start( 'workspace_only', __( 'Show only the workspace', 'novawork' ), __( 'Every other page of the site (posts, pages, archives, search, error pages) opens the workspace instead, and search engines are asked to stay out. wp-admin keeps working.', 'novawork' ) ); ?>
			<button type="button" class="nw-switch" role="switch" aria-labelledby="nw-l-workspace_only" :aria-checked="String(!!values.workspace_only)" @click="values.workspace_only = !values.workspace_only"><span></span></button>
		<?php $novawork_row_end( 'workspace_only' ); ?>
		<div x-show="values.location === 'path'">
			<?php $novawork_row_start( 'app_slug', __( 'Address', 'novawork' ), __( 'Letters, numbers and dashes. The old address keeps working and forwards here.', 'novawork' ), 'nw-f-slug' ); ?>
				<div class="nw-affix"><span x-text="homeLabel()" :title="site.home"></span><input class="nw-input" id="nw-f-slug" maxlength="60" x-model="values.app_slug" spellcheck="false"></div>
				<p class="nw-snote" x-show="!site.prettyLinks"><?php esc_html_e( 'Your site uses plain links, so the workspace opens at ?novawork=1 until you choose another structure under Settings → Permalinks.', 'novawork' ); ?></p>
			<?php $novawork_row_end( 'app_slug' ); ?>
		</div>
	</section>

	<section class="nw-scard" aria-labelledby="nw-s-brand">
		<h3 id="nw-s-brand"><?php esc_html_e( 'Branding', 'novawork' ); ?></h3>
		<?php
		$novawork_images = array(
			'brand_icon'      => array( __( 'Icon', 'novawork' ), __( 'Square, shown beside the workspace name and as the browser tab icon. Without one, the NovaWork flag is shown.', 'novawork' ) ),
			'brand_logo'      => array( __( 'Full logo', 'novawork' ), __( 'Optional. A wide logo that replaces the icon and name in the header and on the sign-in page.', 'novawork' ) ),
			'brand_logo_dark' => array( __( 'Full logo for dark mode', 'novawork' ), __( 'Optional. Used in dark mode, when your logo needs a light version.', 'novawork' ) ),
		);
		foreach ( $novawork_images as $novawork_key => $novawork_text ) :
			$novawork_row_start( $novawork_key, $novawork_text[0], $novawork_text[1] );
			?>
			<div class="nw-media" :class="{ 'nw-wide': '<?php echo esc_attr( $novawork_key ); ?>' !== 'brand_icon' }">
				<span class="nw-media-box">
					<img x-show="media.<?php echo esc_attr( $novawork_key ); ?>" :src="media.<?php echo esc_attr( $novawork_key ); ?>" alt="">
					<span x-show="!media.<?php echo esc_attr( $novawork_key ); ?>" class="nw-media-none" x-html="'<?php echo esc_attr( $novawork_key ); ?>' === 'brand_icon' ? icon('flag-banner-fold', 22, true) : ''"></span>
				</span>
				<button type="button" class="nw-btn" @click="chooseImage('<?php echo esc_attr( $novawork_key ); ?>')" x-text="media.<?php echo esc_attr( $novawork_key ); ?> ? changeLabel() : chooseLabel()"></button>
				<button type="button" class="nw-btn nw-ghost" x-show="values.<?php echo esc_attr( $novawork_key ); ?>" @click="removeImage('<?php echo esc_attr( $novawork_key ); ?>')"><?php esc_html_e( 'Remove', 'novawork' ); ?></button>
			</div>
			<?php
			$novawork_row_end( $novawork_key );
		endforeach;
		?>
		<?php $novawork_row_start( 'accent', __( 'Accent colour', 'novawork' ), __( 'Buttons, links, the selected tab and highlights. Lighter and darker shades for light and dark mode are made from it and kept readable.', 'novawork' ) ); ?>
			<div class="nw-swatches" role="radiogroup" aria-labelledby="nw-l-accent">
				<template x-for="c in presets" :key="c">
					<button type="button" role="radio" class="nw-swatch" :style="'--nw-c:' + c" :aria-checked="String(values.accent === c)" :aria-label="c" :title="c" @click="setAccent(c)"><span></span></button>
				</template>
				<label class="nw-swatch nw-swatch-custom" :class="{ 'nw-on': !presets.includes(values.accent) }" title="<?php esc_attr_e( 'Custom colour', 'novawork' ); ?>">
					<input type="color" :value="values.accent" @input="setAccent($event.target.value)" aria-label="<?php esc_attr_e( 'Pick a custom colour', 'novawork' ); ?>">
				</label>
			</div>
			<div class="nw-color">
				<input class="nw-input" type="text" spellcheck="false" maxlength="24" x-model="accentText" @change="readAccent()" @keydown.enter.prevent="readAccent()" aria-label="<?php esc_attr_e( 'Colour as hex or RGB', 'novawork' ); ?>" placeholder="#2065f5">
			</div>
		<?php $novawork_row_end( 'accent' ); ?>
		<?php $novawork_row_start( 'accent_text', __( 'Text on accent', 'novawork' ), __( 'The colour of words and icons on accent buttons and badges. Auto uses white when it reads well, and dark on light colours.', 'novawork' ) ); ?>
			<div class="nw-seg" role="radiogroup" aria-labelledby="nw-l-accent_text">
				<button type="button" role="radio" :aria-checked="String(values.accent_text === 'auto')" :class="{ 'nw-on': values.accent_text === 'auto' }" @click="values.accent_text = 'auto'"><?php esc_html_e( 'Auto', 'novawork' ); ?></button>
				<button type="button" role="radio" :aria-checked="String(values.accent_text === 'white')" :class="{ 'nw-on': values.accent_text === 'white' }" @click="values.accent_text = 'white'"><?php esc_html_e( 'White', 'novawork' ); ?></button>
				<button type="button" role="radio" :aria-checked="String(values.accent_text === 'dark')" :class="{ 'nw-on': values.accent_text === 'dark' }" @click="values.accent_text = 'dark'"><?php esc_html_e( 'Dark', 'novawork' ); ?></button>
			</div>
		<?php $novawork_row_end( 'accent_text' ); ?>
	</section>

	<section class="nw-scard" id="nw-members" aria-labelledby="nw-s-members">
		<h3 id="nw-s-members"><?php esc_html_e( 'Members', 'novawork' ); ?></h3>
		<div class="nw-srow">
			<div class="nw-srow-l">
				<span class="nw-slabel">
					<?php
					/* translators: %d: number of members. */
					echo esc_html( sprintf( _n( '%d member', '%d members', $novawork_members, 'novawork' ), $novawork_members ) );
					?>
				</span>
				<p class="nw-shelp"><?php esc_html_e( 'Members are WordPress users. Give someone the NovaWork Member role, or tick “NovaWork member” on their profile to keep the role they have. Administrators are always members.', 'novawork' ); ?></p>
			</div>
			<div class="nw-srow-c"><a class="nw-btn" href="<?php echo esc_url( $novawork_users ); ?>"><?php esc_html_e( 'Manage members in Users', 'novawork' ); ?></a></div>
		</div>
			<div class="nw-srow">
			<div class="nw-srow-l">
				<label class="nw-slabel" for="nw-inv-email"><?php esc_html_e( 'Invite people', 'novawork' ); ?></label>
				<p class="nw-shelp"><?php esc_html_e( 'Create a link for someone to join. Add their email to send it for you, or leave it empty and share the link yourself. Each link works once.', 'novawork' ); ?></p>
			</div>
			<div class="nw-srow-c">
				<input class="nw-input" id="nw-inv-email" type="email" maxlength="100" x-model="invite.email" placeholder="<?php esc_attr_e( 'name@example.com (optional)', 'novawork' ); ?>" @keydown.enter.prevent="createInvite()">
				<label class="nw-check" x-show="invite.email.trim()"><input type="checkbox" x-model="invite.send"> <?php esc_html_e( 'Email the invite', 'novawork' ); ?></label>
				<button type="button" class="nw-btn nw-primary" @click="createInvite()" :disabled="invite.busy"><?php esc_html_e( 'Create invite', 'novawork' ); ?></button>
				<p class="nw-serr" role="alert" x-show="invite.error" x-text="invite.error"></p>
			</div>
		</div>
		<div class="nw-invlink" x-show="invite.link" role="status">
			<p x-text="invite.note"></p>
			<div class="nw-affix"><input class="nw-input" readonly :value="invite.link" @focus="$event.target.select()" aria-label="<?php esc_attr_e( 'Invite link', 'novawork' ); ?>"><button type="button" class="nw-btn" @click="copyInvite()" x-text="copyLabel()"></button></div>
		</div>
		<ul class="nw-invites" x-show="invites.length">
			<template x-for="inv in invites" :key="inv.id">
				<li :class="'nw-inv-' + inv.status">
					<span class="nw-inv-who" x-text="inv.email || anyoneLabel()"></span>
					<span class="nw-inv-state" x-text="inviteState(inv)"></span>
					<button type="button" class="nw-btn nw-ghost" x-show="inv.status === 'open'" @click="revokeInvite(inv)"><?php esc_html_e( 'Revoke', 'novawork' ); ?></button>
				</li>
			</template>
		</ul>
		<?php $novawork_row_start( 'invite_days', __( 'Invites last', 'novawork' ), __( 'After this, an unused link stops working.', 'novawork' ), 'nw-f-invdays' ); ?>
			<div class="nw-unit"><input class="nw-input" id="nw-f-invdays" type="number" min="1" max="90" x-model.number="values.invite_days"><span><?php esc_html_e( 'days', 'novawork' ); ?></span></div>
		<?php $novawork_row_end( 'invite_days' ); ?>
	</section>

	<section class="nw-scard" aria-labelledby="nw-s-team">
		<h3 id="nw-s-team"><?php esc_html_e( 'Team and work', 'novawork' ); ?></h3>
		<?php $novawork_row_start( 'now_limit', __( 'Tasks in Now', 'novawork' ), __( 'How many tasks each person keeps in Now before the board warns them.', 'novawork' ), 'nw-f-now' ); ?>
			<div class="nw-unit"><input class="nw-input" id="nw-f-now" type="number" min="1" max="20" x-model.number="values.now_limit"><span><?php esc_html_e( 'per person', 'novawork' ); ?></span></div>
		<?php $novawork_row_end( 'now_limit' ); ?>
		<?php $novawork_row_start( 'done_shown', __( 'Done on the board', 'novawork' ), __( 'How many recently finished tasks the Done column shows. The rest stay one click away under “Show all”.', 'novawork' ), 'nw-f-done' ); ?>
			<div class="nw-unit"><input class="nw-input" id="nw-f-done" type="number" min="1" max="100" x-model.number="values.done_shown"><span><?php esc_html_e( 'tasks', 'novawork' ); ?></span></div>
		<?php $novawork_row_end( 'done_shown' ); ?>
		<?php $novawork_row_start( 'team_order', __( 'Team today order', 'novawork' ), __( 'How members are ordered on Focus, in the team list and in the header.', 'novawork' ), 'nw-f-order' ); ?>
			<select id="nw-f-order" data-nw-sel data-block="1" x-model="values.team_order">
				<option value="dynamic"><?php esc_html_e( 'Who’s here first, most tracked first', 'novawork' ); ?></option>
				<option value="tracked"><?php esc_html_e( 'Most time tracked today', 'novawork' ); ?></option>
				<option value="online"><?php esc_html_e( 'First online today', 'novawork' ); ?></option>
				<option value="fixed"><?php esc_html_e( 'Always the same order', 'novawork' ); ?></option>
			</select>
		<?php $novawork_row_end( 'team_order' ); ?>
		<?php $novawork_row_start( 'decide_rule', __( 'Who records a decision', 'novawork' ), __( 'Administrators always can.', 'novawork' ) ); ?>
			<div class="nw-seg" role="radiogroup" aria-labelledby="nw-l-decide_rule">
				<button type="button" role="radio" :aria-checked="String(values.decide_rule === 'named')" :class="{ 'nw-on': values.decide_rule === 'named' }" @click="values.decide_rule = 'named'"><?php esc_html_e( 'The person named', 'novawork' ); ?></button>
				<button type="button" role="radio" :aria-checked="String(values.decide_rule === 'anyone')" :class="{ 'nw-on': values.decide_rule === 'anyone' }" @click="values.decide_rule = 'anyone'"><?php esc_html_e( 'Any member', 'novawork' ); ?></button>
			</div>
		<?php $novawork_row_end( 'decide_rule' ); ?>
		<?php $novawork_row_start( 'decision_votes', __( 'Team votes', 'novawork' ), __( 'Let a decision ask the team to vote on options. The vote advises; the decider still decides.', 'novawork' ) ); ?>
			<button type="button" class="nw-switch" role="switch" aria-labelledby="nw-l-decision_votes" :aria-checked="String(!!values.decision_votes)" @click="values.decision_votes = !values.decision_votes"><span></span></button>
		<?php $novawork_row_end( 'decision_votes' ); ?>
		<?php $novawork_row_start( 'vote_order', __( 'Order of vote options', 'novawork' ), __( 'Most votes first lifts the leading option to the top as votes come in; ties keep the order they were written in.', 'novawork' ) ); ?>
			<div class="nw-seg" role="radiogroup" aria-labelledby="nw-l-vote_order">
				<button type="button" role="radio" :aria-checked="String(values.vote_order === 'votes')" :class="{ 'nw-on': values.vote_order === 'votes' }" @click="values.vote_order = 'votes'"><?php esc_html_e( 'Most votes first', 'novawork' ); ?></button>
				<button type="button" role="radio" :aria-checked="String(values.vote_order === 'written')" :class="{ 'nw-on': values.vote_order === 'written' }" @click="values.vote_order = 'written'"><?php esc_html_e( 'As written', 'novawork' ); ?></button>
			</div>
		<?php $novawork_row_end( 'vote_order' ); ?>
		<?php $novawork_row_start( 'due_soon_days', __( 'Due date reminders', 'novawork' ), __( 'Tell assignees and deciders this many days ahead. 0 tells them on the day.', 'novawork' ), 'nw-f-due' ); ?>
			<div class="nw-unit"><input class="nw-input" id="nw-f-due" type="number" min="0" max="30" x-model.number="values.due_soon_days"><span><?php esc_html_e( 'days before', 'novawork' ); ?></span></div>
		<?php $novawork_row_end( 'due_soon_days' ); ?>
		<?php $novawork_row_start( 'desktop_alerts', __( 'Desktop alerts', 'novawork' ), __( 'Members can choose to get a browser alert for their notifications while the workspace is open in a background tab. Each member turns it on for their own device, from the bell.', 'novawork' ) ); ?>
			<button type="button" class="nw-switch" role="switch" aria-labelledby="nw-l-desktop_alerts" :aria-checked="String(!!values.desktop_alerts)" @click="values.desktop_alerts = !values.desktop_alerts"><span></span></button>
		<?php $novawork_row_end( 'desktop_alerts' ); ?>
		<?php $novawork_row_start( 'show_tour', __( 'Welcome tour', 'novawork' ), __( 'Show each member a short tour of the workspace the first time they open it. Anyone can take it again from their menu.', 'novawork' ) ); ?>
			<button type="button" class="nw-switch" role="switch" aria-labelledby="nw-l-show_tour" :aria-checked="String(!!values.show_tour)" @click="values.show_tour = !values.show_tour"><span></span></button>
		<?php $novawork_row_end( 'show_tour' ); ?>
		<?php $novawork_row_start( 'installable', __( 'Installable app', 'novawork' ), __( 'Members can install the workspace as an app on their phone or computer, with its own icon and window. The icon is the workspace icon from Branding.', 'novawork' ) ); ?>
			<button type="button" class="nw-switch" role="switch" aria-labelledby="nw-l-installable" :aria-checked="String(!!values.installable)" @click="values.installable = !values.installable"><span></span></button>
		<?php $novawork_row_end( 'installable' ); ?>
	</section>

	<section class="nw-scard" aria-labelledby="nw-s-time">
		<h3 id="nw-s-time"><?php esc_html_e( 'Time tracking', 'novawork' ); ?></h3>
		<?php $novawork_row_start( 'idle_minutes', __( 'Inactivity reminder', 'novawork' ), __( 'While a timer runs, ask “Still working?” after this long without activity.', 'novawork' ), 'nw-f-idle' ); ?>
			<div class="nw-unit"><input class="nw-input" id="nw-f-idle" type="number" min="1" max="240" x-model.number="values.idle_minutes"><span><?php esc_html_e( 'minutes', 'novawork' ); ?></span></div>
		<?php $novawork_row_end( 'idle_minutes' ); ?>
		<?php $novawork_row_start( 'idle_grace', __( 'Time to answer', 'novawork' ), __( 'Without an answer, the timer stops at the moment activity stopped.', 'novawork' ), 'nw-f-grace' ); ?>
			<div class="nw-unit"><input class="nw-input" id="nw-f-grace" type="number" min="15" max="600" x-model.number="values.idle_grace"><span><?php esc_html_e( 'seconds', 'novawork' ); ?></span></div>
		<?php $novawork_row_end( 'idle_grace' ); ?>
		<?php $novawork_row_start( 'week_start', __( 'Week starts on', 'novawork' ), __( 'For “This week” in Time.', 'novawork' ), 'nw-f-week' ); ?>
			<select id="nw-f-week" data-nw-sel data-block="1" x-model.number="values.week_start">
				<?php foreach ( $novawork_days as $novawork_n => $novawork_day ) : ?>
					<option value="<?php echo esc_attr( (string) $novawork_n ); ?>"><?php echo esc_html( $novawork_day ); ?></option>
				<?php endforeach; ?>
			</select>
		<?php $novawork_row_end( 'week_start' ); ?>
	</section>

	<section class="nw-scard" aria-labelledby="nw-s-chat">
		<h3 id="nw-s-chat"><?php esc_html_e( 'Chat, comments and files', 'novawork' ); ?></h3>
		<?php $novawork_row_start( 'message_edits', __( 'Editing and deleting', 'novawork' ), __( 'Whether members can change or remove their own messages and comments. Administrators can always delete.', 'novawork' ) ); ?>
			<div class="nw-seg" role="radiogroup" aria-labelledby="nw-l-message_edits">
				<button type="button" role="radio" :aria-checked="String(values.message_edits === 'always')" :class="{ 'nw-on': values.message_edits === 'always' }" @click="values.message_edits = 'always'"><?php esc_html_e( 'Always', 'novawork' ); ?></button>
				<button type="button" role="radio" :aria-checked="String(values.message_edits === 'window')" :class="{ 'nw-on': values.message_edits === 'window' }" @click="values.message_edits = 'window'"><?php esc_html_e( 'For a while', 'novawork' ); ?></button>
				<button type="button" role="radio" :aria-checked="String(values.message_edits === 'never')" :class="{ 'nw-on': values.message_edits === 'never' }" @click="values.message_edits = 'never'"><?php esc_html_e( 'Never', 'novawork' ); ?></button>
			</div>
			<div class="nw-unit nw-sub" x-show="values.message_edits === 'window'"><label for="nw-f-edit"><?php esc_html_e( 'For', 'novawork' ); ?></label><input class="nw-input" id="nw-f-edit" type="number" min="1" max="1440" x-model.number="values.edit_minutes"><span><?php esc_html_e( 'minutes after posting', 'novawork' ); ?></span></div>
		<?php $novawork_row_end( 'message_edits' ); ?>
		<?php $novawork_row_start( 'upload_max_mb', __( 'File upload max size', 'novawork' ), '', 'nw-f-upload' ); ?>
			<div class="nw-unit"><input class="nw-input" id="nw-f-upload" type="number" min="1" max="2048" x-model.number="values.upload_max_mb"><span>MB</span></div>
			<p class="nw-snote" x-show="values.upload_max_mb > site.serverMaxMb" x-text="serverLimit()"></p>
		<?php $novawork_row_end( 'upload_max_mb' ); ?>
		<?php $novawork_row_start( 'upload_types', __( 'File types', 'novawork' ), __( 'Extensions members can share, separated by commas. Scripts and web pages are never allowed.', 'novawork' ), 'nw-f-types' ); ?>
			<textarea class="nw-input" id="nw-f-types" rows="3" spellcheck="false" x-model="typesText" @input="readTypes()"></textarea>
		<?php $novawork_row_end( 'upload_types' ); ?>
	</section>

	<section class="nw-scard" aria-labelledby="nw-s-keep">
		<h3 id="nw-s-keep"><?php esc_html_e( 'Housekeeping', 'novawork' ); ?></h3>
		<?php $novawork_row_start( 'poll_seconds', __( 'Check for changes every', 'novawork' ), __( 'How often an open app asks for news. Lower feels more live; higher is lighter on the server.', 'novawork' ), 'nw-f-poll' ); ?>
			<div class="nw-unit"><input class="nw-input" id="nw-f-poll" type="number" min="2" max="60" x-model.number="values.poll_seconds"><span><?php esc_html_e( 'seconds', 'novawork' ); ?></span></div>
		<?php $novawork_row_end( 'poll_seconds' ); ?>
		<?php $novawork_row_start( 'activity_days', __( 'Keep the team feed', 'novawork' ), __( 'Notifications → Workspace.', 'novawork' ), 'nw-f-activity' ); ?>
			<div class="nw-unit"><input class="nw-input" id="nw-f-activity" type="number" min="1" max="3650" x-model.number="values.activity_days"><span><?php esc_html_e( 'days', 'novawork' ); ?></span></div>
		<?php $novawork_row_end( 'activity_days' ); ?>
		<?php $novawork_row_start( 'read_note_days', __( 'Keep read notifications', 'novawork' ), __( 'Unread ones always stay.', 'novawork' ), 'nw-f-notes' ); ?>
			<div class="nw-unit"><input class="nw-input" id="nw-f-notes" type="number" min="7" max="3650" x-model.number="values.read_note_days"><span><?php esc_html_e( 'days', 'novawork' ); ?></span></div>
		<?php $novawork_row_end( 'read_note_days' ); ?>
		<?php $novawork_row_start( 'log_days', __( 'Keep the change history', 'novawork' ), __( 'A device away for longer than this reloads everything when it comes back.', 'novawork' ), 'nw-f-log' ); ?>
			<div class="nw-unit"><input class="nw-input" id="nw-f-log" type="number" min="1" max="365" x-model.number="values.log_days"><span><?php esc_html_e( 'days', 'novawork' ); ?></span></div>
		<?php $novawork_row_end( 'log_days' ); ?>
	</section>

	<section class="nw-scard" aria-labelledby="nw-s-privacy">
		<h3 id="nw-s-privacy"><?php esc_html_e( 'Fonts and privacy', 'novawork' ); ?></h3>
		<?php $novawork_row_start( 'load_fontshare', __( 'Load Satoshi and Supreme from Fontshare', 'novawork' ), __( 'Off by default. When on, NovaWork’s typefaces come from Fontshare (Indian Type Foundry) each time the workspace opens, which shares the visitor’s IP address with Fontshare. When off, the workspace uses the device’s own fonts and loads nothing from outside your site.', 'novawork' ) ); ?>
			<button type="button" class="nw-switch" role="switch" aria-labelledby="nw-l-load_fontshare" :aria-checked="String(!!values.load_fontshare)" @click="values.load_fontshare = !values.load_fontshare"><span></span></button>
		<?php $novawork_row_end( 'load_fontshare' ); ?>
	</section>

	<section class="nw-scard nw-danger-zone" aria-labelledby="nw-s-data">
		<h3 id="nw-s-data"><?php esc_html_e( 'Data', 'novawork' ); ?></h3>
		<div x-show="site.samples > 0">
			<?php $novawork_row_start( 'samples', __( 'Sample items', 'novawork' ), __( 'The project, task and decision NovaWork made on its first run to show where to start. Removing them also removes their comments and votes.', 'novawork' ) ); ?>
				<button type="button" class="nw-btn" @click="removeSamples()" :disabled="samplesBusy" x-text="samplesLabel()"></button>
			<?php $novawork_row_end( 'samples' ); ?>
		</div>
		<?php $novawork_row_start( 'delete_data', __( 'Delete everything when NovaWork is deleted', 'novawork' ), __( 'Tasks, projects, decisions, chat, time, files and settings are removed for good when you delete the plugin. Off: deactivating or deleting keeps them.', 'novawork' ) ); ?>
			<button type="button" class="nw-switch nw-red" role="switch" aria-labelledby="nw-l-delete_data" :aria-checked="String(!!values.delete_data)" @click="values.delete_data = !values.delete_data"><span></span></button>
		<?php $novawork_row_end( 'delete_data' ); ?>
	</section>

	<div class="nw-savebar" :class="{ 'nw-show': dirty() || saving }" role="region" aria-label="<?php esc_attr_e( 'Save changes', 'novawork' ); ?>">
		<span x-text="barText()"></span>
		<button type="button" class="nw-btn nw-ghost" @click="discard()" :disabled="saving"><?php esc_html_e( 'Discard', 'novawork' ); ?></button>
		<button type="button" class="nw-btn nw-primary" @click="save()" :disabled="saving" x-text="saveLabel()"></button>
	</div>
</div>
