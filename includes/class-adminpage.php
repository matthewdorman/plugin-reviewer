<?php
/**
 * Administration report screen and CSV export.
 *
 * @package PluginReviewer
 */

namespace PluginReviewer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Displays read-only audit evidence.
 */
class AdminPage {

	/**
	 * Report assembler.
	 *
	 * @var Report
	 */
	private $report;

	/**
	 * Constructor.
	 *
	 * @param Report $report Report assembler.
	 */
	public function __construct( Report $report ) {
		$this->report = $report;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_plugin_reviewer_export', array( $this, 'export_csv' ) );
	}

	/**
	 * Add the Tools screen.
	 *
	 * @return void
	 */
	public function add_page() {
		add_management_page(
			__( 'Plugin Reviewer', 'plugin-reviewer' ),
			__( 'Plugin Reviewer', 'plugin-reviewer' ),
			'activate_plugins',
			'plugin-reviewer',
			array( $this, 'render' )
		);
	}

	/**
	 * Load scoped admin styles.
	 *
	 * @param string $hook_suffix Current admin screen.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( 'tools_page_plugin-reviewer' !== $hook_suffix || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		wp_enqueue_style( 'plugin-reviewer', PLUGIN_REVIEWER_URL . 'assets/css/admin.css', array(), PLUGIN_REVIEWER_VERSION );
	}

	/**
	 * Render the report.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this report.', 'plugin-reviewer' ) );
		}

		$report = $this->report->generate();
		?>
		<div class="wrap plugin-reviewer">
			<h1><?php echo esc_html__( 'Plugin Reviewer', 'plugin-reviewer' ); ?></h1>
			<p class="description"><?php echo esc_html__( 'This report gathers evidence. A human must confirm every conclusion before changing the site.', 'plugin-reviewer' ); ?></p>
			<p>
				<a class="button button-secondary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=plugin_reviewer_export' ), 'plugin_reviewer_export' ) ); ?>">
					<?php echo esc_html__( 'Export report CSV', 'plugin-reviewer' ); ?>
				</a>
			</p>
			<h2><?php echo esc_html__( 'Plugin inventory', 'plugin-reviewer' ); ?></h2>
			<?php $this->render_plugins( $report['plugins'] ); ?>
			<h2><?php echo esc_html__( 'Autoloaded options', 'plugin-reviewer' ); ?></h2>
			<?php $this->render_options( $report['options'] ); ?>
		</div>
		<?php
	}

	/**
	 * Render plugin evidence.
	 *
	 * @param array<int,array<string,mixed>> $plugins Plugin rows.
	 * @return void
	 */
	private function render_plugins( $plugins ) {
		?>
		<table class="widefat striped">
			<thead><tr>
				<th><?php echo esc_html__( 'Name', 'plugin-reviewer' ); ?></th>
				<th><?php echo esc_html__( 'Slug', 'plugin-reviewer' ); ?></th>
				<th><?php echo esc_html__( 'Version', 'plugin-reviewer' ); ?></th>
				<th><?php echo esc_html__( 'Status / type', 'plugin-reviewer' ); ?></th>
				<th><?php echo esc_html__( 'Author', 'plugin-reviewer' ); ?></th>
				<th><?php echo esc_html__( 'WordPress.org evidence', 'plugin-reviewer' ); ?></th>
				<th><?php echo esc_html__( 'Score / evidence', 'plugin-reviewer' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $plugins as $plugin ) : ?>
				<tr>
					<td><?php echo esc_html( $plugin['name'] ); ?></td>
					<td><code><?php echo esc_html( $plugin['slug'] ); ?></code></td>
					<td><?php echo esc_html( $plugin['version'] ); ?></td>
					<td><?php echo esc_html( $plugin['status'] . ' / ' . $plugin['type'] ); ?></td>
					<td><?php echo esc_html( $plugin['author'] ); ?></td>
					<td><?php echo wp_kses_post( $this->wporg_summary( $plugin['wporg'] ) ); ?></td>
					<td>
						<strong><?php echo esc_html( null === $plugin['score']['score'] ? __( 'N/A', 'plugin-reviewer' ) : (string) $plugin['score']['score'] . '/5' ); ?></strong><br>
						<span class="description"><?php echo esc_html( implode( ' ', $plugin['score']['reasons'] ) ); ?></span>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Format directory data as safe HTML.
	 *
	 * @param array<string,mixed> $wporg Directory data.
	 * @return string
	 */
	private function wporg_summary( $wporg ) {
		if ( empty( $wporg['available'] ) ) {
			return esc_html__( 'Unavailable', 'plugin-reviewer' );
		}
		if ( ! empty( $wporg['closed'] ) ) {
			return '<strong>' . esc_html__( 'Closed / not found', 'plugin-reviewer' ) . '</strong>';
		}

		return sprintf(
			/* translators: 1: date, 2: WordPress version, 3: active install count. */
			esc_html__( 'Updated: %1$s; tested to: %2$s; installs: %3$s', 'plugin-reviewer' ),
			esc_html( $wporg['last_updated'] ),
			esc_html( $wporg['tested_up_to'] ),
			esc_html( number_format_i18n( $wporg['active_installs'] ) )
		);
	}

	/**
	 * Render options evidence.
	 *
	 * @param array<string,mixed> $options Options report.
	 * @return void
	 */
	private function render_options( $options ) {
		?>
		<div class="plugin-reviewer-summary">
			<p><strong><?php echo esc_html__( 'Total autoloaded:', 'plugin-reviewer' ); ?></strong> <?php echo esc_html( size_format( $options['total_bytes'] ) ); ?></p>
			<p><strong><?php echo esc_html__( 'Candidate orphan bytes:', 'plugin-reviewer' ); ?></strong> <?php echo esc_html( size_format( $options['candidate_orphan_bytes'] ) ); ?> (<?php echo esc_html( number_format_i18n( $options['candidate_orphan_pct'], 1 ) ); ?>%)</p>
		</div>
		<p class="description"><?php echo esc_html__( 'Candidate orphans are autoloaded options whose prefix does not map to an installed plugin or the built-in safe list. This is a heuristic with false positives; inspect ownership before deleting anything.', 'plugin-reviewer' ); ?></p>
		<table class="widefat striped">
			<thead><tr>
				<th><?php echo esc_html__( 'Option', 'plugin-reviewer' ); ?></th>
				<th><?php echo esc_html__( 'Size', 'plugin-reviewer' ); ?></th>
				<th><?php echo esc_html__( 'Attributed plugin', 'plugin-reviewer' ); ?></th>
				<th><?php echo esc_html__( 'Candidate orphan', 'plugin-reviewer' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $options['top_options'] as $option ) : ?>
				<tr>
					<td><code><?php echo esc_html( $option['name'] ); ?></code></td>
					<td><?php echo esc_html( size_format( $option['bytes'] ) ); ?></td>
					<td><?php echo esc_html( '' === $option['attributed_plugin'] ? __( 'Unattributed', 'plugin-reviewer' ) : $option['attributed_plugin'] ); ?></td>
					<td><?php echo esc_html( $option['candidate_orphan'] ? __( 'Candidate', 'plugin-reviewer' ) : __( 'No evidence', 'plugin-reviewer' ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Export matching plugin and option report rows.
	 *
	 * @return void
	 */
	public function export_csv() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_die( esc_html__( 'You do not have permission to export this report.', 'plugin-reviewer' ) );
		}
		check_admin_referer( 'plugin_reviewer_export' );

		$report = $this->report->generate();
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=plugin-reviewer-' . gmdate( 'Y-m-d' ) . '.csv' );

		$output = fopen( 'php://output', 'w' );
		if ( false === $output ) {
			wp_die( esc_html__( 'The CSV stream could not be opened.', 'plugin-reviewer' ) );
		}
		fputcsv( $output, array( 'section', 'name', 'slug_or_owner', 'version_or_bytes', 'status', 'type', 'wporg_last_updated', 'wporg_tested_to', 'wporg_active_installs', 'closed', 'score', 'evidence', 'candidate_orphan' ) );
		foreach ( $report['plugins'] as $plugin ) {
			fputcsv( $output, array( 'plugin', $plugin['name'], $plugin['slug'], $plugin['version'], $plugin['status'], $plugin['type'], $plugin['wporg']['last_updated'], $plugin['wporg']['tested_up_to'], $plugin['wporg']['active_installs'], $plugin['wporg']['closed'] ? 'yes' : 'no', $plugin['score']['score'], implode( ' ', $plugin['score']['reasons'] ), '' ) );
		}
		foreach ( $report['options']['all_options'] as $option ) {
			fputcsv( $output, array( 'autoloaded_option', $option['name'], $option['attributed_plugin'], $option['bytes'], '', '', '', '', '', '', '', '', $option['candidate_orphan'] ? 'yes' : 'no' ) );
		}
		// Closing the PHP output stream completes the download; WP_Filesystem does not support output streams.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $output );
		exit;
	}
}
