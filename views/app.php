<?php
/**
 * The NovaWork app document.
 *
 * Mirrors the prototype's markup (design/prototype/src/v3.src.html) with the
 * nw- namespace. Alpine renders the dynamic parts; every string here is
 * translatable and escaped, and the app sets text with x-text.
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
// Apply the saved appearance before anything paints, so dark mode never flashes light.
wp_print_inline_script_tag( 'try{var t=localStorage.getItem("nw.theme");if(t==="light"||t==="dark")document.documentElement.dataset.theme=t}catch(e){}' );
if ( in_array( Assets::FONTS, Assets::styles(), true ) ) {
	echo '<link rel="preconnect" href="https://api.fontshare.com">' . "\n";
	echo '<link rel="preconnect" href="https://cdn.fontshare.com" crossorigin>' . "\n";
}
wp_print_styles( Assets::styles() );
?>
<?php \NovaWork\App\Branding::head(); ?>
</head>
<body class="nw-body" x-data="nwApp" x-cloak>

<header class="nw-hdr" id="nw-hdr" :class="{ 'nw-stuck': stuck, 'nw-compact': compact }">
	<div class="nw-hdr-in">
		<div class="nw-row1">
			<a class="nw-logo" href="#focus" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: the workspace's name. */ __( '%s, Focus', 'novawork' ), \NovaWork\Settings::workspace_name() ) ); ?>"><?php echo \NovaWork\App\Branding::mark_html( 24 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside. ?></a>
			<div class="nw-qs" id="nw-qs" role="search" x-ref="qs" :class="{ 'nw-open': qs.open, 'nw-closing': qs.closing, 'nw-has-q': qs.q.trim() !== '' }">
				<div class="nw-qs-row"><label class="nw-qs-field"><span x-html="ic('magnifying-glass')"></span><input id="nw-qs-in" x-ref="qsIn" type="search" autocomplete="off" placeholder="<?php esc_attr_e( 'Quick search…', 'novawork' ); ?>" aria-label="<?php esc_attr_e( 'Search tasks, projects, decisions and messages', 'novawork' ); ?>" aria-controls="nw-qs-res" x-model="qs.q" @input="qsRun()" @focus="qsFocus()" @keydown="qsKey($event)"><kbd>/</kbd></label><button type="button" id="nw-qs-cancel" class="nw-btn nw-ghost" @click="qsCancel()"><?php esc_html_e( 'Cancel', 'novawork' ); ?></button></div>
				<div class="nw-qs-res" id="nw-qs-res" x-ref="qsRes" x-show="qs.showRes" x-html="qs.html" @click="qsClick($event)"></div>
			</div>
			<div class="nw-right" id="nw-right" x-ref="right">
				<button class="nw-iconbtn" id="nw-qs-btn" type="button" aria-label="<?php esc_attr_e( 'Search', 'novawork' ); ?>" x-html="ic('magnifying-glass', 20)" @click="qsOpenPhone()"></button>
				<div class="nw-tracker" id="nw-tracker" :class="{ 'nw-run': !!myRun() }">
					<button class="nw-tplay" type="button" x-html="myRun() ? icf('pause') : icf('play')" :aria-label="myRun() ? t.pauseTracking : t.startTracking" :title="trackTitle()" data-tip-key="T" @click="toggleTracking()"></button>
					<button class="nw-ttime" type="button" :title="myRun() ? t.timerRunningOpen : t.trackedTodayOpen" @click="go('time')"><span data-since x-show="myRun()" x-text="myRun() ? clock($store.nw.now - myRun().start) : ''"></span><span x-show="!myRun()" x-text="dur(tracked($store.nw.me, dayStart()))"></span></button>
				</div>
				<button class="nw-iconbtn nw-box" id="nw-chat-btn" type="button" aria-label="<?php esc_attr_e( 'Team chat', 'novawork' ); ?>" :aria-expanded="String(chatOpen)" @click="toggleChat()" :class="{ 'nw-on': chatOpen }" x-html="(chatOpen ? icf('chat-circle-dots', 17) : ic('chat-circle-dots', 17)) + ($store.nw.chat.unread ? '<i class=&quot;nw-dot-alert&quot;></i>' : '')"></button>
				<button class="nw-iconbtn nw-box" id="nw-bell-btn" type="button" aria-haspopup="dialog" :aria-expanded="String(notesOpen)" :aria-label="bellLabel()" :class="{ 'nw-on': notesOpen }" x-html="bellHtml()" @click.stop="toggleNotes()"></button>
				<div class="nw-pop nw-notes" id="nw-notes" x-ref="notes" x-show="notesOpen" role="dialog" aria-label="<?php esc_attr_e( 'Notifications', 'novawork' ); ?>" @pointerdown.stop><div id="nw-notes-body"></div></div>
				<span class="nw-sepv" aria-hidden="true"></span>
				<button class="nw-crew" id="nw-crew-btn" type="button" aria-haspopup="dialog" :aria-expanded="String(pop.open)" aria-label="<?php esc_attr_e( 'Team', 'novawork' ); ?>" @click.stop="togglePop()">
					<span class="nw-avs" x-html="crewHtml()"></span><span class="nw-chev" x-html="ic('caret-down', 13)"></span>
				</button>

				<div class="nw-pop" x-ref="pop" x-show="pop.open" role="dialog" aria-label="<?php esc_attr_e( 'Team', 'novawork' ); ?>" @pointerdown.stop>
					<div class="nw-sgrab" data-grab aria-hidden="true"></div>
					<div class="nw-me-row">
						<span class="nw-pres" :class="'nw-is-' + status($store.nw.me)"><span class="nw-avatar nw-lg" :title="meMember().name" :style="cpair(meMember().color)" x-html="avatarInner(meMember())"></span><i class="nw-st-dot"></i></span>
						<div class="nw-me-txt"><span x-text="meMember().name"></span><span class="nw-you"><?php echo esc_html_x( 'You', 'badge next to your own name', 'novawork' ); ?></span>
							<small><template x-if="myRun()"><span><span data-since x-text="clock($store.nw.now - myRun().start)"></span><template x-if="taskOf(myRun())"><span> · <span class="nw-tname" :title="taskOf(myRun()).title" x-text="shortTitle(taskOf(myRun()).title)"></span></span></template></span></template><template x-if="!myRun()"><span x-text="onlineToday()"></span></template></small>
						</div>
						<a class="nw-signout" :href="logoutUrl"><?php esc_html_e( 'Sign out', 'novawork' ); ?></a>
					</div>
					<div class="nw-me-acts">
						<button class="nw-btn nw-primary nw-wide" type="button" @click="toggleTracking()"><span x-html="myRun() ? icf('pause', 15) : icf('play', 15)" style="display:contents"></span><span x-text="myRun() ? t.pauseTracking : t.startTracking"></span></button>
						<button class="nw-iconbtn nw-sq" type="button" aria-label="<?php esc_attr_e( 'Team chat', 'novawork' ); ?>" x-html="$store.nw.chat.unread ? icf('chat-circle-dots', 20) + '<i class=&quot;nw-dot-alert&quot; style=&quot;position:absolute;top:6px;right:6px;width:8px;height:8px;border-radius:50%;background:var(--nw-danger)&quot;></i>' : ic('chat-circle-dots', 20)" @click="closePop(); openChat()"></button>
					</div>
					<div class="nw-sep"></div>
					<div class="nw-crew-list">
						<template x-for="m in others()" :key="m.id">
							<div class="nw-row">
								<span class="nw-pres" :class="'nw-is-' + status(m.id)"><span class="nw-avatar nw-lg" :title="m.name" :style="cpair(m.color)" x-html="avatarInner(m)"></span><i class="nw-st-dot"></i></span>
								<div class="nw-tm"><b x-text="m.name"></b><span><template x-if="runOf(m.id)"><span><span data-since x-text="clock($store.nw.now - runOf(m.id).start)"></span><template x-if="taskOf(runOf(m.id))"><span> · <span class="nw-tname" :title="taskOf(runOf(m.id)).title" x-text="shortTitle(taskOf(runOf(m.id)).title)"></span></span></template></span></template><template x-if="!runOf(m.id)"><span x-text="status(m.id) === 'online' ? t.online : t.away"></span></template></span></div>
								<span class="nw-tstat" x-text="dur(tracked(m.id, dayStart()))"></span>
							</div>
						</template>
					</div>
					<div class="nw-sep"></div>
					<div class="nw-appear"><span><?php esc_html_e( 'Theme', 'novawork' ); ?></span>
						<div class="nw-tabs nw-seg" role="radiogroup" aria-label="<?php esc_attr_e( 'Theme', 'novawork' ); ?>" x-ref="appear">
							<span class="nw-ind" aria-hidden="true"></span>
							<template x-for="o in themes" :key="o.key">
								<button class="nw-tab" type="button" role="radio" :class="{ 'nw-on': theme === o.key }" :aria-checked="String(theme === o.key)" :aria-label="o.label" :title="o.label" x-html="theme === o.key ? icf(o.icon, 16) : ic(o.icon, 16)" @click="setTheme(o.key)"></button>
							</template>
						</div>
					</div>
					<div class="nw-sep"></div>
					<div class="nw-pmenu">
						<button class="nw-pmenu-item" type="button" @click="startTour()"><span x-html="ic('compass', 18)"></span><?php esc_html_e( 'Take the tour', 'novawork' ); ?></button>
						<template x-if="settingsUrl"><a class="nw-pmenu-item" :href="settingsUrl"><span x-html="ic('gear-six', 18)"></span><?php esc_html_e( 'Workspace settings', 'novawork' ); ?></a></template>
					</div>
				</div>
			</div>
		</div>
		<nav class="nw-tabs" id="nw-tabs" x-ref="tabs" aria-label="<?php esc_attr_e( 'Views', 'novawork' ); ?>">
			<span class="nw-ind" aria-hidden="true"></span>
			<template x-for="v in views" :key="v.key">
				<a class="nw-tab" :href="'#' + v.key" :data-view="v.key" :class="{ 'nw-on': $store.nw.view === v.key }" :aria-current="$store.nw.view === v.key ? 'page' : null"><span class="nw-swap" x-html="swapInner(v.icon)"></span><span x-text="v.label"></span><template x-if="v.key === 'decisions'"><span class="nw-count" x-show="openDecisions() > 0" x-text="openDecisions()"></span></template></a>
			</template>
		</nav>
	</div>
