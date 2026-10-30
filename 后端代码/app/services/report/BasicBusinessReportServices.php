<?php
namespace app\services\report;

/** 基础报表领域路由；每张表复用同一权威Reader，不读取或执行旧配置中的SQL。 */
final class BasicBusinessReportServices
{
    /** 显式责任划分避免未知报表回落到另一种粒度的数据。 */
    public static function owner(string $code): string
    {
        // 已逐表对齐的 Reader 由统一适配层接入；缺源状态继续随结果显式返回。
        $owners = [Sidebar007ReportServices::class, RoundFour007ReportServices::class,
            BalanceOrder007ReadServices::class, OtherReports007ReadServices::class,
            Sidebar007UnconsumedV3ReadServices::class,
            BasicFinancialReportServices::class, BasicActivityReportServices::class,
            BasicEntitlementReportServices::class, BasicSalaryReportServices::class];
        foreach ($owners as $owner) {
            if (in_array($code, $owner::codes(), true)) return $owner;
        }
        throw new \InvalidArgumentException('未配置该基础业务报表');
    }

    /** 静态列合同不需要打开业务数据库，统一查询启动与页面配置均使用同一声明。 */
    public static function fields(string $code): array
    {
        $owner = self::owner($code);
        $fields = (new $owner())->definitions($code);
        $keys = array_column($fields, 'key');
        if (!in_array('row_id', $keys, true)) array_unshift($fields, [
            'key' => 'row_id', 'label' => '行编号', 'type' => 'text', 'defaultVisible' => false,
            'defaultQuick' => false, 'width' => 120, 'fixed' => '', 'money' => false, 'summable' => false,
        ]);
        return array_map(static function ($field) {
            // 领域Reader的通用number在统一查询中采用decimal，ID/计数仍由字段合同决定。
            if (($field['type'] ?? '') === 'number') $field['type'] = 'decimal';
            return $field + ['defaultVisible' => true, 'defaultQuick' => false, 'width' => 140,
                'fixed' => '', 'money' => false, 'summable' => false];
        }, $fields);
    }

    /** 输入仅为查询日期；授权范围由Host提供，禁止接受浏览器伪造的_report_scope。 */
    public static function read(string $code, array $stores, array $input, array $context): array
    {
        $owner = self::owner($code);
        $input['_report_scope'] = ['mode' => $context['basic_scope_mode'] ?? 'none',
            'employee_id' => (int)($context['basic_staff_id'] ?? 0),
            'operator_id' => (int)($context['operator_id'] ?? 0), 'tenant_id' => (string)$context['tenant_id'],
            'visible_store_ids' => $stores];
        $mode = BasicReportCatalogServices::definition($code)['time_mode'];
        if ($mode === 'current') {
            // 隐藏日期不是静默忽略历史请求；当前资产不能冒充历史截止日资产。
            if (!empty($input['as_of_date']) && $input['as_of_date'] !== $context['query_cutoff_date']) {
                throw new \InvalidArgumentException('实时权益仅支持查询当天数据，不支持历史截止日。');
            }
            unset($input['as_of_date']);
        }
        return (new $owner())->read($code, $stores, $input);
    }
}
