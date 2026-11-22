# NovaWork — handoff (2026-11-22)

A free WordPress.org plugin: team workspace (board, focus, time, roadmap,
decisions, chat, notifications). Repo: `/Users/macbookpro/novawork`
(github `unistudioco/novawork`). Dev site: `novawork.local` (Local; the
plugin folder is symlinked to this repo). Brief:
`/Users/macbookpro/novawork-references/START-HERE.md`.

**Start here:** read `CLAUDE.md` in the repo root. It has the stack, every
owner decision made so far, the gates, and dev-environment notes (WP-CLI
paths, test users, the safe uninstall-testing method). This handoff is just
the "where are we" on top of that.

## Where things stand

| Phase | Status |
|---|---|
| 0–4 | ✓ done |
| 5 (Product layer) | ✓ done — settings screen, members' login/invite/reset, workspace-only mode, branding, privacy exporter/eraser + uninstall, i18n (`.pot` + JS translations), sample-data first run |
| 6 (Release) | **next** — readme.txt (full description, FAQ, screenshots, External services), Playground blueprint demo, zip build script, submission checklist |

Last commit: `f4e121a` (branch `phase-5-product`, made from `phase-4-features`).
Everything through Phase 5 is committed. Nothing is pushed to GitHub beyond
what was pushed earlier (main has only the initial commit; `phase-4-features`
on GitHub stops at `a4261cf` — check before assuming remote is current).

A test zip was built by hand (not from a repo script yet) and is on the
Desktop as `novawork.zip` — being tested on a real host as of this writing.

## Phase 6 — what's left

1. **readme.txt**: finish the full description, FAQ, changelog, and the
   "External services" section disclosing Fontshare (fonts) and Gravatar
   (avatars) — required for WordPress.org review.
2. **Playground blueprint**: a `blueprint.json` so people can try NovaWork
   live with sample data, no install.
3. **Zip build script**: turn the manual build into a repeatable
   `bin/build-zip.sh`-style script (pattern already proven — see
   `bin/plugin-check.sh`, which does the same rsync + `--no-dev` composer
   install + strip step; `.distignore` lists what never ships).
4. **Final Plugin Check** against the actual release zip, not just the
   working tree.
5. **Submission checklist** for the owner — he submits to WordPress.org
   himself, never do that step automatically.

## Gates (run before considering anything done)

```
composer run lint        # phpcs
composer run analyse     # phpstan level 6
composer run plugin-check
bash bin/test-api.sh      # PHP API harness, rolled back transaction
bash bin/test-http.sh
npx playwright test       # tests/browser/*.spec.js
```

All green as of `f4e121a`.

## Non-negotiables (owner has been firm on these)

- Never `git commit` unless the user's message literally says "commit".
- Destructive dev-site actions need explicit approval first.
- Never test plugin uninstall via wp-admin → Plugins → Delete — the dev
  plugin folder is a **symlink** to this repo; see CLAUDE.md's uninstall
  test method (backup, `--skip-plugins=novawork`, restore).
- Keep personal data (emails, names) out of requests to outside services.
- Admin dev login is `admin`/`admin` (owner-given, in the gitignored
  `.dev.env` as `NOVAWORK_ADMIN`).

## Open items / things to ask the owner about

- Whether to keep or revert a few dev-site setting changes that showed up
  mid-session (location: home, idle_grace 600, fig/sketch upload types) —
  never confirmed either way; currently left as-is.
- Host test results for the zip — waiting to hear back before starting
  Phase 6 in earnest.
