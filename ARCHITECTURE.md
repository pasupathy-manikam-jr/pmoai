# pmoai — Public Mutual AI Advisor

Laravel app. Paste Public Mutual (Malaysia) fund data + your goals → AI suggests
buy/hold/sell, grounded in real trailing returns + macro context.

---

## Stack

| Layer | Choice |
|---|---|
| Framework | Laravel 13.9 / PHP 8.3 |
| DB | PostgreSQL 17 + pgvector 0.8.2 (HNSW cosine) |
| LLM (active) | **claude-cli** — `ClaudeCliService` shells `claude -p` (Claude Code headless, billed to the Claude Max subscription, no API credits). `analyzeFund` → Claude; `extractFunds`/`recommend` delegate to Groq. Needs the `claude` binary logged in on this Mac; ~1-2 min per analysis. |
| LLM (testing) | **Groq** `llama-3.3-70b-versatile` — free tier, function calling, **no web search** |
| LLM (paid alt) | **OpenAI** `gpt-4o-mini` — API key from platform.openai.com (a ChatGPT sub gives NO API access), **no web search** |
| LLM (prod) | **Anthropic** `claude-opus-4-7` — paid API credits, built-in web search |
| Embeddings | **Ollama** `mxbai-embed-large` — local, free, 1024-dim |
| Queue | database driver (`jobs` table) |
| Serve | MAMP vhost `https://pmoai.local:8890` (docroot = `public/`, PHP 8.3) |

LLM provider swap: `.env` `LLM_PROVIDER=groq|openai|anthropic` → `AppServiceProvider`
binds `App\Services\Llm` to `GroqService` / `OpenAiService` / `ClaudeService`.
`OpenAiService extends GroqService` (same OpenAI wire format); `GroqService::call()`
is prefix-driven via `config("ai.{prefix}_key/_model/_chat_url")`.
Embedding provider: `.env` `EMBED_PROVIDER=ollama|voyage|openai`.

---

## Data flow

```
3 tabs (paste OR file path)            feedback text
   │                                        │
   ▼                                        ▼
SourceLoader (rtf/rtfd/html → text)    (stored UserFeedback)
   │
   ▼  controller joins with sentinels
[[PMOAI:PRICES]] … [[PMOAI:PERFORMANCE]] … [[PMOAI:INFO]] …
   │
   ▼  ExtractFundsJob
PublicMutualParser  → parse each tab, merge by fund CODE
   │                  → funds table + storage/app/snapshots/{id}.csv
   ▼  RecommendJob
EmbeddingService (Ollama) embed feedback → pgvector recall prior feedback
   │
   ▼
Llm.recommend  (compact 1-line/fund: returns + class)  → recommendations
   │
   ▼
snapshots.show (auto-refresh until recommended/failed)
```

---

## Data capture — console snippet (primary method)

The site is a JS data-grid; manual copy is unreliable. Capture each tab via a
read-only browser-console snippet that emits clean **header-TSV**:

```
Code <tab> Name <tab> <site headers…>
<one fund per line>
```

### The snippet (run per tab, F12 → Console)

