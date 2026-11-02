<?php

namespace Jelmoo\Planner;

/*
 * Zoekt kandidaat-rijen in de standaard WordPress-tabellen, in stukken op primary
 * key, zodat een grote postmeta-tabel geen timeout geeft en de scan kan hervatten.
 */
class Scanner
{
    private const STEP = 20000;

    private const SKIP_META_KEYS = [
        '_wp_attached_file',
        '_wp_attachment_metadata',
        '_elementor_css',
        '_elementor_element_cache',
        '_edit_lock',
        '_edit_last',
        '_wp_old_slug',
        '_wp_old_date',
    ];

    private const SKIP_OPTIONS = [
        'cron',
        'siteurl',
        'home',
        'rewrite_rules',
        'active_plugins',
        'recently_edited',
    ];

    public static function targets(): array
    {
        global $wpdb;

        return [
            ['target' => 'posts', 'table' => $wpdb->posts, 'pk' => 'ID', 'col' => 'post_content', 'owner' => 'ID', 'field' => 'post_type'],
            ['target' => 'posts', 'table' => $wpdb->posts, 'pk' => 'ID', 'col' => 'post_excerpt', 'owner' => 'ID', 'field' => 'post_type'],
            ['target' => 'postmeta', 'table' => $wpdb->postmeta, 'pk' => 'meta_id', 'col' => 'meta_value', 'owner' => 'post_id', 'field' => 'meta_key'],
            ['target' => 'termmeta', 'table' => $wpdb->termmeta, 'pk' => 'meta_id', 'col' => 'meta_value', 'owner' => 'term_id', 'field' => 'meta_key'],
            ['target' => 'options', 'table' => $wpdb->options, 'pk' => 'option_id', 'col' => 'option_value', 'owner' => '0', 'field' => 'option_name'],
        ];
    }

