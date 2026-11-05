<?php
/*
 * Een bestand uit de mediabibliotheek wisselen, gecontroleerd op wat de builder echt
 * rendert en wat de editor te zien krijgt. Draait in de webcontainer:
 * docker compose exec -T -u www-data wordpress php .../media.php
 * Bouwt voort op de melding: Bricks-blok met tag a, link type media.
 */

use Jelmoo\Planner\Runner;
use Jelmoo\Planner\Store;
use Jelmoo\Planner\Time;

$_SERVER['HTTP_HOST'] = 'localhost:8002';
$_SERVER['REQUEST_URI'] = '/';
require '/var/www/html/wp-load.php';
ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_USER_NOTICE);
require_once ABSPATH . 'wp-admin/includes/image.php';

Store::maybe_upgrade();
wp_set_current_user(1);

$failures = 0;

function check(string $label, bool $ok, $extra = ''): void
{
    if (!$ok) {
        $GLOBALS['failures']++;
    }
    echo ($ok ? 'OK    ' : 'FOUT  '), $label, ($ok || $extra === '' ? '' : ' => ' . (is_string($extra) ? $extra : json_encode($extra))), "\n";
}

function make_image(string $name, int $width, int $height, array $rgb): array
{
    $uploads = wp_get_upload_dir();
    $file = $uploads['basedir'] . '/testmenu/' . $name;
    wp_mkdir_p(dirname($file));
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, $rgb[0], $rgb[1], $rgb[2]));
    imagepng($image, $file);
    imagedestroy($image);
    $id = wp_insert_attachment(['post_title' => $name, 'post_mime_type' => 'image/png', 'post_status' => 'inherit'], $file);
    wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $file));

    return ['id' => $id, 'url' => wp_get_attachment_url($id), 'name' => $name, 'medium' => wp_get_attachment_image_url($id, 'medium')];
}

function make_page(string $title, array $meta = [], string $content = ''): int
{
    $id = wp_insert_post(['post_title' => $title, 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => $content]);
    foreach ($meta as $key => $value) {
        update_post_meta($id, $key, $value);
    }

    return $id;
}

function html_of(int $page): string
{
    return wp_remote_retrieve_body(wp_remote_get(add_query_arg('nocache', wp_generate_password(6, false), get_permalink($page)), ['timeout' => 25]));
}

function element(string $html, string $id): string
{
    return preg_match('~<[a-z]+\b[^>]*\bid="' . $id . '"[^>]*>~', $html, $match) ? $match[0] : '';
}

function bricks_page(string $title, array $children): int
{
    $elements = [['id' => 'sect' . wp_generate_password(2, false, false), 'name' => 'section', 'parent' => 0, 'children' => array_column($children, 'id'), 'settings' => []]];
    foreach ($children as $child) {
        $elements[] = $child + ['parent' => $elements[0]['id'], 'children' => []];
    }

    return make_page($title, ['_bricks_editor_mode' => 'bricks', BRICKS_DB_PAGE_CONTENT => $elements]);
}

function bricks_setting(int $page, string $element): array
{
    clean_post_cache($page);
    wp_cache_delete($page, 'post_meta');
    foreach (get_post_meta($page, BRICKS_DB_PAGE_CONTENT, true) as $item) {
        if ($item['id'] === $element) {
            return $item['settings'];
        }
    }

    return [];
}

$GLOBALS['wpdb']->query('UPDATE ' . Store::jobs_table() . " SET status = 'cancelled' WHERE status = 'scheduled'");

$run = strtolower(wp_generate_password(5, false, false));
$old = make_image("lekker2026-{$run}.png", 1000, 1000, [220, 30, 30]);
$new = make_image("lekker2027-{$run}.png", 1200, 800, [30, 30, 220]);
$other = make_image("iets-anders-{$run}.png", 800, 800, [30, 220, 30]);

$image_settings = ['id' => $old['id'], 'filename' => $old['name'], 'size' => 'medium', 'full' => $old['url'], 'url' => $old['medium']];

