<?php
/**
 * Plugin Name: Jelmoo Planner
 * Description: Plant het wisselen van een link of het vervangen van een bestand (bijvoorbeeld een menukaart) op een vast moment, op elke WordPress-site en met elke builder. Toont vooraf wat er verandert, controleert achteraf de echte pagina's en kan de wissel ongedaan maken.
 * Version: 0.1.1
 * Author: Jelmoo Studio
 * Requires at least: 5.9
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

const JELMOO_PLANNER_VERSION = '0.1.1';
const JELMOO_PLANNER_FILE = __FILE__;

// Updates komen uit de releases van deze repo. Leeg = geen update-check.
const JELMOO_PLANNER_REPO = 'https://github.com/GijsH-Jelmoo/planner-plugin/';

require_once __DIR__ . '/includes/Variants.php';
require_once __DIR__ . '/includes/Rewriter.php';
require_once __DIR__ . '/includes/Store.php';
require_once __DIR__ . '/includes/Scanner.php';
require_once __DIR__ . '/includes/FileSwap.php';
require_once __DIR__ . '/includes/Flusher.php';
require_once __DIR__ . '/includes/Runner.php';
require_once __DIR__ . '/includes/Admin.php';

use Jelmoo\Planner\Admin;
use Jelmoo\Planner\Runner;
use Jelmoo\Planner\Store;

register_activation_hook(__FILE__, function () {
    Store::install();
    Runner::schedule_tick();
});

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook(Runner::TICK_HOOK);
    wp_clear_scheduled_hook(Runner::CONTINUE_HOOK);
});

add_filter('cron_schedules', function ($schedules) {
    $schedules[Runner::TICK_SCHEDULE] = ['interval' => 5 * MINUTE_IN_SECONDS, 'display' => 'Elke 5 minuten (Jelmoo Planner)'];
    return $schedules;
});

add_action(Runner::TICK_HOOK, [Runner::class, 'tick']);
add_action(Runner::CONTINUE_HOOK, [Runner::class, 'tick']);

// Herstelt de tick als die uit de cron-lijst is verdwenen (bijv. na een migratie).
add_action('init', [Runner::class, 'schedule_tick']);

add_action('admin_init', [Store::class, 'maybe_upgrade']);

Admin::register();

add_action('plugins_loaded', function () {
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (JELMOO_PLANNER_REPO === '' || !file_exists($autoload)) {
        return;
    }

    require_once $autoload;

    $checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(JELMOO_PLANNER_REPO, __FILE__, 'jelmoo-planner');
    $checker->getVcsApi()->enableReleaseAssets();

    // Alleen nodig bij een private repo.
    if (defined('JELMOO_PLANNER_GITHUB_TOKEN')) {
        $checker->setAuthentication(JELMOO_PLANNER_GITHUB_TOKEN);
    }
});
