# NovaWork — Claude Code context

NovaWork is a calm, premium team workspace that runs inside WordPress: projects,
a board, a roadmap, decisions, a focus timer with time analytics, team chat and
notifications, for teams of 2 to 10. It ships **free on WordPress.org**, so it is
a product on thousands of sites, not an internal tool. Positioning: few buttons,
minimal, premium, "not ClickUp".

## Source material

| What | Where |
|---|---|
| Build brief (scope, architecture, release rules, phases) | `/Users/macbookpro/novawork-references/START-HERE.md` |
| Approved prototype, **the visual and interaction spec** | `/Users/macbookpro/novawork-references/design/prototype/novawork.html` |
| Prototype source (tokens, CSS values, behaviour, timings) | `…/design/prototype/src/v3.src.html`, data model in `src/store3.js` |
| Owner design decisions (later bullet wins) | `/Users/macbookpro/novawork-references/design/design-decisions-log.md` |

The prototype is the spec, not the codebase: port its CSS tokens and values
exactly, re-implement its behaviour cleanly. When the plugin and the prototype
disagree visually, the plugin is wrong, unless the owner approved the change.
The prototype has no `<meta name="viewport">` (the claude.ai host injected
one); the app document must have it.

## Identity and prefixes

- Slug, folder, text domain: `novawork`. First public release: `1.0.0` (owner,
  2026-11-22). The version lives in four places that must match (plugin
  header, `NOVAWORK_VERSION`, readme Stable tag, package.json; `bin/build-zip.sh`
  checks); new hooks get `@since` with the release that ships them.
- PHP functions, options, hooks, meta, transients: `novawork_`. Constants:
  `NOVAWORK_`. Namespace: `NovaWork\` → `src/` (PSR-4).
- **Never `nw_` in PHP** (reviewers reject short prefixes). `nw-` is fine for
  CSS classes only, always scoped under the app root.
- REST namespace: `novawork/v1`.

## Stack (locked; ask before adding anything)

- PHP 8.1+ typed code, PSR-4 via Composer, WordPress Coding Standards.
- WordPress 6.4+ ("Tested up to" 7.1; the dev site runs 7.1.5).
- Front end: **Alpine.js 3 + Vite 8** (Rolldown; owner approved the move from
  Vite 5 on 2026-11-21; needs Node `^20.19` or `>=22.12`). Config uses
  `build.rolldownOptions`, not the deprecated `rollupOptions`. **No React, Vue
  or any SPA framework.**
- CSS: plain CSS with custom properties, ported from the prototype, bundled by
  Vite. Not Tailwind.
- No new runtime or dev dependency without asking.

## Architecture (decided)

- **App route:** its own logged-in URL (`/novawork/` by default, slug is a
  setting) that prints its own HTML document, like `wp-login.php`. No theme CSS
  leaks in. Logged-out visitors go to `wp-login.php` and come back. The wp-admin
  menu links to it. The owner runs it on its own subdomain; it must still work
  on any ordinary site.
- **API:** REST under `novawork/v1`, cookie auth + `wp_rest` nonce, a capability
  check **and** an object-level check on every route.
- **Sync:** one polling endpoint, `GET /sync?since=<change id>`, driven by a
  `changes` log. Poll every 3–5 s while visible, back off while hidden.
  Presence rides on the same poll. No websockets, no external services.
- **Data:** custom tables via `dbDelta`, a schema-version option and migrations:
  projects, tasks, task_assignees, comments, decisions, time_entries, messages,
  attachments, notifications, reads, changes. Every orderable table has an
  `order` column. Prepared statements for every query.
- **Authors are not always humans.** The planned Pro add-on is an AI agent,
  "Spike", that posts in chat, comments on decisions and checks in like a team
  lead. Keep authorship (messages, comments, notifications) able to name a
  non-user author, and keep hooks where an add-on can listen and post. Do not
  build Spike in v1.
- **Members are WordPress users** with a NovaWork capability (a "NovaWork
  Member" role, plus caps for administrators). Added and removed in
  **wp-admin → Users, never from inside the app**.
  - **Owner decision 2026-11-21:** a user who already has another role (an
    Editor, say) becomes a member through a "NovaWork member" checkbox on
    their profile screen (wp-admin → Users → Edit), which grants the
    `novawork_access` capability to that user without touching their role.
    WordPress shows one role per user, so switching roles would strip theirs.
    Built in Phase 5 with the settings screen.
- **Avatars:** the member's Gravatar when they have one, otherwise the initial
  on their colour (deep tone + white text in light, bright tone + ink in dark).
  The colour is user meta, auto-assigned from the 8 accents. Respect the site's
  "Show Avatars" setting; Gravatar is disclosed as an external service.
- **Timer lives on the server** (start time stored): reloads or a second
  device never lose or double-count time. One timer per user. **A running
  timer needs a sign of life** (owner, 2026-11-22): see "Timer and time away"
  below; a folded laptop or a closed tab stops it where the app went quiet.
- **Attachments are private:** a NovaWork folder under uploads, random names,
  `index.php` + deny rules, served only through an authenticated, capability-
  checked endpoint (works on nginx). Never in the Media Library.
- **Due-date notifications:** WP-Cron, and also checked on sync.
- **Fonts:** Satoshi + Supreme from Fontshare's CDN, **never bundled** (licence).
  Every font reference goes through `--nw-font-head` / `--nw-font-body` with the
  fallback `ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont,
  "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif`. Setting "Load
  Satoshi & Supreme from Fontshare", **off by default** (WordPress.org review, 2026-11-24: no outside calls until an admin opts in; the settings screen offers it once, `site.fontsOffer`, option `novawork_fonts_offer`); off = nothing remote.
  Load it only on the app route. Disclose in readme "External services" and the
  privacy text. The owner handles the WordPress.org review conversation.
- **First run:** 3 sample items that show where to start, removable in one step.
  The prototype's seed data is the owner's real business and never ships.
- **Settings screen** in wp-admin, under a real "NovaWork" menu. The menu
  sits below WordPress's own items (no position; review 2026-11-24). For an
  administrator NovaWork opens Settings with "Visit Workspace" under it
  (owner, 2026-11-24); a member without settings rights goes to the app.
  Inline JS/CSS goes through `wp_add_inline_script/style` (`Assets::head()`,
  `Assets::footer_script()`), never a printed `<script>`/`<style>` tag. **Owner
  decision 2026-11-21 (replaces the brief's "native look"):** it renders
  inside the normal wp-admin shell (admin menu and admin bar stay, never a
  full-page takeover) the way WordPress 7's Settings → Connectors page does,
  but its content area uses **NovaWork's own UI**: the app's tokens and
  fonts, centred cards, filled borderless fields, segmented controls, custom
  dropdowns, NovaWork buttons. Not `form-table`. Scope every rule under one
  NovaWork root so nothing leaks into the rest of wp-admin. Settings: app slug, idle threshold (10 min),
  poll interval, week start, upload size and types, Fontshare toggle, delete
  data on uninstall (off). A missing checkbox means "off".
- v1 is 100% free, nothing locked. Out of scope (readme roadmap only):
  multiple workspaces, Multisite network mode, email digests, push alerts
  with the app closed (Web Push), integrations, native apps, AI features,
  client portal, paid add-ons. (Desktop alerts while a tab is open are in:
  owner decision 2026-11-22, below.)

## Data and API conventions (Phase 2)

- Code map: `src/Data/*Repository.php` (all SQL; `Repository` base does
  find/insert/update/delete, logs every write to the change log, and owns
  drag ordering), `src/Services/` (Timer, Notifier, Files, Cron),
  `src/Rest/*Controller.php` (routes, validation, object-level rules),
  `src/Install/` (Schema with `VERSION`, Installer), `src/Access/Capabilities.php`,
  `src/Members.php`, `src/Settings.php`.
- The brief's "order column" is **`sort_order`** (`ORDER` is reserved in
  MySQL). Gaps of 1024; a move takes the midpoint and renumbers the list only
  when there is no room.
