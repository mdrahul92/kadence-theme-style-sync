<?php
/**
 * Reads any theme through WordPress's own global styles: the theme's theme.json (or, for a classic theme, the
 * editor palette it registers) with the user's Site Editor changes and Font Library fonts on top.
 *
 * The fallback for every theme without a dedicated adapter. A dedicated adapter (Blocksy) reads more, from
 * the theme's own settings; this one reads what WordPress knows about any theme.
 *
 * Only the theme's and the user's data are read, never core's defaults, so a classic theme without
 * theme.json does not hand core's default button or palette to Kadence as if they were the theme's.
 *
 * @package KTSS
 */

namespace KTSS\Adapters;

defined( 'ABSPATH' ) || exit;

final class Global_Styles_Adapter implements Theme_Adapter {

	private const COLOR_PATTERN = '/^(#[0-9a-f]{3,8}|rgba?\([^)]*\)|hsla?\([^)]*\))$/i';

	/**
	 * Returns [ 'theme' => raw theme.json data, 'user' => raw user global styles data ]. Null reads WordPress.
	 *
	 * @var callable|null
	 */
	private $provider;

	/**
	 * @var array{theme: array<string, mixed>, user: array<string, mixed>}|null
	 */
	private ?array $data = null;

	/**
	 * @var array<string, array{color: string, title: string, variable: string}>|null
	 */
	private ?array $palette = null;

	/**
	 * @param callable|null $provider Data source; tests and previews hand in a fixture.
	 */
	public function __construct( ?callable $provider = null ) {
		$this->provider = $provider;
	}

	public function id(): string {
		return 'wp-' . sanitize_key( get_stylesheet() );
	}

	public function label(): string {
		return (string) wp_get_theme()->get( 'Name' );
	}

	/**
	 * Any theme, except the Kadence theme (Kadence Blocks reads that one itself), once WordPress has loaded
	 * the theme. Reading global styles earlier would cache them before the theme registered its supports.
	 */
	public function is_active(): bool {
		if ( $this->provider !== null ) {
			return true;
		}

		if ( class_exists( 'Kadence\Theme' ) || ! class_exists( 'WP_Theme_JSON_Resolver' ) ) {
			return false;
		}

		return did_action( 'init' ) > 0 || doing_action( 'init' );
	}

	public function flush(): void {
		$this->data    = null;
		$this->palette = null;
	}

