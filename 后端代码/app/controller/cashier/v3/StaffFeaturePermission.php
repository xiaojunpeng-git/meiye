<?php
namespace app\controller\cashier\v3;

use app\controller\cashier\AuthController;
use app\services\cashier\v3\permission\CashierV3StaffFeatureOverrideServices;
use mohe\exceptions\AdminException;

/** 已停用的个人操作权限兼容入口：人员权限统一由岗位决定。 */
class StaffFeaturePermission extends AuthController
{
    public function read(int $staffId)
    {
        try {
            CashierV3StaffFeatureOverrideServices::assertIndividualPermissionEditingDisabled();
        } catch (AdminException $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function save(int $staffId)
    {
        try {
            CashierV3StaffFeatureOverrideServices::assertIndividualPermissionEditingDisabled();
        } catch (AdminException $e) {
            return $this->fail($e->getMessage());
        }
    }
}
