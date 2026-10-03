/* Carrito en tiempo real. Vive en localStorage (sobrevive a una recarga) y se manda
   como { id, cantidad, opciones } al servidor, que recalcula todos los precios: lo que
   se muestra acá es sólo una vista previa. */
(function () {
  'use strict';
  var root = document.querySelector('[data-mp]');
  if (!root || !window.MP) return;

  // wp_localize_script entrega todo como texto: "500" + 3100 daría "3100500"
  var ENVIO = Number(MP.envio) || 0;
  var MINIMO = Number(MP.minimo) || 0;

  var KEY = 'mp_carrito';
  var cart = [];
  try { cart = JSON.parse(localStorage.getItem(KEY) || '[]') || []; } catch (e) { cart = []; }

  var barra = root.querySelector('.mp-barra');
  var dialog = root.querySelector('.mp-carrito');
  var list = root.querySelector('.mp-items');
  var totales = root.querySelector('.mp-totales');
  var form = root.querySelector('.mp-checkout');
  var msg = root.querySelector('.mp-msg');
  var listo = root.querySelector('.mp-listo');
  var dir = root.querySelector('.mp-dir');

  function money(n) {
    return MP.moneda + Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }
  function save() {
    try { localStorage.setItem(KEY, JSON.stringify(cart)); } catch (e) { /* modo privado */ }
  }
  function delivery() {
    var r = form.querySelector('[name=entrega]:checked');
    return r && r.value === 'delivery';
  }
  function subtotal() {
    return cart.reduce(function (s, it) { return s + it.unitario * it.cantidad; }, 0);
  }
  // los platos que ya no están en la carta (o se agotaron) se sacan del carrito
  cart = cart.filter(function (it) {
    var el = root.querySelector('.mp-plato[data-id="' + it.id + '"]');
    return el && !el.classList.contains('is-agotado');
  });

  function render() {
    var count = cart.reduce(function (s, it) { return s + it.cantidad; }, 0);
    barra.hidden = count === 0;
    root.querySelector('.mp-count').textContent = count;
    root.querySelector('.mp-sum').textContent = money(subtotal());

    list.innerHTML = '';
    if (!cart.length) {
      var li = document.createElement('li');
      li.textContent = MP.txt.vacio;
      list.appendChild(li);
    }
    cart.forEach(function (it, i) {
      var li = document.createElement('li');
      li.className = 'mp-item';
      var name = document.createElement('span');
      name.textContent = it.nombre + (it.etiquetas.length ? ' (' + it.etiquetas.join(', ') + ')' : '');
      var qty = document.createElement('span');
      qty.className = 'mp-qty';
      [['−', -1], ['+', 1]].forEach(function (b, k) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.textContent = b[0];
        btn.setAttribute('aria-label', b[1] < 0 ? MP.txt.quitar : '+1');
        btn.onclick = function () {
          it.cantidad += b[1];
          if (it.cantidad < 1) cart.splice(i, 1);
          save(); render();
        };
        if (k === 1) qty.appendChild(document.createTextNode(' ' + it.cantidad + ' '));
        qty.appendChild(btn);
      });
      var price = document.createElement('strong');
      price.textContent = money(it.unitario * it.cantidad);
      li.append(name, qty, price);
      list.appendChild(li);
    });

    var envio = delivery() ? ENVIO : 0;
    var t = [];
    if (envio > 0) t.push('Envío ' + money(envio));
    t.push('Total ' + money(subtotal() + envio));
    if (MINIMO > 0 && subtotal() < MINIMO) t.push(MP.txt.minimo + ' ' + money(MINIMO));
    totales.textContent = t.join(' · ');
    dir.hidden = !delivery();
    dir.querySelector('input').required = delivery();
  }

  root.addEventListener('click', function (e) {
    var add = e.target.closest('.mp-add');
    if (!add) return;
    var card = add.closest('.mp-plato');
    var ops = Array.prototype.slice.call(card.querySelectorAll('.mp-ops input:checked'));
    var key = card.dataset.id + ':' + ops.map(function (o) { return o.value; }).join(',');
    var existing = cart.filter(function (it) { return it.key === key; })[0];
    if (existing) {
      existing.cantidad++;
    } else {
      cart.push({
        key: key,
        id: Number(card.dataset.id),
        nombre: card.dataset.nombre,
        opciones: ops.map(function (o) { return Number(o.value); }),
        etiquetas: ops.map(function (o) { return o.dataset.nombre; }),
        unitario: Number(card.dataset.precio) + ops.reduce(function (s, o) { return s + Number(o.dataset.extra || 0); }, 0),
        cantidad: 1,
      });
    }
    ops.forEach(function (o) { o.checked = false; });
    save(); render();
    add.textContent = MP.txt.agregado + ' ✓';
    setTimeout(function () { add.textContent = add.dataset.label; }, 1200);
  });
  root.querySelectorAll('.mp-add').forEach(function (b) { b.dataset.label = b.textContent; });

  root.querySelector('.mp-ver').addEventListener('click', function () {
    listo.hidden = true;
    form.hidden = false;
    if (dialog.showModal) dialog.showModal(); else dialog.setAttribute('open', '');
  });
  form.addEventListener('change', render);

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (!cart.length) return;
    var fd = new FormData(form);
    var body = {
      items: cart.map(function (it) { return { id: it.id, cantidad: it.cantidad, opciones: it.opciones }; }),
      entrega: fd.get('entrega'),
      direccion: fd.get('direccion') || '',
      nombre: fd.get('nombre'),
      telefono: fd.get('telefono'),
      notas: fd.get('notas') || '',
      web: fd.get('web') || '',
    };
    var btn = form.querySelector('.mp-enviar');
    btn.disabled = true;
    msg.textContent = MP.txt.enviando;
    fetch(MP.api + 'pedido', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-MP-Nonce': MP.nonce, 'X-WP-Nonce': MP.rest },
      body: JSON.stringify(body),
    })
      .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
      .then(function (res) {
        btn.disabled = false;
        if (!res.ok) {
          msg.textContent = (res.d.errores || [res.d.message || MP.txt.error]).join(' ');
          return;
        }
        msg.textContent = '';
        cart = [];
        save(); render();
        form.hidden = true;
        listo.hidden = false;
        listo.innerHTML = '';
        var p = document.createElement('p');
        p.className = 'mp-ok';
        p.textContent = MP.txt.gracias + ' #' + res.d.codigo + ' · ' + res.d.total;
        listo.appendChild(p);
        if (res.d.whatsapp) {
          var a = document.createElement('a');
          a.href = res.d.whatsapp;
          a.target = '_blank';
          a.rel = 'noopener';
          a.className = 'mp-wa';
          a.textContent = MP.txt.wa;
          listo.appendChild(a);
        }
      })
      .catch(function () {
        btn.disabled = false;
        msg.textContent = MP.txt.error;
      });
  });

  render();
})();
