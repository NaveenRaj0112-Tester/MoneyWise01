/**
 * Money Wise — mobile app (Capacitor).
 * Mobile-first SPA talking to the MoneyWise PHP REST API via bearer tokens.
 * Mirror of the desktop app's features: dashboard, income/expense, statistics,
 * persistent Clear, admin management, salary, and client-side PDF reports.
 */
(function () {
  'use strict';

  /* ── helpers ────────────────────────────────────────────────────────── */
  var MONTHS = ['January','February','March','April','May','June','July','August','September','October','November','December'];
  var CATS = {
    income:  [['Salary','💰'],['Freelance','🧑‍💻'],['Business','🏪'],['Bonus','🎁'],['Investments','📈'],['Interest','🏦'],['Cash','💵'],['Other','➕']],
    expense: [['Groceries','🍎'],['Food','🍔'],['Rent','🏠'],['Bills','💡'],['Transport','🚗'],['Shopping','🛍️'],['Entertainment','🎬'],['Health','🏥'],['Education','🎓'],['Other','➕']]
  };
  var S = { user: null, txs: [], years: [], cleared: [], balanceCleared: false, screen: 'home' };

  function $(s) { return document.querySelector(s); }
  function pad2(n) { return String(n).padStart(2, '0'); }
  function todayStr() { var d = new Date(); return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate()); }
  function greet() { var h = new Date().getHours(); return h < 12 ? 'Good Morning' : h < 17 ? 'Good Afternoon' : 'Good Evening'; }
  function fmtDate(s) { if (!s) return '—'; var p = s.split('-'); return pad2(p[2]) + ' ' + MONTHS[+p[1] - 1].slice(0, 3) + ' ' + p[0]; }
  function fmtMoney(n) { return '₹' + Math.abs(+n || 0).toLocaleString('en-IN', { maximumFractionDigits: 2 }); }
  function sumIn(list) { return list.reduce(function (s, t) { return s + (+t.amount || 0); }, 0); }
  function sumOf(list, type) { return sumIn(list.filter(function (t) { return t.type === type; })); }
  function entries(o) { return Object.keys(o || {}).map(function (k) { return [k, o[k]]; }); }
  function initial(name) { return (name || '?').trim().charAt(0).toUpperCase(); }

  function h(tag, attrs) {
    var el = document.createElement(tag);
    var kids = Array.prototype.slice.call(arguments, 2);
    if (attrs) for (var k in attrs) {
      var v = attrs[k];
      if (v == null) continue;
      if (k === 'class') el.className = v;
      else if (k === 'style') el.style.cssText = v;
      else if (k === 'html') el.innerHTML = v;
      else if (k === 'value') el.value = v;
      else if (k === 'checked') el.checked = v;
      else if (k === 'selected') el.selected = !!v;
      else if (k === 'disabled') el.disabled = !!v;
      else if (k.slice(0, 2) === 'on') el.addEventListener(k.slice(2), v);
      else el.setAttribute(k, v);
    }
    kids.forEach(function (kid) {
      if (kid == null || kid === false) return;
      if (Array.isArray(kid)) { kid.forEach(function (k) { appendOne(k); }); return; }
      appendOne(kid);
    });
    function appendOne(kid) {
      el.appendChild(kid.nodeType ? kid : document.createTextNode(String(kid)));
    }
    return el;
  }

  /* ── toast + modal ──────────────────────────────────────────────────── */
  function toast(msg, type) {
    var t = h('div', { class: 'toast ' + (type || '') }, msg);
    $('#toast-root').appendChild(t);
    setTimeout(function () { t.classList.add('hidden'); setTimeout(function () { t.remove(); }, 300); }, 2600);
  }
  function closeModal() { $('#modal-root').innerHTML = ''; }
  function openModal(bodyEl, centered) {
    var ov = h('div', { class: 'modal-overlay' + (centered ? ' center' : ''), onclick: function (e) { if (e.target === ov) closeModal(); } });
    ov.appendChild(h('div', { class: 'modal' }, bodyEl));
    $('#modal-root').appendChild(ov);
  }
  function confirmDlg(title, message, okLabel, danger) {
    return new Promise(function (resolve) {
      var m = h('div', {},
        h('div', { class: 'modal-hdr' }, h('h3', {}, title), h('button', { class: 'modal-x', onclick: function () { closeModal(); resolve(false); } }, '✕')),
        h('p', { style: 'font-size:13px;color:#4b5563;line-height:1.6;margin-bottom:6px' }, message),
        h('div', { style: 'display:flex;gap:10px;margin-top:14px' },
          h('button', { class: 'btn btn-plain btn-sm', style: 'flex:1;margin-top:0', onclick: function () { closeModal(); resolve(false); } }, 'Cancel'),
          h('button', { class: 'btn btn-sm ' + (danger ? 'btn-d' : 'btn-p'), style: 'flex:1;margin-top:0', onclick: function () { closeModal(); resolve(true); } }, okLabel || 'Confirm'))
      );
      openModal(m);
    });
  }
  function spinner(label) {
    return h('div', { class: 'loading' }, h('div', { class: 'spinner' }), label || 'Loading…');
  }

  /* ── safe async runner ──────────────────────────────────────────────── */
  async function safe(fn) {
    try { await fn(); }
    catch (e) {
      if (e && e.unauthorized) { MWAPI.setUser(null); renderAuth(); toast('Session expired. Please sign in again.', 'err'); }
      else if (e && e.offline) { toast('You are offline. Showing the last saved data.', 'err'); }
      else toast((e && e.message) || 'Something went wrong.', 'err');
    }
  }

  /* ── auth screen ────────────────────────────────────────────────────── */
  function renderAuth() {
    var mode = { v: 'login' };
    function form() {
      var errEl = h('div');
      function showErr(msg) { errEl.innerHTML = ''; errEl.appendChild(h('div', { class: 'al al-e' }, msg)); }
      var emailI = h('input', { class: 'inp', type: 'email', value: '', placeholder: 'you@example.com' });
      var passI = h('input', { class: 'inp', type: 'password', value: '', placeholder: '••••••••' });
      var nameI = null, confirmI = null;
      var fgName = h('div', { class: 'fg' }, h('label', {}, 'Full Name'), h('div', { class: 'iw' }, nameI = h('input', { class: 'inp', type: 'text', value: '', placeholder: 'Your name', maxlength: 100 })));
      var fgConfirm = h('div', { class: 'fg' }, h('label', {}, 'Confirm Password'), h('div', { class: 'iw' }, confirmI = h('input', { class: 'inp', type: 'password', value: '', placeholder: 'Repeat password' })));
      if (mode.v === 'login') { fgName.classList.add('hidden'); fgConfirm.classList.add('hidden'); }
      var btn = h('button', { class: 'btn btn-p' }, mode.v === 'login' ? 'Sign In' : 'Create Account');
      btn.addEventListener('click', async function () {
        btn.disabled = true;
        try {
          if (mode.v === 'login') {
            var r = await MWAPI.login(emailI.value.trim(), passI.value);
            S.user = r.user; renderShell();
          } else {
            if (nameI.value.trim() === '') return showErr('Please enter your name.'), btn.disabled = false, void 0;
            if (passI.value !== confirmI.value) return showErr('Passwords do not match.'), btn.disabled = false, void 0;
            var r2 = await MWAPI.register(nameI.value.trim(), emailI.value.trim(), passI.value);
            S.user = r2.user; renderShell();
          }
        } catch (e) {
          if (e && e.unauthorized) { /* ignore */ }
          showErr((e && e.message) || 'Could not connect. Check your connection.');
          btn.disabled = false;
        }
      });
      var card = h('div', { class: 'auth-card' },
        h('div', { class: 'auth-logo' }, h('div', { class: 'logo-icon' }, h('img', { class: 'logo-img', src: 'img/logo.png', alt: '' })), h('h1', {}, 'Money <span>Wise</span>')),
        h('div', { class: 'auth-title' }, h('h2', {}, mode.v === 'login' ? 'Welcome Back' : 'Create Account'),
          h('p', {}, mode.v === 'login' ? 'Sign in to manage your money' : 'Track your income & expenses')),
        errEl,
        fgName,
        h('div', { class: 'fg' }, h('label', {}, 'Email'), h('div', { class: 'iw' }, emailI)),
        h('div', { class: 'fg' }, h('label', {}, 'Password'), h('div', { class: 'iw' }, passI)),
        fgConfirm,
        btn,
        h('div', { class: 'auth-switch' }, mode.v === 'login' ? 'New here? ' : 'Already have an account? ',
          h('button', {}, mode.v === 'login' ? 'Create an account' : 'Sign in'),
          ''),
        h('div', { class: 'demo-tip' }, 'Demo — Admin: Admin0112@gmail.com / Admin@0112 • Staff: priya@example.com / pass123')
      );
      card.querySelector('.auth-switch button').addEventListener('click', function () {
        mode.v = mode.v === 'login' ? 'register' : 'login';
        var root = $('#app'); root.innerHTML = ''; root.appendChild(wrap(card2()));
      });
      function card2() { return form(); }
      return card;
    }
    var card = form();
    $('#app').innerHTML = '';
    $('#app').appendChild(wrap(card));
    function wrap(c) { return h('div', { class: 'auth-wrap' }, c); }
  }

  /* ── shell + bottom nav ─────────────────────────────────────────────── */
  function renderShell() {
    $('#app').innerHTML = '';
    var screen = h('div', { id: 'screen', class: 'app fade' });
    var tabs = [
      ['home', '🏠', 'Home'], ['tx', '📋', 'Transactions'], ['add', '+', ''],
      ['status', '📊', 'Status']
    ];
    if (S.user && S.user.is_admin === 1) tabs.push(['admin', '👥', 'Admin']);
    tabs.push(['profile', '👤', 'Profile']);
    var nav = h('div', { class: 'bnav' });
    tabs.forEach(function (t) {
      if (t[0] === 'add') {
        nav.appendChild(h('div', { class: 'ni nc', onclick: function () { openTxModal(null); } },
          h('div', { class: 'ncb' }, t[1]), h('span', {}, 'Add')));
      } else {
        nav.appendChild(h('button', { class: 'ni', 'data-tab': t[0], onclick: function () { showScreen(t[0]); } },
          h('span', { class: 'ic' }, t[1]), h('span', {}, t[2])));
      }
    });
    $('#app').appendChild(screen);
    $('#app').appendChild(nav);
    showScreen('home');
  }
  function showScreen(name) {
    S.screen = name;
    var screen = $('#screen');
    document.querySelectorAll('.bnav .ni').forEach(function (b) { b.classList.toggle('act', b.dataset.tab === name); });
    if (name === 'home') return safe(renderHome);
    if (name === 'tx') return safe(renderTx);
    if (name === 'status') return safe(renderStatus);
    if (name === 'admin') return safe(renderAdmin);
    if (name === 'profile') return safe(renderProfile);
  }
  function setScreen(el) { var s = $('#screen'); s.innerHTML = ''; s.appendChild(el); }

  function header(title, onBack) {
    return h('div', { class: 'page-hdr' },
      onBack ? h('button', { class: 'back-btn', onclick: onBack }, '←') : null,
      h('h2', {}, title));
  }

  /* ── HOME ───────────────────────────────────────────────────────────── */
  async function renderHome() {
    var view = h('div', { class: 'page' }, header('Dashboard'), spinner('Loading your dashboard…'));
    setScreen(view);
    S.txs = (await MWAPI.txList({ type: 'all' })).transactions || [];
    var clear = await MWAPI.clearGet();
    S.cleared = clear.cleared || [];
    S.balanceCleared = !!clear.balance_cleared;

    var income = sumOf(S.txs, 'income'), expense = sumOf(S.txs, 'expense'), balance = income - expense;
    var tday = todayStr();
    var todayTxs = S.txs.filter(function (t) { return t.date === tday; });
    var todayExp = sumOf(todayTxs, 'expense');
    var m = new Date().getMonth() + 1, y = new Date().getFullYear();
    var monthExpense = sumIn(S.txs.filter(function (t) { return t.type === 'expense' && t.date.slice(0, 4) === String(y) && +t.date.slice(5, 7) === m; }));

    var balAmt = S.balanceCleared ? '••••' : fmtMoney(balance);
    var balHint = S.balanceCleared
      ? 'Balance erased. Add a new transaction to bring it back.'
      : income + expense === 0 ? 'Add your first income or expense to see your balance.' : 'Total balance (all income − expense)';

    var dash = h('div', { class: 'dash-hdr' },
      h('div', { class: 'greeting' }, greet() + ' 👋'),
      h('div', { class: 'uname' }, 'Hello, ' + (S.user.name || '') + ' 👑'),
      h('div', { class: 'bal-row' },
        h('div', { style: 'flex:1' }, h('div', { class: 'bal-lbl' }, 'Total Balance'), h('div', { class: 'bal-amt' }, balAmt)),
        h('button', { class: 'bal-erase', onclick: async function () {
          var msg = S.balanceCleared
            ? 'Show your balance again?'
            : 'Hide the displayed balance? Your records are never deleted.';
          if (await confirmDlg('Balance', msg, S.balanceCleared ? 'Show' : 'Hide', false)) {
            await safe(async function () { await MWAPI.clearBalance(!S.balanceCleared); S.balanceCleared = !S.balanceCleared; renderHome(); });
          }
        } }, S.balanceCleared ? '👁️' : '🧽')),
      h('div', { class: 'bal-hint' }, balHint));

    view.innerHTML = '';
    view.appendChild(h('div', { class: 'page' },
      header('Dashboard'),
      dash,
      h('div', { class: 'sr' },
        h('div', { class: 'sc' }, h('div', { class: 'si si-in' }, '📈'), h('div', {}, h('div', { class: 'si-lbl' }, 'Total Income'), h('div', { class: 'si-val' }, fmtMoney(income)))),
        h('div', { class: 'sc' }, h('div', { class: 'si si-ex' }, '📉'), h('div', {}, h('div', { class: 'si-lbl' }, 'Total Expense'), h('div', { class: 'si-val' }, fmtMoney(expense))))),
      h('div', { class: 'sr' },
        h('div', { class: 'sc' }, h('div', { class: 'si si-ex' }, '📅'), h('div', {}, h('div', { class: 'si-lbl' }, 'Today'), h('div', { class: 'si-val' }, fmtMoney(todayExp)))),
        h('div', { class: 'sc' }, h('div', { class: 'si si-ex' }, '🗓️'), h('div', {}, h('div', { class: 'si-lbl' }, MONTHS[m - 1]), h('div', { class: 'si-val' }, fmtMoney(monthExpense))))),
      h('div', { class: 'qb' },
        h('button', { class: 'btn btn-d', onclick: function () { openTxModal(null, 'expense'); } }, '➕ Add Expense'),
        h('button', { class: 'btn btn-p', onclick: function () { openTxModal(null, 'income'); } }, '➕ Add Income')),
      h('div', { class: 'sh' }, h('h3', {}, "Today's Transactions"), h('span', { class: 'sh-sub' }, todayTxs.length + (todayTxs.length === 1 ? ' entry' : ' entries'))),
      todayTxs.length ? txList(todayTxs, { recent: true }) : h('div', { class: 'empty' }, h('div', { class: 'eic' }, '🧾'), h('p', {}, 'No transactions today yet. Tap “Add Expense” or “Add Income” to get started.'))
    ));
  }

  /* ── transaction list + add/edit modal ─────────────────────────────── */
  function txList(list, o) {
    o = o || {};
    var shown = list.filter(function (t) { return o.recent ? true : S.cleared.indexOf(t.id) === -1; });
    if (!shown.length) return h('div', { class: 'empty' }, h('div', { class: 'eic' }, '📭'), h('p', {}, 'No entries here.'));
    return h('div', { class: 'tx-list' }, shown.map(function (t) {
      var icon = t.type === 'income' ? '📈' : '💸';
      return h('div', { class: 'tx' },
        h('div', { class: 'tx-ic ' + t.type }, icon),
        h('div', { class: 'tx-bd' },
          h('div', { class: 'tx-cat' }, t.cat || '—'),
          h('div', { class: 'tx-dt' }, fmtDate(t.date)),
          t.notes ? h('div', { class: 'tx-nt' }, t.notes) : null),
        h('div', { class: 'tx-am ' + t.type }, (t.type === 'income' ? '+' : '−') + fmtMoney(t.amount)),
        o.noActions ? null : h('div', { class: 'tx-act' },
          h('button', { class: 'tx-edit', title: 'Edit', onclick: function () { openTxModal(t); } }, '✏️'),
          h('button', { class: 'tx-del', title: 'Delete', onclick: function () { delTx(t.id); } }, '🗑️'))
      );
    }));
  }

  function openTxModal(existing, presetType) {
    var isEdit = !!existing;
    var type = isEdit ? existing.type : (presetType || 'expense');
    var typeBtns = [];
    function typeTog() {
      typeBtns.forEach(function (b) { b.classList.toggle('act', b.dataset.v === type); });
    }
    var amtI = h('input', { class: 'amt-inp', type: 'number', min: '0', step: '0.01', inputmode: 'decimal', value: isEdit ? existing.amount : '' });
    var catI = h('input', { class: 'inp', type: 'text', placeholder: 'Type or pick a category', maxlength: 50, value: isEdit ? existing.cat : '' });
    var chipsEl = h('div', { class: 'chips' });
    function drawChips() {
      chipsEl.innerHTML = '';
      CATS[type].forEach(function (c) {
        var chip = h('button', { class: 'chip' + (catI.value === c[0] ? ' sel' : ''), type: 'button', onclick: function () { catI.value = c[0]; drawChips(); } }, c[1] + ' ' + c[0]);
        chipsEl.appendChild(chip);
      });
    }
    drawChips();
    var dateI = h('input', { class: 'inp', type: 'date', value: isEdit ? existing.date : todayStr() });
    var notesI = h('textarea', { class: 'inp', placeholder: 'Notes (optional)', rows: 2, maxlength: 255 }, isEdit ? existing.notes || '' : '');
    var errEl = h('div');

    var m = h('div', {},
      h('div', { class: 'modal-hdr' }, h('h3', {}, isEdit ? 'Edit ' + (existing.type === 'income' ? 'Income' : 'Expense') : 'Add ' + (type === 'income' ? 'Income' : 'Expense')), h('button', { class: 'modal-x', onclick: closeModal }, '✕')),
      h('div', { class: 'tab-tog', style: 'margin-bottom:6px' },
        typeBtns = [h('button', { 'data-v': 'income', onclick: function () { type = 'income'; typeTog(); drawChips(); } }, '📈 Income'),
          h('button', { 'data-v': 'expense', onclick: function () { type = 'expense'; typeTog(); drawChips(); } }, '💸 Expense')]),
      errEl,
      h('div', { class: 'amt-disp' }, h('span', { class: 'amt-cur' }, '₹'), amtI),
      h('div', { class: 'fg' }, h('label', {}, 'Category'), catI, chipsEl),
      h('div', { class: 'frow' },
        h('div', { class: 'fg' }, h('label', {}, 'Date'), dateI)),
      h('div', { class: 'fg' }, h('label', {}, 'Notes'), notesI),
      h('button', { class: 'btn btn-p', onclick: save }, isEdit ? 'Save Changes' : 'Add ' + (type === 'income' ? 'Income' : 'Expense'))
    );
    typeTog();
    openModal(m);

    async function save() {
      var amt = parseFloat(amtI.value);
      if (!amtI.value || isNaN(amt) || amt <= 0) { errEl.innerHTML = ''; errEl.appendChild(h('div', { class: 'al al-e' }, 'Please enter a valid amount greater than 0.')); return; }
      var cat = catI.value.trim();
      if (!cat) { errEl.innerHTML = ''; errEl.appendChild(h('div', { class: 'al al-e' }, 'Please select a category.')); return; }
      var date = dateI.value;
      if (!date) { errEl.innerHTML = ''; errEl.appendChild(h('div', { class: 'al al-e' }, 'Please select a date.')); return; }
      var payload = { type: type, amount: amt, category: cat, notes: notesI.value.trim(), date: date };
      await safe(async function () {
        if (isEdit) { payload.id = existing.id; await MWAPI.txUpdate(payload); }
        else await MWAPI.txAdd(payload);
        closeModal();
        toast(isEdit ? 'Transaction updated!' : 'Transaction added!', 'ok');
        showScreen(S.screen);
      });
    }
  }

  async function delTx(id) {
    if (!await confirmDlg('Delete', 'Delete this transaction? This cannot be undone.', 'Delete', true)) return;
    await safe(async function () {
      await MWAPI.txDelete(id);
      toast('Transaction deleted.');
      showScreen(S.screen);
    });
  }

  /* ── TRANSACTIONS ───────────────────────────────────────────────────── */
  var txF = { type: 'all', mode: 'all', month: 0, year: 0 };
  async function renderTx() {
    var view = h('div', { class: 'page' },
      header('Transactions'),
      spinner('Loading…'));
    setScreen(view);

    function reload() { showScreen('tx'); }

    var data = await MWAPI.txList(txF);
    var rows = (data.transactions || []).filter(function (t) { return S.cleared.indexOf(t.id) === -1; });
    var total = data.summary ? data.summary.total : 0;
    var years = data.availableYears || [];
    S.years = years;
    var now = new Date();
    if (!txF.year && years.length) txF.year = years[0];
    if (!txF.year) txF.year = now.getFullYear();
    if (!txF.month) txF.month = now.getMonth() + 1;

    var yearOpts = [h('option', { value: '' }, 'All Years')].concat(years.map(function (y) { return h('option', { value: y }, y); }));

    var modeSel = h('select', { class: 'inp', onchange: function () {
      txF.mode = modeSel.value; txF.month = txF.mode === 'monthly' ? now.getMonth() + 1 : 0;
      txF.year = txF.mode !== 'all' && years.length ? years[0] : now.getFullYear();
      reload();
    } },
      h('option', { value: 'all', selected: txF.mode === 'all' }, 'All time'),
      h('option', { value: 'monthly', selected: txF.mode === 'monthly' }, 'Monthly'),
      h('option', { value: 'yearly', selected: txF.mode === 'yearly' }, 'Yearly'));
    var monthSel = h('select', { class: 'inp', onchange: function () { txF.month = +monthSel.value; reload(); } });
    for (var i = 1; i <= 12; i++) monthSel.appendChild(h('option', { value: i, selected: txF.month === i }, MONTHS[i - 1]));
    var yearSel = h('select', { class: 'inp', onchange: function () { txF.year = +yearSel.value; reload(); } }, yearOpts);

    var periodRow = h('div', { class: 'frow c3' }, modeSel, monthSel, yearSel);
    if (txF.mode === 'all') { monthSel.classList.add('hidden'); yearSel.classList.add('hidden'); }
    if (txF.mode === 'yearly') monthSel.classList.add('hidden');

    var typeSel = h('div', { class: 'tab-tog' },
      [['all', 'All'], ['income', '📈 Income'], ['expense', '💸 Expense']].map(function (t) {
        return h('button', { class: txF.type === t[0] ? 'act' : '', onclick: function () { txF.type = t[0]; reload(); } }, t[1]);
      }));

    view.innerHTML = '';
    view.appendChild(h('div', { class: 'page' },
      h('div', { class: 'page-hdr' }, h('h2', {}, 'Transactions'),
        h('button', { class: 'hdr-act', title: 'Erase all', onclick: async function () {
          if (await confirmDlg('Erase Balance', 'Delete ALL your transactions? Records are permanently removed. (Reports and PDFs will then be empty.)', 'Erase all', true)) {
            await safe(async function () { await MWAPI.txEraseAll(); toast('Balance erased.', 'ok'); reload(); });
          }
        } }, '🧽')),
      typeSel,
      periodRow,
      h('div', { class: 'sh' }, h('h3', {}, 'Entries'), h('span', { class: 'sh-sub' }, fmtMoney(total))),
      txList(rows)
    ));
  }

  /* ── STATUS ─────────────────────────────────────────────────────────── */
  var stF = { kind: 'monthly', month: 0, year: 0, date: todayStr() };
  async function renderStatus() {
    var view = h('div', { class: 'page' }, header('Status'), spinner('Loading…'));
    setScreen(view);
    var now = new Date();
    if (!stF.month) stF.month = now.getMonth() + 1;
    if (!stF.year) stF.year = now.getFullYear();

    var params = { type: 'all' };
    if (stF.kind === 'daily') { params.mode = 'monthly'; params.month = +stF.date.slice(5, 7); params.year = +stF.date.slice(0, 4); }
    if (stF.kind === 'monthly') { params.mode = 'monthly'; params.month = stF.month; params.year = stF.year; }
    if (stF.kind === 'yearly') { params.mode = 'yearly'; params.year = stF.year; }

    var data = await MWAPI.txList(params);
    var list = (data.transactions || []);
    var income = sumOf(list, 'income'), expense = sumOf(list, 'expense'), balance = income - expense;
    var cats = entries(data.summary && data.summary.categories || {}).sort(function (a, b) { return b[1] - a[1]; });
    var maxCat = cats.length ? cats[0][1] : 1;

    var kindTabs = h('div', { class: 'ptabs' },
      [['daily', 'Daily'], ['monthly', 'Monthly'], ['yearly', 'Yearly']].map(function (k) {
        return h('button', { class: 'ptab' + (stF.kind === k[0] ? ' act' : ''), onclick: function () { stF.kind = k[0]; showScreen('status'); } }, k[1]);
      }));

    var sel = h('div', { class: 'frow' });
    if (stF.kind === 'daily') {
      sel.appendChild(h('div', { class: 'fg' }, h('label', {}, 'Date'), h('input', { class: 'inp', type: 'date', value: stF.date, onchange: function (e) { stF.date = e.target.value; showScreen('status'); } })));
    } else if (stF.kind === 'monthly') {
      var mSel = h('select', { class: 'inp', onchange: function () { stF.month = +mSel.value; showScreen('status'); } });
      for (var i = 1; i <= 12; i++) mSel.appendChild(h('option', { value: i, selected: stF.month === i }, MONTHS[i - 1]));
      sel.appendChild(h('div', { class: 'fg' }, h('label', {}, 'Month'), mSel));
      sel.appendChild(h('div', { class: 'fg' }, h('label', {}, 'Year'), yearInput(stF.year, function (v) { stF.year = v; showScreen('status'); })));
    } else {
      sel.appendChild(h('div', { class: 'fg' }, h('label', {}, 'Year'), yearInput(stF.year, function (v) { stF.year = v; showScreen('status'); })));
    }

    var periodLabel = stF.kind === 'daily' ? fmtDate(stF.date)
      : stF.kind === 'monthly' ? MONTHS[stF.month - 1] + ' ' + stF.year : 'Year ' + stF.year;

    var ids = list.map(function (t) { return t.id; });
    var visibleIds = ids.filter(function (id) { return S.cleared.indexOf(id) === -1; });
    var hiddenCount = ids.length - visibleIds.length;

    var pdfBtns = h('div', { class: 'pdf-btns' },
      pdfBtn('📅', 'Download Daily PDF', function () { openPdfModal('daily'); }),
      pdfBtn('🗓️', 'Download Monthly PDF', function () { openPdfModal('monthly'); }),
      pdfBtn('📆', 'Download Yearly PDF', function () { openPdfModal('yearly'); }));

    view.innerHTML = '';
    view.appendChild(h('div', { class: 'page' },
      header('Status'),
      kindTabs,
      sel,
      h('div', { class: 'sum-cards' },
        h('div', { class: 'sum-c' }, h('div', { class: 'sum-lbl' }, 'Income · ' + periodLabel), h('div', { class: 'sum-val gr' }, fmtMoney(income))),
        h('div', { class: 'sum-c' }, h('div', { class: 'sum-lbl' }, 'Expense · ' + periodLabel), h('div', { class: 'sum-val rd' }, fmtMoney(expense))),
        h('div', { class: 'sum-c' }, h('div', { class: 'sum-lbl' }, 'Balance · ' + periodLabel), h('div', { class: 'sum-val' }, fmtMoney(balance)))),
      h('div', { class: 'sh' }, h('h3', {}, 'By Category'),
        h('button', { class: 'sh-clear', onclick: async function () {
          if (!ids.length) return toast('Nothing to clear for this period.', 'err');
          if (!await confirmDlg('Clear', 'Hide all ' + list.length + ' entries shown for this period? Records stay in the database — PDFs and Reports are unaffected.', 'Clear', false)) return;
          await safe(async function () { await MWAPI.clearHide(ids); toast('Entries hidden for this period.', 'ok'); S.cleared = S.cleared.concat(ids); showScreen('status'); });
        } }, '🧽 Clear' + (hiddenCount ? ' (' + hiddenCount + ' hidden)' : ''))),
      cats.length ? h('div', {}, cats.map(function (c) {
        return h('div', { class: 'cat-row' },
          h('div', { style: 'flex:1;min-width:0' }, h('div', { style: 'font-size:12px;font-weight:700' }, c[0])),
          h('div', { class: 'cbw' }, h('div', { class: 'cb', style: 'width:' + Math.max(3, Math.round(c[1] / maxCat * 100)) + '%' })),
          h('span', { class: 'cpct' }, fmtMoney(c[1])));
      })) : h('div', { class: 'empty' }, h('div', { class: 'eic' }, '📊'), h('p', {}, 'No transactions in this period.')),
      h('div', { class: 'sh', style: 'margin-top:16px' }, h('h3', {}, 'PDF Reports')),
      pdfBtns,
      h('div', { class: 'sh', style: 'margin-top:16px' }, h('h3', {}, 'Entries')),
      txList(list)
    ));
  }

  function yearInput(val, onchange) {
    var y = h('select', { class: 'inp', onchange: function () { onchange(+y.value); } });
    var years = S.years && S.years.length ? S.years : [new Date().getFullYear()];
    years.forEach(function (yy) { y.appendChild(h('option', { value: yy, selected: val === yy }, yy)); });
    return y;
  }
  function pdfBtn(ic, label, fn) {
    return h('button', { class: 'btn btn-soft', onclick: fn }, h('span', {}, ic), h('span', {}, label));
  }

  function openPdfModal(kind) {
    var dateI = null, mSel = null, ySel = null;
    var fields = [];
    if (kind === 'daily') {
      dateI = h('input', { class: 'inp', type: 'date', value: todayStr() });
      fields.push(h('div', { class: 'fg' }, h('label', {}, 'Date'), dateI));
    } else if (kind === 'monthly') {
      mSel = h('select', { class: 'inp' });
      for (var i = 1; i <= 12; i++) mSel.appendChild(h('option', { value: i, selected: i === new Date().getMonth() + 1 }, MONTHS[i - 1]));
      fields.push(h('div', { class: 'fg' }, h('label', {}, 'Month'), mSel));
      fields.push(h('div', { class: 'fg' }, h('label', {}, 'Year'), buildYearSel()));
    } else {
      fields.push(h('div', { class: 'fg' }, h('label', {}, 'Year'), buildYearSel()));
    }
    function buildYearSel() {
      ySel = h('select', { class: 'inp' });
      var years = S.years && S.years.length ? S.years : [new Date().getFullYear()];
      years.forEach(function (yy) { ySel.appendChild(h('option', { value: yy, selected: yy === new Date().getFullYear() }, yy)); });
      return ySel;
    }
    openModal(h('div', {},
      h('div', { class: 'modal-hdr' }, h('h3', {}, 'Download ' + kind.charAt(0).toUpperCase() + kind.slice(1) + ' PDF'), h('button', { class: 'modal-x', onclick: closeModal }, '✕')),
      fields,
      h('button', { class: 'btn btn-p', onclick: go }, 'Generate PDF')
    ), true);
    async function go() {
      closeModal();
      await safe(async function () {
        if (kind === 'daily') await downloadDailyPdf(dateI.value);
        else if (kind === 'monthly') await downloadMonthlyPdf(+mSel.value, +ySel.value);
        else await downloadYearlyPdf(+ySel.value);
      });
    }
  }

  /* ── PDF reports (jsPDF, ported from the web app) ──────────────────── */
  var pdfMoney = function (n) { return 'Rs. ' + Math.abs(+n || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
  var pctOf = function (amt, total) { return total > 0 ? Math.round(amt / total * 100) : 0; };
  var catTotals = function (list) { var m = {}; list.forEach(function (t) { var k = t.cat || 'Other'; m[k] = (m[k] || 0) + (+t.amount || 0); }); return m; };
  var monthTotals = function (list, y) { var m = {}; list.filter(function (t) { return t.type === 'expense' && t.date.slice(0, 4) === String(y); }).forEach(function (t) { var k = +t.date.slice(5, 7); m[k] = (m[k] || 0) + (+t.amount || 0); }); return m; };
  var expOnly = function (list) { return list.filter(function (t) { return t.type === 'expense'; }); };
  var totalAmt = function (list) { return list.reduce(function (s, t) { return s + (+t.amount || 0); }, 0); };

  var _pdfLogoP = null;
  function pdfLogo() {
    if (!_pdfLogoP) _pdfLogoP = (async function () {
      try {
        var r = await fetch('img/logo.png', { cache: 'force-cache' });
        if (!r.ok) return null;
        var b = await r.blob();
        var url = await new Promise(function (res) { var fr = new FileReader(); fr.onload = function () { res(fr.result); }; fr.onerror = function () { res(null); }; fr.readAsDataURL(b); });
        if (!url) return null;
        var img = new Image();
        await new Promise(function (res, rej) { img.onload = res; img.onerror = rej; img.src = url; });
        var size = 96;
        var ratio = Math.min(size / img.width, size / img.height, 1);
        var c = document.createElement('canvas');
        c.width = Math.max(1, Math.round(img.width * ratio));
        c.height = Math.max(1, Math.round(img.height * ratio));
        c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
        return { url: c.toDataURL('image/png'), ratio: c.width / c.height };
      } catch (e) { return null; }
    })();
    return _pdfLogoP;
  }
  function pdfReady() { return !!(window.jspdf && window.jspdf.jsPDF); }
  async function pdfOpen(title, subtitle) {
    var jsPDF = window.jspdf.jsPDF;
    var doc = new jsPDF({ unit: 'mm', format: 'a4' });
    var W = 210, M = 14;
    doc.setFillColor(124, 58, 237); doc.rect(0, 0, W, 30, 'F');
    doc.setFillColor(167, 139, 250); doc.rect(0, 27.5, W, 1.5, 'F');
    var logo = await pdfLogo();
    var bx = M;
    if (logo) { try { var bw = 17, bh = Math.min(17, bw / logo.ratio); doc.addImage(logo.url, 'PNG', M, 6.5 + (17 - bh) / 2, bw, bh); } catch (e) { } bx = M + 21; }
    doc.setTextColor(255, 255, 255);
    doc.setFont('helvetica', 'bold'); doc.setFontSize(18);
    doc.text('MoneyWise', bx, 12.5);
    doc.setFont('helvetica', 'normal'); doc.setFontSize(9.5);
    doc.setTextColor(237, 233, 254);
    doc.text('Expense Report', bx, 18.5);
    doc.setFont('helvetica', 'bold'); doc.setFontSize(14);
    doc.setTextColor(255, 255, 255);
    doc.text(title, W - M, 12.5, { align: 'right' });
    doc.setFont('helvetica', 'normal'); doc.setFontSize(9);
    doc.setTextColor(237, 233, 254);
    doc.text(subtitle, W - M, 18.5, { align: 'right' });
    return { doc: doc, W: W, M: M };
  }
  function pdfText(doc, s, x, y, maxW) {
    var t = String(s == null ? '' : s);
    if (maxW && doc.getTextWidth(t) > maxW) {
      while (t.length > 1 && doc.getTextWidth(t + '…') > maxW) t = t.slice(0, -1);
      t += '…';
    }
    doc.text(t, x, y);
  }
  function pdfFooter(doc, W, M, note) {
    var n = doc.internal.getNumberOfPages();
    var gen = new Date().toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' });
    for (var i = 1; i <= n; i++) {
      doc.setPage(i);
      doc.setDrawColor(233, 233, 250); doc.setLineWidth(0.3);
      doc.line(M, 284, W - M, 284);
      doc.setFont('helvetica', 'normal'); doc.setFontSize(8);
      doc.setTextColor(156, 163, 175);
      doc.text('MoneyWise • ' + note, M, 289);
      doc.text('Generated ' + gen, W - M, 289, { align: 'right' });
      doc.text('Page ' + i + ' of ' + n, W - M, 293.5, { align: 'right' });
    }
  }
  function pdfTable(doc, W, M, y, head, rows, widths, opts) {
    opts = opts || {};
    var bottom = 278, th = 7, rh = 6.5, pad = 1.5;
    var right = M + widths.reduce(function (a, b) { return a + b; }, 0);
    var xs = [], x = M; widths.forEach(function (w) { xs.push(x); x += w; });
    var startNum = opts.startNum;
    function drawHead() {
      doc.setFillColor(124, 58, 237); doc.rect(M, y, right - M, th, 'F');
      doc.setFont('helvetica', 'bold'); doc.setFontSize(8.5); doc.setTextColor(255, 255, 255);
      widths.forEach(function (w, i) {
        if (i === widths.length - 1) doc.text(String(head[i]), xs[i] + w - pad, y + 4.6, { align: 'right' });
        else doc.text(String(head[i]), xs[i] + pad, y + 4.6);
      });
      y += th;
    }
    drawHead();
    doc.setFont('helvetica', 'normal'); doc.setFontSize(8.5);
    rows.forEach(function (r, ri) {
      if (y + rh > bottom) { doc.addPage(); y = 26; drawHead(); }
      var shade = ri % 2 ? [245, 243, 255] : [255, 255, 255];
      doc.setFillColor(shade[0], shade[1], shade[2]); doc.rect(M, y, right - M, rh, 'F');
      doc.setTextColor(30, 27, 46);
      widths.forEach(function (w, i) {
        var v = r[i];
        if (i === 0 && startNum !== undefined) v = startNum + ri;
        if (i === widths.length - 1) {
          doc.setTextColor(220, 38, 38);
          doc.text(String(v), xs[i] + w - pad, y + 4.6, { align: 'right' });
        } else {
          var c = i === 0 ? [124, 58, 237] : [30, 27, 46];
          doc.setTextColor(c[0], c[1], c[2]);
          pdfText(doc, v, xs[i] + pad, y + 4.6, w - pad * 2);
        }
      });
      y += rh;
    });
    return y;
  }
  function pdfSummaryBox(doc, W, M, y, label, value, rightNote) {
    doc.setFillColor(245, 243, 255); doc.roundedRect(M, y, W - 2 * M, 22, 4, 4, 'F');
    doc.setFont('helvetica', 'bold'); doc.setFontSize(9); doc.setTextColor(124, 58, 237);
    doc.text(label, M + 6, y + 7.5);
    doc.setFontSize(15); doc.setTextColor(30, 27, 46);
    doc.text(value, M + 6, y + 17);
    if (rightNote) { doc.setFont('helvetica', 'normal'); doc.setFontSize(8.5); doc.setTextColor(107, 114, 128); doc.text(rightNote, W - M - 6, y + 16, { align: 'right' }); }
    return y + 27;
  }
  function pdfEmptyBox(doc, W, M, y, msg) {
    doc.setDrawColor(196, 181, 253); doc.setLineWidth(0.4);
    doc.roundedRect(M, y, W - 2 * M, 24, 4, 4, 'S');
    doc.setFont('helvetica', 'normal'); doc.setFontSize(10); doc.setTextColor(124, 58, 237);
    doc.text(msg, W / 2, y + 14, { align: 'center' });
    return y + 30;
  }
  function pdfSection(doc, W, M, y, text) {
    doc.setFont('helvetica', 'bold'); doc.setFontSize(11); doc.setTextColor(124, 58, 237);
    doc.text(text, M, y);
    var tw = doc.getTextWidth(text);
    doc.setDrawColor(221, 214, 254); doc.setLineWidth(0.4);
    doc.line(M + tw + 4, y - 1, W - M, y - 1);
    return y + 6;
  }
  function pdfUserLine(doc, W, M, y) {
    doc.setFont('helvetica', 'normal'); doc.setFontSize(9); doc.setTextColor(75, 85, 99);
    doc.text('User: ' + (S.user ? S.user.name : ''), M, y + 2);
  }
  async function downloadDailyPdf(dateStr) {
    if (!pdfReady()) return toast('PDF library not loaded.', 'err');
    var list = expOnly(S.txs).filter(function (t) { return t.date === dateStr; }).slice().sort(function (a, b) { return String(b.date).localeCompare(String(a.date)); });
    var o = await pdfOpen('Daily Expense Report', fmtDate(dateStr));
    var doc = o.doc, W = o.W, M = o.M;
    var y = 38;
    y = pdfSummaryBox(doc, W, M, y, 'Total Expense for the Day', pdfMoney(totalAmt(list)), list.length + (list.length === 1 ? ' record' : ' records'));
    pdfUserLine(doc, W, M, y);
    doc.text('Date: ' + fmtDate(dateStr), W - M, y + 2, { align: 'right' });
    y += 11;
    if (!list.length) pdfEmptyBox(doc, W, M, y, 'No expenses found for ' + fmtDate(dateStr) + '.');
    else {
      y += 2;
      y = pdfSection(doc, W, M, y, 'All Expenses for the Day');
      pdfTable(doc, W, M, y, ['#', 'Date', 'Category', 'Notes', 'Amount'],
        list.map(function (t) { return [null, fmtDate(t.date), t.cat || '—', t.notes || '—', pdfMoney(t.amount)]; }),
        [8, 26, 42, 64, 42], { startNum: 1 });
    }
    pdfFooter(doc, W, M, 'Daily Expense Report • ' + fmtDate(dateStr));
    doc.save('MoneyWise-Daily-Expenses-' + dateStr + '.pdf');
    toast('Daily PDF downloaded!', 'ok');
  }
  async function downloadMonthlyPdf(m, y) {
    if (!pdfReady()) return toast('PDF library not loaded.', 'err');
    var list = expOnly(S.txs).filter(function (t) { return +t.date.slice(0, 4) === y && +t.date.slice(5, 7) === m; }).slice().sort(function (a, b) { return String(b.date).localeCompare(String(a.date)); });
    var o = await pdfOpen('Monthly Expense Report', MONTHS[m - 1] + ' ' + y);
    var doc = o.doc, W = o.W, M = o.M;
    var yy = 38;
    yy = pdfSummaryBox(doc, W, M, yy, 'Total Monthly Expense', pdfMoney(totalAmt(list)), list.length + (list.length === 1 ? ' record' : ' records'));
    pdfUserLine(doc, W, M, yy);
    doc.text('Period: ' + MONTHS[m - 1] + ' ' + y, W - M, yy + 2, { align: 'right' });
    yy += 11;
    if (!list.length) pdfEmptyBox(doc, W, M, yy, 'No expenses found for ' + MONTHS[m - 1] + ' ' + y + '.');
    else {
      var total = totalAmt(list);
      var cats = entries(catTotals(list)).sort(function (a, b) { return b[1] - a[1]; });
      yy += 2;
      yy = pdfSection(doc, W, M, yy, 'Category-wise Expense Details');
      yy = pdfTable(doc, W, M, yy, ['#', 'Category', '% of Month', 'Amount'],
        cats.map(function (c) { return [null, c[0], pctOf(c[1], total) + '%', pdfMoney(c[1])]; }),
        [8, 88, 40, 46], { startNum: 1 });
      yy += 4;
      yy = pdfSection(doc, W, M, yy, 'Transactions');
      pdfTable(doc, W, M, yy, ['#', 'Date', 'Category', 'Notes', 'Amount'],
        list.map(function (t) { return [null, fmtDate(t.date), t.cat || '—', t.notes || '—', pdfMoney(t.amount)]; }),
        [8, 26, 42, 64, 42], { startNum: 1 });
    }
    pdfFooter(doc, W, M, 'Monthly Expense Report • ' + MONTHS[m - 1] + ' ' + y);
    doc.save('MoneyWise-Monthly-Expenses-' + y + '-' + pad2(m) + '.pdf');
    toast('Monthly PDF downloaded!', 'ok');
  }
  async function downloadYearlyPdf(y) {
    if (!pdfReady()) return toast('PDF library not loaded.', 'err');
    var list = expOnly(S.txs).filter(function (t) { return +t.date.slice(0, 4) === y; });
    var o = await pdfOpen('Yearly Expense Report', 'Calendar Year ' + y);
    var doc = o.doc, W = o.W, M = o.M;
    var yy = 38;
    yy = pdfSummaryBox(doc, W, M, yy, 'Total Yearly Expense', pdfMoney(totalAmt(list)), list.length + (list.length === 1 ? ' record' : ' records'));
    pdfUserLine(doc, W, M, yy);
    doc.text('Year: ' + y, W - M, yy + 2, { align: 'right' });
    yy += 11;
    if (!list.length) pdfEmptyBox(doc, W, M, yy, 'No expenses found for ' + y + '.');
    else {
      var total = totalAmt(list);
      var mt = monthTotals(S.txs, y);
      yy += 2;
      yy = pdfSection(doc, W, M, yy, 'Month-wise Expense Details');
      var mRows = [];
      for (var i = 1; i <= 12; i++) mRows.push([null, MONTHS[i - 1], pctOf(mt[i] || 0, total) + '%', pdfMoney(mt[i] || 0)]);
      yy = pdfTable(doc, W, M, yy, ['#', 'Month', '% of Year', 'Amount'], mRows, [8, 60, 44, 70], { startNum: 1 });
      yy += 4;
      var cats = entries(catTotals(list)).sort(function (a, b) { return b[1] - a[1]; });
      yy = pdfSection(doc, W, M, yy, 'Category-wise Expense Details');
      pdfTable(doc, W, M, yy, ['#', 'Category', '% of Year', 'Amount'],
        cats.map(function (c) { return [null, c[0], pctOf(c[1], total) + '%', pdfMoney(c[1])]; }),
        [8, 88, 40, 46], { startNum: 1 });
    }
    pdfFooter(doc, W, M, 'Yearly Expense Report • ' + y);
    doc.save('MoneyWise-Yearly-Expenses-' + y + '.pdf');
    toast('Yearly PDF downloaded!', 'ok');
  }

  /* ── PROFILE ───────────────────────────────────────────────────────── */
  async function renderProfile() {
    var view = h('div', { class: 'page' }, header('Profile'), spinner('Loading…'));
    setScreen(view);
    var salary = null;
    try { salary = (await MWAPI.salaryMine()).salary; } catch (e) { /* not fatal */ }
    var u = S.user;

    view.innerHTML = '';
    view.appendChild(h('div', { class: 'page' },
      header('Profile'),
      h('div', { class: 'ph' },
        h('div', { class: 'avatar' }, initial(u.name)),
        h('div', { class: 'pname' }, u.name),
        h('div', { class: 'pemail' }, u.email),
        salary ? h('div', { class: 'psalary' }, '💼 Current salary: ' + fmtMoney(salary.amount)) : null),
      h('div', { class: 'settings-section' },
        h('div', { class: 'settings-section-title' }, 'Account'),
        h('div', { class: 'settings-card' },
          row('✏️', 'sri-purple', 'Edit Profile', 'Update your name and email', function () { openProfileModal(); }),
          row('🔒', 'sri-purple', 'Change Password', 'Set a new password', function () { openPasswordModal(); }),
          row('📱', 'sri-green', 'About', 'Money Wise • API: ' + MW_API_BASE, function () { toast('Money Wise mobile app (Capacitor).', 'ok'); }))),
      h('div', { class: 'settings-section' },
        h('div', { class: 'settings-section-title' }, 'Session'),
        h('div', { class: 'settings-card' },
          row('🚪', 'sri-red', 'Log Out', 'Sign out of this device', async function () {
            if (await confirmDlg('Log out', 'Sign out of Money Wise on this device?', 'Log Out', true)) {
              await safe(async function () { await MWAPI.logout(); S.user = null; renderAuth(); });
            }
          })))
    ));
  }
  function row(ic, cls, title, sub, onclick) {
    return h('button', { class: 'settings-row', onclick: onclick },
      h('div', { class: 'settings-row-icon ' + cls }, ic),
      h('div', { class: 'settings-row-body' }, h('div', { class: 'settings-row-title' }, title), h('div', { class: 'settings-row-sub' }, sub)),
      h('span', { class: 'settings-row-arrow' }, '›'));
  }

  function openProfileModal() {
    var u = S.user;
    var nameI = h('input', { class: 'inp', value: u.name, maxlength: 100 });
    var emailI = h('input', { class: 'inp', type: 'email', value: u.email, maxlength: 150 });
    var errEl = h('div');
    openModal(h('div', {},
      h('div', { class: 'modal-hdr' }, h('h3', {}, 'Edit Profile'), h('button', { class: 'modal-x', onclick: closeModal }, '✕')),
      errEl,
      h('div', { class: 'fg' }, h('label', {}, 'Name'), nameI),
      h('div', { class: 'fg' }, h('label', {}, 'Email'), emailI),
      h('button', { class: 'btn btn-p', onclick: save }, 'Save')
    ), true);
    async function save() {
      if (!nameI.value.trim() || !emailI.value.trim()) { errEl.innerHTML = ''; errEl.appendChild(h('div', { class: 'al al-e' }, 'Name and email are required.')); return; }
      await safe(async function () {
        var r = await MWAPI.profileUpdate({ name: nameI.value.trim(), email: emailI.value.trim(), gender: u.gender });
        MWAPI.setUser(r.user); S.user = r.user;
        closeModal(); toast(r.message || 'Profile updated!', 'ok'); showScreen('profile');
      });
    }
  }
  function openPasswordModal() {
    var cur = h('input', { class: 'inp', type: 'password' });
    var nw = h('input', { class: 'inp', type: 'password' });
    var cf = h('input', { class: 'inp', type: 'password' });
    var errEl = h('div');
    openModal(h('div', {},
      h('div', { class: 'modal-hdr' }, h('h3', {}, 'Change Password'), h('button', { class: 'modal-x', onclick: closeModal }, '✕')),
      errEl,
      h('div', { class: 'fg' }, h('label', {}, 'Current Password'), cur),
      h('div', { class: 'fg' }, h('label', {}, 'New Password'), nw),
      h('div', { class: 'fg' }, h('label', {}, 'Confirm New Password'), cf),
      h('div', { class: 'al al-w' }, 'Other devices and the token on this phone are invalidated.'),
      h('button', { class: 'btn btn-p', onclick: save }, 'Change Password')
    ), true);
    async function save() {
      if (nw.value.length < 8) { errEl.innerHTML = ''; errEl.appendChild(h('div', { class: 'al al-e' }, 'Password must be at least 8 characters.')); return; }
      if (nw.value !== cf.value) { errEl.innerHTML = ''; errEl.appendChild(h('div', { class: 'al al-e' }, 'Passwords do not match.')); return; }
      await safe(async function () {
        var r = await MWAPI.passwordChange(cur.value, nw.value);
        closeModal(); toast(r.message || 'Password changed!', 'ok');
      });
    }
  }

  /* ── ADMIN ─────────────────────────────────────────────────────────── */
  var admTab = 'users';
  async function renderAdmin() {
    var view = h('div', { class: 'page' }, header('Admin'), spinner('Loading…'));
    setScreen(view);
    var tabs = h('div', { class: 'ptabs' },
      [['users', 'Users'], ['salaries', 'Salaries'], ['history', 'History']].map(function (t) {
        return h('button', { class: 'ptab' + (admTab === t[0] ? ' act' : ''), onclick: function () { admTab = t[0]; showScreen('admin'); } }, t[1]);
      }));

    if (admTab === 'users') {
      var users = (await MWAPI.adminUsers()).users || [];
      view.innerHTML = '';
      view.appendChild(h('div', { class: 'page' },
        header('Admin'), tabs,
        users.map(function (u2) {
          return h('div', { class: 'emp-card' },
            h('div', { class: 'emp-avatar' }, initial(u2.name)),
            h('div', { class: 'emp-info' },
              h('div', { class: 'emp-name' }, u2.name, u2.is_admin === 1 ? ' ' + h('span', { class: 'badge' }, 'Admin') : null),
              h('div', { class: 'emp-email' }, u2.email)),
            h('div', { class: 'emp-actions' },
              h('button', { class: 'emp-view', title: 'View expenses', onclick: function () { openViewUser(u2); } }, '👁️'),
              u2.is_admin === 1 ? null : h('button', { class: 'emp-del', title: 'Delete', onclick: function () { delUser(u2); } }, '🗑️')));
        })));
    } else if (admTab === 'salaries') {
      var emps = (await MWAPI.salaryList()).employees || [];
      view.innerHTML = '';
      view.appendChild(h('div', { class: 'page' },
        header('Admin'), tabs,
        emps.map(function (e) {
          return h('div', { class: 'emp-card' },
            h('div', { class: 'emp-avatar' }, initial(e.name)),
            h('div', { class: 'emp-info' },
              h('div', { class: 'emp-name' }, e.name),
              h('div', { class: 'emp-email' }, e.email),
              e.amount != null ? h('div', { class: 'emp-salary-badge' }, fmtMoney(e.amount)) : h('div', { class: 'emp-salary-badge' }, 'No salary set')),
            h('div', { class: 'emp-actions' }, h('button', { class: 'emp-set', title: 'Set salary', onclick: function () { openSalaryModal(e); } }, '💼')));
        })));
    } else {
      var hist = (await MWAPI.salaryHistory({ emp: 'all', month: 'all', year: 'all' })).history || [];
      view.innerHTML = '';
      view.appendChild(h('div', { class: 'page' },
        header('Admin'), tabs,
        hist.length ? hist.map(function (h2) {
          var diff = h2.newSalary - h2.oldSalary;
          return h('div', { class: 'history-card' },
            h('div', { class: 'history-header' },
              h('span', { class: 'history-emp' }, h2.employeeName),
              h('span', { class: 'history-period' }, MONTHS[h2.month - 1] + ' ' + h2.year)),
            h('div', { class: 'history-amounts' }, h('span', { class: 'old' }, pdfMoney(h2.oldSalary)), h('span', {}, '→'), h('span', { class: 'new' }, pdfMoney(h2.newSalary))),
            h('div', { class: 'history-time' }, (diff > 0 ? '▲ +' : diff < 0 ? '▼ ' : '= ') + pdfMoney(Math.abs(diff)) + ' • ' + new Date(h2.updatedAt).toLocaleString()));
        }) : h('div', { class: 'empty' }, h('div', { class: 'eic' }, '📜'), h('p', {}, 'No salary history yet.'))));
    }
  }

  async function delUser(u2) {
    if (!await confirmDlg('Delete user', 'Delete ' + u2.name + '? All their transactions, salary and history are removed. This cannot be undone.', 'Delete', true)) return;
    await safe(async function () {
      var r = await MWAPI.adminDelete(u2.id);
      toast(r.message || 'User deleted.', 'ok'); showScreen('admin');
    });
  }

  function openViewUser(u2) {
    var month = new Date().getMonth() + 1, year = new Date().getFullYear();
    var body = h('div', { class: 'page', style: 'max-height:78vh;overflow-y:auto' }, h('div', { class: 'page-hdr' }, h('h3', {}, u2.name), h('button', { class: 'modal-x', onclick: closeModal }, '✕')), spinner('Loading…'));
    openModal(h('div', {}, body), true);
    function months() { var arr = []; for (var i = 1; i <= 12; i++) arr.push(h('option', { value: i, selected: i === month }, MONTHS[i - 1])); return arr; }
    function showView() {
      safe(async function () {
        var d = await MWAPI.adminView({ userId: u2.id, month: month, year: year });
        var s = d.summary || {};
        body.innerHTML = '';
        body.appendChild(h('div', { class: 'page-hdr' }, h('h3', {}, u2.name), h('button', { class: 'modal-x', onclick: closeModal }, '✕')));
        body.appendChild(h('div', { class: 'fg' }, h('label', {}, 'Month'), h('select', { class: 'inp', onchange: function (e) { month = +e.target.value; showView(); } }, months())));
        body.appendChild(h('div', { class: 'sum-cards' },
          h('div', { class: 'sum-c' }, h('div', { class: 'sum-lbl' }, 'Income'), h('div', { class: 'sum-val gr' }, fmtMoney(s.income))),
          h('div', { class: 'sum-c' }, h('div', { class: 'sum-lbl' }, 'Expense'), h('div', { class: 'sum-val rd' }, fmtMoney(s.expense))),
          h('div', { class: 'sum-c' }, h('div', { class: 'sum-lbl' }, 'Balance'), h('div', { class: 'sum-val' }, fmtMoney(s.balance)))));
        body.appendChild(txList(d.transactions || [], { noActions: true }));
      });
    }
    showView();
  }

  function openSalaryModal(emp) {
    var amt = h('input', { class: 'inp', type: 'number', min: '0', step: '0.01', inputmode: 'decimal', value: emp.amount != null ? emp.amount : '' });
    var month = new Date().getMonth() + 1, year = new Date().getFullYear();
    var mSel = h('select', { class: 'inp' });
    for (var i = 1; i <= 12; i++) mSel.appendChild(h('option', { value: i, selected: i === month }, MONTHS[i - 1]));
    var ySel = h('select', { class: 'inp' });
    for (var y = year - 2; y <= year + 2; y++) ySel.appendChild(h('option', { value: y, selected: y === year }, y));
    var errEl = h('div');
    openModal(h('div', {},
      h('div', { class: 'modal-hdr' }, h('h3', {}, 'Set Salary — ' + emp.name), h('button', { class: 'modal-x', onclick: closeModal }, '✕')),
      errEl,
      h('div', { class: 'fg' }, h('label', {}, 'Monthly Salary'), amt),
      h('div', { class: 'frow' }, h('div', { class: 'fg' }, h('label', {}, 'Month'), mSel), h('div', { class: 'fg' }, h('label', {}, 'Year'), ySel)),
      h('button', { class: 'btn btn-p', onclick: save }, 'Save Salary')
    ), true);
    async function save() {
      var amount = parseFloat(amt.value);
      if (!amt.value || isNaN(amount) || amount <= 0) { errEl.innerHTML = ''; errEl.appendChild(h('div', { class: 'al al-e' }, 'Enter a valid salary amount.')); return; }
      await safe(async function () {
        var r = await MWAPI.salarySet({ userId: emp.id, amount: amount, month: +mSel.value, year: +ySel.value });
        closeModal(); toast(r.message || 'Salary updated!', 'ok'); showScreen('admin');
      });
    }
  }

  /* ── boot ───────────────────────────────────────────────────────────── */
  function boot() {
    window.addEventListener('online', function () { $('#offline-bar').classList.add('hidden'); });
    window.addEventListener('offline', function () { $('#offline-bar').classList.remove('hidden'); });
    if (!MWAPI.isOnline()) $('#offline-bar').classList.remove('hidden');

    if (!MWAPI.getToken()) { renderAuth(); return; }
    safe(async function () {
      var r = await MWAPI.session();
      if (r && r.user) { S.user = r.user; renderShell(); }
      else { MWAPI.setUser(null); renderAuth(); }
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
