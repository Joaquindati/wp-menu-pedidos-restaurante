<?php
/**
 * La carta pública con carrito: shortcode [menu_pedidos] y API REST del pedido.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MP_Front {

    public static function init() {
        add_shortcode('menu_pedidos', array(__CLASS__, 'shortcode'));
        add_action('rest_api_init', array(__CLASS__, 'routes'));
        // en init y no en wp_enqueue_scripts: los themes de bloques ejecutan el shortcode
        // antes de ese hook, y wp_localize_script sobre un script sin registrar no hace nada
        add_action('init', function () {
            wp_register_style('mp', MP_URL . 'assets/menu.css', array(), MP_VERSION);
            wp_register_script('mp', MP_URL . 'assets/menu.js', array(), MP_VERSION, true);
        });
    }

    public static function routes() {
        register_rest_route('menu-pedidos/v1', '/pedido', array(
            'methods'             => 'POST',
            // público, pero con nonce propio: sólo lo puede enviar quien cargó la carta
            'permission_callback' => function (WP_REST_Request $req) {
                return (bool) wp_verify_nonce((string) $req->get_header('X-MP-Nonce'), 'mp_pedido');
            },
            'callback'            => function (WP_REST_Request $req) {
                $in = (array) $req->get_json_params();
                if (!empty($in['web'])) { // campo trampa: lo completan los bots, no las personas
                    return new WP_REST_Response(array('errores' => array(__('No se pudo enviar el pedido.', 'wp-menu-pedidos'))), 400);
                }
                $res = MP_Orders::crear($in);
                if (is_array($res)) {
                    return new WP_REST_Response(array('errores' => $res), 422);
                }
                $p = MP_Orders::get($res);
                return array(
                    'codigo'   => $p['codigo'],
                    'total'    => MP_Orders::money($p['total']),
                    'whatsapp' => MP_Orders::whatsapp_url($p),
                );
            },
        ));
        register_rest_route('menu-pedidos/v1', '/estado', array(
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => function () {
                return array('abierto' => MP_Settings::abierto());
            },
        ));
    }

    public static function shortcode($atts) {
        $atts = shortcode_atts(array('categorias' => ''), $atts, 'menu_pedidos');
        $o = MP_Settings::get();

        // categorías en el orden pedido por el shortcode; si no, alfabético
        $slugs = array_filter(array_map('sanitize_title', explode(',', $atts['categorias'])));
        $terms = get_terms(array('taxonomy' => MP_Catalog::CAT, 'hide_empty' => true, 'slug' => $slugs ?: '', 'orderby' => $slugs ? 'slug__in' : 'name'));
        if (is_wp_error($terms)) {
            $terms = array();
        }

        wp_enqueue_style('mp');
        wp_enqueue_script('mp');
        wp_localize_script('mp', 'MP', array(
            'api'     => esc_url_raw(rest_url('menu-pedidos/v1/')),
            'nonce'   => wp_create_nonce('mp_pedido'),
            // con sesión iniciada, la API REST sólo reconoce al usuario con este nonce;
            // sin él lo trata como visitante y el nonce de arriba deja de coincidir
            'rest'    => wp_create_nonce('wp_rest'),
            'moneda'  => $o['moneda'],
            'envio'   => (float) $o['costo_envio'],
            'minimo'  => (float) $o['minimo'],
            'abierto' => MP_Settings::abierto(),
            'txt'     => array(
                'agregado' => __('Agregado', 'wp-menu-pedidos'),
                'vacio'    => __('Tu pedido está vacío', 'wp-menu-pedidos'),
                'minimo'   => __('Pedido mínimo:', 'wp-menu-pedidos'),
                'enviando' => __('Enviando…', 'wp-menu-pedidos'),
                'gracias'  => __('¡Pedido recibido! Código', 'wp-menu-pedidos'),
                'wa'       => __('Enviar el pedido por WhatsApp', 'wp-menu-pedidos'),
                'error'    => __('No se pudo enviar el pedido. Intenta de nuevo.', 'wp-menu-pedidos'),
                'quitar'   => __('Quitar', 'wp-menu-pedidos'),
            ),
        ));

        ob_start();
        ?>
        <div class="mp" data-mp>
            <?php if (!MP_Settings::abierto()) : ?>
                <p class="mp-cerrado" role="status"><?php esc_html_e('Ahora estamos cerrados: puedes ver la carta, pero no hacer pedidos.', 'wp-menu-pedidos'); ?></p>
            <?php endif; ?>
            <?php if (count($terms) > 1) : ?>
                <nav class="mp-nav" aria-label="<?php esc_attr_e('Categorías de la carta', 'wp-menu-pedidos'); ?>">
                    <?php foreach ($terms as $t) : ?><a href="#mp-<?php echo esc_attr($t->slug); ?>"><?php echo esc_html($t->name); ?></a><?php endforeach; ?>
                </nav>
            <?php endif; ?>

            <?php foreach ($terms as $t) :
                $platos = get_posts(array(
                    'post_type' => MP_Catalog::CPT, 'post_status' => 'publish', 'posts_per_page' => -1,
                    'orderby' => array('menu_order' => 'ASC', 'title' => 'ASC'),
                    'tax_query' => array(array('taxonomy' => MP_Catalog::CAT, 'terms' => $t->term_id)),
                ));
                if (!$platos) continue; ?>
                <section class="mp-cat" id="mp-<?php echo esc_attr($t->slug); ?>">
                    <h2><?php echo esc_html($t->name); ?></h2>
                    <?php if ($t->description) : ?><p class="mp-cat-desc"><?php echo esc_html($t->description); ?></p><?php endif; ?>
                    <div class="mp-grid">
                        <?php foreach ($platos as $p) :
                            $agotado = !MP_Catalog::disponible($p->ID);
                            $tags = wp_get_post_terms($p->ID, MP_Catalog::TAG, array('fields' => 'names')); ?>
                            <article class="mp-plato<?php echo $agotado ? ' is-agotado' : ''; ?>" data-id="<?php echo (int) $p->ID; ?>" data-nombre="<?php echo esc_attr($p->post_title); ?>" data-precio="<?php echo esc_attr(MP_Catalog::precio($p->ID)); ?>">
                                <?php if (has_post_thumbnail($p)) : ?>
                                    <?php echo get_the_post_thumbnail($p, 'medium', array('loading' => 'lazy', 'class' => 'mp-foto')); ?>
                                <?php endif; ?>
                                <div class="mp-info">
                                    <h3><?php echo esc_html($p->post_title); ?></h3>
                                    <?php if ($p->post_excerpt) : ?><p><?php echo esc_html($p->post_excerpt); ?></p><?php endif; ?>
                                    <?php if (!is_wp_error($tags) && $tags) : ?>
                                        <ul class="mp-tags"><?php foreach ($tags as $tag) : ?><li><?php echo esc_html($tag); ?></li><?php endforeach; ?></ul>
                                    <?php endif; ?>
                                    <?php $ops = MP_Catalog::opciones($p->ID); if ($ops && !$agotado) : ?>
                                        <fieldset class="mp-ops"><legend><?php esc_html_e('Opciones', 'wp-menu-pedidos'); ?></legend>
                                            <?php foreach ($ops as $i => $op) : ?>
                                                <label><input type="checkbox" value="<?php echo (int) $i; ?>" data-extra="<?php echo esc_attr($op['extra']); ?>" data-nombre="<?php echo esc_attr($op['nombre']); ?>">
                                                    <?php echo esc_html($op['nombre']); ?><?php if ($op['extra'] > 0) echo ' +' . esc_html(MP_Orders::money($op['extra'])); ?></label>
                                            <?php endforeach; ?>
                                        </fieldset>
                                    <?php endif; ?>
                                    <div class="mp-pie">
                                        <strong class="mp-precio"><?php echo esc_html(MP_Orders::money(MP_Catalog::precio($p->ID))); ?></strong>
                                        <?php if ($agotado) : ?>
                                            <span class="mp-agotado"><?php esc_html_e('Agotado', 'wp-menu-pedidos'); ?></span>
                                        <?php else : ?>
                                            <button type="button" class="mp-add" <?php disabled(!MP_Settings::abierto()); ?>><?php esc_html_e('Agregar', 'wp-menu-pedidos'); ?></button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>

            <div class="mp-barra" hidden>
                <button type="button" class="mp-ver"><span class="mp-count">0</span> · <span class="mp-sum"></span></button>
            </div>

            <dialog class="mp-carrito" aria-labelledby="mp-titulo">
                <form method="dialog" class="mp-cerrar-form"><button class="mp-x" aria-label="<?php esc_attr_e('Cerrar', 'wp-menu-pedidos'); ?>">×</button></form>
                <h2 id="mp-titulo"><?php esc_html_e('Tu pedido', 'wp-menu-pedidos'); ?></h2>
                <ul class="mp-items"></ul>
                <p class="mp-totales"></p>
                <form class="mp-checkout">
                    <div class="mp-hp" aria-hidden="true"><input type="text" name="web" tabindex="-1" autocomplete="off"></div>
                    <fieldset class="mp-entrega"><legend><?php esc_html_e('Entrega', 'wp-menu-pedidos'); ?></legend>
                        <?php if ($o['retiro']) : ?><label><input type="radio" name="entrega" value="retiro" checked> <?php esc_html_e('Retiro en el local', 'wp-menu-pedidos'); ?></label><?php endif; ?>
                        <?php if ($o['delivery']) : ?><label><input type="radio" name="entrega" value="delivery" <?php checked(!$o['retiro']); ?>> <?php esc_html_e('Envío a domicilio', 'wp-menu-pedidos'); ?><?php if ($o['costo_envio'] > 0) echo ' (+' . esc_html(MP_Orders::money($o['costo_envio'])) . ')'; ?></label><?php endif; ?>
                    </fieldset>
                    <label class="mp-dir" hidden><?php esc_html_e('Dirección', 'wp-menu-pedidos'); ?> <input type="text" name="direccion" autocomplete="street-address"></label>
                    <label><?php esc_html_e('Nombre', 'wp-menu-pedidos'); ?> <input type="text" name="nombre" required autocomplete="name"></label>
                    <label><?php esc_html_e('Teléfono', 'wp-menu-pedidos'); ?> <input type="tel" name="telefono" required autocomplete="tel"></label>
                    <label><?php esc_html_e('Notas (opcional)', 'wp-menu-pedidos'); ?> <textarea name="notas" rows="2"></textarea></label>
                    <p class="mp-msg" role="alert"></p>
                    <button type="submit" class="mp-enviar"><?php esc_html_e('Hacer el pedido', 'wp-menu-pedidos'); ?></button>
                </form>
                <div class="mp-listo" hidden></div>
            </dialog>
        </div>
        <?php
        return ob_get_clean();
    }
}
