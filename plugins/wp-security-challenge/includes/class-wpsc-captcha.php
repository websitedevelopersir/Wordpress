<?php
if ( ! defined( 'ABSPATH' ) ) exit;

final class WPSC_Captcha {
    public static function create() {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        for ( $i = 0; $i < 5; $i++ ) {
            $code .= $alphabet[ wp_rand( 0, strlen( $alphabet ) - 1 ) ];
        }

        $id = wp_generate_uuid4();
        set_transient( 'wpsc_captcha_' . md5( $id ), wp_hash_password( $code ), 10 * MINUTE_IN_SECONDS );

        $display = '';
        foreach ( str_split( $code ) as $index => $char ) {
            $rotation = wp_rand( -13, 13 );
            $y = wp_rand( -3, 3 );
            $display .= '<span style="--r:' . esc_attr( $rotation ) . 'deg;--y:' . esc_attr( $y ) . 'px">' . esc_html( $char ) . '</span>';
        }

        return array( 'id' => $id, 'html' => $display );
    }

    public static function verify( $id, $answer ) {
        $id = sanitize_text_field( (string) $id );
        $answer = strtr( (string) $answer, array(
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
        ) );
        $answer = strtoupper( preg_replace( '/[^A-Z0-9]/i', '', $answer ) );
        if ( '' === $id || '' === $answer ) return false;
        $key = 'wpsc_captcha_' . md5( $id );
        $hash = get_transient( $key );
        if ( ! is_string( $hash ) || ! wp_check_password( $answer, $hash ) ) return false;
        delete_transient( $key );
        return true;
    }
}
