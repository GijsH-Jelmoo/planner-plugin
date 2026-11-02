<?php

namespace Jelmoo\Planner;

/*
 * Voert taken uit. Eén tick claimt wat aan de beurt is. Een link-wissel wordt eerst
 * volledig voorbereid (hervatbaar) en daarna pas toegepast, zodat een timeout geen
 * halve wissel achterlaat.
 */
class Runner
{
    public const TICK_HOOK = 'jelmoo_planner_tick';
    public const CONTINUE_HOOK = 'jelmoo_planner_continue';
    public const TICK_SCHEDULE = 'jelmoo_planner_5min';

    private const MAX_ATTEMPTS = 20;
    private const MAX_NETWORK_RETRIES = 3;
    private const KEEP_UNDO_DAYS = 90;
    private const CHECK_DELAYS = [HOUR_IN_SECONDS, 23 * HOUR_IN_SECONDS];

    public static function schedule_tick(): void
    {
        if (!wp_next_scheduled(self::TICK_HOOK)) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, self::TICK_SCHEDULE, self::TICK_HOOK);
        }
    }

    public static function tick(): void
    {
        update_option('jelmoo_planner_last_tick', time(), false);

        $deadline = microtime(true) + self::budget();

        foreach (Store::due_job_ids() as $id) {
            if (Store::claim_due($id)) {
                self::work($id, $deadline);
            }
        }

        foreach (Store::running_job_ids() as $id) {
            if (Store::claim_lease($id)) {
                self::work($id, $deadline);
            }
        }

        foreach (Store::check_due_job_ids() as $id) {
            if (Store::claim_lease($id)) {
                self::follow_up_check($id, $deadline);
            }
        }

        Store::cleanup(self::KEEP_UNDO_DAYS);
    }

    private static function budget(): float
    {
        $limit = (int) ini_get('max_execution_time');

        return $limit > 0 ? min(20.0, $limit * 0.5) : 20.0;
    }

    private static function work(int $id, float $deadline): void
    {
        $job = Store::get_job($id);
        if (!$job) {
            return;
        }

        $job['state']['attempts'] = (int) ($job['state']['attempts'] ?? 0) + 1;
        if ($job['state']['attempts'] > self::MAX_ATTEMPTS) {
            self::fail($job, 'De taak bleef vastlopen en is gestopt na ' . self::MAX_ATTEMPTS . ' pogingen.');
            return;
        }

        Store::update_job($id, ['state' => $job['state']]);

        try {
            $finished = $job['type'] === 'file' ? self::advance_file($job) : self::advance_link($job, $deadline);
        } catch (\Throwable $error) {
            error_log('Jelmoo Planner: taak ' . $id . ' mislukt: ' . $error->getMessage());
            self::fail($job, $error->getMessage());
            return;
        }

        // Tijd op: de rest gaat zo snel mogelijk verder in plaats van bij de volgende tick.
        if (!$finished) {
            Store::release($id);
            wp_schedule_single_event(time() + 5, self::CONTINUE_HOOK);
        }
    }

    /** @return bool true als er niets meer te doen is */
    private static function advance_link(array &$job, float $deadline): bool
    {
        $variants = self::variants_for($job);

        if ($job['status'] === 'preparing') {
            if (empty($job['state']['preflight'])) {
                $check = self::check_new_url($job);
                if ($check['result'] === 'retry') {
                    return self::retry_later($job, $check['message']);
                }
                if ($check['result'] === 'fail') {
                    self::fail($job, $check['message']);
                    return true;
                }
                $job['state']['preflight'] = true;
                if ($check['message'] !== '') {
                    $job['state']['messages'][] = $check['message'];
                }
            }

            $rewriter = new Rewriter($variants);
            $job['state']['cursor'] = Scanner::chunk($variants, $job['state']['cursor'] ?? [], $deadline, function ($location, $raw) use ($rewriter, $job) {
                self::prepare_row($job['id'], $location, $raw, $rewriter);
            });

            if (empty($job['state']['cursor']['done'])) {
                Store::update_job($job['id'], ['state' => $job['state']]);
                return false;
            }

            $job['status'] = 'applying';
            Store::update_job($job['id'], ['status' => 'applying', 'state' => $job['state']]);
        }

        if ($job['status'] === 'applying') {
            if (!self::apply_rows($job, $variants, $deadline)) {
                return false;
            }

            $job['status'] = 'finishing';
            $job['state']['cursor'] = [];
            Store::update_job($job['id'], ['status' => 'finishing', 'state' => $job['state']]);
            Flusher::flush($job);
        }

        // De tweede ronde telt wat er na de wissel nog over is.
        $job['state']['remaining_cursor'] = $job['state']['remaining_cursor'] ?? [];
        $remaining = $job['state']['remaining'] ?? [];
        $job['state']['remaining_cursor'] = Scanner::chunk($variants, $job['state']['remaining_cursor'], $deadline, function ($location, $raw) use ($variants, &$remaining) {
            if ($variants->matches($raw)) {
                $remaining[] = self::location_key($location['target'], $location['pk'], $location['col']);
            }
        });
        $job['state']['remaining'] = array_values(array_unique($remaining));

        if (empty($job['state']['remaining_cursor']['done'])) {
            Store::update_job($job['id'], ['state' => $job['state']]);
            return false;
        }

        self::finish($job, $variants);

        return true;
    }

    private static function prepare_row(int $job_id, array $location, string $raw, Rewriter $rewriter): void
    {
        $result = $rewriter->rewrite($raw);

        switch ($result['status']) {
            case Rewriter::CHANGED:
                Store::upsert_row($job_id, $location, 'pending', $result['count'], '', $raw, $result['value']);
                break;
            case Rewriter::SKIPPED:
            case Rewriter::SUSPECT:
                Store::upsert_row($job_id, $location, $result['status'], 0, $result['reason'], null, null);
                break;
        }
    }

    /** @return bool true als alle rijen verwerkt zijn */
    private static function apply_rows(array $job, Variants $variants, float $deadline): bool
    {
        global $wpdb;

        $rewriter = new Rewriter($variants);

        while (true) {
            $rows = Store::rows_with_state($job['id'], 'pending', 25);
            if (!$rows) {
                return true;
            }

            foreach ($rows as $row) {
                if (microtime(true) >= $deadline) {
                    return false;
                }

                $definition = Scanner::target_for($row['target'], $row['col']);
                $location = self::location_from_row($row, $definition);
                $current = Scanner::read($location);

                if ($current === null) {
                    Store::update_row((int) $row['id'], ['state' => 'failed', 'reason' => 'Deze plek bestaat niet meer.']);
                    continue;
                }

                $before = $row['before_value'];
                $after = $row['after_value'];
                $matches = (int) $row['matches'];

                // Iemand heeft de plek na het voorbereiden bewerkt: opnieuw berekenen.
                if ($current !== $before) {
                    $result = $rewriter->rewrite($current);
                    if ($result['status'] !== Rewriter::CHANGED) {
                        Store::update_row((int) $row['id'], ['state' => $result['status'] === Rewriter::UNCHANGED ? 'failed' : $result['status'], 'reason' => $result['reason'] ?: 'De link stond hier niet meer toen de wissel draaide.']);
                        continue;
                    }
                    $before = $current;
                    $after = $result['value'];
                    $matches = $result['count'];
                }

                // Direct op de tabel: update_post_meta zou de backslashes in JSON eraf halen.
                $written = $wpdb->update($definition['table'], [$row['col'] => $after], [$definition['pk'] => (int) $row['pk']]);

                if ($written === false) {
                    Store::update_row((int) $row['id'], ['state' => 'failed', 'reason' => substr('Database weigerde de wijziging: ' . $wpdb->last_error, 0, 255)]);
                    continue;
                }

                self::clear_object_cache($row);
                Store::update_row((int) $row['id'], ['state' => 'applied', 'matches' => $matches, 'before_value' => $before, 'after_value' => $after]);
            }
        }
    }

    private static function location_from_row(array $row, array $definition): array
    {
        return [
            'target' => $row['target'],
            'table' => $definition['table'],
            'pk_col' => $definition['pk'],
            'col' => $row['col'],
            'pk' => (int) $row['pk'],
            'owner' => (int) $row['owner_id'],
            'field' => $row['field'],
        ];
    }

    private static function location_key(string $target, int $pk, string $col): string
    {
        return $target . ':' . $pk . ':' . $col;
    }

    private static function clear_object_cache(array $row): void
    {
        $owner = (int) $row['owner_id'];

        switch ($row['target']) {
            case 'posts':
                clean_post_cache($owner);
                break;
            case 'postmeta':
                wp_cache_delete($owner, 'post_meta');
                clean_post_cache($owner);
                break;
            case 'termmeta':
                wp_cache_delete($owner, 'term_meta');
                break;
            case 'options':
                wp_cache_delete($row['field'], 'options');
                wp_cache_delete('alloptions', 'options');
                break;
        }
    }

    private static function advance_file(array &$job): bool
    {
        // Een eerdere poging kan al gewisseld hebben; dan niet opnieuw.
        if (empty($job['state']['after_hash'])) {
            $files = FileSwap::validate($job['old_url'], $job['new_url']);
            $job['state'] = array_merge($job['state'], FileSwap::swap($files['target'], $files['source'], $job['id']));
            $job['state']['target'] = $files['target'];
            Store::update_job($job['id'], ['status' => 'finishing', 'state' => $job['state']]);
            $job['status'] = 'finishing';
            Flusher::flush($job);
        }

        $messages = [];
        $problems = 0;

        $response = wp_remote_get($job['old_url'], ['timeout' => 30, 'sslverify' => apply_filters('https_local_ssl_verify', false)]);
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            $messages[] = 'De URL kon na de wissel niet worden opgehaald. Controleer hem handmatig.';
            $problems++;
        } elseif (md5(wp_remote_retrieve_body($response)) !== $job['state']['after_hash']) {
            $messages[] = 'De URL geeft nog niet het nieuwe bestand terug. Waarschijnlijk zit er nog een cache voor. Leeg die en controleer de URL opnieuw.';
            $problems++;
        } else {
            $messages[] = 'De URL geeft nu het nieuwe bestand terug (inhoud vergeleken).';
        }

        $report = ['messages' => $messages];
        $status = $problems ? 'warning' : 'done';

        Store::update_job($job['id'], [
            'status' => $status,
            'report' => $report,
            'state' => $job['state'],
            'claimed_at' => null,
            'finished_at' => Store::now(),
        ]);

        self::send_report(Store::get_job($job['id']));

        return true;
    }

    private static function finish(array $job, Variants $variants): void
    {
        $counts = Store::count_by_state($job['id']);
        $pages = Flusher::check_pages(Flusher::affected_urls($job['id']), $variants);

        $applied = $counts['applied']['rows'] ?? 0;
        $known = self::known_locations($job['id']);
        $unexpected = array_values(array_diff($job['state']['remaining'] ?? [], $known));

        $messages = $job['state']['messages'] ?? [];
        if ($applied === 0) {
            $messages[] = 'De oude URL is nergens gewijzigd. Staat de link misschien op een plek buiten de database of al op de nieuwe URL?';
        }

        $old_pages = array_filter($pages, function ($page) {
            return $page['result'] === 'old';
        });
        if ($old_pages) {
            $messages[] = 'Op ' . count($old_pages) . ' pagina(\'s) staat de oude URL nog in de HTML. Dat kan een cache zijn, of een verwijzing die niet in de scan zit (bijv. via een bestands-ID).';
        }

        $not_changed = ($counts['skipped']['rows'] ?? 0) + ($counts['suspect']['rows'] ?? 0) + ($counts['failed']['rows'] ?? 0);
        if ($not_changed > 0) {
            $messages[] = $not_changed . ' plek(ken) zijn niet gewijzigd. Op de taakpagina staat per plek de reden; pas deze zo nodig met de hand aan.';
        }

        if ($unexpected) {
            $messages[] = 'Na de wissel staat de oude URL nog op ' . count($unexpected) . ' plek(ken) in de database. Mogelijk is daar tijdens de wissel iets opgeslagen.';
        }

        $has_problem = $applied === 0 || $old_pages || $unexpected
            || !empty($counts['skipped']) || !empty($counts['suspect']) || !empty($counts['failed']);

        $report = [
            'counts' => $counts,
            'remaining' => $job['state']['remaining'] ?? [],
            'unexpected' => $unexpected,
            'pages' => $pages,
            'messages' => $messages,
        ];

        unset($job['state']['cursor'], $job['state']['remaining_cursor']);

        Store::update_job($job['id'], [
            'status' => $has_problem ? 'warning' : 'done',
            'report' => $report,
            'state' => $job['state'],
            'claimed_at' => null,
            'finished_at' => Store::now(),
            'next_check_at' => gmdate('Y-m-d H:i:s', time() + self::CHECK_DELAYS[0]),
        ]);

        self::send_report(Store::get_job($job['id']));
    }

    /** Plekken die bewust niet gewijzigd zijn; die horen niet als nieuwe treffer te tellen. */
    private static function known_locations(int $job_id): array
    {
        $keys = [];
        foreach (Store::row_summaries($job_id) as $row) {
            if (in_array($row['state'], ['skipped', 'suspect', 'failed'], true)) {
                $keys[] = self::location_key($row['target'], (int) $row['pk'], $row['col']);
            }
        }

        return $keys;
    }

    /*
     * Een open editor-tab kan na de wissel de oude link terugzetten, en een purge kan
     * later doorkomen. Daarom na 1 uur en na 24 uur nog eens tellen en kijken.
     */
    private static function follow_up_check(int $id, float $deadline): void
    {
        $job = Store::get_job($id);
        if (!$job || $job['type'] === 'file' || !in_array($job['status'], ['done', 'warning'], true)) {
            if ($job) {
                Store::update_job($id, ['next_check_at' => null, 'claimed_at' => null]);
            }
            return;
        }

        $variants = self::variants_for($job);
        $hits = $job['state']['check_hits'] ?? [];
        $job['state']['check_cursor'] = Scanner::chunk($variants, $job['state']['check_cursor'] ?? [], $deadline, function ($location, $raw) use ($variants, &$hits) {
            if ($variants->matches($raw)) {
                $hits[] = self::location_key($location['target'], $location['pk'], $location['col']);
            }
        });
        $job['state']['check_hits'] = array_values(array_unique($hits));

        if (empty($job['state']['check_cursor']['done'])) {
            Store::update_job($id, ['state' => $job['state'], 'claimed_at' => null]);
            wp_schedule_single_event(time() + 5, self::CONTINUE_HOOK);
            return;
        }

        $unexpected = array_values(array_diff($job['state']['check_hits'], self::known_locations($id)));
        $pages = Flusher::check_pages(Flusher::affected_urls($id), $variants);
        $old_pages = array_values(array_filter($pages, function ($page) {
            return $page['result'] === 'old';
        }));

        $checks_done = (int) $job['checks_done'] + 1;
        $next = self::CHECK_DELAYS[$checks_done] ?? null;

        $report = $job['report'];
        $report['checks'][] = ['at' => Store::now(), 'unexpected' => $unexpected, 'pages' => $pages];

        unset($job['state']['check_cursor'], $job['state']['check_hits']);

        Store::update_job($id, [
            'checks_done' => $checks_done,
            'next_check_at' => $next ? gmdate('Y-m-d H:i:s', time() + $next) : null,
            'report' => $report,
            'state' => $job['state'],
            'claimed_at' => null,
        ]);

        if ($unexpected || $old_pages) {
            self::send_mail(
                Store::get_job($id),
                'oude link is terug',
                sprintf(
                    "Bij de nacontrole staat de oude URL weer op %d plek(ken) in de database en op %d pagina('s). Iemand kan een oude versie hebben opgeslagen, of er zit nog een cache voor.\n\nOude URL: %s",
                    count($unexpected),
                    count($old_pages),
                    $job['old_url']
                )
            );
        }
    }

    public static function undo(int $id): string
    {
        global $wpdb;

        $job = Store::get_job($id);
        if (!$job || !self::can_undo($job)) {
            return 'Deze wissel kan niet (meer) ongedaan gemaakt worden.';
        }

        if ($job['type'] === 'file') {
            FileSwap::restore($job['state']['target'], $job['state']);
            Store::update_job($id, ['status' => 'undone', 'next_check_at' => null]);
            Flusher::flush($job);
            return 'Het oude bestand staat weer terug.';
        }

        $reverted = 0;
        $conflicts = 0;

        while ($rows = Store::rows_with_state($id, 'applied', 25)) {
            foreach ($rows as $row) {
                $definition = Scanner::target_for($row['target'], $row['col']);
                $current = Scanner::read(self::location_from_row($row, $definition));

                // Nieuwer werk gaat voor: alleen terugzetten wat nog exact onze wijziging is.
                if ($current !== $row['after_value']) {
                    Store::update_row((int) $row['id'], ['state' => 'conflict', 'reason' => 'Sinds de wissel gewijzigd; niet teruggezet.']);
                    $conflicts++;
                    continue;
                }

                $written = $wpdb->update($definition['table'], [$row['col'] => $row['before_value']], [$definition['pk'] => (int) $row['pk']]);
                if ($written === false) {
                    Store::update_row((int) $row['id'], ['state' => 'conflict', 'reason' => substr('Database weigerde het terugzetten: ' . $wpdb->last_error, 0, 255)]);
                    $conflicts++;
                    continue;
                }

                self::clear_object_cache($row);
                Store::update_row((int) $row['id'], ['state' => 'reverted']);
                $reverted++;
            }
        }

        Store::update_job($id, ['status' => 'undone', 'next_check_at' => null]);
        Flusher::flush($job);

        return sprintf('%d plek(ken) teruggezet, %d overgeslagen omdat ze sinds de wissel gewijzigd zijn.', $reverted, $conflicts);
    }

    public static function can_undo(array $job): bool
    {
        if (!in_array($job['status'], ['done', 'warning', 'failed'], true) || empty($job['finished_at'])) {
            return false;
        }

        if (strtotime($job['finished_at'] . ' UTC') < time() - self::KEEP_UNDO_DAYS * DAY_IN_SECONDS) {
            return false;
        }

        if ($job['type'] === 'file') {
            return !empty($job['state']['after_hash']);
        }

        $counts = Store::count_by_state($job['id']);

        return !empty($counts['applied']);
    }

    /** @return array{result:string,message:string} result: ok, retry, fail */
    public static function check_new_url(array $job): array
    {
        $url = $job['new_url'];

        try {
            FileSwap::resolve($url);
            return ['result' => 'ok', 'message' => ''];
        } catch (\RuntimeException $not_local) {
            // Geen lokaal uploads-bestand: via HTTP controleren.
        }

        $is_local = self::same_site($url);
        $response = wp_remote_get($url, [
            'timeout' => 15,
            'limit_response_size' => 4096,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
        ]);

        if (is_wp_error($response)) {
            // Een server die zichzelf niet mag bereiken, mag een nachtelijke wissel niet tegenhouden.
            if ($is_local) {
                return ['result' => 'ok', 'message' => 'De nieuwe URL kon niet gecontroleerd worden: ' . $response->get_error_message()];
            }
            return ['result' => 'retry', 'message' => 'De nieuwe URL is niet bereikbaar: ' . $response->get_error_message()];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code === 404 || $code === 410) {
            return ['result' => 'fail', 'message' => 'De nieuwe URL bestaat niet (HTTP ' . $code . '): ' . $url];
        }
        if ($code >= 500) {
            return ['result' => 'retry', 'message' => 'De nieuwe URL geeft een serverfout (HTTP ' . $code . ').'];
        }

        // Een "zachte 404": de URL lijkt een PDF, maar de server geeft een HTML-pagina terug.
        $type = strtolower((string) wp_remote_retrieve_header($response, 'content-type'));
        if (preg_match('/\.pdf(\?|#|$)/i', $url) && strpos($type, 'text/html') !== false) {
            return ['result' => 'fail', 'message' => 'De nieuwe URL zou een PDF moeten zijn, maar geeft een HTML-pagina terug: ' . $url];
        }

        return ['result' => 'ok', 'message' => ''];
    }

    private static function same_site(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $site = strtolower((string) parse_url(home_url('/'), PHP_URL_HOST));

        return preg_replace('/^www\./', '', $host) === preg_replace('/^www\./', '', $site);
    }

    private static function retry_later(array &$job, string $message): bool
    {
        $job['state']['network_retries'] = (int) ($job['state']['network_retries'] ?? 0) + 1;

        if ($job['state']['network_retries'] > self::MAX_NETWORK_RETRIES) {
            self::fail($job, $message);
            return true;
        }

        // De claim blijft staan, zodat de nieuwe poging pas bij de volgende tick komt.
        Store::update_job($job['id'], ['state' => $job['state'], 'claimed_at' => Store::now()]);

        return true;
    }

    private static function fail(array $job, string $message): void
    {
        Store::update_job($job['id'], [
            'status' => 'failed',
            'report' => array_merge($job['report'], ['counts' => Store::count_by_state($job['id']), 'messages' => [$message]]),
            'state' => $job['state'],
            'claimed_at' => null,
            'finished_at' => Store::now(),
        ]);

        self::send_report(Store::get_job($job['id']));
    }

    public static function variants_for(array $job): Variants
    {
        return new Variants($job['old_url'], $job['new_url'], home_url('/'));
    }

    private static function send_report(?array $job): void
    {
        if (!$job) {
            return;
        }

        $titles = ['done' => 'geslukt', 'warning' => 'gedaan, maar controleer dit', 'failed' => 'mislukt'];
        $lines = [
            'Oud: ' . $job['old_url'],
            'Nieuw: ' . $job['new_url'],
            '',
        ];

        foreach ($job['report']['counts'] ?? [] as $state => $count) {
            $lines[] = self::state_label($state) . ': ' . $count['rows'];
        }

        foreach ($job['report']['pages'] ?? [] as $page) {
            $lines[] = self::page_label($page['result']) . ': ' . $page['url'];
        }

        foreach ($job['report']['messages'] ?? [] as $message) {
            $lines[] = '';
            $lines[] = $message;
        }

        self::send_mail($job, $titles[$job['status']] ?? $job['status'], implode("\n", $lines));
    }

    private static function send_mail(?array $job, string $title, string $body): void
    {
        if (!$job || $job['mail_to'] === '') {
            return;
        }

        $link = admin_url('tools.php?page=jelmoo-planner&view=job&id=' . $job['id']);

        wp_mail(
            $job['mail_to'],
            sprintf('[Planner] %s: %s', wp_parse_url(home_url('/'), PHP_URL_HOST), $title),
            $body . "\n\nDetails: " . $link
        );
    }

    public static function state_label(string $state): string
    {
        $labels = [
            'pending' => 'Klaargezet',
            'applied' => 'Gewijzigd',
            'skipped' => 'Overgeslagen',
            'suspect' => 'Verdacht, niet gewijzigd',
            'failed' => 'Mislukt',
            'conflict' => 'Niet teruggezet',
            'reverted' => 'Teruggezet',
        ];

        return $labels[$state] ?? $state;
    }

    public static function page_label(string $result): string
    {
        $labels = ['ok' => 'Oude link weg', 'old' => 'Oude link nog zichtbaar', 'error' => 'Niet te controleren'];

        return $labels[$result] ?? $result;
    }
}
