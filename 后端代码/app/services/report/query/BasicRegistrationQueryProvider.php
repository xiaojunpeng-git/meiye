<?php
namespace app\services\report\query;

use app\services\query\UnifiedQueryExecutionServices;
use app\services\query\UnifiedQueryException;
use app\services\query\UnifiedQueryProvider;
use think\facade\Db;

/**
 * 刷单登记的中立领域提供器。旧 salary_store 是人工登记权威源，不是经营事实。
 * 必须先按宿主认证范围取源行，再交统一执行器完成过滤、排序、合计和分页。
 */
final class BasicRegistrationQueryProvider implements UnifiedQueryProvider
{
    public const PAGE_CODE = 'basic_brush_registration';
    private $execution;

    public function __construct(UnifiedQueryExecutionServices $execution) { $this->execution = $execution; }
    public function pageCode(): string { return self::PAGE_CODE; }

    /** 上限不是截断：超过框架安全窗口直接拒绝，绝不能输出不完整的合计或导出。 */
    public function query(array $context, array $payload): array
    {
        // 源行和审计序号在同一次一致读取内获得，不能把旧内容配上新编辑序号。
        return Db::transaction(function () use ($context, $payload): array {
            return $this->read($context, $payload);
        });
    }

    private function read(array $context, array $payload): array
    {
        $stores = self::stores($context);
        $tableId = self::legacyTableId();
        $source = Db::name('salary_store')->alias('r')
            ->leftJoin('system_store s', 's.id=r.store_id')
            ->whereIn('r.store_id', $stores)->whereFindInSet('r.table_ids', $tableId)
            ->field('r.*,s.name AS store_name')->order('r.id', 'desc')
            ->limit(UnifiedQueryExecutionServices::MAX_SOURCE_ROWS + 1)->select()->toArray();
        if (count($source) > UnifiedQueryExecutionServices::MAX_SOURCE_ROWS) {
            throw new UnifiedQueryException('UNIFIED_QUERY_SOURCE_WINDOW_TOO_LARGE', '登记数据超过安全查询范围，需启用后端下推查询。', []);
        }
        $versions = [];
        if ($source) {
            foreach (Db::name('basic_report_registration_audit')->whereIn('registration_id', array_column($source, 'id'))
                ->field('registration_id,MAX(record_version) AS record_version')->group('registration_id')->select()->toArray() as $row) {
                $versions[(int)$row['registration_id']] = (int)$row['record_version'];
            }
        }
        $rows = [];
        foreach ($source as $row) $rows[] = self::project($row, $versions[(int)$row['id']] ?? 1);
        $query = array_merge(['page' => 1, 'limit' => 20, 'sorts' => [['field' => 'registration_id', 'direction' => 'desc']],
            'summaries' => [['field' => 'amount', 'aggregation' => 'sum']]], $payload);
        // 普通页面固定20条；导出仍复用统一执行器的完整筛选集，不自行拼另一条查询。
        $query['limit'] = 20;
        // 该表金额合计是固定业务列，设置面板未传合计或清空自选项也不能把完整合计变成0。
        if (!is_array($query['summaries'])) throw new \InvalidArgumentException('合计配置格式不正确');
        $hasAmountSum = false;
        foreach ($query['summaries'] as $summary) {
            if (is_array($summary) && ($summary['field'] ?? $summary['fieldKey'] ?? $summary['field_key'] ?? '') === 'amount'
                && ($summary['aggregation'] ?? $summary['operator'] ?? '') === 'sum') $hasAmountSum = true;
        }
        if (!$hasAmountSum) $query['summaries'][] = ['field' => 'amount', 'aggregation' => 'sum'];
        $result = $this->execution->execute(self::PAGE_CODE, $rows, [], $query, $context,
            static function (array $row) use ($stores): bool { return in_array($row['_store_id'], $stores, true); });
        $records = $result['rows'];
        foreach ($records as &$record) { $record['store_id'] = $record['_store_id']; unset($record['_store_id']); }
        unset($record);
        return array_merge($result, ['rows' => $records, 'fields' => BasicRegistrationQueryRegistrar::fields(),
            'field_sources' => self::sources(), 'can_edit' => !empty($context['basic_registration_write']),
            'source_kind' => 'manual_registration']);
    }

