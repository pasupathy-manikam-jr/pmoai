<?php

namespace App\Support;

use App\Models\Fund;
use App\Models\FundDetail;
use Illuminate\Support\HtmlString;

/**
 * Render a fund name as a link to its detail page (new tab) when a captured
 * FundDetail exists, plus its short code (abbreviation) in small text. Matching
 * is by code first, then normalised name; the maps are built once per request
 * from both the detail pages and the fund catalogue (so a code is shown even
 * for funds without a captured detail page).
 */
class FundLink
{
    private static ?array $idByCode = null;
    private static ?array $idByName = null;
    private static ?array $codeByName = null;

    private static function boot(): void
    {
        if (self::$idByCode !== null) {
            return;
        }
        self::$idByCode = self::$idByName = self::$codeByName = [];

        foreach (FundDetail::get(['id', 'code', 'name']) as $d) {
            $norm = FundDetail::normalizeName($d->name);
            if ($d->code) {
                self::$idByCode[strtoupper($d->code)] = $d->id;
                self::$codeByName[$norm] = strtoupper($d->code);
            }
            self::$idByName[$norm] = $d->id;
        }
        // Catalogue codes fill in abbreviations for funds with no detail page.
        foreach (Fund::whereNotNull('code')->get(['code', 'name']) as $f) {
            $norm = FundDetail::normalizeName($f->name);
            self::$codeByName[$norm] ??= strtoupper($f->code);
        }
    }

    public static function idFor(?string $name, ?string $code = null): ?int
    {
        self::boot();
        if ($code && isset(self::$idByCode[strtoupper($code)])) {
            return self::$idByCode[strtoupper($code)];
        }

        return self::$idByName[FundDetail::normalizeName($name)] ?? null;
    }

    private static function codeFor(?string $name, ?string $code = null): ?string
    {
        self::boot();

        return $code ? strtoupper($code) : (self::$codeByName[FundDetail::normalizeName($name)] ?? null);
    }

    /**
     * HTML for a fund label: a new-tab link when a detail page exists (else
     * plain text), followed by its code in small text when $showCode is true.
     */
    public static function to(?string $name, ?string $display = null, ?string $code = null, bool $showCode = true): HtmlString
    {
        $text = $display ?? trim((string) preg_replace('/^PUBLIC\s+/i', '', (string) $name));
        $safe = e($text);
        $id = self::idFor($name, $code);

        $label = $id === null
            ? $safe
            : '<a href="'.e(route('details.show', $id)).'" target="_blank" rel="noopener" class="fund-link">'.$safe.'</a>';

        if ($showCode) {
            $abbr = self::codeFor($name, $code);
            if ($abbr) {
                $label .= ' <span class="fund-abbr">'.e($abbr).'</span>';
            }
        }

        return new HtmlString($label);
    }
}
