<?php
declare(strict_types=1);

namespace app\services\store\roster;

use app\services\organization\EmployeeDataScopeServices;
use app\services\organization\OrganizationScopeService;
use app\services\report\StoreReportParticipantScopeServices;
use think\facade\Db;

/** 平台排班读写范围由真实管理员身份生成，不能冒用同编号门店员工。 */
final class StaffRosterPlatformScope
{
    /** 组织选择与每次读写都复核同一授权门店集合。 */
    public function pickerTree(int $adminType, int $agentId, array $adminInfo): array
    {
        $storeIds = $this->allowedStoreIds($adminType, $agentId, $adminInfo);
        return [
            'tree' => app()->make(OrganizationScopeService::class)->buildPickerTree($storeIds),
            'allowed_store_ids' => $storeIds,
        ];
    }

    public function selectedStoreScope(int $adminType, int $agentId, array $adminInfo, int $storeId): array
    {
        if ($storeId <= 0) throw new \InvalidArgumentException('请选择门店。');
        if (!in_array($storeId, $this->allowedStoreIds($adminType, $agentId, $adminInfo), true)) {
            throw new \InvalidArgumentException('当前账号无权管理该门店。');
        }
        return ['mode' => 'platform', 'store_ids' => [$storeId]];
    }

    private function allowedStoreIds(int $adminType, int $agentId, array $adminInfo): array
    {
        if ($adminType === 3 && $agentId > 0) {
            return $this->normalize(app()->make(OrganizationScopeService::class)->getResolvedStoreIdsByLegacyAgentId($agentId));
        }
        $employeeId = max(0, (int)($adminInfo['employee_id'] ?? 0));
        if ($employeeId <= 0) return $this->allStoreIds();
        $dataScope = app()->make(EmployeeDataScopeServices::class);
        $resolved = $dataScope->resolveEffectiveStoreIds($employeeId, 0, $adminInfo);
        if ($resolved === null) return $this->allStoreIds();
        if ($dataScope->resolvePrimaryHqScopeMode($employeeId) === EmployeeDataScopeServices::MODE_PERSONAL) {
            return $this->normalize(app()->make(StoreReportParticipantScopeServices::class)->participatingStoreIds('0', $employeeId));
        }
        return $this->normalize($resolved);
    }

    private function allStoreIds(): array { return $this->normalize(Db::name('system_store')->where('is_del', 0)->where('name', '<>', '总部')->column('id') ?: []); }
    private function normalize($storeIds): array { $ids = array_values(array_unique(array_filter(array_map('intval', (array)$storeIds)))); sort($ids); return $ids; }
}
