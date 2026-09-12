<?php

namespace app\services\query\metric;

use app\services\query\UnifiedQueryJson;

/** Report-owned immutable projection. Replays only its exact query, never a fresh read
 * disguised as the old snapshot. Production AI discovery remains separately gated by
 * catalog mapping, report parity and performance acceptance.
 */
final class MetricReadViewServices
{
    const CONTRACT_VERSION = 'group-performance-summary-read-v1';
    const COVERAGE_START = MetricDefinitionRegistry::COVERAGE_START;
    private $store;
    private $transaction;
    private $authorize;
    private $clock;
    private $personnel;

    /** Implemented shared contracts, not a grant of entry/report access or instance deployment readiness. */
    public static function metricCapabilities(): array
    {
        return MetricDefinitionRegistry::capabilities();
    }

    /** authorize must rebuild report permissions from the authenticated principal on EVERY invocation. */
    public function __construct(MetricReadViewStore $store, callable $authorize, ?callable $transaction = null, ?callable $clock = null, ?PersonnelAnalysisObjectServices $personnel = null)
    {
        $this->store = $store;
        $this->authorize = $authorize;
        $this->transaction = $transaction ?: [new MetricReadTransaction(), 'run'];
        $this->clock = $clock ?: static function (): int { return time(); };
        $this->personnel = $personnel;
    }

