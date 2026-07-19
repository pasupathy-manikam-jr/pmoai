<?php

namespace App\Console\Commands;

use App\Models\Snapshot;
use Illuminate\Console\Command;

class PrunePmoai extends Command
{
    protected $signature = 'pmoai:prune {--days=14 : Age in days beyond which bulk is pruned}';

    protected $description = 'Drop bulky snapshot data (raw_text, recommendations, CSV) older than N days. Keeps the slim permanent fund_prices history, the global fund catalog, and the snapshot stub.';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cut = now()->subDays($days);

        $old = Snapshot::where('created_at', '<', $cut)
            ->whereNotNull('raw_text')
            ->get();

        $n = 0;
        foreach ($old as $s) {
            // Funds are a global catalog now — never pruned.
            $s->recommendations()->delete();
            $s->feedback()->delete();
            $s->update(['raw_text' => '']);   // keep stub row for the list
            @unlink(storage_path("app/snapshots/{$s->id}.csv"));
            $n++;
        }

        $this->info("Pruned {$n} snapshot(s) older than {$days}d. fund_prices history kept intact.");

        return self::SUCCESS;
    }
}
