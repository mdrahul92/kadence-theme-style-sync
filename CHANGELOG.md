# Changelog

All notable changes to Theme Style Sync for Kadence Blocks. Each release's section becomes its GitHub release notes and the "View details" changelog in WordPress.

## 1.3.0 - 2026-10-26

### Added
- Every Google font the theme uses (Blocksy: base, headings, buttons, header/footer builder and every other typography control, plus Local Google Fonts; block themes: theme.json, Site Editor and Font Library fonts) is added to the Style Library's favorite fonts, so it sits at the top of every Kadence font picker.
- Favorites follow the theme: a font the theme stops using is removed again. Only favorites this plugin added are ever removed; ones you add yourself stay.
- Kadence's "Load Google Fonts Locally" setting is switched on and kept on, so Google fonts are served from the site.
- Both can be switched off under What Syncs. The Fonts screen shows each theme Google font, whether it is a favorite, and the local-fonts setting.
- CHANGELOG.md; the release workflow publishes each version's section as its release notes.

## 1.2.2 - 2026-10-23

### Removed
- The beta update channel: every site installs the newest release.

## 1.2.1 - 2026-10-23

### Changed
- Author is now wpblocks.dev.

## 1.2.0 - 2026-10-23

### Added
- Updates from GitHub Releases through WordPress's own updater, with auto-updates switched on once per site.
- Updates section on the Overview screen (installed version, Check now, auto-update status).

## 1.1.1 - 2026-10-23

### Fixed
- Block themes: Kadence's own palette, which Kadence merges into the theme's, is no longer read back as theme colors.
- Settings page says "Site Editor" instead of "Customizer" on block themes.

## 1.1.0 - 2026-10-23

### Added
- Works with any theme through WordPress global styles (theme.json, Site Editor changes, Font Library): palette mapped by role, extra colors, text/link colors, buttons, button typography and self-hosted fonts.

## 1.0.1 - 2026-10-23

### Changed
- Settings screen rebuilt in the Kadence Style Library's own look.

## 1.0.0 - 2026-10-23

### Added
- Blocksy palette, extra colors, text/link/border colors, buttons, button typography and Blocksy-hosted fonts feed the Kadence Blocks Style Library as its defaults.