    public function create(array $principal, array $query, ?int $expiresAt = null): array
    {
        $normalized = $this->query($query);
        $binding = $this->binding(call_user_func($this->authorize, $principal), $normalized['store_ids']);
        $personnel = $this->personnelSelection($normalized,$binding);
        $dimensionRanking = $this->dimensionRanking($normalized);
        $now = $this->now();
        $expiresAt=$expiresAt??($now+86400);
        if ($expiresAt<=$now || $expiresAt>$now+86400) $this->fail('METRIC_READ_EXPIRY_INVALID');
        $ranges = ['current' => ['start' => $normalized['start_date'], 'end' => $normalized['end_date']]];
        if ($normalized['compare_range'] !== null) $ranges['comparison'] = $normalized['compare_range'];
        $results = call_user_func($this->transaction, function (GroupPerformanceMetricReadServices $reader) use ($normalized, $binding, $ranges,$personnel,$dimensionRanking,$now): array {
            $results = [];
            $storeNames = $normalized['query_shape'] === 'ranking' && $personnel===null && $dimensionRanking===null ? $reader->storeNames($binding['store_ids']) : [];
            foreach ($ranges as $period => $range) {
                foreach ($normalized['metric_codes'] as $metric) {
                    $metricContract = MetricDefinitionRegistry::get($metric);
                    if ($personnel!==null) {
                        $points=$personnel['pairs']?$reader->personnelTotals($binding['tenant_id'],$binding['store_ids'],$range,$metric,$personnel['pairs']):[];
                        $totals=[];$sum=0;
                        foreach ($points as $point) {
                            $sum=$this->addAmount($sum,$point['amount_cents']);
                            $employee=$point['employee_id'];$totals[$employee]=$this->addAmount($totals[$employee]??0,$point['amount_cents']);
                        }
                        if ($normalized['query_shape']==='summary') $results[]=['period'=>$period,'metric_code'=>$metric,'amount_cents'=>$sum,'storage_unit'=>$metricContract['storage_unit']];
                        else {
                            $rows=[];
                            foreach ($totals as $employee=>$amount) {
                                if (!isset($personnel['names'][$employee])) $this->fail('METRIC_SOURCE_RESULT_INVALID');
                                $rows[]=['employee_id'=>$employee,'employee_name'=>$personnel['names'][$employee],'amount_cents'=>$amount];
                            }
                            $rank=$normalized['ranking'];$directions=$rank['direction']==='top_and_bottom'?['top','bottom']:[$rank['direction']];$groups=[];
                            foreach ($directions as $direction) {
                                $sorted=$rows;usort($sorted,static function($a,$b)use($direction){return ($direction==='top'?($b['amount_cents']<=>$a['amount_cents']):($a['amount_cents']<=>$b['amount_cents']))?:($a['employee_id']<=>$b['employee_id']);});
                                $groups[$direction]=array_slice($sorted,0,$rank['limit']);
                            }
                            $results[]=['period'=>$period,'metric_code'=>$metric,'storage_unit'=>$metricContract['storage_unit'],'rows'=>$groups];
                        }
                        continue;
                    }
                    if ($dimensionRanking!==null) {
                        $groups = [];
                        $directions = $normalized['ranking']['direction'] === 'top_and_bottom' ? ['top', 'bottom'] : [$normalized['ranking']['direction']];
                        foreach ($directions as $direction) {
                            $points = $reader->dimensionRanking($metric, $dimensionRanking['dimension'], $binding['tenant_id'], $binding['store_ids'], $range, $normalized['ranking']['limit'], $direction === 'top' ? 'desc' : 'asc');
                            $groups[$direction] = array_map(static function (array $point): array {
                                return ['entity_id' => $point['entity_id'], 'entity_name' => $point['entity_name'], 'amount_cents' => $point['metric_value']];
                            }, $points);
                        }
                        $results[] = ['period' => $period, 'metric_code' => $metric, 'storage_unit' => $metricContract['storage_unit'],
                            'object_kind'=>$dimensionRanking['object_kind'],'object_label'=>$dimensionRanking['object_label'],
                            'participant_relation'=>$dimensionRanking['participant_relation'],'rows' => $groups];
                        continue;
                    }
                    if (in_array($normalized['query_shape'], ['trend', 'ranking'], true)) {
                        $points = $reader->dailyStoreTotals($binding['tenant_id'], $binding['store_ids'], $range, $metric);
                        $projector = new MetricGroupedProjection();
                        $rows = $normalized['query_shape'] === 'trend' ? $projector->trend($points, $range, (new \DateTimeImmutable('@'.$now))->setTimezone(new \DateTimeZone(MetricQueryDatePolicy::TIMEZONE))->format('Y-m-d')) : $projector->ranking($points, $binding['store_ids'], $normalized['ranking']);
                        if ($normalized['query_shape'] === 'ranking') {
                            foreach ($rows as &$direction) foreach ($direction as &$row) $row['store_name'] = $storeNames[$row['store_id']];
                            unset($row, $direction);
                        }
                        $results[] = ['period' => $period, 'metric_code' => $metric, 'storage_unit' => $metricContract['storage_unit'], 'rows' => $rows];
                        continue;
                    }
                    $value = $reader->metricTotal($binding['tenant_id'], $binding['store_ids'], $range, $metric);
                    $result = ['period' => $period, 'metric_code' => $metric, 'storage_unit' => $metricContract['storage_unit']];
                    $result[$metricContract['storage_unit'] === 'fen' ? 'amount_cents' : 'count'] = $value;
                    $results[] = $result;
                }
            }
            return $results;
        });
        // A revocation during the read cannot mint a usable view.
        if ($this->binding(call_user_func($this->authorize, $principal), $normalized['store_ids']) !== $binding) $this->fail('METRIC_PERMISSION_CHANGED');
        $capabilities = self::metricCapabilities();
        $readiness = []; $metricVersions = []; $allReady = true;
        foreach ($normalized['metric_codes'] as $metric) {
            $readiness[$metric] = $capabilities[$metric];
            if ($capabilities[$metric]['ai_query_ready']) $metricVersions[$metric] = $capabilities[$metric]['metric_version'];
            else $allReady = false;
        }
        $view = [
            'schema_version' => self::CONTRACT_VERSION,
            'read_view_kind' => 'materialized_report_projection',
            'created_at' => $now, 'expires_at' => $expiresAt,
            'binding' => $binding, 'query' => $normalized,
            'query_hash' => hash('sha256', UnifiedQueryJson::encode($normalized)),
            'effective_scope_hash' => hash('sha256', UnifiedQueryJson::encode($binding)),
            'source_kind' => 'unified_facts',
            'source_read_mode' => 'repeatable_read_transaction',
            'coverage_start' => self::COVERAGE_START,
            // Deliberately not a fact commit watermark, nor a claim of aggregate catch-up.
            'aggregation_caught_up' => null,
            'data_as_of' => date('c', $now),
            'source_metric_versions' => array_map(static function (array $capability): string { return $capability['source_metric_version']; }, $readiness),
            'canonical_mapping_version' => 'group-performance-summary-mapping-v1',
            'metric_versions' => $metricVersions,
            'metric_readiness' => $readiness,
            'ai_query_ready' => $allReady,
            'results' => $results, 'result_status' => 'complete',
            'result_hash' => hash('sha256', UnifiedQueryJson::encode(['query' => $normalized, 'binding' => $binding, 'results' => $results, 'version' => self::CONTRACT_VERSION])),
        ];
        if ($personnel!==null) {
            if ($this->personnelSelection($normalized,$binding)!==$personnel) $this->fail('METRIC_PERMISSION_CHANGED');
            $view['personnel_binding_hash']=$personnel['binding_hash'];$view['personnel_selection_label']=$personnel['label'];
            $view['result_hash']=hash('sha256',UnifiedQueryJson::encode(['base'=>$view['result_hash'],'personnel_binding_hash'=>$personnel['binding_hash']]));
        }
        $ref = $this->store->put($view);
        return $this->replay($principal, $normalized, $ref);
    }