$bricks = bricks_page('Bricks media', [
    // Precies de melding: blok, tag a, link media, bestand uit de mediabibliotheek.
    ['id' => 'blok01', 'name' => 'block', 'settings' => ['tag' => 'a', 'link' => ['type' => 'media', 'mediaData' => ['id' => $old['id'], 'filename' => $old['name'], 'url' => $old['url']]]]],
    ['id' => 'blok02', 'name' => 'block', 'settings' => ['tag' => 'a', 'link' => ['type' => 'media', 'mediaData' => ['id' => $old['id'], 'filename' => $old['name']]]]],
    ['id' => 'imag01', 'name' => 'image', 'settings' => ['image' => $image_settings]],
    ['id' => 'imag02', 'name' => 'image', 'settings' => ['image' => ['id' => $other['id'], 'filename' => $other['name'], 'size' => 'full', 'full' => $other['url'], 'url' => $other['url']]]],
    ['id' => 'blok03', 'name' => 'block', 'settings' => ['_background' => ['image' => ['id' => $old['id'], 'filename' => $old['name'], 'size' => 'full', 'full' => $old['url'], 'url' => $old['url']]]]],
    ['id' => 'gall01', 'name' => 'image-gallery', 'settings' => ['items' => ['images' => [['id' => $old['id'], 'full' => $old['url'], 'url' => $old['medium']]], 'size' => 'medium']]],
]);

$elementor = make_page('Elementor media', [
    '_elementor_edit_mode' => 'builder',
    '_elementor_template_type' => 'wp-page',
    '_elementor_version' => '3.0.0',
    '_elementor_data' => wp_slash(wp_json_encode([[
        'id' => 'sec1',
        'elType' => 'section',
        'settings' => [],
        'elements' => [[
            'id' => 'col1',
            'elType' => 'column',
            'settings' => ['_column_size' => 100],
            'elements' => [[
                'id' => 'img1',
                'elType' => 'widget',
                'widgetType' => 'image',
                'settings' => ['image' => ['url' => $old['url'], 'id' => $old['id'], 'size' => '', 'alt' => '', 'source' => 'library'], 'image_size' => 'medium'],
                'elements' => [],
            ]],
        ]],
    ]])),
]);

$gutenberg = make_page('Gutenberg media', [], '<!-- wp:image {"id":' . $old['id'] . ',"sizeSlug":"medium"} --><figure class="wp-block-image size-medium"><img src="' . $old['medium'] . '" alt="" class="wp-image-' . $old['id'] . '"/></figure><!-- /wp:image -->' . "\n\n" . '<!-- wp:file {"id":' . $old['id'] . ',"href":"' . $old['url'] . '"} --><div class="wp-block-file"><a href="' . $old['url'] . '">Bestand</a></div><!-- /wp:file -->');

$settings = new stdClass();
$settings->type = 'photo';
$settings->photo = $old['id'];
$settings->photo_src = $old['url'];
$settings->photo_source = 'library';
$module = (object) ['node' => 'mod1', 'type' => 'module', 'parent' => 'col1', 'position' => 0, 'settings' => $settings];
$row = (object) ['node' => 'row1', 'type' => 'row', 'parent' => null, 'position' => 0, 'settings' => new stdClass()];
$group = (object) ['node' => 'grp1', 'type' => 'column-group', 'parent' => 'row1', 'position' => 0, 'settings' => new stdClass()];
$col = (object) ['node' => 'col1', 'type' => 'column', 'parent' => 'grp1', 'position' => 0, 'settings' => (object) ['size' => 100]];
$beaver = make_page('Beaver media', ['_fl_builder_enabled' => 1, '_fl_builder_data' => ['row1' => $row, 'grp1' => $group, 'col1' => $col, 'mod1' => $module]]);

// Een los getal gelijk aan het oude ID, met een ander bestand ernaast.
$decoy = bricks_page('Zelfde getal', [
    ['id' => 'imag03', 'name' => 'image', 'settings' => ['image' => ['id' => $other['id'], 'filename' => $other['name'], 'size' => 'full', 'full' => $other['url'], 'url' => $other['url']], 'relatedTo' => ['id' => $old['id']]]],
]);

// CSS van Bricks als los bestand, zodat ook die route getest is.
// Bricks laadt deze class alleen als de instelling al aanstond toen de pagina begon.
if (!class_exists('\\Bricks\\Assets_Files')) {
    require_once get_template_directory() . '/includes/assets/files.php';
}

$bricks_settings = get_option(BRICKS_DB_GLOBAL_SETTINGS, []);
$saved_settings = $bricks_settings;
$bricks_settings['cssLoading'] = 'file';
update_option(BRICKS_DB_GLOBAL_SETTINGS, $bricks_settings);
\Bricks\Database::$global_settings = $bricks_settings;
\Bricks\Assets_Files::regenerate_css_file((string) $bricks, 1, true);

$css_file = \Bricks\Assets::$css_dir . '/post-' . $bricks . '.min.css';

