# The Refined Admin Dashboard dashboard

Refined Admin Dashboard replaces the WordPress dashboard (**Dashboard > Home**) with a set of
screens you compose yourself: a few cards per screen, nothing you did not choose. This guide
covers how it works, from browsing to building layouts for a whole team.

> Written for Refined Admin Dashboard 1.0.13 and later. Features that need a specific version
> say so.

## Screens, not one long page

The dashboard is a series of **screens**, side by side. Each screen has a title, an optional
paragraph and one row of cards.

- Move between screens with the arrows and dots at the bottom, the arrow keys, Page Up and
  Page Down, Home and End, the mouse wheel or a swipe. Hover a dot to see a screen's title.
- A counter at the top of each screen ("1 / 3") shows where you are.
- The dashboard remembers the screen you were on when you come back to it in the same tab.

**The last screen, "WordPress and plugins"**, shows the dashboard boxes that WordPress itself
and your other plugins add (At a Glance, Activity, your SEO or shop plugin's box…). Nothing is
lost: they all live there, out of the way of your own screens. You can hide this screen if you
never use it.

**Notices** that WordPress or plugins print on the dashboard are gathered in a small stack at the
bottom right instead of pushing your cards down. Promotional notices are left out.

## A row of six slots

Each screen has one row of **six slots**. Most cards take two slots, so three fit side by side.
A few are narrower or wider:

- one slot: "Create (one type)" and "Admin link";
- four slots: "Create (all types)";
- two slots: every other card.

Cards keep their width; you choose them, not their size. You can have up to 20 screens.

On narrower windows the row reflows to three, two, then one column. Customizing needs a window at
least 1300 pixels wide.

## Customizing your dashboard

Click **Customize** at the top right. A panel opens on the side, and the dashboard switches to
edit mode.

**Every change saves on its own.** The footer of the panel shows "Saving…" then "Saved". Undo and
Redo sit at the top of the panel (also Cmd/Ctrl+Z and Cmd/Ctrl+Shift+Z).

### Add, move and remove cards

- **Add**: drag a card from the **Widgets** section of the panel onto the current screen, or simply
  click it to add it at the end. You can also click an empty "+" slot on the screen. If the row is
  full, you are told so.
- **Move**: drag a card by its handle, within the row or onto another screen.
- **Remove**: click the × on the card.

Most cards can be placed once in the whole dashboard. Note, Shortcuts, Quick actions, Admin link
and Create (one type) can be placed as many times as you like.

### Card settings

In edit mode, click a card to see its settings in the panel. Cards with settings:

| Card | Settings |
|---|---|
| Admin link | Label, URL, open in a new tab |
| Create (one type) | Post type, label |
| Quick actions | Up to 6 actions (new post, page, media, user; updates; menus; comments; themes; Customizer; widgets; Site Health; settings; permalinks) |
| Shortcuts | Up to 8 links to admin screens |
| Note | Write the title and text directly on the card |

Each person only sees the actions and links their role allows.

### Screen title and paragraph

Click the title or paragraph of a screen, on the screen itself or in the **Current screen**
section of the panel. The paragraph accepts **bold**, *italic*, links and `code`.

The lightning button inserts **dynamic content**, replaced when the dashboard is displayed:

| Code | Shows |
|---|---|
| `{{date.greeting}}` | Good morning, good afternoon or good evening |
| `{{user.firstname}}`, `{{user.name}}` | The person's first name or display name |
| `{{site.name}}`, `{{site.url}}` | Your site's name or address |
| `{{date.day}}`, `{{date.now}}` | Today's date |
| `{{updates.summary}}` | Pending updates |

Each screen can also take its own accent and background colour, or follow the global accent set
in the Refined Admin Dashboard settings.

### Screens

In the **Screens** section of the panel: add a screen, drag screens to reorder them, delete one,
or hide the "WordPress and plugins" screen. In edit mode, the "+" between the dots at the bottom
also inserts a screen.

### Start over

**Reset**, at the bottom of the panel, deletes your own layout. You then get the layout assigned
to your role, or the standard one.

**Advanced** exports your layout as a JSON file, or imports one.

## Layouts

A **layout** is a complete dashboard, ready to apply. The **Layout** section of the panel lists
them. Click one to apply it: it replaces your current dashboard (you are asked first).

Built-in layouts:

| Layout | For |
|---|---|
| Standard | Recommended: create, take notes, follow what needs doing, keep an eye on the site |
| Editor | Writing first |
| Administrator | Technical health and maintenance |
| Minimal | Just create and go |
| Shop | WooCommerce sites: the shop, then the editor flow |
| Sales | WooCommerce sites: sales in depth, plus the editor flow |

Shop and Sales appear only when WooCommerce is active. A layout leaves out the Backup card when no
backup plugin is detected.

As soon as you change anything, your dashboard shows as **Custom**.

### Saving and sharing layouts

Administrators can turn the current dashboard into a layout of the site. Use the **⋯** menu of a
card in the Layout section:

- on **Custom**: "Save as new disposition", "Export", "Clear everything";
- on a saved or built-in layout: "Apply", "Update with current settings", "Assign to role(s)…",
  "Export", "Delete".

Saved layouts are shared by every administrator of the site. A layout exported from one site
imports on another (icon next to the Layout title). Updating a built-in layout keeps the original:
it saves your version alongside. Up to 30 layouts can be saved.

### Layouts by role

"Assign to role(s)…" gives a layout to everyone with those roles: an editor sees the editor
layout, a shop manager the sales layout. A role has one layout; a layout can serve several roles.
When you update a layout, everyone it is assigned to sees the change at once.

Without an assignment, each role gets a sensible default:

| The role can | Default layout |
|---|---|
| Manage the site (administrators) | Standard |
| Manage a WooCommerce shop | Sales (when WooCommerce is active) |
| Write posts | Editor |

Which dashboard a person sees, in order: their own customized dashboard, if they made one; then
the layout of their role; then Standard.

**Who can customize.** Administrators customize their own dashboard. Other roles see the dashboard
their role was given, read-only.

## Cards available

| Card | Shows | Who sees it |
|---|---|---|
| Note | A free note, written on the card | Everyone |
| Create (one type) | One button to create a given content type | People who can write |
| Create (all types) | One button per content type, custom types included | People who can write |
| Admin link | One button to any admin screen | Everyone |
| Shortcuts | Your links to admin screens | Everyone |
| Quick actions | Your buttons for frequent actions | Everyone (each action follows its own permission) |
| Site status | WordPress and PHP versions, SSL, pending updates | People who can update WordPress |
| To handle | Drafts, scheduled posts, comments to moderate, plugin updates | People who can write |
| Recent activity | Posts and pages published or updated in the last 7 days | Editors and administrators |
| Disk space | Disk, media library and database size | Administrators |
| Backup | Date, size and status of the last backup; turns red after 7 days | Administrators |
| WordPress health | Site Health results: critical, recommended, passed | People who can view Site Health |
| Modules | Which Double Youpï modules are on | Whoever manages Double Youpï |
| Toolkit | State of the quick toggles | Whoever manages Double Youpï |

**The Backup card** reads the last backup of: UpdraftPlus, All-in-One WP Migration, Duplicator,
WPvivid, BackWPup, Backup Migration, Jetpack Backup, BlogVault, Total Upkeep or XCloner.

**WooCommerce cards**, shown only when WooCommerce is active, for shop managers and
administrators:

| Card | Shows |
|---|---|
| Sales (month) | Revenue this month against last month, same number of days |
| Sales (7 days) | Daily revenue over the last 7 days, total and order count |
| Orders to process | Orders to ship and on hold |
| Shop activity | Latest orders: number, amount, status, time |
| Top products | Best sellers this month |
| Low stock | Products running low or out of stock |

**Cards from your other plugins.** Plugins can add their own cards (Refined Admin Dashboard 1.0.14
and later). They appear in their own section of the panel, "From your plugins". See
[Add a widget to the Refined Admin Dashboard dashboard](rad-dashboard-widget-api.md).

If a card ever fails to load, it says so and the rest of the dashboard stays up.

## For agencies and teams

- **Keep Double Youpï settings to yourself** (Settings, Team, 1.0.13): other administrators keep
  the redesigned admin, including their own dashboard, but can no longer save, update, assign or
  delete shared layouts, nor see the Modules and Toolkit cards.
- **Assign layouts to roles** so each client role lands on the dashboard you designed for it.
- **Hide Feedback and review requests** (Settings, Team, 1.0.12).
- Dashboard layouts stay with each site: the settings profile you export from one site does not
  carry them. Export a layout from the Layout section to reuse it elsewhere.
- **Per-user customization** (Settings, Team) lets each person turn the Double Youpï interface off
  for their own account; they then get the standard WordPress dashboard.

## Screenshots to take

For whoever illustrates this page (none exist yet):

1. The dashboard on its first screen, Standard layout, dark theme.
2. The same in light theme.
3. Customize mode: panel open, a card being dragged from the Widgets section.
4. The settings of the Quick actions card.
5. The Layout section with its cards (Standard, Editor, Administrator, Minimal, Custom) and an open ⋯
   menu.
6. The "Assign … to roles" dialog.
7. A screen with WooCommerce cards (Sales (month), Sales (7 days), Orders to process).
8. The "WordPress and plugins" screen.
9. The dynamic content menu (lightning button) open on a screen title.