    public function replay(array $principal, array $query, string $ref): array
    {
        $normalized = $this->query($query);
        $binding = $this->binding(call_user_func($this->authorize, $principal), $normalized['store_ids']);
        $view = $this->store->get($ref);
        if (($view['schema_version'] ?? null) !== self::CONTRACT_VERSION || ($view['binding'] ?? null) !== $binding
            || ($view['query'] ?? null) !== $normalized) $this->fail('METRIC_READ_BINDING_MISMATCH');
        $personnel=$this->personnelSelection($normalized,$binding);
        if ($personnel!==null && ($view['personnel_binding_hash']??null)!==$personnel['binding_hash']) $this->fail('METRIC_PERMISSION_CHANGED');
        return $view;
    }

    private function addAmount(int $a,int $b): int { $sum=$a+$b;if(!is_int($sum))$this->fail('METRIC_SOURCE_AMOUNT_INVALID');return $sum; }
    /** @return array{dimension:string,object_kind:string,object_label:string,participant_relation:bool}|null */
    private function dimensionRanking(array $query): ?array
    {
        $objectKind=$query['business_filters']['object_kind'] ?? null;
        if (!is_string($objectKind) || $objectKind==='person') return null;
        $metric = MetricDefinitionRegistry::get($query['metric_codes'][0]);
        $matches=array_values(array_filter((array)($metric['analysis_dimension_contracts']??[]),static function($dimension)use($objectKind):bool {
            return is_array($dimension) && ($dimension['object_kind']??null)===$objectKind && ($dimension['filter_keys']??null)===[];
        }));
        if (count($matches)!==1 || $query['query_shape']!=='ranking') $this->fail('METRIC_QUERY_SHAPE_UNAVAILABLE');
        $dimension=$matches[0];
        return ['dimension'=>$dimension['dimension'],'object_kind'=>$dimension['object_kind'],'object_label'=>$dimension['object_label'],
            'participant_relation'=>isset($dimension['analysis_relation_source'])];
    }
    private function personnelSelection(array $query,array $binding): ?array
    {
        // A self-only grant is a people-data grant, not a grant to every total
        // from the stores where that employee works.  Store summaries have no
        // employee predicate, so allowing them here would silently turn
        // "my data" into "my store's data".  The gateway may still offer
        // person-grain metrics, but every such query is bound to this employee
        // below and the same rule applies to replay/export.
        if (($query['business_filters']['object_kind'] ?? null) !== 'person') {
            if ($binding['scope_mode']==='self_participant') $this->fail('METRIC_PERMISSION_GRAIN_UNAVAILABLE');
            return null;
        }
        if (!$this->personnel) $this->fail('METRIC_PERMISSION_GRAIN_UNAVAILABLE');
        $selection=$this->personnel->selection($query['metric_codes'][0],$query['business_filters']['selection_ref']);
        if ($binding['scope_mode']==='self_participant' && ($selection['scope']['employee_id']??0)<1) $this->fail('METRIC_PERMISSION_DENIED');
        if ($binding['scope_mode']==='self_participant' && (int)($binding['employee_id']??0)!==$selection['scope']['employee_id']) $this->fail('METRIC_PERMISSION_DENIED');
        if (array_diff($binding['store_ids'],$selection['scope']['store_ids'])) $this->fail('METRIC_PERMISSION_DENIED');
        if ($selection['scope']['store_ids']!==$binding['store_ids']) {
            $selection['pairs']=array_values(array_filter($selection['pairs'],static fn($pair)=>in_array($pair['store_id'],$binding['store_ids'],true)));
            $selection['names']=array_intersect_key($selection['names'],array_fill_keys(array_column($selection['pairs'],'employee_id'),true));
            $selection['scope']['store_ids']=$binding['store_ids'];
            $selection['binding_hash']=hash('sha256',UnifiedQueryJson::encode($selection));
        }
        return $selection;
    }

