<?php

use Jelmoo\Planner\FileSwap;
use PHPUnit\Framework\TestCase;

class FileSwapTest extends TestCase
{
    /** @var string */
    private $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/jp-' . uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*'));
        rmdir($this->dir);
    }

    private function touch_files(array $names): void
    {
        foreach ($names as $name) {
            touch($this->dir . '/' . $name);
        }
    }

    public function test_vindt_kleinere_versies(): void
    {
        $this->touch_files(['embleem.png', 'embleem-300x300.png', 'embleem-1024x1024.png', 'embleem-oud.png', 'ander-300x300.png', 'embleem-300x300.jpg']);

        $found = FileSwap::stale_variants($this->dir . '/embleem.png');
        sort($found);

        $this->assertSame(['embleem-1024x1024.png', 'embleem-300x300.png'], $found);
    }

    public function test_scaled_hoort_bij_dezelfde_afbeelding(): void
    {
        // Zoals bij Muggels: 3-scaled.png en 3-300x300.png
        $this->touch_files(['3-scaled.png', '3.png', '3-300x300.png', '30-300x300.png']);

        $this->assertSame(['3-300x300.png'], FileSwap::stale_variants($this->dir . '/3-scaled.png'));
    }

    public function test_pdf_heeft_geen_kleinere_versies(): void
    {
        $this->touch_files(['menu.pdf', 'menu-pdf.jpg', 'menu-pdf-300x300.jpg']);

        $this->assertSame([], FileSwap::stale_variants($this->dir . '/menu.pdf'));
    }
}
