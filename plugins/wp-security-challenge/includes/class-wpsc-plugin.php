<?php
if ( ! defined( 'ABSPATH' ) ) exit;

final class WPSC_Plugin {
    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        WPSC_Settings::instance();
        WPSC_File_Monitor::instance();
        WPSC_Gate::instance();

        if ( is_admin() ) {
            WPSC_Admin::instance();
            WPSC_File_Monitor_Admin::instance();
        }

        $this->maybe_migrate();
    }

    private function maybe_migrate() {
        $installed = (string) get_option( 'wpsc_version', '1.2.1' );
        if ( version_compare( $installed, WPSC_VERSION, '>=' ) ) return;

        if ( version_compare( $installed, '1.3.0', '<' ) ) {
            $settings = (array) get_option( 'wpsc_settings', array() );
            $map = array(
                'brand_title'      => array( 'بررسی امنیت اتصال', 'تأیید اتصال' ),
                'checking_title'   => array( 'در حال بررسی مرورگر شما', 'در حال بررسی اتصال شما' ),
                'checking_text'    => array( 'برای حفظ امنیت سایت، چند بررسی کوتاه به‌صورت خودکار انجام می‌شود.', 'پیش از ورود، مرورگر و اتصال شما برای چند لحظه بررسی می‌شود.' ),
                'success_title'    => array( 'تأیید با موفقیت انجام شد', 'بررسی انجام شد' ),
                'success_text'     => array( 'در حال هدایت به سایت هستید...', 'در حال انتقال به سایت...' ),
                'privacy_text'     => array( 'این بررسی بدون بارگذاری سرویس خارجی انجام می‌شود.', 'این بررسی به‌صورت خودکار انجام می‌شود.' ),
                'footer_text'      => array( 'محافظت هوشمند از دسترسی سایت', 'امنیت و دسترسی پایدار' ),
                'primary_color'    => array( '#0F2E26', '#1F2937' ),
                'accent_color'     => array( '#2FD6A3', '#F48120' ),
                'background_color' => array( '#F3F7F5', '#F7F7F8' ),
                'text_color'       => array( '#13221D', '#202124' ),
                'muted_color'      => array( '#66756F', '#6B7280' ),
                'radius'           => array( '28', '12' ),
            );

            foreach ( $map as $key => $pair ) {
                if ( isset( $settings[ $key ] ) && (string) $settings[ $key ] === (string) $pair[0] ) {
                    $settings[ $key ] = $pair[1];
                }
            }
            update_option( 'wpsc_settings', $settings, false );
            WPSC_Settings::instance()->refresh();
        }

        update_option( 'wpsc_version', WPSC_VERSION, false );
    }

    public static function activate() {
        $defaults = WPSC_Settings::defaults();
        $current  = get_option( 'wpsc_settings', array() );
        update_option( 'wpsc_settings', wp_parse_args( $current, $defaults ), false );
        update_option( 'wpsc_version', WPSC_VERSION, false );
        WPSC_File_Monitor::activate();
    }

    public static function deactivate() {
        WPSC_File_Monitor::deactivate();
    }
}
