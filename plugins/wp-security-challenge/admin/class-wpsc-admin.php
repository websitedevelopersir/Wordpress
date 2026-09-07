<?php
if ( ! defined( 'ABSPATH' ) ) exit;

final class WPSC_Admin {
    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', array( $this, 'menu' ) );
        add_action( 'admin_init', array( $this, 'register' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
    }

    public function menu() {
        add_menu_page(
            'امنیت سایت',
            'امنیت سایت',
            'manage_options',
            'wpsc-security',
            array( $this, 'page' ),
            'dashicons-shield-alt',
            80
        );
    }

    public function register() {
        register_setting( 'wpsc_group', 'wpsc_settings', array( $this, 'sanitize' ) );
    }

    public function sanitize( $input ) {
        $d = WPSC_Settings::defaults();
        $out = array();
        $out['enabled']               = ! empty( $input['enabled'] ) ? '1' : '0';
        $out['bypass_logged_in']      = ! empty( $input['bypass_logged_in'] ) ? '1' : '0';
        $out['always_show_challenge']  = ! empty( $input['always_show_challenge'] ) ? '1' : '0';
        $out['always_challenge_captcha'] = ! empty( $input['always_challenge_captcha'] ) ? '1' : '0';
        $out['seo_safe_mode']          = ! empty( $input['seo_safe_mode'] ) ? '1' : '0';
        $out['cookie_hours']          = (string) max( 1, min( 168, absint( isset($input['cookie_hours']) ? $input['cookie_hours'] : $d['cookie_hours'] ) ) );
        $out['sensitivity_level']     = (string) max( 1, min( 10, absint( isset($input['sensitivity_level']) ? $input['sensitivity_level'] : $d['sensitivity_level'] ) ) );
        $out['risk_threshold']        = (string) ( 15 + ( (int) $out['sensitivity_level'] * 6 ) );
        $out['rate_limit_per_minute'] = (string) max( 5, min( 300, absint( isset($input['rate_limit_per_minute']) ? $input['rate_limit_per_minute'] : $d['rate_limit_per_minute'] ) ) );
        $out['min_check_ms']           = (string) max( 200, min( 5000, absint( isset($input['min_check_ms']) ? $input['min_check_ms'] : $d['min_check_ms'] ) ) );
        $out['trusted_ips']           = sanitize_textarea_field( isset($input['trusted_ips']) ? $input['trusted_ips'] : '' );

        $text_keys = array('brand_title','secure_label','checking_title','checking_text','status_initial','status_browser','status_behavior','status_session','status_extra','status_verified','status_stopped','success_title','success_text','captcha_title','captcha_text','captcha_label','captcha_placeholder','captcha_button','captcha_refresh','captcha_error','captcha_incomplete','generic_error','privacy_text','footer_text');
        foreach ( $text_keys as $key ) {
            $out[$key] = sanitize_text_field( isset($input[$key]) ? $input[$key] : $d[$key] );
        }

        foreach ( array('primary_color','accent_color','surface_color','background_color','text_color','muted_color') as $key ) {
            $out[$key] = sanitize_hex_color( isset($input[$key]) ? $input[$key] : $d[$key] );
            if ( ! $out[$key] ) $out[$key] = $d[$key];
        }
        $out['radius'] = (string) max( 10, min( 48, absint( isset($input['radius']) ? $input['radius'] : $d['radius'] ) ) );

        WPSC_Settings::instance()->refresh();
        return $out;
    }

    public function assets( $hook ) {
        if ( 'toplevel_page_wpsc-security' !== $hook ) return;
        wp_enqueue_style( 'wp-color-picker' );
        wp_enqueue_script( 'wp-color-picker' );
        wp_add_inline_script( 'wp-color-picker', 'jQuery(function($){$(".wpsc-color").wpColorPicker();var $a=$("input[name=\"wpsc_settings[always_show_challenge]\"]"),$w=$("#wpsc-always-captcha-wrap");function sync(){var on=$a.is(":checked");$w.toggleClass("is-active",on).attr("aria-hidden",on?"false":"true");$w.find("input").prop("disabled",!on)}$a.on("change",sync);sync();});' );
        wp_register_style( 'wpsc-admin', false, array(), WPSC_VERSION );
        wp_enqueue_style( 'wpsc-admin' );
        wp_add_inline_style( 'wpsc-admin', $this->admin_css() );
    }

    public function page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $s = wp_parse_args( (array) get_option( 'wpsc_settings', array() ), WPSC_Settings::defaults() );
        ?>
        <div class="wrap wpsc-admin-wrap" dir="rtl">
            <div class="wpsc-admin-hero">
                <div>
                    <span class="wpsc-admin-kicker">WORDPRESS SECURITY CHALLENGE</span>
                    <h1>امنیت هوشمند بدون مزاحمت برای کاربر</h1>
                    <p>کاربران مهمان ابتدا به‌صورت خودکار بررسی می‌شوند و CAPTCHA فقط در صورت افزایش امتیاز ریسک نمایش داده می‌شود.</p>
                </div>
                <div class="wpsc-admin-badge"><span></span> نسخه <?php echo esc_html( WPSC_VERSION ); ?></div>
            </div>

            <?php settings_errors(); ?>
            <form method="post" action="options.php">
                <?php settings_fields( 'wpsc_group' ); ?>
                <div class="wpsc-admin-grid">
                    <section class="wpsc-panel">
                        <div class="wpsc-panel-head"><h2>هسته امنیتی</h2><p>رفتار Challenge و حساسیت تشخیص را کنترل کنید.</p></div>
                        <?php $this->toggle( 'enabled', 'فعال بودن بررسی امنیتی', 'فعال‌سازی Gate برای کاربران مهمان', $s ); ?>
                        <?php $this->toggle( 'bypass_logged_in', 'معافیت کاربران لاگین‌شده', 'پیشنهادی: روشن بماند تا مدیریت، Elementor و عملیات داخلی مختل نشوند.', $s ); ?>
                        <?php $this->toggle( 'seo_safe_mode', 'حالت سازگار با گوگل و سئو', 'خزنده‌های شناخته‌شده موتورهای جستجو، Sitemap، Feed و درخواست HEAD مستقیماً محتوای اصلی را دریافت می‌کنند.', $s ); ?>
                        <?php $this->toggle( 'always_show_challenge', 'لود دائمی صفحه بررسی', 'در صورت فعال بودن، هر بار ورود مهمان به یک صفحه جدید Challenge اجرا می‌شود. برای تجربه کاربری عادی خاموش بماند.', $s ); ?>
                        <div class="wpsc-dependent" id="wpsc-always-captcha-wrap">
                            <?php $this->toggle( 'always_challenge_captcha', 'لود دائمی همراه CAPTCHA', 'روشن: در هر بار لود دائمی CAPTCHA نمایش داده می‌شود. خاموش: فقط بررسی مرورگر و هدایت انجام می‌شود و CAPTCHA نمایش داده نمی‌شود.', $s ); ?>
                        </div>
                        <div class="wpsc-fields two">
                            <?php $this->number( 'sensitivity_level', 'حساسیت (۱ تا ۱۰)', $s, 1, 10, 'عدد کمتر = حساسیت بیشتر، واکنش سریع‌تر و نمایش زودتر CAPTCHA. مقدار پیشنهادی: ۵.' ); ?>
                            <?php $this->number( 'cookie_hours', 'اعتبار تأیید (ساعت)', $s, 1, 168, 'در حالت عادی پس از تأیید دوباره Challenge نمایش داده نمی‌شود؛ در حالت لود دائمی این گزینه نادیده گرفته می‌شود.' ); ?>
                            <?php $this->number( 'rate_limit_per_minute', 'درخواست مجاز در دقیقه', $s, 5, 300, 'پس از عبور از این مقدار امتیاز ریسک افزایش می‌یابد.' ); ?>
                            <?php $this->number( 'min_check_ms', 'حداقل زمان بررسی (ms)', $s, 200, 5000, 'برای شناسایی عبورهای غیرطبیعی بسیار سریع.' ); ?>
                        </div>
                        <div class="wpsc-field"><label>IPهای مورد اعتماد</label><textarea name="wpsc_settings[trusted_ips]" rows="4" placeholder="127.0.0.1&#10;1.2.3.4"><?php echo esc_textarea( $s['trusted_ips'] ); ?></textarea><small>هر IP را در یک خط یا با کاما جدا کنید. این IPها Challenge نمی‌بینند.</small></div>
                    </section>

                    <section class="wpsc-panel">
                        <div class="wpsc-panel-head"><h2>ظاهر صفحه</h2><p>همه منابع صفحه از داخل افزونه بارگذاری می‌شوند.</p></div>
                        <div class="wpsc-fields two colors">
                            <?php $this->color( 'primary_color', 'رنگ اصلی', $s ); ?>
                            <?php $this->color( 'accent_color', 'رنگ تأکیدی', $s ); ?>
                            <?php $this->color( 'background_color', 'پس‌زمینه', $s ); ?>
                            <?php $this->color( 'surface_color', 'رنگ کارت', $s ); ?>
                            <?php $this->color( 'text_color', 'رنگ متن', $s ); ?>
                            <?php $this->color( 'muted_color', 'متن ثانویه', $s ); ?>
                        </div>
                        <?php $this->number( 'radius', 'گردی کارت (px)', $s, 10, 48, 'برای ظاهر نرم و مدرن صفحه Challenge.' ); ?>
                    </section>

                    <section class="wpsc-panel wpsc-panel-wide">
                        <div class="wpsc-panel-head"><h2>متن‌های قابل ویرایش</h2><p>تمام متن‌های اصلی صفحه بررسی، موفقیت و CAPTCHA از اینجا تغییر می‌کنند.</p></div>
                        <div class="wpsc-fields two">
                            <?php
                            $labels = array(
                                'brand_title'=>'عنوان بالای صفحه','checking_title'=>'عنوان بررسی','checking_text'=>'توضیح بررسی','success_title'=>'عنوان موفقیت','success_text'=>'متن هدایت','captcha_title'=>'عنوان CAPTCHA','captcha_text'=>'توضیح CAPTCHA','captcha_label'=>'برچسب فیلد CAPTCHA','captcha_placeholder'=>'Placeholder کد','captcha_button'=>'متن دکمه تأیید','captcha_refresh'=>'متن تغییر کد','captcha_error'=>'خطای کد اشتباه','generic_error'=>'خطای عمومی','captcha_incomplete'=>'خطای کد ناقص','status_initial'=>'وضعیت اولیه','status_browser'=>'وضعیت بررسی مرورگر','status_behavior'=>'وضعیت تحلیل رفتار','status_session'=>'وضعیت نشست امنیتی','status_extra'=>'وضعیت نیاز به CAPTCHA','status_verified'=>'وضعیت تأیید موفق','status_stopped'=>'وضعیت توقف بررسی','secure_label'=>'برچسب امن','privacy_text'=>'متن حریم خصوصی','footer_text'=>'متن پایین صفحه'
                            );
                            foreach ( $labels as $key=>$label ) $this->text( $key, $label, $s );
                            ?>
                        </div>
                    </section>
                </div>
                <div class="wpsc-savebar">
                    <div><strong>حالت امن برای مدیریت و سئو</strong><span>Challenge در wp-admin، AJAX، Cron، REST و کاربران واردشده اجرا نمی‌شود؛ حالت SEO Safe نیز به‌صورت پیش‌فرض فعال است.</span></div>
                    <?php submit_button( 'ذخیره تنظیمات', 'primary', 'submit', false ); ?>
                </div>
            </form>
        </div>
        <?php
    }