- Tables are `{$wpdb->prefix}novawork_*`. Every query uses `$wpdb->prepare()`
  with `%i` for identifiers (WP 6.2+), or the `$wpdb` insert/update/delete
  helpers. Repository files carry a file-level phpcs disable for
  DirectQuery/NoCaching only, with the reason.
- API shapes are camelCase like the prototype store (`projectId`, `doneAt`,
  `decideBy`); ids are integers; instants are epoch **milliseconds**; calendar
  dates stay `Y-m-d`. Decision context is `context` (the prototype said
  `options`). Text is stored plain (sanitize_text_field / sanitize_textarea_field);
  the app escapes on output (Alpine `x-text`).
- Writes go through the REST routes; reads come from `GET /sync`. No `since`
  = full snapshot; otherwise changes in (since, latest]. A cursor below the
  prune floor (`novawork_changes_floor`) gets a full snapshot. Each response
  carries members + presence, chat unread, settings and a fresh `nonce`.
- Arg schemas with a custom `sanitize_callback` must also set
  `'validate_callback' => 'rest_validate_request_arg'`, or WordPress skips
  maxLength/enum checks (caught by the tests).
- Authors: comments and messages have `user_id` **or** `agent` (an add-on's
  key, e.g. a future AI teammate). Add-ons register agents with the
  `novawork_agents` filter and listen on `novawork_task_created`,
  `novawork_task_updated`, `novawork_decision_created`,
  `novawork_decision_decided`, `novawork_comment_created`,
  `novawork_message_created`, `novawork_timer_started|stopped`.
- Timer: a running entry has `ended_at NULL` and `running_user = user`; a
  UNIQUE index on `running_user` guarantees one per member. Sessions under 60 s
  are dropped. `POST /timer/stop {endedAt}` ends at the last activity (idle).
- Files: `uploads/novawork/<32 hex>` with no extension; served only by
  `GET /attachments/{id}/file?_wpnonce=` (nosniff, CSP sandbox, images inline,
  everything else as a download). The display name is kept readable; the type
  is checked on a sanitised copy. On nginx the folder is protected by the
  unguessable names only (the API never reveals them).
- Notifications are per recipient, one row each; repeat checks dedupe on
  `(user_id, dedupe_key)`. Due dates are checked hourly by cron and at most
  every 10 minutes on polls.

## App shell conventions (Phase 3)

- Reactive callbacks go through `safeEffect()` (lib/effect.js), never bare
  `Alpine.effect()`: an error thrown in one stops Alpine's shared update
  queue for good and the whole page freezes mid-state.
- Focus on a tablet (owner): below the two-column layout, "Team today" and
  "Due in the next 3 weeks" sit side by side, "Waiting on a decision" spans.

- Code map: `src/App/Route.php` (rewrite `/{slug}/` + `?novawork=1` fallback,
  `auth_redirect()` for visitors), `src/App/Page.php` (prints the document,
  embeds the first sync as `window.novawork`), `src/App/Assets.php` (Vite
  manifest, `wp_print_scripts/styles`, `type="module"`, Fontshare),
  `src/Admin/Menu.php` (top-level menu that redirects to the app),
  `views/app.php` + `views/no-access.php`, `assets/src/js/` (`app.js` entry,
  `store.js`, `sync.js`, `shell.js`, `lib/`), `assets/src/css/`.
- **CSS is the prototype's, mechanically namespaced:** every class, id, custom
  property and keyframe gained `nw-` (`.nw-btn`, `#nw-qs-cancel`,
  `--nw-bg`, `nw-sheet-up`); values and rule order are untouched (the
  conversion round-trips to the original). `tokens.css` holds the tokens,
  `app.css` the rest in the original order. Anything the prototype didn't
  have goes in `additions.css` with a reason. Never hand-edit a ported value.
- **Alpine `<template x-for>` stays in the DOM as its parent's first child.**
  Prototype CSS that uses `:first-child`, `+` or `* + *` on that parent breaks
  (it hid every avatar in the phone header). For such containers build the
  children as an HTML string with `x-html` (escape with `esc()`), as the
  prototype did.
- **Show-then-focus must be synchronous.** Alpine applies `:class` a moment
  later, and focusing a still-hidden field fails, so the next key reaches the
  page (T started the timer). Add the class directly, then focus (`qsShow()`).
- The shell announces intents for Phase 4 as window events: `nw:create`
  `{kind}`, `nw:open` `{kind,id}`, `nw:chat` `{open,search}`.
- Dev server: `define( 'NOVAWORK_DEV_SERVER', 'http://localhost:5175' );` in
  wp-config.php + `npm run dev`. Otherwise the built files load.
- Outside hosts the app contacts: `api.fontshare.com`, `cdn.fontshare.com`
  (setting) and `secure.gravatar.com` (when Settings → Discussion → Show
  Avatars is on). Gravatar answers 404 for members without one; the image is
  then removed and the initials show. All three go in readme "External services".
- i18n (Phase 5): JS strings use `wp.i18n` with a literal 'novawork' domain;
  `wp i18n make-json` must map the source files to the built bundle, or the
  JSON translations won't load for the hashed dist file.
- Demo content for parity screenshots: `bin/seed-dev.sh` (generic studio data,
  refuses to run on a non-empty workspace).

## Owner marks, 2026-11-21 (build with the Phase 5 settings screen)

1. **Workspace name.** "NovaWork" is the plugin's name only. A `workspace_name`
   setting (default: the site title) replaces every visible "NovaWork" in the
   app: header logo text, browser title, login page, no-access page.
2. **Where it lives.** Setting `location`:
   - `path` (default): `site.com/{app_slug}`; the slug is editable (`work`, `team`…).
   - `home`: the workspace is the site's home page (`work.unistudio.co/`). The
     public theme is no longer shown; wp-admin and the REST API are untouched.
     This is the answer for a subdomain: a WordPress install on the subdomain
     with `home` chosen. Serving a subdomain from another install needs
     Multisite/domain mapping, which is out of v1 scope.
   Changing either flushes rewrite rules (FLUSH_OPTION) and keeps old links
   working where possible.
