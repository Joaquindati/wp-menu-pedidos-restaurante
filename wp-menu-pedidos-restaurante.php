<?php
/**
 * Plugin Name:       Menú y Pedidos Online para Restaurantes
 * Plugin URI:        https://github.com/Joaquindati/wp-menu-pedidos-restaurante
 * Description:       Carta digital y pedidos online desde tu propio WordPress, sin comisiones: platos con fotos, precios, opciones y etiquetas (vegano, sin TACC), carrito en tiempo real, retiro o delivery, horarios de atención, aviso por email y WhatsApp, y panel de pedidos con estados.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Joaquín Dati
 * Author URI:        https://joaquindati.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-menu-pedidos
 */

if (!defined('ABSPATH')) {
    exit;
}

define('MP_VERSION', '1.0.0');
define('MP_DIR', plugin_dir_path(__FILE__));
define('MP_URL', plugin_dir_url(__FILE__));

require_once MP_DIR . 'includes/class-settings.php';
require_once MP_DIR . 'includes/class-catalog.php';
require_once MP_DIR . 'includes/class-orders.php';
require_once MP_DIR . 'includes/class-front.php';
require_once MP_DIR . 'includes/class-admin.php';

MP_Settings::init();
MP_Catalog::init();
MP_Orders::init();
MP_Front::init();
MP_Admin::init();

register_activation_hook(__FILE__, function () {
    MP_Catalog::register();
    MP_Orders::register();
    if (!get_option(MP_Settings::OPTION)) {
        update_option(MP_Settings::OPTION, MP_Settings::defaults());
    }
    // etiquetas de uso habitual, para no arrancar con la lista vacía
    foreach (array('Vegano', 'Vegetariano', 'Sin TACC', 'Picante') as $t) {
        if (!term_exists($t, MP_Catalog::TAG)) {
            wp_insert_term($t, MP_Catalog::TAG);
        }
    }
    flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, 'flush_rewrite_rules');
