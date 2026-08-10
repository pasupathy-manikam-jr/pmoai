# PMFAI — Enhancement Plan

A roadmap for pmoai / PMFAI. The app's core value is **visibility** — surfacing
fees, rules, cost basis, and concentration that the provider's UI hides — not
market-beating stock picks. Enhancements below deepen that visibility and cut
the manual steps.

**GROUNDING RULE (non-negotiable).** Every feature must be built on *Public
Mutual's own data and mechanics* — the funds you actually hold, their captured
factsheets (NAV, volatility factor, benchmark, geo/sector/holdings), your real
statements/transactions, and the real PMO rules (sales charges, 90-day
free-switch, e-Series-only switching, no-switch funds, 4 PM MYT cut-off, PRS
RM3,000 relief). No generic-investing-app features, no imagined metrics, no
"best practices" copied from elsewhere. If a proposed feature can't point to a
specific piece of captured PMO data or a documented PMO rule, it doesn't belong
here.

**Status (2026-08-05):** Phases 0, 1, 2, 4, 5 ✅ shipped · Phase 3 skipped · Phase 6
mostly ✅ (parser fix left) · Phase 7 ✅ all four · Phase 8 core ✅ (income +
exposure-over-time data-blocked) · Phase 9 partial · Phase 10 open.
_Superseded line below:_ Phase 6 was "in progress" on 08-03.

**Status (2026-08-03):** Phases 0, 1, 2, 4, 5 ✅ shipped · Phase 3 skipped (reach
features — not needed for a local, single-user app) · Phase 6 🚧 in progress
(sector look-through + stock overlap shipped; fx parser + risk-adjusted left) ·
**Data resilience** shipped (Yahoo query1→query2 host failover + Twelve Data
fallback for USD/MYR + gold). Phases 7–10 are the forward roadmap.

---

## Phase 0 — close open loops (quick, low-risk)

