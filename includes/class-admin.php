<?php
/**
 * Settings screen, built on the Kadence Style Library's own shell and stylesheet so the two screens look
 * and behave as one: same header, side navigation, screen headers, list rows, swatch cards and tokens.
 *
 * @package KTSS
 */

namespace KTSS;

defined( 'ABSPATH' ) || exit;

final class Admin {

	private const PAGE = 'ktss-theme-style-sync';

	private const STYLE_LIBRARY_PAGE = 'kadence-blocks-style-library';

	/**
	 * Kadence's body class: scopes its design tokens and full-height layout to the screen.
	 */
	private const KADENCE_BODY_CLASS = 'kadence-blocks-style-library-page';

	private const PREFIX = 'kadence-blocks-style-library__';

	private const SLOT_LABELS = [
		'palette1' => [ 'Palette 1', 'Brand primary' ],
		'palette2' => [ 'Palette 2', 'Brand secondary' ],
		'palette3' => [ 'Palette 3', 'Strongest text, headings' ],
		'palette4' => [ 'Palette 4', 'Strong text' ],
		'palette5' => [ 'Palette 5', 'Medium text' ],
		'palette6' => [ 'Palette 6', 'Muted text' ],
		'palette7' => [ 'Palette 7', 'Subtle background' ],
		'palette8' => [ 'Palette 8', 'Lighter background' ],
		'palette9' => [ 'Palette 9', 'Base background' ],
	];

	private const PART_LABELS = [
		'palette'           => [ 'Global palette', 'Theme palette fills Kadence palette 1–9, using the mapping on the Palette Mapping screen.' ],
		'extra_colors'      => [ 'Extra colors', 'Theme colors no Kadence slot uses (including colors you add beyond 8) become extra Style Library colors.' ],
		'text_links'        => [ 'Text, links and borders', 'Body text, link and border colors.' ],
		'buttons'           => [ 'Buttons', 'Button colors, border, radius and padding, plus the theme button preset.' ],
		'button_typography' => [ 'Button typography', 'Button font size, weight, style, line height, letter spacing and transform.' ],
		'fonts'             => [ 'Theme-hosted fonts', 'Fonts the theme or WordPress hosts (theme fonts, Font Library, uploaded or locally hosted fonts) in Kadence font pickers.' ],
	];

	private const TOKEN_LABELS = [
		'semantic.color.text'               => 'Text',
		'semantic.color.link'               => 'Link',
		'primitive.color.neutral.200'       => 'Border',
		'primitive.color.brand.button'      => 'Button background',
		'primitive.color.brand.button-hover' => 'Button background, hover',
		'semantic.color.button-bg'          => 'Button background (block default)',
		'semantic.color.button-bg-hover'    => 'Button background hover (block default)',
		'semantic.color.button-text'        => 'Button text',
		'semantic.color.button-text-hover'  => 'Button text, hover',
		'semantic.color.button-border'      => 'Button border color',
		'semantic.radius.control'           => 'Button radius',
		'semantic.border-width.button'      => 'Button border width',
		'semantic.border-style.button'      => 'Button border style',
		'semantic.font-size.control'        => 'Button font size',
		'semantic.font-weight.control'      => 'Button font weight',
		'semantic.font-style.control'       => 'Button font style',
		'semantic.line-height.control'      => 'Button line height',
		'semantic.letter-spacing.control'   => 'Button letter spacing',
		'semantic.text-transform.control'   => 'Button text transform',
	];

	private Sync $sync;

	private string $hook = '';

	/**
	 * Per-request memo of the active library's resolved tokens.
	 *
	 * @var object|null|false
	 */
	private $resolved = null;

