<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CalendarEvent extends Model
{
    protected $fillable = ['event_date', 'kind', 'label', 'note'];

    protected $casts = ['event_date' => 'date'];

    /**
     * Parse pasted lines into events. One per line, pipe- or whitespace-
     * separated:  2026-09-04 | bnm | MPC meeting   (note optional, 4th field).
     * Kind defaults to "other" if the 2nd field isn't a known kind. Upserts on
     * (date, kind, label) so re-pasting is idempotent. Returns rows saved.
     */
    public static function ingestText(string $text): int
    {
        $kinds = ['bnm', 'fed', 'pmo', 'other'];
        $n = 0;
        foreach (preg_split('/\r?\n/', trim($text)) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            // Split on | if present, else on runs of whitespace.
            $parts = str_contains($line, '|')
                ? array_map('trim', explode('|', $line))
                : preg_split('/\s+/', $line, 3);

            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $parts[0] ?? '')) {
                continue;   // no leading ISO date → skip
            }
            $date = $parts[0];
            $kind = in_array(strtolower($parts[1] ?? ''), $kinds, true) ? strtolower($parts[1]) : 'other';
            // If field 2 wasn't a kind, it's really the label.
            $label = in_array(strtolower($parts[1] ?? ''), $kinds, true)
                ? ($parts[2] ?? ucfirst($kind).' event')
                : trim(($parts[1] ?? '').' '.($parts[2] ?? ''));
            $label = $label !== '' ? $label : (ucfirst($kind).' event');

            static::updateOrCreate(
                ['event_date' => $date, 'kind' => $kind, 'label' => $label],
                ['note' => $parts[3] ?? null],
            );
            $n++;
        }

        return $n;
    }

    /** Plain-English "why this matters to your book", by kind. */
    public function why(): string
    {
        return match ($this->kind) {
            'bnm'   => 'BNM rate decision → moves the ringgit → every foreign fund’s RM value',
            'fed'   => 'US Fed decision → moves USD → your US / gold exposure',
            'pmo'   => 'Public Mutual distribution / ex-date on a fund you hold',
            default => $this->note ?: 'Marked date',
        };
    }
}
