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

		// enqueue_block_assets reaches both the front end and the block editor's canvas iframe.
		add_action( 'enqueue_block_assets', [ $this, 'enqueue_font_faces' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_font_faces' ] );
	}

	/**
	 * Register theme-hosted fonts under the names the pickers list them by (see the adapter's font_face_css()).
	 */
	public function enqueue_font_faces(): void {
		if ( ! Settings::enabled( 'fonts' ) ) {
			return;
		}

		$adapter = $this->sync->adapter();

		if ( $adapter === null || ! method_exists( $adapter, 'font_face_css' ) ) {
			return;
		}

		$css = $adapter->font_face_css();

		if ( $css === '' ) {
			return;
		}

		wp_register_style( 'ktss-theme-fonts', false, [], KTSS_VERSION );
		wp_enqueue_style( 'ktss-theme-fonts' );
		wp_add_inline_style( 'ktss-theme-fonts', $css );
	}

	/**
	 * The Style Library and this plugin's screen preview fonts outside the editor canvas.
	 */
	public function enqueue_admin_font_faces(): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen check.

		if ( in_array( $page, [ 'kadence-blocks-style-library', 'ktss-theme-style-sync' ], true ) ) {
			$this->enqueue_font_faces();
		}
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
