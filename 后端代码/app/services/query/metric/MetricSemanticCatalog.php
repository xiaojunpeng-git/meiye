<?php

namespace app\services\query\metric;

/**
 * 指标业务语言只从统一指标注册表与统一指标字典投影。
 *
 * 这里不维护第二份 AI 指标清单，也不保存 SQL、公式或报表字段。字典中的同名词
 * 如果同时指向多个已注册指标，会被标记为歧义而不会静默绑定其中任意一个。
 */
final class MetricSemanticCatalog
{
    /** @return array<string,array<string,mixed>> */
    public static function entries(): array
    {
        $dictionary = new \app\services\metric\MetricDictionaryServices();
        $capabilities = MetricDefinitionRegistry::capabilities();
        $entries = [];
        foreach ($capabilities as $code => $capability) {
            $definition = $dictionary->getByCode($code);
            if (!is_array($definition) || ($definition['user_ready'] ?? false) !== true) {
                continue;
            }
            $terms = array_merge([(string)$definition['name']], (array)($definition['aliases'] ?? []));
            $terms = array_values(array_unique(array_filter(array_map('trim', $terms), static function ($term) {
                return $term !== '';
            })));
            usort($terms, static function ($left, $right) {
                return strlen($right) <=> strlen($left);
            });
            $entries[$code] = [
                'metric_code' => $code,
                'name' => (string)$definition['name'],
                'summary' => (string)($definition['summary'] ?? ''),
                'terms' => $terms,
                'ai_query_ready' => ($capability['ai_query_ready'] ?? false) === true,
                'storage_unit' => $capability['storage_unit'] ?? null,
            ];
        }
        return $entries;
    }

    /** @return array<string,string> metric code => safe generated regex */
    public static function unambiguousPatterns(): array
    {
        $owners = [];
        foreach (self::entries() as $code => $entry) {
            foreach ($entry['terms'] as $term) {
                $owners[$term][] = $code;
            }
        }
        $patterns = [];
        foreach (self::entries() as $code => $entry) {
            $terms = array_values(array_filter($entry['terms'], static function ($term) use ($owners) {
                return count(array_unique($owners[$term])) === 1;
            }));
            if ($terms) {
                $patterns[$code] = '/'.implode('|', array_map(static function ($term) {
                    return preg_quote($term, '/');
                }, $terms)).'/u';
            }
        }
        return $patterns;
    }

    /** @return array<string,string> */
    public static function names(array $codes = []): array
    {
        $entries = self::entries();
        $wanted = $codes ?: array_keys($entries);
        $names = [];
        foreach ($wanted as $code) {
            if (isset($entries[$code])) {
                $names[$code] = $entries[$code]['name'];
            }
        }
        return $names;
    }

    public static function optionLabel(string $code): string
    {
        $entry = self::entries()[$code] ?? null;
        if (!$entry) {
            throw new MetricQueryContractException('METRIC_NOT_REGISTERED', '当前指标尚未注册。');
        }
        return $entry['summary'] !== '' ? $entry['name'].'（'.$entry['summary'].'）' : $entry['name'];
    }

    /**
     * Resolve exact customer metric terms only when the active capability
     * boundary gives every term one and the same registered owner.
     *
     * This is deliberately not fuzzy language parsing: the understanding
     * model has already quoted the customer's measurement words and the
     * registry remains the only authority for their executable meaning.
     * Unknown or ambiguous terms return null and keep the normal controlled
     * clarification path.
     */
    public static function uniqueCodeForTerms(array $terms, array $allowedCodes = []): ?string
    {
        if ($terms === [] || count($terms) > 8) return null;
        $entries = self::entries();
        $allowed = $allowedCodes === []
            ? array_fill_keys(array_keys($entries), true)
            : array_fill_keys(array_values(array_unique(array_filter($allowedCodes, 'is_string'))), true);
        $resolved = null;
        foreach ($terms as $term) {
            if (!is_string($term) || trim($term) === '') return null;
            $term = trim($term);
            $owners = [];
            foreach ($entries as $code => $entry) {
                if (!isset($allowed[$code]) || ($entry['ai_query_ready'] ?? false) !== true) continue;
                if (in_array($term, (array)($entry['terms'] ?? []), true)) $owners[] = $code;
            }
            $owners = array_values(array_unique($owners));
            if (count($owners) !== 1) return null;
            if ($resolved !== null && $resolved !== $owners[0]) return null;
            $resolved = $owners[0];
        }
        return $resolved;
    }

    /**
     * Find one exact registered measurement phrase inside customer text.
     * Several aliases owned by the same metric are harmless; any cross-metric
     * match is ambiguous and therefore returns null.
     *
     * @return array{metric_code:string,term:string}|null
     */
    public static function uniqueTermInText(string $text, array $allowedCodes = []): ?array
    {
        if ($text === '' || preg_match('//u', $text) !== 1) return null;
        $entries = self::entries();
        $allowed = $allowedCodes === []
            ? array_fill_keys(array_keys($entries), true)
            : array_fill_keys(array_values(array_unique(array_filter($allowedCodes, 'is_string'))), true);
        $matches = [];
        $longest = 0;
        foreach ($entries as $code => $entry) {
            if (!isset($allowed[$code]) || ($entry['ai_query_ready'] ?? false) !== true) continue;
            foreach ((array)($entry['terms'] ?? []) as $term) {
                if (!is_string($term) || $term === '' || mb_strpos($text, $term, 0, 'UTF-8') === false) continue;
                $length = mb_strlen($term, 'UTF-8');
                if ($length > $longest) {
                    $matches = [];
                    $longest = $length;
                }
                if ($length === $longest) $matches[$code][$term] = true;
            }
        }
        if (count($matches) !== 1) return null;
        $code = array_key_first($matches);
        $terms = array_keys($matches[$code]);
        usort($terms, static function (string $left, string $right): int {
            return mb_strlen($right, 'UTF-8') <=> mb_strlen($left, 'UTF-8');
        });
        return ['metric_code' => $code, 'term' => $terms[0]];
    }

    /** Remove only registered dictionary terms before checking residual conditions. */
    public static function stripTerms(string $text, array $codes = []): string
    {
        $entries=self::entries();$wanted=$codes?:array_keys($entries);$terms=[];
        foreach($wanted as $code) foreach(($entries[$code]['terms']??[]) as $term) $terms[]=$term;
        $terms=array_values(array_unique($terms));usort($terms,static function($left,$right){return strlen($right)<=>strlen($left);});
        if(!$terms) return $text;
        return (string)preg_replace('/'.implode('|',array_map(static function($term){return preg_quote($term,'/');},$terms)).'/u',' ',$text);
    }
}
