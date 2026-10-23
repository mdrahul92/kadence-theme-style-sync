<?php
/**
 * Updates from GitHub Releases through WordPress's own update system (the plugin's `Update URI` header and
 * the `update_plugins_github.com` filter), so new versions show under Dashboard → Updates and install like
 * any other plugin, auto-updates included.
 *
 * Channels: "stable" takes the newest normal release, "beta" also takes pre-releases, so a staging site can
 * run a release before it reaches every live site.
 *
 * A private repository needs a read-only token in wp-config.php: define( 'KTSS_GITHUB_TOKEN', '…' );
 *
 * @package KTSS
 */

namespace KTSS;

defined( 'ABSPATH' ) || exit;

final class Updater {

	public const REPOSITORY = 'mdrahul92/kadence-theme-style-sync';

	private const SLUG = 'kadence-theme-style-sync';

	private const ASSET = 'kadence-theme-style-sync.zip';

	private const CACHE = 'ktss_github_release_';

	private const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	private const ERROR_TTL = HOUR_IN_SECONDS;

	private const AUTO_UPDATE_FLAG = 'ktss_auto_update_enabled_once';

	public function register(): void {
		add_filter( 'update_plugins_github.com', [ $this, 'check' ], 10, 4 );
		add_filter( 'plugins_api', [ $this, 'details' ], 20, 3 );
		add_filter( 'upgrader_pre_download', [ $this, 'pre_download' ], 10, 4 );
		add_filter( 'upgrader_source_selection', [ $this, 'fix_folder' ], 10, 4 );
		add_action( 'admin_init', [ $this, 'enable_auto_update_once' ] );
		add_action( 'admin_init', [ $this, 'maybe_force_check' ] );
	}

	/**
	 * The update channel: the KTSS_UPDATE_CHANNEL constant, else the saved setting.
	 */
	public static function channel(): string {
		if ( defined( 'KTSS_UPDATE_CHANNEL' ) && in_array( KTSS_UPDATE_CHANNEL, [ 'stable', 'beta' ], true ) ) {
			return KTSS_UPDATE_CHANNEL;
		}

		return Settings::get()['channel'];
	}

	/**
	 * The newest release on the channel, or null (none yet, or GitHub unreachable).
	 *
	 * @return array{version: string, tag: string, package: string, url: string, notes: string, published: string, prerelease: bool}|null
	 */
	public static function latest( bool $force = false ): ?array {
		$channel = self::channel();
		$key     = self::CACHE . $channel;

		if ( ! $force ) {
			$cached = get_site_transient( $key );

			if ( is_array( $cached ) ) {
				return $cached['release'] ?? null;
			}
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPOSITORY . '/releases?per_page=20',
			[
				'timeout' => 10,
				'headers' => self::headers( 'application/vnd.github+json' ),
			]
		);

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			set_site_transient( $key, [ 'release' => null ], self::ERROR_TTL );

			return null;
		}

		$releases = json_decode( wp_remote_retrieve_body( $response ), true );
		$best     = null;

		foreach ( is_array( $releases ) ? $releases : [] as $release ) {
			if ( ! is_array( $release ) || ! empty( $release['draft'] ) || ( ! empty( $release['prerelease'] ) && $channel !== 'beta' ) ) {
				continue;
			}

			$version = ltrim( (string) ( $release['tag_name'] ?? '' ), 'vV' );
			$package = self::package_url( $release );

			if ( $version === '' || $package === '' ) {
				continue;
			}

			if ( $best === null || version_compare( $version, $best['version'], '>' ) ) {
				$best = [
					'version'    => $version,
					'tag'        => (string) $release['tag_name'],
					'package'    => $package,
					'url'        => (string) ( $release['html_url'] ?? 'https://github.com/' . self::REPOSITORY ),
					'notes'      => (string) ( $release['body'] ?? '' ),
					'published'  => (string) ( $release['published_at'] ?? '' ),
					'prerelease' => ! empty( $release['prerelease'] ),
				];
			}
		}

