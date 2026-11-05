<?php

namespace Jelmoo\Planner;

/*
 * Kent de mediabibliotheek: bij welk bestand hoort een URL, en welke kleinere
 * versies horen daarbij. Daaruit volgt welke URL's en ID's mee moeten wisselen.
 */
class Attachments
{
    public static function variants(string $old_url, string $new_url): Variants
    {
        $plan = self::plan($old_url, $new_url);

        return new Variants($old_url, $new_url, home_url('/'), $plan['also'], $plan['refs']);
    }

    /** @return array{old_id:int,new_id:int,also:array<string,string>,refs:?AttachmentRefs} */
    public static function plan(string $old_url, string $new_url): array
    {
        $old_id = self::id_for_url($old_url);
        $new_id = self::id_for_url($new_url);
        $plan = ['old_id' => $old_id, 'new_id' => $new_id, 'also' => [], 'refs' => null];

        if (!$old_id || $old_id === $new_id) {
            return $plan;
        }

        $new_full = $new_id ? (string) wp_get_attachment_url($new_id) : $new_url;
        $new_files = $new_id ? self::files($new_id) : [];

        foreach (self::files($old_id) as $name => $url) {
            $replacement = $new_files[$name] ?? $new_full;
            if ($url !== $old_url && $url !== $replacement) {
                $plan['also'][$url] = $replacement;
            }
        }

        if ($new_id) {
            $plan['refs'] = new AttachmentRefs(
                $old_id,
                $new_id,
                wp_basename((string) wp_get_attachment_url($old_id)),
                wp_basename($new_full)
            );
        }

        return $plan;
    }

    /** ID van het bestand bij deze URL, ook als het een kleinere versie of andere host-vorm is. */
    public static function id_for_url(string $url): int
    {
        $uploads = wp_get_upload_dir();
        $base_path = (string) wp_parse_url($uploads['baseurl'], PHP_URL_PATH);
        $path = (string) wp_parse_url($url, PHP_URL_PATH);

        if (!Runner::same_site($url) || $base_path === '' || strpos($path, $base_path . '/') !== 0) {
            return 0;
        }

        $local = $uploads['baseurl'] . substr($path, strlen($base_path));
        $id = (int) attachment_url_to_postid($local);

        if (!$id) {
            $id = (int) attachment_url_to_postid((string) preg_replace('/-\d+x\d+(\.[A-Za-z0-9]+)$/', '$1', $local));
        }

        if (!$id) {
            // De originele versie van een afbeelding die WordPress heeft verkleind.
            $id = (int) attachment_url_to_postid((string) preg_replace('/(\.[A-Za-z0-9]+)$/', '-scaled$1', $local));
        }

        return $id;
    }

    /** @return array<string,string> soort versie => URL */
    private static function files(int $id): array
    {
        $full = (string) wp_get_attachment_url($id);
        if ($full === '') {
            return [];
        }

        $files = ['full' => $full];
        $meta = wp_get_attachment_metadata($id);
        $dir = dirname($full) . '/';

        if (is_array($meta)) {
            foreach ((array) ($meta['sizes'] ?? []) as $name => $size) {
                if (!empty($size['file'])) {
                    $files[(string) $name] = $dir . $size['file'];
                }
            }

            if (!empty($meta['original_image'])) {
                $files['original_image'] = $dir . $meta['original_image'];
            }
        }

        return $files;
    }
}
