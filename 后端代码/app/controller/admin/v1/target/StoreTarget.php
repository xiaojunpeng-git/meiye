<?php

namespace app\controller\admin\v1\target;

use app\controller\admin\AuthController;
use app\services\target\StoreTargetServices;
use app\services\target\TargetSchemeServices;
use app\services\target\TargetSchemeReportServices;
use app\services\organization\EmployeeDataScopeServices;
use app\services\organization\OrganizationScopeService;
use app\services\report\StoreReportParticipantScopeServices;
use app\services\system\SystemRoleServices;
use app\model\store\SystemStore;
use think\facade\Db;
use think\facade\App;

/**
 * 总后台-门店目标
 */
class StoreTarget extends AuthController
{
    /**
     * @var StoreTargetServices
     */
    protected $services;

    public function __construct(App $app, StoreTargetServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    /**
     * 目标列表（汇总用）
     */
    public function list()
    {
        $where = $this->request->getMore([
            ['year', ''],
            ['store_id', ''],
            ['object_type', ''],
            ['page', 1],
            ['limit', 100],
        ]);
        return $this->success($this->services->list($where, []));
    }

    /**
     * 目标数据分析
     */
    public function analysis()
    {
        $where = $this->request->getMore([
            ['target_id', 0],
            ['id', 0],
            ['year', ''],
            ['store_id', ''],
            ['object_type', 3],
            ['start_month', ''],
            ['end_month', ''],
            ['metric_key', 'revenue'],
        ]);
        return $this->success($this->services->analysis($where, []));
    }

    /**
     * 门店/员工排行（分页）
     */
    public function ranking()
    {
        $where = $this->request->getMore([
            ['target_id', 0],
            ['metric_key', 'revenue'],
            ['sort_type', 'rate'],
            ['asc', 0],
            ['store_id', ''],
            ['object_type', 3],
            ['start_month', ''],
            ['end_month', ''],
            ['rank_type', 'employee'],
            ['page', 1],
            ['limit', 10],
        ]);
        $page = max(1, (int)($where['page'] ?? 1));
        $limit = max(1, min(100, (int)($where['limit'] ?? 10)));
        $rankType = (string)($where['rank_type'] ?? 'employee');

        if ($rankType === 'store') {
            if (empty($where['start_month']) || empty($where['end_month'])) {
                return $this->success($this->services->paginateRankingList([], $page, $limit));
            }
            return $this->success($this->services->storeRankingByPeriod($where, []));
        }

        $targetId = (int)($where['target_id'] ?? 0);
        $hasPeriod = !empty($where['start_month']) && !empty($where['end_month']);
        if ($hasPeriod) {
            return $this->success($this->services->employeeRankingByPeriod($where, []));
        }
        if (!$targetId) {
            return $this->success($this->services->paginateRankingList([], $page, $limit));
        }

        $list = $this->services->employeeRanking(
            $targetId,
            (string)($where['metric_key'] ?? 'revenue'),
            (string)($where['sort_type'] ?? 'rate'),
            (bool)(int)($where['asc'] ?? 0),
            []
        );

        return $this->success($this->services->paginateRankingList($list, $page, $limit));
    }

    /**
     * 门店/对象选项
     */
    public function storeOptions()
    {
        return $this->success($this->services->storeOptions(0, []));
    }

    /**
     * 指标选项
     */
    public function metricOptions()
    {
        return $this->success([
            'core_metrics' => $this->services->getMetricDefinitions(),
            'product_metric_types' => $this->services->getProductMetricTypes(),
        ]);
    }

    /**
     * 月度目标完成明细
     */
    public function monthlyDetail()
    {
        $where = $this->request->getMore([
            ['start_month', ''],
            ['end_month', ''],
            ['store_id', ''],
            ['object_type', 3],
            ['object_name', ''],
            ['metric_keys', ''],
        ]);
        return $this->success($this->services->monthlyDetail($where, []));
    }

    /**
     * 门店目标完成明细
     */
    public function storeDetail()
    {
        $where = $this->request->getMore([
            ['start_month', ''],
            ['end_month', ''],
            ['store_id', ''],
            ['object_type', 3],
            ['object_name', ''],
            ['metric_keys', ''],
        ]);
        return $this->success($this->services->storeDetail($where, []));
    }

    /** 新方案目录只列出经统一事实 Reader 核验的月度指标和当前账号可管理门店。 */
    public function schemeCatalog(TargetSchemeServices $schemes)
    {
        try {
            $context = $this->schemeContext(true);
            $catalog = $schemes->catalog();
            $catalog['stores'] = SystemStore::where('is_del', 0)->whereIn('id', $context['store_ids'])->field('id,name')->order('id')->select()->toArray();
            $catalog['categories'] = Db::name('store_product_category')->where('type', 0)->where('relation_id', 0)
                ->where('is_show', 1)->field('id,cate_name,pid')->order('sort desc,id asc')->select()->toArray();
            $catalog['tree'] = app()->make(OrganizationScopeService::class)->buildPickerTree($context['store_ids']);
            $catalog['allowed_store_ids'] = $context['store_ids'];
            return $this->success($catalog);
        } catch (\InvalidArgumentException $e) { return app('json')->fail($e->getMessage()); }
    }

    /** 商品仅用作目标对象选择，完成值仍按发生时事实快照读取。 */
    public function schemeProducts()
    {
        try {
            $context = $this->schemeContext(true);
            $storeId = (int)$this->request->get('store_id', 0);
            if (!in_array($storeId, $context['store_ids'], true)) throw new \InvalidArgumentException('无权选择该门店商品');
            $keyword = trim((string)$this->request->get('keyword', ''));
            $query = Db::name('store_product')->where('is_del', 0)->where('type', 1)->where('relation_id', $storeId);
            if ($keyword !== '') $query->whereLike('store_name|id', '%' . $keyword . '%');
            return $this->success($query->field('id,store_name,product_type')->order('id', 'desc')->limit(100)->select()->toArray());
        } catch (\InvalidArgumentException $e) { return app('json')->fail($e->getMessage()); }
    }

    public function schemeList(TargetSchemeServices $schemes)
    {
        try { return $this->success($schemes->listing($this->schemeContext(false))); }
        catch (\InvalidArgumentException $e) { return app('json')->fail($e->getMessage()); }
    }

    public function schemeDetail(TargetSchemeServices $schemes)
    {
        try { return $this->success($schemes->detail($this->schemeContext(false), (int)$this->request->get('id', 0))); }
        catch (\InvalidArgumentException $e) { return app('json')->fail($e->getMessage()); }
    }

    public function schemeCreate(TargetSchemeServices $schemes)
    {
        try { return $this->success($schemes->create($this->schemeContext(true), $this->request->post())); }
        catch (\InvalidArgumentException $e) { return app('json')->fail($e->getMessage()); }
    }

    /** 方案名称与说明只能在草稿阶段编辑，沿用门店范围和版本校验。 */
    public function schemeUpdate(TargetSchemeServices $schemes)
    {
        try { $data = $this->request->post(); return $this->success($schemes->updateDraft($this->schemeContext(true), (int)($data['scheme_id'] ?? 0), $data)); }
        catch (\InvalidArgumentException $e) { return app('json')->fail($e->getMessage()); }
    }

    public function schemeLines(TargetSchemeServices $schemes)
    {
        try { $data = $this->request->post(); return $this->success($schemes->replaceLines($this->schemeContext(true), (int)($data['scheme_id'] ?? 0), $data)); }
        catch (\InvalidArgumentException $e) { return app('json')->fail($e->getMessage()); }
    }

    public function schemeActivate(TargetSchemeServices $schemes)
    {
        try { $data = $this->request->post(); return $this->success($schemes->activate($this->schemeContext(true), (int)($data['scheme_id'] ?? 0), $data)); }
        catch (\InvalidArgumentException $e) { return app('json')->fail($e->getMessage()); }
    }

    /** 平台三张分析表共用这一结果，只改变展示方式，不再执行旧订单/人员业绩 SQL。 */
    public function schemeReport(TargetSchemeReportServices $reports)
    {
        try {
            $input = $this->request->getMore([
                ['report_code', 'target_analysis'], ['start_month', date('Y-m')], ['end_month', date('Y-m')],
                ['store_id', 0], ['store_ids', ''], ['metric_keys', ''], ['scope_type', ''],
            ]);
            if (!in_array($input['report_code'], ['target_analysis', 'target_monthly', 'target_store'], true))
                throw new \InvalidArgumentException('目标分析报表无效');
            // 三张分析表的服务端权限分别核验，不能仅凭目标方案管理权限读取其他报表。
            $reportPermission = ['target_analysis' => 'admin-target-data-analysis',
                'target_monthly' => 'admin-target-monthly-detail', 'target_store' => 'admin-target-store-detail'];
            $context = $this->schemeContext(false, $reportPermission[$input['report_code']]);
            $context['store_ids'] = app()->make(OrganizationScopeService::class)
                ->resolveScopedStoreIdsFromRequest($context['store_ids'], $input);
            return $this->success($reports->read($context, $input, (string)$input['report_code']));
        } catch (\InvalidArgumentException $e) { return app('json')->fail($e->getMessage()); }
    }

    /** 与平台统一报表同一组织数据权限上限，客户端选择只能收窄。 */
    private function schemeContext(bool $manage, string $permissionCode = 'admin-target-scheme'): array
    {
        if (!$this->schemePermission($permissionCode))
            throw new \InvalidArgumentException('当前账号未配置目标管理权限');
        $scope = app()->make(OrganizationScopeService::class);
        if ((int)$this->adminType === 3 && $this->agentId) {
            $ids = $scope->getResolvedStoreIdsByLegacyAgentId((int)$this->agentId);
        } else {
            $employeeId = (int)($this->adminInfo['employee_id'] ?? 0);
            if ($employeeId > 0) {
                $dataScope = app()->make(EmployeeDataScopeServices::class);
                $resolved = $dataScope->resolveEffectiveStoreIds($employeeId, 0, (array)$this->adminInfo);
                if ($resolved === null) {
                    $ids = SystemStore::where('is_del', 0)->where('name', '<>', '总部')->column('id');
                } elseif ($dataScope->resolvePrimaryHqScopeMode($employeeId) === EmployeeDataScopeServices::MODE_PERSONAL) {
                    // 个人数据权限只能查看其实际参与门店，不能据此管理整店目标。
                    $ids = $manage ? [] : app()->make(StoreReportParticipantScopeServices::class)
                        ->participatingStoreIds('0', $employeeId);
                } else {
                    $ids = $resolved;
                }
            } else {
                $ids = SystemStore::where('is_del', 0)->where('name', '<>', '总部')->column('id');
            }
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)$ids))));
        sort($ids);
        if ($manage && !$ids) throw new \InvalidArgumentException('当前账号没有可设置目标的门店范围');
        return ['tenant_id' => '0', 'store_ids' => $ids, 'actor_id' => (int)$this->adminId];
    }

    private function schemePermission(string $code): bool
    {
        if (!(int)($this->adminInfo['level'] ?? 0) && (int)$this->adminType !== 3) return true;
        $roles = $this->adminInfo['roles'] ?? [];
        $roles = is_string($roles) ? array_filter(explode(',', $roles)) : (array)$roles;
        if (!$roles) return false;
        foreach (app()->make(SystemRoleServices::class)->getRolesByAuth($roles, 1) as $menu)
            if ((string)($menu['unique_auth'] ?? '') === $code) return true;
        return false;
    }
}
