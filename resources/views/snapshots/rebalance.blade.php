@extends('layouts.app')

@section('title', 'PMFAI — Rebalance')
@section('body-class', 'page-rebalance')

@section('content')
    <div class="rb-page">
        <h1>Rebalance simulator</h1>
        <p class="rb-lead">Type the % you want each fund to be, then <strong>Compute plan</strong>. You get the exact switches to get there and the fee cost. (Same-series switches are free after 90 days; gold, cross-series and cash→equity charge a fee.)</p>

        @php $total = $held->sum('value'); @endphp

        <div class="rb-card">
            <div class="rb-card-top">
                <span class="rb-eyebrow">Your book · RM {{ number_format($total, 0) }}</span>
                <span class="rb-sumpill">Targets: <b id="rb-tsum">100</b>%</span>
            </div>
            <table class="rb-table">
                <thead><tr><th>Fund</th><th class="r">RM now</th><th class="r">% now</th><th class="r">% you want</th></tr></thead>
                <tbody>
                    @foreach ($held as $i => $h)
                        @php $cur = $total > 0 ? $h['value'] / $total * 100 : 0; @endphp
                        <tr>
                            <td class="rb-fund">{!! \App\Support\FundLink::to($h['name'], null, $h['code'], false) !!}<span class="rb-code">{{ $h['code'] }}</span></td>
                            <td class="r rb-mono">{{ number_format($h['value'], 0) }}</td>
                            <td class="r rb-now">{{ number_format($cur, 1) }}%</td>
                            <td class="r"><span class="rb-inwrap"><input type="number" class="rb-target" data-i="{{ $i }}" value="{{ round($cur) }}" step="1" min="0" max="100">%</span></td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr><th>Total</th><th class="r rb-mono">{{ number_format($total, 0) }}</th><th class="r">100%</th><th class="r">—</th></tr>
                </tfoot>
            </table>
            <div class="rb-actions">
                <button id="rb-go" class="rb-btn rb-btn-primary">Compute plan</button>
                <button id="rb-example" class="rb-btn rb-btn-ghost">Try an example</button>
                <button id="rb-reset" class="rb-btn rb-btn-ghost">Reset</button>
            </div>
            <p class="rb-hint" id="rb-hint" hidden></p>
        </div>

        <div id="rb-out" hidden class="rb-out"></div>
    </div>

    <style>
        .rb-page { max-width: 920px; }
        .rb-card { overflow-x: auto; }
        .rb-fund { white-space: nowrap; }
        .rb-out table { display: table; }
        .rb-out td, .rb-out th { white-space: nowrap; }
        .rb-lead { color: #666; font-size: 13px; line-height: 1.5; margin: 0 0 16px; }
        .rb-hint { font-size: 12px; color: #555; background: #f4f7fb; border: 1px solid #e2e8f2; border-radius: 6px; padding: 7px 10px; margin: 10px 0 0; }
        .rb-card { border: 1px solid #e5e5e5; border-radius: 10px; background: #fff; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,.04); }
        .rb-card-top { display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; background: #fafafa; border-bottom: 1px solid #eee; }
        .rb-eyebrow { font-size: 12px; color: #888; text-transform: uppercase; letter-spacing: .04em; }
        .rb-sumpill { font-size: 13px; color: #555; }
        .rb-sumpill b { font-variant-numeric: tabular-nums; }
        .rb-table { width: 100%; border-collapse: collapse; }
        .rb-table th, .rb-table td { padding: 9px 16px; text-align: left; font-size: 14px; }
        .rb-table thead th { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: #999; font-weight: 600; border-bottom: 1px solid #eee; }
        .rb-table tbody tr { border-bottom: 1px solid #f2f2f2; }
        .rb-table tbody tr:hover { background: #fcfcfc; }
        .rb-table .r { text-align: right; }
        .rb-mono, .rb-now { font-variant-numeric: tabular-nums; }
        .rb-now { color: #888; }
        .rb-fund { font-weight: 500; }
        .rb-code { display: inline-block; margin-left: 6px; font-size: 11px; color: #aaa; font-weight: 400; }
        .rb-table tfoot th { padding: 10px 16px; border-top: 2px solid #eee; font-size: 13px; color: #444; }
        .rb-inwrap { display: inline-flex; align-items: center; gap: 3px; font-size: 12px; color: #999; }
        .rb-target { width: 54px; text-align: right; padding: 5px 7px; border: 1px solid #d5d5d5; border-radius: 6px; font-size: 14px; font-variant-numeric: tabular-nums; -moz-appearance: textfield; }
        .rb-target:focus { outline: none; border-color: #c8102e; box-shadow: 0 0 0 2px rgba(200,16,46,.12); }
        .rb-actions { display: flex; gap: 8px; padding: 12px 16px; background: #fafafa; border-top: 1px solid #eee; }
        .rb-btn { padding: 8px 18px; border: 0; border-radius: 7px; font-size: 13px; font-weight: 600; cursor: pointer; }
        .rb-btn-primary { background: #c8102e; color: #fff; }
        .rb-btn-primary:hover { background: #a50d26; }
        .rb-btn-ghost { background: #fff; color: #666; border: 1px solid #d5d5d5; }
        .rb-btn-ghost:hover { background: #f2f2f2; }
        .rb-out { margin-top: 18px; }
        .rb-out table { width: 100%; border-collapse: collapse; border: 1px solid #e5e5e5; border-radius: 10px; overflow: hidden; }
        .rb-out th, .rb-out td { padding: 9px 14px; font-size: 13px; text-align: left; border-bottom: 1px solid #f2f2f2; }
        .rb-out thead th { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: #999; background: #fafafa; }
        .rb-out .r { text-align: right; font-variant-numeric: tabular-nums; }
        .rb-out .rb-total th { border-top: 2px solid #eee; font-size: 14px; background: #fafafa; }
        .rb-out .pos { color: #1a7f5a; } .rb-out .neg { color: #c0392b; }
        .rb-note { font-size: 12px; color: #777; margin: 8px 0 0; line-height: 1.5; }
        .rb-warn { color: #c0392b; font-size: 13px; margin: 0 0 10px; }
    </style>

    <script>
    (function () {
        var HELD = @json($held->map(fn ($h) => ['name' => $h['name'], 'code' => $h['code'], 'value' => (float) $h['value']])->values());
        var TOTAL = {{ $total }};
        var fmt = function (n) { return 'RM ' + Math.round(n).toLocaleString(); };

        // Public Mutual mechanics
        var isE = function (f) { return /(^|\s)e-/i.test(f.name) || /^Pe[A-Z]/.test(f.code || ''); };
        var isBond = function (f) { return /BOND|SUKUK|FIXED|ENHANCED BOND/i.test(f.name); };
        var isCash = function (f) { return /CASH|MONEY MARKET/i.test(f.name); };
        var noSwitch = function (f) { return /EMAS|GOLD FUND/i.test(f.name); };
        var salesCharge = function (to) { return isBond(to) ? (isE(to) ? 0.65 : 1) : (isE(to) ? 3.75 : 5); };
        var cross = function (from, to) { return isE(from) !== isE(to); };

        function chargePct(from, to) {
            if (isCash(from)) return salesCharge(to);   // money-market → equity = fresh sales charge
            if (noSwitch(from)) return salesCharge(to); // gold has no switch — redeem then buy
            if (cross(from, to)) return salesCharge(to); // e ↔ non-e — redeem + repurchase
            return 0;                                    // same-series fund-to-fund switch = free (≥90d)
        }
        function moveType(from, to) {
            if (isCash(from)) return 'buy from cash (sales charge)';
            if (noSwitch(from)) return 'redeem gold → buy';
            if (cross(from, to)) return 'redeem + rebuy (cross-series)';
            return 'switch (free, ≥90d)';
        }
        var shortN = function (n) { return n.replace(/^PUBLIC\s+/i, ''); };

        var inputs = Array.prototype.slice.call(document.querySelectorAll('.rb-target'));
        var tsumEl = document.getElementById('rb-tsum');
        function tsum() { return inputs.reduce(function (a, i) { return a + (parseFloat(i.value) || 0); }, 0); }
        function refreshSum() {
            var s = tsum();
            tsumEl.textContent = s.toFixed(0);
            tsumEl.style.color = Math.abs(s - 100) < 0.5 ? '' : '#c0392b';
        }
        inputs.forEach(function (i) { i.addEventListener('input', refreshSum); });
        refreshSum();

        document.getElementById('rb-reset').onclick = function () {
            inputs.forEach(function (i) { i.value = Math.round(HELD[+i.dataset.i].value / TOTAL * 100); });
            refreshSum();
            document.getElementById('rb-hint').hidden = true;
            document.getElementById('rb-out').hidden = true;
        };

        // Worked example: trim your biggest fund by 8% into your smallest, then
        // compute — so you can see what a plan + its fee actually look like.
        document.getElementById('rb-example').onclick = function () {
            inputs.forEach(function (i) { i.value = Math.round(HELD[+i.dataset.i].value / TOTAL * 100); });
            var big = 0, small = 0;
            HELD.forEach(function (h, i) {
                if (h.value > HELD[big].value) big = i;
                if (h.value < HELD[small].value) small = i;
            });
            var move = Math.min(8, parseFloat(inputs[big].value) || 0);
            inputs[big].value = (parseFloat(inputs[big].value) || 0) - move;
            inputs[small].value = (parseFloat(inputs[small].value) || 0) + move;
            refreshSum();
            var hint = document.getElementById('rb-hint');
            hint.innerHTML = 'Example: move ' + move + '% out of <b>' + shortN(HELD[big].name)
                + '</b> into <b>' + shortN(HELD[small].name) + '</b>. Edit any number, or Compute plan again.';
            hint.hidden = false;
            document.getElementById('rb-go').click();
        };

        document.getElementById('rb-go').onclick = function () {
            var out = document.getElementById('rb-out');
            var s = tsum();
            // build deltas
            var sells = [], buys = [];
            inputs.forEach(function (inp) {
                var i = +inp.dataset.i, h = HELD[i];
                var target = (parseFloat(inp.value) || 0) / 100 * TOTAL;
                var d = target - h.value;
                if (d < -1) sells.push({ fund: h, rem: -d });
                else if (d > 1) buys.push({ fund: h, rem: d });
            });
            sells.sort(function (a, b) { return b.rem - a.rem; });
            buys.sort(function (a, b) { return b.rem - a.rem; });

            var moves = [], totalCost = 0, si = 0, bi = 0;
            while (si < sells.length && bi < buys.length) {
                var sl = sells[si], by = buys[bi];
                var amt = Math.min(sl.rem, by.rem);
                var pct = chargePct(sl.fund, by.fund);
                var cost = amt * pct / 100;
                moves.push({ from: sl.fund, to: by.fund, amt: amt, pct: pct, cost: cost, type: moveType(sl.fund, by.fund) });
                totalCost += cost; sl.rem -= amt; by.rem -= amt;
                if (sl.rem < 1) si++;
                if (by.rem < 1) bi++;
            }
            var leftSell = sells.slice(si).reduce(function (a, x) { return a + x.rem; }, 0);
            var leftBuy = buys.slice(bi).reduce(function (a, x) { return a + x.rem; }, 0);

            var html = '';
            if (Math.abs(s - 100) >= 0.5) {
                html += '<p class="rb-warn">⚠ Targets add up to ' + s.toFixed(0) + '%, not 100% — fix the targets first (the plan below assumes the values as entered).</p>';
            }
            if (!moves.length) {
                html += '<p class="rb-note">No moves needed — targets match current allocation.</p>';
            } else {
                html += '<table><thead><tr><th>Move</th><th>From → To</th><th class="r">Amount</th><th class="r">Charge</th></tr></thead><tbody>';
                moves.forEach(function (m) {
                    html += '<tr><td>' + m.type + '</td><td>' + shortN(m.from.name) + ' → ' + shortN(m.to.name) + '</td>'
                        + '<td class="r">' + fmt(m.amt) + '</td>'
                        + '<td class="r ' + (m.cost > 0 ? 'neg' : 'pos') + '">' + (m.cost > 0 ? fmt(m.cost) + ' (' + m.pct + '%)' : 'free') + '</td></tr>';
                });
                html += '</tbody><tfoot><tr class="rb-total"><th colspan="2">Total sales charge to rebalance</th><th class="r"></th>'
                    + '<th class="r ' + (totalCost > 0 ? 'neg' : 'pos') + '">' + (totalCost > 0 ? fmt(totalCost) : 'RM 0') + '</th></tr></tfoot></table>';
                html += '<p class="rb-note">' + fmt(totalCost) + ' = ' + (totalCost / TOTAL * 100).toFixed(2) + '% of your book, paid once. Same-series switches are free.</p>';
            }
            if (leftSell > 1) html += '<p class="rb-note">↩ ' + fmt(leftSell) + ' of sells has no matching buy → it ends up as cash.</p>';
            if (leftBuy > 1) html += '<p class="rb-note">➕ ' + fmt(leftBuy) + ' of buys has no matching sell → needs new money (fresh sales charge applies).</p>';

            out.innerHTML = html;
            out.hidden = false;
        };
    })();
    </script>
@endsection
