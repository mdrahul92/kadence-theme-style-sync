<?php
/**
 * Wires the sync into Kadence Blocks' container and WordPress.
 *
 * @package KTSS
 */

namespace KTSS;

use KadenceWP\KadenceBlocks\Design_Tokens\Registry\Contracts\Baseline_Document as Baseline_Contract;
use KadenceWP\KadenceBlocks\Design_Tokens\Theme_Buttons\Theme_Button_Styles_Overlay;
use KadenceWP\KadenceBlocks\Design_Tokens\Theme_Style_Guide\Contracts\Style_Guide_Source as Source_Contract;
use KadenceWP\KadenceBlocks\Design_Tokens\Theme_Style_Guide\Style_Guide_Overlay;
use KadenceWP\KadenceBlocks\Design_Tokens\Theme_Style_Guide\Style_Guide_Reader;
use KTSS\Adapters\Blocksy_Adapter;
use KTSS\Adapters\Global_Styles_Adapter;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	/**
	 * Option holding the fingerprint Kadence's caches were last built from.
	 */
	private const FINGERPRINT_OPTION = 'ktss_fingerprint';

	/**
	 * Kadence's "last synced palette" marker; deleting it makes Kadence re-sync kadence_blocks_colors.
	 */
	private const KB_PALETTE_MARKER = 'kadence_blocks_design_tokens_palette_sync';

	private static ?Sync $sync = null;

	private static ?Kadence_Bridge\Baseline_Document $baseline = null;

	private static bool $dirty = false;

	/**
	 * Option holding the last font sync outcome, for the settings screen.
	 */
	public const FONT_SYNC_OPTION = 'ktss_font_sync';

	public static function sync(): ?Sync {
		return self::$sync;
	}

	public static function baseline_active(): bool {
		return self::$baseline !== null;
	}

	/**
	 * plugins_loaded:20.
	 */
	public static function boot(): void {
		/**
		 * Theme adapters, most specific first; the first active one is used. Global_Styles_Adapter reads any
		 * theme through WordPress global styles and stays last as the fallback. Add an adapter implementing
		 * KTSS\Adapters\Theme_Adapter (before the fallback) to read a theme's own settings in more depth.
		 *
		 * @param \KTSS\Adapters\Theme_Adapter[] $adapters
		 */
		$adapters   = apply_filters( 'ktss_theme_adapters', [ new Blocksy_Adapter(), new Global_Styles_Adapter() ] );
		self::$sync = new Sync( array_values( array_filter( (array) $adapters, static fn( $a ) => $a instanceof Adapters\Theme_Adapter ) ) );

		( new Admin( self::$sync ) )->register();

		if ( ! self::kadence_supported() ) {
			add_action( 'admin_notices', [ self::class, 'unsupported_notice' ] );

			return;
		}

		require_once KTSS_PATH . 'includes/kadence/class-style-guide-source.php';
		require_once KTSS_PATH . 'includes/kadence/class-baseline-document.php';

		$sync      = self::$sync;
		$container = kadence_blocks();

		// 1. The theme Style Guide source: palette1…9.
		$source = new Kadence_Bridge\Style_Guide_Source( $sync, new Style_Guide_Reader() );

		$container->singleton(
			Source_Contract::class,
			static function () use ( $source ): Source_Contract {
				return $source;
			}
		);

		self::inject_source( $source );

		// 2. The baseline decorator, wrapped around whatever chain Kadence built. Done on init:-1, after the
		// theme is loaded and before Kadence registers its declarations (init:0) or guards them (init:1).
		add_action( 'init', [ self::class, 'decorate_baseline' ], -1 );
		add_action( 'init', [ self::class, 'register_extra_tokens' ], 0 );
		add_action( 'init', [ self::class, 'check_fingerprint' ], 2 );

		( new Fonts( $sync ) )->register();

		add_filter( 'kadence_blocks_theme_button_styles', [ self::class, 'theme_button_styles' ], 10, 2 );

		// Invalidate whenever the theme's styles or this plugin's settings change.
		add_action( 'customize_save_after', [ self::class, 'mark_dirty' ], 100 );
		add_action( 'save_post_wp_global_styles', [ self::class, 'mark_dirty' ], 100 );
		add_action( 'switch_theme', [ self::class, 'mark_dirty' ], 100 );
		add_action( 'customize_preview_init', [ self::class, 'flush' ], 1 );

		$watched = [ Settings::OPTION ];

		foreach ( (array) $adapters as $candidate ) {
			if ( $candidate instanceof Adapters\Theme_Adapter ) {
				$watched = array_merge( $watched, $candidate->watched_options() );
			}
		}

		foreach ( array_unique( $watched ) as $option ) {
			foreach ( [ 'add_option_', 'update_option_', 'delete_option_' ] as $prefix ) {
				add_action( $prefix . $option, [ self::class, 'mark_dirty' ], 100 );
			}
		}

		add_action( 'shutdown', [ self::class, 'resync_if_dirty' ], 0 );
	}

	/**
	 * Whether this Kadence Blocks build has the design-token seams this plugin plugs into (3.8+).
	 */
	public static function kadence_supported(): bool {
		return function_exists( 'kadence_blocks' )
			&& interface_exists( Source_Contract::class )
			&& interface_exists( Baseline_Contract::class )
			&& class_exists( Style_Guide_Reader::class )
			&& class_exists( Style_Guide_Overlay::class )
			&& class_exists( \KadenceWP\KadenceBlocks\Design_Tokens\Schema\Vocabulary\Extensions::class )
			&& class_exists( \KadenceWP\KadenceBlocks\Design_Tokens\Schema\Vocabulary\Sentinels::class );
	}

	/**
	 * Kadence's Style_Guide_Overlay is a singleton that its own provider already built on plugins_loaded:1
	 * (registering its flush hooks through the container resolves it), so it holds Kadence's reader
	 * before any plugin can rebind the contract. Hand that instance our source.
	 *
	 * @param Source_Contract $source Our source.
	 */
	private static function inject_source( Source_Contract $source ): void {
		try {
			$overlay  = kadence_blocks()->get( Style_Guide_Overlay::class );
			$property = new \ReflectionProperty( $overlay, 'source' );
			$property->setAccessible( true );
			$property->setValue( $overlay, $source );
			$overlay->flush();
		} catch ( \Throwable $e ) {
			// A Kadence build without this property: the container binding above still applies to any
			// overlay built from now on.
			return;
		}
	}

	public static function unsupported_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'Theme Style Sync for Kadence Blocks needs Kadence Blocks 3.8 or newer (the Style Library). It is inactive until then.', 'kadence-theme-style-sync' );
		echo '</p></div>';
	}

	/**
	 * init:-1 — put the decorator into Kadence's baseline chain.
	 *
	 * Kadence's resolver, palettes, presets and guard are built early (during its own provider registration)
	 * and each holds the one baseline instance, so rebinding the container alone reaches none of them. The
	 * outermost Kadence decorator delegates to an `inner` baseline; wrapping that inner one puts this
	 * plugin's values in front of every consumer at once. The container is only rebound as a fallback for a
	 * Kadence build whose chain is shaped differently.
	 */
	public static function decorate_baseline(): void {
		if ( self::$sync === null || self::$sync->adapter() === null ) {
			return;
		}

		try {
			$container = kadence_blocks();
			$outer     = $container->get( Baseline_Contract::class );
		} catch ( \Throwable $e ) {
			return;
		}

		if ( ! $outer instanceof Baseline_Contract ) {
			return;
		}

		try {
			$property = new \ReflectionProperty( $outer, 'inner' );
			$property->setAccessible( true );
			$inner = $property->getValue( $outer );

			if ( $inner instanceof Baseline_Contract ) {
				if ( $inner instanceof Kadence_Bridge\Baseline_Document ) {
					self::$baseline = $inner;

					return;
				}

				$decorator = new Kadence_Bridge\Baseline_Document( $inner, self::$sync );
				$property->setValue( $outer, $decorator );
				self::$baseline = $decorator;

				return;
			}
		} catch ( \Throwable $e ) {
			// Fall through to the container rebind.
		}

		$decorator      = new Kadence_Bridge\Baseline_Document( $outer, self::$sync );
		self::$baseline = $decorator;

		$container->singleton(
			Baseline_Contract::class,
			static function () use ( $decorator ): Baseline_Contract {
				return $decorator;
			}
		);
	}

	/**
	 * init:0 (after Kadence's own declarations) — register the extra theme colors as color tokens. Only when
	 * the decorator is in place, because it is what gives them a baseline entry.
	 */
	public static function register_extra_tokens(): void {
		if ( self::$baseline === null || ! function_exists( 'kadence_blocks_register_design_token' ) ) {
			return;
		}

		foreach ( self::$sync->extra_tokens() as $id => $token ) {
			try {
				kadence_blocks_register_design_token(
					[
						'id'    => $id,
						'type'  => 'color',
						'label' => $token['label'],
						'group' => __( 'Palette', 'kadence-blocks' ),
					]
				);
			} catch ( \Throwable $e ) {
				continue;
			}
		}
	}

	/**
	 * init:2 — when anything this plugin feeds Kadence changed since Kadence last built its caches, drop
	 * those caches. Kadence keys them on its own store version plus the palette signature, which does not
	 * cover links, buttons or extra colors.
	 */
	public static function check_fingerprint(): void {
		if ( self::$sync === null ) {
			return;
		}

		$fingerprint = self::$sync->fingerprint();

		if ( get_option( self::FINGERPRINT_OPTION ) === $fingerprint ) {
			return;
		}

		self::invalidate_kadence();
		self::sync_fonts();
		update_option( self::FINGERPRINT_OPTION, $fingerprint, true );
	}

	/**
	 * Keep Kadence loading Google fonts locally and the theme's Google fonts in the Style Library favorites.
	 * Runs when the theme's styles change, not on every request: favorites are a saved-document write.
	 */
	public static function sync_fonts(): void {
		if ( self::$sync === null || ! self::kadence_supported() || ! did_action( 'init' ) ) {
			return;
		}

		Font_Favorites::ensure_local_fonts();

		$result = ( new Font_Favorites( self::$sync ) )->sync();

		update_option(
			self::FONT_SYNC_OPTION,
			[
				'time'    => time(),
				'error'   => is_wp_error( $result ) ? $result->get_error_message() : '',
				'added'   => is_wp_error( $result ) ? [] : $result['added'],
				'removed' => is_wp_error( $result ) ? [] : $result['removed'],
			],
			false
		);
	}

	/**
	 * A watched option changed: re-read now, finish the job once at shutdown.
	 */
	public static function mark_dirty(): void {
		self::$dirty = true;
		self::flush();
	}

	/**
	 * Drop every per-request memo that depends on the theme's styles.
	 */
	public static function flush(): void {
		if ( self::$sync !== null ) {
			self::$sync->flush();
		}

		if ( self::$baseline !== null ) {
			self::$baseline->flush();
		}

		if ( ! function_exists( 'kadence_blocks' ) ) {
			return;
		}

		foreach ( [ Style_Guide_Overlay::class, Theme_Button_Styles_Overlay::class ] as $class ) {
			try {
				if ( class_exists( $class ) ) {
					kadence_blocks()->get( $class )->flush();
				}
			} catch ( \Throwable $e ) {
				continue;
			}
		}
	}

	public static function resync_if_dirty(): void {
		if ( ! self::$dirty || ! did_action( 'init' ) || self::$sync === null ) {
			return;
		}

		self::$dirty = false;
		self::flush();
		self::invalidate_kadence();
		self::sync_fonts();

		try {
			$projector = kadence_blocks()->get( \KadenceWP\KadenceBlocks\Design_Tokens\Projection\Kadence_Option\Projector::class );
			$projector->on_tokens_changed();
		} catch ( \Throwable $e ) {
			// Kadence re-syncs on the next request instead (its marker was deleted).
		}

		update_option( self::FINGERPRINT_OPTION, self::$sync->fingerprint(), true );
	}

	/**
	 * Flush Kadence's design-token cache group and its palette sync marker.
	 */
	private static function invalidate_kadence(): void {
		self::flush();
		delete_option( self::KB_PALETTE_MARKER );

		if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) ) {
			wp_cache_flush_group( 'kb_design_tokens' );
		} elseif ( wp_using_ext_object_cache() ) {
			wp_cache_flush();
		}
	}

	/**
	 * The theme's button preset in the Style Library: name it after the theme and show the values the
	 * theme renders. The classes stay Kadence's, so the theme's own stylesheet keeps painting it.
	 *
	 * @param mixed  $styles Discovered styles keyed by unprefixed slug.
	 * @param string $theme  Active stylesheet.
	 *
	 * @return mixed
	 */
	public static function theme_button_styles( $styles, $theme = '' ) {
		if ( ! is_array( $styles ) || ! isset( $styles['base'] ) || ! is_array( $styles['base'] ) || self::$sync === null ) {
			return $styles;
		}

		$adapter = self::$sync->adapter();

		if (
			$adapter === null
			|| ! Settings::enabled( 'buttons' )
			|| ! method_exists( $adapter, 'describes_theme_button' )
			|| ! $adapter->describes_theme_button()
		) {
			return $styles;
		}

		$styles['base']['label']  = sprintf( '%s Button', $adapter->label() );
		$styles['base']['values'] = array_merge(
			is_array( $styles['base']['values'] ?? null ) ? $styles['base']['values'] : [],
			self::$sync->button_style_values()
		);

		return $styles;
	}
}
