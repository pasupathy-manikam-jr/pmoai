<?php

namespace App\Services;

use App\Models\Fund;
use App\Models\FundDetail;

/**
 * Whole-catalogue screener. Ranks all ~190 Public Mutual funds on their REAL
 * captured numbers (returns, risk, category) and cross-references your actual
 * holdings + PMO switch mechanics to propose concrete actions:
 *
 *   TRIM    — a single fund is over-concentrated (>30% of the book)
 *   SWITCH  — a held fund is beaten by a better same-category, same-series,
 *             equal-or-lower-risk fund (a free switch after 90 days)
 *   DEPLOY  — idle cash / money-market money that could work harder
 *   BUY     — a category you barely hold, with the best fund to enter it
 *
 * Deterministic and grounded: every pick comes from a real catalogue row and a
 * documented PMO rule. Nothing is invented; the AI layer only writes the "why".
 * PRS funds are excluded entirely (retirement-locked — never trade-advised).
 */
class PortfolioAdvisor
{
    private const RISK_W = ['Very Low' => 1, 'Low' => 2, 'Moderate' => 3, 'High' => 4, 'Very High' => 5];

    private const CAT_LABEL = ['EQ' => 'Equity', 'MA' => 'Mixed asset', 'BO' => 'Bond', 'MM' => 'Money market', 'OA' => 'Other'];

    /** A held fund must beat its replacement's score by this much to be worth switching. */
    private const SWITCH_EDGE = 1.15;   // +15% risk-adjusted

    private const CONCENTRATION = 30.0;  // % of book
    private const TRIM_TO = 25.0;

    public function analyze(): array
    {
        $held = $this->heldFunds();
        $book = array_sum(array_column($held, 'value')) ?: 1.0;

        // Tradable catalogue: real 1Y number, not PRS (retirement-locked).
        $catalog = Fund::whereNotNull('return_1y')
            ->where('category', '!=', 'PRS')
            ->get()
            ->map(fn ($f) => $this->shape($f))
            ->filter(fn ($f) => $f['score'] !== null)
            ->values()
            ->all();

        $heldCodes = array_filter(array_column($held, 'code'));

        return [
            'board'  => $this->board($held, $book, $catalog, $heldCodes),
            'trim'   => $this->trims($held, $book),
            'switch' => $this->switches($held, $catalog, $heldCodes),
            'deploy' => $this->deploys($held, $catalog, $heldCodes),
            'buy'    => $this->buys($held, $book, $catalog, $heldCodes),
            'book'   => $book,
            'held_count' => count($held),
            'catalog_count' => count($catalog),
        ];
    }

    // ---- proposal builders ----------------------------------------------

    /** Over-concentrated single funds → trim to the cap. */
    private function trims(array $held, float $book): array
    {
        $out = [];
        foreach ($held as $h) {
            $w = $h['value'] / $book * 100;
            if ($w < self::CONCENTRATION) {
                continue;
            }
            // Timing awareness: this is a structural RISK flag, not a
            // market-timing sell. But if the fund is running hard right now,
            // trimming caps upside — so soften to "not urgent / into strength"
            // rather than "sell now"; if it's falling, "don't sell low".
            $m5 = $h['trend_5d'];
            if ($m5 !== null && $m5 >= 3) {
                $timing = "It's up {$this->pc($m5)} in the last week, so this isn't a 'sell now' — trimming into strength or setting a ceiling (only sell what's above {$this->pc(self::CONCENTRATION)}) keeps the upside while capping the risk.";
                $urgency = 'not urgent';
            } elseif ($m5 !== null && $m5 <= -3) {
                $timing = "It's down {$this->pc(abs($m5))} this week — selling into a dip locks the loss. You can cut the risk simply by not adding more, and trim later on a bounce.";
                $urgency = 'wait for a bounce';
            } else {
                $timing = 'It’s roughly flat this week, so timing isn’t pressing either way.';
                $urgency = 'your call';
            }

            $out[] = [
                'fund'     => $h['name'],
                'weight'   => round($w, 1),
                'amount'   => round($h['value'] - $book * self::TRIM_TO / 100),
                'risk'     => $h['risk'],
                'trend_5d' => $m5,
                'urgency'  => $urgency,
                'why'      => "One fund is {$this->pc($w)} of your book (over {$this->pc(self::CONCENTRATION)}). That's a structural risk — one fund decides your outcome — not a call that it's about to fall.",
                'timing'   => $timing,
            ];
        }

        return $out;
    }

