// ==UserScript==
// @name         pmoai — Public Mutual capture
// @namespace    pmoai
// @version      1.16
// @description  Scrape Public Mutual Prices/Performance/Info tables and send to the pmoai app. Read-only, your machine only.
// @match        https://www.publicmutualonline.com.my/*
// @match        https://publicmutualonline.com.my/*
// @run-at       document-idle
// @grant        GM_setValue
// @grant        GM_getValue
// @grant        GM_xmlhttpRequest
// @connect      pmoai.local
// ==/UserScript==

/*
  SETUP:
  1. Install Tampermonkey (browser extension).
  2. Tampermonkey → Create new script → paste this file → save.
  3. Set TOKEN below to PMOAI_INGEST_TOKEN from the app's .env.
  4. If @match doesn't cover your PMO pages, edit it to the real domain.

  USE:
  - Log in to Public Mutual, open each of the 3 tab pages (Prices,
    Performance, Info) once. The panel (bottom-right) ticks each as captured.
  - Type goals, click "Send to pmoai" → it POSTs and links the result.
*/

const TOKEN    = 'PASTE_PMOAI_INGEST_TOKEN_HERE';
const API_BASE = 'https://pmoai.local:8890';   // if SSL cert error, try the MAMP http port

// --- table extractor (same logic as the console snippet) -------------------
// Public Mutual hides Risk in a display:none popover and shows Shariah as an
// icon only. Extract the real value per cell, not just visible innerText.
function cellText(td) {
  if (td.querySelector('.fa-star-and-crescent')) return 'Shariah';
  let t = (td.innerText || '').trim().replace(/\s+/g, ' ');
  if (!t) {
    const pop = td.querySelector('.popover_content_wrapper');
    if (pop && pop.textContent.trim()) {
      t = pop.textContent.trim();
    } else {
      const img = td.querySelector('img[src*="risk" i]');
      const m = img && img.src.match(/risk(\d)/i);
      if (m) t = { 1: 'Very Low', 2: 'Low', 3: 'Moderate', 4: 'High', 5: 'Very High' }[m[1]] || '';
    }
  }
  return t;
}

function extractTSV() {
  const tables = [...document.querySelectorAll('table')];
  if (!tables.length) return null;
  const t = tables.sort((a, b) => b.rows.length - a.rows.length)[0];
  const rows = [...t.rows];
  const lens = rows.filter(r => r.querySelector('td')).map(r => r.cells.length);
  if (!lens.length) return null;
  const W = [...new Set(lens)]
    .sort((a, b) => lens.filter(x => x === b).length - lens.filter(x => x === a).length)[0];
  const hr = rows.find(r => !r.querySelector('td') && r.cells.length === W)
          || rows.find(r => r.cells.length === W);
  if (!hr) return null;
  const H = [...hr.cells].map(c => c.innerText.trim().replace(/\s+/g, ' '));
  const out = [['Code', 'Name', ...H.slice(2, -1)].join('\t')];
  for (const r of rows) {
    if (!r.querySelector('td') || r.cells.length !== W) continue;
    const c = [...r.cells];
    const fund = (c[1].innerText || '').split('\n').map(s => s.trim()).filter(Boolean);
    if (!fund[0]) continue;
    const rest = c.slice(2, -1).map(cellText);
    out.push([fund[1] || '', fund[0], ...rest].join('\t'));
  }
  return { header: out[0], tsv: out.join('\n'), rows: out.length - 1 };
}

// --- which tab is this? (by header labels) ---------------------------------
function detectKind(header) {
  const h = header.toUpperCase();
  if (h.includes('YTD') || h.includes('1-YR') || h.includes('FACTOR')) return 'performance';
  if (h.includes('SHARIAH') || h.includes('CATEGORY') || h.includes('INCEPTION')) return 'info';
  if (h.includes('PRICE') || h.includes('CHANGE')) return 'prices';
  return null;
}

// --- capture on page load --------------------------------------------------
function capture() {
  const r = extractTSV();
  if (!r || r.rows < 1) return null;
  const kind = detectKind(r.header);
  if (!kind) return null;
  GM_setValue('cap_' + kind, { tsv: r.tsv, rows: r.rows, ts: Date.now(), url: location.href });
  return { kind, rows: r.rows };
}

