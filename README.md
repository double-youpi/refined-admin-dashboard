![Double Youpï Refined Admin Dashboard](https://ps.w.org/doubleyoupi-refined-admin-dashboard/assets/banner-1544x500.png)

# Double Youpï Refined Admin Dashboard

A dark and light redesign of the whole WordPress admin: navigation, list tables, editor, notices, dashboard and media library, built on WordPress's own components only.

**Install it from WordPress.org:** [wordpress.org/plugins/doubleyoupi-refined-admin-dashboard](https://wordpress.org/plugins/doubleyoupi-refined-admin-dashboard/)

**Try it without installing:** [Live Preview on WordPress.org](https://wordpress.org/plugins/doubleyoupi-refined-admin-dashboard/?preview=1)

[Watch the tour on YouTube](https://www.youtube.com/watch?v=untjaB9-KSs)

![The redesigned admin](https://ps.w.org/doubleyoupi-refined-admin-dashboard/assets/screenshot-1.png)

## What it does

- **A dark theme that actually arrives dark.** Dark by default, light on demand, applied before the first paint. One design system across every screen, your accent colour with contrast computed for you, your logo in the admin and on the login screen.
- **A sidebar you can organise.** Entries grouped into themed lines, a resizable rail, a second bar for the sub-pages of the current screen, and an editor to reorder, move or hide any entry without code.
- **A dashboard you compose.** Drag widgets, add headings and text, save layouts, assign one per role.
- **Every notice in one place.** Plugin notices are collected into a single counter. Feedback on your own actions still appears at once, as a toast.
- **Reimagined lists and editor.** List tables keep every native and plugin action, deduplicated and shown on hover. The classic post editor gets the same care.
- **A media library with folders.** Folders and collections by drag and drop, a detail panel beside the grid, where a file is used before you delete it, WebP and AVIF on upload, one-click import from other folder plugins. This is the whole of [Double Youpï Refined Media Library](https://wordpress.org/plugins/doubleyoupi-refined-media-library/), bundled.
- **It steps aside when it should.** When a plugin owns its screen (page builders, plugins with a full admin of their own), it is left alone.

The markup stays native, so your other plugins keep working and their screens get the same treatment. No Pro version, nothing held back.

**Requirements:** WordPress 6.5 or later, PHP 8.1 or later.

## For developers

- **Hooks and constants:** [docs/rad-hooks.md](docs/rad-hooks.md), what is stable, what is beta, what may change.
- **Add your own dashboard widget** (beta API): [docs/rad-dashboard-widget-api.md](docs/rad-dashboard-widget-api.md), with a complete example plugin in [examples/acme-drafts-widget](examples/acme-drafts-widget). Refined Admin Dashboard does not ship widgets for other plugins: each plugin ships and maintains its own.
- **The dashboard, for site owners:** [docs/rad-dashboard-guide.md](docs/rad-dashboard-guide.md).

Plugins that ship a widget for Refined Admin Dashboard: none listed yet. Yours? Open an issue.

## About this repository

This repository mirrors **every release published on WordPress.org**. Each commit is one release, tagged with its version number, and the files are exactly those of the WordPress.org package. The SCSS sources of the stylesheets are added under `assets/scss/`.

Development happens in a private repository that also holds other projects. This one starts with the first release published on WordPress.org: each commit is a release, dated the day it went out. For the earliest releases, the SCSS sources are no longer available.

## Getting help, reporting a bug

- **Questions and support:** the [support forum on WordPress.org](https://wordpress.org/support/plugin/doubleyoupi-refined-admin-dashboard/).
- **A bug you can reproduce:** [open an issue](../../issues/new/choose).
- **A security problem:** please do not open a public issue, see [SECURITY.md](SECURITY.md).
- **Want to contribute?** Read [CONTRIBUTING.md](CONTRIBUTING.md) first.
- **Translations** happen on [translate.wordpress.org](https://translate.wordpress.org/projects/wp-plugins/doubleyoupi-refined-admin-dashboard/).

## License

GPLv2 or later, see [LICENSE](LICENSE).

Made by [Double Youpï](https://doubleyoupi.com).
