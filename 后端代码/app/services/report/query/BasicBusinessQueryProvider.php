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
        $stores = BasicRegistrationQueryProvider::stores($context);
        // The historical cross-store comparison starts at the calendar year;
        // an omitted date must not silently reduce it to the current month.
        if (static::CODE === 'basic_cross_store_settlement' && !isset($payload['start_date'])) {
            $payload['start_date'] = substr((string)$context['query_cutoff_date'], 0, 4) . '-01-01';
        }
        $input = self::dates($payload, (string)$context['query_cutoff_date']);
        $validationQuery = $payload;
        unset($validationQuery['start_date'], $validationQuery['end_date'], $validationQuery['as_of_date']);
        // 未登记字段、非法条件和表达式先由框架拒绝，避免错误请求触发昂贵的权益读取。
        $this->execution->validatedPlan(static::CODE, [], $validationQuery, $context);
        $read = function () use ($context, $payload, $stores, $input): array {
            $source = BasicBusinessReportServices::read(static::CODE, $stores, $input, $context);
            $fields = BasicBusinessReportServices::fields(static::CODE);
            // 动态完整性只可收紧列的合计资格，不能增加未登记字段或把未知工资合计成0。
            $dynamic = array_column($source['fields'] ?? [], null, 'key');
            $salaryReadonly=static::CODE==='basic_salary_realtime'&&!empty($source['salary_readonly']);
            foreach ($fields as &$field) {
                if($salaryReadonly)$field['editable']=false;
                if (isset($dynamic[$field['key']]['summable'])) $field['summable'] = $field['summable'] && $dynamic[$field['key']]['summable'];
            }
            unset($field);
            $unresolved = !empty($source['data_gaps']);
            if ($unresolved) foreach ($fields as &$field) $field['summable'] = false;
            unset($field);
            $sumFields = $source['sum_fields'] ?? [];
            if ($unresolved) $sumFields = [];
            foreach ($fields as $field) if ($field['summable']) $sumFields[] = $field['key'];
            // 固定业务合计不能被清空的自选合计配置隐式变为0；非可加总列不生成伪合计。
            $summaries = $payload['summaries'] ?? [];
            if (!is_array($summaries)) throw new \InvalidArgumentException('合计配置格式不正确');
            if ($unresolved) $summaries = [];
            foreach (array_unique($sumFields) as $key) {
                $summaries = array_values(array_filter($summaries, static function ($value) use ($key) {
                    return !is_array($value) || ($value['field'] ?? $value['fieldKey'] ?? '') !== $key;
                }));
                $summaries[] = ['field' => $key, 'aggregation' => 'sum'];
            }
            $query = $payload;
            unset($query['start_date'], $query['end_date'], $query['as_of_date']);
            // 007 跨店结算默认按门店 ID 倒序；仅在请求未显式排序时
            // 应用该口径，筛选、导出和分页继续共用同一执行器。
            if (static::CODE === 'basic_cross_store_settlement' && empty($query['sorts'])) {
                $query['sorts'] = [['fieldKey' => 'store_id', 'direction' => 'desc']];
            }
            $query['limit'] = 20;
            $summaryChunks = array_chunk($summaries, UnifiedQueryExecutionServices::MAX_SUMMARIES);
            $query['summaries'] = $summaryChunks[0] ?? [];
            $rows = $source['rows'];
            // 不丢弃未经授权的错误投影后还返回受污染合计；领域行归属异常直接失败。
            foreach ($rows as $row) {
                $ids = isset($row['store_ids']) ? $row['store_ids'] : [(int)($row['store_id'] ?? 0)];
                if (!$ids || array_diff(array_map('intval', $ids), $stores)) throw new \InvalidArgumentException('报表行归属超出当前数据权限');
            }
            $result = $this->execution->execute(static::CODE, $rows, [], $query, $context, static function () { return true; });
            // 工资等宽表超过20项可加总列时分批请求同一执行器；源行、过滤和排序保持完全相同。
            foreach (array_slice($summaryChunks, 1) as $chunk) {
                $summaryQuery = $query;
                unset($summaryQuery['export']);
                $summaryQuery['summaries'] = $chunk;
                $part = $this->execution->execute(static::CODE, $rows, [], $summaryQuery, $context, static function () { return true; });
                $result['summaries'] = array_merge($result['summaries'], $part['summaries']);
            }
            return array_merge($result, ['queryFields' => $fields, 'field_sources' => $source['field_sources'],
                'can_edit' => false, 'salary_state' => $source['salary_state'] ?? null,
                'salary_can_edit' => static::CODE === 'basic_salary_realtime' && !$salaryReadonly && !empty($context['salary_edit'])
                    && (strpos((string)($context['basic_actor_key'] ?? ''),'platform:')===0 || empty($source['salary_state']['frozen'])),
                'salary_can_freeze' => static::CODE === 'basic_salary_realtime' && !$salaryReadonly && !empty($context['salary_freeze']), 'source_kind' => $source['source_kind'] ?? 'unified_business_facts', 'range' => $input,
                'salary_readonly'=>$salaryReadonly,'realtime_start_date'=>$source['realtime_start_date']??null,'saved_at'=>$source['saved_at']??null,
                'table_layout' => ['fixed' => true, 'header_height' => 42, 'summary_position' => 'first_row'],
                'coverage_start' => array_key_exists('coverage_start', $source) ? $source['coverage_start'] : '2026-08-10',
                'coverage' => $source['coverage'] ?? '',
                'aggregation_caught_up' => true, 'data_as_of' => $source['data_as_of'] ?? time(),
                'complete' => $source['complete'] ?? true, 'gaps' => $source['gaps'] ?? [],
                'data_gaps' => $source['data_gaps'] ?? [],
                'limitations' => $source['limitations'] ?? [], 'drilldowns' => $source['drilldowns'] ?? [],
                'metric_version' => $source['metric_version'] ?? 'basic-unified-facts-v1']);
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
