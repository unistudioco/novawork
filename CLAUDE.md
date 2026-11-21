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
- **Settings screen** in wp-admin, native look (`.wrap`, `form-table`, core
  buttons), under a real "NovaWork" menu: app slug, idle threshold (10 min),
  poll interval, week start, upload size and types, Fontshare toggle, delete
  data on uninstall (off). A missing checkbox means "off".
- v1 is 100% free, nothing locked. Out of scope (readme roadmap only):
  multiple workspaces, Multisite network mode, email digests, desktop alerts,
  integrations, native apps, AI features, client portal, paid add-ons.

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
- Test users: `alex` / `alex` and `sam` / `sam` (local only). They are
  subscribers until Phase 2 adds the NovaWork Member role. `WP_DEBUG`,
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

0 Understand ✓ · **1 Scaffold** · 2 Data and API · 3 App shell · 4 Features
(Board+Projects → Focus+Time → Roadmap → Decisions+Comments → Chat →
Notifications → Search) · 5 Product layer · 6 Release.
