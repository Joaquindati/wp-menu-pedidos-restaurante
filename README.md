# Menú digital y pedidos online para restaurantes (plugin de WordPress)

**Carta digital y sistema de pedidos online** para restaurantes, bares, rotiserías y
cafeterías, dentro de tu propio WordPress y **sin comisiones de apps de delivery**. Los
clientes arman el pedido desde el celular, eligen **retiro en el local o envío a
domicilio** y lo mandan. El local recibe un aviso por **email** y el cliente puede
enviarle el pedido por **WhatsApp** con un toque.

> 🇬🇧 **English:** WordPress restaurant menu and online ordering plugin. Digital menu with
> photos, prices, options and dietary tags, a real-time cart, pickup or delivery, opening
> hours, email and WhatsApp order alerts, and an order dashboard with statuses. No
> third-party fees.

## Características

**Carta**
- Platos con foto, descripción, precio y **opciones con precio extra** (`Doble carne|300`).
- **Categorías** (entradas, principales, postres, bebidas…) con navegación fija arriba.
- **Etiquetas** como *Vegano*, *Vegetariano*, *Sin TACC* y *Picante* (se crean al activar).
- Platos **agotados**: se ven en la carta, pero no se pueden pedir.

**Pedido**
- **Carrito en tiempo real**: suma, resta y total al instante, sin recargar la página. Se
  guarda en el navegador, así que no se pierde si recargas.
- **Retiro o delivery**, con costo de envío y **pedido mínimo** configurables.
- **Horarios de atención** por día, incluso rangos que pasan la medianoche
  (`20:00-01:30`). Fuera de horario, la carta se ve pero no se puede pedir.
- **Precios calculados en el servidor**: el total sale de la carta guardada, nunca de lo que
  manda el navegador.
- **Avisos**:
  - email al local con el detalle de cada pedido nuevo;
  - al terminar, un botón **"Enviar el pedido por WhatsApp"** abre WhatsApp con el pedido
    ya escrito para el número del local.

**Panel de administración**
- Listado de pedidos con detalle, total, forma de entrega, teléfono (con enlace para llamar)
  y **estado**.
- Estados *Nuevo → En preparación → Listo → Entregado* (o *Cancelado*), que se avanzan con
  un clic. Filtro por estado y **burbuja con los pedidos nuevos** en el menú.

**Mobile first**: botones de 44 px como mínimo, carrito en un diálogo nativo (`<dialog>`) y
fotos con carga diferida.

## Instalación

1. Clona el repo en `wp-content/plugins/` (o súbelo como ZIP) y actívalo:
   ```bash
   cd wp-content/plugins
   git clone https://github.com/Joaquindati/wp-menu-pedidos-restaurante.git
   ```
2. Configura horarios, entrega, envío, WhatsApp y email en **Pedidos → Ajustes**.
3. Carga los platos en **Carta**: título, extracto (descripción), imagen destacada,
   categoría, etiquetas, precio y opciones.
4. Pega el shortcode en una página:
   ```
   [menu_pedidos]
   [menu_pedidos categorias="entradas,principales,postres,bebidas"]
   ```
   Con `categorias` se elige qué categorías se muestran y en qué orden.

## API

| Método | Ruta | Uso |
|---|---|---|
| `POST` | `/wp-json/menu-pedidos/v1/pedido` | Crear un pedido (requiere el nonce de la carta) |
| `GET` | `/wp-json/menu-pedidos/v1/estado` | `{ "abierto": true }` según el horario |

Hooks: `mp_pedido_creado ($id)` y `mp_estado_cambiado ($id, $estado)`. Sirven para
conectar una impresora de comandas, un bot o un aviso por SMS.

## No incluye

- **Cobro online**: el pago se arregla al retirar o al recibir el pedido. Con los hooks se
  puede conectar a WooCommerce o a una pasarela de pago.
- **WhatsApp automático**: el aviso por WhatsApp lo envía el cliente con un toque (enlace
  `wa.me`), no un bot. Así no hace falta la API de WhatsApp Business.
- **Caché de páginas**: si usas un plugin de caché, excluye la página de la carta. El
  nonce del formulario vence a las 24 horas.

## Requisitos

WordPress 5.8+ · PHP 7.4+ (probado con PHP 8.3, la última versión de WordPress y un theme de bloques).

## Autor

**Joaquín Dati**, desarrollador WordPress y full stack.
[joaquindati.com](https://joaquindati.com)

Licencia GPL v2 o posterior.
