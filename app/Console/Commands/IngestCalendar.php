<?php

namespace App\Console\Commands;

use App\Models\CalendarEvent;
use Illuminate\Console\Command;

/**
 * Bulk-load real published dates you supply (BNM MPC, Fed FOMC, PMO ex-dates).
 *
 *   php artisan pmoai:ingest-calendar dates.txt
 *
 * File format, one per line (# comments allowed):
 *   2026-09-04 | bnm | MPC meeting
 *   2026-09-17 | fed | FOMC decision
 *   2026-12-15 | pmo | e-AI distribution ex-date
 */
class IngestCalendar extends Command
{
    protected $signature = 'pmoai:ingest-calendar {path : A text file of "date | kind | label" lines}';

    protected $description = 'Load real market/PMO dates from a file into the your-funds calendar';

    public function handle(): int
    {
        $path = $this->argument('path');
        if (! is_file($path)) {
            $this->error('No such file: '.$path);
            return self::FAILURE;
        }

        $n = CalendarEvent::ingestText((string) file_get_contents($path));
        $this->info("Loaded {$n} calendar event(s).");

        return self::SUCCESS;
    }
}
