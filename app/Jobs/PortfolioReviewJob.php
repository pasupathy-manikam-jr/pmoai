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

        return <<<PROMPT
You are a cautious Malaysian unit-trust portfolio reviewer. Below is the user's
COMPLETE portfolio at Public Mutual (unit trusts + PRS retirement). Numbers are
ground truth — quote them verbatim, never recompute or invent figures. You have
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

Write a memo with EXACTLY these sections, ~350 words total:
- **Health check** — one paragraph: overall shape, what's working, what's not.
- **Concentration risks** — name the top 2-3 with the numbers (fund % of total,
  category/theme overlaps between funds).
- **Conflicts & gaps** — where fund-level verdicts, triggers, and allocation
  pull in different directions; what's missing (e.g. balanced layer).
- **Market context (live)** — 2-4 sourced bullets on the dominant exposures.
- **Action list** — numbered, most-important first, each one concrete
  (fund, amount range, direction) and consistent with the user's stated
  buy-low philosophy and the active triggers. Include what to do with cash.
- **Review again when** — 2-3 concrete conditions.
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
