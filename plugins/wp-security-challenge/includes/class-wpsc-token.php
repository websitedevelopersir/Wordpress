<?php
if ( ! defined( 'ABSPATH' ) ) exit;

final class WPSC_Token {
    const COOKIE = 'wpsc_verified';
    const ONCE_COOKIE = 'wpsc_once';

    public static function make( $hours = 6 ) {
        $expires = time() + max( 1, absint( $hours ) ) * HOUR_IN_SECONDS;
        $payload = array(
            'exp' => $expires,
            'ua'  => self::ua_hash(),
            'v'   => 1,
        );
        $data = self::b64url_encode( wp_json_encode( $payload ) );
        $sig  = hash_hmac( 'sha256', $data, wp_salt( 'auth' ) );
        return $data . '.' . $sig;
    }

    public static function validate( $token ) {
        if ( ! is_string( $token ) || false === strpos( $token, '.' ) ) return false;
        list( $data, $sig ) = array_pad( explode( '.', $token, 2 ), 2, '' );
        $calc = hash_hmac( 'sha256', $data, wp_salt( 'auth' ) );
        if ( ! hash_equals( $calc, $sig ) ) return false;
        $json = self::b64url_decode( $data );
        $payload = json_decode( $json, true );
        if ( ! is_array( $payload ) || empty( $payload['exp'] ) || time() >= (int) $payload['exp'] ) return false;
        if ( empty( $payload['ua'] ) || ! hash_equals( (string) $payload['ua'], self::ua_hash() ) ) return false;
        return true;
    }

    public static function set_cookie( $hours ) {
        $hours   = max( 1, absint( $hours ) );
        $expires = time() + $hours * HOUR_IN_SECONDS;
        $token   = self::make( $hours );

        setcookie( self::COOKIE, $token, array(
            'expires'  => $expires,
            'path'     => '/',
            'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ) );

        $_COOKIE[ self::COOKIE ] = $token;
        return $token;
    }

    public static function is_verified() {
        return ! empty( $_COOKIE[ self::COOKIE ] ) && self::validate( wp_unslash( $_COOKIE[ self::COOKIE ] ) );
    }


    public static function set_once_cookie() {
        $expires = time() + 5 * MINUTE_IN_SECONDS;
        $payload = array(
            'exp' => $expires,
            'ua'  => self::ua_hash(),
            'v'   => 1,
            'one' => 1,
            'rnd' => wp_generate_password( 16, false, false ),
        );
        $data = self::b64url_encode( wp_json_encode( $payload ) );
        $sig  = hash_hmac( 'sha256', $data, wp_salt( 'auth' ) );
        $token = $data . '.' . $sig;

        setcookie( self::ONCE_COOKIE, $token, array(
            'expires'  => $expires,
            'path'     => '/',
            'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ) );
        $_COOKIE[ self::ONCE_COOKIE ] = $token;
        return $token;
    }

    public static function consume_once_cookie() {
        if ( empty( $_COOKIE[ self::ONCE_COOKIE ] ) ) return false;
        $token = wp_unslash( $_COOKIE[ self::ONCE_COOKIE ] );
        $valid = self::validate_once( $token );
        self::clear_once_cookie();
        return $valid;
    }

    private static function validate_once( $token ) {
        if ( ! is_string( $token ) || false === strpos( $token, '.' ) ) return false;
        list( $data, $sig ) = array_pad( explode( '.', $token, 2 ), 2, '' );
        $calc = hash_hmac( 'sha256', $data, wp_salt( 'auth' ) );
        if ( ! hash_equals( $calc, $sig ) ) return false;
        $json = self::b64url_decode( $data );
        $payload = json_decode( $json, true );
        if ( ! is_array( $payload ) || empty( $payload['one'] ) || empty( $payload['exp'] ) || time() >= (int) $payload['exp'] ) return false;
        if ( empty( $payload['ua'] ) || ! hash_equals( (string) $payload['ua'], self::ua_hash() ) ) return false;
        return true;
    }

    private static function clear_once_cookie() {
        setcookie( self::ONCE_COOKIE, '', array(
            'expires'  => time() - HOUR_IN_SECONDS,
            'path'     => '/',
            'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ) );
        unset( $_COOKIE[ self::ONCE_COOKIE ] );
    }

    private static function ua_hash() {
        $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
        return substr( hash( 'sha256', $ua ), 0, 24 );
    }

    private static function b64url_encode( $data ) {
        return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
    }

    private static function b64url_decode( $data ) {
        $pad = strlen( $data ) % 4;
        if ( $pad ) $data .= str_repeat( '=', 4 - $pad );
        return base64_decode( strtr( $data, '-_', '+/' ) );
    }
}
