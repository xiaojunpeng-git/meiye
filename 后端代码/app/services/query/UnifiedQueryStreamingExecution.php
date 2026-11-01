<?php
namespace app\services\query;

/**
 * 大结果集执行路径。领域服务每次提供有界批次；过滤/比较/投影仍使用统一执行器。
 * 普通查询优先领域SQL分页，此路径承接需逐行派生计算的高级查询与后台导出。
 * 查询临时文件不共享、不写业务表；导出的行粒度继续由领域 exportRows 决定。
 */
trait UnifiedQueryStreamingExecution
{
    public function executeBatches(string $pageCode, iterable $batches, array $definitions, array $query,
        array $context, callable $scopeFilter, ?callable $additionalMatch = null): array
    {
        $query = $this->normalizeUiQuery($pageCode, $query, (array)($context['permissions'] ?? []));
        $plan = $this->validatedPlan($pageCode, $definitions, $query, $context);
        $definitions = $this->prepareDefinitions($pageCode, $definitions, (array)($context['permissions'] ?? []));
        $fields = $this->fieldIndex($pageCode, (array)($context['permissions'] ?? []), $definitions);
        $checkpoint = isset($context['_export_checkpoint']) && is_callable($context['_export_checkpoint']) ? $context['_export_checkpoint'] : null;
        // 空行执行仅生成原有元数据/指纹并验证导出字段，不改变实际聚合结果。
        $metadataQuery = $query; unset($metadataQuery['export']);
        $result = $this->execute($pageCode, [], $definitions, $metadataQuery, $context, $scopeFilter, $additionalMatch);
        $export = isset($query['export']) ? (array)$query['export'] : null;
        if ($export !== null) $this->exportRows([], [], $export, $fields);
        $authorized = new UnifiedQueryExternalRows(); $stable = $plan['stable_row_key'];
        $scopeContext = $context;
        $scopeContext['requested_data_scope'] = (string)($query['dataScope'] ?? 'normal');
        $scopeContext['requested_business_status'] = (string)($query['businessStatus'] ?? '');
        foreach ($batches as $batch) {
            if (!is_array($batch) || count($batch) > 200) throw new \RuntimeException('UNIFIED_QUERY_BATCH_INVALID');
            foreach ($batch as $row) {
                if (!is_array($row)) throw new \RuntimeException('UNIFIED_QUERY_SOURCE_ROW_INVALID');
                if (!$scopeFilter($row, $scopeContext)) continue;
                $this->assertStableRows([$row], $stable); $authorized->append($row);
            }
            if ($checkpoint) $checkpoint();
        }
        // 跨批次重复也必须拒绝；外部排序只保留相邻ID，不能用全量seen数组占内存。
        $authorized->sort(static function (array $a, array $b) use ($stable): int { return strcmp((string)$a[$stable], (string)$b[$stable]); }, $checkpoint);
        $previous = null;
        foreach ($authorized as $row) {
            $key = (string)$row[$stable];
            if ($key === $previous) throw new UnifiedQueryException('UNIFIED_QUERY_STABLE_KEY_DUPLICATE', '查询结果标识重复，已停止分页以避免重复或漏项。', []);
            $previous = $key;
        }
        $aggregates = []; $aggregateEvaluator = new StructuredExpressionStreamingEvaluator();
        foreach ($definitions as $key => $definition) if (!empty($definition['uses_aggregate'])) {
            $aggregates[$key] = $aggregateEvaluator->evaluateRows($definition['expression'], ['query_cutoff_date'=>$plan['query_cutoff_date']], $authorized, $checkpoint);
        }
        $filtered = new UnifiedQueryExternalRows(); $visited = 0;
        foreach ($authorized as $source) {
            $row = $source;
            foreach ($definitions as $key => $definition) {
                $row[$key] = array_key_exists($key, $aggregates) ? $aggregates[$key]
                    : $this->evaluator->evaluate($definition['expression'], $source, ['query_cutoff_date'=>$plan['query_cutoff_date']], []);
            }
            if ($this->streamMatches($row, $plan['filters'], $fields, $plan['filter_relation'])
                && $this->streamMatches($row, $plan['top_filters'], $fields, 'all')
                && $this->streamMatches($row, $plan['quick_filters'], $fields, 'all')
                && $this->streamMatches($row, $plan['keyword_filters'], $fields, 'any', $additionalMatch)) $filtered->append($row);
            if (++$visited % 256 === 0 && $checkpoint) $checkpoint();
        }
        unset($authorized);
        $filtered->sort(function (array $a, array $b) use ($plan, $fields): int {
            foreach ($plan['sorts'] as $sort) {
                $key = $sort['field_key']; $cmp = $this->compare($a[$key] ?? null, $b[$key] ?? null, $fields[$key]['type']);
                if ($cmp !== 0) return $sort['direction'] === 'desc' ? -$cmp : $cmp;
            }
            return 0;
        }, $checkpoint);
        $total = count($filtered); $offset = ($plan['pagination']['page'] - 1) * $plan['pagination']['limit'];
        $pageRows = []; $summaryState = []; $buckets = []; $index = 0;
        $exportRows = $export !== null ? new UnifiedQueryExternalRows() : null;
        foreach ($filtered as $row) {
            $inPage = $index >= $offset && $index < $offset + $plan['pagination']['limit'];
            if ($inPage) $pageRows[] = $row;
            $this->streamAccumulate($summaryState, $row, $plan['summaries'], $fields);
            if ($plan['groups']) {
                $values = []; foreach ($plan['groups'] as $key) $values[$key] = $row[$key] ?? null;
                $bucket = UnifiedQueryJson::encode($values);
                if (!isset($buckets[$bucket])) $buckets[$bucket] = ['values'=>$values,'count'=>0,'state'=>[]];
                // 分组输出自身仍可能很大：限制结果组而非裁掉订单，不返回截断合计。
                if (count($buckets) > 10000) throw new UnifiedQueryException('UNIFIED_QUERY_GROUP_WINDOW_TOO_LARGE', '分组结果超过一万组，请减少分组维度。', []);
                ++$buckets[$bucket]['count']; $this->streamAccumulate($buckets[$bucket]['state'], $row, $plan['summaries'], $fields);
            }
            if ($exportRows !== null && (($export['scope'] ?? 'query') === 'query' || $inPage)) {
                foreach ($this->exportRows([$row], [$row], $export, $fields) as $line) $exportRows->append($line);
            }
            if (++$index % 256 === 0 && $checkpoint) $checkpoint();
        }
        $result['rows'] = $pageRows;
        $result['pagination'] = $plan['pagination'] + ['total'=>$total,'pages'=>(int)ceil($total / $plan['pagination']['limit'])];
        $result['summaries'] = $this->streamSummaryResult($summaryState, $plan['summaries'], $fields);
        $result['groups'] = [];
        foreach ($buckets as $bucket) $result['groups'][] = ['values'=>$bucket['values'],'count'=>$bucket['count'],
            'summaries'=>$this->streamSummaryResult($bucket['state'], $plan['summaries'], $fields)];
        $result['exportRows'] = $exportRows ?? [];
        return $result;
    }

