<?php
/**
 * Plugin Name:       Theme Style Sync for Kadence Blocks
 * Description:       Feeds the active theme's global styles (colors, buttons, links, fonts) into the Kadence Blocks Style Library as its defaults. Reads any theme through WordPress global styles, with a richer Blocksy adapter.
 * Version:           1.3.1
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            wpblocks.dev
 * Author URI:        https://wpblocks.dev
 * License:           GPL-2.0-or-later
 * Text Domain:       kadence-theme-style-sync
 * Update URI:        https://github.com/mdrahul92/kadence-theme-style-sync
 *
 * @package KTSS
 */

defined( 'ABSPATH' ) || exit;

define( 'KTSS_VERSION', '1.3.1' );
define( 'KTSS_FILE', __FILE__ );
define( 'KTSS_PATH', plugin_dir_path( __FILE__ ) );
define( 'KTSS_URL', plugin_dir_url( __FILE__ ) );

require_once KTSS_PATH . 'includes/adapters/interface-theme-adapter.php';
require_once KTSS_PATH . 'includes/adapters/class-blocksy-adapter.php';
require_once KTSS_PATH . 'includes/adapters/class-global-styles-adapter.php';
require_once KTSS_PATH . 'includes/class-settings.php';
require_once KTSS_PATH . 'includes/class-sync.php';
require_once KTSS_PATH . 'includes/class-fonts.php';
require_once KTSS_PATH . 'includes/class-font-favorites.php';
require_once KTSS_PATH . 'includes/class-admin.php';
require_once KTSS_PATH . 'includes/class-updater.php';
require_once KTSS_PATH . 'includes/class-plugin.php';

// Kadence Blocks builds its container on plugins_loaded:1; rebind after that, before anything resolves on init.
add_action( 'plugins_loaded', [ \KTSS\Plugin::class, 'boot' ], 20 );

// Updates come from GitHub Releases, independent of Kadence Blocks being present.
( new \KTSS\Updater() )->register();
