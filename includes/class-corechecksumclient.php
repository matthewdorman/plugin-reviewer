<?php
/**
 * WordPress.org core checksum client.
 *
 * @package PluginReviewer
 */

namespace PluginReviewer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Retrieves authoritative checksums for an official WordPress package.
 */
class CoreChecksumClient {

	/** Cache lifetime. */
	const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Get checksums for one version and package locale.
	 *
	 * @param string $version Installed WordPress version.
	 * @param string $locale  Installed package locale.
	 * @return array<string,mixed>
	 */
	public function get( $version, $locale ) {
		$version   = sanitize_text_field( $version );
		$locale    = sanitize_text_field( $locale );
		$cache_key = 'plugin_reviewer_core_checksums_' . md5( $version . '|' . $locale );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached && is_array( $cached ) ) {
			return array(
				'available' => true,
				'checksums' => $cached,
			);
		}

		if ( ! function_exists( 'get_core_checksums' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}

		$checksums = get_core_checksums( $version, $locale );
		if ( ! is_array( $checksums ) || empty( $checksums ) ) {
			return array(
				'available' => false,
				'checksums' => array(),
			);
		}

		set_transient( $cache_key, $checksums, self::CACHE_TTL );
		return array(
			'available' => true,
			'checksums' => $checksums,
		);
	}
}
