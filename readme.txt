=== Double Youpï Refined Admin Dashboard - Complete Admin Redesign, Dark Mode & Media Folders ===
Contributors: doubleyoupi
Tags: admin theme, dark mode, admin menu, dashboard, admin
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A dark and light redesign of the WordPress admin: navigation, tables, editor, notices, dashboard and media library, with native components only.

== Description ==

The WordPress admin has looked the same since 2013, and it shows. Grey on grey, a sidebar that every plugin adds a line to, a dashboard full of widgets nobody reads. You spend your days in there. It might as well be pleasant.

Double Youpï Refined Admin Dashboard redraws the whole thing, in dark or light, using only WordPress's own components. Nothing is reinvented: the markup stays native, so your plugins keep working and their screens get the same treatment.

https://www.youtube.com/watch?v=untjaB9-KSs

**A dark theme that actually arrives dark**

* Dark by default, light on demand, switched from the admin bar. Your choice is remembered and applied before the first paint, so you never see the old interface flash past.
* One design system across every screen. Tables, forms, metaboxes, notices, tabs, buttons and fields all speak the same language.
* Your accent colour, picked once. Text contrast against it is computed for you, so it stays readable whatever you choose.
* Your logo, in the admin and on the login screen.

**A sidebar you can finally organise**

* Menu entries are grouped into themed lines rather than piled up in one long list.
* The rail is resizable and collapsible, and remembers how you left it.
* A second bar shows the sub-pages and the useful tabs of the current screen, so you stop going back and forth.
* An editor lets you reorder, move, hide, promote or demote any entry, without touching code. Newly installed plugins are detected and offered to you rather than silently appended.

**A dashboard you compose**

* Arrange widgets by dragging them, add headings and text, save the layout under a name.
* Assign a default layout per role, and let each person adjust their own.
* Ready-made presets if you would rather not start from a blank page.

**Every notice in one place**

Plugins compete for the top of your screen. All those notices are collected into a single counter, opened when you want them. Action feedback still appears immediately, as a brief toast, because that one you asked for.

**Reimagined lists and editor**

* List tables keep every native action and every action your plugins add, deduplicated and shown on hover instead of stacked under each row.
* The classic post editor gets the same care, canvas included.

**A media library with folders**

Redrawing the admin and leaving the Media screen alone would have been a strange promise, so it is not the one made here.

* Folders and collections, by drag and drop. A file can sit in one folder and in as many collections as you like.
* A detail panel that opens beside the grid rather than over it, with the fields you actually edit.
* Where a file is used, before you delete it.
* WebP and AVIF generated on upload, and alt text filled from the filename when it is missing.
* Coming from FileBird, Real Media Library, HappyFiles or another folder plugin? Your folders are imported in one click, and nothing is deleted.