    private function binding($binding, array $requested): array
    {
        $fields = ['instance_id', 'subject_ref', 'terminal', 'tenant_id', 'permission_version', 'report_capability_code', 'scope_provider_code', 'scope_mode', 'store_ids'];
        // Optional only for historical non-AI callers. AI always signs both
        // fields from the freshly authenticated personnel authority.
        foreach (['store_report_authorized','employee_id'] as $key) if (is_array($binding) && array_key_exists($key,$binding)) $fields[]=$key;
        if (!is_array($binding) || array_diff(array_keys($binding), $fields) || array_diff($fields, array_keys($binding))) $this->fail('METRIC_PERMISSION_INVALID');
        if (array_key_exists('store_report_authorized',$binding) && !is_bool($binding['store_report_authorized'])) $this->fail('METRIC_PERMISSION_INVALID');
        if (array_key_exists('employee_id',$binding) && (!is_int($binding['employee_id']) || $binding['employee_id']<0)) $this->fail('METRIC_PERMISSION_INVALID');
        foreach (array_diff($fields, ['store_ids','store_report_authorized','employee_id']) as $key) {
            if (!is_string($binding[$key]) || $binding[$key] === '' || strlen($binding[$key]) > 256 || preg_match('/[\x00-\x1f\x7f]/', $binding[$key])) $this->fail('METRIC_PERMISSION_INVALID');
        }
        if (!in_array($binding['terminal'], ['platform', 'store', 'merchant'], true)
            || !in_array($binding['scope_mode'], ['stores', 'all', 'agent_limited', 'platform_admin','self_participant'], true)
            || $binding['report_capability_code'] !== 'group_management_dashboard') $this->fail('METRIC_PERMISSION_GRAIN_UNAVAILABLE');
        $allowed = $this->ids($binding['store_ids']);
        if ($allowed === [] || array_diff($requested, $allowed)) $this->fail('METRIC_PERMISSION_DENIED');
        $binding['store_ids'] = $requested === [] ? $allowed : $requested;
        return UnifiedQueryJson::decode(UnifiedQueryJson::encode($binding));
    }

