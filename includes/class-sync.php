<?php
/**
 * Turns what the active theme adapter reads into Kadence Blocks design-token values.
 *
 * Theme-agnostic: everything theme specific lives in the adapter. Three outputs:
 *
 *  - snapshot():      the Kadence-theme-shaped Style Guide snapshot that Kadence's own mapper re-values
 *                     palette1…9 and the button colors from (fed through Kadence_Bridge\Style_Guide_Source).
 *  - extra_tokens():  theme colors that no Kadence slot carries, registered as extra color tokens.
 *  - leaf_values() /
 *    preset_values(): token and preset values Kadence's mapper does not cover (text, link, border, button
 *                     border / radius / padding / typography), written by Kadence_Bridge\Baseline_Document.
 *
 * Everything lands on the Style Library's BASELINE, never in the stored library: what a user saves in the
 * Style Library still wins, and "reset" in the Style Library returns to the theme's value.
 *
 * @package KTSS
 */

namespace KTSS;

use KTSS\Adapters\Theme_Adapter;

defined( 'ABSPATH' ) || exit;

final class Sync {

	/**
	 * Prefix of the extra color token ids: primitive.color.theme.<key>.
	 */
	public const EXTRA_PREFIX = 'primitive.color.theme.';

	/**
	 * Kadence Blocks 3.8 slot => token map, used until the registry is populated (init:0).
	 */
	private const FALLBACK_SLOT_TOKENS = [
		'palette1' => 'primitive.color.brand.primary',
		'palette2' => 'primitive.color.brand.secondary',
		'palette3' => 'primitive.color.neutral.900',
		'palette4' => 'primitive.color.neutral.700',
		'palette5' => 'primitive.color.neutral.600',
		'palette6' => 'primitive.color.neutral.500',
		'palette7' => 'primitive.color.neutral.100',
		'palette8' => 'primitive.color.neutral.50',
		'palette9' => 'primitive.color.neutral.0',
	];

	private const COLOR_PATTERN = '/^(#[0-9a-f]{3,8}|rgba?\([^)]*\)|hsla?\([^)]*\)|transparent)$/i';

	/**
	 * @var Theme_Adapter[]
	 */
	private array $adapters;

	/**
	 * Per-request memo of fingerprint().
	 */
	private ?string $fingerprint = null;

	/**
	 * @param Theme_Adapter[] $adapters The known adapters, most specific first.
	 */
	public function __construct( array $adapters ) {
		$this->adapters = $adapters;
	}

	/**
	 * The adapter for the active theme, or null when no adapter fits (or the theme is not loaded yet).
	 */
	public function adapter(): ?Theme_Adapter {
		foreach ( $this->adapters as $adapter ) {
			if ( $adapter->is_active() ) {
				return $adapter;
			}
		}

		return null;
	}

	/**
	 * Drop per-request memos so a write inside this request is read back.
	 */
	public function flush(): void {
		$this->fingerprint = null;

		foreach ( $this->adapters as $adapter ) {
			if ( method_exists( $adapter, 'flush' ) ) {
				$adapter->flush();
			}
		}
	}

	/**
	 * The Style Guide snapshot in the Kadence theme's shape, or null when no adapter is active.
	 *
	 * @return array{palette: array<string, mixed>, settings: array<string, mixed>}|null
	 */
	public function snapshot(): ?array {
		$adapter = $this->adapter();

		if ( $adapter === null ) {
			return null;
		}

		$entries = [];

		if ( Settings::enabled( 'palette' ) ) {
			$palette = $adapter->palette();

			foreach ( Settings::slot_map( $adapter ) as $slot => $key ) {
				if ( $key === '' || ! isset( $palette[ $key ] ) ) {
					continue;
				}

				$entries[] = [
					'slug'  => $slot,
					'name'  => $palette[ $key ]['title'],
					'color' => $palette[ $key ]['color'],
				];
			}
		}

		// Button colors are not handed to Kadence's mapper (it would point the button semantics straight at a
		// palette slot); leaf_values() routes them through the palette's "Button" swatches instead.
		return [
			'palette'  => [
				'active'  => 'palette',
				'palette' => $entries,
			],
			'settings' => [],
		];
	}

