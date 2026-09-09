<?php

namespace App\Services;

use App\Models\ActionItem;
use App\Models\AdvisorState;
use App\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Turns the advisor's raw board into an intelligent brief:
 *   headline — the ONE thing worth doing now (RM at stake, why today)
 *   changes  — what's new / resolved / different since the last visit
 *   done     — suggestions you've already acted on (from your transactions +
 *              the checklist), so they're not repeated as if new
 *   remaining— everything else, compact
 * Persists the board so the next visit can diff against it.
 */
class AdvisorBrief
{
    private const RECENT_DAYS = 60;

    public function build(array $plan, array $t1rows, $actions): array
    {
        $board = collect($plan['board']);
        $book = (float) $plan['book'];
        $t1 = collect($t1rows)->keyBy('name');

        // ---- what you've already done -------------------------------------
        $since = Carbon::now()->subDays(self::RECENT_DAYS);
        $recent = Transaction::whereDate('trans_date', '>=', $since)->orderByDesc('trans_date')->get();
        $doneLabels = collect($actions)->where('done', true)->pluck('label')->map(fn ($l) => Str::lower($l));

        $rows = $board->map(function ($r) use ($recent, $doneLabels, $t1, $book) {
            $code = strtoupper((string) $r['code']);
            $short = (string) Str::of($r['name'])->after('PUBLIC ');
            $done = null;

            if ($r['action'] === 'SWITCH' && $r['switch_to']) {
                $toCode = optional(\App\Models\Fund::whereRaw('upper(name)=?', [strtoupper($r['switch_to'])])->first())->code;
                $out = $recent->first(fn ($t) => strtoupper($t->fund_code) === $code && $t->trans_type === 'SWR');
                $in = $toCode ? $recent->first(fn ($t) => strtoupper($t->fund_code) === strtoupper($toCode) && $t->trans_type === 'SWS') : null;
                if ($out) {
                    $done = 'You switched RM'.number_format(abs((float) $out->net), 0).' out on '.$out->trans_date->format('d M')
                        .($in ? ' → '.(string) Str::of($r['switch_to'])->after('PUBLIC ') : '')
                        .'. RM'.number_format((float) ($t1[$short]['value'] ?? 0), 0).' is still there.';
                }
            } elseif ($r['action'] === 'TRIM') {
                $out = $recent->first(fn ($t) => strtoupper($t->fund_code) === $code && in_array($t->trans_type, ['SWR', 'RP'], true));
                if ($out) {
                    $done = 'You trimmed RM'.number_format(abs((float) $out->net), 0).' on '.$out->trans_date->format('d M').' — still '.$r['weight'].'% of the book.';
                }
            } elseif ($r['action'] === 'DEPLOY') {
                $out = $recent->first(fn ($t) => strtoupper($t->fund_code) === $code && $t->trans_type === 'SWR');
                if ($out) {
                    $done = 'You deployed RM'.number_format(abs((float) $out->net), 0).' from cash on '.$out->trans_date->format('d M').'.';
                }
            }
            // Ticked on the checklist → treat as done too.
            $kw = Str::lower(Str::of($short)->before(' ')->value());
            if (! $done && $doneLabels->first(fn ($l) => Str::contains($l, Str::lower(Str::limit($short, 12, ''))))) {
                $done = 'Ticked as done on your checklist.';
            }

            // RM at stake (rough): trim → excess above 25%; switch → its value; deploy → the cash.
            $val = (float) ($t1[$short]['value'] ?? 0);
            $stake = match ($r['action']) {
                'TRIM'   => max(0, $val - 0.25 * $book),
                'SWITCH', 'REDEEM' => $val,
                'DEPLOY' => $val,
                'TOP UP' => 0.05 * $book,
                default  => 0,
            };
            $x = $t1[$short] ?? null;

            return $r + ['short' => $short, 'done' => $done, 'stake' => $stake,
                'exp_pct' => ($x && $x['usable']) ? $x['expected_pct'] : null,
                'exp_rm'  => ($x && $x['usable']) ? $x['expected_rm'] : null];
        });

        // ---- headline: the one thing --------------------------------------
        $prio = ['TRIM' => 3, 'SWITCH' => 3, 'REDEEM' => 3, 'DEPLOY' => 2, 'TOP UP' => 1, 'HOLD' => 0];
        $cands = $rows->filter(fn ($r) => $r['action'] !== 'HOLD' && ! $r['done']);
        $headline = null;
        if ($cands->isNotEmpty()) {
            $top = $cands->sortByDesc(fn ($r) => $prio[$r['action']] * 1e6 + $r['stake'])->first();
            $headline = $this->headline($top, $book);
        }

        // ---- changes since last visit -------------------------------------
        $prev = optional(AdvisorState::latest('id')->first())->board ?? [];
        $cur = $rows->mapWithKeys(fn ($r) => [strtoupper((string) $r['code']) => [
            'action' => $r['action'], 'switch_to' => $r['switch_to'], 'weight' => $r['weight'], 'name' => $r['short'],
        ]])->all();

        $changes = ['new' => [], 'resolved' => [], 'changed' => [], 'first' => empty($prev)];
        foreach ($cur as $code => $c) {
            $p = $prev[$code] ?? null;
            if ($c['action'] === 'HOLD') {
                if ($p && $p['action'] !== 'HOLD') {
                    $changes['resolved'][] = $c['name'].' — no longer needs a '.strtolower($p['action']);
                }
                continue;
            }
            if (! $p) {
                $changes['new'][] = $c['name'].' → '.$c['action'];
            } elseif ($p['action'] !== $c['action']) {
                $changes['changed'][] = $c['name'].': '.$p['action'].' → '.$c['action'];
            } elseif ($c['action'] === 'TRIM' && abs(($p['weight'] ?? 0) - $c['weight']) >= 1.5) {
                $changes['changed'][] = $c['name'].' weight '.$p['weight'].'% → '.$c['weight'].'%';
            }
        }
        foreach ($prev as $code => $p) {
            if (! isset($cur[$code]) && ($p['action'] ?? 'HOLD') !== 'HOLD') {
                $changes['resolved'][] = ($p['name'] ?? $code).' — no longer held';
            }
        }
        $changes['any'] = $changes['new'] || $changes['resolved'] || $changes['changed'];

        // Persist only when something differs (keeps the diff meaningful).
        $sig = fn ($b) => md5(json_encode(collect($b)->map(fn ($x) => [$x['action'], $x['switch_to'] ?? null])->sortKeys()->all()));
        if (empty($prev) || $sig($prev) !== $sig($cur)) {
            AdvisorState::create(['board' => $cur]);
            AdvisorState::where('id', '<', optional(AdvisorState::latest('id')->first())->id - 20)->delete();
        }

        return [
            'headline'  => $headline,
            'changes'   => $changes,
            'done'      => $rows->filter(fn ($r) => $r['done'])->values()->all(),
            'remaining' => $rows->filter(fn ($r) => $r['action'] !== 'HOLD' && ! $r['done'])->values()->all(),
            'holds'     => $rows->where('action', 'HOLD')->count(),
            'last_seen' => optional(AdvisorState::orderByDesc('id')->skip(1)->first())->created_at,
        ];
    }

