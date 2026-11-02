<?php

namespace Jelmoo\Planner;

/*
 * Nieuwe inhoud op een bestaande URL, bijvoorbeeld een PDF achter een geprinte
 * QR-code. Alleen binnen de uploads-map, met backup, en het doelbestand wordt in
 * één keer vervangen zodat een bezoeker nooit een half bestand krijgt.
 */
class FileSwap
{
    private const BACKUP_DIR = 'jelmoo-planner-backups';

    /** Lokaal pad bij een uploads-URL. Gooit een uitzondering met een bruikbare melding. */
    public static function resolve(string $url): string
    {
        $uploads = wp_get_upload_dir();
        $base = self::normalize($uploads['baseurl']);
        $target = self::normalize(strtok($url, '?#'));

        if (strpos($target, $base . '/') !== 0) {
            throw new \RuntimeException('Dit bestand staat niet in de uploads-map van deze site: ' . $url);
        }

        $relative = rawurldecode(substr($target, strlen($base)));
        $path = realpath($uploads['basedir'] . $relative);
        $root = realpath($uploads['basedir']);

        // Staat wel in de mediabibliotheek maar niet op schijf: meestal offloaded media.
        if ($path === false || !is_file($path)) {
            throw new \RuntimeException('Bestand niet op deze server gevonden (staat het op S3 of een CDN?): ' . $url);
        }

        if ($root === false || strpos($path, $root . DIRECTORY_SEPARATOR) !== 0) {
            throw new \RuntimeException('Dit pad valt buiten de uploads-map: ' . $url);
        }

        return $path;
    }

    private static function normalize(string $url): string
    {
        return rtrim(preg_replace('#^https?://(www\.)?#i', '', $url), '/');
    }

    /** @return array{target:string,source:string} */
    public static function validate(string $target_url, string $source_url): array
    {
        $target = self::resolve($target_url);
        $source = self::resolve($source_url);

        if ($target === $source) {
            throw new \RuntimeException('Het nieuwe bestand is hetzelfde als het bestand dat vervangen wordt.');
        }

        $type = wp_check_filetype(basename($target));
        if (empty($type['ext']) || strtolower(pathinfo($source, PATHINFO_EXTENSION)) !== strtolower(pathinfo($target, PATHINFO_EXTENSION))) {
            throw new \RuntimeException('Beide bestanden moeten hetzelfde, toegestane bestandstype hebben.');
        }

        if (!is_writable($target) || !is_writable(dirname($target))) {
            throw new \RuntimeException('Geen schrijfrechten op ' . basename($target) . ' of de map daarvan.');
        }

        if (filesize($source) === 0) {
            throw new \RuntimeException('Het nieuwe bestand is leeg.');
        }

        return ['target' => $target, 'source' => $source];
    }

    /**
     * Verkleinde versies die WordPress van een afbeelding maakte (naam-300x300.png).
     * Die worden niet mee vervangen, terwijl browsers ze via srcset wel kunnen kiezen.
     *
     * @return string[]
     */
    public static function stale_variants(string $path): array
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $base = preg_replace('/-scaled$/', '', pathinfo($path, PATHINFO_FILENAME));
        $variants = [];

        foreach ((array) glob(dirname($path) . '/*') as $file) {
            if (preg_match('/^' . preg_quote($base, '/') . '-\d+x\d+\.' . preg_quote($extension, '/') . '$/', basename($file))) {
                $variants[] = basename($file);
            }
        }

