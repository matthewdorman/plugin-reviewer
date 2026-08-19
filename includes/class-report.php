<?php
/**
 * Audit report assembler.
 *
 * @package PluginReviewer
 */

namespace PluginReviewer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Combines inventory, directory, scoring, and option evidence.
 */
class Report {

	/**
	 * Inventory collector.
	 *
	 * @var Inventory
	 */
	private $inventory;

	/**
	 * WordPress.org client.
	 *
	 * @var WporgClient
	 */
	private $wporg;

	/**
	 * Abandonment scorer.
	 *
	 * @var AbandonmentScorer
	 */
	private $scorer;

	/**
	 * Options auditor.
	 *
	 * @var OptionsAuditor
	 */
	private $options;

	/**
	 * Constructor.
	 *
	 * @param Inventory         $inventory Inventory collector.
	 * @param WporgClient       $wporg Directory client.
	 * @param AbandonmentScorer $scorer Scorer.
	 * @param OptionsAuditor    $options Options auditor.
	 */
	public function __construct( Inventory $inventory, WporgClient $wporg, AbandonmentScorer $scorer, OptionsAuditor $options ) {
		$this->inventory = $inventory;
		$this->wporg     = $wporg;
		$this->scorer    = $scorer;
		$this->options   = $options;
	}

	/**
	 * Build a complete report.
	 *
	 * @return array<string,mixed>
	 */
	public function generate() {
		$plugins = $this->inventory->get_all();
		foreach ( $plugins as &$plugin ) {
			if ( 'standard' === $plugin['type'] ) {
				$plugin['wporg'] = $this->wporg->get( $plugin['slug'] );
				$plugin['score'] = $this->scorer->score( $plugin['wporg'] );
			} else {
				$plugin['wporg'] = array(
					'available'       => false,
					'found'           => false,
					'closed'          => false,
					'last_updated'    => '',
					'tested_up_to'    => '',
					'active_installs' => 0,
				);
				$plugin['score'] = array(
					'score'   => null,
					'reasons' => array( __( 'Directory scoring does not apply to this plugin type.', 'plugin-reviewer' ) ),
				);
			}
		}
		unset( $plugin );

		return array(
			'generated_at' => current_time( 'mysql' ),
			'plugins'      => $plugins,
			'options'      => $this->options->audit( $plugins ),
		);
	}
}