    /** Held funds beaten by a better same-category, same-series, ≤-risk fund. */
    private function switches(array $held, array $catalog, array $heldCodes): array
    {
        $out = [];
        foreach ($held as $h) {
            if ($h['fund'] === null || in_array($h['cat'], ['MM', 'PRS'], true) || $h['gold']) {
                continue;   // cash/PRS/gold handled elsewhere or not switchable
            }
            $best = $this->bestSwitchFor($h, $catalog, $heldCodes);
            if ($best) {
                $out[] = [
                    'from'      => $h['name'],
                    'to'        => $best['name'],
                    'cat'       => self::CAT_LABEL[$h['cat']] ?? $h['cat'],
                    'from_3y'   => $h['r3'],
                    'to_3y'     => $best['r3'],
                    'from_risk' => $h['risk'],
                    'to_risk'   => $best['risk'],
                    'fee'       => 'free (same-series switch, held ≥90 days)',
                    'why'       => "In the same category and same or lower risk, {$this->short($best['name'])} has done better over 3 years ({$this->pc($best['r3'])} vs {$this->pc($h['r3'])}). A same-series switch after 90 days costs nothing.",
                ];
            }
        }

        return $out;
    }

    /**
     * Transparent "conditions to add now" score, 0–100, from real signals — NOT
     * a price forecast. Combines: where the price sits in its 6-month range
     * (near a low is a better entry), whether it's turning up, and whether the
     * fund is any good (positive 3-year return). Returns the score, a band, and
     * the human-readable factors behind it. Null if no price history.
     *
     * @return array{score:int, band:string, factors:array<int,string>}|null
     */
    private function timingScore(?array $entry, ?float $r3): ?array
    {
        if ($entry === null) {
            return null;
        }
        $pos = $entry['pos'];        // 0 = at low, 100 = at high
        $trend = $entry['trend'] ?? 0;
        $factors = [];

        $s = 50.0;
        // Entry position: lower in the range = better place to add.
        $s += (50 - $pos) * 0.4;     // ±20
        $factors[] = $pos <= 33 ? 'near its recent low (+)' : ($pos >= 67 ? 'near its recent high (−)' : 'mid-range');
        // Momentum: turning up is good; still sliding is bad.
        if ($trend > 0) { $s += 12; $factors[] = 'turning up (+)'; }
        elseif ($trend < -1) { $s -= 10; $factors[] = 'still sliding (−)'; }
        // Quality: don't reward a fund that's cheap because it's weak.
        if ($r3 !== null && $r3 > 0) { $s += 10; $factors[] = 'positive 3-year return (+)'; }
        elseif ($r3 !== null && $r3 < 0) { $s -= 12; $factors[] = 'negative 3-year return (−)'; }

        $score = (int) round(max(0, min(100, $s)));
        $band = $score >= 65 ? 'favourable' : ($score >= 40 ? 'neutral' : 'poor');

        return ['score' => $score, 'band' => $band, 'factors' => $factors];
    }