// --- auto-collect: snapshot every visited PMO page to pmoai ----------------
// User browses page by page; each page's text + tables land in pmoai's
// page_captures store (deduped server-side). Parsers mine them later.
function collectPage(statusEl) {
  // Never touch authentication pages — nothing useful there, and the
  // collector should not run anywhere near credential entry.
  if (/login|logout|password|security/i.test(location.pathname)) return;
  const text = (document.body.innerText || '').slice(0, 400000);
  if (text.length < 40) return;
  const tables = [...document.querySelectorAll('table')].slice(0, 30).map(t =>
    [...t.rows].map(r => [...r.cells].map(c =>
      (c.innerText || '').trim().replace(/\t/g, ' ')).join('\t')).join('\n')
  ).filter(s => s.length > 20).slice(0, 30);

  // clickable elements (hrefs + onclick handlers) so pmoai can learn how
  // per-row downloads are wired and later automate them
  const links = [...document.querySelectorAll('a[href], [onclick], input[type=image]')].slice(0, 300)
    .map(el => ({
      t: (el.innerText || el.value || el.alt || '').trim().slice(0, 80),
      h: (el.getAttribute('href') || '').slice(0, 300),
      o: (el.getAttribute('onclick') || '').slice(0, 300),
    }))
    .filter(l => l.h || l.o);

  GM_xmlhttpRequest({
    method: 'POST',
    url: API_BASE + '/ingest-page',
    headers: { 'Content-Type': 'application/json', 'X-PMOAI-TOKEN': TOKEN },
    data: JSON.stringify({
      url: location.href,
      title: document.title,
      text: text,
      tables: tables,
      links: links,
    }),
    onload: res => {
      try {
        const j = JSON.parse(res.responseText);
        if (statusEl) statusEl.textContent = j.stored ? '📥 page saved to pmoai' : '📥 already saved';
      } catch (e) { if (statusEl) statusEl.textContent = '📥 save failed'; }
    },
    onerror: () => { if (statusEl) statusEl.textContent = '📥 save failed (network)'; },
  });
}

// --- holdings page (my portfolio: cost + market value per fund) ------------
// Finds the table whose headers include "Market Value" and "Investment Cost",
// maps columns by header text, and returns one row per held fund.
function extractHoldings() {
  const num = (s) => {
    s = (s || '').replace(/\s/g, '');
    const neg = /^\(.*\)$/.test(s);
    const v = parseFloat(s.replace(/[(),]/g, '').replace(/RM/i, ''));
    return isNaN(v) ? null : (neg ? -v : v);
  };
  // Holdings may render inside a same-origin iframe — search every
  // reachable document, not just the top one.
  const docs = [document];
  document.querySelectorAll('iframe, frame').forEach(function (f) {
    try {
      if (f.contentDocument && f.contentDocument.body) {
        docs.push(f.contentDocument);
        f.contentDocument.querySelectorAll('iframe, frame').forEach(function (g) {
          try { if (g.contentDocument && g.contentDocument.body) docs.push(g.contentDocument); } catch (e) {}
        });
      }
    } catch (e) { /* cross-origin */ }
  });

  // DataTables often splits the header and body into SEPARATE <table>
  // elements — find the header row anywhere, then parse data rows across
  // every table in every reachable document.
  const tables = docs.flatMap(d => [...d.querySelectorAll('table')]);
  let iName = -1, iMv = -1, iCost = -1, hr = null;
  for (const t of tables) {
    for (const r of t.rows) {
      const txt = r.innerText || '';
      if (/market\s*value/i.test(txt) && /investment\s*cost/i.test(txt) && r.cells.length >= 3) {
        const heads = [...r.cells].map(c => (c.innerText || '').toLowerCase());
        const col = (re) => heads.findIndex(h => re.test(h));
        iName = col(/fund|name/);
        iMv = col(/market\s*value/);
        iCost = col(/investment\s*cost/);
        var iPrice = heads.findIndex(h => /price/.test(h));
        extractHoldings._iPrice = iPrice;
        if (iName >= 0 && iMv >= 0 && iCost >= 0) { hr = r; break; }
      }
    }
    if (hr) break;
  }
  if (!hr) return extractHoldingsText(num, docs);

  const out = [];
  for (const t of tables) {
    for (const r of t.rows) {
      if (r === hr || r.cells.length <= Math.max(iMv, iCost)) continue;
      // name cell may stack account no + fund name — take the alpha line
      const lines = (r.cells[iName].innerText || '').split('\n')
        .map(s => s.trim()).filter(Boolean);
      const name = lines.find(s => /[A-Za-z]{4,}/.test(s) && !/market|investment|category|total/i.test(s));
      const mv = num(r.cells[iMv].innerText);
      const cost = num(r.cells[iCost].innerText);
      const iP = extractHoldings._iPrice;
      const px = (iP >= 0 && r.cells[iP]) ? num(r.cells[iP].innerText) : null;
      if (name && mv !== null && cost !== null && mv > 0 && cost > 0) {
        out.push({ name: name, market_value: mv, investment_cost: cost,
                   price: px > 0 ? px : null });
      }
    }
  }
  return out.length ? out : { error: 'header found but no fund rows parsed — column layout differs' };
}

