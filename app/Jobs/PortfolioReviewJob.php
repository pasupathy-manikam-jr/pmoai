<?php

namespace App\Jobs;

use App\Models\Alert;
use App\Models\Fund;
use App\Models\FundDetail;
use App\Models\PortfolioReview;
use App\Models\PortfolioSnapshot;
use App\Services\ClaudeCliService;
use App\Services\Llm;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Whole-portfolio AI review: every held position + fund records + current
 * per-fund verdicts + allocations + triggers → one holistic memo
 * (concentration, rebalancing, cash deployment). Web-enabled when the
 * claude-cli provider is active.
 */
class PortfolioReviewJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public int $reviewId) {}

    public function handle(Llm $llm): void
    {
        $review = PortfolioReview::findOrFail($this->reviewId);

        try {
            $prompt = $this->buildPrompt();

            $text = $llm instanceof ClaudeCliService
                ? $llm->raw($prompt)
                : $llm->chat([], [], [], $prompt);

            $review->update([
                'status'   => 'done',
                'text'     => $text,
                'provider' => config('ai.llm_provider'),
            ]);
        } catch (Throwable $e) {
            $review->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)]);
            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        PortfolioReview::whereKey($this->reviewId)
            ->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)]);
    }

    private function buildPrompt(): string
    {
        $held = FundDetail::whereRaw("payload->'position'->>'invested' is not null")->get();

        $rows = [];
        $totInv = 0.0;
        $totVal = 0.0;
        foreach ($held as $d) {
            $pos = $d->payload['position'];
            $inv = (float) $pos['invested'];
            $val = (float) $pos['current_value'];
            $totInv += $inv;
            $totVal += $val;

            $fund = $d->code ? Fund::whereRaw('upper(code) = ?', [strtoupper($d->code)])->first() : null;
            $verdict = 'none';
            if (! empty($d->payload['ai']['text'])
                && preg_match('/Verdict[^:]*:\s*\**\s*(KEEP|SELL|REDUCE|BUY|WAIT|AVOID)\b/i', $d->payload['ai']['text'], $m)) {
                $verdict = strtoupper($m[1]);
            }

            $rows[] = implode(' | ', [
                $d->code ?? '?',
                $d->name,
                'invested RM '.number_format($inv, 0),
                'value RM '.number_format($val, 0),
                'P/L '.number_format($val - $inv, 0).' ('.number_format($inv > 0 ? ($val - $inv) / $inv * 100 : 0, 1).'%)',
                'category '.($fund->category ?? '?'),
                'risk '.($fund->risk ?? '?'),
                'returns 1Y '.($fund->return_1y ?? 'na').' 3Y '.($fund->return_3y ?? 'na').' 5Y '.($fund->return_5y ?? 'na'),
                'held since '.($pos['since'] ?? 'long-standing'),
                'last fund-level verdict: '.$verdict,
            ]);
        }

        $alerts = Alert::where('active', true)->get()
            ->map(fn ($a) => $a->fund_code.' '.$a->condition.' '.$a->level.' — '.$a->label
                .($a->fired_at ? ' [FIRED '.$a->fired_at->toDateString().']' : ' [armed]'))
            ->implode("\n");

        $history = PortfolioSnapshot::orderBy('snap_date')->get()
            ->map(fn ($s) => $s->snap_date->toDateString().' value '.number_format((float) $s->value, 0))
            ->implode("\n");

        // Today's intelligent brief: the one thing, what changed, what's already
        // done — so the memo says what's NEW instead of re-listing everything.
        $advisor = app(\App\Services\PortfolioAdvisor::class);
        $plan = $advisor->analyze();
        $t1rows = app(\App\Services\ExpectedNav::class)->forHeld()['rows'];
        $brief = app(\App\Services\AdvisorBrief::class)->build($plan, $t1rows, \App\Models\ActionItem::all());
        $briefText = app(\App\Services\AdvisorBrief::class)->toText($brief);

        return <<<PROMPT
You are a cautious Malaysian unit-trust portfolio reviewer. Below is the user's
COMPLETE portfolio at Public Mutual (unit trusts + PRS retirement). Numbers are
ground truth — quote them verbatim, never recompute or invent figures.

WRITE IN PLAIN ENGLISH FOR A NON-EXPERT. No unexplained jargon or bare acronyms.
- Refer to every fund by its FULL NAME, not just its code (you may add the code
  once in brackets).
- Use plain verbs: "sell part of" not "trim/REDUCE"; "sell all / exit" not
  "SELL"; "keep" not "KEEP verdict"; "buy / add to" not "BUY/accumulate".
- A "trigger" is a price level to act on — say "if the price drops to RM X, buy"
  or "it has reached RM X, the level to sell some", NEVER "trigger armed/fired".
- Spell out any metric the first time: XIRR → "your actual yearly return (XIRR)";
  redeem → "sell for cash"; switch → "move money between funds".
- NEVER use the phrase "buy the dip" (or "buy-the-dip"). Say plainly what you
  mean, e.g. "the price is below its recent range".
- Short sentences. If a reader would need a finance dictionary, rewrite it. You have
WebSearch/WebFetch: run 2-4 targeted searches on the portfolio's dominant
exposures (global tech, gold, Indonesia, Asia ex-Japan, Malaysia) and cite every
external claim inline with source + date. Do NOT write disclaimers — the app
displays one. Malaysian mechanics: unit-trust orders execute at same-day price
only if placed before 4:00 PM MYT on a trading day (Mon–Fri, excl public
holidays) — factor this into any timing advice. Switching charges (user is
Mutual Gold): fund-to-fund after 90 days via PMO = FREE; zero-load units
(money-market/cash) into equity/mixed/balanced = fresh sales charge up to 5%
(3.75% e-series), into bond up to 1% (0.65% e-series) — price cash
deployment recommendations accordingly. These THREE charge cases are DISTINCT
— never collapse them into "cross-series": (1) MONEY-MARKET/CASH → equity =
fresh sales charge because the source units are zero-load (never paid an equity
charge); this holds EVEN WITHIN the same series (e.g. e-Cash/PeCDF → an e-equity
fund is same-series, still 3.75%, and is NOT cross-series). (2) EQUITY → equity
same-series after 90 days = FREE (those units already paid the charge).
(3) CROSS-SERIES (e ↔ non-e) = must redeem to cash + repurchase at the
destination's fresh charge. SERIES RULE: e-Series funds ("e-" in the name,
codes starting "Pe") can ONLY be switched into other e-Series funds, non-e only
into non-e; crossing series requires redeeming to cash and repurchasing with a
fresh sales charge — never recommend a cross-series move as a switch. NO-SWITCH
FUNDS: some funds have NO switching facility at all per their PHS — notably
PUBLIC e-EMAS GOLD FUND (PeEMAS) ("Switching charge: Not applicable. No
switching allowed."). For these the ONLY exit is REDEEM TO CASH (then any
repurchase is a fresh purchase); never describe leaving PeEMAS as a "switch" or
a "cross-series" move — say "redeem to cash". The PRS funds are deliberate
RM3,000/year top-ups (the Malaysian tax-relief maximum) — an annual habit,
not trading positions; never advise selling or switching them for
performance reasons alone (early PRS withdrawal carries an 8% tax penalty
before age 55).