    private function query(array $query): array
    {
        if (!array_key_exists('ranking', $query)) $query['ranking'] = null;
        $fields = ['query_shape', 'metric_codes', 'start_date', 'end_date', 'compare_range', 'store_ids', 'business_filters', 'ranking'];
        if (array_diff(array_keys($query), $fields) || array_diff($fields, array_keys($query))) $this->fail('METRIC_QUERY_SCHEMA_INVALID');
        if (!in_array($query['query_shape'], ['summary', 'comparison', 'trend', 'ranking'], true)) $this->fail('METRIC_QUERY_SHAPE_UNAVAILABLE');
        $objectKind=$query['business_filters']['object_kind']??null;
        $person=$objectKind==='person';
        if ($query['business_filters']!==[] && (!$person && !is_string($objectKind))) $this->fail('METRIC_QUERY_SHAPE_UNAVAILABLE');
        if ($person && (count($query['business_filters'])!==2 || !is_string($query['business_filters']['selection_ref']??null)
            || !preg_match('/^((position|person):[1-9][0-9]*|role:craftsman|role:salesperson)$/D',$query['business_filters']['selection_ref']))) $this->fail('METRIC_QUERY_SHAPE_UNAVAILABLE');
        if (!$person && $query['business_filters']!==[] && $query['business_filters']!==['object_kind'=>$objectKind]) $this->fail('METRIC_QUERY_SHAPE_UNAVAILABLE');
        if ($query['query_shape'] === 'ranking') {
            $rank = $query['ranking'];
            if (!is_array($rank) || count($rank) !== 2 || !in_array($rank['direction'] ?? null, ['top', 'bottom', 'top_and_bottom'], true)
                || !is_int($rank['limit'] ?? null) || $rank['limit'] < 1 || $rank['limit'] > 20) $this->fail('METRIC_QUERY_SCHEMA_INVALID');
        } elseif ($query['ranking'] !== null) $this->fail('METRIC_QUERY_SCHEMA_INVALID');
        if (!is_array($query['metric_codes']) || $query['metric_codes'] === [] || count($query['metric_codes']) > 4
            || array_keys($query['metric_codes']) !== range(0, count($query['metric_codes']) - 1)) $this->fail('METRIC_QUERY_SCHEMA_INVALID');
        $allowed=[];
        foreach (self::metricCapabilities() as $code=>$capability) {
            $dimensionCapable=false;
            foreach ((array)($capability['analysis_dimension_contracts']??[]) as $dimension) {
                if (is_array($dimension)&&($dimension['object_kind']??null)===$objectKind&&($dimension['filter_keys']??null)===[]) $dimensionCapable=true;
            }
            if (($capability['ai_query_ready']??false)===true && (($person && $capability['filter_grain']==='person') || (!$person && $query['business_filters']!==[] && $dimensionCapable) || (!$person && $query['business_filters']===[] && $capability['filter_grain']==='store'))) $allowed[]=$code;
        }
        foreach ($query['metric_codes'] as $metric) if (!in_array($metric, $allowed, true)) $this->fail('METRIC_NOT_REGISTERED');
        if ($person && (count($query['metric_codes'])!==1 || !in_array($query['query_shape'],['summary','ranking'],true))) $this->fail('METRIC_QUERY_SHAPE_UNAVAILABLE');
        if (!$person && $query['business_filters']!==[] && (count($query['metric_codes'])!==1 || $query['query_shape']!=='ranking')) $this->fail('METRIC_QUERY_SHAPE_UNAVAILABLE');
        // Preserve the existing two-metric forms.  More than two facts are
        // only batched for an unfiltered summary so a first operating answer
        // cannot combine incompatible object grains or ranking semantics.
        if (count($query['metric_codes'])>2 && ($query['query_shape']!=='summary' || $query['business_filters']!==[])) $this->fail('METRIC_QUERY_SHAPE_UNAVAILABLE');
        if (count(array_unique($query['metric_codes'])) !== count($query['metric_codes'])) $this->fail('METRIC_QUERY_SCHEMA_INVALID');
        $this->range(['start' => $query['start_date'], 'end' => $query['end_date']]);
        if ($query['query_shape'] === 'comparison') {
            if (!is_array($query['compare_range'])) $this->fail('METRIC_QUERY_SCHEMA_INVALID');
            $this->range($query['compare_range']);
        } elseif ($query['compare_range'] !== null) $this->fail('METRIC_QUERY_SCHEMA_INVALID');
        $query['store_ids'] = $this->ids($query['store_ids']);
        // Canonical key order for equality after JSON storage (objects are sorted by encoder).
        return UnifiedQueryJson::decode(UnifiedQueryJson::encode($query));
    }

    private function range(array $range): void
    {
        $today=(new \DateTimeImmutable('@'.$this->now()))->setTimezone(new \DateTimeZone(MetricQueryDatePolicy::TIMEZONE))->format('Y-m-d');
        MetricQueryDatePolicy::assertExecutable($range,self::COVERAGE_START,$today);
    }

    private function ids($ids): array
    {
        if (!is_array($ids) || count($ids) > 10000 || ($ids !== [] && array_keys($ids) !== range(0, count($ids) - 1))) $this->fail('METRIC_PERMISSION_INVALID');
        foreach ($ids as $id) if (!is_int($id) || $id <= 0) $this->fail('METRIC_PERMISSION_INVALID');
        if (count(array_unique($ids)) !== count($ids)) $this->fail('METRIC_PERMISSION_INVALID');
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    private function now(): int
    {
        $now = call_user_func($this->clock);
        if (!is_int($now) || $now < 0 || $now > PHP_INT_MAX - 86400) $this->fail('METRIC_QUERY_CLOCK_INVALID');
        return $now;
    }

    private function fail(string $code): void { throw new MetricQueryContractException($code, '当前指标、条件或权限范围暂不支持此查询。'); }
}
