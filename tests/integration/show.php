<?php
/*
 * Element tonen op een echte Bricks-pagina, opgebouwd zoals de emblemen van Muggels.
 * Draait in de webcontainer, zodat de site zichzelf kan bereiken:
 * docker compose exec -T -u www-data wordpress php .../show.php
 * Laat de testpagina staan, met het element verborgen, om in de browser verder te testen.
 */

use Jelmoo\Planner\Runner;
use Jelmoo\Planner\ShowElement;
use Jelmoo\Planner\Store;

$_SERVER['HTTP_HOST'] = 'localhost:8002';
$_SERVER['REQUEST_URI'] = '/';
require '/var/www/html/wp-load.php';
ini_set('display_errors', '0');

Store::maybe_upgrade();

// Bricks filtert zijn eigen meta als een gebruiker zonder rechten opslaat.
wp_set_current_user(1);

// Schone start: de demo-taak van een vorige run mag niet meetellen.
update_option('jelmoo_planner_active_css', []);
$GLOBALS['wpdb']->query('UPDATE ' . Store::jobs_table() . " SET status = 'cancelled' WHERE status = 'scheduled'");

$failures = 0;

function check(string $label, bool $ok, $extra = ''): void
{
    if (!$ok) {
        $GLOBALS['failures']++;
    }
    echo ($ok ? 'OK    ' : 'FOUT  '), $label, ($ok || $extra === '' ? '' : ' => ' . (is_string($extra) ? $extra : json_encode($extra))), "\n";
}

function page_html(string $url): string
{
    return wp_remote_retrieve_body(wp_remote_get($url, ['timeout' => 20]));
}

if (!defined('BRICKS_DB_PAGE_CONTENT')) {
    exit("Activeer eerst het Bricks-thema.\n");
}

// Een echte afbeelding uit de mediabibliotheek, in het formaat dat de Bricks-editor opslaat.
require_once ABSPATH . 'wp-admin/includes/image.php';
$uploads = wp_get_upload_dir();
$file = $uploads['basedir'] . '/testmenu/embleem.png';
wp_mkdir_p(dirname($file));
copy(ABSPATH . WPINC . '/images/w-logo-blue.png', $file);
$attachment = wp_insert_attachment(['post_title' => 'Embleem', 'post_mime_type' => 'image/png', 'post_status' => 'inherit'], $file);
wp_update_attachment_metadata($attachment, wp_generate_attachment_metadata($attachment, $file));
$logo = wp_get_attachment_url($attachment);

$image = function (string $id, array $extra = []) use ($logo, $attachment) {
    return [
        'id' => $id,
        'name' => 'image',
        'parent' => 'cont01',
        'children' => [],
        'settings' => array_merge(['image' => ['id' => $attachment, 'filename' => 'embleem.png', 'size' => 'full', 'full' => $logo, 'url' => $logo], '_width' => '80px'], $extra),
    ];
};

$page = wp_insert_post(['post_title' => 'Emblemen', 'post_type' => 'page', 'post_status' => 'publish']);
update_post_meta($page, '_bricks_editor_mode', 'bricks');
update_post_meta($page, BRICKS_DB_PAGE_CONTENT, [
    ['id' => 'sect01', 'name' => 'section', 'parent' => 0, 'children' => ['cont01'], 'settings' => []],
    ['id' => 'cont01', 'name' => 'container', 'parent' => 'sect01', 'children' => ['embl01', 'embl02', 'embl03'], 'settings' => ['_direction' => 'row', '_justifyContent' => 'center', '_columnGap' => '40px']],
    $image('embl01'),
    $image('embl02'),
    $image('embl03', ['_display' => 'none']),
]);

$url = get_permalink($page);
$selector = '#brxe-embl03';
$html = page_html($url);

echo "\n-- Voor de wissel\n";
$seen = ShowElement::inspect($html, $selector);
check('Element staat in de HTML', $seen['found'], substr($html, 0, 300));
check('Bricks zet display:none in de CSS', $seen['hidden']);
check('Regel van de plugin nog niet actief', !ShowElement::rule_is_active($html, $selector, 'flex'));

echo "\n-- Taak nog niet aan de beurt\n";
$future = Store::create_job(['type' => 'show', 'run_at' => gmdate('Y-m-d H:i:s', time() + 3600), 'old_url' => '', 'new_url' => '', 'selector' => $selector, 'display_value' => 'flex', 'page_url' => $url, 'mail_to' => '']);
Runner::tick();
check('Toekomstige taak blijft ingepland', Store::get_job($future)['status'] === 'scheduled');
check('En de regel blijft weg', !ShowElement::rule_is_active(page_html($url), $selector, 'flex'));
Store::update_job($future, ['status' => 'cancelled']);

