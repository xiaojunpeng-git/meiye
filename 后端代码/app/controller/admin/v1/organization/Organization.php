<?php
namespace app\controller\admin\v1\organization;

use app\controller\admin\AuthController;
use app\services\organization\OrganizationManageServices;
use app\services\organization\OrganizationMigrateServices;
use app\services\organization\OrganizationScopeService;
use app\services\organization\OrganizationWorkspaceReadServices;
use app\services\organization\OrganizationWorkspaceWriteServices;
use think\facade\App;

/**
 * 组织架构（兼容原 region 路由，新能力入口）
 */
class Organization extends AuthController
{
    /** @var OrganizationManageServices */
    protected $services;

    public function __construct(App $app, OrganizationManageServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    public function tree()
    {
        try {
            /** @var OrganizationWorkspaceReadServices $workspace */
            $workspace = app()->make(OrganizationWorkspaceReadServices::class);
            return $this->success($workspace->getTree());
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function counts()
    {
        return $this->success($this->services->getRegionCountsMap());
    }

    public function write_status()
    {
        try {
            /** @var OrganizationWorkspaceWriteServices $write */
            $write = app()->make(OrganizationWorkspaceWriteServices::class);
            return $this->success($write->getWriteStatus(is_array($this->adminInfo) ? $this->adminInfo : []));
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function save($id = 0)
    {
        $data = $this->request->postMore([
            [['pid', 'd'], 0],
            [['name', 's'], ''],
            [['sort', 'd'], 0],
            [['request_token', 's'], ''],
        ]);
        try {
            /** @var OrganizationWorkspaceWriteServices $write */
            $write = app()->make(OrganizationWorkspaceWriteServices::class);
            $ret = $write->saveOrganization(
                (int)$id,
                $data,
                is_array($this->adminInfo) ? $this->adminInfo : [],
                $this->buildWriteRequestCtx((string)($data['request_token'] ?? ''))
            );
            return $this->success($ret['msg'], $ret['data']);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function delete($id)
    {
        $bodyToken = '';
        try {
            $more = $this->request->postMore([
                [['request_token', 's'], ''],
            ]);
            $bodyToken = (string)($more['request_token'] ?? '');
        } catch (\Throwable $e) {
            $bodyToken = (string)$this->request->param('request_token', '');
        }
        try {
            /** @var OrganizationWorkspaceWriteServices $write */
            $write = app()->make(OrganizationWorkspaceWriteServices::class);
            $ret = $write->deleteOrganization(
                (int)$id,
                is_array($this->adminInfo) ? $this->adminInfo : [],
                $this->buildWriteRequestCtx($bodyToken)
            );
            return $this->success($ret['msg'], $ret['data']);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function bind_store()
    {
        [$storeId, $orgId, $requestToken] = $this->request->postMore([
            ['store_id', 0],
            ['org_id', 0],
            ['request_token', ''],
        ], true);
        try {
            /** @var OrganizationWorkspaceWriteServices $write */
            $write = app()->make(OrganizationWorkspaceWriteServices::class);
            $ret = $write->bindStore(
                (int)$storeId,
                (int)$orgId,
                is_array($this->adminInfo) ? $this->adminInfo : [],
                $this->buildWriteRequestCtx((string)$requestToken)
            );
            return $this->success($ret['msg'], $ret['data']);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function save_leaders($id)
    {
        [$leaders, $requestToken] = $this->request->postMore([
            ['leaders', []],
            ['request_token', ''],
        ], true);
        if (!is_array($leaders)) {
            $leaders = [];
        }
        try {
            /** @var OrganizationWorkspaceWriteServices $write */
            $write = app()->make(OrganizationWorkspaceWriteServices::class);
            $ret = $write->saveLeaders(
                (int)$id,
                $leaders,
                is_array($this->adminInfo) ? $this->adminInfo : [],
                $this->buildWriteRequestCtx((string)$requestToken)
            );
            return $this->success($ret['msg'], $ret['data']);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function save_admin_permission($orgAdminId)
    {
        [$scopeMode, $allowedStoreIds, $requestToken] = $this->request->postMore([
            ['scope_mode', ''],
            ['allowed_store_ids', []],
            ['request_token', ''],
        ], true);
        if (!is_array($allowedStoreIds)) {
            $allowedStoreIds = array_filter(array_map('intval', explode(',', (string)$allowedStoreIds)));
        }
        try {
            /** @var OrganizationWorkspaceWriteServices $write */
            $write = app()->make(OrganizationWorkspaceWriteServices::class);
            $ret = $write->saveAdminPermission(
                (int)$orgAdminId,
                (string)$scopeMode,
                $allowedStoreIds,
                is_array($this->adminInfo) ? $this->adminInfo : [],
                $this->buildWriteRequestCtx((string)$requestToken)
            );
            return $this->success($ret['msg'], $ret['data']);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function admin_excludes($orgAdminId)
    {
        try {
            return $this->success($this->services->getAdminExcludeData((int)$orgAdminId));
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function admin_excludes_by_agent($legacyAgentId)
    {
        try {
            return $this->success($this->services->getAdminExcludeDataByLegacyAgentId((int)$legacyAgentId));
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function save_admin_excludes($orgAdminId)
    {
        [$storeIds, $requestToken] = $this->request->postMore([
            ['store_ids', []],
            ['request_token', ''],
        ], true);
        if (!is_array($storeIds)) {
            $storeIds = array_filter(array_map('intval', explode(',', (string)$storeIds)));
        }
        try {
            /** @var OrganizationWorkspaceWriteServices $write */
            $write = app()->make(OrganizationWorkspaceWriteServices::class);
            $ret = $write->saveAdminExcludes(
                (int)$orgAdminId,
                $storeIds,
                is_array($this->adminInfo) ? $this->adminInfo : [],
                $this->buildWriteRequestCtx((string)$requestToken)
            );
            return $this->success($ret['msg'], $ret['data']);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function save_admin_excludes_by_agent($legacyAgentId)
    {
        [$storeIds, $requestToken] = $this->request->postMore([
            ['store_ids', []],
            ['request_token', ''],
        ], true);
        if (!is_array($storeIds)) {
            $storeIds = array_filter(array_map('intval', explode(',', (string)$storeIds)));
        }
        try {
            /** @var OrganizationWorkspaceWriteServices $write */
            $write = app()->make(OrganizationWorkspaceWriteServices::class);
            $ret = $write->saveAdminExcludesByAgent(
                (int)$legacyAgentId,
                $storeIds,
                is_array($this->adminInfo) ? $this->adminInfo : [],
                $this->buildWriteRequestCtx((string)$requestToken)
            );
            return $this->success($ret['msg'], $ret['data']);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function overview()
    {
        [$orgId, $scene] = $this->request->getMore([
            ['org_id', 0],
            ['scene', ''],
        ], true);
        try {
            // 旧 region/list 不传 scene：保持 getOrgOverview 原语义（含 need_migrate）
            if ((string)$scene === 'workspace') {
                /** @var OrganizationWorkspaceReadServices $workspace */
                $workspace = app()->make(OrganizationWorkspaceReadServices::class);
                return $this->success($workspace->getOverview((int)$orgId));
            }
            return $this->success($this->services->getOrgOverview((int)$orgId));
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function stores()
    {
        [$orgId, $scope, $keyword, $status, $attention, $page, $limit] = $this->request->getMore([
            ['org_id', 0],
            ['scope', 'all'],
            ['keyword', ''],
            ['status', ''],
            ['attention', ''],
            ['page', 1],
            ['limit', 10],
        ], true);
        try {
            /** @var OrganizationWorkspaceReadServices $workspace */
            $workspace = app()->make(OrganizationWorkspaceReadServices::class);
            return $this->success($workspace->getStores(
                (int)$orgId,
                (string)$scope,
                (string)$keyword,
                (string)$status,
                (string)$attention,
                (int)$page,
                (int)$limit
            ));
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function employees()
    {
        [$orgId, $scope, $keyword, $storeId, $role, $page, $limit] = $this->request->getMore([
            ['org_id', 0],
            ['scope', 'all'],
            ['keyword', ''],
            ['store_id', 0],
            ['role', ''],
            ['page', 1],
            ['limit', 10],
        ], true);
        try {
            /** @var OrganizationWorkspaceReadServices $workspace */
            $workspace = app()->make(OrganizationWorkspaceReadServices::class);
            return $this->success($workspace->getEmployees(
                (int)$orgId,
                (string)$scope,
                (string)$keyword,
                (int)$storeId,
                (string)$role,
                (int)$page,
                (int)$limit
            ));
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function leader_candidates()
    {
        [$keyword, $page, $limit] = $this->request->getMore([
            ['keyword', ''],
            ['page', 1],
            ['limit', 10],
        ], true);
        try {
            /** @var OrganizationWorkspaceReadServices $workspace */
            $workspace = app()->make(OrganizationWorkspaceReadServices::class);
            return $this->success($workspace->getLeaderCandidates(
                (string)$keyword,
                (int)$page,
                (int)$limit
            ));
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function permissions($id)
    {
        try {
            /** @var OrganizationWorkspaceReadServices $workspace */
            $workspace = app()->make(OrganizationWorkspaceReadServices::class);
            return $this->success($workspace->getPermissions((int)$id));
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function change_log()
    {
        [$orgId, $keyword, $action, $page, $limit] = $this->request->getMore([
            ['org_id', 0],
            ['keyword', ''],
            ['action', ''],
            ['page', 1],
            ['limit', 10],
        ], true);
        try {
            /** @var OrganizationWorkspaceReadServices $workspace */
            $workspace = app()->make(OrganizationWorkspaceReadServices::class);
            // 兼容原结构，仅增展示字段；默认 limit=10，旧页显式传参仍兼容
            return $this->success($workspace->getChangeLog(
                (int)$orgId,
                (string)$keyword,
                (string)$action,
                (int)$page,
                (int)$limit
            ));
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function migrate()
    {
        [$dryRun] = $this->request->postMore([
            ['dry_run', 1],
        ], true);
        try {
            /** @var OrganizationMigrateServices $migrate */
            $migrate = app()->make(OrganizationMigrateServices::class);
            $report = $migrate->migrateFromLegacy((int)$dryRun === 1);
            return $this->success($dryRun ? '预演完成' : '迁移完成', $report);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * 组织数据源状态（只读，不可通过本接口改 mode）
     */
    public function source_status()
    {
        try {
            /** @var OrganizationScopeService $scope */
            $scope = app()->make(OrganizationScopeService::class);
            return $this->success($scope->getSourceStatus());
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * 迁移就绪检查（只读）
     */
    public function migrate_readiness()
    {
        [$dryRun] = $this->request->getMore([
            ['dry_run', 1],
        ], true);
        try {
            /** @var OrganizationScopeService $scope */
            $scope = app()->make(OrganizationScopeService::class);
            return $this->success($scope->getMigrateReadiness((int)$dryRun === 1));
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * @return array{header_token:string,body_token:string,operator_ip:string,request_id:string}
     */
    protected function buildWriteRequestCtx(string $bodyToken = ''): array
    {
        $headerToken = '';
        try {
            $headerToken = (string)$this->request->header('X-Request-Token', '');
            if ($headerToken === '') {
                $headerToken = (string)$this->request->header('x-request-token', '');
            }
        } catch (\Throwable $e) {
            $headerToken = '';
        }
        $ip = '';
        try {
            $ip = (string)$this->request->ip();
        } catch (\Throwable $e) {
            $ip = '';
        }
        return [
            'header_token' => trim($headerToken),
            'body_token' => trim($bodyToken),
            'operator_ip' => $ip,
        ];
    }
}
