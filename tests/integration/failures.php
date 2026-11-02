<?php
/*
 * Faalscenario's en bestand vervangen. Draait in de webcontainer, zodat de site
 * zichzelf kan bereiken:
 * docker compose exec -T -u www-data wordpress php .../failures.php
 * Verwacht de inhoud uit seed.php, met de oude URL actief (run.sh eindigt zo).
 */

use Jelmoo\Planner\Rewriter;
use Jelmoo\Planner\FileSwap;
use Jelmoo\Planner\Runner;
use Jelmoo\Planner\Scanner;
use Jelmoo\Planner\Store;

$_SERVER['HTTP_HOST'] = 'localhost:8002';
$_SERVER['REQUEST_URI'] = '/';
require '/var/www/html/wp-load.php';
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_USER_NOTICE);
ini_set('display_errors', '0');

$ids = json_decode(file_get_contents(WP_CONTENT_DIR . '/uploads/planner-fixtures.json'), true);
$old = $ids['old'];
$new = $ids['new'];
$failures = 0;

function check(string $label, bool $ok, $extra = ''): void
{
    if (!$ok) {
        $GLOBALS['failures']++;
    }
    echo ($ok ? 'OK    ' : 'FOUT  '), $label, ($ok || $extra === '' ? '' : ' => ' . (is_string($extra) ? $extra : json_encode($extra))), "\n";
}

function make_job(string $old, string $new, string $type = 'link'): int
{
    return Store::create_job([
        'type' => $type,
        'run_at' => gmdate('Y-m-d H:i:s', time() - 60),
        'old_url' => $old,
        'new_url' => $new,
        'mail_to' => 'test@example.com',
    ]);
}

function content(int $id): string
{
    clean_post_cache($id);
    return (string) get_post_field('post_content', $id);
}

function mail_log(): string
{
    return (string) @file_get_contents(WP_CONTENT_DIR . '/uploads/jp-test-mail.log');
}

$gutenberg = $ids['gutenberg'];

echo "\n-- Nieuwe URL bestaat niet\n";
$before = content($gutenberg);
$id = make_job($old, content_url('uploads/testmenu/bestaat-niet.pdf'));
Runner::tick();
$job = Store::get_job($id);
check('404 op nieuwe URL: taak mislukt', $job['status'] === 'failed', $job);
check('404 op nieuwe URL: niets gewijzigd', content($gutenberg) === $before);
check('404 op nieuwe URL: mail verstuurd', strpos(mail_log(), 'mislukt') !== false);

echo "\n-- Zachte 404: PDF-URL geeft HTML\n";
$id = make_job($old, home_url('/?page_id=' . $gutenberg . '&f=menu.pdf'));
Runner::tick();
$job = Store::get_job($id);
check('HTML in plaats van PDF: taak mislukt', $job['status'] === 'failed', $job);

echo "\n-- Dubbele tick\n";
$id = make_job($old, $new);
check('Eerste claim lukt', Store::claim_due($id));
check('Tweede claim lukt niet', !Store::claim_due($id));
Store::update_job($id, ['claimed_at' => Store::now()]);
Runner::tick();
check('Lopende taak met geldige claim blijft met rust', Store::get_job($id)['status'] === 'preparing', Store::get_job($id));
Store::update_job($id, ['status' => 'cancelled', 'claimed_at' => null]);

echo "\n-- Vastgelopen voorbereiding hervat\n";
$id = make_job($old, $new);
$job = Store::get_job($id);
$variants = Runner::variants_for($job);
$cursor = Scanner::chunk($variants, [], microtime(true) - 1, function () {
});
check('Scan stopt bij verstreken tijd', empty($cursor['done']));
Store::update_job($id, ['status' => 'preparing', 'claimed_at' => gmdate('Y-m-d H:i:s', time() - 600), 'state' => ['preflight' => true, 'cursor' => $cursor]]);
Runner::tick();
$job = Store::get_job($id);
check('Hervat na verlopen claim', in_array($job['status'], ['done', 'warning'], true), $job);
check('Pagina is gewisseld', strpos(content($gutenberg), $new) !== false);

echo "\n-- Ongedaan maken na een latere bewerking\n";
wp_update_post(['ID' => $gutenberg, 'post_content' => content($gutenberg) . '<p>Later toegevoegd</p>']);
$message = Runner::undo($id);
check('Rapporteert de conflict', strpos($message, '1 overgeslagen') !== false, $message);
check('Latere bewerking blijft staan', strpos(content($gutenberg), 'Later toegevoegd') !== false);
check('Bewerkte plek houdt de nieuwe URL', strpos(content($gutenberg), $new) !== false);
check('Andere plekken zijn teruggezet', get_post_meta($ids['menu_item'], '_menu_item_url', true) === $old);

// Terug naar de oude staat voor de volgende scenario's
wp_update_post(['ID' => $gutenberg, 'post_content' => str_replace([$new, '<p>Later toegevoegd</p>'], [$old, ''], content($gutenberg))]);

echo "\n-- Bewerking tussen voorbereiden en toepassen\n";
$id = make_job($old, $new);
$job = Store::get_job($id);
$variants = Runner::variants_for($job);
$rewriter = new Rewriter($variants);
$prepare = new ReflectionMethod(Runner::class, 'prepare_row');
$prepare->setAccessible(true);
Scanner::chunk($variants, [], microtime(true) + 20, function ($location, $raw) use ($prepare, $id, $rewriter) {
    $prepare->invoke(null, $id, $location, $raw, $rewriter);
});
wp_update_post(['ID' => $gutenberg, 'post_content' => content($gutenberg) . '<p>Tussendoor</p>']);
Store::update_job($id, ['status' => 'applying', 'claimed_at' => null, 'state' => ['preflight' => true]]);
Runner::tick();
$job = Store::get_job($id);
check('Bewerking blijft behouden', strpos(content($gutenberg), 'Tussendoor') !== false);
check('En de link is toch gewisseld', strpos(content($gutenberg), $new) !== false && strpos(content($gutenberg), $old) === false, content($gutenberg));

