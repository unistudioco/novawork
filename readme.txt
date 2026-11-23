=== NovaWork ===
Contributors: unistudio
Tags: project management, kanban, time tracking, team chat, roadmap
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A calm team workspace inside WordPress: projects, a board, a roadmap, decisions, time tracking and team chat.

== Description ==

NovaWork is a calm, focused workspace for a team of 2 to 10 people, running on a WordPress site you already own. Projects, a board, a roadmap, decisions, a focus timer, team chat and notifications, in one quiet app with no subscription and no seat prices.

It is not another everything-tool. Few buttons, no setup project, nothing to configure before the team can start: open the workspace, add a task, press play.

= Your day: Focus =

* **Your three.** Each person keeps a short list in Now (three by default). Everything else waits in Next or Later.
* **One timer, one click.** Press play on a task and the time is tracked on the server, so a reload or a second device never loses or double-counts it.
* **Time away is never counted.** After a while without activity the timer asks "Still working?". Fold your laptop, close the tab or lose your connection, and the timer stops where your device went quiet.
* **Team today** shows who is here, what they are working on and how long they tracked today.
* **Due soon and waiting on a decision**, on one page.

= Work: Board, Projects and Roadmap =

* A board with **Now, Next, Later and Done**. Drag to reorder or move between columns.
* **Subtasks** as a checklist inside a task: tick, rename, give a step to a teammate and drag to reorder. Cards show progress such as 2/5.
* Priorities, due dates, assignees, notes and comments on every task.
* **Projects** with a goal, a colour, start and target dates and a hard deadline.
* A **roadmap** by months or quarters, with today always one click away.

= Decide: Decisions and votes =

* Write down the questions that block work, with context and a date to decide by.
* **One person decides; votes advise.** Ask the team to vote on up to 8 options, then the person named records the decision. The options with the most votes rise to the top (or keep the order you wrote them in).
* Every decision keeps its answer, who decided and when.

= Talk: Chat, comments and notifications =

* **Team chat** that docks to either side, floats anywhere or opens as a sheet on phones. Shift+Enter starts a new line.
* **@mentions** and **/links** to tasks, decisions and projects, in chat and in comments. Reply, edit and delete, with Undo.
* **Files** in chat, kept private: never in the Media Library, served only to signed-in members.
* **Notifications** for what is about you (assigned, mentioned, asked to vote, comments, due dates) and a **Workspace** feed of what the team did.
* **Desktop alerts** while the workspace is open in a background tab, turned on per device from the bell.
* **Quick search** across tasks, projects, decisions and the whole chat history.

= Made for your site =

* **Its own address** (yoursite.com/novawork/ by default, or the home page of a site or subdomain), its own sign-in page and no theme styles leaking in.
* **Your name, icon, logo and accent colour.** Light and dark mode follow the device.
* **Install it as an app** on phones, tablets and computers, with your icon and its own window. No app store.
* **Members are WordPress users.** Invite people with a link or by email, or tick "NovaWork member" on an existing user's profile. Nobody signs up on their own.
* **Every choice is a setting**: tasks in Now, the inactivity reminder, upload size and types, who records decisions, message editing, retention and more.
* **Privacy built in**: personal data export and erasure through WordPress's own tools, suggested privacy-policy text, and an uninstall that removes everything only when you ask it to.
* **A welcome tour** shows each new member around the first time, and anyone can take it again from their menu. Administrators reach the settings from the same menu.
* **Translation ready**, in the member's own language.

= What it is not =

NovaWork is deliberately small. There are no Gantt charts, custom fields, automation builders or client portals, and there won't be. If you need those, a large SaaS tool is the better fit. If your team wants a quiet place to agree on what matters and do it, NovaWork is for you.

= Source code =

NovaWork's JavaScript and CSS are built with Vite. The unminified source ships in the plugin (assets/src, with package.json and vite.config.js), and the full development repository is public: https://github.com/unistudioco/novawork

To build the assets yourself: `npm ci && npm run build`.

== Installation ==

1. In WordPress, go to Plugins → Add New, search for "NovaWork", then install and activate it. Or upload the zip under Plugins → Add New → Upload Plugin.
2. Go to **NovaWork** in the admin menu to open the workspace. Three sample items show you where to start; remove them in one click when you are ready.
3. Invite your team under **NovaWork → Settings → Members**.
4. Optional: choose the workspace's address, name, icon and accent colour in the same settings screen.

Members use the workspace at its own address (yoursite.com/novawork/ by default). They never need to see wp-admin.

== Frequently Asked Questions ==

= Who can use the workspace? =

Members only: administrators, users with the NovaWork Member role, and users whose profile has "NovaWork member" ticked. Everyone else, signed in or not, is kept out of the workspace and its data.

= How do I add my team? =

Go to NovaWork → Settings → Members and create an invite link (anyone with the link can join once) or an invite for an email address. A new person creates their account on the workspace's own page and becomes a member. To give someone who already has an account access, tick "NovaWork member" on their profile under Users.

= Does it need an outside service or an account somewhere? =

No. Everything is stored in your WordPress database and your uploads folder, and the app talks only to your own site. Two optional things load from outside by default: the Satoshi and Supreme typefaces from Fontshare, and members' Gravatar pictures. Both can be turned off; see "External services" below.

= How do changes reach the rest of the team? =

The app checks for changes every few seconds while it is open (a setting) and less often in a background tab. There are no websockets, so it works on ordinary shared hosting.