    private function toggle( $key, $title, $desc, $s ) { ?>
        <label class="wpsc-toggle-row"><span><strong><?php echo esc_html($title); ?></strong><small><?php echo esc_html($desc); ?></small></span><input type="checkbox" name="wpsc_settings[<?php echo esc_attr($key); ?>]" value="1" <?php checked( $s[$key], '1' ); ?>><i></i></label>
    <?php }
    private function number( $key, $label, $s, $min, $max, $help='' ) { ?>
        <div class="wpsc-field"><label><?php echo esc_html($label); ?></label><input type="number" min="<?php echo esc_attr($min); ?>" max="<?php echo esc_attr($max); ?>" name="wpsc_settings[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($s[$key]); ?>"><?php if($help): ?><small><?php echo esc_html($help); ?></small><?php endif; ?></div>
    <?php }
    private function text( $key, $label, $s ) { ?>
        <div class="wpsc-field"><label><?php echo esc_html($label); ?></label><input type="text" name="wpsc_settings[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($s[$key]); ?>"></div>
    <?php }
    private function color( $key, $label, $s ) { ?>
        <div class="wpsc-field"><label><?php echo esc_html($label); ?></label><input class="wpsc-color" type="text" name="wpsc_settings[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($s[$key]); ?>"></div>
    <?php }

