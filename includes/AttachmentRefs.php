<?php

namespace Jelmoo\Planner;

/*
 * Builders verwijzen vaak via een bestands-ID naar de mediabibliotheek en renderen
 * daarmee, ook als er een URL naast staat (Bricks: {id, filename, url}). Alleen de
 * URL wisselen laat dan het oude bestand staan. Een getal is geen bewijs: het ID
 * wisselt alleen als er in hetzelfde object ook de URL of bestandsnaam van het oude
 * bestand staat. Geen WordPress nodig, zodat dit los te testen is.
 */
class AttachmentRefs
{
    private const ID_KEYS = ['id', 'ID', 'attachment_id', 'attachmentId', 'image_id', 'imageId', 'media_id', 'mediaId'];

    // Blokken van WordPress zelf, waarvan de URL in de HTML onder de blok-opmerking staat.
    private const CORE_BLOCKS = 'image|cover|file|media-text|audio|video';

    /** @var int */
    private $old_id;

    /** @var int */
    private $new_id;

    /** @var string */
    private $old_file;

    /** @var string */
    private $new_file;

    public function __construct(int $old_id, int $new_id, string $old_file, string $new_file)
    {
        $this->old_id = $old_id;
        $this->new_id = $new_id;
        $this->old_file = $old_file;
        $this->new_file = $new_file;
    }

    /** Kan deze tekst een verwijzing zonder volledige URL bevatten? */
    public function mentions(string $text): bool
    {
        return strpos($text, $this->old_file) !== false;
    }

    public function is_id_key(string $name): bool
    {
        return in_array($name, self::ID_KEYS, true);
    }

    public function is_old_id_token(string $token): bool
    {
        $id = (string) $this->old_id;

        return $token === 'i:' . $id . ';' || $token === 's:' . strlen($id) . ':"' . $id . '";';
    }

    public function new_id_token(string $token): string
    {
        $id = (string) $this->new_id;

        return $token[0] === 'i' ? 'i:' . $id . ';' : 's:' . strlen($id) . ':"' . $id . '";';
    }

    public function is_old_file_token(string $token): bool
    {
        return $token === 's:' . strlen($this->old_file) . ':"' . $this->old_file . '";';
    }

    public function new_file_token(): string
    {
        return 's:' . strlen($this->new_file) . ':"' . $this->new_file . '";';
    }

    /**
     * Sleutels en waarden van één serialized array of object. Geeft de waarden terug,
     * met het ID en de bestandsnaam vervangen als het object het oude bestand is.
     *
     * @param array<int,array{name:?string,original:string,value:string}> $entries
     * @return array{entries:array,count:int}
     */
    public function swap_entries(array $entries): array
    {
        $names = [];
        $evidence = false;

        foreach ($entries as $entry) {
            if ($entry['name'] !== null) {
                $names[$entry['name']] = true;
            }

            // Alleen tekst in dit object zelf telt, niet iets dieper in een kind.
            if ($entry['original'][0] === 's' && ($entry['value'] !== $entry['original'] || $this->is_old_file_token($entry['original']))) {
                $evidence = true;
            }
        }

        if (!$evidence) {
            return ['entries' => $entries, 'count' => 0];
        }

        $count = 0;
        foreach ($entries as $index => $entry) {
            $name = $entry['name'];
            // Beaver Builder: photo = ID, photo_src = URL.
            $is_id = $name !== null && ($this->is_id_key($name) || isset($names[$name . '_src']));

            if ($is_id && $this->is_old_id_token($entry['original'])) {
                $entries[$index]['value'] = $this->new_id_token($entry['original']);
                $count++;
            }
        }

        if ($count > 0) {
            foreach ($entries as $index => $entry) {
                if ($this->is_old_file_token($entry['original'])) {
                    $entries[$index]['value'] = $this->new_file_token();
                }
            }
        }

        return ['entries' => $entries, 'count' => $count];
    }

    /**
     * Zelfde vervanging in JSON-tekst (Elementor) en blokken van WordPress. Draait
     * vóór het vervangen van de URL, want die URL is het bewijs.
     *
     * @return array{0:string,1:int} nieuwe tekst en aantal vervangen ID's
     */
    public function rewrite_text(string $text, Variants $variants): array
    {
        $id = (string) $this->old_id;
        if (strpos($text, $id) === false) {
            return [$text, 0];
        }

        $count = 0;
        $has_url = $variants->matches($text);

        $new = preg_replace_callback('~\{[^{}]*\}~', function ($match) use ($variants, &$count) {
            return $this->rewrite_flat_object($match[0], $variants, $count);
        }, $text);

        if ($new === null) {
            throw new SkipRow('Deze plek is te groot om veilig op bestands-ID te doorzoeken.');
        }

        if ($has_url) {
            $new = preg_replace_callback('~<!--\s+wp:(?:' . self::CORE_BLOCKS . ')\s+\{.*?\}\s+/?-->~s', function ($match) use (&$count) {
                $swapped = 0;
                $comment = preg_replace('~("(?:id|mediaId)"\s*:\s*)' . $this->old_id . '(?![0-9])~', '${1}' . $this->new_id, $match[0], -1, $swapped);
                $count += $swapped;

                return $comment;
            }, (string) $new);

            $swapped = 0;
            $new = preg_replace('~\bwp-image-' . $this->old_id . '(?![0-9])~', 'wp-image-' . $this->new_id, (string) $new, -1, $swapped);
            $count += $swapped;
        }

        return [(string) $new, $count];
    }

    private function rewrite_flat_object(string $object, Variants $variants, int &$count): string
    {
        $quote = '(\\\\*")';
        $file = preg_quote($this->old_file, '~');
        $file_regex = '~(' . $quote . 'filename\\\\*"\s*:\s*\\\\*")' . $file . '(\\\\*")~';

        if (!$variants->matches($object) && preg_match($file_regex, $object) !== 1) {
            return $object;
        }

        $keys = implode('|', self::ID_KEYS);
        $swapped = 0;
        $object = (string) preg_replace_callback(
            '~(\\\\*"(?:' . $keys . ')\\\\*"\s*:\s*\\\\*"?)' . $this->old_id . '(?![0-9])~',
            function ($match) {
                return $match[1] . $this->new_id;
            },
            $object,
            -1,
            $swapped
        );

        if ($swapped === 0) {
            return $object;
        }

        $count += $swapped;

        return (string) preg_replace_callback($file_regex, function ($match) {
            return $match[1] . $this->new_file . $match[3];
        }, $object);
    }
}
