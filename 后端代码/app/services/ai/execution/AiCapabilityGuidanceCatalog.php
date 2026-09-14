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
        $operations=$operation===null ? ['summary','trend','ranking','comparison'] : [$operation];
        if (array_diff($operations, ['summary','trend','ranking','comparison'])) return [];
        $allowed=array_flip($capabilities['metric_codes']??[]);
        $readiness=$capabilities['metric_readiness']??[];
        $hasReadiness=is_array($readiness) && $readiness!==[];
        $filterKeys=$objectKind==='person' ? ['selection_ref'] : [];
        $role=$objectKind==='person'?'allocated_employee':($objectKind==='store'?'store_total':null);
        $items=[];
        // One customer object may participate in several registered relations
        // (for example, a project can be sold and also consumed).  Discover
        // every declared relation and let the later metric choice distinguish
        // their business meaning; treating that as an unknown relation would
        // hide valid registered capabilities.
        $roles=$role===null?self::relationRoles($readiness,$objectKind):[$role];
        foreach ($operations as $shape) foreach ($roles as $relationRole) {
            $found=(AnalysisCapabilityCatalogFactory::make())->discover([
                'metric_codes'=>[], 'object_kind'=>$objectKind, 'operation'=>$shape,
                'filter_keys'=>$filterKeys, 'relation_role'=>$relationRole,
            ], static function(array $binding) use($allowed,$readiness,$hasReadiness,$objectKind,$filterKeys,$shape): bool {
                $code=$binding['metric_code']; $contract=$readiness[$code]??null;
                // Production gateway snapshots always include metric_readiness.
                // This narrow fallback keeps older, compiler-only callers from
                // creating a second candidate list; the compiler still applies
                // its complete readiness contract before any execution.
                if (!$hasReadiness) return isset($allowed[$code]);
                $dimensionReady=($contract['filter_grain']??null)===$objectKind && ($contract['business_filters']??null)===$filterKeys;
                foreach ((array)($contract['analysis_dimension_contracts']??[]) as $dimension) {
                    if (is_array($dimension) && ($dimension['object_kind']??null)===$objectKind
                        && ($dimension['filter_keys']??null)===$filterKeys) $dimensionReady=true;
                }
                return isset($allowed[$code]) && is_array($contract) && ($contract['ai_query_ready']??false)===true
                    && $dimensionReady
                    && in_array($shape,$contract['query_shapes']??[],true);
            });
            foreach ($found['items'] as $item) {
                $code=$item['binding']['metric_code'];
                if (!isset($items[$code])) $items[$code]=[
                    'name'=>$item['metric']['name'], 'summary'=>$item['metric']['summary'],
                    'query_shapes'=>[], 'capability_code'=>$item['binding']['capability_code'],
                    'contract_version'=>$item['binding']['contract_version'],
                    'action_codes'=>[],
                    // A default cohort, when registered by the fact contract,
                    // is a data-grain property. It is never derived from the
                    // customer wording or a page/terminal.
                    'default_selection_ref'=>$readiness[$code]['analysis_default_selection_ref']??null,
                ];
                $items[$code]['query_shapes'][]=$shape;
                foreach ((array)($readiness[$code]['analysis_dimension_contracts']??[]) as $dimension) {
                    if (!is_array($dimension) || ($dimension['object_kind']??null)!==$objectKind
                        || ($dimension['filter_keys']??null)!==$filterKeys) continue;
                    foreach ((array)($dimension['action_codes']??[]) as $action) if (is_string($action)) $items[$code]['action_codes'][$action]=true;
                }
            }
        }
        foreach ($items as &$item) {
            $item['query_shapes']=array_values(array_unique($item['query_shapes'])); sort($item['query_shapes']);
            $item['action_codes']=array_keys($item['action_codes']); sort($item['action_codes']);
        }
        unset($item); ksort($items);
        return $items;
    }

    /** @return array<int,string> */
    private static function relationRoles(array $readiness,string $objectKind): array
    {
        $roles=[];
        foreach ($readiness as $contract) foreach ((array)($contract['analysis_dimension_contracts']??[]) as $dimension) {
            if (is_array($dimension) && ($dimension['object_kind']??null)===$objectKind && is_string($dimension['relation_role']??null)) $roles[$dimension['relation_role']]=true;
        }
        $roles=array_keys($roles);sort($roles,SORT_STRING);
        return $roles;
    }
}
