<?php
if ( ! defined( 'ABSPATH' ) ) exit;

final class WPSC_Settings {
    private static $instance = null;
    private $settings = array();

    public static function instance() {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        $this->settings = wp_parse_args( (array) get_option( 'wpsc_settings', array() ), self::defaults() );
    }

    public static function defaults() {
        return array(
            'enabled'                  => '1',
            'bypass_logged_in'         => '1',
            'always_show_challenge'    => '0',
            'always_challenge_captcha' => '0',
            'seo_safe_mode'            => '1',
            'cookie_hours'             => '6',
            'risk_threshold'           => '45',
            'sensitivity_level'        => '5',
            'rate_limit_per_minute'    => '25',
            'min_check_ms'             => '650',
            'trusted_ips'              => '',
            'brand_title'              => 'تأیید اتصال',
            'secure_label'             => 'اتصال امن',
            'checking_title'           => 'در حال بررسی اتصال شما',
            'checking_text'            => 'پیش از ورود، مرورگر و اتصال شما برای چند لحظه بررسی می‌شود.',
            'status_initial'            => 'شروع بررسی امنیتی...',
            'status_browser'            => 'بررسی مرورگر...',
            'status_behavior'           => 'بررسی رفتار اتصال...',
            'status_session'            => 'تأیید نشست...',
            'status_extra'              => 'نیاز به تأیید تکمیلی',
            'status_verified'           => 'بررسی با موفقیت انجام شد',
            'status_stopped'            => 'امکان تکمیل بررسی وجود ندارد',
            'success_title'             => 'بررسی انجام شد',
            'success_text'              => 'در حال انتقال به سایت...',
            'captcha_title'             => 'تأیید کنید انسان هستید',
            'captcha_text'              => 'برای ادامه، کد امنیتی زیر را وارد کنید.',
            'captcha_label'             => 'کد امنیتی',
            'captcha_placeholder'       => 'کد را وارد کنید',
            'captcha_button'            => 'ادامه',
            'captcha_refresh'           => 'کد جدید',
            'captcha_error'             => 'کد واردشده صحیح نیست. دوباره تلاش کنید.',
            'captcha_incomplete'        => 'لطفاً کد امنیتی را کامل وارد کنید.',
            'generic_error'             => 'امکان تکمیل بررسی وجود ندارد. صفحه را دوباره بارگذاری کنید.',
            'privacy_text'              => 'این بررسی به‌صورت خودکار انجام می‌شود.',
            'footer_text'               => 'امنیت و دسترسی پایدار',
            'primary_color'             => '#1F2937',
            'accent_color'              => '#F48120',
            'surface_color'             => '#FFFFFF',
            'background_color'          => '#FFFFFF',
            'text_color'                => '#202124',
            'muted_color'               => '#6B7280',
            'radius'                    => '12',
        );
    }

    public function get( $key, $default = null ) {
        return array_key_exists( $key, $this->settings ) ? $this->settings[ $key ] : $default;
    }

    public function all() {
        return $this->settings;
    }

    public function refresh() {
        $this->settings = wp_parse_args( (array) get_option( 'wpsc_settings', array() ), self::defaults() );
    }
}
