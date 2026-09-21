<?php
if ( ! defined( 'ABSPATH' ) ) exit;

final class WPSC_File_Monitor_Admin {
    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', array( $this, 'menu' ), 20 );
        add_action( 'admin_init', array( $this, 'save' ) );
    }

    public function menu() {
        add_submenu_page(
            'wpsc-security',
            'پایش فایل‌ها',
            'پایش فایل‌ها',
            'manage_options',
            'wpsc-file-monitor',
            array( $this, 'page' )
        );
    }

    public function save() {
        if ( ! isset( $_POST['wpsc_fim_save'] ) ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;
        check_admin_referer( 'wpsc_fim_save_settings' );
        WPSC_File_Monitor::save_settings( wp_unslash( $_POST['wpsc_fim'] ?? array() ) );
        wp_safe_redirect( add_query_arg( array( 'page' => 'wpsc-file-monitor', 'saved' => 1 ), admin_url( 'admin.php' ) ) );
        exit;
    }

    public function page() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $s = WPSC_File_Monitor::settings();
        $status = WPSC_File_Monitor::status();
        $events = WPSC_File_Monitor::events( 100 );
        $last = $status['last'];
        ?>
        <div class="wrap wpsc-fim-wrap" dir="rtl">
            <style><?php echo $this->css(); ?></style>

            <div class="wpsc-fim-head">
                <div>
                    <span class="wpsc-fim-eyebrow">FILE INTEGRITY MONITOR</span>
                    <h1>پایش تغییرات فایل‌های سایت</h1>
                    <p>ساخت، تغییر یا حذف فایل‌های مهم وردپرس را با Baseline و SHA-256 بررسی می‌کند و تغییرات تأییدنشده را ثبت می‌کند.</p>
                </div>
                <span class="wpsc-fim-state <?php echo '1' === $s['enabled'] ? 'on' : 'off'; ?>">
                    <?php echo '1' === $s['enabled'] ? 'فعال' : 'غیرفعال'; ?>
                </span>
            </div>

            <?php if ( isset( $_GET['saved'] ) ) : ?><div class="notice notice-success is-dismissible"><p>تنظیمات پایش فایل ذخیره شد.</p></div><?php endif; ?>
            <?php if ( isset( $_GET['wpsc_baseline'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Baseline جدید ساخته شد.</p></div><?php endif; ?>
            <?php if ( isset( $_GET['wpsc_trust'] ) ) : ?><div class="notice notice-info is-dismissible"><p>پنجره تغییر مجاز برای ۱۵ دقیقه فعال شد.</p></div><?php endif; ?>

            <div class="wpsc-fim-stats">
                <?php $this->stat( 'فایل‌های Baseline', number_format_i18n( $status['baseline'] ), 'تعداد فایل‌های تحت پایش' ); ?>
                <?php $this->stat( 'رویداد ۲۴ ساعت', number_format_i18n( $status['events24'] ), 'تغییرات تأییدنشده ثبت‌شده' ); ?>
                <?php $this->stat( 'آخرین اسکن', ! empty( $last['time'] ) ? $last['time'] : 'هنوز اجرا نشده', ! empty( $last['files'] ) ? number_format_i18n( $last['files'] ) . ' فایل بررسی شد' : '—' ); ?>
                <?php $this->stat( 'تغییر مجاز', ! empty( $status['trusted'] ) ? 'فعال' : 'غیرفعال', ! empty( $status['trusted']['context'] ) ? $status['trusted']['context'] : 'هیچ پنجره فعالی نیست' ); ?>
            </div>

            <div class="wpsc-fim-grid">
                <section class="wpsc-fim-card">
                    <div class="wpsc-fim-card-head">
                        <div><h2>تنظیمات مانیتورینگ</h2><p>حالت هوشمند برای اکثر سایت‌ها پیشنهاد می‌شود.</p></div>
                    </div>

                    <form method="post">
                        <?php wp_nonce_field( 'wpsc_fim_save_settings' ); ?>
                        <input type="hidden" name="wpsc_fim_save" value="1">

                        <?php $this->toggle( 'enabled', 'فعال بودن پایش فایل', 'بررسی دوره‌ای فایل‌ها و ثبت تغییرات', $s ); ?>
                        <?php $this->toggle( 'email_enabled', 'ارسال هشدار ایمیلی', 'برای رویدادهای High و Critical ایمیل ارسال شود.', $s ); ?>
                        <?php $this->toggle( 'admin_notice', 'هشدار داخل پیشخوان', 'در صورت وجود رویدادهای جدید، اعلان مدیریتی نمایش داده شود.', $s ); ?>

                        <div class="wpsc-fim-fields">
                            <div class="wpsc-fim-field">
                                <label>محدوده پایش</label>
                                <select name="wpsc_fim[scope]">
                                    <option value="smart" <?php selected( $s['scope'], 'smart' ); ?>>هوشمند — هسته کامل + فایل‌های اجرایی wp-content</option>
                                    <option value="public_html" <?php selected( $s['scope'], 'public_html' ); ?>>کامل — تمام ریشه نصب وردپرس (معمولاً public_html)</option>
                                </select>
                                <small>حالت کامل روی هاست‌های بزرگ سنگین‌تر است.</small>
                            </div>

                            <div class="wpsc-fim-field">
                                <label>فاصله بررسی</label>
                                <select name="wpsc_fim[interval]">
                                    <option value="1" <?php selected( $s['interval'], '1' ); ?>>هر ۱ دقیقه</option>
                                    <option value="5" <?php selected( $s['interval'], '5' ); ?>>هر ۵ دقیقه — پیشنهادی</option>
                                    <option value="15" <?php selected( $s['interval'], '15' ); ?>>هر ۱۵ دقیقه</option>
                                </select>
                                <small>WP-Cron به ترافیک سایت وابسته است و تضمین زمان دقیق ندارد.</small>
                            </div>

                            <div class="wpsc-fim-field">
                                <label>حداکثر زمان هر اسکن (ثانیه)</label>
                                <input type="number" name="wpsc_fim[max_scan_time]" min="5" max="60" value="<?php echo esc_attr( $s['max_scan_time'] ); ?>">
                            </div>

                            <div class="wpsc-fim-field">
                                <label>ایمیل هشدار</label>
                                <input type="email" name="wpsc_fim[email_to]" value="<?php echo esc_attr( $s['email_to'] ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
                                <small>اگر خالی باشد ایمیل مدیر وردپرس استفاده می‌شود.</small>
                            </div>
                        </div>

                        <div class="wpsc-fim-field">
                            <label>مسیرهای مستثنی</label>
                            <textarea name="wpsc_fim[excluded_paths]" rows="7"><?php echo esc_textarea( $s['excluded_paths'] ); ?></textarea>
                            <small>هر مسیر نسبت به ریشه وردپرس در یک خط. پوشه‌های Cache و Backup بهتر است مستثنی بمانند.</small>
                        </div>

                        <p><button class="button button-primary" type="submit">ذخیره تنظیمات</button></p>
                    </form>
                </section>

                <section class="wpsc-fim-card">
                    <div class="wpsc-fim-card-head"><div><h2>کنترل و Baseline</h2><p>Baseline باید از یک نسخه سالم سایت ساخته شود.</p></div></div>

                    <div class="wpsc-fim-action">
                        <strong>اسکن فوری</strong>
                        <span>همین حالا فایل‌ها را با Baseline مقایسه می‌کند.</span>
                        <?php $this->action_button( 'wpsc_fim_scan_now', 'wpsc_fim_scan_now', 'اجرای اسکن' ); ?>
                    </div>
                    <div class="wpsc-fim-action">
                        <strong>ساخت مجدد Baseline</strong>
                        <span>بعد از اطمینان از سالم بودن سایت استفاده کنید. وضعیت فعلی به‌عنوان مبنا ثبت می‌شود.</span>
                        <?php $this->action_button( 'wpsc_fim_rebaseline', 'wpsc_fim_rebaseline', 'ساخت Baseline', 'button-secondary' ); ?>
                    </div>
                    <div class="wpsc-fim-action">
                        <strong>پنجره تغییر مجاز ۱۵ دقیقه‌ای</strong>
                        <span>برای File Manager، SSH یا تغییراتی که افزونه نمی‌تواند مستقیماً به یک مدیر نسبت دهد.</span>
                        <?php $this->action_button( 'wpsc_fim_trust_window', 'wpsc_fim_trust_window', 'فعال‌سازی تغییر مجاز', 'button-secondary' ); ?>
                    </div>

                    <div class="wpsc-fim-note">
                        <strong>تشخیص منبع تغییر</strong>
                        <p>افزونه نصب/آپدیت استاندارد وردپرس، File Editor و حذف افزونه/قالب توسط کاربران مجاز را به‌عنوان «پنجره تغییر معتبر» می‌شناسد. تغییر فایل توسط FTP، SSH، بدافزار یا اسکریپت ناشناس در این پنجره‌ها نباشد به‌عنوان تغییر تأییدنشده ثبت می‌شود.</p>
                        <p>در سطح PHP نمی‌توان PID یا پردازش سیستم‌عامل ایجادکننده فایل را با قطعیت ۱۰۰٪ تشخیص داد؛ برای آن باید روی سرور از inotify/auditd استفاده شود.</p>
                    </div>
                </section>
            </div>

            <section class="wpsc-fim-card wpsc-fim-events">
                <div class="wpsc-fim-card-head">
                    <div><h2>آخرین رویدادها</h2><p>Critical و High را در اولویت بررسی قرار دهید.</p></div>
                    <?php if ( $events ) $this->action_button( 'wpsc_fim_clear_events', 'wpsc_fim_clear_events', 'پاک کردن تاریخچه', 'button-link-delete' ); ?>
                </div>

                <?php if ( ! $events ) : ?>
                    <div class="wpsc-fim-empty">هنوز تغییر تأییدنشده‌ای ثبت نشده است.</div>
                <?php else : ?>
                    <div class="wpsc-fim-table-wrap">
                        <table class="widefat striped">
                            <thead><tr><th>سطح</th><th>نوع</th><th>فایل</th><th>زمان</th></tr></thead>
                            <tbody>
                            <?php foreach ( $events as $event ) : ?>
                                <tr>
                                    <td><span class="wpsc-severity <?php echo esc_attr( $event->severity ); ?>"><?php echo esc_html( strtoupper( $event->severity ) ); ?></span></td>
                                    <td><?php echo esc_html( $this->event_label( $event->event_type ) ); ?></td>
                                    <td><code><?php echo esc_html( $event->path ); ?></code></td>
                                    <td><?php echo esc_html( $event->created_at ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        </div>
        <?php
    }

    private function stat( $label, $value, $desc ) {
        echo '<div class="wpsc-fim-stat"><span>' . esc_html( $label ) . '</span><strong>' . esc_html( $value ) . '</strong><small>' . esc_html( $desc ) . '</small></div>';
    }

    private function toggle( $key, $title, $desc, $s ) {
        ?><label class="wpsc-fim-toggle"><span><strong><?php echo esc_html( $title ); ?></strong><small><?php echo esc_html( $desc ); ?></small></span><input type="checkbox" name="wpsc_fim[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( $s[ $key ], '1' ); ?>><i></i></label><?php
    }

    private function action_button( $action, $nonce, $label, $class = 'button-secondary' ) {
        $url = wp_nonce_url( admin_url( 'admin-post.php?action=' . $action ), $nonce );
        echo '<a class="button ' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
    }

    private function event_label( $type ) {
        $map = array( 'created' => 'فایل جدید', 'modified' => 'تغییر فایل', 'deleted' => 'حذف فایل' );
        return $map[ $type ] ?? $type;
    }

    private function css() {
        $font = esc_url_raw( WPSC_URL . 'public/assets/fonts/Vazirmatn-UI-FD-NL-Regular.woff2' );
        return '@font-face{font-family:Vazirmatn;src:url("' . $font . '") format("woff2");font-display:swap}.wpsc-fim-wrap{max-width:1180px;margin:28px 18px 45px 0;font-family:Vazirmatn,Tahoma,sans-serif}.wpsc-fim-head{display:flex;justify-content:space-between;align-items:center;gap:24px;background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:24px 26px}.wpsc-fim-eyebrow{font-size:10px;letter-spacing:1.2px;color:#6b7280}.wpsc-fim-head h1{margin:5px 0 7px;font-size:23px}.wpsc-fim-head p{margin:0;color:#6b7280;font-size:11px;line-height:2}.wpsc-fim-state{padding:8px 14px;border-radius:999px;font-size:11px;font-weight:800}.wpsc-fim-state.on{background:#ecfdf5;color:#047857}.wpsc-fim-state.off{background:#fef2f2;color:#b91c1c}.wpsc-fim-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:14px 0}.wpsc-fim-stat{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:16px;display:flex;flex-direction:column;gap:5px}.wpsc-fim-stat span,.wpsc-fim-stat small{font-size:10px;color:#6b7280}.wpsc-fim-stat strong{font-size:17px}.wpsc-fim-grid{display:grid;grid-template-columns:1.2fr .8fr;gap:14px}.wpsc-fim-card{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:20px}.wpsc-fim-card-head{display:flex;align-items:center;justify-content:space-between;gap:16px;border-bottom:1px solid #eef0f2;padding-bottom:14px;margin-bottom:14px}.wpsc-fim-card-head h2{margin:0 0 4px;font-size:15px}.wpsc-fim-card-head p{margin:0;font-size:10px;color:#6b7280}.wpsc-fim-toggle{display:flex;justify-content:space-between;align-items:center;gap:15px;padding:12px 0;border-bottom:1px solid #f2f3f5}.wpsc-fim-toggle>span{display:flex;flex-direction:column;gap:4px}.wpsc-fim-toggle strong{font-size:11px}.wpsc-fim-toggle small{font-size:9px;color:#6b7280}.wpsc-fim-toggle input{display:none}.wpsc-fim-toggle i{width:40px;height:22px;border-radius:99px;background:#d1d5db;position:relative;flex:none}.wpsc-fim-toggle i:after{content:"";position:absolute;width:16px;height:16px;right:3px;top:3px;background:#fff;border-radius:50%;transition:.2s}.wpsc-fim-toggle input:checked+i{background:#1f2937}.wpsc-fim-toggle input:checked+i:after{right:21px}.wpsc-fim-fields{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:15px}.wpsc-fim-field{display:flex;flex-direction:column;gap:6px;margin-top:14px}.wpsc-fim-fields .wpsc-fim-field{margin-top:0}.wpsc-fim-field label{font-size:10px;font-weight:800}.wpsc-fim-field small{font-size:9px;color:#6b7280;line-height:1.7}.wpsc-fim-field input,.wpsc-fim-field select,.wpsc-fim-field textarea{width:100%;max-width:none;border:1px solid #d9dde3;border-radius:9px;min-height:40px;font-family:Vazirmatn,Tahoma,sans-serif;font-size:10px;box-shadow:none}.wpsc-fim-field textarea{resize:vertical}.wpsc-fim-action{display:flex;flex-direction:column;gap:7px;padding:14px 0;border-bottom:1px solid #f0f1f3}.wpsc-fim-action strong{font-size:11px}.wpsc-fim-action span{font-size:9px;color:#6b7280;line-height:1.8}.wpsc-fim-action .button{align-self:flex-start}.wpsc-fim-note{margin-top:16px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:12px;padding:14px}.wpsc-fim-note strong{font-size:10px}.wpsc-fim-note p{font-size:9px;color:#64748b;line-height:1.9;margin:7px 0 0}.wpsc-fim-events{margin-top:14px}.wpsc-fim-table-wrap{overflow:auto}.wpsc-fim-table-wrap table{min-width:720px}.wpsc-fim-table-wrap th,.wpsc-fim-table-wrap td{font-size:10px;vertical-align:middle}.wpsc-fim-table-wrap code{font-size:10px;direction:ltr;display:inline-block}.wpsc-severity{display:inline-block;padding:5px 8px;border-radius:999px;font-size:8px;font-weight:900}.wpsc-severity.critical{background:#fee2e2;color:#991b1b}.wpsc-severity.high{background:#ffedd5;color:#9a3412}.wpsc-severity.medium{background:#fef3c7;color:#92400e}.wpsc-fim-empty{text-align:center;padding:34px;color:#6b7280;font-size:10px}.wpsc-fim-wrap .button-primary{background:#1f2937!important;border-color:#1f2937!important}.wpsc-fim-wrap .button{font-family:Vazirmatn,Tahoma,sans-serif;border-radius:8px}.wpsc-fim-wrap .notice{margin:12px 0;border-radius:8px}@media(max-width:900px){.wpsc-fim-stats{grid-template-columns:1fr 1fr}.wpsc-fim-grid{grid-template-columns:1fr}}@media(max-width:560px){.wpsc-fim-wrap{margin-right:10px}.wpsc-fim-head{align-items:flex-start;flex-direction:column}.wpsc-fim-stats{grid-template-columns:1fr}.wpsc-fim-fields{grid-template-columns:1fr}}';
    }
}
