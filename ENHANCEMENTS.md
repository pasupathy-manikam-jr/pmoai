# PMFAI — Enhancement Plan

A roadmap for pmoai / PMFAI. The app's core value is **visibility** — surfacing
fees, rules, cost basis, and concentration that the provider's UI hides — not
market-beating stock picks. Enhancements below deepen that visibility and cut
the manual steps.

Recommended order: **Phase 0 → 1 → 2 → 3/4**.

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