```js
(() => {
  const t=[...document.querySelectorAll('table')].sort((a,b)=>b.rows.length-a.rows.length)[0];
  const rows=[...t.rows];
  const lens=rows.filter(r=>r.querySelector('td')).map(r=>r.cells.length);
  const W=[...new Set(lens)].sort((a,b)=>lens.filter(x=>x===b).length-lens.filter(x=>x===a).length)[0];
  const hr=rows.find(r=>!r.querySelector('td')&&r.cells.length===W)||rows.find(r=>r.cells.length===W);
  const H=[...hr.cells].map(c=>c.innerText.trim().replace(/\s+/g,' '));
  const cell=td=>{
    if(td.querySelector('.fa-star-and-crescent')) return 'Shariah';
    let t=(td.innerText||'').trim().replace(/\s+/g,' ');
    if(!t){const p=td.querySelector('.popover_content_wrapper');
      if(p&&p.textContent.trim())t=p.textContent.trim();
      else{const i=td.querySelector('img[src*="risk" i]'),m=i&&i.src.match(/risk(\d)/i);
        if(m)t=({1:'Very Low',2:'Low',3:'Moderate',4:'High',5:'Very High'})[m[1]]||'';}}
    return t;};
  const out=[['Code','Name',...H.slice(2,-1)].join('\t')];
  for(const r of rows){
    if(!r.querySelector('td')||r.cells.length!==W) continue;
    const c=[...r.cells];
    const fund=(c[1].innerText||'').split('\n').map(s=>s.trim()).filter(Boolean);
    if(!fund[0]) continue;
    const rest=c.slice(2,-1).map(cell);
    out.push([fund[1]||'',fund[0],...rest].join('\t'));
  }
  const w=window.open('','_blank');
  w.document.write('<pre>'+out.join('\n').replace(/</g,'&lt;')+'</pre>');
  w.document.close();
  console.log('W=',W,'data rows:',out.length-1);
})();
```

Steps: open tab → run → new tab opens → ⌘A ⌘C → paste matching app box
(Prices/Performance/Info). Repeat per tab (3×). Console shows
`W= … data rows: ~181`. `data rows: 0` → wrong table; capture the table's
`<thead>`/first 3 `<tr>` to retarget.

Snippet logic:
- pick largest `<table>`; **W = modal data-row cell count** (skips the
  group/super-header row that broke naive `th>2` detection on Performance);
- header row = the no-`td` row with `W` cells;
- Fund cell = `NAME\nCODE` *or* `CODE\nNAME` (order differs per tab) — emitted
  as two cols, **parser decides which is the code by shape** (spaceless,
  `^[A-Za-z][A-Za-z0-9.\-]{1,13}$`), not position;
- dumps to a new tab; user ⌘A ⌘C → paste into the matching app box.

Security: read-only, no auth/requests/loops; only run code the user can read
(self-XSS caution on a banking site). Manual paste / saved-file path still work
as fallbacks.

## Automated capture — Tampermonkey userscript (preferred)

`tools/pmoai.user.js`. Browser Same-Origin Policy means the app cannot script
the PMO site — automation runs **on the PMO pages** via a userscript.

