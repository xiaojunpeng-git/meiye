<?php
namespace app\services\store;

use app\dao\store\StoreStaffTransferLogDao;
use app\services\BaseServices;
use app\services\system\SystemRoleServices;
use mohe\exceptions\AdminException;
use think\facade\Db;

class StoreStaffTransferServices extends BaseServices
{
    public function __construct(
        StoreStaffTransferLogDao $dao,
        SystemStoreStaffServices $staffServices
    ) {
        $this->dao = $dao;
        $this->staffServices = $staffServices;
    }

    /** @var SystemStoreStaffServices */
    protected $staffServices;

    /**
     * 店员调店
     */
    public function transfer(
        int $staffId,
        int $targetStoreId,
        array $roles,
        string $reason,
        int $immediate,
        array $operator
    ): bool {
        if ($staffId <= 0 || $targetStoreId <= 0) {
            throw new AdminException('参数有误');
        }
        if (!$roles) {
            throw new AdminException('请选择店员身份');
        }
        // 调店一律立即生效
        $immediate = 1;
        return (bool)Db::transaction(function () use ($staffId, $targetStoreId, $roles, $reason, $immediate, $operator) {
            $staff = $this->staffServices->getStaffInfo($staffId);
            $fromStoreId = (int)$staff['store_id'];
            if ($fromStoreId === $targetStoreId) {
                throw new AdminException('目标门店与当前门店相同');
            }
            /** @var SystemStoreServices $storeServices */
            $storeServices = app()->make(SystemStoreServices::class);
            $fromStore = $storeServices->get($fromStoreId);
            $toStore = $storeServices->get($targetStoreId);
            if (!$toStore || (int)($toStore['is_del'] ?? 0) === 1) {
                throw new AdminException('目标门店不存在');
            }
            // 门店营业状态字段为 is_show（1营业中），无 status 列
            if ((int)($toStore['is_show'] ?? 0) !== 1) {
                throw new AdminException('目标门店未启用');
            }
            $fromRoles = $staff['roles'] ?? [];
            if (!is_array($fromRoles)) {
                $fromRoles = $fromRoles !== '' ? explode(',', (string)$fromRoles) : [];
            }
            $update = [
                'store_id' => $targetStoreId,
                'roles' => $roles,
                'order_status' => 0,
                'is_cashier' => 0,
            ];
            $this->staffServices->applyRolesFlags($update);
            $now = time();
            $effectiveTime = $now;
            if (!$this->staffServices->update($staffId, $update)) {
                throw new AdminException('调店失败，请稍后再试');
            }
            $this->dao->save([
                'staff_id' => $staffId,
                'staff_name' => (string)($staff['staff_name'] ?? ''),
                'from_store_id' => $fromStoreId,
                'from_store_name' => (string)($fromStore['name'] ?? ''),
                'to_store_id' => $targetStoreId,
                'to_store_name' => (string)($toStore['name'] ?? ''),
                'from_roles' => json_encode(array_values($fromRoles), JSON_UNESCAPED_UNICODE),
                'to_roles' => json_encode(array_values($roles), JSON_UNESCAPED_UNICODE),
                'reason' => trim($reason),
                'operator_id' => (int)($operator['id'] ?? 0),
                'operator_name' => (string)($operator['name'] ?? ''),
                'operator_type' => (int)($operator['type'] ?? 1),
                'immediate' => $immediate ? 1 : 0,
                'effective_time' => $effectiveTime,
                'add_time' => $now,
            ]);
            return true;
        });
    }

    /**
     * 调店记录列表
     */
    public function getTransferLogList(array $where): array
    {
        [$page, $limit] = $this->getPageValue();
        $result = $this->dao->getList($where, $page, $limit);
        if (!empty($result['list'])) {
            /** @var SystemRoleServices $roleServices */
            $roleServices = app()->make(SystemRoleServices::class);
            foreach ($result['list'] as &$item) {
                $item['from_roles_text'] = $this->formatRolesText($item['from_roles'] ?? '', $roleServices);
                $item['to_roles_text'] = $this->formatRolesText($item['to_roles'] ?? '', $roleServices);
                $item['add_time_text'] = !empty($item['add_time']) ? date('Y-m-d H:i:s', (int)$item['add_time']) : '';
                $item['effective_time_text'] = !empty($item['effective_time']) ? date('Y-m-d H:i:s', (int)$item['effective_time']) : '';
            }
            unset($item);
        }
        return $result;
    }

    protected function formatRolesText($rolesValue, SystemRoleServices $roleServices): string
    {
        if ($rolesValue === '' || $rolesValue === null) {
            return '';
        }
        $roleIds = json_decode((string)$rolesValue, true);
        if (!is_array($roleIds)) {
            $roleIds = array_filter(array_map('intval', explode(',', (string)$rolesValue)));
        }
        if (!$roleIds) {
            return '';
        }
        $names = $roleServices->getColumn([['id', 'in', $roleIds]], 'role_name');
        return $names ? implode(',', $names) : '';
    }
}
