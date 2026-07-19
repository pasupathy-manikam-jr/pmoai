<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;

/**
 * Accepts either pasted text OR a local file path. If the input is a path to
 * an existing file / .rtfd bundle, its content is loaded and converted to
 * plain text (RTF/RTFD/DOC via macOS `textutil`). Otherwise the input is
 * returned unchanged (treated as a direct paste).
 *
 * Local single-user tool: arbitrary local file read is acceptable here, but
 * the path is restricted to the user's home directory as a guard.
 */
class SourceLoader
{
    public function load(string $input): string
    {
        $path = trim($input);

        // Heuristic: a path is a single line with no spaces-as-data, exists on disk.
        if (! $this->looksLikePath($path)) {
            return $input;
        }

        $home = rtrim((string) getenv('HOME'), '/');
        $real = realpath($path);
        if ($real === false || ($home !== '' && ! str_starts_with($real, $home))) {
            return $input; // outside home or missing -> treat as paste
        }

        $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));

        // .rtfd is a bundle (directory); .rtf/.doc/.docx need conversion.
        if (in_array($ext, ['rtfd', 'rtf', 'doc', 'docx', 'odt', 'html', 'webarchive'], true) || is_dir($real)) {
            $res = Process::timeout(60)->run([
                'textutil', '-convert', 'txt', '-stdout', $real,
            ]);
            if ($res->successful() && trim($res->output()) !== '') {
                return $res->output();
            }
        }

        $content = @file_get_contents($real);
        if ($content === false) {
            return $input;
        }

        // Guard: if raw HTML slipped through, strip tags so no markup enters
        // the pipeline.
        if (preg_match('/<[a-z!][\s\S]*?>/i', $content)) {
            $content = strip_tags($content);
        }

        return $content;
    }

    private function looksLikePath(string $s): bool
    {
        return $s !== ''
            && ! str_contains($s, "\n")
            && (str_starts_with($s, '/') || str_starts_with($s, '~'))
            && (file_exists($s) || is_dir($s));
    }
}
