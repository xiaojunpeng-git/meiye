<?php
namespace app\services\report\query;

use app\services\query\UnifiedQueryProvider;
use app\services\query\UnifiedQueryExecutionServices;
use app\services\report\BasicBusinessReportServices;
use think\facade\Db;

/** 已授权事实先形成领域行，再由既有统一执行器做完整筛选、排序、合计和分页。 */
abstract class BasicBusinessQueryProvider implements UnifiedQueryProvider
{
    protected const CODE = '';
    private $execution;
    public function __construct(UnifiedQueryExecutionServices $execution) { $this->execution = $execution; }
    public function pageCode(): string { return static::CODE; }

    /** 同一查询事务读取事实和维度，空授权范围不允许退化成全量查询。 */
    public function query(array $context, array $payload): array
    {
        // 侧栏矩阵的门店列需在认证后动态登记，仍复用统一执行器而非前端筛选。
        if (in_array(static::CODE, \app\services\report\Sidebar007ReportServices::codes(), true)) {
            return (new \app\services\report\Sidebar007ReportServices())->query(static::CODE, $context, $payload);
        }
        $stores = BasicRegistrationQueryProvider::stores($context);
        $input = self::dates($payload, (string)$context['query_cutoff_date']);
        $validationQuery = $payload;
        unset($validationQuery['start_date'], $validationQuery['end_date'], $validationQuery['as_of_date']);
        unset($validationQuery['unconsumed_task_id']);
        // 未登记字段、非法条件和表达式先由框架拒绝，避免错误请求触发昂贵的权益读取。
        $this->execution->validatedPlan(static::CODE, [], $validationQuery, $context);
        // 多店当前权益超过同步来源窗口时，先生成同一范围的私有快照；
        // 完成前不返回局部行或合计，完成后仍由统一执行器筛选、排序和分页。
        $async = null;
        if (\app\services\report\BasicUnconsumedTaskServices::applies(static::CODE, $stores)) {
            // 当前权益不能通过后台任务号伪装为历史快照；与单店同步来源
            // 保持同一天的 as_of 校验，再允许入队。
            if ($input['as_of_date'] !== (string)$context['query_cutoff_date']) {
                throw new \InvalidArgumentException('本报表仅支持当天实时资产，不能查询历史日期。');
            }
            $taskId = $payload['unconsumed_task_id'] ?? null;
            if ($taskId !== null && !is_string($taskId)) throw new \InvalidArgumentException('报表任务编号无效');
            $async = (new \app\services\report\BasicUnconsumedTaskServices())
                ->resolve(static::CODE, $stores, $context, $taskId);
            if ($async['pending']) return ['async_report' => $async,
                'queryFields' => BasicBusinessReportServices::fields(static::CODE)];
        }
        $read = function () use ($context, $payload, $stores, $input, $async): array {
            $source = $async === null ? BasicBusinessReportServices::read(static::CODE, $stores, $input, $context)
                : $async['source'];
            $fields = BasicBusinessReportServices::fields(static::CODE);
            // 动态完整性只可收紧列的合计资格，不能增加未登记字段或把未知工资合计成0。
            $dynamic = array_column($source['fields'] ?? [], null, 'key');
            foreach ($fields as &$field) {
                if (isset($dynamic[$field['key']]['summable'])) $field['summable'] = $field['summable'] && $dynamic[$field['key']]['summable'];
            }
            unset($field);
            // 007原表没有合计时，列表与导出都不生成额外汇总，避免只隐藏UI而保留另一套合同。
            $withoutSummary = ($source['table_layout']['summary_position'] ?? '') === 'none';
            $hasSummaryCards = !empty($source['summary_cards']);
            if ($withoutSummary) foreach ($fields as &$field) $field['summable'] = false;
            unset($field);
            // 第4轮缺口处理仅覆盖本轮18表。工资、员工统计等既有报表继续保留
            // 已核实列的合计，不能因其他待核指标被共享提供器一并清空。
            $roundFourCodes = [
                'basic_sales_data','basic_sales_pk','basic_sales_analysis','basic_store_efficiency',
                'basic_store_summary','basic_cross_store_settlement','basic_store_item_sales',
                'basic_item_consumption','basic_writeoff_detail','basic_product_sales',
                'basic_balance_order_detail','basic_balance_order_summary','basic_daily_reconciliation',
                'basic_unconsumed_summary','basic_unconsumed_detail','basic_followup_employee_detail',
                'basic_customer_entitlements','basic_unconsumed_as_of_detail',
            ];
            $unresolved = in_array(static::CODE, $roundFourCodes, true)
                && (!empty($source['data_gaps']) || (($source['complete'] ?? true) === false));
            if ($unresolved) foreach ($fields as &$field) $field['summable'] = false;
            unset($field);
            $sumFields = $source['sum_fields'] ?? [];
            if ($unresolved || ($withoutSummary && !$hasSummaryCards)) $sumFields = [];
            foreach ($fields as $field) if ($field['summable']) $sumFields[] = $field['key'];
            // 固定业务合计不能被清空的自选合计配置隐式变为0；非可加总列不生成伪合计。
            $summaries = $payload['summaries'] ?? [];
            if (!is_array($summaries)) throw new \InvalidArgumentException('合计配置格式不正确');
            if ($unresolved || $withoutSummary) $summaries = [];
            // 数值类型并不等于可加总：ID、单价和比例不能通过自选合计绕过领域声明。
            $allowedSums = array_fill_keys(array_unique($sumFields), true);
            foreach ($summaries as $summary) {
                $key = is_array($summary) ? ($summary['field'] ?? $summary['fieldKey'] ?? $summary['field_key'] ?? '') : '';
                $aggregation = is_array($summary) ? ($summary['aggregation'] ?? $summary['operator'] ?? '') : '';
                if (!isset($allowedSums[$key]) || $aggregation !== 'sum') {
                    throw new \InvalidArgumentException('该列不支持所选合计方式，请刷新报表查询配置');
                }
            }
            foreach (array_unique($sumFields) as $key) {
                $summaries = array_values(array_filter($summaries, static function ($value) use ($key) {
                    return !is_array($value) || ($value['field'] ?? $value['fieldKey'] ?? '') !== $key;
                }));
                $summaries[] = ['field' => $key, 'aggregation' => 'sum'];
            }
            $query = $payload;
            // 任务号只用于认证后定位私有快照，不属于统一查询的业务筛选字段。
            unset($query['start_date'], $query['end_date'], $query['as_of_date'], $query['unconsumed_task_id']);
            $query['limit'] = 20;
            $summaryChunks = array_chunk($summaries, UnifiedQueryExecutionServices::MAX_SUMMARIES);
            $query['summaries'] = $summaryChunks[0] ?? [];
            $rows = $source['rows'] ?? [];
            // 不丢弃未经授权的错误投影后还返回受污染合计；领域行归属异常直接失败。
            foreach ($rows as $row) {
                $ids = isset($row['store_ids']) ? $row['store_ids'] : [(int)($row['store_id'] ?? 0)];
                if (!$ids || array_diff(array_map('intval', $ids), $stores)) throw new \InvalidArgumentException('报表行归属超出当前数据权限');
            }
            $scopeFilter = static function (array $row) use ($stores): bool {
                $ids = isset($row['store_ids']) ? $row['store_ids'] : [(int)($row['store_id'] ?? 0)];
                if (!$ids || array_diff(array_map('intval', $ids), $stores)) {
                    throw new \InvalidArgumentException('报表行归属超出当前数据权限');
                }
                return true;
            };
            $result = $async === null
                ? $this->execution->execute(static::CODE, $rows, [], $query, $context, $scopeFilter)
                : $this->execution->executeBatches(static::CODE, $async['batch_factory'](), [], $query, $context, $scopeFilter);
            // 工资等宽表超过20项可加总列时分批请求同一执行器；源行、过滤和排序保持完全相同。
            foreach (array_slice($summaryChunks, 1) as $chunk) {
                $summaryQuery = $query;
                unset($summaryQuery['export']);
                $summaryQuery['summaries'] = $chunk;
                $part = $async === null
                    ? $this->execution->execute(static::CODE, $rows, [], $summaryQuery, $context, $scopeFilter)
                    : $this->execution->executeBatches(static::CODE, $async['batch_factory'](), [], $summaryQuery, $context, $scopeFilter);
                $result['summaries'] = array_merge($result['summaries'], $part['summaries']);
            }
            // 工具栏指标与当前完整筛选集使用同一执行器结果，不另跑未带筛选的总数。
            $summaryCards = [];
            foreach ($source['summary_cards'] ?? [] as $card) {
                $card['value'] = $unresolved ? null : ($result['summaries'][$card['key'] . ':sum'] ?? null);
                $summaryCards[] = $card;
            }
            return array_merge($result, ['queryFields' => $fields, 'field_sources' => $source['field_sources'],
                'summary_cards' => $summaryCards,
                'can_edit' => false, 'source_kind' => 'unified_business_facts', 'range' => $input,
                'table_layout' => ['fixed' => true, 'header_height' => 42, 'summary_position' => $source['table_layout']['summary_position'] ?? 'first_row'],
                'coverage_start' => array_key_exists('coverage_start', $source) ? $source['coverage_start'] : '2026-08-10',
                'aggregation_caught_up' => true, 'data_as_of' => $source['data_as_of'] ?? time(),
                'complete' => $source['complete'] ?? true, 'gaps' => $source['gaps'] ?? [],
                'data_gaps' => $source['data_gaps'] ?? [],
                'limitations' => $source['limitations'] ?? [], 'drilldowns' => $source['drilldowns'] ?? [],
                'metric_version' => $source['metric_version'] ?? 'basic-unified-facts-v1',
                'unconsumed_task_id' => $async['task_id'] ?? null]);
        };
        // 工资Reader自行建立独立一致读取并拒绝嵌套；后续框架仅处理内存行，不再读取数据库。
        return static::CODE === 'basic_salary_realtime' ? $read() : Db::transaction($read);
    }

