/**
 * josratpvpresta - interfaz de la pantalla de venta.
 *
 * Eventos que emite (para addons, ver ADDONS.md):
 *   ready, cart:updated, customer:changed, sale:completed, return:completed,
 *   session:opened, session:closed, product:selected, ui:refresh, payment:modal
 */
(function (window, document, JTP) {
  'use strict';

  var h = JTP.h;
  var esc = JTP.esc;
  var money = JTP.money;
  var S = JTP.state;
  var UI = JTP.ui;
  var app;
  var els = {};
  var view = { mode: 'favorites', category: null, search: '', results: [], more: false, page: 1 };

  // ================================================================== utilidades de interfaz

  UI.toast = function (msg, type) {
    var box = document.getElementById('jtp-toasts');
    if (!box) {
      box = h('div#jtp-toasts.jtp-toasts');
      document.body.appendChild(box);
    }
    var t = h('div.jtp-toast.' + (type || 'info'), msg);
    box.appendChild(t);
    setTimeout(function () { t.classList.add('out'); }, type === 'error' ? 4500 : 2200);
    setTimeout(function () { t.remove(); }, type === 'error' ? 5000 : 2700);
  };

  UI.error = function (e) {
    UI.toast(e && e.message ? e.message : String(e), 'error');
    if (window.console) { console.warn(e); }
  };

  var modalStack = [];

  /**
   * UI.modal({title, body (Node|string), footer (Node[]), size: 'sm'|'md'|'lg'|'xl', onClose})
   * Devuelve {el, body, close, setBody}
   */
  UI.modal = function (o) {
    var body = h('div.jtp-modal-body');
    var footer = h('div.jtp-modal-footer');
    var box = h('div.jtp-modal.' + (o.size || 'md'), [
      h('div.jtp-modal-head', [
        h('h2', o.title || ''),
        h('button.jtp-x', { type: 'button', 'aria-label': 'Cerrar', onclick: function () { m.close(); } }, '×')
      ]),
      body,
      footer
    ]);
    var overlay = h('div.jtp-overlay', { onmousedown: function (e) { if (e.target === overlay && !o.sticky) { m.close(); } } }, box);
    var m = {
      el: box,
      body: body,
      footer: footer,
      closed: false,
      close: function (v) {
        if (m.closed) { return; }
        m.closed = true;
        overlay.remove();
        modalStack = modalStack.filter(function (x) { return x !== m; });
        if (o.onClose) { o.onClose(v); }
        if (!modalStack.length) { focusSearch(); }
      },
      setBody: function (n) {
        body.innerHTML = '';
        if (typeof n === 'string') { body.innerHTML = n; } else if (n) { body.appendChild(n); }
      },
      setFooter: function (nodes) {
        footer.innerHTML = '';
        (nodes || []).forEach(function (n) { if (n) { footer.appendChild(n); } });
        footer.style.display = nodes && nodes.length ? '' : 'none';
      }
    };
    m.setBody(o.body);
    m.setFooter(o.footer);
    document.body.appendChild(overlay);
    modalStack.push(m);
    setTimeout(function () {
      var f = box.querySelector('[autofocus]') || box.querySelector('input:not([type=hidden]):not([readonly]), select, textarea');
      if (f) { f.focus(); if (f.select) { f.select(); } }
    }, 30);
    return m;
  };

  UI.topModal = function () { return modalStack[modalStack.length - 1]; };

  UI.confirm = function (msg, okLabel) {
    return new Promise(function (resolve) {
      var m = UI.modal({
        title: 'Confirmar',
        size: 'sm',
        body: h('p.jtp-lead', msg),
        onClose: function (v) { resolve(!!v); },
        footer: [
          h('button.jtp-btn', { type: 'button', onclick: function () { m.close(false); } }, 'Cancelar'),
          h('button.jtp-btn.primary', { type: 'button', autofocus: true, onclick: function () { m.close(true); } }, okLabel || 'Aceptar')
        ]
      });
    });
  };

  UI.prompt = function (title, value, opts) {
    opts = opts || {};
    return new Promise(function (resolve) {
      var input = h(opts.multiline ? 'textarea.jtp-input' : 'input.jtp-input', { type: opts.type || 'text', value: value || '', placeholder: opts.placeholder || '', inputmode: opts.inputmode || null, rows: opts.multiline ? 4 : null });
      if (opts.multiline) { input.value = value || ''; }
      var result = null;
      var m = UI.modal({
        title: title,
        size: 'sm',
        body: h('form', { onsubmit: function (e) { e.preventDefault(); result = input.value; m.close(); } }, [
          opts.label ? h('label.jtp-label', opts.label) : null,
          input
        ]),
        onClose: function () { resolve(result); },
        footer: [
          h('button.jtp-btn', { type: 'button', onclick: function () { m.close(); } }, 'Cancelar'),
          h('button.jtp-btn.primary', { type: 'button', onclick: function () { result = input.value; m.close(); } }, opts.ok || 'Aceptar')
        ]
      });
    });
  };

  /**
   * Teclado numérico táctil ligado a un input.
   */
  UI.keypad = function (input, onEnter) {
    var keys = ['7', '8', '9', '4', '5', '6', '1', '2', '3', ',', '0', '⌫'];
    return h('div.jtp-keypad', keys.map(function (k) {
      return h('button.jtp-key', {
        type: 'button',
        onclick: function () {
          var v = input.value;
          // Valor propuesto/seleccionado: la primera tecla lo sustituye (como al teclear)
          if (input.dataset.fresh === '1' || (document.activeElement === input && input.selectionStart === 0 && input.selectionEnd === v.length && v.length)) {
            v = '';
            input.dataset.fresh = '';
            if (k === '⌫') { input.value = ''; input.dispatchEvent(new Event('input', { bubbles: true })); input.focus(); return; }
          }
          if (k === '⌫') { input.value = v.slice(0, -1); } else if (k === ',') { if (v.indexOf(',') === -1) { input.value = (v || '0') + ','; } } else { input.value = v + k; }
          input.dispatchEvent(new Event('input', { bubbles: true }));
          input.focus();
        }
      }, k);
    }).concat(onEnter ? [h('button.jtp-key.enter', { type: 'button', onclick: onEnter }, 'OK')] : []));
  };

  function busy(btn, promise) {
    if (btn) { btn.disabled = true; btn.classList.add('busy'); }
    return promise.then(function (r) {
      if (btn) { btn.disabled = false; btn.classList.remove('busy'); }
      return r;
    }, function (e) {
      if (btn) { btn.disabled = false; btn.classList.remove('busy'); }
      throw e;
    });
  }
  UI.busy = busy;

  function focusSearch() {
    if (els.search && !modalStack.length && window.innerWidth > 900) { els.search.focus(); }
  }
  UI.focusSearch = focusSearch;

  function screen(node) {
    app = app || document.getElementById('jtp-app');
    app.innerHTML = '';
    app.appendChild(node);
  }

  // ================================================================== arranque y acceso

  UI.boot = function () {
    ensureTicketCss();
    if (!JTP.config.logged) { return loginScreen(); }
    JTP.api('boot').then(function (d) {
      applyBoot(d);
      if (!S.register) { return registerScreen(); }
      mainScreen();
      JTP.emit('ready', S);
    }).catch(function (e) {
      if (e.data && e.data.auth) { return; }
      screen(h('div.jtp-center', [h('div.jtp-card', [h('h1', 'No se pudo cargar el TPV'), h('p', e.message), h('button.jtp-btn.primary', { onclick: function () { location.reload(); } }, 'Reintentar')])]));
    });
  };

  function applyBoot(d) {
    S.employee = d.employee;
    S.permissions = d.permissions || {};
    S.registers = d.registers || [];
    S.register = d.register;
    S.session = d.session;
    S.print = d.print || {};
    S.addons = d.addons || [];
    S.requireSession = d.require_session;
    S.countries = d.countries || [];
    S.defaultCountry = d.default_country;
    if (d.register) {
      S.paymentMethods = d.payment_methods || [];
      S.favorites = d.favorites || [];
      S.favoriteIds = d.favorite_ids || [];
      S.categories = d.categories;
      S.cart = d.cart;
      S.parkedCount = d.parked_count || 0;
    }
  }

  JTP.on('auth:required', function (d) {
    if (d.auth === 'login') { JTP.config.logged = false; loginScreen(); }
    if (d.auth === 'register') { registerScreen(); }
    if (d.auth === 'csrf') { location.reload(); }
    if (d.auth === 'device') { location.reload(); }
  });

  function loginScreen() {
    modalStack.slice().forEach(function (m) { m.close(); });
    var list = h('div.jtp-emp-list', h('div.jtp-muted', 'Cargando empleados…'));
    var pinBox = h('div.jtp-pin-box');
    screen(h('div.jtp-center', h('div.jtp-card.login', [
      h('h1', JTP.config.shopName || 'TPV'),
      h('p.jtp-muted', 'Elige tu nombre e introduce tu PIN'),
      list,
      pinBox
    ])));
    JTP.api('employees').then(function (emps) {
      list.innerHTML = '';
      if (!emps.length) {
        list.appendChild(h('div.jtp-alert', 'No hay empleados con PIN. Configúralos en el back office (TPV > Empleados) o abre el TPV desde el back office.'));
        return;
      }
      emps.forEach(function (e) {
        list.appendChild(h('button.jtp-emp', { type: 'button', onclick: function () { askPin(e); } }, [h('span.jtp-avatar', initials(e.name)), h('span', e.name)]));
      });
    }).catch(UI.error);

    function askPin(emp) {
      var input = h('input.jtp-input.pin', { type: 'password', inputmode: 'numeric', autocomplete: 'off', maxlength: 8, placeholder: '••••' });
      var submit = function () {
        busy(null, JTP.api('login', { id_employee: emp.id, pin: input.value })).then(function (r) {
          JTP.config.csrf = r.csrf;
          JTP.config.logged = true;
          UI.boot();
        }).catch(function (e) { input.value = ''; UI.error(e); input.focus(); });
      };
      input.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { submit(); } });
      pinBox.innerHTML = '';
      pinBox.appendChild(h('div', [h('div.jtp-pin-name', emp.name), input, UI.keypad(input, submit)]));
      input.focus();
    }
  }

  function initials(name) {
    return String(name || '?').split(' ').map(function (p) { return p.charAt(0); }).join('').substring(0, 2).toUpperCase();
  }

  function registerScreen() {
    screen(h('div.jtp-center', h('div.jtp-card', [
      h('h1', 'Elige la caja'),
      h('div.jtp-emp-list', (S.registers || []).map(function (r) {
        return h('button.jtp-emp', { type: 'button', onclick: function () {
          JTP.api('selectRegister', { id_register: r.id }).then(function (d) { applyBoot(d); mainScreen(); JTP.emit('ready', S); }).catch(UI.error);
        } }, [h('span.jtp-avatar', r.series), h('span', r.name)]);
      })),
      !S.registers || !S.registers.length ? h('div.jtp-alert', 'No hay cajas activas para esta tienda.') : null
    ])));
  }

  function logout() {
    JTP.api('logout').then(function () { JTP.config.logged = false; loginScreen(); }, function () { location.reload(); });
  }

  // ================================================================== pantalla principal

  function mainScreen() {
    els = {};
    els.search = h('input.jtp-search', { type: 'search', placeholder: 'Buscar producto, referencia o escanear código (F2)', autocomplete: 'off', 'data-scan': '1' });
    els.grid = h('div.jtp-grid');
    els.crumbs = h('div.jtp-crumbs');
    els.lines = h('div.jtp-lines');
    els.totals = h('div.jtp-totals');
    els.customer = h('div.jtp-customer');
    els.actions = h('div.jtp-actions');
    els.pay = h('button.jtp-pay', { type: 'button', onclick: openPayment }, 'COBRAR');
    els.session = h('button.jtp-chip', { type: 'button', onclick: openCash });
    els.parked = h('button.jtp-top-btn', { type: 'button', onclick: openParked });
    els.menuExtra = h('span');
    els.qz = S.print.mode === 'qz' ? h('button.jtp-top-btn.jtp-qz', { type: 'button', onclick: qzInfo, title: 'Estado de QZ Tray' }) : h('span');

    var top = h('header.jtp-top', [
      h('div.jtp-brand', [h('strong', JTP.config.shopName || 'TPV'), h('span.jtp-reg', S.register.name)]),
      els.session,
      h('nav.jtp-nav', [
        h('button.jtp-top-btn', { type: 'button', onclick: openSales }, [icon('receipt'), 'Ventas']),
        els.parked,
        h('button.jtp-top-btn', { type: 'button', onclick: openCash }, [icon('cash'), 'Caja']),
        els.menuExtra,
        els.qz,
        h('button.jtp-top-btn', { type: 'button', onclick: openSettings, title: 'Impresora y ajustes' }, [icon('gear')]),
        h('button.jtp-top-btn.user', { type: 'button', onclick: switchEmployee, title: 'Cambiar de empleado' }, [h('span.jtp-avatar.sm', initials(S.employee.name)), h('span.jtp-hide-sm', S.employee.name)])
      ])
    ]);

    var catalog = h('section.jtp-catalog', [
      h('div.jtp-searchbar', [
        els.search,
        h('button.jtp-btn.icon', { type: 'button', title: 'Limpiar', onclick: function () { els.search.value = ''; showFavorites(); focusSearch(); } }, '×')
      ]),
      h('div.jtp-tabs', [
        h('button.jtp-tab', { type: 'button', 'data-tab': 'favorites', onclick: showFavorites }, '★ Favoritos'),
        h('button.jtp-tab', { type: 'button', 'data-tab': 'categories', onclick: function () { showCategory(0); } }, 'Categorías'),
        els.crumbs
      ]),
      els.grid
    ]);

    var panel = h('aside.jtp-panel', [
      els.customer,
      els.lines,
      els.totals,
      els.actions,
      els.pay
    ]);

    screen(h('div.jtp-shell', [top, h('main.jtp-main', [catalog, panel])]));

    var timer;
    els.search.addEventListener('input', function () {
      clearTimeout(timer);
      var q = els.search.value.trim();
      timer = setTimeout(function () {
        if (q.length >= 2) { doSearch(q, 1); } else if (!q) { showFavorites(); }
      }, 280);
    });
    els.search.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        clearTimeout(timer);
        var q = els.search.value.trim();
        if (q) { doScan(q); }
      }
    });

    renderMenuExtra();
    renderSession();
    renderParked();
    renderCart();
    showFavorites();
    focusSearch();
  }

  function icon(name) {
    var paths = {
      receipt: 'M6 2h12v20l-3-2-3 2-3-2-3 2V2zm3 5h6M9 11h6M9 15h4',
      cash: 'M2 6h20v12H2zM12 9a3 3 0 1 1 0 6 3 3 0 0 1 0-6zM5 9v6M19 9v6',
      pause: 'M8 5v14M16 5v14',
      gear: 'M12 8a4 4 0 1 1 0 8 4 4 0 0 1 0-8zm0-6v3m0 14v3M2 12h3m14 0h3M4.9 4.9l2.1 2.1m10 10 2.1 2.1M4.9 19.1 7 17m10-10 2.1-2.1',
      user: 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zm-7 9a7 7 0 0 1 14 0'
    };
    var span = document.createElement('span');
    span.className = 'jtp-ico';
    span.innerHTML = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="' + (paths[name] || '') + '"/></svg>';
    return span;
  }

  function renderMenuExtra() {
    els.menuExtra.innerHTML = '';
    JTP.ext.menu.forEach(function (m) {
      if (m.visible && !m.visible(JTP)) { return; }
      els.menuExtra.appendChild(h('button.jtp-top-btn', { type: 'button', onclick: function () { m.onClick(JTP); } }, m.label));
    });
  }

  function renderSession() {
    if (!els.session) { return; }
    els.session.className = 'jtp-chip ' + (S.session ? 'ok' : 'warn');
    els.session.textContent = S.session ? 'Caja abierta' : 'Caja cerrada';
  }

  function renderParked() {
    if (!els.parked) { return; }
    els.parked.innerHTML = '';
    els.parked.appendChild(icon('pause'));
    els.parked.appendChild(document.createTextNode('Aparcados'));
    if (S.parkedCount) { els.parked.appendChild(h('span.jtp-badge', String(S.parkedCount))); }
  }

  JTP.on('ui:refresh', function () {
    if (els.actions) { renderCart(); renderMenuExtra(); }
  });

  // ------------------------------------------------------------------ catálogo

  function setTab(tab) {
    view.mode = tab;
    Array.prototype.forEach.call(document.querySelectorAll('.jtp-tab'), function (b) {
      b.classList.toggle('active', b.getAttribute('data-tab') === tab);
    });
  }

  function showFavorites() {
    setTab('favorites');
    els.crumbs.innerHTML = '';
    renderGrid(S.favorites, false, S.favorites.length ? null : 'Sin favoritos todavía. Busca un producto y pulsa ★ para añadirlo aquí.');
  }

  function showCategory(id) {
    setTab('categories');
    els.grid.innerHTML = '';
    els.grid.appendChild(h('div.jtp-muted.pad', 'Cargando…'));
    Promise.all([JTP.api('categories', { id_category: id }), id ? JTP.api('search', { q: '', id_category: id }) : Promise.resolve({ products: [], more: false })]).then(function (r) {
      var cat = r[0];
      view.category = cat;
      els.crumbs.innerHTML = '';
      if (cat.id_parent || id) {
        els.crumbs.appendChild(h('button.jtp-crumb', { type: 'button', onclick: function () { showCategory(cat.id_parent); } }, '‹ Volver'));
        els.crumbs.appendChild(h('span.jtp-crumb-cur', cat.name));
      }
      els.grid.innerHTML = '';
      cat.children.forEach(function (c) {
        els.grid.appendChild(h('button.jtp-card-cat', { type: 'button', onclick: function () { showCategory(c.id); } }, c.name));
      });
      r[1].products.forEach(function (p) { els.grid.appendChild(productCard(p)); });
      if (!cat.children.length && !r[1].products.length) { els.grid.appendChild(h('div.jtp-muted.pad', 'Categoría vacía')); }
    }).catch(UI.error);
  }

  function doSearch(q, page) {
    setTab('search');
    view.search = q;
    JTP.api('search', { q: q, page: page || 1 }).then(function (r) {
      if (els.search.value.trim() !== q) { return; }
      view.page = page || 1;
      renderGrid(r.products, r.more, r.products.length ? null : 'Sin resultados para "' + q + '"');
    }).catch(UI.error);
  }

  function renderGrid(products, more, empty) {
    els.grid.innerHTML = '';
    if (empty) { els.grid.appendChild(h('div.jtp-muted.pad', empty)); }
    products.forEach(function (p) { els.grid.appendChild(productCard(p)); });
    if (more) {
      els.grid.appendChild(h('button.jtp-card-cat', { type: 'button', onclick: function () {
        JTP.api('search', { q: view.search, page: view.page + 1 }).then(function (r) {
          view.page++;
          els.grid.lastChild.remove();
          r.products.forEach(function (p) { els.grid.appendChild(productCard(p)); });
          if (r.more) { els.grid.appendChild(this); }
        }.bind(this)).catch(UI.error);
      } }, 'Ver más…'));
    }
  }

  function productCard(p) {
    var fav = S.favoriteIds.indexOf(p.id_product) !== -1;
    var card = h('div.jtp-prod' + (p.stock <= 0 ? '.oos' : ''), { role: 'button', tabindex: 0 }, [
      h('div.jtp-prod-img', p.image ? h('img', { src: p.image, alt: '', loading: 'lazy', onerror: function (e) { e.target.replaceWith(h('span.jtp-noimg', initials(p.name))); } }) : h('span.jtp-noimg', initials(p.name))),
      h('div.jtp-prod-name', p.name),
      h('div.jtp-prod-meta', [
        h('span.jtp-prod-price', money(p.price)),
        h('span.jtp-prod-stock', (p.has_combinations ? 'Variantes · ' : '') + 'Stock ' + p.stock)
      ]),
      JTP.can('favorites') ? h('button.jtp-fav' + (fav ? '.on' : ''), { type: 'button', title: 'Favorito', onclick: function (e) {
        e.stopPropagation();
        JTP.api('favoriteToggle', { id_product: p.id_product }).then(function (r) {
          S.favorites = r.favorites;
          S.favoriteIds = r.favorite_ids;
          e.target.classList.toggle('on', r.favorite);
          if (view.mode === 'favorites') { showFavorites(); }
        }).catch(UI.error);
      } }, '★') : null
    ]);
    var go = function () { selectProduct(p); };
    card.addEventListener('click', go);
    card.addEventListener('keydown', function (e) { if (e.key === 'Enter') { go(); } });
    return card;
  }

  function selectProduct(p) {
    JTP.emit('product:selected', p);
    if (p.has_combinations) {
      JTP.api('product', { id_product: p.id_product }).then(openVariants).catch(UI.error);
    } else {
      addToCart(p.id_product, 0, 1);
    }
  }

  /**
   * Pitido de caja: 'ok' al meter algo en el carrito, 'error' si no se encuentra o falla.
   */
  var audioCtx = null;
  JTP.beep = function (kind) {
    if (!S.print || S.print.beep === false) { return; }
    try {
      var AC = window.AudioContext || window.webkitAudioContext;
      if (!AC) { return; }
      audioCtx = audioCtx || new AC();
      if (audioCtx.state === 'suspended') { audioCtx.resume(); }
      var err = kind === 'error';
      var o = audioCtx.createOscillator();
      var g = audioCtx.createGain();
      var t = audioCtx.currentTime;
      var len = err ? 0.22 : 0.08;
      o.type = err ? 'square' : 'sine';
      o.frequency.value = err ? 300 : 1800;
      g.gain.setValueAtTime(0.0001, t);
      g.gain.exponentialRampToValueAtTime(err ? 0.15 : 0.3, t + 0.005);
      g.gain.setValueAtTime(err ? 0.15 : 0.3, t + len - 0.02);
      g.gain.exponentialRampToValueAtTime(0.0001, t + len);
      o.connect(g);
      g.connect(audioCtx.destination);
      o.start(t);
      o.stop(t + len + 0.02);
    } catch (e) { /* sin audio */ }
  };

  function addToCart(idProduct, idAttr, qty) {
    return JTP.api('cart.add', { id_product: idProduct, id_product_attribute: idAttr || 0, qty: qty || 1 }).then(function (cart) {
      setCart(cart, cart.last);
      JTP.beep('ok');
      return cart;
    }).catch(function (e) {
      if (e.data && e.data.needs_variant) {
        return JTP.api('product', { id_product: e.data.id_product }).then(openVariants);
      }
      JTP.beep('error');
      UI.error(e);
    });
  }
  JTP.addToCart = addToCart;

  function doScan(code) {
    JTP.api('scan', { code: code }).then(function (r) {
      els.search.value = '';
      if (r.type === 'cart') { setCart(r.cart, r.cart.last); showFavoritesIfSearch(); JTP.beep('ok'); }
      if (r.type === 'variant') { openVariants(r.product); }
      if (r.type === 'sale') { openSaleDetail(r.id_sale); }
      if (r.type === 'search') {
        els.search.value = code;
        setTab('search');
        view.search = code;
        if (!r.result.products.length) { JTP.beep('error'); }
        renderGrid(r.result.products, r.result.more, r.result.products.length ? null : 'Nada encontrado para "' + code + '"');
      }
    }).catch(function (e) { JTP.beep('error'); UI.error(e); });
  }

  function showFavoritesIfSearch() {
    if (view.mode === 'search') { showFavorites(); }
  }

  JTP.on('scan', function (code) {
    if (!els.search) { return; }
    var top = UI.topModal();
    if (top && top.onScan) { top.onScan(code); return; }
    if (top) { return; }
    doScan(code);
  });

  // ------------------------------------------------------------------ variantes

  function openVariants(p) {
    var qty = 1;
    var groups = p.groups;
    var combos = p.combinations;
    var body = h('div.jtp-variants');
    var info = h('div.jtp-var-info');
    var qtyInput = h('input.jtp-input.qty', { type: 'number', min: 1, value: 1, inputmode: 'numeric' });
    qtyInput.addEventListener('input', function () { qty = Math.max(1, parseInt(qtyInput.value, 10) || 1); });

    function comboFor(sel) {
      return combos.filter(function (c) {
        return Object.keys(sel).every(function (g) { return String(c.attributes[g]) === String(sel[g]); });
      });
    }

    function swatch(v, g) {
      if (g.type !== 'color') { return null; }
      if (v.texture) { return h('span.jtp-swatch', { style: { backgroundImage: 'url(' + v.texture + ')' } }); }
      return h('span.jtp-swatch', { style: { background: v.color || '#ccc' } });
    }

    function add(c, btn) {
      busy(btn, addToCart(p.id_product, c.id_product_attribute, qty)).then(function (cart) {
        if (!cart) { return; }
        UI.toast('Añadido: ' + p.name + ' ' + describe(c), 'ok');
        if (groups.length !== 2) { m.close(); }
      });
    }

    function describe(c) {
      return groups.map(function (g) {
        var v = g.values.filter(function (x) { return String(x.id) === String(c.attributes[g.id]); })[0];
        return v ? v.name : '';
      }).join(' / ');
    }

    function cellLabel(c) {
      return [h('span.jtp-cell-stock' + (c.stock <= 0 ? '.zero' : ''), String(c.stock)), c.price !== p.price ? h('small', money(c.price)) : null];
    }

    if (groups.length === 1) {
      var g = groups[0];
      body.appendChild(h('div.jtp-var-title', g.name));
      body.appendChild(h('div.jtp-var-buttons', g.values.map(function (v) {
        var c = comboFor(obj(g.id, v.id))[0];
        if (!c) { return null; }
        var b = h('button.jtp-var-btn' + (c.stock <= 0 ? '.zero' : ''), { type: 'button', onclick: function () { add(c, b); } }, [swatch(v, g), h('strong', v.name), h('span', money(c.price)), h('small', 'Stock ' + c.stock)]);
        return b;
      })));
    } else if (groups.length === 2) {
      // Cuadrícula filas x columnas. Filas = grupo con más valores.
      var a = groups[0];
      var b2 = groups[1];
      if (b2.values.length > a.values.length) { var t = a; a = b2; b2 = t; }
      var table = h('table.jtp-matrix', [
        h('thead', h('tr', [h('th', a.name + ' \\ ' + b2.name)].concat(b2.values.map(function (v) { return h('th', [swatch(v, b2), v.name]); })))),
        h('tbody', a.values.map(function (va) {
          return h('tr', [h('th', [swatch(va, a), va.name])].concat(b2.values.map(function (vb) {
            var sel = {};
            sel[a.id] = va.id;
            sel[b2.id] = vb.id;
            var c = comboFor(sel)[0];
            if (!c) { return h('td.none', '—'); }
            var cell = h('td', h('button.jtp-cell' + (c.stock <= 0 ? '.zero' : ''), { type: 'button', title: describe(c) + ' · ' + money(c.price), onclick: function () { add(c, cell.firstChild); } }, cellLabel(c)));
            return cell;
          })));
        }))
      ]);
      body.appendChild(h('div.jtp-muted', 'Pulsa una celda para añadir. El número es el stock.'));
      body.appendChild(h('div.jtp-matrix-wrap', table));
    } else {
      // 3 o más grupos: selección encadenada
      var sel = {};
      var wrap = h('div');
      var addBtn = h('button.jtp-btn.primary.lg', { type: 'button', disabled: true }, 'Añadir');
      var render = function () {
        wrap.innerHTML = '';
        groups.forEach(function (g2, idx) {
          var prev = {};
          groups.slice(0, idx).forEach(function (pg) { if (sel[pg.id]) { prev[pg.id] = sel[pg.id]; } });
          wrap.appendChild(h('div.jtp-var-title', g2.name));
          wrap.appendChild(h('div.jtp-var-buttons', g2.values.map(function (v) {
            var test = Object.assign({}, prev, obj(g2.id, v.id));
            var available = comboFor(test);
            var stock = available.reduce(function (s, c) { return s + c.stock; }, 0);
            return h('button.jtp-var-btn' + (String(sel[g2.id]) === String(v.id) ? '.active' : '') + (available.length ? '' : '.disabled'), {
              type: 'button',
              disabled: !available.length,
              onclick: function () {
                sel[g2.id] = v.id;
                groups.slice(idx + 1).forEach(function (ng) {
                  if (sel[ng.id] && !comboFor(Object.assign({}, sel)).length) { delete sel[ng.id]; }
                });
                render();
              }
            }, [swatch(v, g2), h('strong', v.name), h('small', 'Stock ' + stock)]);
          })));
        });
        var full = groups.every(function (g3) { return sel[g3.id]; });
        var c = full ? comboFor(sel)[0] : null;
        info.innerHTML = '';
        if (c) {
          info.appendChild(h('div', [h('strong', describe(c)), ' · ', money(c.price), ' · Stock ' + c.stock + (c.reference ? ' · Ref. ' + c.reference : '')]));
        }
        addBtn.disabled = !c;
        addBtn.onclick = function () { if (c) { add(c, addBtn); } };
      };
      render();
      body.appendChild(wrap);
      body.appendChild(info);
      body.appendChild(h('div.jtp-row-end', [h('label.jtp-label', 'Cantidad'), qtyInput, addBtn]));
    }
    if (groups.length <= 2) {
      body.appendChild(h('div.jtp-row-end', [h('label.jtp-label', 'Cantidad por pulsación'), qtyInput]));
    }
    var m = UI.modal({
      title: p.name,
      size: groups.length === 2 ? 'xl' : 'lg',
      body: h('div', [p.image ? h('img.jtp-var-img', { src: p.image, alt: '', onerror: function (e) { e.target.remove(); } }) : null, body]),
      footer: [h('button.jtp-btn.primary', { type: 'button', onclick: function () { m.close(); } }, 'Hecho')]
    });
    // Escanear una variante con el modal abierto la añade igualmente
    m.onScan = function (code) { m.close(); doScan(code); };
  }

  function obj(k, v) { var o = {}; o[k] = v; return o; }

  // ------------------------------------------------------------------ ticket (carrito)

  function setCart(cart, highlight) {
    S.cart = cart;
    renderCart(highlight);
    JTP.emit('cart:updated', cart);
  }
  JTP.setCart = setCart;

  function renderCart(highlight) {
    var c = S.cart;
    if (!c || !els.lines) { return; }
    // Cliente
    els.customer.innerHTML = '';
    var cust = c.customer || {};
    els.customer.appendChild(h('button.jtp-cust-btn', { type: 'button', onclick: openCustomer }, [
      icon('user'),
      h('span.jtp-cust-name', cust.is_default ? 'Cliente de mostrador' : cust.name),
      !cust.is_default && cust.nif ? h('span.jtp-cust-nif', cust.nif) : null,
      h('span.jtp-cust-change', cust.is_default ? 'Asignar cliente' : 'Cambiar')
    ]));
    // Líneas
    els.lines.innerHTML = '';
    if (!c.lines.length) {
      els.lines.appendChild(h('div.jtp-empty', [h('div.jtp-empty-ico', '🛒'), h('div', 'Escanea o busca productos para empezar')]));
    }
    c.lines.forEach(function (l) {
      var disc = l.original_price > l.unit_price + 0.004;
      var row = h('div.jtp-line' + (highlight === l.key ? '.flash' : ''), { role: 'button', tabindex: 0, onclick: function () { openLine(l); } }, [
        h('div.jtp-line-main', [
          h('div.jtp-line-name', l.name),
          l.attributes ? h('div.jtp-line-attr', l.attributes) : null,
          (l.notes && l.notes.length) ? h('div.jtp-line-attr.jtp-line-notes', l.notes.join(' · ')) : null,
          h('div.jtp-line-price', [
            h('span', l.quantity + ' × ' + money(l.unit_price)),
            disc ? h('s', money(l.original_price)) : null,
            l.mode === 'percent' ? h('span.jtp-tag', '-' + l.value + '%') : null,
            l.mode === 'price' ? h('span.jtp-tag', 'precio manual') : null,
            l.mode === 'amount' ? h('span.jtp-tag', '-' + money(l.value)) : null
          ])
        ]),
        h('div.jtp-line-qty', [
          h('button.jtp-qbtn', { type: 'button', onclick: function (e) { e.stopPropagation(); changeQty(l, l.quantity - 1); } }, '−'),
          h('span', String(l.quantity)),
          h('button.jtp-qbtn', { type: 'button', onclick: function (e) { e.stopPropagation(); changeQty(l, l.quantity + 1); } }, '+')
        ]),
        h('div.jtp-line-total', money(l.total))
      ]);
      els.lines.appendChild(row);
      if (highlight === l.key) { setTimeout(function () { row.scrollIntoView({ block: 'nearest' }); }, 10); }
    });
    // Totales
    var t = c.totals;
    els.totals.innerHTML = '';
    if (c.discounts.length || t.discounts) {
      els.totals.appendChild(h('div.jtp-trow', [h('span', 'Subtotal'), h('span', money(t.products))]));
      c.discounts.forEach(function (d) {
        els.totals.appendChild(h('div.jtp-trow.disc', [
          h('span', [d.name, ' ', h('button.jtp-link', { type: 'button', title: 'Quitar', onclick: function () {
            JTP.api('cart.voucherRemove', { id_cart_rule: d.id_cart_rule }).then(function (cart) { setCart(cart); }).catch(UI.error);
          } }, '✕')]),
          h('span', '-' + money(d.value))
        ]));
      });
    }
    els.totals.appendChild(h('div.jtp-trow.small', [h('span', t.items + ' artículos · IVA incl.'), h('span', 'Base ' + money(t.total_tax_excl) + ' · IVA ' + money(t.tax))]));
    if (c.note) { els.totals.appendChild(h('div.jtp-note', '📝 ' + c.note)); }
    // Acciones
    els.actions.innerHTML = '';
    var acts = [
      JTP.can('discount') ? ['Descuento', openDiscount] : null,
      ['Vale', openVoucher],
      ['Nota', function () {
        UI.prompt('Nota del ticket', c.note, { multiline: true }).then(function (v) {
          if (v === null) { return; }
          JTP.api('cart.note', { note: v }).then(function (cart) { setCart(cart); }).catch(UI.error);
        });
      }],
      ['Aparcar', parkCart],
      ['Vaciar', function () {
        if (!c.lines.length) { return; }
        UI.confirm('¿Vaciar el ticket actual?', 'Vaciar').then(function (ok) {
          if (ok) { JTP.api('cart.clear').then(function (cart) { setCart(cart); }).catch(UI.error); }
        });
      }]
    ].filter(Boolean);
    acts.forEach(function (a) { els.actions.appendChild(h('button.jtp-act', { type: 'button', onclick: a[1] }, a[0])); });
    JTP.ext.actions.forEach(function (a) {
      if (a.visible && !a.visible(JTP)) { return; }
      els.actions.appendChild(h('button.jtp-act.addon', { type: 'button', onclick: function () { a.onClick(JTP); } }, a.label));
    });
    // Botón cobrar
    els.pay.innerHTML = '';
    els.pay.appendChild(h('span', 'COBRAR'));
    els.pay.appendChild(h('strong', money(t.total)));
    els.pay.disabled = !c.lines.length;
  }

  function changeQty(l, qty) {
    JTP.api('cart.qty', { id_product: l.id_product, id_product_attribute: l.id_product_attribute, qty: qty }).then(function (cart) { setCart(cart, l.key); }).catch(UI.error);
  }

  function openLine(l) {
    var qtyIn = h('input.jtp-input.big', { type: 'text', inputmode: 'decimal', value: String(l.quantity) });
    var mode = l.mode || 'percent';
    var valIn = h('input.jtp-input.big', { type: 'text', inputmode: 'decimal', value: l.mode ? String(l.value).replace('.', ',') : '' });
    var active = qtyIn;
    [qtyIn, valIn].forEach(function (i) { i.addEventListener('focus', function () { active = i; }); });
    var modes = h('div.jtp-seg', [
      JTP.can('discount') ? ['percent', 'Dto. %'] : null,
      JTP.can('discount') ? ['amount', 'Dto. € / ud.'] : null,
      JTP.can('price') ? ['price', 'Precio final / ud.'] : null
    ].filter(Boolean).map(function (x) {
      return h('button.jtp-seg-btn' + (mode === x[0] ? '.active' : ''), { type: 'button', onclick: function (e) {
        mode = x[0];
        Array.prototype.forEach.call(modes.children, function (b) { b.classList.remove('active'); });
        e.target.classList.add('active');
        valIn.focus();
      } }, x[1]);
    }));
    var proxy = h('input', { type: 'hidden' });
    var kp = UI.keypad(proxy);
    proxy.addEventListener('input', function () {});
    // El teclado escribe en el campo activo
    Array.prototype.forEach.call(kp.querySelectorAll('.jtp-key'), function (b) {
      b.onclick = function () {
        var k = b.textContent;
        var v = active.value;
        if (k === '⌫') { active.value = v.slice(0, -1); } else if (k === ',') { if (v.indexOf(',') === -1) { active.value = (v || '0') + ','; } } else { active.value = v + k; }
        active.focus();
      };
    });
    var save = function (btn) {
      var qty = Math.round(JTP.num(qtyIn.value));
      var chain = Promise.resolve();
      var canEditPrice = JTP.can('discount') || JTP.can('price');
      var newVal = valIn.value.trim();
      chain = chain.then(function () {
        if (qty !== l.quantity) {
          return JTP.api('cart.qty', { id_product: l.id_product, id_product_attribute: l.id_product_attribute, qty: qty }).then(function (c) { setCart(c, l.key); });
        }
      });
      if (canEditPrice && qty > 0) {
        chain = chain.then(function () {
          if (newVal === '' && !l.mode) { return; }
          var payload = newVal === '' ? { mode: '', value: 0 } : { mode: mode, value: JTP.num(newVal) };
          if (payload.mode === l.mode && payload.value === l.value) { return; }
          return JTP.api('cart.line', Object.assign({ id_product: l.id_product, id_product_attribute: l.id_product_attribute }, payload)).then(function (c) { setCart(c, l.key); });
        });
      }
      busy(btn, chain).then(function () { m.close(); }).catch(UI.error);
    };
    var m = UI.modal({
      title: l.name + (l.attributes ? ' · ' + l.attributes : ''),
      size: 'md',
      body: h('div.jtp-line-edit', [
        h('div.jtp-line-edit-info', [
          h('div', ['PVP: ', h('strong', money(l.original_price)), ' · Precio actual: ', h('strong', money(l.unit_price))]),
          h('div.jtp-muted', 'Stock disponible: ' + l.stock + (l.reference ? ' · Ref. ' + l.reference : ''))
        ]),
        h('div.jtp-two', [
          h('div', [h('label.jtp-label', 'Cantidad'), qtyIn]),
          (JTP.can('discount') || JTP.can('price')) ? h('div', [h('label.jtp-label', 'Descuento / precio'), modes, valIn, h('div.jtp-muted.small', 'Vacío = precio normal')]) : null
        ]),
        kp
      ]),
      footer: [
        h('button.jtp-btn.danger', { type: 'button', onclick: function () {
          JTP.api('cart.remove', { id_product: l.id_product, id_product_attribute: l.id_product_attribute }).then(function (c) { setCart(c); m.close(); }).catch(UI.error);
        } }, 'Eliminar línea'),
        h('span.jtp-flex'),
        h('button.jtp-btn', { type: 'button', onclick: function () { m.close(); } }, 'Cancelar'),
        h('button.jtp-btn.primary', { type: 'button', onclick: function (e) { save(e.currentTarget); } }, 'Aplicar')
      ]
    });
    [qtyIn, valIn].forEach(function (i) { i.addEventListener('keydown', function (e) { if (e.key === 'Enter') { save(null); } }); });
  }

  function openDiscount() {
    var md = S.cart.manual_discount || {};
    var type = md.type || 'percent';
    var input = h('input.jtp-input.big', { type: 'text', inputmode: 'decimal', value: md.value ? String(md.value).replace('.', ',') : '' });
    var seg = h('div.jtp-seg', [['percent', '%'], ['amount', '€']].map(function (x) {
      return h('button.jtp-seg-btn' + (type === x[0] ? '.active' : ''), { type: 'button', onclick: function (e) {
        type = x[0];
        Array.prototype.forEach.call(seg.children, function (b) { b.classList.remove('active'); });
        e.target.classList.add('active');
        input.focus();
      } }, x[1]);
    }));
    var apply = function (v) {
      JTP.api('cart.discount', { type: type, value: v }).then(function (c) { setCart(c); m.close(); }).catch(UI.error);
    };
    var m = UI.modal({
      title: 'Descuento en el ticket',
      size: 'sm',
      body: h('div', [
        seg,
        input,
        h('div.jtp-quick', [5, 10, 15, 20, 25, 50].map(function (p) {
          return h('button.jtp-btn', { type: 'button', onclick: function () { type = 'percent'; apply(p); } }, p + '%');
        })),
        UI.keypad(input, function () { apply(JTP.num(input.value)); })
      ]),
      footer: [
        md.type ? h('button.jtp-btn.danger', { type: 'button', onclick: function () { apply(0); } }, 'Quitar descuento') : null,
        h('span.jtp-flex'),
        h('button.jtp-btn.primary', { type: 'button', onclick: function () { apply(JTP.num(input.value)); } }, 'Aplicar')
      ]
    });
    input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { apply(JTP.num(input.value)); } });
  }

  function openVoucher() {
    UI.prompt('Aplicar vale o código', '', { placeholder: 'Escanea o escribe el código', ok: 'Aplicar' }).then(function (code) {
      if (!code) { return; }
      JTP.api('cart.voucher', { code: code.trim() }).then(function (c) { setCart(c); UI.toast('Vale aplicado', 'ok'); }).catch(UI.error);
    });
  }

  function parkCart() {
    if (!S.cart.lines.length) { return UI.toast('El ticket está vacío'); }
    UI.prompt('Aparcar ticket', S.cart.customer && !S.cart.customer.is_default ? S.cart.customer.name : '', { label: 'Nombre para identificarlo', ok: 'Aparcar' }).then(function (label) {
      if (label === null) { return; }
      JTP.api('cart.park', { label: label }).then(function (c) {
        S.parkedCount = (S.parkedCount || 0) + 1;
        renderParked();
        setCart(c);
        UI.toast('Ticket aparcado', 'ok');
      }).catch(UI.error);
    });
  }

  function openParked() {
    var m = UI.modal({ title: 'Tickets aparcados', size: 'lg', body: h('div.jtp-muted', 'Cargando…') });
    var load = function () {
      JTP.api('parked.list').then(function (list) {
        S.parkedCount = list.length;
        renderParked();
        if (!list.length) { return m.setBody(h('div.jtp-muted.pad', 'No hay tickets aparcados')); }
        m.setBody(h('div.jtp-list', list.map(function (p) {
          return h('div.jtp-list-row', [
            h('div.jtp-grow', [h('strong', p.label || 'Sin nombre'), h('div.jtp-muted', P2(p.date) + ' · ' + p.employee + ' · ' + p.items + ' art.' + (p.customer ? ' · ' + p.customer : ''))]),
            h('strong', money(p.total)),
            h('button.jtp-btn', { type: 'button', onclick: function () {
              UI.confirm('¿Eliminar el ticket aparcado "' + (p.label || '') + '"?', 'Eliminar').then(function (ok) {
                if (ok) { JTP.api('parked.delete', { id_cart: p.id_cart }).then(load).catch(UI.error); }
              });
            } }, 'Eliminar'),
            h('button.jtp-btn.primary', { type: 'button', onclick: function () {
              JTP.api('parked.resume', { id_cart: p.id_cart }).then(function (c) {
                setCart(c);
                m.close();
                JTP.api('parked.list').then(function (l2) { S.parkedCount = l2.length; renderParked(); });
              }).catch(UI.error);
            } }, 'Recuperar')
          ]);
        })));
      }).catch(UI.error);
    };
    load();
  }

  function P2(d) { return JTP.print.fmtDate(d); }

  // ------------------------------------------------------------------ clientes

  function openCustomer() {
    var input = h('input.jtp-input', { type: 'search', placeholder: 'Nombre, NIF, email o teléfono', autocomplete: 'off' });
    var list = h('div.jtp-list');
    var timer;
    input.addEventListener('input', function () {
      clearTimeout(timer);
      timer = setTimeout(function () {
        var q = input.value.trim();
        if (q.length < 2) { list.innerHTML = ''; return; }
        JTP.api('customer.search', { q: q }).then(function (rows) {
          list.innerHTML = '';
          if (!rows.length) { list.appendChild(h('div.jtp-muted.pad', 'Sin resultados. Puedes crear el cliente.')); }
          rows.forEach(function (c) {
            list.appendChild(h('button.jtp-list-row.btn', { type: 'button', onclick: function () { choose(c.id_customer); } }, [
              h('div.jtp-grow', [h('strong', c.name + (c.company ? ' · ' + c.company : '')), h('div.jtp-muted', [c.nif, c.email, c.phone, c.address_count > 1 ? c.address_count + ' direcciones' : ''].filter(Boolean).join(' · '))])
            ]));
          });
        }).catch(UI.error);
      }, 250);
    });
    var choose = function (id) {
      JTP.api('cart.customer', { id_customer: id }).then(function (c) {
        setCart(c);
        JTP.emit('customer:changed', c.customer);
        m.close();
        // Con varias direcciones de facturación, se elige cuál
        if (c.customer && c.customer.address_count > 1) { openAddresses(c.customer.id_customer); }
      }).catch(UI.error);
    };
    var cur = S.cart.customer;
    var m = UI.modal({
      title: 'Cliente',
      size: 'lg',
      body: h('div', [
        cur && !cur.is_default ? h('div.jtp-cust-card', [
          h('strong', cur.name), h('div', [cur.nif, cur.email, cur.phone].filter(Boolean).join(' · ')),
          cur.address ? h('div.jtp-muted', [cur.address.company && cur.address.company !== cur.company ? cur.address.company : '', cur.address.address1, cur.address.postcode, cur.address.city, cur.address.state].filter(Boolean).join(', ')) : null,
          cur.address_count > 1 ? h('div.jtp-muted.small', 'Tiene ' + cur.address_count + ' direcciones de facturación: "Direcciones" para elegir otra.') : null,
          cur.billing_missing && cur.billing_missing.length ? h('div.jtp-muted.small', 'Para factura completa falta: ' + cur.billing_missing.join(', ')) : null
        ]) : null,
        input,
        list
      ]),
      footer: [
        cur && !cur.is_default ? h('button.jtp-btn', { type: 'button', onclick: function () { choose(0); } }, 'Quitar cliente') : null,
        cur && !cur.is_default ? h('button.jtp-btn', { type: 'button', onclick: function () { m.close(); openAddresses(cur.id_customer); } }, 'Direcciones' + (cur.address_count > 1 ? ' (' + cur.address_count + ')' : '')) : null,
        cur && !cur.is_default ? h('button.jtp-btn', { type: 'button', onclick: function () { m.close(); editCustomer(cur.id_customer).catch(UI.error); } }, 'Editar datos') : null,
        h('span.jtp-flex'),
        h('button.jtp-btn.primary', { type: 'button', onclick: function () { m.close(); openNewCustomer(input.value); } }, '+ Nuevo cliente')
      ]
    });
  }

  function openNewCustomer(seed) {
    return openCustomerForm(null, seed);
  }

  /**
   * Alta o modificación de un cliente con su dirección de facturación.
   * cust: datos de customer.get (modificar) o null (alta). Devuelve una promesa que se resuelve al guardar.
   */
  function openCustomerForm(cust, seed, opt) {
    opt = opt || {};
    return new Promise(function (resolve) {
      var f = {};
      var field = function (name, label, attrs) {
        f[name] = h('input.jtp-input', Object.assign({ type: 'text', name: name }, attrs || {}));
        return h('div.jtp-field', [h('label.jtp-label', label), f[name]]);
      };
      // Nueva dirección: solo los datos del cliente; los de facturación, en blanco
      var addr = cust && cust.address && !opt.newAddress ? cust.address : null;
      var looksNif = /^[0-9XYZKLMxyzklm][0-9]{6,8}[A-Za-z]?$|^[A-Za-z][0-9]{7}[A-Za-z0-9]$/.test((seed || '').trim());
      var countryId = String(addr ? addr.id_country : S.defaultCountry);
      var countrySel = h('select.jtp-input', (S.countries || []).map(function (c) {
        return h('option', { value: c.id, selected: String(c.id) === countryId ? 'selected' : null }, c.name);
      }));
      var stateSel = h('select.jtp-input', { name: 'id_state' });
      var stateBox = h('div.jtp-field', [h('label.jtp-label', 'Provincia *'), stateSel]);
      var fillStates = function (keep) {
        var c = (S.countries || []).filter(function (x) { return String(x.id) === String(countrySel.value); })[0];
        var states = c && c.states ? c.states.slice() : [];
        states.sort(function (x, y) { return x.name.localeCompare(y.name, 'es'); });
        stateSel.innerHTML = '';
        stateSel.appendChild(h('option', { value: '' }, 'Elige provincia…'));
        states.forEach(function (st) {
          stateSel.appendChild(h('option', { value: st.id, selected: String(st.id) === String(keep) ? 'selected' : null }, st.name));
        });
        stateBox.style.display = states.length ? '' : 'none';
      };
      // Teléfonos según el formato de dirección del país (fijo, móvil o los dos) y si son obligatorios
      var fillPhones = function () {
        var c = (S.countries || []).filter(function (x) { return String(x.id) === String(countrySel.value); })[0];
        var pf = c && c.phones ? c.phones : { fields: ['phone', 'phone_mobile'], required: [] };
        [['phone', 'Teléfono fijo'], ['phone_mobile', 'Móvil']].forEach(function (x) {
          var box = f[x[0]].parentNode;
          box.style.display = pf.fields.indexOf(x[0]) === -1 ? 'none' : '';
          box.querySelector('label').textContent = x[1] + (pf.required.indexOf(x[0]) !== -1 ? ' *' : '');
        });
      };
      countrySel.addEventListener('change', function () { fillStates(''); fillPhones(); });
      var missing = cust && cust.billing_missing && cust.billing_missing.length
        ? h('div.jtp-warn', 'Para factura completa falta: ' + cust.billing_missing.join(', ') + '.') : null;
      var form = h('form.jtp-form', [
        missing,
        h('div.jtp-two', [field('firstname', 'Nombre *'), field('lastname', 'Apellidos')]),
        h('div.jtp-two', [field('company', 'Empresa'), field('nif', 'NIF / CIF')]),
        h('div.jtp-two', [field('email', 'Email', { type: 'email' }), field('phone_mobile', 'Móvil', { type: 'tel' })]),
        h('div.jtp-two', [field('phone', 'Teléfono fijo', { type: 'tel' }), h('div')]),
        h('div.jtp-sub', opt.newAddress ? 'Nueva dirección de facturación' : 'Dirección de facturación (opcional, necesaria para factura completa)'),
        field('address1', 'Dirección'),
        h('div.jtp-two', [field('postcode', 'CP'), field('city', 'Ciudad')]),
        h('div.jtp-two', [h('div.jtp-field', [h('label.jtp-label', 'País'), countrySel]), stateBox])
      ]);
      fillStates(addr ? addr.id_state : '');
      fillPhones();
      if (cust) {
        f.firstname.value = cust.firstname || '';
        f.lastname.value = cust.lastname || '';
        f.company.value = opt.newAddress ? '' : (addr && addr.company !== undefined ? addr.company : (cust.company || ''));
        f.nif.value = opt.newAddress ? '' : (cust.nif || '');
        f.email.value = cust.email || '';
        f.phone.value = opt.newAddress ? '' : (cust.phone_fixed !== undefined ? cust.phone_fixed : '');
        f.phone_mobile.value = opt.newAddress ? '' : (cust.phone_mobile || '');
        if (addr) {
          f.address1.value = [addr.address1, addr.address2].filter(Boolean).join(' ');
          f.postcode.value = addr.postcode || '';
          f.city.value = addr.city || '';
        }
      } else if (seed) {
        if (/^\+?[0-9 ]{9,15}$/.test(seed.trim())) { (f.phone_mobile.parentNode.style.display === 'none' ? f.phone : f.phone_mobile).value = seed.trim(); } else if (looksNif) { f.nif.value = seed.trim().toUpperCase(); } else if (seed.indexOf('@') !== -1) { f.email.value = seed.trim(); } else { f.firstname.value = seed.trim(); }
      }
      var submit = function (btn) {
        var data = {};
        Object.keys(f).forEach(function (k) { data[k] = f[k].value.trim(); });
        data.id_country = countrySel.value;
        data.id_state = stateBox.style.display === 'none' ? '' : stateSel.value;
        var hasAddr = data.address1 || data.postcode || data.city;
        if (hasAddr && stateBox.style.display !== 'none' && !data.id_state) {
          UI.toast('Elige la provincia', 'error');
          stateSel.focus();
          return;
        }
        if (cust) {
          data.id_customer = cust.id_customer;
          data.id_address = opt.newAddress ? 'new' : (cust.id_address || '');
          if (opt.newAddress && !hasAddr) { UI.toast('Rellena dirección, CP y ciudad', 'error'); f.address1.focus(); return; }
        }
        busy(btn, JTP.api(cust ? 'customer.update' : 'customer.create', data)).then(function (c) {
          setCart(c);
          JTP.emit('customer:changed', c.customer);
          resolve(c);
          m.close();
          UI.toast(cust ? 'Cliente actualizado' : 'Cliente asignado', 'ok');
        }).catch(UI.error);
      };
      form.addEventListener('submit', function (e) { e.preventDefault(); submit(null); });
      var m = UI.modal({
        title: cust ? (opt.newAddress ? 'Nueva dirección de facturación' : 'Datos del cliente' + (addr && addr.alias ? ' · ' + addr.alias : '')) : 'Nuevo cliente',
        size: 'lg',
        body: form,
        onClose: function () { resolve(null); },
        footer: [
          h('button.jtp-btn', { type: 'button', onclick: function () { m.close(); } }, 'Cancelar'),
          h('button.jtp-btn.primary', { type: 'button', onclick: function (e) { submit(e.currentTarget); } }, cust ? 'Guardar' : 'Crear y asignar')
        ]
      });
    });
  }

  /** Editar el cliente del ticket (o el indicado) y una de sus direcciones (por defecto la del ticket) */
  function editCustomer(idCustomer, idAddress) {
    var cc = S.cart && S.cart.customer ? S.cart.customer : null;
    var id = idCustomer || (cc ? cc.id_customer : 0);
    if (!id || (cc && cc.is_default && !idCustomer)) { return Promise.resolve(null); }
    if (!idAddress && cc && String(cc.id_customer) === String(id)) { idAddress = cc.id_address; }
    return JTP.api('customer.get', { id_customer: id, id_address: idAddress || 0 }).then(function (c) { return openCustomerForm(c); });
  }

  /**
   * Direcciones de facturación del cliente: elegir la del ticket, corregirla o añadir otra.
   */
  function openAddresses(idCustomer) {
    var m = UI.modal({ title: 'Direcciones de facturación', size: 'lg', body: h('div.jtp-muted.pad', 'Cargando…') });
    var load = function () {
      JTP.api('customer.addresses', { id_customer: idCustomer }).then(function (rows) {
        var list = h('div.jtp-list');
        if (!rows.length) { list.appendChild(h('div.jtp-muted.pad', 'Sin direcciones')); }
        rows.forEach(function (a) {
          var who = [a.company, a.name].filter(Boolean).join(' · ');
          list.appendChild(h('div.jtp-list-row' + (a.selected ? '.jtp-addr-sel' : ''), [
            h('div.jtp-grow', [
              h('strong', (a.is_shop ? 'Sin dirección propia (datos de la tienda)' : a.alias) + (a.selected ? ' · en el ticket' : '')),
              who ? h('div', who + (a.nif ? ' · ' + a.nif : '')) : (a.nif ? h('div', a.nif) : null),
              a.is_shop ? null : h('div.jtp-muted', [a.address1, a.postcode + ' ' + a.city, a.state, a.country].filter(function (x) { return x && x.trim(); }).join(', ')),
              a.missing.length ? h('div.jtp-muted.small', 'Para factura completa falta: ' + a.missing.join(', ')) : null
            ]),
            h('button.jtp-btn', { type: 'button', onclick: function () {
              m.close();
              JTP.api('customer.get', { id_customer: idCustomer, id_address: a.id_address }).then(function (c) { return openCustomerForm(c); }).catch(UI.error);
            } }, a.is_shop ? 'Poner dirección' : 'Corregir'),
            a.selected ? h('span.jtp-tag', 'En uso') : h('button.jtp-btn.primary', { type: 'button', onclick: function (e) {
              busy(e.currentTarget, JTP.api('cart.address', { id_address: a.id_address })).then(function (c) {
                setCart(c);
                JTP.emit('customer:changed', c.customer);
                m.close();
                UI.toast('Facturación: ' + (who || a.alias), 'ok');
              }).catch(UI.error);
            } }, 'Usar esta')
          ]));
        });
        m.setBody(list);
        m.setFooter([
          h('button.jtp-btn', { type: 'button', onclick: function () { m.close(); } }, 'Cerrar'),
          h('span.jtp-flex'),
          h('button.jtp-btn.primary', { type: 'button', onclick: function () {
            m.close();
            JTP.api('customer.get', { id_customer: idCustomer }).then(function (c) { return openCustomerForm(c, null, { newAddress: true }); }).catch(UI.error);
          } }, '+ Nueva dirección')
        ]);
      }).catch(UI.error);
    };
    load();
  }

  // ------------------------------------------------------------------ cobro

  function openPayment() {
    var c = S.cart;
    if (!c || !c.lines.length) { return; }
    if (!S.session && S.requireSession) {
      UI.toast('Abre la caja antes de cobrar', 'error');
      return openCash();
    }
    var total = c.totals.total;
    var payments = [];
    var methods = S.paymentMethods;
    var current = methods[0];
    var amountIn = h('input.jtp-input.huge', { type: 'text', inputmode: 'decimal' });
    var summary = h('div.jtp-pay-summary');
    var list = h('div.jtp-pay-lines');
    // Opciones de los addons (p. ej. "Factura completa") y datos extra que viajan con el cobro
    var options = h('div.jtp-pay-options');
    var extra = {};
    var finish = h('button.jtp-btn.primary.lg.jtp-finish', { type: 'button', onclick: function () { doCheckout(finish); } }, 'Finalizar venta');
    var methodBtns = h('div.jtp-methods', methods.map(function (pm) {
      return h('button.jtp-method' + (pm === current ? '.active' : ''), { type: 'button', onclick: function (e) {
        current = pm;
        Array.prototype.forEach.call(methodBtns.children, function (b) { b.classList.remove('active'); });
        e.currentTarget.classList.add('active');
        prefill(remaining());
        amountIn.focus();
        amountIn.select();
      } }, pm.name);
    }));

    function paid() { return JTP.round(payments.reduce(function (s, p) { return s + p.amount; }, 0)); }
    function remaining() { return Math.max(0, JTP.round(total - paid())); }
    function fmtIn(v) { return v ? v.toFixed(2).replace('.', ',') : ''; }
    // Importe propuesto (seleccionado): la primera pulsación de billete o tecla lo sustituye
    function prefill(v) { amountIn.value = fmtIn(v); amountIn.dataset.fresh = '1'; }

    function refresh() {
      var p = paid();
      var rem = JTP.round(total - p);
      summary.innerHTML = '';
      summary.appendChild(h('div.jtp-pay-total', [h('span', 'Total'), h('strong', money(total))]));
      summary.appendChild(h('div.jtp-pay-row', [h('span', 'Entregado'), h('span', money(p))]));
      if (rem > 0) {
        summary.appendChild(h('div.jtp-pay-row.rem', [h('span', 'Pendiente'), h('strong', money(rem))]));
      } else {
        summary.appendChild(h('div.jtp-pay-row.change', [h('span', 'Cambio'), h('strong', money(-rem))]));
      }
      list.innerHTML = '';
      payments.forEach(function (py, i) {
        list.appendChild(h('div.jtp-pay-line', [
          h('span', py.name + (py.reference ? ' · ' + py.reference : '')),
          h('strong', money(py.amount)),
          h('button.jtp-link', { type: 'button', onclick: function () { payments.splice(i, 1); refresh(); } }, '✕')
        ]));
      });
      finish.disabled = total > 0 && p + 0.004 < total;
    }

    function addPayment(amount) {
      amount = JTP.round(amount);
      if (amount <= 0) { return Promise.resolve(); }
      var rem = remaining();
      if (amount > rem && !current.allow_change) {
        UI.toast(current.name + ' no admite cambio: se ajusta al pendiente');
        amount = rem;
      }
      if (amount <= 0) { return Promise.resolve(); }
      var handler = JTP.ext.paymentHandlers[current.code];
      var pr = handler ? Promise.resolve(handler(amount, { method: current, cart: S.cart })) : Promise.resolve({ ok: true });
      return pr.then(function (r) {
        if (!r || r.ok === false) { throw new JTP.Error((r && r.error) || 'Cobro no completado', {}); }
        payments.push({ code: current.code, name: current.name, amount: amount, reference: (r && r.reference) || '' });
        prefill(remaining());
        refresh();
      });
    }

    function doCheckout(btn) {
      var go = function () {
        return busy(btn, JTP.api('checkout', { extra: extra, payments: payments.map(function (p) { return { code: p.code, amount: p.amount, reference: p.reference }; }) })).then(function (r) {
          m.close();
          afterSale(r);
        }).catch(function (e) {
          if (e.data && e.data.session_closed) { m.close(); openCash(); }
          UI.error(e);
        });
      };
      // Si no se ha añadido ninguna línea de pago, se toma el importe escrito con el método elegido
      if (!payments.length && total > 0) {
        var v = JTP.num(amountIn.value) || total;
        return addPayment(v).then(function () { if (paid() + 0.004 >= total) { return go(); } }).catch(UI.error);
      }
      return go();
    }

    var bills = h('div.jtp-quick', [
      h('button.jtp-btn', { type: 'button', onclick: function () { prefill(remaining()); } }, 'Exacto'),
      [5, 10, 20, 50, 100, 200].map(function (b) {
        // 1ª pulsación: el billete sustituye al importe propuesto; las siguientes suman (100 + 20 = 120)
        return h('button.jtp-btn', { type: 'button', title: 'Pulsa otra vez para sumar', onclick: function () {
          var base = amountIn.dataset.fresh === '1' ? 0 : JTP.num(amountIn.value);
          amountIn.value = fmtIn(JTP.round(base + b));
          amountIn.dataset.fresh = '';
        } }, b + ' €');
      })
    ].reduce(function (a, x) { return a.concat(x); }, []));

    var m = UI.modal({
      title: 'Cobrar',
      size: 'xl',
      sticky: true,
      body: h('div.jtp-paybox', [
        h('div.jtp-pay-left', [
          h('label.jtp-label', 'Método de pago'),
          methodBtns,
          h('label.jtp-label', 'Importe'),
          h('div.jtp-row-end', [amountIn, h('button.jtp-btn.primary', { type: 'button', onclick: function (e) {
            busy(e.currentTarget, addPayment(JTP.num(amountIn.value))).catch(UI.error);
          } }, 'Añadir pago')]),
          bills,
          UI.keypad(amountIn, function () { addPayment(JTP.num(amountIn.value)).catch(UI.error); })
        ]),
        h('div.jtp-pay-right', [summary, list, options, finish])
      ]),
      footer: [h('button.jtp-btn', { type: 'button', onclick: function () { m.close(); } }, 'Cancelar')]
    });
    prefill(total);
    amountIn.addEventListener('input', function () { amountIn.dataset.fresh = ''; });
    amountIn.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        var v = JTP.num(amountIn.value);
        if (!payments.length && v >= total - 0.004 && current.allow_change) {
          doCheckout(finish);
        } else if (v > 0) {
          addPayment(v).catch(UI.error);
        } else if (!finish.disabled) {
          doCheckout(finish);
        }
      }
    });
    refresh();
    JTP.emit('payment:modal', { modal: m, total: total, payments: payments, extra: extra, options: options, cart: c });
  }

  /** Botones de los addons para una venta (PDF, email…) */
  function saleButtons(ctx) {
    return (JTP.ext.saleButtons || []).filter(function (b) {
      try { return !b.visible || b.visible(ctx); } catch (e) { return false; }
    }).map(function (b) {
      return h('button.jtp-btn', { type: 'button', onclick: function (e) {
        var btn = e.currentTarget;
        var r = b.onClick(ctx, btn);
        if (r && r.then) { busy(btn, r).catch(UI.error); }
      } }, b.label);
    });
  }

  function afterSale(r) {
    setCart(r.cart);
    JTP.emit('sale:completed', r);
    var t = r.ticket;
    var pc = S.print;
    if (pc.auto) {
      // auto: impresión automática al cobrar (un addon puede sustituirla, p. ej. por el PDF en A4)
      JTP.print.print(t, { drawer: r.open_drawer, copies: pc.copies, auto: true }).catch(UI.error);
    } else if (r.open_drawer) {
      JTP.print.openDrawer().catch(function () {});
    }
    var cust = t.customer;
    var emailIn = h('input.jtp-input', { type: 'email', placeholder: 'email@cliente.com', value: '' });
    if (r.sale && r.sale.id_customer && S.cart) {
      // El email se rellena si el cliente lo tiene
      JTP.api('saleDetail', { id_sale: r.sale.id_sale }).then(function (d) {
        if (d.customer && d.customer.email) { emailIn.value = d.customer.email; }
      }).catch(function () {});
    }
    var m = UI.modal({
      title: 'Venta completada',
      size: 'md',
      body: h('div.jtp-done', [
        h('div.jtp-done-num', t.number),
        t.change > 0 ? h('div.jtp-done-change', ['Cambio: ', h('strong', money(t.change))]) : h('div.jtp-done-total', money(t.totals.total)),
        cust ? h('div.jtp-muted', cust.name + (cust.nif ? ' · ' + cust.nif : '')) : null,
        pc.email ? h('div.jtp-row-end', [emailIn, h('button.jtp-btn', { type: 'button', onclick: function (e) {
          busy(e.currentTarget, JTP.api('ticket.email', { id_sale: r.sale.id_sale, email: emailIn.value })).then(function () { UI.toast('Ticket enviado', 'ok'); }).catch(UI.error);
        } }, 'Enviar ticket')]) : null
      ]),
      footer: [
        h('button.jtp-btn', { type: 'button', onclick: function () { JTP.print.print(t, {}).catch(UI.error); } }, 'Imprimir ticket'),
        h('button.jtp-btn', { type: 'button', onclick: function () { JTP.print.print(t, { gift: true }).catch(UI.error); } }, 'Ticket regalo')
      ].concat(saleButtons({ id_sale: r.sale.id_sale, ticket: t, sale: r.sale })).concat([
        h('span.jtp-flex'),
        h('button.jtp-btn.primary.lg', { type: 'button', autofocus: true, onclick: function () { m.close(); } }, 'Nueva venta')
      ])
    });
    m.onScan = function (code) { m.close(); doScan(code); };
  }

  // ------------------------------------------------------------------ ventas y devoluciones

  function openSales() {
    // Buscar no exige fecha: con texto busca en todas las fechas; las fechas acotan (opcionales).
    // Sin texto ni fechas se muestran las ventas de hoy.
    var qIn = h('input.jtp-input', { type: 'search', placeholder: 'Nº ticket, pedido, cliente, NIF, email o teléfono' });
    var fromIn = h('input.jtp-input', { type: 'date' });
    var toIn = h('input.jtp-input', { type: 'date' });
    var hint = h('div.jtp-sales-hint');
    var list = h('div.jtp-list');
    var seq = 0;
    var day = function (d) { var x = String(d).split('-'); return x.length === 3 ? x[2] + '/' + x[1] + '/' + x[0] : d; };
    var load = function () {
      var my = ++seq;
      var q = qIn.value.trim();
      var from = fromIn.value;
      var to = toIn.value;
      hint.textContent = from || to
        ? (q ? 'Buscando "' + q + '" ' : 'Ventas ') + (from && to ? 'del ' + day(from) + ' al ' + day(to) : from ? 'desde el ' + day(from) : 'hasta el ' + day(to))
        : (q ? 'Buscando "' + q + '" en todas las fechas' : 'Ventas de hoy');
      list.innerHTML = '';
      list.appendChild(h('div.jtp-muted.pad', 'Cargando…'));
      JTP.api('sales', { q: q, date_from: from, date_to: to }).then(function (rows) {
        if (my !== seq) { return; }
        list.innerHTML = '';
        if (!rows.length) { list.appendChild(h('div.jtp-muted.pad', 'Sin ventas')); }
        var sum = 0;
        rows.forEach(function (s) {
          sum += s.total - s.returned_total;
          list.appendChild(h('button.jtp-list-row.btn', { type: 'button', onclick: function () { m.close(); openSaleDetail(s.id_sale); } }, [
            h('div.jtp-grow', [
              h('strong', s.ticket_number),
              h('div.jtp-muted', P2(s.date_add) + ' · ' + s.employee + (s.customer ? ' · ' + s.customer : '') + ' · ' + (s.payment || ''))
            ]),
            s.returned_total > 0 ? h('span.jtp-tag.warn', 'Devuelto ' + money(s.returned_total)) : null,
            h('strong', money(s.total))
          ]));
        });
        if (rows.length) { list.insertBefore(h('div.jtp-list-sum', rows.length + ' tickets · neto ' + money(sum)), list.firstChild); }
      }).catch(UI.error);
    };
    fromIn.addEventListener('change', load);
    toIn.addEventListener('change', load);
    var t;
    qIn.addEventListener('input', function () { clearTimeout(t); t = setTimeout(load, 300); });
    qIn.addEventListener('keydown', function (e) { if (e.key === 'Enter') { clearTimeout(t); load(); } });
    var clear = h('button.jtp-btn', { type: 'button', onclick: function () { qIn.value = ''; fromIn.value = ''; toIn.value = ''; load(); qIn.focus(); } }, 'Limpiar');
    var m = UI.modal({ title: 'Ventas', size: 'lg', body: h('div', [
      h('div.jtp-sales-filter', [
        h('label', ['Buscar', qIn]),
        h('label', ['Desde', fromIn]),
        h('label', ['Hasta', toIn]),
        clear
      ]),
      hint,
      list
    ]) });
    m.onScan = function (code) { qIn.value = code; load(); };
    load();
    setTimeout(function () { qIn.focus(); }, 50);
  }

  function openSaleDetail(idSale) {
    JTP.api('saleDetail', { id_sale: idSale }).then(function (d) {
      var s = d.sale;
      var canReturn = JTP.can('return') && d.lines.some(function (l) { return l.returnable > 0; });
      var m = UI.modal({
        title: 'Ticket ' + s.ticket_number,
        size: 'lg',
        body: h('div', [
          h('div.jtp-muted', P2(s.date_add) + (d.customer ? ' · ' + d.customer.name + (d.customer.nif ? ' (' + d.customer.nif + ')' : '') : '')),
          h('table.jtp-table', [
            h('thead', h('tr', [h('th', 'Artículo'), h('th.r', 'Ud.'), h('th.r', 'Devuelto'), h('th.r', 'Precio pagado')])),
            h('tbody', d.lines.map(function (l) {
              return h('tr', [h('td', l.name), h('td.r', String(l.quantity)), h('td.r', l.returned ? String(l.returned) : ''), h('td.r', money(l.unit_refund))]);
            }))
          ]),
          h('div.jtp-trow.big', [h('span', 'Total'), h('strong', money(s.total))]),
          h('div.jtp-muted', s.payments.map(function (p) { return p.name + ' ' + money(p.amount); }).join(' + ')),
          d.returns.length ? h('div.jtp-sub', 'Devoluciones') : null,
          d.returns.length ? h('div.jtp-list', d.returns.map(function (r) {
            return h('div.jtp-list-row', [
              h('div.jtp-grow', [h('strong', r.ticket_number), h('div.jtp-muted', P2(r.date_add) + ' · ' + r.refund_name)]),
              h('strong', '-' + money(r.total)),
              h('button.jtp-btn', { type: 'button', onclick: function () {
                JTP.api('ticket', { type: 'return', id: r.id_return }).then(function (t) { return JTP.print.print(t); }).catch(UI.error);
              } }, 'Reimprimir')
            ]);
          })) : null
        ]),
        footer: [
          h('button.jtp-btn', { type: 'button', onclick: function () {
            JTP.api('ticket', { type: 'sale', id: s.id_sale }).then(function (t) { return JTP.print.print(t); }).catch(UI.error);
          } }, 'Reimprimir'),
          h('button.jtp-btn', { type: 'button', onclick: function () {
            JTP.api('ticket', { type: 'sale', id: s.id_sale }).then(function (t) { return JTP.print.print(t, { gift: true }); }).catch(UI.error);
          } }, 'Ticket regalo')
        ].concat(saleButtons({ id_sale: s.id_sale, sale: s, customer: d.customer })).concat([
          h('span.jtp-flex'),
          canReturn ? h('button.jtp-btn.primary', { type: 'button', onclick: function () { m.close(); openReturn(d); } }, 'Devolver / cambiar') : null
        ])
      });
    }).catch(UI.error);
  }

  function openReturn(d) {
    if (!S.session && S.requireSession) {
      UI.toast('Abre la caja antes de devolver', 'error');
      return openCash();
    }
    var qty = {};
    var totalEl = h('strong');
    var rows = d.lines.filter(function (l) { return l.returnable > 0; });
    var refresh = function () {
      var t = rows.reduce(function (s, l) { return s + (qty[l.id_order_detail] || 0) * l.unit_refund; }, 0);
      totalEl.textContent = money(t);
    };
    var refundSel = h('select.jtp-input', S.paymentMethods.map(function (p) { return h('option', { value: p.code }, 'Reembolso: ' + p.name); }).concat([h('option', { value: 'voucher' }, 'Vale de compra (para cambios)')]));
    var reasonIn = h('input.jtp-input', { type: 'text', placeholder: 'Motivo (opcional)' });
    var restock = h('input', { type: 'checkbox', checked: 'checked' });
    var m = UI.modal({
      title: 'Devolución del ticket ' + d.sale.ticket_number,
      size: 'lg',
      body: h('div', [
        h('table.jtp-table', [
          h('thead', h('tr', [h('th', 'Artículo'), h('th.r', 'Precio'), h('th.r', 'Puede devolver'), h('th.r', 'Devolver')])),
          h('tbody', rows.map(function (l) {
            var n = h('span.jtp-qty-val', '0');
            var set = function (v) {
              v = Math.max(0, Math.min(l.returnable, v));
              qty[l.id_order_detail] = v;
              n.textContent = String(v);
              refresh();
            };
            return h('tr', [
              h('td', l.name), h('td.r', money(l.unit_refund)), h('td.r', String(l.returnable)),
              h('td.r', h('div.jtp-line-qty', [
                h('button.jtp-qbtn', { type: 'button', onclick: function () { set((qty[l.id_order_detail] || 0) - 1); } }, '−'),
                n,
                h('button.jtp-qbtn', { type: 'button', onclick: function () { set((qty[l.id_order_detail] || 0) + 1); } }, '+'),
                h('button.jtp-link', { type: 'button', onclick: function () { set(l.returnable); } }, 'Todo')
              ]))
            ]);
          }))
        ]),
        h('div.jtp-trow.big', [h('span', 'A devolver'), totalEl]),
        h('div.jtp-two', [refundSel, reasonIn]),
        h('label.jtp-check', [restock, ' Reponer stock']),
        h('p.jtp-muted.small', 'Para un cambio: devuelve con "Vale de compra" y aplica el vale en la nueva venta.')
      ]),
      footer: [
        h('button.jtp-btn', { type: 'button', onclick: function () { m.close(); } }, 'Cancelar'),
        h('button.jtp-btn.primary', { type: 'button', onclick: function (e) {
          var lines = Object.keys(qty).filter(function (k) { return qty[k] > 0; }).map(function (k) { return { id_order_detail: parseInt(k, 10), quantity: qty[k] }; });
          if (!lines.length) { return UI.toast('Indica qué unidades se devuelven'); }
          busy(e.currentTarget, JTP.api('return', { id_sale: d.sale.id_sale, lines: lines, refund: refundSel.value, reason: reasonIn.value, restock: restock.checked })).then(function (r) {
            m.close();
            JTP.emit('return:completed', r);
            JTP.print.print(r.ticket, { drawer: r.open_drawer }).catch(UI.error);
            UI.toast('Devolución ' + r.return.ticket_number + ' registrada', 'ok');
            if (r.ticket.voucher) {
              UI.modal({ title: 'Vale generado', size: 'sm', body: h('div.jtp-done', [h('div.jtp-done-num', r.ticket.voucher.code), h('div.jtp-done-total', money(r.ticket.voucher.amount))]) });
            }
          }).catch(UI.error);
        } }, 'Confirmar devolución')
      ]
    });
    refresh();
  }

  // ------------------------------------------------------------------ caja

  function openCash() {
    if (!S.session) {
      if (!JTP.can('close')) { return UI.toast('No tienes permiso para abrir la caja', 'error'); }
      var amt = h('input.jtp-input.huge', { type: 'text', inputmode: 'decimal', placeholder: '0,00' });
      var open = function (btn) {
        busy(btn, JTP.api('session.open', { amount: JTP.num(amt.value) })).then(function (r) {
          S.session = r.session;
          renderSession();
          m.close();
          UI.toast('Caja abierta', 'ok');
          JTP.emit('session:opened', r.session);
          JTP.print.openDrawer().catch(function () {});
        }).catch(UI.error);
      };
      // Contar el fondo es opcional: se puede escribir el total o desplegar los billetes y monedas
      var count = h('details.jtp-count', [h('summary', 'Contar por billetes y monedas (opcional)'), denomsGrid(amt)]);
      var m = UI.modal({
        title: 'Abrir caja · ' + S.register.name,
        size: 'md',
        body: h('div', [h('label.jtp-label', 'Fondo de caja (efectivo inicial)'), amt, count, UI.keypad(amt, function () { open(null); })]),
        footer: [h('button.jtp-btn.primary.lg', { type: 'button', onclick: function (e) { open(e.currentTarget); } }, 'Abrir caja')]
      });
      amt.addEventListener('keydown', function (e) { if (e.key === 'Enter') { open(null); } });
      return;
    }
    var m2 = UI.modal({ title: 'Caja · ' + S.register.name, size: 'lg', body: h('div.jtp-muted', 'Cargando…') });
    var load = function () {
      JTP.api('session.status').then(function (s) {
        if (!s) { S.session = null; renderSession(); m2.close(); return; }
        m2.setBody(summaryNode(s));
        m2.setFooter([
          JTP.can('cash_move') ? h('button.jtp-btn', { type: 'button', onclick: function () { cashMove('in', load); } }, 'Entrada') : null,
          JTP.can('cash_move') ? h('button.jtp-btn', { type: 'button', onclick: function () { cashMove('out', load); } }, 'Salida') : null,
          h('button.jtp-btn', { type: 'button', onclick: function () {
            JTP.api('ticket', { type: 'z', id: s.session.id_session }).then(function (t) { return JTP.print.print(t); }).catch(UI.error);
          } }, 'Imprimir X'),
          h('button.jtp-btn', { type: 'button', onclick: function () { JTP.print.openDrawer().then(function () { UI.toast('Orden de apertura enviada al cajón', 'ok'); }).catch(UI.error); } }, 'Abrir cajón'),
          h('span.jtp-flex'),
          JTP.can('close') ? h('button.jtp-btn.danger', { type: 'button', onclick: function () { m2.close(); closeCash(s); } }, 'Cerrar caja') : null
        ]);
      }).catch(UI.error);
    };
    load();
  }

  function summaryNode(s) {
    return h('div.jtp-summary', [
      h('div.jtp-muted', 'Abierta el ' + P2(s.session.date_open) + ' por ' + s.employee_open),
      h('div.jtp-kpis', [
        kpi('Ventas', money(s.sales_total), s.sales_count + ' tickets'),
        kpi('Devoluciones', money(s.returns_total), s.returns_count + ' tickets'),
        kpi('Efectivo esperado', money(s.cash.expected), 'en cajón')
      ]),
      h('div.jtp-two', [
        h('div', [h('div.jtp-sub', 'Por método de pago'), h('table.jtp-table', h('tbody', s.by_method.map(function (x) {
          return h('tr', [h('td', x.name + ' (' + x.n + ')'), h('td.r', money(x.amount))]);
        }).concat(s.returns_by_method.map(function (x) {
          return h('tr', [h('td', 'Devolución ' + x.name), h('td.r', '-' + money(x.amount))]);
        }))))]),
        h('div', [h('div.jtp-sub', 'Efectivo'), h('table.jtp-table', h('tbody', [
          ['Fondo inicial', s.cash.opening], ['Ventas', s.cash.sales], ['Devoluciones', s.cash.refunds], ['Entradas', s.cash.in], ['Salidas', s.cash.out]
        ].map(function (r) { return h('tr', [h('td', r[0]), h('td.r', money(r[1]))]); }).concat([h('tr.b', [h('td', 'Esperado'), h('td.r', money(s.cash.expected))])])))])
      ]),
      s.movements.length ? h('div', [h('div.jtp-sub', 'Entradas y salidas'), h('table.jtp-table', h('tbody', s.movements.map(function (mv) {
        return h('tr', [h('td', P2(mv.date_add)), h('td', mv.employee), h('td', mv.reason), h('td.r', money(mv.amount))]);
      })))]) : null
    ]);
  }

  function kpi(label, value, sub) {
    return h('div.jtp-kpi', [h('div.jtp-kpi-l', label), h('div.jtp-kpi-v', value), h('div.jtp-kpi-s', sub)]);
  }

  function cashMove(type, done) {
    var amt = h('input.jtp-input.big', { type: 'text', inputmode: 'decimal', placeholder: '0,00' });
    var reason = h('input.jtp-input', { type: 'text', placeholder: type === 'in' ? 'Ej. cambio del banco' : 'Ej. pago proveedor, ingreso banco' });
    var save = function (btn) {
      busy(btn, JTP.api('session.move', { type: type, amount: JTP.num(amt.value), reason: reason.value })).then(function (r) {
        m.close();
        UI.toast('Movimiento registrado', 'ok');
        JTP.print.print(r.ticket, { drawer: r.open_drawer }).catch(UI.error);
        if (done) { done(); }
      }).catch(UI.error);
    };
    var m = UI.modal({
      title: type === 'in' ? 'Entrada de efectivo' : 'Salida de efectivo',
      size: 'sm',
      body: h('div', [h('label.jtp-label', 'Importe'), amt, h('label.jtp-label', 'Motivo'), reason, UI.keypad(amt)]),
      footer: [h('button.jtp-btn', { type: 'button', onclick: function () { m.close(); } }, 'Cancelar'), h('button.jtp-btn.primary', { type: 'button', onclick: function (e) { save(e.currentTarget); } }, 'Registrar')]
    });
  }

  /**
   * Rejilla de billetes y monedas: al contar, escribe el total en "target".
   */
  function denomsGrid(target, onChange) {
    var denoms = [500, 200, 100, 50, 20, 10, 5, 2, 1, 0.5, 0.2, 0.1, 0.05, 0.02, 0.01];
    var counts = {};
    return h('div.jtp-denoms', denoms.map(function (dn) {
      var inp = h('input.jtp-input.sm', { type: 'number', min: 0, inputmode: 'numeric', placeholder: '0' });
      inp.addEventListener('input', function () {
        counts[dn] = Math.max(0, parseInt(inp.value, 10) || 0);
        var t = Object.keys(counts).reduce(function (a, k) { return a + parseFloat(k) * counts[k]; }, 0);
        target.value = JTP.round(t).toFixed(2).replace('.', ',');
        target.dataset.fresh = '';
        if (onChange) { onChange(); }
      });
      return h('label.jtp-denom', [h('span', dn >= 1 ? dn + ' €' : Math.round(dn * 100) + ' cts'), inp]);
    }));
  }

  function closeCash(s) {
    var counted = h('input.jtp-input.huge', { type: 'text', inputmode: 'decimal', placeholder: '0,00' });
    var diff = h('div.jtp-diff');
    var note = h('input.jtp-input', { type: 'text', placeholder: 'Observaciones (opcional)' });
    var upd = function () {
      var v = JTP.num(counted.value);
      var d = JTP.round(v - s.cash.expected);
      diff.className = 'jtp-diff ' + (Math.abs(d) < 0.005 ? 'ok' : (d > 0 ? 'plus' : 'minus'));
      diff.textContent = 'Esperado ' + money(s.cash.expected) + ' · Diferencia ' + (d > 0 ? '+' : '') + money(d);
    };
    counted.addEventListener('input', upd);
    var grid = denomsGrid(counted, upd);
    var close = function (btn) {
      busy(btn, JTP.api('session.close', { counted: JTP.num(counted.value), note: note.value })).then(function (r) {
        m.close();
        S.session = null;
        renderSession();
        JTP.emit('session:closed', r);
        JTP.print.print(r.ticket).catch(UI.error);
        UI.modal({ title: 'Caja cerrada', size: 'lg', body: summaryNode(r.ticket.summary), footer: [h('button.jtp-btn', { type: 'button', onclick: function () { JTP.print.print(r.ticket).catch(UI.error); } }, 'Reimprimir Z')] });
      }).catch(UI.error);
    };
    var m = UI.modal({
      title: 'Cerrar caja · arqueo',
      size: 'lg',
      body: h('div', [
        h('div.jtp-sub', 'Cuenta el efectivo (o escribe el total directamente)'),
        grid,
        h('label.jtp-label', 'Total contado'),
        counted,
        diff,
        note
      ]),
      footer: [h('button.jtp-btn', { type: 'button', onclick: function () { m.close(); } }, 'Cancelar'), h('span.jtp-flex'), h('button.jtp-btn.danger.lg', { type: 'button', onclick: function (e) { close(e.currentTarget); } }, 'Cerrar caja e imprimir Z')]
    });
    upd();
  }

  // ------------------------------------------------------------------ ajustes / empleado

  // ------------------------------------------------------------------ estado de QZ Tray

  function qzLabel(st) {
    return { on: 'Impresora lista', connecting: 'Conectando…', error: 'Impresora: error', off: 'Impresora: sin conexión' }[st.state] || 'QZ';
  }

  function qzPaint() {
    if (!els.qz || !els.qz.classList) { return; }
    var st = JTP.print.qzStatus;
    els.qz.className = 'jtp-top-btn jtp-qz ' + st.state;
    els.qz.innerHTML = '';
    els.qz.appendChild(h('span.jtp-dot'));
    els.qz.appendChild(h('span.jtp-hide-sm', qzLabel(st)));
    els.qz.title = st.error || qzLabel(st);
  }
  JTP.on('qz:status', qzPaint);
  JTP.on('ready', function () { setTimeout(qzPaint, 0); });

  function qzInfo() {
    var st = JTP.print.qzStatus;
    var body = h('div', [
      h('p', [h('strong', 'Estado: '), qzLabel(st)]),
      st.lastError ? h('p', [h('strong', 'Último error (' + st.lastErrorAt + '): '), st.lastError]) : null,
      h('p.jtp-muted', 'QZ Tray tiene que estar abierto en este ordenador (icono junto al reloj de Windows) y tener instalado el certificado del TPV (override.crt).')
    ]);
    var m = UI.modal({
      title: 'QZ Tray', size: 'md', body: body,
      footer: [
        h('button.jtp-btn', { type: 'button', onclick: function () { m.close(); } }, 'Cerrar'),
        h('span.jtp-flex'),
        h('button.jtp-btn.primary', { type: 'button', onclick: function () {
          var go = window.qz && qz.websocket.isActive() ? qz.websocket.disconnect().catch(function () {}) : Promise.resolve();
          go.then(function () { return JTP.print.qzConnect(); }).then(function () {
            UI.toast('Conectado con QZ Tray', 'ok'); m.close();
          }).catch(UI.error);
        } }, 'Reconectar')
      ]
    });
  }

  function openSettings() {
    var pc = S.print;
    var info = h('div.jtp-muted', 'Modo: ' + (pc.mode === 'qz' ? 'QZ Tray (' + (pc.format === 'html' ? 'HTML' : 'ESC/POS') + ') · ' + qzLabel(JTP.print.qzStatus) : 'navegador') + ' · Papel ' + pc.width + ' mm · Impresora: ' + (pc.printer || 'predeterminada de Windows')
      + (pc.mode === 'qz' && JTP.print.qzStatus.lastError ? ' · Último error: ' + JTP.print.qzStatus.lastError : ''));
    var printers = h('div.jtp-list');
    var m = UI.modal({
      title: 'Impresión y ajustes',
      size: 'md',
      body: h('div', [
        info,
        h('div.jtp-quick', [
          h('button.jtp-btn', { type: 'button', onclick: testPrint }, 'Ticket de prueba'),
          h('button.jtp-btn', { type: 'button', onclick: function () { JTP.print.openDrawer().then(function () { UI.toast('Orden de apertura enviada al cajón', 'ok'); }).catch(UI.error); } }, 'Abrir cajón'),
          pc.mode === 'qz' ? h('button.jtp-btn', { type: 'button', onclick: function () {
            JTP.print.qzPrinters().then(function (list) {
              printers.innerHTML = '';
              printers.appendChild(h('div.jtp-sub', 'Impresoras detectadas por QZ Tray (cópialas en Configuración > Cajas)'));
              list.forEach(function (p) { printers.appendChild(h('div.jtp-list-row', p)); });
            }).catch(UI.error);
          } }, 'Ver impresoras') : null
        ]),
        printers,
        h('div.jtp-sub', 'Addons activos'),
        S.addons.length ? h('div.jtp-list', S.addons.map(function (a) {
          return h('div.jtp-list-row', [h('div.jtp-grow', [h('strong', a.name), h('div.jtp-muted', a.module + ' v' + a.version)]), a.compatible ? h('span.jtp-tag', 'OK') : h('span.jtp-tag.warn', 'Requiere base ' + a.requires)]);
        })) : h('div.jtp-muted', 'Ninguno'),
        h('div.jtp-muted.small', 'TPV v' + JTP.version)
      ]),
      footer: [h('button.jtp-btn', { type: 'button', onclick: function () { location.href = JTP.config.posUrl + (JTP.config.posUrl.indexOf('?') === -1 ? '?' : '&') + 'logout=1'; } }, 'Cerrar sesión'), h('span.jtp-flex'), h('button.jtp-btn.primary', { type: 'button', onclick: function () { m.close(); } }, 'Cerrar')]
    });
  }

  function testPrint() {
    var t = {
      type: 'sale', title: 'TICKET DE PRUEBA', number: 'PRUEBA', date: new Date().toISOString().replace('T', ' ').substring(0, 19),
      register: S.register.name, employee: S.employee.name, shop: { name: JTP.config.shopName, lines: ['Prueba de impresión'], header: '', footer: 'Acentos: áéíóú ñÑ ¿? ¡!', logo: '' },
      options: { show_employee: true, show_taxes: true, barcode: true }, customer: null,
      lines: [{ name: 'Artículo de prueba', quantity: 1, unit_price: 1, total: 1, original_price: null }],
      discounts: [], totals: { total: 1 }, taxes: [{ rate: 21, base: 0.83, tax: 0.17 }], payments: [{ name: 'Efectivo', amount: 1 }], received: 1, change: 0, blocks: [{ type: 'qr', value: 'https://www.prestashop.com', label: 'QR de prueba' }]
    };
    JTP.print.print(t).catch(UI.error);
  }

  function switchEmployee() {
    UI.confirm('¿Cambiar de empleado? El ticket actual se conserva en la caja.', 'Cambiar').then(function (ok) {
      if (ok) { logout(); }
    });
  }

  // ------------------------------------------------------------------ atajos de teclado

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      var top = UI.topModal();
      if (top) { e.preventDefault(); top.close(); }
      return;
    }
    if (!els.search || UI.topModal()) { return; }
    if (e.key === 'F2') { e.preventDefault(); els.search.focus(); els.search.select(); }
    if (e.key === 'F4' || e.key === 'F12') { e.preventDefault(); openPayment(); }
    if (e.key === 'F6') { e.preventDefault(); openCustomer(); }
    if (e.key === 'F8') { e.preventDefault(); parkCart(); }
  });

  // API pública de la interfaz para addons
  UI.openPayment = openPayment;
  UI.openCustomer = openCustomer;
  UI.openCustomerForm = openCustomerForm;
  UI.editCustomer = editCustomer;
  UI.openAddresses = openAddresses;
  UI.openSales = openSales;
  UI.openSaleDetail = openSaleDetail;
  UI.openCash = openCash;
  UI.openVariants = openVariants;
  UI.refreshCart = function () { return JTP.api('cart.get').then(function (c) { setCart(c); return c; }); };

  // ------------------------------------------------------------------ CSS del ticket (también para QZ HTML)

  function ensureTicketCss() {
    if (document.getElementById('jtp-ticket-css')) { return; }
    var css = '.jtp-ticket{font-family:"Courier New",Courier,monospace;font-size:12px;line-height:1.35;color:#000;background:#fff;padding:2mm 1mm;box-sizing:border-box}' +
      '.jtp-ticket.w80{width:72mm;margin:0 auto}.jtp-ticket.w58{width:48mm;margin:0 auto;font-size:10.5px}' +
      '.jtp-ticket .t-logo{text-align:center;margin-bottom:2mm}.jtp-ticket .t-logo img{display:inline-block;max-width:60%;max-height:22mm;height:auto}' +
      '.jtp-ticket .t-shop{text-align:center;font-weight:bold;font-size:1.25em}.jtp-ticket .t-center{text-align:center}' +
      '.jtp-ticket .t-title{text-align:center;font-weight:bold;margin:2px 0}.jtp-ticket .t-b{font-weight:bold}' +
      '.jtp-ticket .t-sep{border-top:1px dashed #000;margin:4px 0}.jtp-ticket .t-row{display:flex;justify-content:space-between;gap:6px}' +
      '.jtp-ticket .t-row span:last-child{text-align:right;white-space:nowrap}.jtp-ticket .t-taxes .t-row span{flex:1;text-align:right}.jtp-ticket .t-taxes .t-row span:first-child{text-align:left}' +
      '.jtp-ticket .t-total{font-weight:bold;font-size:1.3em}.jtp-ticket .t-small{font-size:.85em}.jtp-ticket .t-item{margin:2px 0}' +
      '.jtp-ticket s{color:#555}.jtp-ticket .t-bc{text-align:center;margin-top:6px}.jtp-ticket .jtp-barcode{width:90%;height:12mm}' +
      '.jtp-ticket .t-qr svg{width:32mm;height:32mm}.jtp-ticket .t-img img{max-width:40mm;max-height:40mm}.jtp-ticket .t-sign{margin-top:14mm;border-top:1px solid #000}';
    var st = document.createElement('style');
    st.id = 'jtp-ticket-css';
    st.textContent = css;
    document.head.appendChild(st);
  }
})(window, document, window.JTP);
