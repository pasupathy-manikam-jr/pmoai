<?php

namespace App\Console\Commands;

use App\Models\FundDetail;
use App\Services\Pdf\PhsParser;
use Illuminate\Console\Command;

class IngestPhs extends Command
{
    protected $signature = 'pmoai:ingest-phs {path : Absolute path to a per-fund PHS PDF}';

    protected $description = 'Parse a Public Mutual Product Highlights Sheet and merge static fields into fund_details.payload.';

    public function handle(): int
    {
        $path = $this->argument('path');
        if (! is_file($path)) {
            $this->error("Not a file: $path");
            return self::FAILURE;
        }

        $parser = new PhsParser();
        $data = $parser->parseFile($path);

        if (! $data['name']) {
            $this->error("Could not detect fund name in $path");
            return self::FAILURE;
        }

        // Detail rows are unique by name; code is best-effort.
        $detail = FundDetail::firstOrNew(['name' => $data['name']]);

        $payload = $detail->payload ?? [];
        $payload['phs'] = array_filter([
            'category'              => $data['category'],
            'fund_objective'        => $data['fund_objective'],
            'asset_allocation_rule' => $data['asset_allocation_rule'],
            'location'              => $data['location'],
            'investor_profile'      => $data['investor_profile'],
            'risk_text'             => $data['risk_text'],
            'fees'                  => $data['fees'] ?: null,
            'min_initial_invest'    => $data['min_initial_invest'],
            'min_additional_invest' => $data['min_additional_invest'],
            'avg_annual_returns'    => $data['avg_annual_returns'],
            'ptr'                   => $data['ptr'],
            'phs_distributions'     => $data['phs_distributions'],
            'source_pdf'            => $data['source_pdf'],
        ], fn ($v) => $v !== null && $v !== [] && $v !== '');

        $detail->code        = $data['code'] ?: $detail->code;
        $detail->name        = $data['name'];
        $detail->payload     = $payload;
        $detail->raw_text    = $detail->raw_text ?: $data['raw_text'];
        $detail->source_url  = $detail->source_url ?: $data['source_pdf'];
        $detail->captured_at = now();
        $detail->save();

        $this->info("Merged PHS for {$data['name']} ({$data['code']})");
        return self::SUCCESS;
    }
}
