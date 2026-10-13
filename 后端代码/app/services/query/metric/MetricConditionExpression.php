<?php
namespace app\services\query\metric;

/**
 * Bounded Boolean relationship between already registered condition rows.
 * Leaves are zero-based row positions, never model-supplied SQL or metric
 * formulas. Every leaf must occur once so a signed query cannot drop a user
 * condition while presenting a plausible but incomplete answer.
 */
final class MetricConditionExpression
{
    private const MAX_DEPTH = 3;

    public static function valid($expression, int $conditionCount): bool
    {
        if ($conditionCount < 2 || $conditionCount > MetricDefinitionRegistry::MAX_CONDITIONS) return false;
        $seen = [];
        return self::walk($expression, $conditionCount, 1, $seen)
            && count($seen) === $conditionCount;
    }

    /** Evaluate only checked Boolean values from one authorized object. */
    public static function evaluate(array $expression, array $matches): bool
    {
        $values = [];
        foreach ($expression['children'] as $child) {
            $values[] = is_int($child) ? $matches[$child] : self::evaluate($child, $matches);
        }
        return $expression['relation'] === 'all'
            ? !in_array(false, $values, true)
            : in_array(true, $values, true);
    }

    private static function walk($node, int $count, int $depth, array &$seen): bool
    {
        if (!is_array($node) || $depth > self::MAX_DEPTH) return false;
        $keys = array_keys($node); sort($keys, SORT_STRING);
        if ($keys !== ['children', 'relation'] || !in_array($node['relation'], ['all', 'any'], true)
            || !is_array($node['children']) || count($node['children']) < 2
            || count($node['children']) > $count
            || array_keys($node['children']) !== range(0, count($node['children']) - 1)) return false;
        foreach ($node['children'] as $child) {
            if (is_int($child)) {
                if ($child < 0 || $child >= $count || isset($seen[$child])) return false;
                $seen[$child] = true;
            } elseif (!self::walk($child, $count, $depth + 1, $seen)) return false;
        }
        return true;
    }
}
