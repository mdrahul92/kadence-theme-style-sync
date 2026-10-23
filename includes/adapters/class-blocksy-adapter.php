<?php
/**
 * Reads Blocksy's Customizer: global palette (all colors, including ones added beyond the default 8),
 * global text/link colors, buttons (colors, border, radius, padding, typography) and the fonts Blocksy
 * Companion hosts (custom uploads, locally hosted Google fonts, Typekit).
 *
 * Defaults mirror Blocksy's own option definitions (inc/options/general/*.php), so a never-customized
 * site reads what Blocksy actually renders.
 *
 * @package KTSS
 */

namespace KTSS\Adapters;

defined( 'ABSPATH' ) || exit;

final class Blocksy_Adapter implements Theme_Adapter {

	private const DEFAULT_PALETTE = [
		'color1' => '#2872fa',
		'color2' => '#1559ed',
		'color3' => '#3A4F66',
		'color4' => '#192a3d',
		'color5' => '#e1e8ed',
		'color6' => '#f2f5f7',
		'color7' => '#FAFBFC',
		'color8' => '#ffffff',
	];

	/**
	 * Per-request memo of palette(). Cleared by flush().
	 *
	 * @var array<string, array{color: string, title: string, variable: string}>|null
	 */
	private ?array $palette = null;

	public function id(): string {
		return 'blocksy';
	}

	public function label(): string {
		return 'Blocksy';
	}

	public function is_active(): bool {
		return get_template() === 'blocksy' && function_exists( 'blocksy_manager' );
	}

	public function flush(): void {
		$this->palette = null;
	}

	public function palette(): array {
		if ( $this->palette !== null ) {
			return $this->palette;
		}

		$palette = [];
		$raw     = [];

		try {
			$manager = blocksy_manager();

			if ( isset( $manager->colors ) && is_object( $manager->colors ) && method_exists( $manager->colors, 'get_color_palette' ) ) {
				$raw = $manager->colors->get_color_palette();
			}
		} catch ( \Throwable $e ) {
			$raw = [];
		}

		if ( ! is_array( $raw ) || $raw === [] ) {
			foreach ( self::DEFAULT_PALETTE as $key => $color ) {
				$raw[ $key ] = [
					'color'    => $color,
					'title'    => sprintf( 'Color %d', (int) substr( $key, 5 ) ),
					'variable' => 'theme-palette-color-' . substr( $key, 5 ),
				];
			}
		}

		foreach ( $raw as $key => $entry ) {
			if ( ! is_string( $key ) || ! is_array( $entry ) || ! preg_match( '/^color\d+$/', $key ) ) {
				continue;
			}

			$color = is_string( $entry['color'] ?? null ) ? trim( $entry['color'] ) : '';

			if ( $color === '' ) {
				continue;
			}

			$palette[ $key ] = [
				'color'    => $color,
				'title'    => is_string( $entry['title'] ?? null ) && $entry['title'] !== '' ? $entry['title'] : ucfirst( $key ),
				'variable' => is_string( $entry['variable'] ?? null ) && $entry['variable'] !== '' ? $entry['variable'] : 'theme-palette-color-' . substr( $key, 5 ),
			];
		}

		uksort(
			$palette,
			static function ( string $a, string $b ): int {
				return (int) substr( $a, 5 ) <=> (int) substr( $b, 5 );
			}
		);

		$this->palette = $palette;

		return $palette;
	}

	/**
	 * Role-based map. Blocksy: 1 accent, 2 accent hover, 3 body text, 4 headings, 5 borders,
	 * 6 / 7 light backgrounds, 8 white. Kadence: 1-2 accents, 3 strongest text … 6 muted text,
	 * 7 / 8 subtle backgrounds, 9 base background. Color 5 (borders) has no Kadence slot and is offered
	 * as an extra color instead.
	 */
	public function default_slot_map(): array {
		return [
			'palette1' => 'color1',
			'palette2' => 'color2',
			'palette3' => 'color4',
			'palette4' => 'color3',
			'palette5' => 'color3',
			'palette6' => 'color3',
			'palette7' => 'color6',
			'palette8' => 'color7',
			'palette9' => 'color8',
		];
	}

	public function direct_slot_map(): array {
		$map = [];

		for ( $i = 1; $i <= 9; $i++ ) {
			$map[ 'palette' . $i ] = 'color' . $i;
		}

		if ( ! isset( $this->palette()['color9'] ) ) {
			$map['palette9'] = 'color8';
		}

		return $map;
	}

	public function palette_key_for( string $raw ): ?string {
		$raw = trim( $raw );

		if ( ! preg_match( '/^var\(\s*--([a-z0-9_-]+)\s*(?:,[^)]*)?\)$/i', $raw, $match ) ) {
			return null;
		}

		foreach ( $this->palette() as $key => $entry ) {
			if ( strcasecmp( $entry['variable'], $match[1] ) === 0 ) {
				return $key;
			}
		}

		if ( preg_match( '/^theme-palette-color-(\d+)$/', $match[1], $number ) ) {
			return 'color' . $number[1];
		}

		return null;
	}

