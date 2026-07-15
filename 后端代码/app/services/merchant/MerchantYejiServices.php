<?php
namespace app\services\merchant;

use app\services\BaseServices;
use app\services\store\SystemStoreStaffServices;
use app\services\yeji\SatffYejiServices;
use think\exception\ValidateException;

/**
 * 商家端本人业绩（强制 uid + active_store_id∈scope 解析 staff_id，拒绝客户端 staff_id）
 */
class MerchantYejiServices extends BaseServices
{
    /**
     * 解析当前商家上下文下的本人员工 id
     */
    public function resolveSelfStaffId(int $uid, array $access): int
    {
        if ($uid <= 0) {
            throw new ValidateException('未登录');
        }
        /** @var MerchantAccessServices $accessServices */
        $accessServices = app()->make(MerchantAccessServices::class);
        $scopeStoreIds = $accessServices->requireScopeStoreIds($access);
        $storeId = (int)($access['active_store_id'] ?? 0);
        if ($storeId <= 0 || !in_array($storeId, $scopeStoreIds, true)) {
            throw new ValidateException('当前门店不在可访问范围内');
        }
        try {
            /** @var SystemStoreStaffServices $staffServices */
            $staffServices = app()->make(SystemStoreStaffServices::class);
            $staffRow = $staffServices->getStaffInfoByUid($uid, $storeId);
            $staff = $staffRow ? (is_array($staffRow) ? $staffRow : $staffRow->toArray()) : null;
            $staffId = (int)($staff['id'] ?? 0);
        } catch (\Throwable $e) {
            $staffId = 0;
        }
        if ($staffId <= 0) {
            throw new ValidateException('未找到当前门店员工档案');
        }
        return $staffId;
    }

    /**
     * 本人业绩概览（与个人业绩页同源 staffInfo）
     * @param array $filter start_date / end_date / date_type
     */
    public function selfOverview(int $uid, array $access, array $filter): array
    {
        $staffId = $this->resolveSelfStaffId($uid, $access);
        $start = (string)($filter['start_date'] ?? date('Y-m-d'));
        $end = (string)($filter['end_date'] ?? date('Y-m-d'));
        $time = str_replace('-', '/', $start) . '-' . str_replace('-', '/', $end);

        /** @var SatffYejiServices $yejiServices */
        $yejiServices = app()->make(SatffYejiServices::class);
        $info = $yejiServices->staffInfo([
            'staff_id' => $staffId,
            'created_time' => $time,
            'sum_type' => 1,
        ]);
        if (!is_array($info)) {
            $info = [];
        }

        /** @var MerchantMetricPresenter $presenter */
        $presenter = app()->make(MerchantMetricPresenter::class);
        $metrics = $presenter->presentStaffYejiInfo($info);

        return [
            'staff' => [
                'staff_id' => $staffId,
                'staff_name' => (string)($info['staff_name'] ?? ''),
                'phone' => (string)($info['phone'] ?? ''),
            ],
            'metrics' => $metrics,
            'metrics_mode' => 'staff_self',
            'filter' => [
                'date_type' => $filter['date_type'] ?? 'custom',
                'start_date' => $start,
                'end_date' => $end,
                'created_time' => $time,
            ],
            'scope' => [
                'store_id' => (int)($access['active_store_id'] ?? 0),
                'scope_store_ids' => $access['scope_store_ids'] ?? [],
            ],
            'note' => '口径与历史「个人业绩」页一致；staff_id 仅服务端按登录账号+当前门店解析',
            'updated_at' => date('Y-m-d H:i:s'),
            'metric_version' => 'SatffYejiServices::staffInfo + MetricDictionaryServices',
        ];
    }

    /**
     * 本人业绩明细列表（销售/劳动）；忽略并拒绝信任请求中的 staff_id
     */
    public function selfDetail(int $uid, array $access, array $filter): array
    {
        $staffId = $this->resolveSelfStaffId($uid, $access);
        $start = (string)($filter['start_date'] ?? date('Y-m-d'));
        $end = (string)($filter['end_date'] ?? date('Y-m-d'));
        $time = str_replace('-', '/', $start) . '-' . str_replace('-', '/', $end);
        $sumType = (int)($filter['sum_type'] ?? 1);
        if (!in_array($sumType, [1, 2], true)) {
            $sumType = 1;
        }

        /** @var SatffYejiServices $yejiServices */
        $yejiServices = app()->make(SatffYejiServices::class);
        // 不把客户端 staff_id 传入；强制本人
        $list = $yejiServices->detailYeji([
            'staff_id' => $staffId,
            'created_time' => $time,
            'sum_type' => $sumType,
        ]);

        return [
            'list' => is_array($list) ? $list : [],
            'staff_id' => $staffId,
            'sum_type' => $sumType,
            'filter' => [
                'date_type' => $filter['date_type'] ?? 'custom',
                'start_date' => $start,
                'end_date' => $end,
                'created_time' => $time,
            ],
            'updated_at' => date('Y-m-d H:i:s'),
        ];
    }
}