echo "\n-- Voor de wissel\n";
$html = html_of($bricks);
check('Blok linkt naar het oude bestand', strpos(element($html, 'brxe-blok01'), $old['name']) !== false, element($html, 'brxe-blok01'));
check('CSS-bestand bevat de oude achtergrond', strpos((string) @file_get_contents($css_file), $old['name']) !== false, $css_file);

$job_id = Store::create_job(['type' => 'link', 'run_at' => gmdate('Y-m-d H:i:s', time() - 60), 'old_url' => $old['url'], 'new_url' => $new['url'], 'mail_to' => '']);
Runner::tick();
$job = Store::get_job($job_id);

echo "\n-- Resultaat van de taak: {$job['status']}\n";
check('Taak is geslaagd', $job['status'] === 'done', $job['report']['messages'] ?? []);
check('Geen enkele plek overgeslagen', empty($job['report']['counts']['skipped']) && empty($job['report']['counts']['suspect']) && empty($job['report']['counts']['failed']), $job['report']['counts'] ?? []);

echo "\n-- Bricks: wat de bezoeker krijgt\n";
$html = html_of($bricks);
check('Blok met link media gaat naar het nieuwe bestand', strpos(element($html, 'brxe-blok01'), 'href="' . $new['url'] . '"') !== false, element($html, 'brxe-blok01'));
check('Medialink met alleen ID en bestandsnaam ook', strpos(element($html, 'brxe-blok02'), 'href="' . $new['url'] . '"') !== false, element($html, 'brxe-blok02'));
check('Afbeelding toont het nieuwe bestand, ook srcset', strpos(element($html, 'brxe-imag01'), 'lekker2027-' . $run) !== false && strpos(element($html, 'brxe-imag01'), 'lekker2026-' . $run) === false, element($html, 'brxe-imag01'));
check('Nergens op de pagina nog het oude bestand', strpos($html, 'lekker2026-' . $run) === false, 'staat er nog in');
check('Andere afbeelding is ongemoeid', strpos(element($html, 'brxe-imag02'), $other['name']) !== false, element($html, 'brxe-imag02'));

echo "\n-- Bricks: wat de editor te zien krijgt\n";
$link = bricks_setting($bricks, 'blok01')['link']['mediaData'];
check('Medialink: nieuw ID', (int) $link['id'] === $new['id'], $link);
check('Medialink: nieuwe bestandsnaam', $link['filename'] === $new['name'], $link);
check('Medialink: nieuwe URL', $link['url'] === $new['url'], $link);
$short = bricks_setting($bricks, 'blok02')['link']['mediaData'];
check('Medialink zonder URL: nieuw ID en bestandsnaam', (int) $short['id'] === $new['id'] && $short['filename'] === $new['name'], $short);
$image = bricks_setting($bricks, 'imag01')['image'];
check('Afbeelding: nieuw ID, bestandsnaam en kleine versie', (int) $image['id'] === $new['id'] && $image['filename'] === $new['name'] && $image['url'] === $new['medium'], $image);
$gallery = bricks_setting($bricks, 'gall01')['items']['images'][0];
check('Galerij: nieuw ID en kleine versie', (int) $gallery['id'] === $new['id'] && $gallery['url'] === $new['medium'], $gallery);
check('CSS-bestand van Bricks is opnieuw gemaakt', strpos((string) file_get_contents($css_file), $new['name']) !== false && strpos((string) file_get_contents($css_file), $old['name']) === false, (string) file_get_contents($css_file));

echo "\n-- Hetzelfde getal naast een ander bestand\n";
check('Blijft staan', (int) bricks_setting($decoy, 'imag03')['relatedTo']['id'] === $old['id'] && (int) bricks_setting($decoy, 'imag03')['image']['id'] === $other['id'], bricks_setting($decoy, 'imag03'));

echo "\n-- Elementor\n";
$data = json_decode(get_post_meta($elementor, '_elementor_data', true), true);
check('Opgeslagen ID is nieuw', (int) $data[0]['elements'][0]['elements'][0]['settings']['image']['id'] === $new['id'], $data[0]['elements'][0]['elements'][0]['settings']['image']);
$html = html_of($elementor);
check('Pagina toont het nieuwe bestand', strpos($html, 'lekker2027-' . $run) !== false && strpos($html, 'lekker2026-' . $run) === false, 'zie pagina ' . $elementor);

