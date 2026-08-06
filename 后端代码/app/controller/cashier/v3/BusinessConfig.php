<?php
declare(strict_types=1);

namespace app\controller\cashier\v3;

use app\controller\cashier\AuthController;
use app\services\cashier\v3\config\CashierV3BusinessConfigServices;
use think\facade\App;

final class BusinessConfig extends AuthController
{
    public function __construct(App $app, CashierV3BusinessConfigServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    /** 当前门店只能读取总部配置出的有效来源和记账方式。 */
    public function checkoutCatalog()
    {
        return $this->success('ok', $this->services->checkoutCatalog());
    }
}