    public static function target_for(string $target, string $col): ?array
    {
        foreach (self::targets() as $definition) {
            if ($definition['target'] === $target && $definition['col'] === $col) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * Verwerkt kandidaten tot de deadline en geeft de cursor terug. Klaar als
     * $cursor['done'] true is. $on_row krijgt de locatie en de ruwe waarde.
     */
    public static function chunk(Variants $variants, array $cursor, float $deadline, callable $on_row): array
    {
        $targets = self::targets();
        $cursor = array_merge(['t' => 0, 'last' => 0, 'max' => null, 'done' => false], $cursor);

        while ($cursor['t'] < count($targets)) {
            $definition = $targets[$cursor['t']];

            if ($cursor['max'] === null) {
                $cursor['max'] = self::max_pk($definition);
            }

            while ($cursor['last'] < $cursor['max']) {
                if (microtime(true) >= $deadline) {
                    return $cursor;
                }

                $upper = min($cursor['last'] + self::STEP, $cursor['max']);

                foreach (self::candidates($definition, $variants, $cursor['last'], $upper) as $location) {
                    $raw = self::read($location);
                    if ($raw !== null) {
                        $on_row($location, $raw);
                    }
                }

                $cursor['last'] = $upper;
            }

            $cursor['t']++;
            $cursor['last'] = 0;
            $cursor['max'] = null;
        }

        $cursor['done'] = true;

        return $cursor;
    }

    private static function max_pk(array $definition): int
    {
        global $wpdb;

        return (int) $wpdb->get_var("SELECT MAX({$definition['pk']}) FROM {$definition['table']}");
    }

    private static function candidates(array $definition, Variants $variants, int $from, int $to): array
    {
        global $wpdb;

        $likes = [];
        $values = [$from, $to];
        foreach ($variants->search_terms() as $term) {
            $likes[] = "t.{$definition['col']} LIKE %s";
            $values[] = '%' . $wpdb->esc_like($term) . '%';
        }

        $join = '';
        $where = '';

        switch ($definition['target']) {
            case 'posts':
                $where = " AND t.post_type <> 'revision' AND t.post_status <> 'auto-draft'";
                break;
            case 'postmeta':
                // Meta van revisies hoort niet bij de live pagina.
                $join = " INNER JOIN {$wpdb->posts} p ON p.ID = t.post_id AND p.post_type <> 'revision'";
                $where = self::not_in('t.meta_key', self::SKIP_META_KEYS);
                break;
            case 'termmeta':
                $where = self::not_in('t.meta_key', self::SKIP_META_KEYS);
                break;
            case 'options':
                $where = self::not_in('t.option_name', self::SKIP_OPTIONS)
                    . " AND t.option_name NOT LIKE '\\_transient\\_%'"
                    . " AND t.option_name NOT LIKE '\\_site\\_transient\\_%'"
                    . " AND t.option_name NOT LIKE 'jelmoo\\_planner\\_%'";
                break;
        }

        $owner = $definition['owner'] === '0' ? '0' : 't.' . $definition['owner'];

        $sql = "SELECT t.{$definition['pk']} AS pk, {$owner} AS owner_id, t.{$definition['field']} AS field_name
                FROM {$definition['table']} t{$join}
                WHERE t.{$definition['pk']} > %d AND t.{$definition['pk']} <= %d{$where}
                AND (" . implode(' OR ', $likes) . ')';

        $rows = $wpdb->get_results($wpdb->prepare($sql, $values), ARRAY_A) ?: [];

        return array_map(function ($row) use ($definition) {
            return [
                'target' => $definition['target'],
                'table' => $definition['table'],
                'pk_col' => $definition['pk'],
                'col' => $definition['col'],
                'pk' => (int) $row['pk'],
                'owner' => (int) $row['owner_id'],
                'field' => (string) $row['field_name'],
            ];
        }, $rows);
    }

    private static function not_in(string $column, array $values): string
    {
        $quoted = array_map(function ($value) {
            return "'" . esc_sql($value) . "'";
        }, $values);

        return " AND {$column} NOT IN (" . implode(',', $quoted) . ')';
    }

    /** Waarden komen per rij binnen; zo blijft het geheugen beperkt bij grote pagina's. */
    public static function read(array $location): ?string
    {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare(
            "SELECT {$location['col']} FROM {$location['table']} WHERE {$location['pk_col']} = %d",
            $location['pk']
        ));
    }

    /** @return array{label:string,detail:string,edit_url:string} */
    public static function describe(string $target, string $col, int $owner, string $field): array
    {
        if ($target === 'options') {
            return ['label' => 'Instelling: ' . $field, 'detail' => 'wp_options', 'edit_url' => ''];
        }

        if ($target === 'termmeta') {
            $term = get_term($owner);
            $name = $term instanceof \WP_Term ? $term->name : '#' . $owner;
            return ['label' => 'Term: ' . $name, 'detail' => $field, 'edit_url' => ''];
        }

        $post = get_post($owner);
        if (!$post) {
            return ['label' => 'Post #' . $owner . ' (bestaat niet meer)', 'detail' => $field, 'edit_url' => ''];
        }

        $detail = $target === 'posts' ? $col : $field;

        if ($post->post_type === 'nav_menu_item') {
            return ['label' => 'Menu-item: ' . ($post->post_title !== '' ? $post->post_title : '#' . $owner), 'detail' => $detail, 'edit_url' => admin_url('nav-menus.php')];
        }

        $type = get_post_type_object($post->post_type);
        $type_label = $type ? $type->labels->singular_name : $post->post_type;
        $title = $post->post_title !== '' ? $post->post_title : '(geen titel) #' . $owner;

        return [
            'label' => $type_label . ': ' . $title . ($post->post_status !== 'publish' ? ' [' . $post->post_status . ']' : ''),
            'detail' => $detail,
            'edit_url' => (string) get_edit_post_link($owner, 'raw'),
        ];
    }
}
