<?php
if ( ! defined( 'ABSPATH' ) ) exit;

final class WPSC_Risk_Engine {
    public static function score( array $signals ) {
        $score   = 0;
        $reasons = array();
        $ua      = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( (string) $_SERVER['HTTP_USER_AGENT'] ) : '';

        if ( '' === trim( $ua ) ) {
            $score += 45;
            $reasons[] = 'missing_user_agent';
        }

        $bot_terms = array( 'headlesschrome', 'phantomjs', 'selenium', 'playwright', 'puppeteer', 'python-requests', 'curl/', 'wget/', 'scrapy' );
        foreach ( $bot_terms as $term ) {
            if ( false !== strpos( $ua, $term ) ) {
                $score += 50;
                $reasons[] = 'automation_ua';
                break;
            }
        }

        if ( ! empty( $signals['webdriver'] ) ) {
            $score += 55;
            $reasons[] = 'webdriver';
        }
        if ( isset( $signals['cookieEnabled'] ) && ! $signals['cookieEnabled'] ) {
            $score += 14;
            $reasons[] = 'cookies_disabled';
        }
        if ( isset( $signals['localStorage'] ) && ! $signals['localStorage'] ) {
            $score += 10;
            $reasons[] = 'storage_unavailable';
        }
        if ( empty( $signals['language'] ) ) {
            $score += 8;
            $reasons[] = 'missing_language';
        }
        if ( isset( $signals['screenW'], $signals['screenH'] ) && ( (int) $signals['screenW'] < 100 || (int) $signals['screenH'] < 100 ) ) {
            $score += 12;
            $reasons[] = 'invalid_screen';
        }
        if ( isset( $signals['elapsed'] ) && (int) $signals['elapsed'] < 250 ) {
            $score += 18;
            $reasons[] = 'too_fast';
        }
        if ( isset( $signals['hardwareConcurrency'] ) && 0 === (int) $signals['hardwareConcurrency'] ) {
            $score += 8;
            $reasons[] = 'invalid_hardware';
        }

        $hits = self::rate_hits();
        $limit = max( 5, absint( WPSC_Settings::instance()->get( 'rate_limit_per_minute', 25 ) ) );
        if ( $hits > $limit ) {
            $score += min( 45, 15 + ( $hits - $limit ) * 2 );
            $reasons[] = 'rate_limit';
        }

        $score = max( 0, min( 100, (int) apply_filters( 'wpsc_risk_score', $score, $signals, $reasons ) ) );
        return array( 'score' => $score, 'reasons' => $reasons );
    }

    public static function client_ip() {
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
        return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
    }

    private static function rate_hits() {
        $key = 'wpsc_rate_' . md5( self::client_ip() );
        $hits = (int) get_transient( $key );
        $hits++;
        set_transient( $key, $hits, MINUTE_IN_SECONDS + 5 );
        return $hits;
    }
}
