<?php

namespace Jelmoo\Planner;

/*
 * Vervangt de URL in één databasewaarde en bewaakt dat de waarde geldig blijft.
 * Serialized data wordt byte voor byte gelezen en nooit met classes ge-unserialized,
 * zodat er geen objecten van klantdata worden aangemaakt. Bij twijfel blijft de
 * waarde ongemoeid en krijgt de beheerder een reden te zien.
 */
class Rewriter
{
    public const UNCHANGED = 'unchanged';
    public const CHANGED = 'changed';
    public const SKIPPED = 'skipped';
    public const SUSPECT = 'suspect';

    private const MAX_DEPTH = 64;

    /** @var Variants */
    private $variants;

    /** @var int */
    private $count = 0;

    public function __construct(Variants $variants)
    {
        $this->variants = $variants;
    }

    /** @return array{status:string,value:string,count:int,reason:string} */
    public function rewrite(string $raw): array
    {
        if (!$this->concerns($raw)) {
            return $this->no_match($raw);
        }

        // Oxygen ondertekent shortcodes; een wijziging daarin zet de code uit.
        if (strpos($raw, 'ct_sign_') !== false) {
            return $this->result(self::SKIPPED, $raw, 0, 'Ondertekende code (Oxygen): pas deze link in de builder aan.');
        }

        $this->count = 0;

        try {
            $value = $this->rewrite_string($raw, 0);
        } catch (SkipRow $skip) {
            return $this->result(self::SKIPPED, $raw, 0, $skip->getMessage());
        }

        if ($value === $raw) {
            return $this->no_match($raw);
        }

        return $this->result(self::CHANGED, $value, $this->count, '');
    }

    /** De URL, of bij een bestand uit de mediabibliotheek alleen de bestandsnaam naast een ID. */
    private function concerns(string $value): bool
    {
        if ($this->variants->matches($value)) {
            return true;
        }

        $attachment = $this->variants->attachment();

        return $attachment !== null && $attachment->mentions($value);
    }

    private function no_match(string $raw): array
    {
        $status = $this->variants->is_suspect($raw) ? self::SUSPECT : self::UNCHANGED;
        $reason = $status === self::SUSPECT ? 'Bestandsnaam gevonden in een vorm die de plugin niet herkent.' : '';

        return $this->result($status, $raw, 0, $reason);
    }

    private function rewrite_string(string $value, int $depth): string
    {
        if ($depth > self::MAX_DEPTH) {
            throw new SkipRow('Te diep geneste data.');
        }

        if (self::looks_serialized($value)) {
            if (!$this->concerns($value)) {
                return $value;
            }

            $parser = new SerializedParser($value, function ($inner, $inner_depth) use ($depth) {
                return $this->rewrite_string($inner, $depth + $inner_depth + 1);
            }, $this->variants);
            $new = $parser->rewrite();
            $this->count += $parser->attachment_count();

            if ($new !== $value && @unserialize($new, ['allowed_classes' => false]) === false && $new !== 'b:0;') {
                throw new SkipRow('Serialized data zou ongeldig worden.');
            }

            return $new;
        }

        if (!$this->concerns($value)) {
            return $value;
        }

        $decoded = null;
        $is_json = false;
        $trimmed = ltrim($value);
        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            $decoded = json_decode($value, true);
            $is_json = json_last_error() === JSON_ERROR_NONE;
        }

        if ($is_json && $this->has_signed_match($decoded)) {
            throw new SkipRow('Ondertekende code: pas deze link in de builder aan.');
        }

        $attachment = $this->variants->attachment();
        if ($attachment !== null) {
            list($value, $ids) = $attachment->rewrite_text($value, $this->variants);
            $this->count += $ids;
        }

        list($new, $count) = $this->variants->replace($value);

        if ($is_json) {
            json_decode($new, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new SkipRow('JSON zou ongeldig worden.');
            }
        }

        $this->count += $count;

