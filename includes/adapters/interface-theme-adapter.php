<?php
/**
 * Contract every theme adapter implements.
 *
 * An adapter only reads the theme. It knows nothing about Kadence token ids; KTSS\Sync turns what it
 * returns into Kadence values. To support another theme (Astra, GeneratePress…), implement this and add
 * it through the `ktss_theme_adapters` filter.
 *
 * @package KTSS
 */

namespace KTSS\Adapters;

defined( 'ABSPATH' ) || exit;

interface Theme_Adapter {

	/**
	 * Stable machine id, used to key saved settings (e.g. "blocksy").
	 */
	public function id(): string;

	/**
	 * Human label (e.g. "Blocksy").
	 */
	public function label(): string;

	/**
	 * Whether the theme is active and loaded enough to be read.
	 */
	public function is_active(): bool;

	/**
	 * The theme's global palette in display order.
	 *
	 * @return array<string, array{color: string, title: string, variable: string}> Keyed by the theme's own color key.
	 */
	public function palette(): array;

	/**
	 * Recommended Kadence slot => theme color key map (palette1…palette9), matching color roles.
	 *
	 * @return array<string, string>
	 */
	public function default_slot_map(): array;

	/**
	 * Numeric Kadence slot => theme color key map (palette N = theme color N).
	 *
	 * @return array<string, string>
	 */
	public function direct_slot_map(): array;

	/**
	 * The palette key a raw theme value points at (e.g. "var(--theme-palette-color-1)" => "color1"), or null.
	 */
	public function palette_key_for( string $raw ): ?string;

	/**
	 * Raw global color settings. Each value is a color literal or a palette reference the adapter understands.
	 * Keys: text, link, link_hover, border, button_bg, button_bg_hover, button_text, button_text_hover, button_border.
	 *
	 * @return array<string, string>
	 */
	public function colors(): array;

	/**
	 * Button box metrics. Keys (all optional): radius (string[4]), padding (string[4]), border_width (string),
	 * border_style (string).
	 *
	 * @return array<string, mixed>
	 */
	public function button_metrics(): array;

	/**
	 * Button typography. Keys (all optional): font_size, font_weight, font_style, line_height, letter_spacing,
	 * text_transform. CSS-ready strings.
	 *
	 * @return array<string, string>
	 */
	public function button_typography(): array;

	/**
	 * Fonts the theme hosts itself (uploaded, locally hosted Google, Typekit…) that block font pickers should offer.
	 *
	 * @return array<int, array{family: string, label: string, weights: string[], styles: string[], source: string}>
	 */
	public function fonts(): array;

	/**
	 * Option names whose change means the theme's styles changed.
	 *
	 * @return string[]
	 */
	public function watched_options(): array;
}
