<?php

use Jelmoo\Planner\Rewriter;
use Jelmoo\Planner\Variants;
use PHPUnit\Framework\TestCase;

class RewriterTest extends TestCase
{
    private const OLD = 'https://example.nl/wp-content/uploads/oud.pdf';
    private const NEW = 'https://example.nl/wp-content/uploads/2026/10/een-veel-langere-nieuwe-naam.pdf';

    private function rewriter(): Rewriter
    {
        return new Rewriter(new Variants(self::OLD, self::NEW, 'https://example.nl'));
    }

    public function test_tekst_zonder_treffer_blijft_byte_identiek(): void
    {
        foreach (['', 'gewone tekst', 'a:1:{i:0;s:3:"foo";}', '{"a":1}', "\xFF\xFE binair", 'a:9:{kapot'] as $input) {
            $result = $this->rewriter()->rewrite($input);
            $this->assertSame(Rewriter::UNCHANGED, $result['status']);
            $this->assertSame($input, $result['value']);
        }
    }

    public function test_html(): void
    {
        $result = $this->rewriter()->rewrite('<a href="' . self::OLD . '">Menu</a>');

        $this->assertSame(Rewriter::CHANGED, $result['status']);
        $this->assertSame('<a href="' . self::NEW . '">Menu</a>', $result['value']);
        $this->assertSame(1, $result['count']);
    }

    public function test_elementor_json_blijft_geldig_en_behoudt_opmaak(): void
    {
        $data = [['elType' => 'widget', 'settings' => ['link' => ['url' => self::OLD], 'titel' => 'Café €5 "zomer"']]];
        $raw = json_encode($data);

        $result = $this->rewriter()->rewrite($raw);

        $this->assertSame(Rewriter::CHANGED, $result['status']);
        $decoded = json_decode($result['value'], true);
        $this->assertSame(self::NEW, $decoded[0]['settings']['link']['url']);
        $this->assertSame('Café €5 "zomer"', $decoded[0]['settings']['titel']);
        $this->assertStringContainsString(trim(json_encode('é'), '"'), $result['value'], 'bestaande codering blijft staan');
    }

    public function test_serialized_lengte_klopt_na_vervangen(): void
    {
        $data = ['link' => self::OLD, 'n' => 5, 'f' => 1.5, 'b' => true, 'x' => null, 'diep' => ['a' => [self::OLD]]];

        $result = $this->rewriter()->rewrite(serialize($data));

        $this->assertSame(Rewriter::CHANGED, $result['status']);
        $this->assertSame(2, $result['count']);
        $this->assertSame(
            ['link' => self::NEW, 'n' => 5, 'f' => 1.5, 'b' => true, 'x' => null, 'diep' => ['a' => [self::NEW]]],
            unserialize($result['value'])
        );
    }

    public function test_alleen_strings_veranderen(): void
    {
        $data = [];
        for ($i = 0; $i < 50; $i++) {
            $data['k' . $i] = ['tekst "; a:1:{' . $i, $i, self::OLD . '?i=' . $i, 'é' . str_repeat('ü', $i)];
        }

        $result = $this->rewriter()->rewrite(serialize($data));

        array_walk_recursive($data, function (&$value) {
            if (is_string($value)) {
                $value = str_replace(self::OLD, self::NEW, $value);
            }
        });

        $this->assertSame($data, unserialize($result['value']));
    }

    public function test_sleutel_blijft_staan(): void
    {
        $result = $this->rewriter()->rewrite(serialize([self::OLD => self::OLD]));

        $this->assertSame([self::OLD => self::NEW], unserialize($result['value']));
    }

    public function test_object_wordt_niet_aangemaakt_en_klopt_na_vervangen(): void
    {
        $object = new stdClass();
        $object->link = self::OLD;
        $raw = serialize(['o' => $object]);
        $raw = str_replace('stdClass', 'NietBestaandeKlasse', $raw);
        $raw = str_replace('O:8:', 'O:19:', $raw);

        $result = $this->rewriter()->rewrite($raw);

        $this->assertSame(Rewriter::CHANGED, $result['status']);
        $this->assertStringContainsString('O:19:"NietBestaandeKlasse":1:', $result['value']);
        $this->assertStringContainsString('s:' . strlen(self::NEW) . ':"' . self::NEW . '"', $result['value']);
    }

    public function test_referenties_blijven_kloppen(): void
    {
        $shared = self::OLD;
        $data = ['a' => &$shared, 'b' => &$shared];

        $result = $this->rewriter()->rewrite(serialize($data));

        $back = unserialize($result['value']);
        $this->assertSame(self::NEW, $back['a']);
        $this->assertSame(self::NEW, $back['b']);
    }

