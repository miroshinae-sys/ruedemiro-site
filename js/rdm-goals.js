/* RUE DE MIRÓ — цели Яндекс Метрики (счётчик 104468544). Подключается на всех страницах после dc-lite.js.
   Метрика загружается только после согласия на cookies; до этого цели копятся в очереди. */
(function () {
  var ID = 104468544, Q = [];
  function send() { if (!window.ym) return; while (Q.length) { var x = Q.shift(); try { window.ym(ID, 'reachGoal', x[0], x[1] || {}); } catch (e) {} } }
  function goal(name, params) { Q.push([name, params]); send(); }
  var iv = setInterval(function () { send(); }, 1500);
  window.addEventListener('pagehide', function () { clearInterval(iv); });

  // 1. Добавление в корзину. Кнопка набора добавляет несколько масел за одно нажатие,
  //    поэтому всё, что добавлено за один такт, считаем одним действием: 2+ разных масла — набор, иначе — масло.
  var tick = null;
  function flush() { var ids = Object.keys(tick); tick = null; goal('add_to_cart'); goal(ids.length >= 2 ? 'add_set' : 'add_oil', { items: ids.join(',') }); }
  try {
    var orig = Storage.prototype.setItem;
    Storage.prototype.setItem = function (k, v) {
      if (k === 'rdm-cart' && this === window.localStorage) {
        var before = parse(this.getItem(k)), after = parse(v);
        for (var id in after) if ((+after[id] || 0) > (+before[id] || 0)) { if (!tick) { tick = {}; setTimeout(flush, 0); } tick[id] = 1; }
      }
      return orig.apply(this, arguments);
    };
  } catch (e) {}
  function parse(s) { try { return JSON.parse(s || '{}') || {}; } catch (e) { return {}; } }

  // 2. Ответы магазина: оформление, сертификат, подписка, промокод, оплата
  if (window.fetch) {
    var f = window.fetch;
    window.fetch = function (url, opt) {
      var u = String(url && url.url || url || ''), p = f.apply(this, arguments);
      if (u.indexOf('/api/') === -1) return p;
      p.then(function (r) { return r.clone().json(); }).then(function (j) {
        if (!j || !j.ok) return;
        if (u.indexOf('/api/order.php') > -1) goal('checkout');
        else if (u.indexOf('/api/cert.php') > -1) goal('cert_order');
        else if (u.indexOf('/api/subscribe.php') > -1 && j.state === 'new') goal('subscribe');
        else if (u.indexOf('/api/promo.php') > -1) goal('promo_applied', { code: j.code });
        else if (u.indexOf('/api/status.php') > -1 && j.status === 'paid') {
          var key = 'rdm-paid-' + j.number, seen = null;
          try { seen = localStorage.getItem(key); } catch (e) {}
          if (seen) return;
          try { localStorage.setItem(key, '1'); } catch (e) {}
          var price = { order_price: +j.total || 0, currency: 'RUB' };
          goal('order_paid', price);
          goal(j.kind === 'cert' ? 'paid_cert' : 'paid_goods', price);
        }
      }).catch(function () {});
      return p;
    };
  }
})();
