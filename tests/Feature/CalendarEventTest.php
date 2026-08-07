<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CalendarEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_pipe_format_parses_date_kind_label(): void
    {
        $n = CalendarEvent::ingestText('2026-09-04 | bnm | MPC meeting');

        $this->assertSame(1, $n);
        $e = CalendarEvent::first();
        $this->assertSame('bnm', $e->kind);
        $this->assertSame('MPC meeting', $e->label);
        $this->assertSame('2026-09-04', $e->event_date->toDateString());
    }

    public function test_unknown_kind_field_becomes_other_and_folds_into_label(): void
    {
        CalendarEvent::ingestText('2026-10-01 gold rebalance window');

        $e = CalendarEvent::first();
        $this->assertSame('other', $e->kind);
        $this->assertStringContainsString('gold rebalance', $e->label);
    }

    public function test_comments_and_blanks_are_skipped(): void
    {
        $n = CalendarEvent::ingestText("# a note\n\n2026-11-05 | fed | FOMC\n   \nnot-a-date line");

        $this->assertSame(1, $n);   // only the FOMC line is a valid dated row
    }

    public function test_reingest_is_idempotent(): void
    {
        CalendarEvent::ingestText('2026-12-15 | pmo | e-AI ex-date');
        CalendarEvent::ingestText('2026-12-15 | pmo | e-AI ex-date');

        $this->assertSame(1, CalendarEvent::count());
    }

    public function test_why_explains_by_kind(): void
    {
        CalendarEvent::ingestText('2026-09-04 | bnm | MPC');
        $this->assertStringContainsString('ringgit', CalendarEvent::first()->why());
    }
}