PORTFOLIO (total invested RM {$this->fmt($totInv)}, current value RM {$this->fmt($totVal)}):
{$this->lines($rows)}

ACTIVE PRICE TRIGGERS:
{$alerts}

PORTFOLIO VALUE HISTORY (daily captures):
{$history}

TODAY'S BRIEF (already computed from the numbers above — this is the spine of the memo):
{$briefText}

Write a SHORT memo, ~220 words, with EXACTLY these sections. Do not pad. Do not
repeat the portfolio table back. Say what is NEW and what to DO, nothing generic:
- **What matters now** — THE ONE THING from the brief, in 2-3 plain sentences:
  fund, the RM amount, and why today (use the market / 4 PM note if given).
- **Since the last review** — only what CHANGED. If the brief says nothing
  changed, write exactly one line saying so and move on. Never re-describe the
  whole book.
- **Already handled** — one clause per ALREADY DONE item, acknowledging it.
  NEVER suggest these again in any form.
- **Still open** — only the STILL OPEN items, numbered, each concrete (fund,
  amount, direction, and whether the switch is free or paid). Nothing that is
  done, nothing extra.
- **Market context (live)** — 2-3 sourced bullets ONLY on markets behind the
  open items (not a general market tour).
- **Look again when** — 2 concrete conditions.
PROMPT;
    }

    private function fmt(float $v): string
    {
        return number_format($v, 0);
    }

    /** @param string[] $rows */
    private function lines(array $rows): string
    {
        return implode("\n", $rows);
    }
}
