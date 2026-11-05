<?php
declare(strict_types=1);
namespace app\controller\cashier\v3;
use app\services\store\roster\StaffRosterControllerActions;
/** 门店及操作人仅取强制门店会话；页面只读会话仍受V3公共写入门禁保护。 */
final class StaffRoster extends \app\controller\cashier\AuthController
{
    use StaffRosterControllerActions;
    protected function rosterStoreId(): int { return (int)$this->storeId; }
    protected function rosterActor(): array { return ['type' => 'STORE', 'id' => (int)$this->cashierId]; }
}
