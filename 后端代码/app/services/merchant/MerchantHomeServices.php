<?php
namespace app\services\merchant;

use app\model\order\StoreDebt;
use app\services\BaseServices;
use app\services\order\agent\AgentOrderServices;
use app\services\order\StoreDebtServices;
use app\services\store\SystemStoreServices;
use app\services\store\SystemStoreStaffServices;

/**
 * 商家首页聚合（复用既有口径，无门店范围时禁止全平台聚合；普通员工禁止整店聚合）
 */
class MerchantHomeServices extends BaseServices
{
    public function overview(int $uid, array $access): array
    {
        /** @var MerchantAccessServices $accessServices */
        $accessServices = app()->make(MerchantAccessServices::class);
        $scopeStoreIds = $accessServices->requireScopeStoreIds($access);
        $role = (string)($access['active_role'] ?? '');
        $storeId = (int)($access['active_store_id'] ?? 0);
        $today = date('Y/m/d');
        $dataRange = $today . '-' . $today;
        $isStaffSelfOnly = $role === 'store_staff'
            || (!$accessServices->canAggregateStoreMetrics($access)
                && in_array('merchant.data.self', $access['permissions'] ?? [], true));

        $metrics = [];
        $metricsDeveloping = false;
        $metricsNote = '';
        $storeName = '';

        $canStoreMetrics = $accessServices->canAggregateStoreMetrics($access)
            && in_array('merchant.home.view', $access['permissions'] ?? [], true)
            && !empty($scopeStoreIds);

        $hasHomeView = in_array('merchant.home.view', $access['permissions'] ?? [], true);

        if (!$canStoreMetrics) {
            $metricsDeveloping = true;
            if (!$hasHomeView) {
                $metricsNote = '当前身份无首页经营查看权限，仅展示工作入口';
                $metrics = [];
            } else {
                $metricsNote = $isStaffSelfOnly
                    ? '普通员工仅可查看本人数据；个人经营口径待确认前不返回整店聚合'
                    : '当前身份无有效门店经营范围或无整店数据权限，不返回聚合数据';
                $metrics = [
                    ['metric_code' => 'cash_performance', 'title' => '现金业绩', 'number' => null, 'developing' => true, 'detail_api' => null, 'detail_developing' => true],
                    ['metric_code' => 'actual_performance', 'title' => '实收业绩', 'number' => null, 'developing' => true, 'detail_api' => null, 'detail_developing' => true],
                    ['metric_code' => 'consume_amount', 'title' => '消耗金额', 'number' => null, 'developing' => true, 'detail_api' => null, 'detail_developing' => true],
                ];
            }
        } else {
            try {
                /** @var AgentOrderServices $agentOrder */
                $agentOrder = app()->make(AgentOrderServices::class);
                $where = [
                    'time' => $dataRange,
                    'store_id' => count($scopeStoreIds) === 1 ? $scopeStoreIds[0] : $scopeStoreIds,
                ];
                $raw = $agentOrder->homeStatics($where);
                /** @var MerchantMetricPresenter $presenter */
                $presenter = app()->make(MerchantMetricPresenter::class);
                $metrics = $presenter->presentHomeStatics(is_array($raw) ? $raw : []);
                if ($storeId > 0) {
                    /** @var SystemStoreServices $storeServices */
                    $storeServices = app()->make(SystemStoreServices::class);
                    $storeName = (string)$storeServices->value(['id' => $storeId], 'name');
                }
            } catch (\Throwable $e) {
                $metricsDeveloping = true;
                $metricsNote = '经营指标加载失败';
                $metrics = [];
            }
        }

        $todos = [
            'reservation' => null,
            'reservation_developing' => true,
            'unshipped' => 0,
            'refunding' => 0,
            'policeforce' => 0,
            'debt' => null,
            'debt_developing' => true,
        ];
        $todoNote = '预约待办口径待核实；欠款待办需 merchant.debt.view';
        $hasDebtView = in_array('merchant.debt.view', $access['permissions'] ?? [], true);

        // 整店待办（发货/售后/库存）仅有整店经营聚合权限时返回
        if ($canStoreMetrics && $scopeStoreIds && $storeId > 0) {
            try {
                /** @var SystemStoreStaffServices $staffServices */
                $staffServices = app()->make(SystemStoreStaffServices::class);
                $staff = null;
                try {
                    $staff = $staffServices->getStaffInfoByUid($uid, $storeId);
                    $staff = $staff ? (is_array($staff) ? $staff : $staff->toArray()) : null;
                } catch (\Throwable $e) {
                    $staff = null;
                }
                $staffId = (int)($staff['id'] ?? 0);
                $staging = $staffServices->getStagingData($storeId, $staffId, 'today');
                $stg = $staging['staging'] ?? $staging;
                $todos['unshipped'] = (int)($stg['unshipped_count'] ?? 0);
                $todos['refunding'] = (int)($stg['refunding_count'] ?? 0);
                $todos['policeforce'] = (int)($stg['policeforce'] ?? 0);
            } catch (\Throwable $e) {
            }
        }

        // 欠款待办：必须具备 merchant.debt.view，禁止仅凭 home/经营权限泄露欠款数量
        if ($hasDebtView && $scopeStoreIds && $storeId > 0) {
            $todos['debt_developing'] = false;
            try {
                /** @var StoreDebtServices $debtServices */
                $debtServices = app()->make(StoreDebtServices::class);
                $debtList = $debtServices->getAdminList([
                    'store_id' => $storeId,
                    'status' => StoreDebt::STATUS_PENDING,
                ], 1, 1);
                $todos['debt'] = (int)($debtList['count'] ?? 0);
            } catch (\Throwable $e) {
                $todos['debt'] = null;
                $todos['debt_developing'] = true;
            }
        } else {
            $todos['debt'] = null;
            $todos['debt_developing'] = true;
        }

        return [
            'metrics' => $metrics,
            'metrics_developing' => $metricsDeveloping,
            'metrics_note' => $metricsNote,
            'store_name' => $storeName,
            'todos' => $todos,
            'todo_note' => $todoNote,
            'reservations' => [],
            'reservations_developing' => true,
            'has_home_view' => $hasHomeView,
            'scope_store_ids' => $scopeStoreIds,
            'resolved_store_ids' => $access['resolved_store_ids'] ?? [],
            'date_range' => $dataRange,
            'updated_at' => date('Y-m-d H:i:s'),
            'metric_version' => 'MetricDictionaryServices@1.0.0 + AgentOrderServices::homeStatics',
        ];
    }
}