= What happens to the timer if I close my laptop? =

It stops where your device went quiet, and the time away is not counted. The same happens when the tab is closed or the connection is lost for longer than the inactivity reminder (10 minutes by default). You will see a short note saying what was not counted. Working in other apps with the workspace open in a background tab is fine.

= Why don't I see the option for desktop alerts? =

Browsers only allow alerts on secure (https) sites, and an administrator can switch them off for everyone. On an iPhone or iPad, Safari offers alerts only to web apps added to the Home Screen (iOS 16.4 or later).

= Can members install it as an app? =

Yes. In Chrome, Edge and on Android, choose "Install app" in the team menu. On an iPhone or iPad, open the workspace in Safari and choose Share, then Add to Home Screen; in Safari on a Mac, File, then Add to Dock. The app uses the workspace icon from Branding and opens in its own window. It needs a secure (https) site. An administrator can switch it off in Settings.

= Can the workspace be the whole site, on its own subdomain? =

Yes. Install WordPress on the subdomain, then in NovaWork → Settings → Workspace set "Where it lives" to "The site's home page". You can also turn on "Show only the workspace" so visitors never see the theme.

= Where are files stored? Are they public? =

In a private folder under wp-content/uploads/novawork, with random names and no file extensions. They are never added to the Media Library and are served only through an address that checks the visitor is a signed-in member. On Apache the folder also refuses direct requests; on nginx the unguessable names protect it.

= Does it work with Multisite? =

Yes, per site: each site in a network has its own workspace. A shared workspace across a network is not part of NovaWork.

= Is my theme or another plugin going to break the workspace? =

The workspace prints its own page and does not load your theme's styles, so it looks the same on any theme.

= What happens to our data if we delete the plugin? =

Nothing is removed unless you turn on NovaWork → Settings → Data → "Delete everything when NovaWork is deleted". Deactivating never removes data.

= Is there a Pro version? =

NovaWork is free and complete. Nothing is locked.

== Screenshots ==

1. Focus: your three, the timer, who is here today and what is due.
2. The board, with subtasks, priorities and due dates on every card.
3. A task with its checklist, comments and tracked time.
4. The roadmap by months.
5. Decisions, with the team's vote.
6. Team chat with mentions and links, docked beside the work.
7. Notifications: yours and the team's.
8. Time: who tracked what, by day, week, month or year.
9. Dark mode.
10. Settings, in wp-admin.
11. On a phone, with the timer running: the same workspace, ready to install as an app.

== External services ==

NovaWork stores everything on your own site. It contacts outside services only for these two optional things, and only on the workspace's own pages:

= Fontshare (typefaces) =

The workspace uses the Satoshi and Supreme typefaces, which Fontshare (by Indian Type Foundry) provides only from its own servers. When the setting "Load Satoshi and Supreme from Fontshare" is on (the default), each time someone opens the workspace or its sign-in page their browser requests the stylesheet from api.fontshare.com and the font files from cdn.fontshare.com. Fontshare receives the visitor's IP address and browser details, as with any web request. No other data is sent. Turn the setting off in NovaWork → Settings → Fonts and privacy and the workspace uses the device's own fonts and loads nothing from outside.

Fontshare's license for Satoshi and Supreme (ITF Free Font License): https://www.fontshare.com/licenses/itf-ffl

= Gravatar (profile pictures) =

When Settings → Discussion → "Show Avatars" is on, members' pictures come from Gravatar, a service by Automattic. The browser requests each picture from secure.gravatar.com using a hash of the member's email address (never the address itself). Members without a Gravatar see their initials instead. Turn off "Show Avatars" and nothing is requested.

Gravatar: https://gravatar.com/ – Automattic privacy policy: https://automattic.com/privacy/

== Privacy ==

NovaWork keeps the workspace's data in your WordPress database: tasks, projects, decisions, comments, chat messages and their files, tracked time, votes, notifications and the team feed, each linked to the member who wrote or did it. Members' browsers keep a few preferences in local storage (filters, the chat window's position, the notifications tab and whether desktop alerts are on). The installed app's small worker script stores nothing; it only shows an offline page.

NovaWork works with WordPress's personal data tools (Tools → Export Personal Data and Erase Personal Data), and adds suggested text for your privacy policy to WordPress's policy guide (Settings → Privacy → Policy Guide).

== Changelog ==

= 1.1.0 =
* New: install the workspace as an app on phones, tablets and computers, with the workspace icon and its own window, and a calm offline page. Setting: Installable app.
* New: vote options with the most votes rise to the top, and glide to their new place as votes change. Setting: Order of vote options (most votes first, or as written).
* New: one smooth tooltip for icon buttons and the timer, with the T shortcut, instead of the browser's plain popups.
* Fix: with the workspace open on two devices, "Still working?" no longer appears on one while you work on the other, and never pauses the timer then; it closes by itself when you're active elsewhere. "I'm still working" no longer reopens on Android.
* Fix: the personal data export showed the wrong "Last seen" date.

= 1.0.0 =
* First release: Focus with a server-side timer, the board with subtasks, projects and the roadmap, decisions with team votes, time analytics, team chat with mentions, links and private files, notifications with desktop alerts, quick search, invites, branding, privacy tools and translations.

== Upgrade Notice ==

= 1.1.0 =
Install the workspace as an app, vote options ordered by votes, new tooltips, and a timer fix for teams who use NovaWork on two devices.

= 1.0.0 =
First release.
