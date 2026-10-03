<?php
/**
 * La carta: platos (con precio, opciones y disponibilidad), categorías y etiquetas.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MP_Catalog {

    const CPT = 'mp_plato';
    const CAT = 'mp_categoria';
    const TAG = 'mp_etiqueta';

    public static function init() {
        add_action('init', array(__CLASS__, 'register'));
        add_action('add_meta_boxes', array(__CLASS__, 'meta_box'));
        add_action('save_post_' . self::CPT, array(__CLASS__, 'save'));
        add_filter('manage_' . self::CPT . '_posts_columns', array(__CLASS__, 'columns'));
        add_action('manage_' . self::CPT . '_posts_custom_column', array(__CLASS__, 'column'), 10, 2);
    }

    public static function register() {
        register_post_type(self::CPT, array(
            'labels'       => array(
                'name'          => __('Carta', 'wp-menu-pedidos'),
                'singular_name' => __('Plato', 'wp-menu-pedidos'),
                'add_new_item'  => __('Nuevo plato', 'wp-menu-pedidos'),
                'edit_item'     => __('Editar plato', 'wp-menu-pedidos'),
                'all_items'     => __('Todos los platos', 'wp-menu-pedidos'),
            ),
            'public'       => false,
            'show_ui'      => true,
            'show_in_rest' => true,
            'menu_icon'    => 'dashicons-carrot',
            'supports'     => array('title', 'excerpt', 'thumbnail', 'page-attributes'),
        ));
        register_taxonomy(self::CAT, self::CPT, array(
            'labels'            => array('name' => __('Categorías', 'wp-menu-pedidos'), 'singular_name' => __('Categoría', 'wp-menu-pedidos')),
            'hierarchical'      => true,
            'public'            => false,
            'show_ui'           => true,
            'show_admin_column' => true,
            'show_in_rest'      => true,
        ));
        register_taxonomy(self::TAG, self::CPT, array(
            'labels'            => array('name' => __('Etiquetas', 'wp-menu-pedidos'), 'singular_name' => __('Etiqueta', 'wp-menu-pedidos')),
            'hierarchical'      => false,
            'public'            => false,
            'show_ui'           => true,
            'show_admin_column' => true,
            'show_in_rest'      => true,
        ));
    }

    /**
     * Opciones del plato: una por línea, "Nombre|precio extra" (el extra es opcional).
     *
     * @return array<int, array{nombre:string, extra:float}>
     */
    public static function opciones($id) {
        $out = array();
        foreach (preg_split('/\r?\n/', (string) get_post_meta($id, '_mp_opciones', true)) as $line) {
            $p = array_map('trim', explode('|', $line));
            if ($p[0] !== '') {
                $out[] = array('nombre' => $p[0], 'extra' => isset($p[1]) ? (float) str_replace(',', '.', $p[1]) : 0);
            }
        }
        return $out;
    }

    public static function precio($id) {
        return (float) get_post_meta($id, '_mp_precio', true);
    }

    public static function disponible($id) {
        return get_post_type($id) === self::CPT && get_post_status($id) === 'publish' && get_post_meta($id, '_mp_agotado', true) !== '1';
    }

    public static function meta_box() {
        add_meta_box('mp_precio', __('Precio y opciones', 'wp-menu-pedidos'), array(__CLASS__, 'render_box'), self::CPT, 'normal', 'high');
    }

    public static function render_box($post) {
        wp_nonce_field('mp_plato', '_mp_plato');
        ?>
        <p><label><strong><?php esc_html_e('Precio', 'wp-menu-pedidos'); ?></strong><br>
            <input type="number" step="0.01" min="0" name="mp_precio" value="<?php echo esc_attr(get_post_meta($post->ID, '_mp_precio', true)); ?>"></label></p>
        <p><label><strong><?php esc_html_e('Opciones (una por línea: Nombre|precio extra)', 'wp-menu-pedidos'); ?></strong><br>
            <textarea name="mp_opciones" rows="4" class="widefat code" placeholder="Doble carne|1500&#10;Sin cebolla&#10;Papas grandes|800"><?php echo esc_textarea(get_post_meta($post->ID, '_mp_opciones', true)); ?></textarea></label></p>
        <p><label><input type="checkbox" name="mp_agotado" value="1" <?php checked(get_post_meta($post->ID, '_mp_agotado', true), '1'); ?>>
            <?php esc_html_e('Agotado (se muestra en la carta pero no se puede pedir)', 'wp-menu-pedidos'); ?></label></p>
        <p class="description"><?php esc_html_e('La descripción corta va en "Extracto" y la foto en "Imagen destacada". El orden en la carta sale de "Atributos → Orden".', 'wp-menu-pedidos'); ?></p>
        <?php
    }

    public static function save($id) {
        if (!isset($_POST['_mp_plato']) || !wp_verify_nonce(sanitize_key($_POST['_mp_plato']), 'mp_plato')
            || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || !current_user_can('edit_post', $id)) {
            return;
        }
        update_post_meta($id, '_mp_precio', max(0, (float) str_replace(',', '.', wp_unslash($_POST['mp_precio'] ?? 0))));
        update_post_meta($id, '_mp_opciones', sanitize_textarea_field(wp_unslash($_POST['mp_opciones'] ?? '')));
        update_post_meta($id, '_mp_agotado', empty($_POST['mp_agotado']) ? '0' : '1');
    }

    public static function columns($cols) {
        $cols['mp_precio'] = __('Precio', 'wp-menu-pedidos');
        return $cols;
    }

    public static function column($col, $id) {
        if ($col === 'mp_precio') {
            echo esc_html(MP_Settings::get('moneda') . number_format_i18n(self::precio($id), 2));
            if (get_post_meta($id, '_mp_agotado', true) === '1') {
                echo ' <em>(' . esc_html__('agotado', 'wp-menu-pedidos') . ')</em>';
            }
        }
    }
}
