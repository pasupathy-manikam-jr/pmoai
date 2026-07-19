# Progress

## 2026-05-18 — Snapshot pipeline + price history fixes

### 1. Snapshot stuck `pending` (auto-reload loop)
- **Cause:** queued `ExtractFundsJob` never ran (no queue worker alive). View
  meta-refreshes every 4s until status terminal → looked like reload bug.
- **Fix:** drained queue (`queue:work --stop-when-empty`); job set status
  `stored` (terminal). Confirmed no AI in snapshot path — `ExtractFundsJob`
  is parse + DB upsert only (Claude/RecommendJob removed).
- Recurring: `SnapshotController::ensureQueueWorker()` spawns a detached
  worker on controller hit (`--max-time=590`); for reliability run a real
  `php artisan queue:work --tries=1`.

### 2. Details page price-history chart
- **Cause:** details page had no chart and no `fund_prices` query. All 21
  `FundDetail` rows have `code = NULL`, so a `detail.code` join finds nothing.
- **Fix:**
  - `FundDetailController::show` resolves code: `detail.code` ?: Fund-catalog
    `normalizeName` match → query `FundPrice` by code.
  - Added inline SVG line chart to `details/show.blade.php` (polyline +
    hover-title dots, range/%-delta header). CSS in `public/css/app.css`.
  - Chart needs >=2 points; shows once >=2 months of history exist.

### 3. Performance returns all NULL (1Y/3Y/5Y/10Y/YTD)
- **Cause:** pasted PERFORMANCE tab lost tab delimiters → each fund a single
  glued line. Both tab-based parsers bailed → returns never merged.
- **Fix:** `PublicMutualParser::parsePerformanceFlat` (routed when no `\t`).
  Splits glued `Code+Name` by longest-prefix match against codes parsed
  from the (tab-agnostic) PRICES tab; regex-extracts factor/class + fixed
  2-decimal return tokens (`--` → null). Legacy tab path untouched.
- Result: 181/181 1Y, 175 3Y, 163 5Y, 124 10Y, 181 YTD populated.

### 4. Snapshot listing missing YTD / 10Y columns
- View-only gap; DB already had the data.
- `snapshots/show.blade.php`: added `YTD%` and `10Y%` columns
  (now Unit · YTD · 1Y · 3Y · 5Y · 10Y).

### 5. fund_prices → MONTHLY series (model change)
- Final model: **one row per fund per month**.
- Migrations:
  - `2026_05_18_000001_drop_fund_prices_unique` — dropped `unique(code,
    price_date)` (intermediate append step).
  - `2026_05_18_000002_fund_prices_monthly` — added `period` (YYYY-MM),
    collapsed rows to one per `(code, period)` (latest id wins),
    `unique(code, period)`, kept `(code, price_date)` index for chart order.
- `ExtractFundsJob::accumulatePrices` upserts on `(code, period)`,
  updating `price`, `name`, `price_date`, `created_at`.
- `FundPrice` model: `period` added to `$fillable`.
- Behavior on re-send:
  - **Same month:** overwrites `price` + `price_date` (real capture day,
    1..end-of-month). Row count unchanged.
  - **New month:** +1 row per fund. Series grows one point/month; detail
    chart grows horizontally.
- Data: 724 (append) → collapsed to 181 (one per code, 2026-05).

### Touched files
- `app/Http/Controllers/FundDetailController.php`
- `app/Http/Controllers/SnapshotController.php` (inspected only)
- `app/Services/PublicMutualParser.php`
- `app/Jobs/ExtractFundsJob.php`
- `app/Models/FundPrice.php`
- `resources/views/details/show.blade.php`
- `resources/views/snapshots/show.blade.php`
- `public/css/app.css`
- `database/migrations/2026_05_18_000001_drop_fund_prices_unique.php`
- `database/migrations/2026_05_18_000002_fund_prices_monthly.php`

