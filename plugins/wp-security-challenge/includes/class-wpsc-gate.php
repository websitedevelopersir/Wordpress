<?php
if ( ! defined( 'ABSPATH' ) ) exit;

final class WPSC_Gate {
    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        $this->bootstrap_no_cache_context();
        add_action( 'template_redirect', array( $this, 'route' ), -9999 );
        add_action( 'wp_ajax_nopriv_wpsc_inspect', array( $this, 'ajax_inspect' ) );
        add_action( 'wp_ajax_nopriv_wpsc_verify_captcha', array( $this, 'ajax_verify_captcha' ) );
        add_action( 'wp_ajax_nopriv_wpsc_refresh_captcha', array( $this, 'ajax_refresh_captcha' ) );
    }

    private function bootstrap_no_cache_context() {
        $is_gate = isset( $_GET['wpsc_gate'] );
        $action  = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
        $is_security_ajax = in_array( $action, array( 'wpsc_inspect', 'wpsc_verify_captcha', 'wpsc_refresh_captcha' ), true );
        if ( $is_gate || $is_security_ajax ) {
            $this->prevent_cache();
        }
    }

    public function route() {
        $always = '1' === (string) WPSC_Settings::instance()->get( 'always_show_challenge', '0' );

        if ( isset( $_GET['wpsc_gate'] ) ) {
            if ( WPSC_Bypass::should_bypass() || ( ! $always && WPSC_Token::is_verified() ) ) {
                wp_safe_redirect( $this->return_url() );
                exit;
            }
            $this->render_gate();
        }

        if ( WPSC_Bypass::should_bypass() ) return;
        if ( $always ) {
            if ( WPSC_Token::consume_once_cookie() ) return;
        } elseif ( WPSC_Token::is_verified() ) {
            return;
        }
        if ( headers_sent() ) return;

        $current = $this->current_url();
        $gate = add_query_arg(
            array(
                'wpsc_gate' => '1',
                'return'    => $this->encode_return( $current ),
            ),
            home_url( '/' )
        );

        wp_safe_redirect( $gate, 302, 'WordPress Security Challenge' );
        exit;
    }

    public function ajax_inspect() {
        $this->prevent_cache();
        check_ajax_referer( 'wpsc_gate', 'nonce' );

        $gate_id = isset( $_POST['gate_id'] ) ? sanitize_text_field( wp_unslash( $_POST['gate_id'] ) ) : '';
        $state = $this->get_gate_state( $gate_id );
        if ( ! $state ) {
            wp_send_json_error( array( 'message' => WPSC_Settings::instance()->get( 'generic_error' ) ), 400 );
        }

        $raw = isset( $_POST['signals'] ) ? wp_unslash( $_POST['signals'] ) : '{}';
        $signals = json_decode( $raw, true );
        if ( ! is_array( $signals ) ) $signals = array();

        $server_elapsed = (int) round( ( microtime( true ) - (float) $state['started'] ) * 1000 );
        $signals['elapsed'] = $server_elapsed;

        $result    = WPSC_Risk_Engine::score( $signals );
        $level     = max( 1, min( 10, absint( WPSC_Settings::instance()->get( 'sensitivity_level', 5 ) ) ) );
        $threshold = 15 + ( $level * 6 ); // 1 => 21 (most sensitive), 10 => 75 (least sensitive).
        $always    = '1' === (string) WPSC_Settings::instance()->get( 'always_show_challenge', '0' );
        $always_captcha = '1' === (string) WPSC_Settings::instance()->get( 'always_challenge_captcha', '0' );
        $min_ms    = max( 200, absint( WPSC_Settings::instance()->get( 'min_check_ms', 650 ) ) );

        if ( $server_elapsed < $min_ms ) {
            $result['score'] = min( 100, $result['score'] + 20 );
            $result['reasons'][] = 'server_too_fast';
        }

        $needs_captcha = $always ? $always_captcha : ( $result['score'] >= $threshold );

        if ( $needs_captcha ) {
            $captcha = WPSC_Captcha::create();
            set_transient( 'wpsc_gate_risk_' . md5( $gate_id ), 1, 10 * MINUTE_IN_SECONDS );
            wp_send_json_success( array(
                'status'     => 'captcha',
                'captcha_id' => $captcha['id'],
                'captcha'    => $captcha['html'],
            ) );
        }

        if ( $always ) {
            WPSC_Token::set_once_cookie();
        } else {
            WPSC_Token::set_cookie( WPSC_Settings::instance()->get( 'cookie_hours', 6 ) );
        }
        $this->clear_gate_state( $gate_id );

        wp_send_json_success( array(
            'status'   => 'verified',
            'redirect' => $this->sanitize_return_url( isset( $state['return'] ) ? $state['return'] : home_url( '/' ) ),
        ) );
    }

    public function ajax_verify_captcha() {
        $this->prevent_cache();
        check_ajax_referer( 'wpsc_gate', 'nonce' );

        $gate_id    = isset( $_POST['gate_id'] ) ? sanitize_text_field( wp_unslash( $_POST['gate_id'] ) ) : '';
        $captcha_id = isset( $_POST['captcha_id'] ) ? sanitize_text_field( wp_unslash( $_POST['captcha_id'] ) ) : '';
        $answer     = isset( $_POST['answer'] ) ? sanitize_text_field( wp_unslash( $_POST['answer'] ) ) : '';
        $state      = $this->get_gate_state( $gate_id );

        if ( ! $state || ! get_transient( 'wpsc_gate_risk_' . md5( $gate_id ) ) ) {
            wp_send_json_error( array( 'message' => WPSC_Settings::instance()->get( 'generic_error' ) ), 400 );
        }

        if ( ! WPSC_Captcha::verify( $captcha_id, $answer ) ) {
            $captcha = WPSC_Captcha::create();
            wp_send_json_error( array(
                'message'    => WPSC_Settings::instance()->get( 'captcha_error' ),
                'captcha_id' => $captcha['id'],
                'captcha'    => $captcha['html'],
            ) );
        }

        if ( '1' === (string) WPSC_Settings::instance()->get( 'always_show_challenge', '0' ) ) {
            WPSC_Token::set_once_cookie();
        } else {
            WPSC_Token::set_cookie( WPSC_Settings::instance()->get( 'cookie_hours', 6 ) );
        }
        delete_transient( 'wpsc_gate_risk_' . md5( $gate_id ) );
        $this->clear_gate_state( $gate_id );

        wp_send_json_success( array(
            'status'   => 'verified',
            'redirect' => $this->sanitize_return_url( isset( $state['return'] ) ? $state['return'] : home_url( '/' ) ),
        ) );
    }

    public function ajax_refresh_captcha() {
        $this->prevent_cache();
        check_ajax_referer( 'wpsc_gate', 'nonce' );
        $gate_id = isset( $_POST['gate_id'] ) ? sanitize_text_field( wp_unslash( $_POST['gate_id'] ) ) : '';
        if ( ! $this->get_gate_state( $gate_id ) || ! get_transient( 'wpsc_gate_risk_' . md5( $gate_id ) ) ) {
            wp_send_json_error( array( 'message' => WPSC_Settings::instance()->get( 'generic_error' ) ), 400 );
        }
        $captcha = WPSC_Captcha::create();
        wp_send_json_success( array( 'captcha_id' => $captcha['id'], 'captcha' => $captcha['html'] ) );
    }

    private function prevent_cache() {
        if ( ! defined( 'DONOTCACHEPAGE' ) ) define( 'DONOTCACHEPAGE', true );
        if ( ! defined( 'DONOTCACHEDB' ) ) define( 'DONOTCACHEDB', true );
        if ( ! defined( 'DONOTCACHEOBJECT' ) ) define( 'DONOTCACHEOBJECT', true );
        if ( ! defined( 'DONOTMINIFY' ) ) define( 'DONOTMINIFY', true );
        if ( ! defined( 'DONOTCDN' ) ) define( 'DONOTCDN', true );

        if ( function_exists( 'nocache_headers' ) ) nocache_headers();
        if ( ! headers_sent() ) {
            header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0, s-maxage=0', true );
            header( 'Pragma: no-cache', true );
            header( 'Expires: 0', true );
            header( 'Surrogate-Control: no-store', true );
            header( 'CDN-Cache-Control: no-store', true );
            header( 'Cloudflare-CDN-Cache-Control: no-store', true );
            header( 'X-LiteSpeed-Cache-Control: no-cache', true );
        }

        do_action( 'litespeed_control_set_nocache', 'WordPress Security Challenge' );
        do_action( 'wpsc_security_nocache' );
    }

    private function render_gate() {
        $this->prevent_cache();
        header( 'X-Robots-Tag: noindex, nofollow, noarchive', true );
        header( 'Referrer-Policy: same-origin', true );
        header( 'X-Content-Type-Options: nosniff', true );

        $s = WPSC_Settings::instance();
        $return  = $this->return_url();
        $gate_id = wp_generate_uuid4();
        set_transient( 'wpsc_gate_' . md5( $gate_id ), array( 'started' => microtime( true ), 'return' => $return ), 10 * MINUTE_IN_SECONDS );

        $config = array(
            'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'wpsc_gate' ),
            'gateId'   => $gate_id,
            'inspectDelay' => max( 360, min( 900, 300 + ( max( 1, min( 10, absint( $s->get( 'sensitivity_level', 5 ) ) ) ) * 55 ) ) ),
            'texts'    => array(
                'successTitle' => $s->get( 'success_title' ),
                'successText'  => $s->get( 'success_text' ),
                'error'        => $s->get( 'generic_error' ),
                'statusBrowser'  => $s->get( 'status_browser' ),
                'statusBehavior' => $s->get( 'status_behavior' ),
                'statusSession'  => $s->get( 'status_session' ),
                'statusExtra'    => $s->get( 'status_extra' ),
                'statusVerified' => $s->get( 'status_verified' ),
                'statusStopped'  => $s->get( 'status_stopped' ),
                'captchaIncomplete' => $s->get( 'captcha_incomplete' ),
            ),
        );
        ?>
<!doctype html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>" dir="rtl">
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex,nofollow,noarchive">
<title><?php echo esc_html( $s->get( 'brand_title' ) ); ?></title>
<link rel="stylesheet" href="<?php echo esc_url( WPSC_URL . 'public/assets/css/gate.css?ver=' . WPSC_VERSION ); ?>">
<style>:root{--wpsc-primary:<?php echo esc_html( $s->get('primary_color') ); ?>;--wpsc-accent:<?php echo esc_html( $s->get('accent_color') ); ?>;--wpsc-surface:<?php echo esc_html( $s->get('surface_color') ); ?>;--wpsc-bg:<?php echo esc_html( $s->get('background_color') ); ?>;--wpsc-text:<?php echo esc_html( $s->get('text_color') ); ?>;--wpsc-muted:<?php echo esc_html( $s->get('muted_color') ); ?>;--wpsc-radius:<?php echo absint( $s->get('radius',28) ); ?>px}</style>
</head>
<body class="wpsc-gate-body">
<main class="wpsc-shell" aria-live="polite">
    <div class="wpsc-orb wpsc-orb-a" aria-hidden="true"></div>
    <div class="wpsc-orb wpsc-orb-b" aria-hidden="true"></div>

    <section class="wpsc-card" id="wpsc-card">
        <div class="wpsc-topline">
            <span class="wpsc-brand-dot"></span>
            <span><?php echo esc_html( $s->get( 'brand_title' ) ); ?></span>
            <span class="wpsc-secure-pill">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2.8 19 5.7v5.7c0 4.5-2.9 8.6-7 9.8-4.1-1.2-7-5.3-7-9.8V5.7L12 2.8Zm-1 12.3 5-5-1.4-1.4-3.6 3.6-1.7-1.7L7.9 12l3.1 3.1Z"/></svg>
                <?php echo esc_html( $s->get( 'secure_label' ) ); ?>
            </span>
        </div>

        <div class="wpsc-stage wpsc-stage-check" id="wpsc-stage-check">
            <div class="wpsc-loader" id="wpsc-loader" aria-hidden="true">
                <div class="wpsc-loader-glow"></div>
                <div class="wpsc-ring wpsc-ring-1"></div>
                <div class="wpsc-ring wpsc-ring-2"></div>
                <div class="wpsc-shield">
                    <svg viewBox="0 0 64 72"><path d="M32 3 57 13v19c0 17-10.7 31.6-25 36C17.7 63.6 7 49 7 32V13L32 3Z"/><path class="wpsc-shield-check" d="m20.5 35 7.1 7.1L44.2 25.5"/></svg>
                </div>
                <div class="wpsc-scan"></div>
            </div>

            <h1 id="wpsc-title"><?php echo esc_html( $s->get( 'checking_title' ) ); ?></h1>
            <p id="wpsc-description"><?php echo esc_html( $s->get( 'checking_text' ) ); ?></p>

            <div class="wpsc-progress" aria-hidden="true"><span id="wpsc-progress-bar"></span></div>
            <div class="wpsc-status-row">
                <span class="wpsc-status-dot"></span>
                <span id="wpsc-status-text"><?php echo esc_html( $s->get( 'status_initial' ) ); ?></span>
            </div>
        </div>

        <div class="wpsc-stage wpsc-stage-captcha" id="wpsc-stage-captcha" hidden>
            <div class="wpsc-captcha-icon">
                <svg viewBox="0 0 24 24"><path d="M12 2a7 7 0 0 0-7 7v2H3.8A1.8 1.8 0 0 0 2 12.8v7.4A1.8 1.8 0 0 0 3.8 22h16.4a1.8 1.8 0 0 0 1.8-1.8v-7.4a1.8 1.8 0 0 0-1.8-1.8H19V9a7 7 0 0 0-7-7Zm0 2a5 5 0 0 1 5 5v2H7V9a5 5 0 0 1 5-5Z"/></svg>
            </div>
            <h2><?php echo esc_html( $s->get( 'captcha_title' ) ); ?></h2>
            <p><?php echo esc_html( $s->get( 'captcha_text' ) ); ?></p>

            <div class="wpsc-captcha-box">
                <div class="wpsc-captcha-code" id="wpsc-captcha-code" aria-label="کد امنیتی"></div>
                <button type="button" class="wpsc-refresh" id="wpsc-refresh" aria-label="<?php echo esc_attr( $s->get('captcha_refresh') ); ?>">
                    <svg viewBox="0 0 24 24"><path d="M20 6v5h-5M4 18v-5h5M6.1 9A7 7 0 0 1 18 6.2L20 11M4 13l2 4.8A7 7 0 0 0 17.9 15"/></svg>
                    <span><?php echo esc_html( $s->get( 'captcha_refresh' ) ); ?></span>
                </button>
            </div>

            <form id="wpsc-captcha-form" novalidate>
                <label for="wpsc-captcha-input"><?php echo esc_html( $s->get( 'captcha_label' ) ); ?></label>
                <input id="wpsc-captcha-input" type="text" inputmode="text" autocomplete="off" autocapitalize="characters" maxlength="5" placeholder="<?php echo esc_attr( $s->get( 'captcha_placeholder' ) ); ?>">
                <div class="wpsc-error" id="wpsc-error" hidden></div>
                <button class="wpsc-submit" id="wpsc-submit" type="submit">
                    <span><?php echo esc_html( $s->get( 'captcha_button' ) ); ?></span>
                    <svg viewBox="0 0 24 24"><path d="m9 18 6-6-6-6"/></svg>
                </button>
            </form>
        </div>

        <div class="wpsc-bottom">
            <span><?php echo esc_html( $s->get( 'privacy_text' ) ); ?></span>
            <span class="wpsc-bottom-sep"></span>
            <span><?php echo esc_html( $s->get( 'footer_text' ) ); ?></span>
        </div>
    </section>
</main>
<script>window.WPSC_GATE=<?php echo wp_json_encode( $config ); ?>;</script>
<script src="<?php echo esc_url( WPSC_URL . 'public/assets/vendor/gsap/gsap.min.js?ver=' . WPSC_VERSION ); ?>"></script>
<script src="<?php echo esc_url( WPSC_URL . 'public/assets/js/gate.js?ver=' . WPSC_VERSION ); ?>"></script>
</body>
</html>
        <?php
        exit;
    }

    private function current_url() {
        $scheme = is_ssl() ? 'https' : 'http';
        $host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : wp_parse_url( home_url(), PHP_URL_HOST );
        $uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
        return esc_url_raw( $scheme . '://' . $host . $uri );
    }

    private function return_url() {
        $raw = isset( $_GET['return'] ) ? sanitize_text_field( wp_unslash( $_GET['return'] ) ) : '';
        $decoded = $this->decode_return( $raw );
        return $this->sanitize_return_url( $decoded );
    }

    private function sanitize_return_url( $url ) {
        $fallback = home_url( '/' );
        $url = esc_url_raw( (string) $url );
        if ( ! $url ) return $fallback;
        $home_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
        $url_host  = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
        if ( ! $home_host || ! $url_host || $home_host !== $url_host ) return $fallback;
        return $url;
    }

    private function encode_return( $value ) {
        return rtrim( strtr( base64_encode( (string) $value ), '+/', '-_' ), '=' );
    }

    private function decode_return( $value ) {
        if ( ! is_string( $value ) || '' === $value ) return home_url( '/' );
        $value = strtr( $value, '-_', '+/' );
        $pad = strlen( $value ) % 4;
        if ( $pad ) $value .= str_repeat( '=', 4 - $pad );
        $decoded = base64_decode( $value, true );
        return false === $decoded ? home_url( '/' ) : $decoded;
    }

    private function get_gate_state( $gate_id ) {
        if ( ! $gate_id ) return false;
        $state = get_transient( 'wpsc_gate_' . md5( $gate_id ) );
        return is_array( $state ) ? $state : false;
    }

    private function clear_gate_state( $gate_id ) {
        if ( $gate_id ) delete_transient( 'wpsc_gate_' . md5( $gate_id ) );
    }
}
