/* Inferium: interacción de todas las páginas. Sin dependencias. Todo es mejora progresiva. */
(function () {
  'use strict';
  var reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  /* Aparición al hacer scroll, con respaldo a los 3,5 s */
  var rev = $$('.reveal');
  if ('IntersectionObserver' in window) {
    var io = new IntersectionObserver(function (es) { es.forEach(function (e) { if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); } }); }, { rootMargin: '0px 0px -8% 0px' });
    rev.forEach(function (el) { io.observe(el); });
  }
  setTimeout(function () { rev.forEach(function (el) { el.classList.add('in'); }); }, 3500);

  /* Brillo de cobre que sigue al ratón en las tarjetas de categoría */
  $$('.cat').forEach(function (el) {
    el.addEventListener('pointermove', function (e) { var r = el.getBoundingClientRect(); el.style.setProperty('--mx', (e.clientX - r.left) + 'px'); el.style.setProperty('--my', (e.clientY - r.top) + 'px'); });
  });

  /* Titular de portada: palabras que suben una a una */
  var h1 = $('.hero h1, .prox h1');
  if (h1 && !reduce) {
    var k = 0;
    (function split(node) {
      Array.prototype.slice.call(node.childNodes).forEach(function (n) {
        if (n.nodeType === 3) {
          var frag = document.createDocumentFragment();
          n.textContent.split(/(\s+)/).forEach(function (w) {
            if (!w || /^\s+$/.test(w)) { frag.appendChild(document.createTextNode(w)); return; }
            var s = document.createElement('span'); s.className = 'ch'; s.style.animationDelay = (k++ * 35) + 'ms'; s.textContent = w; frag.appendChild(s);
          });
          n.parentNode.replaceChild(frag, n);
        } else if (n.nodeType === 1) split(n);
      });
    })(h1);
  }

  /* Cálculos (los mismos que en inferium.php) */
  function vramNecesaria(m, bits) { return m.fijo ? (bits >= 16 ? m.fijo * 1.6 : m.fijo) : m.params * bits / 8 * 1.2; }
  function gb(n) { return n.toFixed(n < 10 ? 1 : 0).replace('.', ',') + ' GB'; }
  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

  /* Calculadora de VRAM */
  $$('[data-calc]').forEach(function (root) {
    var d = JSON.parse($('[data-datos]', root).textContent);
    var range = $('[data-range]', root), items = $$('.modelo', root), reco = $('[data-reco]', root), bits = 4.5;
    function pinta() {
      var v = d.pasos[+range.value], caben = 0;
      $('[data-v]', root).textContent = v;
      range.style.setProperty('--p', (+range.value / (d.pasos.length - 1) * 100) + '%');
      d.modelos.forEach(function (m, i) {
        var n = vramNecesaria(m, bits), ok = n <= v, el = items[i];
        if (ok) caben++;
        el.className = 'modelo ' + (ok ? 'si' : 'no');
        $('.tag', el).textContent = ok ? 'cabe' : 'no cabe';
        $('.need', el).textContent = gb(n);
        $('.meter i', el).style.setProperty('--w', Math.min(100, n / v * 100) + '%');
      });
      var r = d.productos.filter(function (p) { return p.vram >= v; }).sort(function (a, b) { return a.vram - b.vram || b.anchoBanda - a.anchoBanda; })[0];
      reco.innerHTML = 'Con ' + v + ' GB corres <b>' + caben + ' de ' + d.modelos.length + '</b> modelos.' + (r ? ' El equipo más ajustado con esa memoria: <a href="' + r.url + '">' + esc(r.nombre) + '</a>.' : '');
    }
    range.addEventListener('input', pinta);
    $$('[data-bits]', root).forEach(function (b) {
      b.addEventListener('click', function () {
        $$('[data-bits]', root).forEach(function (x) { x.setAttribute('aria-pressed', 'false'); });
        b.setAttribute('aria-pressed', 'true'); bits = +b.dataset.bits; pinta();
      });
    });
    pinta();
  });

  /* Comparador */
  var cmp = $('[data-comparador]');
  if (cmp) {
    var c = JSON.parse(cmp.textContent), sels = $$('[data-sel]'), tabla = $('[data-tabla]');
    var q = new URLSearchParams(location.search).get('c');
    if (q) q.split(',').slice(0, 4).forEach(function (s, i) { if (c.datos.some(function (x) { return x.slug === s; })) sels[i].value = s; });
    var pintaCmp = function () {
      var el = sels.map(function (s) { return c.datos.filter(function (x) { return x.slug === s.value; })[0] || null; });
      var head = tabla.tHead.rows[0].cells, rows = tabla.tBodies[0].rows;
      el.forEach(function (x, i) { head[i + 1].innerHTML = x ? '<a href="' + x.ficha + '">' + esc(x.nombre) + '</a>' : ''; });
      c.meta.forEach(function (f, r) {
        var nums = el.filter(Boolean).map(function (x) { return x.celdas[r].n; }).filter(function (n) { return n !== null; });
        var best = f[1] === 'max' ? Math.max.apply(null, nums) : f[1] === 'min' ? Math.min.apply(null, nums) : null;
        el.forEach(function (x, i) {
          var td = rows[r].cells[i + 1];
          td.textContent = x ? x.celdas[r].t : '';
          td.classList.toggle('mejor', !!x && best !== null && nums.length > 1 && x.celdas[r].n === best);
        });
      });
      el.forEach(function (x, i) { rows[c.meta.length].cells[i + 1].innerHTML = x ? '<a class="btn btn-copper btn-sm" href="' + x.url + '" target="_blank" rel="' + c.rel + '">Ver en Amazon</a>' : ''; });
      var sel = el.filter(Boolean).map(function (x) { return x.slug; }).join(',');
      try { history.replaceState(null, '', sel ? '?c=' + sel : location.pathname); } catch (e) {}
    };
    sels.forEach(function (s) { s.addEventListener('change', pintaCmp); });
    pintaCmp();
  }

  /* Chip 3D de la landing: se inclina con el ratón */
  var g3 = $('.gpu3d');
  if (g3 && !reduce) {
    addEventListener('pointermove', function (e) {
      var x = e.clientX / innerWidth - 0.5, y = e.clientY / innerHeight - 0.5;
      g3.style.setProperty('--rx', (58 - y * 18) + 'deg'); g3.style.setProperty('--rz', (-34 + x * 28) + 'deg');
    });
  }

  /* Fondo: un die de GPU vivo en cobre. Se pausa fuera de pantalla. */
  var cv = $('#die');
  if (!cv || !cv.getContext) return;
  var ctx = cv.getContext('2d'), W = 0, H = 0, cells = [], packets = [], mouse = { x: -999, y: -999 }, C = 22, t0 = performance.now(), visible = true;
  var stops = [[20, 20, 20], [70, 34, 14], [201, 98, 44], [233, 138, 79], [255, 228, 200]];
  function heat(v) { v = Math.max(0, Math.min(1, v)) * (stops.length - 1); var i = Math.floor(v), f = v - i, a = stops[i], b = stops[Math.min(i + 1, stops.length - 1)]; return 'rgb(' + ((a[0] + (b[0] - a[0]) * f) | 0) + ',' + ((a[1] + (b[1] - a[1]) * f) | 0) + ',' + ((a[2] + (b[2] - a[2]) * f) | 0) + ')'; }
  function resize() {
    var dpr = Math.min(devicePixelRatio || 1, 2), r = cv.getBoundingClientRect();
    W = r.width; H = r.height; cv.width = W * dpr; cv.height = H * dpr; ctx.setTransform(dpr, 0, 0, dpr, 0, 0); cells = [];
    for (var y = 0; y < Math.ceil(H / C); y++) for (var x = 0; x < Math.ceil(W / C); x++) {
      var gx = x % 9, gy = y % 7; if (gx === 8 || gy === 6) continue;
      cells.push({ x: x, y: y, v: Math.random() * 0.12, tg: 0, ph: Math.random() * 6.28, core: (gx + gy) % 3 === 0 });
    }
  }
  function frame(now) {
    var t = (now - t0) / 1000;
    ctx.fillStyle = '#141414'; ctx.fillRect(0, 0, W, H);
    var wx = (Math.sin(t * 0.35) * 0.5 + 0.5) * W, wy = (Math.cos(t * 0.27) * 0.5 + 0.5) * H;
    for (var i = 0; i < cells.length; i++) {
      var c = cells[i], px = c.x * C + C / 2, py = c.y * C + C / 2;
      var target = 0.06 + 0.05 * Math.sin(t * 2 + c.ph);
      target += Math.max(0, 1 - Math.hypot(px - wx, py - wy) / (Math.min(W, H) * 0.55)) * 0.5;
      target += Math.max(0, 1 - Math.hypot(px - mouse.x, py - mouse.y) / 170) * 0.9;
      if (Math.random() < 0.0008) c.tg = 1;
      c.tg *= 0.96; target += c.tg * 0.7; c.v += (target - c.v) * 0.08;
      ctx.globalAlpha = 0.3 + c.v * 0.7; ctx.fillStyle = heat(c.v * (c.core ? 1 : 0.8));
      ctx.fillRect(c.x * C + 2, c.y * C + 2, C - 4, C - 4);
      if (c.core && c.v > 0.5) { ctx.globalAlpha = (c.v - 0.5) * 0.9; ctx.fillStyle = '#ffe4c8'; ctx.fillRect(px - 2, py - 2, 4, 4); }
    }
    ctx.globalAlpha = 1;
    if (Math.random() < 0.3) {
      var horiz = Math.random() < 0.6;
      packets.push({ horiz: horiz, lane: horiz ? Math.floor(Math.random() * (H / C / 7)) * 7 + 6 : Math.floor(Math.random() * (W / C / 9)) * 9 + 8, p: -2, s: 0.35 + Math.random() * 0.9, len: 3 + Math.random() * 8 });
    }
    ctx.lineCap = 'round'; ctx.shadowColor = '#e98a4f'; ctx.shadowBlur = 12;
    packets = packets.filter(function (p) {
      p.p += p.s; if (p.p - p.len > (p.horiz ? W : H) / C) return false;
      var a = (p.p - p.len) * C, b = p.p * C, l = p.lane * C + C / 2;
      var g = p.horiz ? ctx.createLinearGradient(a, 0, b, 0) : ctx.createLinearGradient(0, a, 0, b);
      g.addColorStop(0, 'rgba(201,98,44,0)'); g.addColorStop(0.7, 'rgba(233,138,79,.85)'); g.addColorStop(1, '#ffe4c8');
      ctx.strokeStyle = g; ctx.lineWidth = 2; ctx.beginPath();
      if (p.horiz) { ctx.moveTo(a, l); ctx.lineTo(b, l); } else { ctx.moveTo(l, a); ctx.lineTo(l, b); }
      ctx.stroke(); return true;
    });
    ctx.shadowBlur = 0;
    if (!reduce && visible) requestAnimationFrame(frame);
  }
  addEventListener('resize', resize);
  var host = cv.parentElement;
  host.addEventListener('pointermove', function (e) { var r = cv.getBoundingClientRect(); mouse.x = e.clientX - r.left; mouse.y = e.clientY - r.top; });
  host.addEventListener('pointerleave', function () { mouse.x = mouse.y = -999; });
  if ('IntersectionObserver' in window) new IntersectionObserver(function (es) { var was = visible; visible = es[0].isIntersecting; if (visible && !was && !reduce) requestAnimationFrame(frame); }).observe(host);
  resize(); requestAnimationFrame(frame);
})();

