<?php
/**
 * Panel de pedidos: listado con detalle y estado, cambio de estado en un clic,
 * filtro y contador de pedidos nuevos en el menú.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MP_Admin {

    public static function init() {
        $cpt = MP_Orders::CPT;
        add_filter("manage_{$cpt}_posts_columns", array(__CLASS__, 'columns'));
        add_action("manage_{$cpt}_posts_custom_column", array(__CLASS__, 'column'), 10, 2);
        add_filter('post_row_actions', array(__CLASS__, 'row_actions'), 10, 2);
        add_action('admin_post_mp_estado', array(__CLASS__, 'cambiar_estado'));
        add_action('add_meta_boxes', array(__CLASS__, 'meta_box'));
        add_action("save_post_{$cpt}", array(__CLASS__, 'save'));
        add_action('restrict_manage_posts', array(__CLASS__, 'filtro'));
        add_action('pre_get_posts', array(__CLASS__, 'aplicar_filtro'));
        add_action('admin_menu', array(__CLASS__, 'contador'), 99);
        add_action('admin_head', array(__CLASS__, 'css'));
    }

    private static function label($estado) {
        return isset(MP_Orders::ESTADOS[$estado]) ? __(MP_Orders::ESTADOS[$estado], 'wp-menu-pedidos') : $estado; // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
    }

    public static function columns($cols) {
        return array(
            'cb'         => $cols['cb'],
            'title'      => __('Pedido', 'wp-menu-pedidos'),
            'mp_detalle' => __('Detalle', 'wp-menu-pedidos'),
            'mp_total'   => __('Total', 'wp-menu-pedidos'),
            'mp_entrega' => __('Entrega', 'wp-menu-pedidos'),
            'mp_estado'  => __('Estado', 'wp-menu-pedidos'),
            'date'       => __('Recibido', 'wp-menu-pedidos'),
        );
    }

    public static function column($col, $id) {
        $p = MP_Orders::get($id);
        switch ($col) {
            case 'mp_detalle':
                foreach ($p['items'] as $it) {
                    echo esc_html($it['cantidad'] . 'x ' . $it['nombre'] . ($it['opciones'] ? ' (' . implode(', ', $it['opciones']) . ')' : '')) . '<br>';
                }
                if ($p['notas'] !== '') {
                    echo '<em>' . esc_html($p['notas']) . '</em>';
                }
                break;
            case 'mp_total':
                echo esc_html(MP_Orders::money($p['total']));
                break;
            case 'mp_entrega':
                echo $p['entrega'] === 'delivery'
                    ? esc_html__('Envío:', 'wp-menu-pedidos') . ' ' . esc_html($p['direccion'])
                    : esc_html__('Retiro', 'wp-menu-pedidos');
                echo '<br><a href="tel:' . esc_attr(preg_replace('/[^\d+]/', '', $p['telefono'])) . '">' . esc_html($p['telefono']) . '</a>';
                break;
            case 'mp_estado':
                printf('<span class="mp-badge mp-%s">%s</span>', esc_attr($p['estado']), esc_html(self::label($p['estado'])));
                break;
        }
    }

    /** El siguiente paso lógico del pedido, más cancelar. */
    public static function row_actions($actions, $post) {
        if ($post->post_type !== MP_Orders::CPT || !current_user_can('edit_post', $post->ID)) {
            return $actions;
        }
        $estado = get_post_meta($post->ID, '_mp_estado', true);
        $orden = array_keys(MP_Orders::ESTADOS);
        $i = array_search($estado, $orden, true);
        $next = array();
        if ($i !== false && $i < 3) {
            $next[] = $orden[$i + 1];
        }
        if (!in_array($estado, array('entregado', 'cancelado'), true)) {
            $next[] = 'cancelado';
        }
        foreach ($next as $e) {
            $url = wp_nonce_url(admin_url('admin-post.php?action=mp_estado&id=' . $post->ID . '&estado=' . $e), 'mp_estado_' . $post->ID);
            $actions['mp_' . $e] = '<a href="' . esc_url($url) . '">→ ' . esc_html(self::label($e)) . '</a>';
        }
        unset($actions['inline hide-if-no-js'], $actions['view']);
        return $actions;
    }

    public static function cambiar_estado() {
        $id = absint($_GET['id'] ?? 0);
        $estado = sanitize_key($_GET['estado'] ?? '');
        check_admin_referer('mp_estado_' . $id);
        if (!current_user_can('edit_post', $id) || get_post_type($id) !== MP_Orders::CPT || !isset(MP_Orders::ESTADOS[$estado])) {
            wp_die(esc_html__('No puedes hacer eso.', 'wp-menu-pedidos'), 403);
        }
        update_post_meta($id, '_mp_estado', $estado);
        do_action('mp_estado_cambiado', $id, $estado);
        wp_safe_redirect(wp_get_referer() ?: admin_url('edit.php?post_type=' . MP_Orders::CPT));
        exit;
    }

    public static function meta_box() {
        add_meta_box('mp_pedido', __('Detalle del pedido', 'wp-menu-pedidos'), array(__CLASS__, 'render_box'), MP_Orders::CPT, 'normal', 'high');
    }

    public static function render_box($post) {
        $p = MP_Orders::get($post->ID);
        wp_nonce_field('mp_pedido_admin', '_mp_admin');
        echo '<pre style="white-space:pre-wrap;font:inherit">' . esc_html(MP_Orders::resumen($p)) . '</pre>';
        echo '<p><label><strong>' . esc_html__('Estado', 'wp-menu-pedidos') . '</strong> <select name="mp_estado">';
        foreach (array_keys(MP_Orders::ESTADOS) as $e) {
            printf('<option value="%s" %s>%s</option>', esc_attr($e), selected($p['estado'], $e, false), esc_html(self::label($e)));
        }
        echo '</select></label></p>';
    }

    public static function save($id) {
        if (!isset($_POST['_mp_admin']) || !wp_verify_nonce(sanitize_key($_POST['_mp_admin']), 'mp_pedido_admin') || !current_user_can('edit_post', $id)) {
            return;
        }
        $estado = sanitize_key($_POST['mp_estado'] ?? '');
        if (isset(MP_Orders::ESTADOS[$estado]) && $estado !== get_post_meta($id, '_mp_estado', true)) {
            update_post_meta($id, '_mp_estado', $estado);
            do_action('mp_estado_cambiado', $id, $estado);
        }
    }

    public static function filtro($post_type) {
        if ($post_type !== MP_Orders::CPT) {
            return;
        }
        $actual = sanitize_key($_GET['mp_estado'] ?? '');
        echo '<select name="mp_estado"><option value="">' . esc_html__('Todos los estados', 'wp-menu-pedidos') . '</option>';
        foreach (array_keys(MP_Orders::ESTADOS) as $e) {
            printf('<option value="%s" %s>%s</option>', esc_attr($e), selected($actual, $e, false), esc_html(self::label($e)));
        }
        echo '</select>';
    }

    public static function aplicar_filtro($q) {
        if (!is_admin() || !$q->is_main_query() || $q->get('post_type') !== MP_Orders::CPT) {
            return;
        }
        $estado = sanitize_key($_GET['mp_estado'] ?? '');
        if (isset(MP_Orders::ESTADOS[$estado])) {
            $q->set('meta_query', array(array('key' => '_mp_estado', 'value' => $estado)));
        }
    }

    /** Burbuja con los pedidos nuevos, como la de comentarios pendientes. */
    public static function contador() {
        global $menu;
        $n = count(get_posts(array(
            'post_type' => MP_Orders::CPT, 'post_status' => 'publish', 'posts_per_page' => 99, 'fields' => 'ids',
            'meta_query' => array(array('key' => '_mp_estado', 'value' => 'nuevo')),
        )));
        if (!$n) {
            return;
        }
        foreach ((array) $menu as $k => $item) {
            if (isset($item[2]) && $item[2] === 'edit.php?post_type=' . MP_Orders::CPT) {
                $menu[$k][0] .= ' <span class="awaiting-mod"><span class="pending-count">' . (int) $n . '</span></span>';
            }
        }
    }

    public static function css() {
        $s = get_current_screen();
        if (!$s || $s->post_type !== MP_Orders::CPT) {
            return;
        }
        echo '<style>.mp-badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:12px}'
            . '.mp-nuevo{background:#dbeafe;color:#1e3a8a}.mp-preparando{background:#fcf0c3;color:#6b5200}'
            . '.mp-listo{background:#d1f2d9;color:#0f5a23}.mp-entregado{background:#e5e7eb;color:#374151}.mp-cancelado{background:#f6d5d5;color:#7a1414}</style>';
    }
}
