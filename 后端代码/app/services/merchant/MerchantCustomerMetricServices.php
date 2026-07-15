<?php
namespace app\services\merchant;

use app\services\BaseServices;
use app\services\metric\MetricDictionaryServices;
use think\facade\Db;

/**
 * 客户类指标统一出口（客群 / 我的客户 / 数仓客户分析必须共用）
 *
 * 新增客户口径（产品确认 2026-07-15）：A = COUNT(DISTINCT uid)
 * 时间字段：store_user.add_time；范围：scope_store_ids
 */
class MerchantCustomerMetricServices extends BaseServices
{
    public const CODE_NEW_CUSTOMER = 'new_customer';

    /** @var string 产品确认 A；禁止改为 store_user_rows 除非产品再确认 */
    public const NEW_CUSTOMER_MODE = 'distinct_uid';

    /**
     * 新增客户统一聚合（唯一数值出口）
     *
     * @param int[] $scopeStoreIds
     * @return array{
     *   metric_code: string,
     *   title: string,
     *   number: ?int,
     *   developing: bool,
     *   note: string,
     *   source: string,
     *   formula: string,
     *   time_field: string,
     *   detail_api: string|null,
     *   detail_developing: bool,
     *   tooltip_api: string
     * }
     */
    public function newCustomerMetric(array $scopeStoreIds, int $startTs, int $endTs): array
    {
        /** @var MetricDictionaryServices $dict */
        $dict = app()->make(MetricDictionaryServices::class);
        $def = $dict->getByCode(self::CODE_NEW_CUSTOMER) ?: [];

        $out = [
            'metric_code' => self::CODE_NEW_CUSTOMER,
            'title' => (string)($def['name'] ?? '新增客户数'),
            'number' => null,
            'developing' => true,
            'note' => (string)($def['note'] ?? ''),
            'source' => (string)($def['source'] ?? 'MerchantCustomerMetricServices::newCustomerMetric'),
            'formula' => (string)($def['formula'] ?? ''),
            'time_field' => (string)($def['time_field'] ?? 'store_user.add_time'),
            'detail_api' => null,
            'detail_developing' => true,
            'tooltip_api' => 'metric/dictionary/' . self::CODE_NEW_CUSTOMER,
        ];

        $scopeStoreIds = array_values(array_unique(array_filter(array_map('intval', $scopeStoreIds))));
        if (!$scopeStoreIds || $startTs <= 0 || $endTs <= 0 || $endTs < $startTs) {
            $out['note'] = '无有效门店范围或时间窗，不返回数值';
            return $out;
        }

        try {
            // 口径 A：去重客户数（产品确认，唯一实现）
            $n = (int)Db::name('store_user')
                ->whereIn('store_id', $scopeStoreIds)
                ->whereBetween('add_time', [$startTs, $endTs])
                ->count('DISTINCT uid');
            $out['number'] = $n;
            $out['developing'] = false;
            $out['detail_developing'] = false;
            // 明细：商家客户页按 store_user.add_time 日期窗下钻（前端带 start_date/end_date）
            $out['detail_api'] = '/pages/merchant/customer/index';
            $out['note'] = '口径 A：store_user.add_time + COUNT(DISTINCT uid)，范围=scope_store_ids';
            return $out;
        } catch (\Throwable $e) {
            $out['note'] = '新增客户统计失败';
            return $out;
        }
    }
}