    /**
     * 90-day free-switch status for a held fund. Same-series switches are free
     * of the load once the units are held ≥90 days; the latest "in" transaction
     * (II/AI/SWS/RII) starts that clock. Gold has no switch facility; PRS is
     * locked — both flagged as not-switchable.
     *
     * @return array{state:string, days_left:?int, free_date:?string, since:?string}
     */
    private function freeSwitchStatus(array $h): array
    {
        if ($h['gold']) {
            return ['state' => 'no_switch', 'days_left' => null, 'free_date' => null, 'since' => null];
        }
        if ($h['cat'] === 'PRS') {
            return ['state' => 'locked', 'days_left' => null, 'free_date' => null, 'since' => null];
        }

        $lastIn = $h['code']
            ? \App\Models\Transaction::whereRaw('upper(fund_code) = ?', [strtoupper($h['code'])])
                ->whereIn('trans_type', ['II', 'AI', 'SWS', 'RII'])
                ->max('trans_date')
            : null;

        if (! $lastIn) {
            return ['state' => 'unknown', 'days_left' => null, 'free_date' => null, 'since' => null];
        }

        $since = \Illuminate\Support\Carbon::parse($lastIn);
        $held = (int) $since->diffInDays(now());
        if ($held >= 90) {
            return ['state' => 'free', 'days_left' => 0, 'free_date' => null, 'since' => $since->toDateString()];
        }

        return [
            'state'     => 'waiting',
            'days_left' => 90 - $held,
            'free_date' => $since->copy()->addDays(90)->toDateString(),
            'since'     => $since->toDateString(),
        ];
    }

    /** Best same-category, same-series, ≤-risk, meaningfully-better fund, or null. */
    private function bestSwitchFor(array $h, array $catalog, array $heldCodes): ?array
    {
        if ($h['fund'] === null || in_array($h['cat'], ['MM', 'PRS'], true) || $h['gold']) {
            return null;
        }
        $best = null;
        foreach ($catalog as $c) {
            if (in_array($c['code'], $heldCodes, true) || $c['cat'] !== $h['cat']) {
                continue;
            }
            if ($c['is_e'] !== $h['is_e'] || self::RISK_W[$c['risk']] > self::RISK_W[$h['risk']]) {
                continue;   // e-only-within-e; never push into more risk
            }
            if ($c['score'] < $h['score'] * self::SWITCH_EDGE) {
                continue;   // not enough of an upgrade to bother
            }
            if ($best === null || $c['score'] > $best['score']) {
                $best = $c;
            }
        }

        return $best;
    }