    /** 冻结计划的字段和条件由统一执行器验证；执行时仍使用重新认证后的宿主范围。 */
    public function executeFrozenPlan(array $context, array $plan, string $exportScope, array $fieldKeys): array
    {
        if (($plan['page_code'] ?? '') !== self::PAGE_CODE) throw new \InvalidArgumentException('导出计划与报表不匹配');
        return $this->query($context, [
            'filters' => $plan['filters'] ?? [], 'filterRelation' => $plan['filter_relation'] ?? 'all',
            'topFilterConditions' => $plan['top_filters'] ?? [], 'keywordFilters' => $plan['keyword_filters'] ?? [],
            'quickFilters' => $plan['quick_filters'] ?? [], 'sorts' => $plan['sorts'] ?? [],
            'summaries' => $plan['summaries'] ?? [], 'visibleFields' => $plan['visible_fields'] ?? [],
            'page' => $plan['pagination']['page'] ?? 1,
            'export' => ['scope' => $exportScope, 'fields' => $fieldKeys],
        ]);
    }

    /** 不以固定数字9识别刷单配置；不同客户迁入后的配置ID可能不同。 */
    public static function legacyTableId(): int
    {
        $rows = Db::name('salary_table')->where('name', '刷单登记')->where('type', 4)->column('id');
        if (count($rows) !== 1) throw new \InvalidArgumentException('刷单登记来源配置缺失或不唯一');
        return (int)$rows[0];
    }

    /** 空范围绝不代表全店，个人权限不能借“参与门店集合”扩大成人工登记全店权限。 */
    public static function stores(array $context): array
    {
        if (($context['basic_scope_mode'] ?? '') === 'self_participant') throw new \InvalidArgumentException('个人权限不能查看无本人归属的刷单登记');
        $stores = array_values(array_unique(array_filter(array_map('intval', $context['visible_store_ids'] ?? []), static function ($id) { return $id > 0; })));
        if (!$stores) throw new \InvalidArgumentException('当前账号没有可查看的门店');
        return $stores;
    }

    /** 保留旧数据原始金额分精度；缺失日期返回null，不生成1970年或今天的伪日期。 */
    public static function project(array $row, int $version): array
    {
        $info = json_decode((string)($row['salary_info'] ?? ''), true);
        if (!is_array($info)) throw new \InvalidArgumentException('登记内容格式异常，请核对原记录');
        $amount = self::cents($info['shuadanjine'] ?? '0');
        return [
            'registration_id' => (int)$row['id'], '_store_id' => (int)$row['store_id'],
            'store_name' => (string)($row['store_name'] ?? ''),
            'platform' => (string)($info['shuadanpingtai'] ?? ''),
            'registered_date' => (int)($row['date'] ?? 0) > 0
                ? (new \DateTimeImmutable('@' . (int)$row['date']))->setTimezone(new \DateTimeZone('Asia/Shanghai'))->format('Y-m-d') : null,
            'coupon_code' => (string)($info['shuadanquanma'] ?? ''),
            'amount' => $amount / 100, 'amount_cents' => $amount,
            'registration_type' => (string)($info['quanmabeizhu'] ?? ''),
            'record_version' => $version,
            'source_hash' => self::sourceHash($row),
        ];
    }

    /** 旧编辑入口没有版本审计，按原业务内容识别变化，不能只依赖新入口的审计序号。 */
    public static function sourceHash(array $row): string
    {
        return hash('sha256', json_encode([(int)$row['id'], (int)$row['store_id'], (int)($row['date'] ?? 0),
            (string)$row['salary_info'], (string)$row['table_ids'], (int)($row['staff_id'] ?? 0)], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** 历史金额由十进制文本解析，不经浮点乘100；新写入的整元校验由写服务执行。 */
    public static function cents($value): int
    {
        $text = trim((string)$value);
        if ($text === '') $text = '0';
        if (!preg_match('/^(-?)([0-9]{1,10})(?:\.([0-9]{1,2}))?$/D', $text, $parts)) throw new \InvalidArgumentException('登记金额格式异常');
        return ($parts[1] === '-' ? -1 : 1) * ((int)$parts[2] * 100 + (int)str_pad($parts[3] ?? '', 2, '0'));
    }

    public static function sources(): array
    {
        return ['platform' => '登记时填写的平台，不代表实际到账方式。', 'store_name' => '该条人工登记归属的门店。',
            'registered_date' => '原登记核销日期；未填写时保留空值。', 'coupon_code' => '人工登记的券码，不自动生成核销事实。',
            'amount' => '该条人工登记的金额，不自动计入现金、消耗或员工业绩。', 'registration_type' => '登记时填写的门店或探店类型。'];
    }
}