        return $variants;
    }

    public static function stale_variants_warning(array $variants): string
    {
        return sprintf(
            'Dit is een afbeelding met %d kleinere versie(s), zoals %s. Die worden niet vervangen, dus bezoekers kunnen het oude beeld blijven zien. Gebruik voor afbeeldingen Element tonen met Tegelijk verbergen.',
            count($variants),
            $variants[0]
        );
    }

    /** @return array{backup:string,before_hash:string,after_hash:string} */
    public static function swap(string $target, string $source, int $job_id): array
    {
        $backup = self::backup_path($target, $job_id);

        if (!copy($target, $backup) || md5_file($backup) !== md5_file($target)) {
            throw new \RuntimeException('Backup maken van het oude bestand is mislukt.');
        }

        $before_hash = md5_file($target);
        $after_hash = self::replace_atomic($source, $target);

        self::refresh_attachment($target);

        return ['backup' => $backup, 'before_hash' => $before_hash, 'after_hash' => $after_hash];
    }

    /** Zet de backup terug, tenzij het bestand sinds de wissel is veranderd. */
    public static function restore(string $target, array $state): void
    {
        if (empty($state['backup']) || !is_file($state['backup'])) {
            throw new \RuntimeException('De backup bestaat niet meer.');
        }

        if (md5_file($target) !== ($state['after_hash'] ?? '')) {
            throw new \RuntimeException('Het bestand is sinds de wissel gewijzigd; het is niet teruggezet.');
        }

        self::replace_atomic($state['backup'], $target);
        self::refresh_attachment($target);
    }

    private static function replace_atomic(string $from, string $to): string
    {
        $hash = md5_file($from);
        $temp = $to . '.jelmoo-' . wp_generate_password(8, false) . '.tmp';

        if (!copy($from, $temp) || md5_file($temp) !== $hash) {
            @unlink($temp);
            throw new \RuntimeException('Kopiëren naar een tijdelijk bestand is mislukt.');
        }

        @chmod($temp, fileperms($to) & 0777);

        if (!rename($temp, $to)) {
            @unlink($temp);
            throw new \RuntimeException('Het bestand kon niet vervangen worden.');
        }

        clearstatcache(true, $to);

        return $hash;
    }

    private static function backup_path(string $target, int $job_id): string
    {
        $uploads = wp_get_upload_dir();
        $dir = trailingslashit($uploads['basedir']) . self::BACKUP_DIR;

        if (!wp_mkdir_p($dir)) {
            throw new \RuntimeException('Backup-map kon niet aangemaakt worden.');
        }

        if (!file_exists($dir . '/index.php')) {
            file_put_contents($dir . '/index.php', "<?php\n// Stil.\n");
        }

        return $dir . '/job' . $job_id . '-' . wp_generate_password(12, false) . '-' . basename($target);
    }

    /** De mediabibliotheek toont anders nog de oude bestandsgrootte. */
    private static function refresh_attachment(string $target): void
    {
        $uploads = wp_get_upload_dir();
        $url = $uploads['baseurl'] . substr($target, strlen(realpath($uploads['basedir'])));
        $id = attachment_url_to_postid($url);
        if (!$id) {
            return;
        }

        $meta = wp_get_attachment_metadata($id);
        if (is_array($meta)) {
            $meta['filesize'] = filesize($target);
            wp_update_attachment_metadata($id, $meta);
        }
    }

    /** Een purge bereikt een telefoon niet die het bestand al heeft. Geeft een waarschuwing of ''. */
    public static function browser_cache_warning(string $url): string
    {
        $response = wp_remote_get($url, ['timeout' => 15, 'limit_response_size' => 1024]);
        if (is_wp_error($response)) {
            return '';
        }

        $seconds = 0;
        $control = (string) wp_remote_retrieve_header($response, 'cache-control');
        if (preg_match('/max-age=(\d+)/', $control, $match)) {
            $seconds = (int) $match[1];
        } else {
            $expires = strtotime((string) wp_remote_retrieve_header($response, 'expires'));
            if ($expires) {
                $seconds = $expires - time();
            }
        }

        if ($seconds <= DAY_IN_SECONDS) {
            return '';
        }

        return sprintf(
            'De server laat browsers dit bestand %d dagen bewaren. Wie het bestand al eerder opende, kan tot die tijd de oude versie blijven zien. Zet de browsercache voor PDF\'s lager als de wissel meteen overal zichtbaar moet zijn.',
            (int) round($seconds / DAY_IN_SECONDS)
        );
    }
}
