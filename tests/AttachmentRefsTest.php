<?php

use Jelmoo\Planner\AttachmentRefs;
use Jelmoo\Planner\Rewriter;
use Jelmoo\Planner\Variants;
use PHPUnit\Framework\TestCase;

class AttachmentRefsTest extends TestCase
{
    private const OLD = 'https://example.nl/wp-content/uploads/lekker2026.png';
    private const OLD_SMALL = 'https://example.nl/wp-content/uploads/lekker2026-300x300.png';
    private const NEW = 'https://example.nl/wp-content/uploads/lekker2027.png';
    private const NEW_SMALL = 'https://example.nl/wp-content/uploads/lekker2027-300x200.png';

    private function rewriter(): Rewriter
    {
        return new Rewriter(new Variants(
            self::OLD,
            self::NEW,
            'https://example.nl',
            [self::OLD_SMALL => self::NEW_SMALL],
            new AttachmentRefs(20, 21, 'lekker2026.png', 'lekker2027.png')
        ));
    }

    private function serialized(array $value): array
    {
        $result = $this->rewriter()->rewrite(serialize($value));
        $this->assertSame(Rewriter::CHANGED, $result['status'], $result['reason']);

        return unserialize($result['value']);
    }

    public function test_bricks_medialink_id_bestandsnaam_en_url(): void
    {
        $out = $this->serialized([['settings' => ['tag' => 'a', 'link' => ['type' => 'media', 'mediaData' => ['id' => 20, 'filename' => 'lekker2026.png', 'url' => self::OLD]]]]]);

        $this->assertSame(['id' => 21, 'filename' => 'lekker2027.png', 'url' => self::NEW], $out[0]['settings']['link']['mediaData']);
    }

    public function test_medialink_zonder_url_op_bestandsnaam(): void
    {
        $out = $this->serialized(['mediaData' => ['id' => 20, 'filename' => 'lekker2026.png']]);

        $this->assertSame(['id' => 21, 'filename' => 'lekker2027.png'], $out['mediaData']);
    }

    public function test_afbeelding_met_kleinere_versie(): void
    {
        $out = $this->serialized(['image' => ['id' => 20, 'filename' => 'lekker2026.png', 'size' => 'medium', 'full' => self::OLD, 'url' => self::OLD_SMALL]]);

        $this->assertSame(['id' => 21, 'filename' => 'lekker2027.png', 'size' => 'medium', 'full' => self::NEW, 'url' => self::NEW_SMALL], $out['image']);
    }

    public function test_alleen_kleinere_versie_zonder_volledige_url(): void
    {
        $out = $this->serialized(['images' => [['id' => 20, 'url' => self::OLD_SMALL]]]);

        $this->assertSame(['id' => 21, 'url' => self::NEW_SMALL], $out['images'][0]);
    }

    public function test_id_als_tekst_blijft_tekst(): void
    {
        $out = $this->serialized(['image' => ['id' => '20', 'url' => self::OLD]]);

        $this->assertSame('21', $out['image']['id']);
    }

    public function test_beaver_photo_met_src(): void
    {
        $settings = new stdClass();
        $settings->photo = 20;
        $settings->photo_src = self::OLD;

        $result = $this->rewriter()->rewrite(serialize(['mod1' => ['settings' => (array) $settings]]));
        $this->assertSame(Rewriter::CHANGED, $result['status']);
        $this->assertSame(21, unserialize($result['value'])['mod1']['settings']['photo']);
    }

    public function test_getal_zonder_bewijs_blijft_staan(): void
    {
        $value = ['item' => ['id' => 20, 'url' => 'https://example.nl/iets-anders.png'], 'other' => ['id' => 20], 'image' => ['id' => 99, 'url' => self::OLD]];
        $out = $this->serialized($value);

        $this->assertSame(20, $out['item']['id'], 'andere URL naast hetzelfde getal');
        $this->assertSame(20, $out['other']['id'], 'geen URL of bestandsnaam');
        $this->assertSame(99, $out['image']['id'], 'URL naast een ander ID: ID blijft');
        $this->assertSame(self::NEW, $out['image']['url']);
    }

    public function test_url_dieper_in_een_kind_is_geen_bewijs(): void
    {
        $out = $this->serialized(['id' => 20, 'settings' => ['link' => ['url' => self::OLD]]]);

        $this->assertSame(20, $out['id']);
        $this->assertSame(self::NEW, $out['settings']['link']['url']);
    }

    public function test_langere_id_met_zelfde_begin_blijft_staan(): void
    {
        $json = '{"image":{"url":"' . str_replace('/', '\\/', self::OLD) . '","id":201}}';
        $result = $this->rewriter()->rewrite($json);

        $this->assertStringContainsString('"id":201', $result['value']);
    }

    public function test_elementor_json(): void
    {
        $json = '[{"settings":{"image":{"url":"' . str_replace('/', '\\/', self::OLD) . '","id":20,"size":"","source":"library"}}}]';
        $result = $this->rewriter()->rewrite($json);

        $this->assertSame(Rewriter::CHANGED, $result['status']);
        $decoded = json_decode($result['value'], true);
        $this->assertSame(21, $decoded[0]['settings']['image']['id']);
        $this->assertSame(self::NEW, $decoded[0]['settings']['image']['url']);
    }

    public function test_geneste_json_in_serialized(): void
    {
        $inner = wp_json_like(['image' => ['id' => 20, 'url' => self::OLD]]);
        $out = $this->serialized(['data' => $inner]);

        $this->assertSame(21, json_decode($out['data'], true)['image']['id']);
    }

    public function test_gutenberg_afbeelding(): void
    {
        $html = '<!-- wp:image {"id":20,"sizeSlug":"medium"} --><figure><img src="' . self::OLD_SMALL . '" class="wp-image-20"/></figure><!-- /wp:image -->';
        $result = $this->rewriter()->rewrite($html);

        $this->assertSame('<!-- wp:image {"id":21,"sizeSlug":"medium"} --><figure><img src="' . self::NEW_SMALL . '" class="wp-image-21"/></figure><!-- /wp:image -->', $result['value']);
    }

    public function test_gutenberg_ander_blok_met_zelfde_getal_blijft_staan(): void
    {
        $html = '<!-- wp:latest-posts {"id":20} /--><a href="' . self::OLD . '">x</a><p>wp-image-201</p>';
        $result = $this->rewriter()->rewrite($html);

        $this->assertStringContainsString('wp:latest-posts {"id":20}', $result['value']);
        $this->assertStringContainsString('wp-image-201', $result['value']);
    }

    public function test_tekst_met_alleen_de_bestandsnaam_is_verdacht(): void
    {
        $result = $this->rewriter()->rewrite('<p>Zie lekker2026.png</p>');

        $this->assertSame(Rewriter::SUSPECT, $result['status']);
        $this->assertSame('<p>Zie lekker2026.png</p>', $result['value']);
    }

    public function test_ondertekende_code_blijft_staan(): void
    {
        $result = $this->rewriter()->rewrite(serialize(['code' => '<img src="' . self::OLD . '">', 'id' => 20, 'signature' => 'abc']));

        $this->assertSame(Rewriter::SKIPPED, $result['status']);
    }

    public function test_tweede_keer_verandert_niets(): void
    {
        $once = $this->rewriter()->rewrite(serialize(['mediaData' => ['id' => 20, 'filename' => 'lekker2026.png', 'url' => self::OLD]]));
        $twice = $this->rewriter()->rewrite($once['value']);

        $this->assertSame(Rewriter::UNCHANGED, $twice['status']);
    }
}

function wp_json_like(array $value): string
{
    return json_encode($value);
}
