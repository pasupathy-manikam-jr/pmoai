<?php

namespace App\Http\Controllers;

use App\Models\FundDetail;
use App\Models\Snapshot;

class FundDetailController extends Controller
{
    public function show(FundDetail $detail, \App\Services\FundAnalysis $analysis)
    {
        // The one canonical snapshot — back-link target (null = none yet).
        $snapshot = Snapshot::orderBy('id')->first();

        [$code, $priceHistory, $fund] = $analysis->resolve($detail);

        $factsheet = $analysis->factsheetFor($code);

        // This fund's own transactions — plotted as buy/sell markers on the
        // price chart. Units sign gives direction (in vs out).
        $trades = $code
            ? \App\Models\Transaction::whereRaw('upper(fund_code) = ?', [strtoupper($code)])
                ->orderBy('trans_date')
                ->get()
                ->map(fn ($t) => [
                    'date'  => $t->trans_date->toDateString(),
                    'type'  => $t->trans_type,
                    'price' => $t->price !== null ? (float) $t->price : null,
                    'units' => (float) $t->units,
                    'net'   => (float) $t->net,
                    'in'    => (float) $t->units >= 0,
                ])
                ->filter(fn ($t) => $t['price'] !== null && $t['price'] > 0)
                ->values()
            : collect();

        // Analysis state changes out-of-band (queue job) — never let the
        // browser serve this page from cache.
        return response()
            ->view('details.show', compact('detail', 'snapshot', 'priceHistory', 'fund', 'factsheet', 'trades'))
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }

    /**
     * Single-fund AI analysis. Queues AnalyzeFundJob (the CLI provider
     * outlives FastCGI timeouts), spawns a one-shot worker, redirects; the
     * page auto-refreshes while payload.ai_status = running.
     */
    public function analyze(\Illuminate\Http\Request $request, FundDetail $detail, \App\Services\FundAnalysis $analysis)
    {
        [, , $fund] = $analysis->resolve($detail);

        if (! $fund) {
            return back()->with('ai_error', 'No matching catalog fund — cannot analyze.');
        }

        $pos = $request->validate([
            'invested'      => ['nullable', 'numeric', 'min:0'],
            'current_value' => ['nullable', 'numeric', 'min:0'],
        ]);

        $invested = isset($pos['invested']) && $pos['invested'] !== null ? (float) $pos['invested'] : null;
        $value = isset($pos['current_value']) && $pos['current_value'] !== null ? (float) $pos['current_value'] : null;

        // Held funds stay holder-verdict even if the form comes in empty:
        // fall back to the stored position.
        $stored = $detail->payload['position'] ?? null;
        if ((! $invested || ! $value) && ! empty($stored['invested']) && ! empty($stored['current_value'])) {
            $invested = (float) $stored['invested'];
            $value = (float) $stored['current_value'];
        }

        // The CLI provider takes 1-2 min — longer than MAMP's 30s FastCGI
        // timeout — so the click only queues; the job does the slow part.
        $payload = $detail->payload ?? [];
        if ($invested && $value) {
            $prev = $payload['position'] ?? null;
            $payload['position'] = [
                'invested'      => $invested,
                'current_value' => $value,
                'since'         => $prev ? ($prev['since'] ?? null) : now()->toDateString(),
            ];
        }
        $payload['ai_status'] = 'running';
        unset($payload['ai_error']);
        $detail->update(['payload' => $payload]);

        \App\Jobs\AnalyzeFundJob::dispatch($detail->id, $invested, $value);
        $this->spawnWorker();

        return redirect()
            ->route('details.show', $detail)
            ->withFragment('ai-analysis');
    }

    /**
     * One chat turn about this fund — queue + poll, same as analyze.
     */
    public function chat(\Illuminate\Http\Request $request, FundDetail $detail)
    {
        $data = $request->validate(['message' => ['required', 'string', 'max:2000']]);

        $payload = $detail->payload ?? [];
        $chat = $payload['chat'] ?? [];
        $chat[] = ['role' => 'user', 'text' => $data['message'], 'at' => now()->toDateTimeString()];
        $payload['chat'] = array_slice($chat, -20);
        $payload['chat_status'] = 'running';
        unset($payload['chat_error']);
        $detail->update(['payload' => $payload]);

        \App\Jobs\ChatFundJob::dispatch($detail->id, $data['message']);
        $this->spawnWorker();

        return redirect()->route('details.show', $detail)->withFragment('ai-chat');
    }

    /**
     * Delete one chat message by its index in payload.chat. Used by the
     * per-row ✕ button; renumbering is automatic since chat is a plain list.
     */
    public function deleteChat(\Illuminate\Http\Request $request, FundDetail $detail)
    {
        $data = $request->validate(['i' => ['required', 'integer', 'min:0']]);

        $payload = $detail->payload ?? [];
        $chat = $payload['chat'] ?? [];
        if (array_key_exists($data['i'], $chat)) {
            array_splice($chat, $data['i'], 1);
            $payload['chat'] = $chat;
            $detail->update(['payload' => $payload]);
        }

        return redirect()->route('details.show', $detail)->withFragment('ai-chat');
    }

    /**
     * Save your own research note against this fund. Kept in the payload so it
     * rides along with the rest of the captured data; empty clears it.
     */
    public function saveNote(\Illuminate\Http\Request $request, FundDetail $detail)
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:10000']]);

        $payload = $detail->payload ?? [];
        $text = trim((string) ($data['note'] ?? ''));
        if ($text === '') {
            unset($payload['note'], $payload['note_at']);
        } else {
            $payload['note'] = $text;
            $payload['note_at'] = now()->toDateTimeString();
        }
        $detail->update(['payload' => $payload]);

        return redirect()->route('details.show', $detail)->withFragment('my-note');
    }

    /**
     * Lightweight poll target for the in-page progress indicators.
     */
    public function status(FundDetail $detail)
    {
        $p = $detail->payload ?? [];

        return response()->json([
            'status' => ($p['ai_status'] ?? null) === 'running'
                ? 'running'
                : (! empty($p['ai_error']) ? 'failed' : 'done'),
            'chat' => ($p['chat_status'] ?? null) === 'running'
                ? 'running'
                : (! empty($p['chat_error']) ? 'failed' : 'done'),
        ])->header('Cache-Control', 'no-store, max-age=0');
    }

    /**
     * Dev setup has no supervisor: kick a detached one-shot queue worker so
     * the just-dispatched job runs even when nothing else is listening.
     */
    private function spawnWorker(): void
    {
        $php = config('ai.queue_php_bin');
        if (! $php || ! is_executable($php)) {
            return; // rely on an already-running worker
        }
        // No nohup: FPM has no controlling console ("can't detach" error).
        // Subshell + closed stdin detaches the worker from the request.
        $cmd = sprintf(
            '(%s %s queue:work --stop-when-empty --timeout=600 < /dev/null >> %s 2>&1 &)',
            escapeshellarg($php),
            escapeshellarg(base_path('artisan')),
            escapeshellarg(storage_path('logs/queue.log')),
        );
        exec($cmd);
    }

}