// Text fallback: PMO holdings render per fund as
//   <9-digit account no> / <FUND NAME> / <category> ... then a number run:
//   units, price, market value, investment cost, P/L, P/L% — parse the
//   visible text directly, DOM shape irrelevant.
function extractHoldingsText(num, docs) {
  const text = (docs || [document])
    .map(d => (d.body && d.body.innerText) || '')
    .join('\n');
  const lines = text.split('\n').map(s => s.trim());
  const out = [];
  for (let i = 0; i < lines.length; i++) {
    if (!/^\d{7,12}$/.test(lines[i])) continue;           // account number
    const name = lines[i + 1];
    if (!name || !/[A-Za-z]{4,}/.test(name) || /total|value|cost/i.test(name)) continue;
    const nums = [];
    for (let j = i + 2; j < lines.length && j < i + 18 && nums.length < 6; j++) {
      if (/^\d{7,12}$/.test(lines[j])) break;             // next account row
      if (!/^[-\d(),.%\sRM]+$/.test(lines[j]) || lines[j] === '') continue;
      const v = num(lines[j].replace('%', ''));
      if (v !== null) nums.push(v);
    }
    // nums = units, price, market value, investment cost, [P/L, P/L%]
    if (nums.length >= 4 && nums[2] > 0 && nums[3] > 0) {
      out.push({ name: name, market_value: nums[2], investment_cost: nums[3],
                 price: nums[1] > 0 ? nums[1] : null });
    }
  }
  if (out.length) return out;
  // diagnostics so a failure report tells us what the script could see
  const tbl = (docs || [document]).reduce((n, d) => n + d.querySelectorAll('table').length, 0);
  const ifr = document.querySelectorAll('iframe, frame').length;
  const hasCost = /investment\s*cost/i.test(text) ? 'y' : 'n';
  return { error: 'no holdings found [docs:' + (docs || [1]).length + ' tables:' + tbl
    + ' iframes:' + ifr + ' sees"InvestmentCost":' + hasCost + ']' };
}

