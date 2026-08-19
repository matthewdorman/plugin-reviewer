<?php
/**
 * Main plugin orchestrator.
 *
 * @package PluginReviewer
 */

namespace PluginReviewer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the plugin components.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Return the singleton.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register hooks and components.
	 *
	 * @return void
	 */
	public function run() {
		$this->load_dependencies();
		$report     = new Report(
			new Inventory(),
			new WporgClient(),
			new AbandonmentScorer(),
			new OptionsAuditor(),
			new CoreIntegrityAuditor( new CoreChecksumClient() )
		);
		$admin_page = new AdminPage( $report );
		$admin_page->register();
	}

	/**
	 * Load class files.
	 *
	 * @return void
	 */
	private function load_dependencies() {
		require_once PLUGIN_REVIEWER_DIR . 'includes/class-inventory.php';
		require_once PLUGIN_REVIEWER_DIR . 'includes/class-wporgclient.php';
		require_once PLUGIN_REVIEWER_DIR . 'includes/class-abandonmentscorer.php';
		require_once PLUGIN_REVIEWER_DIR . 'includes/class-optionsauditor.php';
		require_once PLUGIN_REVIEWER_DIR . 'includes/class-corechecksumclient.php';
		require_once PLUGIN_REVIEWER_DIR . 'includes/class-coreintegrityauditor.php';
		require_once PLUGIN_REVIEWER_DIR . 'includes/class-report.php';
		require_once PLUGIN_REVIEWER_DIR . 'includes/class-adminpage.php';
	}

	/** Prevent direct construction. */
	private function __construct() {}

	/** Prevent cloning. */
	private function __clone() {}
}