    /**
     * ONE clear call per held fund — the scannable decision layer.
     * TOP UP / HOLD / SWITCH / TRIM / REDEEM / DEPLOY, with a timing read and a
     * plain reason. All from real signals: weight, 3Y return, risk, the fund's
     * position in its 6-month range, and whether a better same-series fund exists.
     *
     * @return array<int, array>  sorted so action-needed rows come first
     */
    public function board(array $held, float $book, array $catalog, array $heldCodes): array
    {
        // action => sort priority (lower = higher up the list)
        $priority = ['TRIM' => 0, 'SWITCH' => 1, 'REDEEM' => 2, 'TOP UP' => 3, 'DEPLOY' => 4, 'HOLD' => 5];
        $rows = [];

        foreach ($held as $h) {
            $w = $book > 0 ? $h['value'] / $book * 100 : 0;
            $entry = $this->entrySignal($h['code']);
            $better = $this->bestSwitchFor($h, $catalog, $heldCodes);
            $weak = ($h['r3'] !== null && $h['r3'] < 0) && (($entry['good'] ?? null) === false || ($h['trend_5d'] ?? 0) < 0);

            if ($h['cat'] === 'PRS') {
                [$action, $why] = ['HOLD', 'Retirement money — locked until 55, never traded on market moves.'];
            } elseif ($h['cat'] === 'MM' || $this->isCashName($h['name'])) {
                [$action, $why] = ['DEPLOY', 'Idle cash. See the deploy options below to put some to work at a sensible entry.'];
            } elseif ($w >= self::CONCENTRATION) {
                [$action, $why] = ['TRIM', "{$this->pc($w)} of your book — one fund shouldn't decide your outcome. "
                    .(($h['trend_5d'] ?? 0) >= 3 ? "It's rising now, so trim into strength / set a ceiling, not urgent." : 'Bring it toward 25%.')];
            } elseif ($better) {
                [$action, $why] = ['SWITCH', "A same-category, same-or-lower-risk fund has done better ({$this->pc($better['r3'])} vs {$this->pc($h['r3'])} over 3Y) — a free same-series switch."];
            } elseif ($h['gold'] && $weak) {
                [$action, $why] = ['REDEEM', 'Weak and gold has no switch facility — the only exit is redeeming to cash. Consider it only if you no longer want the gold hedge.'];
            } elseif ($weak) {
                [$action, $why] = ['REDEEM', 'Negative over 3 years and still sliding, with no better same-series fund to switch into — taking it to cash stops the bleed.'];
            } elseif (($entry['good'] ?? null) === true && $w < self::TRIM_TO && ($h['r3'] ?? 0) > 0) {
                [$action, $why] = ['TOP UP', "Healthy fund, not over-weight ({$this->pc($w)}), and {$entry['label']} — a reasonable spot to add."];
            } else {
                [$action, $why] = ['HOLD', 'Nothing to do — reasonable weight, no clearly better option, no red flag right now.'];
            }

            $timing = $this->timingScore($entry, $h['r3']);

            // 90-day free-switch clock: same-series switches are free of the
            // load once units are held ≥90 days. The latest "in" transaction
            // (buy / switch-in / reinvest) is the binding lot for that fund.
            $switch = $this->freeSwitchStatus($h);

            // Pre-filled alert to act at a better moment: a ceiling to trim
            // into for a rising over-weight fund; a dip level for a better
            // entry when deploying / topping up. User can edit before arming.
            $price = $entry['last'] ?? null;
            $alertCond = $alertLevel = null;
            if ($price && ! in_array($action, ['HOLD'], true) && $h['cat'] !== 'PRS') {
                if ($action === 'TRIM') {
                    $alertCond = 'above';
                    $alertLevel = round($price * 1.10, 4);   // +10% ceiling
                } else {
                    $alertCond = 'below';
                    $alertLevel = round($price * 0.95, 4);   // −5% dip = better entry
                }
            }

            $rows[] = [
                'name'     => $h['name'],
                'code'     => $h['code'],
                'action'   => $action,
                'weight'   => round($w, 1),
                'r3'       => $h['r3'],
                'risk'     => $h['risk'],
                'trend_5d' => $h['trend_5d'],
                'entry'    => $entry['label'] ?? null,
                'entry_good' => $entry['good'] ?? null,
                'score'    => $timing['score'] ?? null,
                'band'     => $timing['band'] ?? null,
                'factors'  => $timing['factors'] ?? [],
                'switch_to' => $better['name'] ?? null,
                'switch'   => $switch,
                'why'      => $why,
                'price'      => $price,
                'alert_cond'  => $alertCond,
                'alert_level' => $alertLevel,
                '_p'       => $priority[$action] ?? 9,
            ];
        }

        usort($rows, fn ($a, $b) => $a['_p'] <=> $b['_p'] ?: $b['weight'] <=> $a['weight']);

        return $rows;
    }