</header>

<div class="nw-wrap nw-samples-wrap" x-show="$store.nw.canManage && samples() > 0">
	<div class="nw-samples" role="status"><span class="nw-samples-ic" x-html="ic('sparkle', 18)"></span><p x-text="samplesText()"></p><button class="nw-btn nw-sm" type="button" @click="removeSamples()" :disabled="samplesBusy"><?php esc_html_e( 'Remove them', 'novawork' ); ?></button></div>
</div>
<main class="nw-wrap" id="nw-main"></main>

<nav class="nw-tabs nw-bn" id="nw-bn" x-ref="bn" aria-label="<?php esc_attr_e( 'Views', 'novawork' ); ?>">
	<template x-for="v in views" :key="v.key">
		<a class="nw-tab" :href="'#' + v.key" :data-view="v.key" :class="{ 'nw-on': $store.nw.view === v.key }" :aria-current="$store.nw.view === v.key ? 'page' : null"><span class="nw-swap" x-html="swapInner(v.icon)"></span><span x-text="v.label"></span><template x-if="v.key === 'decisions'"><span class="nw-count" x-show="openDecisions() > 0" x-text="openDecisions()"></span></template></a>
	</template>
</nav>
<div class="nw-fabs" id="nw-fabs">
	<div class="nw-ftimer" id="nw-ftimer" :class="{ 'nw-run': !!myRun() }"><button class="nw-tplay" type="button" x-html="myRun() ? icf('pause') : icf('play')" :aria-label="myRun() ? t.pauseTracking : t.startTracking" @click="toggleTracking()"></button><button class="nw-ttime" type="button" :aria-label="myRun() ? t.timerRunningOpen : t.trackedTodayOpen" @click="go('time')"><span data-since x-show="myRun()" x-text="myRun() ? clock($store.nw.now - myRun().start) : ''"></span><span x-show="!myRun()" x-text="dur(tracked($store.nw.me, dayStart()))"></span></button></div>
	<button class="nw-fab" id="nw-fab" type="button" aria-label="<?php esc_attr_e( 'Create', 'novawork' ); ?>" aria-haspopup="dialog" :aria-expanded="String(fab.open)" x-html="ic('plus', 26)" @click.stop="toggleFab()"></button>
