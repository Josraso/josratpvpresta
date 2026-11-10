/**
 * josratpvpresta - núcleo de la pantalla de venta.
 *
 * Expone window.JTP con:
 *  - JTP.api(action, data)            llamada a la API del TPV (Promise)
 *  - JTP.addonApi(module, call, params) llamada a la API de un addon
 *  - JTP.on(evento, fn) / JTP.emit()  bus de eventos (ver ADDONS.md)
 *  - JTP.state                        estado actual (empleado, caja, carrito…)
 *  - JTP.registerAction({...})        botón extra en la barra de acciones del ticket
 *  - JTP.registerMenuItem({...})      opción extra en el menú superior
 *  - JTP.registerPaymentHandler(code, fn) cobro gestionado por un addon (datáfono, QR…)
 *  - JTP.registerSaleButton({...})    botón extra en "Venta completada" y en el detalle de una venta
 *  - JTP.ui.modal / toast / confirm / prompt  utilidades de interfaz
 */
(function (window, document) {
  'use strict';

  var cfg = window.JTP_CONFIG || {};
  var listeners = {};

  var JTP = {
    config: cfg,
    version: cfg.version,
    state: {
      employee: null,
      permissions: {},
      register: null,
      registers: [],
      session: null,
      cart: null,
      paymentMethods: [],
      favorites: [],
      favoriteIds: [],
      print: {},
      addons: []
    },
    ext: {
      actions: [],
      menu: [],
      saleButtons: [],
      paymentHandlers: {}
    },
    ui: {}
  };

  // ------------------------------------------------------------------ eventos

  JTP.on = function (evt, fn) {
    (listeners[evt] = listeners[evt] || []).push(fn);
    return function () {
      listeners[evt] = (listeners[evt] || []).filter(function (f) { return f !== fn; });
    };
  };

  JTP.emit = function (evt, payload) {
    (listeners[evt] || []).slice().forEach(function (fn) {
      try { fn(payload, JTP); } catch (e) { console.error('[JTP] listener ' + evt, e); }
    });
  };

  // ------------------------------------------------------------------ extensiones

  JTP.registerAction = function (a) {
    // a = {id, label, icon?, onClick(JTP), visible?(JTP) }
    if (!a || !a.id) { return; }
    JTP.ext.actions = JTP.ext.actions.filter(function (x) { return x.id !== a.id; });
    JTP.ext.actions.push(a);
    JTP.emit('ui:refresh');
  };

  JTP.registerMenuItem = function (m) {
    if (!m || !m.id) { return; }
    JTP.ext.menu = JTP.ext.menu.filter(function (x) { return x.id !== m.id; });
    JTP.ext.menu.push(m);
  };

  /**
   * b = {id, label, onClick(ctx, btn), visible?(ctx)}; ctx = {id_sale, ticket?, sale?, customer?}
   */
  JTP.registerSaleButton = function (b) {
    if (!b || !b.id) { return; }
    JTP.ext.saleButtons = JTP.ext.saleButtons.filter(function (x) { return x.id !== b.id; });
    JTP.ext.saleButtons.push(b);
  };

  /**
   * fn(amount, ctx) => Promise<{ok:true, reference:'...'}|{ok:false, error:'...'}>
   */
  JTP.registerPaymentHandler = function (code, fn) {
    JTP.ext.paymentHandlers[code] = fn;
  };

  // ------------------------------------------------------------------ API

  JTP.api = function (action, data) {
    return fetch(cfg.api, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-JTP-CSRF': cfg.csrf || '' },
      body: JSON.stringify({ action: action, data: data || {} })
    }).then(function (r) {
      return r.text().then(function (t) {
        var j;
        try { j = JSON.parse(t); } catch (e) {
          throw new JtpError('Respuesta no válida del servidor (' + r.status + ')', {});
        }
        if (!j.ok) {
          var err = new JtpError(j.error || 'Error', j.data || {});
          if (j.data && j.data.auth) { JTP.emit('auth:required', j.data); }
          throw err;
        }
        return j.data;
      });
    }, function () {
      throw new JtpError('Sin conexión con la tienda. Revisa la red.', { network: true });
    });
  };

  JTP.addonApi = function (module, call, params) {
    return JTP.api('addon', { module: module, call: call, params: params || {} });
  };

  function JtpError(message, data) {
    this.message = message;
    this.data = data || {};
  }
  JtpError.prototype = Object.create(Error.prototype);
  JTP.Error = JtpError;

  // ------------------------------------------------------------------ utilidades

  var fmt = null;
  JTP.money = function (v) {
    v = Number(v) || 0;
    try {
      if (!fmt) {
        fmt = new Intl.NumberFormat(cfg.locale || 'es-ES', { style: 'currency', currency: (cfg.currency && cfg.currency.iso) || 'EUR' });
      }
      return fmt.format(v);
    } catch (e) {
      return v.toFixed(2) + ' ' + ((cfg.currency && cfg.currency.sign) || '€');
    }
  };

  JTP.num = function (s) {
    if (typeof s === 'number') { return s; }
    s = String(s || '').trim().replace(/\s/g, '');
    if (s.indexOf(',') !== -1) { s = s.replace(/\./g, '').replace(',', '.'); }
    var n = parseFloat(s);
    return isNaN(n) ? 0 : n;
  };

  JTP.round = function (v) { return Math.round((Number(v) || 0) * 100) / 100; };

  JTP.esc = function (s) {
    return String(s === null || s === undefined ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  };

  JTP.can = function (perm) { return !!(JTP.state.permissions && JTP.state.permissions[perm]); };

  /**
   * Crea un elemento: JTP.h('div.clase#id', {attrs}, [hijos|texto])
   */
  JTP.h = function (sel, attrs, children) {
    var m = sel.match(/^([a-z0-9]+)?((?:[.#][\w-]+)*)$/i) || [];
    var el = document.createElement(m[1] || 'div');
    (m[2] || '').replace(/([.#])([\w-]+)/g, function (_, t, v) {
      if (t === '.') { el.classList.add(v); } else { el.id = v; }
    });
    if (attrs && (typeof attrs !== 'object' || Array.isArray(attrs) || attrs.nodeType)) {
      children = attrs;
      attrs = null;
    }
    if (attrs) {
      Object.keys(attrs).forEach(function (k) {
        var v = attrs[k];
        if (v === null || v === undefined || v === false) { return; }
        if (k.indexOf('on') === 0 && typeof v === 'function') {
          el.addEventListener(k.substring(2).toLowerCase(), v);
        } else if (k === 'html') {
          el.innerHTML = v;
        } else if (k === 'text') {
          el.textContent = v;
        } else if (k === 'style' && typeof v === 'object') {
          Object.keys(v).forEach(function (s) { el.style[s] = v[s]; });
        } else if (k === 'dataset') {
          Object.keys(v).forEach(function (d) { el.dataset[d] = v[d]; });
        } else {
          el.setAttribute(k, v === true ? '' : v);
        }
      });
    }
    (Array.isArray(children) ? children : (children === undefined || children === null ? [] : [children])).forEach(function (c) {
      if (c === null || c === undefined || c === false) { return; }
      el.appendChild(c.nodeType ? c : document.createTextNode(String(c)));
    });
    return el;
  };

  // ------------------------------------------------------------------ lector de códigos

  /**
   * Los lectores USB "teclean" muy rápido y terminan en Enter. Si el foco no está en
   * un campo de texto, acumulamos esas pulsaciones y emitimos 'scan'.
   */
  (function () {
    var buf = '';
    var last = 0;
    document.addEventListener('keydown', function (e) {
      var t = e.target;
      var inField = t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.isContentEditable);
      if (inField && !t.hasAttribute('data-scan')) { return; }
      if (inField) { return; }
      var now = Date.now();
      if (now - last > 80) { buf = ''; }
      last = now;
      if (e.key === 'Enter') {
        if (buf.length >= 3) {
          e.preventDefault();
          JTP.emit('scan', buf);
        }
        buf = '';
        return;
      }
      if (e.key && e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) {
        buf += e.key;
      }
    }, true);
  })();

  // ------------------------------------------------------------------ arranque

  JTP.start = function () {
    JTP.emit('init');
    if (JTP.ui.boot) { JTP.ui.boot(); }
  };

  window.JTP = JTP;
})(window, document);