// --- floating panel --------------------------------------------------------
function panel() {
  const box = document.createElement('div');
  box.style.cssText = 'position:fixed;right:14px;bottom:14px;z-index:2147483647;'
    + 'background:#111;color:#eee;font:12px system-ui;padding:12px;border-radius:8px;'
    + 'width:260px;box-shadow:0 4px 16px rgba(0,0,0,.4)';
  box.innerHTML = `
    <b style="color:#c8102e">pmoai capture</b>
    <div id="pmoai-st" style="margin:8px 0;line-height:1.6"></div>
    <button id="pmoai-cap" style="margin-top:8px;width:100%;padding:7px;
      background:#1a7;color:#fff;border:0;border-radius:5px;cursor:pointer">Capture this tab</button>
    <button id="pmoai-hold" style="margin-top:6px;width:100%;padding:7px;
      background:#26c;color:#fff;border:0;border-radius:5px;cursor:pointer">Capture holdings → pmoai</button>
    <button id="pmoai-dlall" style="margin-top:6px;width:100%;padding:7px;
      background:#555;color:#fff;border:0;border-radius:5px;cursor:pointer">Download all PDFs on this page</button>
    <label style="display:flex;align-items:center;gap:7px;margin-top:8px;cursor:pointer;font-size:11px">
      <input type="checkbox" id="pmoai-auto" style="margin:0">
      auto-collect every page I visit
    </label>
    <div id="pmoai-auto-st" style="margin-top:3px;color:#8ac;font-size:11px"></div>
    <textarea id="pmoai-fb" placeholder="Goals e.g. Shariah only, growth, war risk"
      style="width:100%;height:48px;box-sizing:border-box;margin-top:8px;font:11px monospace"></textarea>
    <button id="pmoai-send" style="margin-top:8px;width:100%;padding:7px;
      background:#c8102e;color:#fff;border:0;border-radius:5px;cursor:pointer">Send to pmoai</button>
    <button id="pmoai-clear" style="margin-top:6px;width:100%;padding:4px;
      background:#444;color:#bbb;border:0;border-radius:5px;cursor:pointer;font-size:11px">Clear captured</button>
    <div id="pmoai-msg" style="margin-top:6px;color:#9c9"></div>`;
  document.body.appendChild(box);

  const render = () => {
    const st = box.querySelector('#pmoai-st');
    st.innerHTML = ['prices', 'performance', 'info'].map(k => {
      const v = GM_getValue('cap_' + k);
      return v
        ? `✅ ${k} — ${v.rows} rows`
        : `⬜ ${k} — open that tab`;
    }).join('<br>');
  };
  render();
  setInterval(render, 1500);

  box.querySelector('#pmoai-cap').onclick = () => {
    const msg = box.querySelector('#pmoai-msg');
    const r = extractTSV();
    if (!r || r.rows < 1) { msg.textContent = '❌ no fund table found on this page'; return; }
    const kind = detectKind(r.header);
    if (!kind) { msg.textContent = '❌ unknown tab — header: ' + r.header.slice(0, 60); return; }
    GM_setValue('cap_' + kind, { tsv: r.tsv, rows: r.rows, ts: Date.now(), url: location.href });
    msg.textContent = `✅ captured ${kind} (${r.rows} rows)`;
    render();
  };

  // Bulk statement download: click every per-row PDF button, spaced out so
  // the server keeps up. Works on any PMO list page whose rows carry a PDF
  // icon button (Statement of Transaction, Monthly Statement, Fund Reports).
  box.querySelector('#pmoai-dlall').onclick = (ev) => {
    const msg = box.querySelector('#pmoai-msg');
    if (ev.altKey) {
      GM_setValue('dl_' + location.pathname, {});
      msg.textContent = '↺ download memory reset for this page';
      return;
    }
    // every visible thing that plausibly opens a PDF: image submit buttons,
    // clickable pdf icons (img or font-icon), anchors to .pdf, download handlers
    const cand = [
      ...document.querySelectorAll('input[type=image][src*="pdf" i]'),
      ...[...document.querySelectorAll('img[src*="pdf" i]')].map(img => img.closest('a,button,[onclick]') || img),
      ...document.querySelectorAll('a[href*=".pdf" i]'),
      ...document.querySelectorAll('[onclick*="Download" i], [onclick*="Pdf" i]'),
      ...[...document.querySelectorAll('i[class*="pdf" i], .fa-file-pdf')].map(ic => ic.closest('a,button,[onclick]') || ic),
      // ASP.NET grid rows where the date text IS the download link:
      // __doPostBack('ctl00$MainContent$gvwDaily','Download$0')
      ...document.querySelectorAll('a[href*="Download$"]'),
    ];
    let btns = [...new Set(cand)].filter(el => el.offsetParent !== null);
    if (!btns.length) { msg.textContent = '❌ no PDF buttons found on this page'; return; }

    // Remember what was already downloaded (keyed by page + row text) so a
    // second visit only fetches NEW rows. Alt-click the button to reset.
    const memKey = 'dl_' + location.pathname;
    const seen = GM_getValue(memKey) || {};
    const rowKey = el => (el.innerText || el.value || el.alt || el.getAttribute('href') || '').trim().slice(0, 60);
    const fresh = btns.filter(el => !seen[rowKey(el)]);
    if (!fresh.length) {
      msg.textContent = '✅ nothing new — all ' + btns.length + ' already downloaded before (alt-click to force redo)';
      return;
    }
    if (!confirm('Download ' + fresh.length + ' NEW PDFs (' + (btns.length - fresh.length) + ' already done) — ~'
        + Math.ceil(fresh.length * 2.5) + 's?')) return;
    let i = 0;
    const tick = () => {
      if (i >= fresh.length) {
        msg.textContent = '✅ ' + fresh.length + ' PDFs downloaded — check Downloads, then run pmoai:ingest-stmt';
        return;
      }
      msg.textContent = '⬇ downloading ' + (i + 1) + ' / ' + fresh.length + '…';
      seen[rowKey(fresh[i])] = Date.now();
      GM_setValue(memKey, seen);
      fresh[i].click();
      i++;
      setTimeout(tick, 2500);
    };
    tick();
  };

  const autoBox = box.querySelector('#pmoai-auto');
  const autoSt = box.querySelector('#pmoai-auto-st');
  autoBox.checked = !!GM_getValue('auto_collect');
  autoBox.onchange = () => {
    GM_setValue('auto_collect', autoBox.checked);
    autoSt.textContent = autoBox.checked ? 'ON — browsing PMO now feeds pmoai' : '';
    if (autoBox.checked) collectPage(autoSt);
  };
  if (autoBox.checked) {
    autoSt.textContent = 'ON — browsing PMO now feeds pmoai';
    // let dynamic content settle, then snapshot this page; fund detail
    // pages also get their structured capture automatically (no click)
    setTimeout(() => { collectPage(autoSt); autoDetail(autoSt); }, 2200);
  }

  box.querySelector('#pmoai-hold').onclick = () => {
    const msg = box.querySelector('#pmoai-msg');
    const holdings = extractHoldings();
    if (!holdings || holdings.error) {
      msg.textContent = '❌ ' + (holdings && holdings.error
        ? holdings.error
        : 'no holdings table here — open My Portfolio / Account Holdings page');
      return;
    }
    msg.textContent = 'sending ' + holdings.length + ' holdings…';
    GM_xmlhttpRequest({
      method: 'POST',
      url: API_BASE + '/ingest-holdings',
      headers: { 'Content-Type': 'application/json', 'X-PMOAI-TOKEN': TOKEN },
      data: JSON.stringify({ holdings: holdings }),
      onload: res => {
        try {
          const j = JSON.parse(res.responseText);
          if (j.updated !== undefined) {
            const miss = (j.results || []).filter(r => !r.ok).map(r => r.name);
            msg.innerHTML = `✅ ${j.updated}/${holdings.length} positions updated`
              + (miss.length ? `<br>❓ unmatched: ${miss.join(', ').slice(0, 120)}` : '');
          } else {
            msg.textContent = '❌ ' + (j.error || res.responseText).slice(0, 120);
          }
        } catch (e) {
          msg.textContent = '❌ ' + res.status + ' ' + res.responseText.slice(0, 120);
        }
      },
      onerror: () => { msg.textContent = '❌ network/SSL error'; },
    });
  };

  box.querySelector('#pmoai-clear').onclick = () => {
    ['prices', 'performance', 'info'].forEach(k => GM_setValue('cap_' + k, undefined));
    box.querySelector('#pmoai-msg').textContent = 'cleared';
    render();
  };

  box.querySelector('#pmoai-send').onclick = () => {
    const p = GM_getValue('cap_prices'), pf = GM_getValue('cap_performance'), inf = GM_getValue('cap_info');
    const msg = box.querySelector('#pmoai-msg');
    if (!p) { msg.textContent = '❌ open the Prices tab first'; return; }
    msg.textContent = 'sending…';
    GM_xmlhttpRequest({
      method: 'POST',
      url: API_BASE + '/ingest',
      headers: { 'Content-Type': 'application/json', 'X-PMOAI-TOKEN': TOKEN },
      data: JSON.stringify({
        prices: p.tsv,
        performance: pf ? pf.tsv : '',
        info: inf ? inf.tsv : '',
        feedback: box.querySelector('#pmoai-fb').value,
        skip_ai: true,
      }),
      onload: res => {
        try {
          const j = JSON.parse(res.responseText);
          if (j.url) {
            msg.innerHTML = `✅ sent → <a href="${j.url}" target="_blank" style="color:#6cf">snapshot ${j.id}</a>`;
            ['prices', 'performance', 'info'].forEach(k => GM_setValue('cap_' + k, undefined));
          } else {
            msg.textContent = '❌ ' + (j.error || res.responseText).slice(0, 120);
          }
        } catch (e) {
          msg.textContent = '❌ ' + res.status + ' ' + res.responseText.slice(0, 120);
        }
      },
      onerror: () => { msg.textContent = '❌ network/SSL error — accept the pmoai.local cert or use http port'; },
    });
  };
}

