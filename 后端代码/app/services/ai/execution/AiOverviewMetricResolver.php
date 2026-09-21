<?php

namespace app\services\ai\execution;

/**
 * Expands an already-admitted open overview into source-owned registered
 * metrics. It contains no question terms, display names, formulas, or
 * permission decisions; those remain in the semantic layer, dictionary and
 * Reader respectively.
 */
final class AiOverviewMetricResolver
{
    public const MAX_METRICS = 12;

    /** @return array<int,array{metric_code:string,section:string,order:int}> */
    public static function resolve(array $capabilities, string $objectKind): array
    {
        $items=[];
        foreach ((array)($capabilities['metric_readiness'] ?? []) as $code=>$contract) {
            if (!is_string($code) || !is_array($contract) || ($contract['ai_query_ready'] ?? false)!==true
                || !in_array('summary',(array)($contract['query_shapes'] ?? []),true)) continue;
            foreach ((array)($contract['overview'] ?? []) as $overview) {
                if (!is_array($overview) || ($overview['object_kind'] ?? null)!==$objectKind) continue;
                if (!self::supportsObjectSummary($contract,$objectKind)) continue;
                $items[]=['metric_code'=>$code,'section'=>(string)$overview['section'],'order'=>(int)$overview['order']];
            }
        }
        usort($items,static function(array $left,array $right): int {
            return [$left['section'],$left['order'],$left['metric_code']] <=> [$right['section'],$right['order'],$right['metric_code']];
        });
        if (count($items)>self::MAX_METRICS) {
            throw new \RuntimeException('AI_OVERVIEW_CAPACITY_EXCEEDED');
        }
        return $items;
    }

    /**
     * Project the registered overview group names owned by one metric.
     *
     * These names are language guidance only. They grant neither a metric nor
     * a query: understanding and binding still preserve the complete customer
     * request, and the normal Reader contract remains authoritative.
     *
     * @return array<int,string>
     */
    public static function sectionNamesForMetric(array $contract): array
    {
        $sections=[];
        foreach ((array)($contract['overview'] ?? []) as $entry) {
            $section=is_array($entry) ? ($entry['section'] ?? null) : null;
            if (is_string($section) && trim($section)!=='') $sections[trim($section)]=true;
        }
        $sections=array_keys($sections);
        sort($sections,SORT_STRING);
        return $sections;
    }

    private static function supportsObjectSummary(array $contract,string $objectKind): bool
    {
        if ($objectKind==='store') return ($contract['filter_grain'] ?? null)==='store' && ($contract['business_filters'] ?? null)===[];
        if (($contract['filter_grain'] ?? null)!=='store' || ($contract['business_filters'] ?? null)!==[]) return false;
        foreach ((array)($contract['analysis_dimension_contracts'] ?? []) as $dimension) {
            if (is_array($dimension) && ($dimension['object_kind'] ?? null)===$objectKind && ($dimension['filter_keys'] ?? null)===[]) return true;
        }
        return false;
    }
}