echo "\n-- Vastgelopen toepassen hervat\n";
check('Alle pending rijen zijn verwerkt', empty(Store::count_by_state($id)['pending']), Store::count_by_state($id));

echo "\n-- Nacontrole: oude link komt terug\n";
$mail_before = strlen(mail_log());
wp_update_post(['ID' => $gutenberg, 'post_content' => content($gutenberg) . '<a href="' . $old . '">terug</a>']);
Store::update_job($id, ['next_check_at' => gmdate('Y-m-d H:i:s', time() - 10), 'claimed_at' => null]);
Runner::tick();
$job = Store::get_job($id);
check('Nacontrole vindt de plek', !empty($job['report']['checks'][0]['unexpected']), $job['report']['checks'] ?? []);
check('Mail over de teruggekomen link', strpos(substr(mail_log(), $mail_before), 'oude link is terug') !== false);
check('Tweede nacontrole staat gepland', !empty($job['next_check_at']) && (int) $job['checks_done'] === 1);

echo "\n-- 0 treffers\n";
$id = make_job(content_url('uploads/testmenu/bestaat-nergens-in-de-site.pdf'), $new);
Runner::tick();
$job = Store::get_job($id);
check('0 treffers: waarschuwing, geen stille "geslaagd"', $job['status'] === 'warning', $job);

echo "\n-- Te vaak vastgelopen\n";
$id = make_job($old, $new);
Store::update_job($id, ['status' => 'preparing', 'claimed_at' => null, 'state' => ['attempts' => 99]]);
Runner::tick();
check('Stopt na te veel pogingen', Store::get_job($id)['status'] === 'failed', Store::get_job($id));

echo "\n-- Bestand vervangen\n";
$uploads = wp_get_upload_dir();
$dir = $uploads['basedir'] . '/testmenu';
file_put_contents($dir . '/Allergenen.pdf', "%PDF-1.4\n% OUD\n%%EOF\n");
file_put_contents($dir . '/Allergenen-nieuw.pdf', "%PDF-1.4\n% NIEUW\n%%EOF\n");
$qr = $uploads['baseurl'] . '/testmenu/Allergenen.pdf';
$source = $uploads['baseurl'] . '/testmenu/Allergenen-nieuw.pdf';

$id = make_job($qr, $source, 'file');
Runner::tick();
$job = Store::get_job($id);
check('Taak geslaagd', $job['status'] === 'done', $job);
check('URL geeft de nieuwe inhoud', strpos(wp_remote_retrieve_body(wp_remote_get($qr)), 'NIEUW') !== false);
check('Backup bevat de oude inhoud', strpos((string) @file_get_contents($job['state']['backup']), 'OUD') !== false);
check('Geen tijdelijke bestanden over', !glob($dir . '/*.tmp'));
$message = Runner::undo($id);
clearstatcache();
check('Ongedaan maken zet het terug', strpos(file_get_contents($dir . '/Allergenen.pdf'), 'OUD') !== false, $message);

$id = make_job($qr, $source, 'file');
Runner::tick();
file_put_contents($dir . '/Allergenen.pdf', "%PDF-1.4\n% HANDMATIG\n%%EOF\n");
try {
    Runner::undo($id);
    check('Handmatig gewijzigd bestand wordt niet overschreven', false);
} catch (RuntimeException $problem) {
    check('Handmatig gewijzigd bestand wordt niet overschreven', strpos(file_get_contents($dir . '/Allergenen.pdf'), 'HANDMATIG') !== false);
}

unlink($dir . '/Allergenen-nieuw.pdf');
$id = make_job($qr, $source, 'file');
Runner::tick();
check('Bronbestand weg: taak mislukt', Store::get_job($id)['status'] === 'failed', Store::get_job($id));
check('Bronbestand weg: doel onaangeraakt', strpos(file_get_contents($dir . '/Allergenen.pdf'), 'HANDMATIG') !== false);

echo "\n-- Bestand vervangen: geweigerde paden\n";
file_put_contents($dir . '/plaatje.jpg', 'x');
file_put_contents($dir . '/Allergenen-nieuw.pdf', "%PDF-1.4\n% NIEUW\n%%EOF\n");
$refused = [
    'Buiten uploads via ..' => [$uploads['baseurl'] . '/../../wp-config.php', $source],
    'Bron buiten uploads via ..' => [$qr, $uploads['baseurl'] . '/../../wp-config.php'],
    'Andere site' => ['https://andere-site.nl/wp-content/uploads/a.pdf', $source],
    'Ander bestandstype' => [$qr, $uploads['baseurl'] . '/testmenu/plaatje.jpg'],
    'Zelfde bestand' => [$qr, $qr],
    'Bestaat niet' => [$uploads['baseurl'] . '/testmenu/nergens.pdf', $source],
];
foreach ($refused as $label => $pair) {
    try {
        FileSwap::validate($pair[0], $pair[1]);
        check($label, false, 'niet geweigerd');
    } catch (RuntimeException $problem) {
        check($label, true);
    }
}

echo "\n", $failures ? "MISLUKT: {$failures}\n" : "Alle faalscenario's geslaagd.\n";
exit($failures ? 1 : 0);
