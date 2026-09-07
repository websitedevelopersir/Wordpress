<?php
/**
 * Plugin Name: WordPress Security Challenge
 * Description: Lightweight risk-based browser verification and conditional CAPTCHA gate for guest visitors.
 * Version: 1.2.1
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: Mode Media
 * Text Domain: wp-security-challenge
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WPSC_VERSION', '1.2.1' );
define( 'WPSC_FILE', __FILE__ );
define( 'WPSC_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPSC_URL', plugin_dir_url( __FILE__ ) );

require_once WPSC_DIR . 'includes/class-wpsc-settings.php';
require_once WPSC_DIR . 'includes/class-wpsc-token.php';
require_once WPSC_DIR . 'includes/class-wpsc-bypass.php';
require_once WPSC_DIR . 'includes/class-wpsc-risk-engine.php';
require_once WPSC_DIR . 'includes/class-wpsc-captcha.php';
require_once WPSC_DIR . 'includes/class-wpsc-gate.php';
require_once WPSC_DIR . 'admin/class-wpsc-admin.php';
require_once WPSC_DIR . 'includes/class-wpsc-plugin.php';

register_activation_hook( __FILE__, array( 'WPSC_Plugin', 'activate' ) );
WPSC_Plugin::instance();