	/**
	 * Theme colors no synced Kadence slot carries, as extra color tokens.
	 *
	 * @return array<string, array{key: string, label: string, value: string}> Token id => definition.
	 */
	public function extra_tokens(): array {
		$adapter = $this->adapter();

		if ( $adapter === null || ! Settings::enabled( 'extra_colors' ) ) {
			return [];
		}

		$mapped = Settings::enabled( 'palette' ) ? array_filter( Settings::slot_map( $adapter ) ) : [];
		$extras = [];

		foreach ( $adapter->palette() as $key => $entry ) {
			if ( in_array( $key, $mapped, true ) || preg_match( self::COLOR_PATTERN, $entry['color'] ) !== 1 ) {
				continue;
			}

			$extras[ self::extra_id( $key ) ] = [
				'key'   => $key,
				'label' => sprintf( '%s %s', $adapter->label(), $entry['title'] ),
				'value' => $entry['color'],
			];
		}

		return $extras;
	}

	/**
	 * Token id => value for the tokens this plugin re-values beyond Kadence's own mapper. Only ids that
	 * already exist in Kadence's baseline are written by the decorator; anything else is ignored there.
	 *
	 * @return array<string, mixed>
	 */
	public function leaf_values(): array {
		$adapter = $this->adapter();

		if ( $adapter === null ) {
			return [];
		}

		$colors = $adapter->colors();
		$values = [];

		if ( Settings::enabled( 'text_links' ) ) {
			$values['semantic.color.text']          = $this->token_value( $adapter, $colors['text'] ?? '' );
			$values['semantic.color.link']          = $this->token_value( $adapter, $colors['link'] ?? '' );
			$values['primitive.color.neutral.200']  = $this->token_value( $adapter, $colors['border'] ?? '' );
		}

		if ( Settings::enabled( 'buttons' ) ) {
			// The palette's "Button" swatches carry the theme's button colors (as an alias when the theme points
			// at a palette color, so editing that palette color moves the buttons too, as in the theme), and the
			// button semantics read those swatches, so editing a "Button" swatch in the Style Library restyles
			// buttons as well.
			$values['primitive.color.brand.button']       = $this->token_value( $adapter, $colors['button_bg'] ?? '' );
			$values['primitive.color.brand.button-hover'] = $this->token_value( $adapter, $colors['button_bg_hover'] ?? '' );

			// A hover the theme paints with something that is not a plain color (color-mix(), a gradient) falls
			// back to the resting color rather than Kadence's own hover blue.
			$values['primitive.color.brand.button-hover'] = $values['primitive.color.brand.button-hover'] ?? $values['primitive.color.brand.button'];
			$values['semantic.color.button-bg']           = $values['primitive.color.brand.button'] !== null ? '{primitive.color.brand.button}' : null;
			$values['semantic.color.button-bg-hover']     = $values['primitive.color.brand.button-hover'] !== null ? '{primitive.color.brand.button-hover}' : null;
			$values['semantic.color.button-text']         = $this->token_value( $adapter, $colors['button_text'] ?? '' );
			$values['semantic.color.button-text-hover']   = $this->token_value( $adapter, $colors['button_text_hover'] ?? '' );

			if ( isset( $colors['button_border'] ) ) {
				$values['semantic.color.button-border'] = $this->token_value( $adapter, $colors['button_border'] );
			}

			$metrics = $adapter->button_metrics();

			if ( isset( $metrics['radius'] ) && is_array( $metrics['radius'] ) && $metrics['radius'] !== [] ) {
				$values['semantic.radius.control'] = (string) reset( $metrics['radius'] );
			}

			if ( isset( $metrics['border_width'] ) ) {
				$values['semantic.border-width.button'] = (string) $metrics['border_width'];
			}

			if ( isset( $metrics['border_style'] ) ) {
				$values['semantic.border-style.button'] = (string) $metrics['border_style'];
			}
		}

		if ( Settings::enabled( 'button_typography' ) ) {
			$typography = $adapter->button_typography();
			$map        = [
				'font_size'      => 'semantic.font-size.control',
				'font_weight'    => 'semantic.font-weight.control',
				'font_style'     => 'semantic.font-style.control',
				'line_height'    => 'semantic.line-height.control',
				'letter_spacing' => 'semantic.letter-spacing.control',
				'text_transform' => 'semantic.text-transform.control',
			];

			foreach ( $map as $from => $token ) {
				if ( isset( $typography[ $from ] ) ) {
					$values[ $token ] = $typography[ $from ];
				}
			}
		}

		return array_filter(
			$values,
			static function ( $value ): bool {
				return $value !== null && $value !== '';
			}
		);
	}

