<?php
declare(strict_types=1);

namespace app\services\merchant;

use app\services\BaseServices;
use app\services\order\StoreReservationOrderServices;
use app\services\store\SystemStoreStaffServices;
use think\exception\ValidateException;

/**
 * 商家模式预约：统一用 active_store_id + scope_store_ids，禁止走旧 getStaffInfoByUid 单条任职。
 */
class MerchantReservationServices extends BaseServices
{
    /**
     * @return array{store_id:int,staff_id:int,self_only:bool,staff:array}
     */
    public function resolveReservationContext(int $uid, array $access): array
    {
        /** @var MerchantAccessServices $accessServices */
        $accessServices = app()->make(MerchantAccessServices::class);
        $scopeStoreIds = $accessServices->requireScopeStoreIds($access);
        $storeId = (int)($access['active_store_id'] ?? 0);
        $role = (string)($access['active_role'] ?? '');

        if ($storeId <= 0 || !in_array($storeId, $scopeStoreIds, true)) {
            throw new ValidateException('请先选择有效门店');
        }
        if (!in_array($role, ['store_manager', 'store_staff', 'region_agent'], true)) {
            throw new ValidateException('当前身份不可查看预约');
        }

        $staff = [];
        $staffId = 0;
        try {
            /** @var SystemStoreStaffServices $staffServices */
            $staffServices = app()->make(SystemStoreStaffServices::class);
            $staffRow = $staffServices->getStaffInfoByUid($uid, $storeId);
            $staff = $staffRow ? (is_array($staffRow) ? $staffRow : $staffRow->toArray()) : [];
            $staffId = (int)($staff['id'] ?? 0);
        } catch (\Throwable $e) {
            $staff = [];
            $staffId = 0;
        }

        $isManager = SystemStoreStaffServices::staffIsManager($staff);
        // 区域代理看当前店：全店；店长全店；普通员工仅本人服务单
        $selfOnly = $role === 'store_staff' && !$isManager;
        if ($selfOnly && $staffId <= 0) {
            throw new ValidateException('未找到当前门店员工档案');
        }
        // 接单/拒单：店长身份、区域代理，或当前店员工档案为店长
        $canManage = in_array($role, ['store_manager', 'region_agent'], true) || $isManager;

        return [
            'store_id' => $storeId,
            'staff_id' => $staffId,
            'self_only' => $selfOnly,
            'can_manage' => $canManage,
            'staff' => $staff,
            'scope_store_ids' => $scopeStoreIds,
        ];
    }

    public function listOrders(int $uid, array $access, array $filter): array
    {
        $ctx = $this->resolveReservationContext($uid, $access);
        $where = [
            'store_id' => $ctx['store_id'],
            'is_del' => 0,
        ];
        if ($ctx['self_only']) {
            $where['service_staff_id'] = $ctx['staff_id'];
        }
        if (!empty($filter['status']) || (isset($filter['status']) && $filter['status'] === '0')) {
            $where['status'] = $filter['status'];
        }
        $this->applyReservationTimeFilter($where, $filter);
        if (!empty($filter['search'])) {
            $where['search'] = $filter['search'];
        }
        if (!empty($filter['oid'])) {
            $where['oid'] = (int)$filter['oid'];
        }

        /** @var StoreReservationOrderServices $services */
        $services = app()->make(StoreReservationOrderServices::class);
        $list = $services->getReservationOrderList($where);
        return is_array($list) ? $list : [];
    }

    /**
     * 状态 Tab 统计：与 list 同一门店/本人范围
     */
    public function statistics(int $uid, array $access, array $filter): array
    {
        $ctx = $this->resolveReservationContext($uid, $access);
        $where = [
            'store_id' => $ctx['store_id'],
            'is_del' => 0,
        ];
        if ($ctx['self_only']) {
            $where['service_staff_id'] = $ctx['staff_id'];
        }
        $this->applyReservationTimeFilter($where, $filter);
        /** @var StoreReservationOrderServices $services */
        $services = app()->make(StoreReservationOrderServices::class);
        return $services->getButlerCenterStatistics($where);
    }

    /**
     * 预约日期过滤：优先 start_date/end_date 区间（数仓下钻），否则单日 date
     */
    protected function applyReservationTimeFilter(array &$where, array $filter): void
    {
        $startDate = trim((string)($filter['start_date'] ?? ''));
        $endDate = trim((string)($filter['end_date'] ?? ''));
        if ($startDate !== '' && $endDate !== '') {
            $startTs = strtotime($startDate . ' 00:00:00');
            $endTs = strtotime($endDate . ' 23:59:59');
            if ($startTs > 0 && $endTs > 0 && $endTs >= $startTs) {
                $where['reservation_time'] = [$startTs, $endTs];
                return;
            }
        }
        $date = trim((string)($filter['date'] ?? ''));
        if ($date !== '') {
            $where['reservation_time'] = [
                strtotime($date . ' 00:00:00'),
                strtotime($date . ' 23:59:59'),
            ];
        }
    }

