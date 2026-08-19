<?php
namespace app\controller\cashier\v3;

use app\controller\cashier\AuthController;
use app\services\cashier\v3\permission\CashierV3FeatureResolver;
use app\services\cashier\v3\permission\CashierV3StaffFeatureOverrideServices;
use mohe\exceptions\AdminException;

/** 门店端当前门店员工的个人操作权限覆盖。 */
class StaffFeaturePermission extends AuthController
{
    public function read(int $staffId, CashierV3StaffFeatureOverrideServices $service)
    {
        try {
            $this->assertCanEditPermissions();
            return $this->success($service->read($staffId, (int)$this->storeId));
        } catch (AdminException $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function save(int $staffId, CashierV3StaffFeatureOverrideServices $service)
    {
        try {
            $this->assertCanEditPermissions();
            $input = $this->request->post();
            $input = is_array($input) ? $input : [];
            $effects = is_array($input['effects'] ?? null) ? $input['effects'] : [];
            $result = $service->save($staffId, (int)$this->storeId, $effects, (int)($input['version'] ?? 0), [
                'id' => (int)$this->cashierId,
                'name' => (string)($this->cashierInfo['staff_name'] ?? $this->cashierInfo['account'] ?? ''),
                'ip' => (string)$this->request->ip(),
                'request_id' => (string)$this->request->header('X-Request-Token', ''),
            ]);
            return $this->success('员工功能权限已保存，下次登录生效', $result + ['reauth_required' => true]);
        } catch (AdminException $e) {
            return $this->fail($e->getMessage());
        }
    }

    private function assertCanEditPermissions(): void
    {
        $profile = is_array($this->cashierInfo) ? $this->cashierInfo : [];
        if (!empty($profile['_cashier_v3_delegated'])) throw new AdminException('当前为门店查看模式，不能编辑员工权限');
        $features = app()->make(CashierV3FeatureResolver::class)->resolveGrantedFeatures($profile);
        if (!in_array('cashier.v3.staff.permission_edit', $features, true)) {
            throw new AdminException('当前账号没有编辑员工权限的权限');
        }
    }
}
