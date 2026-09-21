<?php
if ( ! defined( 'ABSPATH' ) ) exit;

final class WPSC_File_Monitor {
    const CRON_HOOK = 'wpsc_file_integrity_scan';
    const LOCK_KEY  = 'wpsc_fim_lock';
    const TRUST_KEY = 'wpsc_fim_trusted_change';
    const DB_VER    = '1.0';

    private static $instance = null;
    private $baseline_table;
    private $events_table;

    public static function instance() {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->baseline_table = $wpdb->prefix . 'wpsc_file_baseline';
        $this->events_table   = $wpdb->prefix . 'wpsc_security_events';

        $this->maybe_install();

        add_filter( 'cron_schedules', array( $this, 'cron_schedules' ) );
        add_action( self::CRON_HOOK, array( $this, 'scan' ) );
        add_action( 'init', array( $this, 'ensure_schedule' ), 30 );

        add_filter( 'upgrader_pre_install', array( $this, 'trusted_upgrade_start' ), 10, 2 );
        add_action( 'upgrader_process_complete', array( $this, 'trusted_upgrade_complete' ), 10, 2 );
        add_action( 'automatic_updates_complete', array( $this, 'trusted_auto_update' ), 10, 1 );
        add_action( 'wp_ajax_edit-theme-plugin-file', array( $this, 'trusted_admin_edit' ), 0 );
        add_action( 'delete_plugin', array( $this, 'trusted_plugin_delete' ), 1 );
        add_action( 'delete_theme', array( $this, 'trusted_theme_delete' ), 1 );

        add_action( 'admin_post_wpsc_fim_scan_now', array( $this, 'handle_scan_now' ) );
        add_action( 'admin_post_wpsc_fim_rebaseline', array( $this, 'handle_rebaseline' ) );
        add_action( 'admin_post_wpsc_fim_clear_events', array( $this, 'handle_clear_events' ) );
        add_action( 'admin_post_wpsc_fim_trust_window', array( $this, 'handle_trust_window' ) );
        add_action( 'admin_notices', array( $this, 'admin_notice' ) );
    }

    public static function defaults() {
        return array(
            'enabled'        => '1',
            'scope'          => 'smart',
            'interval'       => '5',
            'email_enabled'  => '1',
            'email_to'       => '',
            'admin_notice'   => '1',
            'max_scan_time'  => '15',
            'excluded_paths' => "wp-content/cache\nwp-content/upgrade\nwp-content/uploads/cache\nwp-content/ai1wm-backups\nwp-content/updraft\nwp-content/wflogs",
        );
    }

    public static function settings() {
        return wp_parse_args( (array) get_option( 'wpsc_fim_settings', array() ), self::defaults() );
    }

    public static function save_settings( $input ) {
        $d = self::defaults();
        $scope = isset( $input['scope'] ) && 'public_html' === $input['scope'] ? 'public_html' : 'smart';
        $out = array(
            'enabled'        => empty( $input['enabled'] ) ? '0' : '1',
            'scope'          => $scope,
            'interval'       => (string) ( in_array( absint( $input['interval'] ?? 5 ), array( 1, 5, 15 ), true ) ? absint( $input['interval'] ?? 5 ) : 5 ),
            'email_enabled'  => empty( $input['email_enabled'] ) ? '0' : '1',
            'email_to'       => sanitize_email( $input['email_to'] ?? '' ),
            'admin_notice'   => empty( $input['admin_notice'] ) ? '0' : '1',
            'max_scan_time'  => (string) max( 5, min( 60, absint( $input['max_scan_time'] ?? $d['max_scan_time'] ) ) ),
            'excluded_paths' => sanitize_textarea_field( $input['excluded_paths'] ?? $d['excluded_paths'] ),
        );
        update_option( 'wpsc_fim_settings', $out, false );
        self::instance()->ensure_schedule( true );
        return $out;
    }

    public static function activate() {
        self::install_tables();
        update_option( 'wpsc_fim_settings', wp_parse_args( (array) get_option( 'wpsc_fim_settings', array() ), self::defaults() ), false );
        self::instance()->ensure_schedule( true );
        if ( ! get_option( 'wpsc_fim_baseline_ready', false ) ) {
            wp_schedule_single_event( time() + 20, self::CRON_HOOK );
        }
    }

    public static function deactivate() {
        wp_clear_scheduled_hook( self::CRON_HOOK );
        delete_transient( self::LOCK_KEY );
    }