    public function test_geneste_serialized_string(): void
    {
        $inner = serialize(['url' => self::OLD]);

        $result = $this->rewriter()->rewrite(serialize(['inner' => $inner]));

        $outer = unserialize($result['value']);
        $this->assertSame(['url' => self::NEW], unserialize($outer['inner']));
    }

    public function test_json_in_serialized(): void
    {
        $json = json_encode(['url' => self::OLD]);

        $result = $this->rewriter()->rewrite(serialize(['data' => $json]));

        $this->assertSame(['url' => self::NEW], json_decode(unserialize($result['value'])['data'], true));
    }

    public function test_kapotte_serialized_wordt_overgeslagen(): void
    {
        $raw = 'a:1:{s:4:"link";s:999:"' . self::OLD . '";}';

        $result = $this->rewriter()->rewrite($raw);

        $this->assertSame(Rewriter::SKIPPED, $result['status']);
        $this->assertSame($raw, $result['value']);
        $this->assertNotSame('', $result['reason']);
    }

    public function test_tekst_na_het_einde_wordt_overgeslagen(): void
    {
        $raw = serialize(['link' => self::OLD]) . 'rommel';

        $this->assertSame(Rewriter::SKIPPED, $this->rewriter()->rewrite($raw)['status']);
    }

    public function test_bricks_signature_blijft_ongemoeid(): void
    {
        $elements = [[
            'id' => 'abc',
            'name' => 'code',
            'settings' => ['code' => '<a href="' . self::OLD . '">x</a>', 'executeCode' => true, 'signature' => 'hash123'],
        ]];
        $raw = serialize($elements);

        $result = $this->rewriter()->rewrite($raw);

        $this->assertSame(Rewriter::SKIPPED, $result['status']);
        $this->assertSame($raw, $result['value']);
        $this->assertStringContainsString('Bricks', $result['reason']);
    }

    public function test_bricks_link_naast_ondertekend_element_blijft_wel_vervangen_als_de_signature_elders_staat(): void
    {
        $elements = [
            ['id' => 'a', 'name' => 'code', 'settings' => ['code' => 'geen link', 'signature' => 'hash']],
            ['id' => 'b', 'name' => 'button', 'settings' => ['link' => ['type' => 'external', 'url' => self::OLD]]],
        ];

        $result = $this->rewriter()->rewrite(serialize($elements));

        $this->assertSame(Rewriter::CHANGED, $result['status']);
        $back = unserialize($result['value']);
        $this->assertSame(self::NEW, $back[1]['settings']['link']['url']);
        $this->assertSame('hash', $back[0]['settings']['signature']);
    }

    public function test_signature_in_json_blijft_ongemoeid(): void
    {
        $raw = json_encode(['settings' => ['code' => self::OLD, 'signature' => 'hash']]);

        $this->assertSame(Rewriter::SKIPPED, $this->rewriter()->rewrite($raw)['status']);
    }

    public function test_oxygen_signed_shortcode_blijft_ongemoeid(): void
    {
        $raw = '[ct_section ct_sign_sha256="abc" ct_options=\'{"url":"' . self::OLD . '"}\']';

        $this->assertSame(Rewriter::SKIPPED, $this->rewriter()->rewrite($raw)['status']);
    }

    public function test_c_token_met_treffer_wordt_overgeslagen(): void
    {
        $payload = 'x' . self::OLD;
        $raw = 'a:1:{i:0;C:5:"Klass":' . strlen($payload) . ':{' . $payload . '}}';

        $this->assertSame(Rewriter::SKIPPED, $this->rewriter()->rewrite($raw)['status']);
    }

    public function test_verdacht_wordt_niet_gewijzigd(): void
    {
        $raw = 'https://staging.example.nl/wp-content/uploads/oud.pdf';

        $result = $this->rewriter()->rewrite($raw);

        $this->assertSame(Rewriter::SUSPECT, $result['status']);
        $this->assertSame($raw, $result['value']);
    }

    public function test_gutenberg_block_blijft_geldig(): void
    {
        $raw = '<!-- wp:button {"url":"' . self::OLD . '"} --><a href="' . self::OLD . '">x</a><!-- /wp:button -->';

        $result = $this->rewriter()->rewrite($raw);

        $this->assertSame(2, $result['count']);
        $this->assertStringNotContainsString(self::OLD, $result['value']);
    }
}
