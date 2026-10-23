<?php
/**
 * Decorates Kadence Blocks' Style Library baseline with the theme values Kadence's own theme layer does
 * not cover: extra theme colors (as new color tokens plus a swatch group), text / link / border colors, the
 * button colors, border, radius, padding and typography.
 *
 * Same contract and rules as Kadence's own decorators: only the BASELINE changes, so stored Style Library
 * edits still win and a reset returns to the theme's value. has() adds exactly the extra color tokens this
 * plugin registers, so Kadence's baseline guard sees an entry for every declared token.
 *
 * Loaded only when Kadence Blocks provides the contract.
 *
 * @package KTSS
 */

namespace KTSS\Kadence_Bridge;

use KadenceWP\KadenceBlocks\Design_Tokens\Registry\Contracts\Baseline_Document as Baseline_Contract;
use KadenceWP\KadenceBlocks\Design_Tokens\Schema\Vocabulary\Extensions;
use KadenceWP\KadenceBlocks\Design_Tokens\Schema\Vocabulary\Sentinels;
use KTSS\Sync;

defined( 'ABSPATH' ) || exit;

final class Baseline_Document implements Baseline_Contract {

	/**
	 * Id of the swatch group holding the extra theme colors.
	 */
	public const GROUP_ID = 'theme-colors';

	private Baseline_Contract $inner;

	private Sync $sync;

	/**
	 * @var array<string, mixed>|null
	 */
	private ?array $document = null;

	private string $fingerprint = '';

	public function __construct( Baseline_Contract $inner, Sync $sync ) {
		$this->inner = $inner;
		$this->sync  = $sync;
	}

	public function has( string $id ): bool {
		return $this->inner->has( $id ) || isset( $this->sync->extra_tokens()[ $id ] );
	}

	public function document(): array {
		$fingerprint = $this->sync->fingerprint();

		if ( $this->document !== null && $this->fingerprint === $fingerprint ) {
			return $this->document;
		}

		$document = $this->inner->document();

		if ( $document === [] || $fingerprint === '' ) {
			return $document;
		}

		$document = $this->add_extra_tokens( $document );
		$document = $this->set_leaf_values( $document );
		$document = $this->update_swatches( $document );
		$document = $this->set_preset_values( $document );

		// Kadence's inner decorators depend on the token registry, which is populated on init:0; do not pin
		// a document built before then for the rest of the request.
		if ( did_action( 'init' ) ) {
			$this->document    = $document;
			$this->fingerprint = $fingerprint;
		}

		return $document;
	}

	/**
	 * Drop the memo (after a theme or settings write inside this request).
	 */
	public function flush(): void {
		$this->document = null;
	}

	/**
	 * @param array<string, mixed> $document
	 *
	 * @return array<string, mixed>
	 */
	private function add_extra_tokens( array $document ): array {
		$value_key = Sentinels::get_value_key();

		foreach ( $this->sync->extra_tokens() as $id => $token ) {
			$document = self::set_path(
				$document,
				explode( '.', $id ),
				[
					'$type'     => 'color',
					$value_key  => $token['value'],
				]
			);
		}

		return $document;
	}

	/**
	 * Re-value existing token leaves. A value for an id with no $value leaf is skipped, never added.
	 *
	 * @param array<string, mixed> $document
	 *
	 * @return array<string, mixed>
	 */
	private function set_leaf_values( array $document ): array {
		$value_key = Sentinels::get_value_key();

		foreach ( $this->sync->leaf_values() as $id => $value ) {
			$path = explode( '.', $id );
			$leaf = self::get_path( $document, $path );

			if ( ! is_array( $leaf ) || ! array_key_exists( $value_key, $leaf ) ) {
				continue;
			}

			$leaf[ $value_key ] = $value;
			$document           = self::set_path( $document, $path, $leaf );
		}

		return $document;
	}

