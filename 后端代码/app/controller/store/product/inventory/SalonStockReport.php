<?php
declare(strict_types=1);

namespace app\controller\store\product\inventory;

use app\controller\store\AuthController;
use app\services\product\inventory\SalonStockReportServices;
use think\facade\App;

/**
 * 门店端·院装领用/退回明细与统计（仅本店）
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
            ['status', ''],
            ['consumable_product_id', ''],
            ['writeoff_id', ''],
            ['start_time', ''],
            ['end_time', ''],
        ]);
        return $this->success($this->services->usageDetailList($where, (int)$this->storeId));
    }

    public function statistics()
    {
        $where = $this->request->getMore([
            ['status', ''],
            ['consumable_product_id', ''],
            ['start_time', ''],
            ['end_time', ''],
        ]);
        return $this->success($this->services->usageStatistics($where, (int)$this->storeId));
    }
}
