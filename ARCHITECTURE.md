# pmoai — Public Mutual AI Advisor

Personal wealth-management system for a Public Mutual (Malaysia) investor.
Captures the full PMO universe (prices, holdings, statements, factsheets),
computes deterministic truth (XIRR, P/L, signals), and layers a web-researching
AI (Claude CLI) on top for position-aware verdicts, chat, and whole-portfolio
reviews. Single user, Mutual Gold tier.

---

## Stack

| Layer | Choice |
|---|---|
| Framework | Laravel 13.9 / PHP 8.3 |
| DB | PostgreSQL 17 + pgvector 0.8.2 (HNSW cosine) |
| LLM (ACTIVE) | **claude-cli** — `ClaudeCliService` shells `claude -p --output-format text --allowedTools WebSearch,WebFetch`. Auth via `CLAUDE_CODE_OAUTH_TOKEN` (from `claude setup-token`, billed to the Claude Max subscription — no API credits). Web research built in. ~1–2 min per analysis. |
| LLM (testing) | Groq `llama-3.3-70b-versatile` — free tier, no web |
| LLM (dormant) | OpenAI (`OpenAiService extends GroqService`, prefix-driven) · Anthropic API (`ClaudeService`) |
| Embeddings | Ollama `mxbai-embed-large`, local, 1024-dim |
| Queue | database driver + self-spawned detached workers (see Async pattern) |
| Serve | MAMP vhost `https://pmoai.local:8890` (docroot `public/`, PHP-FPM 8.3) |

Provider swap: `.env` `LLM_PROVIDER=claude-cli|groq|openai|anthropic` →
`AppServiceProvider` binds `App\Services\Llm`. Interface:
`extractFunds / recommend / analyzeFund / chat` (+ `raw()` on the CLI service
for the portfolio review).

### PHP-FPM environment quirks (recurring trap)

- FPM PATH lacks homebrew → resolve absolute binaries (`pdftotext`,
  `PMOAI_PHP_BIN=/Applications/MAMP/bin/php/php8.3.30/bin/php`).
- No macOS Keychain access under FPM → the Claude CLI must auth via the
  OAuth token env var, never keychain login.
- 30s FastCGI idle timeout → anything slow (LLM calls) runs as a queue job.
- `nohup` fails under FPM ("can't detach") → workers spawn via detached
  subshell: `(… queue:work --stop-when-empty … < /dev/null >> log 2>&1 &)`
  (`App\Support\Worker::spawn()`).

### Async job pattern (all AI endpoints)

POST endpoint → create pending record / set `ai_status` → dispatch job →
`Worker::spawn()` → respond immediately. JS polls a status JSON endpoint,
shows progress, reloads (cache-busted) when done. Used by: fund analysis,
fund chat, portfolio review. All result pages send
`Cache-Control: no-store`; CSS is versioned with `filemtime()`.

---

## Data capture (all pipelines)

Browser Same-Origin Policy means the app can't script the PMO site —
automation runs **on** the PMO pages via Tampermonkey userscripts that POST
to token-authed, CSRF-exempt ingest endpoints (`X-PMOAI-TOKEN` ==
`PMOAI_INGEST_TOKEN`, `hash_equals`).

`tools/pmoai.user.js` (v1.16) — the main userscript. Repo copy carries a
placeholder token; the installed copy holds the real one. Inert on
login/password/security pages; `@connect` pmoai.local only; reads
`innerText` only.

| What | How | Lands in |
|---|---|---|
| Catalog prices/performance/info (3 tabs, ~190 funds) | capture panel → `POST /ingest` → `ExtractFundsJob` → `PublicMutualParser` | `funds` (catalog, one row per fund) + `fund_prices` |
| **Holdings** (account summary, multi-account) | auto-detects holdings table on `Ut_AcctSummary.aspx`, sums same-fund rows across accounts → `POST /ingest-holdings` | `fund_details.payload.position` + `portfolio_snapshots` + price feed |
| Fund detail pages (objective, performance tables) | auto-capture on fund detail pages → `POST /ingest-detail` (merge-preserving: never wipes `position`/`ai`; self-heals NULL codes from catalog by name) | `fund_details.payload` |
| Arbitrary PMO pages (reconnaissance) | "collect" button → `POST /ingest-page` (stores text + links) | `page_captures` |
| Transaction statement PDFs (bulk) | statement rows are ASP.NET postbacks (`a[href*="Download$"]`) — bulk-download button with per-page memory (`GM_setValue`, Alt-click resets) | files → `pmoai:ingest-statements` |
| MFR factsheet PDFs | `tools/pmoai-mfr.user.js` "→ pmoai" button per PDF link → base64 → `POST /ingest-mfr` | `fund_factsheets` via `MfrParser` |

