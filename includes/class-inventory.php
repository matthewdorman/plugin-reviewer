<?php
/**
 * Plugin inventory collector.
 *
 * @package PluginReviewer
 */

namespace PluginReviewer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects regular, must-use, and drop-in plugins.
 */
class Inventory {

	/**
	 * Return the full plugin inventory.
	 *
	 * @return array<int,array<string,string>>
	 */
	public function get_all() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}

		$rows = array();
		foreach ( get_plugins() as $file => $data ) {
			$rows[] = $this->format_row(
				$file,
				$data,
				in_array( $file, $active, true ) ? 'active' : 'inactive',
				'standard'
			);
		}

		foreach ( get_mu_plugins() as $file => $data ) {
			$rows[] = $this->format_row( $file, $data, 'active', 'must-use' );
		}

		foreach ( get_dropins() as $file => $data ) {
			$rows[] = $this->format_row( $file, $data, 'active', 'drop-in' );
		}

		usort(
			$rows,
			static function ( $left, $right ) {
				return strcasecmp( $left['name'], $right['name'] );
			}
		);

		return $rows;
	}

	/**
	 * Normalize one inventory row.
	 *
	 * @param string              $file Plugin file.
	 * @param array<string,mixed> $data Plugin header data.
	 * @param string              $status Status.
	 * @param string              $type Plugin type.
	 * @return array<string,string>
	 */
	private function format_row( $file, $data, $status, $type ) {
		$directory = dirname( $file );
		$slug      = '.' === $directory ? sanitize_title( basename( $file, '.php' ) ) : sanitize_title( $directory );

		return array(
			'file'        => sanitize_text_field( $file ),
			'name'        => sanitize_text_field( isset( $data['Name'] ) ? $data['Name'] : $slug ),
			'slug'        => $slug,
			'version'     => sanitize_text_field( isset( $data['Version'] ) ? $data['Version'] : '' ),
			'status'      => $status,
			'type'        => $type,
			'author'      => wp_strip_all_tags( isset( $data['AuthorName'] ) ? $data['AuthorName'] : ( isset( $data['Author'] ) ? $data['Author'] : '' ) ),
			'text_domain' => sanitize_key( isset( $data['TextDomain'] ) ? $data['TextDomain'] : '' ),
		);
	}
}
