/**
 * josratpvpresta - tickets: HTML (navegador / QZ HTML) y ESC/POS (QZ Tray), cajón portamonedas.
 *
 * Los addons pueden cambiar el render con los eventos:
 *   JTP.on('ticket:html', function (ctx) { ctx.html += '...'; })          ctx = {ticket, html, opts}
 *   JTP.on('ticket:escpos', function (ctx) { ctx.data.push('...'); })      ctx = {ticket, data, opts}
 */
(function (window, JTP) {
  'use strict';

  var esc = JTP.esc;
  var money = JTP.money;

  var P = JTP.print = {
    codepage: { escpos: 2 } // ESC t 2 = CP850 (ñ, acentos)
  };

  // ================================================================== Code128 (SVG)

  var C128 = ['212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213', '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132', '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211', '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313', '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331', '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111', '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214', '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111', '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141', '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141', '114131', '311141', '411131', '211412', '211214', '211232', '2331112'];

  P.barcodeSvg = function (text, height) {
    height = height || 40;
    var codes = [104];
    for (var i = 0; i < text.length; i++) {
      var c = text.charCodeAt(i) - 32;
      codes.push(c >= 0 && c < 95 ? c : 0);
    }
    var sum = codes[0];
    for (var j = 1; j < codes.length; j++) { sum += codes[j] * j; }
    codes.push(sum % 103);
    codes.push(106);
    var x = 10;
    var bars = '';
    codes.forEach(function (code) {
      var pattern = C128[code];
      for (var k = 0; k < pattern.length; k++) {
        var w = parseInt(pattern[k], 10);
        if (k % 2 === 0) { bars += '<rect x="' + x + '" y="0" width="' + w + '" height="' + height + '"/>'; }
        x += w;
      }
    });
    x += 10;
    return '<svg class="jtp-barcode" viewBox="0 0 ' + x + ' ' + height + '" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg" fill="#000">' + bars + '</svg>';
  };

  /**
   * QR en PNG (base64, sin cabecera) para imprimir por ESC/POS como imagen.
   * Unos 34 mm de ancho a 203 ppp, corrección de errores M (lo que pide la AEAT para VERI*FACTU).
   */
  P.qrPng = function (text, mm) {
    if (typeof window.qrcode !== 'function' || !document.createElement('canvas').getContext) { return ''; }
    try {
      window.qrcode.stringToBytes = window.qrcode.stringToBytesFuncs['UTF-8'];
      var q = window.qrcode(0, 'M');
      q.addData(String(text), 'Byte');
      q.make();
      var n = q.getModuleCount();
      var margin = 4;
      var target = Math.round((mm || 34) * 8);
      var cell = Math.max(2, Math.floor(target / (n + margin * 2)));
      var size = (n + margin * 2) * cell;
      var c = document.createElement('canvas');
      c.width = size;
      c.height = size;
      var ctx = c.getContext('2d');
      ctx.fillStyle = '#fff';
      ctx.fillRect(0, 0, size, size);
      ctx.fillStyle = '#000';
      for (var r = 0; r < n; r++) {
        for (var k = 0; k < n; k++) {
          if (q.isDark(r, k)) { ctx.fillRect((k + margin) * cell, (r + margin) * cell, cell, cell); }
        }
      }
      return c.toDataURL('image/png').split(',')[1];
    } catch (e) { return ''; }
  };

  P.qrSvg = function (text) {
    if (typeof window.qrcode !== 'function') { return ''; }
    try {
      window.qrcode.stringToBytes = window.qrcode.stringToBytesFuncs['UTF-8'];
      var q = window.qrcode(0, 'M');
      q.addData(String(text), 'Byte');
      q.make();
      return q.createSvgTag({ cellSize: 3, margin: 2, scalable: true });
    } catch (e) { return ''; }
  };

  // ================================================================== HTML

  function blocksHtml(blocks, position) {
    return (blocks || []).filter(function (b) { return (b.position || 'bottom') === position; }).map(function (b) {
      var align = ' style="text-align:' + (b.align || (b.type === 'qr' || b.type === 'barcode' || b.type === 'title' ? 'center' : 'left')) + '"';
      switch (b.type) {
        case 'separator': return '<div class="t-sep"></div>';
        case 'title': return '<div class="t-title"' + align + '>' + esc(b.value) + '</div>';
        case 'pair': return '<div class="t-row' + (b.bold ? ' t-b' : '') + '"><span>' + esc(b.label) + '</span><span>' + esc(b.value) + '</span></div>';
        case 'qr': return '<div class="t-qr"' + align + '>' + P.qrSvg(b.value) + (b.label ? '<div class="t-small">' + esc(b.label) + '</div>' : '') + '</div>';
        case 'barcode': return '<div class="t-bc"' + align + '>' + P.barcodeSvg(String(b.value)) + '<div class="t-small">' + esc(b.value) + '</div></div>';
        case 'image': return '<div class="t-img" style="text-align:' + (b.align || 'center') + '"><img src="' + esc(imgSrc(b.value)) + '" alt="">' + (b.label ? '<div class="t-small">' + esc(b.label) + '</div>' : '') + '</div>';
        default: return '<div class="t-text' + (b.bold ? ' t-b' : '') + '"' + align + '>' + esc(b.value).replace(/\n/g, '<br>') + '</div>';
      }
    }).join('');
  }

  // Imagen: URL, data URI o base64 PNG sin prefijo
  function imgSrc(v) {
    v = String(v || '');
    if (/^(https?:|data:|\/)/.test(v)) { return v; }
    return 'data:image/png;base64,' + v;
  }
  P.imgSrc = imgSrc;

  // ------------------------------------------------------------------ logo

  var logoCache = {};
  var logoReady = {};

  /**
   * Prepara el logo una sola vez: PNG sobre fondo blanco (HTML) y mapa de bits de 1 bit para ESC/POS
   * (ancho ajustado a la impresora, transparencias en blanco). Si no se puede cargar, se resuelve a null.
   */
  P.logoAssets = function (url) {
    if (!url) { return Promise.resolve(null); }
    if (logoReady[url]) { return Promise.resolve(logoReady[url]); }
    if (logoCache[url]) { return logoCache[url]; }
    logoCache[url] = new Promise(function (resolve) {
      var img = new Image();
      var finished = false;
      var end = function (v) {
        if (finished) { return; }
        finished = true;
        if (v) { logoReady[url] = v; } else { delete logoCache[url]; }
        resolve(v);
      };
      img.onload = function () {
        try { end(buildLogo(img)); } catch (e) { end(null); }
      };
      img.onerror = function () { end(null); };
      setTimeout(function () { end(null); }, 4000);
      img.src = url;
    });
    return logoCache[url];
  };

  function buildLogo(img) {
    var nw = img.naturalWidth || img.width;
    var nh = img.naturalHeight || img.height;
    if (!nw || !nh) { return null; }
    // PNG para HTML (máx. 600 px de ancho)
    var k = Math.min(1, 600 / nw);
    var c = document.createElement('canvas');
    c.width = Math.max(1, Math.round(nw * k));
    c.height = Math.max(1, Math.round(nh * k));
    var x = c.getContext('2d');
    x.fillStyle = '#fff';
    x.fillRect(0, 0, c.width, c.height);
    x.drawImage(img, 0, 0, c.width, c.height);
    var out = { dataUrl: c.toDataURL('image/png'), raster: {} };
    // Mapa de bits ESC/POS para 80 mm (máx. 384 puntos = 48 mm) y 58 mm (máx. 256 puntos)
    [[80, 384], [58, 256]].forEach(function (cfg) {
      var maxH = 160;
      var sc = Math.min(cfg[1] / nw, maxH / nh);
      var w = Math.max(8, Math.round(nw * sc));
      w = Math.ceil(w / 8) * 8;
      var hgt = Math.max(1, Math.round(nh * sc));
      var cv = document.createElement('canvas');
      cv.width = w;
      cv.height = hgt;
      var cx = cv.getContext('2d');
      cx.fillStyle = '#fff';
      cx.fillRect(0, 0, w, hgt);
      cx.drawImage(img, 0, 0, w, hgt);
      var px = cx.getImageData(0, 0, w, hgt).data;
      var bytesW = w / 8;
      var bin = '';
      // GS v 0 en bandas de 64 líneas (algunas impresoras limitan la altura por orden)
      for (var y0 = 0; y0 < hgt; y0 += 64) {
        var bh = Math.min(64, hgt - y0);
        bin += GS + 'v0\x00' + String.fromCharCode(bytesW % 256, Math.floor(bytesW / 256), bh % 256, Math.floor(bh / 256));
        for (var y = y0; y < y0 + bh; y++) {
          for (var bx = 0; bx < bytesW; bx++) {
            var byte = 0;
            for (var bit = 0; bit < 8; bit++) {
              var i = (y * w + bx * 8 + bit) * 4;
              var lum = 0.299 * px[i] + 0.587 * px[i + 1] + 0.114 * px[i + 2];
              if (lum < 140) { byte |= 0x80 >> bit; }
            }
            bin += String.fromCharCode(byte);
          }
        }
      }
      out.raster[cfg[0]] = bin;
    });
    return out;
  }

  function headerHtml(t) {
    var s = t.shop || {};
    var h = '';
    if (s.logo) {
      // El logo va incrustado (PNG sobre fondo blanco) si ya está preparado: así QZ y el navegador no dependen de descargarlo
      var lg = logoReady[s.logo];
      h += '<div class="t-logo"><img src="' + esc(lg && lg.dataUrl ? lg.dataUrl : s.logo) + '" alt=""></div>';
    }
    h += '<div class="t-shop">' + esc(s.name) + '</div>';
    (s.lines || []).forEach(function (l) { h += '<div class="t-center">' + esc(l) + '</div>'; });
    if (s.header) { h += '<div class="t-center">' + esc(s.header).replace(/\n/g, '<br>') + '</div>'; }
    h += blocksHtml(t.blocks, 'top');
    h += '<div class="t-sep"></div>';
    h += '<div class="t-title">' + esc(t.title) + '</div>';
    h += '<div class="t-row"><span>Nº ' + esc(t.number) + '</span><span>' + esc(fmtDate(t.date)) + '</span></div>';
    if (t.original_number) { h += '<div class="t-row"><span>Ticket original</span><span>' + esc(t.original_number) + '</span></div>'; }
    var meta = [];
    if (t.register) { meta.push(esc(t.register)); }
    if (t.employee && t.options && t.options.show_employee) { meta.push('Atendido por ' + esc(t.employee)); }
    if (meta.length) { h += '<div class="t-small">' + meta.join(' · ') + '</div>'; }
    h += blocksHtml(t.blocks, 'header');
    if (t.customer) {
      h += '<div class="t-sep"></div><div class="t-b">' + esc(t.customer.name) + '</div>';
      if (t.customer.nif) { h += '<div>NIF: ' + esc(t.customer.nif) + '</div>'; }
      (t.customer.lines || []).forEach(function (l) { if (l) { h += '<div>' + esc(l) + '</div>'; } });
    }
    return h;
  }

  function fmtDate(d) {
    if (!d) { return ''; }
    var m = String(d).match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
    return m ? m[3] + '/' + m[2] + '/' + m[1] + ' ' + m[4] + ':' + m[5] : d;
  }
  P.fmtDate = fmtDate;

  P.renderHtml = function (t, opts) {
    opts = opts || {};
    var gift = !!opts.gift;
    // Margen superior configurable: sin él, el área no imprimible del driver corta el logo o el nombre de la tienda
    var top = Math.max(0, Math.min(30, parseInt(JTP.state.print.top_mm, 10) || 0));
    var h = '<div class="jtp-ticket w' + (JTP.state.print.width || 80) + '" style="padding-top:' + (2 + top) + 'mm">';
    h += headerHtml(gift ? Object.assign({}, t, { title: 'TICKET REGALO', customer: null }) : t);

    if (t.type === 'sale' || t.type === 'return') {
      h += '<div class="t-sep"></div>';
      (t.lines || []).forEach(function (l) {
        if (l.sub) {
          // Ecotasa (u otra línea ligada al producto de arriba): sangrada y en pequeño
          h += '<div class="t-item t-sub"><div class="t-row t-small"><span>&nbsp;&nbsp;+ ' + esc(l.name) + '</span><span></span></div>';
          if (!gift) {
            h += '<div class="t-row t-small"><span>&nbsp;&nbsp;&nbsp;&nbsp;' + l.quantity + ' x ' + money(l.unit_price) + '</span><span>' + money(l.total) + '</span></div>';
          }
          h += '</div>';
          return;
        }
        h += '<div class="t-item"><div class="t-name">' + esc(l.name) + '</div>';
        if (t.options && t.options.show_reference && l.reference) { h += '<div class="t-small">Ref. ' + esc(l.reference) + '</div>'; }
        (l.notes || []).forEach(function (n) { h += '<div class="t-small">' + esc(n) + '</div>'; });
        if (gift) {
          h += '<div class="t-row"><span>' + l.quantity + ' ud.</span><span></span></div>';
        } else {
          h += '<div class="t-row"><span>' + l.quantity + ' x ' + money(l.unit_price) +
            (l.original_price ? ' <s>' + money(l.original_price) + '</s>' : '') + '</span><span>' + money(l.total) + '</span></div>';
        }
        h += '</div>';
      });
      if (!gift) {
        (t.discounts || []).forEach(function (d) {
          h += '<div class="t-row"><span>' + esc(d.name) + '</span><span>-' + money(d.value) + '</span></div>';
        });
        h += '<div class="t-sep"></div>';
        h += '<div class="t-row t-total"><span>TOTAL</span><span>' + money(t.totals.total) + '</span></div>';
        if (t.options && t.options.show_taxes && t.taxes && t.taxes.length) {
          h += '<div class="t-taxes"><div class="t-row t-small"><span>IVA</span><span>Base</span><span>Cuota</span></div>';
          t.taxes.forEach(function (x) {
            h += '<div class="t-row t-small"><span>' + x.rate + '%</span><span>' + money(x.base) + '</span><span>' + money(x.tax) + '</span></div>';
            if (x.re_rate) {
              h += '<div class="t-row t-small"><span>RE ' + String(x.re_rate).replace('.', ',') + '%</span><span>' + money(x.base) + '</span><span>' + money(x.re) + '</span></div>';
            }
          });
          h += '</div>';
        }
        h += '<div class="t-sep"></div>';
        (t.payments || []).forEach(function (p) {
          h += '<div class="t-row"><span>' + esc(p.name) + (p.reference ? ' (' + esc(p.reference) + ')' : '') + '</span><span>' + money(p.amount) + '</span></div>';
        });
        if (t.change > 0) {
          h += '<div class="t-row"><span>Entregado</span><span>' + money(t.received) + '</span></div>';
          h += '<div class="t-row t-b"><span>Cambio</span><span>' + money(t.change) + '</span></div>';
        }
        if (t.voucher) {
          h += '<div class="t-sep"></div><div class="t-title">VALE ' + money(t.voucher.amount) + '</div>' +
            '<div class="t-bc">' + P.barcodeSvg(t.voucher.code) + '<div class="t-b">' + esc(t.voucher.code) + '</div></div>';
        }
        if (t.reason) { h += '<div class="t-small">Motivo: ' + esc(t.reason) + '</div>'; }
      }
      if (t.note) { h += '<div class="t-sep"></div><div>' + esc(t.note) + '</div>'; }
    } else if (t.type === 'z') {
      h += zHtml(t.summary);
    } else if (t.type === 'movement') {
      h += '<div class="t-sep"></div><div class="t-row t-total"><span>Importe</span><span>' + money(t.amount) + '</span></div>';
      h += '<div>' + esc(t.reason) + '</div><div class="t-sign">Firma:</div>';
    }

    h += blocksHtml(t.blocks, 'bottom');
    if ((t.type === 'sale' || t.type === 'return') && t.options && t.options.barcode) {
      h += '<div class="t-bc">' + P.barcodeSvg(t.number) + '</div>';
    }
    if (t.shop && t.shop.footer && t.type !== 'z' && t.type !== 'movement') {
      h += '<div class="t-sep"></div><div class="t-center">' + esc(t.shop.footer).replace(/\n/g, '<br>') + '</div>';
    }
    h += '</div>';
    var ctx = { ticket: t, html: h, opts: opts };
    JTP.emit('ticket:html', ctx);
    return ctx.html;
  };

  function zHtml(s) {
    var h = '<div class="t-sep"></div>';
    h += '<div class="t-row"><span>Apertura</span><span>' + fmtDate(s.session.date_open) + '</span></div>';
    if (s.session.date_close) { h += '<div class="t-row"><span>Cierre</span><span>' + fmtDate(s.session.date_close) + '</span></div>'; }
    h += '<div class="t-row"><span>Ventas (' + s.sales_count + ')</span><span>' + money(s.sales_total) + '</span></div>';
    h += '<div class="t-row"><span>Devoluciones (' + s.returns_count + ')</span><span>-' + money(s.returns_total) + '</span></div>';
    h += '<div class="t-sep"></div><div class="t-b">Por método de pago</div>';
    s.by_method.forEach(function (m) { h += '<div class="t-row"><span>' + esc(m.name) + ' (' + m.n + ')</span><span>' + money(m.amount) + '</span></div>'; });
    s.returns_by_method.forEach(function (m) { h += '<div class="t-row"><span>Dev. ' + esc(m.name) + '</span><span>-' + money(m.amount) + '</span></div>'; });
    h += '<div class="t-sep"></div><div class="t-b">Efectivo</div>';
    h += '<div class="t-row"><span>Fondo inicial</span><span>' + money(s.cash.opening) + '</span></div>';
    h += '<div class="t-row"><span>Ventas</span><span>' + money(s.cash.sales) + '</span></div>';
    h += '<div class="t-row"><span>Devoluciones</span><span>' + money(s.cash.refunds) + '</span></div>';
    h += '<div class="t-row"><span>Entradas</span><span>' + money(s.cash.in) + '</span></div>';
    h += '<div class="t-row"><span>Salidas</span><span>' + money(s.cash.out) + '</span></div>';
    h += '<div class="t-row t-b"><span>Esperado</span><span>' + money(s.cash.expected) + '</span></div>';
    if (s.session.status === 'closed') {
      h += '<div class="t-row t-b"><span>Contado</span><span>' + money(s.session.counted_cash) + '</span></div>';
      h += '<div class="t-row t-total"><span>Diferencia</span><span>' + money(s.session.difference) + '</span></div>';
      if (s.session.note) { h += '<div>' + esc(s.session.note) + '</div>'; }
    }
    return h;
  }

  // ================================================================== ESC/POS

  var ESC = '\x1B';
  var GS = '\x1D';
  var CMD = {
    init: ESC + '@',
    left: ESC + 'a\x00',
    center: ESC + 'a\x01',
    right: ESC + 'a\x02',
    boldOn: ESC + 'E\x01',
    boldOff: ESC + 'E\x00',
    big: GS + '!\x11',
    tall: GS + '!\x01',
    normal: GS + '!\x00',
    cut: GS + 'VA\x03',
    drawer: ESC + 'p\x00\x19\xFA'
  };
  P.CMD = CMD;

  // Texto Unicode -> bytes CP850 (acentos, ñ, ç, ¿, ¡, º, ª…)
  var CP850 = { 'Ç': 0x80, 'ü': 0x81, 'é': 0x82, 'â': 0x83, 'ä': 0x84, 'à': 0x85, 'å': 0x86, 'ç': 0x87, 'ê': 0x88, 'ë': 0x89, 'è': 0x8A, 'ï': 0x8B, 'î': 0x8C, 'ì': 0x8D, 'Ä': 0x8E, 'Å': 0x8F, 'É': 0x90, 'æ': 0x91, 'Æ': 0x92, 'ô': 0x93, 'ö': 0x94, 'ò': 0x95, 'û': 0x96, 'ù': 0x97, 'ÿ': 0x98, 'Ö': 0x99, 'Ü': 0x9A, 'ø': 0x9B, '£': 0x9C, 'Ø': 0x9D, '×': 0x9E, 'á': 0xA0, 'í': 0xA1, 'ó': 0xA2, 'ú': 0xA3, 'ñ': 0xA4, 'Ñ': 0xA5, 'ª': 0xA6, 'º': 0xA7, '¿': 0xA8, '®': 0xA9, '½': 0xAB, '¼': 0xAC, '¡': 0xAD, '«': 0xAE, '»': 0xAF, 'Á': 0xB5, 'Â': 0xB6, 'À': 0xB7, '©': 0xB8, 'ã': 0xC6, 'Ã': 0xC7, 'Ê': 0xD2, 'Ë': 0xD3, 'È': 0xD4, 'Í': 0xD6, 'Î': 0xD7, 'Ï': 0xD8, 'Ì': 0xDE, 'Ó': 0xE0, 'ß': 0xE1, 'Ô': 0xE2, 'Ò': 0xE3, 'õ': 0xE4, 'Õ': 0xE5, 'µ': 0xE6, 'Ú': 0xE9, 'Û': 0xEA, 'Ù': 0xEB, 'ý': 0xEC, 'Ý': 0xED, '°': 0xF8, '·': 0xFA };

  P.cp850 = function (text) {
    var out = '';
    String(text === null || text === undefined ? '' : text).replace(/€/g, 'EUR').replace(/[‘’]/g, "'").replace(/[“”]/g, '"').replace(/[–—]/g, '-')
      .split('').forEach(function (ch) {
        var c = ch.charCodeAt(0);
        if (c < 128) { out += ch; return; }
        if (CP850[ch]) { out += String.fromCharCode(CP850[ch]); return; }
        var base = ch.normalize ? ch.normalize('NFD').replace(/[̀-ͯ]/g, '') : '?';
        out += base && base.charCodeAt(0) < 128 ? base : '?';
      });
    return out;
  };

  function pad(s, n, right) {
    s = String(s);
    if (s.length > n) { return s.substring(0, n); }
    var sp = new Array(n - s.length + 1).join(' ');
    return right ? sp + s : s + sp;
  }

  function wrap(text, w) {
    var out = [];
    String(text === null || text === undefined ? '' : text).split('\n').forEach(function (para) {
      var line = '';
      para.split(' ').forEach(function (word) {
        while (word.length > w) {
          if (line) { out.push(line); line = ''; }
          out.push(word.substring(0, w));
          word = word.substring(w);
        }
        if ((line + (line ? ' ' : '') + word).length > w) {
          out.push(line);
          line = word;
        } else {
          line += (line ? ' ' : '') + word;
        }
      });
      out.push(line);
    });
    return out;
  }

  function m(v) {
    return (Number(v) || 0).toFixed(2).replace('.', ',') + ((JTP.config.currency && JTP.config.currency.iso !== 'EUR') ? ' ' + JTP.config.currency.iso : ' EUR');
  }

  function qrCmd(data) {
    var bytes = unescape(encodeURIComponent(String(data)));
    var len = bytes.length + 3;
    return GS + '(k\x04\x00\x31\x41\x32\x00' +
      GS + '(k\x03\x00\x31\x43\x06' +
      GS + '(k\x03\x00\x31\x45\x31' +
      GS + '(k' + String.fromCharCode(len % 256) + String.fromCharCode(Math.floor(len / 256)) + '\x31\x50\x30' + bytes +
      GS + '(k\x03\x00\x31\x51\x30';
  }

  function barcodeCmd(data) {
    data = P.cp850(data).replace(/[^\x20-\x7E]/g, '').substring(0, 60);
    return GS + 'h\x50' + GS + 'w\x02' + GS + 'H\x02' + GS + 'k\x49' + String.fromCharCode(data.length + 2) + '{B' + data;
  }

  /**
   * Constructor de ESC/POS en bytes (cadena binaria). El texto se convierte a CP850;
   * los comandos van tal cual. Se envía a QZ en base64 para que no se recodifique.
   */
  function Builder(w) {
    this.w = w;
    this.bin = '';
  }
  Builder.prototype.cmd = function (c) { this.bin += c; return this; };
  Builder.prototype.text = function (s) { this.bin += P.cp850(s); return this; };
  Builder.prototype.ln = function (s) { return this.text((s === undefined ? '' : s) + '\n'); };
  Builder.prototype.wrap = function (s) { var self = this; wrap(s, this.w).forEach(function (l) { self.ln(l); }); return this; };
  Builder.prototype.sep = function () { return this.ln(new Array(this.w + 1).join('-')); };
  Builder.prototype.row = function (left, right) {
    left = String(left);
    right = String(right);
    var space = this.w - right.length - 1;
    if (left.length > space) {
      this.wrap(left);
      return this.ln(pad(right, this.w, true));
    }
    return this.ln(pad(left, space) + ' ' + right);
  };
  Builder.prototype.bold = function (fn) { this.cmd(CMD.boldOn); fn.call(this); return this.cmd(CMD.boldOff); };

  P.escBase64 = function (bin) {
    return { type: 'raw', format: 'command', flavor: 'base64', data: window.btoa(bin) };
  };
  P.escText = function (text) { return P.escBase64(P.cp850(text)); };

  function blocksEsc(b, blocks, position, data) {
    (blocks || []).filter(function (x) { return (x.position || 'bottom') === position; }).forEach(function (x) {
      var al = x.align === 'center' || x.type === 'qr' || x.type === 'barcode' || x.type === 'title' ? CMD.center : (x.align === 'right' ? CMD.right : CMD.left);
      b.cmd(al);
      switch (x.type) {
        case 'separator': b.cmd(CMD.left).sep(); break;
        case 'title': b.bold(function () { this.wrap(x.value); }); break;
        case 'pair': b.cmd(CMD.left); if (x.bold) { b.bold(function () { this.row(x.label || '', x.value || ''); }); } else { b.row(x.label || '', x.value || ''); } break;
        case 'qr':
          var png = data && JTP.state.print.qr !== 'native' ? P.qrPng(x.value) : '';
          if (png) {
            // Como imagen: la imprime cualquier impresora ESC/POS (el comando QR nativo no lo tienen todas)
            data.push(P.escBase64(b.bin));
            b.bin = '';
            data.push({ type: 'raw', format: 'image', flavor: 'base64', data: png, options: { language: 'ESCPOS', dotDensity: 'double' } });
            b.cmd(CMD.center).ln();
          } else {
            b.cmd(qrCmd(x.value)).ln();
          }
          if (x.label) { b.wrap(x.label); }
          break;
        case 'barcode': b.cmd(barcodeCmd(x.value)).ln(); break;
        case 'image':
          if (data) {
            data.push(P.escBase64(b.bin));
            b.bin = '';
            var src = imgSrc(x.value);
            data.push(src.indexOf('data:') === 0
              ? { type: 'raw', format: 'image', flavor: 'base64', data: src.substring(src.indexOf(',') + 1), options: { language: 'ESCPOS', dotDensity: 'double' } }
              : { type: 'raw', format: 'image', flavor: 'file', data: src, options: { language: 'ESCPOS', dotDensity: 'double' } });
            b.cmd(CMD.center).ln();
            if (x.label) { b.wrap(x.label); }
          }
          break;
        default: if (x.bold) { b.bold(function () { this.wrap(x.value); }); } else { b.wrap(x.value); }
      }
      b.cmd(CMD.left);
    });
  }

  P.toEscPos = function (t, opts) {
    opts = opts || {};
    var gift = !!opts.gift;
    var w = (JTP.state.print.width || 80) === 58 ? 32 : 48;
    var data = [];
    var s = t.shop || {};
    var b = new Builder(w);
    b.cmd(CMD.init + ESC + 't' + String.fromCharCode(P.codepage.escpos));
    if (s.logo && opts.logo !== false) {
      var lg = logoReady[s.logo];
      var ras = lg && lg.raster ? lg.raster[(JTP.state.print.width || 80) === 58 ? 58 : 80] : '';
      b.cmd(CMD.center);
      if (ras) {
        // Logo ya convertido a 1 bit en el navegador (no depende de que QZ lo descargue)
        b.cmd(ras).cmd('\n');
      } else {
        data.push(P.escBase64(b.bin));
        data.push({ type: 'raw', format: 'image', flavor: 'file', data: s.logo, options: { language: 'ESCPOS', dotDensity: 'double' } });
        b = new Builder(w);
      }
    }
    b.cmd(CMD.center + CMD.boldOn + CMD.tall).wrap(s.name || '').cmd(CMD.normal + CMD.boldOff);
    (s.lines || []).forEach(function (l) { b.wrap(l); });
    if (s.header) { b.wrap(s.header); }
    blocksEsc(b, t.blocks, 'top', data);
    b.cmd(CMD.left).sep().cmd(CMD.center).bold(function () { this.ln(gift ? 'TICKET REGALO' : t.title); }).cmd(CMD.left);
    b.row('N. ' + t.number, fmtDate(t.date));
    if (t.original_number) { b.row('Ticket original', t.original_number); }
    if (t.register) { b.wrap(t.register + (t.employee && t.options && t.options.show_employee ? ' - ' + t.employee : '')); }
    blocksEsc(b, t.blocks, 'header', data);
    if (t.customer && !gift) {
      b.sep().bold(function () { this.wrap(t.customer.name); });
      if (t.customer.nif) { b.ln('NIF: ' + t.customer.nif); }
      (t.customer.lines || []).forEach(function (l) { if (l) { b.wrap(l); } });
    }
    if (t.type === 'sale' || t.type === 'return') {
      b.sep();
      (t.lines || []).forEach(function (l) {
        if (l.sub) {
          b.wrap('  + ' + l.name);
          if (!gift) { b.row('    ' + l.quantity + ' x ' + m(l.unit_price), m(l.total)); }
          return;
        }
        b.wrap(l.name);
        if (t.options && t.options.show_reference && l.reference) { b.ln('  Ref. ' + l.reference); }
        (l.notes || []).forEach(function (n) { b.wrap('  ' + n); });
        if (gift) { b.ln('  ' + l.quantity + ' ud.'); } else { b.row('  ' + l.quantity + ' x ' + m(l.unit_price), m(l.total)); }
      });
      if (!gift) {
        (t.discounts || []).forEach(function (d) { b.row(d.name, '-' + m(d.value)); });
        b.sep().cmd(CMD.boldOn + CMD.tall).row('TOTAL', m(t.totals.total)).cmd(CMD.normal + CMD.boldOff);
        if (t.options && t.options.show_taxes && t.taxes && t.taxes.length) {
          t.taxes.forEach(function (x) {
            b.row('IVA ' + x.rate + '% s/ ' + m(x.base), m(x.tax));
            if (x.re_rate) { b.row('RE ' + String(x.re_rate).replace('.', ',') + '% s/ ' + m(x.base), m(x.re)); }
          });
        }
        b.sep();
        (t.payments || []).forEach(function (p) { b.row(p.name, m(p.amount)); });
        if (t.change > 0) {
          b.row('Entregado', m(t.received));
          b.bold(function () { this.row('Cambio', m(t.change)); });
        }
        if (t.voucher) {
          b.sep().cmd(CMD.center).bold(function () { this.ln('VALE ' + m(t.voucher.amount)); }).cmd(barcodeCmd(t.voucher.code)).ln().cmd(CMD.left);
        }
        if (t.reason) { b.wrap('Motivo: ' + t.reason); }
      }
      if (t.note) { b.sep().wrap(t.note); }
    } else if (t.type === 'z') {
      var z = t.summary;
      b.sep();
      b.row('Apertura', fmtDate(z.session.date_open));
      if (z.session.date_close) { b.row('Cierre', fmtDate(z.session.date_close)); }
      b.row('Ventas (' + z.sales_count + ')', m(z.sales_total));
      b.row('Devoluciones (' + z.returns_count + ')', '-' + m(z.returns_total));
      b.sep().bold(function () { this.ln('Por método de pago'); });
      z.by_method.forEach(function (x) { b.row(x.name + ' (' + x.n + ')', m(x.amount)); });
      z.returns_by_method.forEach(function (x) { b.row('Dev. ' + x.name, '-' + m(x.amount)); });
      b.sep().bold(function () { this.ln('Efectivo'); });
      b.row('Fondo inicial', m(z.cash.opening)).row('Ventas', m(z.cash.sales)).row('Devoluciones', m(z.cash.refunds));
      b.row('Entradas', m(z.cash.in)).row('Salidas', m(z.cash.out));
      b.bold(function () { this.row('Esperado', m(z.cash.expected)); });
      if (z.session.status === 'closed') {
        b.bold(function () { this.row('Contado', m(z.session.counted_cash)).row('Diferencia', m(z.session.difference)); });
      }
    } else if (t.type === 'movement') {
      b.sep().bold(function () { this.row('Importe', m(t.amount)); }).wrap(t.reason).ln().ln().ln('Firma:').ln().ln();
    }
    blocksEsc(b, t.blocks, 'bottom', data);
    if ((t.type === 'sale' || t.type === 'return') && t.options && t.options.barcode) {
      b.cmd(CMD.center + barcodeCmd(t.number)).ln().cmd(CMD.left);
    }
    if (s.footer && t.type !== 'z' && t.type !== 'movement') {
      b.sep().cmd(CMD.center).wrap(s.footer).cmd(CMD.left);
    }
    b.ln().ln().ln().ln().cmd(CMD.cut);
    data.push(P.escBase64(b.bin));
    var ctx = { ticket: t, data: data, opts: opts };
    JTP.emit('ticket:escpos', ctx);
    return ctx.data;
  };

  // ================================================================== QZ Tray

  var qzReady = null;
  var qzSecurityDone = false;

  // Estado de la conexión, visible en la barra superior: off | connecting | on | error
  P.qzStatus = { state: 'off', error: '', lastError: '', lastErrorAt: '' };

  function qzSet(state, error) {
    P.qzStatus.state = state;
    P.qzStatus.error = error || '';
    if (error) {
      P.qzStatus.lastError = error;
      P.qzStatus.lastErrorAt = new Date().toLocaleTimeString();
    }
    JTP.emit('qz:status', P.qzStatus);
  }

  function errText(e) {
    if (!e) { return 'error desconocido'; }
    if (typeof e === 'string') { return e; }
    if (e.message) { return e.message; }
    if (e.target && e.type) { return 'conexión cerrada (' + e.type + ')'; }
    try { return JSON.stringify(e); } catch (x) { return String(e); }
  }
  P.errText = errText;

  function qzSecurity() {
    if (qzSecurityDone || !JTP.state.print.signed) { return; }
    qzSecurityDone = true;
    qz.security.setCertificatePromise(function (resolve, reject) {
      JTP.api('qz.cert').then(resolve, reject);
    });
    qz.security.setSignatureAlgorithm('SHA512');
    qz.security.setSignaturePromise(function (toSign) {
      return function (resolve, reject) {
        JTP.api('qz.sign', { request: toSign }).then(resolve, reject);
      };
    });
    if (qz.websocket.setClosedCallbacks) {
      qz.websocket.setClosedCallbacks(function () { qzSet('off'); });
    }
  }

  P.qzConnect = function () {
    if (!window.qz) {
      qzSet('error', 'No se ha cargado la librería de QZ Tray');
      return Promise.reject(new Error('No se ha cargado la librería de QZ Tray'));
    }
    if (qz.websocket.isActive()) { qzSet('on'); return Promise.resolve(); }
    if (qzReady) { return qzReady; }
    qzSecurity();
    qzSet('connecting');
    var attempt = function () {
      return qz.websocket.connect({ retries: 3, delay: 1 });
    };
    qzReady = attempt().catch(function (e) {
      // "ya existe una conexión": la anterior aún se estaba abriendo o cerrando
      if (/already exists|ya existe/i.test(errText(e))) {
        return new Promise(function (r) { setTimeout(r, 800); }).then(function () {
          return qz.websocket.isActive() ? null : qz.websocket.disconnect().catch(function () {}).then(attempt);
        });
      }
      throw e;
    }).then(function () {
      qzReady = null;
      qzSet('on');
    }, function (e) {
      qzReady = null;
      var msg = 'No se pudo conectar con QZ Tray: ' + errText(e) + '. Comprueba que QZ Tray está abierto en este ordenador y que tiene el certificado del TPV (override.crt).';
      qzSet('error', msg);
      throw new Error(msg);
    });
    return qzReady;
  };

  P.qzPrinter = function () {
    var name = JTP.state.print.printer;
    return P.qzConnect().then(function () {
      return (name ? qz.printers.find(name) : qz.printers.getDefault()).catch(function (e) {
        throw new Error(name
          ? 'QZ Tray no encuentra la impresora "' + name + '" (' + errText(e) + '). Revisa el nombre en Configuración > Cajas (⚙ > Ver impresoras).'
          : 'No hay impresora predeterminada en Windows y la caja no tiene impresora configurada.');
      });
    }).then(function (printer) {
      if (Array.isArray(printer)) { printer = printer[0]; }
      if (!printer) {
        throw new Error(name ? 'QZ Tray no encuentra la impresora "' + name + '"' : 'No hay impresora predeterminada');
      }
      return printer;
    });
  };

  P.qzPrinters = function () {
    return P.qzConnect().then(function () { return qz.printers.find(); });
  };

  function wrapHtmlDoc(html) {
    var css = document.getElementById('jtp-ticket-css');
    return '<html><head><meta charset="utf-8"><style>' + (css ? css.textContent : '') + '</style></head><body>' + html + '</body></html>';
  }

  function qzPrintOnce(t, opts) {
    var pc = JTP.state.print;
    var logo = t.shop && t.shop.logo && opts.logo !== false ? P.logoAssets(t.shop.logo) : Promise.resolve(null);
    return logo.then(function () { return P.qzPrinter(); }).then(function (printer) {
      var copies = opts && opts.copies ? opts.copies : 1;
      if (pc.format === 'html') {
        var cfg = qz.configs.create(printer, { copies: copies, size: { width: pc.width === 58 ? 58 : 80 }, units: 'mm', margins: { top: 0, right: 0, bottom: 0, left: 0 }, scaleContent: true });
        return qz.print(cfg, [{ type: 'pixel', format: 'html', flavor: 'plain', data: wrapHtmlDoc(P.renderHtml(t, opts)) }]).then(function () {
          if (opts && opts.drawer) { return P.openDrawer().catch(function () {}); }
        });
      }
      var cfgRaw = qz.configs.create(printer, { copies: copies });
      var data = P.toEscPos(t, opts);
      if (opts && opts.drawer) { data.unshift(P.escBase64(CMD.drawer)); }
      return qz.print(cfgRaw, data).catch(function (e) {
        // Si falla el logo (imagen), se reintenta sin él
        if (t.shop && t.shop.logo && opts.logo !== false) {
          var d2 = P.toEscPos(t, Object.assign({}, opts, { logo: false }));
          if (opts && opts.drawer) { d2.unshift(P.escBase64(CMD.drawer)); }
          return qz.print(cfgRaw, d2);
        }
        throw e;
      });
    });
  }

  /** Imprime por QZ; si falla, reconecta y lo intenta una vez más */
  P.qzPrint = function (t, opts) {
    opts = opts || {};
    return qzPrintOnce(t, opts).catch(function (e) {
      var first = errText(e);
      var reconnect = qz && qz.websocket && qz.websocket.isActive() ? qz.websocket.disconnect().catch(function () {}) : Promise.resolve();
      return reconnect.then(function () {
        return new Promise(function (r) { setTimeout(r, 500); });
      }).then(function () {
        return qzPrintOnce(t, opts);
      }).catch(function (e2) {
        var msg = errText(e2) === first ? first : first + ' / ' + errText(e2);
        if (/block|reject|denied|untrusted|not allowed/i.test(msg)) {
          msg += ' — QZ Tray ha bloqueado la impresión: le falta el certificado del TPV (override.crt) o alguien pulsó "Block" en su aviso. Revisa en QZ Tray > Advanced > Site Manager.';
        }
        qzSet(qz.websocket.isActive() ? 'on' : 'error', msg);
        throw new Error(msg);
      });
    });
  };

  /**
   * Abre el cajón: la orden va a la impresora de tickets (el cajón se conecta a ella), así que necesita QZ Tray.
   */
  P.openDrawer = function () {
    if (JTP.state.print.mode !== 'qz') {
      return Promise.reject(new Error('El cajón se abre a través de la impresora y eso solo es posible con QZ Tray. Actívalo en Módulos > TPV > Configurar > Impresión.'));
    }
    var send = function () {
      return P.qzPrinter().then(function (printer) {
        return qz.print(qz.configs.create(printer), [P.escBase64(CMD.drawer)]);
      });
    };
    return send().catch(function () {
      return new Promise(function (r) { setTimeout(r, 500); }).then(send);
    }).then(function () { return true; }, function (e) {
      throw new Error('No se pudo abrir el cajón: ' + errText(e));
    });
  };

  // Conexión al cargar el TPV (así la primera venta no espera a conectar) y reconexión si se cae
  var qzWatch = false;
  JTP.on('ready', function () {
    if (JTP.state.print.mode !== 'qz') { return; }
    P.qzConnect().catch(function () {});
    if (qzWatch) { return; }
    qzWatch = true;
    setInterval(function () {
      if (window.qz && !qz.websocket.isActive() && !qzReady && document.visibilityState === 'visible') {
        P.qzConnect().catch(function () {});
      }
    }, 30000);
  });

  // ================================================================== PDF (A4)

  /** ¿Hay impresora A4 configurada en la caja y se imprime con QZ Tray? */
  P.hasA4 = function () {
    return JTP.state.print.mode === 'qz' && !!JTP.state.print.printer_a4;
  };

  function pdfUrl(b64) {
    var bin = atob(b64);
    var bytes = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) { bytes[i] = bin.charCodeAt(i); }
    return URL.createObjectURL(new Blob([bytes], { type: 'application/pdf' }));
  }

  /**
   * Abre un PDF (base64) en una pestaña. win: pestaña abierta antes (los navegadores bloquean
   * window.open fuera del clic). Si no se puede abrir, se descarga.
   */
  P.openPdf = function (b64, name, win) {
    var url = pdfUrl(b64);
    if (win && !win.closed) {
      // Visor del navegador dentro de una página con título y enlace de descarga con su nombre
      var n = String(name || 'documento.pdf').replace(/[<>"&]/g, '');
      win.document.open();
      win.document.write('<!doctype html><html><head><meta charset="utf-8"><title>' + n + '</title>'
        + '<style>html,body{margin:0;height:100%;font-family:sans-serif}embed{display:block;width:100%;height:calc(100% - 34px);border:0}'
        + 'p{margin:0;height:34px;line-height:34px;padding:0 12px;background:#f2f4f7;font-size:14px}</style></head>'
        + '<body><p><a href="' + url + '" download="' + n + '">Descargar ' + n + '</a></p>'
        + '<embed src="' + url + '" type="application/pdf"></body></html>');
      win.document.close();
      return;
    }
    var w = window.open(url, '_blank');
    if (!w) {
      var a = document.createElement('a');
      a.href = url;
      a.download = name || 'documento.pdf';
      document.body.appendChild(a);
      a.click();
      a.remove();
    }
  };

  /**
   * Imprime un PDF en la impresora A4 de la caja (QZ Tray). Sin impresora A4 lo abre en el navegador.
   * Devuelve 'a4' o 'browser'.
   */
  P.printPdf = function (b64, opts) {
    opts = opts || {};
    if (!P.hasA4()) {
      P.openPdf(b64, opts.name, opts.win);
      return Promise.resolve('browser');
    }
    var name = JTP.state.print.printer_a4;
    return P.qzConnect().then(function () {
      return qz.printers.find(name);
    }).then(function (printer) {
      if (Array.isArray(printer)) { printer = printer[0]; }
      if (!printer) { throw new Error('QZ Tray no encuentra la impresora A4 "' + name + '"'); }
      var cfg = qz.configs.create(printer, { copies: opts.copies || 1, scaleContent: true, colorType: 'color' });
      return qz.print(cfg, [{ type: 'pixel', format: 'pdf', flavor: 'base64', data: b64 }]);
    }).then(function () {
      if (opts.win && !opts.win.closed) { opts.win.close(); }
      return 'a4';
    }, function (e) {
      throw new Error('No se pudo imprimir en la impresora A4: ' + errText(e));
    });
  };

  // ================================================================== navegador

  P.browserPrint = function (t, opts) {
    var logo = t && t.shop && t.shop.logo ? P.logoAssets(t.shop.logo) : Promise.resolve(null);
    return logo.then(function () { return browserPrintNow(t, opts); });
  };

  function browserPrintNow(t, opts) {
    var area = document.getElementById('jtp-print-area');
    area.innerHTML = P.renderHtml(t, opts);
    var imgs = area.querySelectorAll('img');
    var pending = Array.prototype.filter.call(imgs, function (i) { return !i.complete; });
    return new Promise(function (resolve) {
      var go = function () {
        setTimeout(function () {
          window.print();
          resolve(true);
        }, 60);
      };
      if (!pending.length) { return go(); }
      var n = pending.length;
      var done = function () { if (--n <= 0) { go(); } };
      pending.forEach(function (i) { i.addEventListener('load', done); i.addEventListener('error', done); });
      setTimeout(go, 1500);
    });
  }

  /**
   * Imprime un ticket con el método configurado.
   * opts: {gift: bool, drawer: bool, copies: n}
   */
  P.print = function (t, opts) {
    opts = opts || {};
    var ctx = { ticket: t, opts: opts, cancel: false };
    JTP.emit('ticket:beforePrint', ctx);
    if (ctx.cancel) { return Promise.resolve(false); }
    if (JTP.state.print.mode === 'qz') {
      return P.qzPrint(t, opts).then(function () { return true; }, function (e) {
        // En modo QZ no se abre el diálogo de Windows por sorpresa: se pregunta
        return P.qzFailed(t, opts, e);
      });
    }
    return P.browserPrint(t, opts);
  };

  /**
   * Fallo de QZ: ventana con el motivo y opciones (reintentar, imprimir con el navegador o no imprimir).
   * La interfaz puede sustituir esta función.
   */
  P.qzFailed = function (t, opts, e) {
    var UI = JTP.ui;
    if (!UI || !UI.modal || !JTP.h) {
      return P.browserPrint(t, opts);
    }
    var h = JTP.h;
    return new Promise(function (resolve) {
      var done = false;
      var m = UI.modal({
        title: 'No se pudo imprimir con QZ Tray',
        size: 'md',
        body: h('div', [
          h('p', errText(e)),
          h('p.jtp-muted', 'La venta está guardada. Puedes reintentar, imprimir con el navegador o reimprimirlo más tarde desde Ventas.')
        ]),
        onClose: function () { if (!done) { done = true; resolve(false); } },
        footer: [
          h('button.jtp-btn', { type: 'button', onclick: function () { done = true; m.close(); resolve(false); } }, 'No imprimir'),
          h('span.jtp-flex'),
          h('button.jtp-btn', { type: 'button', onclick: function () { done = true; m.close(); resolve(P.browserPrint(t, opts)); } }, 'Imprimir con el navegador'),
          h('button.jtp-btn.primary', { type: 'button', onclick: function () { done = true; m.close(); resolve(P.print(t, opts)); } }, 'Reintentar')
        ]
      });
    });
  };
})(window, window.JTP);
