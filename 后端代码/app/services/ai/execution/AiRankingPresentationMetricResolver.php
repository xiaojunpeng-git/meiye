<?php
namespace app\services\ai\execution;

/**
 * Selects evidence columns for one already-selected ranking metric.
 *
 * The first code remains the sole sorting metric. Extra codes are shown only
 * when the metric registry declares the same analytical object and dimension
 * as an overview-capable fact; they never become hidden ranking queries or
 * replace an explicit customer metric. This makes new registered object
 * metrics available without encoding card/project/product vocabulary here.
 */
final class AiRankingPresentationMetricResolver
{
    public const MAX_METRICS = 4;

    /** A detail request may select only a complete registry-owned profile,
     * never arbitrary extra columns supplied by a model or client. Ordinary
     * rankings retain their existing lightweight presentation. */
    public static function detail(array $metrics, string $primaryMetric, ?string $objectKind): array
    {
        if (!isset($metrics[$primaryMetric])) return [];
        $kind=$objectKind ?: ($metrics[$primaryMetric]['filter_grain']??null);
        $records=[];
        foreach ($metrics as $code=>$metric) {
            // A compiler snapshot contains ready metrics only; raw Reader
            // capabilities additionally carry the explicit readiness flag.
            if (($metric['ai_query_ready']??true)!==true || !in_array($kind,(array)($metric['ranking_detail_object_kinds']??[]),true)) continue;
            $records[]=['code'=>$code,'order'=>self::overviewOrder($metric,(string)$kind)];
        }
        if (!$records) return [];
        usort($records,static function(array $a,array $b): int { return [$a['order'],$a['code']]<=>[$b['order'],$b['code']]; });
        $codes=[$primaryMetric];
        foreach ($records as $record) if (!in_array($record['code'],$codes,true) && count($codes)<self::MAX_METRICS) $codes[]=$record['code'];
        return count($codes)>1?$codes:[];
    }

    /** Both the signed compiler and Reader enforce the same closed profile. */
    public static function expected(array $metrics,string $primaryMetric,?string $objectKind,array $requested): array
    {
        $detail=self::detail($metrics,$primaryMetric,$objectKind);
        return $detail!==[] && $requested===$detail ? $detail : self::resolve($metrics,$primaryMetric,$objectKind);
    }

    /** @return array<int,string> primary sort metric followed by display-only metrics */
    public static function resolve(array $metrics, string $primaryMetric, ?string $objectKind): array
    {
        if (!isset($metrics[$primaryMetric]) || !is_string($objectKind) || $objectKind==='') return [];
        $primaryDimension=self::dimension($metrics[$primaryMetric],$objectKind);
        if ($primaryDimension===null || !self::hasOverview($metrics[$primaryMetric],$objectKind)) return [$primaryMetric];
        $records=[];
        foreach ($metrics as $code=>$metric) {
            if (!is_string($code) || !is_array($metric) || $code===$primaryMetric
                || !in_array('ranking',(array)($metric['query_shapes']??[]),true)
                || self::dimension($metric,$objectKind)!==$primaryDimension
                || !self::hasOverview($metric,$objectKind)) continue;
            // Presentation reads reuse the fact-dimension batch reader. A new
            // strategy is opt-in only after it implements that registered
            // reader contract, rather than falling back to a second formula.
            try {
                $definition=\app\services\query\metric\MetricDefinitionRegistry::get($code);
                if (($definition['reader_strategy']??null)!=='fact_sum') continue;
            } catch (\Throwable $ignored) { continue; }
            $records[]=['code'=>$code,'order'=>self::overviewOrder($metric,$objectKind)];
        }
        usort($records,static function(array $left,array $right): int {
            return [$left['order'],$left['code']] <=> [$right['order'],$right['code']];
        });
        $codes=[$primaryMetric];
        foreach ($records as $record) {
            if (count($codes)>=self::MAX_METRICS) break;
            $codes[]=$record['code'];
        }
        return $codes;
    }

    /** A display metric must address exactly the same reader-owned identity. */
    private static function dimension(array $metric,string $objectKind): ?string
    {
        $matches=[];
        foreach ((array)($metric['analysis_dimension_contracts']??[]) as $contract) {
            if (is_array($contract) && ($contract['object_kind']??null)===$objectKind
                && ($contract['filter_keys']??null)===[] && is_string($contract['dimension']??null)) {
                $matches[]=$contract['dimension'];
            }
        }
        return count($matches)===1?$matches[0]:null;
    }

    private static function hasOverview(array $metric,string $objectKind): bool
    {
        foreach ((array)($metric['overview']??[]) as $record) {
            if (is_array($record) && ($record['object_kind']??null)===$objectKind) return true;
        }
        return false;
    }

    private static function overviewOrder(array $metric,string $objectKind): int
    {
        foreach ((array)($metric['overview']??[]) as $record) {
            if (is_array($record) && ($record['object_kind']??null)===$objectKind && is_int($record['order']??null)) return $record['order'];
        }
        return PHP_INT_MAX;
    }
}
