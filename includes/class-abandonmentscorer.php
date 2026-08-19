<?php
/**
 * Explainable plugin abandonment scoring.
 *
 * @package PluginReviewer
 */

namespace PluginReviewer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scores directory evidence from one (low concern) to five (high concern).
 */
class AbandonmentScorer {

	/**
	 * Score a plugin and return its evidence.
	 *
	 * @param array<string,mixed> $wporg WordPress.org data.
	 * @return array<string,mixed>
	 */
	public function score( $wporg ) {
		if ( empty( $wporg['available'] ) ) {
			return array(
				'score'   => null,
				'reasons' => array( __( 'WordPress.org data unavailable.', 'plugin-reviewer' ) ),
			);
		}

		$points  = 1;
		$reasons = array();
		if ( ! empty( $wporg['closed'] ) ) {
			return array(
				'score'   => 5,
				'reasons' => array( __( 'The plugin was not found in the WordPress.org directory.', 'plugin-reviewer' ) ),
			);
		}

		$updated = ! empty( $wporg['last_updated'] ) ? strtotime( $wporg['last_updated'] ) : false;
		$age     = $updated ? ( time() - $updated ) / YEAR_IN_SECONDS : null;
		if ( null === $age ) {
			++$points;
			$reasons[] = __( 'No last-updated date was reported.', 'plugin-reviewer' );
		} elseif ( $age >= 3 ) {
			$points   += 3;
			$reasons[] = sprintf( /* translators: %s: age in years. */ __( 'Last updated %s years ago.', 'plugin-reviewer' ), number_format_i18n( $age, 1 ) );
		} elseif ( $age >= 2 ) {
			$points   += 2;
			$reasons[] = sprintf( /* translators: %s: age in years. */ __( 'Last updated %s years ago.', 'plugin-reviewer' ), number_format_i18n( $age, 1 ) );
		} elseif ( $age >= 1 ) {
			++$points;
			$reasons[] = sprintf( /* translators: %s: age in years. */ __( 'Last updated %s years ago.', 'plugin-reviewer' ), number_format_i18n( $age, 1 ) );
		}

		$wp_major     = (int) strtok( get_bloginfo( 'version' ), '.' );
		$tested_major = ! empty( $wporg['tested_up_to'] ) ? (int) strtok( (string) $wporg['tested_up_to'], '.' ) : 0;
		if ( 0 === $tested_major ) {
			++$points;
			$reasons[] = __( 'No tested-up-to version was reported.', 'plugin-reviewer' );
		} elseif ( $tested_major < $wp_major ) {
			++$points;
			$reasons[] = __( 'Tested only to an earlier WordPress major version.', 'plugin-reviewer' );
		}

		if ( 1000 > absint( $wporg['active_installs'] ) ) {
			++$points;
			$reasons[] = __( 'Fewer than 1,000 active installs were reported.', 'plugin-reviewer' );
		}

		if ( empty( $reasons ) ) {
			$reasons[] = __( 'No abandonment indicators were found.', 'plugin-reviewer' );
		}

		return array(
			'score'   => min( 5, $points ),
			'reasons' => $reasons,
		);
	}
}
