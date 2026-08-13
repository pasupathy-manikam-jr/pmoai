<?php

namespace App\Services;

use App\Models\PageCapture;

/**
 * Reads your Public Mutual Privilege Circle status straight from the latest
 * captured Portfolio page — "Your MGQP <n>". No calculation/guessing: it's the
 * real number PMO shows you. Derives tier, gap to the next tier, and the free
 * PA-insurance cover (RM1 per MGQP, capped by tier).
 */
class MembershipService
{
    /** tier => [min MGQP, PA cover cap, free switches/yr] */
    private const TIERS = [
        'Mutual Prestige'  => [10_000_000, 1_200_000, 'unlimited'],
        'Mutual Platinum'  => [3_000_000,  1_000_000, 'unlimited'],
        'Mutual Signature' => [1_500_000,  850_000,   30],
        'Mutual Elite'     => [600_000,    750_000,   25],
        'Mutual Gold'      => [150_000,    500_000,   15],
    ];

    public function current(): ?array
    {
        $cap = PageCapture::where('text', 'like', '%Your MGQP%')
            ->orderByDesc('captured_at')->orderByDesc('id')->first();
        if (! $cap) {
            return null;
        }
        $text = preg_replace('/\s+/', ' ', (string) $cap->text);

        if (! preg_match('/Your MGQP\s+([\d,]+(?:\.\d+)?)/i', $text, $m)) {
            return null;
        }
        $mgqp = (float) str_replace(',', '', $m[1]);

        // Current tier = highest whose minimum you clear.
        $tier = null;
        $capAmt = 0;
        $switches = null;
        foreach (self::TIERS as $name => [$min, $paCap, $sw]) {
            if ($mgqp >= $min) {
                $tier = $name;
                $capAmt = $paCap;
                $switches = $sw;
                break;
            }
        }
        // Next tier up + how far to it.
        $next = null;
        $gap = null;
        $ordered = array_reverse(self::TIERS, true);   // low → high
        foreach ($ordered as $name => [$min]) {
            if ($min > $mgqp) {
                $next = $name;
                $gap = $min - $mgqp;
                break;
            }
        }

        // "…Holdings as of 13 August 2026, 17:22" and the RM book value.
        $asOf = preg_match('/as of ([0-9]{1,2} \w+ [0-9]{4}[^A-Za-z]*[0-9:]*)/i', $text, $d) ? trim($d[1]) : null;
        $book = preg_match('/RM\s?([\d,]+\.\d{2})/', $text, $b) ? (float) str_replace(',', '', $b[1]) : null;

        return [
            'mgqp'      => $mgqp,
            'tier'      => $tier,
            'switches'  => $switches,
            'pa_cover'  => min($mgqp, $capAmt),
            'pa_cap'    => $capAmt,
            'next_tier' => $next,
            'gap'       => $gap,
            'as_of'     => $asOf,
            'book'      => $book,
        ];
    }
}