    private function streamMatches(array $row, array $filters, array $fields, string $relation, ?callable $extra = null): bool
    {
        if (!$filters) return true;
        foreach ($filters as $filter) {
            $key = $filter['field_key']; $match = $this->matches($row[$key] ?? null, $filter['operator'], $filter['value'], $fields[$key]['type']);
            if ($relation === 'all' && !$match) return false;
            if ($relation === 'any' && $match) return true;
        }
        return $relation === 'all' || ($extra !== null && $extra($row));
    }

    /** 金额沿用bc精度，只记录count/sum/extremum，不保留聚合的所有明细。 */
    private function streamAccumulate(array &$state, array $row, array $summaries, array $fields): void
    {
        foreach ($summaries as $summary) {
            $field = $summary['field_key']; $op = $summary['operator']; $key = $field . ':' . $op;
            $value = $row[$field] ?? null;
            if ($value === null) continue;
            if (!isset($state[$key])) $state[$key] = ['count'=>0,'sum'=>'0','selected'=>$value];
            ++$state[$key]['count'];
            if ($op === 'sum' || $op === 'average') $state[$key]['sum'] = bcadd($state[$key]['sum'], (string)$value, StructuredExpressionEvaluator::INTERNAL_SCALE);
            elseif ($op === 'minimum' || $op === 'maximum') {
                $cmp = $this->compare($value, $state[$key]['selected'], $fields[$field]['type']);
                if ($op === 'minimum' ? $cmp < 0 : $cmp > 0) $state[$key]['selected'] = $value;
            }
        }
    }
    private function streamSummaryResult(array $state, array $summaries, array $fields): array
    {
        $out = [];
        foreach ($summaries as $summary) {
            $field = $summary['field_key']; $op = $summary['operator']; $key = $field . ':' . $op;
            $item = $state[$key] ?? ['count'=>0,'sum'=>'0','selected'=>null]; $type = $fields[$field]['type'];
            if ($op === 'count') $value = $item['count'];
            elseif (!$item['count']) $value = null;
            elseif ($op === 'minimum' || $op === 'maximum') $value = $item['selected'];
            else {
                $sum = $op === 'average' ? bcdiv($item['sum'], (string)$item['count'], StructuredExpressionEvaluator::INTERNAL_SCALE) : $item['sum'];
                $value = $this->roundDecimal($sum, $type === 'amount' ? 2 : ($type === 'integer' && $op === 'sum' ? 0 : 6));
            }
            $out[$key] = $value;
        }
        return $out;
    }
}
