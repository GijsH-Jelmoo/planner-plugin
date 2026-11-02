<?php
/*
 * Maakt testinhoud aan via de eigen opslagfuncties van elke builder.
 * Draaien met: wp eval-file seed.php
 * Schrijft planner-fixtures.json met de ID's voor assert.php.
 */

const JP_OLD_NAME = 'Zomer2026-Web_compressed.pdf';
const JP_NEW_NAME = 'Herfst2026.pdf';

function jp_upload_pdf(string $name, string $marker): string
{
    $uploads = wp_get_upload_dir();
    $dir = $uploads['basedir'] . '/testmenu';
    wp_mkdir_p($dir);
    file_put_contents($dir . '/' . $name, "%PDF-1.4\n% " . $marker . "\n%%EOF\n");

    return $uploads['baseurl'] . '/testmenu/' . $name;
}

function jp_page(string $title, string $content = '', string $type = 'page'): int
{
    return wp_insert_post(['post_title' => $title, 'post_type' => $type, 'post_status' => 'publish', 'post_content' => $content]);
}

// Elementor slaat alleen op voor een gebruiker die mag bewerken
wp_set_current_user(1);

$old = jp_upload_pdf(JP_OLD_NAME, 'ZOMER');
$new = jp_upload_pdf(JP_NEW_NAME, 'HERFST');
$ids = ['old' => $old, 'new' => $new];

// Gutenberg: href in HTML en in block-attributen
$ids['gutenberg'] = jp_page('Gutenberg', '<!-- wp:file {"href":"' . $old . '"} --><div class="wp-block-file"><a href="' . $old . '">Menu</a></div><!-- /wp:file -->');

// Eerdere versie van de pagina: de revisie moet ongemoeid blijven
wp_update_post(['ID' => $ids['gutenberg'], 'post_title' => 'Gutenberg bewerkt']);
$revisions = wp_get_post_revisions($ids['gutenberg']);
$ids['gutenberg_revision'] = $revisions ? array_key_first($revisions) : 0;

// Elementor, via Document::save zodat het echte opslagformaat ontstaat
if (class_exists('\Elementor\Plugin')) {
    $elements = [[
        'id' => 'aaaa111',
        'elType' => 'container',
        'settings' => [],
        'elements' => [[
            'id' => 'bbbb222',
            'elType' => 'widget',
            'widgetType' => 'button',
            'settings' => ['text' => 'Bekijk menu', 'link' => ['url' => $old, 'is_external' => '', 'nofollow' => '']],
            'elements' => [],
        ]],
    ]];

    $ids['elementor'] = jp_page('Elementor');
    update_post_meta($ids['elementor'], '_elementor_edit_mode', 'builder');
    \Elementor\Plugin::instance()->documents->get($ids['elementor'])->save(['elements' => $elements]);

    // Template dat de oude hardcoded snippet miste
    $ids['elementor_template'] = jp_page('Popup-template', '', 'elementor_library');
    update_post_meta($ids['elementor_template'], '_elementor_edit_mode', 'builder');
    update_post_meta($ids['elementor_template'], '_elementor_template_type', 'section');
    update_post_meta($ids['elementor_template'], '_elementor_data', wp_slash(wp_json_encode($elements)));
}

// Beaver Builder: serialized stdClass-nodes
if (class_exists('FLBuilderModel')) {
    $ids['beaver'] = jp_page('Beaver');

    $row = (object) ['node' => 'row1', 'type' => 'row', 'parent' => null, 'position' => 0, 'settings' => FLBuilderModel::get_row_defaults()];
    $group = (object) ['node' => 'grp1', 'type' => 'column-group', 'parent' => 'row1', 'position' => 0, 'settings' => (object) []];
    $col = (object) ['node' => 'col1', 'type' => 'column', 'parent' => 'grp1', 'position' => 0, 'settings' => FLBuilderModel::get_col_defaults()];
    $module = (object) [
        'node' => 'mod1',
        'type' => 'module',
        'parent' => 'col1',
        'position' => 0,
        'settings' => (object) array_merge((array) FLBuilderModel::get_module_defaults('rich-text'), ['type' => 'rich-text', 'text' => '<a href="' . $old . '">Menu</a>']),
    ];

    update_post_meta($ids['beaver'], '_fl_builder_enabled', 1);
    update_post_meta($ids['beaver'], '_fl_builder_data', ['row1' => $row, 'grp1' => $group, 'col1' => $col, 'mod1' => $module]);
}