// --- fund DETAIL page (single fund deep data) ------------------------------
// Reference capture only — posts to /ingest-detail, NOT the rec pipeline.
// DOM differs per Public Mutual template, so parse is label-driven and
// every field is optional; full page text is always kept as the fallback.
const DETAIL_LABELS = [
  'Category of Fund', 'Launch Date', 'Shariah Compliant', 'Foreign Exposure',
  'Financial Year End', 'Sales Charge', 'Distribution Policy',
  'Geographical Region', 'Current Fund Size (NAV)', 'Fund Volatility',
];

function isDetailPage() {
  const t = document.body.innerText || '';
  return /Fund Objective/i.test(t)
    && /(Top 5 Holdings|Asset Allocation|Fund Facts)/i.test(t);
}

function detailLines() {
  return (document.body.innerText || '')
    .split('\n').map(s => s.trim()).filter(Boolean);
}

// value = rest of the label line, else the next non-empty line
function labelValue(lines, label) {
  for (let i = 0; i < lines.length; i++) {
    const L = lines[i];
    if (L.toLowerCase() === label.toLowerCase()) {
      return (lines[i + 1] || '').replace(/\s+/g, ' ').trim();
    }
    if (L.toLowerCase().startsWith(label.toLowerCase() + ' ')) {
      return L.slice(label.length).replace(/\s+/g, ' ').trim();
    }
  }
  return '';
}