    /** Compact text of the brief for the AI prompts — what's new, done, and left. */
    public function toText(array $b): string
    {
        $L = [];
        $L[] = $b['headline'] ? 'THE ONE THING NOW: '.$b['headline']['text'] : 'THE ONE THING NOW: nothing urgent — the book is in reasonable shape.';
        $c = $b['changes'];
        if ($c['first']) { $L[] = 'CHANGES SINCE LAST VISIT: first visit, no history yet.'; }
        elseif (! $c['any']) { $L[] = 'CHANGES SINCE LAST VISIT: none — same picture as last time.'; }
        else { $L[] = 'CHANGES SINCE LAST VISIT: '.implode('; ', array_merge($c['new'], $c['changed'], $c['resolved'])).'.'; }
        if ($b['done']) { $L[] = 'ALREADY DONE (do NOT suggest again): '.implode(' | ', array_map(fn ($d) => $d['short'].': '.$d['done'], $b['done'])); }
        if ($b['remaining']) { $L[] = 'STILL OPEN: '.implode('; ', array_map(fn ($r) => $r['action'].' '.$r['short'].' (RM'.number_format($r['stake'], 0).')'.($r['exp_pct'] !== null ? ', markets point it '.($r['exp_pct'] >= 0 ? '+' : '').$r['exp_pct'].'% at next price' : ''), $b['remaining'])).'.'; }
        $L[] = $b['holds'].' other funds are fine — leave them.';

        return implode("\n", $L);
    }

    /** Plain, specific, one-paragraph headline with the why-today. */
    private function headline(array $r, float $book): array
    {
        $n = $r['short'];
        $rm = 'RM'.number_format($r['stake'], 0);
        $today = '';
        if ($r['exp_pct'] !== null && abs($r['exp_pct']) >= 0.3) {
            $dir = $r['exp_pct'] < 0 ? 'down' : 'up';
            $today = " Markets today point it {$dir} about ".number_format(abs($r['exp_pct']), 1).'% at the next price'
                .(($r['action'] === 'TRIM' || $r['action'] === 'SWITCH' || $r['action'] === 'REDEEM')
                    ? ($r['exp_pct'] < 0 ? ' — selling before 4 PM locks in today\'s higher price.' : ' — no rush, tomorrow\'s price should be higher.')
                    : ($r['exp_pct'] < 0 ? ' — buying after 4 PM gets that lower price.' : ' — buying before 4 PM beats tomorrow\'s higher price.'));
        }
        $sw = $r['switch']['state'] ?? null;
        $fee = $sw === 'free' ? ' Free to switch (held over 90 days).'
            : ($sw === 'waiting' ? ' Note: free switch only from '.Carbon::parse($r['switch']['free_date'])->format('d M').' — moving earlier pays the load.' : '');

        $text = match ($r['action']) {
            'TRIM'   => "{$n} is {$r['weight']}% of your money — about {$rm} more than the 25% ceiling. Move that {$rm} into something else so one fund can't sink the book.{$fee}{$today}",
            'SWITCH' => "{$n} ({$rm}) is being beaten by ".(string) Str::of($r['switch_to'])->after('PUBLIC ')." at the same risk. Switch it.{$fee}{$today}",
            'REDEEM' => "{$n} ({$rm}) is losing and has nowhere better to switch to. Take it to cash.{$today}",
            'DEPLOY' => "{$rm} is sitting idle in {$n} earning ~3%. Put part of it to work — see the options below.{$today}",
            'TOP UP' => "{$n} is healthy and priced well right now — a good moment to add.{$today}",
            default  => '',
        };

        return ['fund' => $n, 'action' => $r['action'], 'stake' => $r['stake'], 'text' => $text, 'code' => $r['code']];
    }
}
