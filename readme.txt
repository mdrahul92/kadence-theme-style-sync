=== Theme Style Sync for Kadence Blocks ===
Requires at least: 6.5
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later

Feeds the active theme's global styles into the Kadence Blocks Style Library (Kadence Blocks 3.8+) as its defaults. Works with any block theme through WordPress global styles, with a richer adapter for Blocksy.

== What it does ==

Kadence Blocks 3.8 seeds its Style Library (palette, button colors) from the theme, but only reads the Kadence theme. On Blocksy it falls back to Kadence's shipped colors. This plugin makes the Style Library start from Blocksy instead:

* Blocksy global palette -> Kadence palette 1-9 (configurable map; "recommended by role" or "numeric").
* Blocksy colors no Kadence slot uses (color 5, and every color you add beyond 8) -> extra color tokens in a "Blocksy Colors" swatch group.
* Blocksy text, link and border colors -> Kadence text / link / border tokens.
* Blocksy buttons -> Kadence "Button" swatches, button text colors, border, radius and the Default button preset's padding, plus a "Blocksy Button" preset painted by Blocksy's own stylesheet.
* Blocksy button typography -> Kadence control font size / weight / style / line height / letter spacing / transform.
* Fonts Blocksy hosts itself (Custom Fonts uploads, Local Google Fonts, Typekit) -> Kadence font pickers (block editor and Style Library). Listed as custom fonts so Kadence does not also load them from Google.

Everything is written to the Style Library's baseline, never to the saved library:

* Change something in the Blocksy Customizer and the Style Library follows on the next page load.
* Anything you save in the Style Library still wins over the theme. "Reset" in the Style Library returns to the Blocksy value.
* Colors that reference a Blocksy palette color (e.g. button = Color 1) are stored as aliases, so editing that palette color in the Style Library moves them too.

Settings: Kadence Blocks -> Theme Style Sync.

== Any other theme ==

Every other theme is read through WordPress's own global styles: the theme's theme.json (or, for a classic theme, the editor color palette it registers), plus your Site Editor changes and Font Library fonts.

* Palette -> Kadence palette 1-9, mapped by role: body text color -> Palette 3, page background -> Palette 9, the most saturated color readable on white -> brand, other neutrals fill the text and background ramps (works for dark styles too). Remap freely on Palette Mapping.
* Colors no slot uses -> extra Style Library colors.
* Text and link colors (a "currentColor" link follows the text color), button colors, border, radius, padding and typography.
* Self-hosted theme fonts and Font Library fonts -> Kadence font pickers.
* Button presets for block themes are left to Kadence Blocks, which already reads them from theme.json.

Palette colors that are not plain colors (CSS variables, color-mix()) are skipped. Many classic themes (Astra, GeneratePress, Neve) register their editor palette as CSS variables, so their palette needs a dedicated adapter, like the Blocksy one.

The Kadence theme is left alone: Kadence Blocks reads it itself.

To read a theme's own settings in more depth, implement KTSS\Adapters\Theme_Adapter and add it before the fallback with the `ktss_theme_adapters` filter.

== Notes ==

* Kadence's Style Library font list shows a custom font by its CSS family (Blocksy names uploads `ct_font_<name>`); the block editor shows the display name.
* Blocksy heading typography is not mapped: Kadence's heading preset deliberately leaves font sizes to the theme's own h1-h6 styles.