// raw text slice between a start heading and the first of the stop headings
function section(text, start, stops) {
  const s = text.search(new RegExp(start, 'i'));
  if (s < 0) return '';
  let end = text.length;
  for (const st of stops) {
    const e = text.slice(s + start.length).search(new RegExp(st, 'i'));
    if (e >= 0) end = Math.min(end, s + start.length + e);
  }
  return text.slice(s, end).trim();
}

function extractDetail() {
  // SVG chart labels (legend, hi/lo, date range, ticker) are <text>/<tspan>
  // nodes — NOT in body.innerText. Append them so code + chart parse works.
  const svgTxt = [...document.querySelectorAll('svg text, svg tspan')]
    .map(n => (n.textContent || '').trim()).filter(Boolean).join('\n');
  // Drop the volatility disclaimer + site footer (Terms/Privacy/Copyright):
  // boilerplate, not fund data — keep it out of payload and raw_text.
  const rawBody = document.body.innerText || '';
  const cut = rawBody.search(/The Volatility Factor\b|Terms And Conditions of Use/i);
  const body = cut >= 0 ? rawBody.slice(0, cut) : rawBody;
  const text  = body + (svgTxt ? '\n' + svgTxt : '');
  const lines = body.split('\n').map(s => s.trim()).filter(Boolean);

  // name: <title>, then first heading-ish line. Allow lowercase: PM has
  // "e-" prefixed funds (PUBLIC e-ISLAMIC ASIA THEMATIC GROWTH FUND).
  let name = (document.querySelector('h1, h2')?.innerText || '').trim();
  if (!name || name.length < 6) {
    name = lines.find(l => /^[A-Za-z0-9 .&'()/-]{8,80}$/.test(l)
      && /FUND|TRUST|AMANAH/i.test(l)) || '';
  }
  name = name.replace(/\s+/g, ' ').trim();
  if (!name) return null;

  // code is the reliable join key (names drift: list "X SELECT" vs
  // detail "X SELECT FUND"). Try, in order: chart legend "PINDOSF
  // (Risk 5)", an explicit Fund/Stock Code label, the URL query
  // (?fundCode=/?code=/?fundid=), then a "(CODE)" beside the name.
  // Codes are mixed-case (e.g. PeIATGF) — match [A-Za-z], normalize upper.
  let code = '';
  const cm = text.match(/\b([A-Za-z]{3,9})\s*\(\s*Risk/);
  if (cm) {
    code = cm[1];
  }
  if (!code) {
    const lm = text.match(/(?:Fund|Stock)\s*Code\s*[:\-]?\s*([A-Za-z]{2,9}\d?)/i);
    if (lm) code = lm[1];
  }
  if (!code) {
    const um = location.href.match(/[?&](?:fund_?code|code|fund_?id)=([A-Za-z]{2,9}\d?)/i);
    if (um) code = um[1];
  }
  if (!code) {
    const nm = name.match(/\(([A-Za-z]{2,9}\d?)\)/);
    if (nm) code = nm[1];
  }
  code = code.toUpperCase();

  const fields = {};
  for (const lab of DETAIL_LABELS) {
    const v = labelValue(lines, lab);
    if (v) fields[lab] = v;
  }
  const pm = text.match(/Price\/?\s*Unit[^\n]*\n?\s*RM?\s*([0-9][0-9.,]*)/i);
  if (pm) fields['Price/Unit'] = pm[1];

  const payload = {
    fields,
    objective:    section(text, 'Fund Objective', ['Fund Facts', 'View Document', 'Risk Level']),
    performance:  section(text, 'Performance as at', ['Asset Allocation', 'Performance for Calendar']),
    calendar:     section(text, 'Performance for Calendar Years', ['Fund Price', 'Last Distribution']),
    allocation:   section(text, 'Asset Allocation', ['Performance for Calendar', 'Fund Price']),
    price:        section(text, 'Fund Price', ['Last Distribution', 'Distribution For Financial', 'Performance for Calendar']),
    distribution: section(text, 'Distribution For Financial Year', ['Performance for Calendar', 'Fund Price', '$']),
    chart:        section(text, 'Fund Performance From', ['Indices Disclosure', 'Period of Analysis']),
  };

  // Fallback: chart labels are scattered SVG nodes, not a clean block.
  // Stitch the key figures from the combined text via regex.
  if (!payload.chart) {
    const g = [];
    const period = text.match(/Fund Performance From[^\n]*/i);
    const legend = [...text.matchAll(/([A-Z][A-Za-z0-9 ()]*?)\s*:\s*(-?\d+\.?\d*\s*%)/g)];
    const hi = text.match(/Highest Returns?\s*\(([^)]+)\)/i);
    const lo = text.match(/Lowest Returns?\s*\(([^)]+)\)/i);
    if (period) g.push(period[0].trim());
    legend.slice(0, 4).forEach(m => g.push(`${m[1].trim()}\t${m[2].replace(/\s/g, '')}`));
    if (hi) g.push(`Highest Returns\t${hi[1].trim()}`);
    if (lo) g.push(`Lowest Returns\t${lo[1].trim()}`);
    if (g.length) payload.chart = g.join('\n');
  }

  return { name, code, payload, raw_text: text.slice(0, 60000), source_url: location.href };
}