- `@match https://www.publicmutualonline.com.my/pmo/*` (the `s=` query param
  is a per-session encrypted token — can't pin, match the path).
- ASP.NET tab switches are **postbacks** (no reload) → a `MutationObserver`
  re-captures whenever the table DOM changes (covers postback tabs AND
  separate URLs).
- On load + each table change: scrapes, **auto-detects tab type from header**
  (perf: YTD/1-Yr/Factor · info: Shariah/Category/Inception · prices:
  Price/Change), stores TSV in `GM_setValue('cap_<kind>')`.
- Floating panel: shows ✅/⬜ per tab, goals box, "Send to pmoai".
- Send → `GM_xmlhttpRequest` POST (bypasses CORS) to `POST /ingest`.

Endpoint `SnapshotController::ingest` — token auth via `X-PMOAI-TOKEN` ==
`config('ai.ingest_token')` (`PMOAI_INGEST_TOKEN` in `.env`,
`hash_equals`). CSRF excluded for `ingest` in `bootstrap/app.php`
(`preventRequestForgery(except:['ingest'])`). Builds the sentinel blob →
Snapshot + feedback → `ExtractFundsJob` → returns `{id,url}`.

Setup: install Tampermonkey → paste `tools/pmoai.user.js` → set `TOKEN` to
`PMOAI_INGEST_TOKEN` → fix `@match`/`API_BASE` if needed. Visit the 3 PMO
tab pages once, type goals, Send. SSL: `pmoai.local` cert must be accepted
(or point `API_BASE` at the MAMP http port).

## The 3 tabs (Public Mutual site)

The "Action" column pastes junk tokens (`Add Favourite`, `Chart`,
`Add to Compare`, `Buy`) — parser strips them.

### Fund Prices (required)
`Fund | Date | Price RM | Change RM | Change %`
Layouts handled: token-per-line (RTF), tab-glued line, **CSV export**
(col0 = `"NAME\nCODE"`).

### Fund Performance (optional — the trend data)
`Fund | Date | Factor | Class | YTD | 1-Yr | 3-Yr | 5-Yr | 10-Yr`
Class = risk band (e.g. `V. HIGH`). 10-Yr may be absent for newer funds.
**Without this tab, returns = `na` and the model will NOT judge performance.**

### Fund Info (optional)
`Fund | Shariah | Category | Risk | Since Inception | Fund Size (RM)`
e.g. `MA` = Mixed Asset, `14 Yrs`, `3,748 mil`.

Merge key = **fund code** (PBISCF, PIATAF…). Falls back to upper(name).

### Parser paths (priority order)
1. **header-TSV** (`parseHeaderTsv`) — console-snippet format; columns mapped
   by header label via `classify()`; name/code shape-normalized. Primary.
2. legacy token / tab-glued / CSV-export — fallback for manual paste & old
   snapshots.

All three segments run through the same path; `overlay()` merges non-empty
fields across tabs by code (Prices px, Performance returns, Info cat/risk/size).

---

## DB schema (key tables)

- `snapshots(id, user_id, raw_text, status)` — status: pending→extracted→recommended|failed
- `funds(snapshot_id, name, fund_type, shariah, category, risk, unit_price,
  return_ytd/1y/3y/5y/10y, perf_factor, perf_class, perf_date,
  since_inception, fund_size, currency, extra json{code,change_pct,price_date})`
- `user_feedback(snapshot_id, text, embedding vector(1024))` — HNSW cosine
- `market_events(headline, body, embedding vector(1024))` — HNSW (path A, unused with Groq)
- `recommendations(snapshot_id, fund_name, action, target_weight, rationale, model)`

All tables owned by role `pmoai` (reassigned). Use `.env` creds for DB ops.

---

## Prompt guardrails (fixes fabricated-trend bug)

Fund line sent to LLM:
`name | type | Shariah | px=price | d=1-day% | YTD | 1Y | 3Y | 5Y | 10Y | class`

Rules enforced in system prompt:
1. `na` return → DO NOT invent history / "high/low in its history" — say unavailable.
2. `d=` is 1-day move only, never a trend.
3. Prefer strong 3Y/5Y; flag YTD ≫ long-run as possibly extended.
4. Hundreds of funds → select 10–15 most relevant.

**Groq 12k TPM cap:** ~180 fund lines (with returns) ≈ 13k tokens > limit.
`GroqService::recommend` ranks by 3Y return (fallback 1Y) and **caps to 120**
before building the prompt; the model picks the final 10–15 from that
shortlist. Anthropic path uncapped (higher limits).

---

## .env (key vars)

```
DB_CONNECTION=pgsql  DB_DATABASE=pmoai  DB_USERNAME=pmoai  DB_PASSWORD=pmoai_secret_2026
LLM_PROVIDER=groq            GROQ_API_KEY=gsk_...   GROQ_MODEL=llama-3.3-70b-versatile
ANTHROPIC_API_KEY=           ANTHROPIC_MODEL=claude-opus-4-7
EMBED_PROVIDER=ollama        EMBED_MODEL=mxbai-embed-large   EMBED_DIM=1024
OLLAMA_URL=http://localhost:11434
APP_URL=https://pmoai.local:8890
```

---

## Run

```bash
ollama serve                       # embeddings (model already pulled)
# MAMP serves public/ at https://pmoai.local:8890  (PHP 8.3)
php artisan queue:work --tries=1 --timeout=200    # REQUIRED, separate process
```

Open site → paste Prices (and ideally Performance) tab + goals → submit →
auto-refreshes to recommendations. `snapshots/{id}.csv` = parsed-funds artifact.

---

## Operational rules

- ⚠️ **Restart `queue:work` after ANY code change** — worker caches code in
  memory. Stale worker = old behavior / hallucinated runs. For dev use
  `php artisan queue:listen` (reloads per job, slower).
- File-path inputs must be **under the user's home dir** (SourceLoader guard).
- RTF/RTFD/HTML/webarchive auto-converted via macOS `textutil`; HTML tags stripped.
- Groq free tier = 12k tokens/min. Compact fund lines keep large lists under it.
- Claude.ai/Claude Code subscription ≠ Anthropic API credits (separate billing).

---

## Known limits / next

- Groq (testing) has no web search → no live wars/market; macro = model knowledge
  only. Switch `LLM_PROVIDER=anthropic` for live web search.
- Info tab `MA`/risk mapping is heuristic — verify against real Info paste.
- Single-snapshot only; no cross-date price history yet (could accumulate by code).
- llama-3.3-70b sometimes returns fewer recs than the 10–15 target.

---

## Automation (Tampermonkey) + ingest API

- `tools/pmoai.user.js` runs on `publicmutualonline.com.my/pmo/*` (DataTables
  `#tdFundList`, ASP.NET postback tabs — same URL). Panel: **Capture this
  tab** (manual, reliable), status ⬜/✅, goals box, **Send to pmoai**,
  **Clear**. `cellText()` reads Shariah icon + hidden `.popover` risk +
  `risk{n}` img since those aren't in `innerText`.
- POST → `/ingest` (token `X-PMOAI-TOKEN` == `PMOAI_INGEST_TOKEN`,
  CSRF-exempt). Builds sentinel blob → Snapshot → jobs.
- `/` = snapshot **index** (list, status, counts, auto-refresh). `/new` =
  manual form. `/snapshots/{id}` = result. Userscript ≠ form: it hits the
  API directly, never populates the form.
- `tools/pmoai-mfr.user.js` (2026-07-12) runs on `publicmutual.com.my` (and
  PMO): adds a "→ pmoai" button beside every PDF link. Click = fetch PDF
  in-browser → POST base64 to `/ingest-mfr` (same token, CSRF-exempt) →
  `App\Services\MfrIngest` (shared with `pmoai:ingest-mfr` command, which
  also accepts a directory) parses via `MfrParser` and upserts
  `fund_factsheets`. PMO reuses one generic filename per booklet — the
  report period comes from PDF content; uploads are archived timestamped
  under `storage/app/mfr/`. Codes normalised (spaces stripped:
  "P ITTIKAL" → PITTIKAL) to join the catalog. `MfrParser` resolves an
  absolute `pdftotext` (PHP-FPM PATH lacks homebrew; `PMOAI_PDFTOTEXT_BIN`
  overrides).

## Recommendation semantics (screener, not portfolio)

App knows PMO's **public universe** plus any holdings the user names in the
feedback text: a catalog fund whose name (normalised, ≥8 chars) or code
appears in the feedback is tagged **OWNED** (`RecommendJob`), always included
in the shortlist, and must get `keep`/`sell`. Actions:
`buy` = good entry now · `watch` = quality but extended/near-high, wait ·
`avoid` = weak/poor fit · `keep`/`sell` = OWNED funds only.
`RecommendJob` lowercases and whitelists the action set.

The single-fund DETAIL page prompt (`FundAnalysisPrompt`) also emits a
"Verdict for a current holder: KEEP | SELL | REDUCE" section (2026-07-05;
previously education-only with no verdict).

**Hallucination guard**: the model must echo `fund_code` in its JSON; the
`RecommendJob` matches by code first (authoritative), then falls back to
`matchFund`/`norm` — exact normalised name or unambiguous containment
(≥8 chars, single candidate) — else dropped. Persists the canonical name.
Both prompts also forbid macro/market claims (no web access) and require
rationale numbers to be copied verbatim from the data lines. Killed invented "PUBLIC GLOBAL SUSTAINABLE GROWTH".

**Peak-risk tag** in `GroqService`/`ClaudeService` fund line:
- ≥5 days real history → `NEAR-HIGH(x% below peak)` / `OFF-PEAK` from
  `fund_prices` (trusted over returns).
- <5 days → fallback heuristic `EXTENDED` (1Y ≫ 5Y-annualised ⇒ ran up).
- Hard rule: NEAR-HIGH/EXTENDED can never be BUY → WATCH.

## Price history accumulator + prune

- `fund_prices(code,name,price,price_date)` unique`(code,price_date)`.
  `ExtractFundsJob::accumulatePrices` upserts every ingest — slim, permanent,
  the real 52-wk-high signal as daily captures accumulate.
- `php artisan pmoai:prune --days=14` — clears `raw_text`, funds, recs,
  feedback, CSV of old snapshots; **keeps `fund_prices` + snapshot stub**.

## Status — last session (2026-05-16)

**WORKING end-to-end** via Tampermonkey (snapshot 11: 181 funds, px+returns
merged, real names). Screener semantics live; hallucination guard live;
`fund_prices` accumulating (needs ≥5 days for real NEAR-HIGH, heuristic
EXTENDED until then).

**Groq quota lesson:** free tier limits are **per-model per-day** (TPD
100k). 70b exhausted after ~10 reprocess runs. 8b-instant + qwen3-32b both
failed strict function-calling (`tool_use_failed`, malformed/unquoted JSON).
FIX: `GroqService::recommend` now uses **JSON mode**
(`response_format:json_object`), no tools/enum — robust on any model, we
parse + lowercase + filter ourselves. Current `GROQ_MODEL=llama-3.3-70b-versatile`
(since 2026-07-05 — stronger rule-following than qwen3-32b; TPD pools reset
daily, swap back to qwen in `.env` on 429). Shortlist is now mixed: OWNED
funds always in, then ~60% top-3Y + remainder by 5Y, cap 35 — fixes the
all-EXTENDED/AVOID skew. Line = `CODE | name | S/- | OWNED? | 1Y/3Y/5Y | TAG`.
Historical qwen notes below still apply if qwen is re-enabled:
qwen3 TPM is only **6000** (vs 70b 12k) → fund cap cut to **35** and the
compact line trimmed to `name | S/- | 1Y/3Y/5Y | TAG`. qwen3 is a reasoning
model: it emits `<think>` which breaks json_object → append `/no_think` to
system+user and strip `<think>…</think>` + slice to outer `{…}` before
`json_decode`. (`extractFunds` still uses the old tool path but is unused —
parser is deterministic.) Known skew: shortlist = `sortByDesc(3Y)` surfaces
the biggest flyers (all EXTENDED) → mostly AVOID; mix in mid-performers to
get BUY candidates (tuning, open).

**DECISION — bulk chart-history scrape: SKIPPED (do not build).** Looping
~190 per-fund history fetches on a secured trading site risks anti-bot /
account flags / ToS breach; not worth it. Real peak signal comes from the
zero-risk `fund_prices` accumulator (one date/day, ~3–4 wks to maturity).
If ever needed faster: fetch history only for the 10–15 shortlisted funds
on-demand (indistinguishable from manual clicks) — deferred, not needed.

Open / next:
- llama paraphrases fund names → guard drops them → thin rec lists.
  Fix: add `fund_code` to the compact line + JSON output, match by code
  (near-zero drop). **Highest-value next task.**
- Optional cross-day momentum (slope from `fund_prices`) once history grows.
- Schedule `pmoai:prune` (cron/Laravel scheduler) instead of manual.
