<?php
declare(strict_types=1);
namespace app\controller\admin\v1\marketing\combination;

use app\controller\admin\AuthController;
use app\services\activity\combination\CombinationPromotionServices;

/** 推广人报表使用认证后台身份；服务层统一校验权限、门店范围和订单事实。 */
class PromoterReport extends AuthController
{
    public function options() { return app('json')->success((new CombinationPromotionServices())->promoterOptions($this->adminInfo)); }
    public function summary() { return app('json')->success((new CombinationPromotionServices())->promoterSummary($this->request->get(), $this->adminInfo)); }
    public function details() { return app('json')->success((new CombinationPromotionServices())->details($this->request->get(), $this->adminInfo, null, false, true)); }
    public function exportSummary() {
        $result = (new CombinationPromotionServices())->promoterSummary($this->request->get(), $this->adminInfo, true);
        try { $content = file_get_contents($result['path']); }
        finally { @unlink($result['path']); }
        return response($content, 200)->header(['Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="promoter-summary.csv"', 'Cache-Control' => 'private, no-store']);
    }
    public function exportDetails() { return app('json')->success((new CombinationPromotionServices())->details($this->request->get(), $this->adminInfo, null, true, true)); }
}