/* Medición: eventos para Google Analytics 4. Solo envían algo si el visitante aceptó las cookies (existe window.gtag). */
(function () {
  'use strict';
  function ev(nombre, datos) { if (typeof window.gtag === 'function') window.gtag('event', nombre, datos || {}); }
  function zona(el) {
    if (el.closest('header')) return 'menu';
    if (el.closest('footer')) return 'pie';
    var s = el.closest('section, .surface');
    var h = s && s.querySelector('h1, h2');
    return h ? h.textContent.trim().slice(0, 60) : 'contenido';
  }
  document.addEventListener('click', function (e) {
    var a = e.target.closest('a, button, summary');
    if (!a) return;
    var txt = (a.textContent || '').trim().slice(0, 60);
    if (a.tagName === 'A' && /amazon\./.test(a.href)) {
      var m = a.href.match(/\/dp\/([A-Z0-9]{10})/);
      ev('clic_amazon', { producto: a.getAttribute('data-prod') || txt, asin: m ? m[1] : 'busqueda', zona: zona(a) });
    } else if (a.tagName === 'A') {
      var interno = a.host === location.host;
      ev(interno ? 'clic_enlace' : 'clic_salida', { destino: interno ? a.pathname : a.href, texto: txt, zona: zona(a) });
    } else if (a.tagName === 'SUMMARY') {
      ev('abre_pregunta', { pregunta: txt });
    } else {
      ev('clic_boton', { texto: txt, zona: zona(a) });
    }
  }, true);
  document.addEventListener('change', function (e) {
    var t = e.target;
    if (t.matches && t.matches('[data-sel]')) ev('usa_comparador', { equipo: t.value || 'ninguno' });
    else if (t.closest && t.closest('[data-calc]')) ev('usa_calculadora', { valor: String(t.value) });
  }, true);
  var vistos = {};
  function prof() {
    var d = document.documentElement, total = d.scrollHeight - innerHeight;
    if (total <= 0) return;
    var p = Math.round((scrollY / total) * 100);
    [25, 50, 75, 100].forEach(function (n) { if (p >= n - 1 && !vistos[n]) { vistos[n] = 1; ev('profundidad_scroll', { porcentaje: n }); } });
  }
  addEventListener('scroll', prof, { passive: true });
  var t0 = Date.now();
  addEventListener('pagehide', function () { ev('tiempo_en_pagina', { segundos: Math.round((Date.now() - t0) / 1000) }); });
})();
(function () {
  var d = document.querySelector('.toc details');
  if (d && window.matchMedia && matchMedia('(min-width: 900px)').matches) d.open = true;
})();
(function () {
  var cs = document.querySelectorAll('[data-cuenta]');
  if (!cs.length || !('IntersectionObserver' in window) || matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  var io = new IntersectionObserver(function (es) {
    es.forEach(function (e) {
      if (!e.isIntersecting) return; io.unobserve(e.target);
      var el = e.target, fin = +el.dataset.cuenta, t0 = performance.now();
      (function paso(t) { var p = Math.min(1, (t - t0) / 1200); el.textContent = Math.round(fin * (1 - Math.pow(1 - p, 3))); if (p < 1) requestAnimationFrame(paso); })(t0);
    });
  }, { threshold: 0.6 });
  cs.forEach(function (c) { io.observe(c); });
})();

(function () {
  function mira() { document.querySelectorAll('.tabla-wrap').forEach(function (w) { w.classList.toggle('desliza', w.scrollWidth > w.clientWidth + 4); }); }
  mira(); addEventListener('resize', mira);
})();