// POST one fund-detail payload; onDone(text) gets a status line.
function sendDetail(d, onDone) {
  GM_xmlhttpRequest({
    method: 'POST',
    url: API_BASE + '/ingest-detail',
    headers: { 'Content-Type': 'application/json', 'X-PMOAI-TOKEN': TOKEN },
    data: JSON.stringify(d),
    onload: res => {
      try {
        const j = JSON.parse(res.responseText);
        onDone(j.id ? `✅ stored detail #${j.id} — ${j.name}` : '❌ ' + (j.error || res.responseText).slice(0, 120));
      } catch (e) {
        onDone('❌ ' + res.status + ' ' + res.responseText.slice(0, 120));
      }
    },
    onerror: () => onDone('❌ network/SSL error — accept the pmoai.local cert or use http port'),
  });
}

// Auto-collect extension: when the visited page IS a fund detail page,
// capture it automatically — no button click. One shot per page load,
// with a late retry because the chart SVG (code source) renders slowly.
let autoDetailSent = '';
function autoDetail(statusEl, attempt) {
  if (!isDetailPage()) return;
  const d = extractDetail();
  if (!d || !d.name) {
    if ((attempt || 0) < 2) setTimeout(() => autoDetail(statusEl, (attempt || 0) + 1), 3000);
    return;
  }
  const key = d.code || d.name;
  if (key === autoDetailSent) return;
  autoDetailSent = key;
  sendDetail(d, t => { if (statusEl) statusEl.textContent = t.replace('✅ stored', '📄 auto-stored'); });
}

