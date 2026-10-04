<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use app\services\cashier\v3\permission\CashierV3FeatureResolver;
use app\services\product\inventory\query\InventoryBatchStockQueryContract;
use think\facade\Db;

/** 从可信门店会话和当前 V3 岗位规则构建逐功能的库存成本查看能力。 */
final class InventoryStoreAccessPolicy
{
    /**
     * 门店库存写入以已认证会话解析操作者；组织选店的会话行不是传统门店员工行。
     * 返回稳定的员工身份供单据与库存事实审计，且每次按当前岗位重新校验功能授权。
     * @return array{type:string,id:int,name:string}
     */
    public function writableActor(int $storeId, int $sessionId, string $featureCode): array
    {
        if ($storeId <= 0 || $sessionId <= 0 || !in_array($featureCode, CashierV3FeatureResolver::FEATURE_CODES, true)) {
            throw new \RuntimeException('inventory_store_actor_denied');
        }
        $profile = (array)(request()->storeStaffInfo ?? []);
        if (!empty($profile['_cashier_v3_organization']) || !empty($profile['_cashier_v3_delegated'])) {
            if (!empty($profile['_cashier_v3_delegated']) || !empty($profile['read_only'])
                || (int)($profile['id'] ?? 0) !== $sessionId
                || (int)($profile['store_id'] ?? 0) !== $storeId
                || (int)($profile['employee_id'] ?? 0) <= 0
                || ($profile['session_mode'] ?? '') !== 'store_organization'
                || !in_array($featureCode, app()->make(CashierV3FeatureResolver::class)->resolveGrantedFeatures($profile), true)) {
                throw new \RuntimeException('inventory_store_actor_denied');
            }
            $employee = Db::name('employee')->where('id', (int)$profile['employee_id'])
                ->where('status', 1)->where('is_del', 0)->field('id,name')->find();
            if (!$employee || trim((string)$employee['name']) === '') {
                throw new \RuntimeException('inventory_store_actor_denied');
            }
            return ['type' => 'EMPLOYEE', 'id' => (int)$employee['id'],
                'name' => mb_substr(trim((string)$employee['name']), 0, 128)];
        }
        $staff = Db::name('system_store_staff')->where('id', $sessionId)
            ->where('store_id', $storeId)->where('status', 1)->where('is_del', 0)
            ->field('id,staff_name')->find();
        if (!$staff) {
            throw new \RuntimeException('inventory_store_actor_denied');
        }
        return ['type' => 'STORE_STAFF', 'id' => $sessionId,
            'name' => mb_substr(trim((string)$staff['staff_name']), 0, 128)];
    }

    /** @return string[] */
    public function features(int $storeId, int $operatorId, string $featureCode = ''): array
    {
        // V3 数据权限选店会话没有 system_store_staff 任职行；这里只接受
        // 中间件已认证的组织会话，并继续用服务端岗位规则判断当前功能。
        $sessionInfo = (array)(request()->storeStaffInfo ?? []);
        if (!empty($sessionInfo['_cashier_v3_delegated']) || !empty($sessionInfo['_cashier_v3_organization'])) {
            if ((int)($sessionInfo['store_id'] ?? 0) !== $storeId) {
                throw new \RuntimeException('inventory_store_access_denied');
            }
            // 库存统一查询的旧权限名只作为内部查询能力标识；成本仍逐功能判断。
            $features = ['inventory.batch.view', 'inventory.batch.export'];
            if ($featureCode !== '' && $this->canViewCostForFeature($storeId, $operatorId, $featureCode)) {
                $features[] = InventoryBatchStockQueryContract::PERMISSION_COST;
            }
            return $features;
        }
        $staff = Db::name('system_store_staff')->where('id', $operatorId)
            ->where('store_id', $storeId)->where('status', 1)->where('is_del', 0)
            ->field('id')->find();
        if (!$staff) {
            throw new \RuntimeException('inventory_store_access_denied');
        }
        $features = [InventoryBatchStockQueryContract::PERMISSION_VIEW, InventoryBatchStockQueryContract::PERMISSION_EXPORT];
        // 成本能力只在当前库存功能被服务端 V3 岗位规则授予时附加，
        // 不能因拥有任意一个库存入口而扩散到其他库存页面。
        if ($featureCode !== '' && $this->canViewCostForFeature($storeId, $operatorId, $featureCode)) {
            $features[] = InventoryBatchStockQueryContract::PERMISSION_COST;
        }
        sort($features, SORT_STRING);
        return $features;
    }

    /** 当前库存功能的使用权限同时允许查看该功能内单价和金额；不读取浏览器权限提示。 */
    public function canViewCostForFeature(int $storeId, int $operatorId, string $featureCode): bool
    {
        if (strpos($featureCode, 'cashier.v3.inventory.') !== 0
            || !in_array($featureCode, CashierV3FeatureResolver::FEATURE_CODES, true)) {
            throw new \InvalidArgumentException('inventory_cost_feature_invalid');
        }
        $sessionInfo = (array)(request()->storeStaffInfo ?? []);
        if (!empty($sessionInfo['_cashier_v3_delegated']) || !empty($sessionInfo['_cashier_v3_organization'])) {
            if ((int)($sessionInfo['store_id'] ?? 0) !== $storeId) return false;
            // delegated 仅有页面可见性而没有岗位使用权限，绝不据此暴露成本。
            if (!empty($sessionInfo['_cashier_v3_delegated'])) return false;
            $profile = $sessionInfo;
        } else {
            $profile = Db::name('system_store_staff')->where('id', $operatorId)
                ->where('store_id', $storeId)->where('status', 1)->where('is_del', 0)->find();
            if (!$profile) return false;
        }
        return in_array($featureCode, app()->make(CashierV3FeatureResolver::class)->resolveGrantedFeatures((array)$profile), true);
    }
}
