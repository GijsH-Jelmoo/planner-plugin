<?php
/*
 * Haalt elke serialized waarde uit de database door de parser, zonder iets te
 * vervangen. De uitvoer moet byte-identiek zijn. Dit dekt vormen die de
 * unit-tests niet bedenken (objecten van plugins, widgets, rollen, enz.).
 * Draaien met: wp eval-file corpus.php
 */

use Jelmoo\Planner\Rewriter;
use Jelmoo\Planner\SerializedParser;
use Jelmoo\Planner\SkipRow;
use Jelmoo\Planner\Variants;

global $wpdb;

$variants = new Variants('https://voorbeeld.invalid/bestaat-niet.pdf', 'https://voorbeeld.invalid/nieuw.pdf', home_url('/'));
$identity = function ($bytes) {
    return $bytes;
};

$queries = [
    "SELECT option_name AS k, option_value AS v FROM {$wpdb->options} WHERE option_value LIKE 'a:%' OR option_value LIKE 'O:%' OR option_value LIKE 's:%'",
    "SELECT meta_key AS k, meta_value AS v FROM {$wpdb->postmeta} WHERE meta_value LIKE 'a:%' OR meta_value LIKE 'O:%' OR meta_value LIKE 's:%'",
    "SELECT meta_key AS k, meta_value AS v FROM {$wpdb->usermeta} WHERE meta_value LIKE 'a:%' OR meta_value LIKE 'O:%' OR meta_value LIKE 's:%'",
];

$total = 0;
$identical = 0;
$bytes = 0;
$skipped = [];
$different = [];

foreach ($queries as $sql) {
    foreach ($wpdb->get_results($sql, ARRAY_A) as $row) {
        if (!Rewriter::looks_serialized($row['v'])) {
            continue;
        }

        $total++;
        $bytes += strlen($row['v']);

        try {
            $out = (new SerializedParser($row['v'], $identity, $variants))->rewrite();
            if ($out === $row['v']) {
                $identical++;
            } else {
                $different[] = $row['k'];
            }
        } catch (SkipRow $skip) {
            $skipped[] = $row['k'] . ': ' . $skip->getMessage();
        }
    }
}

WP_CLI::log(sprintf('%d waarden (%s), %d byte-identiek', $total, size_format($bytes), $identical));
WP_CLI::log('Afgewezen: ' . wp_json_encode($skipped));
WP_CLI::log('Anders: ' . wp_json_encode($different));

if ($different) {
    WP_CLI::error('Parser wijzigt data terwijl er niets te vervangen is.');
}

WP_CLI::success('Geen enkele waarde gewijzigd.');