	public function colors(): array {
		$font   = $this->picker( 'fontColor', [ 'default' => 'var(--theme-palette-color-3)' ] );
		$link   = $this->picker(
			'linkColor',
			[
				'default' => 'var(--theme-palette-color-1)',
				'hover'   => 'var(--theme-palette-color-2)',
			]
		);
		$bg     = $this->picker(
			'buttonColor',
			[
				'default' => 'var(--theme-palette-color-1)',
				'hover'   => 'var(--theme-palette-color-2)',
			]
		);
		$text   = $this->picker(
			'buttonTextColor',
			[
				'default' => '#ffffff',
				'hover'   => '#ffffff',
			]
		);
		$border = $this->border();
		$colors = [
			'text'              => $font['default'],
			'link'              => $link['default'],
			'link_hover'        => $link['hover'],
			'border'            => 'var(--theme-palette-color-5)',
			'button_bg'         => $bg['default'],
			'button_bg_hover'   => $bg['hover'],
			'button_text'       => $text['default'],
			'button_text_hover' => $text['hover'],
		];

		if ( $border['style'] !== 'none' && $border['color'] !== '' ) {
			$colors['button_border'] = $border['color'];
		}

		return array_filter( $colors, 'strlen' );
	}

	public function button_metrics(): array {
		$metrics = [];
		$radius  = $this->spacing( $this->mod( 'buttonRadius', null ) );

		// Blocksy leaves the radius empty by default and renders its 3px placeholder.
		$metrics['radius'] = $radius ?? [ '3px', '3px', '3px', '3px' ];

		$padding = $this->spacing( $this->mod( 'buttonPadding', null ) );

		$metrics['padding'] = $padding ?? [ '5px', '20px', '5px', '20px' ];

		$border = $this->border();

		if ( $border['style'] === 'none' ) {
			$metrics['border_width'] = '0';
		} else {
			$metrics['border_width'] = $border['width'];
			$metrics['border_style'] = $border['style'];
		}

		return $metrics;
	}

	public function button_typography(): array {
		$value = $this->mod(
			'buttons',
			[
				'size'      => '15px',
				'variation' => 'n5',
			]
		);

		if ( ! is_array( $value ) ) {
			return [];
		}

		$out = [];
		$map = [
			'size'           => 'font_size',
			'line-height'    => 'line_height',
			'letter-spacing' => 'letter_spacing',
			'text-transform' => 'text_transform',
		];

		foreach ( $map as $from => $to ) {
			$css = $this->desktop( $value[ $from ] ?? null );

			if ( $css !== null ) {
				$out[ $to ] = $css;
			}
		}

		$variation = is_string( $value['variation'] ?? null ) ? $value['variation'] : '';

		if ( preg_match( '/^([ni])([1-9])$/', $variation, $match ) ) {
			$out['font_weight'] = $match[2] . '00';
			$out['font_style']  = $match[1] === 'i' ? 'italic' : 'normal';
		}

		return $out;
	}

	public function fonts(): array {
		if ( ! class_exists( '\Blocksy\FontsManager' ) ) {
			return [];
		}

		try {
			$manager = new \Blocksy\FontsManager();
			$sources = $manager->get_all_fonts();
		} catch ( \Throwable $e ) {
			return [];
		}

		if ( ! is_array( $sources ) ) {
			return [];
		}

		$fonts = [];

		foreach ( $sources as $source_id => $source ) {
			if ( ! is_array( $source ) || ! is_array( $source['families'] ?? null ) ) {
				continue;
			}

			$type = is_string( $source['type'] ?? null ) ? $source['type'] : (string) $source_id;

			// Blocksy's own system list and remote Google list are already in Kadence's pickers.
			if ( in_array( $type, [ 'system', 'google' ], true ) ) {
				continue;
			}

			foreach ( $source['families'] as $family ) {
				if ( ! is_array( $family ) || ! is_string( $family['family'] ?? null ) || $family['family'] === '' ) {
					continue;
				}

				$variations = is_array( $family['all_variations'] ?? null ) ? $family['all_variations'] : [];
				$weights    = [];
				$styles     = [];

				foreach ( $variations as $variation ) {
					if ( is_string( $variation ) && preg_match( '/^([ni])([1-9])$/', $variation, $match ) ) {
						$weights[] = $match[2] . '00';
						$styles[]  = $match[1] === 'i' ? 'italic' : 'normal';
					}
				}

				$fonts[] = [
					'family'  => $family['family'],
					'label'   => is_string( $family['display'] ?? null ) && $family['display'] !== '' ? $family['display'] : $family['family'],
					'weights' => array_values( array_unique( $weights ) ),
					'styles'  => array_values( array_unique( $styles ) ),
					'source'  => $type,
				];
			}
		}

		return $fonts;
	}

