<?php
/**
 * Autoloaded options evidence collector.
 *
 * @package PluginReviewer
 */

namespace PluginReviewer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Audits autoloaded option size and candidate ownership.
 */
class OptionsAuditor {

	/** Cache lifetime. */
	const CACHE_TTL = HOUR_IN_SECONDS;

	/**
	 * Core and common infrastructure prefixes excluded from candidate orphans.
	 *
	 * @var array<int,string>
	 */
	private $safe_prefixes = array(
		'_site_transient_',
		'_transient_',
		'active_plugins',
		'admin_',
		'auto_core_',
		'blog_',
		'can_compress_scripts',
		'category_',
		'close_comments_',
		'comment_',
		'cron',
		'current_theme',
		'dashboard_',
		'db_version',
		'default_',
		'finished_splitting_',
		'fresh_site',
		'gmt_offset',
		'home',
		'html_type',
		'initial_db_version',
		'link_manager_',
		'mailserver_',
		'medium_size_',
		'moderation_',
		'page_',
		'permalink_',
		'ping_',
		'posts_per_',
		'recently_activated',
		'rewrite_rules',
		'rss_',
		'show_',
		'sidebars_widgets',
		'site_icon',
		'siteurl',
		'start_of_week',
		'sticky_posts',
		'tag_base',
		'template',
		'theme_mods_',
		'thumbnail_size_',
		'timezone_string',
		'uninstall_plugins',
		'upload_',
		'users_can_register',
		'widget_',
		'WPLANG',
		'wp_user_roles',
		'wp_page_for_privacy_policy',
		'stylesheet',
	);

	/**
	 * Audit autoloaded options.
	 *
	 * @param array<int,array<string,string>> $plugins Inventory rows.
	 * @return array<string,mixed>
	 */
	public function audit( $plugins ) {
		$cache_key = 'plugin_reviewer_options_' . md5( wp_json_encode( wp_list_pluck( $plugins, 'slug' ) ) );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$rows       = $this->get_autoloaded_rows();
		$identities = $this->plugin_identities( $plugins );
		$attributed = array();
		$options    = array();
		$total      = 0;
		$orphans    = 0;

		foreach ( $rows as $row ) {
			$name      = (string) $row['option_name'];
			$bytes     = absint( $row['option_bytes'] );
			$owner     = $this->find_owner( $name, $identities );
			$safe      = $this->has_prefix( $name, $this->safe_prefixes );
			$candidate = '' === $owner && ! $safe;
			$total    += $bytes;
			if ( $candidate ) {
				$orphans += $bytes;
			}
			if ( '' !== $owner ) {
				$attributed[ $owner ] = isset( $attributed[ $owner ] ) ? $attributed[ $owner ] + $bytes : $bytes;
			}
			$options[] = array(
				'name'              => $name,
				'bytes'             => $bytes,
				'attributed_plugin' => $owner,
				'candidate_orphan'  => $candidate,
			);
		}

		usort(
			$options,
			static function ( $left, $right ) {
				return $right['bytes'] <=> $left['bytes'];
			}
		);
		arsort( $attributed );

		$result = array(
			'total_bytes'            => $total,
			'candidate_orphan_bytes' => $orphans,
			'candidate_orphan_pct'   => 0 < $total ? round( ( $orphans / $total ) * 100, 1 ) : 0,
			'top_options'            => array_slice( $options, 0, 20 ),
			'all_options'            => $options,
			'attributed_bytes'       => $attributed,
		);
		set_transient( $cache_key, $result, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Query autoloaded option names and serialized sizes.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function get_autoloaded_rows() {
		global $wpdb;
		$autoload_values = version_compare( get_bloginfo( 'version' ), '6.6', '>=' )
			? array( 'yes', 'on', 'auto-on', 'auto' )
			: array( 'yes', 'on' );
		$placeholders    = implode( ', ', array_fill( 0, count( $autoload_values ), '%s' ) );
		$sql             = "SELECT option_name, LENGTH(option_value) AS option_bytes FROM {$wpdb->options} WHERE autoload IN ($placeholders)";

		// Direct SQL is required to measure serialized option bytes without loading values into the object cache.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $autoload_values ), ARRAY_A );
	}

	/**
	 * Build normalized plugin identifiers.
	 *
	 * @param array<int,array<string,string>> $plugins Plugins.
	 * @return array<string,array<int,string>>
	 */
	private function plugin_identities( $plugins ) {
		$result = array();
		foreach ( $plugins as $plugin ) {
			$values = array( $plugin['slug'], $plugin['text_domain'] );
			foreach ( $values as $value ) {
				$value = strtolower( trim( (string) $value ) );
				if ( '' !== $value ) {
					$result[ $plugin['slug'] ][] = $value;
					$result[ $plugin['slug'] ][] = str_replace( '-', '_', $value );
				}
			}
			$result[ $plugin['slug'] ] = array_unique( isset( $result[ $plugin['slug'] ] ) ? $result[ $plugin['slug'] ] : array() );
		}
		return $result;
	}

	/**
	 * Find a plugin prefix owner.
	 *
	 * @param string                          $name Option name.
	 * @param array<string,array<int,string>> $identities Plugin identities.
	 * @return string
	 */
	private function find_owner( $name, $identities ) {
		foreach ( $identities as $slug => $prefixes ) {
			if ( $this->has_prefix( $name, $prefixes ) ) {
				return $slug;
			}
		}
		return '';
	}

	/**
	 * Check a delimiter-aware prefix match.
	 *
	 * @param string            $name Name.
	 * @param array<int,string> $prefixes Prefixes.
	 * @return bool
	 */
	private function has_prefix( $name, $prefixes ) {
		$name = strtolower( $name );
		foreach ( $prefixes as $prefix ) {
			$prefix = strtolower( $prefix );
			if ( $name === $prefix || 0 === strpos( $name, $prefix . '_' ) || 0 === strpos( $name, $prefix . '-' ) ) {
				return true;
			}
		}
		return false;
	}
}