    private function maybe_install() {
        if ( self::DB_VER !== (string) get_option( 'wpsc_fim_db_version', '' ) ) {
            self::install_tables();
        }
    }

    private static function install_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $baseline = $wpdb->prefix . 'wpsc_file_baseline';
        $events   = $wpdb->prefix . 'wpsc_security_events';

        dbDelta( "CREATE TABLE {$baseline} (
            path_hash char(64) NOT NULL,
            path text NOT NULL,
            signature char(64) NOT NULL,
            file_size bigint(20) unsigned NOT NULL DEFAULT 0,
            file_mtime bigint(20) unsigned NOT NULL DEFAULT 0,
            first_seen datetime NOT NULL,
            last_seen datetime NOT NULL,
            PRIMARY KEY (path_hash)
        ) {$charset};" );

        dbDelta( "CREATE TABLE {$events} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_type varchar(24) NOT NULL,
            severity varchar(16) NOT NULL,
            path text NOT NULL,
            signature char(64) NOT NULL DEFAULT '',
            details longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY created_at (created_at),
            KEY severity (severity)
        ) {$charset};" );

        update_option( 'wpsc_fim_db_version', self::DB_VER, false );
    }

    public function cron_schedules( $schedules ) {
        $schedules['wpsc_every_minute'] = array( 'interval' => MINUTE_IN_SECONDS, 'display' => 'WPSC every minute' );
        $schedules['wpsc_every_five_minutes'] = array( 'interval' => 5 * MINUTE_IN_SECONDS, 'display' => 'WPSC every five minutes' );
        $schedules['wpsc_every_fifteen_minutes'] = array( 'interval' => 15 * MINUTE_IN_SECONDS, 'display' => 'WPSC every fifteen minutes' );
        return $schedules;
    }

    public function ensure_schedule( $force = false ) {
        $s = self::settings();
        if ( '1' !== $s['enabled'] ) {
            wp_clear_scheduled_hook( self::CRON_HOOK );
            return;
        }
        $map = array( 1 => 'wpsc_every_minute', 5 => 'wpsc_every_five_minutes', 15 => 'wpsc_every_fifteen_minutes' );
        $minutes = (int) $s['interval'];
        $schedule = $map[ $minutes ] ?? 'wpsc_every_five_minutes';
        $event = wp_get_scheduled_event( self::CRON_HOOK );
        if ( $force || ! $event || $event->schedule !== $schedule ) {
            wp_clear_scheduled_hook( self::CRON_HOOK );
            wp_schedule_event( time() + 60, $schedule, self::CRON_HOOK );
        }
    }

    public function trusted_upgrade_start( $response, $hook_extra ) {
        $this->mark_trusted_change( 'wordpress_upgrader', 15 * MINUTE_IN_SECONDS );
        return $response;
    }
    public function trusted_upgrade_complete( $upgrader, $hook_extra ) { $this->mark_trusted_change( 'wordpress_upgrader_complete', 10 * MINUTE_IN_SECONDS ); }
    public function trusted_auto_update( $results ) { $this->mark_trusted_change( 'automatic_update', 15 * MINUTE_IN_SECONDS ); }
    public function trusted_admin_edit() {
        if ( current_user_can( 'edit_plugins' ) || current_user_can( 'edit_themes' ) ) $this->mark_trusted_change( 'admin_file_editor', 5 * MINUTE_IN_SECONDS );
    }
    public function trusted_plugin_delete() {
        if ( current_user_can( 'delete_plugins' ) ) $this->mark_trusted_change( 'admin_plugin_delete', 10 * MINUTE_IN_SECONDS );
    }
    public function trusted_theme_delete() {
        if ( current_user_can( 'delete_themes' ) ) $this->mark_trusted_change( 'admin_theme_delete', 10 * MINUTE_IN_SECONDS );
    }

    public function mark_trusted_change( $context, $ttl = 600 ) {
        set_transient( self::TRUST_KEY, array(
            'context' => sanitize_key( $context ),
            'user_id' => get_current_user_id(),
            'until'   => time() + absint( $ttl ),
        ), absint( $ttl ) );
    }

    private function is_trusted_window() {
        $trusted = get_transient( self::TRUST_KEY );
        return is_array( $trusted ) && ! empty( $trusted['until'] ) && (int) $trusted['until'] >= time() ? $trusted : false;
    }

    public function scan( $force_baseline = false ) {
        global $wpdb;
        $s = self::settings();
        if ( '1' !== $s['enabled'] ) return array();

        if ( get_transient( self::LOCK_KEY ) ) return array();
        set_transient( self::LOCK_KEY, 1, 2 * MINUTE_IN_SECONDS );

        $started = microtime( true );
        $budget  = max( 5, min( 60, (int) $s['max_scan_time'] ) );
        $stamp   = current_time( 'mysql' );
        $ready   = (bool) get_option( 'wpsc_fim_baseline_ready', false );
        $trusted = $this->is_trusted_window();
        $events  = array();
        $count   = 0;
        $complete = true;

        foreach ( $this->files_for_scope( $s['scope'], $s['excluded_paths'] ) as $absolute => $relative ) {
            if ( microtime( true ) - $started > $budget ) { $complete = false; break; }
            if ( ! is_file( $absolute ) || ! is_readable( $absolute ) ) continue;

            $meta = $this->file_meta( $absolute );
            $hash = hash( 'sha256', $relative );
            $old  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->baseline_table} WHERE path_hash=%s", $hash ), ARRAY_A );

            if ( ! $old ) {
                $wpdb->insert( $this->baseline_table, array(
                    'path_hash' => $hash, 'path' => $relative, 'signature' => $meta['signature'],
                    'file_size' => $meta['size'], 'file_mtime' => $meta['mtime'], 'first_seen' => $stamp, 'last_seen' => $stamp,
                ), array( '%s','%s','%s','%d','%d','%s','%s' ) );
                if ( $ready && ! $trusted && ! $force_baseline ) $events[] = $this->record_event( 'created', $relative, $meta );
            } else {
                $changed = ! hash_equals( (string) $old['signature'], (string) $meta['signature'] );
                $wpdb->update( $this->baseline_table, array(
                    'signature' => $meta['signature'], 'file_size' => $meta['size'], 'file_mtime' => $meta['mtime'], 'last_seen' => $stamp,
                ), array( 'path_hash' => $hash ), array( '%s','%d','%d','%s' ), array( '%s' ) );
                if ( $changed && $ready && ! $trusted && ! $force_baseline ) $events[] = $this->record_event( 'modified', $relative, $meta );
            }
            $count++;
        }

        if ( $complete ) {
            $missing = $wpdb->get_results( $wpdb->prepare(
                "SELECT path_hash,path FROM {$this->baseline_table} WHERE last_seen<>%s", $stamp
            ), ARRAY_A );
            foreach ( (array) $missing as $row ) {
                if ( $ready && ! $trusted && ! $force_baseline ) $events[] = $this->record_event( 'deleted', $row['path'], array( 'signature' => '', 'size' => 0, 'mtime' => 0 ) );
                $wpdb->delete( $this->baseline_table, array( 'path_hash' => $row['path_hash'] ), array( '%s' ) );
            }
            if ( ! $ready || $force_baseline ) update_option( 'wpsc_fim_baseline_ready', 1, false );
        }

        update_option( 'wpsc_fim_last_scan', array(
            'time' => $stamp, 'files' => $count, 'events' => count( $events ), 'complete' => $complete ? 1 : 0,
            'duration' => round( microtime( true ) - $started, 2 ),
        ), false );

        delete_transient( self::LOCK_KEY );
        if ( $events ) $this->send_notification( $events );
        return $events;
    }

    private function files_for_scope( $scope, $excluded_raw ) {
        $excluded = array_filter( array_map( array( $this, 'normalize_relative' ), preg_split( '/[\r\n,]+/', (string) $excluded_raw ) ) );
        $files = array();

        if ( 'public_html' === $scope ) {
            $this->collect_recursive( ABSPATH, '', $files, $excluded, false );
            return $files;
        }

        try {
            foreach ( new DirectoryIterator( ABSPATH ) as $item ) {
                if ( $item->isDot() || ! $item->isFile() ) continue;
                $relative = $this->normalize_relative( $item->getFilename() );
                if ( ! $this->is_excluded( $relative, $excluded ) ) $files[ $item->getPathname() ] = $relative;
            }
        } catch ( Exception $e ) {}

        $this->collect_recursive( ABSPATH . 'wp-admin', 'wp-admin', $files, $excluded, false );
        $this->collect_recursive( ABSPATH . WPINC, WPINC, $files, $excluded, false );
        $this->collect_recursive( WP_CONTENT_DIR, 'wp-content', $files, $excluded, true );
        return $files;
    }

    private function collect_recursive( $root, $prefix, &$files, $excluded, $risky_only ) {
        if ( ! is_dir( $root ) ) return;
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveCallbackFilterIterator(
                    new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
                    function( $current ) use ( $root, $prefix, $excluded ) {
                        $sub = ltrim( str_replace( wp_normalize_path( $root ), '', wp_normalize_path( $current->getPathname() ) ), '/' );
                        $rel = $this->normalize_relative( trim( $prefix . '/' . $sub, '/' ) );
                        return ! $this->is_excluded( $rel, $excluded );
                    }
                ),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ( $iterator as $file ) {
                if ( ! $file->isFile() ) continue;
                $sub = ltrim( str_replace( wp_normalize_path( $root ), '', wp_normalize_path( $file->getPathname() ) ), '/' );
                $relative = $this->normalize_relative( trim( $prefix . '/' . $sub, '/' ) );
                if ( $risky_only && ! $this->is_risky_path( $relative ) ) continue;
                $files[ $file->getPathname() ] = $relative;
            }
        } catch ( UnexpectedValueException $e ) {}
    }

    private function is_risky_path( $path ) {
        $name = strtolower( basename( $path ) );
        if ( in_array( $name, array( '.htaccess', '.user.ini', 'web.config' ), true ) ) return true;
        $ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
        return in_array( $ext, array( 'php','php3','php4','php5','php7','php8','phtml','phar','inc','cgi','pl','py','sh','js','ini','conf' ), true );
    }

    private function normalize_relative( $path ) {
        return ltrim( str_replace( '\\', '/', trim( (string) $path ) ), '/' );
    }

    private function is_excluded( $path, $excluded ) {
        foreach ( $excluded as $item ) {
            if ( $item !== '' && ( $path === $item || 0 === strpos( $path, rtrim( $item, '/' ) . '/' ) ) ) return true;
        }
        return false;
    }

    private function file_meta( $absolute ) {
        $size = (int) @filesize( $absolute );
        $mtime = (int) @filemtime( $absolute );
        $signature = $size <= 20 * MB_IN_BYTES ? @hash_file( 'sha256', $absolute ) : false;
        if ( ! $signature ) $signature = hash( 'sha256', $size . '|' . $mtime . '|' . wp_normalize_path( $absolute ) );
        return array( 'signature' => $signature, 'size' => $size, 'mtime' => $mtime );
    }

    private function record_event( $type, $path, $meta ) {
        global $wpdb;
        $severity = $this->severity( $type, $path );
        $details = wp_json_encode( array(
            'source' => 'external_or_unverified',
            'ip' => WPSC_Risk_Engine::client_ip(),
            'user_id' => get_current_user_id(),
            'size' => (int) $meta['size'],
            'mtime' => (int) $meta['mtime'],
        ) );
        $event = array(
            'event_type' => $type, 'severity' => $severity, 'path' => $path,
            'signature' => (string) $meta['signature'], 'details' => $details, 'created_at' => current_time( 'mysql' ),
        );
        $wpdb->insert( $this->events_table, $event, array( '%s','%s','%s','%s','%s','%s' ) );
        $this->trim_events();
        return $event;
    }

    private function severity( $type, $path ) {
        $p = strtolower( $this->normalize_relative( $path ) );
        $name = basename( $p );
        $ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );

        if ( preg_match( '#^wp-content/uploads/.*\.(?:php\d*|phtml|phar|cgi|pl|py|sh)$#i', $p ) ) return 'critical';
        if ( 0 === strpos( $p, 'wp-admin/' ) || 0 === strpos( $p, 'wp-includes/' ) ) return 'critical';
        if ( in_array( $name, array( 'wp-config.php', '.htaccess', '.user.ini', 'web.config' ), true ) ) return 'critical';
        if ( false === strpos( $p, '/' ) && in_array( $ext, array( 'php','phtml','phar' ), true ) ) return 'critical';
        if ( in_array( $ext, array( 'php','phtml','phar','inc','cgi','sh','py','pl' ), true ) ) return 'high';
        return 'medium';
    }

    private function trim_events() {
        global $wpdb;
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->events_table}" );
        if ( $count <= 500 ) return;
        $remove = $count - 500;
        $ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$this->events_table} ORDER BY id ASC LIMIT %d", $remove ) );
        if ( $ids ) $wpdb->query( "DELETE FROM {$this->events_table} WHERE id IN (" . implode( ',', array_map( 'absint', $ids ) ) . ")" );
    }

    private function send_notification( $events ) {
        $s = self::settings();
        if ( '1' !== $s['email_enabled'] ) return;
        $important = array_values( array_filter( $events, function( $e ) { return in_array( $e['severity'], array( 'critical', 'high' ), true ); } ) );
        if ( ! $important ) return;

        $to = sanitize_email( $s['email_to'] );
        if ( ! $to ) $to = sanitize_email( get_option( 'admin_email' ) );
        if ( ! $to ) return;

        $site = wp_parse_url( home_url(), PHP_URL_HOST );
        $subject = sprintf( '[%s] هشدار تغییر فایل در سایت', $site ?: get_bloginfo( 'name' ) );
        $lines = array(
            'سیستم پایش فایل یک یا چند تغییر مهم و تأییدنشده شناسایی کرده است.',
            '',
            'سایت: ' . home_url( '/' ),
            'زمان: ' . current_time( 'Y-m-d H:i:s' ),
            '',
        );
        foreach ( array_slice( $important, 0, 20 ) as $event ) {
            $lines[] = strtoupper( $event['severity'] ) . ' | ' . $event['event_type'] . ' | ' . $event['path'];
        }
        if ( count( $important ) > 20 ) $lines[] = '... و ' . ( count( $important ) - 20 ) . ' مورد دیگر';
        $lines[] = '';
        $lines[] = 'برای بررسی جزئیات وارد پیشخوان وردپرس > امنیت سایت > پایش فایل‌ها شوید.';
        wp_mail( $to, $subject, implode( "\n", $lines ) );
    }

    public static function status() {
        global $wpdb;
        $self = self::instance();
        $last = get_option( 'wpsc_fim_last_scan', array() );
        $baseline = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$self->baseline_table}" );
        $since = wp_date( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS, wp_timezone() );
        $events24 = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$self->events_table} WHERE created_at >= %s", $since
        ) );
        return array(
            'baseline' => $baseline,
            'events24' => $events24,
            'last' => is_array( $last ) ? $last : array(),
            'trusted' => get_transient( self::TRUST_KEY ),
        );
    }

    public static function events( $limit = 100 ) {
        global $wpdb;
        $self = self::instance();
        $limit = max( 1, min( 500, absint( $limit ) ) );
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$self->events_table} ORDER BY id DESC LIMIT %d", $limit
        ) );
    }

    public function handle_scan_now() {
        $this->require_admin( 'wpsc_fim_scan_now' );
        $events = $this->scan();
        $this->redirect_admin( array( 'wpsc_scan' => count( $events ) ) );
    }

    public function handle_rebaseline() {
        $this->require_admin( 'wpsc_fim_rebaseline' );
        global $wpdb;
        $wpdb->query( "TRUNCATE TABLE {$this->baseline_table}" );
        delete_option( 'wpsc_fim_baseline_ready' );
        $this->scan( true );
        $this->redirect_admin( array( 'wpsc_baseline' => 1 ) );
    }

    public function handle_clear_events() {
        $this->require_admin( 'wpsc_fim_clear_events' );
        global $wpdb;
        $wpdb->query( "TRUNCATE TABLE {$this->events_table}" );
        $this->redirect_admin( array( 'wpsc_cleared' => 1 ) );
    }

    public function handle_trust_window() {
        $this->require_admin( 'wpsc_fim_trust_window' );
        $this->mark_trusted_change( 'manual_admin_window', 15 * MINUTE_IN_SECONDS );
        $this->redirect_admin( array( 'wpsc_trust' => 1 ) );
    }

    private function require_admin( $nonce ) {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Access denied.' );
        check_admin_referer( $nonce );
    }

    private function redirect_admin( $args ) {
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=wpsc-file-monitor' ) ) );
        exit;
    }

    public function admin_notice() {
        $s = self::settings();
        if ( '1' !== $s['admin_notice'] || ! current_user_can( 'manage_options' ) ) return;
        $status = self::status();
        if ( empty( $status['events24'] ) ) return;
        $url = admin_url( 'admin.php?page=wpsc-file-monitor' );
        printf(
            '<div class="notice notice-warning"><p>%s</p></div>',
            wp_kses_post( sprintf(
                'پایش امنیتی فایل‌ها در ۲۴ ساعت گذشته <strong>%d تغییر تأییدنشده</strong> ثبت کرده است. <a href="%s">مشاهده رویدادها</a>',
                absint( $status['events24'] ), esc_url( $url )
            ) )
        );
    }
}
