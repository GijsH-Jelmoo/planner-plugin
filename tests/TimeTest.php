<?php

use Jelmoo\Planner\Time;
use PHPUnit\Framework\TestCase;

class TimeTest extends TestCase
{
    /** De melding: 18:00 ingepland moet 18:00 Nederlandse tijd zijn, dus 17:00 UTC in de winter. */
    public function test_winter_is_utc_plus_een(): void
    {
        $this->assertSame('2026-11-05 17:00', gmdate('Y-m-d H:i', Time::parse('2026-11-05T18:00')));
    }

    public function test_zomer_is_utc_plus_twee(): void
    {
        $this->assertSame('2026-07-05 16:00', gmdate('Y-m-d H:i', Time::parse('2026-07-05T18:00')));
    }

    public function test_heen_en_terug(): void
    {
        $moment = Time::parse('2026-11-05T18:00');

        $this->assertSame('18:00', Time::clock($moment));
        $this->assertSame('2026-11-05T18:00', Time::input_value($moment));
        $this->assertSame('donderdag 5 november 2026', Time::date($moment));
        $this->assertSame('do 5 nov', Time::short_date($moment));
        $this->assertSame('donderdag 5 november 2026 om 18:00 Nederlandse tijd', Time::full($moment));
    }

    public function test_tijd_die_niet_bestaat_bij_zomertijd(): void
    {
        $this->assertNull(Time::parse('2026-03-29T02:30'));
    }

    public function test_ongeldige_invoer(): void
    {
        $this->assertNull(Time::parse(''));
        $this->assertNull(Time::parse('morgen'));
        $this->assertNull(Time::parse('2026-13-01T10:00'));
    }

    public function test_offset(): void
    {
        $this->assertSame('UTC+1', Time::offset(Time::parse('2026-11-05T18:00')));
        $this->assertSame('UTC+2', Time::offset(Time::parse('2026-07-05T18:00')));
    }

    public function test_tijdsduur(): void
    {
        $this->assertSame('minder dan een minuut', Time::span(0));
        $this->assertSame('1 minuut', Time::span(30));
        $this->assertSame('5 minuten', Time::span(300));
        $this->assertSame('1 uur', Time::span(3600));
        $this->assertSame('3 uur en 12 minuten', Time::span(3 * 3600 + 12 * 60));
        $this->assertSame('2 dagen en 1 uur', Time::span(49 * 3600));
        $this->assertSame('5 minuten', Time::span(-300));
    }
}
