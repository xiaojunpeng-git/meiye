<?php
declare(strict_types=1);
namespace app\controller\admin\v1\marketing\combination;

use app\controller\admin\AuthController;
use app\services\activity\combination\CombinationPromotionServices;

/** 平台身份只取认证上下文；同一服务负责大屏、下钻和全量筛选导出。 */
class CombinationPromotion extends AuthController
{
    public function dashboard() { return app('json')->success((new CombinationPromotionServices())->dashboard($this->request->get(), $this->adminInfo)); }
    public function settings() { return app('json')->success((new CombinationPromotionServices())->settings($this->adminInfo)); }
    public function saveSettings() { return app('json')->success((new CombinationPromotionServices())->settings($this->adminInfo, $this->request->post())); }
    public function activities() { return app('json')->success((new CombinationPromotionServices())->activities($this->adminInfo)); }
    public function details() { return app('json')->success((new CombinationPromotionServices())->details($this->request->get(), $this->adminInfo)); }
    public function export() { return app('json')->success((new CombinationPromotionServices())->details($this->request->get(), $this->adminInfo, null, true)); }
}
