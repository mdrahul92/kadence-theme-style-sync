<?php
/**
 * Offers the fonts the theme hosts itself (Blocksy custom uploads, locally hosted Google fonts, Typekit…)
 * in every Kadence Blocks font picker — the block editor's typography controls and the Style Library's
 * Typography screen both read the `kadence_blocks_custom_fonts` filter.
 *
 * Listed as custom fonts on purpose: Kadence then leaves loading them to the theme, which already serves
 * them (self-hosted), instead of requesting the same family from Google.
 *
 * @package KTSS
 */

namespace KTSS;

defined( 'ABSPATH' ) || exit;

final class Fonts {

	private Sync $sync;

	public function __construct( Sync $sync ) {
		$this->sync = $sync;
	}

	public function register(): void {
		add_filter( 'kadence_blocks_custom_fonts', [ $this, 'add_fonts' ], 20 );
	}

	/**
	 * @param mixed $fonts Kadence's custom fonts, keyed by CSS font-family.
	 *
	 * @return mixed
	 */
	public function add_fonts( $fonts ) {
		if ( ! is_array( $fonts ) || ! Settings::enabled( 'fonts' ) ) {
			return $fonts;
		}

		$adapter = $this->sync->adapter();

		if ( $adapter === null ) {
			return $fonts;
		}

		foreach ( $adapter->fonts() as $font ) {
			if ( isset( $fonts[ $font['family'] ] ) ) {
				continue;
			}

			$fonts[ $font['family'] ] = [
				'name'    => $font['family'],
				'label'   => $font['label'],
				'weights' => $font['weights'] !== [] ? $font['weights'] : [ '400' ],
				'styles'  => $font['styles'] !== [] ? $font['styles'] : [ 'normal' ],
			];
		}

		return $fonts;
	}
}
