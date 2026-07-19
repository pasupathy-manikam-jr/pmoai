// ==UserScript==
// @name         pmoai — MFR booklet capture
// @namespace    pmoai
// @version      1.1
// @description  Adds a "→ pmoai" button next to fund-review PDF links on Public Mutual pages. One click fetches the PDF and sends it to the pmoai app — no manual download.
// @match        https://www.publicmutual.com.my/*
// @match        https://publicmutual.com.my/*
// @match        https://www.publicmutualonline.com.my/*
// @run-at       document-idle
// @grant        GM_xmlhttpRequest
// @connect      pmoai.local
// @connect      publicmutual.com.my
// @connect      www.publicmutual.com.my
// @connect      publicmutualonline.com.my
// @connect      www.publicmutualonline.com.my
// ==/UserScript==

/*
  SETUP:
  1. Tampermonkey → Create new script → paste this file → save.
  2. TOKEN below = PMOAI_INGEST_TOKEN from the app's .env (same as the
     existing pmoai capture script).

  USE:
  - Open the page listing the monthly fund-review booklets (MFR).
  - Every PDF link grows a small "→ pmoai" button.
  - Click it once per booklet: the script downloads the PDF in-browser and
    POSTs it to pmoai, which parses all funds and reports the period.
  - Filenames don't matter (PMO reuses the same name); pmoai reads the
    report month from the PDF content.
*/

(function () {
  'use strict';

  const TOKEN    = 'PASTE_PMOAI_INGEST_TOKEN_HERE';
  const API_BASE = 'https://pmoai.local:8890';

  // base64 a large ArrayBuffer without blowing the call stack
  function b64(buf) {
    const bytes = new Uint8Array(buf);
    let bin = '';
    const CHUNK = 0x8000;
    for (let i = 0; i < bytes.length; i += CHUNK) {
      bin += String.fromCharCode.apply(null, bytes.subarray(i, i + CHUNK));
    }
    return btoa(bin);
  }

  function sendToPmoai(pdfBuf, filename, btn) {
    btn.textContent = '… sending';
    GM_xmlhttpRequest({
      method: 'POST',
      url: API_BASE + '/ingest-mfr',
      headers: {
        'Content-Type': 'application/json',
        'X-PMOAI-TOKEN': TOKEN,
      },
      data: JSON.stringify({ filename: filename, pdf_b64: b64(pdfBuf) }),
      onload: (res) => {
        try {
          const j = JSON.parse(res.responseText);
          if (j.ok) {
            btn.textContent = `✓ ${j.written} funds (${j.period})`;
            btn.style.color = '#155724';
          } else {
            btn.textContent = '✗ ' + (j.error || res.status);
            btn.style.color = '#900';
          }
        } catch (e) {
          btn.textContent = '✗ HTTP ' + res.status;
          btn.style.color = '#900';
        }
      },
      onerror: () => {
        btn.textContent = '✗ pmoai unreachable';
        btn.style.color = '#900';
      },
    });
  }

  function capture(href, btn) {
    btn.textContent = '… downloading';
    btn.disabled = true;
    let name = decodeURIComponent(href.split('/').pop().split('?')[0]) || 'mfr.pdf';
    // PMO serves booklets via PopupMonthlyFundReview.aspx?series=… — name
    // the archive copy after the series (content decides the period anyway).
    const qs = href.split('?')[1] || '';
    const series = new URLSearchParams(qs).get('series');
    if (series) name = 'MFR ' + series + '.pdf';
    GM_xmlhttpRequest({
      method: 'GET',
      url: href,
      responseType: 'arraybuffer',
      onload: (res) => {
        if (res.status !== 200 || !res.response || res.response.byteLength < 1024) {
          btn.textContent = '✗ download failed';
          btn.style.color = '#900';
          return;
        }
        sendToPmoai(res.response, name, btn);
      },
      onerror: () => {
        btn.textContent = '✗ download failed';
        btn.style.color = '#900';
      },
    });
  }

  // Direct .pdf links, MFR popup links (href or onclick), or nothing.
  function pdfUrlFrom(el) {
    const href = el.href || '';
    if (/\.pdf(\?|$)/i.test(href) || /PopupMonthlyFundReview/i.test(href)) return href;
    const oc = (el.getAttribute && el.getAttribute('onclick')) || '';
    const m = oc.match(/['"]([^'"]*PopupMonthlyFundReview[^'"]*)['"]/i);
    if (m) {
      try { return new URL(m[1], location.href).href; } catch (e) { return null; }
    }
    return null;
  }

  function decorate() {
    document.querySelectorAll('a[href], [onclick]').forEach((a) => {
      if (a.dataset.pmoaiMfr) return;
      const href = pdfUrlFrom(a);
      if (!href) return;
      a.dataset.pmoaiMfr = '1';
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.textContent = '→ pmoai';
      btn.style.cssText =
        'margin-left:6px;padding:1px 7px;font-size:11px;cursor:pointer;' +
        'border:1px solid #888;border-radius:3px;background:#fffbe6;vertical-align:middle;';
      btn.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        capture(href, btn);
      });
      a.after(btn);
    });
  }

  decorate();
  // PMO pages render lists dynamically — keep decorating new links.
  new MutationObserver(decorate).observe(document.body, { childList: true, subtree: true });
})();