	/**
	 * Block preset token values to write: block => preset => property => value.
	 *
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	public function preset_values(): array {
		$adapter = $this->adapter();

		if ( $adapter === null || ! Settings::enabled( 'buttons' ) ) {
			return [];
		}

		$metrics = $adapter->button_metrics();

		if ( ! isset( $metrics['padding'] ) || ! is_array( $metrics['padding'] ) || count( $metrics['padding'] ) !== 4 ) {
			return [];
		}

		return [
			'kadence/singlebtn' => [
				'default' => [
					'button-padding' => array_values( array_map( 'strval', $metrics['padding'] ) ),
				],
			],
		];
	}

	/**
	 * Display values for the theme's button preset in the Style Library (the "Theme Button" preset that
	 * the theme's own stylesheet paints).
	 *
	 * @return array<string, mixed>
	 */
	public function button_style_values(): array {
		$adapter = $this->adapter();

		if ( $adapter === null || ! Settings::enabled( 'buttons' ) ) {
			return [];
		}

		$colors  = $adapter->colors();
		$metrics = $adapter->button_metrics();
		$values  = [
			'button-bg'         => $this->token_value( $adapter, $colors['button_bg'] ?? '' ),
			'button-bg-hover'   => $this->token_value( $adapter, $colors['button_bg_hover'] ?? '' ),
			'button-text'       => $this->token_value( $adapter, $colors['button_text'] ?? '' ),
			'button-text-hover' => $this->token_value( $adapter, $colors['button_text_hover'] ?? '' ),
		];

		if ( isset( $colors['button_border'] ) ) {
			$values['button-border-color'] = $this->token_value( $adapter, $colors['button_border'] );
		}

		if ( isset( $metrics['radius'] ) ) {
			$values['button-radius'] = $metrics['radius'];
		}

		if ( isset( $metrics['padding'] ) ) {
			$values['button-padding'] = $metrics['padding'];
		}

		if ( isset( $metrics['border_width'] ) ) {
			$values['button-border-width'] = $metrics['border_width'];
		}

		if ( isset( $metrics['border_style'] ) ) {
			$values['button-border-style'] = $metrics['border_style'];
		}

		return array_filter(
			$values,
			static function ( $value ): bool {
				return $value !== null && $value !== '' && $value !== [];
			}
		);
	}

	/**
	 * A short hash of everything this plugin feeds Kadence. Changes whenever the theme's styles, this
	 * plugin's settings, or the plugin itself change.
	 */
	public function fingerprint(): string {
		if ( $this->fingerprint !== null ) {
			return $this->fingerprint;
		}

		$adapter = $this->adapter();

		if ( $adapter === null ) {
			return '';
		}

		$this->fingerprint = md5(
			(string) wp_json_encode(
				[
					KTSS_VERSION,
					$adapter->id(),
					Settings::get(),
					$this->snapshot(),
					$this->extra_tokens(),
					$this->leaf_values(),
					$this->preset_values(),
				]
			)
		);

		return $this->fingerprint;
	}