echo "\n-- Gutenberg\n";
clean_post_cache($gutenberg);
$content = get_post_field('post_content', $gutenberg);
check('Blok-ID en wp-image zijn nieuw', strpos($content, '"id":' . $new['id'] . ',"sizeSlug"') !== false && strpos($content, 'wp-image-' . $new['id']) !== false && strpos($content, '"id":' . $new['id'] . ',"href"') !== false, $content);
$html = html_of($gutenberg);
check('Pagina toont het nieuwe bestand', strpos($html, 'lekker2027-' . $run) !== false && strpos($html, 'lekker2026-' . $run) === false, 'zie pagina ' . $gutenberg);

echo "\n-- Beaver Builder\n";
wp_cache_delete($beaver, 'post_meta');
$data = get_post_meta($beaver, '_fl_builder_data', true);
check('Foto-ID en URL zijn nieuw', (int) $data['mod1']->settings->photo === $new['id'] && $data['mod1']->settings->photo_src === $new['url'], $data['mod1']->settings);

echo "\n-- Ongedaan maken\n";
echo '      ', Runner::undo($job_id), "\n";
$link = bricks_setting($bricks, 'blok01')['link']['mediaData'];
check('Bricks staat weer op het oude bestand', (int) $link['id'] === $old['id'] && $link['filename'] === $old['name'] && $link['url'] === $old['url'], $link);
check('CSS-bestand staat weer op het oude bestand', strpos((string) file_get_contents($css_file), $old['name']) !== false, (string) file_get_contents($css_file));
check('Pagina linkt weer naar het oude bestand', strpos(element(html_of($bricks), 'brxe-blok01'), $old['name']) !== false);
clean_post_cache($gutenberg);
check('Gutenberg staat weer op het oude bestand', strpos(get_post_field('post_content', $gutenberg), 'wp-image-' . $old['id']) !== false);

echo "\n-- Nieuwe URL buiten de mediabibliotheek\n";
$job_id = Store::create_job(['type' => 'link', 'run_at' => gmdate('Y-m-d H:i:s', time() - 60), 'old_url' => $old['url'], 'new_url' => site_url('/wp-includes/images/w-logo-blue.png'), 'mail_to' => '']);
Runner::tick();
$job = Store::get_job($job_id);
check('Geen "Geslaagd", wel een duidelijke melding', $job['status'] === 'warning' && strpos(implode(' ', $job['report']['messages']), 'mediabibliotheek') !== false, $job['report']['messages'] ?? []);
Runner::undo($job_id);

echo "\n-- Tijdzone en het exacte moment\n";
$saved_string = get_option('timezone_string');
$saved_offset = get_option('gmt_offset');
update_option('timezone_string', '');
update_option('gmt_offset', 0);
check('Site op UTC+0: 18:00 blijft 17:00 UTC in de winter', gmdate('H:i', Time::parse('2026-11-05T18:00')) === '17:00');
update_option('timezone_string', 'America/New_York');
check('Site in New York: 18:00 blijft 17:00 UTC in de winter', gmdate('H:i', Time::parse('2026-11-05T18:00')) === '17:00');
update_option('timezone_string', $saved_string);
update_option('gmt_offset', $saved_offset);

$moment = time() + 3600;
$job_id = Store::create_job(['type' => 'link', 'run_at' => gmdate('Y-m-d H:i:s', $moment), 'old_url' => $old['url'], 'new_url' => $new['url'], 'mail_to' => '']);
Runner::schedule_job($job_id, $moment);
check('Eigen cron-event op het exacte moment', wp_next_scheduled(Runner::CONTINUE_HOOK, [$job_id]) === $moment, wp_next_scheduled(Runner::CONTINUE_HOOK, [$job_id]));
Store::update_job($job_id, ['run_at' => gmdate('Y-m-d H:i:s', time() - 1)]);
do_action(Runner::CONTINUE_HOOK, $job_id);
check('Dat event voert de taak uit', Store::get_job($job_id)['status'] === 'done', Store::get_job($job_id)['status']);
Runner::undo($job_id);
wp_unschedule_event($moment, Runner::CONTINUE_HOOK, [$job_id]);

foreach ([$bricks, $elementor, $gutenberg, $beaver, $decoy] as $page) {
    wp_delete_post($page, true);
}
@unlink($css_file);
update_option(BRICKS_DB_GLOBAL_SETTINGS, $saved_settings);

echo "\n", $failures ? "MISLUKT: {$failures}\n" : "Alles geslaagd.\n";
exit($failures ? 1 : 0);