</div>
<div class="nw-sheet-scrim2" id="nw-scrim2" x-ref="scrim2" x-show="scrim2Shown()"></div>

<div class="nw-snapzone nw-left" id="nw-snap-l"></div><div class="nw-snapzone nw-right" id="nw-snap-r"></div>
<div class="nw-sheet-scrim" id="nw-scrim" hidden></div>
<aside class="nw-drawer nw-dock-right" id="nw-chat" hidden aria-label="<?php esc_attr_e( 'Team chat', 'novawork' ); ?>">
	<div class="nw-grab" id="nw-grab" aria-hidden="true"></div>
	<header id="nw-chat-head"><h2><?php esc_html_e( 'Team chat', 'novawork' ); ?><small><?php esc_html_e( 'Drag to move. Drop at an edge to dock.', 'novawork' ); ?></small></h2><button class="nw-iconbtn" type="button" id="nw-chat-find" aria-label="<?php esc_attr_e( 'Search messages', 'novawork' ); ?>" aria-expanded="false"></button><button class="nw-iconbtn" type="button" id="nw-chat-dock"></button><button class="nw-iconbtn" type="button" id="nw-chat-close" aria-label="<?php esc_attr_e( 'Close chat', 'novawork' ); ?>"></button></header>
	<div class="nw-chat-tools" id="nw-chat-tools" hidden>
		<label class="nw-qs-field nw-sm"><span id="nw-chat-q-icon"></span><input id="nw-chat-q" type="search" autocomplete="off" placeholder="<?php esc_attr_e( 'Search messages', 'novawork' ); ?>" aria-label="<?php esc_attr_e( 'Search messages', 'novawork' ); ?>"></label>
		<select id="nw-chat-when" data-nw-sel data-right="1" aria-label="<?php esc_attr_e( 'When', 'novawork' ); ?>"><option value="all"><?php esc_html_e( 'Any time', 'novawork' ); ?></option><option value="today"><?php esc_html_e( 'Today', 'novawork' ); ?></option><option value="yesterday"><?php esc_html_e( 'Yesterday', 'novawork' ); ?></option><option value="7"><?php esc_html_e( 'Last 7 days', 'novawork' ); ?></option><option value="30"><?php esc_html_e( 'Last 30 days', 'novawork' ); ?></option><option value="date"><?php esc_html_e( 'On a date…', 'novawork' ); ?></option></select>
		<input class="nw-input" type="date" id="nw-chat-date" aria-label="<?php esc_attr_e( 'Date', 'novawork' ); ?>" hidden>
		<div class="nw-count" id="nw-chat-count" aria-live="polite"></div>
	</div>
	<div class="nw-msgs" id="nw-msgs"></div>
	<form class="nw-composer" id="nw-chat-form">
		<div class="nw-replying" id="nw-chat-reply" hidden></div>
		<div class="nw-pending" id="nw-pending"></div>
		<div class="nw-line">
			<button class="nw-iconbtn" type="button" id="nw-attach" aria-label="<?php esc_attr_e( 'Attach files', 'novawork' ); ?>"></button>
			<input type="file" id="nw-file-in" multiple hidden>
			<label for="nw-chat-in" class="nw-vh"><?php esc_html_e( 'Message', 'novawork' ); ?></label><textarea class="nw-input nw-grow" id="nw-chat-in" rows="1" maxlength="2000" enterkeyhint="send" placeholder="<?php esc_attr_e( 'Message the team', 'novawork' ); ?>" aria-autocomplete="list" aria-controls="nw-mention-list"></textarea>
			<button class="nw-btn nw-primary" type="submit" aria-label="<?php esc_attr_e( 'Send', 'novawork' ); ?>" id="nw-chat-send"></button>
		</div>
	</form>
