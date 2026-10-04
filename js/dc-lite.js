/* RUE DE MIRÓ — tiny runtime for page interactivity (holes, sc-if, sc-for, onClick) */
(function () {
  var RE = /\{\{\s*([^}]+?)\s*\}\}/g;
  function get(scope, path) {
    path = path.trim();
    if (path === 'true') return true;
    if (path === 'false') return false;
    var parts = path.split('.'), v = scope;
    for (var i = 0; i < parts.length; i++) { if (v == null) return undefined; v = v[parts[i]]; }
    return v;
  }
  function str(v) { return v == null ? '' : String(v); }
  function interp(tpl, scope) {
    var m = /^\{\{\s*([^}]+?)\s*\}\}$/.exec(tpl);
    if (m) return get(scope, m[1]);
    return tpl.replace(RE, function (_, p) { return str(get(scope, p)); });
  }
  function exprOf(attr) { var m = /\{\{\s*([^}]+?)\s*\}\}/.exec(attr || ''); return m ? m[1] : null; }

  function compileChildren(parent) {
    var ups = [];
    Array.prototype.slice.call(parent.childNodes).forEach(function (n) { compile(n, ups); });
    return ups;
  }
  function instantiate(frag) {
    var clone = frag.cloneNode(true);
    var ups = compileChildren(clone);
    return { nodes: Array.prototype.slice.call(clone.childNodes), frag: clone,
      update: function (s) { ups.forEach(function (u) { u(s); }); } };
  }
  function takeFrag(el) {
    var f = document.createDocumentFragment();
    while (el.firstChild) f.appendChild(el.firstChild);
    return f;
  }
  function compile(n, ups) {
    if (n.nodeType === 3) {
      if (RE.test(n.data)) { RE.lastIndex = 0; var t = n.data; ups.push(function (s) { var v = str(interp(t, s)); if (n.data !== v) n.data = v; }); }
      RE.lastIndex = 0;
      return;
    }
    if (n.nodeType !== 1) return;
    var tag = n.tagName;
    if (tag === 'SC-IF' || tag === 'SC-FOR') {
      var anchor = document.createComment(tag);
      var isFor = tag === 'SC-FOR';
      var ex = exprOf(n.getAttribute(isFor ? 'list' : 'value'));
      var as = n.getAttribute('as') || 'item';
      var frag = takeFrag(n);
      n.parentNode.replaceChild(anchor, n);
      var insts = [];
      ups.push(function (s) {
        var v = get(s, ex);
        var list = isFor ? (v || []) : (v ? [null] : []);
        while (insts.length > list.length) insts.pop().nodes.forEach(function (x) { if (x.parentNode) x.parentNode.removeChild(x); });
        for (var i = 0; i < list.length; i++) {
          var sc = s;
          if (isFor) { sc = Object.create(s); sc[as] = list[i]; }
          if (!insts[i]) {
            var inst = instantiate(frag);
            inst.update(sc); // заполнить src/текст до вставки в страницу
            var last = i ? insts[i - 1].nodes[insts[i - 1].nodes.length - 1] : anchor;
            last.parentNode.insertBefore(inst.frag, last.nextSibling);
            insts[i] = inst;
          } else {
            insts[i].update(sc);
          }
        }
      });
      return;
    }
    Array.prototype.slice.call(n.attributes).forEach(function (a) {
      if (a.value.indexOf('{{') < 0) return;
      var name = a.name, tpl = a.value;
      if (name.indexOf('data-dc-') === 0) { n.removeAttribute(name); name = name.slice(8); }
      if (name === 'onclick') {
        n.removeAttribute('onclick');
        var cur = null;
        n.addEventListener('click', function (e) { if (typeof cur === 'function') cur(e); });
        ups.push(function (s) { cur = interp(tpl, s); });
        return;
      }
      ups.push(function (s) { var v = str(interp(tpl, s)); if (n.getAttribute(name) !== v) n.setAttribute(name, v); });
    });
    compileChildren(n).forEach(function (u) { ups.push(u); });
  }

  function DCLogic() { this.state = undefined; }
  DCLogic.prototype.setState = function (p) {
    this.state = Object.assign({}, this.state || {}, p);
    var self = this;
    if (!self._q) { self._q = true; Promise.resolve().then(function () { self._q = false; self._render(); }); }
  };

  function boot() {
    var root = document.getElementById('dc-root');
    var code = document.getElementById('dc-script');
    var Comp = new Function('DCLogic', code.textContent + '\n;return Component;')(DCLogic);
    var c = new Comp();
    var ups = compileChildren(root);
    c._render = function () { var vals = c.renderVals ? c.renderVals() : {}; ups.forEach(function (u) { u(vals); }); };
    c._render();
    root.removeAttribute('data-dc-pending');
    if (c.componentDidMount) c.componentDidMount();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
