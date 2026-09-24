<?php
namespace app\services\ai\presentation;

use app\services\query\metric\MetricMoneyFormatter;
use app\services\query\metric\MetricReadViewServices;
use app\services\query\metric\MetricDefinitionRegistry;
use RuntimeException;

/** Converts verified Reader evidence into the stable customer answer shape. */
final class AiAnswerRenderer
{
    public function render(array $view): array
    {
        $dictionary = new \app\services\metric\MetricDictionaryServices();
        // The verified answer itself is the primary presentation.  Retain the
        // response key for old clients, but do not repeat the same evidence as
        // summary prose and metric cards.
        $cards = []; $rows = []; $facts = []; $metricNames = []; $rankingPresentationColumns=[]; $shape = $view['query']['query_shape'];
        $threshold = $shape === 'threshold_count' ? $this->thresholdCondition($view['query']) : null;
        $conditionSummary = null; $conditionListHasMore = false; $conditionListLimit = null; $conditionObjectLabel = null;
        $breakdownHasMore=false;$breakdownLimit=null;$breakdownObjectLabel=null;$breakdownColumns=[];
        foreach ($view['results'] as $row) {
            $registered = MetricReadViewServices::metricCapabilities();
            if (!isset($registered[$row['metric_code'] ?? '']) || !$registered[$row['metric_code']]['ai_query_ready'] || !in_array($row['period'] ?? '', ['current', 'comparison'], true)) {
                throw new RuntimeException('AI_EVIDENCE_INVALID');
            }
            $tooltip = $dictionary->getTooltip($row['metric_code']);
            if (empty($tooltip['user_ready'])) throw new RuntimeException('AI_METRIC_EXPLANATION_NOT_READY');
            if (!in_array($tooltip['name'], $metricNames, true)) $metricNames[] = $tooltip['name'];
            $storageUnit = (string)($row['storage_unit'] ?? 'fen');
            $unit = $this->displayUnit($tooltip, $storageUnit);
            $range = $row['period'] === 'current' ? ['start' => $view['query']['start_date'], 'end' => $view['query']['end_date']] : $view['query']['compare_range'];
            if (in_array($shape,['condition_count','condition_list'],true)) {
                $set=$this->conditionSet($view['query']);
                $subject=$set['subject'];
                if ($storageUnit!=='count'||($row['object_kind']??null)!==$subject||!self::same($row['condition_set']??null,$set)
                    ||!is_int($row['count']??null)||$row['count']<0||!is_array($range)) throw new RuntimeException('AI_EVIDENCE_INVALID');
                $conditionSummary=$this->conditionSummary($row['count'],$set);
                $conditionObjectLabel=$this->conditionObjectLabel($subject,$row);
                if ($shape==='condition_list') {
                    if (!is_array($row['rows']??null)) throw new RuntimeException('AI_EVIDENCE_INVALID');
                    if (array_key_exists('has_more',$row)) {
                        if (!is_bool($row['has_more'])||!is_int($row['list_limit']??null)||$row['list_limit']<1) throw new RuntimeException('AI_EVIDENCE_INVALID');
                        $conditionListHasMore=$row['has_more'];$conditionListLimit=$row['list_limit'];
                    }
                    foreach ($row['rows'] as $object) {
                        [$idKey,$nameKey]=$this->conditionObjectKeys($subject);
                        $identity=$object[$idKey]??null;
                        $validIdentity=$idKey==='entity_id'?$this->conditionEntityIdentity($identity):is_int($identity);
                        if (!$validIdentity||!is_string($object[$nameKey]??null)||!is_array($object['metrics']??null)) throw new RuntimeException('AI_EVIDENCE_INVALID');
                        foreach ($set['conditions'] as $condition) {
                            $code=$condition['metric_code'];$value=$object['metrics'][$code]??null;
                            $metricTip=$dictionary->getTooltip($code);$metric=$registered[$code]??null;
                            if (!is_int($value)||!is_array($metric)||empty($metricTip['user_ready'])) throw new RuntimeException('AI_EVIDENCE_INVALID');
                            $metricUnit=$this->displayUnit($metricTip,(string)$metric['storage_unit']);
                            $rows[]=['label'=>$object[$nameKey],'metric'=>$metricTip['name'],
                                'value'=>$this->metricValue($value,(string)$metric['storage_unit']),'unit'=>$metricUnit,'period'=>'current','period_label'=>'本期'];
                        }
                    }
                }
                continue;
            }
            if ($shape === 'threshold_count') {
                if ($storageUnit !== 'count' || ($row['object_kind'] ?? null) !== 'member'
                    || !self::same($row['aggregate_condition'] ?? null, $threshold)
                    || !is_int($row['count'] ?? null) || $row['count'] < 0 || !is_array($range)) {
                    throw new RuntimeException('AI_EVIDENCE_INVALID');
                }
                $facts[$row['metric_code']][$row['period']] = ['name' => $tooltip['name'], 'count' => $row['count']];
                continue;
            }
            if ($shape==='breakdown') {
                if (!is_string($row['object_kind']??null)||!is_string($row['object_label']??null)
                    ||!is_array($row['rows']??null)||!is_bool($row['has_more']??null)
                    ||!is_int($row['list_limit']??null)||$row['list_limit']<1
                    ||(!is_null($row['object_count']??null)&&(!is_int($row['object_count'])||$row['object_count']<0))) {
                    throw new RuntimeException('AI_EVIDENCE_INVALID');
                }
                if ($breakdownObjectLabel!==null && $breakdownObjectLabel!==$row['object_label']) throw new RuntimeException('AI_EVIDENCE_INVALID');
                $breakdownObjectLabel=$row['object_label'];$breakdownHasMore=$breakdownHasMore||$row['has_more'];
                $breakdownLimit=$breakdownLimit===null?$row['list_limit']:min($breakdownLimit,$row['list_limit']);
                $breakdownColumns[$row['metric_code']]=['label'=>$tooltip['name'],'unit'=>$unit];
                foreach ($row['rows'] as $point) {
                    if (!is_int($point['entity_id']??null)||$point['entity_id']<1||!is_string($point['entity_name']??null)
                        ||$point['entity_name']===''||!is_int($point['amount_cents']??null)) throw new RuntimeException('AI_EVIDENCE_INVALID');
                    $rows[]=['label'=>$point['entity_name'],'metric'=>$tooltip['name'],'metric_code'=>$row['metric_code'],'entity_id'=>$point['entity_id'],
                        'value'=>$this->metricValue($point['amount_cents'],$storageUnit),'unit'=>$unit,
                        'period'=>$row['period'],'period_label'=>$this->periodLabel($row['period'])];
                }
                continue;
            }
            if ($shape === 'trend') {
                foreach ($row['rows'] as $point) $rows[] = [
                    'label' => $point['business_date'], 'metric' => $tooltip['name'],
                    'value' => $this->metricValue($point['amount_cents'], $storageUnit), 'unit' => $unit,
                    'period' => $row['period'], 'period_label' => $this->periodLabel($row['period']),
                ];
                continue;
            }
            if ($shape === 'ranking') {
                $metricLabel = $tooltip['name'];
                if (($row['participant_relation'] ?? false) === true && is_string($row['object_label'] ?? null)) $metricLabel = $row['object_label'] . '关联订单' . $tooltip['name'];
                $supplements=$this->rankingPresentationValues($row,$registered,$dictionary);
                foreach ($supplements as $code=>$supplement) $rankingPresentationColumns[$code]=$supplement['label'];
                foreach ($row['rows'] as $direction => $points) {
                    $valueCounts = [];
                    foreach ($points as $point) {
                        if (!is_int($point['amount_cents'] ?? null)) throw new RuntimeException('AI_EVIDENCE_VALUE_INVALID');
                        $value = (string)$point['amount_cents'];
                        $valueCounts[$value] = ($valueCounts[$value] ?? 0) + 1;
                    }
                    $ordinal = 0; $previous = null;
                    foreach ($points as $index => $point) {
                        $amount = $point['amount_cents'];
                        if ($previous === null || $amount !== $previous) $ordinal = $index + 1;
                        $previous = $amount;
                        $rank = ($valueCounts[(string)$amount] > 1 ? '并列' : '')
                            . ($direction === 'top' ? '第' : '倒数第') . $ordinal . '名';
                        $rendered=[
                            'label' => $point['business_date'] ?? ($point['employee_name'] ?? ($point['member_name'] ?? ($point['entity_name'] ?? ($point['store_name'] ?? ('门店 ID ' . $point['store_id']))))),
                            'metric' => $metricLabel, 'rank' => $rank, 'value' => $this->metricValue($amount, $storageUnit), 'unit' => $unit,
                            'period' => $row['period'], 'period_label' => $this->periodLabel($row['period']),
                        ];
                        // A supplementary column is evidence for the same
                        // already-ranked entity. Missing source rows display a
                        // neutral dash instead of inventing a zero or changing
                        // the rank's population.
                        foreach ($supplements as $code=>$supplement) {
                            $value=$supplement['values'][$point['entity_id']]??null;
                            $rendered['presentation_'.$code]=$value===null?'—':$this->metricValue($value,$supplement['storage_unit']).$supplement['unit'];
                        }
                        $rows[]=$rendered;
                    }
                }
                continue;
            }
            $value = $storageUnit === 'fen'
                ? ($row['amount_cents'] ?? null) : ($row['count'] ?? null);
            $display = $this->metricValue($value, $storageUnit);
            // Preserve the Reader's integer storage value until both periods
            // have been paired. Differences and rates must never use rounded
            // yuan or formatted text as their arithmetic inputs.
            $facts[$row['metric_code']][$row['period']] = ['name' => $tooltip['name'], 'value' => $display,
                'unit' => $unit, 'raw' => $value, 'storage_unit' => $storageUnit];
        }
        if ($shape==='breakdown') {
            // All visible columns are projected from the same verified Reader
            // evidence. One entity stays on one row; missing evidence remains
            // absent so clients render a neutral dash instead of inventing 0.
            $rows=$this->pivotBreakdownRows($rows,$breakdownColumns,$breakdownLimit,$breakdownHasMore);
        }
        $objectKind = $view['query']['business_filters']['object_kind'] ?? 'store';
        $person = $objectKind === 'person';
        $member = $objectKind === 'member';
        $dimensionLabel = null;
        if (!$person && !$member && in_array($shape,['ranking','breakdown'],true)) foreach ($view['results'] as $result) {
            if (($result['object_kind'] ?? null) === $objectKind && is_string($result['object_label'] ?? null)) {
                $dimensionLabel = $result['object_label'];
                break;
            }
        }
        $summary = $shape === 'threshold_count'
            ? $this->thresholdSummary($facts, $threshold)
            : ($conditionSummary??$this->resultSummary($shape, $facts, $rows, $metricNames));
        if ($shape==='breakdown' && $rows) {
            $summary='已列出'.($breakdownObjectLabel??'对象').'的'.implode('、',$metricNames ?: ['所选指标']).'。';
        }
        $conclusion = $summary;
        $periodLabel = '统计时间：' . $view['query']['start_date'] . ' 至 ' . $view['query']['end_date'] . '。';
        $summary .= ($summary === '' ? '' : ' ') . $periodLabel;
        $answer = ['summary' => $summary, 'cards' => $cards];
        $notes = [];
        if ($person && $shape!=='breakdown') {
            // The selection label is produced by the executed population.
            // Do not append a current-employment claim to a historical-fact
            // cohort or another explicitly selected population.
            $notes[] = '人员范围：' . $view['personnel_selection_label'] . '。';
            if ($shape === 'ranking' && $rows) $notes[] = '仅按所选指标排名；相同数值并列，不代表综合评价。';
        }
        if ($member && $shape === 'ranking') {
            if ($rows) $notes[] = '仅按所选指标排名；相同数值并列。';
        }
        if ($conditionListHasMore) $notes[]='符合条件的结果较多，当前展示前'.$conditionListLimit.'条；总数以结论中的精确数量为准。';
        if ($breakdownHasMore) $notes[]='结果较多，当前显示前'.$breakdownLimit.'条，仍有更多结果；可继续按更具体范围查询。';
        if ($dimensionLabel !== null && $shape==='ranking') {
            if ($rows) $notes[] = $dimensionLabel . '按所选指标排名；相同数值并列。';
        }
        if ($view['query']['compare_range']) $notes[] = '对比时间：' . $view['query']['compare_range']['start'] . ' 至 ' . $view['query']['compare_range']['end'] . '。';
        foreach ($notes as $note) $answer['summary'] .= ' ' . $note;
        if ($rows) {
            $columns = [['key' => 'label', 'label' => $shape === 'trend' ? '日期' : ($breakdownObjectLabel ?? ($conditionObjectLabel ?? ($person ? '人员' : ($member ? '会员' : ($dimensionLabel ?? '门店')))))]];
            if ($shape==='breakdown') {
                foreach ($breakdownColumns as $code=>$column) {
                    $columns[]=['key'=>'metric_'.$code,'label'=>$column['label'].($column['unit']===''?'':'（'.$column['unit'].'）')];
                }
            } elseif ($shape==='ranking' && $rankingPresentationColumns!==[]) {
                // The leading value remains the documented sort metric. Other
                // registry columns are contextual evidence, not extra ranks.
                $columns[]=['key'=>'value','label'=>$metricNames[0]??'排序指标'];
                foreach ($rankingPresentationColumns as $code=>$label) $columns[]=['key'=>'presentation_'.$code,'label'=>$label];
            } else {
                $columns=array_merge($columns,[['key' => 'metric', 'label' => '指标'], ['key' => 'value', 'label' => '数值'], ['key' => 'unit', 'label' => '单位']]);
            }
            if (count(array_unique(array_column($rows, 'period'))) > 1) array_unshift($columns, ['key' => 'period_label', 'label' => '期间']);
            if ($shape === 'ranking') $columns[] = ['key' => 'rank', 'label' => '名次'];
            $answer['table'] = ['columns' => $columns, 'rows' => $rows];
        }
        // `summary` remains the backwards-compatible transcript fallback.
        // New clients receive this small evidence-only view and choose the
        // hierarchy in their own layout; no client derives business values.
        $answer['presentation'] = $this->presentation($shape, $facts, $conclusion, $periodLabel, $notes, $objectKind);
        return $answer;
    }

