<?php
/**
 * REST Radar admin plugin UI.
 *
 * @package RestRadarEndpointInspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin class.
 */
class Rest_Radar_Plugin {
	/**
	 * Option name.
	 */
	const OPTION_NAME = 'rest_radar_options';

	/**
	 * Option name for saved scan snapshots.
	 */
	const SNAPSHOT_OPTION_NAME = 'rest_radar_snapshots';

	/**
	 * Option name for endpoint review decisions.
	 */
	const REVIEW_OPTION_NAME = 'rest_radar_endpoint_reviews';

	/**
	 * Maximum number of snapshots stored in wp_options.
	 */
	const MAX_SNAPSHOTS = 8;

	/**
	 * Maximum endpoint rows stored per snapshot.
	 */
	const MAX_SNAPSHOT_ROWS = 1000;

	/**
	 * Soft guard for serialized snapshot option payload size.
	 */
	const MAX_SNAPSHOT_OPTION_BYTES = 768000;

	/**
	 * Singleton instance.
	 *
	 * @var Rest_Radar_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Return singleton instance.
	 *
	 * @return Rest_Radar_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'wp_dashboard_setup', array( $this, 'register_dashboard_widget' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'admin_post_rest_radar_export_csv', array( $this, 'export_csv' ) );
		add_action( 'admin_post_rest_radar_export_markdown', array( $this, 'export_markdown' ) );
		add_action( 'admin_post_rest_radar_save_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_post_rest_radar_save_shield_settings', array( $this, 'save_shield_settings' ) );
		add_action( 'admin_post_rest_radar_save_endpoint_review', array( $this, 'save_endpoint_review' ) );
		add_action( 'admin_post_rest_radar_add_shield_rule', array( $this, 'add_shield_rule' ) );
		add_action( 'admin_post_rest_radar_delete_shield_rule', array( $this, 'delete_shield_rule' ) );
		add_action( 'admin_post_rest_radar_clear_shield_logs', array( $this, 'clear_shield_logs' ) );
		add_action( 'admin_post_rest_radar_create_snapshot', array( $this, 'create_snapshot' ) );
		add_action( 'admin_post_rest_radar_delete_snapshot', array( $this, 'delete_snapshot' ) );
		add_action( 'admin_post_rest_radar_clear_snapshots', array( $this, 'clear_snapshots' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( REST_RADAR_FILE ), array( $this, 'plugin_action_links' ) );
	}

	/**
	 * Add settings link on plugin screen.
	 *
	 * @param array<int,string> $links Existing links.
	 * @return array<int,string>
	 */
	public function plugin_action_links( $links ) {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'tools.php?page=rest-radar' ) ),
			esc_html__( 'Open scanner', 'rest-radar' )
		);

		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Register admin page.
	 *
	 * @return void
	 */
	public function register_admin_menu() {
		add_management_page(
			esc_html__( 'REST Radar', 'rest-radar' ),
			esc_html__( 'REST Radar', 'rest-radar' ),
			'manage_options',
			'rest-radar',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Register REST Radar dashboard widget.
	 *
	 * @return void
	 */
	public function register_dashboard_widget() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'rest_radar_dashboard_widget',
			esc_html__( 'REST Radar', 'rest-radar' ),
			array( $this, 'render_dashboard_widget' )
		);
	}

	/**
	 * Render WordPress dashboard summary widget.
	 *
	 * @return void
	 */
	public function render_dashboard_widget() {
		if ( ! current_user_can( 'manage_options' ) ) {
			echo '<p>' . esc_html__( 'You do not have permission to view REST Radar data.', 'rest-radar' ) . '</p>';
			return;
		}

		$options        = $this->get_options();
		$shield_options = Rest_Radar_Shield::get_options();
		$shield_logs    = Rest_Radar_Shield::get_logs();
		$rows           = Rest_Radar_Scanner::scan();
		$prepared_rows  = $this->prepare_rows( $rows, $options );
		$stats          = $this->build_stats( $prepared_rows );
		$urgent_rows    = $this->get_dashboard_urgent_rows( $prepared_rows );
		$scanner_url    = admin_url( 'tools.php?page=rest-radar' );
		$shield_enabled = ! empty( $shield_options['enabled'] );
		$rule_count     = ! empty( $shield_options['rules'] ) && is_array( $shield_options['rules'] ) ? count( $shield_options['rules'] ) : 0;
		?>
		<div class="rest-radar-dashboard">
			<div class="rest-radar-dashboard-status">
				<div>
					<strong><?php echo esc_html__( 'Endpoint scan summary', 'rest-radar' ); ?></strong>
					<p class="rest-radar-muted"><?php echo esc_html__( 'Quick admin snapshot. Open the full scanner for details, QA drafts, GET probe, CSV/Markdown exports, and Shield rules.', 'rest-radar' ); ?></p>
				</div>
				<span class="rest-radar-dashboard-pill <?php echo $shield_enabled ? 'is-on' : 'is-off'; ?>">
					<?php echo esc_html( $shield_enabled ? __( 'Shield ON', 'rest-radar' ) : __( 'Shield OFF', 'rest-radar' ) ); ?>
				</span>
			</div>

			<div class="rest-radar-dashboard-grid">
				<?php $this->render_dashboard_metric( __( 'Total', 'rest-radar' ), count( $prepared_rows ), 'total' ); ?>
				<?php $this->render_dashboard_metric( __( 'Critical', 'rest-radar' ), $stats['critical'] ?? 0, 'critical' ); ?>
				<?php $this->render_dashboard_metric( __( 'High', 'rest-radar' ), $stats['high'] ?? 0, 'high' ); ?>
				<?php $this->render_dashboard_metric( __( 'Review', 'rest-radar' ), $stats['medium'] ?? 0, 'medium' ); ?>
				<?php $this->render_dashboard_metric( __( 'Fix required', 'rest-radar' ), $stats['review_fix_required'] ?? 0, 'review-fix' ); ?>
				<?php $this->render_dashboard_metric( __( 'Retest', 'rest-radar' ), $stats['review_retest_required'] ?? 0, 'review-retest' ); ?>
			</div>

			<div class="rest-radar-dashboard-meta">
				<div><strong><?php echo esc_html( (string) absint( $rule_count ) ); ?></strong> <?php echo esc_html__( 'shield rule(s)', 'rest-radar' ); ?></div>
				<div><strong><?php echo esc_html( (string) count( $shield_logs ) ); ?></strong> <?php echo esc_html__( 'recent block log(s)', 'rest-radar' ); ?></div>
			</div>

			<?php if ( ! empty( $urgent_rows ) ) : ?>
				<h4><?php echo esc_html__( 'Top endpoints to review', 'rest-radar' ); ?></h4>
				<ul class="rest-radar-dashboard-list">
					<?php foreach ( $urgent_rows as $row ) : ?>
						<li>
							<?php $this->render_risk_badge( $row['risk'] ); ?>
							<a href="<?php echo esc_url( add_query_arg( 'rr_detail', $row['key'], $scanner_url ) ); ?>"><code><?php echo esc_html( $row['route'] ?? '' ); ?></code></a>
							<span class="rest-radar-muted"><?php echo esc_html( $row['source']['label'] ?? __( 'Unknown source', 'rest-radar' ) ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p class="rest-radar-dashboard-good"><?php echo esc_html__( 'No critical or high endpoints detected in the current scan.', 'rest-radar' ); ?></p>
			<?php endif; ?>

			<?php if ( ! empty( $shield_logs ) ) : ?>
				<?php $latest_log = $shield_logs[0]; ?>
				<p class="rest-radar-dashboard-latest">
					<strong><?php echo esc_html__( 'Latest block:', 'rest-radar' ); ?></strong>
					<code><?php echo esc_html( ( $latest_log['method'] ?? '' ) . ' ' . ( $latest_log['route'] ?? '' ) ); ?></code>
				</p>
			<?php endif; ?>

			<p class="rest-radar-dashboard-actions">
				<a href="<?php echo esc_url( $scanner_url ); ?>" class="button button-primary"><?php echo esc_html__( 'Open REST Radar', 'rest-radar' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'tools.php?page=rest-radar&risk=critical' ) ); ?>" class="button button-secondary"><?php echo esc_html__( 'Critical only', 'rest-radar' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Render one compact dashboard metric.
	 *
	 * @param string $label Label.
	 * @param int    $count Count.
	 * @param string $level Level class.
	 * @return void
	 */
	private function render_dashboard_metric( $label, $count, $level ) {
		?>
		<div class="rest-radar-dashboard-metric rest-radar-dashboard-metric-<?php echo esc_attr( sanitize_html_class( $level ) ); ?>">
			<span><?php echo esc_html( (string) absint( $count ) ); ?></span>
			<small><?php echo esc_html( $label ); ?></small>
		</div>
		<?php
	}

	/**
	 * Get dashboard rows worth showing first.
	 *
	 * @param array<int,array<string,mixed>> $rows Prepared rows.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_dashboard_urgent_rows( array $rows ) {
		$urgent = array_filter(
			$rows,
			static function ( $row ) {
				$level = isset( $row['risk']['level'] ) ? (string) $row['risk']['level'] : 'low';
				return in_array( $level, array( 'critical', 'high' ), true ) && empty( $row['ignored'] );
			}
		);

		return array_slice( array_values( $urgent ), 0, 5 );
	}

	/**
	 * Enqueue admin stylesheet on REST Radar screens.
	 *
	 * @param string $hook Current admin hook.
	 * @return void
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( ! in_array( $hook, array( 'tools_page_rest-radar', 'index.php' ), true ) ) {
			return;
		}

		wp_enqueue_style(
			'rest-radar-admin',
			REST_RADAR_URL . 'assets/css/admin.css',
			array(),
			REST_RADAR_VERSION
		);

		wp_enqueue_script(
			'rest-radar-admin',
			REST_RADAR_URL . 'assets/js/admin.js',
			array(),
			REST_RADAR_VERSION,
			true
		);
	}

	/**
	 * Render admin scanner page.
	 *
	 * @return void
	 */
	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'rest-radar' ) );
		}

		$options        = $this->get_options();
		$shield_options = Rest_Radar_Shield::get_options();
		$shield_logs    = Rest_Radar_Shield::get_logs();
		$rows           = Rest_Radar_Scanner::scan();
		$prepared_rows = $this->prepare_rows( $rows, $options );
		$risk_filter   = isset( $_GET['risk'] ) ? sanitize_key( wp_unslash( $_GET['risk'] ) ) : '';
		$source_filter = isset( $_GET['source'] ) ? sanitize_key( wp_unslash( $_GET['source'] ) ) : '';
		$search_filter = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$review_filter = isset( $_GET['review'] ) ? sanitize_key( wp_unslash( $_GET['review'] ) ) : '';
		$filtered_rows = $this->filter_rows( $prepared_rows, $risk_filter, $source_filter, $search_filter, $review_filter, $options );
		$stats         = $this->build_stats( $prepared_rows );
		$snapshots     = $this->get_snapshots();
		$snapshot_a    = isset( $_GET['rr_snap_a'] ) ? sanitize_key( wp_unslash( $_GET['rr_snap_a'] ) ) : '';
		$snapshot_b    = isset( $_GET['rr_snap_b'] ) ? sanitize_key( wp_unslash( $_GET['rr_snap_b'] ) ) : '';
		$snapshot_compare = ( $snapshot_a && $snapshot_b ) ? $this->build_snapshot_compare( $snapshots, $snapshot_a, $snapshot_b ) : null;
		$export_url    = wp_nonce_url(
			admin_url( 'admin-post.php?action=rest_radar_export_csv' ),
			'rest_radar_export_csv'
		);
		$markdown_url  = wp_nonce_url(
			admin_url( 'admin-post.php?action=rest_radar_export_markdown' ),
			'rest_radar_export_markdown'
		);

		foreach ( array( 'risk' => $risk_filter, 'source' => $source_filter, 's' => $search_filter, 'review' => $review_filter ) as $key => $value ) {
			if ( '' !== $value ) {
				$export_url   = add_query_arg( $key, rawurlencode( $value ), $export_url );
				$markdown_url = add_query_arg( $key, rawurlencode( $value ), $markdown_url );
			}
		}

		$detail_key    = isset( $_GET['rr_detail'] ) ? sanitize_text_field( wp_unslash( $_GET['rr_detail'] ) ) : '';
		$probe_key     = isset( $_GET['rr_probe'] ) ? sanitize_text_field( wp_unslash( $_GET['rr_probe'] ) ) : '';
		$selected_key  = $probe_key ? $probe_key : $detail_key;
		$selected_row  = $selected_key ? $this->find_row_by_key( $prepared_rows, $selected_key ) : null;
		$probe_result  = null;

		if ( $selected_row && $probe_key ) {
			$probe_nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
			if ( wp_verify_nonce( $probe_nonce, 'rest_radar_probe_' . $probe_key ) ) {
				$probe_result = $this->probe_endpoint( $selected_row );
			} else {
				$probe_result = array(
					'status'  => 'error',
					'message' => __( 'Invalid probe nonce. Please open the endpoint details and try again.', 'rest-radar' ),
				);
			}
		}
		?>
		<div class="wrap rest-radar-wrap">
			<div class="rest-radar-hero">
				<div class="rest-radar-hero-main">
					<div class="rest-radar-kicker"><?php echo esc_html__( 'REST API QA & Shield', 'rest-radar' ); ?></div>
					<h1><?php echo esc_html__( 'REST Radar - Endpoint Inspector', 'rest-radar' ); ?></h1>
					<p class="rest-radar-lead">
						<?php echo esc_html__( 'Inspect REST API routes, review risky permissions, generate QA evidence, and apply non-destructive Shield rules from one clean admin workspace.', 'rest-radar' ); ?>
					</p>
				</div>
				<div class="rest-radar-hero-actions">
					<span class="rest-radar-version-pill"><?php echo esc_html( 'v' . REST_RADAR_VERSION ); ?></span>
					<a href="<?php echo esc_url( $export_url ); ?>" class="button button-primary"><?php echo esc_html__( 'Export CSV', 'rest-radar' ); ?></a>
					<a href="<?php echo esc_url( $markdown_url ); ?>" class="button button-secondary"><?php echo esc_html__( 'Export QA Markdown', 'rest-radar' ); ?></a>
				</div>
			</div>

			<?php if ( isset( $_GET['rest_radar_updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php echo esc_html__( 'REST Radar settings saved.', 'rest-radar' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['rest_radar_shield_updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php echo esc_html__( 'Endpoint Shield settings saved.', 'rest-radar' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['rest_radar_auto_confirm_required'] ) ) : ?>
				<div class="notice notice-warning is-dismissible">
					<p><?php echo esc_html__( 'Auto Safe Mode was not enabled because the explicit confirmation checkbox was not selected.', 'rest-radar' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['rest_radar_core_confirm_required'] ) ) : ?>
				<div class="notice notice-error is-dismissible">
					<p><?php echo esc_html__( 'Core route protection was not enabled because it requires a separate confirmation. This can affect the editor, admin screens, and WordPress integrations.', 'rest-radar' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $shield_options['enabled'] ) ) : ?>
				<div class="notice notice-warning inline">
					<p><strong><?php echo esc_html__( 'Endpoint Shield is active.', 'rest-radar' ); ?></strong> <?php echo esc_html__( 'Test rules on staging first. A broad rule can block legitimate REST API traffic.', 'rest-radar' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $shield_options['auto_safe_mode'] ) ) : ?>
				<div class="notice notice-warning inline">
					<p><strong><?php echo esc_html__( 'Auto Safe Mode is active.', 'rest-radar' ); ?></strong> <?php echo esc_html__( 'REST Radar may automatically block critical/high custom endpoints for non-admin users.', 'rest-radar' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $shield_options['include_core'] ) ) : ?>
				<div class="notice notice-error inline">
					<p><strong><?php echo esc_html__( 'Core route protection is enabled.', 'rest-radar' ); ?></strong> <?php echo esc_html__( 'Use this only for controlled testing because blocking WordPress core REST routes may affect admin and editor functionality.', 'rest-radar' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['rest_radar_shield_added'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php echo esc_html__( 'Endpoint Shield rule added.', 'rest-radar' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['rest_radar_shield_duplicate'] ) ) : ?>
				<div class="notice notice-warning is-dismissible">
					<p><?php echo esc_html__( 'This Endpoint Shield rule already exists, so REST Radar did not add it again.', 'rest-radar' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['rest_radar_shield_deleted'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php echo esc_html__( 'Endpoint Shield rule deleted.', 'rest-radar' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['rest_radar_snapshot_created'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php echo esc_html__( 'REST Radar snapshot saved.', 'rest-radar' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['rest_radar_snapshot_limited'] ) ) : ?>
				<div class="notice notice-warning is-dismissible">
					<p><?php echo esc_html__( 'Snapshot storage was limited to protect wp_options size. Older snapshots or excessive endpoint rows may have been trimmed.', 'rest-radar' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['rest_radar_snapshot_deleted'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php echo esc_html__( 'REST Radar snapshot deleted.', 'rest-radar' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['rest_radar_snapshots_cleared'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php echo esc_html__( 'REST Radar snapshots cleared.', 'rest-radar' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['rest_radar_review_saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php echo esc_html__( 'Endpoint review decision saved.', 'rest-radar' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['rest_radar_review_note_required'] ) ) : ?>
				<div class="notice notice-error is-dismissible">
					<p><?php echo esc_html__( 'Severity override was not saved because a reviewer note is required.', 'rest-radar' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( $selected_key && ! $selected_row ) : ?>
				<div class="notice notice-error inline">
					<p><?php echo esc_html__( 'The selected endpoint is no longer available in the current scan.', 'rest-radar' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( $selected_row ) : ?>
				<?php $this->render_endpoint_details( $selected_row, $probe_result ); ?>
			<?php endif; ?>

			<?php $this->render_admin_dashboard_overview( $prepared_rows, $filtered_rows, $stats, $shield_options, $snapshots, $export_url, $markdown_url ); ?>

			<?php if ( is_array( $snapshot_compare ) ) : ?>
				<?php $this->render_snapshot_compare_panel( $snapshot_compare ); ?>
			<?php endif; ?>

			<div class="rest-radar-layout">
				<div class="rest-radar-main">
					<div class="rest-radar-toolbar">
						<div class="rest-radar-toolbar-title">
							<strong><?php echo esc_html__( 'Endpoint inventory', 'rest-radar' ); ?></strong>
							<span><?php echo esc_html( sprintf( __( 'Showing %1$d of %2$d endpoint rows', 'rest-radar' ), count( $filtered_rows ), count( $prepared_rows ) ) ); ?></span>
						</div>
						<form method="get" action="" class="rest-radar-filter-form">
							<input type="hidden" name="page" value="rest-radar" />
							<label for="rest-radar-risk" class="screen-reader-text"><?php echo esc_html__( 'Risk filter', 'rest-radar' ); ?></label>
							<select id="rest-radar-risk" name="risk">
								<option value=""><?php echo esc_html__( 'All risk levels', 'rest-radar' ); ?></option>
								<?php foreach ( array( 'critical', 'high', 'medium', 'public', 'low' ) as $risk_level ) : ?>
									<option value="<?php echo esc_attr( $risk_level ); ?>" <?php selected( $risk_filter, $risk_level ); ?>><?php echo esc_html( ucfirst( $risk_level ) ); ?></option>
								<?php endforeach; ?>
							</select>

							<label for="rest-radar-source" class="screen-reader-text"><?php echo esc_html__( 'Source filter', 'rest-radar' ); ?></label>
							<select id="rest-radar-source" name="source">
								<option value=""><?php echo esc_html__( 'All sources', 'rest-radar' ); ?></option>
								<?php foreach ( array( 'plugin', 'theme', 'mu-plugin', 'core', 'unknown' ) as $source_level ) : ?>
									<option value="<?php echo esc_attr( $source_level ); ?>" <?php selected( $source_filter, $source_level ); ?>><?php echo esc_html( ucfirst( $source_level ) ); ?></option>
								<?php endforeach; ?>
							</select>

							<label for="rest-radar-review" class="screen-reader-text"><?php echo esc_html__( 'Review filter', 'rest-radar' ); ?></label>
							<select id="rest-radar-review" name="review">
								<option value=""><?php echo esc_html__( 'All review states', 'rest-radar' ); ?></option>
								<option value="unreviewed" <?php selected( $review_filter, 'unreviewed' ); ?>><?php echo esc_html__( 'Unreviewed only', 'rest-radar' ); ?></option>
								<option value="high_unreviewed" <?php selected( $review_filter, 'high_unreviewed' ); ?>><?php echo esc_html__( 'Critical/High + unreviewed', 'rest-radar' ); ?></option>
								<option value="has_shield_rule" <?php selected( $review_filter, 'has_shield_rule' ); ?>><?php echo esc_html__( 'Has Shield rule', 'rest-radar' ); ?></option>
								<?php foreach ( $this->review_statuses() as $status_key => $status_label ) : ?>
									<option value="<?php echo esc_attr( $status_key ); ?>" <?php selected( $review_filter, $status_key ); ?>><?php echo esc_html( $status_label ); ?></option>
								<?php endforeach; ?>
							</select>

							<label for="rest-radar-search" class="screen-reader-text"><?php echo esc_html__( 'Search endpoint', 'rest-radar' ); ?></label>
							<input id="rest-radar-search" type="search" name="s" value="<?php echo esc_attr( $search_filter ); ?>" placeholder="<?php echo esc_attr__( 'Search route, namespace, callback...', 'rest-radar' ); ?>" />
							<button type="submit" class="button button-secondary"><?php echo esc_html__( 'Filter', 'rest-radar' ); ?></button>
							<a href="<?php echo esc_url( admin_url( 'tools.php?page=rest-radar' ) ); ?>" class="button button-secondary rest-radar-reset-button"><span class="dashicons dashicons-image-rotate" aria-hidden="true"></span><?php echo esc_html__( 'Reset', 'rest-radar' ); ?></a>
						</form>

					</div>

					<?php if ( empty( $filtered_rows ) ) : ?>
						<div class="notice notice-info inline">
							<p><?php echo esc_html__( 'No REST endpoints matched the current filter.', 'rest-radar' ); ?></p>
						</div>
					<?php else : ?>
						<div class="rest-radar-table-card">
							<table class="widefat striped rest-radar-table">
							<thead>
								<tr>
									<th scope="col"><?php echo esc_html__( 'Risk', 'rest-radar' ); ?></th>
									<th scope="col"><?php echo esc_html__( 'Review', 'rest-radar' ); ?></th>
									<th scope="col"><?php echo esc_html__( 'Actions', 'rest-radar' ); ?></th>
									<th scope="col"><?php echo esc_html__( 'Methods', 'rest-radar' ); ?></th>
									<th scope="col"><?php echo esc_html__( 'Source', 'rest-radar' ); ?></th>
									<th scope="col"><?php echo esc_html__( 'Namespace', 'rest-radar' ); ?></th>
									<th scope="col"><?php echo esc_html__( 'Route', 'rest-radar' ); ?></th>
									<th scope="col"><?php echo esc_html__( 'Permission callback', 'rest-radar' ); ?></th>
									<th scope="col"><?php echo esc_html__( 'Main callback', 'rest-radar' ); ?></th>
									<th scope="col"><?php echo esc_html__( 'Tags', 'rest-radar' ); ?></th>
									<th scope="col"><?php echo esc_html__( 'Recommended action', 'rest-radar' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $filtered_rows as $row ) : ?>
									<tr class="<?php echo ! empty( $row['ignored'] ) ? 'rest-radar-row-ignored' : ''; ?>">
										<td>
											<?php $this->render_risk_badge( $row['risk'] ); ?>
											<?php if ( ! empty( $row['ignored'] ) ) : ?>
												<div class="rest-radar-ignored-label"><?php echo esc_html__( 'Ignored', 'rest-radar' ); ?></div>
											<?php endif; ?>
											<?php if ( ! empty( $row['review']['severity_override'] ) ) : ?>
												<div class="rest-radar-muted"><?php echo esc_html__( 'Manual override', 'rest-radar' ); ?></div>
											<?php endif; ?>
										</td>
										<td>
											<?php $this->render_review_badge( $row['review'] ?? array() ); ?>
											<?php if ( ! empty( $row['shield_rule_state'] ) && 'active' === $row['shield_rule_state'] ) : ?>
												<div class="rest-radar-muted"><?php echo esc_html__( 'Shield rule active', 'rest-radar' ); ?></div>
											<?php endif; ?>
										</td>
										<td><?php $this->render_row_actions( $row ); ?></td>
										<td><code><?php echo esc_html( implode( ', ', $row['methods'] ) ); ?></code></td>
										<td><?php echo esc_html( $row['source']['label'] ); ?></td>
										<td><?php echo esc_html( $row['namespace'] ); ?></td>
										<td>
											<code><?php echo esc_html( $row['route'] ); ?></code>
											<?php if ( ! empty( $row['route_shape']['has_params'] ) ) : ?>
												<div class="rest-radar-source">
													<?php echo esc_html( sprintf( __( '%d route parameter(s)', 'rest-radar' ), absint( $row['route_shape']['param_count'] ) ) ); ?>
												</div>
											<?php endif; ?>
										</td>
										<td>
											<code><?php echo esc_html( $row['permission_callback_label'] ); ?></code>
											<?php if ( ! empty( $row['permission_callback_source'] ) ) : ?>
												<div class="rest-radar-source"><?php echo esc_html( $row['permission_callback_source'] ); ?></div>
											<?php endif; ?>
										</td>
										<td>
											<code><?php echo esc_html( $row['callback_label'] ); ?></code>
											<?php if ( ! empty( $row['callback_source'] ) ) : ?>
												<div class="rest-radar-source"><?php echo esc_html( $row['callback_source'] ); ?></div>
											<?php endif; ?>
										</td>
										<td><?php $this->render_tags( $row['tags'] ); ?></td>
										<td>
											<strong><?php echo esc_html( $row['risk']['message'] ); ?></strong>
											<div class="rest-radar-recommendation"><?php echo esc_html( $row['recommendation'] ); ?></div>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
							</table>
						</div>
					<?php endif; ?>

					<div class="rest-radar-notes">
						<h2><?php echo esc_html__( 'How to read this report', 'rest-radar' ); ?></h2>
						<ul>
							<li><?php echo esc_html__( 'Critical: missing permission_callback. Review immediately.', 'rest-radar' ); ?></li>
							<li><?php echo esc_html__( 'High: public permission callback on a write-capable route.', 'rest-radar' ); ?></li>
							<li><?php echo esc_html__( 'Review: write-capable route or sensitive-looking read route. Verify capability and object-level checks.', 'rest-radar' ); ?></li>
							<li><?php echo esc_html__( 'Public: public read endpoint. This may be valid, but confirm it exposes no private data.', 'rest-radar' ); ?></li>
						</ul>
					</div>
				</div>

				<aside class="rest-radar-side">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rest-radar-settings-box">
						<input type="hidden" name="action" value="rest_radar_save_settings" />
						<?php wp_nonce_field( 'rest_radar_save_settings' ); ?>
						<h2><?php echo esc_html__( 'Noise control', 'rest-radar' ); ?></h2>
						<p><?php echo esc_html__( 'Use this to hide known-safe routes from repeated review. Patterns support * wildcards.', 'rest-radar' ); ?></p>

						<label class="rest-radar-checkbox">
							<input type="checkbox" name="hide_core_public" value="1" <?php checked( ! empty( $options['hide_core_public'] ) ); ?> />
							<?php echo esc_html__( 'Hide WordPress core public read endpoints', 'rest-radar' ); ?>
						</label>

						<label class="rest-radar-checkbox">
							<input type="checkbox" name="hide_ignored" value="1" <?php checked( ! empty( $options['hide_ignored'] ) ); ?> />
							<?php echo esc_html__( 'Hide ignored endpoints from the table', 'rest-radar' ); ?>
						</label>

						<label for="rest-radar-ignored-patterns"><strong><?php echo esc_html__( 'Ignored route patterns', 'rest-radar' ); ?></strong></label>
						<textarea id="rest-radar-ignored-patterns" name="ignored_patterns" rows="9" placeholder="/wp/v2/*&#10;/contact-form-7/*"><?php echo esc_textarea( implode( "\n", $options['ignored_patterns'] ) ); ?></textarea>
						<p class="description"><?php echo esc_html__( 'One pattern per line. It matches route, namespace, callback, and source label.', 'rest-radar' ); ?></p>

						<hr />

						<h3><?php echo esc_html__( 'Uninstall cleanup', 'rest-radar' ); ?></h3>
						<label class="rest-radar-checkbox">
							<input type="checkbox" name="cleanup_on_uninstall" value="1" <?php checked( ! empty( $options['cleanup_on_uninstall'] ) ); ?> />
							<span>
								<strong><?php echo esc_html__( 'Remove REST Radar data when the plugin is deleted', 'rest-radar' ); ?></strong><br />
								<span class="description"><?php echo esc_html__( 'Deletes settings, Shield rules/logs, and snapshots during uninstall. Leave unchecked if you want to keep audit evidence after removing the plugin.', 'rest-radar' ); ?></span>
							</span>
						</label>

						<button type="submit" class="button button-primary"><?php echo esc_html__( 'Save settings', 'rest-radar' ); ?></button>
					</form>

					<?php $this->render_snapshot_box( $snapshots, $snapshot_a, $snapshot_b ); ?>

					<?php $this->render_shield_box( $shield_options, $shield_logs ); ?>
				</aside>
			</div>
		</div>
		<?php
	}



	/**
	 * Render saved snapshot controls.
	 *
	 * @param array<int,array<string,mixed>> $snapshots Saved snapshots.
	 * @param string                         $selected_a Baseline snapshot ID.
	 * @param string                         $selected_b Comparison snapshot ID.
	 * @return void
	 */
	private function render_snapshot_box( array $snapshots, $selected_a, $selected_b ) {
		if ( count( $snapshots ) >= 2 ) {
			$selected_a = $selected_a ? $selected_a : ( $snapshots[1]['id'] ?? '' );
			$selected_b = $selected_b ? $selected_b : ( $snapshots[0]['id'] ?? '' );
		}
		?>
		<div class="rest-radar-settings-box rest-radar-snapshot-box">
			<h2><?php echo esc_html__( 'Snapshots & compare', 'rest-radar' ); ?></h2>
			<p><?php echo esc_html__( 'Save a scan before and after updates, then compare REST API changes for regression QA.', 'rest-radar' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rest-radar-snapshot-create-form">
				<input type="hidden" name="action" value="rest_radar_create_snapshot" />
				<?php wp_nonce_field( 'rest_radar_create_snapshot' ); ?>
				<label for="rest-radar-snapshot-name"><strong><?php echo esc_html__( 'Snapshot name', 'rest-radar' ); ?></strong></label>
				<input id="rest-radar-snapshot-name" type="text" class="regular-text" name="snapshot_name" placeholder="<?php echo esc_attr__( 'Before plugin update', 'rest-radar' ); ?>" />
				<button type="submit" class="button button-primary"><?php echo esc_html__( 'Save current scan', 'rest-radar' ); ?></button>
			</form>

			<?php if ( count( $snapshots ) >= 2 ) : ?>
				<form method="get" action="" class="rest-radar-snapshot-compare-form">
					<input type="hidden" name="page" value="rest-radar" />
					<label for="rest-radar-snap-a"><strong><?php echo esc_html__( 'Baseline', 'rest-radar' ); ?></strong></label>
					<select id="rest-radar-snap-a" name="rr_snap_a">
						<?php foreach ( $snapshots as $snapshot ) : ?>
							<option value="<?php echo esc_attr( $snapshot['id'] ); ?>" <?php selected( $selected_a, $snapshot['id'] ); ?>><?php echo esc_html( $snapshot['label'] ); ?></option>
						<?php endforeach; ?>
					</select>

					<label for="rest-radar-snap-b"><strong><?php echo esc_html__( 'Compare with', 'rest-radar' ); ?></strong></label>
					<select id="rest-radar-snap-b" name="rr_snap_b">
						<?php foreach ( $snapshots as $snapshot ) : ?>
							<option value="<?php echo esc_attr( $snapshot['id'] ); ?>" <?php selected( $selected_b, $snapshot['id'] ); ?>><?php echo esc_html( $snapshot['label'] ); ?></option>
						<?php endforeach; ?>
					</select>

					<button type="submit" class="button button-secondary"><?php echo esc_html__( 'Compare snapshots', 'rest-radar' ); ?></button>
				</form>
			<?php else : ?>
				<p class="description"><?php echo esc_html__( 'Save at least two snapshots to enable comparison.', 'rest-radar' ); ?></p>
			<?php endif; ?>

			<?php if ( ! empty( $snapshots ) ) : ?>
				<h3><?php echo esc_html__( 'Saved snapshots', 'rest-radar' ); ?></h3>
				<div class="rest-radar-snapshot-list">
					<?php foreach ( array_slice( $snapshots, 0, 8 ) as $snapshot ) : ?>
						<div class="rest-radar-snapshot-item">
							<div>
								<strong><?php echo esc_html( $snapshot['name'] ); ?></strong>
								<div class="rest-radar-muted">
									<?php echo esc_html( sprintf( __( '%1$d endpoint rows · %2$s', 'rest-radar' ), absint( $snapshot['count'] ?? 0 ), $snapshot['created_at'] ?? '' ) ); ?>
								</div>
							</div>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="rest_radar_delete_snapshot" />
								<input type="hidden" name="snapshot_id" value="<?php echo esc_attr( $snapshot['id'] ); ?>" />
								<?php wp_nonce_field( 'rest_radar_delete_snapshot_' . $snapshot['id'] ); ?>
								<button type="submit" class="button button-small button-secondary"><?php echo esc_html__( 'Delete', 'rest-radar' ); ?></button>
							</form>
						</div>
					<?php endforeach; ?>
				</div>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rest-radar-clear-snapshots-form">
					<input type="hidden" name="action" value="rest_radar_clear_snapshots" />
					<?php wp_nonce_field( 'rest_radar_clear_snapshots' ); ?>
					<button type="submit" class="button button-secondary"><?php echo esc_html__( 'Clear all snapshots', 'rest-radar' ); ?></button>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render snapshot comparison result panel.
	 *
	 * @param array<string,mixed> $compare Compare result.
	 * @return void
	 */
	private function render_snapshot_compare_panel( array $compare ) {
		if ( empty( $compare['valid'] ) ) {
			?>
			<div class="notice notice-error inline">
				<p><?php echo esc_html__( 'Snapshot comparison could not be loaded. Select two valid saved snapshots.', 'rest-radar' ); ?></p>
			</div>
			<?php
			return;
		}

		$summary = $compare['summary'];
		?>
		<div class="rest-radar-compare-panel">
			<div class="rest-radar-compare-heading">
				<div>
					<div class="rest-radar-kicker"><?php echo esc_html__( 'Regression QA', 'rest-radar' ); ?></div>
					<h2><?php echo esc_html__( 'Snapshot comparison', 'rest-radar' ); ?></h2>
					<p>
						<strong><?php echo esc_html( $compare['a']['name'] ); ?></strong>
						<?php echo esc_html__( 'vs', 'rest-radar' ); ?>
						<strong><?php echo esc_html( $compare['b']['name'] ); ?></strong>
					</p>
				</div>
				<a href="<?php echo esc_url( admin_url( 'tools.php?page=rest-radar' ) ); ?>" class="button button-secondary"><?php echo esc_html__( 'Clear compare', 'rest-radar' ); ?></a>
			</div>

			<div class="rest-radar-compare-grid">
				<?php $this->render_compare_metric( __( 'New endpoints', 'rest-radar' ), $summary['added'] ?? 0, 'high' ); ?>
				<?php $this->render_compare_metric( __( 'Removed endpoints', 'rest-radar' ), $summary['removed'] ?? 0, 'public' ); ?>
				<?php $this->render_compare_metric( __( 'Risk changed', 'rest-radar' ), $summary['risk_changed'] ?? 0, 'medium' ); ?>
				<?php $this->render_compare_metric( __( 'Permission changed', 'rest-radar' ), $summary['permission_changed'] ?? 0, 'critical' ); ?>
				<?php $this->render_compare_metric( __( 'Source changed', 'rest-radar' ), $summary['source_changed'] ?? 0, 'low' ); ?>
			</div>

			<?php foreach ( array(
				'added'              => __( 'New endpoints', 'rest-radar' ),
				'removed'            => __( 'Removed endpoints', 'rest-radar' ),
				'risk_changed'       => __( 'Risk changes', 'rest-radar' ),
				'permission_changed' => __( 'Permission callback changes', 'rest-radar' ),
				'source_changed'     => __( 'Source changes', 'rest-radar' ),
			) as $section_key => $section_label ) : ?>
				<?php if ( empty( $compare[ $section_key ] ) ) : ?>
					<?php continue; ?>
				<?php endif; ?>
				<div class="rest-radar-compare-section">
					<h3><?php echo esc_html( $section_label ); ?></h3>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php echo esc_html__( 'Route', 'rest-radar' ); ?></th>
								<th><?php echo esc_html__( 'Methods', 'rest-radar' ); ?></th>
								<th><?php echo esc_html__( 'Before', 'rest-radar' ); ?></th>
								<th><?php echo esc_html__( 'After', 'rest-radar' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( array_slice( $compare[ $section_key ], 0, 20 ) as $change ) : ?>
								<tr>
									<td><code><?php echo esc_html( $change['route'] ?? '' ); ?></code></td>
									<td><code><?php echo esc_html( $change['methods'] ?? '' ); ?></code></td>
									<td><?php echo esc_html( $change['before'] ?? '' ); ?></td>
									<td><?php echo esc_html( $change['after'] ?? '' ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<?php if ( count( $compare[ $section_key ] ) > 20 ) : ?>
						<p class="description"><?php echo esc_html__( 'Showing the first 20 changes in this section.', 'rest-radar' ); ?></p>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>

			<?php if ( empty( $summary['added'] ) && empty( $summary['removed'] ) && empty( $summary['risk_changed'] ) && empty( $summary['permission_changed'] ) && empty( $summary['source_changed'] ) ) : ?>
				<p class="rest-radar-dashboard-good"><?php echo esc_html__( 'No REST endpoint regression differences found between these snapshots.', 'rest-radar' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render one compare metric.
	 *
	 * @param string $label Label.
	 * @param int    $count Count.
	 * @param string $level Badge level.
	 * @return void
	 */
	private function render_compare_metric( $label, $count, $level ) {
		?>
		<div class="rest-radar-compare-metric rest-radar-compare-metric-<?php echo esc_attr( sanitize_html_class( $level ) ); ?>">
			<span><?php echo esc_html( (string) absint( $count ) ); ?></span>
			<small><?php echo esc_html( $label ); ?></small>
		</div>
		<?php
	}

	/**
	 * Get saved snapshots.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function get_snapshots() {
		$snapshots = get_option( self::SNAPSHOT_OPTION_NAME, array() );
		if ( ! is_array( $snapshots ) ) {
			return array();
		}

		$clean = array();
		foreach ( $snapshots as $snapshot ) {
			if ( ! is_array( $snapshot ) || empty( $snapshot['id'] ) || empty( $snapshot['rows'] ) || ! is_array( $snapshot['rows'] ) ) {
				continue;
			}

			$name       = isset( $snapshot['name'] ) ? sanitize_text_field( (string) $snapshot['name'] ) : __( 'Unnamed snapshot', 'rest-radar' );
			$created_at = isset( $snapshot['created_at'] ) ? sanitize_text_field( (string) $snapshot['created_at'] ) : '';
			$count      = count( $snapshot['rows'] );
			$clean[]    = array(
				'id'         => sanitize_key( (string) $snapshot['id'] ),
				'name'       => $name,
				'label'      => trim( $name . ' (' . $count . ')' ),
				'created_at' => $created_at,
				'count'      => $count,
				'rows'       => $this->sanitize_snapshot_rows( $snapshot['rows'] ),
			);
		}

		usort(
			$clean,
			static function ( $a, $b ) {
				return strcmp( (string) ( $b['created_at'] ?? '' ), (string) ( $a['created_at'] ?? '' ) );
			}
		);

		return array_values( array_slice( $clean, 0, self::MAX_SNAPSHOTS ) );
	}

	/**
	 * Sanitize compact snapshot rows.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows.
	 * @return array<int,array<string,mixed>>
	 */
	private function sanitize_snapshot_rows( array $rows ) {
		$clean = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || empty( $row['route'] ) ) {
				continue;
			}

			$methods = isset( $row['methods'] ) && is_array( $row['methods'] ) ? array_map( 'sanitize_key', $row['methods'] ) : array();
			$methods = array_values( array_filter( array_map( 'strtoupper', $methods ) ) );
			sort( $methods );

			$clean[] = array(
				'signature'                 => isset( $row['signature'] ) ? sanitize_text_field( (string) $row['signature'] ) : $this->snapshot_row_signature( array( 'route' => $row['route'], 'methods' => $methods ) ),
				'route'                     => sanitize_text_field( (string) $row['route'] ),
				'methods'                   => $methods,
				'namespace'                 => isset( $row['namespace'] ) ? sanitize_text_field( (string) $row['namespace'] ) : '',
				'risk_level'                => isset( $row['risk_level'] ) ? sanitize_key( (string) $row['risk_level'] ) : '',
				'risk_message'              => isset( $row['risk_message'] ) ? sanitize_text_field( (string) $row['risk_message'] ) : '',
				'permission_callback_label' => isset( $row['permission_callback_label'] ) ? sanitize_text_field( (string) $row['permission_callback_label'] ) : '',
				'callback_label'            => isset( $row['callback_label'] ) ? sanitize_text_field( (string) $row['callback_label'] ) : '',
				'source_label'              => isset( $row['source_label'] ) ? sanitize_text_field( (string) $row['source_label'] ) : '',
				'source_category'           => isset( $row['source_category'] ) ? sanitize_key( (string) $row['source_category'] ) : '',
				'tags'                      => isset( $row['tags'] ) && is_array( $row['tags'] ) ? array_values( array_map( 'sanitize_key', $row['tags'] ) ) : array(),
			);
		}

		return array_slice( $clean, 0, self::MAX_SNAPSHOT_ROWS );
	}

	/**
	 * Build compact snapshot rows from current scan.
	 *
	 * @param array<int,array<string,mixed>> $rows Prepared rows.
	 * @return array<int,array<string,mixed>>
	 */
	private function build_snapshot_rows( array $rows ) {
		$compact = array();
		foreach ( array_slice( $rows, 0, self::MAX_SNAPSHOT_ROWS ) as $row ) {
			$item = array(
				'route'                     => (string) ( $row['route'] ?? '' ),
				'methods'                   => isset( $row['methods'] ) && is_array( $row['methods'] ) ? array_values( $row['methods'] ) : array(),
				'namespace'                 => (string) ( $row['namespace'] ?? '' ),
				'risk_level'                => (string) ( $row['risk']['level'] ?? '' ),
				'risk_message'              => (string) ( $row['risk']['message'] ?? '' ),
				'permission_callback_label' => (string) ( $row['permission_callback_label'] ?? '' ),
				'callback_label'            => (string) ( $row['callback_label'] ?? '' ),
				'source_label'              => (string) ( $row['source']['label'] ?? '' ),
				'source_category'           => (string) ( $row['source']['category'] ?? '' ),
				'tags'                      => isset( $row['tags'] ) && is_array( $row['tags'] ) ? array_values( $row['tags'] ) : array(),
			);
			$item['signature'] = $this->snapshot_row_signature( $item );
			$compact[]         = $item;
		}

		return $compact;
	}

	/**
	 * Build route+method signature for compare.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return string
	 */
	private function snapshot_row_signature( array $row ) {
		$methods = isset( $row['methods'] ) && is_array( $row['methods'] ) ? $row['methods'] : array();
		$methods = array_values( array_filter( array_map( 'strtoupper', array_map( 'sanitize_key', $methods ) ) ) );
		sort( $methods );

		return sha1( trim( (string) ( $row['route'] ?? '' ) ) . '|' . implode( ',', $methods ) );
	}

	/**
	 * Build comparison between two saved snapshots.
	 *
	 * @param array<int,array<string,mixed>> $snapshots Snapshots.
	 * @param string                         $a_id Baseline ID.
	 * @param string                         $b_id Compare ID.
	 * @return array<string,mixed>
	 */
	private function build_snapshot_compare( array $snapshots, $a_id, $b_id ) {
		$a = null;
		$b = null;
		foreach ( $snapshots as $snapshot ) {
			if ( hash_equals( (string) $snapshot['id'], (string) $a_id ) ) {
				$a = $snapshot;
			}
			if ( hash_equals( (string) $snapshot['id'], (string) $b_id ) ) {
				$b = $snapshot;
			}
		}

		if ( ! $a || ! $b ) {
			return array( 'valid' => false );
		}

		$a_map = $this->snapshot_rows_by_signature( $a['rows'] );
		$b_map = $this->snapshot_rows_by_signature( $b['rows'] );

		$result = array(
			'valid'              => true,
			'a'                  => $a,
			'b'                  => $b,
			'summary'            => array(),
			'added'              => array(),
			'removed'            => array(),
			'risk_changed'       => array(),
			'permission_changed' => array(),
			'source_changed'     => array(),
		);

		foreach ( $b_map as $signature => $row_b ) {
			if ( ! isset( $a_map[ $signature ] ) ) {
				$result['added'][] = array(
					'route'   => $row_b['route'],
					'methods' => implode( ', ', $row_b['methods'] ),
					'before'  => '—',
					'after'   => $this->snapshot_row_summary( $row_b ),
				);
				continue;
			}

			$row_a = $a_map[ $signature ];
			if ( ( $row_a['risk_level'] ?? '' ) !== ( $row_b['risk_level'] ?? '' ) ) {
				$result['risk_changed'][] = array(
					'route'   => $row_b['route'],
					'methods' => implode( ', ', $row_b['methods'] ),
					'before'  => strtoupper( $row_a['risk_level'] ?? '' ) . ' — ' . ( $row_a['risk_message'] ?? '' ),
					'after'   => strtoupper( $row_b['risk_level'] ?? '' ) . ' — ' . ( $row_b['risk_message'] ?? '' ),
				);
			}

			if ( ( $row_a['permission_callback_label'] ?? '' ) !== ( $row_b['permission_callback_label'] ?? '' ) ) {
				$result['permission_changed'][] = array(
					'route'   => $row_b['route'],
					'methods' => implode( ', ', $row_b['methods'] ),
					'before'  => $row_a['permission_callback_label'] ?? '',
					'after'   => $row_b['permission_callback_label'] ?? '',
				);
			}

			if ( ( $row_a['source_label'] ?? '' ) !== ( $row_b['source_label'] ?? '' ) ) {
				$result['source_changed'][] = array(
					'route'   => $row_b['route'],
					'methods' => implode( ', ', $row_b['methods'] ),
					'before'  => $row_a['source_label'] ?? '',
					'after'   => $row_b['source_label'] ?? '',
				);
			}
		}

		foreach ( $a_map as $signature => $row_a ) {
			if ( isset( $b_map[ $signature ] ) ) {
				continue;
			}
			$result['removed'][] = array(
				'route'   => $row_a['route'],
				'methods' => implode( ', ', $row_a['methods'] ),
				'before'  => $this->snapshot_row_summary( $row_a ),
				'after'   => '—',
			);
		}

		$result['summary'] = array(
			'added'              => count( $result['added'] ),
			'removed'            => count( $result['removed'] ),
			'risk_changed'       => count( $result['risk_changed'] ),
			'permission_changed' => count( $result['permission_changed'] ),
			'source_changed'     => count( $result['source_changed'] ),
		);

		return $result;
	}

	/**
	 * Map snapshot rows by signature.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows.
	 * @return array<string,array<string,mixed>>
	 */
	private function snapshot_rows_by_signature( array $rows ) {
		$map = array();
		foreach ( $rows as $row ) {
			$signature = ! empty( $row['signature'] ) ? $row['signature'] : $this->snapshot_row_signature( $row );
			$map[ $signature ] = $row;
		}

		return $map;
	}

	/**
	 * Compact snapshot row summary.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return string
	 */
	private function snapshot_row_summary( array $row ) {
		return strtoupper( (string) ( $row['risk_level'] ?? '' ) ) . ' · ' . ( $row['permission_callback_label'] ?? '' ) . ' · ' . ( $row['source_label'] ?? '' );
	}

	/**
	 * Render risk explanation card.
	 *
	 * @param array<string,mixed> $row Endpoint row.
	 * @return void
	 */
	private function render_risk_explanation_card( array $row ) {
		$explanation = $this->build_risk_explanation( $row );
		?>
		<div class="rest-radar-detail-card rest-radar-risk-explanation-card">
			<h3><?php echo esc_html__( 'Why this risk?', 'rest-radar' ); ?></h3>
			<p><?php echo esc_html( $explanation['summary'] ); ?></p>

			<div class="rest-radar-risk-columns">
				<div>
					<h4><?php echo esc_html__( 'Evidence', 'rest-radar' ); ?></h4>
					<ul>
						<?php foreach ( $explanation['evidence'] as $item ) : ?>
							<li><?php echo esc_html( $item ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
				<div>
					<h4><?php echo esc_html__( 'Suggested manual tests', 'rest-radar' ); ?></h4>
					<ul>
						<?php foreach ( $explanation['tests'] as $item ) : ?>
							<li><?php echo esc_html( $item ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			</div>

			<?php if ( ! empty( $explanation['false_positive'] ) ) : ?>
				<p class="rest-radar-muted"><strong><?php echo esc_html__( 'False positive notes:', 'rest-radar' ); ?></strong> <?php echo esc_html( $explanation['false_positive'] ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Build risk explanation details for QA review.
	 *
	 * @param array<string,mixed> $row Endpoint row.
	 * @return array<string,mixed>
	 */
	private function build_risk_explanation( array $row ) {
		$level       = (string) ( $row['risk']['level'] ?? 'low' );
		$message     = (string) ( $row['risk']['message'] ?? '' );
		$methods     = isset( $row['methods'] ) && is_array( $row['methods'] ) ? $row['methods'] : array();
		$is_write    = ! empty( $row['is_write'] );
		$permission  = (string) ( $row['permission_callback_label'] ?? '' );
		$tags        = isset( $row['tags'] ) && is_array( $row['tags'] ) ? $row['tags'] : array();
		$route_shape = isset( $row['route_shape'] ) && is_array( $row['route_shape'] ) ? $row['route_shape'] : array();
		$source      = isset( $row['source']['label'] ) ? (string) $row['source']['label'] : __( 'Unknown source', 'rest-radar' );

		$evidence = array();
		$tests    = array();

		$evidence[] = sprintf( __( 'Risk level: %1$s — %2$s', 'rest-radar' ), strtoupper( $level ), $message );
		$evidence[] = sprintf( __( 'Methods: %s', 'rest-radar' ), implode( ', ', $methods ) );
		$evidence[] = sprintf( __( 'Permission callback: %s', 'rest-radar' ), $permission ? $permission : __( 'missing', 'rest-radar' ) );
		$evidence[] = sprintf( __( 'Source: %s', 'rest-radar' ), $source );

		if ( 'missing' === $permission || '' === $permission ) {
			$evidence[] = __( 'The endpoint does not explicitly declare access control.', 'rest-radar' );
			$tests[]    = __( 'Open the route registration and confirm whether permission_callback is missing or dynamically added elsewhere.', 'rest-radar' );
		}

		if ( '__return_true' === $permission ) {
			$evidence[] = __( 'The endpoint is explicitly public via __return_true.', 'rest-radar' );
			$tests[]    = __( 'Call the endpoint as an anonymous visitor and confirm that the response contains only intentionally public data.', 'rest-radar' );
		}

		if ( $is_write ) {
			$evidence[] = __( 'The endpoint accepts write-capable methods.', 'rest-radar' );
			$tests[]    = __( 'Test anonymous, subscriber, editor, and administrator access separately for write actions.', 'rest-radar' );
			$tests[]    = __( 'Verify nonce/authentication, capability checks, object ownership checks, and input validation.', 'rest-radar' );
		}

		if ( ! empty( $tags ) ) {
			$evidence[] = sprintf( __( 'Sensitive-looking tags detected: %s', 'rest-radar' ), implode( ', ', $tags ) );
			$tests[]    = __( 'Review the JSON response for user, auth, settings, file, payment, license, token, or business data.', 'rest-radar' );
		}

		if ( ! empty( $route_shape['has_params'] ) ) {
			$evidence[] = sprintf( __( 'Parameterized route: %d parameter(s).', 'rest-radar' ), absint( $route_shape['param_count'] ?? 0 ) );
			$tests[]    = __( 'Test object-level authorization with IDs that belong to another user/account.', 'rest-radar' );
		}

		if ( empty( $tests ) ) {
			$tests[] = __( 'Keep normal REST API review: verify response data, caching behavior, and expected public/private access.', 'rest-radar' );
		}

		$false_positive = '';
		if ( in_array( $level, array( 'public', 'low' ), true ) ) {
			$false_positive = __( 'Public read endpoints can be valid when they return only intentionally public data.', 'rest-radar' );
		} elseif ( '__return_true' === $permission && ! $is_write ) {
			$false_positive = __( 'A public GET route may be acceptable for public content, but the returned fields still need review.', 'rest-radar' );
		} else {
			$false_positive = __( 'Some frameworks wrap authorization outside permission_callback; verify in source before filing a final bug.', 'rest-radar' );
		}

		return array(
			'summary'        => sprintf( __( 'REST Radar classified this endpoint as %s based on permission callback, HTTP methods, route shape, and sensitive keyword signals.', 'rest-radar' ), strtoupper( $level ) ),
			'evidence'       => array_values( array_unique( $evidence ) ),
			'tests'          => array_values( array_unique( $tests ) ),
			'false_positive' => $false_positive,
		);
	}


	/**
	 * Find a row by its stable key.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows.
	 * @param string                         $key Row key.
	 * @return array<string,mixed>|null
	 */
	private function find_row_by_key( array $rows, $key ) {
		$key = sanitize_text_field( (string) $key );
		foreach ( $rows as $row ) {
			if ( isset( $row['key'] ) && hash_equals( (string) $row['key'], $key ) ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * Render endpoint detail panel.
	 *
	 * @param array<string,mixed> $row Endpoint row.
	 * @param array<string,mixed>|null $probe_result Optional probe result.
	 * @return void
	 */
	private function render_endpoint_details( array $row, $probe_result ) {
		$close_url = remove_query_arg( array( 'rr_detail', 'rr_probe', '_wpnonce' ) );
		$rest_url  = $this->build_rest_url_for_row( $row );
		?>
		<div class="rest-radar-detail-panel">
			<div class="rest-radar-detail-heading">
				<div>
					<h2><?php echo esc_html__( 'Endpoint details', 'rest-radar' ); ?></h2>
					<p><code><?php echo esc_html( $row['route'] ?? '' ); ?></code></p>
				</div>
				<a href="<?php echo esc_url( $close_url ); ?>" class="button button-secondary"><?php echo esc_html__( 'Close details', 'rest-radar' ); ?></a>
			</div>

			<div class="rest-radar-detail-grid">
				<div class="rest-radar-detail-card">
					<h3><?php echo esc_html__( 'Summary', 'rest-radar' ); ?></h3>
					<dl>
						<dt><?php echo esc_html__( 'Risk', 'rest-radar' ); ?></dt>
						<dd><?php $this->render_risk_badge( $row['risk'] ); ?> <span><?php echo esc_html( $row['risk']['message'] ?? '' ); ?></span></dd>
						<dt><?php echo esc_html__( 'Methods', 'rest-radar' ); ?></dt>
						<dd><code><?php echo esc_html( implode( ', ', $row['methods'] ?? array() ) ); ?></code></dd>
						<dt><?php echo esc_html__( 'Source', 'rest-radar' ); ?></dt>
						<dd><?php echo esc_html( $row['source']['label'] ?? __( 'Unknown', 'rest-radar' ) ); ?></dd>
						<dt><?php echo esc_html__( 'Namespace', 'rest-radar' ); ?></dt>
						<dd><code><?php echo esc_html( $row['namespace'] ?? '' ); ?></code></dd>
						<dt><?php echo esc_html__( 'REST URL', 'rest-radar' ); ?></dt>
						<dd><code><?php echo esc_html( $rest_url ); ?></code></dd>
					</dl>
				</div>

				<div class="rest-radar-detail-card">
					<h3><?php echo esc_html__( 'Callbacks', 'rest-radar' ); ?></h3>
					<dl>
						<dt><?php echo esc_html__( 'Permission callback', 'rest-radar' ); ?></dt>
						<dd><code><?php echo esc_html( $row['permission_callback_label'] ?? '' ); ?></code></dd>
						<?php if ( ! empty( $row['permission_callback_source'] ) ) : ?>
							<dt><?php echo esc_html__( 'Permission source', 'rest-radar' ); ?></dt>
							<dd><code><?php echo esc_html( $row['permission_callback_source'] ); ?></code></dd>
						<?php endif; ?>
						<dt><?php echo esc_html__( 'Main callback', 'rest-radar' ); ?></dt>
						<dd><code><?php echo esc_html( $row['callback_label'] ?? '' ); ?></code></dd>
						<?php if ( ! empty( $row['callback_source'] ) ) : ?>
							<dt><?php echo esc_html__( 'Main source', 'rest-radar' ); ?></dt>
							<dd><code><?php echo esc_html( $row['callback_source'] ); ?></code></dd>
						<?php endif; ?>
					</dl>
				</div>

				<div class="rest-radar-detail-card">
					<h3><?php echo esc_html__( 'Review notes', 'rest-radar' ); ?></h3>
					<p><strong><?php echo esc_html__( 'Recommended action:', 'rest-radar' ); ?></strong> <?php echo esc_html( $row['recommendation'] ?? '' ); ?></p>
					<p><strong><?php echo esc_html__( 'Tags:', 'rest-radar' ); ?></strong> <?php $this->render_tags( $row['tags'] ?? array() ); ?></p>
					<?php if ( ! empty( $row['route_shape']['has_params'] ) ) : ?>
						<p><?php echo esc_html( sprintf( __( 'This route has %d parameter(s). REST Radar will not auto-probe parameterized routes in this version.', 'rest-radar' ), absint( $row['route_shape']['param_count'] ?? 0 ) ) ); ?></p>
					<?php endif; ?>
				</div>

				<?php $this->render_endpoint_review_card( $row ); ?>

				<?php $this->render_risk_explanation_card( $row ); ?>

				<div class="rest-radar-detail-card rest-radar-shield-card">
					<h3><?php echo esc_html__( 'Protect this endpoint', 'rest-radar' ); ?></h3>
					<p><?php echo esc_html__( 'Add a non-destructive Endpoint Shield rule. REST Radar will not edit the source plugin or theme file; it blocks matching REST requests before the endpoint callback runs.', 'rest-radar' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rest-radar-protect-form">
						<input type="hidden" name="action" value="rest_radar_add_shield_rule" />
						<input type="hidden" name="return_key" value="<?php echo esc_attr( $row['key'] ?? '' ); ?>" />
						<?php wp_nonce_field( 'rest_radar_add_shield_rule' ); ?>
						<label><strong><?php echo esc_html__( 'Route pattern', 'rest-radar' ); ?></strong></label>
						<input type="text" class="regular-text code" name="pattern" value="<?php echo esc_attr( $row['route'] ?? '' ); ?>" />
						<label><strong><?php echo esc_html__( 'Methods', 'rest-radar' ); ?></strong></label>
						<input type="text" class="regular-text code" name="methods" value="<?php echo esc_attr( implode( ',', $row['methods'] ?? array( 'ANY' ) ) ); ?>" />
						<label><strong><?php echo esc_html__( 'Protection mode', 'rest-radar' ); ?></strong></label>
						<select name="mode">
							<?php $suggested_mode = $this->suggest_shield_mode( $row ); ?>
							<?php foreach ( array( 'block_guests', 'require_login', 'admins_only', 'capability', 'disable_route' ) as $mode ) : ?>
								<option value="<?php echo esc_attr( $mode ); ?>" <?php selected( $suggested_mode, $mode ); ?>><?php echo esc_html( Rest_Radar_Shield::mode_label( $mode ) ); ?></option>
							<?php endforeach; ?>
						</select>
						<label><strong><?php echo esc_html__( 'Capability for capability mode', 'rest-radar' ); ?></strong></label>
						<input type="text" class="regular-text code" name="capability" value="manage_options" />
						<input type="hidden" name="note" value="<?php echo esc_attr( 'Created from REST Radar detail view: ' . ( $row['risk']['message'] ?? '' ) ); ?>" />
						<button type="submit" class="button button-primary"><?php echo esc_html__( 'Add shield rule', 'rest-radar' ); ?></button>
					</form>
				</div>

				<div class="rest-radar-detail-card rest-radar-fix-card">
					<div class="rest-radar-snippet-header">
						<div>
							<h3><?php echo esc_html__( 'Developer fix snippet', 'rest-radar' ); ?></h3>
							<p><?php echo esc_html__( 'Use this as a review starting point in the source plugin/theme. It is not applied automatically.', 'rest-radar' ); ?></p>
						</div>
						<div class="rest-radar-snippet-toolbar" aria-label="<?php echo esc_attr__( 'Developer snippet actions', 'rest-radar' ); ?>">
							<button type="button" class="button rest-radar-snippet-button rest-radar-copy-button" data-target="rest-radar-fix-snippet"><?php echo esc_html__( 'Copy', 'rest-radar' ); ?></button>
							<button type="button" class="button rest-radar-snippet-button rest-radar-expand-button" data-target="rest-radar-fix-snippet" data-expand-label="<?php echo esc_attr__( 'Expand', 'rest-radar' ); ?>" data-collapse-label="<?php echo esc_attr__( 'Collapse', 'rest-radar' ); ?>" aria-expanded="false"><?php echo esc_html__( 'Expand', 'rest-radar' ); ?></button>
						</div>
					</div>
					<pre id="rest-radar-fix-snippet" class="rest-radar-snippet-code rest-radar-fix-snippet rest-radar-snippet-area" tabindex="0"><code><?php echo esc_html( $this->build_developer_fix_snippet( $row ) ); ?></code></pre>
				</div>

				<div class="rest-radar-detail-card rest-radar-qa-card">
					<div class="rest-radar-snippet-header">
						<div>
							<h3><?php echo esc_html__( 'QA bug report draft', 'rest-radar' ); ?></h3>
							<p><?php echo esc_html__( 'Copy this into Jira, GitHub Issues, Trello, Upwork evidence, or a manual QA report. It is generated from the current endpoint metadata; verify it before submitting.', 'rest-radar' ); ?></p>
						</div>
						<div class="rest-radar-snippet-toolbar" aria-label="<?php echo esc_attr__( 'QA draft actions', 'rest-radar' ); ?>">
							<button type="button" class="button rest-radar-snippet-button rest-radar-copy-button" data-target="rest-radar-qa-ticket"><?php echo esc_html__( 'Copy', 'rest-radar' ); ?></button>
							<button type="button" class="button rest-radar-snippet-button rest-radar-expand-button" data-target="rest-radar-qa-ticket" data-expand-label="<?php echo esc_attr__( 'Expand', 'rest-radar' ); ?>" data-collapse-label="<?php echo esc_attr__( 'Collapse', 'rest-radar' ); ?>" aria-expanded="false"><?php echo esc_html__( 'Expand', 'rest-radar' ); ?></button>
						</div>
					</div>
					<pre id="rest-radar-qa-ticket" class="rest-radar-snippet-code rest-radar-qa-ticket rest-radar-snippet-area" tabindex="0"><code><?php echo esc_html( $this->build_qa_ticket_text( $row ) ); ?></code></pre>
				</div>

				<div class="rest-radar-detail-card">
					<h3><?php echo esc_html__( 'Safe GET probe', 'rest-radar' ); ?></h3>
					<p><?php echo esc_html__( 'The probe performs one anonymous GET request against the site REST URL. It never calls POST, PUT, PATCH, or DELETE endpoints.', 'rest-radar' ); ?></p>
					<?php if ( ! empty( $row['can_probe_get'] ) ) : ?>
						<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'rr_probe' => $row['key'] ), remove_query_arg( array( 'rr_detail', 'rr_probe', '_wpnonce' ) ) ), 'rest_radar_probe_' . $row['key'] ) ); ?>" class="button button-primary"><?php echo esc_html__( 'Run GET probe', 'rest-radar' ); ?></a>
					<?php else : ?>
						<p class="rest-radar-muted"><?php echo esc_html__( 'Probe is unavailable for this endpoint because it is not a simple non-parameterized GET route.', 'rest-radar' ); ?></p>
					<?php endif; ?>

					<?php if ( is_array( $probe_result ) ) : ?>
						<?php $this->render_probe_result( $probe_result ); ?>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the endpoint review decision form.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return void
	 */
	private function render_endpoint_review_card( array $row ) {
		$review     = isset( $row['review'] ) && is_array( $row['review'] ) ? $row['review'] : array();
		$status     = isset( $review['status'] ) ? (string) $review['status'] : 'new';
		$override   = isset( $review['severity_override'] ) ? (string) $review['severity_override'] : '';
		$note       = isset( $review['note'] ) ? (string) $review['note'] : '';
		$updated_at = isset( $review['updated_at'] ) ? (string) $review['updated_at'] : '';
		$reviewer   = isset( $review['reviewer'] ) ? (string) $review['reviewer'] : '';
		?>
		<div class="rest-radar-detail-card rest-radar-review-card">
			<h3><?php echo esc_html__( 'Endpoint review decision', 'rest-radar' ); ?></h3>
			<p><?php echo esc_html__( 'Record the human review result so scanner output becomes reusable QA evidence instead of a one-time warning list.', 'rest-radar' ); ?></p>

			<div class="rest-radar-review-current">
				<?php $this->render_review_badge( $review ); ?>
				<?php if ( ! empty( $row['shield_rule_state'] ) && 'active' === $row['shield_rule_state'] ) : ?>
					<span class="rest-radar-review-badge rest-radar-review-shield-rule"><?php echo esc_html__( 'Shield rule active', 'rest-radar' ); ?></span>
				<?php endif; ?>
			</div>

			<?php if ( ! empty( $review['auto_retest'] ) ) : ?>
				<div class="notice notice-warning inline">
					<p><?php echo esc_html__( 'This endpoint changed after a previous review decision. Retest before trusting the old decision.', 'rest-radar' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rest-radar-review-form">
				<input type="hidden" name="action" value="rest_radar_save_endpoint_review" />
				<input type="hidden" name="row_key" value="<?php echo esc_attr( $row['key'] ?? '' ); ?>" />
				<input type="hidden" name="route" value="<?php echo esc_attr( $row['route'] ?? '' ); ?>" />
				<input type="hidden" name="methods" value="<?php echo esc_attr( implode( ',', $row['methods'] ?? array() ) ); ?>" />
				<input type="hidden" name="fingerprint" value="<?php echo esc_attr( $this->build_endpoint_fingerprint( $row ) ); ?>" />
				<?php wp_nonce_field( 'rest_radar_save_endpoint_review_' . ( $row['key'] ?? '' ) ); ?>

				<label for="rest-radar-review-status"><strong><?php echo esc_html__( 'Review status', 'rest-radar' ); ?></strong></label>
				<select id="rest-radar-review-status" name="review_status">
					<?php foreach ( $this->review_statuses() as $status_key => $status_label ) : ?>
						<option value="<?php echo esc_attr( $status_key ); ?>" <?php selected( $status, $status_key ); ?>><?php echo esc_html( $status_label ); ?></option>
					<?php endforeach; ?>
				</select>

				<label for="rest-radar-severity-override"><strong><?php echo esc_html__( 'Severity override', 'rest-radar' ); ?></strong></label>
				<select id="rest-radar-severity-override" name="severity_override">
					<option value=""><?php echo esc_html__( 'No manual override', 'rest-radar' ); ?></option>
					<?php foreach ( $this->severity_override_options() as $severity_key => $severity_label ) : ?>
						<option value="<?php echo esc_attr( $severity_key ); ?>" <?php selected( $override, $severity_key ); ?>><?php echo esc_html( $severity_label ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php echo esc_html__( 'A severity override requires a reviewer note. The original scanner severity is kept in exports.', 'rest-radar' ); ?></p>

				<label for="rest-radar-review-note"><strong><?php echo esc_html__( 'Reviewer note', 'rest-radar' ); ?></strong></label>
				<textarea id="rest-radar-review-note" name="review_note" rows="5" placeholder="<?php echo esc_attr__( 'Why this endpoint is accepted, false positive, shielded, or needs a fix...', 'rest-radar' ); ?>"><?php echo esc_textarea( $note ); ?></textarea>

				<?php if ( $updated_at ) : ?>
					<p class="description"><?php echo esc_html( sprintf( __( 'Last reviewed: %1$s by %2$s', 'rest-radar' ), $updated_at, $reviewer ? $reviewer : __( 'unknown reviewer', 'rest-radar' ) ) ); ?></p>
				<?php endif; ?>

				<button type="submit" class="button button-primary"><?php echo esc_html__( 'Save review decision', 'rest-radar' ); ?></button>
			</form>
		</div>
		<?php
	}

	/**
	 * Render result of a GET probe.
	 *
	 * @param array<string,mixed> $result Probe result.
	 * @return void
	 */
	private function render_probe_result( array $result ) {
		?>
		<div class="rest-radar-probe-result rest-radar-probe-<?php echo esc_attr( sanitize_html_class( $result['status'] ?? 'unknown' ) ); ?>">
			<h4><?php echo esc_html__( 'Probe result', 'rest-radar' ); ?></h4>
			<?php if ( ! empty( $result['message'] ) ) : ?>
				<p><?php echo esc_html( $result['message'] ); ?></p>
			<?php endif; ?>
			<?php if ( isset( $result['http_code'] ) ) : ?>
				<ul>
					<li><?php echo esc_html( sprintf( __( 'HTTP status: %s', 'rest-radar' ), (string) $result['http_code'] ) ); ?></li>
					<li><?php echo esc_html( sprintf( __( 'Content-Type: %s', 'rest-radar' ), (string) ( $result['content_type'] ?? 'unknown' ) ) ); ?></li>
					<li><?php echo esc_html( sprintf( __( 'Body size: %s bytes', 'rest-radar' ), (string) absint( $result['body_size'] ?? 0 ) ) ); ?></li>
					<li><?php echo esc_html( sprintf( __( 'Time: %s ms', 'rest-radar' ), (string) absint( $result['elapsed_ms'] ?? 0 ) ) ); ?></li>
				</ul>
			<?php endif; ?>
			<?php if ( ! empty( $result['json_summary'] ) ) : ?>
				<p><strong><?php echo esc_html__( 'JSON summary:', 'rest-radar' ); ?></strong> <?php echo esc_html( $result['json_summary'] ); ?></p>
			<?php endif; ?>
			<?php if ( ! empty( $result['preview'] ) ) : ?>
				<pre><?php echo esc_html( $result['preview'] ); ?></pre>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render row actions.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return void
	 */
	private function render_row_actions( array $row ) {
		if ( empty( $row['key'] ) ) {
			return;
		}

		$base_url   = remove_query_arg( array( 'rr_detail', 'rr_probe', '_wpnonce' ) );
		$detail_url = add_query_arg( array( 'rr_detail' => $row['key'] ), $base_url );
		$probe_url  = wp_nonce_url( add_query_arg( array( 'rr_probe' => $row['key'] ), $base_url ), 'rest_radar_probe_' . $row['key'] );
		?>
		<a href="<?php echo esc_url( $detail_url ); ?>" class="button button-small"><?php echo esc_html__( 'Details', 'rest-radar' ); ?></a>
		<?php if ( ! empty( $row['can_probe_get'] ) ) : ?>
			<a href="<?php echo esc_url( $probe_url ); ?>" class="button button-small"><?php echo esc_html__( 'GET probe', 'rest-radar' ); ?></a>
		<?php endif; ?>
		<?php
	}

	/**
	 * Build REST URL for a row.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return string
	 */
	private function build_rest_url_for_row( array $row ) {
		$route = isset( $row['route'] ) ? (string) $row['route'] : '';
		return rest_url( ltrim( $route, '/' ) );
	}

	/**
	 * Perform a safe anonymous GET probe against a simple endpoint.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	private function probe_endpoint( array $row ) {
		if ( empty( $row['can_probe_get'] ) ) {
			return array(
				'status'  => 'skipped',
				'message' => __( 'Probe skipped. REST Radar only probes simple, non-parameterized GET endpoints.', 'rest-radar' ),
			);
		}

		$url   = $this->build_rest_url_for_row( $row );
		$start = microtime( true );
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'     => 8,
				'redirection' => 0,
				'user-agent' => 'REST Radar/' . REST_RADAR_VERSION . '; ' . home_url( '/' ),
				'headers'    => array(
					'Accept' => 'application/json',
				),
			)
		);
		$elapsed_ms = (int) round( ( microtime( true ) - $start ) * 1000 );

		if ( is_wp_error( $response ) ) {
			return array(
				'status'  => 'error',
				'message' => $response->get_error_message(),
			);
		}

		$body         = (string) wp_remote_retrieve_body( $response );
		$content_type = (string) wp_remote_retrieve_header( $response, 'content-type' );
		$http_code    = (int) wp_remote_retrieve_response_code( $response );
		$preview      = function_exists( 'mb_substr' ) ? mb_substr( $body, 0, 1600 ) : substr( $body, 0, 1600 );
		if ( strlen( $body ) > strlen( $preview ) ) {
			$preview .= "\n...";
		}

		return array(
			'status'       => $http_code >= 200 && $http_code < 400 ? 'ok' : 'warning',
			'message'      => __( 'Anonymous GET probe completed.', 'rest-radar' ),
			'http_code'    => $http_code,
			'content_type' => $content_type ? $content_type : __( 'unknown', 'rest-radar' ),
			'body_size'    => strlen( $body ),
			'elapsed_ms'   => $elapsed_ms,
			'json_summary' => $this->summarize_json_body( $body ),
			'preview'      => $preview,
		);
	}

	/**
	 * Summarize JSON body without dumping full content.
	 *
	 * @param string $body Body.
	 * @return string
	 */
	private function summarize_json_body( $body ) {
		$decoded = json_decode( $body, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return '';
		}

		if ( is_array( $decoded ) ) {
			$count   = count( $decoded );
			$is_list = 0 === $count || array_keys( $decoded ) === range( 0, $count - 1 );
			if ( $is_list ) {
				$keys  = array();
				if ( isset( $decoded[0] ) && is_array( $decoded[0] ) ) {
					$keys = array_slice( array_map( 'strval', array_keys( $decoded[0] ) ), 0, 10 );
				}

				return $keys ? sprintf( __( 'Array response: %1$d item(s). First item keys: %2$s', 'rest-radar' ), $count, implode( ', ', $keys ) ) : sprintf( __( 'Array response: %d item(s).', 'rest-radar' ), $count );
			}

			$keys = array_slice( array_map( 'strval', array_keys( $decoded ) ), 0, 15 );
			return sprintf( __( 'Object response keys: %s', 'rest-radar' ), implode( ', ', $keys ) );
		}

		return __( 'Valid JSON scalar response.', 'rest-radar' );
	}

	/**
	 * Render optimized admin dashboard overview.
	 *
	 * @param array<int,array<string,mixed>> $prepared_rows All prepared rows.
	 * @param array<int,array<string,mixed>> $filtered_rows Filtered rows.
	 * @param array<string,int>              $stats Scan statistics.
	 * @param array<string,mixed>            $shield_options Shield options.
	 * @param array<int,array<string,mixed>> $snapshots Saved snapshots.
	 * @param string                         $export_url CSV export URL.
	 * @param string                         $markdown_url Markdown export URL.
	 * @return void
	 */
	private function render_admin_dashboard_overview( array $prepared_rows, array $filtered_rows, array $stats, array $shield_options, array $snapshots, $export_url, $markdown_url ) {
		$total             = count( $prepared_rows );
		$filtered_count    = count( $filtered_rows );
		$critical_count    = absint( $stats['critical'] ?? 0 );
		$high_count        = absint( $stats['high'] ?? 0 );
		$review_count      = absint( $stats['medium'] ?? 0 );
		$public_count      = absint( $stats['public'] ?? 0 );
		$low_count         = absint( $stats['low'] ?? 0 );
		$ignored_count     = absint( $stats['ignored'] ?? 0 );
		$needs_count       = absint( $stats['review_needs_review'] ?? 0 );
		$fix_count         = absint( $stats['review_fix_required'] ?? 0 );
		$retest_count      = absint( $stats['review_retest_required'] ?? 0 );
		$shielded_count    = absint( $stats['review_shielded'] ?? 0 );
		$unreviewed_count  = absint( $stats['review_unreviewed'] ?? 0 );
		$reviewed_count    = max( 0, $total - $unreviewed_count );
		$review_progress   = $total > 0 ? (int) round( ( $reviewed_count / $total ) * 100 ) : 100;
		$shield_enabled    = ! empty( $shield_options['enabled'] );
		$rule_count        = ! empty( $shield_options['rules'] ) && is_array( $shield_options['rules'] ) ? count( $shield_options['rules'] ) : 0;
		$snapshot_count    = count( $snapshots );
		$latest_snapshot   = ! empty( $snapshots[0]['label'] ) ? (string) $snapshots[0]['label'] : __( 'No snapshot yet', 'rest-radar' );
		$base_url          = admin_url( 'tools.php?page=rest-radar' );
		$priority_rows     = array_filter(
			$prepared_rows,
			static function ( $row ) {
				$level  = isset( $row['risk']['level'] ) ? (string) $row['risk']['level'] : 'low';
				$status = isset( $row['review']['status'] ) ? (string) $row['review']['status'] : 'new';
				return in_array( $level, array( 'critical', 'high' ), true ) || in_array( $status, array( 'fix_required', 'retest_required' ), true );
			}
		);
		$priority_count    = count( $priority_rows );
		$priority_tone     = $critical_count || $fix_count ? 'is-danger' : ( $priority_count ? 'is-warning' : 'is-good' );
		$priority_message  = $priority_count
			? __( 'Prioritize these before exporting or accepting the current endpoint surface.', 'rest-radar' )
			: __( 'No critical, high, fix-required, or retest-required endpoints are currently in the priority queue.', 'rest-radar' );
		$review_status_url = add_query_arg( 'review', 'unreviewed', $base_url );
		$critical_url      = add_query_arg( 'risk', 'critical', $base_url );
		$high_url          = add_query_arg( 'risk', 'high', $base_url );
		$fix_url           = add_query_arg( 'review', 'fix_required', $base_url );
		$retest_url        = add_query_arg( 'review', 'retest_required', $base_url );
		$shield_url        = add_query_arg( 'review', 'has_shield_rule', $base_url );
		?>
		<section class="rest-radar-admin-dashboard" aria-label="<?php echo esc_attr__( 'REST Radar dashboard overview', 'rest-radar' ); ?>">
			<div class="rest-radar-admin-dashboard-head">
				<div>
					<div class="rest-radar-kicker"><?php echo esc_html__( 'Operational dashboard', 'rest-radar' ); ?></div>
					<h2><?php echo esc_html__( 'Review workload and endpoint risk at a glance', 'rest-radar' ); ?></h2>
					<p><?php echo esc_html__( 'The dashboard now separates immediate action, review progress, Shield state, and scan scope so the next decision is visible without reading the full table first.', 'rest-radar' ); ?></p>
				</div>
				<div class="rest-radar-admin-dashboard-actions">
					<a href="<?php echo esc_url( $review_status_url ); ?>" class="button button-primary"><?php echo esc_html__( 'Review queue', 'rest-radar' ); ?></a>
					<a href="<?php echo esc_url( $export_url ); ?>" class="button button-secondary"><?php echo esc_html__( 'CSV', 'rest-radar' ); ?></a>
					<a href="<?php echo esc_url( $markdown_url ); ?>" class="button button-secondary"><?php echo esc_html__( 'QA Markdown', 'rest-radar' ); ?></a>
				</div>
			</div>

			<div class="rest-radar-admin-dashboard-grid">
				<div class="rest-radar-priority-card <?php echo esc_attr( $priority_tone ); ?>">
					<div class="rest-radar-card-eyebrow"><?php echo esc_html__( 'Priority queue', 'rest-radar' ); ?></div>
					<div class="rest-radar-priority-number"><?php echo esc_html( (string) absint( $priority_count ) ); ?></div>
					<p><?php echo esc_html( $priority_message ); ?></p>
					<div class="rest-radar-priority-split">
						<a href="<?php echo esc_url( $critical_url ); ?>"><strong><?php echo esc_html( (string) $critical_count ); ?></strong><span><?php echo esc_html__( 'Critical', 'rest-radar' ); ?></span></a>
						<a href="<?php echo esc_url( $high_url ); ?>"><strong><?php echo esc_html( (string) $high_count ); ?></strong><span><?php echo esc_html__( 'High', 'rest-radar' ); ?></span></a>
						<a href="<?php echo esc_url( $fix_url ); ?>"><strong><?php echo esc_html( (string) $fix_count ); ?></strong><span><?php echo esc_html__( 'Fix', 'rest-radar' ); ?></span></a>
						<a href="<?php echo esc_url( $retest_url ); ?>"><strong><?php echo esc_html( (string) $retest_count ); ?></strong><span><?php echo esc_html__( 'Retest', 'rest-radar' ); ?></span></a>
					</div>
				</div>

				<div class="rest-radar-workload-card">
					<div class="rest-radar-card-eyebrow"><?php echo esc_html__( 'Review progress', 'rest-radar' ); ?></div>
					<div class="rest-radar-progress-heading">
						<strong><?php echo esc_html( (string) absint( $review_progress ) ); ?>%</strong>
						<span><?php echo esc_html__( 'triaged', 'rest-radar' ); ?></span>
					</div>
					<div class="rest-radar-progress-bar" aria-hidden="true"><span style="width: <?php echo esc_attr( (string) absint( $review_progress ) ); ?>%;"></span></div>
					<div class="rest-radar-workload-list">
						<a href="<?php echo esc_url( $review_status_url ); ?>"><span><?php echo esc_html__( 'Unreviewed', 'rest-radar' ); ?></span><strong><?php echo esc_html( (string) $unreviewed_count ); ?></strong></a>
						<a href="<?php echo esc_url( add_query_arg( 'review', 'needs_review', $base_url ) ); ?>"><span><?php echo esc_html__( 'Needs review', 'rest-radar' ); ?></span><strong><?php echo esc_html( (string) $needs_count ); ?></strong></a>
						<a href="<?php echo esc_url( add_query_arg( 'review', 'shielded', $base_url ) ); ?>"><span><?php echo esc_html__( 'Shielded decisions', 'rest-radar' ); ?></span><strong><?php echo esc_html( (string) $shielded_count ); ?></strong></a>
					</div>
				</div>

				<div class="rest-radar-system-card">
					<div class="rest-radar-card-eyebrow"><?php echo esc_html__( 'System state', 'rest-radar' ); ?></div>
					<div class="rest-radar-system-state">
						<span class="rest-radar-dashboard-pill <?php echo $shield_enabled ? 'is-on' : 'is-off'; ?>"><?php echo esc_html( $shield_enabled ? __( 'Shield ON', 'rest-radar' ) : __( 'Shield OFF', 'rest-radar' ) ); ?></span>
						<a href="<?php echo esc_url( $shield_url ); ?>"><?php echo esc_html( sprintf( _n( '%d active rule', '%d active rules', absint( $rule_count ), 'rest-radar' ), absint( $rule_count ) ) ); ?></a>
					</div>
					<div class="rest-radar-system-details">
						<div><span><?php echo esc_html__( 'Snapshots', 'rest-radar' ); ?></span><strong><?php echo esc_html( (string) absint( $snapshot_count ) ); ?></strong></div>
						<div><span><?php echo esc_html__( 'Latest', 'rest-radar' ); ?></span><strong><?php echo esc_html( $latest_snapshot ); ?></strong></div>
						<div><span><?php echo esc_html__( 'Filtered rows', 'rest-radar' ); ?></span><strong><?php echo esc_html( (string) absint( $filtered_count ) ); ?></strong></div>
					</div>
				</div>
			</div>

			<div class="rest-radar-metric-strip" aria-label="<?php echo esc_attr__( 'Scan scope metrics', 'rest-radar' ); ?>">
				<?php $this->render_dashboard_strip_metric( __( 'Total routes', 'rest-radar' ), $total, $base_url, 'total' ); ?>
				<?php $this->render_dashboard_strip_metric( __( 'Review risk', 'rest-radar' ), $review_count, add_query_arg( 'risk', 'medium', $base_url ), 'review' ); ?>
				<?php $this->render_dashboard_strip_metric( __( 'Public', 'rest-radar' ), $public_count, add_query_arg( 'risk', 'public', $base_url ), 'public' ); ?>
				<?php $this->render_dashboard_strip_metric( __( 'Low', 'rest-radar' ), $low_count, add_query_arg( 'risk', 'low', $base_url ), 'low' ); ?>
				<?php $this->render_dashboard_strip_metric( __( 'Ignored', 'rest-radar' ), $ignored_count, $base_url, 'ignored' ); ?>
			</div>
		</section>
		<?php
	}

	/**
	 * Render dashboard strip metric link.
	 *
	 * @param string $label Metric label.
	 * @param int    $count Metric count.
	 * @param string $url Link URL.
	 * @param string $tone Tone class.
	 * @return void
	 */
	private function render_dashboard_strip_metric( $label, $count, $url, $tone ) {
		?>
		<a class="rest-radar-strip-metric rest-radar-strip-metric-<?php echo esc_attr( sanitize_html_class( $tone ) ); ?>" href="<?php echo esc_url( $url ); ?>">
			<strong><?php echo esc_html( (string) absint( $count ) ); ?></strong>
			<span><?php echo esc_html( $label ); ?></span>
		</a>
		<?php
	}

	/**
	 * Render stat card.
	 *
	 * @param string $label Label.
	 * @param int    $count Count.
	 * @param string $level Level class.
	 * @return void
	 */
	private function render_stat_card( $label, $count, $level ) {
		?>
		<div class="rest-radar-card rest-radar-card-<?php echo esc_attr( $level ); ?>">
			<div class="rest-radar-card-count"><?php echo esc_html( (string) absint( $count ) ); ?></div>
			<div class="rest-radar-card-label"><?php echo esc_html( $label ); ?></div>
		</div>
		<?php
	}

	/**
	 * Render risk badge.
	 *
	 * @param array<string,string> $risk Risk data.
	 * @return void
	 */
	private function render_risk_badge( array $risk ) {
		$level = isset( $risk['level'] ) ? sanitize_html_class( $risk['level'] ) : 'low';
		$label = isset( $risk['label'] ) ? $risk['label'] : __( 'Low', 'rest-radar' );
		?>
		<span class="rest-radar-badge rest-radar-badge-<?php echo esc_attr( $level ); ?>"><?php echo esc_html( $label ); ?></span>
		<?php
	}

	/**
	 * Render tags.
	 *
	 * @param array<int,string> $tags Tags.
	 * @return void
	 */
	private function render_tags( array $tags ) {
		if ( empty( $tags ) ) {
			echo '<span class="rest-radar-muted">' . esc_html__( 'None', 'rest-radar' ) . '</span>';
			return;
		}

		foreach ( $tags as $tag ) {
			echo '<span class="rest-radar-tag">' . esc_html( $tag ) . '</span> ';
		}
	}

	/**
	 * Get available review statuses.
	 *
	 * @return array<string,string>
	 */
	private function review_statuses() {
		return array(
			'new'             => __( 'New', 'rest-radar' ),
			'needs_review'    => __( 'Needs review', 'rest-radar' ),
			'accepted_public' => __( 'Accepted public', 'rest-radar' ),
			'false_positive'  => __( 'False positive', 'rest-radar' ),
			'fix_required'    => __( 'Fix required', 'rest-radar' ),
			'shielded'        => __( 'Shielded', 'rest-radar' ),
			'retest_required' => __( 'Retest required', 'rest-radar' ),
		);
	}

	/**
	 * Get severity override choices.
	 *
	 * @return array<string,string>
	 */
	private function severity_override_options() {
		return array(
			'critical' => __( 'Critical', 'rest-radar' ),
			'high'     => __( 'High', 'rest-radar' ),
			'medium'   => __( 'Review', 'rest-radar' ),
			'public'   => __( 'Public', 'rest-radar' ),
			'low'      => __( 'Low', 'rest-radar' ),
		);
	}

	/**
	 * Render review status badge.
	 *
	 * @param array<string,mixed> $review Review.
	 * @return void
	 */
	private function render_review_badge( array $review ) {
		$status = isset( $review['status'] ) ? (string) $review['status'] : 'new';
		$label  = $this->review_status_label( $status );
		?>
		<span class="rest-radar-review-badge rest-radar-review-<?php echo esc_attr( sanitize_html_class( $status ) ); ?>"><?php echo esc_html( $label ); ?></span>
		<?php
	}

	/**
	 * Return review status label.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	private function review_status_label( $status ) {
		$statuses = $this->review_statuses();
		return isset( $statuses[ $status ] ) ? $statuses[ $status ] : $statuses['new'];
	}

	/**
	 * Return saved endpoint reviews.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function get_endpoint_reviews() {
		$reviews = get_option( self::REVIEW_OPTION_NAME, array() );
		if ( ! is_array( $reviews ) ) {
			return array();
		}

		$clean = array();
		foreach ( $reviews as $key => $review ) {
			if ( ! is_string( $key ) || ! is_array( $review ) ) {
				continue;
			}

			$key = sanitize_key( $key );
			if ( '' === $key ) {
				continue;
			}

			$clean[ $key ] = $this->sanitize_endpoint_review( $review );
		}

		return $clean;
	}

	/**
	 * Sanitize one endpoint review.
	 *
	 * @param array<string,mixed> $review Review.
	 * @return array<string,mixed>
	 */
	private function sanitize_endpoint_review( array $review ) {
		$statuses   = array_keys( $this->review_statuses() );
		$overrides  = array_keys( $this->severity_override_options() );
		$status     = isset( $review['status'] ) ? sanitize_key( (string) $review['status'] ) : 'new';
		$override   = isset( $review['severity_override'] ) ? sanitize_key( (string) $review['severity_override'] ) : '';

		if ( ! in_array( $status, $statuses, true ) ) {
			$status = 'new';
		}

		if ( ! in_array( $override, $overrides, true ) ) {
			$override = '';
		}

		return array(
			'status'            => $status,
			'note'              => isset( $review['note'] ) ? sanitize_textarea_field( (string) $review['note'] ) : '',
			'severity_override' => $override,
			'fingerprint'       => isset( $review['fingerprint'] ) ? sanitize_text_field( (string) $review['fingerprint'] ) : '',
			'route'             => isset( $review['route'] ) ? sanitize_text_field( (string) $review['route'] ) : '',
			'methods'           => isset( $review['methods'] ) ? sanitize_text_field( (string) $review['methods'] ) : '',
			'updated_at'        => isset( $review['updated_at'] ) ? sanitize_text_field( (string) $review['updated_at'] ) : '',
			'reviewer'          => isset( $review['reviewer'] ) ? sanitize_text_field( (string) $review['reviewer'] ) : '',
			'reviewer_id'       => isset( $review['reviewer_id'] ) ? absint( $review['reviewer_id'] ) : 0,
		);
	}

	/**
	 * Build review data for one row, including automatic retest flag.
	 *
	 * @param array<string,mixed>               $row Row.
	 * @param array<string,array<string,mixed>> $reviews Saved reviews.
	 * @return array<string,mixed>
	 */
	private function build_row_review( array $row, array $reviews ) {
		$key         = isset( $row['key'] ) ? sanitize_key( (string) $row['key'] ) : '';
		$fingerprint = $this->build_endpoint_fingerprint( $row );
		$review      = isset( $reviews[ $key ] ) ? $reviews[ $key ] : array();

		$review = wp_parse_args(
			$review,
			array(
				'status'            => 'new',
				'note'              => '',
				'severity_override' => '',
				'fingerprint'       => '',
				'route'             => isset( $row['route'] ) ? (string) $row['route'] : '',
				'methods'           => ! empty( $row['methods'] ) && is_array( $row['methods'] ) ? implode( ',', $row['methods'] ) : '',
				'updated_at'        => '',
				'reviewer'          => '',
				'reviewer_id'       => 0,
			)
		);

		$review = $this->sanitize_endpoint_review( $review );
		if ( ! empty( $review['fingerprint'] ) && ! hash_equals( (string) $review['fingerprint'], (string) $fingerprint ) && in_array( $review['status'], array( 'accepted_public', 'false_positive', 'shielded' ), true ) ) {
			$review['original_status'] = $review['status'];
			$review['status']          = 'retest_required';
			$review['auto_retest']     = true;
		}

		$review['fingerprint_current'] = $fingerprint;
		return $review;
	}

	/**
	 * Build stable technical fingerprint for retest detection.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return string
	 */
	private function build_endpoint_fingerprint( array $row ) {
		$methods = isset( $row['methods'] ) && is_array( $row['methods'] ) ? $row['methods'] : array();
		$methods = array_map( 'strval', $methods );
		sort( $methods );

		$data = array(
			'route'             => isset( $row['route'] ) ? (string) $row['route'] : '',
			'methods'           => $methods,
			'permission'        => isset( $row['permission_callback_label'] ) ? (string) $row['permission_callback_label'] : '',
			'permission_source' => isset( $row['permission_callback_source'] ) ? (string) $row['permission_callback_source'] : '',
			'callback'          => isset( $row['callback_label'] ) ? (string) $row['callback_label'] : '',
			'callback_source'   => isset( $row['callback_source'] ) ? (string) $row['callback_source'] : '',
			'risk'              => isset( $row['risk']['level'] ) ? (string) $row['risk']['level'] : 'low',
		);

		return sha1( wp_json_encode( $data ) );
	}

	/**
	 * Build an effective risk from a manual override.
	 *
	 * @param string              $override Override level.
	 * @param array<string,mixed> $scanner_risk Original scanner risk.
	 * @return array<string,string>
	 */
	private function build_override_risk( $override, array $scanner_risk ) {
		$options = $this->severity_override_options();
		$level   = isset( $options[ $override ] ) ? $override : ( $scanner_risk['level'] ?? 'low' );

		return array(
			'level'   => $level,
			'label'   => $options[ $level ] ?? __( 'Low', 'rest-radar' ),
			'message' => sprintf(
				/* translators: %s: original scanner severity. */
				__( 'Manual severity override. Original scanner severity: %s.', 'rest-radar' ),
				isset( $scanner_risk['label'] ) ? (string) $scanner_risk['label'] : ucfirst( (string) ( $scanner_risk['level'] ?? 'low' ) )
			),
		);
	}

	/**
	 * Check if an endpoint has a matching enabled Shield rule.
	 *
	 * @param array<string,mixed>            $row Row.
	 * @param array<int,array<string,mixed>> $rules Shield rules.
	 * @return bool
	 */
	private function row_has_enabled_shield_rule( array $row, array $rules ) {
		$route   = isset( $row['route'] ) ? (string) $row['route'] : '';
		$methods = isset( $row['methods'] ) && is_array( $row['methods'] ) ? $row['methods'] : array();

		foreach ( $rules as $rule ) {
			if ( empty( $rule['enabled'] ) ) {
				continue;
			}

			$rule_methods = isset( $rule['methods'] ) && is_array( $rule['methods'] ) ? $rule['methods'] : array( 'ANY' );
			$method_match = in_array( 'ANY', $rule_methods, true ) || ! empty( array_intersect( $methods, $rule_methods ) );
			if ( $method_match && Rest_Radar_Shield::pattern_matches( $rule['pattern'] ?? '', $route ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get sanitized options.
	 *
	 * @return array{ignored_patterns:array<int,string>,hide_ignored:bool,hide_core_public:bool}
	 */
	private function get_options() {
		$defaults = array(
			'ignored_patterns'      => array(),
			'hide_ignored'          => false,
			'hide_core_public'      => true,
			'cleanup_on_uninstall'  => false,
		);

		$options = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $options ) ) {
			$options = array();
		}

		$options = wp_parse_args( $options, $defaults );
		if ( ! is_array( $options['ignored_patterns'] ) ) {
			$options['ignored_patterns'] = array();
		}

		$options['ignored_patterns']     = array_values( array_filter( array_map( 'sanitize_text_field', $options['ignored_patterns'] ) ) );
		$options['hide_ignored']         = ! empty( $options['hide_ignored'] );
		$options['hide_core_public']     = ! empty( $options['hide_core_public'] );
		$options['cleanup_on_uninstall'] = ! empty( $options['cleanup_on_uninstall'] );

		return $options;
	}

	/**
	 * Save one endpoint review decision.
	 *
	 * @return void
	 */
	public function save_endpoint_review() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to save endpoint reviews.', 'rest-radar' ) );
		}

		$row_key = isset( $_POST['row_key'] ) ? sanitize_key( wp_unslash( $_POST['row_key'] ) ) : '';
		check_admin_referer( 'rest_radar_save_endpoint_review_' . $row_key );

		$status      = isset( $_POST['review_status'] ) ? sanitize_key( wp_unslash( $_POST['review_status'] ) ) : 'new';
		$override    = isset( $_POST['severity_override'] ) ? sanitize_key( wp_unslash( $_POST['severity_override'] ) ) : '';
		$note        = isset( $_POST['review_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['review_note'] ) ) : '';
		$route       = isset( $_POST['route'] ) ? sanitize_text_field( wp_unslash( $_POST['route'] ) ) : '';
		$methods     = isset( $_POST['methods'] ) ? sanitize_text_field( wp_unslash( $_POST['methods'] ) ) : '';
		$fingerprint = isset( $_POST['fingerprint'] ) ? sanitize_text_field( wp_unslash( $_POST['fingerprint'] ) ) : '';

		if ( '' === $row_key ) {
			wp_safe_redirect( admin_url( 'tools.php?page=rest-radar' ) );
			exit;
		}

		if ( ! isset( $this->review_statuses()[ $status ] ) ) {
			$status = 'new';
		}

		if ( '' !== $override && ! isset( $this->severity_override_options()[ $override ] ) ) {
			$override = '';
		}

		if ( '' !== $override && '' === trim( $note ) ) {
			wp_safe_redirect( add_query_arg( array( 'rest_radar_review_note_required' => '1', 'rr_detail' => rawurlencode( $row_key ) ), admin_url( 'tools.php?page=rest-radar' ) ) );
			exit;
		}

		$current_user = wp_get_current_user();
		$reviews      = $this->get_endpoint_reviews();
		$reviews[ $row_key ] = $this->sanitize_endpoint_review(
			array(
				'status'            => $status,
				'note'              => $note,
				'severity_override' => $override,
				'fingerprint'       => $fingerprint,
				'route'             => $route,
				'methods'           => $methods,
				'updated_at'        => current_time( 'Y-m-d H:i:s' ),
				'reviewer'          => $current_user && $current_user->exists() ? $current_user->display_name : '',
				'reviewer_id'       => get_current_user_id(),
			)
		);

		update_option( self::REVIEW_OPTION_NAME, $reviews, false );

		wp_safe_redirect( add_query_arg( array( 'rest_radar_review_saved' => '1', 'rr_detail' => rawurlencode( $row_key ) ), admin_url( 'tools.php?page=rest-radar' ) ) );
		exit;
	}

	/**
	 * Save settings from admin form.
	 *
	 * @return void
	 */
	public function save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to save REST Radar settings.', 'rest-radar' ) );
		}

		check_admin_referer( 'rest_radar_save_settings' );

		$raw_patterns = isset( $_POST['ignored_patterns'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ignored_patterns'] ) ) : '';
		$patterns     = preg_split( '/\r\n|\r|\n/', $raw_patterns );
		$patterns     = is_array( $patterns ) ? $patterns : array();
		$patterns     = array_map( 'trim', $patterns );
		$patterns     = array_filter(
			$patterns,
			static function ( $pattern ) {
				return '' !== $pattern && 0 !== strpos( $pattern, '#' );
			}
		);
		$patterns     = array_values( array_unique( array_map( 'sanitize_text_field', $patterns ) ) );

		update_option(
			self::OPTION_NAME,
			array(
				'ignored_patterns'      => $patterns,
				'hide_ignored'          => ! empty( $_POST['hide_ignored'] ),
				'hide_core_public'      => ! empty( $_POST['hide_core_public'] ),
				'cleanup_on_uninstall'  => ! empty( $_POST['cleanup_on_uninstall'] ),
			),
			false
		);

		wp_safe_redirect( add_query_arg( 'rest_radar_updated', '1', admin_url( 'tools.php?page=rest-radar' ) ) );
		exit;
	}

	/**
	 * Add ignored, Shield, and review markers to rows.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows.
	 * @param array<string,mixed>            $options Options.
	 * @return array<int,array<string,mixed>>
	 */
	private function prepare_rows( array $rows, array $options ) {
		$reviews        = $this->get_endpoint_reviews();
		$shield_options = Rest_Radar_Shield::get_options();
		$shield_rules   = isset( $shield_options['rules'] ) && is_array( $shield_options['rules'] ) ? $shield_options['rules'] : array();

		foreach ( $rows as $index => $row ) {
			$rows[ $index ]['ignored']           = $this->row_matches_patterns( $row, $options['ignored_patterns'] );
			$rows[ $index ]['shield_rule_state'] = $this->row_has_enabled_shield_rule( $row, $shield_rules ) ? 'active' : 'none';
			$rows[ $index ]['scanner_risk']       = isset( $row['risk'] ) && is_array( $row['risk'] ) ? $row['risk'] : array( 'level' => 'low', 'label' => __( 'Low', 'rest-radar' ), 'message' => '' );
			$rows[ $index ]['review']            = $this->build_row_review( $row, $reviews );

			if ( ! empty( $rows[ $index ]['review']['severity_override'] ) ) {
				$rows[ $index ]['risk'] = $this->build_override_risk( $rows[ $index ]['review']['severity_override'], $rows[ $index ]['scanner_risk'] );
			}
		}

		return $rows;
	}

	/**
	 * Build stats by risk level and review state.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows.
	 * @return array<string,int>
	 */
	private function build_stats( array $rows ) {
		$stats = array(
			'critical'                => 0,
			'high'                    => 0,
			'medium'                  => 0,
			'public'                  => 0,
			'low'                     => 0,
			'ignored'                 => 0,
			'review_new'              => 0,
			'review_needs_review'     => 0,
			'review_accepted_public'  => 0,
			'review_false_positive'   => 0,
			'review_fix_required'     => 0,
			'review_shielded'         => 0,
			'review_retest_required'  => 0,
			'review_unreviewed'       => 0,
		);

		foreach ( $rows as $row ) {
			$level = isset( $row['risk']['level'] ) ? $row['risk']['level'] : 'low';
			if ( isset( $stats[ $level ] ) ) {
				$stats[ $level ]++;
			}
			if ( ! empty( $row['ignored'] ) ) {
				$stats['ignored']++;
			}

			$status = isset( $row['review']['status'] ) ? (string) $row['review']['status'] : 'new';
			$key    = 'review_' . $status;
			if ( isset( $stats[ $key ] ) ) {
				$stats[ $key ]++;
			}
			if ( in_array( $status, array( 'new', 'needs_review', 'retest_required' ), true ) ) {
				$stats['review_unreviewed']++;
			}
		}

		return $stats;
	}

	/**
	 * Filter rows by UI values and saved options.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows.
	 * @param string                         $risk_filter Risk filter.
	 * @param string                         $source_filter Source filter.
	 * @param string                         $search_filter Search filter.
	 * @param string                         $review_filter Review filter.
	 * @param array<string,mixed>            $options Options.
	 * @return array<int,array<string,mixed>>
	 */
	private function filter_rows( array $rows, $risk_filter, $source_filter, $search_filter, $review_filter, array $options ) {
		$allowed_risks   = array( 'critical', 'high', 'medium', 'public', 'low' );
		$allowed_sources = array( 'plugin', 'theme', 'mu-plugin', 'core', 'unknown' );
		$allowed_reviews = array_merge( array_keys( $this->review_statuses() ), array( 'unreviewed', 'high_unreviewed', 'has_shield_rule' ) );
		$risk_filter     = in_array( $risk_filter, $allowed_risks, true ) ? $risk_filter : '';
		$source_filter   = in_array( $source_filter, $allowed_sources, true ) ? $source_filter : '';
		$review_filter   = in_array( $review_filter, $allowed_reviews, true ) ? $review_filter : '';
		$search_filter   = strtolower( trim( (string) $search_filter ) );

		return array_values(
			array_filter(
				$rows,
				static function ( $row ) use ( $risk_filter, $source_filter, $search_filter, $review_filter, $options ) {
					$level           = isset( $row['risk']['level'] ) ? $row['risk']['level'] : 'low';
					$source_category = isset( $row['source']['category'] ) ? $row['source']['category'] : 'unknown';
					$review_status   = isset( $row['review']['status'] ) ? (string) $row['review']['status'] : 'new';
					$has_shield_rule = ! empty( $row['shield_rule_state'] ) && 'active' === $row['shield_rule_state'];

					if ( ! empty( $options['hide_ignored'] ) && ! empty( $row['ignored'] ) ) {
						return false;
					}

					if ( ! empty( $options['hide_core_public'] ) && 'core' === $source_category && 'public' === $level ) {
						return false;
					}

					if ( $risk_filter && $risk_filter !== $level ) {
						return false;
					}

					if ( $source_filter && $source_filter !== $source_category ) {
						return false;
					}

					if ( 'unreviewed' === $review_filter && ! in_array( $review_status, array( 'new', 'needs_review', 'retest_required' ), true ) ) {
						return false;
					}

					if ( 'high_unreviewed' === $review_filter && ( ! in_array( $level, array( 'critical', 'high' ), true ) || ! in_array( $review_status, array( 'new', 'needs_review', 'retest_required' ), true ) ) ) {
						return false;
					}

					if ( 'has_shield_rule' === $review_filter && ! $has_shield_rule ) {
						return false;
					}

					if ( $review_filter && ! in_array( $review_filter, array( 'unreviewed', 'high_unreviewed', 'has_shield_rule' ), true ) && $review_filter !== $review_status ) {
						return false;
					}

					if ( '' === $search_filter ) {
						return true;
					}

					$haystack = strtolower(
						implode(
							' ',
							array(
								$row['namespace'] ?? '',
								$row['route'] ?? '',
								$row['callback_label'] ?? '',
								$row['callback_source'] ?? '',
								$row['permission_callback_label'] ?? '',
								$row['permission_callback_source'] ?? '',
								$row['source']['label'] ?? '',
								$row['review']['note'] ?? '',
								implode( ' ', $row['tags'] ?? array() ),
							)
						)
					);

					return false !== strpos( $haystack, $search_filter );
				}
			)
		);
	}

	/**
	 * Check row against ignored patterns.
	 *
	 * @param array<string,mixed> $row Row.
	 * @param array<int,string>   $patterns Patterns.
	 * @return bool
	 */
	private function row_matches_patterns( array $row, array $patterns ) {
		if ( empty( $patterns ) ) {
			return false;
		}

		$haystack = strtolower(
			implode(
				' ',
				array(
					$row['namespace'] ?? '',
					$row['route'] ?? '',
					$row['callback_label'] ?? '',
					$row['permission_callback_label'] ?? '',
					$row['source']['label'] ?? '',
				)
			)
		);

		foreach ( $patterns as $pattern ) {
			$pattern = strtolower( trim( (string) $pattern ) );
			if ( '' === $pattern ) {
				continue;
			}

			$regex = '/^' . str_replace( '\\*', '.*', preg_quote( $pattern, '/' ) ) . '$/i';
			foreach ( preg_split( '/\s+/', $haystack ) as $piece ) {
				if ( preg_match( $regex, $piece ) || false !== strpos( $piece, $pattern ) ) {
					return true;
				}
			}

			if ( false !== strpos( $haystack, $pattern ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Build a manual QA ticket draft from one endpoint row.
	 *
	 * @param array<string,mixed> $row Endpoint row.
	 * @return string
	 */
	private function build_qa_ticket_text( array $row ) {
		$risk_level = isset( $row['risk']['level'] ) ? strtoupper( (string) $row['risk']['level'] ) : 'LOW';
		$route      = isset( $row['route'] ) ? (string) $row['route'] : '';
		$title      = '[' . $risk_level . '] REST endpoint permission review: ' . $route;
		$tags       = ! empty( $row['tags'] ) && is_array( $row['tags'] ) ? implode( ', ', array_map( 'strval', $row['tags'] ) ) : 'none';
		$methods    = ! empty( $row['methods'] ) && is_array( $row['methods'] ) ? implode( ', ', array_map( 'strval', $row['methods'] ) ) : 'unknown';

		$review_status = isset( $row['review']['status'] ) ? $this->review_status_label( (string) $row['review']['status'] ) : __( 'New', 'rest-radar' );
		$review_note   = ! empty( $row['review']['note'] ) ? (string) $row['review']['note'] : 'none';
		$override      = ! empty( $row['review']['severity_override'] ) ? (string) $row['review']['severity_override'] : 'none';
		$shield_state  = ! empty( $row['shield_rule_state'] ) && 'active' === $row['shield_rule_state'] ? 'active' : 'none';

		$lines = array(
			'Title: ' . $title,
			'',
			'Environment:',
			'- Site: ' . home_url( '/' ),
			'- WordPress: ' . get_bloginfo( 'version' ),
			'- REST Radar: ' . REST_RADAR_VERSION,
			'',
			'Endpoint:',
			'- Route: ' . $route,
			'- REST URL: ' . $this->build_rest_url_for_row( $row ),
			'- Methods: ' . $methods,
			'- Namespace: ' . ( $row['namespace'] ?? 'unknown' ),
			'- Source: ' . ( $row['source']['label'] ?? 'Unknown' ),
			'- Permission callback: ' . ( $row['permission_callback_label'] ?? 'missing' ),
			'- Permission source: ' . ( $row['permission_callback_source'] ?? 'unknown' ),
			'- Main callback: ' . ( $row['callback_label'] ?? 'unknown' ),
			'- Main source: ' . ( $row['callback_source'] ?? 'unknown' ),
			'- Tags: ' . $tags,
			'',
			'Review decision:',
			'- Status: ' . $review_status,
			'- Severity override: ' . $override,
			'- Shield rule state: ' . $shield_state,
			'- Reviewer note: ' . $review_note,
			'',
			'Current observation:',
			'- ' . ( $row['risk']['message'] ?? 'No warning message available.' ),
			'',
			'Impact / risk:',
			'- ' . $this->build_qa_impact_text( $row ),
			'',
			'Steps to review:',
		);

		foreach ( $this->build_qa_steps( $row ) as $step_index => $step ) {
			$lines[] = ( $step_index + 1 ) . '. ' . $step;
		}

		$lines[] = '';
		$lines[] = 'Expected result:';
		$lines[] = '- ' . $this->build_expected_result_text( $row );
		$lines[] = '';
		$lines[] = 'Actual / current finding:';
		$lines[] = '- ' . $this->build_actual_result_text( $row );
		$lines[] = '';
		$lines[] = 'Recommended fix / next action:';
		$lines[] = '- ' . ( $row['recommendation'] ?? 'Review the route manually.' );
		$lines[] = '';
		$lines[] = 'QA notes:';
		$lines[] = '- This is an automated review draft. Confirm behaviour manually before reporting it as a defect.';
		$lines[] = '- Do not execute write methods on production while testing.';

		return implode( "\n", array_map( 'wp_strip_all_tags', $lines ) );
	}

	/**
	 * Build impact text for QA ticket.
	 *
	 * @param array<string,mixed> $row Endpoint row.
	 * @return string
	 */
	private function build_qa_impact_text( array $row ) {
		$level    = isset( $row['risk']['level'] ) ? (string) $row['risk']['level'] : 'low';
		$is_write = ! empty( $row['is_write'] );

		if ( 'critical' === $level ) {
			return $is_write ? 'A write-capable REST endpoint may be missing explicit access control.' : 'A REST endpoint may be missing explicit access control.';
		}

		if ( 'high' === $level ) {
			return 'A public write-capable REST endpoint may allow unintended create/update/delete actions if no deeper checks exist.';
		}

		if ( 'medium' === $level ) {
			return 'The endpoint is not automatically confirmed vulnerable, but it needs manual QA/security review.';
		}

		if ( 'public' === $level ) {
			return 'The endpoint appears intentionally public. Confirm that the response exposes only public data.';
		}

		return 'No immediate issue detected by REST Radar, but normal regression and code review still apply.';
	}

	/**
	 * Build review steps for QA ticket.
	 *
	 * @param array<string,mixed> $row Endpoint row.
	 * @return array<int,string>
	 */
	private function build_qa_steps( array $row ) {
		$steps = array(
			'Open the endpoint source file shown in REST Radar, if available.',
			'Review the permission_callback and confirm that it uses an appropriate capability check.',
		);

		if ( ! empty( $row['is_write'] ) ) {
			$steps[] = 'Because this route supports write methods, verify authentication, nonce/token handling, capability checks, object ownership checks, and input validation.';
			$steps[] = 'Test only in a staging environment. Do not send POST/PUT/PATCH/DELETE requests on production.';
		} else {
			$steps[] = 'If the endpoint is public, perform a safe GET check and confirm the response contains no private user, settings, token, file, payment, or business data.';
		}

		if ( ! empty( $row['route_shape']['has_params'] ) ) {
			$steps[] = 'Check object-level authorization for parameterized resources, especially IDs in the route.';
		}

		$steps[] = 'Document expected access for anonymous users, logged-in subscribers, editors, and administrators.';
		$steps[] = 'Retest after applying a fix and attach before/after evidence to the ticket.';

		return $steps;
	}

	/**
	 * Build expected result text for QA ticket.
	 *
	 * @param array<string,mixed> $row Endpoint row.
	 * @return string
	 */
	private function build_expected_result_text( array $row ) {
		if ( ! empty( $row['is_write'] ) ) {
			return 'Write-capable REST routes should reject unauthorized users and allow changes only for users with the correct capability and object-level permission.';
		}

		if ( isset( $row['risk']['level'] ) && 'public' === $row['risk']['level'] ) {
			return 'Public read routes should return only intentionally public, cache-safe data.';
		}

		return 'The endpoint should have explicit and appropriate access control for the data it returns.';
	}

	/**
	 * Build actual result text for QA ticket.
	 *
	 * @param array<string,mixed> $row Endpoint row.
	 * @return string
	 */
	private function build_actual_result_text( array $row ) {
		$permission = isset( $row['permission_callback_label'] ) ? (string) $row['permission_callback_label'] : 'missing';
		$message    = isset( $row['risk']['message'] ) ? (string) $row['risk']['message'] : 'REST Radar did not generate a warning message.';

		return 'REST Radar detected permission callback "' . $permission . '". ' . $message;
	}

	/**
	 * Export a QA-ready Markdown report.
	 *
	 * @return void
	 */
	public function export_markdown() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to export this report.', 'rest-radar' ) );
		}

		check_admin_referer( 'rest_radar_export_markdown' );

		$options       = $this->get_options();
		$rows          = Rest_Radar_Scanner::scan();
		$prepared_rows = $this->prepare_rows( $rows, $options );
		$risk_filter   = isset( $_GET['risk'] ) ? sanitize_key( wp_unslash( $_GET['risk'] ) ) : '';
		$source_filter = isset( $_GET['source'] ) ? sanitize_key( wp_unslash( $_GET['source'] ) ) : '';
		$search_filter = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$review_filter = isset( $_GET['review'] ) ? sanitize_key( wp_unslash( $_GET['review'] ) ) : '';
		$filtered_rows = $this->filter_rows( $prepared_rows, $risk_filter, $source_filter, $search_filter, $review_filter, $options );

		nocache_headers();
		header( 'Content-Type: text/markdown; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=rest-radar-qa-report-' . gmdate( 'Y-m-d-His' ) . '.md' );

		echo $this->build_markdown_report( $filtered_rows, $prepared_rows, $risk_filter, $source_filter, $search_filter, $review_filter ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markdown file download, content is sanitized in builder.
		exit;
	}

	/**
	 * Build Markdown audit report.
	 *
	 * @param array<int,array<string,mixed>> $filtered_rows Filtered rows.
	 * @param array<int,array<string,mixed>> $all_rows All prepared rows.
	 * @param string                         $risk_filter Risk filter.
	 * @param string                         $source_filter Source filter.
	 * @param string                         $search_filter Search filter.
	 * @return string
	 */
	private function build_markdown_report( array $filtered_rows, array $all_rows, $risk_filter, $source_filter, $search_filter, $review_filter = '' ) {
		$stats = $this->build_stats( $all_rows );
		$lines = array(
			'# REST Radar QA Audit Report',
			'',
			'- Site: ' . $this->markdown_inline( home_url( '/' ) ),
			'- Generated: ' . gmdate( 'Y-m-d H:i:s' ) . ' UTC',
			'- WordPress: ' . $this->markdown_inline( get_bloginfo( 'version' ) ),
			'- PHP: ' . $this->markdown_inline( PHP_VERSION ),
			'- REST Radar: ' . REST_RADAR_VERSION,
			'',
			'## Active filters',
			'',
			'- Risk: ' . ( $risk_filter ? $this->markdown_inline( $risk_filter ) : 'all' ),
			'- Source: ' . ( $source_filter ? $this->markdown_inline( $source_filter ) : 'all' ),
			'- Search: ' . ( $search_filter ? $this->markdown_inline( $search_filter ) : 'none' ),
			'- Review: ' . ( $review_filter ? $this->markdown_inline( $review_filter ) : 'all' ),
			'',
			'## Summary',
			'',
			'| Metric | Count |',
			'|---|---:|',
			'| Total routes | ' . count( $all_rows ) . ' |',
			'| Critical | ' . absint( $stats['critical'] ?? 0 ) . ' |',
			'| High | ' . absint( $stats['high'] ?? 0 ) . ' |',
			'| Review | ' . absint( $stats['medium'] ?? 0 ) . ' |',
			'| Public | ' . absint( $stats['public'] ?? 0 ) . ' |',
			'| Low | ' . absint( $stats['low'] ?? 0 ) . ' |',
			'| Ignored | ' . absint( $stats['ignored'] ?? 0 ) . ' |',
			'| Needs review | ' . absint( $stats['review_needs_review'] ?? 0 ) . ' |',
			'| Fix required | ' . absint( $stats['review_fix_required'] ?? 0 ) . ' |',
			'| Retest required | ' . absint( $stats['review_retest_required'] ?? 0 ) . ' |',
			'| Shielded | ' . absint( $stats['review_shielded'] ?? 0 ) . ' |',
			'',
			'## Manual QA focus',
			'',
			'- Verify `permission_callback` on all critical/high/review endpoints.',
			'- For write methods, confirm authentication, capability checks, object ownership checks, nonce/token flow, and input validation.',
			'- For public read endpoints, confirm that the returned response contains only intentionally public data.',
			'- Do not execute write methods on production while testing.',
			'',
			'## Findings',
			'',
		);

		$findings = array_values(
			array_filter(
				$filtered_rows,
				static function ( $row ) {
					$level = isset( $row['risk']['level'] ) ? (string) $row['risk']['level'] : 'low';
					return in_array( $level, array( 'critical', 'high', 'medium', 'public' ), true );
				}
			)
		);

		if ( empty( $findings ) ) {
			$lines[] = 'No critical/high/review/public findings matched the current filters.';
			$lines[] = '';
			return implode( "\n", $lines );
		}

		foreach ( $findings as $index => $row ) {
			$lines[] = '### Finding ' . ( $index + 1 ) . ': `' . $this->markdown_code( $row['route'] ?? '' ) . '`';
			$lines[] = '';
			$lines[] = '- Severity: **' . $this->markdown_inline( $row['risk']['label'] ?? 'Low' ) . '**';
			$lines[] = '- Scanner severity: **' . $this->markdown_inline( $row['scanner_risk']['label'] ?? ( $row['scanner_risk']['level'] ?? 'Low' ) ) . '**';
			$lines[] = '- Review status: ' . $this->markdown_inline( $this->review_status_label( $row['review']['status'] ?? 'new' ) );
			$lines[] = '- Severity override: ' . $this->markdown_inline( ! empty( $row['review']['severity_override'] ) ? $row['review']['severity_override'] : 'none' );
			$lines[] = '- Reviewer note: ' . $this->markdown_inline( ! empty( $row['review']['note'] ) ? $row['review']['note'] : 'none' );
			$lines[] = '- Reviewed date: ' . $this->markdown_inline( ! empty( $row['review']['updated_at'] ) ? $row['review']['updated_at'] : 'not reviewed' );
			$lines[] = '- Shield rule: ' . $this->markdown_inline( ! empty( $row['shield_rule_state'] ) && 'active' === $row['shield_rule_state'] ? 'active' : 'none' );
			$lines[] = '- Methods: `' . $this->markdown_code( implode( ', ', $row['methods'] ?? array() ) ) . '`';
			$lines[] = '- Source: ' . $this->markdown_inline( $row['source']['label'] ?? 'Unknown' );
			$lines[] = '- Namespace: `' . $this->markdown_code( $row['namespace'] ?? '' ) . '`';
			$lines[] = '- Permission callback: `' . $this->markdown_code( $row['permission_callback_label'] ?? '' ) . '`';
			$lines[] = '- Main callback: `' . $this->markdown_code( $row['callback_label'] ?? '' ) . '`';
			$lines[] = '- Tags: ' . $this->markdown_inline( ! empty( $row['tags'] ) ? implode( ', ', $row['tags'] ) : 'none' );
			$lines[] = '- Observation: ' . $this->markdown_inline( $row['risk']['message'] ?? '' );
			$lines[] = '- Recommended action: ' . $this->markdown_inline( $row['recommendation'] ?? '' );
			$lines[] = '';
			$lines[] = '#### QA ticket draft';
			$lines[] = '';
			$lines[] = '```text';
			$lines[] = $this->markdown_fence_safe( $this->build_qa_ticket_text( $row ) );
			$lines[] = '```';
			$lines[] = '';
		}

		return implode( "\n", $lines );
	}

	/**
	 * Sanitize inline Markdown text.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function markdown_inline( $value ) {
		$value = wp_strip_all_tags( (string) $value );
		$value = str_replace( array( "\r\n", "\r", "\n", '|' ), array( ' ', ' ', ' ', '\\|' ), $value );
		return trim( $value );
	}

	/**
	 * Sanitize text for inline code.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function markdown_code( $value ) {
		$value = wp_strip_all_tags( (string) $value );
		$value = str_replace( array( "\r\n", "\r", "\n", '`' ), array( ' ', ' ', ' ', '\\`' ), $value );
		return trim( $value );
	}

	/**
	 * Prevent closing a Markdown fence from endpoint-controlled content.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private function markdown_fence_safe( $value ) {
		$value = wp_strip_all_tags( (string) $value );
		return str_replace( '```', '` ` `', $value );
	}


	/**
	 * Render Endpoint Shield controls and logs in the sidebar.
	 *
	 * @param array<string,mixed>            $shield_options Shield options.
	 * @param array<int,array<string,mixed>> $shield_logs Shield logs.
	 * @return void
	 */
	private function render_shield_box( array $shield_options, array $shield_logs ) {
		$rules = isset( $shield_options['rules'] ) && is_array( $shield_options['rules'] ) ? $shield_options['rules'] : array();
		?>
		<div class="rest-radar-settings-box rest-radar-shield-box">
			<div class="rest-radar-box-heading">
				<h2><?php echo esc_html__( 'Endpoint Shield', 'rest-radar' ); ?></h2>
				<span class="rest-radar-status-dot <?php echo ! empty( $shield_options['enabled'] ) ? 'is-active' : 'is-inactive'; ?>"><?php echo esc_html( ! empty( $shield_options['enabled'] ) ? __( 'Active', 'rest-radar' ) : __( 'Inactive', 'rest-radar' ) ); ?></span>
			</div>
			<p><?php echo esc_html__( 'Non-destructive mitigation layer. It does not edit third-party plugin files; it blocks matching REST requests before callbacks run.', 'rest-radar' ); ?></p>

			<div class="rest-radar-safety-callout">
				<strong><?php echo esc_html__( 'Safe defaults are enforced.', 'rest-radar' ); ?></strong>
				<p><?php echo esc_html__( 'Endpoint Shield and Auto Safe Mode are OFF by default. Auto blocking requires explicit confirmation, and WordPress core route protection requires a separate confirmation.', 'rest-radar' ); ?></p>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="rest_radar_save_shield_settings" />
				<?php wp_nonce_field( 'rest_radar_save_shield_settings' ); ?>

				<label class="rest-radar-checkbox">
					<input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $shield_options['enabled'] ) ); ?> />
					<span>
						<strong><?php echo esc_html__( 'Enable Endpoint Shield', 'rest-radar' ); ?></strong><br />
						<span class="description"><?php echo esc_html__( 'Manual rules can block matching REST requests. Test rules on staging before using them on production.', 'rest-radar' ); ?></span>
					</span>
				</label>

				<label class="rest-radar-checkbox">
					<input type="checkbox" name="auto_safe_mode" value="1" <?php checked( ! empty( $shield_options['auto_safe_mode'] ) ); ?> />
					<span>
						<strong><?php echo esc_html__( 'Auto Safe Mode: block critical/high custom endpoints for non-admin users', 'rest-radar' ); ?></strong><br />
						<span class="description"><?php echo esc_html__( 'Auto Safe Mode is disabled unless the confirmation checkbox below is also selected.', 'rest-radar' ); ?></span>
					</span>
				</label>

				<label class="rest-radar-checkbox rest-radar-confirm-checkbox">
					<input type="checkbox" name="auto_safe_mode_confirm" value="1" <?php checked( ! empty( $shield_options['auto_safe_mode'] ) ); ?> />
					<span><?php echo esc_html__( 'I understand Auto Safe Mode may block legitimate plugin endpoints and I have tested this on staging.', 'rest-radar' ); ?></span>
				</label>

				<label class="rest-radar-checkbox">
					<input type="checkbox" name="include_core" value="1" <?php checked( ! empty( $shield_options['include_core'] ) ); ?> />
					<span>
						<strong><?php echo esc_html__( 'Allow Auto Safe Mode to affect WordPress core routes', 'rest-radar' ); ?></strong><br />
						<span class="description"><?php echo esc_html__( 'This is risky and is intended only for controlled testing or advanced review.', 'rest-radar' ); ?></span>
					</span>
				</label>

				<label class="rest-radar-checkbox rest-radar-confirm-checkbox">
					<input type="checkbox" name="include_core_confirm" value="1" <?php checked( ! empty( $shield_options['include_core'] ) ); ?> />
					<span><?php echo esc_html__( 'I understand core route protection may affect the editor, admin screens, mobile apps, and integrations.', 'rest-radar' ); ?></span>
				</label>

				<label class="rest-radar-checkbox">
					<input type="checkbox" name="log_enabled" value="1" <?php checked( ! empty( $shield_options['log_enabled'] ) ); ?> />
					<?php echo esc_html__( 'Log blocked requests', 'rest-radar' ); ?>
				</label>

				<label class="rest-radar-checkbox">
					<input type="checkbox" name="anonymize_ip" value="1" <?php checked( ! empty( $shield_options['anonymize_ip'] ) ); ?> />
					<span>
						<strong><?php echo esc_html__( 'Anonymize IP addresses in Shield logs', 'rest-radar' ); ?></strong><br />
						<span class="description"><?php echo esc_html__( 'Recommended for EU production sites. IPv4 logs keep the first three octets only; IPv6 logs keep the network prefix only.', 'rest-radar' ); ?></span>
					</span>
				</label>

				<button type="submit" class="button button-primary"><?php echo esc_html__( 'Save shield settings', 'rest-radar' ); ?></button>
			</form>

			<hr />
			<h3><?php echo esc_html__( 'Manual shield rules', 'rest-radar' ); ?></h3>
			<?php if ( empty( $rules ) ) : ?>
				<p class="rest-radar-muted"><?php echo esc_html__( 'No manual shield rules yet. Open an endpoint Details panel and use Add shield rule.', 'rest-radar' ); ?></p>
			<?php else : ?>
				<div class="rest-radar-rule-list">
					<?php foreach ( $rules as $rule ) : ?>
						<div class="rest-radar-rule-card">
							<div><strong><code><?php echo esc_html( $rule['pattern'] ?? '' ); ?></code></strong></div>
							<div class="rest-radar-muted"><?php echo esc_html( implode( ', ', $rule['methods'] ?? array( 'ANY' ) ) ); ?> · <?php echo esc_html( Rest_Radar_Shield::mode_label( $rule['mode'] ?? 'admins_only' ) ); ?></div>
							<?php if ( ! empty( $rule['capability'] ) ) : ?>
								<div class="rest-radar-muted"><?php echo esc_html( sprintf( __( 'Capability: %s', 'rest-radar' ), $rule['capability'] ) ); ?></div>
							<?php endif; ?>
							<?php if ( ! empty( $rule['note'] ) ) : ?>
								<div class="rest-radar-muted"><?php echo esc_html( $rule['note'] ); ?></div>
							<?php endif; ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="rest_radar_delete_shield_rule" />
								<input type="hidden" name="rule_id" value="<?php echo esc_attr( $rule['id'] ?? '' ); ?>" />
								<?php wp_nonce_field( 'rest_radar_delete_shield_rule_' . ( $rule['id'] ?? '' ) ); ?>
								<button type="submit" class="button button-small button-link-delete"><?php echo esc_html__( 'Delete rule', 'rest-radar' ); ?></button>
							</form>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<hr />
			<h3><?php echo esc_html__( 'Recent blocks', 'rest-radar' ); ?></h3>
			<?php if ( empty( $shield_logs ) ) : ?>
				<p class="rest-radar-muted"><?php echo esc_html__( 'No blocked REST requests logged yet.', 'rest-radar' ); ?></p>
			<?php else : ?>
				<div class="rest-radar-log-list">
					<?php foreach ( array_slice( $shield_logs, 0, 8 ) as $log ) : ?>
						<div class="rest-radar-log-row">
							<code><?php echo esc_html( ( $log['method'] ?? '' ) . ' ' . ( $log['route'] ?? '' ) ); ?></code>
							<div class="rest-radar-muted"><?php echo esc_html( $log['time'] ?? '' ); ?></div>
							<div class="rest-radar-muted"><?php echo esc_html( $log['reason'] ?? '' ); ?></div>
						</div>
					<?php endforeach; ?>
				</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="rest_radar_clear_shield_logs" />
					<?php wp_nonce_field( 'rest_radar_clear_shield_logs' ); ?>
					<button type="submit" class="button button-secondary"><?php echo esc_html__( 'Clear logs', 'rest-radar' ); ?></button>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Save Endpoint Shield settings.
	 *
	 * @return void
	 */
	public function save_shield_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to save Endpoint Shield settings.', 'rest-radar' ) );
		}

		check_admin_referer( 'rest_radar_save_shield_settings' );

		$auto_requested   = ! empty( $_POST['auto_safe_mode'] );
		$auto_confirmed   = ! empty( $_POST['auto_safe_mode_confirm'] );
		$core_requested   = ! empty( $_POST['include_core'] );
		$core_confirmed   = ! empty( $_POST['include_core_confirm'] );
		$auto_safe_mode   = $auto_requested && $auto_confirmed;
		$include_core     = $auto_safe_mode && $core_requested && $core_confirmed;

		Rest_Radar_Shield::save_settings(
			array(
				'enabled'        => ! empty( $_POST['enabled'] ),
				'auto_safe_mode' => $auto_safe_mode,
				'include_core'   => $include_core,
				'log_enabled'    => ! empty( $_POST['log_enabled'] ),
				'anonymize_ip'   => ! empty( $_POST['anonymize_ip'] ),
			)
		);

		$redirect_args = array(
			'rest_radar_shield_updated' => '1',
		);

		if ( $auto_requested && ! $auto_confirmed ) {
			$redirect_args['rest_radar_auto_confirm_required'] = '1';
		}

		if ( $core_requested && ! $core_confirmed ) {
			$redirect_args['rest_radar_core_confirm_required'] = '1';
		}

		wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'tools.php?page=rest-radar' ) ) );
		exit;
	}

	/**
	 * Add Endpoint Shield rule from admin form.
	 *
	 * @return void
	 */
	public function add_shield_rule() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to add Endpoint Shield rules.', 'rest-radar' ) );
		}

		check_admin_referer( 'rest_radar_add_shield_rule' );

		$pattern     = isset( $_POST['pattern'] ) ? sanitize_text_field( wp_unslash( $_POST['pattern'] ) ) : '';
		$methods     = isset( $_POST['methods'] ) ? sanitize_text_field( wp_unslash( $_POST['methods'] ) ) : 'ANY';
		$mode        = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'admins_only';
		$capability  = isset( $_POST['capability'] ) ? sanitize_key( wp_unslash( $_POST['capability'] ) ) : '';
		$note        = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '';
		$return_key  = isset( $_POST['return_key'] ) ? sanitize_text_field( wp_unslash( $_POST['return_key'] ) ) : '';

		$add_result = Rest_Radar_Shield::add_rule(
			array(
				'enabled'    => true,
				'pattern'    => $pattern,
				'methods'    => $methods,
				'mode'       => $mode,
				'capability' => $capability,
				'note'       => $note,
			)
		);

		$redirect_flag = ! empty( $add_result['added'] ) ? 'rest_radar_shield_added' : 'rest_radar_shield_duplicate';
		$redirect      = add_query_arg( $redirect_flag, '1', admin_url( 'tools.php?page=rest-radar' ) );
		if ( $return_key ) {
			$redirect = add_query_arg( 'rr_detail', rawurlencode( $return_key ), $redirect );
		}

		wp_safe_redirect( $redirect );
		exit;
	}


	/**
	 * Save current REST scan as a snapshot.
	 *
	 * @return void
	 */
	public function create_snapshot() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to create REST Radar snapshots.', 'rest-radar' ) );
		}

		check_admin_referer( 'rest_radar_create_snapshot' );

		$name = isset( $_POST['snapshot_name'] ) ? sanitize_text_field( wp_unslash( $_POST['snapshot_name'] ) ) : '';
		if ( '' === $name ) {
			$name = sprintf( __( 'Scan %s', 'rest-radar' ), current_time( 'Y-m-d H:i' ) );
		}

		$options     = $this->get_options();
		$rows        = $this->prepare_rows( Rest_Radar_Scanner::scan(), $options );
		$snapshots   = $this->get_snapshots();
		$snapshot_rows = $this->build_snapshot_rows( $rows );
		$limited       = count( $rows ) > count( $snapshot_rows );

		$snapshots[] = array(
			'id'         => substr( sha1( $name . '|' . microtime( true ) . '|' . wp_rand() ), 0, 16 ),
			'name'       => $name,
			'created_at' => current_time( 'Y-m-d H:i:s' ),
			'rows'       => $snapshot_rows,
		);

		$snapshots = array_slice( $snapshots, - self::MAX_SNAPSHOTS );
		while ( strlen( maybe_serialize( $snapshots ) ) > self::MAX_SNAPSHOT_OPTION_BYTES && count( $snapshots ) > 1 ) {
			array_shift( $snapshots );
			$limited = true;
		}

		update_option( self::SNAPSHOT_OPTION_NAME, $snapshots, false );

		$redirect_args = array( 'rest_radar_snapshot_created' => '1' );
		if ( $limited ) {
			$redirect_args['rest_radar_snapshot_limited'] = '1';
		}

		wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'tools.php?page=rest-radar' ) ) );
		exit;
	}

	/**
	 * Delete one saved snapshot.
	 *
	 * @return void
	 */
	public function delete_snapshot() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to delete REST Radar snapshots.', 'rest-radar' ) );
		}

		$snapshot_id = isset( $_POST['snapshot_id'] ) ? sanitize_key( wp_unslash( $_POST['snapshot_id'] ) ) : '';
		check_admin_referer( 'rest_radar_delete_snapshot_' . $snapshot_id );

		$snapshots = array_values(
			array_filter(
				$this->get_snapshots(),
				static function ( $snapshot ) use ( $snapshot_id ) {
					return ! isset( $snapshot['id'] ) || ! hash_equals( (string) $snapshot['id'], (string) $snapshot_id );
				}
			)
		);

		update_option( self::SNAPSHOT_OPTION_NAME, $snapshots, false );

		wp_safe_redirect( add_query_arg( 'rest_radar_snapshot_deleted', '1', admin_url( 'tools.php?page=rest-radar' ) ) );
		exit;
	}

	/**
	 * Clear all saved snapshots.
	 *
	 * @return void
	 */
	public function clear_snapshots() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to clear REST Radar snapshots.', 'rest-radar' ) );
		}

		check_admin_referer( 'rest_radar_clear_snapshots' );
		delete_option( self::SNAPSHOT_OPTION_NAME );

		wp_safe_redirect( add_query_arg( 'rest_radar_snapshots_cleared', '1', admin_url( 'tools.php?page=rest-radar' ) ) );
		exit;
	}


	/**
	 * Delete Endpoint Shield rule.
	 *
	 * @return void
	 */
	public function delete_shield_rule() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to delete Endpoint Shield rules.', 'rest-radar' ) );
		}

		$rule_id = isset( $_POST['rule_id'] ) ? sanitize_key( wp_unslash( $_POST['rule_id'] ) ) : '';
		check_admin_referer( 'rest_radar_delete_shield_rule_' . $rule_id );

		Rest_Radar_Shield::delete_rule( $rule_id );

		wp_safe_redirect( add_query_arg( 'rest_radar_shield_deleted', '1', admin_url( 'tools.php?page=rest-radar' ) ) );
		exit;
	}

	/**
	 * Clear Endpoint Shield logs.
	 *
	 * @return void
	 */
	public function clear_shield_logs() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to clear Endpoint Shield logs.', 'rest-radar' ) );
		}

		check_admin_referer( 'rest_radar_clear_shield_logs' );
		Rest_Radar_Shield::clear_logs();

		wp_safe_redirect( add_query_arg( 'rest_radar_shield_updated', '1', admin_url( 'tools.php?page=rest-radar' ) ) );
		exit;
	}

	/**
	 * Suggest a protective rule mode for an endpoint.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return string
	 */
	private function suggest_shield_mode( array $row ) {
		$level = isset( $row['risk']['level'] ) ? (string) $row['risk']['level'] : 'low';
		if ( ! empty( $row['is_write'] ) || in_array( $level, array( 'critical', 'high' ), true ) ) {
			return 'admins_only';
		}

		if ( 'public' === $level || 'medium' === $level ) {
			return 'block_guests';
		}

		return 'block_guests';
	}

	/**
	 * Build developer-facing code snippet for a route finding.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return string
	 */
	private function build_developer_fix_snippet( array $row ) {
		$is_write = ! empty( $row['is_write'] );
		$level    = isset( $row['risk']['level'] ) ? (string) $row['risk']['level'] : 'low';
		$route    = isset( $row['route'] ) ? (string) $row['route'] : '';

		$lines = array(
			'// REST Radar developer fix suggestion',
			'// Route: ' . $route,
			'// Current finding: ' . ( $row['risk']['message'] ?? 'No warning message available.' ),
			'// Review the original route registration and adjust permission_callback there.',
			'',
		);

		if ( $is_write || in_array( $level, array( 'critical', 'high' ), true ) ) {
			$lines[] = "'permission_callback' => function ( WP_REST_Request \$request ) {";
			$lines[] = "    if ( ! is_user_logged_in() ) {";
			$lines[] = "        return false;";
			$lines[] = "    }";
			$lines[] = '';
			$lines[] = "    // Replace manage_options with the narrowest capability that fits this action.";
			$lines[] = "    if ( ! current_user_can( 'manage_options' ) ) {";
			$lines[] = "        return false;";
			$lines[] = "    }";
			$lines[] = '';
			$lines[] = "    // Also validate object ownership, nonces/tokens, and request input before changing data.";
			$lines[] = "    return true;";
			$lines[] = "},";
		} elseif ( 'public' === $level ) {
			$lines[] = "// Public GET endpoint example. Keep __return_true only when the response is intentionally public.";
			$lines[] = "'permission_callback' => '__return_true',";
			$lines[] = '';
			$lines[] = '// Before accepting public access, verify that the response does not include:';
			$lines[] = '// - private user data';
			$lines[] = '// - settings/options';
			$lines[] = '// - tokens/secrets/nonces';
			$lines[] = '// - private files or drafts';
		} else {
			$lines[] = "'permission_callback' => function ( WP_REST_Request \$request ) {";
			$lines[] = "    return current_user_can( 'read' );";
			$lines[] = "},";
			$lines[] = '';
			$lines[] = '// Replace read with a stricter capability if the endpoint returns private data.';
		}

		$lines[] = '';
		$lines[] = '// REST Radar Shield can mitigate immediately, but the long-term fix belongs in the source code.';

		return implode( "\n", $lines );
	}

	/**
	 * Export CSV.
	 *
	 * @return void
	 */
	public function export_csv() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to export this report.', 'rest-radar' ) );
		}

		check_admin_referer( 'rest_radar_export_csv' );

		$options       = $this->get_options();
		$rows          = Rest_Radar_Scanner::scan();
		$prepared_rows = $this->prepare_rows( $rows, $options );
		$risk_filter   = isset( $_GET['risk'] ) ? sanitize_key( wp_unslash( $_GET['risk'] ) ) : '';
		$source_filter = isset( $_GET['source'] ) ? sanitize_key( wp_unslash( $_GET['source'] ) ) : '';
		$search_filter = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$review_filter = isset( $_GET['review'] ) ? sanitize_key( wp_unslash( $_GET['review'] ) ) : '';
		$filtered_rows = $this->filter_rows( $prepared_rows, $risk_filter, $source_filter, $search_filter, $review_filter, $options );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=rest-radar-endpoints-' . gmdate( 'Y-m-d-His' ) . '.csv' );

		$output = fopen( 'php://output', 'w' );
		if ( false === $output ) {
			wp_die( esc_html__( 'Could not open export stream.', 'rest-radar' ) );
		}

		fputcsv(
			$output,
			array(
				'Effective Risk',
				'Scanner Risk',
				'Methods',
				'Source',
				'Namespace',
				'Route',
				'Permission Callback',
				'Permission Source',
				'Main Callback',
				'Main Source',
				'Tags',
				'Ignored',
				'Review Status',
				'Reviewer Note',
				'Severity Override',
				'Reviewed Date',
				'Reviewer',
				'Shield Rule State',
				'Reason',
				'Recommended Action',
			)
		);

		foreach ( $filtered_rows as $row ) {
			fputcsv(
				$output,
				array_map(
					array( $this, 'sanitize_csv_cell' ),
					array(
						$row['risk']['level'] ?? '',
						$row['scanner_risk']['level'] ?? '',
						implode( ', ', $row['methods'] ?? array() ),
						$row['source']['label'] ?? '',
						$row['namespace'] ?? '',
						$row['route'] ?? '',
						$row['permission_callback_label'] ?? '',
						$row['permission_callback_source'] ?? '',
						$row['callback_label'] ?? '',
						$row['callback_source'] ?? '',
						implode( ', ', $row['tags'] ?? array() ),
						! empty( $row['ignored'] ) ? 'yes' : 'no',
						$row['review']['status'] ?? 'new',
						$row['review']['note'] ?? '',
						$row['review']['severity_override'] ?? '',
						$row['review']['updated_at'] ?? '',
						$row['review']['reviewer'] ?? '',
						$row['shield_rule_state'] ?? 'none',
						$row['risk']['message'] ?? '',
						$row['recommendation'] ?? '',
					)
				)
			);
		}

		fclose( $output );
		exit;
	}

	/**
	 * Prevent CSV formula injection in spreadsheet apps.
	 *
	 * @param mixed $value Cell value.
	 * @return string
	 */
	private function sanitize_csv_cell( $value ) {
		$value = (string) $value;
		$value = str_replace( array( "\r\n", "\r", "\n" ), ' ', $value );
		$value = trim( $value );

		if ( '' !== $value && preg_match( '/^[=+\-@\t]/', $value ) ) {
			$value = "'" . $value;
		}

		return $value;
	}
}