        return $new;
    }

    /** Staat de treffer in een array naast een sleutel "signature" (Bricks-code)? */
    private function has_signed_match($node): bool
    {
        if (!is_array($node)) {
            return false;
        }

        if (isset($node['signature']) && $this->tree_matches($node)) {
            return true;
        }

        foreach ($node as $child) {
            if ($this->has_signed_match($child)) {
                return true;
            }
        }

        return false;
    }

    private function tree_matches($node): bool
    {
        if (is_string($node)) {
            return $this->variants->matches($node);
        }

        if (is_array($node)) {
            foreach ($node as $child) {
                if ($this->tree_matches($child)) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function looks_serialized(string $value): bool
    {
        return preg_match('/^(?:a:\d+:\{|O:\d+:"|s:\d+:"|C:\d+:"|E:\d+:"|S:\d+:"|[bid]:[^;]+;|N;)/', $value) === 1;
    }

    private function result(string $status, string $value, int $count, string $reason): array
    {
        return ['status' => $status, 'value' => $value, 'count' => $count, 'reason' => $reason];
    }
}

/** De rij blijft ongewijzigd; de boodschap komt in het rapport. */
class SkipRow extends \RuntimeException
{
}

/*
 * Recursive-descent parser over de bytes van een serialized waarde. Alleen
 * string-waarden worden herschreven, sleutels en classnamen nooit. Onbekende
 * tokens betekenen: overslaan.
 */
class SerializedParser
{
    /** @var string */
    private $data;

    /** @var int */
    private $pos = 0;

    /** @var int */
    private $length;

    /** @var callable */
    private $on_string;

    /** @var Variants */
    private $variants;

    /** @var int */
    private $depth = 0;

    /** @var int */
    private $attachment_count = 0;

    public function __construct(string $data, callable $on_string, Variants $variants)
    {
        $this->data = $data;
        $this->length = strlen($data);
        $this->on_string = $on_string;
        $this->variants = $variants;
    }

    public function attachment_count(): int
    {
        return $this->attachment_count;
    }

    public function rewrite(): string
    {
        $out = $this->value();

        if ($this->pos !== $this->length) {
            throw new SkipRow('Kapotte serialized data (tekst na het einde).');
        }

        return $out;
    }

    private function value(): string
    {
        $type = $this->data[$this->pos] ?? '';

        switch ($type) {
            case 'N':
                return $this->expect('N;');
            case 'b':
            case 'i':
            case 'd':
            case 'r':
            case 'R':
                return $this->scalar();
            case 's':
                return $this->string_value();
            case 'a':
                return $this->array_value();
            case 'O':
                return $this->object_value();
            case 'E':
                return $this->enum_value();
            case 'C':
                return $this->custom_object();
            case 'S':
                throw new SkipRow('Serialized data met escaped strings (S:) wordt niet herschreven.');
            default:
                throw new SkipRow('Onbekend token in serialized data.');
        }
    }

    private function expect(string $text): string
    {
        if (substr($this->data, $this->pos, strlen($text)) !== $text) {
            throw new SkipRow('Kapotte serialized data.');
        }

        $this->pos += strlen($text);

        return $text;
    }

    private function scalar(): string
    {
        $end = strpos($this->data, ';', $this->pos);
        if ($end === false) {
            throw new SkipRow('Kapotte serialized data.');
        }

        $token = substr($this->data, $this->pos, $end - $this->pos + 1);
        if (!preg_match('/^(?:b:[01];|[irR]:-?\d+;|d:(?:-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?|-?INF|NAN);)$/', $token)) {
            throw new SkipRow('Kapotte serialized data.');
        }

        $this->pos = $end + 1;

        return $token;
    }

    private function read_int(string $prefix): int
    {
        $this->expect($prefix);
        $end = strpos($this->data, ':', $this->pos);
        if ($end === false) {
            throw new SkipRow('Kapotte serialized data.');
        }

        $digits = substr($this->data, $this->pos, $end - $this->pos);
        if (!ctype_digit($digits)) {
            throw new SkipRow('Kapotte serialized data.');
        }

        $this->pos = $end + 1;

        return (int) $digits;
    }

    /** Leest de bytes van "...": op basis van de lengte, dus inhoud kan alles zijn. */
    private function quoted(int $length): string
    {
        $this->expect('"');
        if ($this->pos + $length > $this->length) {
            throw new SkipRow('Kapotte serialized data (string te kort).');
        }

        $bytes = substr($this->data, $this->pos, $length);
        $this->pos += $length;
        $this->expect('"');

        return $bytes;
    }

    private function string_value(): string
    {
        $bytes = $this->quoted($this->read_int('s:'));
        $this->expect(';');

        $new = ($this->on_string)($bytes, $this->depth);

        return 's:' . strlen($new) . ':"' . $new . '";';
    }

    /** Sleutels blijven exact wat ze waren. */
    private function key(): array
    {
        $start = $this->pos;
        $type = $this->data[$this->pos] ?? '';
        $name = null;

        if ($type === 's') {
            $name = $this->quoted($this->read_int('s:'));
            $this->expect(';');
        } elseif ($type === 'i') {
            $this->scalar();
        } else {
            throw new SkipRow('Kapotte serialized data (ongeldige sleutel).');
        }

        return [substr($this->data, $start, $this->pos - $start), $name];
    }

    private function pairs(int $count): string
    {
        $this->expect('{');
        $this->depth++;

        $entries = [];
        $has_signature = false;

        for ($i = 0; $i < $count; $i++) {
            list($key, $name) = $this->key();
            $has_signature = $has_signature || $name === 'signature';

            $start = $this->pos;
            $value = $this->value();

            $entries[] = ['key' => $key, 'name' => $name, 'original' => substr($this->data, $start, $this->pos - $start), 'value' => $value];
        }

        $this->depth--;
        $this->expect('}');

        $attachment = $this->variants->attachment();
        if ($attachment !== null) {
            $swap = $attachment->swap_entries($entries);
            $entries = $swap['entries'];
            $this->attachment_count += $swap['count'];
        }

        $out = '';
        $changed = false;
        foreach ($entries as $entry) {
            $out .= $entry['key'] . $entry['value'];
            $changed = $changed || $entry['value'] !== $entry['original'];
        }

        // Bricks controleert de signature tegen de code; die mag niet veranderen.
        if ($has_signature && $changed) {
            throw new SkipRow('Ondertekende code (Bricks): pas deze link in de builder aan.');
        }

        return '{' . $out . '}';
    }

    private function array_value(): string
    {
        $count = $this->read_int('a:');

        return 'a:' . $count . ':' . $this->pairs($count);
    }

    private function object_value(): string
    {
        $class_length = $this->read_int('O:');
        $class = $this->quoted($class_length);
        $this->expect(':');
        $end = strpos($this->data, ':', $this->pos);
        if ($end === false) {
            throw new SkipRow('Kapotte serialized data.');
        }

        $digits = substr($this->data, $this->pos, $end - $this->pos);
        if (!ctype_digit($digits)) {
            throw new SkipRow('Kapotte serialized data.');
        }

        $this->pos = $end + 1;
        $count = (int) $digits;

        return 'O:' . $class_length . ':"' . $class . '":' . $count . ':' . $this->pairs($count);
    }

    private function enum_value(): string
    {
        $start = $this->pos;
        $this->quoted($this->read_int('E:'));
        $this->expect(';');

        return substr($this->data, $start, $this->pos - $start);
    }

    /** Eigen serialize-formaat van een class: niet te herschrijven, wel over te slaan. */
    private function custom_object(): string
    {
        $start = $this->pos;
        $this->quoted($this->read_int('C:'));
        $this->expect(':');
        $end = strpos($this->data, ':', $this->pos);
        if ($end === false) {
            throw new SkipRow('Kapotte serialized data.');
        }

        $digits = substr($this->data, $this->pos, $end - $this->pos);
        if (!ctype_digit($digits)) {
            throw new SkipRow('Kapotte serialized data.');
        }

        $this->pos = $end + 1;
        $length = (int) $digits;
        $this->expect('{');
        if ($this->pos + $length > $this->length) {
            throw new SkipRow('Kapotte serialized data.');
        }

        $payload = substr($this->data, $this->pos, $length);
        if ($this->variants->matches($payload)) {
            throw new SkipRow('De link staat in een eigen objectformaat (C:) dat niet veilig te herschrijven is.');
        }

        $this->pos += $length;
        $this->expect('}');

        return substr($this->data, $start, $this->pos - $start);
    }
}