echo "\n-- Wissel\n";
$id = Store::create_job(['type' => 'show', 'run_at' => gmdate('Y-m-d H:i:s', time() - 60), 'old_url' => '', 'new_url' => '', 'selector' => $selector, 'display_value' => 'flex', 'page_url' => $url, 'mail_to' => 'test@example.com']);
$meta_before = get_post_meta($page, BRICKS_DB_PAGE_CONTENT, true);
Runner::tick();
$job = Store::get_job($id);
check('Taak geslaagd', $job['status'] === 'done', $job);
check('Paginacontrole: zichtbaar', ($job['report']['pages'][0]['result'] ?? '') === 'shown', $job['report']);
check('Regel staat in de HTML', ShowElement::rule_is_active(page_html($url), $selector, 'flex'));
check('Bricks-data onaangeraakt', get_post_meta($page, BRICKS_DB_PAGE_CONTENT, true) === $meta_before);
check('Ongedaan maken is mogelijk', Runner::can_undo($job));

echo "\n-- Nacontrole\n";
Store::update_job($id, ['next_check_at' => gmdate('Y-m-d H:i:s', time() - 10), 'claimed_at' => null]);
Runner::tick();
$job = Store::get_job($id);
check('Nacontrole bevestigt', ($job['report']['checks'][0]['pages'][0]['result'] ?? '') === 'shown', $job['report']);
check('Tweede nacontrole gepland', !empty($job['next_check_at']));

echo "\n-- Ongedaan maken\n";
echo Runner::undo($id), "\n";
check('Regel is weg', !ShowElement::rule_is_active(page_html($url), $selector, 'flex'));
check('Status klopt', Store::get_job($id)['status'] === 'undone');

echo "\n-- Tegelijk verbergen, zoals een oud embleem door een nieuw vervangen\n";
$swap = Store::create_job(['type' => 'show', 'run_at' => gmdate('Y-m-d H:i:s', time() - 60), 'old_url' => '', 'new_url' => '', 'selector' => $selector, 'display_value' => 'flex', 'hide_selector' => '#brxe-embl02', 'page_url' => $url, 'mail_to' => '']);
Runner::tick();
$job = Store::get_job($swap);
$html = page_html($url);
check('Taak geslaagd', $job['status'] === 'done', $job);
check('Nieuw element getoond', ShowElement::rule_is_active($html, $selector, 'flex'));
check('Oud element verborgen', ShowElement::rule_is_active($html, '#brxe-embl02', 'none'));
echo Runner::undo($swap), "\n";
$html = page_html($url);
check('Ongedaan: beide regels weg', !ShowElement::rule_is_active($html, $selector, 'flex') && !ShowElement::rule_is_active($html, '#brxe-embl02', 'none'));

$typo = Store::create_job(['type' => 'show', 'run_at' => gmdate('Y-m-d H:i:s', time() - 60), 'old_url' => '', 'new_url' => '', 'selector' => $selector, 'display_value' => 'flex', 'hide_selector' => '#brxe-bestaatniet', 'page_url' => $url, 'mail_to' => '']);
Runner::tick();
$job = Store::get_job($typo);
check('Waarschuwing bij onbekend te verbergen element', $job['status'] === 'warning' && $job['report']['pages'][0]['result'] === 'hide_missing', $job);
Runner::undo($typo);

echo "\n-- Fout element\n";
$wrong = Store::create_job(['type' => 'show', 'run_at' => gmdate('Y-m-d H:i:s', time() - 60), 'old_url' => '', 'new_url' => '', 'selector' => '#brxe-bestaatniet', 'display_value' => 'flex', 'page_url' => $url, 'mail_to' => '']);
Runner::tick();
$job = Store::get_job($wrong);
check('Waarschuwing bij een onbekend element', $job['status'] === 'warning' && $job['report']['pages'][0]['result'] === 'missing', $job);
Runner::undo($wrong);

echo "\n-- Ongeldige regel in de database wordt niet uitgestuurd\n";
update_option('jelmoo_planner_active_css', ['99' => '#a{color:red}</style><script>alert(1)</script>']);
ob_start();
ShowElement::print_css();
check('Niets uitgestuurd', ob_get_clean() === '');
update_option('jelmoo_planner_active_css', []);

echo "\nTestpagina: {$url}\n";
echo "\n", $failures ? "MISLUKT: {$failures}\n" : "Element tonen geslaagd.\n";
exit($failures ? 1 : 0);
