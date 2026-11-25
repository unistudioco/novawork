# NovaWork

**A calm team workspace that runs inside WordPress.** Projects, a board, a roadmap, decisions, a focus timer with time analytics, team chat and notifications, for teams of 2 to 10, on a WordPress site you already own. No subscription, no seat prices, no outside service.

It is deliberately *not* another everything-tool: few buttons, nothing to configure before the team can start, and a design that stays quiet so the work can be loud.

![Focus: your three, the timer, who is here today and what is due](.wordpress-org/screenshot-1.png)

**[▶ Try the live demo](https://playground.wordpress.net/?mode=seamless&blueprint-url=https://raw.githubusercontent.com/unistudioco/novawork/main/demo/blueprint.json)**: a private copy of NovaWork in your browser, with a demo team and their work. Nothing to install, nothing you do reaches anyone else, and a reload starts fresh.

- **Free and complete.** GPLv2 or later, nothing locked, no Pro version of the core.
- **Yours.** Everything lives in your WordPress database and uploads folder. The app talks only to your own site.
- **Made to feel like a product**, not a plugin page: its own address, sign-in page, branding, light and dark mode, and a phone layout built for touch.

---

## Contents

[Features](#features) · [The small things](#the-small-things) · [Screenshots](#screenshots) · [Tech stack](#tech-stack) · [How it works](#how-it-works) · [Extending NovaWork](#extending-novawork) · [Install](#install) · [Development](#development) · [Releasing](#releasing) · [Roadmap](#roadmap) · [License and credits](#license-and-credits)

---

## Features

### Focus: your day on one page
- **Your three.** Each person keeps a short list in Now (three by default, a setting). Everything else waits in Next or Later.
- **One timer, one click.** Press play on any task; time is tracked on the server, so a reload or a second device never loses or double-counts it.
- **Team today**: who is here, what they are working on, how long they tracked today.
- **Due in the next three weeks** and **waiting on a decision**, beside your work.

### Work: board, subtasks, projects, roadmap
- A board with **Now, Next, Later and Done**; drag to reorder or move between columns.
- **Subtasks** as a checklist inside a task: tick, rename in place, give a step to a teammate, drag to reorder. Cards show progress such as `2/5`.
- Priorities, due dates, several assignees, notes and comments on every task.
- **Projects** with a goal, colour, start and target dates and a hard deadline, and a **roadmap** by months or quarters.

### Decide: decisions and votes
- Write down the questions that block work, with context and a date to decide by.
- **One person decides; votes advise.** Ask the team to vote on up to 8 options; the person named records the decision, with who and when.
- The options with the most votes rise to the top and glide to their new place as votes come in (or keep the order you wrote).

### Time
- Where the hours went: today, week, month or year, with the daily average, sessions, the top project and a breakdown by person.
- **Time away is never counted.** After a while without activity the timer asks "Still working?". Fold the laptop, close the tab or lose the connection, and the timer stops where the device went quiet, with a note saying what was not counted.

### Talk: chat, comments, notifications
- **Team chat** that docks to either side, floats anywhere, or opens as a sheet on phones; Shift+Enter for a new line.
- **@mentions** and **/links** to tasks, decisions and projects, in chat and comments. Reply, edit and delete, with Undo.
- **Private files** in chat: never in the Media Library, served only to signed-in members.
- **Notifications** in two tabs: *Mine* (assigned, mentioned, asked to vote, comments, due dates) and *Workspace* (the team's activity feed).
- **Desktop alerts** while the workspace is open in a background tab, turned on per device.
- **Quick search** across tasks, projects, decisions and the whole chat history.

### Your site, your workspace
- **Its own address** (`yoursite.com/novawork/`, any slug you like) or the home page of a site or subdomain; its own sign-in, reset and invite pages; an option to show only the workspace.
- **Your name, icon, logo and accent colour.** Every accent tone is derived and kept readable in light and dark.
- **Install it as an app** on Android, iPhone, iPad and desktop, with your icon and its own window, and a calm offline page. No app store.
- **Members are WordPress users.** Invite people with a link or by email, or tick "NovaWork member" on an existing user's profile. Nobody signs up on their own.
- **Every choice an admin could want is a setting**, on a settings screen in NovaWork's own design inside wp-admin.

### Privacy and data
- Personal data export and erasure through WordPress's own tools; suggested privacy-policy text.
- An uninstall that removes everything only when you ask it to.
- Translation ready, in each member's own language.

---

## The small things

The details that make it feel finished:

| | |
|---|---|
| **Undo everywhere** | Deleting a task, project, decision, comment, message or subtask shows "Deleted. Undo", and Undo really brings it back. |
| **Live without websockets** | Changes reach everyone within seconds through one polling endpoint, so it works on ordinary shared hosting. |
| **Keyboard** | <kbd>N</kbd> new task · <kbd>/</kbd> search · <kbd>T</kbd> start or pause the timer · <kbd>Esc</kbd> closes whatever is open. |
| **Honest timer** | A heartbeat on the server plus a sleep check in the page: a folded laptop never keeps tracking. |
| **Flowing gradient** | The running timer's card, the tracked task's card and the phone's floating tracker share one animated ring (off with reduced motion). |
| **Phones** | Bottom sheets with swipe-to-close, a floating tracker, a create button, and a full-screen search. |
| **Dark mode** | Follows the device, with tokens tuned for both themes. |
| **First run** | Three sample items show where to start and leave in one click. |
| **Welcome tour** | A first visit walks through each page with a gliding spotlight; anyone can take it again from their menu (an admin can turn it off). |
| **Tooltips** | One smooth tooltip for icon buttons and the timer (with its <kbd>T</kbd> shortcut), above dialogs too; never on touch. |
| **Settings from the workspace** | Administrators open the settings screen from the team menu, no detour through wp-admin. |

---

## Screenshots

| Board with subtasks | A task |
|---|---|
| ![The board](.wordpress-org/screenshot-2.png) | ![A task with its checklist and comments](.wordpress-org/screenshot-3.png) |
| **Chat beside the work** | **Dark mode** |
| ![Team chat docked beside the board](.wordpress-org/screenshot-6.png) | ![Dark mode](.wordpress-org/screenshot-9.png) |

All ten are in [`.wordpress-org/`](.wordpress-org); `node bin/screenshots.mjs` retakes them.

---

## Tech stack

| Layer | What | Why |
|---|---|---|
| Server | **PHP 8.1+**, typed, PSR-4 (Composer), WordPress Coding Standards | Runs on any modern WordPress host. |
| Platform | **WordPress 6.4+** (tested up to 7.1) | Users, roles, cron, privacy tools, i18n, REST. |
| Data | **Custom tables** via `dbDelta` with a schema version and migrations; every query through `$wpdb->prepare()` | Fast, indexed, and nothing mixed into posts. |
| API | **REST** under `novawork/v1`; cookie auth, nonce, capability and object-level checks on every route | One small, testable surface. |
| Sync | One **polling endpoint** (`GET /sync?since=`) over a change log; presence and the timer heartbeat ride on it | No websockets, no outside service. |
| Front end | **Alpine.js 3** + **Vite 8** (Rolldown) | A light reactive layer, no SPA framework. |
| Styles | **Plain CSS** with custom properties, ported token-for-token from the approved prototype | Exact design, easy to theme. |
| Icons and type | **Phosphor** icons (bundled), **Satoshi** and **Supreme** from Fontshare (optional) | |
| Quality | **PHPStan** level 6, **PHPCS**, **Plugin Check** on the release zip, **REST integration tests** (rolled back), **HTTP tests**, **Playwright** browser tests | Every change goes through the same gates. |

---

## How it works

```mermaid
flowchart LR
  subgraph Browser
    A["Alpine.js app<br/>(views, store)"]
    W["Alerts worker"]
    P["App worker<br/>(offline page only)"]
  end
  subgraph WordPress
    R["REST: novawork/v1<br/>(controllers)"]
    S["/sync<br/>changes + presence + heartbeat"]
    Svc["Services<br/>Timer · Notifier · Activity · Files · Privacy"]
    Repo["Repositories<br/>(all SQL)"]
    DB[("Custom tables<br/>+ change log")]
    Cron["WP-Cron<br/>(hourly)"]
  end
  A -- "writes" --> R
  A -- "polls every few seconds" --> S
  R --> Svc --> Repo --> DB
  S --> Repo
  Cron --> Svc
  A -. "background tab" .-> W
  A -. "installed app" .-> P
```

- **Writes** go through REST controllers, which validate input and check both the capability and the object (who may change this decision?). Repositories hold all SQL and record every write in a **change log**.
- **Reads** come from `GET /sync`: a full snapshot the first time, then only what changed since the last cursor, plus members, presence, unread counts, settings and a fresh nonce.
- The **timer** lives on the server: its start time is stored, and each poll is a sign of life. A timer whose app goes quiet stops at the last sign of life.

Code map: `src/` (PHP: `App`, `Rest`, `Data`, `Services`, `Install`, `Access`, `Admin`), `assets/src/` (JS and CSS source), `views/` (the app, sign-in and settings documents), `tests/` (API, HTTP and browser tests).

---

## Extending NovaWork

NovaWork is built so an add-on can listen and act, including authors that are not people (a planned AI teammate, "Spike", will post in chat and comment like a team member).

- **Agents:** register a non-human author with the `novawork_agents` filter; messages and comments can be written by an agent instead of a user.
- **Actions:** `novawork_task_created|updated`, `novawork_subtask_created|updated|deleted`, `novawork_project_created|updated`, `novawork_decision_created|updated|decided`, `novawork_comment_created|updated|deleted`, `novawork_message_created|updated|deleted`, `novawork_timer_started|stopped`, `novawork_member_joined`, `novawork_settings_saved`, `novawork_loaded`.
- **Team feed:** add rows with `NovaWork\Services\Activity::record()`.

---

## Install

**From WordPress.org** (once approved): Plugins → Add New → search "NovaWork" → Install → Activate. Then open **NovaWork** in the admin menu.

**From this repository:**

```bash
git clone https://github.com/unistudioco/novawork.git
cd novawork
npm ci && composer install
bash bin/build-zip.sh          # → build/novawork-<version>.zip
```

Upload the zip under Plugins → Add New → Upload Plugin.

Requirements: WordPress 6.4+ and PHP 8.1+. Desktop alerts and installing the app need HTTPS.

---

## Development

1. Run a local WordPress (the team uses [Local](https://localwp.com)) and symlink this folder into `wp-content/plugins/novawork`.
2. Copy `.dev.env.example` to `.dev.env` and fill in the site path, MySQL socket and test users (`alex` and `sam`, both NovaWork members).
3. `npm ci && composer install`, then `npm run dev` (with `NOVAWORK_DEV_SERVER` in wp-config.php) or `npm run build`.
4. `bash bin/seed-dev.sh` fills an empty workspace with a generic demo studio.

The gates every change passes:

```bash
composer run lint          # PHPCS: WordPress + PHPCompatibilityWP
composer run analyse       # PHPStan level 6
composer run test:api      # REST integration tests (rolled back)
bash bin/test-http.sh      # real HTTP: nonce, two-member sync, files
npm run test:browser       # Playwright
composer run plugin-check  # Plugin Check on the release zip
```

---

## Releasing

- `bash bin/build-zip.sh` builds `build/novawork-<version>.zip` from `.distignore`, with a production-only autoloader, and refuses to build when the version differs between its four places or a dev file slipped in.
- `composer run plugin-check` checks exactly that zip.
- `node bin/blueprint.mjs` writes the WordPress Playground blueprint for the directory's Live Preview from the same demo data.

---

## Roadmap

**1.1** (built)
- **Tooltips**: one smooth, animated tooltip for every icon button and the time tracker.
- **Vote order**: the leading option rises to the top (a setting: most votes first, or as written).
- **Installable app**: a web app manifest, icons and an offline page, so the workspace installs on Android, iPhone, iPad and desktop with its own icon and window.

**Ideas we are considering**
- Alerts with the workspace closed (Web Push), and a daily or weekly email digest.
- Recurring tasks and task templates.
- A calendar feed of due dates and decision dates.
- A command palette for everything, from the keyboard.
- "Spike", the AI teammate, as a separate add-on.

---

## License and credits

NovaWork is free software under the **GNU General Public License v2 or later**.

- [Alpine.js](https://alpinejs.dev) (MIT) and [Phosphor Icons](https://phosphoricons.com) (MIT), bundled.
- [Satoshi and Supreme](https://www.fontshare.com) by the Indian Type Foundry, loaded from Fontshare under the [ITF Free Font License](https://www.fontshare.com/licenses/itf-ffl) (optional, never bundled).
- Built by [UniStudio](https://unistudio.co), with help from Claude Code.
