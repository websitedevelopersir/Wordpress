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
            'always_show_challenge'     => '0',
            'always_challenge_captcha' => '0',
            'seo_safe_mode'             => '1',
            'cookie_hours'             => '6',
            'risk_threshold'           => '45', // legacy compatibility
            'sensitivity_level'        => '5',
            'rate_limit_per_minute'    => '25',
            'min_check_ms'              => '650',
            'trusted_ips'              => '',
            'brand_title'              => 'بررسی امنیت اتصال',
            'secure_label'             => 'امن',
            'checking_title'           => 'در حال بررسی مرورگر شما',
            'checking_text'            => 'برای حفظ امنیت سایت، چند بررسی کوتاه به‌صورت خودکار انجام می‌شود.',
            'status_initial'            => 'در حال تحلیل اتصال و رفتار مرورگر...',
            'status_browser'            => 'بررسی قابلیت‌های مرورگر...',
            'status_behavior'           => 'تحلیل رفتار اتصال...',
            'status_session'            => 'اعتبارسنجی نشست امنیتی...',
            'status_extra'              => 'نیاز به تأیید تکمیلی',
            'status_verified'           => 'اتصال امن تأیید شد',
            'status_stopped'            => 'بررسی متوقف شد',
            'success_title'            => 'تأیید با موفقیت انجام شد',
            'success_text'             => 'در حال هدایت به سایت هستید...',
            'captcha_title'            => 'یک مرحله تا ورود باقی مانده',
            'captcha_text'             => 'کد امنیتی نمایش‌داده‌شده را وارد کنید.',
            'captcha_label'            => 'کد امنیتی',
            'captcha_placeholder'      => 'کد را وارد کنید',
            'captcha_button'           => 'تأیید و ورود',
            'captcha_refresh'          => 'تغییر کد',
            'captcha_error'            => 'کد واردشده صحیح نیست. دوباره تلاش کنید.',
            'captcha_incomplete'       => 'لطفاً کد امنیتی را کامل وارد کنید.',
            'generic_error'            => 'در بررسی امنیتی مشکلی پیش آمد. صفحه را دوباره بارگذاری کنید.',
            'privacy_text'             => 'این بررسی بدون بارگذاری سرویس خارجی انجام می‌شود.',
            'footer_text'              => 'محافظت هوشمند از دسترسی سایت',
            'primary_color'            => '#0F2E26',
            'accent_color'             => '#2FD6A3',
            'surface_color'            => '#FFFFFF',
            'background_color'         => '#F3F7F5',
            'text_color'               => '#13221D',
            'muted_color'              => '#66756F',
            'radius'                   => '28',
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
