<?php
/*
 * Controleert de staat van de database na een wissel.
 * Draaien met: wp eval-file assert.php -- <swapped|undone>
 */

use Jelmoo\Planner\Store;

$mode = $args[0] ?? 'swapped';
$ids = json_decode(file_get_contents(WP_CONTENT_DIR . '/uploads/planner-fixtures.json'), true);
$old = $ids['old'];
$new = $ids['new'];
$from = $mode === 'swapped' ? $new : $old;
$other = $mode === 'swapped' ? $old : $new;

$GLOBALS['jp_failures'] = 0;

function jp_check(string $label, bool $ok, string $extra = ''): void
{
    if (!$ok) {
        $GLOBALS['jp_failures']++;
    }
    WP_CLI::log(($ok ? 'OK    ' : 'FOUT  ') . $label . ($ok || $extra === '' ? '' : ' => ' . $extra));
}

function jp_has(string $haystack, string $url): bool
{
    $forms = [$url, str_replace('/', '\\/', $url)];
    foreach ($forms as $form) {
        if (strpos($haystack, $form) !== false) {
            return true;
        }
    }

    return false;
}

function jp_raw_meta(int $id, string $key): string
{
    global $wpdb;
    return (string) $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $id, $key));
}

$gutenberg = (string) get_post_field('post_content', $ids['gutenberg']);
jp_check('Gutenberg bevat juiste URL', jp_has($gutenberg, $from) && !jp_has($gutenberg, $other), $gutenberg);

if ($ids['gutenberg_revision']) {
    jp_check('Revisie blijft de oude URL houden', jp_has((string) get_post_field('post_content', $ids['gutenberg_revision']), $old));
}

if (!empty($ids['elementor'])) {
    $raw = jp_raw_meta($ids['elementor'], '_elementor_data');
    jp_check('Elementor: JSON-escaped URL', jp_has($raw, $from) && !jp_has($raw, $other), $raw);
    jp_check('Elementor: JSON blijft geldig', is_array(json_decode($raw, true)));
    jp_check('Elementor: backslashes intact', strpos($raw, '\\/') !== false);

    $raw = jp_raw_meta($ids['elementor_template'], '_elementor_data');
    jp_check('Elementor: template (popup) ook geraakt', jp_has($raw, $from) && !jp_has($raw, $other), $raw);
}

if (!empty($ids['beaver'])) {
    $raw = jp_raw_meta($ids['beaver'], '_fl_builder_data');
    jp_check('Beaver: juiste URL', jp_has($raw, $from) && !jp_has($raw, $other), substr($raw, 0, 400));
    $data = get_post_meta($ids['beaver'], '_fl_builder_data', true);
    jp_check('Beaver: stdClass-nodes leesbaar', is_array($data) && is_object($data['mod1']) && jp_has($data['mod1']->settings->text, $from));
}

if (!empty($ids['bricks'])) {
    $elements = get_post_meta($ids['bricks'], BRICKS_DB_PAGE_CONTENT, true);
    jp_check('Bricks: knop heeft juiste URL', is_array($elements) && $elements[1]['settings']['link']['url'] === $from, wp_json_encode($elements));

    $signed = get_post_meta($ids['bricks_signed'], BRICKS_DB_PAGE_CONTENT, true);
    jp_check('Bricks: ondertekende code onaangeraakt', jp_has($signed[0]['settings']['code'], $old));
    jp_check('Bricks: signature klopt nog', $signed[0]['settings']['signature'] === wp_hash($signed[0]['settings']['code']));

    $header = get_post_meta($ids['bricks_header'], BRICKS_DB_PAGE_HEADER, true);
    jp_check('Bricks: header-template geraakt', $header[0]['settings']['link']['url'] === $from, wp_json_encode($header));
}

jp_check('Navigatiemenu', get_post_meta($ids['menu_item'], '_menu_item_url', true) === $from);

$options = get_option('jp_test_theme_options');
jp_check('Optie: serialized', is_array($options) && $options['menu_url'] === $from, wp_json_encode($options));
jp_check('Optie: geneste serialized', is_array($options) && unserialize($options['nested'])['url'] === $from);
jp_check('Optie: JSON in serialized', is_array($options) && json_decode($options['json'], true)['url'] === $from);

jp_check('Termmeta', get_term_meta($ids['term'], 'jp_test_url', true) === $from);

$decoy = (string) get_post_field('post_content', $ids['decoy']);
jp_check('Lokaas: langere naam onaangeraakt', strpos($decoy, $old . '-oud') !== false, $decoy);
jp_check('Lokaas: hoofdletters onaangeraakt', strpos($decoy, 'zomer2026-web_compressed.pdf') !== false, $decoy);

$suspect = (string) get_post_field('post_content', $ids['suspect']);
jp_check('Verdachte staging-link onaangeraakt', strpos($suspect, 'staging.example.org') !== false, $suspect);

global $wpdb;
$corrupt = (string) $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_id = %d", $ids['corrupt_meta']));
jp_check('Kapotte serialized onaangeraakt', strpos($corrupt, 's:999:') !== false && jp_has($corrupt, $old), $corrupt);

$job = Store::list_jobs(1)[0] ?? null;
if ($job) {
    WP_CLI::log('Taak #' . $job['id'] . ' status=' . $job['status']);
    WP_CLI::log(wp_json_encode(['counts' => $job['report']['counts'] ?? [], 'unexpected' => $job['report']['unexpected'] ?? [], 'pages' => $job['report']['pages'] ?? [], 'messages' => $job['report']['messages'] ?? []], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    foreach (Store::row_summaries($job['id']) as $row) {
        WP_CLI::log(sprintf('  %-9s %-8s pk=%-5s %-14s %-24s %s', $row['state'], $row['target'], $row['pk'], $row['col'], $row['field'], $row['reason']));
    }
}

if ($GLOBALS['jp_failures']) {
    WP_CLI::error($GLOBALS['jp_failures'] . ' controle(s) mislukt.');
}

WP_CLI::success('Alle controles geslaagd.');