    /** Idle cash / money-market money that could be deployed. */
    private function deploys(array $held, array $catalog, array $heldCodes): array
    {
        $out = [];
        foreach ($held as $h) {
            if ($h['cat'] !== 'MM' && ! $this->isCashName($h['name'])) {
                continue;
            }
            // e-Cash can only move within e-Series. Don't dump safe cash into
            // only the riskiest funds — offer a spread: the best option at each
            // risk tier (steadier / balanced / growth) so the choice is visible.
            $cands = array_filter($catalog, fn ($c) => $c['is_e'] === $h['is_e']
                && in_array($c['cat'], ['EQ', 'MA', 'BO'], true)
                && ! in_array($c['code'], $heldCodes, true));

            $tiers = [
                'Steadier' => fn ($c) => self::RISK_W[$c['risk']] <= 2,             // Very Low / Low
                'Balanced' => fn ($c) => in_array(self::RISK_W[$c['risk']], [3, 4], true), // Moderate / High
                'Growth'   => fn ($c) => self::RISK_W[$c['risk']] >= 5,             // Very High
            ];
            $options = [];
            foreach ($tiers as $label => $test) {
                $tc = array_values(array_filter($cands, $test));
                usort($tc, fn ($a, $b) => $b['r3'] <=> $a['r3']);   // strongest first
                // Among the top performers in this tier, prefer a better ENTRY
                // (near a recent low and steadying) over one at its peak.
                $pool = array_slice($tc, 0, 4);
                $ranked = array_map(fn ($c) => ['c' => $c, 'e' => $this->entrySignal($c['code'])], $pool);
                usort($ranked, function ($a, $b) {
                    $ea = $a['e']['good'] ?? null;
                    $eb = $b['e']['good'] ?? null;
                    $rank = fn ($g) => $g === true ? 0 : ($g === null ? 1 : 2);   // good < unknown < peak
                    return $rank($ea) <=> $rank($eb) ?: $b['c']['r3'] <=> $a['c']['r3'];
                });
                if ($ranked) {
                    $c = $ranked[0]['c'];
                    $e = $ranked[0]['e'];
                    $options[] = [
                        'tier' => $label, 'name' => $c['name'], 'r3' => $c['r3'],
                        'risk' => $c['risk'], 'fee_pct' => $this->salesCharge($c),
                        'entry' => $e['label'] ?? null, 'entry_good' => $e['good'] ?? null,
                    ];
                }
            }
            // "Not at a high" ranking: every same-series, decent-return option
            // sorted by where it sits in its 6-month range (lowest first = best
            // entry). Lets you deploy into what has NOT already run up.
            $byEntry = [];
            foreach ($cands as $c) {
                if (($c['r3'] ?? 0) <= 3) {
                    continue;   // skip weak funds — cheap-because-bad isn't an entry
                }
                $e = $this->entrySignal($c['code']);
                if ($e === null) {
                    continue;
                }
                $byEntry[] = [
                    'name' => $c['name'], 'cat' => self::CAT_LABEL[$c['cat']] ?? $c['cat'],
                    'risk' => $c['risk'], 'r3' => $c['r3'], 'pos' => $e['pos'],
                    'good' => $e['good'], 'fee_pct' => $this->salesCharge($c),
                ];
            }
            usort($byEntry, fn ($a, $b) => $a['pos'] <=> $b['pos']);
            $byEntry = array_slice($byEntry, 0, 7);

            if ($options) {
                $out[] = [
                    'from'     => $h['name'],
                    'amount'   => round($h['value']),
                    'options'  => $options,
                    'by_entry' => $byEntry,
                    'why'      => 'This is cash sitting idle. Two things matter, not just the return: (1) how much risk you want — the options span steadier to higher-risk; (2) timing — putting a lump sum into a fund near its high is a poor entry, so where a fund has pulled back and is steadying is better, and if it’s near a high, feed the money in gradually instead of all at once. Cash→fund pays a fresh sales charge; keeping some as your buffer is fine.',
                ];
            }
        }

        return $out;
    }

    /** Categories you barely hold → the best fund to enter each. */
    private function buys(array $held, float $book, array $catalog, array $heldCodes): array
    {
        // Current weight per category.
        $catW = [];
        foreach ($held as $h) {
            $catW[$h['cat']] = ($catW[$h['cat']] ?? 0) + $h['value'];
        }
        $catW = array_map(fn ($v) => $v / $book * 100, $catW);

        // A balanced Malaysian book usually wants some bond + some mixed-asset
        // ballast. Flag the ones you're light on.
        $wanted = ['BO' => 10.0, 'MA' => 10.0];
        $out = [];
        foreach ($wanted as $cat => $minPct) {
            if (($catW[$cat] ?? 0) >= $minPct) {
                continue;
            }
            // Ballast should STEADY the book — so exclude Very High risk here
            // (a Very-High "mixed asset" fund doesn't cushion anything). Rank
            // by return among the acceptable-risk set.
            $cands = array_filter($catalog, fn ($c) => $c['cat'] === $cat
                && self::RISK_W[$c['risk']] <= 4
                && ! in_array($c['code'], $heldCodes, true));
            usort($cands, fn ($a, $b) => $b['r3'] <=> $a['r3']);
            $top = array_slice($cands, 0, 3);
            if ($top) {
                $out[] = [
                    'category' => self::CAT_LABEL[$cat] ?? $cat,
                    'have_pct' => round($catW[$cat] ?? 0, 1),
                    'want_pct' => $minPct,
                    'options'  => array_map(fn ($c) => [
                        'name' => $c['name'], 'r3' => $c['r3'], 'risk' => $c['risk'], 'is_e' => $c['is_e'],
                        'fee_pct' => $this->salesCharge($c),
                    ], $top),
                    'why' => "You hold about {$this->pc($catW[$cat] ?? 0)} in ".(self::CAT_LABEL[$cat] ?? $cat).'. Some of this class steadies the book when equities fall — here are the strongest options to start one.',
                ];
            }
        }

        return $out;
    }

