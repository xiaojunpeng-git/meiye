<?php

namespace app\controller\admin\v1\product;

use app\controller\admin\AuthController;
use app\services\product\CashierV3PerformanceRuleAdminServices;

final class CashierV3PerformanceRule extends AuthController
{
    public function read(int $id, CashierV3PerformanceRuleAdminServices $services)
    {
        try { return $this->success($services->read($id)); }
        catch (\InvalidArgumentException $e) { return $this->fail($e->getMessage()); }
    }

    public function save(int $id, CashierV3PerformanceRuleAdminServices $services)
    {
        try { return $this->success('业绩与固定手工费已保存', $services->save($id, (array)$this->request->post(), (int)$this->adminId)); }
        catch (\InvalidArgumentException $e) { return $this->fail($e->getMessage()); }
    }
}
