# PMFAI — Enhancement Plan

A roadmap for pmoai / PMFAI. The app's core value is **visibility** — surfacing
fees, rules, cost basis, and concentration that the provider's UI hides — not
market-beating stock picks. Enhancements below deepen that visibility and cut
the manual steps.

**Status (2026-08-03):** Phases 0, 1, 2, 4 ✅ shipped · Phase 3 skipped (reach
features — not needed for a local, single-user app) · Phase 5 ✅ shipped.
Phases 6–10 below are the forward roadmap.

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

## Phase 6 — hidden-exposure analytics (the next visibility layer)

The geography work proved the pattern; apply it to the other captured blocks.

- **Sector exposure** — aggregate each fund's Top-5 Sectors by RM (you're heavily
  Technology; quantify it) → a portfolio sector breakdown + a sector-index tie-in.
- **Stock overlap / look-through** — the same stock (NVIDIA, ASML, Alphabet)
  sits in multiple funds. Roll top holdings up across funds → your true
  single-stock concentration, which fund-level weights hide.
- **Factsheet fx/geo parser fix** — teach `MfrParser` the e-Series/foreign
  layout so `fx_exposure`/`geo_foreign` populate for held funds; upgrades the
  currency panel from estimate to real and feeds the above from a second source.
- **Risk-adjusted view** — pair each fund's return with its volatility factor
  (already captured) → a simple return-per-unit-of-risk ranking.

---

## Phase 7 — decision support & simulation

- **Whole-portfolio rebalance simulator** — beyond the single-switch tool: set a
  target allocation, get the exact set of switches/redemptions, total fees, and
  the tax/charge cost to get there.
- **Stress test** — "US tech −20% / gold −10% / ringgit +5%" → projected
  portfolio value and which funds hurt most, using real geo + currency exposure.
- **PRS optimiser** — track the RM3,000/yr relief across years, warn on
  over-contribution (done) and suggest the tax-optimal timing/fund.
- **Cash deployment planner** — given the idle e-Cash, rank where deploying it
  (fees + buy-low triggers + concentration limits) does the most good.

---

## Phase 8 — history, attribution & benchmarking

- **Full per-fund NAV history** — a real chart (not just a sparkline) from the
  stored price history, with your buy/sell markers overlaid.
- **Return attribution** — split portfolio growth into contributions vs market
  gain vs fees, over time.
- **Benchmark comparison** — your money-weighted return vs KLCI / a blended
  benchmark of your actual exposures.
- **Income view** — distribution (RII/DP) history + a forward income estimate
  once non-PRS distributions are captured.
- **Exposure-over-time** — how your country/sector/currency mix drifted as you
  switched funds.

---

## Phase 9 — trust, testing & self-checks

- **Reconciliation guard** — auto-compare the app's total against the latest
  statement total and flag any drift (would have caught the month-end overwrite).
- **Verdict-accuracy tracker** — persist the backtest over time; show whether the
  AI's calls are getting better and weight advice by its hit rate.
- **Wider test coverage** — `IngestStatements` (extract-to-static like the float
  parser), `PortfolioIndices`, the simulator math (port the JS to a testable
  service or add a JS test runner).
- **Data-freshness monitor** — warn when prices/holdings are stale, before you
  act on old numbers.

---

## Phase 10 — knowledge & context

- **Per-fund research notes** — your own annotations, kept with the fund.
- **Macro calendar** — FOMC / BNM / key earnings dates for your holdings, so a
  trigger is read against what's coming.
- **News / sentiment per holding** — headlines for the top stocks you hold
  through funds, cited and dated.
- **Backup & restore** — one-command export of the whole local dataset.

---

## Beyond

- Multi-portfolio / household view (spouse + kids' PRS).
- A second provider's funds (generalise the parsers).
- Local LLM fine-tune on your own captured factsheets for cheaper, private
  analysis.
