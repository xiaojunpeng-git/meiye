<?php
declare(strict_types=1);

namespace app\controller\admin\v1\product\inventory;

use app\controller\admin\AuthController;
use app\services\product\inventory\InventoryScopeServices;
use app\services\product\inventory\SalonStockReportServices;
use think\facade\App;

/**
 * 平台端·院装领用/退回明细与统计
 * P1：院装仅门店业务；hq 返回空；store 指定门店；all=全部门店汇总
 */
class SalonStockReport extends AuthController
{
    public function __construct(App $app, SalonStockReportServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    /**
     * @return array{empty:bool,store_id:int|string,scope_label:string}
     */
    protected function salonScope(): array
    {
        $params = $this->request->getMore([
            ['scope', ''],
            ['store_id', ''],
        ]);
        // 兼容旧前端：未传 scope 时，有 store_id=指定门店，无=全部门店汇总
        $scope = strtolower(trim((string)($params['scope'] ?? '')));
        if ($scope === '') {
            $sid = (int)($params['store_id'] ?? 0);
            $scope = $sid > 0 ? InventoryScopeServices::SCOPE_STORE : InventoryScopeServices::SCOPE_ALL;
        }
        /** @var InventoryScopeServices $scopeServices */
        $scopeServices = app()->make(InventoryScopeServices::class);
        $resolved = $scopeServices->resolve($scope, $params['store_id'] ?? '');
        if ($resolved['scope'] === InventoryScopeServices::SCOPE_HQ) {
            // 总部仓无院装领退/院装库存分类
            return ['empty' => true, 'store_id' => 0, 'scope_label' => '总部仓（无院装）'];
        }
        if ($resolved['scope'] === InventoryScopeServices::SCOPE_STORE) {
            return ['empty' => false, 'store_id' => $resolved['store_id'], 'scope_label' => $resolved['scope_label']];
        }
        // all：不写 store_id，Service 不按店过滤 = 全部门店
        return ['empty' => false, 'store_id' => '', 'scope_label' => $resolved['scope_label']];
    }

    public function usageList()
    {
        $where = $this->request->getMore([
            ['store_id', ''],
            ['status', ''],
            ['consumable_product_id', ''],
            ['writeoff_id', ''],
            ['keyword', ''],
            ['start_time', ''],
            ['end_time', ''],
            ['business_date_from', ''],
            ['business_date_to', ''],
        ]);
        // 旧院装流水以 add_time 记录发生时间；将统一日期筛选映射为
        // 门店本地日的完整时间边界，避免前端参数被静默忽略。
        if ((string)$where['business_date_from'] !== '' && (string)$where['business_date_to'] !== '') {
            $where['start_time'] = (string)$where['business_date_from'] . ' 00:00:00';
            $where['end_time'] = (string)$where['business_date_to'] . ' 23:59:59';
        }
        $scope = $this->salonScope();
        if (!empty($scope['empty'])) {
            return $this->success(['list' => [], 'count' => 0, 'scope_label' => $scope['scope_label']]);
        }
        $where['store_id'] = $scope['store_id'];
        $data = $this->services->usageDetailList($where, 0);
        $data['scope_label'] = $scope['scope_label'];
        return $this->success($data);
    }

    public function statistics()
    {
        $where = $this->request->getMore([
            ['store_id', ''],
            ['status', ''],
            ['consumable_product_id', ''],
            ['start_time', ''],
            ['end_time', ''],
            ['business_date_from', ''],
            ['business_date_to', ''],
        ]);
        if ((string)$where['business_date_from'] !== '' && (string)$where['business_date_to'] !== '') {
            $where['start_time'] = (string)$where['business_date_from'] . ' 00:00:00';
            $where['end_time'] = (string)$where['business_date_to'] . ' 23:59:59';
        }
        $scope = $this->salonScope();
        if (!empty($scope['empty'])) {
            return $this->success(['list' => [], 'count' => 0, 'scope_label' => $scope['scope_label']]);
        }
        $where['store_id'] = $scope['store_id'];
        $data = $this->services->usageStatistics($where, 0);
        $data['scope_label'] = $scope['scope_label'];
        return $this->success($data);
    }
}