	public function palette(): array {
		if ( $this->palette !== null ) {
			return $this->palette;
		}

		$data    = $this->data();
		$entries = $this->origin_list( $data['theme']['settings']['color']['palette'] ?? null, 'theme' );
		$user    = $data['user']['settings']['color']['palette'] ?? null;

		// In its "override" mode Kadence Blocks replaces the theme palette in the theme data with its own, so
		// read the theme's palette from its theme.json files instead.
		if ( $this->provider === null && self::kadence_overrides_palette() ) {
			$entries = self::theme_json_palette();
		}

		// The Site Editor stores an edited theme palette whole, under its origin, and the user's own colors
		// under "custom".
		$user_theme = $this->origin_list( $user, 'theme' );

		if ( $user_theme !== [] ) {
			$entries = $user_theme;
		}

		$entries = array_merge( $entries, $this->origin_list( $user, 'custom' ) );
		$kadence = $this->provider === null ? self::kadence_palette_slugs() : [];
		$palette = [];

		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) || ! is_string( $entry['slug'] ?? null ) || ! is_string( $entry['color'] ?? null ) ) {
				continue;
			}

			$slug = sanitize_key( $entry['slug'] );

			// Kadence Blocks merges its own palette (the one this plugin feeds) into the theme's; reading it
			// back as a theme color would be a loop.
			if ( $slug === '' || isset( $kadence[ $slug ] ) ) {
				continue;
			}

			$palette[ $slug ] = [
				'color'    => trim( $entry['color'] ),
				'title'    => is_string( $entry['name'] ?? null ) && $entry['name'] !== '' ? $entry['name'] : ucwords( str_replace( '-', ' ', $slug ) ),
				'variable' => 'wp--preset--color--' . $slug,
			];
		}

		$this->palette = $palette;

		return $palette;
	}

	/**
	 * Kadence slots by role: the body text color is the strongest text, the page background is the base
	 * background, the most saturated color that reads on white is the brand; the rest of the neutrals fill
	 * the text and background ramps by lightness.
	 */
	public function default_slot_map(): array {
		$palette = $this->usable_palette();
		$map     = array_fill_keys( [ 'palette1', 'palette2', 'palette3', 'palette4', 'palette5', 'palette6', 'palette7', 'palette8', 'palette9' ], '' );

		if ( $palette === [] ) {
			return $map;
		}

		$styles = $this->styles();
		$text   = $this->palette_key_for( (string) ( $styles['color']['text'] ?? '' ) );
		$base   = $this->palette_key_for( (string) ( $styles['color']['background'] ?? '' ) );

		// Brand: named primary / secondary first, then the most saturated colors readable on white.
		$accents = [];

		foreach ( [ 'primary', 'brand', 'secondary', 'tertiary' ] as $name ) {
			if ( isset( $palette[ $name ] ) ) {
				$accents[] = $name;
			}
		}

		$scores = [];

		foreach ( $palette as $key => $info ) {
			if ( in_array( $key, $accents, true ) || $key === $text || $key === $base || $info['chroma'] < 0.12 ) {
				continue;
			}

			$scores[ $key ] = $info['saturation'] * ( $info['contrast'] >= 3 ? 1 : 0.3 );
		}

		arsort( $scores );
		$accents = array_merge( $accents, array_keys( $scores ) );

		$map['palette1'] = $accents[0] ?? '';
		$map['palette2'] = $accents[1] ?? $map['palette1'];

		// Neutral ramps, darkest first. Neutral = little chroma: HSL saturation overstates near-whites.
		$neutrals = array_filter(
			$palette,
			static function ( array $info, string $key ) use ( $map ): bool {
				return $info['chroma'] < 0.12 && ! in_array( $key, [ $map['palette1'], $map['palette2'] ], true );
			},
			ARRAY_FILTER_USE_BOTH
		);

		// Measured from the page background, so a dark style (light text on dark) maps the same way: text
		// ramp = neutrals far from the background, most contrast first; background ramp = neutrals near it.
		$background = $base !== null && isset( $palette[ $base ] ) ? $palette[ $base ]['luminance'] : 1.0;
		$distance   = static function ( array $info ) use ( $background ): float {
			return abs( $info['luminance'] - $background );
		};

		uasort(
			$neutrals,
			static function ( array $a, array $b ) use ( $distance ): int {
				return $distance( $b ) <=> $distance( $a );
			}
		);

		$texts       = array_keys( array_filter( $neutrals, static fn( array $info ): bool => $distance( $info ) >= 0.5 ) );
		$backgrounds = array_reverse( array_keys( array_filter( $neutrals, static fn( array $info ): bool => $distance( $info ) < 0.5 ) ) );

		if ( $text !== null && isset( $palette[ $text ] ) ) {
			$texts = array_values( array_unique( array_merge( [ $text ], $texts ) ) );
		}

		if ( $base !== null && isset( $palette[ $base ] ) ) {
			$backgrounds = array_values( array_unique( array_merge( [ $base ], $backgrounds ) ) );
		}

		$count = count( $texts );

		if ( $count > 0 ) {
			$map['palette3'] = $texts[0];
			$map['palette4'] = $count >= 3 ? $texts[1] : $texts[0];
			$map['palette5'] = $count >= 4 ? $texts[2] : $map['palette4'];
			$map['palette6'] = $texts[ $count - 1 ];
		}

		$count = count( $backgrounds );

		if ( $count > 0 ) {
			$map['palette9'] = $backgrounds[0];
			$map['palette8'] = $count >= 2 ? $backgrounds[1] : $backgrounds[0];
			$map['palette7'] = $count >= 3 ? $backgrounds[2] : $map['palette8'];
		}

		return $map;
	}

	public function direct_slot_map(): array {
		$keys = array_keys( $this->usable_palette() );
		$map  = [];

		for ( $i = 1; $i <= 9; $i++ ) {
			$map[ 'palette' . $i ] = $keys[ $i - 1 ] ?? '';
		}

		return $map;
	}

	public function palette_key_for( string $raw ): ?string {
		$raw = trim( $raw );

		if (
			preg_match( '/^var:preset\|color\|([\w-]+)$/', $raw, $match ) !== 1
			&& preg_match( '/^var\(\s*--wp--preset--color--([\w-]+)\s*(?:,[^)]*)?\)$/', $raw, $match ) !== 1
		) {
			return null;
		}

		$key = sanitize_key( $match[1] );

		return isset( $this->palette()[ $key ] ) ? $key : null;
	}

	public function colors(): array {
		$styles = $this->styles();
		$button = $this->button_node();
		$colors = [
			'text'              => $styles['color']['text'] ?? '',
			'link'              => $styles['elements']['link']['color']['text'] ?? '',
			'link_hover'        => $styles['elements']['link'][':hover']['color']['text'] ?? '',
			'border'            => $styles['border']['color'] ?? '',
			'button_bg'         => $button['color']['background'] ?? '',
			'button_bg_hover'   => $button[':hover']['color']['background'] ?? '',
			'button_text'       => $button['color']['text'] ?? '',
			'button_text_hover' => $button[':hover']['color']['text'] ?? '',
			'button_border'     => $button['border']['color'] ?? '',
		];

		foreach ( $colors as $key => $value ) {
			$value = is_string( $value ) ? $this->css( $value ) : '';

			// A link in "currentColor" is the text color.
			if ( $key !== 'text' && strcasecmp( $value, 'currentColor' ) === 0 ) {
				$value = strpos( $key, 'button' ) === 0 ? '' : $this->css( (string) $colors['text'] );
			}

			$colors[ $key ] = $value;
		}

		return array_filter( $colors, 'strlen' );
	}

	public function button_metrics(): array {
		$button  = $this->button_node();
		$metrics = [];
		$radius  = $this->four( $button['border']['radius'] ?? null, [ 'topLeft', 'topRight', 'bottomRight', 'bottomLeft' ] );
		$padding = $this->four( $button['spacing']['padding'] ?? null, [ 'top', 'right', 'bottom', 'left' ] );

		if ( $radius !== null ) {
			$metrics['radius'] = $radius;
		}

		if ( $padding !== null ) {
			$metrics['padding'] = $padding;
		}

		if ( is_string( $button['border']['width'] ?? null ) && $button['border']['width'] !== '' ) {
			$metrics['border_width'] = $this->css( $button['border']['width'] );
		}

		if ( is_string( $button['border']['style'] ?? null ) && $button['border']['style'] !== '' ) {
			$metrics['border_style'] = $button['border']['style'];
		}

		return $metrics;
	}

	public function button_typography(): array {
		$typography = $this->button_node()['typography'] ?? [];
		$out        = [];
		$map        = [
			'fontSize'      => 'font_size',
			'fontWeight'    => 'font_weight',
			'fontStyle'     => 'font_style',
			'lineHeight'    => 'line_height',
			'letterSpacing' => 'letter_spacing',
			'textTransform' => 'text_transform',
		];

		foreach ( $map as $from => $to ) {
			$value = $typography[ $from ] ?? null;

			if ( is_int( $value ) || is_float( $value ) ) {
				$value = (string) $value;
			}

			if ( is_string( $value ) && trim( $value ) !== '' ) {
				$out[ $to ] = $this->css( $value );
			}
		}

		return $out;
	}

	/**
	 * Font families the theme declares with their own font files, plus Font Library fonts the user
	 * activated. WordPress prints their @font-face rules itself, so they are self-hosted; system font stacks
	 * (no files) are left out because Kadence already has its own.
	 */
	public function fonts(): array {
		$data     = $this->data();
		$families = array_merge(
			$this->origin_list( $data['theme']['settings']['typography']['fontFamilies'] ?? null, 'theme' ),
			$this->origin_list( $data['user']['settings']['typography']['fontFamilies'] ?? null, 'theme' ),
			$this->origin_list( $data['user']['settings']['typography']['fontFamilies'] ?? null, 'custom' )
		);
		$fonts    = [];
		$seen     = [];

		foreach ( $families as $index => $family ) {
			if ( ! is_array( $family ) || ! is_string( $family['fontFamily'] ?? null ) || ! is_array( $family['fontFace'] ?? null ) || $family['fontFace'] === [] ) {
				continue;
			}

			$name = trim( explode( ',', $family['fontFamily'] )[0], " \t\n\r\0\x0B\"'" );

			if ( $name === '' || isset( $seen[ strtolower( $name ) ] ) ) {
				continue;
			}

			$seen[ strtolower( $name ) ] = true;
			$weights                     = [];
			$styles                      = [];

			foreach ( $family['fontFace'] as $face ) {
				if ( ! is_array( $face ) ) {
					continue;
				}

				$weights = array_merge( $weights, self::weights( (string) ( $face['fontWeight'] ?? '400' ) ) );
				$styles[] = is_string( $face['fontStyle'] ?? null ) && $face['fontStyle'] !== '' ? $face['fontStyle'] : 'normal';
			}

			$weights = array_values( array_unique( $weights ) );
			sort( $weights, SORT_NUMERIC );

			$fonts[] = [
				'family'  => $name,
				'label'   => is_string( $family['name'] ?? null ) && $family['name'] !== '' ? $family['name'] : $name,
				'weights' => $weights,
				'styles'  => array_values( array_unique( $styles ) ),
				'source'  => $index < count( $this->origin_list( $data['theme']['settings']['typography']['fontFamilies'] ?? null, 'theme' ) ) ? 'theme' : 'font library',
			];
		}

		return $fonts;
	}

	/**
	 * Every font family the theme declares (theme.json, Site Editor, Font Library) and any family named
	 * directly in its styles, by first family name.
	 */
	public function font_families(): array {
		$data     = $this->data();
		$families = [];
		$declared = array_merge(
			$this->origin_list( $data['theme']['settings']['typography']['fontFamilies'] ?? null, 'theme' ),
			$this->origin_list( $data['user']['settings']['typography']['fontFamilies'] ?? null, 'theme' ),
			$this->origin_list( $data['user']['settings']['typography']['fontFamilies'] ?? null, 'custom' )
		);

		foreach ( $declared as $family ) {
			if ( is_array( $family ) && is_string( $family['fontFamily'] ?? null ) ) {
				$families[] = $family['fontFamily'];
			}
		}

		array_walk_recursive(
			$data,
			static function ( $value, $key ) use ( &$families ): void {
				if ( $key === 'fontFamily' && is_string( $value ) && strpos( $value, 'var' ) !== 0 ) {
					$families[] = $value;
				}
			}
		);

		$out = [];

		foreach ( $families as $stack ) {
			$name = trim( explode( ',', $stack )[0], " \t\n\r\0\x0B\"'" );

			if ( $name !== '' && strpos( $name, '-' ) !== 0 ) {
				$out[ strtolower( $name ) ] = $name;
			}
		}

		return array_values( $out );
	}

	public function watched_options(): array {
		return [ 'theme_mods_' . get_stylesheet() ];
	}

	public function describes_theme_button(): bool {
		// Kadence Blocks already reads a block theme's button styles (and variations) into its presets.
		return false;
	}

	public function fonts_hint(): string {
		return __( 'Add fonts in Appearance → Editor → Styles → Typography (Font Library), or ship them in the theme’s theme.json, and they appear in every Kadence font picker.', 'kadence-theme-style-sync' );
	}

	/**
	 * Theme + user raw data.
	 *
	 * @return array{theme: array<string, mixed>, user: array<string, mixed>}
	 */
	private function data(): array {
		if ( $this->data !== null ) {
			return $this->data;
		}

		$theme = [];
		$user  = [];

		if ( $this->provider !== null ) {
			$given = ( $this->provider )();
			$theme = is_array( $given['theme'] ?? null ) ? $given['theme'] : [];
			$user  = is_array( $given['user'] ?? null ) ? $given['user'] : [];
		} else {
			try {
				$theme = \WP_Theme_JSON_Resolver::get_theme_data()->get_raw_data();
				$user  = \WP_Theme_JSON_Resolver::get_user_data()->get_raw_data();
			} catch ( \Throwable $e ) {
				$theme = [];
				$user  = [];
			}
		}

		$this->data = [
			'theme' => is_array( $theme ) ? $theme : [],
			'user'  => is_array( $user ) ? $user : [],
		];

		return $this->data;
	}

	/**
	 * Theme styles with the user's Site Editor styles on top.
	 *
	 * @return array<string, mixed>
	 */
	private function styles(): array {
		$data  = $this->data();
		$theme = is_array( $data['theme']['styles'] ?? null ) ? $data['theme']['styles'] : [];
		$user  = is_array( $data['user']['styles'] ?? null ) ? $data['user']['styles'] : [];

		return array_replace_recursive( $theme, $user );
	}

	/**
	 * The button element merged with the core/button block styles, as the page renders a button.
	 *
	 * @return array<string, mixed>
	 */
	private function button_node(): array {
		$styles  = $this->styles();
		$element = is_array( $styles['elements']['button'] ?? null ) ? $styles['elements']['button'] : [];
		$block   = is_array( $styles['blocks']['core/button'] ?? null ) ? $styles['blocks']['core/button'] : [];

		unset( $block['variations'] );

		return array_replace_recursive( $element, $block );
	}

	/**
	 * The entries of a theme.json preset list: a plain list (theme.json) or the origin-keyed shape (merged
	 * or user data).
	 *
	 * @param mixed  $list   The raw value.
	 * @param string $origin The origin to read from an origin-keyed shape.
	 *
	 * @return array<int, mixed>
	 */
	private function origin_list( $list, string $origin ): array {
		if ( ! is_array( $list ) ) {
			return [];
		}

		if ( array_is_list( $list ) ) {
			return $origin === 'theme' ? $list : [];
		}

		return is_array( $list[ $origin ] ?? null ) && array_is_list( $list[ $origin ] ) ? $list[ $origin ] : [];
	}

	/**
	 * Palette colors that are plain color literals, with their lightness and saturation.
	 *
	 * @return array<string, array{luminance: float, chroma: float, saturation: float, contrast: float}>
	 */
	private function usable_palette(): array {
		$out = [];

		foreach ( $this->palette() as $key => $entry ) {
			$rgb = self::rgb( $entry['color'] );

			if ( $rgb === null ) {
				continue;
			}

			$luminance = self::luminance( $rgb );
			$max       = (float) max( $rgb );
			$min       = (float) min( $rgb );
			$lightness = ( $max + $min ) / 2;
			$delta     = $max - $min;
			$divisor   = 1 - abs( 2 * $lightness - 1 );

			$out[ $key ] = [
				'luminance'  => $luminance,
				'chroma'     => $delta,
				'saturation' => $delta <= 0.0 || $divisor <= 0.0 ? 0.0 : min( 1.0, $delta / $divisor ),
				'contrast'   => 1.05 / ( $luminance + 0.05 ),
			];
		}

		return $out;
	}

	/**
	 * A theme.json value as CSS: the `var:preset|group|slug` shorthand becomes the variable WordPress prints.
	 */
	private function css( string $value ): string {
		$value = trim( $value );

		return (string) preg_replace( '/^var:preset\|([\w-]+)\|([\w-]+)$/', 'var(--wp--preset--$1--$2)', $value );
	}

	/**
	 * A one-value-or-per-side setting as four CSS values, or null when absent or partial.
	 *
	 * @param mixed    $value The raw value.
	 * @param string[] $keys  The object keys in slot order.
	 *
	 * @return string[]|null
	 */
	private function four( $value, array $keys ): ?array {
		if ( is_string( $value ) && trim( $value ) !== '' ) {
			$css = $this->css( $value );

			return [ $css, $css, $css, $css ];
		}

		if ( ! is_array( $value ) ) {
			return null;
		}

		$out = [];

		foreach ( $keys as $key ) {
			if ( ! is_string( $value[ $key ] ?? null ) || trim( $value[ $key ] ) === '' ) {
				return null;
			}

			$out[] = $this->css( $value[ $key ] );
		}

		return $out;
	}

	/**
	 * Slugs of the palette Kadence Blocks merges into the theme's (its kadence_blocks_colors option).
	 *
	 * @return array<string, true>
	 */
	private static function kadence_palette_slugs(): array {
		$decoded = json_decode( (string) get_option( 'kadence_blocks_colors', '' ), true );
		$slugs   = [];

		foreach ( is_array( $decoded['palette'] ?? null ) ? $decoded['palette'] : [] as $entry ) {
			if ( is_array( $entry ) && is_string( $entry['slug'] ?? null ) ) {
				$slugs[ sanitize_key( $entry['slug'] ) ] = true;
			}
		}

		return $slugs;
	}

	private static function kadence_overrides_palette(): bool {
		$decoded = json_decode( (string) get_option( 'kadence_blocks_colors', '' ), true );

		return is_array( $decoded ) && ( $decoded['override'] ?? false ) === true;
	}

	/**
	 * The palette in the active theme's theme.json files (child over parent), unfiltered.
	 *
	 * @return array<int, mixed>
	 */
	private static function theme_json_palette(): array {
		foreach ( array_unique( [ get_stylesheet_directory(), get_template_directory() ] ) as $dir ) {
			$file = $dir . '/theme.json';

			if ( ! is_readable( $file ) ) {
				continue;
			}

			$json    = wp_json_file_decode( $file, [ 'associative' => true ] );
			$palette = is_array( $json ) ? ( $json['settings']['color']['palette'] ?? null ) : null;

			if ( is_array( $palette ) && $palette !== [] ) {
				return array_values( $palette );
			}
		}

		return [];
	}

	/**
	 * "400" => [400]; "200 800" (variable) => [200…800].
	 *
	 * @return string[]
	 */
	private static function weights( string $weight ): array {
		$parts = preg_split( '/\s+/', trim( $weight ) );

		if ( ! is_array( $parts ) ) {
			return [ '400' ];
		}

		$parts = array_map( static fn( string $part ): string => strtolower( $part ) === 'normal' ? '400' : ( strtolower( $part ) === 'bold' ? '700' : $part ), $parts );

		if ( count( $parts ) === 2 && is_numeric( $parts[0] ) && is_numeric( $parts[1] ) ) {
			$out = [];

			for ( $w = (int) ceil( (float) $parts[0] / 100 ) * 100; $w <= (int) $parts[1]; $w += 100 ) {
				$out[] = (string) $w;
			}

			return $out;
		}

		return is_numeric( $parts[0] ) ? [ (string) (int) $parts[0] ] : [ '400' ];
	}

	/**
	 * A hex / rgb() color as 0–1 channels, or null.
	 *
	 * @return float[]|null
	 */
	private static function rgb( string $color ): ?array {
		$color = trim( $color );

		if ( preg_match( '/^#([0-9a-f]{3,8})$/i', $color, $match ) === 1 ) {
			$hex = $match[1];

			if ( strlen( $hex ) === 3 || strlen( $hex ) === 4 ) {
				$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
			}

			if ( strlen( $hex ) < 6 ) {
				return null;
			}

			return [ (float) hexdec( substr( $hex, 0, 2 ) ) / 255, (float) hexdec( substr( $hex, 2, 2 ) ) / 255, (float) hexdec( substr( $hex, 4, 2 ) ) / 255 ];
		}

		if ( preg_match( '/^rgba?\(\s*([\d.]+)[,\s]+([\d.]+)[,\s]+([\d.]+)/i', $color, $match ) === 1 ) {
			return [ (float) $match[1] / 255, (float) $match[2] / 255, (float) $match[3] / 255 ];
		}

		return null;
	}

	/**
	 * WCAG relative luminance.
	 *
	 * @param float[] $rgb 0–1 channels.
	 */
	private static function luminance( array $rgb ): float {
		$linear = array_map(
			static function ( float $channel ): float {
				return $channel <= 0.03928 ? $channel / 12.92 : ( ( $channel + 0.055 ) / 1.055 ) ** 2.4;
			},
			$rgb
		);

		return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
	}
}