    /**
     * Merge independently verified metric evidence by stable object identity.
     * The first Reader order remains authoritative for display. A missing
     * metric cell is deliberately omitted: only the metric Reader may decide
     * whether absence means zero, unavailable data, or an inapplicable fact.
     *
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,array{label:string,unit:string}> $columns
     * @return array<int,array<string,mixed>>
     */
    private function pivotBreakdownRows(array $rows,array $columns,?int $limit,bool &$hasMore): array
    {
        $grouped=[];$order=[];
        foreach ($rows as $row) {
            $code=$row['metric_code']??null;$entity=$row['entity_id']??null;$period=$row['period']??null;
            if (!is_string($code)||!isset($columns[$code])||!is_int($entity)||$entity<1
                ||!is_string($period)||!in_array($period,['current','comparison'],true)
                ||!is_string($row['label']??null)||!is_string($row['value']??null)) {
                throw new RuntimeException('AI_EVIDENCE_INVALID');
            }
            $key=$period.':'.$entity;
            if (!isset($grouped[$key])) {
                $grouped[$key]=['label'=>$row['label'],'entity_id'=>$entity,
                    'period'=>$period,'period_label'=>$row['period_label']];
                $order[]=$key;
            } elseif ($grouped[$key]['label']!==$row['label']) {
                throw new RuntimeException('AI_EVIDENCE_INVALID');
            }
            $cell='metric_'.$code;
            if (array_key_exists($cell,$grouped[$key]) && $grouped[$key][$cell]!==$row['value']) {
                throw new RuntimeException('AI_EVIDENCE_INVALID');
            }
            $grouped[$key][$cell]=$row['value'];
        }
        $projected=array_map(static function(string $key)use($grouped):array {return $grouped[$key];},$order);
        if ($limit!==null && count($projected)>$limit) {
            $hasMore=true;
            $projected=array_slice($projected,0,$limit);
        }
        return $projected;
    }

