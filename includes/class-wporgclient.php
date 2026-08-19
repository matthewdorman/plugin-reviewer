<?php
/**
 * WordPress.org plugin information client.
 *
 * @package PluginReviewer
 */

namespace PluginReviewer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Retrieves and caches public directory metadata.
 */
class WporgClient {

	/** Cache lifetime. */
	const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Fetch directory evidence for a slug.
	 *
	 * @param string $slug Plugin slug.
	 * @return array<string,mixed>
	 */
	public function get( $slug ) {
		$slug      = sanitize_key( $slug );
		$cache_key = 'plugin_reviewer_wporg_' . md5( $slug );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$url      = add_query_arg(
			array(
				'action'          => 'plugin_information',
				'request[slug]'   => $slug,
				'request[fields]' => wp_json_encode(
					array(
						'sections' => false,
						'icons'    => false,
						'banners'  => false,
					)
				),
			),
			'https://api.wordpress.org/plugins/info/1.2/'
		);
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'    => 8,
				'user-agent' => 'Plugin Reviewer/' . PLUGIN_REVIEWER_VERSION . '; ' . home_url( '/' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->unavailable();
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $status || ! is_array( $body ) ) {
			return $this->unavailable();
		}

		if ( isset( $body['error'] ) ) {
			$result = array(
				'available'       => true,
				'found'           => false,
				'closed'          => true,
				'last_updated'    => '',
				'tested_up_to'    => '',
				'active_installs' => 0,
			);
		} else {
			$result = array(
				'available'       => true,
				'found'           => true,
				'closed'          => false,
				'last_updated'    => sanitize_text_field( isset( $body['last_updated'] ) ? $body['last_updated'] : '' ),
				'tested_up_to'    => sanitize_text_field( isset( $body['tested'] ) ? $body['tested'] : '' ),
				'active_installs' => absint( isset( $body['active_installs'] ) ? $body['active_installs'] : 0 ),
			);
		}

		set_transient( $cache_key, $result, self::CACHE_TTL );
		return $result;
	}

	/**
	 * Offline response.
	 *
	 * @return array<string,mixed>
	 */
	private function unavailable() {
		return array(
			'available'       => false,
			'found'           => false,
			'closed'          => false,
			'last_updated'    => '',
			'tested_up_to'    => '',
			'active_installs' => 0,
		);
	}
}
