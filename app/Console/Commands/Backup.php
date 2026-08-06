<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * One-command backup + restore of the whole local dataset.
 *
 *   php artisan pmoai:backup            → writes a timestamped .sql to storage/app/backups
 *   php artisan pmoai:backup --restore=storage/app/backups/pmoai-….sql
 *
 * Everything lives in this one Postgres database, so a plain pg_dump is the
 * complete, portable backup — no per-table logic to drift out of date.
 */
class Backup extends Command
{
    protected $signature = 'pmoai:backup
        {--restore= : Path to a .sql dump to restore INTO this database (overwrites current data)}
        {--keep=10 : How many recent backups to keep when creating a new one}';

    protected $description = 'Back up (pg_dump) or restore (psql) the entire local dataset';

    public function handle(): int
    {
        $cfg = config('database.connections.'.config('database.default'));
        if (($cfg['driver'] ?? null) !== 'pgsql') {
            $this->error('Backup only supports PostgreSQL (current driver: '.($cfg['driver'] ?? 'none').').');
            return self::FAILURE;
        }

        return $this->option('restore')
            ? $this->restore($cfg, $this->option('restore'))
            : $this->backup($cfg);
    }

    private function backup(array $cfg): int
    {
        $dir = storage_path('app/backups');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $file = $dir.'/pmoai-'.date('Ymd-His').'.sql';

        // --clean --if-exists so the dump can restore over an existing DB.
        $proc = new Process([
            'pg_dump', '--clean', '--if-exists', '--no-owner', '--no-privileges',
            '-h', (string) $cfg['host'], '-p', (string) $cfg['port'],
            '-U', (string) $cfg['username'], '-d', (string) $cfg['database'],
            '-f', $file,
        ], env: ['PGPASSWORD' => (string) ($cfg['password'] ?? '')] + $_ENV);
        $proc->setTimeout(600);
        $proc->run();

        if (! $proc->isSuccessful()) {
            $this->error('pg_dump failed: '.trim($proc->getErrorOutput()));
            return self::FAILURE;
        }

        $this->info('Backup written: '.$file.' ('.$this->human(filesize($file)).')');
        $this->prune($dir, (int) $this->option('keep'));

        return self::SUCCESS;
    }

    private function restore(array $cfg, string $path): int
    {
        if (! is_file($path)) {
            $this->error('No such file: '.$path);
            return self::FAILURE;
        }
        $this->warn('This OVERWRITES the current "'.$cfg['database'].'" database with: '.$path);
        if (! $this->confirm('Continue?')) {
            $this->line('Aborted.');
            return self::SUCCESS;
        }

        $proc = new Process([
            'psql', '-v', 'ON_ERROR_STOP=1',
            '-h', (string) $cfg['host'], '-p', (string) $cfg['port'],
            '-U', (string) $cfg['username'], '-d', (string) $cfg['database'],
            '-f', $path,
        ], env: ['PGPASSWORD' => (string) ($cfg['password'] ?? '')] + $_ENV);
        $proc->setTimeout(600);
        $proc->run(fn ($type, $buf) => $this->output->write($buf));

        if (! $proc->isSuccessful()) {
            $this->error('Restore failed — database may be partially changed.');
            return self::FAILURE;
        }

        $this->info('Restore complete from '.$path);
        return self::SUCCESS;
    }

    /** Keep only the newest $keep backups. */
    private function prune(string $dir, int $keep): void
    {
        if ($keep <= 0) {
            return;
        }
        $files = glob($dir.'/pmoai-*.sql') ?: [];
        rsort($files); // newest first (timestamped names sort lexically)
        foreach (array_slice($files, $keep) as $old) {
            @unlink($old);
            $this->line('  pruned old backup: '.basename($old));
        }
    }

    private function human(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $u) {
            if ($bytes < 1024) {
                return round($bytes, 1).$u;
            }
            $bytes /= 1024;
        }
        return round($bytes, 1).'TB';
    }
}
