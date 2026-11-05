<?php

namespace Jelmoo\Planner;

/*
 * Leegt caches na een wissel en kijkt daarna wat een bezoeker echt te zien krijgt.
 * Bewust alleen integraties die getest zijn; andere caches kunnen op de actie
 * jelmoo_planner_after_swap haken, en de paginacontrole meldt het als er nog een
 * oude link wordt uitgeserveerd.
 */
class Flusher
{
    private const MAX_PAGES = 15;

    public static function flush(array $job): void
    {
        wp_cache_flush();

        if (class_exists('\Elementor\Plugin')) {
            try {
                \Elementor\Plugin::instance()->files_manager->clear_cache();
            } catch (\Throwable $error) {
                error_log('Jelmoo Planner: Elementor-cache legen mislukt: ' . $error->getMessage());
            }
        }

        self::refresh_bricks_css($job);

        do_action('litespeed_purge_all', 'Jelmoo Planner');

        do_action('jelmoo_planner_after_swap', $job);

        self::kick();
    }

    /*
     * Met "External Files" staan achtergrondafbeeldingen in een CSS-bestand per pagina.
     * Bricks maakt dat alleen opnieuw bij opslaan, en de wissel schrijft rechtstreeks.
     */
    private static function refresh_bricks_css(array $job): void
    {
        if (!class_exists('\Bricks\Assets_Files') || !class_exists('\Bricks\Database') || \Bricks\Database::get_setting('cssLoading') !== 'file') {
            return;
        }

        $posts = [];
        foreach (Store::row_summaries($job['id']) as $row) {
            if ($row['target'] === 'postmeta' && strpos($row['field'], '_bricks_page_') === 0) {
                $posts[(int) $row['owner_id']] = true;
            }
        }

        foreach (array_keys($posts) as $post_id) {
            try {
                // Een index boven 0: Bricks gooit dan niet eerst alle CSS-bestanden weg.
                \Bricks\Assets_Files::regenerate_css_file((string) $post_id, 1, true);
            } catch (\Throwable $error) {
                error_log('Jelmoo Planner: Bricks-CSS maken mislukt: ' . $error->getMessage());
            }
        }
    }

    /*
     * LiteSpeed zet een purge vanuit cron in een wachtrij, die de volgende PHP-aanroep
     * verstuurt. Pagina's uit de cache raken PHP niet, dus sturen we die aanroep zelf.
     */
    private static function kick(): void
    {
        if (!defined('LSCWP_V')) {
            return;
        }

        wp_remote_get(add_query_arg('action', 'jelmoo_planner_kick', admin_url('admin-ajax.php')), [
            'timeout' => 10,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
        ]);
    }

    /** Pagina's die geraakt zijn, plus de homepage (daar staat het menu meestal ook). */
    public static function affected_urls(int $job_id): array
    {
        $urls = [home_url('/')];

        foreach (Store::row_summaries($job_id) as $row) {
            if (!in_array($row['target'], ['posts', 'postmeta'], true)) {
                continue;
            }

            $post = get_post((int) $row['owner_id']);
            if ($post && is_post_publicly_viewable($post)) {
                $urls[] = get_permalink($post);
            }
        }

        return array_slice(array_values(array_unique(array_filter($urls))), 0, self::MAX_PAGES);
    }

    /** @return array<int,array{url:string,result:string}> result: ok, old, error */
    public static function check_pages(array $urls, Variants $variants): array
    {
        $results = [];

        foreach ($urls as $url) {
            $response = wp_remote_get($url, [
                'timeout' => 15,
                'redirection' => 3,
                'sslverify' => apply_filters('https_local_ssl_verify', false),
            ]);

            if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) >= 400) {
                $results[] = ['url' => $url, 'result' => 'error'];
                continue;
            }

            $results[] = [
                'url' => $url,
                'result' => $variants->matches(wp_remote_retrieve_body($response)) ? 'old' : 'ok',
            ];
        }

        return $results;
    }
}