function detailPanel() {
  const box = document.createElement('div');
  box.style.cssText = 'position:fixed;left:14px;bottom:14px;z-index:2147483647;'
    + 'background:#111;color:#eee;font:12px system-ui;padding:12px;border-radius:8px;'
    + 'width:260px;box-shadow:0 4px 16px rgba(0,0,0,.4)';
  box.innerHTML = `
    <b style="color:#c8102e">pmoai fund detail</b>
    <div id="pmoai-dst" style="margin:8px 0;line-height:1.5;color:#9bd"></div>
    <button id="pmoai-dcap" style="margin-top:4px;width:100%;padding:7px;
      background:#c8102e;color:#fff;border:0;border-radius:5px;cursor:pointer">
      Capture fund detail → pmoai</button>
    <div id="pmoai-dmsg" style="margin-top:6px;color:#9c9"></div>`;
  document.body.appendChild(box);

  const render = () => {
    const d = extractDetail();
    box.querySelector('#pmoai-dst').textContent = d
      ? `${d.name}${d.code ? ' (' + d.code + ')' : ''}`
      : 'no fund detail on this page';
  };
  render();
  setInterval(render, 1500);

  box.querySelector('#pmoai-dcap').onclick = () => {
    const msg = box.querySelector('#pmoai-dmsg');
    const d = extractDetail();
    if (!d) { msg.textContent = '❌ no fund detail found'; return; }
    msg.textContent = 'sending…';
    sendDetail(d, t => { msg.innerHTML = t; });
  };
}

function toast(got) {
  if (!got) return;
  const m = document.createElement('div');
  m.textContent = `pmoai: captured ${got.kind} (${got.rows})`;
  m.style.cssText = 'position:fixed;top:10px;right:10px;z-index:2147483647;'
    + 'background:#155724;color:#fff;padding:6px 10px;border-radius:5px;font:12px system-ui';
  document.body.appendChild(m);
  setTimeout(() => m.remove(), 3000);
}

// Stay completely inert on authentication pages: no panel, no capture,
// no observers anywhere near credential entry.
if (/login|logout|password|security/i.test(location.pathname)) {
  // eslint-disable-next-line no-throw-literal
  throw 'pmoai: auth page — inactive';
}

panel();
if (isDetailPage()) detailPanel();
toast(capture());

// ASP.NET tab switches are postbacks (no reload). Re-capture whenever the
// table DOM changes; debounce + only toast when the captured kind changes.
let last = '', timer = null;
new MutationObserver(() => {
  clearTimeout(timer);
  timer = setTimeout(() => {
    const g = capture();
    if (g && g.kind + g.rows !== last) {
      last = g.kind + g.rows;
      toast(g);
    }
  }, 600);
}).observe(document.body, { childList: true, subtree: true });
