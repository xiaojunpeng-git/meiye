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
    private $member;

    /** Implemented shared contracts, not a grant of entry/report access or instance deployment readiness. */
    public static function metricCapabilities(): array
    {
        return MetricDefinitionRegistry::capabilities();
    }

    /** authorize must rebuild report permissions from the authenticated principal on EVERY invocation. */
    public function __construct(MetricReadViewStore $store, callable $authorize, ?callable $transaction = null, ?callable $clock = null, ?PersonnelAnalysisObjectServices $personnel = null, ?MemberAnalysisObjectServices $member = null)
    {
        $this->store = $store;
        $this->authorize = $authorize;
        $this->transaction = $transaction ?: [new MetricReadTransaction(), 'run'];
        $this->clock = $clock ?: static function (): int { return time(); };
        $this->personnel = $personnel;
        $this->member = $member;
    }

    public function create(array $principal, array $query, ?int $expiresAt = null): array
    {
        $normalized = $this->query($query);
        $binding = $this->binding(call_user_func($this->authorize, $principal), $normalized['store_ids']);
        $personnel = $this->personnelSelection($normalized,$binding);
        $member = $this->memberSelection($normalized,$binding);
        $dimensionRanking = $this->dimensionRanking($normalized);
        $thresholdCount = $this->thresholdCount($normalized);
        $conditionSet = $this->conditionSet($normalized);
        $now = $this->now();
        $expiresAt=$expiresAt??($now+86400);
        if ($expiresAt<=$now || $expiresAt>$now+86400) $this->fail('METRIC_READ_EXPIRY_INVALID');
        $ranges = ['current' => ['start' => $normalized['start_date'], 'end' => $normalized['end_date']]];
        if ($normalized['compare_range'] !== null) $ranges['comparison'] = $normalized['compare_range'];
        $results = call_user_func($this->transaction, function (GroupPerformanceMetricReadServices $reader) use ($normalized, $binding, $ranges,$personnel,$member,$dimensionRanking,$thresholdCount,$conditionSet,$now): array {
            $results = [];
            $storeNames = $normalized['query_shape'] === 'ranking' && $personnel===null && $dimensionRanking===null ? $reader->storeNames($binding['store_ids']) : [];
            foreach ($ranges as $period => $range) {
                foreach ($normalized['metric_codes'] as $metric) {
                    $metricContract = MetricDefinitionRegistry::get($metric);
                    if ($conditionSet !== null) {
                        // A condition-set is one population read, not N independent
                        // answers.  The reader supplies each registered metric and
                        // this layer only evaluates the already-normalized values.
                        if ($metric!==$normalized['metric_codes'][0]) continue;
                        $results[]=$this->conditionResult($reader,$binding,$range,$conditionSet,$personnel,$normalized['query_shape']);
                        continue;
                    }
                    if ($thresholdCount !== null) {
                        $results[] = [
                            'period' => $period, 'metric_code' => $metric, 'storage_unit' => 'count',
                            'source_storage_unit' => $metricContract['storage_unit'],
                            'object_kind' => 'member', 'aggregate_condition' => $thresholdCount,
                            'count' => $reader->thresholdCount($binding['tenant_id'], $binding['store_ids'], $range, $metric, $thresholdCount),
                        ];
                        continue;
                    }
                    if ($personnel!==null) {
                        $points=$personnel['pairs']?$reader->personnelTotals($binding['tenant_id'],$binding['store_ids'],$range,$metric,$personnel['pairs']):[];
                        $totals=[];$sum=0;
                        foreach ($points as $point) {
                            $sum=$this->addAmount($sum,$point['amount_cents']);
                            $employee=$point['employee_id'];$totals[$employee]=$this->addAmount($totals[$employee]??0,$point['amount_cents']);
                        }
                        if ($normalized['query_shape']==='summary') {
                            $result=['period'=>$period,'metric_code'=>$metric,'storage_unit'=>$metricContract['storage_unit']];
                            $result[$metricContract['storage_unit']==='fen'?'amount_cents':'count']=$sum;
                            $results[]=$result;
                        }
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
                    if ($member!==null) {
                        $dimension=$this->selectionDimension($metric,'member');
                        $value=$reader->dimensionSelectionTotal($metric,$dimension,$binding['tenant_id'],$binding['store_ids'],$range,$member['member_id']);
                        $result=['period'=>$period,'metric_code'=>$metric,'storage_unit'=>$metricContract['storage_unit'],
                            'object_kind'=>'member','object_label'=>$member['label']];
                        $result[$metricContract['storage_unit']==='fen'?'amount_cents':'count']=$value;
                        $results[]=$result;
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
                        // The primary metric alone determines rank. Additional
                        // registered metrics are read only for these returned
                        // identities, so a display column cannot broaden the
                        // population, change order, or masquerade as another
                        // independent ranking.
                        $presentation=$this->rankingPresentation($reader,$normalized,$dimensionRanking,$binding,$range,$groups);
                        $results[] = ['period' => $period, 'metric_code' => $metric, 'storage_unit' => $metricContract['storage_unit'],
                            'object_kind'=>$dimensionRanking['object_kind'],'object_label'=>$dimensionRanking['object_label'],
                            'participant_relation'=>$dimensionRanking['participant_relation'],'rows' => $groups,
                            'ranking_presentation_metrics'=>$presentation];
                        continue;
                    }
                    $dimensionSummary=$this->dimensionSummary($normalized,$metric);
                    if ($dimensionSummary!==null) {
                        $value=$reader->dimensionSummary($metric,$dimensionSummary['dimension'],$binding['tenant_id'],$binding['store_ids'],$range);
                        $result=['period'=>$period,'metric_code'=>$metric,'storage_unit'=>$metricContract['storage_unit'],
                            'object_kind'=>$dimensionSummary['object_kind'],'object_label'=>$dimensionSummary['object_label']];
                        $result[$metricContract['storage_unit']==='fen'?'amount_cents':'count']=$value;
                        $results[]=$result;
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
        if ($member!==null) {
            if ($this->memberSelection($normalized,$binding)!==$member) $this->fail('METRIC_PERMISSION_CHANGED');
            $view['member_binding_hash']=$member['binding_hash'];$view['member_selection_label']=$member['label'];
            $view['result_hash']=hash('sha256',UnifiedQueryJson::encode(['base'=>$view['result_hash'],'member_binding_hash'=>$member['binding_hash']]));
        }
        $ref = $this->store->put($view);
        return $this->replay($principal, $normalized, $ref);
    }

    public function replay(array $principal, array $query, string $ref): array
    {
        $normalized = $this->query($query);
        $binding = $this->binding(call_user_func($this->authorize, $principal), $normalized['store_ids']);
        $view = $this->store->get($ref);
        // A read view can legitimately predate an additive optional query
        // field. Normalize it through the same closed schema before
        // comparison so an omitted default has exactly the same meaning as
        // the current explicit default. Unknown fields, altered values and
        // invalid legacy data still fail closed in query().
        $storedQuery = is_array($view['query'] ?? null) ? $this->query($view['query']) : null;
        // Read views are persisted through UnifiedQueryJson, which
        // canonicalizes object-key order.  The current trusted authority and
        // request are freshly assembled arrays, so strict PHP array equality
        // would reject an otherwise identical, signed view solely because the
        // associative keys were inserted in a different order.  Compare the
        // canonical payloads instead: values, list order and every binding
        // field must still match exactly.
        if (($view['schema_version'] ?? null) !== self::CONTRACT_VERSION
            || !isset($view['binding'], $view['query'])
            || !is_array($view['binding']) || !is_array($view['query'])
            || $storedQuery === null
            || UnifiedQueryJson::encode($view['binding']) !== UnifiedQueryJson::encode($binding)
            || UnifiedQueryJson::encode($storedQuery) !== UnifiedQueryJson::encode($normalized)) {
            $this->fail('METRIC_READ_BINDING_MISMATCH');
        }
        $personnel=$this->personnelSelection($normalized,$binding);
        if ($personnel!==null && ($view['personnel_binding_hash']??null)!==$personnel['binding_hash']) $this->fail('METRIC_PERMISSION_CHANGED');
        $member=$this->memberSelection($normalized,$binding);
        if ($member!==null && ($view['member_binding_hash']??null)!==$member['binding_hash']) $this->fail('METRIC_PERMISSION_CHANGED');
        return $view;
    }

    private function addAmount(int $a,int $b): int { $sum=$a+$b;if(!is_int($sum))$this->fail('METRIC_SOURCE_AMOUNT_INVALID');return $sum; }
    /** @return array{dimension:string,object_kind:string,object_label:string,participant_relation:bool}|null */
    private function dimensionRanking(array $query): ?array
    {
        if ($query['query_shape'] !== 'ranking') return null;
        $objectKind=$query['business_filters']['object_kind'] ?? null;
        if (!is_string($objectKind) || $objectKind==='person') return null;
        $metric = MetricDefinitionRegistry::get($query['metric_codes'][0]);
        $matches=array_values(array_filter((array)($metric['analysis_dimension_contracts']??[]),static function($dimension)use($objectKind):bool {
            return is_array($dimension) && ($dimension['object_kind']??null)===$objectKind && ($dimension['filter_keys']??null)===[];
        }));
        if (count($matches)!==1) $this->fail('METRIC_QUERY_SHAPE_UNAVAILABLE');
        $dimension=$matches[0];
        return ['dimension'=>$dimension['dimension'],'object_kind'=>$dimension['object_kind'],'object_label'=>$dimension['object_label'],
            'participant_relation'=>isset($dimension['analysis_relation_source'])];
    }

    /** @return array<int,array{metric_code:string,storage_unit:string,values:array<int,array{entity_id:int,metric_value:int}>}> */
    private function rankingPresentation(GroupPerformanceMetricReadServices $reader,array $query,array $primaryDimension,array $binding,array $range,array $groups): array
    {
        $codes=(array)($query['ranking_presentation_metrics']??[]);
        if (count($codes)<2) return [];
        $ids=[];foreach ($groups as $points) foreach ($points as $point) if (is_int($point['entity_id']??null)) $ids[]=$point['entity_id'];
        $ids=array_values(array_unique($ids));sort($ids,SORT_NUMERIC);
        if ($ids===[]) return [];
        $out=[];
        foreach (array_slice($codes,1) as $code) {
            $dimension=$this->rankingDimension($code,$primaryDimension['object_kind']);
            if ($dimension===null || $dimension['dimension']!==$primaryDimension['dimension']) $this->fail('METRIC_QUERY_SHAPE_UNAVAILABLE');
            $contract=MetricDefinitionRegistry::get($code);
            $out[]=['metric_code'=>$code,'storage_unit'=>$contract['storage_unit'],
                'values'=>$reader->dimensionValues($code,$dimension['dimension'],$binding['tenant_id'],$binding['store_ids'],$range,$ids)];
        }
        return $out;
    }

    /** Resolves the registered dimension instead of accepting a caller field name. */
    private function rankingDimension(string $metricCode,string $objectKind): ?array
    {
        $metric=MetricDefinitionRegistry::get($metricCode);
        $matches=array_values(array_filter((array)($metric['analysis_dimension_contracts']??[]),static function($dimension)use($objectKind):bool {
            return is_array($dimension) && ($dimension['object_kind']??null)===$objectKind && ($dimension['filter_keys']??null)===[];
        }));
        return count($matches)===1?$matches[0]:null;
    }

    /** @return array{dimension:string,object_kind:string,object_label:string}|null */
    private function dimensionSummary(array $query,string $metricCode): ?array
    {
        if (($query['query_shape'] ?? null)!=='summary') return null;
        $objectKind=$query['business_filters']['object_kind']??null;
        if (!is_string($objectKind) || $objectKind==='person') return null;
        $metric=MetricDefinitionRegistry::get($metricCode);
        $matches=array_values(array_filter((array)($metric['analysis_dimension_contracts']??[]),static function($dimension)use($objectKind):bool {
            return is_array($dimension) && ($dimension['object_kind']??null)===$objectKind && ($dimension['filter_keys']??null)===[];
        }));
        if (count($matches)!==1) $this->fail('METRIC_QUERY_SHAPE_UNAVAILABLE');
        $dimension=$matches[0];
        return ['dimension'=>$dimension['dimension'],'object_kind'=>$dimension['object_kind'],'object_label'=>$dimension['object_label']];
    }
    /** @return array{subject:string,aggregation:string,operator:string,amount_cents:int}|null */
    private function thresholdCount(array $query): ?array
    {
        if ($query['query_shape'] !== 'threshold_count') return null;
        return $query['aggregate_condition'];
    }

    /** @return array{subject:string,relation:string,conditions:array<int,array{metric_code:string,operator:string,value:int}>}|null */
    private function conditionSet(array $query): ?array
    {
        return in_array($query['query_shape'],['condition_count','condition_list'],true) ? $query['condition_set'] : null;
    }

    /** @return array<string,mixed> */
    private function conditionResult(GroupPerformanceMetricReadServices $reader,array $binding,array $range,array $conditionSet,?array $personnel,string $shape): array
    {
        if (($conditionSet['subject']??null)==='member') {
            if ($personnel!==null) $this->fail('METRIC_QUERY_SHAPE_UNAVAILABLE');
            $population=$reader->conditionMembers(
                $binding['tenant_id'],$binding['store_ids'],$range,$conditionSet,
                $shape==='condition_list'?100:0
            );
            $result=['period'=>'current','metric_code'=>$conditionSet['conditions'][0]['metric_code'],'storage_unit'=>'count','object_kind'=>'member',
                'condition_set'=>$conditionSet,'count'=>$population['count']];
            if ($shape==='condition_list') {
                $result['rows']=$population['rows'];
                $result['list_limit']=$population['limit'];
                $result['has_more']=$population['has_more'];
            }
            return $result;
        }
        if (($conditionSet['subject']??null)==='store') {
            if ($personnel!==null) $this->fail('METRIC_QUERY_SHAPE_UNAVAILABLE');
            $names=$reader->storeNames($binding['store_ids']);
            $values=[];
            foreach ($binding['store_ids'] as $storeId) {
                if (!isset($names[$storeId])) $this->fail('METRIC_STORE_LABEL_UNAVAILABLE');
                $values[$storeId]=['store_id'=>$storeId,'store_name'=>$names[$storeId],'metrics'=>[]];
            }
            foreach ($conditionSet['conditions'] as $condition) {
                $metric=$condition['metric_code'];
                foreach ($values as &$row) $row['metrics'][$metric]=0;
                unset($row);
                foreach ($reader->dailyStoreTotals($binding['tenant_id'],$binding['store_ids'],$range,$metric) as $point) {
                    $storeId=(int)($point['store_id']??0);
                    if (!isset($values[$storeId])) $this->fail('METRIC_SOURCE_RESULT_INVALID');
                    $values[$storeId]['metrics'][$metric]=$this->addAmount(
                        $values[$storeId]['metrics'][$metric],(int)($point['amount_cents']??0)
                    );
                }
            }
            $rows=[];
            foreach ($values as $row) {
                $matches=[];
                foreach ($conditionSet['conditions'] as $condition) {
                    $matches[]=$this->matchesCondition($row['metrics'][$condition['metric_code']],$condition['operator'],$condition['value']);
                }
                $qualified=$conditionSet['relation']==='all'?!in_array(false,$matches,true):in_array(true,$matches,true);
                if ($qualified) $rows[]=$row;
            }
            usort($rows,static function(array $left,array $right):int { return [$left['store_name'],$left['store_id']] <=> [$right['store_name'],$right['store_id']]; });
            $result=['period'=>'current','metric_code'=>$conditionSet['conditions'][0]['metric_code'],'storage_unit'=>'count','object_kind'=>'store',
                'condition_set'=>$conditionSet,'count'=>count($rows)];
            if ($shape==='condition_list') {
                $limit=100;
                $result['rows']=array_slice($rows,0,$limit);
                $result['list_limit']=$limit;
                $result['has_more']=count($rows)>$limit;
            }
            return $result;
        }
        if (($conditionSet['subject']??null)!=='person') {
            if ($personnel!==null) $this->fail('METRIC_QUERY_SHAPE_UNAVAILABLE');
            $population=$reader->conditionDimensions($binding['tenant_id'],$binding['store_ids'],$range,$conditionSet);
            $result=['period'=>'current','metric_code'=>$conditionSet['conditions'][0]['metric_code'],'storage_unit'=>'count',
                'object_kind'=>$conditionSet['subject'],'object_label'=>$population['object_label'],
                'condition_set'=>$conditionSet,'count'=>$population['count']];
            if ($shape==='condition_list') {
                $result['rows']=$population['rows'];
                $result['list_limit']=$population['limit'];
                $result['has_more']=$population['has_more'];
            }
            return $result;
        }
        if ($personnel===null || !$personnel['pairs']) $this->fail('METRIC_PERMISSION_GRAIN_UNAVAILABLE');
        $values=[];
        foreach ($personnel['pairs'] as $pair) {
            $employee=(int)$pair['employee_id'];
            if (!isset($personnel['names'][$employee])) $this->fail('METRIC_SOURCE_RESULT_INVALID');
            $values[$employee]=['employee_id'=>$employee,'employee_name'=>$personnel['names'][$employee],'metrics'=>[]];
        }
        foreach ($conditionSet['conditions'] as $condition) {
            $metric=$condition['metric_code'];
            foreach ($values as &$row) $row['metrics'][$metric]=0;
            unset($row);
            $points=$reader->personnelTotals($binding['tenant_id'],$binding['store_ids'],$range,$metric,$personnel['pairs']);
            foreach ($points as $point) {
                $employee=(int)($point['employee_id']??0);
                if (!isset($values[$employee])) $this->fail('METRIC_SOURCE_RESULT_INVALID');
                $values[$employee]['metrics'][$metric]=$this->addAmount($values[$employee]['metrics'][$metric],(int)($point['amount_cents']??0));
            }
        }
        $rows=[];
        foreach ($values as $row) {
            $matches=[];
            foreach ($conditionSet['conditions'] as $condition) $matches[]=$this->matchesCondition($row['metrics'][$condition['metric_code']],$condition['operator'],$condition['value']);
            $qualified=$conditionSet['relation']==='all' ? !in_array(false,$matches,true) : in_array(true,$matches,true);
            if ($qualified) $rows[]=$row;
        }
        usort($rows,static function(array $left,array $right):int { return [$left['employee_name'],$left['employee_id']] <=> [$right['employee_name'],$right['employee_id']]; });
        $result=['period'=>'current','metric_code'=>$conditionSet['conditions'][0]['metric_code'],'storage_unit'=>'count','object_kind'=>'person',
            'condition_set'=>$conditionSet,'count'=>count($rows)];
        if ($shape==='condition_list') {
            $limit=100;
            $result['rows']=array_slice($rows,0,$limit);
            $result['list_limit']=$limit;
            $result['has_more']=count($rows)>$limit;
        }
        return $result;
    }

    private function matchesCondition(int $actual,string $operator,int $target): bool
    {
        return ['gte'=>$actual>=$target,'gt'=>$actual>$target,'lte'=>$actual<=$target,'lt'=>$actual<$target,'eq'=>$actual===$target][$operator]??false;
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
        $conditionPopulation=in_array($query['query_shape'],['condition_count','condition_list'],true);
        $selection=$conditionPopulation
            ? $this->personnel->conditionSelection($query['metric_codes'])
            : $this->personnel->selection($query['metric_codes'][0],$query['business_filters']['selection_ref']);
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

    private function memberSelection(array $query,array $binding): ?array
    {
        if (($query['business_filters']['object_kind']??null)!=='member' || !isset($query['business_filters']['selection_ref'])) {
            return null;
        }
        if (!$this->member || $binding['scope_mode']==='self_participant') $this->fail('METRIC_PERMISSION_GRAIN_UNAVAILABLE');
        $selection=$this->member->selection($query['business_filters']['selection_ref']);
        if (array_diff($binding['store_ids'],(array)($selection['scope']['store_ids']??[]))) $this->fail('METRIC_PERMISSION_DENIED');
        // A user may explicitly narrow an otherwise authorized report to one
        // store. Preserve that narrower read boundary in the frozen binding;
        // the member relation was still checked against the complete current
        // authorized scope above.
        if ($selection['scope']['store_ids']!==$binding['store_ids']) {
            $selection['scope']['store_ids']=$binding['store_ids'];
            $selection['binding_hash']=hash('sha256',UnifiedQueryJson::encode($selection));
        }
        return $selection;
    }

    private function selectionDimension(string $metricCode,string $objectKind): string
    {
        $metric=MetricDefinitionRegistry::get($metricCode);
        $matches=array_values(array_filter((array)($metric['analysis_dimension_contracts']??[]),static function($dimension)use($objectKind):bool {
            return is_array($dimension) && ($dimension['object_kind']??null)===$objectKind && ($dimension['filter_keys']??null)===['selection_ref'];
        }));
        if (count($matches)!==1) $this->fail('METRIC_QUERY_SHAPE_UNAVAILABLE');
        return $matches[0]['dimension'];
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
        if (!array_key_exists('aggregate_condition', $query)) $query['aggregate_condition'] = null;
        if (!array_key_exists('condition_set', $query)) $query['condition_set'] = null;
        // Older signed views did not carry presentation columns. Treat omission
        // as the canonical empty list; current compiler output is then checked
        // against the same registry-derived list below.
        if (!array_key_exists('ranking_presentation_metrics',$query)) $query['ranking_presentation_metrics']=[];
        $fields = ['query_shape', 'metric_codes', 'start_date', 'end_date', 'compare_range', 'store_ids', 'business_filters', 'ranking', 'aggregate_condition','condition_set','ranking_presentation_metrics'];
        if (array_diff(array_keys($query), $fields) || array_diff($fields, array_keys($query))) $this->fail('METRIC_QUERY_SCHEMA_INVALID');
        if (!in_array($query['query_shape'], ['summary', 'comparison', 'trend', 'ranking', 'threshold_count','condition_count','condition_list'], true)) $this->fail('METRIC_QUERY_SHAPE_UNAVAILABLE');
        $objectKind=$query['business_filters']['object_kind']??null;
        $person=$objectKind==='person';
        $conditionPopulation=in_array($query['query_shape'],['condition_count','condition_list'],true);
        $memberSelection=$objectKind==='member' && isset($query['business_filters']['selection_ref']);
        if ($query['business_filters']!==[] && (!$person && !is_string($objectKind))) $this->fail('METRIC_QUERY_SHAPE_UNAVAILABLE');
        if ($person && (count($query['business_filters'])!==2 || !is_string($query['business_filters']['selection_ref']??null)
            || (!$conditionPopulation && !preg_match('/^((position|person):[1-9][0-9]*|role:craftsman|role:salesperson)$/D',$query['business_filters']['selection_ref']))
            || ($conditionPopulation && $query['business_filters']['selection_ref']!=='cohort:active_personnel'))) $this->fail('METRIC_QUERY_SHAPE_UNAVAILABLE');
        if ($memberSelection && (count($query['business_filters'])!==2 || !is_string($query['business_filters']['selection_ref'])
            || !preg_match('/^member:[1-9][0-9]*$/D',$query['business_filters']['selection_ref']))) $this->fail('METRIC_QUERY_SHAPE_UNAVAILABLE');
        if (!$person && !$memberSelection && $query['business_filters']!==[] && $query['business_filters']!==['object_kind'=>$objectKind]) $this->fail('METRIC_QUERY_SHAPE_UNAVAILABLE');
        if ($query['query_shape'] === 'ranking') {
            $rank = $query['ranking'];
            if (!is_array($rank) || count($rank) !== 2 || !in_array($rank['direction'] ?? null, ['top', 'bottom', 'top_and_bottom'], true)
                || !is_int($rank['limit'] ?? null) || $rank['limit'] < 1 || $rank['limit'] > 20) $this->fail('METRIC_QUERY_SCHEMA_INVALID');
        } elseif ($query['ranking'] !== null) $this->fail('METRIC_QUERY_SCHEMA_INVALID');
        if ($query['query_shape'] === 'threshold_count') {
            $condition = $query['aggregate_condition']; $conditionKeys = is_array($condition) ? array_keys($condition) : []; sort($conditionKeys, SORT_STRING);
            if ($objectKind !== 'member' || $query['business_filters'] !== ['object_kind' => 'member']
                || $conditionKeys !== ['aggregation', 'amount_cents', 'operator', 'subject']
                || ($condition['subject'] ?? null) !== 'member' || ($condition['aggregation'] ?? null) !== 'period_total'
                || !in_array($condition['operator'] ?? null, ['gte', 'gt', 'lte', 'lt', 'eq'], true)
                || !is_int($condition['amount_cents'] ?? null) || $condition['amount_cents'] < 1 || $condition['amount_cents'] > 100000000000) {
                $this->fail('METRIC_QUERY_SCHEMA_INVALID');
            }
        } elseif (!$conditionPopulation && $query['aggregate_condition'] !== null) $this->fail('METRIC_QUERY_SCHEMA_INVALID');
        if ($conditionPopulation) {
            $set=$query['condition_set'];$keys=is_array($set)?array_keys($set):[];sort($keys,SORT_STRING);
            $conditionSubject=$set['subject']??null;
            $expectedFilters=$conditionSubject==='person'
                ? ['object_kind'=>'person','selection_ref'=>'cohort:active_personnel']
                : ['object_kind'=>$conditionSubject];
            if (!is_string($conditionSubject)||!preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$conditionSubject)||$query['business_filters']!==$expectedFilters
                ||$keys!==['conditions','relation','subject']||$conditionSubject!==$objectKind
                ||!in_array($set['relation']??null,['all','any'],true)||!is_array($set['conditions']??null)
                ||count($set['conditions'])<1||count($set['conditions'])>4||array_keys($set['conditions'])!==range(0,count($set['conditions'])-1)) $this->fail('METRIC_QUERY_SCHEMA_INVALID');
            $conditionMetrics=[];
            foreach ($set['conditions'] as $condition) {
                $keys=is_array($condition)?array_keys($condition):[];sort($keys,SORT_STRING);
                if ($keys!==['metric_code','operator','value']||!is_string($condition['metric_code']??null)||!in_array($condition['operator']??null,['gte','gt','lte','lt','eq'],true)
                    ||!is_int($condition['value']??null)||abs($condition['value'])>100000000000) $this->fail('METRIC_QUERY_SCHEMA_INVALID');
                $conditionMetrics[]=$condition['metric_code'];
            }
            if (count(array_unique($conditionMetrics))!==count($conditionMetrics)||$query['metric_codes']!==$conditionMetrics) $this->fail('METRIC_QUERY_SCHEMA_INVALID');
            $binding=$query['aggregate_condition'];$bindingKeys=is_array($binding)?array_keys($binding):[];sort($bindingKeys,SORT_STRING);
            if ($bindingKeys!==['conditions','relation','result_form','subject']||($binding['subject']??null)!==$conditionSubject
                ||($binding['relation']??null)!==$set['relation']
                ||($binding['result_form']??null)!==($query['query_shape']==='condition_count'?'count':'list')
                ||!is_array($binding['conditions']??null)||count($binding['conditions'])!==count($set['conditions'])) $this->fail('METRIC_QUERY_SCHEMA_INVALID');
            foreach ($binding['conditions'] as $index=>$condition) {
                $conditionKeys=is_array($condition)?array_keys($condition):[];sort($conditionKeys,SORT_STRING);
                if ($conditionKeys!==['metric_code','operator','quantity','unit']
                    ||($condition['metric_code']??null)!==$set['conditions'][$index]['metric_code']
                    ||($condition['operator']??null)!==$set['conditions'][$index]['operator']
                    ||!is_string($condition['quantity']??null)||!preg_match('/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,6})?$/D',$condition['quantity'])
                    ||!in_array($condition['unit']??null,['yuan','count','day'],true)) $this->fail('METRIC_QUERY_SCHEMA_INVALID');
            }
        } elseif ($query['condition_set']!==null) $this->fail('METRIC_QUERY_SCHEMA_INVALID');
        if (!is_array($query['metric_codes']) || $query['metric_codes'] === [] || count($query['metric_codes']) > \app\services\ai\execution\AiOverviewMetricResolver::MAX_METRICS
            || array_keys($query['metric_codes']) !== range(0, count($query['metric_codes']) - 1)) $this->fail('METRIC_QUERY_SCHEMA_INVALID');
        $allowed=[];
        foreach (self::metricCapabilities() as $code=>$capability) {
            $dimensionCapable=false;
            foreach ((array)($capability['analysis_dimension_contracts']??[]) as $dimension) {
                if (!is_array($dimension)||($dimension['object_kind']??null)!==$objectKind) continue;
                if (($memberSelection && ($dimension['filter_keys']??null)===['selection_ref']) || (!$memberSelection && ($dimension['filter_keys']??null)===[])) $dimensionCapable=true;
            }
            $storeCondition=$conditionPopulation && $objectKind==='store'
                && $query['business_filters']===['object_kind'=>'store']
                && ($capability['filter_grain']??null)==='store'
                && in_array('store',(array)($capability['condition_subjects']??[]),true);
            if (($capability['ai_query_ready']??false)===true && (($person && $capability['filter_grain']==='person') || $storeCondition || (!$person && $query['business_filters']!==[] && $dimensionCapable) || (!$person && $query['business_filters']===[] && $capability['filter_grain']==='store'))) $allowed[]=$code;
        }
        foreach ($query['metric_codes'] as $metric) if (!in_array($metric, $allowed, true)) $this->fail('METRIC_NOT_REGISTERED');
        foreach ($query['metric_codes'] as $metric) if (!in_array($query['query_shape'],self::metricCapabilities()[$metric]['query_shapes']??[],true)) $this->fail('METRIC_QUERY_SHAPE_UNAVAILABLE');
        $expectedPresentation=$query['query_shape']==='ranking'
            ? \app\services\ai\execution\AiRankingPresentationMetricResolver::resolve(self::metricCapabilities(),$query['metric_codes'][0],is_string($objectKind)?$objectKind:'') : [];
        if ($query['ranking_presentation_metrics']!==[] && $query['ranking_presentation_metrics']!==$expectedPresentation) $this->fail('METRIC_QUERY_SCHEMA_INVALID');
        $query['ranking_presentation_metrics']=$expectedPresentation;
        if ($conditionPopulation) foreach ($query['metric_codes'] as $metric) if (!in_array($objectKind,(array)(self::metricCapabilities()[$metric]['condition_subjects']??[]),true)) $this->fail('METRIC_QUERY_SHAPE_UNAVAILABLE');
        if ($person && (!in_array($query['query_shape'],['summary','ranking'],true)
            || ($query['query_shape']==='ranking' && count($query['metric_codes'])!==1)
            || ($query['query_shape']==='summary' && count($query['metric_codes'])>4))) {
            if (!$conditionPopulation) $this->fail('METRIC_QUERY_SHAPE_UNAVAILABLE');
        }
        if ($memberSelection && (count($query['metric_codes'])!==1 || $query['query_shape']!=='summary')) $this->fail('METRIC_QUERY_SHAPE_UNAVAILABLE');
        if (!$person && $query['business_filters']!==[] && (!in_array($query['query_shape'],['summary','ranking','threshold_count','condition_count','condition_list'],true)
            || (!$conditionPopulation && $query['query_shape']!=='summary' && count($query['metric_codes'])!==1))) $this->fail('METRIC_QUERY_SHAPE_UNAVAILABLE');
        // Preserve the existing two-metric forms.  More than two facts are
        // only batched for an unfiltered summary so a first operating answer
        // cannot combine incompatible object grains or ranking semantics.
        if (count($query['metric_codes'])>2 && !in_array($query['query_shape'],['summary','condition_count','condition_list'],true)) $this->fail('METRIC_QUERY_SHAPE_UNAVAILABLE');
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
