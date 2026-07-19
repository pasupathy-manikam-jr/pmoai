<?php

namespace App\Support;

/**
 * Dev setup has no supervisor: kick a detached one-shot queue worker so a
 * just-dispatched job runs even when nothing else is listening. No nohup —
 * FPM has no controlling console; subshell + closed stdin detaches.
 */
class Worker
{
    public static function spawn(): void
    {
        $php = config('ai.queue_php_bin');
        if (! $php || ! is_executable($php)) {
            return; // rely on an already-running worker
        }
        $cmd = sprintf(
            '(%s %s queue:work --stop-when-empty --timeout=600 < /dev/null >> %s 2>&1 &)',
            escapeshellarg($php),
            escapeshellarg(base_path('artisan')),
            escapeshellarg(storage_path('logs/queue.log')),
        );
        exec($cmd);
    }
}
