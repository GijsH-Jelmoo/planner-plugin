<?php

namespace Jelmoo\Planner;

/*
 * Alle tijden in de Planner zijn Nederlandse tijd, wat WordPress op de site ook
 * instelt. Een nieuwe site staat standaard op UTC+0, waardoor 18:00 anders pas om
 * 19:00 live zou gaan. Een site in een ander land kan dit met het filter
 * jelmoo_planner_timezone aanpassen.
 */
class Time
{
    private const DEFAULT_ZONE = 'Europe/Amsterdam';

    private const DAYS = ['zondag', 'maandag', 'dinsdag', 'woensdag', 'donderdag', 'vrijdag', 'zaterdag'];
    private const MONTHS = ['januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december'];

    public static function zone_name(): string
    {
        $name = function_exists('apply_filters') ? apply_filters('jelmoo_planner_timezone', self::DEFAULT_ZONE) : self::DEFAULT_ZONE;

        return in_array($name, \DateTimeZone::listIdentifiers(), true) ? $name : self::DEFAULT_ZONE;
    }

    public static function zone(): \DateTimeZone
    {
        return new \DateTimeZone(self::zone_name());
    }

    public static function label(): string
    {
        $name = self::zone_name();

        return $name === self::DEFAULT_ZONE ? 'Nederlandse tijd' : str_replace('_', ' ', $name);
    }

    /** Ingevoerde datum en tijd naar een moment. Geeft null als dat moment niet bestaat. */
    public static function parse(string $input): ?int
    {
        $moment = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $input, self::zone());

        // Bij de overgang naar zomertijd bestaat 02:30 niet; PHP schuift dat stilletjes op.
        if (!$moment || $moment->format('Y-m-d\TH:i') !== $input) {
            return null;
        }

        return $moment->getTimestamp();
    }

    public static function input_value(int $timestamp): string
    {
        return self::at($timestamp)->format('Y-m-d\TH:i');
    }

    public static function clock(int $timestamp): string
    {
        return self::at($timestamp)->format('H:i');
    }

    /** "donderdag 5 november 2026" */
    public static function date(int $timestamp): string
    {
        $at = self::at($timestamp);

        return self::DAYS[(int) $at->format('w')] . ' ' . $at->format('j') . ' ' . self::MONTHS[(int) $at->format('n') - 1] . ' ' . $at->format('Y');
    }

    /** "do 5 nov" */
    public static function short_date(int $timestamp): string
    {
        $at = self::at($timestamp);

        return substr(self::DAYS[(int) $at->format('w')], 0, 2) . ' ' . $at->format('j') . ' ' . substr(self::MONTHS[(int) $at->format('n') - 1], 0, 3);
    }

    /** "donderdag 5 november 2026 om 18:00 Nederlandse tijd" */
    public static function full(int $timestamp): string
    {
        return self::date($timestamp) . ' om ' . self::clock($timestamp) . ' ' . self::label();
    }

    /** "UTC+1" */
    public static function offset(int $timestamp): string
    {
        $seconds = self::at($timestamp)->getOffset();
        $text = 'UTC' . ($seconds < 0 ? '-' : '+') . floor(abs($seconds) / 3600);
        $minutes = (abs($seconds) % 3600) / 60;

        return $minutes ? $text . ':' . sprintf('%02d', $minutes) : $text;
    }

    /** "3 uur en 12 minuten" */
    public static function span(int $seconds): string
    {
        $minutes = (int) ceil(abs($seconds) / 60);

        if ($minutes < 1) {
            return 'minder dan een minuut';
        }

        $days = (int) floor($minutes / 1440);
        $hours = (int) floor(($minutes % 1440) / 60);
        $rest = $minutes % 60;

        if ($days > 0) {
            return $days . ($days === 1 ? ' dag' : ' dagen') . ($hours ? ' en ' . $hours . ' uur' : '');
        }

        if ($hours > 0) {
            return $hours . ' uur' . ($rest ? ' en ' . $rest . ($rest === 1 ? ' minuut' : ' minuten') : '');
        }

        return $rest . ($rest === 1 ? ' minuut' : ' minuten');
    }

    public static function from_utc(string $utc): int
    {
        return (int) strtotime($utc . ' UTC');
    }

    private static function at(int $timestamp): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@' . $timestamp))->setTimezone(self::zone());
    }
}
