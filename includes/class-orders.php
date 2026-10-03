<?php
/**
 * Pedidos: alta (con los precios recalculados en el servidor), estados y avisos.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MP_Orders {

    const CPT = 'mp_pedido';
    const ESTADOS = array(
        'nuevo'      => 'Nuevo',
        'preparando' => 'En preparación',
        'listo'      => 'Listo',
        'entregado'  => 'Entregado',
        'cancelado'  => 'Cancelado',
    );

    public static function init() {
        add_action('init', array(__CLASS__, 'register'));
        add_action('mp_pedido_creado', array(__CLASS__, 'avisar'));
    }

    public static function register() {
        register_post_type(self::CPT, array(
            'labels'       => array(
                'name'          => __('Pedidos', 'wp-menu-pedidos'),
                'singular_name' => __('Pedido', 'wp-menu-pedidos'),
                'edit_item'     => __('Pedido', 'wp-menu-pedidos'),
                'not_found'     => __('Todavía no hay pedidos', 'wp-menu-pedidos'),
            ),
            'public'       => false,
            'show_ui'      => true,
            'menu_icon'    => 'dashicons-food',
            'supports'     => array('title'),
            'capabilities' => array('create_posts' => 'do_not_allow'), // los pedidos entran desde la web
            'map_meta_cap' => true,
        ));
    }

    public static function money($n) {
        return MP_Settings::get('moneda') . number_format_i18n((float) $n, 2);
    }

    /**
     * Arma el pedido a partir del carrito. Nunca se confía en los precios del navegador:
     * todo se recalcula con lo que está guardado en la carta.
     *
     * @param array $in carrito y datos del cliente
     * @return int|string[] ID del pedido o lista de errores
     */
    public static function crear(array $in) {
        $err = array();
        $o = MP_Settings::get();

        if (!MP_Settings::abierto()) {
            $err[] = __('En este momento no estamos tomando pedidos.', 'wp-menu-pedidos');
        }

        $items = array();
        $subtotal = 0;
        foreach (array_slice((array) ($in['items'] ?? array()), 0, 50) as $it) {
            $id = absint($it['id'] ?? 0);
            $cant = min(50, absint($it['cantidad'] ?? 0));
            if (!$cant) {
                continue;
            }
            if (!MP_Catalog::disponible($id)) {
                $err[] = sprintf(__('"%s" ya no está disponible.', 'wp-menu-pedidos'), $id ? get_the_title($id) : '?');
                continue;
            }
            $todas = MP_Catalog::opciones($id);
            $elegidas = array();
            $unit = MP_Catalog::precio($id);
            foreach (array_unique(array_map('absint', (array) ($it['opciones'] ?? array()))) as $i) {
                if (isset($todas[$i])) {
                    $elegidas[] = $todas[$i]['nombre'];
                    $unit += $todas[$i]['extra'];
                }
            }
            $items[] = array(
                'id'       => $id,
                'nombre'   => get_the_title($id),
                'opciones' => $elegidas,
                'cantidad' => $cant,
                'unitario' => $unit,
                'total'    => $unit * $cant,
            );
            $subtotal += $unit * $cant;
        }
        if (!$items && !$err) {
            $err[] = __('El pedido está vacío.', 'wp-menu-pedidos');
        }

        $entrega = ($in['entrega'] ?? '') === 'delivery' ? 'delivery' : 'retiro';
        if (($entrega === 'delivery' && !$o['delivery']) || ($entrega === 'retiro' && !$o['retiro'])) {
            $err[] = __('Ese método de entrega no está disponible.', 'wp-menu-pedidos');
        }
        $datos = array(
            'nombre'    => sanitize_text_field(wp_unslash($in['nombre'] ?? '')),
            'telefono'  => sanitize_text_field(wp_unslash($in['telefono'] ?? '')),
            'direccion' => $entrega === 'delivery' ? sanitize_text_field(wp_unslash($in['direccion'] ?? '')) : '',
            'notas'     => sanitize_textarea_field(wp_unslash($in['notas'] ?? '')),
        );
        if ($datos['nombre'] === '' || $datos['telefono'] === '') {
            $err[] = __('Completa tu nombre y teléfono.', 'wp-menu-pedidos');
        }
        if ($entrega === 'delivery' && $datos['direccion'] === '') {
            $err[] = __('Indica la dirección de entrega.', 'wp-menu-pedidos');
        }
        if ($o['minimo'] > 0 && $subtotal < $o['minimo']) {
            $err[] = sprintf(__('El pedido mínimo es de %s.', 'wp-menu-pedidos'), self::money($o['minimo']));
        }
        if ($err) {
            return array_values(array_unique($err));
        }

        $envio = $entrega === 'delivery' ? (float) $o['costo_envio'] : 0;
        $codigo = strtoupper(wp_generate_password(5, false));
        $id = wp_insert_post(array(
            'post_type'   => self::CPT,
            'post_status' => 'publish',
            'post_title'  => sprintf('#%s · %s', $codigo, $datos['nombre']),
        ), true);
        if (is_wp_error($id)) {
            return array($id->get_error_message());
        }
        $meta = $datos + array(
            'codigo'   => $codigo,
            'items'    => $items,
            'entrega'  => $entrega,
            'subtotal' => $subtotal,
            'envio'    => $envio,
            'total'    => $subtotal + $envio,
            'estado'   => 'nuevo',
        );
        foreach ($meta as $k => $v) {
            update_post_meta($id, '_mp_' . $k, $v);
        }
        do_action('mp_pedido_creado', $id);
        return $id;
    }

    public static function get($id) {
        $keys = array('codigo', 'items', 'entrega', 'subtotal', 'envio', 'total', 'estado', 'nombre', 'telefono', 'direccion', 'notas');
        $p = array('id' => (int) $id);
        foreach ($keys as $k) {
            $p[$k] = get_post_meta($id, '_mp_' . $k, true);
        }
        $p['items'] = is_array($p['items']) ? $p['items'] : array();
        return $p;
    }

    /** El pedido en texto plano: para el email y para el mensaje de WhatsApp. */
    public static function resumen(array $p) {
        $l = array(sprintf(__('Pedido #%s', 'wp-menu-pedidos'), $p['codigo']));
        foreach ($p['items'] as $it) {
            $l[] = sprintf('%dx %s%s · %s', $it['cantidad'], $it['nombre'], $it['opciones'] ? ' (' . implode(', ', $it['opciones']) . ')' : '', self::money($it['total']));
        }
        if ($p['envio'] > 0) {
            $l[] = sprintf(__('Envío: %s', 'wp-menu-pedidos'), self::money($p['envio']));
        }
        $l[] = sprintf(__('Total: %s', 'wp-menu-pedidos'), self::money($p['total']));
        $l[] = $p['entrega'] === 'delivery'
            ? sprintf(__('Envío a: %s', 'wp-menu-pedidos'), $p['direccion'])
            : __('Retiro en el local', 'wp-menu-pedidos');
        $l[] = sprintf(__('Cliente: %s (%s)', 'wp-menu-pedidos'), $p['nombre'], $p['telefono']);
        if ($p['notas'] !== '') {
            $l[] = sprintf(__('Notas: %s', 'wp-menu-pedidos'), $p['notas']);
        }
        return implode("\n", $l);
    }

    /** Enlace wa.me con el pedido escrito, para que el cliente se lo mande al local. */
    public static function whatsapp_url(array $p) {
        $num = MP_Settings::get('whatsapp');
        return $num ? 'https://wa.me/' . $num . '?text=' . rawurlencode(self::resumen($p)) : '';
    }

    public static function avisar($id) {
        $p = self::get($id);
        $email = MP_Settings::get('email');
        if ($email) {
            wp_mail(
                $email,
                sprintf(__('Nuevo pedido #%1$s · %2$s · %3$s', 'wp-menu-pedidos'), $p['codigo'], $p['nombre'], self::money($p['total'])),
                self::resumen($p) . "\n\n" . admin_url('edit.php?post_type=' . self::CPT)
            );
        }
    }
}
