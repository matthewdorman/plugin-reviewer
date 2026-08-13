<?php
/**
 * Plugin Name:       Plugin Reviewer
 * Plugin URI:        https://github.com/matthewdorman/plugin-reviewer
 * Description:       Read-only evidence for reviewing a WordPress plugin stack.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Matt Dorman
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       plugin-reviewer
 * Domain Path:       /languages
 *
 * @package PluginReviewer
 */

namespace PluginReviewer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PLUGIN_REVIEWER_VERSION', '0.1.0' );
define( 'PLUGIN_REVIEWER_FILE', __FILE__ );
define( 'PLUGIN_REVIEWER_DIR', plugin_dir_path( __FILE__ ) );
define( 'PLUGIN_REVIEWER_URL', plugin_dir_url( __FILE__ ) );

require_once PLUGIN_REVIEWER_DIR . 'includes/class-plugin.php';

Plugin::instance()->run();