	public function __construct( Sync $sync ) {
		$this->sync = $sync;
	}

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'menu' ], 100 );
		add_action( 'admin_init', [ $this, 'register_setting' ] );
		add_filter( 'plugin_action_links_' . plugin_basename( KTSS_FILE ), [ $this, 'action_links' ] );
	}

	public function register_setting(): void {
		register_setting(
			self::PAGE,
			Settings::OPTION,
			[
				'type'              => 'array',
				'sanitize_callback' => [ Settings::class, 'sanitize' ],
				'default'           => [],
			]
		);
	}

	public function menu(): void {
		global $admin_page_hooks;

		$parent = isset( $admin_page_hooks['kadence-blocks'] ) ? 'kadence-blocks' : 'themes.php';
		$hook   = add_submenu_page(
			$parent,
			__( 'Theme Style Sync', 'kadence-theme-style-sync' ),
			__( 'Theme Style Sync', 'kadence-theme-style-sync' ),
			'edit_theme_options',
			self::PAGE,
			[ $this, 'render' ],
			3
		);

		if ( ! is_string( $hook ) ) {
			return;
		}

		$this->hook = $hook;

		add_action( 'admin_print_styles-' . $hook, [ $this, 'enqueue' ] );
		add_action( 'load-' . $hook, [ $this, 'load_screen' ] );
	}

	/**
	 * Same screen treatment as the Style Library: its body class (tokens + full-height layout) and no
	 * admin notices inside the app frame.
	 */
	public function load_screen(): void {
		if ( $this->kadence_css_url() !== '' ) {
			add_filter(
				'admin_body_class',
				static function ( string $classes ): string {
					return $classes . ' ' . self::KADENCE_BODY_CLASS . ' ktss-page';
				}
			);
		}

		foreach ( [ 'admin_notices', 'all_admin_notices', 'user_admin_notices', 'network_admin_notices' ] as $hook ) {
			remove_all_actions( $hook );
		}
	}

	public function enqueue(): void {
		$deps = [ 'wp-components', 'dashicons' ];
		$url  = $this->kadence_css_url();

		if ( $url !== '' ) {
			wp_enqueue_style( 'ktss-kadence-style-library', $url, $deps, defined( 'KADENCE_BLOCKS_VERSION' ) ? KADENCE_BLOCKS_VERSION : KTSS_VERSION );
			$deps[] = 'ktss-kadence-style-library';
		}

		wp_enqueue_style( 'ktss-admin', KTSS_URL . 'assets/admin.css', $deps, KTSS_VERSION );
		wp_enqueue_script( 'ktss-admin', KTSS_URL . 'assets/admin.js', [], KTSS_VERSION, true );
	}

	/**
	 * @param string[] $links
	 *
	 * @return string[]
	 */
	public function action_links( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( $this->page_url() ) . '">' . esc_html__( 'Settings', 'kadence-theme-style-sync' ) . '</a>' );

		return $links;
	}

	public function render(): void {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}

		$adapter = $this->sync->adapter();
		$screens = $this->screens( $adapter );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen state.
		$current = sanitize_key( wp_unslash( $_GET['ktss-screen'] ?? '' ) );
		$current = isset( $screens[ $current ] ) ? $current : array_key_first( $screens );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flag set by options.php.
		$saved = ! empty( $_GET['settings-updated'] );
		$p     = self::PREFIX;
		?>
		<div class="wrap kadence_blocks_dash kadence-blocks-style-library-wrap">
			<div class="kadence-blocks-style-library-root ktss-root">
				<form class="<?php echo esc_attr( $p ); ?>shell" method="post" action="options.php" data-ktss-form>
					<?php settings_fields( self::PAGE ); ?>
					<header class="<?php echo esc_attr( $p ); ?>header">
						<div class="<?php echo esc_attr( $p ); ?>header-bar">
							<?php echo self::logo(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
							<h1 class="<?php echo esc_attr( $p ); ?>title"><?php esc_html_e( 'Theme Style Sync', 'kadence-theme-style-sync' ); ?></h1>
							<span class="<?php echo esc_attr( $p ); ?>slash-separator <?php echo esc_attr( $p ); ?>header-separator">/</span>
							<span class="ktss-header-theme"><?php echo esc_html( $adapter !== null ? $adapter->label() : wp_get_theme()->get( 'Name' ) ); ?></span>
							<div class="<?php echo esc_attr( $p ); ?>header-actions ktss-header-actions">
								<?php if ( $saved ) : ?>
									<span class="<?php echo esc_attr( $p ); ?>inheritance-pill ktss-pill ktss-pill--success" data-ktss-saved><?php esc_html_e( 'Settings saved', 'kadence-theme-style-sync' ); ?></span>
								<?php endif; ?>
								<?php if ( wp_is_block_theme() ) : ?>
									<a class="components-button is-secondary" href="<?php echo esc_url( admin_url( 'site-editor.php?p=%2Fstyles' ) ); ?>"><?php esc_html_e( 'Open Site Editor', 'kadence-theme-style-sync' ); ?></a>
								<?php else : ?>
									<a class="components-button is-secondary" href="<?php echo esc_url( admin_url( 'customize.php' ) ); ?>"><?php esc_html_e( 'Open Customizer', 'kadence-theme-style-sync' ); ?></a>
								<?php endif; ?>
								<a class="components-button is-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::STYLE_LIBRARY_PAGE ) ); ?>"><?php esc_html_e( 'Open Style Library', 'kadence-theme-style-sync' ); ?></a>
							</div>
						</div>
					</header>

					<div class="<?php echo esc_attr( $p ); ?>body">
						<nav class="<?php echo esc_attr( $p ); ?>sidebar">
							<div class="<?php echo esc_attr( $p ); ?>nav">
								<?php $this->render_nav( $screens, $current ); ?>
							</div>
						</nav>

						<main class="<?php echo esc_attr( $p ); ?>content">
							<?php foreach ( $screens as $id => $screen ) : ?>
								<section class="ktss-screen" data-ktss-screen="<?php echo esc_attr( $id ); ?>" <?php echo $id === $current ? '' : 'hidden'; ?>>
									<div class="<?php echo esc_attr( $p ); ?>screen-header-slot">
										<div class="<?php echo esc_attr( $p ); ?>screen-header">
											<div class="<?php echo esc_attr( $p ); ?>screen-header-row">
												<div class="<?php echo esc_attr( $p ); ?>screen-header-lead">
													<h2 class="<?php echo esc_attr( $p ); ?>screen-header-title"><?php echo esc_html( $screen['title'] ); ?></h2>
													<?php if ( ! empty( $screen['subtitle'] ) ) : ?>
														<span class="<?php echo esc_attr( $p ); ?>slash-separator <?php echo esc_attr( $p ); ?>screen-header-separator">/</span>
														<span class="ktss-screen-subtitle"><?php echo esc_html( $screen['subtitle'] ); ?></span>
													<?php endif; ?>
												</div>
												<div class="<?php echo esc_attr( $p ); ?>screen-header-trail">
													<span class="<?php echo esc_attr( $p ); ?>screen-header-primary-action">
														<?php if ( ! empty( $screen['form'] ) ) : ?>
															<?php if ( $id === 'mapping' && $adapter !== null ) : ?>
																<button type="button" class="components-button is-secondary" data-ktss-preset="<?php echo esc_attr( (string) wp_json_encode( $adapter->default_slot_map() ) ); ?>"><?php esc_html_e( 'By role', 'kadence-theme-style-sync' ); ?></button>
																<button type="button" class="components-button is-secondary" data-ktss-preset="<?php echo esc_attr( (string) wp_json_encode( $adapter->direct_slot_map() ) ); ?>"><?php esc_html_e( 'Color N → Palette N', 'kadence-theme-style-sync' ); ?></button>
															<?php endif; ?>
															<button type="submit" class="components-button is-primary"><?php esc_html_e( 'Save', 'kadence-theme-style-sync' ); ?></button>
														<?php endif; ?>
													</span>
												</div>
											</div>
										</div>
									</div>
									<div class="<?php echo esc_attr( $p ); ?>screen-body">
										<div>
											<?php call_user_func( $screen['render'], $adapter ); ?>
										</div>
									</div>
								</section>
							<?php endforeach; ?>
						</main>
					</div>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * The screens, in nav order, grouped into nav sections.
	 *
	 * @return array<string, array{section: string, title: string, subtitle?: string, icon: string, form?: bool, count?: int, render: callable}>
	 */
	private function screens( ?Adapters\Theme_Adapter $adapter ): array {
		$screens = [
			'overview' => [
				'section' => __( 'Sync', 'kadence-theme-style-sync' ),
				'title'   => __( 'Overview', 'kadence-theme-style-sync' ),
				'icon'    => 'overview',
				'render'  => [ $this, 'render_overview' ],
			],
		];

		if ( $adapter === null ) {
			return $screens;
		}

		$extras = $this->sync->extra_tokens();
		$fonts  = Settings::enabled( 'fonts' ) ? $adapter->fonts() : [];

		return $screens + [
			'mapping' => [
				'section'  => __( 'Sync', 'kadence-theme-style-sync' ),
				'title'    => __( 'Palette Mapping', 'kadence-theme-style-sync' ),
				'subtitle' => sprintf( '%s → Kadence', $adapter->label() ),
				'icon'     => 'palette',
				'form'     => true,
				'render'   => [ $this, 'render_mapping' ],
			],
			'parts'   => [
				'section' => __( 'Sync', 'kadence-theme-style-sync' ),
				'title'   => __( 'What Syncs', 'kadence-theme-style-sync' ),
				'icon'    => 'toggles',
				'form'    => true,
				'render'  => [ $this, 'render_parts' ],
			],
			'colors'  => [
				'section' => __( 'From the theme', 'kadence-theme-style-sync' ),
				'title'   => __( 'Extra Colors', 'kadence-theme-style-sync' ),
				'icon'    => 'swatch',
				'count'   => count( $extras ),
				'render'  => [ $this, 'render_colors' ],
			],
			'tokens'  => [
				'section' => __( 'From the theme', 'kadence-theme-style-sync' ),
				'title'   => __( 'Text, Links & Buttons', 'kadence-theme-style-sync' ),
				'icon'    => 'button',
				'count'   => count( $this->sync->leaf_values() ),
				'render'  => [ $this, 'render_tokens' ],
			],
			'fonts'   => [
				'section' => __( 'From the theme', 'kadence-theme-style-sync' ),
				'title'   => __( 'Fonts', 'kadence-theme-style-sync' ),
				'icon'    => 'type',
				'count'   => count( $fonts ),
				'render'  => [ $this, 'render_fonts' ],
			],
		];
	}

	/**
	 * @param array<string, array<string, mixed>> $screens
	 */
	private function render_nav( array $screens, string $current ): void {
		$p        = self::PREFIX;
		$sections = [];

		foreach ( $screens as $id => $screen ) {
			$sections[ $screen['section'] ][ $id ] = $screen;
		}

		foreach ( $sections as $label => $items ) :
			?>
			<div class="<?php echo esc_attr( $p ); ?>nav-section">
				<h2 class="<?php echo esc_attr( $p ); ?>nav-section-label"><?php echo esc_html( $label ); ?></h2>
				<ul class="<?php echo esc_attr( $p ); ?>nav-list">
					<?php foreach ( $items as $id => $screen ) : ?>
						<li class="<?php echo esc_attr( $p ); ?>nav-item">
							<button type="button" data-ktss-nav="<?php echo esc_attr( $id ); ?>" class="<?php echo esc_attr( $p ); ?>nav-item-button<?php echo $id === $current ? ' ' . esc_attr( $p ) . 'nav-item-button--active' : ''; ?>">
								<?php echo self::icon( $screen['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
								<span class="ktss-nav-label"><?php echo esc_html( $screen['title'] ); ?></span>
								<?php if ( isset( $screen['count'] ) ) : ?>
									<span class="ktss-nav-count"><?php echo esc_html( (string) $screen['count'] ); ?></span>
								<?php endif; ?>
							</button>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php
		endforeach;
	}

	public function render_overview( ?Adapters\Theme_Adapter $adapter ): void {
		$supported = Plugin::kadence_supported();
		$rows      = [
			[ __( 'Active theme', 'kadence-theme-style-sync' ), wp_get_theme()->get( 'Name' ), null ],
			[ __( 'Theme adapter', 'kadence-theme-style-sync' ), $adapter !== null ? $adapter->label() : __( 'None — this theme is not supported yet', 'kadence-theme-style-sync' ), $adapter !== null ],
			[ __( 'Kadence Blocks', 'kadence-theme-style-sync' ), defined( 'KADENCE_BLOCKS_VERSION' ) ? KADENCE_BLOCKS_VERSION : '—', $supported ],
			[ __( 'Style Library link', 'kadence-theme-style-sync' ), Plugin::baseline_active() ? __( 'Connected', 'kadence-theme-style-sync' ) : __( 'Not connected', 'kadence-theme-style-sync' ), Plugin::baseline_active() ],
		];

		$this->section_heading( __( 'Status', 'kadence-theme-style-sync' ) );
		?>
		<ul class="<?php echo esc_attr( self::PREFIX ); ?>row-list ktss-list">
			<?php foreach ( $rows as [ $label, $value, $ok ] ) : ?>
				<li class="<?php echo esc_attr( self::PREFIX ); ?>list-row">
					<div class="<?php echo esc_attr( self::PREFIX ); ?>list-row-main ktss-row">
						<span class="<?php echo esc_attr( self::PREFIX ); ?>list-row-label"><?php echo esc_html( $label ); ?></span>
						<span class="ktss-row-text"><?php echo esc_html( (string) $value ); ?></span>
						<?php if ( $ok !== null ) : ?>
							<?php $this->pill( $ok ? __( 'OK', 'kadence-theme-style-sync' ) : __( 'Check', 'kadence-theme-style-sync' ), $ok ? 'success' : 'warning' ); ?>
						<?php endif; ?>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>

		<?php $this->render_updates(); ?>

		<?php $this->section_heading( __( 'How it works', 'kadence-theme-style-sync' ) ); ?>
		<div class="ktss-prose">
			<p><?php echo esc_html( sprintf( /* translators: 1: theme, 2: where its styles are edited */ __( 'The Style Library starts from your %1$s %2$s. Change them there and the Style Library, the editor and the site follow on the next page load.', 'kadence-theme-style-sync' ), $adapter !== null ? $adapter->label() : __( 'theme', 'kadence-theme-style-sync' ), wp_is_block_theme() ? __( 'Site Editor styles', 'kadence-theme-style-sync' ) : __( 'Customizer settings', 'kadence-theme-style-sync' ) ) ); ?></p>
			<p><?php esc_html_e( 'Anything you save in the Style Library overrides the theme for Kadence blocks. Reset it there to follow the theme again.', 'kadence-theme-style-sync' ); ?></p>
		</div>
		<?php
	}

	private function render_updates(): void {
		$p        = self::PREFIX;
		$latest   = Updater::latest();
		$auto     = in_array( plugin_basename( KTSS_FILE ), (array) get_site_option( 'auto_update_plugins', [] ), true );
		$newer    = $latest !== null && version_compare( $latest['version'], KTSS_VERSION, '>' );
		$check    = wp_nonce_url( add_query_arg( 'ktss-check-updates', '1' ), 'ktss-check-updates' );

		$this->section_heading( __( 'Updates', 'kadence-theme-style-sync' ), sprintf( /* translators: %s: repository */ __( 'New versions come from GitHub Releases (%s) and install like any other plugin update.', 'kadence-theme-style-sync' ), Updater::REPOSITORY ) );
		?>
		<ul class="<?php echo esc_attr( $p ); ?>row-list ktss-list">
			<li class="<?php echo esc_attr( $p ); ?>list-row">
				<div class="<?php echo esc_attr( $p ); ?>list-row-main ktss-row">
					<span class="<?php echo esc_attr( $p ); ?>list-row-label"><?php esc_html_e( 'Installed version', 'kadence-theme-style-sync' ); ?></span>
					<span class="ktss-row-text"><?php echo esc_html( KTSS_VERSION ); ?></span>
					<?php if ( $newer ) : ?>
						<?php $this->pill( sprintf( /* translators: %s: version */ __( '%s available', 'kadence-theme-style-sync' ), $latest['version'] ), 'warning' ); ?>
						<a class="components-button is-secondary" href="<?php echo esc_url( self_admin_url( 'update-core.php' ) ); ?>"><?php esc_html_e( 'Update now', 'kadence-theme-style-sync' ); ?></a>
					<?php elseif ( $latest !== null ) : ?>
						<?php $this->pill( __( 'Up to date', 'kadence-theme-style-sync' ), 'success' ); ?>
					<?php else : ?>
						<?php $this->pill( __( 'Could not reach GitHub', 'kadence-theme-style-sync' ), 'inherited', __( 'Checked again automatically within the hour.', 'kadence-theme-style-sync' ) ); ?>
					<?php endif; ?>
					<a class="components-button is-secondary" href="<?php echo esc_url( $check ); ?>"><?php esc_html_e( 'Check now', 'kadence-theme-style-sync' ); ?></a>
				</div>
			</li>
			<li class="<?php echo esc_attr( $p ); ?>list-row">
				<div class="<?php echo esc_attr( $p ); ?>list-row-main ktss-row">
					<span class="<?php echo esc_attr( $p ); ?>list-row-label"><?php esc_html_e( 'Auto-updates', 'kadence-theme-style-sync' ); ?></span>
					<span class="ktss-row-text"><?php echo $auto ? esc_html__( 'On — new versions install automatically', 'kadence-theme-style-sync' ) : esc_html__( 'Off — update manually', 'kadence-theme-style-sync' ); ?></span>
					<a class="components-button is-secondary" href="<?php echo esc_url( self_admin_url( 'plugins.php' ) ); ?>"><?php esc_html_e( 'Change', 'kadence-theme-style-sync' ); ?></a>
				</div>
			</li>
		</ul>
		<?php
	}

	public function render_mapping( ?Adapters\Theme_Adapter $adapter ): void {
		if ( $adapter === null ) {
			return;
		}

		$palette = $adapter->palette();
		$map     = Settings::slot_map( $adapter );
		$current = $this->kadence_palette();
		$name    = Settings::OPTION . '[maps][' . $adapter->id() . ']';
		$p       = self::PREFIX;

		$this->section_heading( __( 'Kadence palette slots', 'kadence-theme-style-sync' ), __( 'Each Kadence slot has a fixed role. Pick the theme color that fills it. Theme colors left unused become Extra Colors.', 'kadence-theme-style-sync' ) );
		?>
		<ul class="<?php echo esc_attr( $p ); ?>row-list ktss-list">
			<?php foreach ( Settings::SLOTS as $slot ) : ?>
				<?php
				$key    = $map[ $slot ];
				$theme  = $key !== '' && isset( $palette[ $key ] ) ? $palette[ $key ]['color'] : '';
				$live   = $current[ $slot ] ?? '';
				$synced = $theme !== '' && strcasecmp( $theme, $live ) === 0;
				?>
				<li class="<?php echo esc_attr( $p ); ?>list-row">
					<div class="<?php echo esc_attr( $p ); ?>list-row-main ktss-row">
						<span class="ktss-chip" style="background:<?php echo esc_attr( $live !== '' ? $live : 'transparent' ); ?>"></span>
						<span class="<?php echo esc_attr( $p ); ?>list-row-label">
							<?php echo esc_html( self::SLOT_LABELS[ $slot ][0] ); ?>
							<span class="ktss-row-hint ktss-row-hint--plain"><?php echo esc_html( self::SLOT_LABELS[ $slot ][1] ); ?></span>
						</span>
						<select class="ktss-select" data-ktss-slot="<?php echo esc_attr( $slot ); ?>" name="<?php echo esc_attr( $name . '[' . $slot . ']' ); ?>">
							<option value=""><?php esc_html_e( 'Not synced (Kadence default)', 'kadence-theme-style-sync' ); ?></option>
							<?php foreach ( $palette as $color_key => $entry ) : ?>
								<option value="<?php echo esc_attr( $color_key ); ?>" data-color="<?php echo esc_attr( $entry['color'] ); ?>" <?php selected( $key, $color_key ); ?>>
									<?php echo esc_html( $entry['title'] . ' — ' . $entry['color'] ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<span class="<?php echo esc_attr( $p ); ?>list-row-value"><?php echo esc_html( $live ); ?></span>
						<?php if ( $theme !== '' ) : ?>
							<?php if ( $synced ) : ?>
								<?php $this->pill( __( 'From theme', 'kadence-theme-style-sync' ), 'inherited' ); ?>
							<?php else : ?>
								<?php $this->pill( __( 'Overridden', 'kadence-theme-style-sync' ), 'warning', __( 'A value saved in the Style Library wins over the theme (or a change is still pending). Reset the swatch in the Style Library to follow the theme again.', 'kadence-theme-style-sync' ) ); ?>
							<?php endif; ?>
						<?php endif; ?>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	public function render_parts( ?Adapters\Theme_Adapter $adapter ): void {
		$settings = Settings::get();
		$p        = self::PREFIX;

		$this->section_heading( __( 'Parts', 'kadence-theme-style-sync' ), __( 'Switch a part off to leave it at Kadence’s own defaults.', 'kadence-theme-style-sync' ) );
		?>
		<ul class="<?php echo esc_attr( $p ); ?>row-list ktss-list">
			<?php foreach ( self::PART_LABELS as $part => [ $label, $help ] ) : ?>
				<?php $field = Settings::OPTION . '[parts][' . $part . ']'; ?>
				<li class="<?php echo esc_attr( $p ); ?>list-row">
					<label class="<?php echo esc_attr( $p ); ?>list-row-main ktss-row ktss-row--toggle">
						<span class="components-form-toggle<?php echo $settings['parts'][ $part ] ? ' is-checked' : ''; ?>">
							<input type="hidden" name="<?php echo esc_attr( $field ); ?>" value="0" />
							<input class="components-form-toggle__input" type="checkbox" name="<?php echo esc_attr( $field ); ?>" value="1" <?php checked( $settings['parts'][ $part ] ); ?> data-ktss-toggle />
							<span class="components-form-toggle__track"></span>
							<span class="components-form-toggle__thumb"></span>
						</span>
						<span class="<?php echo esc_attr( $p ); ?>list-row-label"><?php echo esc_html( $label ); ?></span>
						<span class="ktss-row-text ktss-row-text--muted"><?php echo esc_html( $help ); ?></span>
					</label>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	public function render_colors( ?Adapters\Theme_Adapter $adapter ): void {
		$extras = $this->sync->extra_tokens();
		$p      = self::PREFIX;

		if ( $extras === [] ) {
			$this->empty_state( __( 'No extra colors', 'kadence-theme-style-sync' ), __( 'Every theme color fills a Kadence palette slot, or Extra colors is switched off.', 'kadence-theme-style-sync' ) );

			return;
		}
		?>
		<div class="<?php echo esc_attr( $p ); ?>swatch-group">
			<div class="<?php echo esc_attr( $p ); ?>swatch-group-heading">
				<div class="<?php echo esc_attr( $p ); ?>section-heading-row">
					<h3 class="<?php echo esc_attr( $p ); ?>section-heading"><?php echo esc_html( sprintf( '%s Colors', $adapter !== null ? $adapter->label() : 'Theme' ) ); ?></h3>
				</div>
			</div>
			<div class="<?php echo esc_attr( $p ); ?>swatch-group-grid">
				<?php foreach ( $extras as $id => $token ) : ?>
					<?php $now = $this->resolved_value( $id ); ?>
					<div class="<?php echo esc_attr( $p ); ?>swatch-card <?php echo esc_attr( $p ); ?>swatch-card--with-pill-slot">
						<div class="<?php echo esc_attr( $p ); ?>swatch-card-main">
							<div class="<?php echo esc_attr( $p ); ?>swatch-card-select ktss-static" title="<?php echo esc_attr( $id ); ?>">
								<span class="<?php echo esc_attr( $p ); ?>swatch-card-preview" style="background:<?php echo esc_attr( $now !== '' ? $now : $token['value'] ); ?>"></span>
								<span class="<?php echo esc_attr( $p ); ?>swatch-card-details">
									<span class="<?php echo esc_attr( $p ); ?>swatch-card-name"><?php echo esc_html( $token['label'] ); ?></span>
									<span class="<?php echo esc_attr( $p ); ?>swatch-card-sub-line"><?php echo esc_html( $now !== '' ? $now : $token['value'] ); ?></span>
								</span>
							</div>
							<span class="<?php echo esc_attr( $p ); ?>swatch-card-pill-slot">
								<?php
								$overridden = $now !== '' && strcasecmp( $now, $token['value'] ) !== 0;
								$this->pill( $overridden ? __( 'Overridden', 'kadence-theme-style-sync' ) : __( 'From theme', 'kadence-theme-style-sync' ), $overridden ? 'warning' : 'inherited' );
								?>
							</span>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<p class="ktss-prose ktss-prose--muted"><?php esc_html_e( 'These show in the Style Library’s Color Palette as their own group and in every Kadence color picker.', 'kadence-theme-style-sync' ); ?></p>
		<?php
	}

	public function render_tokens( ?Adapters\Theme_Adapter $adapter ): void {
		$leaves  = $this->sync->leaf_values();
		$presets = $this->sync->preset_values();
		$p       = self::PREFIX;

		if ( $leaves === [] && $presets === [] ) {
			$this->empty_state( __( 'Nothing synced', 'kadence-theme-style-sync' ), __( 'Text, links and button parts are switched off.', 'kadence-theme-style-sync' ) );

			return;
		}

		$this->section_heading( __( 'Style Library values', 'kadence-theme-style-sync' ), __( 'Theme value on the left, what the Style Library uses now on the right.', 'kadence-theme-style-sync' ) );
		?>
		<ul class="<?php echo esc_attr( $p ); ?>row-list ktss-list">
			<?php foreach ( $leaves as $id => $value ) : ?>
				<?php
				$now      = $this->resolved_value( $id );
				$is_color = strpos( $id, '.color.' ) !== false;
				?>
				<li class="<?php echo esc_attr( $p ); ?>list-row">
					<div class="<?php echo esc_attr( $p ); ?>list-row-main ktss-row">
						<?php if ( $is_color ) : ?>
							<span class="ktss-chip" style="background:<?php echo esc_attr( $now !== '' ? $now : 'transparent' ); ?>"></span>
						<?php else : ?>
							<span class="ktss-chip ktss-chip--empty"></span>
						<?php endif; ?>
						<span class="<?php echo esc_attr( $p ); ?>list-row-label">
							<?php echo esc_html( self::TOKEN_LABELS[ $id ] ?? $id ); ?>
							<span class="ktss-row-hint"><?php echo esc_html( $id ); ?></span>
						</span>
						<code class="ktss-code"><?php echo esc_html( is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value ) ); ?></code>
						<span class="<?php echo esc_attr( $p ); ?>list-row-value"><?php echo esc_html( $now ); ?></span>
					</div>
				</li>
			<?php endforeach; ?>
			<?php foreach ( $presets as $block => $block_presets ) : ?>
				<?php foreach ( $block_presets as $preset => $properties ) : ?>
					<?php foreach ( $properties as $property => $value ) : ?>
						<li class="<?php echo esc_attr( $p ); ?>list-row">
							<div class="<?php echo esc_attr( $p ); ?>list-row-main ktss-row">
								<span class="ktss-chip ktss-chip--empty"></span>
								<span class="<?php echo esc_attr( $p ); ?>list-row-label">
									<?php echo esc_html( ucfirst( str_replace( '-', ' ', $property ) ) ); ?>
									<span class="ktss-row-hint"><?php echo esc_html( $block . ' › ' . $preset ); ?></span>
								</span>
								<code class="ktss-code"><?php echo esc_html( is_array( $value ) ? implode( ' ', $value ) : (string) $value ); ?></code>
							</div>
						</li>
					<?php endforeach; ?>
				<?php endforeach; ?>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	public function render_fonts( ?Adapters\Theme_Adapter $adapter ): void {
		$fonts = $adapter !== null && Settings::enabled( 'fonts' ) ? $adapter->fonts() : [];
		$p     = self::PREFIX;

		if ( $fonts === [] ) {
			$this->empty_state(
				__( 'No theme-hosted fonts', 'kadence-theme-style-sync' ),
				$adapter !== null && method_exists( $adapter, 'fonts_hint' ) ? $adapter->fonts_hint() : __( 'Fonts the theme hosts itself appear here and in every Kadence font picker.', 'kadence-theme-style-sync' )
			);

			return;
		}

		$this->section_heading( __( 'In Kadence font pickers', 'kadence-theme-style-sync' ) );
		?>
		<ul class="<?php echo esc_attr( $p ); ?>row-list ktss-list">
			<?php foreach ( $fonts as $font ) : ?>
				<li class="<?php echo esc_attr( $p ); ?>list-row">
					<div class="<?php echo esc_attr( $p ); ?>list-row-main ktss-row">
						<span class="ktss-font-preview" style="font-family:<?php echo esc_attr( $font['family'] ); ?>">Aa</span>
						<span class="<?php echo esc_attr( $p ); ?>list-row-label">
							<?php echo esc_html( $font['label'] ); ?>
							<span class="ktss-row-hint"><?php echo esc_html( $font['family'] ); ?></span>
						</span>
						<?php $this->pill( $font['source'], 'inherited' ); ?>
						<span class="ktss-row-text ktss-row-text--muted"><?php echo esc_html( implode( ', ', $font['weights'] ) ); ?></span>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	private function section_heading( string $title, string $description = '' ): void {
		$p = self::PREFIX;
		?>
		<div class="ktss-section-heading">
			<div class="<?php echo esc_attr( $p ); ?>section-heading-row">
				<h3 class="<?php echo esc_attr( $p ); ?>section-heading"><?php echo esc_html( $title ); ?></h3>
			</div>
			<?php if ( $description !== '' ) : ?>
				<p class="ktss-prose ktss-prose--muted"><?php echo esc_html( $description ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	private function empty_state( string $title, string $description ): void {
		$p = self::PREFIX;
		?>
		<div class="<?php echo esc_attr( $p ); ?>empty-state">
			<p class="<?php echo esc_attr( $p ); ?>empty-state-title"><?php echo esc_html( $title ); ?></p>
			<p class="<?php echo esc_attr( $p ); ?>empty-state-description"><?php echo esc_html( $description ); ?></p>
		</div>
		<?php
	}

	/**
	 * Kadence's inheritance pill. Variants: inherited (Kadence's grey "Default" look), success, warning.
	 */
	private function pill( string $label, string $variant, string $title = '' ): void {
		$p     = self::PREFIX;
		$class = $p . 'inheritance-pill ktss-pill ktss-pill--' . $variant;

		if ( $variant === 'inherited' ) {
			$class .= ' ' . $p . 'inheritance-pill--inherited';
		}

		printf(
			'<span class="%s"%s>%s</span>',
			esc_attr( $class ),
			$title !== '' ? ' title="' . esc_attr( $title ) . '"' : '',
			esc_html( $label )
		);
	}

	/**
	 * What the active Style Library resolves a token to now (theme baseline plus saved overrides).
	 */
	private function resolved_value( string $id ): string {
		if ( $this->resolved === null ) {
			$this->resolved = false;

			try {
				$container      = kadence_blocks();
				$slug           = $container->get( \KadenceWP\KadenceBlocks\Design_Tokens\Database\Active_Token_Library_Store::class )->get();
				$this->resolved = $container->get( \KadenceWP\KadenceBlocks\Design_Tokens\Resolver\Token_Resolver::class )->resolve( $slug );
			} catch ( \Throwable $e ) {
				$this->resolved = false;
			}
		}

		if ( $this->resolved === false ) {
			return '';
		}

		try {
			$value = $this->resolved->value( $id );
		} catch ( \Throwable $e ) {
			return '';
		}

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Slot => color Kadence currently projects (its kadence_blocks_colors option tracks the resolved palette).
	 *
	 * @return array<string, string>
	 */
	private function kadence_palette(): array {
		$raw     = get_option( 'kadence_blocks_colors', '' );
		$decoded = is_string( $raw ) ? json_decode( $raw, true ) : null;
		$out     = [];

		foreach ( is_array( $decoded['palette'] ?? null ) ? $decoded['palette'] : [] as $entry ) {
			if ( is_array( $entry ) && isset( $entry['slug'], $entry['color'] ) ) {
				$out[ (string) $entry['slug'] ] = (string) $entry['color'];
			}
		}

		return $out;
	}

	/**
	 * URL of the Style Library stylesheet Kadence Blocks ships, or '' when this build has none.
	 */
	private function kadence_css_url(): string {
		if ( ! defined( 'KADENCE_BLOCKS_PATH' ) ) {
			return '';
		}

		$file = KADENCE_BLOCKS_PATH . 'dist/admin-kadence-style-library.css';

		if ( ! file_exists( $file ) ) {
			return '';
		}

		return plugins_url( 'dist/admin-kadence-style-library.css', KADENCE_BLOCKS_PATH . 'kadence-blocks.php' );
	}

	private function page_url(): string {
		return admin_url( 'admin.php?page=' . self::PAGE );
	}

	private static function logo(): string {
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 44 44" fill="none" width="48" height="48" class="' . self::PREFIX . 'logo" aria-hidden="true" focusable="false"><path fill-rule="evenodd" clip-rule="evenodd" d="M31.7632 36.9985H22.975C22.0234 36.9985 21.2511 36.2258 21.2511 35.2742C21.2511 34.3228 22.0234 33.5501 22.975 33.5501H28.7156C29.6115 33.4887 30.3201 32.7416 30.3201 31.8304C30.3201 30.8788 29.5476 30.1064 28.596 30.1064H24.399V30.1061H19.6132C18.663 30.1061 17.8915 29.3347 17.8915 28.3845C17.8915 27.4342 18.663 26.6626 19.6132 26.6626H20.6975L20.6952 26.6604H22.3074C23.2571 26.6604 24.0283 25.8894 24.0283 24.9396C24.0283 23.9898 23.2571 23.2188 22.3074 23.2188H18.2527V23.2173H9.74993C8.80017 23.2173 8.02902 22.4461 8.02902 21.4961C8.02902 20.5463 8.80017 19.775 9.74993 19.775H18.2527V19.7703H21.5819C22.5307 19.7703 23.3011 18.9999 23.3011 18.0508C23.3011 17.1018 22.5307 16.3314 21.5819 16.3314H20.823L20.8232 16.3311H17.2876C16.3374 16.3311 15.5661 15.5596 15.5661 14.6095C15.5661 13.6591 16.3374 12.8876 17.2876 12.8876H25.4516C25.4924 12.8876 25.5326 12.8889 25.5725 12.8917H28.4908C29.4421 12.8917 30.2146 12.1193 30.2146 11.1677C30.2146 10.2162 29.4421 9.44378 28.4908 9.44378H24.2011C23.3082 9.37899 22.6033 8.6333 22.6033 7.72417C22.6033 6.77255 23.3756 6 24.3272 6H31.0846C31.092 6 31.0993 6 31.1073 6.00015H39.0747L25.1143 21.2468L42 37H31.8088L31.8064 36.9977C31.7922 36.9983 31.7777 36.9985 31.7632 36.9985ZM15.2218 33.5501C16.17 33.5501 16.9398 34.32 16.9398 35.2681C16.9398 36.2164 16.17 36.986 15.2218 36.986H8.91762C7.96954 36.986 7.19975 36.2164 7.19975 35.2681C7.19975 34.32 7.96954 33.5501 8.91762 33.5501H15.2218ZM12.726 26.6626C13.677 26.6626 14.4491 27.4347 14.4491 28.3858C14.4491 29.3367 13.677 30.1089 12.726 30.1089C11.7749 30.1089 11.0029 29.3367 11.0029 28.3858C11.0029 27.4347 11.7749 26.6626 12.726 26.6626ZM3.72303 19.775C4.67415 19.775 5.44622 20.5471 5.44622 21.4983C5.44622 22.4493 4.67415 23.2214 3.72303 23.2214C2.77206 23.2214 2 22.4493 2 21.4983C2 20.5471 2.77206 19.775 3.72303 19.775ZM10.4006 12.8876C11.3516 12.8876 12.1238 13.6597 12.1238 14.6108C12.1238 15.5618 11.3516 16.3338 10.4006 16.3338C9.44963 16.3338 8.67741 15.5618 8.67741 14.6108C8.67741 13.6597 9.44963 12.8876 10.4006 12.8876ZM16.58 6C17.5282 6 18.2979 6.76982 18.2979 7.71795C18.2979 8.66623 17.5282 9.43605 16.58 9.43605H12.5951C11.647 9.43605 10.8772 8.66623 10.8772 7.71795C10.8772 6.76982 11.6469 6 12.5951 6H16.58Z" fill="#2458DC"></path></svg>';
	}

	private static function icon( string $name ): string {
		$paths = [
			'overview' => '<circle cx="8" cy="8" r="6.25"/><path d="M8 7.25v4M8 4.75h.01"/>',
			'palette'  => '<path d="M8 1.75a6.25 6.25 0 1 0 0 12.5c.7 0 1.1-.55.85-1.15-.3-.75.2-1.6 1-1.6h1.4a3 3 0 0 0 3-3C14.25 4.6 11.45 1.75 8 1.75Z"/><path d="M4.75 8h.01M6 5h.01M9.5 4.5h.01M11.5 7h.01"/>',
			'toggles'  => '<rect x="1.75" y="3" width="12.5" height="4" rx="2"/><rect x="1.75" y="9" width="12.5" height="4" rx="2"/><circle cx="11.75" cy="5" r=".9"/><circle cx="4.25" cy="11" r=".9"/>',
			'swatch'   => '<rect x="1.75" y="1.75" width="5.5" height="5.5" rx="1"/><rect x="8.75" y="1.75" width="5.5" height="5.5" rx="1"/><rect x="1.75" y="8.75" width="5.5" height="5.5" rx="1"/><path d="M9 11.5h5M11.5 9v5"/>',
			'button'   => '<rect x="1.75" y="4.25" width="12.5" height="7.5" rx="2"/><path d="M5.5 8h5"/>',
			'type'     => '<path d="M2.75 4.25V2.75h10.5v1.5M8 2.75v10.5M6 13.25h4"/>',
		];

		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round" width="16" height="16" class="' . self::PREFIX . 'nav-item-icon" aria-hidden="true" focusable="false">' . ( $paths[ $name ] ?? '' ) . '</svg>';
	}
}
