<?php
if ( ! defined( 'ABSPATH' ) ) exit;

final class WPSC_Plugin {
    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        WPSC_Settings::instance();
        WPSC_Gate::instance();

        if ( is_admin() ) {
            WPSC_Admin::instance();
        }
    }

    public static function activate() {
        $defaults = WPSC_Settings::defaults();
        $current  = get_option( 'wpsc_settings', array() );
        update_option( 'wpsc_settings', wp_parse_args( $current, $defaults ), false );
    }
}
