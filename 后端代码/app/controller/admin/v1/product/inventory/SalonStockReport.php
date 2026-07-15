<?php
declare(strict_types=1);

namespace app\controller\admin\v1\product\inventory;

use app\controller\admin\AuthController;
use app\services\product\inventory\SalonStockReportServices;
use think\facade\App;

/**
 * 平台端·院装领用/退回明细与统计
 */
class SalonStockReport extends AuthController
{
    public function __construct(App $app, SalonStockReportServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    public function usageList()
    {
        $where = $this->request->getMore([
            ['store_id', ''],
            ['status', ''],
            ['consumable_product_id', ''],
            ['writeoff_id', ''],
            ['start_time', ''],
            ['end_time', ''],
        ]);
        return $this->success($this->services->usageDetailList($where, 0));
    }

    public function statistics()
    {
        $where = $this->request->getMore([
            ['store_id', ''],
            ['status', ''],
            ['consumable_product_id', ''],
            ['start_time', ''],
            ['end_time', ''],
        ]);
        return $this->success($this->services->usageStatistics($where, 0));
    }
}
