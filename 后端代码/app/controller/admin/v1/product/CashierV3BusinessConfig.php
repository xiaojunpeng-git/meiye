<?php
declare(strict_types=1);

namespace app\controller\admin\v1\product;

use app\controller\admin\AuthController;
use app\services\cashier\v3\config\CashierV3BusinessConfigServices;
use think\facade\App;

final class CashierV3BusinessConfig extends AuthController
{
    public function __construct(App $app, CashierV3BusinessConfigServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    public function sources()
    {
        return $this->success($this->services->sourceTree(false));
    }

    public function createSource()
    {
        return $this->success('新增成功', $this->services->createSource(
            (array)$this->request->post(),
            (int)$this->adminId
        ));
    }

    public function updateSource(int $id)
    {
        return $this->success('保存成功', $this->services->updateSource(
            $id,
            (array)$this->request->post(),
            (int)$this->adminId
        ));
    }

    public function accountingMethods()
    {
        return $this->success($this->services->accountingMethods(false));
    }

    public function updateAccountingMethod(string $code)
    {
        return $this->success('保存成功', $this->services->updateAccountingMethod(
            $code,
            (array)$this->request->post(),
            (int)$this->adminId
        ));
    }

    public function restoreAccountingDefaults()
    {
        return $this->success('已恢复默认名称', $this->services->restoreAccountingDefaults(
            (array)$this->request->post(),
            (int)$this->adminId
        ));
    }
}
