<?php

namespace App\Console\Commands;

use App\Models\MarketEvent;
use App\Services\EmbeddingService;
use App\Services\Pdf\MfrMacroParser;
use Illuminate\Console\Command;
use Pgvector\Laravel\Vector;
use Throwable;

class IngestMacro extends Command
{
    protected $signature = 'pmoai:ingest-macro
        {path : Absolute path to the MFR PDF (macro pages 1-6 are read)}
        {--period= : Override YYYY-MM}
        {--no-embed : Skip embedding (useful when Ollama is offline)}';

    protected $description = 'Extract MFR macro commentary (rate, FX, GDP outlook) and store as market_events with embeddings.';

    public function handle(EmbeddingService $embed): int
    {
        $path = $this->argument('path');
        if (! is_file($path)) {
            $this->error("Not a file: $path");
            return self::FAILURE;
        }

        $parser = new MfrMacroParser();
        $events = $parser->parseFile($path, $this->option('period') ?: null);

        if (! $events) {
            $this->warn('No macro sections detected.');
            return self::SUCCESS;
        }

        $skipEmbed = (bool) $this->option('no-embed');

        $written = 0;
        foreach ($events as $e) {
            $vec = null;
            if (! $skipEmbed) {
                try {
                    $vec = new Vector($embed->embed($e['body']));
                } catch (Throwable $ex) {
                    $this->warn("Embed failed for {$e['headline']}: {$ex->getMessage()}");
                }
            }

            MarketEvent::updateOrCreate(
                ['source' => $e['source'], 'headline' => $e['headline']],
                [
                    'body'         => $e['body'],
                    'published_at' => $e['published_at'],
                    'embedding'    => $vec,
                ],
            );
            $written++;
        }

        $this->info("Ingested $written macro section(s) from ".basename($path));
        return self::SUCCESS;
    }
}
