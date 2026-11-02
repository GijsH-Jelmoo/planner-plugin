<?php

use Jelmoo\Planner\Variants;
use PHPUnit\Framework\TestCase;

class VariantsTest extends TestCase
{
    private const OLD = 'https://example.nl/wp-content/uploads/2026/07/Zomer2026.pdf';
    private const NEW = 'https://example.nl/wp-content/uploads/2026/10/Herfst2026.pdf';
    private const SITE = 'https://example.nl';

    private function variants(string $old = self::OLD, string $new = self::NEW): Variants
    {
        return new Variants($old, $new, self::SITE);
    }

    /** @dataProvider replaced */
    public function test_vervangt_in_dezelfde_codering(string $input, string $expected): void
    {
        [$result] = $this->variants()->replace($input);
        $this->assertSame($expected, $result);
    }

    public function replaced(): array
    {
        return [
            'gewoon' => [
                '<a href="https://example.nl/wp-content/uploads/2026/07/Zomer2026.pdf">',
                '<a href="https://example.nl/wp-content/uploads/2026/10/Herfst2026.pdf">',
            ],
            'json (Elementor)' => [
                '{"url":"https:\/\/example.nl\/wp-content\/uploads\/2026\/07\/Zomer2026.pdf"}',
                '{"url":"https:\/\/example.nl\/wp-content\/uploads\/2026\/10\/Herfst2026.pdf"}',
            ],
            'json in json' => [
                '"https:\\\\\\/\\\\\\/example.nl\\\\\\/wp-content\\\\\\/uploads\\\\\\/2026\\\\\\/07\\\\\\/Zomer2026.pdf\\"',
                '"https:\\\\\\/\\\\\\/example.nl\\\\\\/wp-content\\\\\\/uploads\\\\\\/2026\\\\\\/10\\\\\\/Herfst2026.pdf\\"',
            ],
            'url-encoded' => [
                'u=https%3A%2F%2Fexample.nl%2Fwp-content%2Fuploads%2F2026%2F07%2FZomer2026.pdf&x=1',
                'u=https%3A%2F%2Fexample.nl%2Fwp-content%2Fuploads%2F2026%2F10%2FHerfst2026.pdf&x=1',
            ],
            'url-encoded kleine letters' => [
                'https%3a%2f%2fexample.nl%2fwp-content%2fuploads%2f2026%2f07%2fZomer2026.pdf',
                'https%3a%2f%2fexample.nl%2fwp-content%2fuploads%2f2026%2f10%2fHerfst2026.pdf',
            ],
            'http wordt https-doel' => [
                'http://example.nl/wp-content/uploads/2026/07/Zomer2026.pdf',
                self::NEW,
            ],
            'www' => [
                'https://www.example.nl/wp-content/uploads/2026/07/Zomer2026.pdf',
                self::NEW,
            ],
            'protocol-relatief' => [
                'src="//example.nl/wp-content/uploads/2026/07/Zomer2026.pdf"',
                'src="//example.nl/wp-content/uploads/2026/10/Herfst2026.pdf"',
            ],
            'site-relatief' => [
                'href="/wp-content/uploads/2026/07/Zomer2026.pdf"',
                'href="/wp-content/uploads/2026/10/Herfst2026.pdf"',
            ],
            'query blijft staan' => [
                'https://example.nl/wp-content/uploads/2026/07/Zomer2026.pdf?v=2',
                'https://example.nl/wp-content/uploads/2026/10/Herfst2026.pdf?v=2',
            ],
            'anker blijft staan' => [
                'https://example.nl/wp-content/uploads/2026/07/Zomer2026.pdf#page=2',
                'https://example.nl/wp-content/uploads/2026/10/Herfst2026.pdf#page=2',
            ],
            'zin met punt' => [
                'Zie https://example.nl/wp-content/uploads/2026/07/Zomer2026.pdf.',
                'Zie https://example.nl/wp-content/uploads/2026/10/Herfst2026.pdf.',
            ],
            'json-sluitquote' => [
                '\"https:\/\/example.nl\/wp-content\/uploads\/2026\/07\/Zomer2026.pdf\"',
                '\"https:\/\/example.nl\/wp-content\/uploads\/2026\/10\/Herfst2026.pdf\"',
            ],
            'twee keer' => [
                self::OLD . ' ' . self::OLD,
                self::NEW . ' ' . self::NEW,
            ],
        ];
    }

    /** @dataProvider untouched */
    public function test_raakt_andere_urls_niet(string $input): void
    {
        [$result, $count] = $this->variants()->replace($input);
        $this->assertSame($input, $result);
        $this->assertSame(0, $count);
    }

