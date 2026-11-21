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

- Slug, folder, text domain: `novawork`. Version stays `0.1.0` until release.
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
- **Timer lives on the server** (start time stored): reloads, a second device or
  a closed laptop never lose or double-count time. One timer per user.
- **Attachments are private:** a NovaWork folder under uploads, random names,
  `index.php` + deny rules, served only through an authenticated, capability-
  checked endpoint (works on nginx). Never in the Media Library.
- **Due-date notifications:** WP-Cron, and also checked on sync.
- **Fonts:** Satoshi + Supreme from Fontshare's CDN, **never bundled** (licence).
  Every font reference goes through `--nw-font-head` / `--nw-font-body` with the
  fallback `ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont,
  "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif`. Setting "Load
  Satoshi & Supreme from Fontshare", on by default; off = nothing remote.
  Load it only on the app route. Disclose in readme "External services" and the
  privacy text. The owner handles the WordPress.org review conversation.
- **First run:** 3 sample items that show where to start, removable in one step.
  The prototype's seed data is the owner's real business and never ships.
- **Settings screen** in wp-admin, under a real "NovaWork" menu. **Owner
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
  multiple workspaces, Multisite network mode, email digests, desktop alerts,
  integrations, native apps, AI features, client portal, paid add-ons.

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

## Owner decision, 2026-11-21: toasts with an action

Deleting (a task, project, decision, comment…) still arms on the first click
and deletes on the second, and then shows **"Deleted. Undo"** with an Undo
button. Any toast that carries an action stays up longer than a plain one
(plain 2.6 s; with an action about 6 s, and it waits while hovered or
focused). Undo must really bring the item back. **Toasts stack** (owner,
2026-11-21): newest at the bottom, each rises in and fades out, the others
glide to their places (FLIP in `lib/ui.js`); at most 4, the oldest makes way.

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
`upload_types`, `load_fontshare` (on), `app_slug`, `delete_data` (off); plus
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

## Design rules that bite (full list in the log)

- Tokens: copy the block from `v3.src.html` exactly, light and dark. Blue
  `#2065f5`, cream `#f7f5f2`, ink `#1d1919`, warm greys. Soft surfaces invert in
  dark mode.
- Phosphor regular + fill by state, **never duotone**. No native `<select>`.
- Header tabs: sliding indicator. Every other tab: rounded segmented control.
- **Never left accent bars** (`border-left` stripes, inset left shadows).
- Avatars always the same size; status is a dot, never a ring. "You" is a small
  uppercase badge. Unassigned is `prohibit-inset` (fill).
- Running timer: only the Focus card gets the gradient ring (`::before`, conic,
  `@property`); the real border stays 1.5px so nothing moves. Timer text has a
  min-width (4.2ch header, 3.6ch phone).
- **CSS traps hit four times:** specific, namespaced class names (`.tick`,
  `.bar`, `.focus` collided); scope container selectors to direct children
  (`.assign label > span`); a sticky header is a stacking context.
- Scroll lock under every modal-like overlay, reference-counted
  (`html{overflow:hidden}` + `body{position:fixed;top:-scrollY}`). The docked
  desktop chat is the exception. `prefers-reduced-motion` turns movement off.

## Gates (run them; never assume)

```
composer run lint          # PHPCS: WordPress + PHPCompatibilityWP (8.1-)
composer run analyse       # PHPStan level 6 + WordPress stubs
composer run plugin-check  # bin/plugin-check.sh: Plugin Check on the release copy
composer run test:api      # bin/test-api.sh: REST integration tests, rolled back
bash bin/test-http.sh      # real HTTP: nonce, two-member sync, upload/download
bash bin/seed-dev.sh       # generic demo data on an empty dev workspace
npm run test:browser       # Playwright (installed Chrome): tests/browser/*.spec.js
npm run build              # Vite → assets/dist (manifest.json)
npm run dev                # Vite dev server on :5175
```

- Read gate exit codes from a file, never through `| tail`.
- `bin/plugin-check.sh` builds a release copy from `.distignore` (with
  `composer install --no-dev`) into `build/check/novawork` and checks that, so
  dev files never show as false errors. Plugin Check itself exits 0 even with
  errors; the script counts ERROR rows and fails on any.
- `.distignore` is the single list of what never ships; the Phase 6 zip build
  must use it too. Built assets ship with their source (`assets/src`,
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

- **Never `git commit` unless the owner's latest message literally says
  "commit".** "Go", "go ahead" or a phase summary is not a commit. Include the
  owner's own uncommitted edits in their own commit; never discard them.
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

0 Understand ✓ · 1 Scaffold ✓ · 2 Data and API ✓ · 3 App shell ✓ · 4 Features
(Board+Projects → Focus+Time → Roadmap → Decisions+Comments → Chat →
Notifications → Search) · 5 Product layer · 6 Release.
