<?php
declare(strict_types=1);

namespace app\services\product\inventory\completion;

use think\facade\Db;

/** Inventory-owned policy writer. Approval/notification hooks attach here later. */
final class InventoryShortagePolicyServices
{
    public function setMerchantDefault(
        InventoryCompletionDataScope $scope,
        string $policy,
        int $recordedAt
    ): array {
        InventoryEntitlementCompletionContract::resolvePolicy(
            $policy,
            InventoryEntitlementCompletionContract::POLICY_INHERIT
        );
        return $this->save(
            $scope,
            'MERCHANT',
            0,
            $policy,
            $recordedAt
        );
    }

    public function setProjectOverride(
        InventoryCompletionDataScope $scope,
        int $projectId,
        string $policy,
        int $recordedAt
    ): array {
        if ($projectId <= 0) {
            throw new InventoryCompletionContractException('inventory_policy_project_invalid');
        }
        InventoryEntitlementCompletionContract::resolvePolicy(
            InventoryEntitlementCompletionContract::POLICY_DENY,
            $policy
        );
        return $this->save($scope, 'PROJECT', $projectId, $policy, $recordedAt);
    }

    private function save(
        InventoryCompletionDataScope $scope,
        string $policyScope,
        int $projectId,
        string $policy,
        int $recordedAt
    ): array {
        if ($recordedAt <= 0) {
            throw new InventoryCompletionContractException('inventory_policy_time_invalid');
        }
        return Db::transaction(function () use ($scope, $policyScope, $projectId, $policy, $recordedAt) {
            $row = Db::name('inventory_shortage_policy')
                ->where('tenant_id', $scope->tenantId())
                ->where('policy_scope', $policyScope)
                ->where('project_id', $projectId)
                ->lock(true)
                ->find();
            if ($row) {
                $version = (int)$row['version'] + 1;
                Db::name('inventory_shortage_policy')->where('id', (int)$row['id'])->update([
                    'policy_value' => $policy,
                    'version' => $version,
                    'updated_by' => $scope->operatorId(),
                    'updated_at' => $recordedAt,
                ]);
                return ['id' => (int)$row['id'], 'version' => $version, 'policy' => $policy];
            }
            $id = (int)Db::name('inventory_shortage_policy')->insertGetId([
                'tenant_id' => $scope->tenantId(),
                'policy_scope' => $policyScope,
                'project_id' => $projectId,
                'policy_value' => $policy,
                'version' => 1,
                'updated_by' => $scope->operatorId(),
                'created_at' => $recordedAt,
                'updated_at' => $recordedAt,
            ]);
            return ['id' => $id, 'version' => 1, 'policy' => $policy];
        });
    }
}