## 2026-05-19 — Daily price columns, chart UX, full-width, single-fund AI

### 1. fund_prices → 31 daily columns (model change)
- **Why:** monthly model overwrote one `price`/month → no per-day value;
  today's capture had nowhere to land ("no today column").
- Migration `2026_05_19_000001_fund_prices_daily_columns` — added
  `d1..d31` `decimal(12,4)` nullable; backfilled existing 181 rows from
  `extract(day FROM price_date)`.
- `ExtractFundsJob::accumulatePrices` — still upsert `(code, period)`,
  but writes today's price into `d{day-of-month}` (`$dayCol`). `price`/
  `price_date` kept = latest (chart/RecommendJob back-compat). Update
  column list now `[$dayCol, price, name, price_date, created_at]`.
- `FundPrice` model — `d1..d31` added to `$fillable` + `decimal:4` casts
  via constructor loop.
- Behavior: same day re-capture overwrites that `d{N}`; new day → next
  column; new month → new row. Short months leave `d29..d31` null.

### 2. Detail chart rebuilt from daily columns + axes + tooltip
- `FundDetailController` — `$priceHistory` flatMaps every non-null
  `d{N}` across month rows (ordered `period`) → continuous daily line.
  `resolve()` extracted (shared by `show`/`analyze`).
- `details/show.blade.php` chart:
  - X/Y axis ticks + faint gridlines (min/mid/max price, first/mid/last
    date).
  - Floating legend tooltip (date · price · since-first %), JS-clamped
    fully inside chart box, flips below near top.
  - Dot `r=0.8`, fat invisible `r=4` hit target (dense 31-pt safe).
  - Dropped `preserveAspectRatio="none"` (was ovalising dots); viewBox
    `1400×240` so full-width keeps round dots + sane height.