No-login automation: `pmoai:fetch-prs` hits the public
publicmutual.com.my API (RequestVerificationToken + cookie jar →
`GetFilteredFundList` / `GetFundReportBySchemeCode` / `DownloadDocByDocId`
base64 PDF) for the 9 PRS fund reports.

### Statement ingestion (the transactions truth)

- `pmoai:ingest-statements {dir}` — parses "Statement of Transactions"
  PDFs: backward token-parse per row, dedupe by TR reference.
- `pmoai:ingest-prs-statements` — parses "SUMMARY OF PRS CONTRIBUTIONS"
  yearly PDFs (handles continuation rows carrying only date+amount);
  synthetic refs `PRSC-{acct}-{Ymd}` so manual reconstructions dedupe
  against future official ingests.
- Archive limit: PMO only serves statements back to ~Feb 2025 — older
  history is incomplete, which the XIRR layer must (and does) admit.

---

## Deterministic money math

- **`App\Services\Xirr`** — bisection XIRR per fund from `transactions`.
  Flow signs by type (acq II/AI/RII/SI/SWS/DR = out; SWR/RP/SRR/DP/REV/COF
  = in). Honesty guards: units-implied-vs-actual completeness check (±2%,
  price fallback to `FundPrice` for unit-less money-market rows) → result
  is `complete` / `partial` / `too new` (<90 days). Also sums fees paid.
- **`FundAnalysis::position()`** — P/L, break-even %, years-to-break-even
  at 4/6/8%/yr — precomputed so the LLM quotes, never derives.
- **`FundAnalysis::signals()`** — period change, drawdown, peak distance,
  OLS slope, volatility from captured dailies.
- **`AlertCheck`** — price triggers (`alerts` table: fund_code,
  below/above, level, label, explanation). Evaluated after every price
  capture; fires once, macOS notification via `osascript`, banner on
  dashboard until acknowledged.
- **`SnapshotController::show()`** — holdings rows: original start date
  (PMO "Initial Investment on"), current **run start** (user often sold
  out and restarted — detected by backward scan for the acquisition
  suffix covering ≥98% of invested), origin fund for switches (SWS ref →
  account map), XIRR + fees per fund, PRS tax-relief tracker
  (`RM x / 3,000` for the current year).

## Domain rules (encoded in prompts + UI + simulator)

1. **Trading mechanics:** orders execute at same-day price only before
   4:00 PM MYT on trading days (Mon–Fri, excl. public holidays). Compute
   in `Asia/Kuala_Lumpur` (app TZ is UTC). Dashboard shows a live cut-off
   line.
2. **Switching charges (Mutual Gold):** fund-to-fund via PMO after 90
   days = FREE; within 90 days ~0.75% (e-series 0.50%); zero-load
   (cash/MM) units → equity/mixed/balanced = fresh sales charge up to 5%
   (3.75% e-series), → bond up to 1% (0.65% e-series); EPF switches free.
3. **Series rule:** e-Series funds ("e-" in name, codes `Pe[A-Z]…`,
   case-sensitive — `PEBF` is NOT e-series) switch ONLY within e-Series;
   non-e only within non-e. Crossing = redeem to cash + fresh purchase at
   full sales charge. `FundAnalysis::isESeries()`; switch candidates are
   series-filtered; simulator narrows the destination list and prices the
   redeem path.
4. **PRS:** deliberate RM3,000/yr top-ups (tax-relief max), locked to age
   55 (8% penalty), never trade-advised.
5. **Verdict discipline:** SELL/REDUCE only on SUSTAINED multi-year
   weakness, never day/week moves; positions <90 days old are "too new"
   for churn advice (KEEP + named review condition).

---

## AI layer