    // ---- data shaping ----------------------------------------------------

    /** @return array<int, array{name:string,value:float,code:?string,fund:?Fund,cat:?string,risk:string,is_e:bool,gold:bool,score:?float,r3:?float}> */
    private function heldFunds(): array
    {
        $analysis = app(FundAnalysis::class);
        $out = [];
        foreach (FundDetail::whereRaw("payload->'position'->>'current_value' is not null")->get() as $d) {
            $value = (float) $d->payload['position']['current_value'];
            [$code, $hist, $fund] = $analysis->resolve($d);
            $code = $code ?: ($d->code ? strtoupper($d->code) : null);
            $shaped = $fund ? $this->shape($fund) : null;
            $out[] = [
                'name'     => $d->name,
                'value'    => $value,
                'code'     => $fund?->code ? strtoupper($fund->code) : $code,
                'fund'     => $fund,
                'cat'      => $fund?->category ?? $shaped['cat'] ?? 'OA',
                'risk'     => $shaped['risk'] ?? 'High',
                'is_e'     => $shaped['is_e'] ?? $this->isEName($d->name, $d->code),
                'gold'     => (bool) preg_match('/EMAS|GOLD/i', $d->name),
                'score'    => $shaped['score'] ?? 0.0,
                'r3'       => $shaped['r3'] ?? null,
                'trend_5d' => $this->recentTrend($hist, 5),
            ];
        }

        return $out;
    }

    /**
     * Where a fund's latest price sits inside its recent range — the entry
     * signal for deploying a lump sum. Reads fund_prices by code. Returns
     * ['pos' => 0..100, 'label' => …] (0 = at its recent low = better entry,
     * 100 = at its recent high) or null if too little price history.
     */
    private function entrySignal(?string $code): ?array
    {
        if (! $code) {
            return null;
        }
        $canon = Fund::canonicalCode($code);
        $prices = [];
        foreach (\App\Models\FundPrice::whereRaw('upper(code) = ?', [strtoupper($canon)])
            ->orderBy('period')->get() as $row) {
            for ($d = 1; $d <= 31; $d++) {
                $v = $row->{"d{$d}"};
                if ($v !== null) {
                    $prices[] = (float) $v;
                }
            }
        }
        $prices = array_slice($prices, -130);   // ~6 months of trading days
        $n = count($prices);
        if ($n < 20) {
            return null;
        }
        $min = min($prices);
        $max = max($prices);
        $last = end($prices);
        if ($max <= $min) {
            return null;
        }
        $pos = ($last - $min) / ($max - $min) * 100;
        // Is it actually turning up, or just chronically low? (last vs ~1 month ago)
        $prior = $prices[max(0, $n - 22)];
        $trend = $prior != 0 ? ($last - $prior) / $prior * 100 : 0;

        // Near-low is only a good entry if it's NOT still sliding — otherwise
        // it's cheap for a reason (a long slump, e.g. a weak market or gold dip).
        if ($pos <= 33) {
            [$label, $good] = $trend >= -1
                ? ['near its recent low and steadying — a better entry', true]
                : ['near a low but still sliding — cheap for a reason, not a clear entry', false];
        } elseif ($pos >= 67) {
            [$label, $good] = ['near its recent high — stagger your entry rather than going all-in', false];
        } else {
            [$label, $good] = ['mid-range', null];
        }

        return ['pos' => round($pos), 'trend' => round($trend, 1), 'label' => $label, 'good' => $good, 'last' => round($last, 4)];
    }