    /** @return array<string,array{label:string,storage_unit:string,unit:string,values:array<int,int>}> */
    private function rankingPresentationValues(array $row,array $registered,\app\services\metric\MetricDictionaryServices $dictionary): array
    {
        $out=[];
        foreach ((array)($row['ranking_presentation_metrics']??[]) as $record) {
            if (!is_array($record)||!is_string($record['metric_code']??null)||isset($out[$record['metric_code']])) throw new RuntimeException('AI_EVIDENCE_INVALID');
            $code=$record['metric_code'];$metric=$registered[$code]??null;$storage=$record['storage_unit']??null;
            if (!is_array($metric)||!is_string($storage)||$storage!==($metric['storage_unit']??null)||!is_array($record['values']??null)) throw new RuntimeException('AI_EVIDENCE_INVALID');
            $tip=$dictionary->getTooltip($code);
            if (empty($tip['user_ready'])) throw new RuntimeException('AI_METRIC_EXPLANATION_NOT_READY');
            $values=[];
            foreach ($record['values'] as $value) {
                if (!is_array($value)||!is_int($value['entity_id']??null)||$value['entity_id']<1||!is_int($value['metric_value']??null)||isset($values[$value['entity_id']])) throw new RuntimeException('AI_EVIDENCE_INVALID');
                $values[$value['entity_id']]=$value['metric_value'];
            }
            $out[$code]=['label'=>$tip['name'],'storage_unit'=>$storage,'unit'=>$this->displayUnit($tip,$storage),'values'=>$values];
        }
        return $out;
    }

