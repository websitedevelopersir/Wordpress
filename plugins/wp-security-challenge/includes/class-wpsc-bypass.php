<?php
if ( ! defined( 'ABSPATH' ) ) exit;

final class WPSC_Bypass {
    public static function should_bypass() {
        $settings = WPSC_Settings::instance();

        if ( '1' !== (string) $settings->get( 'enabled', '1' ) ) return true;
        if ( defined( 'WP_CLI' ) && WP_CLI ) return true;
        if ( defined( 'DOING_CRON' ) && DOING_CRON ) return true;
        if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) return true;
        if ( '1' === (string) $settings->get( 'bypass_logged_in', '1' ) && is_user_logged_in() ) return true;
        if ( is_admin() ) return true;
        if ( self::is_rest_request() ) return true;
        if ( self::is_safe_system_request() ) return true;
        if ( '1' === (string) $settings->get( 'seo_safe_mode', '1' ) && self::is_search_crawler() ) return true;
        if ( self::is_trusted_ip() ) return true;

        return (bool) apply_filters( 'wpsc_should_bypass', false );
    }

    private static function is_rest_request() {
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return true;
        $uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
        return false !== strpos( $uri, '/wp-json/' );
    }

    private static function is_safe_system_request() {
        $uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
        $path = wp_parse_url( $uri, PHP_URL_PATH );
        $path = is_string( $path ) ? $path : '';

        $safe_fragments = array(
            '/wp-login.php',
            '/wp-cron.php',
            '/xmlrpc.php',
            '/robots.txt',
            '/favicon.ico',
            '/sitemap',
            '/wc-api/',
        );
        foreach ( $safe_fragments as $fragment ) {
            if ( false !== strpos( $path, $fragment ) ) return true;
        }

        if ( isset( $_GET['wc-ajax'] ) || isset( $_GET['rest_route'] ) || isset( $_GET['feed'] ) ) return true;
        $method = strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : 'GET' );
        if ( 'HEAD' === $method ) return true;
        if ( preg_match( '#/(?:feed|comments/feed)/?$#i', $path ) ) return true;
        if ( 'POST' === $method ) return true;

        return false;
    }


    private static function is_search_crawler() {
        $method = strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : 'GET' );
        if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) return false;

        $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
        if ( '' === $ua ) return false;

        return (bool) preg_match(
            '/(?:Googlebot|Google-InspectionTool|GoogleOther|Storebot-Google|AdsBot-Google|Mediapartners-Google|bingbot|BingPreview|DuckDuckBot|Applebot|YandexBot|Baiduspider|Slurp)/i',
            $ua
        );
    }

    private static function is_trusted_ip() {
        $raw = (string) WPSC_Settings::instance()->get( 'trusted_ips', '' );
        if ( '' === trim( $raw ) ) return false;
        $ip = WPSC_Risk_Engine::client_ip();
        $ips = preg_split( '/[\s,]+/', $raw );
        return in_array( $ip, array_filter( array_map( 'trim', (array) $ips ) ), true );
    }
}
