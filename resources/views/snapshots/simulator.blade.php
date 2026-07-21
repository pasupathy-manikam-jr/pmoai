@extends('layouts.app')

@section('title', 'pmoai — what-if simulator')
@section('body-class', 'page-show')

@push('head')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@700;800&family=IBM+Plex+Mono:wght@400;600&display=swap" rel="stylesheet">
@endpush

@section('content')
    <p class="ps-eyebrow">What-if simulator</p>
    @if ($portfolio->isEmpty())
        <p>No holdings tracked yet — capture your holdings from PMO first.</p>
    @else
        <section class="ps-card" id="whatif">
            <h2>What-if simulator</h2>
            <p class="ps-sub" style="margin:0 0 10px">Model a switch before you make it — fee cost, new weights, and how the moved money compounds in each fund at its own 5-year rate.</p>
            <div class="wi-form">
                <label>
                    <span>From</span>
                    <select id="wi-from">
                        <option value="__cash__">New money (bank / outside)</option>
                        @foreach ($portfolio as $h)
                            <option value="{{ $h['id'] }}">{{ $h['name'] }} — RM {{ number_format($h['value'], 0) }}</option>
                        @endforeach
                    </select>
                </label>
                <label>
                    <span>To (type to search)</span>
                    <input id="wi-to" list="wi-funds" placeholder="fund name or code…" autocomplete="off">
                    <datalist id="wi-funds">
                        @foreach ($funds as $f)
                            <option value="{{ $f->code ?? $f->name }}">{{ $f->name }}</option>
                        @endforeach
                    </datalist>
                </label>
                <label>
                    <span>Amount (RM)</span>
                    <input id="wi-amt" type="number" min="0" step="any" placeholder="e.g. 30000">
                </label>
                <label>
                    <span>Charge %</span>
                    <input id="wi-fee" type="number" min="0" max="10" step="0.1" value="0"
                           title="Fund-to-fund switches were free in your history; cash→equity usually carries a sales charge — confirm with PMO">
                </label>
                <button type="button" class="btn" id="wi-go">Simulate</button>
            </div>
            <p class="ps-sub" id="wi-fee-note" style="margin-top:8px"></p>
            <div id="wi-out" class="wi-out" hidden></div>
        </section>

        @php
            $wiHeld = $portfolio->map(fn ($h) => ['id' => $h['id'], 'name' => $h['name'], 'value' => $h['value'], 'invested' => $h['invested'] ?? null, 'since' => $h['since'] ?? null])->values();
            $wiFunds = $funds->map(fn ($f) => [
                'code' => $f->code,
                'name' => $f->name,
                'r5' => $f->return_5y !== null ? (float) $f->return_5y : null,
                'r3' => $f->return_3y !== null ? (float) $f->return_3y : null,
                'risk' => $f->risk,
                'shariah' => (bool) $f->shariah,
                // real risk from latest factsheet (volatility factor + class)
                'vf' => $vol[strtoupper($f->code ?? '')]['vf'] ?? null,
                'vclass' => $vol[strtoupper($f->code ?? '')]['vclass'] ?? null,
            ])->values();
            $wiDetailFund = $portfolio->mapWithKeys(function ($h) use ($detailByCode) {
                $code = collect($detailByCode)->search($h['id']);
                return [$h['id'] => $code ?: null];
            });
        @endphp
        <script>
        (function () {
            var HELD = @json($wiHeld);
            var FUNDS = @json($wiFunds);
            var DETAIL_FUND = @json($wiDetailFund);

            var fmt = function (n, d) {
                return (n < 0 ? '−' : '') + 'RM ' + Math.abs(n).toLocaleString('en-MY', { maximumFractionDigits: d === undefined ? 0 : d });
            };
            var findFund = function (q) {
                q = (q || '').trim().toLowerCase();
                if (!q) return null;
                return FUNDS.find(function (f) { return (f.code || '').toLowerCase() === q; })
                    || FUNDS.find(function (f) { return f.name.toLowerCase() === q; })
                    || FUNDS.find(function (f) { return f.name.toLowerCase().indexOf(q) !== -1; })
                    || null;
            };
            // Auto-suggest the charge from PMO's schedule (Mutual Gold tier):
            // cash/MM/new money into equity = fresh sales charge (5% / 3.75%
            // e-series; bonds 1% / 0.65%); fund-to-fund after 90 days = free.
            // Series rule: e-Series funds switch only within e-Series (and
            // non-e only within non-e) — crossing series = redeem to cash +
            // fresh purchase, which costs the destination's full sales charge.
            var isE = function (f) { return /(^|\s)e-/i.test(f.name) || /^Pe[A-Z]/.test(f.code || ''); };
            var isEName = function (name) { return /(^|\s)e-/i.test(name || ''); };
            var isBond = function (f) { return /BOND|SUKUK|FIXED|ENHANCED BOND/i.test(f.name); };
            var salesCharge = function (to) { return isBond(to) ? (isE(to) ? 0.65 : 1) : (isE(to) ? 3.75 : 5); };
            var crossSeries = function (fromHeld, to) {
                return !!fromHeld && isEName(fromHeld.name) !== isE(to);
            };
            // Some funds have NO switching facility at all (per their PHS) — the
            // only exit is redemption to cash, then a fresh purchase of the
            // destination (its full sales charge, days out of market). PeEMAS
            // (Public e-Emas Gold Fund) is one: "Switching charge: Not
            // applicable. No switching allowed." Applies whether it's the
            // source OR the destination.
            var noSwitch = function (f) {
                if (!f) return false;
                return /EMAS|GOLD FUND/i.test(f.name || '') || /^PEEMAS$/i.test(f.code || '');
            };
            // A move needs the redeem-to-cash + fresh-purchase model when it
            // crosses e/non-e series OR touches a no-switch fund on either leg.
            var mustRedeem = function (fromHeld, to) {
                return crossSeries(fromHeld, to) || noSwitch(fromHeld) || noSwitch(to);
            };
            // Destination datalist honours the series rule: picking an
            // e-Series source narrows "To" to e-Series funds only (and
            // vice versa). New money can buy either series.
            var rebuildToList = function () {
                var fromId = document.getElementById('wi-from').value;
                var fromHeld = fromId !== '__cash__' ? HELD.find(function (h) { return String(h.id) === fromId; }) : null;
                var list = document.getElementById('wi-funds');
                var toInput = document.getElementById('wi-to');
                var pool = FUNDS;
                // A no-switch source (e.g. e-Emas) can only be redeemed to cash,
                // then the cash buys ANY fund — so the series filter doesn't
                // apply; offer the full list. Otherwise honour the series rule.
                if (fromHeld && !noSwitch(fromHeld)) {
                    var wantE = isEName(fromHeld.name);
                    pool = FUNDS.filter(function (f) { return isE(f) === wantE; });
                }
                list.innerHTML = '';
                pool.forEach(function (f) {
                    var o = document.createElement('option');
                    o.value = f.code || f.name;
                    o.textContent = f.name;
                    list.appendChild(o);
                });
                var cur = findFund(toInput.value);
                if (fromHeld && !noSwitch(fromHeld) && cur && crossSeries(fromHeld, cur)) {
                    toInput.value = '';
                    document.getElementById('wi-fee-note').textContent =
                        '⚠ destination cleared — ' + (isEName(fromHeld.name) ? 'e-Series switches only to e-Series' : 'non-e switches only to non-e') + '. List narrowed.';
                }
            };
            var suggestFee = function () {
                var to = findFund(document.getElementById('wi-to').value);
                if (!to) return;
                var fromId = document.getElementById('wi-from').value;
                var fromHeld = fromId !== '__cash__' ? HELD.find(function (h) { return String(h.id) === fromId; }) : null;
                // "cash" here = zero-sales-charge money: uninvested new money
                // (__cash__) OR a held money-market fund (e-Cash). Both never
                // paid an equity sales charge, so switching into equity carries
                // the destination's full sales charge (PMO official rule: no
                // switching FEE leaving a money-market fund, but the destination
                // equity fund's SALES CHARGE still applies — 3.75% e-series /
                // up to 5% non-e). Equity→equity differs: those units already
                // paid it, so a same-series switch after 90d is free.
                var srcIsCash = !fromHeld || /CASH|MONEY MARKET/i.test(fromHeld.name);
                var fee, why;
                if (fromHeld && noSwitch(fromHeld)) {
                    fee = salesCharge(to);
                    why = '⛔ ' + fromHeld.name.split(' ').slice(0, 3).join(' ')
                        + ' has NO switching facility (PHS: "no switching allowed"). Only exit is REDEEM TO CASH, '
                        + 'then buy the destination fresh: full sales charge (max rate shown) + days out of market. Crystallizes any gain/loss.';
                } else if (noSwitch(to)) {
                    fee = salesCharge(to);
                    why = '⛔ ' + to.name.split(' ').slice(0, 3).join(' ')
                        + ' cannot be switched INTO (PHS: "no switching allowed"). Redeem the source to cash, then buy it fresh: sales charge up to '
                        + fee + '%.';
                } else if (fromHeld && crossSeries(fromHeld, to)) {
                    fee = salesCharge(to);
                    why = '⚠ NOT SWITCHABLE — ' + (isEName(fromHeld.name) ? 'e-Series → non-e' : 'non-e → e-Series')
                        + '. Must redeem to cash first, then buy fresh: full sales charge on the destination (max rate shown)';
                } else if (srcIsCash) {
                    fee = salesCharge(to);
                    why = 'cash/new money into ' + (isBond(to) ? 'bond' : 'equity/balanced')
                        + (isE(to) ? ' (e-series)' : '') + ' = fresh sales charge (max rate shown — actual may be lower)';
                } else {
                    var days = fromHeld.since ? Math.floor((Date.now() - new Date(fromHeld.since).getTime()) / 864e5) : null;
                    if (days !== null && days < 90) {
                        fee = isEName(fromHeld.name) ? 0.5 : 0.75;
                        why = '⚠ source position only ' + days + ' days old (<90) — early-switch charge '
                            + fee + '% (min RM' + (isEName(fromHeld.name) ? '1' : '50') + '). Free in ' + (90 - days) + ' more days';
                    } else {
                        fee = 0;
                        why = 'fund-to-fund via PMO after 90 days = free (Mutual Gold)'
                            + (days === null ? '. If the source was bought under 90 days ago: ~0.5–0.75%' : ' — source held ' + days + ' days');
                    }
                }
                document.getElementById('wi-fee').value = fee;
                var n = document.getElementById('wi-fee-note');
                n.textContent = 'suggested: ' + why;
            };
            // A no-switch source (e.g. e-Emas) can't be switched at all — lock
            // the destination input and Simulate button so no move is modelled.
            var lockForNoSwitch = function () {
                var fromId = document.getElementById('wi-from').value;
                var fromHeld = fromId !== '__cash__' ? HELD.find(function (h) { return String(h.id) === fromId; }) : null;
                var locked = noSwitch(fromHeld);
                document.getElementById('wi-to').disabled = locked;
                document.getElementById('wi-go').disabled = locked;
                var out = document.getElementById('wi-out');
                if (locked) {
                    document.getElementById('wi-fee-note').textContent =
                        '⛔ ' + fromHeld.name.split(' ').slice(0, 3).join(' ')
                        + ' has NO switching facility — cannot switch out. Redeem to cash first (crystallizes gain/loss), then invest separately.';
                    out.hidden = true;
                }
                return locked;
            };
            document.getElementById('wi-from').addEventListener('change', function () {
                if (lockForNoSwitch()) return;
                rebuildToList(); suggestFee();
            });
            // A filled datalist input only offers its own value back — clear it
            // on focus so the full list drops down again; restore on blur if
            // the user picked nothing new.
            var toEl = document.getElementById('wi-to');
            toEl.addEventListener('focus', function () {
                if (this.value) { this.dataset.prev = this.value; this.value = ''; }
            });
            toEl.addEventListener('change', suggestFee);
            toEl.addEventListener('blur', function () {
                if (!this.value && this.dataset.prev) { this.value = this.dataset.prev; delete this.dataset.prev; }
                suggestFee();
            });
            rebuildToList();
            lockForNoSwitch();

            var rate = function (f) {
                if (f.r5 !== null) return { r: f.r5 / 100, src: '5Y avg' };
                if (f.r3 !== null) return { r: f.r3 / 100, src: '3Y avg' };
                return { r: 0, src: 'no record — assumed 0%' };
            };

            document.getElementById('wi-go').onclick = function () {
                var out = document.getElementById('wi-out');
                var fromId = document.getElementById('wi-from').value;
                var to = findFund(document.getElementById('wi-to').value);
                var amt = parseFloat(document.getElementById('wi-amt').value);
                var feePct = parseFloat(document.getElementById('wi-fee').value) || 0;

                if (!to) { out.hidden = false; out.innerHTML = '<p class="neg">Pick a destination fund (type its name or code).</p>'; return; }
                if (!(amt > 0)) { out.hidden = false; out.innerHTML = '<p class="neg">Enter an amount.</p>'; return; }

                var fromHeld = fromId !== '__cash__' ? HELD.find(function (h) { return String(h.id) === fromId; }) : null;
                if (fromHeld && amt > fromHeld.value) {
                    out.hidden = false;
                    out.innerHTML = '<p class="neg">Amount exceeds that fund\'s current value (' + fmt(fromHeld.value) + ').</p>';
                    return;
                }
                var fromCode = fromHeld ? DETAIL_FUND[fromHeld.id] : null;
                var fromFund = fromCode ? FUNDS.find(function (f) { return (f.code || '').toUpperCase() === String(fromCode).toUpperCase(); }) : null;

                var fee = amt * feePct / 100;
                var net = amt - fee;
                var rTo = rate(to);
                var rFrom = fromFund ? rate(fromFund) : { r: 0, src: 'cash — 0%' };

                var total = HELD.reduce(function (s, h) { return s + h.value; }, 0) + (fromHeld ? 0 : amt);
                // existing balance already sitting in the destination fund — the
                // net-50k lands on top of this and both grow at the dest rate.
                var toHeldEarly = HELD.find(function (h) { return h.name.toUpperCase().indexOf(to.name.toUpperCase()) !== -1; });
                var existingTo = toHeldEarly ? toHeldEarly.value : 0;
                var rows = '';
                // y=0 = day-1 baseline (no growth): pow(_,0)=1, so figures are the
                // raw principals — shows the starting pile before compounding.
                [0, 1, 3, 5].forEach(function (y) {
                    var stay = amt * Math.pow(1 + rFrom.r, y);
                    var move = net * Math.pow(1 + rTo.r, y);
                    var d = move - stay;
                    // full destination pile after the switch: existing holding +
                    // moved money, both compounding at the destination rate.
                    var totalPos = (existingTo + net) * Math.pow(1 + rTo.r, y);
                    rows += '<tr><td>' + (y === 0 ? 'now' : y + ' yr') + '</td><td>' + fmt(stay) + '</td><td>' + fmt(move) + '</td>'
                        + '<td class="' + (d >= 0 ? 'pos' : 'neg') + '">' + (d >= 0 ? '+' : '') + fmt(d) + '</td>'
                        + '<td>' + fmt(totalPos) + '</td></tr>';
                });

                // P/L readout — gain/loss on cost basis (invested). Informational
                // only; does NOT feed the projection (future growth compounds on
                // current value, not on what you paid — cost basis is sunk).
                var pl = function (h) {
                    if (!h || h.invested == null || !(h.invested > 0)) return null;
                    var g = h.value - h.invested, pct = g / h.invested * 100;
                    return '<span class="' + (g >= 0 ? 'pos' : 'neg') + '">'
                        + (g >= 0 ? '+' : '') + fmt(g) + ' (' + (g >= 0 ? '+' : '') + pct.toFixed(1)
                        + '%)</span> — paid ' + fmt(h.invested) + ', now ' + fmt(h.value);
                };
                var plFrom = pl(fromHeld);
                var plTo = pl(toHeldEarly);
                var realize = (fromHeld && fromHeld.invested > 0)
                    ? (fromHeld.value - fromHeld.invested) * (amt / fromHeld.value) : null;

                var wFromB = fromHeld ? (fromHeld.value / total * 100) : null;
                var wFromA = fromHeld ? ((fromHeld.value - amt) / total * 100) : null;
                var toHeld = HELD.find(function (h) { return h.name.toUpperCase().indexOf(to.name.toUpperCase()) !== -1; });
                var wToB = (toHeld ? toHeld.value : 0) / total * 100;
                var wToA = ((toHeld ? toHeld.value : 0) + net) / total * 100;

                out.hidden = false;
                var xSeries = crossSeries(fromHeld, to);
                var noSw = noSwitch(fromHeld) || noSwitch(to);
                var noSwName = (noSwitch(fromHeld) ? fromHeld : (noSwitch(to) ? to : null));
                out.innerHTML =
                    '<table>'
                    + (noSw
                        ? '<tr><th class="neg">No switch</th><td class="neg">⛔ ' + noSwName.name.split(' ').slice(0, 3).join(' ')
                          + ' has NO switching facility (PHS: "no switching allowed"). This models REDEEM TO CASH + fresh purchase — '
                          + 'destination sales charge applies, gain/loss is crystallized, and days out of market (settlement) are NOT modelled.</td></tr>'
                        : xSeries
                        ? '<tr><th class="neg">Series</th><td class="neg">⚠ ' + (isEName(fromHeld.name) ? 'e-Series → non-e' : 'non-e → e-Series')
                          + ' — direct switch NOT allowed. This models redeem-to-cash + fresh purchase (destination sales charge applies; days out of market not modelled).</td></tr>'
                        : '')
                    + '<tr><th>Move</th><td>' + fmt(amt) + (fromHeld ? ' from ' + fromHeld.name : ' new money') + ' → ' + to.name + '</td></tr>'
                    + '<tr><th>Charge</th><td>' + (fee > 0 ? fmt(fee, 2) + ' (' + feePct + '%) — ' + fmt(net, 2) + ' actually invested' : 'none') + '</td></tr>'
                    + (plFrom ? '<tr><th>' + fromHeld.name.split(' ').slice(0, 3).join(' ') + ' P/L</th><td>' + plFrom + '</td></tr>' : '')
                    + (realize != null ? '<tr><th>Realizes on ' + fmt(amt) + '</th><td class="' + (realize >= 0 ? 'pos' : 'neg') + '">'
                        + (realize >= 0 ? '+' : '') + fmt(realize) + ' ' + (realize >= 0 ? 'gain locked in' : 'loss crystallized') + ' (paper → real)</td></tr>' : '')
                    + (plTo ? '<tr><th>' + to.name.split(' ').slice(0, 3).join(' ') + ' P/L</th><td>' + plTo + '</td></tr>' : '')
                    + (fromHeld ? '<tr><th>' + fromHeld.name.split(' ').slice(0, 3).join(' ') + ' weight</th><td>' + wFromB.toFixed(1) + '% → ' + wFromA.toFixed(1) + '%</td></tr>' : '')
                    + '<tr><th>' + to.name.split(' ').slice(0, 3).join(' ') + ' weight</th><td>' + wToB.toFixed(1) + '% → ' + wToA.toFixed(1) + '%</td></tr>'
                    + '<tr><th>Rates used</th><td>stay: ' + (rFrom.r * 100).toFixed(2) + '%/yr (' + rFrom.src + ') · move: ' + (rTo.r * 100).toFixed(2) + '%/yr (' + rTo.src + ')</td></tr>'
                    // Risk change from factsheet volatility factor — a higher
                    // return into a much more volatile fund isn't a free win.
                    + (function () {
                        var vf = function (f) { return f && f.vf != null ? f.vf.toFixed(1) + (f.vclass ? ' (' + f.vclass + ')' : '') : '—'; };
                        var sf = fromFund || (srcIsCash ? { vf: 0, vclass: 'cash' } : null);
                        var moreRisk = fromFund && fromFund.vf != null && to.vf != null && to.vf > fromFund.vf;
                        return '<tr><th>Risk (volatility)</th><td class="' + (moreRisk ? 'neg' : '') + '">'
                            + vf(sf) + ' → ' + vf(to)
                            + (moreRisk ? ' ⚠ moving to a MORE volatile fund' : '')
                            + '<br><span class="ps-sub">higher volatility factor = bigger swings; from latest QFR factsheet</span></td></tr>';
                    })()
                    + '</table>'
                    + '<table><tr><th></th><th>If it stays</th><th>If it moves</th><th>Difference</th>'
                    + '<th>Total in ' + to.name.split(' ').slice(0, 3).join(' ') + '</th></tr>' + rows + '</table>'
                    + (existingTo > 0
                        ? '<p class="ps-sub" style="margin:6px 0 0">Total column = existing ' + fmt(existingTo)
                          + ' already in ' + to.name.split(' ').slice(0, 3).join(' ') + ' + moved money, both at the dest rate.</p>'
                        : '')
                    + '<p class="ps-sub">Projections compound each fund\'s own historical average — the past, not a prediction. Risk: '
                    + (to.risk || '?') + (to.shariah ? ' · Shariah' : '') + '. Confirm the actual charge with PMO before acting.</p>';
            };
        })();
        </script>
    @endif

@endsection