    /** The condition payload is compiler-verified too, but rendering keeps an independent evidence guard. */
    private function conditionSet(array $query): array
    {
        $set=$query['condition_set']??null;$keys=is_array($set)?array_keys($set):[];sort($keys,SORT_STRING);
        if ($keys!==['conditions','relation','subject']||!is_string($set['subject']??null)
            ||!preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$set['subject'])||!in_array($set['relation']??null,['all','any'],true)
            ||!is_array($set['conditions']??null)||!$set['conditions']) throw new RuntimeException('AI_EVIDENCE_INVALID');
        $capabilities=MetricReadViewServices::metricCapabilities();
        foreach ($set['conditions'] as $condition) if (!is_array($condition)||!is_string($condition['metric_code']??null)
            ||!in_array($set['subject'],(array)($capabilities[$condition['metric_code']]['condition_subjects']??[]),true)
            ||!in_array($condition['operator']??null,['gte','gt','lte','lt','eq'],true)||!is_int($condition['value']??null)) throw new RuntimeException('AI_EVIDENCE_INVALID');
        return $set;
    }

    private function conditionSummary(int $count,array $set): string
    {
        $relation=$set['relation']==='all'?'全部':'任一';
        $labels=['member'=>['客户','人'],'person'=>['人员','人'],'store'=>['门店','家'],
            'order'=>['销售订单','笔'],'sale_line'=>['销售明细','条'],'card'=>['卡项','个'],
            'project'=>['项目','个'],'product'=>['产品','个']];
        $fallback=MetricDefinitionRegistry::overviewObjectLabel((string)($set['subject']??''))??'对象';
        [$label,$unit]=$labels[$set['subject']??'']??[$fallback,'个'];
        return '符合'.$relation.count($set['conditions']).'项条件的'.$label.'共有'.$count.$unit.'。';
    }

    /** @return array{0:string,1:string} */
    private function conditionObjectKeys(string $subject): array
    {
        $keys=['person'=>['employee_id','employee_name'],'member'=>['member_id','member_name'],'store'=>['store_id','store_name']];
        return $keys[$subject]??['entity_id','entity_name'];
    }

    /**
     * Order and sale-fact identities are immutable opaque strings, while sold
     * catalogue items use integer ids. The identity is verified but never
     * rendered; labels and values still come only from Reader evidence.
     */
    private function conditionEntityIdentity($identity): bool
    {
        if (is_int($identity)) return $identity>0;
        return is_string($identity)
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9:_|.\-]{0,127}$/D',$identity)===1;
    }

    private function conditionObjectLabel(string $subject,array $row): string
    {
        $fixed=['person'=>'人员','member'=>'会员','store'=>'门店'];
        if (isset($fixed[$subject])) return $fixed[$subject];
        $label=$row['object_label']??MetricDefinitionRegistry::overviewObjectLabel($subject);
        if (!is_string($label)||trim($label)==='') throw new RuntimeException('AI_EVIDENCE_INVALID');
        return $label;
    }

    /** @return array{version:int,headline:string,facts:array<int,array<string,string>>,period_label:string,notes:array<int,string>} */
    private function presentation(string $shape, array $facts, string $conclusion, string $periodLabel, array $notes, string $objectKind): array
    {
        $items = [];
        $hasComparison = false;
        foreach ($facts as $periods) if (is_array($periods) && isset($periods['comparison'])) $hasComparison = true;
        foreach ($facts as $metricCode=>$periods) {
            if (!is_array($periods)) continue;
            $section=$this->overviewSection((string)$metricCode,$objectKind);
            foreach (['current', 'comparison'] as $period) {
                $fact = $periods[$period] ?? null;
                if (!is_array($fact) || !isset($fact['name'], $fact['value'], $fact['unit'])) continue;
                $item = [
                    'label' => $hasComparison ? $this->periodLabel($period) . '·' . $fact['name'] : $fact['name'],
                    'value' => (string)$fact['value'], 'unit' => (string)$fact['unit'],
                ];
                if ($section!==null) $item['section']=$section;
                $items[]=$item;
            }
            if ($shape==='comparison' && isset($periods['current'],$periods['comparison'])) {
                $change=$this->comparisonChange($periods['current'],$periods['comparison']);
                foreach ([['label'=>'增减值','value'=>$change['difference'],'unit'=>$periods['current']['unit']],
                          ['label'=>'变化率','value'=>$change['rate'],'unit'=>'']] as $measure) {
                    $measure['label']=$periods['current']['name'].'·'.$measure['label'];
                    if ($section!==null) $measure['section']=$section;
                    $items[]=$measure;
                }
            }
        }
        $isMetricOverview = in_array($shape, ['summary', 'comparison'], true) && count($items) > 1;
        return [
            'version' => 1,
            'headline' => $isMetricOverview ? $this->overviewHeadline($objectKind,$hasComparison) : $conclusion,
            'facts' => $isMetricOverview ? $items : [],
            'period_label' => $periodLabel,
            'notes' => array_values(array_filter($notes, 'is_string')),
        ];
    }

    /** Registry membership supplies both the overview group and its visible section. */
    private function overviewSection(string $metricCode,string $objectKind): ?string
    {
        try { $definition=MetricDefinitionRegistry::get($metricCode); }
        catch (\Throwable $ignored) { return null; }
        foreach ((array)($definition['overview']??[]) as $entry) {
            if (is_array($entry) && ($entry['object_kind']??null)===$objectKind && is_string($entry['section']??null)) return $entry['section'];
        }
        return null;
    }

    private function overviewHeadline(string $objectKind,bool $comparison): string
    {
        if ($comparison) return '本期与对比期概览';
        $label=MetricDefinitionRegistry::overviewObjectLabel($objectKind);
        return $label===null ? '本期概览' : '本期'.$label.'概览';
    }

    /** Builds the first visible sentence only from the Reader evidence already on screen. */
    private function resultSummary(string $shape, array $facts, array $rows, array $metricNames): string
    {
        if (in_array($shape, ['summary', 'comparison'], true) && $facts) {
            $parts = [];
            foreach ($facts as $periods) {
                $current = $periods['current'] ?? null;
                if (!is_array($current)) continue;
                $text = $current['name'] . '为' . $current['value'] . $current['unit'];
                $comparison = $periods['comparison'] ?? null;
                if (is_array($comparison)) {
                    $change=$this->comparisonChange($current,$comparison);
                    $text .= '；对比期间为' . $comparison['value'] . $comparison['unit']
                        . '；增减' . $change['difference'] . $current['unit'] . '；变化率' . $change['rate'];
                }
                $parts[] = $text;
            }
            return $parts ? implode('；', $parts) . '。' : '';
        }
        if ($shape === 'ranking' && $rows) {
            $periods = [];
            foreach ($rows as $row) $periods[$row['period'] ?? 'current'][] = $row;
            if (count($periods) > 1) {
                $parts = [];
                foreach (['current', 'comparison'] as $period) if (isset($periods[$period])) {
                    $parts[] = $this->periodLabel($period) . '：' . $this->rankingNarrative($periods[$period]);
                }
                return implode(' ', $parts);
            }
            return $this->rankingNarrative($rows);
        }
        if ($shape === 'trend' && $rows) {
            $last = null;
            foreach ($rows as $row) if (($row['period'] ?? 'current') === 'current') $last = $row;
            $last = $last ?? $rows[count($rows) - 1];
            return $last['label'] . '的' . $last['metric'] . '为' . $last['value'] . $last['unit'] . '。';
        }
        if ($shape==='breakdown' && $rows) return '已按'.implode('、',$metricNames ?: ['所选指标']).'列出分组明细。';
        if (in_array($shape, ['ranking', 'trend','breakdown'], true)) return '本期间没有符合当前筛选条件的' . implode('、', $metricNames ?: ['数据']) . '数据。';
        return '已按您当前报表的数据范围查询。';
    }


    /** @param array<int,array<string,mixed>> $rows */
    private function rankingNarrative(array $rows): string
    {
        $first = $rows[0];
        $metrics = array_values(array_unique(array_filter(array_column($rows, 'metric'), 'is_string')));
        $prefix = count($metrics) === 1 ? '按' . $metrics[0] . '看，' : '';
        // A short ranking is commonly a continuation such as “第二名呢？”.
        // State every verified row; longer rankings remain table-first.
        if (count($rows) > 1 && count($rows) <= 3) {
            return $prefix . implode('；', array_map(static function (array $row): string {
                return ($row['rank'] ?? '第一名') . '是' . $row['label'] . '，'
                    . $row['metric'] . '为' . $row['value'] . $row['unit'];
            }, $rows)) . '。';
        }
        return $prefix . ($first['rank'] ?? '第一名') . '是' . $first['label'] . '，'
            . $first['metric'] . '为' . $first['value'] . $first['unit'] . '。';
    }

    private function periodLabel(string $period): string
    {
        if ($period === 'current') return '本期';
        if ($period === 'comparison') return '对比期';
        throw new RuntimeException('AI_EVIDENCE_INVALID');
    }

    /** The condition is already compiler-verified; this only turns it into customer wording. */
    private function thresholdSummary(array $facts, array $condition): string
    {
        $parts = [];
        foreach ($facts as $periods) {
            $current = $periods['current'] ?? null;
            if (!is_array($current) || !is_int($current['count'] ?? null)) continue;
            $operator = ['gte' => '达到', 'gt' => '超过', 'lte' => '不超过', 'lt' => '低于', 'eq' => '等于'][$condition['operator']];
            $amount = $this->formatInteger(MetricMoneyFormatter::integerYuan($condition['amount_cents']));
            $parts[] = '累计' . $current['name'] . $operator . $amount . '元的会员共有' . $current['count'] . '人。';
        }
        return $parts ? implode('', $parts) : '已按您当前报表的数据范围查询。';
    }

    /** @return array{subject:string,aggregation:string,operator:string,amount_cents:int} */
    private function thresholdCondition(array $query): array
    {
        $condition = $query['aggregate_condition'] ?? null;
        $keys = is_array($condition) ? array_keys($condition) : []; sort($keys, SORT_STRING);
        if ($keys !== ['aggregation', 'amount_cents', 'operator', 'subject']
            || ($condition['subject'] ?? null) !== 'member' || ($condition['aggregation'] ?? null) !== 'period_total'
            || !in_array($condition['operator'] ?? null, ['gte', 'gt', 'lte', 'lt', 'eq'], true)
            || !is_int($condition['amount_cents'] ?? null) || $condition['amount_cents'] < 1) {
            throw new RuntimeException('AI_EVIDENCE_INVALID');
        }
        return $condition;
    }

    private static function same($left, $right): bool
    {
        if (!is_array($left) || !is_array($right) || count($left) !== count($right)) return false;
        ksort($left); ksort($right);
        return $left === $right;
    }

    private function metricValue($value, string $storageUnit): string
    {
        if ($storageUnit === 'fen') return $this->formatInteger(MetricMoneyFormatter::integerYuan($value));
        if ($storageUnit === 'count' && is_int($value)) return (string)$value;
        if ($storageUnit === 'project_count_micro' && is_int($value)) return $this->formatProjectCount($value);
        if ($storageUnit === 'customer_tenth' && is_int($value)) return $this->formatTenths($value);
        throw new RuntimeException('AI_EVIDENCE_VALUE_INVALID');
    }

    /** Compare one registered metric with itself using signed Reader integers. */
    private function comparisonChange(array $current,array $comparison): array
    {
        $currentValue=$current['raw']??null;$baseValue=$comparison['raw']??null;
        if (!is_int($currentValue)||!is_int($baseValue)
            ||($current['storage_unit']??null)!==($comparison['storage_unit']??null)) {
            throw new RuntimeException('AI_EVIDENCE_VALUE_INVALID');
        }
        $difference=$currentValue-$baseValue;
        if (!is_int($difference)) throw new RuntimeException('AI_EVIDENCE_VALUE_INVALID');
        $formatted=$this->metricValue($difference,$current['storage_unit']);
        if ($difference>0) $formatted='+'.$formatted;
        // A zero or negative base has no ordinary business growth rate. A
        // zero-to-zero comparison is explicitly flat instead of NaN or 100%.
        $rate=$baseValue===0?($currentValue===0?'持平':'基期为0，无法计算'):
            ($baseValue<0?'基期为负，无法计算':
                sprintf('%+.1f%%',round(($difference/$baseValue)*100,1)));
        return ['difference'=>$formatted,'rate'=>$rate];
    }

    /** Service allocations are stored as integer tenths so three-person splits stay exact. */
    private function formatTenths(int $value): string
    {
        $negative = $value < 0;
        $digits = ltrim((string)$value, '-');
        $digits = str_pad($digits, 2, '0', STR_PAD_LEFT);
        $whole = ltrim(substr($digits, 0, -1), '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = substr($digits, -1);
        return ($negative ? '-' : '') . $whole . ($fraction === '0' ? '' : '.' . $fraction);
    }

    /** Integer millionths prevent floating-point drift in the visible project count. */
    private function formatProjectCount(int $value): string
    {
        $negative = $value < 0;
        $digits = ltrim((string)$value, '-');
        $digits = str_pad($digits, 7, '0', STR_PAD_LEFT);
        $whole = ltrim(substr($digits, 0, -6), '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = rtrim(substr($digits, -6), '0');
        return ($negative ? '-' : '') . $whole . ($fraction === '' ? '' : '.' . $fraction);
    }

    /** Adds thousands separators only after the authoritative integer-yuan rounding. */
    private function formatInteger(string $value): string
    {
        $negative = substr($value, 0, 1) === '-';
        $digits = $negative ? substr($value, 1) : $value;
        $grouped = preg_replace('/(?<=\\d)(?=(\\d{3})+$)/', ',', $digits);
        return ($negative ? '-' : '') . $grouped;
    }

    /** Display vocabulary is metadata from the registered metric dictionary. */
    private function displayUnit(array $tooltip, string $storageUnit): string
    {
        if ($storageUnit === 'fen') return '元';
        if ($storageUnit === 'count') {
            $unit = $tooltip['display_unit'] ?? null;
            return is_string($unit) && $unit !== '' ? $unit : '个';
        }
        if ($storageUnit === 'project_count_micro') {
            $unit = $tooltip['display_unit'] ?? null;
            return is_string($unit) && $unit !== '' ? $unit : '项';
        }
        if ($storageUnit === 'customer_tenth') {
            $unit = $tooltip['display_unit'] ?? null;
            return is_string($unit) && $unit !== '' ? $unit : '人次';
        }
        throw new RuntimeException('AI_EVIDENCE_VALUE_INVALID');
    }
}
