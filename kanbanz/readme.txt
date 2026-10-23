=== Kanbanz ===
Contributors: Softaculous, Pagelayer
Tags: kanban, project management, task management, task-tracking, productivity
Requires at least: 5.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Visual kanban boards inside WordPress. Drag-and-drop task management with custom columns, subtasks, roles and optional Deskuss ticket sync.

== Description ==

Kanbanz brings project and task management directly into your WordPress admin. Create a board for any workflow — software development, marketing campaigns, HR onboarding, client work, internal to-dos — and move work across columns by dragging cards.

Everything lives in your own database as standard WordPress content. There is no external service, no account to create, and nothing is sent anywhere.

= Boards that match how your team works =

* Unlimited boards and tasks
* Drag-and-drop cards between columns, with drag-to-reorder inside a column
* Rename, add, reorder and delete columns per board
* WIP limits per column to stop work piling up
* Start from a template — Customer Support, Software Development, Marketing Campaign, HR Onboarding, or a blank board
* Board view or list view, switchable per user
* Light and dark theme

= Task detail that avoids the spreadsheet =

* Subtasks with progress indicators
* Custom colour-coded labels
* Priority levels and due dates, with overdue highlighting
* File attachments through the WordPress Media Library
* Task comments with @mentions
* Per-task activity log showing who changed what, and when
* Full-text search plus filters for assignee, priority, due date and overdue state

= Permissions built for real teams =

Kanbanz adds two roles — Kanban Manager and Kanban Staff — and administrators and editors inherit access automatically.

* Assign tasks to any staff member, with role badges shown in the picker
* Boards are open to all staff until you add team members; then only the team can see them. Anyone assigned a task on a team board joins its team automatically
* Optionally restrict who may move a task into the final column, so "Completed" means something
* Per-board manager assignment

= Notifications =

* In-admin notification bell for task assignments, @mentions and new comments on your tasks
* Optional email notifications via wp_mail(), with separate toggles for assignments and for comments/@mentions

= Works with Deskuss (optional) =

If the [Deskuss](https://deskuss.com) helpdesk plugin is active, Kanbanz can mirror support departments as boards and keep tickets and tasks in sync. Kanbanz is a complete, standalone kanban plugin without it — Deskuss is never required.

= Built to stay out of the way =

Kanbanz is an admin-only plugin. Its scripts and styles are scoped to its own screens and never load on your public site, so there is no front-end performance cost for visitors.

= Kanbanz Pro =

A separate Pro add-on extends Kanbanz with My Tasks (a personal cross-board inbox), stopwatch time tracking, staff performance scorecards, task completion trend and burndown analytics, SLA rules and CSV exports. The free plugin is fully functional on its own and is not time-limited or feature-crippled.

== Installation ==

1. Upload the `kanbanz` folder to `/wp-content/plugins/`, or install it through **Plugins → Add New** in your WordPress admin.
2. Activate the plugin through the **Plugins** screen.
3. Open **Kanbanz** in the admin menu.
4. Choose **Add New Board**, pick a template or start blank, and give the board a name.
5. Add your first task with the **New Task** button, then drag it between columns as work progresses.

To let colleagues use Kanbanz, edit their user profile and assign the **Kanban Staff** or **Kanban Manager** role. Administrators and editors already have access.

== Frequently Asked Questions ==

= Do I need the Deskuss helpdesk plugin to use Kanbanz? =

No. Kanbanz is a standalone, general-purpose kanban plugin. Boards work for any kind of project. If Deskuss happens to be active, Kanbanz offers optional ticket synchronisation on top — but nothing requires it.

= Who can see and edit a board? =

Administrators see every board. A board with no team members is visible to every Kanbanz user; once you add team members (when creating the board or in Board Settings), only the team, administrators and the board's creator can see it. Assigning a task on a team board adds the assignee to the team. Kanban Managers additionally see boards belonging to their department when Deskuss is in use.

= Can I stop people marking their own tasks as finished? =

Yes. Open a board's **Board Settings → Task completion** and choose who may move tasks into the final column: everyone, admins and board managers only, or selected staff. Everyone else can still work the task through every other column.

= Does Kanbanz slow down my website? =

No. Kanbanz loads nothing on the front end. Its assets are restricted to its own admin screens, so visitors to your site never download them.

= Does the plugin send data anywhere? =

No. Kanbanz makes no external HTTP requests. Boards, tasks, comments and attachments are stored in your own WordPress database and media library.

= Can I use it on a multisite network? =

Yes. Activate it per site; each site keeps its own boards and settings.

= What happens to my boards if I deactivate the plugin? =

Nothing is deleted. Boards and tasks are stored as custom post types and remain in your database, ready for when you reactivate.

== Screenshots ==

1. A board in the default view, with columns, cards and drag-and-drop.
2. The task panel — description, subtasks, labels, due date, attachments and comments.
3. Creating a board from a workflow template.
4. Board settings, showing team access and visibility controls.
5. List view, for scanning a board as a table.
6. The settings screen, with notification and permission options.

== Changelog ==

= 1.0.2 =
* [Bug-Fix] Improved the Kanbanz Pro updater.

= 1.0.1 =
* [Bug-Fix] Fixed issues identified during the plugin review.

= 1.0.0 =
* Initial release.