3. **Members' login page** at `{workspace}/login`, in NovaWork's own UI with
   the workspace name: email/username, password, remember me, "Forgot
   password?" (WordPress's reset flow), errors that don't reveal which part was
   wrong. Signs in with `wp_signon()` so login-limiting and 2FA plugins that
   hook core authentication still apply; nonce-protected; rate-limiting left to
   core/security plugins. Signed-out app visits go here instead of
   wp-login.php; logout returns here. A signed-in non-member sees the
   no-access page.

## Phase 5, part 1: settings screen (built 2026-11-22)

- wp-admin → NovaWork → Settings (`Admin\SettingsPage`, cap `novawork_manage`),
  view `views/admin-settings.php`, bundle `js/settings.js` + `css/admin.css`
  (its own Vite entry; tokens + copies of the app's components scoped under
  `.nw-admin`, toasts under the body class `nw-admin-screen`; never import
  app.css there, it styles body/dialog). The cards stay light (wp-admin is
  light). Fontshare loads there only when its setting is on.
- `GET|POST novawork/v1/settings` (`Rest\SettingsController`, admin-only via
  `admin_route()`/`can_manage()`). Every value is checked by
  `Settings::clean()` against `Settings::SCHEMA` (int ranges clamped, choices,
  bools, text, file types never scripts, the address: not reserved, not a
  page's). One bad value saves nothing; errors come back by field.
- Owner marks built: `workspace_name` (`Settings::workspace_name()`: the
  setting, else the site title) in the app header, tab title and no-access
  page; `location` path|home (`Route`: home mode serves the bare home page
  only, posts and ?p= links still reach WordPress); a changed address
  forwards (`novawork_old_slugs`, newest five, 301); the "NovaWork member"
  checkbox on profiles (`Access\Profile`: grants the user cap, keeps the
  role; locked when the role already grants access; admins only).
- Dev admin login: `NOVAWORK_ADMIN` in .dev.env.

## Phase 5, part 2: members' sign-in page (built 2026-11-22)

- `{workspace}/login` (`/login` in home mode; `?novawork=login` with plain
  links) → `App\Login` + `views/login.php`, the app document's look with the
  workspace's name. Signed-out app visits go there (not wp-login.php); the
  #view travels through the form; signing out returns with "You've signed
  out" (`Route::login_url( true )`; `logoutUrl` is decoded from
  wp_logout_url()'s HTML escaping).
- `wp_signon()` so `authenticate`-hooked plugins apply; the form fires core's
  `login_form` for captcha/security fields; nonce `novawork_login`. Wrong
  username/email/password all get one message; another plugin's refusal
  (too many attempts…) is shown as text, status 403. "Forgot password?" is
  WordPress's reset (`wp_lostpassword_url()`).
- The login address under an earlier address (or under the path in home
  mode) forwards (`?novawork=go-login`). `Route::RULES_VERSION` bumps make
  existing sites rebuild rewrite rules once.
- Reset pages: `?action=lost` (retrieve_password(); one answer whether or
  not the account exists) and `?action=rp&key&login` (check_password_reset_key,
  core's `validate_password_reset` hook, reset_password()). A member's reset
  email is rewritten to link there (`retrieve_password_notification_email`);
  anyone else keeps WordPress's link.
- Invites: table `{prefix}novawork_invites` (schema v7; only an HMAC of the
  token is stored), `Services\Invites`, `GET|POST /invites`,
  `DELETE /invites/{id}` (admins), setting `invite_days` (7). The join page
  is `?action=join&invite=…`: a new person gets the Member role and is signed
  in; a signed-in or signing-in person gains access and keeps their role
  ("Already have an account?" carries the invite through sign-in). An
  email-bound invite always uses that address. Feed verb `member_joined`;
  hook `novawork_member_joined`. The link is shown once (Copy falls back to
  execCommand on plain-http sites).
- `workspace_only` (off): `Route::only_workspace()` at template_redirect 1
  sends every other front-end request to the workspace; sitemaps off,
  robots.txt disallows all. wp-admin, REST, ajax, cron, wp-login.php untouched.

## Owner decisions, 2026-11-22: sign-up, demo, reset pages, site mode

- **No open sign-up.** WordPress's "Anyone can register" never makes members.
  Members join by **invite links**: an administrator creates an invite (link
  or email); the person signs up on a NovaWork page and gets the Member role.
- **Demo:** a **WordPress Playground blueprint** (sample data, a demo user
  signed in), which also powers WordPress.org's Live Preview. Phase 6. No
  demo mode inside the plugin.
- **Reset pages** are NovaWork pages ({workspace}/login?action=lost|reset),
  built on WordPress's own reset functions; the reset email links there.
- **Setting "Show only the workspace"** (`workspace_only`, off): every other
  public front-end request (pages, posts, archives, search, 404s, feeds,
  sitemaps) goes to the workspace or its sign-in page; wp-admin, REST,
  admin-ajax, cron and wp-login.php keep working; search engines are told
  not to index.

## Owner decisions, 2026-11-22: branding

- Settings → Branding: a square **icon** (beside the workspace name, and the
  browser tab icon) and an optional wide **full logo** that replaces icon +
  name in the header and on the sign-in pages, with an optional dark-mode
  version. Uploaded through WordPress's media library (`wp.media`).
- With no icon uploaded, the NovaWork flag stays (owner's choice).
- **Accent colour:** preset swatches plus a custom colour (hex or RGB).
  `Branding` derives every accent token (soft, ink, line, on-soft, text on
  accent) for light and dark, keeping text readable (contrast ≥ 4.5); the
  default blue prints nothing, so the prototype's tokens stay exact.
- No live preview. wp-admin keeps NovaWork's own flag and blue.

## Phase 5, part 3: privacy and uninstall (built 2026-11-22)

- `Services\Privacy`: seven exporters (messages, comments, time, votes,
  notifications, team feed, profile; 100 per page) and one eraser (200 rows
  a pass, called until done). The eraser removes the person's messages
  (with files), comments, time, votes, notifications, feed rows, read
  markers, unsent uploads, invites to their address and NovaWork user meta;
  it unassigns them and clears created_by / owner_id / decided_by /
  actor_id / invite links, so shared work stays without their name (it
  says so in its message). Deletes and updates go through the
  repositories, so open apps hear about them.
- Suggested privacy-policy text (Settings → Privacy) covers what is stored,
  retention, browser storage, Fontshare and Gravatar.
- `uninstall.php` → `Install\Uninstaller`: only when `delete_data` is on;
  per site on a network: clears cron, takes access from members (NovaWork
  Members move to the default role), removes the role and caps, drops the
  tables, deletes `novawork_*` options, transients and user meta, and
  deletes uploads/novawork. Never on deactivation.
- Testing uninstall for real (verified 2026-11-22): back up first (Local's
  mysqldump: `wp db export … --socket=$NOVAWORK_MYSQL_SOCKET` with Local's
  mysql bin on PATH; copy uploads/novawork), run uninstall.php with
  `--skip-plugins=novawork` (an active NovaWork reinstalls itself on the
  next load), inspect with `--skip-plugins`, then restore with Local's
  `mysql --socket=… < backup.sql` (`wp db import` ignores the socket).
  Never test from wp-admin → Plugins → Delete: the dev plugin folder is a
  symlink to this repository.

## Phase 5, part 4: translations (built 2026-11-22)

- `composer run make-pot` writes `languages/novawork.pot` (PHP + JS
  source; excludes dist, build, vendor, tests). Every string is a literal
  with the `novawork` domain; JS imports `__`/`_n`/`_x`/`sprintf` from
  `lib/i18n.js` (wp-i18n).
- JS translations are looked up per source file (`novawork-{locale}-{md5(
  path)}.json`, as language packs and `wp i18n make-json` name them). The
  bundles are hashed and span many sources, so the Vite plugin in
  vite.config.js writes `assets/dist/i18n.json` (sources per bundle) and
  `App\Translations` merges their JSON on `pre_load_script_translations`
  (plugin `languages/` first, then wp-content/languages/plugins, which wins).
- Never name a JS source `admin.js`: WP-CLI's make-json mangles it to
  `a.js` (why the settings bundle is `js/settings.js`).
- The app and its REST calls use the member's language (Profile →
  Language): `switch_to_user_locale()` in Route before rendering, and
  `_locale=user` on every `api()` request. The sign-in page uses the site's.
  `switch_to_user_locale()` ignores locales without a core language pack,
  so testing a language needs `wp language core install xx_XX` (remove it
  after).

## Phase 5, part 5: first run (owner decisions 2026-11-22)

- Three sample items (`is_sample = 1`): project "Getting started", a task
  in it in Now (assigned to the admin), and an open decision (voting with
  Monday/Wednesday/Friday when decision votes are on). `Services\Samples`.
- Activation only sets `novawork_samples = due`; the first app load by an
  administrator makes them (so WP-CLI/Playground activations work) and sets
  `made`. Never in a workspace that already has projects, tasks or
  decisions; reactivation never asks again.
- Removal in one step: an admin-only note above the views ("N sample items
  show where to start. Remove them"), counted live from the store, and
  Settings → Data → "Remove N sample items" (only while some remain).
  `DELETE /samples` (admins). Removing works like deleting each item:
  comments, votes, notifications go; timers on the task stop; real tasks in
  the sample project stay, without a project.
- Empty states are the prototype's, verified on an empty workspace.

## Owner decision, 2026-11-21: toasts with an action

Deleting (a task, project, decision, comment…) still arms on the first click
and deletes on the second, and then shows **"Deleted. Undo"** with an Undo
button. Any toast that carries an action stays up longer than a plain one
(plain 2.6 s; with an action about 6 s, and it waits while hovered or
focused). Undo must really bring the item back. **Toasts stack** (owner,
2026-11-21): newest at the bottom, each rises in and fades out, the others
glide to their places (FLIP in `lib/ui.js`); at most 4, the oldest makes way.

Members UI (owner, 2026-11-21): the header strip shows only members who
are here (tracking or online): you next to the chevron, up to two others to
your left, then a grey "+N"; the left avatar sits on top (`row-reverse`, you
first in the markup so phones still show only you). The team popover list
and Focus "Team today" show five rows and half of the sixth (300px), then
scroll. Custom dropdowns with more than 8 options cut off halfway through
the eighth row and scroll. The order of members everywhere (Team today, the popover,
the strip's picks) comes from one function, `lib/team.js` `orderTeam()`. In
dark mode the "+N" circle uses `--nw-line-strong` so it reads against the page.
Browser tests name their tasks with `unique()` and `cleanUp()` removes every
"E2E …" task after each test, even a failed one. Never chain `git commit`
after a gate without checking its exit code.

Browser tests: drag with `pointerDrag()` from `tests/browser/helpers.js`, never
Playwright's `dragTo()` (it scrolls mid-drag and the drag starts on the wrong
card). Test pages use a 1500px-tall viewport so a whole column is visible.

## Owner rule, 2026-11-21: admin choices are settings, never hardcoded

Anything an administrator could reasonably want to choose is an optional
setting with a sensible default (in `Settings::defaults()`), read through
`Settings::get()`, sent to the app in `/sync` `settings` when the app needs
it, and shown on the Phase 5 settings screen. Never a literal in code.

Already settings: `idle_minutes` (10, the "Still working?" threshold),
`poll_seconds` (4), `week_start` (WordPress's), `upload_max_mb` (10),
`upload_types`, `load_fontshare` (off), `app_slug`, `delete_data` (off); plus
the owner's marks above (`workspace_name`, `location`).
Also settings now (2026-11-21): `now_limit` (3 per person; with 3 the
prototype's "three" wording is kept word for word, other values use wording
with the number), `due_soon_days` (3, due/decision notifications),
`idle_grace` (60 s to answer "Still working?"), `log_days` (30, change-log
retention), `read_note_days` (90). Sync sends `nowLimit`, `idleMinutes`,
`idleGrace`, `dueSoonDays`, `pollSeconds`, `weekStart`. Fixed on purpose
(design, not an admin choice): Focus's "Due in the next 3 weeks" list.
`team_order` (owner, 2026-11-21): "Team today" order: `dynamic` (default:
members who are here, tracking or online, first by most tracked today; away
last), `tracked`, `online` (first to come online; uses member
`onlineSince`, set when a member returns after being away), `fixed`.
`message_edits` / `edit_minutes` (owner, 2026-11-21): see "Reply, Edit and
Delete" below.
`activity_days` (30, the Workspace feed's retention): see "Notifications".

## Decisions: who decides, and team votes (owner, 2026-11-21)

- **One person decides; votes advise.** `Capabilities::can_decide()`:
  administrators always; otherwise the member named in "Who decides", or
  anyone when no one is named. Setting `decide_rule` = `named` (default) or
  `anyone`. The server enforces it on `/decide` and on a PATCH that changes
  the status or a decided answer (403 "Only Sam can record this decision.").
  Anyone may still edit the question, context, project and date, and comment.
  The app shows "Waiting on Sam" instead of Decide to everyone else.
- **Votes** (setting `decision_votes`, on): a decision can "Ask the team to
  vote" with up to 8 options. Options live on the decision (`vote_options`
  JSON of `{key, label}`), votes in `{prefix}novawork_decision_votes` (one per
  member). `POST /decisions/{id}/vote {option|null}` casts, changes or
  withdraws. Keys survive edits (same label, or a rename in place); votes for
  removed options are dropped. Voting closes once decided. Opening a vote
  notifies every other member (`vote` notification, once per decision).
  The majority never decides by itself.
- Schema version 2 added these (the in-place upgrade ran on the dev site).
- **Spike (Pro, later; owner 2026-11-21):** the team can ask Spike, the AI
  teammate, to decide, as with any member. Today "Who decides" holds a user
  id only, so Spike will need an agent owner on decisions (an `owner_agent`
  column next to `owner_id`, like the `agent` column on messages and
  comments), `can_decide()` letting the add-on decide for its agent, and a
  hook when a decision is assigned to an agent so the add-on can act on it
  (read the context, the comments and the votes, then post its answer).

## Chat (Phase 4)

- `views/chat.js`: docks left/right (`body.nw-docked-*` pushes the page),
  floats when dragged by its header (snap zones at the edges), bottom sheet
  with swipe-to-close on phones (scroll locked). Position remembered per
  device (`nw.chat`). The header button and the create sheet talk to it with
  `nw:chat` events; it reports `nw:chat-state`.
- The app holds the last 100 messages; older ones load as you scroll up
  (`GET /messages?beforeId`). Search and the date filter run on the server
  (`GET /messages?q&from&to`), so they find everything.
- Files upload as soon as they are picked (`POST /attachments`), checked
  first against `uploadMaxBytes`/`uploadTypes`; Send waits for uploads.
  Images show inline through the private route (`?_wpnonce=`).
- Reading: when the window is open and scrolled to the bottom, `POST
  /chat/read` clears the unread count and chat/mention notifications.
- **@mentions (owner):** `@` suggests members (keyboard: arrows, Enter/Tab,
  Escape); `messages.mentions` stores `[{id, name}]` (schema v3), kept only
  when `@Name` is really in the text and the id is a member. A mentioned
  member gets a `mention` notification instead of `chat`. Mentions render as
  accent text; a mention of you gets the soft accent surface.

## Mentions and links, in chat and comments (owner, 2026-11-21)

- `views/composer.js` attaches to a text field: `@` suggests members, `/`
  suggests tasks, decisions and projects (`/task …`, `/decision …`,
  `/project …` narrow it; a bare `/` offers your current work). The field
  gets the text as typed ("@Sam ", "/Fix the checkout bug "); `payload()`
  sends only ids whose text is still there.
- `Services/References.php` keeps a mention only for a member whose "@Name"
  is in the text, and a link only for an item that exists and whose "/Title"
  is in the text. Stored as typed: `messages.mentions|refs`,
  `comments.mentions|refs` (schema v4).
- `views/rich.js` renders both: mentions as accent text (yours highlighted),
  links as chips with the item's icon and *current* title that open it
  (`data-act="open-ref"` → `nw:open`); a deleted item's chip is struck out.
- Notifications: a mentioned member gets `mention` (instead of chat or
  comment); a linked task's assignees and a linked decision's decider get
  `link` (owner), never twice for one message.

## Reply, Edit and Delete, in chat and comments (owner, 2026-11-21)

- `views/thread.js` is shared by chat and the comment box. Reply puts
  "@Name " in the field (the composer remembers the mention) and a
  "Replying to" bar above it; the post stores `reply_to` (schema v5,
  `messages|comments.reply_to`, `edited_at`). A reply quotes the original
  (`.nw-quote`); clicking it scrolls there, loading older chat history if
  needed, and flashes it. A comment can only reply to a comment on the same
  task or decision.
- Edit happens in place (Enter saves, Escape cancels only the edit). Only
  your own words are editable, never an agent's or someone else's, not even
  by an administrator. An edit tells only newly mentioned people and newly
  linked items. Delete uses the usual Undo; a deleted chat message takes its
  notifications with it. A reply to a deleted message says so.
- Settings: `message_edits` = `always` (default), `window` (for
  `edit_minutes`, 15, after posting) or `never`; one rule for edit and
  delete. Administrators can always delete anyone's message or comment.
  Sync sends `messageEdits`, `editMinutes`. Routes: `PATCH|DELETE
  /messages/{id}`, `PATCH|DELETE /comments/{id}`; `replyTo` on POST.
- Desktop shows the toolbar on hover or focus; on touch screens, tapping a
  message shows it. Message `.nw-msg` carries `data-mid` and `data-kind`.
- The comment box redraws itself from the store (Alpine effect, released
  when the dialog's list leaves the page), so Undo and teammates' comments
  show up while it is open.
- Hooks: `novawork_message_updated|deleted`, `novawork_comment_updated|deleted`.

## Notifications: Mine | Workspace (owner, 2026-11-21)

- The bell opens `views/notes.js` (the prototype's panel: Today / Earlier,
  an icon per kind, unread dots, Mark all as read; a bottom sheet on
  phones). The owner's small segmented filter sits under the title.
- **Mine** (default) is what is about you: assigned, mentioned, asked to
  vote, comments on your work, due dates. **Workspace** holds new chat
  messages (your own `chat` notifications, with read state) plus the team
  feed below (owner). Each tab shows an unread count; Mark all as read marks
  the open tab. Nobody ever sees anyone else's notifications.
- Going to an item (owner): a notification, a feed row or a /link in chat
  goes to the item's page and flashes its card (`nw:goto` in
  views/index.js: Board, Decisions, Roadmap's project cards), never its
  editor. The Board widens its filters (with a toast) or shows all of Done
  when they would hide the task. A chat message opens the chat on it. /links
  inside a dialog's comments still open the item in the dialog.
- Chat notifications carry `files` (first three names) and `count`, so a
  message that is only files reads "Sam shared plan.png".
- **Workspace** is the team's activity feed, read only, from its own table
  `{prefix}novawork_activity` (schema v6), never from other people's
  notifications. `Services/Activity.php` writes one row per event from
  NovaWork's hooks: `task_added`, `task_done`, `project_added`,
  `project_done`, `decision_opened`, `decision_made`, `vote_opened`,
  `commented` (chat is not repeated). The same event on the same object by
  the same person within 10 minutes is one row. Rows keep the title, so a
  deleted item still reads well. Sync sends the newest 50 (`activity`) and
  changes; `GET /activity?beforeId=` pages back as you scroll. Add-ons call
  `Activity::record()`. New hooks for it: `novawork_project_created|updated`,
  `novawork_decision_updated`.
- Setting `activity_days` (30): feed retention, pruned by cron.
- The last tab is remembered per viewer (`nw.notesTab` in localStorage).
- Browser tests clean their feed rows in `tests/browser/teardown.js`.

## Owner fixes and features, 2026-11-22 (after using it as the team's workspace)

- **Dialogs close only on a real backdrop click**: pointerdown and click
  must both land on the `<dialog>` (dialog.js). Selecting text in a field
  and letting go over the backdrop used to close it.
- **No empty wrappers**: Focus slots print `.nw-meta` only when it has
  content. A Board card without a project puts the title in the top row
  beside the timer (`.nw-ctop.nw-ctop-t`) instead of an empty band.
- **Message fields are growing textareas** (`lib/multiline.js`): chat,
  comments and editing in place. Enter sends, Shift+Enter is a new line,
  IME composition and an open @ / suggestion list are respected (attach
  `multiline()` after `attachComposer()`). Up to ~7 lines, then scroll.
- **Toasts live inside an open modal dialog** (`toast()` in lib/ui.js) and
  go back to the page when it closes: a modal makes the page inert, so an
  Undo under a dialog could not be clicked.
- **Desktop alerts** (`views/alerts.js`, `assets/alerts-worker.js`): while
  the workspace is open in a hidden or unfocused tab, a new notification for
  you also shows as a browser alert with the bell's wording; a click focuses
  the tab and opens the item (`openNote()`). Per device, from the bell's
  last row (`nw.alerts` in localStorage); setting `desktop_alerts` (on) lets
  an admin switch the feature off. Needs https (secure context). No outside
  service: alerts come from the /sync poll (hidden tabs poll every 30 s, so
  an alert can lag that much; nothing when no tab is open). The worker only
  shows alerts and handles clicks (Android shows notifications only through
  a worker); it has no fetch handler and caches nothing; its scope is the
  plugin's assets folder. Several tabs alert once (`nw.alertedId` + tag).
  iPhone: only once the workspace is added to the Home Screen (the
  installable app, 1.1.0).
- **Subtasks are a checklist inside a task** (owner's choice over child
  tasks): table `subtasks` (schema v8), `SubtaskRepository` (not logged
  itself; every write touches the parent task, so they travel as
  `task.subtasks` [{id, title, done, doneAt, assigneeId, order}]),
  `Rest\SubtasksController` (`POST /tasks/{id}/subtasks`, `PATCH|DELETE
  /tasks/{id}/subtasks/{sid}`, `POST …/{sid}/move`; every answer is the
  whole task). Up to 50 per task. Since 1.2.0 only the task's editors change
  its checklist (edit rights), and someone given a subtask may tick it.
  Giving someone a subtask sends an `assign` notification about the task
  with `data.subtask`. Deleting a task removes its subtasks; a removed member
  is unassigned; the eraser clears `created_by`. UI: `views/subtasks.js` in
  the task dialog (tick, rename in place, avatar-only assignee dropdown, drag
  to reorder on desktop, remove with Undo that re-adds it in place; a new
  task keeps a draft saved right after it), "2/5" chip on Board cards and
  Focus slots (`subChip()`). Hooks: `novawork_subtask_created|updated|deleted`.

## Timer and time away (owner decision, 2026-11-22)

Reported: with the timer running, folding the laptop and coming back later
left it tracking. The idle rule lived only in the page, and a sleeping
machine runs no code; on waking, "Still working?" counted the whole time
away (all of it on "Yes"), and a closed tab or a flat battery left the timer
running for good. Now:

- **Heartbeat on the server:** every /sync poll is a sign of life
  (`Members::SEEN_META`, written at most every 15 s). `Timer::away_after()`
  is the `idle_minutes` setting, never under 3 minutes (a background tab
  polls only about once a minute). `Timer::stop_if_away()` stops a timer
  whose app has been silent longer than that, ending at the last sign of
  life (the last poll, or the start when later). It runs on the member's own
  poll **before** `Members::touch()` (so the first poll after a folded laptop
  stops it), for everyone on any member's poll (`sweep_away()`, at most once
  a minute) and in the hourly cron. The poll that stopped your timer answers
  with `timerAway` {endedAt, awayMs, taskId}.
- **In the page** (views/idle.js): the one-second ticker notices it slept
  (a gap of 2+ minutes); when the gap reaches the away limit it stops the
  timer at the last moment it was awake, without asking. Either way one
  toast says "Your timer on … stopped at 14:32, when this device went quiet.
  The 2h 10m after that weren't counted." (said once even if page and server
  both notice).
- Shorter gaps and plain inactivity keep the old rule ("Still working?").
- **Activity counts across devices** (owner report, 2026-11-23: the phone
  asked "Still working?" and paused the timer while the laptop was in use;
  on Android "Yes" reopened it). `lib/activity.js` holds this device's last
  activity; `sync.js` sends it as `active` (server ms) when it changed;
  `Members::mark_active()` keeps the latest per member in user meta
  `novawork_last_active` (every 10 s at most, never in the future); `/sync`
  answers `activeAt`. views/idle.js asks only when the member was inactive
  on every device, closes an open question when `activeAt` moves past the
  value it saw when asking (server time, so latency can't fake it), clears
  the Idle Detection API's `idleSince` on "Yes", and on waking polls first
  (`sync.poll()` returns the poll in flight) so time worked elsewhere keeps
  the timer. Tests: tests/browser/devices.spec.js (two devices; takes real
  minutes) and the activity checks in tests/api/run.php.
- Trade-off: tracking needs the workspace open in a browser that keeps the
  tab running. A browser that freezes or discards background tabs (Chrome's
  Memory Saver, phones) makes the app go quiet, and the timer stops there.

## Phase 6: release (built 2026-11-22)

- `bin/build-zip.sh` → `build/novawork-<version>.zip`; `bin/plugin-check.sh`
  checks that zip unpacked (0 errors, 0 warnings). Verified installing the
  zip on a brand-new WordPress.
- `readme.txt` is complete (description, FAQ, screenshots, External
  services for Fontshare and Gravatar, privacy, changelog).
- `.wordpress-org/` (never ships) holds what goes to SVN `assets/`:
  `screenshot-N.png` from `node bin/screenshots.mjs` (needs Fontshare
  reachable for the real fonts) and `blueprints/blueprint.json` from
  `node bin/blueprint.mjs`, which builds the Live Preview from
  `tests/seed/dev-data.php` (one demo; the visitor is the admin, shown as
  Alex). Rerun both after changing the demo data or the UI. The directory's
  icon and banner come from `node bin/wporg-assets.mjs` (the app mark; the
  banner crops screenshot-1.png), with Fontshare reachable for the type.
- Every PHP file starts with `defined( 'ABSPATH' ) || exit;` (after the
  namespace and imports in src/); reviewers flag direct file access.
- `RELEASE.md` (never ships): the owner's decisions, the submission and SVN
  steps, and the guideline-by-guideline check. The owner submits.

## Owner fixes before submitting 1.0.0 (2026-11-23)

- The header keeps its glass while a panel locks the page: `onScroll` in
  shell.js ignores scroll events while `html.nw-scroll-locked` (the pinned
  body reads scrollY 0).
- "Still working?" is a full-width bottom sheet on phones like every dialog
  (`#nw-idle-dlg` width overridden in additions.css).
- The subtask list scrolls after seven and a half rows.
- Setting `done_shown` (8, 1–100; sync `doneShown`): how many recent tasks
  the Board's Done column shows before "Show all N". Done tasks never
  disappear; no separate archive (owner agreed).
- README.md rewritten as the project's showcase (features, the small
  things, stack, architecture, hooks, install, development, roadmap).
- **Welcome tour** (owner, in 1.0.0): `views/tour.js`. It goes to each page
  (hash) and spotlights the part it talks about, like `nw:goto`: a fixed
  spotlight whose huge box-shadow dims the rest, kept inside the free area
  (below the sticky header, above the phone's bottom bar), and a card beside
  it (top or bottom on phones). 11 steps, phone and admin variants. Starts by
  itself once per member: boot `tour.auto` = setting `show_tour` (on) and no
  user meta `novawork_tour_seen`; finishing or skipping calls
  `POST /tour/seen` (`Rest\TourController`). "Take the tour" in the team
  menu replays it (`nw:tour`). Keys: → / Enter next, ← back, Esc leaves; the
  app's shortcuts wait while it is open. Browser tests mark it seen for the
  test members in `tests/browser/setup.js` (globalSetup); tour.spec.js
  resets it on purpose; bin/screenshots.mjs marks it seen first.
- **Workspace settings from the workspace** (owner, in 1.0.0): the team
  menu's last rows (`.nw-pmenu`): "Take the tour" for everyone, "Workspace
  settings" for administrators (boot `settingsUrl`, empty for members).

## Owner requests queued for later phases

- (Built.) Chat @mentions: see "Chat" below.
- (Built.) Notifications with Mine | Workspace: see "Notifications" below.
- (Built in 1.1.0.) Tooltips, vote order, installable app: see "Release
  1.1.0" below.

## Release 1.1.0 (built 2026-11-23, branch `release-1.1.0`)

- **Vote order** (setting `vote_order`: `votes` default | `written`; sync
  `voteOrder`): `votesHTML()` in views/model.js sorts by count, ties keep the
  written order. Options glide to their new place: `data-flip` keys and
  `flipBefore()`/`flipAfter()` around the view redraw in views/index.js
  (FLIP, 360 ms, off with reduced motion).
- **Tooltips** (`lib/tooltip.js`, `mountTooltips()` in app.js): one tooltip
  for anything with `title`, `data-tip`, or an icon-only button/link with an
  `aria-label`. The `title` moves to `data-nw-title` while it shows (no
  browser popup) and comes back after. `data-tip-key="T"` adds a key chip;
  `data-no-tip` opts out. Hover after 380 ms (none between neighbours) or
  keyboard focus; never touch. It is a `popover="manual"` in the top layer,
  so it shows over modal dialogs; `pointer-events: none`. Styles `.nw-tip`
  in additions.css.
- **Installable app** (`App\WebApp`, setting `installable`, on):
  `?novawork=manifest`, `?novawork=icon-{any-192|any-512|mask-512|apple-180}`
  and `?novawork=app-worker`, answered in `Route::maybe_render()` for anyone
  (browsers fetch the manifest without cookies; it holds only what the
  sign-in page shows). Addresses carry `ver` (name, accent, icon, address)
  and are cached for good while current.
  - Icons: the workspace icon (cropped square; white behind it for iOS), or
    the header's mark: black layers `assets/app-icons/shape.png` and
    `flag.png` coloured with the accent and its text colour through GD
    (`IMG_FILTER_COLORIZE`); maskable and Apple versions are edge to edge.
    No GD: the blue `icon-*.png` files. Rebuild the layers with
    `NOVAWORK_CHROME=… node bin/app-icons.mjs`. Never `assets/icons`
    (that name is close to `assets/src/icons`, the Phosphor set).
  - `assets/app-worker.js` (PHP prepends `const NOVAWORK = {pages, offline}`):
    only navigations to the workspace or its sign-in page, network first,
    the offline page when the network fails (it reloads itself when back
    online). Caches nothing, no navigation preload (in home mode its scope
    is the whole site). Scope = the workspace address (the site folder with
    plain links). Turned off, the served worker unregisters itself and the
    page removes it too.
  - `WebApp::head()` (app and sign-in documents): manifest link,
    theme-color metas (page colour, light/dark; `themeColor()` in
    views/webapp.js follows the Theme switch), apple tags and touch icon.
  - The team menu shows "Install app" after `beforeinstallprompt` (the
    browser's banner is held back), "Add to Home Screen" on iPhone/iPad and
    "Add to Dock" in Mac Safari (a hint toast); nothing when already
    installed (`display-mode: standalone`).
  - iPhone alerts: Safari offers notifications only to Home Screen web apps
    (iOS 16.4+). Not verified on a device yet; test before promising it.
- `.nw-pmenu-item > span:first-child` is the icon (muted); a label in a
  `span` keeps the ink colour.
- Time → Today charts the whole day, hours 0 to 23 (owner, 2026-11-23; the
  prototype's 6 to 24 hid time tracked after midnight).
- Container tests: `WP_CLI_ALLOW_ROOT=1` for helpers that run WP-CLI; the
  dev server is `127.0.0.1:8080` (curl with `--connect-to
  novawork.local:80:127.0.0.1:8080 --noproxy novawork.local`).

## Release 1.2.0 (built 2026-11-24, branch `release-1.2.0`)

Owner report: Oussama folded his laptop with the timer running, worked
elsewhere, and found 40 minutes of 2h+ kept; offline, two hours were lost
and Stop did nothing. The rule now: **NovaWork never loses time silently,
and never counts time nobody confirmed.** Scenario matrix and test plan:
the "NovaWork timer: scenarios and rules" doc.

- **Edit rights** (setting `edit_rule`: `own` default | `anyone`; sync
  `editRule`): `Capabilities::can_edit_task()` (creator or assignee) and
  `can_edit_own()` (creator, for projects and decisions); administrators
  always. Checked on every update, move and delete (403
  `novawork_not_your_work`); a decision's decider may still change only its
  outcome (status, answer); someone given a subtask may tick it. App:
  `canEditTask()/canEditOwn()` in views/model.js; someone else's work opens
  read only (`lockFields()` + `closeOnly()` in dialog.js, `.nw-ro`), its
  cards don't drag, the checklist locks itself (`wireSubtasks( id, ro )`).
- **Time editing** (administrators): `Services\TimeEdits` (no overlaps per
  member, nothing in the future, a minute at least; a running session keeps
  running), `POST /time-entries`, `PATCH|DELETE /time-entries/{id}`
  (`can_edit_time()`), hooks `novawork_time_entry_saved|deleted`. Time page:
  "Sessions" lists the whole period (8, then "Show all"), edit buttons and
  "Add time" for administrators (`openSession()` in editors.js; delete is
  immediate on the server, Undo posts it back). `forgetHistory()` refetches
  Month/Year after an edit.
- **"Were you working?"** (setting `recover_time`, on; sync `recoverTime`):
  `Timer::stop( $user, $end, $reason )` with reason `away` (server silence),
  `sleep` (the page woke) or `idle` (no answer) records user meta
  `novawork_pending_stop`; `/sync` sends `pendingStop`;
  `POST /timer/recover {until, keep}` ("Count it", optionally keep
  tracking: the same session runs on) or `POST /timer/dismiss`. Own stop
  only, within 24 h, capped at the next session's start (and then never
  kept running). views/recover.js asks once per page (never over another
  dialog or the tour); answered elsewhere, it closes. With it on, the old
  "weren't counted" toasts stay quiet.
- **"Still working?" as an alert**: `showIdleAlert()/closeIdleAlert()` in
  views/alerts.js (tag `novawork-idle`, actions resume|pause) when the
  question opens and the window isn't in front; alerts-worker.js posts
  `{novaworkIdle}` back, idle.js answers. Needs desktop alerts on for that
  device. An answer from another device must be newer on the server and
  later than the activity the question counts from (`anywhere() > idle.from`).
- **Offline**: sync.js sets `offlineSince` on a network failure (no HTTP
  status) and fires `nw:offline` / `nw:online`; activity is marked sent
  only after it arrived. `stopTracking()` offline queues `nw.queuedStop`
  {start, at}; `flushQueuedStop()` (on `nw:online` and at load) asks the
  server for that session: still running → stop at `at`; stopped meanwhile
  → recover until `at`.
- Tests: tests/browser/rights.spec.js, timer.spec.js (alert test lets the
  click's scroll settle before moving the clock: a scroll is activity), the
  "Edit rights", "Were you working?" and "fix time by hand" API sections.

## Design rules that bite (full list in the log)

- Tokens: copy the block from `v3.src.html` exactly, light and dark. Blue
  `#2065f5`, cream `#f7f5f2`, ink `#1d1919`, warm greys. Soft surfaces invert in
  dark mode.
- Phosphor regular + fill by state, **never duotone**. No native `<select>`.
- Header tabs: sliding indicator. Every other tab: rounded segmented control.
- **Never left accent bars** (`border-left` stripes, inset left shadows).
- Avatars always the same size; status is a dot, never a ring. "You" is a small
  uppercase badge. Unassigned is `prohibit-inset` (fill).
- Running timer: the gradient ring (`::before`, conic, `@property`) is on the
  Focus card and, since 2026-11-23 (owner), on the tracked task's Board card
  and the phone's floating tracker, with the same moving colours on their
  pause buttons (additions.css); the real border stays as it is so nothing
  moves. Timer text has a min-width (4.2ch header, 3.6ch phone).
- **CSS traps hit five times:** specific, namespaced class names (`.tick`,
  `.bar`, `.focus` collided, and on 2026-11-22 the subtask rows' `.nw-sub`
  restyled the quick-search results' `.nw-sub`; subtasks now use
  `.nw-subtask…`); grep a new class name before using it; scope container
  selectors to direct children (`.assign label > span`); a sticky header is a
  stacking context.
- Scroll lock under every modal-like overlay, reference-counted
  (`html{overflow:hidden}` + `body{position:fixed;top:-scrollY}`). The docked
  desktop chat is the exception. `prefers-reduced-motion` turns movement off.

## Gates (run them; never assume)

```
composer run lint          # PHPCS: WordPress + PHPCompatibilityWP (8.1-)
composer run analyse       # PHPStan level 6 + WordPress stubs
bash bin/build-zip.sh      # the release zip: build/novawork-<version>.zip
composer run plugin-check  # bin/plugin-check.sh: Plugin Check on that zip, unpacked
composer run test:api      # bin/test-api.sh: REST integration tests, rolled back
bash bin/test-http.sh      # real HTTP: nonce, two-member sync, upload/download
bash bin/seed-dev.sh       # generic demo data on an empty dev workspace
npm run test:browser       # Playwright (installed Chrome): tests/browser/*.spec.js
npm run build              # Vite → assets/dist (manifest.json)
npm run dev                # Vite dev server on :5175
```

- Read gate exit codes from a file, never through `| tail`.
- `bin/build-zip.sh` (Phase 6) builds the release: `.distignore` decides what
  never ships, vendor/ is rebuilt with `composer install --no-dev`, and it
  refuses to build when the version differs between the plugin header,
  `NOVAWORK_VERSION`, readme's Stable tag and package.json, when a file that
  must ship is missing, or when a dev file slipped in. Output:
  `build/novawork-<version>.zip` (one `novawork/` folder) and
  `build/release/novawork`.
- `bin/plugin-check.sh` builds that zip, unpacks it into `build/check` and
  checks exactly what ships. Plugin Check itself exits 0 even with errors;
  the script counts ERROR rows and fails on any.
- `.distignore` is the single list of what never ships. Built assets ship with their source (`assets/src`,
  `package.json`, `vite.config.js`) and the readme links the public repo.
- Visual parity: Playwright screenshots side by side with the prototype, light
  and dark, 1366px and 390px touch. **Look at the screenshots.**
- Two-user sync test with two real accounts in two browsers.

## Dev environment

- Repo: `/Users/macbookpro/novawork` → `github.com/unistudioco/novawork` (public).
- Site: `http://novawork.local` (Local), plugin symlinked from the repo.
- WP-CLI needs Local's MySQL socket: settings live in `.dev.env` (git-ignored,
  see `.dev.env.example`). Pattern:
  `php -d mysqli.default_socket="$NOVAWORK_MYSQL_SOCKET" "$(command -v wp)" --path="$NOVAWORK_WP_PATH" …`
- Test users: `alex` / `alex` and `sam` / `sam` (local only), both with the
  NovaWork Member role. `WP_DEBUG`,
  `WP_DEBUG_LOG` and `SCRIPT_DEBUG` are on; the log is `wp-content/debug.log`.

## Working agreements

- **Commit and push when work is done and its gates pass** (owner, 2026-11-22,
  replaces "only when the owner says commit"): the owner pulls the branch to
  test, so finished work goes to the phase branch on GitHub without waiting
  for a review. Never commit work whose gates fail. Include the owner's own
  uncommitted edits in their own commit; never discard them.
- Commit trailer: `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
  PR bodies end with `🤖 Generated with [Claude Code](https://claude.com/claude-code)`.
- One phase at a time, each on its own branch (`phase-N-name`). At the end of a
  phase stop, show results with screenshots, wait for review.
- Investigate before fixing; state the root cause, then change code. Ask one
  good question rather than guess.
- Build only what was asked. Ask before any dependency, spending money or
  deploying. Never submit to WordPress.org; the owner does.
- Keep personal data out of any request to an outside service.

## Phases

0 Understand ✓ · 1 Scaffold ✓ · 2 Data and API ✓ · 3 App shell ✓ · 4 Features ✓
(Board+Projects → Focus+Time → Roadmap → Decisions+Comments → Chat →
Notifications → Search) · 5 Product layer ✓ · 6 Release ✓ (built; the owner
submits, see RELEASE.md).

Search (final pass, 2026-11-21): tasks, projects and decisions from the
store; messages from the store plus older ones from `GET /messages?q=`
(debounced; the app holds only the newest 100), shown as plain text (a
/link reads as its title). Every result goes to the item itself: a task,
decision or project to its page and card (`nw:goto`), a message to the chat
on that message. The full-screen search (phones, compact header) fills the
screen with results (the prototype's 70vh cap left a gap).
