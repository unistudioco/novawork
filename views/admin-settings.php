<?php
/**
 * NovaWork → Settings (wp-admin). Labels are printed here; values, saving
 * and errors are handled by assets/src/js/admin.js (Alpine).
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
		<a class="nw-btn nw-primary" :href="site.appUrl" target="_blank" rel="noopener"><?php esc_html_e( 'Open the workspace', 'novawork' ); ?></a>
	</header>

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
		<div x-show="values.location === 'path'">
			<?php $novawork_row_start( 'app_slug', __( 'Address', 'novawork' ), __( 'Letters, numbers and dashes. The old address keeps working and forwards here.', 'novawork' ), 'nw-f-slug' ); ?>
				<div class="nw-affix"><span x-text="homeLabel()" :title="site.home"></span><input class="nw-input" id="nw-f-slug" maxlength="60" x-model="values.app_slug" spellcheck="false"></div>
				<p class="nw-snote" x-show="!site.prettyLinks"><?php esc_html_e( 'Your site uses plain links, so the workspace opens at ?novawork=1 until you choose another structure under Settings → Permalinks.', 'novawork' ); ?></p>
			<?php $novawork_row_end( 'app_slug' ); ?>
		</div>
	</section>

	<section class="nw-scard" aria-labelledby="nw-s-members">
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
	</section>

	<section class="nw-scard" aria-labelledby="nw-s-team">
		<h3 id="nw-s-team"><?php esc_html_e( 'Team and work', 'novawork' ); ?></h3>
		<?php $novawork_row_start( 'now_limit', __( 'Tasks in Now', 'novawork' ), __( 'How many tasks each person keeps in Now before the board warns them.', 'novawork' ), 'nw-f-now' ); ?>
			<div class="nw-unit"><input class="nw-input" id="nw-f-now" type="number" min="1" max="20" x-model.number="values.now_limit"><span><?php esc_html_e( 'per person', 'novawork' ); ?></span></div>
		<?php $novawork_row_end( 'now_limit' ); ?>
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
		<?php $novawork_row_start( 'due_soon_days', __( 'Due date reminders', 'novawork' ), __( 'Tell assignees and deciders this many days ahead. 0 tells them on the day.', 'novawork' ), 'nw-f-due' ); ?>
			<div class="nw-unit"><input class="nw-input" id="nw-f-due" type="number" min="0" max="30" x-model.number="values.due_soon_days"><span><?php esc_html_e( 'days before', 'novawork' ); ?></span></div>
		<?php $novawork_row_end( 'due_soon_days' ); ?>
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
		<?php $novawork_row_start( 'load_fontshare', __( 'Load Satoshi and Supreme from Fontshare', 'novawork' ), __( 'NovaWork’s typefaces come from Fontshare (Indian Type Foundry) each time the workspace opens, which shares the visitor’s IP address with Fontshare. Turn this off to use the device’s own fonts and load nothing from outside your site.', 'novawork' ) ); ?>
			<button type="button" class="nw-switch" role="switch" aria-labelledby="nw-l-load_fontshare" :aria-checked="String(!!values.load_fontshare)" @click="values.load_fontshare = !values.load_fontshare"><span></span></button>
		<?php $novawork_row_end( 'load_fontshare' ); ?>
	</section>

	<section class="nw-scard nw-danger-zone" aria-labelledby="nw-s-data">
		<h3 id="nw-s-data"><?php esc_html_e( 'Data', 'novawork' ); ?></h3>
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
