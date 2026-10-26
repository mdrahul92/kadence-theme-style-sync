<?php
/**
 * Puts the theme's Google fonts at the top of every Kadence font picker, as Style Library favorites, and keeps
 * Kadence's "Load Google Fonts Locally" setting on so those fonts are served from the site.
 *
 * Favorites live in the active Style Library's saved document, so they are written through Kadence's own
 * document pipeline (the same validation its "favorite" button runs). The plugin remembers which favorites it
 * added: when the theme stops using a font, only those are removed; favorites added by hand are never touched.
 *
 * @package KTSS
 */

namespace KTSS;

defined( 'ABSPATH' ) || exit;

final class Font_Favorites {

	/**
	 * Library slug => families this plugin added to that library's favorites.
	 */
	private const OPTION = 'ktss_favorite_fonts';

	private const KADENCE_FONT_SETTINGS = 'kadence_blocks_font_settings';

	private Sync $sync;

	public function __construct( Sync $sync ) {
		$this->sync = $sync;
	}

	/**
	 * The theme's font families that are Google fonts, in Kadence's own spelling.
	 *
	 * @return string[]
	 */
	public function google_families(): array {
		$adapter = $this->sync->adapter();

		if ( $adapter === null ) {
			return [];
		}

		$catalog = self::google_catalog();
		$out     = [];

		foreach ( $adapter->font_families() as $family ) {
			$key = strtolower( trim( $family ) );

			if ( isset( $catalog[ $key ] ) ) {
				$out[ $key ] = $catalog[ $key ];
			}
		}

		return array_values( $out );
	}

	/**
	 * Bring the active library's favorites in line with the theme.
	 *
	 * @return array{added: string[], removed: string[]}|\WP_Error
	 */
	public function sync() {
		$wanted = Settings::enabled( 'favorite_fonts' ) ? $this->google_families() : [];

		try {
			$container = kadence_blocks();
			$store     = $container->get( \KadenceWP\KadenceBlocks\Design_Tokens\Database\Token_Store::class );
			$index     = $container->get( \KadenceWP\KadenceBlocks\Design_Tokens\Document\Favorite_Font_Index::class );
			$pipeline  = $container->get( \KadenceWP\KadenceBlocks\Design_Tokens\Rest\V1\Document_Write_Pipeline::class );
			$slug      = $container->get( \KadenceWP\KadenceBlocks\Design_Tokens\Database\Active_Token_Library_Store::class )->get();
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'ktss_kadence_unavailable', $e->getMessage() );
		}

		$stored  = $store->get_decoded_document( $slug );
		$stored  = is_array( $stored ) ? $stored : [];
		$doc     = $stored;
		$owned   = get_option( self::OPTION, [] );
		$owned   = is_array( $owned ) ? $owned : [];
		$ours    = is_array( $owned[ $slug ] ?? null ) ? $owned[ $slug ] : [];
		$lower   = array_map( 'strtolower', $wanted );
		$keep    = [];
		$added   = [];
		$removed = [];

		foreach ( $ours as $family ) {
			if ( in_array( strtolower( (string) $family ), $lower, true ) ) {
				$keep[] = $family;
				continue;
			}

			$doc       = $index->remove( $doc, (string) $family );
			$removed[] = $family;
		}

		foreach ( $wanted as $family ) {
			if ( $index->has( $doc, $family ) ) {
				continue;
			}

			$doc     = $index->add( $doc, $family );
			$added[] = $family;
		}

		if ( $doc !== $stored ) {
			$result = $pipeline->validate_and_save( $doc, $slug, '', (string) $store->get_version( $slug ) );

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		$owned[ $slug ] = array_values( array_unique( array_merge( $keep, $added ) ) );
		update_option( self::OPTION, $owned, false );

		return [
			'added'   => $added,
			'removed' => $removed,
		];
	}

	/**
	 * Keep Kadence's "Load Google Fonts Locally" on while the part is enabled.
	 */
	public static function ensure_local_fonts(): void {
		if ( ! Settings::enabled( 'local_google_fonts' ) ) {
			return;
		}

		$settings = get_option( self::KADENCE_FONT_SETTINGS, [] );
		$settings = is_array( $settings ) ? $settings : [];

		if ( ( $settings['load_fonts_local'] ?? '' ) === 'true' ) {
			return;
		}

		$settings['load_fonts_local'] = 'true';
		update_option( self::KADENCE_FONT_SETTINGS, $settings );
	}

	public static function local_fonts_on(): bool {
		$settings = get_option( self::KADENCE_FONT_SETTINGS, [] );

		return is_array( $settings ) && ( $settings['load_fonts_local'] ?? '' ) === 'true';
	}

	/**
	 * The active library's current favorites.
	 *
	 * @return string[]
	 */
	public static function current_favorites(): array {
		try {
			$container = kadence_blocks();
			$slug      = $container->get( \KadenceWP\KadenceBlocks\Design_Tokens\Database\Active_Token_Library_Store::class )->get();
			$doc       = $container->get( \KadenceWP\KadenceBlocks\Design_Tokens\Database\Token_Store::class )->get_decoded_document( $slug );

			return $container->get( \KadenceWP\KadenceBlocks\Design_Tokens\Document\Favorite_Font_Index::class )->all( is_array( $doc ) ? $doc : [] );
		} catch ( \Throwable $e ) {
			return [];
		}
	}

	/**
	 * Kadence's Google font names, lowercased name => name.
	 *
	 * @return array<string, string>
	 */
	private static function google_catalog(): array {
		static $catalog = null;

		if ( $catalog !== null ) {
			return $catalog;
		}

		$catalog = [];

		try {
			$names = kadence_blocks()->get( \KadenceWP\KadenceBlocks\Design_Tokens\Admin\Feed\Font_Catalog::class )->all()['google'];
		} catch ( \Throwable $e ) {
			$names = [];
		}

		foreach ( $names as $name ) {
			$catalog[ strtolower( $name ) ] = $name;
		}

		return $catalog;
	}
}