- **Float / pending auto-ingest** — a `pmoai:ingest-float` parser (two formats:
  fund switch + PRS contribution) plus a userscript button. Replace-snapshot
  semantics (the float statement is a full list of what's pending). Removes the
  manual entry done for the 29 Jul items.
- **Pending → settled reconcile** — when a settled statement row lands matching a
  pending fund + units, auto-clear the pending row.
- **`.gitignore` leak-guards** — add `*.sql`, `*.dump`, `*.sqlite`,
  `holdings*.json`, `storage/app/mfr/` so a future accidental commit can't leak
  data. Pre-public safety.
- **Re-run AI review** — regenerate the portfolio review + per-fund analyses so
  the new plain-English prompts take effect (existing output is cached).
- **Finish rebrand (optional)** — coordinated internal `pmoai → pmfai` rename
  (env vars, `X-PMOAI-TOKEN` header, `pmoai:` artisan prefix, DB, storage,
  userscript) and de-brand the LLM prompts ("Public Mutual" → "Malaysian unit
  trust").

## Phase 1 — live data (highest analytical value)

- **Index quote feed** — a server-polled index API (JCI, KLCI, gold, NASDAQ,
  USD/MYR) stored locally so **triggers fire on live data**, not on manually
  pasted quotes, and the dashboard shows real-time instead of a delayed widget.
- **Live fund NAV capture** — formalise + schedule the userscript auto-collect
  so daily prices land without a manual click.
- **Trigger auto-generation** — turn AI-review verdicts into armed price
  triggers automatically (currently a manual link).

## Phase 2 — portfolio intelligence

- **PRS tax-relief tracker** — RM3,000/yr cap, remaining allowance for the year,
  and a December deadline reminder.
- **Concentration / rebalance alerts** — flag when a fund exceeds a target
  weight (e.g. AI already 27.8% and rising) and suggest trims.
- **Currency exposure view** — use factsheet `fx_exposure` to show USD / other
  currency exposure across the book and the USD/MYR impact on RM returns.
- **Distribution / income view** — reinvestment (RII) transactions → dividend
  history and running yield.
- **Verdict backtest** — track how past AI calls actually performed (was
  "reduce Indonesia" right?) to build trust and tune the prompts.

## Phase 3 — reporting & reach

- **Glossary page** — one-stop plain-English definitions of every term.
- **Printable portfolio report** — a clean PDF: holdings, P/L, allocation,
  verdicts — for records or a quick review.
- **PWA / mobile** — responsive + installable; native Android / iOS if wanted.
- **Weekly digest** — an opt-in push / email summary.
- **Charts** — allocation donut and per-fund sparklines (the equity curve
  already exists).

## Phase 4 — engineering hardening

- **Tests (Pest)** — cover the ingest parsers (statement / MFR / PHS / float),
  XIRR, simulator math, and per-account aggregation. Currently only the default
  example test exists.
- **Ingest idempotency + validation** — dedup guarantees and graceful handling
  of malformed PDFs.
- **Queue reliability** — guard the "snapshot stuck pending" class of bug
  (worker + migration checks).
- **Factsheet trend** — with several months ingested, show volatility / holdings
  drift over time.

---

## Phase 5 — portfolio-aware market view ✅ (shipped 2026-08-03)

- **Indices derived from real holdings** — parse each fund's captured
  Geographical Breakdown, weight each country by RM value, and show the markets
  the portfolio is *actually* in (surfaced Taiwan/Korea/Netherlands, which the
  old hand-picked list missed). Gold fund → gold price; always adds USD/MYR +
  home KLCI.
- **Own daily index history in Postgres** — `market_quote_days`, backfilled from
  Yahoo and accrued on every poll; independent of whatever TradingView will
  chart. Dashboard sparklines with X (dates) + Y (min/max) axes.
- **Per-index fund attribution** — each card lists which of your funds drive it
  and by how much.

---

## Phase 6 — hidden-exposure analytics (the next visibility layer) 🚧

The geography work proved the pattern; apply it to the other captured blocks.

- ✅ **Sector exposure** — aggregates each fund's Top-5 Sectors by RM into a
  portfolio sector look-through (Technology leads via the AI + semis funds).
  `PortfolioExposure::sectors()`, panel on the Overview.
- ✅ **Stock overlap / look-through** — rolls Top-5 holdings up across funds and
  flags any stock held through >1 fund (found NVIDIA in 2 funds ~RM230k, SK Hynix
  in 2 ~RM219k) — real single-stock concentration the fund weights hide.
  `PortfolioExposure::stocks()`.
- ✅ **Currency panel now real** — instead of the MFR fx-table (absent for held
  e-Series funds), currency exposure is built from each fund's captured
  Geographical Breakdown (country % × value → currency; gold = USD; remainder =
  MYR). `PortfolioExposure::currencies()`. _Optional leftover:_ still parse the
  MFR fx table where present as a cross-check second source.
- ✅ **Risk-adjusted view** — pair each fund's return with its volatility factor
  (already captured) → a simple return-per-unit-of-risk ranking.

---

## Phase 7 — decision support & simulation

- ✅ **Whole-portfolio rebalance simulator** — beyond the single-switch tool: set a
  target allocation, get the exact set of switches/redemptions, total fees, and
  the tax/charge cost to get there.
- ✅ **Stress test** — "US tech −20% / gold −10% / ringgit +5%" → projected
  portfolio value and which funds hurt most, using real geo + currency exposure.
- ✅ **PRS optimiser** — full per-year contribution history (2019→now) vs the
  RM3,000 relief cap, flags years that wasted excess (2022 was RM6k → RM3k
  wasted), lifetime relief + est. tax saved + PRS XIRR. Overview panel.
  _Optional leftover:_ forward "contribute by 31 Dec" nudge (the annual box
  already shows room left).
- ✅ **Cash deployment planner** — given the idle e-Cash, rank where deploying it
  (fees + buy-low triggers + concentration limits) does the most good.

---

## Phase 8 — history, attribution & benchmarking

- ✅ **Full per-fund NAV history** — a real chart (not just a sparkline) from the
  stored price history, with your buy/sell markers overlaid.
- ✅ **Return attribution** (fees split shipped; contributions-vs-market over time pending) — split portfolio growth into contributions vs market
  gain vs fees, over time.
- ✅ **Benchmark comparison** — your money-weighted return vs KLCI / a blended
  benchmark of your actual exposures.
- **Income view** — distribution (RII/DP) history + a forward income estimate
  once non-PRS distributions are captured.
- ✅ **Exposure-over-time** — each capture now stores the real currency mix;
  the "Currency mix over time" Overview panel charts the drift (fills as more
  captures accrue). Country/sector drift can follow the same way.

---

## Phase 9 — trust, testing & self-checks

- ✅ **Multi-source quote resilience** — Yahoo `query1`→`query2` host failover,
  then a **Twelve Data** fallback (config-gated) for USD/MYR + gold. A single
  host hiccup no longer blanks the dashboard; a full Yahoo outage still delivers
  the two most critical quotes. Indices stay Yahoo-only (Twelve Data's free tier
  excludes them).
- ✅ **Two-source cross-check** — `MarketQuoteService::crossCheck()` compares
  Yahoo vs Twelve Data on the symbols both cover (USD/MYR + gold) and shows a
  green/amber banner on the dashboard when they disagree > tolerance (2%, so
  normal gold futures-vs-spot basis doesn't cry wolf). Dormant with no TD key.
- ✅ **Reconciliation guard** — auto-compare the app's total against the latest
  statement total and flag any drift (would have caught the month-end overwrite).
- ✅ **Verdict-accuracy tracker** — persist the backtest over time; show whether the
  AI's calls are getting better and weight advice by its hit rate.
- 🚧 **Wider test coverage** — added: `crossCheck()` (4), currency exposure (2),
  `ReconciliationService` (3: unexplained-drop / redemption-explained / healthy),
  `PortfolioIndices` (4: country→index, macro trio, gold, symbols). Suite now 41.
  Added `PublicMutualParser` (CSV price path) + `CalendarEvent` parser (5). Suite
  now 48. Only genuinely-open: simulator switch/fee math (lives in JS — needs a
  JS runner or a PHP port).
- ✅ **Data-freshness monitor** — warn when prices/holdings are stale, before you
  act on old numbers.

---

## Phase 10 — knowledge & context

- ✅ **Per-fund research notes** — your own annotations kept against a specific PMO
  fund.
- ✅ **Exposure-driven calendar** — `calendar_events` + `pmoai:ingest-calendar` +
  a paste-your-dates form on the dashboard. Shows only future dates relevant to
  YOUR exposure (BNM if any foreign, Fed if USD, PMO/other always). You supply
  the real published dates — nothing invented or fetched.
- ✅ **Backup & restore** — `pmoai:backup` (pg_dump, auto-prune) + `--restore=` — one-command export of the local PMO dataset.

---

## Phase 11 — one clear call per fund (make the advisor act-on-able) 🚧

The advisor screens well but reads as four separate lists. Make it a single,
scannable decision the user can act on, and widen the actions to TOP-UP and
REDEEM. Everything stays grounded in captured numbers + real PMO rules.

- 🚧 **Action board** — one row per held fund with a single verdict —
  **TOP UP / HOLD / SWITCH / TRIM / REDEEM / DEPLOY** — a timing chip, and a
  one-line reason. Retirement PRS shows HOLD (locked); gold shows REDEEM (no
  switch facility); cash shows DEPLOY. Sorted so the funds needing action sit on
  top. This is the "easy to understand at a glance" layer.
- **TOP-UP** — the inverse of trim: a held fund that is NOT over-weight, in a
  healthy category, and at a good entry (near a recent low and steadying) → add
  to it. Uses idle cash or new money; shows the real sales charge.
- **REDEEM** — take to cash when a held fund is genuinely weak (negative 3Y +
  still sliding) AND has no better same-series switch (e.g. gold, or a lone
  non-e fund). Distinct from switch; never for PRS.
- **Transparent timing score** — a small, explainable 0–100 "conditions now"
  read per fund from real signals (entry position in its 6-month range, whether
  it's turning up, weight, risk-adjusted return). No black box, no price
  forecast — just "favourable / neutral / poor entry", with the factors shown.
- **Act on it** — each verdict links to the tool that does it: SWITCH/TRIM →
  the rebalance simulator pre-filled; DEPLOY → the deploy options; and an
  optional "arm a price trigger" so the move fires when a level is hit.

---

## Beyond (explicitly NOT core — only if you ask)

These leave strict PMO grounding, so they sit outside the roadmap unless wanted:

- Multi-portfolio / household view (spouse + kids' PMO/PRS accounts) — still PMO,
  just more of it.
- A second fund provider (would need new parsers) — only if you ever hold funds
  elsewhere.
- Local LLM fine-tuned on your captured PMO factsheets — cheaper/private
  analysis, but an ambitious side-quest.

*(Dropped from earlier drafts as too generic: per-holding news/sentiment feeds,
"monte-carlo" projections, and portfolio-analytics jargon — none tied to a
concrete piece of PMO data.)*