		set_site_transient( $key, [ 'release' => $best ], self::CACHE_TTL );

		return $best;
	}

	/**
	 * update_plugins_github.com: tell WordPress about a newer release.
	 *
	 * @param array|false $update      The update so far.
	 * @param array       $plugin_data Plugin headers.
	 * @param string      $plugin_file Plugin basename.
	 *
	 * @return array|false
	 */
	public function check( $update, $plugin_data, $plugin_file ) {
		if ( $plugin_file !== plugin_basename( KTSS_FILE ) ) {
			return $update;
		}

		$release = self::latest();

		if ( $release === null ) {
			return $update;
		}

		return [
			'id'           => 'github.com/' . self::REPOSITORY,
			'slug'         => self::SLUG,
			'plugin'       => $plugin_file,
			'version'      => $release['version'],
			'new_version'  => $release['version'],
			'url'          => $release['url'],
			'package'      => $release['package'],
			'requires'     => '6.5',
			'requires_php' => '7.4',
			'autoupdate'   => true,
		];
	}

	/**
	 * plugins_api: the "View details" modal.
	 *
	 * @param mixed  $result The result so far.
	 * @param string $action The API action.
	 * @param object $args   Request args.
	 *
	 * @return mixed
	 */
	public function details( $result, $action, $args ) {
		if ( $action !== 'plugin_information' || ! is_object( $args ) || ( $args->slug ?? '' ) !== self::SLUG ) {
			return $result;
		}

		$release = self::latest();

		return (object) [
			'name'          => 'Theme Style Sync for Kadence Blocks',
			'slug'          => self::SLUG,
			'version'       => $release['version'] ?? KTSS_VERSION,
			'author'        => '<a href="https://wpblocks.dev">wpblocks.dev</a>',
			'homepage'      => 'https://github.com/' . self::REPOSITORY,
			'requires'      => '6.5',
			'requires_php'  => '7.4',
			'last_updated'  => $release['published'] ?? '',
			'download_link' => $release['package'] ?? '',
			'sections'      => [
				'description' => 'Feeds the active theme\'s global styles into the Kadence Blocks Style Library as its defaults.',
				'changelog'   => $release !== null && $release['notes'] !== '' ? wpautop( esc_html( $release['notes'] ) ) : 'See the release notes on GitHub.',
			],
		];
	}

	/**
	 * Download a private release asset. GitHub answers the API asset URL with a redirect to a short-lived
	 * signed storage URL; WordPress's HTTP client would repeat the Authorization header on that redirect,
	 * which the storage host rejects. So: ask the API with the token and without following redirects, then
	 * download the signed URL on its own.
	 *
	 * @param false|string|\WP_Error $reply      Short-circuit value.
	 * @param string                 $package    Package URL.
	 * @param object                 $upgrader   Upgrader.
	 * @param array                  $hook_extra Extra data.
	 *
	 * @return false|string|\WP_Error Path to the downloaded zip, an error, or false to let WordPress download.
	 */
	public function pre_download( $reply, $package, $upgrader, $hook_extra = [] ) {
		if ( $reply !== false || ! is_string( $package ) || strpos( $package, 'https://api.github.com/repos/' . self::REPOSITORY . '/releases/assets/' ) !== 0 ) {
			return $reply;
		}

		$response = wp_remote_get(
			$package,
			[
				'timeout'     => 30,
				'redirection' => 0,
				'headers'     => self::headers( 'application/octet-stream' ),
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( in_array( $code, [ 301, 302, 303, 307, 308 ], true ) ) {
			$location = (string) wp_remote_retrieve_header( $response, 'location' );

			if ( $location === '' ) {
				return new \WP_Error( 'ktss_download_failed', __( 'GitHub returned a redirect without a location.', 'kadence-theme-style-sync' ) );
			}

			if ( ! function_exists( 'download_url' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}

			return download_url( $location, 300 );
		}

		if ( $code === 200 ) {
			$file = wp_tempnam( self::ASSET );

			if ( ! $file || file_put_contents( $file, wp_remote_retrieve_body( $response ) ) === false ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				return new \WP_Error( 'ktss_download_failed', __( 'Could not save the update package.', 'kadence-theme-style-sync' ) );
			}

			return $file;
		}

		return new \WP_Error(
			'ktss_download_failed',
			sprintf(
				/* translators: %d: HTTP status */
				__( 'GitHub refused the update download (HTTP %d). Check KTSS_GITHUB_TOKEN in wp-config.php.', 'kadence-theme-style-sync' ),
				$code
			)
		);
	}

	/**
	 * Keep the plugin in its own folder whatever the zip's top folder is called.
	 *
	 * @param string|\WP_Error $source        Extracted source.
	 * @param string           $remote_source Remote source dir.
	 * @param object           $upgrader      Upgrader.
	 * @param array            $hook_extra    Extra data.
	 *
	 * @return string|\WP_Error
	 */
	public function fix_folder( $source, $remote_source, $upgrader, $hook_extra = [] ) {
		global $wp_filesystem;

		if ( is_wp_error( $source ) || ( $hook_extra['plugin'] ?? '' ) !== plugin_basename( KTSS_FILE ) || ! $wp_filesystem ) {
			return $source;
		}

		$wanted = trailingslashit( $remote_source ) . self::SLUG . '/';

		if ( trailingslashit( $source ) === $wanted ) {
			return $source;
		}

		return $wp_filesystem->move( $source, $wanted ) ? $wanted : $source;
	}

	/**
	 * Turn on WordPress auto-updates for this plugin once per site. A site that later switches it off keeps
	 * it off.
	 */
	public function enable_auto_update_once(): void {
		if ( get_site_option( self::AUTO_UPDATE_FLAG ) || ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		$plugins = (array) get_site_option( 'auto_update_plugins', [] );
		$file    = plugin_basename( KTSS_FILE );

		if ( ! in_array( $file, $plugins, true ) ) {
			$plugins[] = $file;
			update_site_option( 'auto_update_plugins', $plugins );
		}

		update_site_option( self::AUTO_UPDATE_FLAG, 1 );
	}

	/**
	 * "Check for updates now" link on the settings page: ?ktss-check-updates=1&_wpnonce=…
	 */
	public function maybe_force_check(): void {
		if ( empty( $_GET['ktss-check-updates'] ) || ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		check_admin_referer( 'ktss-check-updates' );

		self::flush();
		self::latest( true );
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();

		wp_safe_redirect( remove_query_arg( [ 'ktss-check-updates', '_wpnonce' ] ) );
		exit;
	}

	public static function flush(): void {
		delete_site_transient( self::CACHE . 'stable' );
		delete_site_transient( self::CACHE . 'beta' );
	}

	/**
	 * The zip to install: the release asset, through the API for a private repository (needs the token),
	 * its public download URL otherwise.
	 *
	 * @param array<string, mixed> $release A GitHub release.
	 */
	private static function package_url( array $release ): string {
		foreach ( is_array( $release['assets'] ?? null ) ? $release['assets'] : [] as $asset ) {
			if ( ! is_array( $asset ) || ( $asset['name'] ?? '' ) !== self::ASSET ) {
				continue;
			}

			if ( self::token() !== '' ) {
				return (string) ( $asset['url'] ?? '' );
			}

			return (string) ( $asset['browser_download_url'] ?? '' );
		}

		return '';
	}

	/**
	 * @return array<string, string>
	 */
	private static function headers( string $accept ): array {
		$headers = [
			'Accept'     => $accept,
			'User-Agent' => 'kadence-theme-style-sync/' . KTSS_VERSION,
		];

		if ( self::token() !== '' ) {
			$headers['Authorization'] = 'Bearer ' . self::token();
		}

		return $headers;
	}

	public static function has_token(): bool {
		return self::token() !== '';
	}

	private static function token(): string {
		return defined( 'KTSS_GITHUB_TOKEN' ) && is_string( KTSS_GITHUB_TOKEN ) ? KTSS_GITHUB_TOKEN : '';
	}
}