</aside>

<div class="nw-pop" x-ref="fabSheet" x-show="fab.open" role="dialog" aria-label="<?php esc_attr_e( 'Create', 'novawork' ); ?>" @pointerdown.stop>
	<div class="nw-sgrab" data-grab aria-hidden="true"></div>
	<div class="nw-sheet-title"><?php esc_html_e( 'Create', 'novawork' ); ?></div>
	<div class="nw-create">
		<button type="button" @click="create('task')"><span class="nw-nic" style="--nw-c:var(--nw-c-blue);--nw-ci:var(--nw-ci-blue)" x-html="ic('check')"></span><span><?php esc_html_e( 'New task', 'novawork' ); ?><small><?php esc_html_e( 'Goes to Now, assigned to you', 'novawork' ); ?></small></span></button>
		<button type="button" @click="create('project')"><span class="nw-nic" style="--nw-c:var(--nw-c-emerald);--nw-ci:var(--nw-ci-emerald)" x-html="ic('map-trifold')"></span><span><?php esc_html_e( 'New project', 'novawork' ); ?><small><?php esc_html_e( 'With dates for the roadmap', 'novawork' ); ?></small></span></button>
		<button type="button" @click="create('decision')"><span class="nw-nic" style="--nw-c:var(--nw-c-amber);--nw-ci:var(--nw-ci-amber)" x-html="ic('scales')"></span><span><?php esc_html_e( 'New decision', 'novawork' ); ?><small><?php esc_html_e( 'A question that blocks work', 'novawork' ); ?></small></span></button>
		<button type="button" @click="create('chat')"><span class="nw-nic" style="--nw-c:var(--nw-c-grape);--nw-ci:var(--nw-ci-grape)" x-html="ic('chat-circle-dots')"></span><span><?php esc_html_e( 'Message the team', 'novawork' ); ?><small><?php esc_html_e( 'Opens the team chat', 'novawork' ); ?></small></span></button>
	</div>
