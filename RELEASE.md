# Releasing NovaWork on WordPress.org

The owner submits. Never submit, tag or commit to SVN on the owner's behalf.

## 1. Owner decisions (2026-11-22)

| # | Decision | Answer |
|---|---|---|
| 1 | Version of the first release | **1.0.0** (set in the header, `NOVAWORK_VERSION`, readme Stable tag and changelog, package.json; hooks say `@since 1.0.0`). |
| 2 | Fontshare on by default | **On.** Guideline 8 allows fonts from a CDN; the readme discloses it and the setting turns it off. If the reviewer asks for consent first, switch the default to off in `Settings::defaults()` and reply. |
| 3 | `Contributors` in readme.txt | **Both brothers.** Waiting for the exact WordPress.org usernames. |
| 4 | Name and slug | **NovaWork, kept** (owner, after a trademark search on 2026-11-22). USPTO: no "NOVAWORK" marks. TMview: one registered "novaWORK" (NOVAWORK GmbH, Germany) in class 35, business services, not software; a French "NovaWork" in 9/41/42 has ended; the live software-class marks are "NOVAWORKS" (Novaworks LLC via WIPO in 9/41/42, NovaWorks Research in Australia in 9/42, Argentina in 42), a different word held by unrelated companies. The directory has "NovaWorks Low Stock Report for WooCommerce" (different name, slug and product). Before submitting, check that https://wordpress.org/plugins/novawork/ shows "not found". If a reviewer finds a name too close, a fuller display name such as "NovaWork – Team Workspace" separates it without changing the slug. |
| 5 | Directory banner and icon | To design (not needed to submit; sizes in section 4). |

## 2. Before submitting

- [ ] Merge the phase branches into `main` on GitHub. The readme sends people to the public repository for the source, and `main` holds only the starter files today.
- [x] Fontshare link: Fontshare has no terms page; the readme and the privacy text link its license for Satoshi and Supreme, https://www.fontshare.com/licenses/itf-ffl (checked by the owner). The Gravatar and Automattic links were checked too.
- [ ] Retake the screenshots on your Mac, where Fontshare loads, so they show Satoshi and Supreme: `bash bin/seed-dev.sh` on an empty dev workspace, drag one of Alex's four Now tasks to Next (otherwise Focus shows the "4 things in Now" note), then `node bin/screenshots.mjs`.
- [ ] Run every gate (see CLAUDE.md → Gates), then build the zip:
      `bash bin/build-zip.sh` → `build/novawork-<version>.zip`.
- [ ] `composer run plugin-check` reports 0 errors (it checks that same zip).
- [ ] Install the zip on a clean site you have not used for development (Plugins → Add New → Upload) and click through: sign in, Focus, Board, a task with subtasks, chat, the bell, Settings.

## 3. Submitting

1. Sign in to WordPress.org with the account named in `Contributors`, then upload the zip at https://wordpress.org/plugins/developers/add/.
2. Reviews take from a few days to several weeks. The team writes to the account's email address: answer every point, upload a fixed zip with the **same version or a higher one**, and reply to the email.
3. When it is approved you get an SVN repository: `https://plugins.svn.wordpress.org/novawork/`.

## 4. After approval: the first SVN release

```
svn checkout https://plugins.svn.wordpress.org/novawork novawork-svn
```

- `trunk/` ← the contents of `build/release/novawork/` (what the zip holds, without the outer folder).
- `assets/` ← the contents of `.wordpress-org/`:
  - `screenshot-1.png` … `screenshot-10.png` (match the readme's `== Screenshots ==` order),
  - `blueprints/blueprint.json` (the Live Preview; regenerate with `node bin/blueprint.mjs` after changing the demo data),
  - your banner (`banner-772x250.png`, `banner-1544x500.png`) and icon (`icon-128x128.png`, `icon-256x256.png`).
- `tags/<version>/` ← a copy of `trunk/` (`svn cp trunk tags/1.0.0`).
- `svn ci -m "Release 1.0.0"`. One commit per release (guideline 14: no rapid-fire commits).
- On the plugin's page, **Advanced** view: set the Live Preview to public once you have tried it as a committer.

## 5. Guidelines check (2026-11-22)

Checked against the 18 Detailed Plugin Guidelines (from the WordPress/wporg-plugin-guidelines repository) and the Security APIs rules (sanitize input, escape output late, nonces, capability checks, prepared SQL).

| # | Guideline | Status |
|---|---|---|
| 1 | GPLv2-compatible | ✓ GPLv2 or later. Alpine.js (MIT) and Phosphor icons (MIT) are compatible; the fonts are not bundled. |
| 2 | Developer responsible, third-party terms | ✓ Fontshare and Gravatar used as their providers intend. |
| 3 | Stable version in the directory | ✓ After approval: keep trunk and the tag current. |
| 4 | Human-readable code, source available | ✓ The built JS/CSS are minified, which the guideline allows when the source is public: it ships in the plugin (`assets/src`, `package.json`, `vite.config.js`), and the readme links the repository and gives `npm ci && npm run build`. |
| 5 | No trialware | ✓ Nothing locked; no Pro version. |
| 6 | SaaS documented | ✓ Not a service. Outside requests documented in "External services". |
| 7 | No tracking without consent | ✓ No analytics or phoning home. Fontshare (default on, switchable) and Gravatar (WordPress's own "Show Avatars") are disclosed; see decision 2. |
| 8 | No executable code from third parties | ✓ No remote JS/CSS except fonts; updates only from WordPress.org; no iframes. The alerts service worker ships in the plugin. |
| 9 | Nothing illegal or dishonest | ✓ |
| 10 | No credits on the public site | ✓ No "Powered by" or links on the workspace, sign-in or no-access pages. |
| 11 | Don't hijack the admin | ✓ No admin notices or dashboard widgets; one menu and a settings page. |
| 12 | Readme not spam | ✓ 5 tags, no affiliate links, no competitors named. |
| 13 | WordPress's own libraries | ✓ Uses core's `wp-i18n`; bundles none of core's libraries (no jQuery). |
| 14 | Infrequent SVN commits | Process: one commit per release. |
| 15 | Version bumps | Process: see decision 1. |
| 16 | Complete plugin at submission | ✓ |
| 17 | Trademarks | Check: see decision 4. |
| 18 | Directory rights | — |

Security, as reviewers check it:
- Every REST route has a capability check and an object-level check (the API tests fail on any route without one).
- Input is sanitized with `wp_unslash()` + `sanitize_*`; output is escaped late (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`); the app escapes on render.
- Forms carry nonces (sign-in, reset, join, the profile checkbox); REST uses the `wp_rest` nonce.
- All SQL goes through `$wpdb->prepare()` with `%i` for identifiers, or the `$wpdb` helpers.
- Every PHP file refuses direct access (`defined( 'ABSPATH' ) || exit;`, or `WP_UNINSTALL_PLUGIN` in uninstall.php).
- Uploads: type checked, stored outside the Media Library with random names, served only to members.
- PHPCS (WordPress Coding Standards) and Plugin Check on the release zip: 0 errors, 0 warnings.