    /** % change over the last ~N captured price points; null if too few. */
    private function recentTrend($hist, int $days): ?float
    {
        $pts = $hist instanceof \Illuminate\Support\Collection ? $hist->values() : collect($hist)->values();
        $n = $pts->count();
        if ($n < 2) {
            return null;
        }
        $last = (float) $pts[$n - 1]['price'];
        $prior = (float) $pts[max(0, $n - 1 - $days)]['price'];
        if ($prior == 0.0) {
            return null;
        }

        return round(($last - $prior) / $prior * 100, 1);
    }

    private function shape(Fund $f): array
    {
        $risk = $f->risk && isset(self::RISK_W[$f->risk]) ? $f->risk : 'High';
        $r3 = $f->return_3y !== null ? (float) $f->return_3y : ($f->return_1y !== null ? (float) $f->return_1y : null);
        return [
            'name'  => $f->name,
            'code'  => strtoupper((string) $f->code),
            'cat'   => $f->category,
            'risk'  => $risk,
            'is_e'  => $this->isEName($f->name, $f->code),
            'gold'  => (bool) preg_match('/EMAS|GOLD/i', $f->name),
            'r3'    => $r3,
            // Risk-adjusted score: return per unit of risk. Null if no return.
            'score' => $r3 !== null ? $r3 / self::RISK_W[$risk] : null,
        ];
    }

    // ---- PMO rule helpers ------------------------------------------------

    private function isEName(?string $name, ?string $code): bool
    {
        return (bool) (preg_match('/(^|\s)e-/i', (string) $name) || preg_match('/^Pe[A-Z]/', (string) $code));
    }

    private function isCashName(string $name): bool
    {
        return (bool) preg_match('/CASH|MONEY MARKET/i', $name);
    }

    /** Fresh sales-charge % for buying into a fund (per PHS). */
    private function salesCharge(array $c): float
    {
        $bond = $c['cat'] === 'BO';
        return $bond ? ($c['is_e'] ? 0.65 : 1.0) : ($c['is_e'] ? 3.75 : 5.0);
    }

    /** Flatten a plan into compact text for the AI narrative prompt. */
    public function toText(array $plan): string
    {
        $L = [];
        $L[] = 'Book RM '.number_format($plan['book'], 0).', '.$plan['held_count'].' funds held.';
        foreach ($plan['trim'] as $t) {
            $wk = $t['trend_5d'] !== null ? " (up/down {$t['trend_5d']}% this week — {$t['urgency']})" : '';
            $L[] = "TRIM (structural risk flag, not a sell-now): {$t['fund']} is {$t['weight']}% of the book{$wk} — trim ~RM".number_format($t['amount'], 0).' to 25% when timing suits.';
        }
        foreach ($plan['switch'] as $s) {
            $L[] = "SWITCH: {$s['from']} (3Y {$s['from_3y']}%) → {$s['to']} (3Y {$s['to_3y']}%), same {$s['cat']} category, risk {$s['from_risk']}→{$s['to_risk']}, {$s['fee']}.";
        }
        foreach ($plan['deploy'] as $d) {
            $opts = implode('; ', array_map(fn ($o) => "{$o['name']} (3Y {$o['r3']}%, {$o['fee_pct']}% charge)", $d['options']));
            $L[] = 'DEPLOY: RM'.number_format($d['amount'], 0)." idle in {$d['from']} → options: {$opts}.";
        }
        foreach ($plan['buy'] as $b) {
            $opts = implode('; ', array_map(fn ($o) => "{$o['name']} (3Y {$o['r3']}%)", $b['options']));
            $L[] = "DIVERSIFY: only {$b['have_pct']}% in {$b['category']} → options: {$opts}.";
        }

        return implode("\n", $L);
    }

    private function pc($v): string
    {
        return number_format((float) $v, 1).'%';
    }

    private function short(string $n): string
    {
        return trim((string) preg_replace('/^PUBLIC\s+/i', '', $n));
    }
}