</div>

<dialog id="nw-idle-dlg" aria-labelledby="nw-idle-title" aria-describedby="nw-idle-text"><div class="nw-idle-in"><span class="nw-nic" id="nw-idle-icon" style="--nw-c:var(--nw-c-amber);--nw-ci:var(--nw-ci-amber)"></span><h2 id="nw-idle-title"><?php esc_html_e( 'Still working?', 'novawork' ); ?></h2><p id="nw-idle-text">
<?php
printf(
	/* translators: 1: time without activity, e.g. "10m"; 2: seconds left. */
	esc_html__( 'No activity for %1$s. The timer pauses in %2$ss, and the time you were away won’t be counted.', 'novawork' ),
	'<b id="nw-idle-for">10m</b>',
	'<b id="nw-idle-left">60</b>'
);
?>
</p><div class="nw-idle-acts"><button class="nw-btn" type="button" data-idle="pause"><?php esc_html_e( 'Pause now', 'novawork' ); ?></button><button class="nw-btn nw-primary" type="button" data-idle="resume"><?php esc_html_e( 'I’m still working', 'novawork' ); ?></button></div></div></dialog>
<dialog id="nw-dlg" aria-labelledby="nw-dlg-title"><form class="nw-dlg" id="nw-dlg-form" method="dialog"></form></dialog>

<?php wp_print_scripts( array( Assets::SCRIPT ) ); ?>
</body>
</html>
