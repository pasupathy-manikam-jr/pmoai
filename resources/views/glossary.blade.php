@extends('layouts.app')

@section('title', 'PMFAI — Glossary')
@section('body-class', 'page-glossary')

@section('content')
    <h1>Glossary</h1>
    <p class="ps-sub" style="margin:0 0 18px">Plain-English meaning of every term the app uses. Nothing here is financial advice.</p>

    @php
        $groups = [
            'Your position' => [
                'Invested (cost)' => 'The total money you actually put in, across every purchase — your cost basis.',
                'Current value' => 'What your units are worth today (units × latest price).',
                'Gain / loss (P&L)' => 'Current value minus invested. Green = up, red = down. "Unrealised" = on paper, not sold yet.',
                'Annual return (XIRR)' => 'Your real yearly return, money-weighted — it accounts for when you put money in and took it out, not just start-vs-end price.',
                'Units' => 'How many units of the fund you hold. Units × price = value.',
                'NAV / unit price' => 'Net Asset Value — the price of one unit. Funds are bought and sold at NAV.',
            ],
            'Fund screen (catalog tags)' => [
                '🔵 Quality, down now' => 'A fund with a solid long-term record that is currently trading below its recent range — worth a look, not a buy signal.',
                '🟢 Steady record' => 'A consistent, stable performer over time.',
                '🟠 Extended (ran up)' => 'Has risen sharply lately — the price may be stretched / on the high side of its range.',
                '🔴 Weak record' => 'A poor track record — an underperformer.',
                '★ My holdings' => 'A fund you currently own.',
            ],
            'AI review' => [
                'BUY' => 'The AI suggests adding to / starting this fund.',
                'KEEP (hold)' => 'The AI suggests holding what you have — no change.',
                'REDUCE (sell part)' => 'The AI suggests selling some, not all.',
                'SELL (exit)' => 'The AI suggests selling the whole position.',
                'Verdict' => 'The AI\'s one-word call: BUY / KEEP / REDUCE / SELL (defined above).',
                'Verdict scorecard' => 'A check of whether past calls moved the right way — a keep/buy is "right" if the price rose since, a sell/reduce if it fell.',
                'Concentration' => 'How much of the whole book sits in one fund. Over ~30% means one fund\'s drop swings the entire portfolio.',
            ],
            'Triggers & alerts' => [
                'Trigger' => 'A price level you want to act on. When the price crosses it, the app notifies you.',
                'Armed' => 'A trigger that is set and waiting — not yet reached.',
                'Fired' => 'A trigger whose level has been reached — it notified you once and now shows as a banner.',
                'Captured high / low' => 'The highest and lowest prices the app has recorded for a fund. Triggers are often set just beyond these.',
                'Index trigger' => 'A trigger on a market (JCI, gold, Nasdaq…) instead of a fund — checked against the live quote feed.',
            ],
            'Funds & families' => [
                'NAV' => 'See "NAV / unit price" above.',
                'e-Series' => 'Public Mutual\'s online-only fund family ("e-" in the name, codes starting "Pe"). Can only be switched within the e-Series.',
                'PB / Public / PRS' => 'Brand families: PB (Public Bank-distributed), Public (main series), PRS (retirement scheme).',
                'Shariah' => 'Islamic funds that follow Shariah investment rules.',
                'Volatility factor' => 'A risk number from the fund\'s factsheet — higher means the price swings more.',
                'Fund-of-funds' => 'A fund that holds other funds/ETFs (e.g. the gold fund holds gold ETFs).',
            ],
            'Switching & charges' => [
                'Switch' => 'Moving money from one fund to another. Fund-to-fund via PMO after 90 days is free for Mutual Gold.',
                'Cross-series' => 'e-Series ↔ non-e is NOT switchable — you must redeem to cash and buy fresh (full sales charge).',
                'No-switch fund' => 'Some funds (e.g. e-Emas Gold) have no switch facility at all — the only exit is selling for cash.',
                'Sales charge' => 'The fee to buy into an equity fund with new/cash money — up to 3.75% (e-series) or 5% (non-e); bonds 0.65%/1%.',
                'Redeem' => 'Sell units for cash (money leaves the fund back to you).',
                '4 PM MYT cut-off' => 'Orders placed before 4 PM on a trading day get that day\'s price; after, the next trading day\'s.',
                'MGQP / Mutual Gold' => 'Your privilege tier and its qualifying points — higher tiers get lower charges.',
            ],
            'Transactions' => [
                'Settled vs pending (float)' => 'Settled = processed and priced. Pending/float = submitted but not yet processed (no price yet).',
                'II / AI' => 'Initial / Additional Investment — a buy.',
                'RII' => 'Reinvestment — a distribution paid back in as units.',
                'SWS / SWR' => 'Switch in / Switch out — the two legs of a switch.',
                'RP' => 'Redemption — selling for cash.',
                'DP / DR' => 'Distribution Payout / Reinvestment — income the fund pays you.',
            ],
            'PRS & tax' => [
                'PRS' => 'Private Retirement Scheme — locked until age 55 (early withdrawal has an 8% tax penalty).',
                'Tax relief (RM3,000/yr)' => 'PRS contributions up to RM3,000 per YEAR are tax-deductible — the cap is per person per year, NOT per fund.',
            ],
            'Currency & market' => [
                'Currency exposure' => 'How much of your book moves with a foreign currency. A foreign fund\'s RM value shifts when the ringgit moves, even if the fund is flat.',
                'USD / MYR' => 'The ringgit price of one US dollar — a rise means your USD-exposed funds are worth more in RM.',
            ],
            'Privilege Circle tiers (your perks)' => [
                '★ Mutual Gold (your tier)' => 'Entry tier — 150,000 MGQP. Up to 15 free switches/year (fee waived on units held ≥90 days, same-series). Sales charge as low as 4.75% (Public/PB) / 3.60% (e-Series). Free-for-life MG–PB Visa card + 0.3% cash bonus. Free PA insurance (RM1 per MGQP, up to ~RM500–750k).',
                'Mutual Elite' => '600,000 MGQP. Up to 25 free switches/year. Sales charge ~4.50% / 3.50%. Year-end + birthday gifts.',
                'Mutual Signature' => '1,500,000 MGQP. Up to 30 switches. ~4.25% / 3.40%. Lifetime 2.80% rate after 15 years.',
                'Mutual Platinum' => '3,000,000 MGQP. UNLIMITED switches. ~3.75% / 3.30%. Lifetime 1.50% after 12 yrs. Airport lounge (2 visits). One family member shares the lifetime rate.',
                'Mutual Prestige' => '10,000,000 MGQP. Unlimited switches. ~3.00% both series. Lifetime 1.00% after 10 yrs. Lounge (5 visits). Two family members share the rate.',
                'MGQP' => 'Mutual Gold Qualifying Points — roughly tracks how much you hold; determines your tier. More invested (in qualifying funds) = more points = higher tier.',
                'Switch waiver (the "free switch")' => 'Your tier waives the switching FEE on same-series switches after 90 days — Gold gets 15/year. It waives the fee, not the loss: a fund that\'s down is still down when you switch it.',
            ],
            'Public Mutual rules (official)' => [
                'Sales charge — equity / mixed / balanced' => 'Up to 5.0% of NAV; e-Series up to 3.75%. (EPF-MIS capped at 3%.)',
                'Sales charge — bond / sukuk' => 'Up to 1.0% of NAV; e-Series up to 0.65%.',
                'Sales charge — money market' => 'Nil — no charge to buy in.',
                'Money market — free to hold, charged to deploy' => 'e-Cash / money-market funds are free to buy, free to redeem, and free to switch to another money-market fund. BUT switching into an equity/bond fund pays the DESTINATION fund\'s sales charge (e.g. e-equity 3.75%) — that\'s why deploying idle e-Cash costs a fee. It\'s a liquid cash "parking spot" (~3% return); you only pay when you put it to work.',
                'Switching fee' => 'Minimum RM50 per switch — only RM1 for e-Series. Money-market and EPF-scheme switches are free.',
                '90-day free switch' => 'Same-series fund-to-fund switches held ≥90 days are free of the load. Privilege Circle: unlimited waivers on loaded / 1%-load units via PMO.',
                'PeEMAS (gold) — no switch' => 'The e-Emas Gold Fund has NO switch facility at all. The only exit is redeeming to cash.',
                'Cross-series' => 'e-Series ↔ non-e cannot be switched — redeem to cash and buy fresh (a full sales charge applies).',
                'Cooling-off (6 business days)' => 'First-time investors can cancel within 6 business days of Public Mutual receiving the form + payment. Refund = lower of (original, market) price + the sales charge returned.',
                'Redemption charge' => 'None — no fee to sell units.',
                'Redemption payout' => 'Within 7 business days (Privilege Circle 2; e-Series via PMO 4 business days).',
                '4 PM cut-off + forward pricing' => 'Orders before 4 PM on a business day get that day\'s price; after 4 PM, the next business day. You always get the NEXT valuation point — never a price you can see in advance.',
                'Minimum balance' => 'Leave the minimum units needed to keep the account open. Minimum redemption units vary by fund.',
            ],
        ];
    @endphp

    <div class="gloss-grid">
        @foreach ($groups as $heading => $terms)
            <section class="gloss-card">
                <h2>{{ $heading }}</h2>
                <dl>
                    @foreach ($terms as $term => $def)
                        <dt>{{ $term }}</dt>
                        <dd>{{ $def }}</dd>
                    @endforeach
                </dl>
            </section>
        @endforeach
    </div>

    <p class="ps-sub" style="margin:16px 0 0">The "Public Mutual rules (official)" figures are from Public Mutual's own investor guides
        (<a href="https://www.publicmutual.com.my/pmb/investor-guide-investment" target="_blank" rel="noopener">Investment</a>,
        <a href="https://www.publicmutual.com.my/pmb/investor-guide-switching" target="_blank" rel="noopener">Switching</a>,
        <a href="https://www.publicmutual.com.my/pmb/investor-guide-redemption" target="_blank" rel="noopener">Redemption</a>),
        captured Aug 2026. Maximums shown — the Manager may charge less. Always verify against your fund's current prospectus / PHS.</p>

    <style>
        .gloss-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 16px; }
        .gloss-card { border: 1px solid #e5e5e5; border-radius: 8px; padding: 14px 16px; background: #fff; }
        .gloss-card h2 { margin: 0 0 8px; font-size: 15px; color: #c8102e; }
        .gloss-card dt { font-weight: 600; font-size: 13px; margin-top: 8px; }
        .gloss-card dd { margin: 2px 0 0; font-size: 13px; color: #555; line-height: 1.45; }
    </style>
@endsection