- **Per-fund analysis** (`AnalyzeFundJob` → `FundAnalysis::run`) —
  context: catalog facts, precomputed signals, MFR factsheet, peers,
  macro, USER POSITION (with break-even + age), series-filtered SWITCH
  CANDIDATES. `FundAnalysisPrompt::system(bool $owned)` swaps the verdict
  block: holders get KEEP/SELL/REDUCE (position rules a–f), non-held get
  BUY/WAIT/AVOID (near-high can never be BUY). ClaudeCliService prepends
  a web-research override (2–4 sourced searches, inline citations).
- **Per-fund chat** (`ChatFundJob`, 20-message cap) — same ground-truth
  context + web tools when on claude-cli.
- **Portfolio review** (`PortfolioReviewJob`) — all positions + verdicts
  + triggers + value history → fixed-section memo (~350 words): health
  check, concentration, conflicts & gaps, live market context (sourced),
  action list, review-again-when. Rendered in the AI review tab.
- Anti-hallucination: all numbers precomputed and quoted verbatim;
  absent metric = total silence; no disclaimers in output (page footer
  has one); junk-row guard in `ExtractFundsJob` rejects non-fund rows.

## UI

- `/` redirects to the single dashboard (`/snapshots/{id}` — singleton
  snapshot model: ONE snapshot ever; `funds` is the catalog;
  `fund_prices` is the only time-series).
- **Dashboard** (`snapshots/show.blade.php`): eyebrow + 4PM countdown +
  PRS tracker → fired-alert banners → one card with classic folder tabs
  (full-width): **Overview** (default: hero tiles, armed triggers with
  expandable explanations + live distance, equity curve from
  `portfolio_snapshots`) | **My holdings** (Original / Run since / Origin
  / Invested / Value / P/L / XIRR / Fees + total row) | **Past funds**
  (exited positions) | **AI review** | **Fund catalog** (~190 funds,
  search by name/type/shariah).
- **Fund detail** (`details/show.blade.php`): dossier layout, verdict
  stamp, SVG daily price chart, factsheet block, position P/L line,
  analysis + chat with polling.
- **What-if simulator** (`/simulator`): client-side; models a switch —
  auto-suggests the charge from the schedule + source position age,
  enforces the series rule (narrowed destination list, cross-series
  warning), compounds stay-vs-move at each fund's own 5Y rate, shows
  weight shifts.
- Styling: hand-rolled tokens in `public/css/app.css` (shadcn-like cards,
  folder tabs) — Blade + vanilla JS, NO React/Tailwind. Blade traps:
  `@json` needs `@php`-prepped variables; `{{ }}` HTML-escapes quotes
  inside JS.

---

## DB tables (current)

`snapshots` (singleton stub) · `funds` (catalog) · `fund_prices`
(one row per code+month, `d1..d31` daily columns) · `fund_details`
(payload jsonb: objective/performance/position/ai/phs) ·
`fund_factsheets` (unique code+period, MFR structured) · `transactions`
(unique trans_ref) · `portfolio_snapshots` (daily value) · `alerts` ·
`portfolio_reviews` · `page_captures` · `market_events` (+pgvector) ·
`user_feedback` (+pgvector)

## .env (key vars)

```
LLM_PROVIDER=claude-cli   PMOAI_CLAUDE_TOKEN=sk-ant-oat01-…
GROQ_API_KEY=…            GROQ_MODEL=llama-3.3-70b-versatile
PMOAI_INGEST_TOKEN=…      PMOAI_PHP_BIN=/Applications/MAMP/bin/php/php8.3.30/bin/php
DB_*=pmoai / pgsql        APP_URL=https://pmoai.local:8890
EMBED_PROVIDER=ollama     EMBED_MODEL=mxbai-embed-large
```

## Operational rules

- Long-running AI work is queue jobs + `Worker::spawn()`; a stuck
  "Analyzing…" usually means no worker — check `storage/logs/queue*.log`.
- Restart any long-lived `queue:work` after code changes (in-memory code
  cache); the self-spawned `--stop-when-empty` workers avoid this.
- Fund codes are mixed case (`PeEMAS`) — every code join uses
  `whereRaw('upper(code) = ?')`.
- tinker: complex `--execute` strings parse-error — write a script file
  and `require` it.
- Git: private repo `pasupathy-manikam-jr/pmoai`; commits use the GitHub
  noreply email; `.env`, `storage/app` captures, and real tokens never
  committed (userscript token is a placeholder in the repo).
