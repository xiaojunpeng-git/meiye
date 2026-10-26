<?php
namespace app\services\merchant;

use app\model\order\StoreDebt;
use app\services\BaseServices;
use app\services\order\agent\AgentOrderServices;
use app\services\order\StoreDebtServices;
use app\services\order\StoreReservationOrderServices;
use app\services\store\SystemStoreServices;
use app\services\store\SystemStoreStaffServices;
use app\services\yeji\SatffYejiServices;

/**
 * 商家首页聚合（复用既有口径，无门店范围时禁止全平台聚合；普通员工用个人业绩页口径）
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
        $metricsSource = '';

        $canStoreMetrics = $accessServices->canAggregateStoreMetrics($access)
            && in_array('merchant.home.view', $access['permissions'] ?? [], true)
            && !empty($scopeStoreIds);

        $hasHomeView = in_array('merchant.home.view', $access['permissions'] ?? [], true);

        $staff = null;
        $staffId = 0;
        if ($storeId > 0 && in_array($storeId, $scopeStoreIds, true)) {
            try {
                /** @var SystemStoreStaffServices $staffServices */
                $staffServices = app()->make(SystemStoreStaffServices::class);
                $staffRow = $staffServices->getStaffInfoByUid($uid, $storeId);
                $staff = $staffRow ? (is_array($staffRow) ? $staffRow : $staffRow->toArray()) : null;
                $staffId = (int)($staff['id'] ?? 0);
            } catch (\Throwable $e) {
                $staff = null;
                $staffId = 0;
            }
        }

        /** @var MerchantMetricPresenter $presenter */
        $presenter = app()->make(MerchantMetricPresenter::class);

        // 本人：对齐「个人业绩」页（销售/劳动/客数/指定客/提成/项目数），禁止套用店级三指标
        if ($isStaffSelfOnly && $hasHomeView) {
            if ($staffId <= 0) {
                $metricsDeveloping = true;
                $metricsNote = '未找到当前门店员工档案，无法加载个人业绩';
                $metrics = [];
            } else {
                try {
                    /** @var SatffYejiServices $yejiServices */
                    $yejiServices = app()->make(SatffYejiServices::class);
                    $yejiInfo = $yejiServices->staffInfo([
                        'staff_id' => $staffId,
                        'created_time' => $dataRange,
                        'sum_type' => 1,
                    ]);
                    $metrics = $presenter->presentStaffYejiInfo(is_array($yejiInfo) ? $yejiInfo : []);
                    $metricsDeveloping = false;
                    $metricsNote = '口径与「个人业绩」页一致';
                    $metricsSource = 'SatffYejiServices::staffInfo';
                    if ($storeId > 0) {
                        /** @var SystemStoreServices $storeServices */
                        $storeServices = app()->make(SystemStoreServices::class);
                        $storeName = (string)$storeServices->value(['id' => $storeId], 'name');
                    }
                } catch (\Throwable $e) {
                    $metricsDeveloping = true;
                    $metricsNote = '个人业绩加载失败';
                    $metrics = [];
                }
            }
        } elseif (!$canStoreMetrics) {
            $metricsDeveloping = true;
            if (!$hasHomeView) {
                $metricsNote = '当前身份无首页经营查看权限，仅展示工作入口';
                $metrics = [];
            } else {
                $metricsNote = '当前身份无有效门店经营范围或无整店数据权限，不返回聚合数据';
                $metrics = [
                    ['metric_code' => 'cash_performance', 'title' => '现金业绩', 'number' => null, 'developing' => true, 'detail_api' => null, 'detail_developing' => true],
                    ['metric_code' => 'actual_performance', 'title' => '实际业绩', 'number' => null, 'developing' => true, 'detail_api' => null, 'detail_developing' => true],
                    ['metric_code' => 'consume_amount', 'title' => '消耗业绩', 'number' => null, 'developing' => true, 'detail_api' => null, 'detail_developing' => true],
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
                $metrics = $presenter->presentHomeStatics(is_array($raw) ? $raw : []);
                $metricsSource = 'AgentOrderServices::homeStatics';
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
        $todoNote = '预约待办：今日待确认+待服务；欠款待办需 merchant.debt.view';
        $hasDebtView = in_array('merchant.debt.view', $access['permissions'] ?? [], true);
        $reservations = [];
        $reservationsDeveloping = true;

        if ($canStoreMetrics && $scopeStoreIds && $storeId > 0) {
            try {
                /** @var SystemStoreStaffServices $staffServices */
                $staffServices = app()->make(SystemStoreStaffServices::class);
                $staging = $staffServices->getStagingData($storeId, $staffId, 'today');
                $stg = $staging['staging'] ?? $staging;
                $todos['unshipped'] = (int)($stg['unshipped_count'] ?? 0);
                $todos['refunding'] = (int)($stg['refunding_count'] ?? 0);
                $todos['policeforce'] = (int)($stg['policeforce'] ?? 0);
            } catch (\Throwable $e) {
            }
        }

        $canSeeReservation = in_array($role, ['store_manager', 'store_staff', 'region_agent'], true)
            && $storeId > 0
            && in_array($storeId, $scopeStoreIds, true);
        if ($canSeeReservation) {
            $selfOnly = $role === 'store_staff' && !SystemStoreStaffServices::staffIsManager($staff ?: []);
            if ($selfOnly && $staffId <= 0) {
                $todos['reservation'] = 0;
                $todos['reservation_developing'] = false;
                $reservationsDeveloping = false;
                $reservations = [];
            } else {
                try {
                    /** @var StoreReservationOrderServices $reservationServices */
                    $reservationServices = app()->make(StoreReservationOrderServices::class);
                    $homeRsv = $reservationServices->getMerchantHomeTodayPending(
                        $storeId,
                        $selfOnly ? $staffId : 0,
                        5
                    );
                    $todos['reservation'] = (int)($homeRsv['count'] ?? 0);
                    $todos['reservation_developing'] = false;
                    $reservationsDeveloping = false;
                    $reservations = is_array($homeRsv['list'] ?? null) ? $homeRsv['list'] : [];
                } catch (\Throwable $e) {
                    $todos['reservation'] = null;
                    $todos['reservation_developing'] = true;
                    $reservations = [];
                    $reservationsDeveloping = true;
                }
            }
        }

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

        $metricVersion = $metricsSource !== ''
            ? ('MetricDictionaryServices@1.0.0 + ' . $metricsSource)
            : 'MetricDictionaryServices@1.0.0';

        return [
            'metrics' => $metrics,
            'metrics_developing' => $metricsDeveloping,
            'metrics_note' => $metricsNote,
            'metrics_mode' => $isStaffSelfOnly ? 'staff_self' : 'store',
            'store_name' => $storeName,
            'todos' => $todos,
            'todo_note' => $todoNote,
            'reservations' => $reservations,
            'reservations_developing' => $reservationsDeveloping,
            'has_home_view' => $hasHomeView,
            'scope_store_ids' => $scopeStoreIds,
            'resolved_store_ids' => $access['resolved_store_ids'] ?? [],
            'date_range' => $dataRange,
            'updated_at' => date('Y-m-d H:i:s'),
            'metric_version' => $metricVersion,
        ];
    }
}