This is the whole of [Double Youpï Refined Media Library](https://wordpress.org/plugins/doubleyoupi-refined-media-library/), a free plugin of ours that is also published on its own. Same folders, same collections, same settings: if you already run it, activate this one and everything is where you left it. If you only ever wanted the media library, that plugin on its own is the right choice.

**It steps aside when it should**

Some plugins manage their own interface. When one of them owns the screen, this plugin stops theming it rather than fighting for control. Page builders, and anything that ships a full admin of its own, are left alone.

== Installation ==

1. Install it from the Plugins screen, or upload it to `/wp-content/plugins/doubleyoupi-refined-admin-dashboard` by hand if that's your thing.
2. Activate it.
3. Open Double Youpï Refined Admin Dashboard > Settings. Pick your accent colour and your logo if you want them. The rest already works.

== Frequently Asked Questions ==

= Does it break my other plugins? =

It shouldn't. The redesign styles WordPress's native components rather than replacing them, so a plugin that uses standard markup is styled along with everything else. A plugin that ships its own full interface is detected and left alone.

= Can I turn it off for some users? =

Yes. By default it applies to everyone. You can switch on a mode where each account decides for itself, so one person can go back to the stock admin without affecting anyone else.

= Does it slow the admin down? =

No. The theme is CSS on native markup, the brand font is self-hosted, and the body text uses the fonts already on your machine. Nothing is fetched from a third party.

= Can I reorganise the menu? =

That is one of the main points. An editor lets you reorder entries, move them between themed lines, hide what you never use, and reset everything in one click if you change your mind.

= What is the catch with "free"? =

There isn't one. No Pro version, nothing held back, no banner asking you to upgrade.

== External services ==

This plugin sends a report to doubleyoupi.com when you use the "Send feedback" tab, and only then. Nothing is sent while you use the admin, and nothing is sent in the background.

**When it happens.** Only when you open the feedback tab, write a message and tick the consent box. The dialog shows you the exact report before it leaves.

**What is sent.** Your message and, if you attached one, your screenshot. The title and address of the admin screen you were on, with any token removed. The plugin or theme that screen belongs to. Version numbers for WordPress, PHP, your theme and this plugin. Your browser's user agent string and window size. A site identifier that is a truncated one-way hash of your site address, so reports from one install can be grouped without knowing which site it is. If, and only if, you ask for a reply, your email address and your site address.

**What is never sent.** No posts, pages, media files, users, passwords or database content.

Reports are kept for 24 months, then deleted, screenshot included. You can ask for access, correction or deletion at any time.

Service provider: Double Youpï, https://doubleyoupi.com
Terms of use: https://doubleyoupi.com/terms
Privacy policy: https://doubleyoupi.com/privacy

== Screenshots ==

1. The post list, redrawn. The menu became a rail of seven sections, with a second bar for the sub-pages of the screen you are on.
2. The same screen in light. Your choice is remembered and applied before the first paint, so the old interface never flashes past.
3. Hover a row and its actions line up beside the title: the native ones and those your plugins add, each shown once.
4. Reorganise the navigation by dragging: move entries between sections, hide them, promote a sub-page to the top level.
5. Hovering a section of the rail shows its pages, so any screen is one click away.
6. Compose the dashboard: drag the widgets, name the layout, assign one per role.
7. Every notice from WordPress and your plugins, collected in one place instead of stacking above the page.
8. The editor follows suit, custom fields included: your accent, your contrast, native components only.
9. Your plugins get the same treatment. WooCommerce here, with every native and third-party row action kept.
10. One accent colour and your logo. The text contrast against your colour is computed for you, so it stays readable.
11. Your logo and name on the login screen, in the same design as the admin.
12. The media library comes included: folders and collections on the left, a filter by file type, the redrawn grid on the right.
13. Click a file and its panel opens beside the grid, with the preview, the folder, the collections and the fields you edit.
14. Find the files nothing uses. Posts, pages, featured images and custom fields are checked first, and deleting sends files to the trash.
15. Coming from FileBird or another folder plugin? Preview its folders, then import them in one click. The source plugin is never modified.

== Changelog ==

= 1.0.6 =
* New: a single review request after a week of use, in the notifications centre. "No thanks" closes it for good.
* New: a "Leave a review" link beside the Feedback button.
* Fix: on W3 Total Cache screens the footer is back in place, and the section bars no longer show a light strip on their right edge.

= 1.0.5 =
* New: the Backup widget of the dashboard reads the backup plugin your site already uses (UpdraftPlus, All-in-One WP Migration, Duplicator, WPvivid, BackWPup, Backup Migration, Total Upkeep, XCloner, Jetpack Backup or BlogVault) and shows your last backup. It only reads what that plugin has saved.
* New: the media library follows your light or dark theme, switched live, including an open media window.
* Fix: warning and danger colours show again on the dashboard and the Overview. Badges, buttons and toasts had turned grey.
* Fix: the dashboard no longer offers a widget or an action for a feature this plugin does not include, the Toolkit link opens the Tools page, and the Site status widget says "Update available" when WordPress has an update waiting.
* Fix: in light mode, captions under the thumbnails of media windows are readable, and the "Add Media File" button takes your accent colour.
* The "Shop Pro" dashboard layout is now called "Sales".
* Translations: the bundled language files no longer carry strings the plugin does not use, and plurals follow the rule of each language (in French and Portuguese, zero is now singular).

= 1.0.4 =
* Fix: in the block editor, the block inserter shows each block name under its own icon again (since WordPress 7.1 it slipped onto the row below). In dark mode the inserter panel is dark too, and its search field is one piece.
* Fix: in the Divi 5 Theme Builder, template names show as plain labels again instead of text boxes.
* Fix: in the top bar, the EDD counter is centred, and W3 Total Cache keeps its icon, counter and label, readable in light mode too.
* Fix: W3 Total Cache screens keep their own native look, with the rail and section bars around them.
* New: plugin screens that ship their own app (Animation Addons for Elementor, for example) are recognised on their own and left exactly as their authors designed them, in light and dark mode.
* Fix: your accent colour now reaches the whole media library: folders, hovers, selection, focus, the detail panel and the media windows.
* Fix: the list view pagination buttons no longer show a dropdown arrow.

= 1.0.3 =
* New: your folders and collections now appear in the Bricks 2.4 media browser. Open a folder or a collection to filter, create, rename, move and delete folders, and move files, all from the builder.
* New: a file uploaded from the Bricks media browser lands in the folder you have open.
* New: in the builder's "WordPress: Media library" window, the folder panel is there too.
* Improvement: a section bar too long for the screen now shows arrows and scrolls, and the notifications badge no longer hides its last entries.
* Fix: on the dashboard, the Create widget keeps a set height and scrolls when the site has many content types.
* Translations: the French interface was updated after review by the WordPress French translation team.

= 1.0.2 =
* New: a Settings link in the plugin's row on the Plugins screen.
* Fix: the duplicate badge now shows on duplicate files, in the media grid and in media windows. It did not appear before.
* Fix: the duplicate badge and its column are translated, and read as a noun in German, Spanish, Italian and Portuguese.
* The Duplicate column is back in the list view when the duplicate badge is on, with a link to the original file.
* Fix: the "Duplicate" link of the post lists has its own translation, so it no longer reads "Doublon" in French.
* Translations: the French interface was reviewed against the official WordPress French glossary, and a few Hindi lines were tidied.

= 1.0.1 =
* Fix: dashboard widgets no longer appear twice while the page loads.
* Fix: in the media library, the details panel no longer overlaps the section bar.
* Fix: media windows (such as "Choose a logo" or "Add Media") have even spacing and a single selection ring, and in "Load more" mode the folder column no longer covers the first thumbnails.
* Fix: typing an accent colour no longer shows an error at every keystroke.
* Improvement: the classic editor loads in one smooth step instead of jumping into place.
* Fix: without JavaScript, the classic editor shows the title field and the Publish button again.
* Improvement: the block editor shows its frame from the very first moment and appears in one step; the featured image no longer pushes the sidebar down.
* Fix: menu icons from plugins such as WPForms or WP Mail SMTP show again in the section bar and the flyouts (regression in 1.0.0).
* Fix: with debug logging on, an unknown menu entry is logged once a day instead of on every screen; Background and Header from classic themes now sit under Appearance.
* Fix: a rail flyout taller than the screen now opens below the admin bar and scrolls, its title kept in view.
* Fix: the dashboard presets no longer offer a widget that has nothing to show on your site.
* Fix: an empty note no longer tells people without the Customize button to click it.

= 1.0.0 =
* First public release. Dark and light themes applied before the first paint, a themed design system across every native screen, a themed login screen, your accent colour and logo.
* A themed navigation rail with grouped lines, resizable and collapsible, plus a bar for the sub-pages and tabs of the current screen.
* An editor to reorder, move, hide, promote or demote menu entries, with a one-click reset.
* A composable dashboard: drag widgets, name layouts, assign them per role.
* One notices centre for WordPress and plugin notices, with immediate toasts for action feedback.
* List tables and the classic editor reimagined, keeping every native and third-party action.
* The whole Double Youpï Refined Media Library built in: folders and collections, a detail panel beside the grid, where each file is used, unused media, WebP and AVIF on upload, and import from other folder plugins.
* Built-in toolkit, and per-user opt-out when you want it.

== Upgrade Notice ==

= 1.0.6 =
A "Leave a review" link, a one-time review request after a week, and fixes for W3 Total Cache screens and the section bars.

= 1.0.5 =
The Backup widget now reads UpdraftPlus and nine other backup plugins, and the media library follows your light or dark theme.

= 1.0.4 =
The block inserter works again on WordPress 7.1, Divi 5 and W3 Total Cache screens are fixed, and your accent colour now reaches the whole media library.

= 1.0.3 =
Your media folders and collections now work inside the Bricks 2.4 media browser, and long section bars scroll.

= 1.0.2 =
The duplicate badge of the media library now shows, a Settings link on the Plugins screen, and a reviewed French translation.

= 1.0.1 =
Fixes from the first week: plugin menu icons are back, dashboard widgets load once, smoother editors and media windows.

= 1.0.0 =
First public release.