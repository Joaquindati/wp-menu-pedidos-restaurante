<?php
/**
 * Ajustes: horarios de atención, retiro y delivery, costos, WhatsApp y avisos.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MP_Settings {

    const OPTION = 'mp_settings';

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_init', function () {
            register_setting('mp', self::OPTION, array('sanitize_callback' => array(__CLASS__, 'sanitize')));
        });
    }

    public static function defaults() {
        return array(
            // una entrada por día (0 = domingo): rangos "HH:MM-HH:MM" separados por coma
            'horarios'      => array_fill(0, 7, '11:30-15:00, 19:30-23:30'),
            'retiro'        => 1,
            'delivery'      => 1,
            'costo_envio'   => 0,
            'minimo'        => 0,
            'whatsapp'      => '',
            'email'         => get_option('admin_email'),
            'moneda'        => '$',
        );
    }

    public static function get($key = null) {
        $o = wp_parse_args(get_option(self::OPTION, array()), self::defaults());
        return $key === null ? $o : ($o[$key] ?? null);
    }

    /**
     * Rangos de un día como pares de minutos desde la medianoche. Un rango que cruza la
     * medianoche (20:00-01:00) se acepta: termina al día siguiente.
     */
    public static function rangos($texto) {
        $out = array();
        preg_match_all('/([01]?\d|2[0-3]):([0-5]\d)\s*-\s*([01]?\d|2[0-3]):([0-5]\d)/', (string) $texto, $m, PREG_SET_ORDER);
        foreach ($m as $r) {
            $a = $r[1] * 60 + $r[2];
            $b = $r[3] * 60 + $r[4];
            $out[] = array($a, $b > $a ? $b : $b + 1440);
        }
        return $out;
    }

    /** ¿Se toman pedidos en este momento? */
    public static function abierto(?DateTimeImmutable $ahora = null) {
        $ahora = $ahora ?: new DateTimeImmutable('now', wp_timezone());
        $horarios = (array) self::get('horarios');
        $min = $ahora->format('G') * 60 + (int) $ahora->format('i');
        $hoy = (int) $ahora->format('w');
        foreach (self::rangos($horarios[$hoy] ?? '') as $r) {
            if ($min >= $r[0] && $min < $r[1]) {
                return true;
            }
        }
        // un rango de ayer que cruzó la medianoche
        $ayer = ($hoy + 6) % 7;
        foreach (self::rangos($horarios[$ayer] ?? '') as $r) {
            if ($r[1] > 1440 && $min + 1440 < $r[1]) {
                return true;
            }
        }
        return false;
    }

    public static function menu() {
        add_submenu_page('edit.php?post_type=' . MP_Orders::CPT, __('Ajustes de pedidos', 'wp-menu-pedidos'), __('Ajustes', 'wp-menu-pedidos'), 'manage_options', 'mp-ajustes', array(__CLASS__, 'render'));
    }

    public static function sanitize($in) {
        $d = self::defaults();
        $h = array();
        for ($i = 0; $i < 7; $i++) {
            $h[$i] = sanitize_text_field($in['horarios'][$i] ?? '');
        }
        return array(
            'horarios'    => $h,
            'retiro'      => empty($in['retiro']) ? 0 : 1,
            'delivery'    => empty($in['delivery']) ? 0 : 1,
            'costo_envio' => max(0, (float) str_replace(',', '.', $in['costo_envio'] ?? 0)),
            'minimo'      => max(0, (float) str_replace(',', '.', $in['minimo'] ?? 0)),
            'whatsapp'    => preg_replace('/\D/', '', $in['whatsapp'] ?? ''),
            'email'       => sanitize_email($in['email'] ?? '') ?: $d['email'],
            'moneda'      => sanitize_text_field($in['moneda'] ?? '$'),
        );
    }

    public static function render() {
        $o = self::get();
        $n = self::OPTION;
        $dias = array(__('Domingo', 'wp-menu-pedidos'), __('Lunes', 'wp-menu-pedidos'), __('Martes', 'wp-menu-pedidos'), __('Miércoles', 'wp-menu-pedidos'), __('Jueves', 'wp-menu-pedidos'), __('Viernes', 'wp-menu-pedidos'), __('Sábado', 'wp-menu-pedidos'));
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Ajustes de pedidos', 'wp-menu-pedidos'); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields('mp'); ?>
                <h2><?php esc_html_e('Horarios en que se toman pedidos', 'wp-menu-pedidos'); ?></h2>
                <p class="description"><?php esc_html_e('Rangos separados por coma, por ejemplo 11:30-15:00, 19:30-23:30. Vacío = cerrado. Un rango puede pasar la medianoche (20:00-01:00).', 'wp-menu-pedidos'); ?></p>
                <table class="form-table" role="presentation">
                    <?php foreach ($dias as $i => $dia) : ?>
                        <tr><th><?php echo esc_html($dia); ?></th><td>
                            <input type="text" class="regular-text" name="<?php echo esc_attr($n); ?>[horarios][<?php echo (int) $i; ?>]" value="<?php echo esc_attr($o['horarios'][$i] ?? ''); ?>"></td></tr>
                    <?php endforeach; ?>
                </table>
                <h2><?php esc_html_e('Entrega', 'wp-menu-pedidos'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr><th><?php esc_html_e('Métodos', 'wp-menu-pedidos'); ?></th><td>
                        <label><input type="checkbox" name="<?php echo esc_attr($n); ?>[retiro]" value="1" <?php checked($o['retiro']); ?>> <?php esc_html_e('Retiro en el local', 'wp-menu-pedidos'); ?></label><br>
                        <label><input type="checkbox" name="<?php echo esc_attr($n); ?>[delivery]" value="1" <?php checked($o['delivery']); ?>> <?php esc_html_e('Envío a domicilio', 'wp-menu-pedidos'); ?></label></td></tr>
                    <tr><th><?php esc_html_e('Costo de envío', 'wp-menu-pedidos'); ?></th><td>
                        <input type="number" step="0.01" min="0" name="<?php echo esc_attr($n); ?>[costo_envio]" value="<?php echo esc_attr($o['costo_envio']); ?>"></td></tr>
                    <tr><th><?php esc_html_e('Pedido mínimo', 'wp-menu-pedidos'); ?></th><td>
                        <input type="number" step="0.01" min="0" name="<?php echo esc_attr($n); ?>[minimo]" value="<?php echo esc_attr($o['minimo']); ?>"></td></tr>
                    <tr><th><?php esc_html_e('Moneda', 'wp-menu-pedidos'); ?></th><td>
                        <input type="text" class="small-text" name="<?php echo esc_attr($n); ?>[moneda]" value="<?php echo esc_attr($o['moneda']); ?>"></td></tr>
                </table>
                <h2><?php esc_html_e('Avisos de pedidos nuevos', 'wp-menu-pedidos'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr><th><?php esc_html_e('Email', 'wp-menu-pedidos'); ?></th><td>
                        <input type="email" class="regular-text" name="<?php echo esc_attr($n); ?>[email]" value="<?php echo esc_attr($o['email']); ?>"></td></tr>
                    <tr><th><?php esc_html_e('WhatsApp del local', 'wp-menu-pedidos'); ?></th><td>
                        <input type="text" class="regular-text" name="<?php echo esc_attr($n); ?>[whatsapp]" value="<?php echo esc_attr($o['whatsapp']); ?>" placeholder="5493411234567">
                        <p class="description"><?php esc_html_e('Con código de país y sin espacios. Al terminar el pedido, el cliente lo envía con un botón a este número, con el detalle ya escrito.', 'wp-menu-pedidos'); ?></p></td></tr>
                </table>
                <?php submit_button(); ?>
            </form>
            <h2><?php esc_html_e('Shortcode', 'wp-menu-pedidos'); ?></h2>
            <p><code>[menu_pedidos]</code> &nbsp; <code>[menu_pedidos categorias="entradas,principales,postres"]</code></p>
        </div>
        <?php
    }
}
