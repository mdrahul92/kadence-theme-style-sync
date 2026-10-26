<?php
/**
 * The plugin's own settings: which parts sync, and the theme color => Kadence palette slot map.
 *
 * @package KTSS
 */

namespace KTSS;

use KTSS\Adapters\Theme_Adapter;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION = 'ktss_settings';

	/**
	 * The parts that can be switched off, with their labels.
	 */
	public const PARTS = [
		'palette'           => 'Global palette → Kadence palette 1–9',
		'extra_colors'      => 'Theme colors without a Kadence slot → extra Style Library colors',
		'text_links'        => 'Text, link and border colors',
		'buttons'           => 'Button colors, border, radius and padding',
		'button_typography' => 'Button typography',
		'fonts'             => 'Fonts the theme hosts itself in Kadence font pickers',
		'favorite_fonts'    => 'Theme Google fonts → Style Library favorite fonts',
		'local_google_fonts' => 'Keep Kadence “Load Google Fonts Locally” on',
	];

	/**
	 * The Kadence palette slots.
	 */
	public const SLOTS = [ 'palette1', 'palette2', 'palette3', 'palette4', 'palette5', 'palette6', 'palette7', 'palette8', 'palette9' ];

	/**
	 * The stored settings merged over the defaults.
	 *
	 * @return array{parts: array<string, bool>, maps: array<string, array<string, string>>}
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION, [] );
		$stored = is_array( $stored ) ? $stored : [];
		$parts  = [];

		foreach ( array_keys( self::PARTS ) as $part ) {
			$parts[ $part ] = isset( $stored['parts'][ $part ] ) ? (bool) $stored['parts'][ $part ] : true;
		}

		return [
			'parts' => $parts,
			'maps'  => is_array( $stored['maps'] ?? null ) ? $stored['maps'] : [],
		];
	}

	public static function enabled( string $part ): bool {
		return self::get()['parts'][ $part ] ?? false;
	}

	/**
	 * Kadence slot => theme color key for an adapter: the saved map where it names a color the theme still
	 * has, the adapter's recommended map everywhere else. An empty string means "leave this slot alone".
	 *
	 * @param Theme_Adapter $adapter The active adapter.
	 *
	 * @return array<string, string>
	 */
	public static function slot_map( Theme_Adapter $adapter ): array {
		$saved   = self::get()['maps'][ $adapter->id() ] ?? [];
		$default = $adapter->default_slot_map();
		$palette = $adapter->palette();
		$map     = [];

		foreach ( self::SLOTS as $slot ) {
			$key = is_array( $saved ) && array_key_exists( $slot, $saved ) ? (string) $saved[ $slot ] : ( $default[ $slot ] ?? '' );

			if ( $key !== '' && ! isset( $palette[ $key ] ) ) {
				$key = isset( $palette[ $default[ $slot ] ?? '' ] ) ? $default[ $slot ] : '';
			}

			$map[ $slot ] = $key;
		}

		return $map;
	}

	/**
	 * Sanitize callback for register_setting().
	 *
	 * @param mixed $input The submitted value.
	 *
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : [];
		$parts = [];

		foreach ( array_keys( self::PARTS ) as $part ) {
			$parts[ $part ] = ! empty( $input['parts'][ $part ] );
		}

		$maps = [];

		foreach ( is_array( $input['maps'] ?? null ) ? $input['maps'] : [] as $adapter_id => $map ) {
			$adapter_id = sanitize_key( (string) $adapter_id );

			if ( $adapter_id === '' || ! is_array( $map ) ) {
				continue;
			}

			foreach ( self::SLOTS as $slot ) {
				$key = isset( $map[ $slot ] ) ? (string) $map[ $slot ] : '';

				$maps[ $adapter_id ][ $slot ] = preg_match( '/^[a-z0-9_-]*$/i', $key ) ? $key : '';
			}
		}

		return [
			'parts' => $parts,
			'maps'  => $maps,
		];
	}
}
