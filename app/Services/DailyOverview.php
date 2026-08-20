<?php

namespace App\Services;

use App\Models\Fund;
use App\Models\FundDetail;
use App\Models\FundPrice;
use Illuminate\Support\Carbon;

/**
 * The "Today" card: what moved, what to do, and the cut-off clock — all from
 * real fund_prices + held positions, no hard-coded numbers.
 */
class DailyOverview
{
    private const IDLE_CASH_FLAG = 10_000;   // idle cash worth flagging (RM)
    private const CONCENTRATION  = 25.0;     // top-holding weight flag (%)

    public function build(): array
    {
        $held = FundDetail::whereRaw("payload->'position'->>'current_value' is not null")->get()
            ->map(function ($d) {
                $code = $d->code ? Fund::canonicalCode($d->code) : null;
                [$today, $prev] = $this->lastTwoPrices($code);
                $val = (float) ($d->payload['position']['current_value'] ?? 0);
                $pct = ($today && $prev && $prev != 0.0) ? ($today - $prev) / $prev * 100 : null;
                // RM move = units × price change; units = value / today's price.
                $rm = ($today && $prev && $today != 0.0) ? $val * ($today - $prev) / $today : null;
                $fund = $code ? Fund::whereRaw('upper(code)=?', [strtoupper($code)])->first() : null;

                return [
                    'name' => (string) \Illuminate\Support\Str::of($d->name)->after('PUBLIC '),
                    'cat'  => $fund?->category,
                    'val'  => $val,
                    'pct'  => $pct !== null ? round($pct, 2) : null,
                    'rm'   => $rm !== null ? round($rm, 0) : null,
                ];
            });

        $moved = $held->filter(fn ($h) => $h['pct'] !== null)->values();
        $book  = $held->sum('val');

        // Drift flags — only real ones.
        $topW = $book > 0 ? $held->max('val') / $book * 100 : 0;
        $topName = $held->sortByDesc('val')->first()['name'] ?? null;
        $idleCash = $held->filter(fn ($h) => $h['cat'] === 'MM')->sum('val');
        $bondVal = $held->filter(fn ($h) => $h['cat'] === 'BO')->sum('val');

        $flags = [];
        if ($topW >= self::CONCENTRATION) {
            $flags[] = "{$topName} is ".round($topW).'% of your book — one fund carries the day.';
        }
        if ($idleCash >= self::IDLE_CASH_FLAG) {
            $flags[] = 'RM '.number_format($idleCash, 0).' idle in cash — earning ~3% while it waits.';
        }
        if ($bondVal <= 0) {
            $flags[] = 'No bond / steady fund — nothing cushions an equity drop.';
        }

        $totalRm = $moved->sum('rm');

        return [
            'cutoff'   => $this->cutoff(),
            'gainers'  => $moved->where('pct', '>', 0)->sortByDesc('pct')->take(3)->values(),
            'losers'   => $moved->where('pct', '<', 0)->sortBy('pct')->take(3)->values(),
            'total_rm' => $totalRm !== null ? round($totalRm, 0) : null,
            'moved_n'  => $moved->count(),
            'flags'    => $flags,
            'context'  => $totalRm < 0
                ? 'A down day is normal — no need to react. Review the plan monthly, not daily.'
                : 'Green today — the plan is the same whether it is up or down. Stick to it.',
        ];
    }

    /** 4 PM MYT cut-off state + the next cut-off instant (ISO) for a live countdown. */
    private function cutoff(): array
    {
        $now = Carbon::now('Asia/Kuala_Lumpur');
        $cut = $now->copy()->setTime(16, 0, 0);

        if ($now->isWeekend() || $now->gte($cut)) {
            // Roll to the next weekday 4 PM.
            $next = $now->copy()->addDay()->setTime(16, 0, 0);
            while ($next->isWeekend()) {
                $next->addDay();
            }
            $sameDay = false;
        } else {
            $next = $cut;
            $sameDay = true;
        }

        return [
            'target'  => $next->toIso8601String(),   // JS counts down to this
            'same_day' => $sameDay,
            'label'   => $sameDay ? 'today' : $next->format('D d M'),
            'closed'  => $now->isWeekend(),
        ];
    }

    /** Last two distinct daily prices for a code, newest first: [today, prev]. */
    private function lastTwoPrices(?string $code): array
    {
        if (! $code) {
            return [null, null];
        }
        $px = [];
        foreach (FundPrice::whereRaw('upper(code)=?', [strtoupper($code)])->orderBy('period')->get() as $row) {
            for ($d = 1; $d <= 31; $d++) {
                $v = $row->{"d{$d}"};
                if ($v !== null) {
                    $px[] = (float) $v;
                }
            }
        }
        $n = count($px);

        return [$n >= 1 ? $px[$n - 1] : null, $n >= 2 ? $px[$n - 2] : null];
    }
}
