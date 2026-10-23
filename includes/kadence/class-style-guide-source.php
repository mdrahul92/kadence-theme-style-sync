<?php
/**
 * Replaces Kadence Blocks' theme Style Guide source.
 *
 * Kadence Blocks 3.8 seeds the Style Library's palette (palette1…9) from the active theme through this
 * contract, but only ships a reader for the Kadence theme. This one answers from the active theme adapter
 * and hands everything else back to Kadence's own reader, so a site on the Kadence theme behaves exactly as
 * before.
 *
 * Loaded only when Kadence Blocks provides the contract.
 *
 * @package KTSS
 */

namespace KTSS\Kadence_Bridge;

use KadenceWP\KadenceBlocks\Design_Tokens\Theme_Style_Guide\Contracts\Style_Guide_Source as Source_Contract;
use KTSS\Sync;

defined( 'ABSPATH' ) || exit;

final class Style_Guide_Source implements Source_Contract {

	private Sync $sync;

	private Source_Contract $fallback;

	public function __construct( Sync $sync, Source_Contract $fallback ) {
		$this->sync     = $sync;
		$this->fallback = $fallback;
	}

	public function snapshot(): ?array {
		$snapshot = $this->sync->snapshot();

		return $snapshot ?? $this->fallback->snapshot();
	}
}
