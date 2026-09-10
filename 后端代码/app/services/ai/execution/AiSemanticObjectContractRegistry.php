<?php
namespace app\services\ai\execution;

/**
 * Maps the stable contract references published by a Skill to source-owned
 * executable bindings.  The gateway never infers readiness from an object
 * label; a future domain becomes executable only after registering a binding
 * here and its lower-layer query/permission contract.  Dimension contracts
 * use the `metric_dimension_<object>_v1` convention so new registered
 * objects do not require another object-name allowlist in AI code.
 */
final class AiSemanticObjectContractRegistry
{
    private const BINDINGS = [
        'metric_read_view_store_v1' => ['query_object_kind'=>'store'],
        'metric_read_view_person_v1' => ['query_object_kind'=>'person'],
        // Membership is intentionally a registered analysis dimension, not a
        // privacy-side fallback or a direct member-table query.
        'metric_read_view_member_v1' => ['query_object_kind'=>'member'],
    ];

    private const MISSING_REASONS = [
        'object_project_v1'=>'AI_PROJECT_OBJECT_NOT_READY',
        'object_product_v1'=>'AI_PRODUCT_OBJECT_NOT_READY',
        'object_category_v1'=>'AI_CATEGORY_OBJECT_NOT_READY',
        'object_partner_v1'=>'AI_PARTNER_OBJECT_NOT_READY',
        'object_inventory_v1'=>'AI_INVENTORY_OBJECT_NOT_READY',
    ];

    /** @return array<string,array{state:string,contract_ref:string,reason:?string,query_object_kind:?string}> */
    public static function statuses(array $projection, ?array $capabilities=null): array
    {
        $out=[];
        foreach (($projection['objects']??[]) as $object) {
            if (!is_array($object) || !is_string($object['code']??null) || !is_string($object['contract_ref']??null)) throw new \RuntimeException('AI_RUNTIME_SKILL_INVALID');
            $binding=self::BINDINGS[$object['contract_ref']]??self::dimensionBinding($object,$capabilities);
            $out[$object['code']]=[
                'state'=>$binding===null?'missing':'registered', 'contract_ref'=>$object['contract_ref'],
                'reason'=>$binding===null?(self::MISSING_REASONS[$object['contract_ref']]??'AI_OBJECT_CONTRACT_NOT_READY'):null,
                'query_object_kind'=>$binding['query_object_kind']??null,
            ];
        }
        return $out;
    }

    /** Returns only publicly recognized objects that lack a source-registered contract. */
    public static function missingObjects(array $projection,array $recognizedTerms,?array $capabilities=null): array
    {
        self::requireGatewayRule($projection,'contract_missing','GUIDE_OR_EXPLAIN');
        $statuses=self::statuses($projection,$capabilities);$out=[];
        foreach ($recognizedTerms as $term) {
            if (!is_array($term) || ($term['kind']??null)!=='object' || !is_string($term['code']??null)) continue;
            $status=$statuses[$term['code']]??null;
            if (($status['state']??null)!=='missing') continue;
            $out[$term['code']]=$term+['contract_ref'=>$status['contract_ref'],'missing_reason'=>$status['reason']];
        }
        return array_values($out);
    }

    public static function requireGatewayRule(array $projection,string $case,string $action): void
    {
        foreach (($projection['gateway']['rules']??[]) as $rule) {
            if (is_array($rule) && ($rule['case']??null)===$case && ($rule['action']??null)===$action) return;
        }
        throw new \RuntimeException('AI_RUNTIME_SKILL_INVALID');
    }

    private static function dimensionBinding(array $object, ?array $capabilities): ?array
    {
        $kind=$object['code']??null; $ref=$object['contract_ref']??null;
        if (!is_string($kind)||!is_string($ref)||$ref!==('metric_dimension_'.$kind.'_v1')||!is_array($capabilities)) return null;
        foreach ((array)($capabilities['metric_readiness']??[]) as $contract) {
            if (!is_array($contract)||($contract['ai_query_ready']??false)!==true) continue;
            foreach ((array)($contract['analysis_dimension_contracts']??[]) as $dimension) {
                if (is_array($dimension)&&($dimension['object_kind']??null)===$kind
                    && is_string($dimension['dimension']??null)&&is_string($dimension['relation_role']??null)
                    && in_array('ranking',$contract['query_shapes']??[],true)) return ['query_object_kind'=>$kind];
            }
        }
        return null;
    }
}
