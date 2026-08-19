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
		'_wp_',
		'active_plugins',
		'admin_',
		'avatar_default',
		'avatar_rating',
		'auto_core_',
		'auto_plugin_theme_update_emails',
		'auto_update_core_dev',
		'auto_update_core_major',
		'auto_update_core_minor',
		'blog_',
		'blog_charset',
		'blogdescription',
		'blogname',
		'blog_public',
		'can_compress_scripts',
		'category_',
		'close_comments_',
		'comment_',
		'comments_per_page',
		'comments_notify',
		'cron',
		'current_theme',
		'dashboard_',
		'date_format',
		'db_version',
		'default_',
		'disallowed_keys',
		'finished_splitting_',
		'finished_updating_comment_type',
		'fresh_site',
		'gmt_offset',
		'hack_file',
		'home',
		'html_type',
		'https_detection_errors',
		'https_migration_required',
		'image_default_',
		'initial_db_version',
		'link_manager_',
		'links_updated_date_format',
		'large_size_',
		'mailserver_',
		'medium_size_',
		'medium_large_size_',
		'moderation_',
		'new_admin_email',
		'page_',
		'permalink_',
		'ping_',
		'posts_per_',
		'post_count',
		'recently_activated',
		'recently_edited',
		'recovery_keys',
		'recovery_mode_email_last_sent',
		'require_name_email',
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
		'thumbnail_crop',
		'thread_comments',
		'thread_comments_depth',
		'time_format',
		'timezone_string',
		'uninstall_plugins',
		'upload_',
		'uploads_use_yearmonth_folders',
		'use_balanceTags',
		'use_smilies',
		'use_trackback',
		'user_count',
		'users_can_register',
		'widget_',
		'WPLANG',
		'wp_user_roles',
		'wp_page_for_privacy_policy',
		'wp_attachment_pages_enabled',
		'wp_mainsite_attachment_pages_enabled',
		'stylesheet',
	);

	/**
	 * Option prefixes that do not resemble their installed plugin slug.
	 *
	 * Aliases are only enabled when the matching plugin is installed. This keeps
	 * stale aliases visible as candidate orphans after their plugin is removed.
	 *
	 * @var array<string,array<int,string>>
	 */
	private $plugin_aliases = array(
		'admin-menu-editor-pro'       => array( 'ame', 'ws_ame', 'ws_menu_editor_pro' ),
		'all-in-one-wp-migration'     => array( 'ai1wm', 'ai1wmme' ),
		'better-search-replace-pro'   => array( 'bsr' ),
		'enable-media-replace'        => array( 'enablemediareplace' ),
		'easy-accordion-pro'          => array( 'sp_eap' ),
		'disable-comments'            => array( 'disable_comment' ),
		'livemesh-siteorigin-widgets' => array( 'lsow' ),
		'onelogin-saml-sso'           => array( 'onelogin_saml' ),
		'popover'                     => array( 'inc_popup' ),
		'shortcodes-ultimate'         => array( 'su' ),
		'siteorigin-panels'           => array( 'siteorigin_panels' ),
		'siteorigin-premium'          => array( 'siteorigin_premium' ),
		'so-widgets-bundle'           => array( 'siteorigin_widget', 'siteorigin_widgets', 'sow' ),
		'ultimate-branding'           => array( 'branda', 'ub' ),
		'user-role-editor-pro'        => array( 'ure', 'user_role_editor' ),
		'wordpress-seo'               => array( 'wpseo', 'yoast' ),
		'wp-carousel-pro'             => array( 'sp_wpcp', 'wp_carousel_pro', 'wp_mainsite_carousel_pro' ),
		'wp-mail-smtp'                => array( 'wp_mail_smtp', 'wp_mainsite_mail_smtp' ),
		'wp-rocket'                   => array( 'wpr', 'wp_rocket', 'wp_mainsite_rocket' ),
		'wpshapere'                   => array( 'wps', 'wpshapere' ),
	);

	/**
	 * Shared framework prefixes enabled by installed consumers.
	 *
	 * @var array<string,array<string,array<int,string>>>
	 */
	private $framework_aliases = array(
		'framework:freemius'         => array(
			'consumers' => array( 'livemesh-siteorigin-widgets', 'shortcodes-ultimate' ),
			'prefixes'  => array( 'fs' ),
		),
		'framework:action-scheduler' => array(
			'consumers' => array( 'imagify', 'wp-mail-smtp', 'wp-rocket' ),
			'prefixes'  => array( 'action_scheduler', 'as', 'schema-actionscheduler' ),
		),
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
		$identities = array_merge(
			$this->plugin_identities( $plugins ),
			$this->framework_identities( $plugins ),
			$this->theme_identities()
		);
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
			$slug   = (string) $plugin['slug'];
			$values = array(
				$slug,
				$plugin['text_domain'],
				basename( (string) $plugin['file'], '.php' ),
				$this->normalized_name( (string) $plugin['name'] ),
			);
			if ( isset( $this->plugin_aliases[ $slug ] ) ) {
				$values = array_merge( $values, $this->plugin_aliases[ $slug ] );
			}
			foreach ( $values as $value ) {
				$value = strtolower( trim( (string) $value ) );
				if ( '' !== $value ) {
					$result[ $slug ][] = $value;
					$result[ $slug ][] = str_replace( '-', '_', $value );
					$result[ $slug ][] = str_replace( '_', '-', $value );
				}
			}
			$result[ $slug ] = array_unique( isset( $result[ $slug ] ) ? $result[ $slug ] : array() );
		}
		return $result;
	}

	/**
	 * Normalize a plugin display name into a conservative option prefix.
	 *
	 * @param string $name Plugin name.
	 * @return string
	 */
	private function normalized_name( $name ) {
		$name = strtolower( sanitize_title( $name ) );
		$name = preg_replace( '/(?:-for-wordpress|-wordpress|-plugin|-premium|-pro)+$/', '', $name );

		return str_replace( '-', '_', (string) $name );
	}

	/**
	 * Build identities for shared SDKs present in installed plugins.
	 *
	 * @param array<int,array<string,string>> $plugins Plugins.
	 * @return array<string,array<int,string>>
	 */
	private function framework_identities( $plugins ) {
		$installed = wp_list_pluck( $plugins, 'slug' );
		$result    = array();

		foreach ( $this->framework_aliases as $owner => $framework ) {
			if ( array_intersect( $installed, $framework['consumers'] ) ) {
				$result[ $owner ] = $framework['prefixes'];
			}
		}

		return $result;
	}

	/**
	 * Build identities for the active child theme and its parent framework.
	 *
	 * @return array<string,array<int,string>>
	 */
	private function theme_identities() {
		$theme  = wp_get_theme();
		$themes = array();
		$parent = $theme->parent();

		if ( $parent ) {
			$themes[] = $parent;
		}
		$themes[] = $theme;

		$result = array();
		foreach ( $themes as $installed_theme ) {
			$slug   = (string) $installed_theme->get_stylesheet();
			$owner  = 'theme:' . $slug;
			$values = array(
				$slug,
				(string) $installed_theme->get_template(),
				(string) $installed_theme->get( 'TextDomain' ),
				$this->normalized_name( (string) $installed_theme->get( 'Name' ) ),
			);

			foreach ( $values as $value ) {
				$value = strtolower( trim( (string) $value ) );
				if ( '' !== $value ) {
					$result[ $owner ][] = $value;
					$result[ $owner ][] = str_replace( '-', '_', $value );
				}
			}
			$result[ $owner ] = array_unique( isset( $result[ $owner ] ) ? $result[ $owner ] : array() );
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
			$prefix            = strtolower( $prefix );
			$ends_in_delimiter = in_array( substr( $prefix, -1 ), array( '_', '-' ), true );
			if ( $name === $prefix || ( $ends_in_delimiter && 0 === strpos( $name, $prefix ) ) || 0 === strpos( $name, $prefix . '_' ) || 0 === strpos( $name, $prefix . '-' ) ) {
				return true;
			}
		}
		return false;
	}
}
