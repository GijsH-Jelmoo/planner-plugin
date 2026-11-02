<?php

use Jelmoo\Planner\ShowElement;
use PHPUnit\Framework\TestCase;

class ShowElementTest extends TestCase
{
    /** @dataProvider selectors */
    public function test_selector(string $input, string $expected): void
    {
        $this->assertSame($expected, ShowElement::normalize_selector($input));
    }

    public function selectors(): array
    {
        return [
            'id' => ['#brxe-abc123', '#brxe-abc123'],
            'id zonder hekje (zoals Bricks het toont)' => ['brxe-abc123', '#brxe-abc123'],
            'class' => ['.embleem-nieuw', '.embleem-nieuw'],
            'spaties eromheen' => ['  #brxe-abc123 ', '#brxe-abc123'],
            'leeg' => ['', ''],
            'alleen een hekje' => ['#', ''],
            'meerdere selectors' => ['#a, #b', ''],
            'genest' => ['#a .b', ''],
            'losse CSS' => ['#a{color:red}body', ''],
            'style-tag breken' => ['</style><script>', ''],
            'begint met cijfer' => ['#1abc', ''],
        ];
    }

    public function test_waarden(): void
    {
        $this->assertTrue(ShowElement::is_valid_value('flex'));
        $this->assertFalse(ShowElement::is_valid_value('none'));
        $this->assertFalse(ShowElement::is_valid_value('flex;color:red'));
    }

    public function test_regel(): void
    {
        $this->assertSame('#brxe-a{display:flex!important}', ShowElement::rule('#brxe-a', 'flex'));
    }

    public function test_regels_met_tegelijk_verbergen(): void
    {
        $this->assertSame('#a{display:flex!important}', ShowElement::rules_for('#a', 'flex', ''));
        $this->assertSame('#a{display:flex!important}#b{display:none!important}', ShowElement::rules_for('#a', 'flex', '#b'));
    }

    public function test_alleen_eigen_css_wordt_vertrouwd(): void
    {
        $this->assertTrue(ShowElement::is_safe_css('#a{display:flex!important}'));
        $this->assertTrue(ShowElement::is_safe_css('#a{display:flex!important}.b-c{display:none!important}'));
        $this->assertFalse(ShowElement::is_safe_css('#a{color:red}'));
        $this->assertFalse(ShowElement::is_safe_css('#a{display:flex!important}</style><script>'));
        $this->assertFalse(ShowElement::is_safe_css(''));
        $this->assertFalse(ShowElement::is_safe_css(['#a{display:flex!important}']));
    }

    public function test_omschrijving(): void
    {
        $job = ['selector' => '#a', 'display_value' => 'flex'];
        $this->assertSame('#a (display: flex)', ShowElement::describe($job));
        $this->assertSame('#a (display: flex), verbergt #b', ShowElement::describe($job + ['hide_selector' => '#b']));
    }

    public function test_element_vinden_op_id(): void
    {
        $html = '<img class="brxe-image" alt="" id="brxe-zkhxxu" decoding="async" />';

        $this->assertTrue(ShowElement::inspect($html, '#brxe-zkhxxu')['found']);
        $this->assertFalse(ShowElement::inspect($html, '#brxe-zkhxx')['found'], 'deel van een ID telt niet');
        $this->assertFalse(ShowElement::inspect('<p>brxe-zkhxxu</p>', '#brxe-zkhxxu')['found'], 'losse tekst telt niet');
    }

    public function test_element_vinden_op_class(): void
    {
        $this->assertTrue(ShowElement::inspect('<div class="a embleem b">', '.embleem')['found']);
        $this->assertFalse(ShowElement::inspect('<div class="embleem-oud">', '.embleem')['found']);
    }

    public function test_verborgen_herkennen(): void
    {
        $this->assertTrue(ShowElement::inspect('#brxe-a {align-items: center; display: none}', '#brxe-a')['hidden']);
        $this->assertTrue(ShowElement::inspect('#brxe-a{display:none}', '#brxe-a')['hidden']);
        $this->assertFalse(ShowElement::inspect('#brxe-a {display: flex}', '#brxe-a')['hidden']);
        $this->assertFalse(ShowElement::inspect('#brxe-ab {display: none}', '#brxe-a')['hidden']);
    }

    public function test_regel_actief_ook_na_minifier(): void
    {
        $this->assertTrue(ShowElement::rule_is_active('<style>#a{display:flex!important}</style>', '#a', 'flex'));
        $this->assertTrue(ShowElement::rule_is_active('<style>#a { display: flex !important }</style>', '#a', 'flex'));
        $this->assertFalse(ShowElement::rule_is_active('<style>#a{display:flex}</style>', '#a', 'flex'));
        $this->assertFalse(ShowElement::rule_is_active('<style>#ab{display:flex!important}</style>', '#a', 'flex'));
    }
}