    public function untouched(): array
    {
        return [
            'langere bestandsnaam' => ['https://example.nl/wp-content/uploads/2026/07/Zomer2026.pdf-oud'],
            'andere extensie erachter' => ['https://example.nl/wp-content/uploads/2026/07/Zomer2026.pdf.zip'],
            'andere site' => ['https://andere-site.nl/wp-content/uploads/2026/07/Zomer2026.pdf'],
            'relatief binnen andere url' => ['https://andere-site.nl:8080/wp-content/uploads/2026/07/Zomer2026.pdf'],
            'pad loopt door' => ['https://example.nl/wp-content/uploads/2026/07/Zomer2026.pdf/x'],
            'pad loopt door json' => ['https:\/\/example.nl\/wp-content\/uploads\/2026\/07\/Zomer2026.pdf\/x'],
            'pad loopt door encoded' => ['https%3A%2F%2Fexample.nl%2Fwp-content%2Fuploads%2F2026%2F07%2FZomer2026.pdf%2Fx'],
            'hoofdletters in pad' => ['https://example.nl/wp-content/uploads/2026/07/zomer2026.pdf'],
            'andere protocol-relatieve host' => ['//andere-site.nl/wp-content/uploads/2026/07/Zomer2026.pdf'],
        ];
    }

    public function test_pagina_url_raakt_vervolgpagina_niet(): void
    {
        $variants = $this->variants('https://example.nl/menu', 'https://example.nl/menu-winter');

        $this->assertSame('https://example.nl/menu-winter', $variants->replace('https://example.nl/menu')[0]);
        $this->assertSame('https://example.nl/menu-winter', $variants->replace('https://example.nl/menu/')[0]);
        $this->assertSame('https://example.nl/menu-2', $variants->replace('https://example.nl/menu-2')[0]);
        $this->assertSame('https://example.nl/menu/sub', $variants->replace('https://example.nl/menu/sub')[0]);
    }

    public function test_vervanging_wordt_niet_opnieuw_geraakt(): void
    {
        $variants = $this->variants('https://example.nl/menu', 'https://example.nl/menu/menu');

        $this->assertSame('https://example.nl/menu/menu', $variants->replace('https://example.nl/menu')[0]);
    }

    public function test_block_attributen_met_dubbel_streepje_en_ampersand(): void
    {
        $variants = $this->variants('https://example.nl/a--b.pdf', 'https://example.nl/c--d.pdf');
        $this->assertSame(
            'https://example.nl/c--d.pdf',
            $variants->replace('https://example.nl/a--b.pdf')[0]
        );

        $variants = $this->variants('https://example.nl/a?x=1&y=2', 'https://example.nl/b?x=1&y=3');
        $this->assertSame('https://example.nl/b?x=1&amp;y=3', $variants->replace('https://example.nl/a?x=1&amp;y=2')[0]);
        $this->assertSame('https://example.nl/b?x=1&#038;y=3', $variants->replace('https://example.nl/a?x=1&#038;y=2')[0]);
        $this->assertSame('https://example.nl/b?x=1&y=3', $variants->replace('https://example.nl/a?x=1&y=2')[0]);
    }

    public function test_spatie_in_bestandsnaam(): void
    {
        $variants = $this->variants('https://example.nl/Menu%20Oud.pdf', self::NEW);

        $this->assertSame(self::NEW, $variants->replace('https://example.nl/Menu%20Oud.pdf')[0]);
        $this->assertSame(self::NEW, $variants->replace('https://example.nl/Menu Oud.pdf')[0]);
    }

    public function test_relatief_krijgt_absolute_url_bij_doel_op_andere_site(): void
    {
        $variants = $this->variants(self::OLD, 'https://cdn.nl/menu.pdf');

        $this->assertSame('"https://cdn.nl/menu.pdf"', $variants->replace('"/wp-content/uploads/2026/07/Zomer2026.pdf"')[0]);
    }

    public function test_externe_oude_url_krijgt_geen_relatieve_variant(): void
    {
        $variants = new Variants('https://extern.nl/menu.pdf', self::NEW, self::SITE);

        $this->assertSame('"/menu.pdf"', $variants->replace('"/menu.pdf"')[0]);
        $this->assertSame('"' . self::NEW . '"', $variants->replace('"https://extern.nl/menu.pdf"')[0]);
    }

    public function test_verdacht_als_alleen_de_bestandsnaam_past(): void
    {
        $variants = $this->variants();

        $this->assertTrue($variants->is_suspect('https://staging.example.nl/wp-content/uploads/2026/07/Zomer2026.pdf'));
        $this->assertFalse($variants->is_suspect(self::OLD));
        $this->assertFalse($variants->is_suspect('geen bestand'));
        $this->assertFalse($this->variants('https://example.nl/menu', self::NEW)->is_suspect('het menu van vandaag'));
    }

    public function test_zoektermen_bevatten_de_bestandsnaam(): void
    {
        $this->assertContains('Zomer2026.pdf', $this->variants()->search_terms());
        $this->assertContains('example.nl', $this->variants('https://example.nl/', self::NEW)->search_terms());
    }

    public function test_weigert_url_zonder_host(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Variants('/menu.pdf', self::NEW, self::SITE);
    }
}