    public function detail(int $uid, array $access, int $id): array
    {
        if ($id <= 0) {
            throw new ValidateException('参数错误');
        }
        $ctx = $this->resolveReservationContext($uid, $access);
        $scope = $ctx['scope_store_ids'];

        /** @var StoreReservationOrderServices $services */
        $services = app()->make(StoreReservationOrderServices::class);
        // 先不按 store 过滤拉出，再校验是否在 scope 内（避免错店任职导致 404 掩盖越权）
        $info = $services->getReservationOrderInfo(0, $id, 0);
        $rsvStoreId = (int)($info['store_id'] ?? 0);
        if ($rsvStoreId <= 0 || !in_array($rsvStoreId, $scope, true)) {
            throw new ValidateException('无权查看该预约');
        }
        // 当前激活门店必须与预约门店一致（区域代理需先切到该店）
        if ($rsvStoreId !== (int)$ctx['store_id']) {
            throw new ValidateException('请先切换到该预约所属门店后再查看');
        }
        if ($ctx['self_only']) {
            $serviceStaffId = (int)($info['service_staff_id'] ?? 0);
            if ($serviceStaffId !== (int)$ctx['staff_id']) {
                throw new ValidateException('仅可查看本人服务的预约');
            }
        }
        $info['now_staff_id'] = (int)$ctx['staff_id'];
        $info['merchant_guard'] = true;
        $info['can_manage'] = !empty($ctx['can_manage']);
        return $info;
    }

    /**
     * 当前激活门店房间列表（接单选房）
     */
    public function tableList(int $uid, array $access): array
    {
        $ctx = $this->resolveReservationContext($uid, $access);
        if (empty($ctx['can_manage'])) {
            throw new ValidateException('仅店长可查看房间列表');
        }
        /** @var StoreReservationOrderServices $services */
        $services = app()->make(StoreReservationOrderServices::class);
        return $services->getStoreTableList((int)$ctx['store_id']);
    }

    /**
     * 店长接单确认
     */
    public function confirm(int $uid, array $access, int $id, int $tableId, string $tableName): bool
    {
        $ctx = $this->assertManageAndLoad($uid, $access, $id);
        /** @var StoreReservationOrderServices $services */
        $services = app()->make(StoreReservationOrderServices::class);
        return $services->confirmReservationOrder(
            $id,
            (int)$ctx['store_id'],
            (int)$ctx['staff_id'],
            $tableId,
            $tableName,
            true
        );
    }

    /**
     * 店长拒绝预约
     */
    public function refuse(int $uid, array $access, int $id, string $refuseReason): bool
    {
        $ctx = $this->assertManageAndLoad($uid, $access, $id);
        /** @var StoreReservationOrderServices $services */
        $services = app()->make(StoreReservationOrderServices::class);
        return $services->refuseReservationOrder(
            $id,
            (int)$ctx['store_id'],
            $refuseReason,
            (int)$ctx['staff_id']
        );
    }

    /**
     * 店长修改预约（与 store/reservation/update 同业务，商家 Guard 收口）
     */
    public function update(int $uid, array $access, int $id, array $data): bool
    {
        $this->assertManageAndLoad($uid, $access, $id);
        /** @var StoreReservationOrderServices $services */
        $services = app()->make(StoreReservationOrderServices::class);
        return (bool)$services->updateReservationOrder($id, $data);
    }

    /**
     * 开始/结束服务：可读范围内（员工本人或店长全店）
     */
    public function setServiceStatus(int $uid, array $access, int $id, int $status, array $appendData = []): array
    {
        $info = $this->detail($uid, $access, $id);
        $ctx = $this->resolveReservationContext($uid, $access);
        if ((int)$ctx['staff_id'] <= 0 && empty($ctx['can_manage'])) {
            throw new ValidateException('未找到当前门店员工档案，无法操作服务');
        }
        /** @var StoreReservationOrderServices $services */
        $services = app()->make(StoreReservationOrderServices::class);
        $payload = array_merge($appendData, ['store_id' => (int)$ctx['store_id']]);
        $result = $services->setServiceStatus(
            0,
            $id,
            $status,
            (int)$ctx['staff_id'],
            $payload
        );
        return is_array($result) ? $result : [];
    }

    /**
     * 店长写操作：校验 can_manage + 预约归属当前激活店
     * @return array{store_id:int,staff_id:int,can_manage:bool,...}
     */
    protected function assertManageAndLoad(int $uid, array $access, int $id): array
    {
        $ctx = $this->resolveReservationContext($uid, $access);
        if (empty($ctx['can_manage'])) {
            throw new ValidateException('仅店长可执行此操作');
        }
        // 复用 detail 的 scope / 当前店 / 本人校验（店长 self_only=false）
        $this->detail($uid, $access, $id);
        return $ctx;
    }
}
