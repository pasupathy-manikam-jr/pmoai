<?php

namespace App\Services;

/**
 * Builds the analysis, chat, extraction and screener prompts. Shared by every
 * Llm implementation so the system contract + data layout stay identical
 * regardless of provider.
 */
class FundAnalysisPrompt
{
    /** Extraction instruction for parsing a pasted Public Mutual page. */
    public const EXTRACT_SYSTEM = 'Extract Malaysian Public Mutual funds. Numbers only, '
        .'strip symbols and % signs. Unknown fields => null.';

    /** Shape of one extracted fund row, described for providers without tool schemas. */
    public const EXTRACT_SHAPE = '{"funds":[{"name":"<string>","fund_type":"<string>",'
        .'"shariah":<true|false>,"unit_price":<number|null>,"selling_price":<number|null>,'
        .'"return_1y":<number|null>,"return_3y":<number|null>,"return_5y":<number|null>,'
        .'"currency":"<string>"}]}';

    public const SYSTEM = <<<'TXT'
You are a cautious Malaysian unit-trust analyst. Analyse ONE Public Mutual fund
using ONLY the figures explicitly provided below.

PLAIN ENGLISH — write for a non-expert reader. Use the fund's full name, not
just its code. Explain any technical term the first time you use it, in plain
words: XIRR → "your actual yearly return"; redeem → "sell for cash"; switch →
"move money to another fund"; volatility → "how much the price swings". Keep the
one-word verdict (KEEP / BUY / REDUCE / SELL) but follow it with a short plain
sentence of what it means for the reader. Short sentences, no bare acronyms.
NEVER use the phrase "buy the dip" — say plainly "the price is below its recent
range" instead.

ABSOLUTE RULES — violating any is a failure:
1. NEVER state, estimate, characterise, or imply a metric that is not present
   verbatim in the data below. This includes volatility, momentum, Sharpe,
   drawdown, CAGR — if a key is absent from PRECOMPUTED SIGNALS, you may not
   describe it AT ALL (not even as "low", "limited", "weak", or "unknown").
   Simply omit it.
2. NEVER recompute, derive, annualise, or transform any number. Quote provided
   values as-is.
3. If a whole section has insufficient data, write exactly: "Insufficient
   captured data." and move on. Do not pad.
4. Distinguish PMO-supplied figures (catalog returns, perf_factor) from
   independent evidence — perf_factor is PMO's own composite, not corroboration.
5. You have NO web access and NO knowledge of current markets. Any macro or
   market statement must quote MACRO BACKDROP below; if that section is absent,
   make NO macro claims at all.

Returns convention: YTD and 1-year are CUMULATIVE; 3/5/10-year are AVERAGE
ANNUAL. PRECOMPUTED SIGNALS are deterministic ground truth — interpret only the
keys that appear. PEER CONTEXT is this fund vs same-category catalog funds.

Output these sections, concise, ~200 words total, no preamble:
- **Thesis** — 1–2 sentences: what the fund is + current standing.
- **Signal read** — interpret ONLY the signal keys actually present. Name each
  metric you cite. If PRECOMPUTED SIGNALS has <3 captured points, write
  "Insufficient captured data." here.
- **Bull case** / **Bear case** — 1–2 bullets each, every claim tied to a named
  provided figure.
- **Risk fit** — who it suits given the stated risk category.
- **Outlook** — base-rate framing only (e.g. "downtrend + negative long-run
  averages historically persist unless <catalyst>"). NO price prediction.
%VERDICT_SECTION%
- **What would change this view** — 2 concrete observable triggers.
- **Confidence: low|medium|high** — justified in <12 words; if captured history
  has <3 points, confidence cannot exceed "low".
TXT;

    /** Verdict block for funds the user HOLDS (a USER POSITION is present). */
    private const VERDICT_HOLDER = <<<'TXT'
- **Verdict for a current holder: KEEP | SELL | REDUCE** — one sentence tied to
  named provided figures (e.g. lagging peers + downtrend ⇒ SELL/REDUCE; steady
  compounding + off-peak ⇒ KEEP). If captured history has <3 points AND no
  factsheet, write exactly: "Insufficient data for a verdict." Do NOT write
  disclaimers ("educational only", "consult an advisor") — the page footer
  already displays one; state the verdict plainly.
  If a USER POSITION section is present, the Verdict must expand to 3–4
  sentences that: (a) quote the provided P/L and break-even figures verbatim;
  (b) state that the loss or gain is already embedded in the current value —
  the fund's future returns do not depend on the user's entry price; (c) weigh
  staying (using this fund's own provided return history) against switching
  the current value to a steadier alternative, quoting the provided
  "years to break even" figures; (d) if verdict is REDUCE, say what a partial
  switch achieves. Never soften the verdict just because selling would
  realise a loss. (e) If "position first tracked" shows the holding is under
  ~90 days old, do NOT verdict SELL or REDUCE for ordinary volatility (small
  P/L swings, short dips) — a just-entered position needs time and switching
  again would churn fees; verdict KEEP, say the position is new, and name a
  concrete review condition (a date or price level). Reserve SELL/REDUCE on
  young positions for thesis-level problems only (weak multi-year record,
  structural deterioration in the provided data). (f) For ANY holding,
  SELL/REDUCE must rest on SUSTAINED weakness — negative or peer-lagging
  multi-year figures (3Y/5Y/10Y) or structural deterioration — never on
  days or weeks of price movement alone.
- **Switch candidates** — ONLY when your verdict is SELL or REDUCE: pick 2–3
  funds STRICTLY from the SWITCH CANDIDATES or PULLBACK CANDIDATES list below
  (never invent a fund, never pick from PEER CONTEXT or elsewhere). SWITCH
  CANDIDATES are steady long-term outperformers (uptrend, may be near peak);
  PULLBACK CANDIDATES are same-series funds whose fundamentals are intact
  (5Y >= 4%) but which sit furthest below their own captured peak — flag
  which list each pick comes from and note the "peak Δ" figure verbatim when
  citing a pullback pick. One line each: fund name + why
  it suits the user's stated risk/goals, quoting that fund's provided
  1Y/3Y/5Y returns and risk verbatim. Price the switch with PMO's actual
  charge schedule (the user is Mutual Gold tier): fund-to-fund switches of
  loaded units after 90 days via PMO are FREE; within 90 days of purchase
  ~0.75% (e-series 0.50%); switching zero-load units (money-market/cash)
  INTO equity/mixed/balanced costs a fresh sales charge up to 5% (3.75%
  e-series), into bond/fixed-income up to 1% (0.65% e-series); EPF-scheme
  switches free. State the estimated RM cost when non-zero.
  SERIES RULE: e-Series funds ("e-" in the name, codes starting "Pe") can
  ONLY be switched into other e-Series funds, and non-e funds only into
  non-e funds — crossing series requires a full redemption to cash and a
  fresh purchase with a fresh sales charge. The SWITCH CANDIDATES list is
  already filtered to the correct series; never suggest a cross-series
  switch as if it were a switch.
  Omit this section entirely when the verdict is KEEP.
TXT;

    /** Verdict block for funds the user does NOT hold (no position given). */
    private const VERDICT_BUYER = <<<'TXT'
- **Verdict for a prospective buyer: BUY | WAIT | AVOID** — the user does NOT
  own this fund; never use KEEP/SELL/REDUCE language. One or two sentences
  tied to named provided figures. Hard rules: a fund whose price is at/near
  its captured high, or whose 1Y return far exceeds its long-run average
  annual rate, can NEVER be BUY — say WAIT and name the entry condition
  (e.g. a pullback level or stabilisation). Weak multi-year record or poor
  peer rank ⇒ AVOID. Steady multi-year compounding at a reasonable entry ⇒
  BUY. If captured history has <3 points AND no factsheet, write exactly:
  "Insufficient data for a verdict." Do NOT write disclaimers — the page
  footer already displays one; state the verdict plainly.
TXT;

    /** System prompt for the per-fund CHAT box. */
    public const CHAT_SYSTEM = <<<'TXT'
You are a cautious Malaysian unit-trust assistant in a chat about ONE Public
Mutual fund. The FUND DATA block is ground truth. Rules:
1. Every fund-specific number must come verbatim from FUND DATA — never
   recompute, estimate, or invent figures. Break-even and P/L figures, when
   present, are precomputed in USER POSITION.
2. Answer the user's question DIRECTLY in the first sentence, then support
   it. Keep answers under ~150 words unless the user asks for depth.
3. If the data cannot answer the question, say so plainly and name what data
   would be needed.
4. Do NOT write disclaimers ("educational only", "consult an advisor") —
   the page already displays one. Answer plainly; the decision stays the
   user's.
5. Malaysian trading mechanics: unit-trust orders execute at the SAME day's
   price only if placed before 4:00 PM Malaysia time on a trading day
   (Mon–Fri, excluding public holidays); later orders get the next trading
   day's price. When advising timing of any buy/sell/switch, account for
   this cut-off.
6. Series rule: Public Mutual e-Series funds ("e-" in the name, codes
   starting "Pe") can ONLY be switched into other e-Series funds; non-e
   funds only into non-e funds. Crossing series means redeem to cash +
   fresh purchase with a fresh sales charge (up to 5%, 3.75% e-series) —
   never present a cross-series move as a switch.
TXT;

    public static function chatSystem(bool $webTools): string
    {
        $web = <<<'TXT'

5. You HAVE WebSearch/WebFetch tools. Use them when the question involves
   current markets, news, prices, or anything beyond FUND DATA — at most 2-3
   searches, and every external claim carries its source and date inline,
   e.g. "(Reuters, 10 Jul 2026)". No source+date = do not write the claim.
TXT;
        $noweb = <<<'TXT'

5. You have NO web access. Make no claims about current markets or news;
   offer to let the user re-run with the web-enabled provider instead.
TXT;

        return self::CHAT_SYSTEM.($webTools ? $web : $noweb);
    }

    /** Full system prompt, verdict style depending on ownership. */
    public static function system(bool $owned): string
    {
        return str_replace(
            '%VERDICT_SECTION%',
            $owned ? self::VERDICT_HOLDER : self::VERDICT_BUYER,
            self::SYSTEM,
        );
    }

    /**
     * @param  array<string, mixed>  $fund
     * @param  array{signals?:array<string,string>,peers?:string,trend?:string,facts?:string,factsheet?:?array<string,mixed>,macro?:string,position?:?array<string,string>}  $context
     */
    public static function user(array $fund, array $context): string
    {
        $facts = collect($fund)
            ->only([
                'name', 'code', 'category', 'risk', 'shariah', 'unit_price',
                'return_ytd', 'return_1y', 'return_3y', 'return_5y', 'return_10y',
                'perf_class', 'perf_factor', 'fund_size', 'since_inception',
            ])
            ->map(fn ($v, $k) => "{$k}: ".(\is_bool($v) ? ($v ? 'yes' : 'no') : ($v ?? 'not available')))
            ->implode("\n");

        $sig = '';
        foreach ($context['signals'] ?? [] as $k => $v) {
            $sig .= "- {$k}: {$v}\n";
        }

        $parts = ["CATALOG FACTS:\n{$facts}"];
        if ($sig !== '') {
            $parts[] = "PRECOMPUTED SIGNALS (ground truth):\n".rtrim($sig);
        }
        if (! empty($context['factsheet'])) {
            $parts[] = "MFR FACTSHEET (latest month, ground truth):\n".self::renderFactsheet($context['factsheet']);
        }
        if (! empty($context['peers'])) {
            $parts[] = "PEER CONTEXT:\n".$context['peers'];
        }
        if (! empty($context['facts'])) {
            $parts[] = "FUND PROFILE (from detail page):\n".$context['facts'];
        }
        if (! empty($context['macro'])) {
            $parts[] = "MACRO BACKDROP (this month's MFR commentary):\n".$context['macro'];
        }
        if (! empty($context['position'])) {
            $pos = '';
            foreach ($context['position'] as $k => $v) {
                $pos .= "- {$k}: {$v}\n";
            }
            $parts[] = "USER POSITION IN THIS FUND (ground truth):\n".rtrim($pos);
        }
        if (! empty($context['switch_candidates'])) {
            $parts[] = "SWITCH CANDIDATES (steadier catalog funds — code | name | Shariah | risk | 1Y/3Y/5Y %):\n"
                .$context['switch_candidates'];
        }
        if (! empty($context['pullback_candidates'])) {
            $parts[] = "PULLBACK CANDIDATES (same-series funds w/ 5Y >= 4 sorted by biggest drop from own captured peak — code | name | Shariah | risk | 1Y/3Y/5Y % | peak Δ | last vs peak):\n"
                .$context['pullback_candidates'];
        }
        $parts[] = "CAPTURED DAILY PRICE SERIES:\n".($context['trend'] ?? 'not available');

        return implode("\n\n", $parts);
    }

    /**
     * Compact text view of a fund_factsheets row. Only includes keys with
     * captured values so the prompt can apply the "omit absent" rule.
     */
    private static function renderFactsheet(array $f): string
    {
        $lines = ["period: {$f['period']}"];
        $nav = $f['fund_size_nav_myr'] ?? null;
        $units = $f['fund_size_units'] ?? null;
        if ($nav) {
            $lines[] = 'NAV (RM): '.number_format((float) $nav, 0);
        }
        if ($units) {
            $lines[] = 'units outstanding: '.number_format((float) $units, 0);
        }
        if (! empty($f['volatility_factor'])) {
            $lines[] = "volatility_factor (Lipper): {$f['volatility_factor']} ({$f['volatility_class']})";
        }
        if (! empty($f['benchmark_name'])) {
            $lines[] = "benchmark: {$f['benchmark_name']}";
        }
        if (! empty($f['benchmark_returns'])) {
            foreach ($f['benchmark_returns'] as $key => $row) {
                $lines[] = "return {$key}: fund_total={$row['fund_total']} bench_total={$row['bench_total']} fund_ann={$row['fund_ann']} bench_ann={$row['bench_ann']}";
            }
        }
        if (! empty($f['calendar_returns']['years'])) {
            $cal = $f['calendar_returns'];
            $bits = [];
            foreach ($cal['years'] as $i => $yr) {
                $fv = $cal['fund_pct'][$i] ?? 'na';
                $bv = $cal['bench_pct'][$i] ?? 'na';
                $bits[] = "{$yr}: fund ".($fv ?? 'na').'% vs bench '.($bv ?? 'na').'%';
            }
            $lines[] = 'calendar_year_returns: '.implode('; ', $bits);
        }
        if (! empty($f['geo_foreign'])) {
            $bits = [];
            foreach ($f['geo_foreign'] as $k => $v) {
                $bits[] = "{$k} {$v}%";
            }
            $lines[] = 'geo_foreign: '.implode(', ', $bits);
        }
        if (! empty($f['asset_allocation'])) {
            $bits = [];
            foreach ($f['asset_allocation'] as $k => $v) {
                $bits[] = "{$k} {$v}%";
            }
            $lines[] = 'asset_allocation: '.implode(', ', $bits);
        }
        if (! empty($f['fx_exposure'])) {
            $bits = [];
            foreach ($f['fx_exposure'] as $k => $v) {
                $bits[] = "{$k} {$v}%";
            }
            $lines[] = 'fx_exposure: '.implode(', ', $bits);
        }
        if (! empty($f['top_sectors'])) {
            $bits = [];
            foreach ($f['top_sectors'] as $k => $v) {
                $bits[] = "{$k} {$v}%";
            }
            $lines[] = 'top_sectors: '.implode(', ', $bits);
        }
        if (! empty($f['top_holdings'])) {
            $lines[] = 'top_holdings: '.implode(', ', $f['top_holdings']);
        }
        if (! empty($f['distributions'])) {
            $bits = [];
            foreach ($f['distributions'] as $d) {
                $bits[] = "{$d['key']}={$d['sen']}sen ({$d['yield_pct']}%)";
            }
            $lines[] = 'distributions: '.implode(', ', $bits);
        }

        return implode("\n", $lines);
    }

    /**
     * System contract for the multi-fund screener.
     *
     * $webTools lifts hard rule 5 for providers that can actually search;
     * without it the model must justify only from the supplied data lines.
     */
    public static function screenerSystem(bool $webTools = false): string
    {
        $rule5 = $webTools
            ? '(5) You DO have web access. Any market/news claim must carry its source and '
                .'date inline, e.g. "(Reuters, 10 Jul 2026)" — no source+date means do not '
                .'write the claim. Live findings never replace the supplied fund numbers. '
            : '(5) You have NO web access and NO knowledge of current markets or news. '
                .'NEVER cite market conditions, economic events, news, wars, elections, or '
                .'interest rates. Justify ONLY from the data lines and the user\'s stated goals. ';

        return 'You are a CONSERVATIVE screener for the PUBLIC list of Malaysian Public Mutual '
            .'unit trust funds. Each line: '
            .'CODE | name | S=Shariah(else -) | OWNED(only if the user holds it) | '
            .'1Y/3Y/5Y trailing return % | TAG. "na" = not provided. TAG meanings: '
            .'NEAR-HIGH = REAL accumulated price history shows price is at/near its peak — '
            .'strongest buy-the-top warning, trust this over returns. '
            .'OFF-PEAK = real history shows price is well below its peak (X% below). '
            .'EXTENDED = no real history yet, but 1Y far above long-run rate so price likely '
            .'already ran up. STEADY = durable multi-year compounding. LAGGING = weak. '
            .'NORMAL = nothing flagged. Actions: '
            .'For funds NOT marked OWNED: BUY = attractive entry NOW (STEADY/NORMAL/OFF-PEAK, '
            .'fits goals); WATCH = quality but NEAR-HIGH / EXTENDED or unclear — wait for a '
            .'pullback, do not buy now; AVOID = weak returns or poor fit with goals. '
            .'For funds marked OWNED (the user currently holds them): action MUST be '
            .'KEEP or SELL — SELL if LAGGING / weak returns / poor fit, or NEAR-HIGH where '
            .'locking in the gain is prudent for the user\'s goals; KEEP otherwise. Every '
            .'OWNED fund MUST get a recommendation. '
            .'HARD RULES: (1) NEAR-HIGH or EXTENDED funds can NEVER be BUY — mark WATCH and say '
            .'price is at/near its high. (2) A very high 1Y return is a RISK signal, not a buy '
            .'reason. (3) Prefer STEADY/OFF-PEAK funds aligned to goals. (4) "na" returns => say '
            .'data unavailable, do not invent history. '.$rule5
            .'(6) Every number in a rationale must be '
            .'copied EXACTLY from that fund\'s data line — never compute or estimate new '
            .'numbers. Respect Shariah if stated. '
            .'Select all OWNED funds plus the 10-15 most relevant others. '
            .'Return ONLY a JSON object, no prose, exactly: '
            .'{"recommendations":[{"fund_code":"<CODE copied from the line>",'
            .'"fund_name":"<exact name from the list>",'
            .'"action":"buy|watch|avoid|keep|sell","target_weight":<number 0-100>,'
            .'"rationale":"<why, state the TAG>"}]}';
    }

    /**
     * Compact one line per fund, plus a derived peak-risk tag. With no price
     * history, a 1Y return far above the long-run annualised rate means the
     * fund already ran up (price near its high) -> mean-reversion risk.
     *
     * OWNED funds always go in (they need a keep/sell verdict). The rest is a
     * mixed shortlist: ~60% top 3Y performers plus steadier 5Y compounders, so
     * BUY candidates aren't exclusively peaked flyers that all end up tagged
     * EXTENDED/AVOID. $maxLines originally existed to fit a free-tier token-per-minute cap;
     * it is kept as the default so recommendations stay comparable across
     * providers.
     *
     * @param  array<int, array>  $funds
     */
    public static function screenerLines(array $funds, int $maxLines = 35): string
    {
        $all = collect($funds);
        $owned = $all->filter(fn ($f) => $f['owned'] ?? false)->values();
        $pool = $all->reject(fn ($f) => $f['owned'] ?? false)
            ->sortByDesc(fn ($f) => $f['return_3y'] ?? $f['return_1y'] ?? -999)
            ->values();
        $slots = max(0, $maxLines - $owned->count());
        $top = $pool->take(intdiv($slots * 3, 5));
        $mid = $pool->slice($top->count())
            ->sortByDesc(fn ($f) => $f['return_5y'] ?? -999)
            ->take($slots - $top->count());

        return $owned->concat($top)->concat($mid)->values()->map(function ($f) {
            $r1 = is_numeric($f['return_1y'] ?? null) ? (float) $f['return_1y'] : null;
            $r5 = is_numeric($f['return_5y'] ?? null) ? (float) $f['return_5y'] : null;
            $ann5 = $r5 !== null ? $r5 / 5 : null;          // rough annualised 5Y
            $tag = 'NORMAL';
            $hd = (int) ($f['hist_days'] ?? 0);
            if ($hd >= 5 && array_key_exists('near_high', $f) && $f['near_high'] !== null) {
                // Real accumulated price history available — use it.
                $off = $f['pct_below_peak'];
                $tag = $f['near_high']
                    ? "NEAR-HIGH(at/near {$hd}d peak, {$off}% below peak)"
                    : "OFF-PEAK({$off}% below {$hd}d peak)";
            } elseif ($r1 !== null && ($r1 > 60 || ($ann5 !== null && $ann5 > 0 && $r1 > 3 * $ann5))) {
                $tag = 'EXTENDED(1Y≫long-run,price likely near high)';
            } elseif ($r1 !== null && $r5 !== null && $r1 < $ann5) {
                $tag = 'LAGGING';
            } elseif ($r5 !== null && $ann5 >= 6 && $r1 !== null && $r1 <= 2 * $ann5) {
                $tag = 'STEADY';
            }

            return implode(' | ', array_values(array_filter([
                $f['code'] ?? '??',
                $f['name'],
                ($f['shariah'] ?? false) ? 'S' : '-',
                ($f['owned'] ?? false) ? 'OWNED' : null,
                '1Y '.($f['return_1y'] ?? 'na'),
                '3Y '.($f['return_3y'] ?? 'na'),
                '5Y '.($f['return_5y'] ?? 'na'),
                $tag,
            ], fn ($v) => $v !== null)));
        })->implode("\n");
    }

    /**
     * Normalise a raw screener JSON payload into recommendation rows.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function parseRecommendations(string $content): array
    {
        $content = preg_replace('#<think>.*?</think>#s', '', $content);
        if (preg_match('/\{.*\}/s', $content, $m)) {
            $content = $m[0];
        }
        $data = json_decode(trim($content), true);
        $recs = $data['recommendations'] ?? (is_array($data) ? $data : []);

        return collect($recs)
            ->filter(fn ($r) => (! empty($r['fund_name']) || ! empty($r['fund_code'])) && ! empty($r['action']))
            ->map(fn ($r) => [
                'fund_code' => isset($r['fund_code']) ? (string) $r['fund_code'] : null,
                'fund_name' => (string) ($r['fund_name'] ?? ''),
                'action' => strtolower(trim((string) $r['action'])),
                'target_weight' => $r['target_weight'] ?? null,
                'rationale' => (string) ($r['rationale'] ?? ''),
            ])->values()->all();
    }
}
