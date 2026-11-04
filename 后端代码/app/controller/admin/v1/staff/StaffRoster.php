<?php
declare(strict_types=1);
namespace app\controller\admin\v1\staff;
use app\services\store\roster\StaffRosterControllerActions;
use app\services\store\roster\StaffRosterPlatformScope;
/** 平台保留管理员身份，组织选择只缩小其授权范围；业务与门店同权同服务。 */
final class StaffRoster extends \app\controller\admin\AuthController
{
    use StaffRosterControllerActions;
    protected function rosterStoreId(): int {
        $scope = (new StaffRosterPlatformScope())->selectedStoreScope((int)$this->adminType, (int)$this->agentId, (array)$this->adminInfo, (int)$this->request->param('store_id', 0));
        return (int)$scope['store_ids'][0];
    }
    protected function rosterActor(): array { return ['type' => 'ADMIN', 'id' => (int)$this->adminId]; }
    public function scope() { return $this->success((new StaffRosterPlatformScope())->pickerTree((int)$this->adminType, (int)$this->agentId, (array)$this->adminInfo)); }
}