	/**
	 * The extra token id for a theme color key ("color10" => "primitive.color.theme.color-10").
	 */
	public static function extra_id( string $key ): string {
		$slug = strtolower( (string) preg_replace( '/([a-z])(\d)/i', '$1-$2', $key ) );
		$slug = trim( (string) preg_replace( '/[^a-z0-9]+/', '-', $slug ), '-' );

		return self::EXTRA_PREFIX . $slug;
	}

	/**
	 * Kadence slot => token id, from the registry once it is populated.
	 *
	 * @return array<string, string>
	 */
	public function slot_tokens(): array {
		static $resolved = null;

		if ( $resolved !== null ) {
			return $resolved;
		}

		try {
			$overlay = kadence_blocks()->get( \KadenceWP\KadenceBlocks\Design_Tokens\Theme_Style_Guide\Style_Guide_Overlay::class );
			$tokens  = $overlay->slot_tokens();
		} catch ( \Throwable $e ) {
			$tokens = [];
		}

		if ( $tokens === [] ) {
			return self::FALLBACK_SLOT_TOKENS;
		}

		$resolved = $tokens;

		return $resolved;
	}

	/**
	 * The token that carries a theme color key, or null: the first synced Kadence slot mapped to it, else
	 * its extra token.
	 */
	public function token_for_key( Theme_Adapter $adapter, string $key ): ?string {
		if ( Settings::enabled( 'palette' ) ) {
			$tokens = $this->slot_tokens();

			foreach ( Settings::slot_map( $adapter ) as $slot => $mapped ) {
				if ( $mapped === $key && isset( $tokens[ $slot ] ) ) {
					return $tokens[ $slot ];
				}
			}
		}

		$extra = self::extra_id( $key );

		return isset( $this->extra_tokens()[ $extra ] ) ? $extra : null;
	}

	/**
	 * A raw theme color as a token value: an alias of the token carrying the referenced palette color (so it
	 * follows Style Library edits of that color), else the literal. Null when unusable.
	 */
	public function token_value( Theme_Adapter $adapter, string $raw ): ?string {
		$raw = trim( $raw );

		if ( $raw === '' ) {
			return null;
		}

		$key = $adapter->palette_key_for( $raw );

		if ( $key !== null ) {
			$token = $this->token_for_key( $adapter, $key );

			if ( $token !== null ) {
				return '{' . $token . '}';
			}
		}

		return $this->literal( $adapter, $raw );
	}

	/**
	 * A raw theme color as a color literal (palette references resolved), or null.
	 */
	public function literal( Theme_Adapter $adapter, string $raw ): ?string {
		$raw = trim( $raw );
		$key = $adapter->palette_key_for( $raw );

		if ( $key !== null ) {
			$raw = $adapter->palette()[ $key ]['color'] ?? '';
		}

		return preg_match( self::COLOR_PATTERN, $raw ) === 1 ? $raw : null;
	}

	/**
	 * The color each re-valued palette primitive renders, for its Style Library swatch (a swatch shows a
	 * color, not an alias).
	 *
	 * @return array<string, string> Token id => color literal.
	 */
	public function swatch_literals(): array {
		$adapter = $this->adapter();

		if ( $adapter === null ) {
			return [];
		}

		$colors   = $adapter->colors();
		$literals = [];

		if ( Settings::enabled( 'text_links' ) ) {
			$literals['primitive.color.neutral.200'] = $this->literal( $adapter, $colors['border'] ?? '' );
		}

		if ( Settings::enabled( 'buttons' ) ) {
			$literals['primitive.color.brand.button']       = $this->literal( $adapter, $colors['button_bg'] ?? '' );
			$literals['primitive.color.brand.button-hover'] = $this->literal( $adapter, $colors['button_bg_hover'] ?? '' ) ?? $literals['primitive.color.brand.button'];
		}

		return array_filter( $literals );
	}
}
