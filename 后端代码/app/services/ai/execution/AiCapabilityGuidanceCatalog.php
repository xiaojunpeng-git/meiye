<?php
namespace app\services\ai\execution;

use app\services\query\metric\AnalysisCapabilityCatalogFactory;

/**
 * Turns the lower-layer, source-owned capability registrations into choices for
 * one already-authorized user.  It deliberately knows neither report pages nor
 * business question names.  The model receives only the resulting identifiers;
 * the compiler independently rechecks the same contracts before execution.
 */
final class AiCapabilityGuidanceCatalog
{
    public static function discover(array $capabilities, string $objectKind, ?string $operation=null): array
    {
        if (!in_array($objectKind, ['store','person'], true)) return [];
        $operations=$operation===null ? ['summary','trend','ranking','comparison'] : [$operation];
        if (array_diff($operations, ['summary','trend','ranking','comparison'])) return [];
        $allowed=array_flip($capabilities['metric_codes']??[]);
        $readiness=$capabilities['metric_readiness']??[];
        $hasReadiness=is_array($readiness) && $readiness!==[];
        $filterKeys=$objectKind==='person' ? ['selection_ref'] : [];
        $role=$objectKind==='person' ? 'allocated_employee' : 'store_total';
        $items=[];
        foreach ($operations as $shape) {
            $found=(AnalysisCapabilityCatalogFactory::make())->discover([
                'metric_codes'=>[], 'object_kind'=>$objectKind, 'operation'=>$shape,
                'filter_keys'=>$filterKeys, 'relation_role'=>$role,
            ], static function(array $binding) use($allowed,$readiness,$hasReadiness,$objectKind,$filterKeys,$shape): bool {
                $code=$binding['metric_code']; $contract=$readiness[$code]??null;
                // Production gateway snapshots always include metric_readiness.
                // This narrow fallback keeps older, compiler-only callers from
                // creating a second candidate list; the compiler still applies
                // its complete readiness contract before any execution.
                if (!$hasReadiness) return isset($allowed[$code]);
                return isset($allowed[$code]) && is_array($contract) && ($contract['ai_query_ready']??false)===true
                    && ($contract['filter_grain']??null)===$objectKind
                    && ($contract['business_filters']??null)===$filterKeys
                    && in_array($shape,$contract['query_shapes']??[],true);
            });
            foreach ($found['items'] as $item) {
                $code=$item['binding']['metric_code'];
                if (!isset($items[$code])) $items[$code]=[
                    'name'=>$item['metric']['name'], 'summary'=>$item['metric']['summary'],
                    'query_shapes'=>[], 'capability_code'=>$item['binding']['capability_code'],
                    'contract_version'=>$item['binding']['contract_version'],
                ];
                $items[$code]['query_shapes'][]=$shape;
            }
        }
        foreach ($items as &$item) { $item['query_shapes']=array_values(array_unique($item['query_shapes'])); sort($item['query_shapes']); }
        unset($item); ksort($items);
        return $items;
    }
}