	/**
	 * Show the re-valued colors on the default palette's swatches and append the extra theme colors as their
	 * own swatch group.
	 *
	 * @param array<string, mixed> $document
	 *
	 * @return array<string, mixed>
	 */
	private function update_swatches( array $document ): array {
		$section_path = Extensions::get_color_palettes_path();
		$section      = self::get_path( $document, $section_path );

		if ( ! is_array( $section ) ) {
			return $document;
		}

		$default = $section[ Extensions::get_default_key() ] ?? null;

		if ( ! is_string( $default ) || ! is_array( $section[ $default ] ?? null ) ) {
			return $document;
		}

		$groups_key   = Extensions::get_groups_key();
		$swatches_key = Extensions::get_swatches_key();
		$token_key    = Extensions::get_swatch_token_key();
		$value_key    = Sentinels::get_value_key();
		$groups       = is_array( $section[ $default ][ $groups_key ] ?? null ) ? $section[ $default ][ $groups_key ] : [];
		$literals     = $this->sync->swatch_literals();

		foreach ( $groups as $group_index => $group ) {
			if ( ! is_array( $group ) || ! is_array( $group[ $swatches_key ] ?? null ) ) {
				continue;
			}

			foreach ( $group[ $swatches_key ] as $swatch_index => $swatch ) {
				$token = is_array( $swatch ) ? ( $swatch[ $token_key ] ?? null ) : null;

				if ( is_string( $token ) && isset( $literals[ $token ] ) ) {
					$groups[ $group_index ][ $swatches_key ][ $swatch_index ][ $value_key ] = $literals[ $token ];
				}
			}
		}

		$extras = $this->sync->extra_tokens();

		if ( $extras !== [] ) {
			$adapter  = $this->sync->adapter();
			$swatches = [];

			foreach ( $extras as $id => $token ) {
				$swatches[] = [
					$token_key => $id,
					'label'    => $token['label'],
					$value_key => $token['value'],
				];
			}

			$groups = array_values(
				array_filter(
					$groups,
					static function ( $group ): bool {
						return ! is_array( $group ) || ( $group['id'] ?? '' ) !== self::GROUP_ID;
					}
				)
			);

			$groups[] = [
				'id'          => self::GROUP_ID,
				'label'       => sprintf( '%s Colors', $adapter !== null ? $adapter->label() : 'Theme' ),
				$swatches_key => $swatches,
			];
		}

		$section[ $default ][ $groups_key ] = $groups;

		return self::set_path( $document, $section_path, $section );
	}

	/**
	 * Re-value properties of shipped block presets (only properties the preset already declares).
	 *
	 * @param array<string, mixed> $document
	 *
	 * @return array<string, mixed>
	 */
	private function set_preset_values( array $document ): array {
		$tokens_key = Extensions::get_tokens_key();

		foreach ( $this->sync->preset_values() as $block => $presets ) {
			foreach ( $presets as $preset => $properties ) {
				$path   = array_merge( Extensions::get_presets_path(), [ $block, $preset, $tokens_key ] );
				$tokens = self::get_path( $document, $path );

				if ( ! is_array( $tokens ) ) {
					continue;
				}

				foreach ( $properties as $property => $value ) {
					if ( array_key_exists( $property, $tokens ) ) {
						$tokens[ $property ] = $value;
					}
				}

				$document = self::set_path( $document, $path, $tokens );
			}
		}

		return $document;
	}

	/**
	 * @param array<string, mixed> $document
	 * @param string[]             $path
	 *
	 * @return mixed
	 */
	private static function get_path( array $document, array $path ) {
		$node = $document;

		foreach ( $path as $key ) {
			if ( ! is_array( $node ) || ! array_key_exists( $key, $node ) ) {
				return null;
			}

			$node = $node[ $key ];
		}

		return $node;
	}

	/**
	 * @param array<string, mixed> $document
	 * @param string[]             $path
	 * @param mixed                $value
	 *
	 * @return array<string, mixed>
	 */
	private static function set_path( array $document, array $path, $value ): array {
		$cursor = &$document;

		foreach ( $path as $key ) {
			if ( ! isset( $cursor[ $key ] ) || ! is_array( $cursor[ $key ] ) ) {
				$cursor[ $key ] = [];
			}

			$cursor = &$cursor[ $key ];
		}

		$cursor = $value;
		unset( $cursor );

		return $document;
	}
}
