<?php

namespace App\Services;

/**
 * "What if the market moves" stress test — applies a shock to each region /
 * asset and projects the portfolio's RM impact using each fund's REAL captured
 * Public Mutual geography (PortfolioIndices::fundGeo). Not a monte-carlo or a
 * model: just your actual country exposure × a chosen shock.
 *
 * A shock map keys on a country (upper), plus two specials:
 *   GOLD    — applied to the whole value of the gold fund.
 *   FOREIGN — applied to every non-Malaysia slice (a ringgit-move proxy).
 */
class PortfolioStress
{
    /** Preset scenarios: label => [region => percent move]. */
    private const SCENARIOS = [
        'AI / tech correction' => ['USA' => -20, 'TAIWAN' => -18, 'KOREA' => -18, 'NETHERLANDS' => -15, 'JAPAN' => -12],
        'Gold selloff (−15%)'  => ['GOLD' => -15],
        'Ringgit rally (+5%)'  => ['FOREIGN' => -5],
        'EM-Asia risk-off'     => ['INDONESIA' => -15, 'INDIA' => -12, 'CHINA' => -12, 'KOREA' => -8, 'TAIWAN' => -8],
    ];

    public function __construct(private PortfolioIndices $indices) {}

    /**
     * @return array<int, array{label:string, delta:float, pct:float, worst:?array{name:string, delta:float}}>
     */
    public function run(): array
    {
        $funds = $this->indices->fundGeo();
        $total = array_sum(array_column($funds, 'value')) ?: 1;

        $out = [];
        foreach (self::SCENARIOS as $label => $shock) {
            $delta = 0.0;
            $worst = null;
            foreach ($funds as $f) {
                $d = $this->fundDelta($f, $shock);
                $delta += $d;
                if ($d < 0 && ($worst === null || $d < $worst['delta'])) {
                    $worst = ['name' => $f['name'], 'delta' => $d];
                }
            }
            $out[] = [
                'label' => $label,
                'delta' => $delta,
                'pct'   => $delta / $total * 100,
                'worst' => $worst,
            ];
        }

        return $out;
    }

    /** RM impact on one fund under a shock map. */
    private function fundDelta(array $f, array $shock): float
    {
        if ($f['gold']) {
            return $f['value'] * (($shock['GOLD'] ?? 0) / 100);
        }

        $delta = 0.0;
        foreach ($f['geo'] as $country => $pct) {
            $slice = $f['value'] * $pct / 100;
            $move = $shock[strtoupper($country)]
                ?? ($country !== 'MALAYSIA' ? ($shock['FOREIGN'] ?? 0) : 0);
            $delta += $slice * $move / 100;
        }

        return $delta;
    }
}