    private function admin_css() {
        $font_url = esc_url_raw( WPSC_URL . 'public/assets/fonts/Vazirmatn-UI-FD-NL-Regular.woff2' );
        return '@font-face{font-family:"Vazirmatn";src:url("' . $font_url . '") format("woff2");font-style:normal;font-weight:400;font-display:swap}.wpsc-admin-wrap{max-width:1180px;margin:28px 18px 40px 0;font-family:"Vazirmatn",Tahoma,"Segoe UI",sans-serif}.wpsc-admin-hero{background:#0f2e26;color:#fff;border-radius:22px;padding:28px 30px;display:flex;align-items:center;justify-content:space-between;gap:25px;box-shadow:0 15px 40px rgba(15,46,38,.16)}.wpsc-admin-kicker{font-size:10px;letter-spacing:1.4px;opacity:.65}.wpsc-admin-hero h1{color:#fff;margin:8px 0 7px;font-size:25px}.wpsc-admin-hero p{margin:0;max-width:720px;color:#d7e4df;line-height:2;font-size:12px}.wpsc-admin-badge{white-space:nowrap;background:#ffffff12;border:1px solid #ffffff1c;padding:10px 13px;border-radius:999px;font-size:11px}.wpsc-admin-badge span{display:inline-block;width:7px;height:7px;border-radius:50%;background:#2fd6a3;margin-left:6px;box-shadow:0 0 0 4px #2fd6a31f}.wpsc-admin-grid{display:grid;grid-template-columns:1.25fr .75fr;gap:18px;margin-top:18px}.wpsc-panel{background:#fff;border:1px solid #e7ece9;border-radius:20px;padding:22px;box-shadow:0 5px 22px rgba(15,46,38,.035)}.wpsc-panel-wide{grid-column:1/-1}.wpsc-panel-head{border-bottom:1px solid #edf1ef;margin:-2px 0 20px;padding:0 0 15px}.wpsc-panel-head h2{font-size:16px;margin:0 0 7px}.wpsc-panel-head p{margin:0;color:#6d7b75;font-size:11px}.wpsc-toggle-row{display:flex;align-items:center;justify-content:space-between;gap:20px;padding:13px 0;border-bottom:1px solid #f1f3f2;cursor:pointer}.wpsc-toggle-row>span{display:flex;flex-direction:column;gap:6px}.wpsc-toggle-row strong{font-size:12px}.wpsc-toggle-row small,.wpsc-field small{font-size:10px;color:#7b8782;line-height:1.8}.wpsc-toggle-row input{display:none}.wpsc-toggle-row i{width:42px;height:24px;border-radius:999px;background:#d7ddda;position:relative;transition:.2s;flex:none}.wpsc-toggle-row i:after{content:"";position:absolute;width:18px;height:18px;top:3px;right:3px;border-radius:50%;background:#fff;box-shadow:0 2px 7px #0002;transition:.2s}.wpsc-toggle-row input:checked+i{background:#0f2e26}.wpsc-toggle-row input:checked+i:after{right:21px}.wpsc-dependent{display:none;margin-top:8px;padding:0 14px;border-radius:14px;background:#f7faf8;border:1px solid #e7efeb}.wpsc-dependent.is-active{display:block}.wpsc-fields{display:grid;gap:14px;margin-top:16px}.wpsc-fields.two{grid-template-columns:repeat(2,minmax(0,1fr))}.wpsc-field{display:flex;flex-direction:column;gap:7px;margin-top:15px}.wpsc-fields .wpsc-field{margin-top:0}.wpsc-field label{font-weight:700;font-size:11px;color:#263b34}.wpsc-field input[type=text],.wpsc-field input[type=number],.wpsc-field textarea{width:100%;max-width:none;border:1px solid #dfe6e3;border-radius:11px;min-height:42px;padding:8px 11px;box-shadow:none;font-family:"Vazirmatn",Tahoma,"Segoe UI",sans-serif;font-size:11px}.wpsc-field textarea{resize:vertical}.wpsc-field input:focus,.wpsc-field textarea:focus{border-color:#0f2e26;box-shadow:0 0 0 3px #0f2e2610}.wpsc-savebar{position:sticky;bottom:16px;margin-top:18px;background:#fff;border:1px solid #e2e8e5;box-shadow:0 12px 35px rgba(15,46,38,.1);padding:13px 15px;border-radius:17px;display:flex;align-items:center;justify-content:space-between;gap:20px;z-index:5}.wpsc-savebar>div{display:flex;flex-direction:column;gap:4px}.wpsc-savebar strong{font-size:11px}.wpsc-savebar span{font-size:9px;color:#76827d}.wpsc-savebar .button-primary{background:#0f2e26!important;border-color:#0f2e26!important;border-radius:10px!important;padding:2px 18px!important;min-height:38px!important;font-family:"Vazirmatn",Tahoma,"Segoe UI",sans-serif!important}.wp-picker-container .wp-color-result.button{border-radius:9px}.notice{border-radius:10px}@media(max-width:900px){.wpsc-admin-grid{grid-template-columns:1fr}.wpsc-panel-wide{grid-column:auto}.wpsc-admin-hero{align-items:flex-start;flex-direction:column}.wpsc-fields.two{grid-template-columns:1fr}.wpsc-savebar{position:static}}';
    }
}