    /** 日期属于事实读取范围，不允许无效日期、反向范围或浏览器传入SQL。 */
    public static function dates(array $payload, string $today): array
    {
        $start = (string)($payload['start_date'] ?? substr($today, 0, 7) . '-01');
        $end = (string)($payload['end_date'] ?? $today);
        $asOf = (string)($payload['as_of_date'] ?? $end);
        foreach ([$start, $end, $asOf] as $value) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('Asia/Shanghai'));
            if (!$date || $date->format('Y-m-d') !== $value) throw new \InvalidArgumentException('报表日期格式不正确');
        }
        if ($start > $end || $end > $today || $asOf > $today) throw new \InvalidArgumentException('报表日期范围不正确，不能查询未来日期');
        if ((strtotime($end) - strtotime($start)) / 86400 > 366) throw new \InvalidArgumentException('单次报表查询不能超过一年');
        return ['start_date' => $start, 'end_date' => $end, 'as_of_date' => $asOf];
    }

    /** 本入口仅提供同步同口径导出；缺少事实日期的旧任务禁止猜测当月后执行。 */
    public function executeFrozenPlan(array $context, array $plan, string $exportScope, array $fieldKeys): array
    {
        throw new \InvalidArgumentException('该报表请从当前查询页面导出，不能重放缺少业务日期的后台计划');
    }
}