	public function describes_theme_button(): bool {
		return true;
	}

	public function fonts_hint(): string {
		return __( 'Upload fonts with Blocksy’s Custom Fonts extension or turn on Local Google Fonts, and they appear in every Kadence font picker.', 'kadence-theme-style-sync' );
	}

	public function watched_options(): array {
		return [
			'theme_mods_' . get_stylesheet(),
			'blocksy_ext_custom_fonts_settings',
			'blocksy_ext_local_google_fonts_settings',
		];
	}

	/**
	 * A theme mod through Blocksy's accessor when available (it is Customizer-preview aware).
	 *
	 * @param string $key     The theme mod.
	 * @param mixed  $default Fallback.
	 *
	 * @return mixed
	 */
	private function mod( string $key, $default ) {
		if ( function_exists( 'blocksy_get_theme_mod' ) ) {
			return blocksy_get_theme_mod( $key, $default );
		}

		return get_theme_mod( $key, $default );
	}

	/**
	 * A Blocksy color picker setting ({ default: { color }, hover: { color } }) merged over its defaults.
	 *
	 * @param string                $key      The theme mod.
	 * @param array<string, string> $defaults Picker id => color.
	 *
	 * @return array<string, string>
	 */
	private function picker( string $key, array $defaults ): array {
		$value = $this->mod( $key, [] );
		$out   = [];

		foreach ( $defaults as $picker => $default ) {
			$color = is_array( $value ) && is_array( $value[ $picker ] ?? null ) ? ( $value[ $picker ]['color'] ?? '' ) : '';
			$color = is_string( $color ) ? trim( $color ) : '';

			$out[ $picker ] = ( $color === '' || strpos( $color, 'CT_CSS_SKIP_RULE' ) !== false ) ? $default : $color;
		}

		return $out;
	}

	/**
	 * The button border setting as width / style / color.
	 *
	 * @return array{width: string, style: string, color: string}
	 */
	private function border(): array {
		$value = $this->mod( 'buttonBorder', [] );
		$value = is_array( $value ) ? $value : [];
		$width = $value['width'] ?? 1;
		$style = is_string( $value['style'] ?? null ) && $value['style'] !== '' ? $value['style'] : 'none';
		$color = is_array( $value['color'] ?? null ) && is_string( $value['color']['color'] ?? null ) ? trim( $value['color']['color'] ) : 'rgba(224, 229, 235, 0.5)';

		return [
			'width' => is_numeric( $width ) ? $width . 'px' : '1px',
			'style' => $style,
			'color' => $color,
		];
	}

	/**
	 * A Blocksy spacing value (state + 4 sides, optionally responsive) as four CSS lengths for desktop,
	 * or null when it is empty or a free-form custom value.
	 *
	 * @param mixed $value The theme mod value.
	 *
	 * @return string[]|null
	 */
	private function spacing( $value ): ?array {
		if ( ! is_array( $value ) ) {
			return null;
		}

		if ( isset( $value['desktop'] ) && is_array( $value['desktop'] ) ) {
			$value = $value['desktop'];
		}

		if ( ! is_array( $value['values'] ?? null ) || count( $value['values'] ) !== 4 || (int) ( $value['state'] ?? 1 ) === 3 ) {
			return null;
		}

		$sides = [];
		$unit  = '';
		$empty = true;

		foreach ( $value['values'] as $side ) {
			$number = is_array( $side ) ? ( $side['value'] ?? '' ) : '';
			$suffix = is_array( $side ) && is_string( $side['unit'] ?? null ) ? $side['unit'] : '';

			if ( $suffix !== '' ) {
				$unit = $suffix;
			}

			if ( $number !== '' && $number !== 'auto' ) {
				$empty = false;
			}

			$sides[] = [ $number, $suffix ];
		}

		if ( $empty ) {
			return null;
		}

		$unit = $unit === '' ? 'px' : $unit;
		$out  = [];

		foreach ( $sides as [ $number, $suffix ] ) {
			if ( $number === '' || $number === 'auto' ) {
				$number = 0;
			}

			$out[] = is_numeric( $number ) && (float) $number === 0.0 ? '0' : $number . ( $suffix === '' ? $unit : $suffix );
		}

		return $out;
	}

	/**
	 * The desktop value of a possibly-responsive typography field, or null when Blocksy skips it.
	 *
	 * @param mixed $value The field value.
	 *
	 * @return string|null
	 */
	private function desktop( $value ): ?string {
		if ( is_array( $value ) ) {
			$value = $value['desktop'] ?? null;
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			$value = (string) $value;
		}

		if ( ! is_string( $value ) || trim( $value ) === '' || strpos( $value, 'CT_CSS_SKIP_RULE' ) !== false ) {
			return null;
		}

		return trim( $value );
	}
}