// Bricks: serialized elementen, plus een ondertekend code-element
if (defined('BRICKS_DB_PAGE_CONTENT')) {
    $code = '<a href="' . $old . '">Code-link</a>';

    $ids['bricks'] = jp_page('Bricks');
    update_post_meta($ids['bricks'], '_bricks_editor_mode', 'bricks');
    update_post_meta($ids['bricks'], BRICKS_DB_PAGE_CONTENT, [
        ['id' => 'sec001', 'name' => 'section', 'parent' => 0, 'children' => ['btn001'], 'settings' => []],
        ['id' => 'btn001', 'name' => 'button', 'parent' => 'sec001', 'children' => [], 'settings' => ['text' => 'Bekijk menu', 'link' => ['type' => 'external', 'url' => $old]]],
    ]);

    $ids['bricks_signed'] = jp_page('Bricks ondertekend');
    update_post_meta($ids['bricks_signed'], '_bricks_editor_mode', 'bricks');
    update_post_meta($ids['bricks_signed'], BRICKS_DB_PAGE_CONTENT, [
        ['id' => 'cod001', 'name' => 'code', 'parent' => 0, 'children' => [], 'settings' => ['code' => $code, 'executeCode' => true, 'signature' => wp_hash($code)]],
    ]);

    // Header-template, zodat ook header-opslag geraakt wordt
    $ids['bricks_header'] = jp_page('Bricks header', '', BRICKS_DB_TEMPLATE_SLUG);
    update_post_meta($ids['bricks_header'], BRICKS_DB_TEMPLATE_TYPE, 'header');
    update_post_meta($ids['bricks_header'], BRICKS_DB_PAGE_HEADER, [
        ['id' => 'hdr001', 'name' => 'text-link', 'parent' => 0, 'children' => [], 'settings' => ['text' => 'Menukaart', 'link' => ['type' => 'external', 'url' => $old]]],
    ]);
}

// Navigatiemenu
$menu = wp_create_nav_menu('Testmenu ' . wp_generate_password(4, false));
$ids['menu_item'] = wp_update_nav_menu_item($menu, 0, ['menu-item-title' => 'Menukaart', 'menu-item-url' => $old, 'menu-item-status' => 'publish', 'menu-item-type' => 'custom']);

// Thema-instelling, geneste serialized, JSON in serialized
update_option('jp_test_theme_options', ['menu_url' => $old, 'nested' => serialize(['url' => $old]), 'json' => wp_json_encode(['url' => $old])]);

// Termmeta
$term = wp_insert_term('Menu-cat ' . wp_generate_password(4, false), 'category');
$ids['term'] = $term['term_id'];
update_term_meta($ids['term'], 'jp_test_url', $old);

// Dingen die NIET mogen veranderen
$ids['decoy'] = jp_page('Lokaas', '<a href="' . $old . '-oud">langere naam</a> <a href="' . str_replace(JP_OLD_NAME, 'zomer2026-web_compressed.pdf', $old) . '">andere hoofdletters</a>');
$ids['suspect'] = jp_page('Verdacht', '<a href="https://staging.example.org/wp-content/uploads/testmenu/' . JP_OLD_NAME . '">staging</a>');
$ids['corrupt'] = jp_page('Kapotte meta');
global $wpdb;
$wpdb->insert($wpdb->postmeta, ['post_id' => $ids['corrupt'], 'meta_key' => 'jp_test_corrupt', 'meta_value' => 'a:1:{s:3:"url";s:999:"' . $old . '";}']);
$ids['corrupt_meta'] = $wpdb->insert_id;

// Testmail opvangen: in de container is geen mailserver
$mu = WP_CONTENT_DIR . '/mu-plugins';
wp_mkdir_p($mu);
file_put_contents($mu . '/jp-test-mail.php', "<?php\nadd_filter('pre_wp_mail', function (\$short, \$atts) {\n    file_put_contents(WP_CONTENT_DIR . '/uploads/jp-test-mail.log', \$atts['subject'] . \"\\n\" . \$atts['message'] . \"\\n=====\\n\", FILE_APPEND);\n    return true;\n}, 10, 2);\n");
@unlink(WP_CONTENT_DIR . '/uploads/jp-test-mail.log');

file_put_contents(WP_CONTENT_DIR . '/uploads/planner-fixtures.json', wp_json_encode($ids));
WP_CLI::success('Fixtures: ' . wp_json_encode($ids));