### 3. Returns (%) table on detail page
- `show.blade.php`: YTD/1y/3y/5y/10y from `Fund` catalog, null-skipped,
  pos/neg colored. (Source has no 2y — PMO doesn't supply it.)

### 4. Full-width layout
- `public/css/app.css`: `body` `max-width:none`, 32px side padding —
  all pages full-bleed (was capped 920px).

### 5. Snapshot ingest now synchronous (kills pending/reload for good)
- **Cause:** `ExtractFundsJob` dispatched to DB queue + flaky self-spawned
  `nohup queue:work` (`ensureQueueWorker`). Dead worker → snapshot stuck
  `pending` → `<meta refresh>` loop.
- **Fix:** `SnapshotController::store()` + `ingest()` →
  `ExtractFundsJob::dispatchSync()`. Runs in-request (~50ms), status
  `stored` before response → refresh condition never true.
- Removed `ensureQueueWorker()` + its `index()` call + `Cache` import
  (no async jobs remain anywhere). No queue worker needed.
- `[[snapshot-stuck-pending]]` memory note now stale (worker removed).

### 6. Single-fund AI analysis (detail page)
- Route `POST /details/{detail}/analyze` → `FundDetailController@analyze`,
  synchronous, stores prose on `detail.payload['ai']`
  (text/provider/at), renders in `.ai-box` with button.
- `Llm::analyzeFund(fund, context)` — interface + `ClaudeService` +
  `GroqService`. Provider-swap via `LLM_PROVIDER` (groq free / anthropic
  paid — Anthropic key absent, stays on groq).
- `FundAnalysisPrompt` (new) — shared system+user builder, both
  providers identical contract.
- **Accuracy layer (#1+#2):** controller precomputes deterministic
  inputs so the LLM interprets, never does arithmetic:
  - `signals()` — period change, max drawdown, peak distance, hi/lo,
    OLS trend slope; volatility/`1y÷vol` unlock at ≥3 captured points.
  - `peers()` — percentile vs same-`category` catalog funds (3y return,
    perf_factor).
  - `profile()` — objective + performance/calendar/distribution from
    `payload`, capped 2500 chars.
- **Prompt tightening:** absent metric = total silence (no "low/weak/
  unknown"); no recompute/annualise; thin section → "Insufficient
  captured data."; `perf_factor` flagged as PMO composite (not
  corroboration); confidence capped `low` when <3 captured points.

### Why this project (vs pasting raw data to an LLM)
- **Moat = data pipeline, not the model.** LLM is swappable/least
  important.
- Time-series memory: `d1..d31` accrues history a one-off paste can
  never reconstruct (chart, volatility, momentum depend on it).
- Deterministic precompute kills LLM math hallucination.
- Catalog enables instant peer percentile (no re-pasting 100+ funds).
- Consistent structured contract + one-click capture across funds/days.
- Honest limit: for a single ad-hoc one-fund look, direct LLM paste is
  equivalent and less effort. Value is **longitudinal** — only pays off
  if captured regularly.

### Touched files (2026-05-19)
- `app/Http/Controllers/FundDetailController.php`
- `app/Http/Controllers/SnapshotController.php`
- `app/Jobs/ExtractFundsJob.php`
- `app/Models/FundPrice.php`
- `app/Services/Llm.php`
- `app/Services/ClaudeService.php`
- `app/Services/GroqService.php`
- `app/Services/FundAnalysisPrompt.php` (new)
- `resources/views/details/show.blade.php`
- `resources/views/snapshots/index.blade.php` (added then reverted)
- `public/css/app.css`
- `routes/web.php`
- `.env` (`LLM_PROVIDER`)
- `database/migrations/2026_05_19_000001_fund_prices_daily_columns.php`

## 2026-05-20 — PDF ingestion (MFR + PHS + macro) → factsheet pipeline

### Why
Catalog + `fund_prices` covered prices and basic returns, but ❌ holdings,
sectors, geo, benchmark, real (Lipper) volatility, AUM exact, dividends,
asset mix, FX. All ground-truth, all printed in PM's monthly MFR + PHS
PDFs. Pull them in, store structured, feed LLM. Closes 9/13 of the gap
parameter list (interest rate / FX / commodity stay macro narrative).

### 1. New table `fund_factsheets` (monthly history per fund)
- Migration `2026_05_20_000001_create_fund_factsheets`.
- Key: `unique(code, period)` where `period = YYYY-MM`. Re-ingest of
  same month overwrites in place; next month adds a row.
- Columns: `fund_size_nav_myr` (decimal RM, not "3,748 mil" string),
  `fund_size_units`, `benchmark_name`, `benchmark_returns` jsonb,
  `volatility_factor` + `volatility_class` (replaces label-only
  `funds.risk`), `asset_allocation` jsonb, `geo_foreign` jsonb,
  `fx_exposure` jsonb, `fx_foreign_total_pct`, `top_sectors` jsonb,
  `top_holdings` jsonb, `distributions` jsonb, `calendar_returns`
  jsonb, `duration_yrs` (bond/sukuk only), `source_pdf`, `captured_at`.
- `FundFactsheet` model: array casts for all jsonb, `decimal:2` casts,
  `latestFor(code)` scope. `Fund::factsheets()` hasMany + `latestFactsheet()`.

### 2. `MfrParser` — monthly Public Series PDF
- `pdftotext -layout` → 2-column page text.
- Split file into per-fund blocks by `PUBLIC … FUND (CODE)` heading
  (54 anchors, 57 funds ingested for April 2026).
- Per block, **column-aware slice** at char column 130 — pdftotext layout
  glues right column onto each left line; without split, geo/sector/
  holdings get corrupted by left-column prefix. `splitColumns()` slices
  per line; left + right then run through their own anchored regexes.
- Section extractors (each returns null on miss, never throws):
  - `fundSize()` — `NAV : RM<n> Million` + `UNITS : <n> Million`.
  - `volatility()` — `VF for this fund is X and is classified as "Y"`.
  - `returnsTable()` — Year-to-Date / 1-year / 3-year / 5-year / 10-year /
    20-year / 30-year / Since Commencement (4 cols: fund total/bench
    total/fund ann/bench ann).
  - `assetAllocation()` — rows under "Asset Allocation as at …".
  - `geoForeign()` — rows under "Geographical Breakdown - Foreign".
  - `fxFromGeo()` — geo % × `config/fx_map.php` (country → ISO-4217
    currency) → `{USD: 13.7, CNY: 3.4, …}` + total foreign %. Derived,
    not parsed — solves the "FX exposure not in any PDF column" gap.
  - `topSectors()` / `topHoldings()` — under "Top 5 Sectors" / "Top 5
    Holdings".
  - `distributions()` — `<key> <sen> <dd.mm.yy> <yield>` rows.
  - `calendarReturns()` — 10-year fund + benchmark calendar strip.
- Period auto-detect: `APRIL 2026` → `2026-04`.
- Command `pmoai:ingest-mfr {path} {--period=}` — upserts on
  `(code, period)`. 57 factsheets written from `MFR April 2026 L.pdf`.
- Spot-check PAGF: NAV RM249.53M, units 353.27M, VF 7.8 (low), asset
  76.4/19.1/4.5, geo USA 13.7/China 3.4/France 2.0 → fx_total 19.1%,
  holdings Public Bank/Tenaga/Maybank/NVIDIA/Meta — all match PDF.

### 3. `PhsParser` — Product Highlights Sheet
- One fund per file. `pdftotext -layout` then `labeled()` helper pulls
  multi-line values for each left-column row label (Category, Fund
  objective, Asset allocation, Location of assets, Investor profile,
  Sales/Redemption/Switching/Transfer/Management/Trustee charge,
  Minimum initial / additional investment).
- Plus `risks()` (KEY RISKS body concat), `avgAnnualReturns()`,
  `ptr()`, `phsDistributions()` (gross/net sen).
- Command `pmoai:ingest-phs {path}` — merges into existing
  `fund_details` row by name (PHS only has name in header; code is
  best-effort). Result stored under `payload.phs` so it never collides
  with `payload.ai` or existing detail-page payload.
- Test: PINDOSF (`P 35 PINDOSF (eng).pdf`) → fund_objective,
  asset_allocation_rule, location, risk_text, fees, avg_annual_returns,
  PTR all populated.

### 4. `MfrMacroParser` + `pmoai:ingest-macro`
- Closes interest-rate / FX backdrop gap that has no per-fund column.
- Reads first 6 pages of MFR with `pdftotext -raw`. Splits on anchors:
  `Most Equity Markets… Monthly Summary`, `Update on Equity Markets`,
  `Update on Bond & Currency Markets`, `Outlook`.
- Each section → `market_events` row (`source='mfr'`, headline =
  section + period, body cleaned of "Source:" / page-number lines,
  capped 8000 chars). Embedding via `EmbeddingService` (Ollama by
  default; `--no-embed` flag for offline test).
- 4 macro events ingested for 2026-04.

### 5. Wired into recommend pipeline
- `FundAnalysisPrompt::user()` — accepts `factsheet` + `macro` in
  `$context`; renders two new sections:
  - `MFR FACTSHEET (latest month, ground truth)` via
    `renderFactsheet()` — period, NAV, units, VF + class, benchmark,
    8-horizon returns table, asset alloc, fx, sectors, holdings,
    distributions, all keyed lines.
  - `MACRO BACKDROP (this month's MFR commentary)` — concat of
    market_events bodies for the factsheet's period.
- `FundDetailController::analyze()` — loads `latestFor($code)`
  + `macro($period)` and passes both into context. Provider-agnostic
  (both Claude + Groq call the same prompt builder).
- `FundDetailController::show()` — passes `$factsheet` to the view.
- **Open**: system prompt at `FundAnalysisPrompt.php:12-47` still says
  "absent from PRECOMPUTED SIGNALS = silence"; should be widened to
  also treat MFR FACTSHEET as ground truth (otherwise model may
  conservatively ignore factsheet values).

### 6. Detail view renders factsheet block
- `resources/views/details/show.blade.php` — new "MFR factsheet"
  section after the Returns (%) table, hidden when no factsheet:
  - KV: Fund size (NAV), Units outstanding, Volatility factor +
    class, Benchmark, Total foreign exposure %.
  - Tables: Fund vs benchmark returns (8 horizons, pos/neg colored
    for total cols), Asset allocation, FX exposure, Geographical
    breakdown (foreign), Top sectors.
  - List: Top holdings.
  - Tables: Distribution history (period/sen/date/yield), Calendar
    year returns 10y (fund + benchmark, pos/neg colored).
- Empty subsections auto-hidden via `@if`.

### Mapping (parameter → store)
| Parameter | Source PDF | DB field |
|---|---|---|
| AUM exact | MFR | `fund_factsheets.fund_size_nav_myr` |
| Holdings | MFR | `top_holdings` |
| Sector alloc | MFR | `top_sectors` |
| Geo alloc | MFR | `geo_foreign` |
| Benchmark | MFR | `benchmark_name` + `benchmark_returns` |
| Volatility (Lipper VF) | MFR | `volatility_factor` + `volatility_class` |
| Dividends | MFR | `distributions` |
| Returns YTD/1/3/5/10y + 20/30y | MFR | `benchmark_returns` (fund + bench) |
| Equity vs bond mix | MFR | `asset_allocation` |
| FX exposure | derived | `fx_exposure` from geo × `config/fx_map.php` |
| Interest rate / GDP / oil narrative | MFR pages 1-6 | `market_events` (rag-ready) |
| Category / fees / risk text / fund objective / PTR | PHS | `fund_details.payload.phs` |

### Touched files (2026-05-20)
- `database/migrations/2026_05_20_000001_create_fund_factsheets.php` (new)
- `app/Models/FundFactsheet.php` (new)
- `app/Models/Fund.php` (added `factsheets()` + `latestFactsheet()`)
- `app/Services/Pdf/MfrParser.php` (new)
- `app/Services/Pdf/PhsParser.php` (new)
- `app/Services/Pdf/MfrMacroParser.php` (new)
- `app/Console/Commands/IngestMfr.php` (new)
- `app/Console/Commands/IngestPhs.php` (new)
- `app/Console/Commands/IngestMacro.php` (new)
- `config/fx_map.php` (new)
- `app/Services/FundAnalysisPrompt.php` (`renderFactsheet()` + factsheet/macro context)
- `app/Http/Controllers/FundDetailController.php` (factsheet + macro into show + analyze)
- `resources/views/details/show.blade.php` (factsheet section)

## 2026-07-05 → 2026-07-19 — Screener → full personal wealth-management system

The app outgrew the original screener. Everything below was built across
these sessions; ARCHITECTURE.md now describes the current system — this
entry is the changelog.

### AI layer rebuilt on Claude CLI (web-researching, subscription-billed)
- `ClaudeCliService` (ACTIVE, `LLM_PROVIDER=claude-cli`) shells
  `claude -p --allowedTools WebSearch,WebFetch`; auth via
  `claude setup-token` OAuth token in env (FPM has no Keychain). Billed to
  the Max subscription — no API credits. Groq kept for testing.
- All AI endpoints async: queue job + detached worker spawn
  (`App\Support\Worker::spawn()` — nohup fails under FPM), status JSON +
  JS polling + progress UI (kills the 30s FastCGI timeout and stale-page
  problems; `no-store` headers + `filemtime()` CSS versioning everywhere).
- Per-fund analysis is position-aware: USER POSITION context (P/L,
  break-even math, years-to-break-even, position age) + series-filtered
  SWITCH CANDIDATES. Holders get KEEP/SELL/REDUCE, non-held BUY/WAIT/AVOID.
  Verdict discipline: SELL/REDUCE only on sustained multi-year weakness;
  <90-day positions protected from churn advice.
- Per-fund chat box (20-msg history) + whole-portfolio review memo
  (`PortfolioReviewJob`: health/concentration/conflicts/live market
  context with citations/action list).

### Portfolio truth from statements
- Holdings auto-capture from PMO account summary (multi-account rows
  summed per fund) → `fund_details.payload.position` + daily
  `portfolio_snapshots` equity curve.
- 28 UT + 6 PRS statement PDFs + 7 PRS contribution summaries ingested →
  `transactions` (TR-ref dedupe). Per-fund XIRR (bisection) with honesty
  guards: completeness by implied-vs-actual units, `partial` when the
  archive (≥Feb 2025 only) can't support the number, `too new` under 90
  days. Fees-paid column. Holdings table shows Original start vs current
  Run since (user sold out and restarted several funds), switch origin,
  and a total row; Past funds table for exited positions.
- PRS: RM3,000/yr tax-relief tracker on Overview (statement-proven,
  synthetic `PRSC-*` refs dedupe future official ingests).

### Decision support
- Price triggers (`alerts`): below/above levels with plain-language
  explanations, checked after every price capture, macOS notification +
  dashboard banner. 8 armed — brackets on Indonesia, EMAS, India +
  verdict levels on AI-Tech, Tactical.
- What-if simulator (`/simulator`): fee-aware switch modelling using
  PMO's real charge schedule (Mutual Gold), source position age
  (auto 0.75%/0.5% under 90 days), and the e-Series rule.
- Domain rules encoded everywhere (prompts, simulator, UI): 4PM MYT
  cut-off Mon–Fri (live countdown), Mutual Gold switching charges,
  **e-Series switches only within e-Series** (catalog detection
  `Pe[A-Z]` case-sensitive; destination lists filtered; cross-series
  priced as redeem + fresh sales charge), PRS never trade-advised.

### Data pipelines added
- MFR factsheet booklets via userscript button + bulk PDF download with
  per-page memory; PRS fund reports via public-site API (no login,
  `pmoai:fetch-prs`); fund-detail auto-capture (merge-preserving, code
  self-heal); page reconnaissance captures.
- Junk-row guard on catalog ingest (killed risk-word/category phantom
  rows); catalog steady at ~190 funds; all code joins upper()-insensitive.

### UI
- Dashboard = landing page (`/` redirects to the singleton snapshot).
  One card, classic folder tabs: Overview (default) | My holdings |
  Past funds | AI review | Fund catalog. Fired alerts banner above.
  Fund detail rebuilt as dossier with verdict stamp. Blade + vanilla JS
  + hand-rolled CSS tokens (no framework migration).

### Repo
- 2026-07-19: git init, private GitHub `pasupathy-manikam-jr/pmoai`
  (noreply commit email). Excluded: `.env`, all `storage/app` captures
  (statements/PRS/MFR PDFs, CSVs), logs; userscript ingest token replaced
  with a placeholder in the repo copies.

### Real-world outcomes
- Indonesia REDUCE executed (halved into PIATAF at RM0 switch charge,
  near the local bottom); PIATAF now KEEP with review conditions.
- Portfolio XIRR truth: Gold-account +6.93%/yr (complete), PRS +7.77%/yr
  since 2019; ~RM6,380 lifetime fees surfaced.

### Open
- EPF ~5.5%/yr benchmark line on the equity curve (last roadmap item).
- PBINDOBF factsheet parse glitch (benchmark_name='2022').
- User money decisions pending: e-Cash deployment, AI-Tech trim
  (e-series destinations only), EMAS trim (trigger bracket armed).
