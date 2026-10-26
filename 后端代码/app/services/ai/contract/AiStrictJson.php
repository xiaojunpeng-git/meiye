<?php

namespace app\services\ai\contract;

/**
 * Bounded JSON boundary for AI contracts, independent of ThinkPHP and secrets.
 * Objects remain stdClass: {} and [] must not become the same hash input.
 * All numerical JSON tokens must be representable PHP integers; money is never
 * accepted from the model by the higher-level plan contract.
 */
final class AiStrictJson
{
    const MAX_BYTES = 65536;
    const MAX_DEPTH = 32;
    const MAX_NODES = 4096;

    private $source;
    private $offset = 0;
    private $nodes = 0;

    private function __construct(string $source)
    {
        $this->source = $source;
    }

    public static function decodeObject(string $source): \stdClass
    {
        if ($source === '' || strlen($source) > self::MAX_BYTES) {
            throw new AiContractException('AI_JSON_SIZE_INVALID');
        }
        $parser = new self($source);
        $value = $parser->value(0);
        $parser->whitespace();
        if ($parser->offset !== strlen($source) || !($value instanceof \stdClass)) {
            throw new AiContractException('AI_JSON_OBJECT_REQUIRED');
        }
        return $value;
    }

    public static function canonicalEncode($value): string
    {
        $nodes = 0;
        $normalized = self::canonicalValue($value, 0, $nodes);
        $encoded = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false || strlen($encoded) > self::MAX_BYTES) {
            throw new AiContractException('AI_JSON_ENCODING_INVALID');
        }
        return $encoded;
    }

    private static function canonicalValue($value, int $depth, int &$nodes)
    {
        if ($depth > self::MAX_DEPTH || ++$nodes > self::MAX_NODES) {
            throw new AiContractException('AI_JSON_COMPLEXITY_EXCEEDED');
        }
        if ($value instanceof \stdClass) {
            $properties = get_object_vars($value);
            ksort($properties, SORT_STRING);
            $object = new \stdClass();
            foreach ($properties as $key => $item) {
                if (strpos((string)$key, "\0") !== false) {
                    throw new AiContractException('AI_JSON_STRING_INVALID');
                }
                $object->{(string)$key} = self::canonicalValue($item, $depth + 1, $nodes);
            }
            return $object;
        }
        if (is_array($value)) {
            if ($value && array_keys($value) !== range(0, count($value) - 1)) {
                throw new AiContractException('AI_JSON_OBJECT_TYPE_REQUIRED');
            }
            $list = [];
            foreach ($value as $item) {
                $list[] = self::canonicalValue($item, $depth + 1, $nodes);
            }
            return $list;
        }
        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return $value;
        }
        throw new AiContractException('AI_JSON_TYPE_INVALID');
    }

    private function value(int $depth)
    {
        if ($depth > self::MAX_DEPTH || ++$this->nodes > self::MAX_NODES) {
            throw new AiContractException('AI_JSON_COMPLEXITY_EXCEEDED');
        }
        $this->whitespace();
        $char = $this->char();
        if ($char === '{') {
            return $this->object($depth);
        }
        if ($char === '[') {
            return $this->listValue($depth);
        }
        if ($char === '"') {
            return $this->stringValue();
        }
        foreach (['true' => true, 'false' => false, 'null' => null] as $literal => $result) {
            if (substr($this->source, $this->offset, strlen($literal)) === $literal) {
                $this->offset += strlen($literal);
                return $result;
            }
        }
        if (preg_match('/\G-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/', $this->source, $match, 0, $this->offset)) {
            $this->offset += strlen($match[0]);
            $number = json_decode($match[0]);
            if (!is_int($number)) {
                throw new AiContractException('AI_JSON_INTEGER_REQUIRED');
            }
            return $number;
        }
        throw new AiContractException('AI_JSON_SYNTAX_INVALID');
    }

    private function object(int $depth): \stdClass
    {
        $this->offset++;
        $object = new \stdClass();
        $seen = [];
        $this->whitespace();
        if ($this->char() === '}') {
            $this->offset++;
            return $object;
        }
        while (true) {
            $this->whitespace();
            if ($this->char() !== '"') {
                throw new AiContractException('AI_JSON_SYNTAX_INVALID');
            }
            $key = $this->stringValue();
            if (strpos($key, "\0") !== false) {
                throw new AiContractException('AI_JSON_STRING_INVALID');
            }
            // Prefix avoids PHP's numeric-string array-key conversion.
            if (isset($seen['key:' . $key])) {
                throw new AiContractException('AI_JSON_DUPLICATE_KEY');
            }
            $seen['key:' . $key] = true;
            $this->whitespace();
            $this->expect(':');
            $object->{$key} = $this->value($depth + 1);
            $this->whitespace();
            if ($this->char() === '}') {
                $this->offset++;
                return $object;
            }
            $this->expect(',');
        }
    }

    private function listValue(int $depth): array
    {
        $this->offset++;
        $list = [];
        $this->whitespace();
        if ($this->char() === ']') {
            $this->offset++;
            return $list;
        }
        while (true) {
            $list[] = $this->value($depth + 1);
            $this->whitespace();
            if ($this->char() === ']') {
                $this->offset++;
                return $list;
            }
            $this->expect(',');
        }
    }

    private function stringValue(): string
    {
        $start = $this->offset++;
        while ($this->offset < strlen($this->source)) {
            $char = $this->source[$this->offset++];
            if ($char === '\\') {
                $this->offset++;
                continue;
            }
            if ($char === '"') {
                $decoded = json_decode(substr($this->source, $start, $this->offset - $start));
                if (!is_string($decoded) || json_last_error() !== JSON_ERROR_NONE) {
                    throw new AiContractException('AI_JSON_STRING_INVALID');
                }
                return $decoded;
            }
        }
        throw new AiContractException('AI_JSON_STRING_INVALID');
    }

    private function char(): string
    {
        return $this->offset < strlen($this->source) ? $this->source[$this->offset] : '';
    }

    private function whitespace(): void
    {
        while ($this->offset < strlen($this->source) && strpos(" \t\r\n", $this->source[$this->offset]) !== false) {
            $this->offset++;
        }
    }

    private function expect(string $char): void
    {
        if ($this->char() !== $char) {
            throw new AiContractException('AI_JSON_SYNTAX_INVALID');
        }
        $this->offset++;
    }
}
