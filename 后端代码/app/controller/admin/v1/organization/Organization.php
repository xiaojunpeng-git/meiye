<?php
namespace app\controller\admin\v1\organization;

use app\controller\admin\AuthController;
use app\services\organization\OrganizationManageServices;
use app\services\organization\OrganizationMigrateServices;
use app\services\organization\OrganizationScopeService;
use app\services\organization\OrganizationWorkspaceReadServices;
use app\services\organization\OrganizationWorkspaceWriteServices;
use think\facade\App;
use think\facade\Db;

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

    public function delete_blockers($id)
    {
        try {
            return $this->success($this->services->getDeleteBlockers((int)$id));
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

    /**
     * 组织权限授权候选（只读，精确 employee_id 关联）
     */
    public function admin_candidates($id)
    {
        [$keyword, $page, $limit] = $this->request->getMore([
            ['keyword', ''],
            ['page', 1],
            ['limit', 10],
        ], true);
        try {
            /** @var OrganizationWorkspaceReadServices $read */
            $read = app()->make(OrganizationWorkspaceReadServices::class);
            return $this->success($read->getAdminCandidates((int)$id, (string)$keyword, (int)$page, (int)$limit));
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * 新增组织权限授权关系
     */
    public function admin_grants($id)
    {
        [$employeeId, $adminId, $scopeMode, $allowedStoreIds, $requestToken] = $this->request->postMore([
            ['employee_id', 0],
            ['admin_id', 0],
            ['scope_mode', 'inherit'],
            ['allowed_store_ids', []],
            ['request_token', ''],
        ], true);
        if (!is_array($allowedStoreIds)) {
            $allowedStoreIds = array_filter(array_map('intval', explode(',', (string)$allowedStoreIds)));
        }
        try {
            /** @var OrganizationWorkspaceWriteServices $write */
            $write = app()->make(OrganizationWorkspaceWriteServices::class);
            $ret = $write->grantAdmin(
                (int)$id,
                (int)$employeeId,
                (int)$adminId,
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

    /**
     * 撤销组织权限授权关系
     */
    public function revoke_admin_grant($id, $orgAdminId)
    {
        [$requestToken] = $this->request->postMore([
            ['request_token', ''],
        ], true);
        // DELETE 也可能把 token 放 header；body 兼容
        if ($requestToken === '' && method_exists($this->request, 'deleteMore')) {
            try {
                [$requestToken] = $this->request->deleteMore([
                    ['request_token', ''],
                ], true);
            } catch (\Throwable $e) {
                $requestToken = '';
            }
        }
        try {
            /** @var OrganizationWorkspaceWriteServices $write */
            $write = app()->make(OrganizationWorkspaceWriteServices::class);
            $ret = $write->revokeAdminGrant(
                (int)$id,
                (int)$orgAdminId,
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

    /** I1：组织直属列表 */
    public function org_employees()
    {
        [$orgId, $page, $limit] = $this->request->getMore([
            [['org_id', 'd'], 0],
            [['page', 'd'], 1],
            [['limit', 'd'], 20],
        ], true);
        try {
            /** @var \app\services\organization\OrganizationEmployeeServices $svc */
            $svc = app()->make(\app\services\organization\OrganizationEmployeeServices::class);
            return $this->success($svc->getList((int)$orgId, (int)$page, (int)$limit));
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    /** I1：保存组织直属 */
    public function org_employees_save()
    {
        $data = $this->request->postMore([
            [['org_id', 'd'], 0],
            [['employee_id', 'd'], 0],
            ['phone', ''],
            ['name', ''],
            ['job_title', ''],
            [['sort', 'd'], 0],
            [['status', 'd'], 1],
            ['request_token', ''],
        ]);
        try {
            /** @var OrganizationWorkspaceWriteServices $writeGate */
            $gate = app()->make(\app\services\organization\OrganizationWorkspaceWriteGate::class);
            $permission = $gate->assertPlatformStaffMaintainPermission(is_array($this->adminInfo) ? $this->adminInfo : []);
            if (!$permission['ok']) {
                return $this->fail($permission['reason_text'] ?: '无权限');
            }
            $orgPermission = $gate->assertOrganizationManagePermission(
                (int)$data['org_id'],
                is_array($this->adminInfo) ? $this->adminInfo : []
            );
            if (!$orgPermission['ok']) {
                return $this->fail($orgPermission['reason_text'] ?: '无权限');
            }
            /** @var \app\services\organization\OrganizationEmployeeServices $svc */
            $svc = app()->make(\app\services\organization\OrganizationEmployeeServices::class);
            $ret = $svc->save($data, [
                'operator_id' => (int)$this->adminId,
                'operator_name' => (string)($this->adminInfo['real_name'] ?? $this->adminInfo['account'] ?? ''),
                'operator_ip' => (string)$this->request->ip(),
                'source' => 'admin',
                'reason' => '保存组织直属',
            ]);
            return $this->success('保存成功', $ret);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    /** I1：删除组织直属 */
    public function org_employees_delete($id = 0)
    {
        try {
            $gate = app()->make(\app\services\organization\OrganizationWorkspaceWriteGate::class);
            $permission = $gate->assertPlatformStaffMaintainPermission(is_array($this->adminInfo) ? $this->adminInfo : []);
            if (!$permission['ok']) {
                return $this->fail($permission['reason_text'] ?: '无权限');
            }
            $relation = Db::name('organization_employee')->where('id', (int)$id)->where('is_del', 0)->find();
            $orgPermission = $gate->assertOrganizationManagePermission(
                (int)($relation['org_id'] ?? 0),
                is_array($this->adminInfo) ? $this->adminInfo : []
            );
            if (!$orgPermission['ok']) {
                return $this->fail($orgPermission['reason_text'] ?: '无权限');
            }
            /** @var \app\services\organization\OrganizationEmployeeServices $svc */
            $svc = app()->make(\app\services\organization\OrganizationEmployeeServices::class);
            $svc->softDelete((int)$id, [
                'operator_id' => (int)$this->adminId,
                'operator_name' => (string)($this->adminInfo['real_name'] ?? $this->adminInfo['account'] ?? ''),
                'operator_ip' => (string)$this->request->ip(),
                'source' => 'admin',
            ]);
            return $this->success('已移除');
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    /** 完整移除组织名册及本组织管理员授权。 */
    public function org_employees_remove_completely($orgId = 0, $id = 0)
    {
        try {
            $data = $this->request->postMore([['request_token', '']]);
            $write = app()->make(OrganizationWorkspaceWriteServices::class);
            $ret = $write->removeOrganizationEmployeeCompletely(
                (int)$orgId,
                (int)$id,
                is_array($this->adminInfo) ? $this->adminInfo : [],
                $this->buildWriteRequestCtx((string)($data['request_token'] ?? ''))
            );
            return $this->success($ret['msg'], $ret['data']);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    /** I1：员工全局离职 */
    public function employee_leave($id = 0)
    {
        try {
            $gate = app()->make(\app\services\organization\OrganizationWorkspaceWriteGate::class);
            $gate->assertCanWrite();
            $permission = $gate->assertPlatformStaffMaintainPermission(is_array($this->adminInfo) ? $this->adminInfo : []);
            if (!$permission['ok']) {
                return $this->fail($permission['reason_text'] ?: '无权限');
            }
            /** @var \app\services\employee\EmployeeStaffWriteServices $write */
            $write = app()->make(\app\services\employee\EmployeeStaffWriteServices::class);
            $write->leaveEmployeeGlobally((int)$id, [
                'operator_id' => (int)$this->adminId,
                'operator_name' => (string)($this->adminInfo['real_name'] ?? $this->adminInfo['account'] ?? ''),
                'operator_ip' => (string)$this->request->ip(),
                'source' => 'admin',
                'reason' => '总部全局离职',
            ]);
            return $this->success('已办理全局离职');
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    /** 软删除人员档案（保留订单/工资/任职历史） */
    public function employee_archive_delete($id = 0)
    {
        try {
            $gate = app()->make(\app\services\organization\OrganizationWorkspaceWriteGate::class);
            $gate->assertCanWrite();
            $super = $gate->assertSuperAdmin(is_array($this->adminInfo) ? $this->adminInfo : []);
            if (!$super['ok']) {
                return $this->fail($super['reason_text'] ?: '无权限');
            }
            /** @var \app\services\employee\EmployeeStaffWriteServices $write */
            $write = app()->make(\app\services\employee\EmployeeStaffWriteServices::class);
            $write->softDeleteEmployeeArchive((int)$id, [
                'operator_id' => (int)$this->adminId,
                'operator_name' => (string)($this->adminInfo['real_name'] ?? $this->adminInfo['account'] ?? ''),
                'operator_ip' => (string)$this->request->ip(),
                'source' => 'admin',
                'reason' => '总部软删除人员档案',
            ]);
            return $this->success('已软删除人员档案（订单与工资依据仍保留）');
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    /** I1：调店申请列表 */
    public function transfer_applies()
    {
        [$status, $fromStoreId, $toStoreId, $employeeId, $page, $limit] = $this->request->getMore([
            ['status', ''],
            [['from_store_id', 'd'], 0],
            [['to_store_id', 'd'], 0],
            [['employee_id', 'd'], 0],
            [['page', 'd'], 1],
            [['limit', 'd'], 20],
        ], true);
        try {
            /** @var \app\services\store\StoreStaffTransferApplyServices $svc */
            $svc = app()->make(\app\services\store\StoreStaffTransferApplyServices::class);
            return $this->success($svc->getList([
                'status' => (string)$status,
                'from_store_id' => (int)$fromStoreId,
                'to_store_id' => (int)$toStoreId,
                'employee_id' => (int)$employeeId,
            ], (int)$page, (int)$limit));
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    /** 总部发起调店申请（走既有审批链，不直接改任职） */
    public function transfer_apply_create()
    {
        $data = $this->request->postMore([
            ['request_token', ''],
            [['source_staff_id', 'd'], 0],
            [['to_store_id', 'd'], 0],
            ['reason', ''],
        ]);
        try {
            $gate = app()->make(\app\services\organization\OrganizationWorkspaceWriteGate::class);
            $gate->assertCanWrite();
            $super = $gate->assertSuperAdmin(is_array($this->adminInfo) ? $this->adminInfo : []);
            if (!$super['ok']) {
                return $this->fail($super['reason_text'] ?: '无权限');
            }
            /** @var \app\services\store\StoreStaffTransferApplyServices $svc */
            $svc = app()->make(\app\services\store\StoreStaffTransferApplyServices::class);
            $ret = $svc->createByAdmin($data, [
                'id' => (int)$this->adminId,
                'name' => (string)($this->adminInfo['real_name'] ?? $this->adminInfo['account'] ?? ''),
            ]);
            return $this->success('申请已提交', $ret);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    /** I1：驳回调店申请 */
    public function transfer_apply_reject($id = 0)
    {
        [$reason] = $this->request->postMore([
            ['reject_reason', ''],
        ], true);
        try {
            $gate = app()->make(\app\services\organization\OrganizationWorkspaceWriteGate::class);
            $super = $gate->assertSuperAdmin(is_array($this->adminInfo) ? $this->adminInfo : []);
            if (!$super['ok']) {
                return $this->fail($super['reason_text'] ?: '无权限');
            }
            /** @var \app\services\store\StoreStaffTransferApplyServices $svc */
            $svc = app()->make(\app\services\store\StoreStaffTransferApplyServices::class);
            $ret = $svc->rejectByAdmin((int)$id, (string)$reason, [
                'id' => (int)$this->adminId,
                'name' => (string)($this->adminInfo['real_name'] ?? $this->adminInfo['account'] ?? ''),
            ]);
            return $this->success('已驳回', $ret);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    /** I1：批准并执行调店 */
    public function transfer_apply_approve($id = 0)
    {
        $data = $this->request->postMore([
            ['roles', []],
            [['position', 'd'], 0],
            [['position_level', 'd'], 0],
            [['is_manager', 'd'], 0],
            [['is_cashier', 'd'], 0],
        ]);
        try {
            $gate = app()->make(\app\services\organization\OrganizationWorkspaceWriteGate::class);
            $super = $gate->assertSuperAdmin(is_array($this->adminInfo) ? $this->adminInfo : []);
            if (!$super['ok']) {
                return $this->fail($super['reason_text'] ?: '无权限');
            }
            /** @var \app\services\store\StoreStaffTransferApplyServices $svc */
            $svc = app()->make(\app\services\store\StoreStaffTransferApplyServices::class);
            $ret = $svc->approveAndExecute((int)$id, $data, [
                'id' => (int)$this->adminId,
                'name' => (string)($this->adminInfo['real_name'] ?? $this->adminInfo['account'] ?? ''),
                'ip' => (string)$this->request->ip(),
            ]);
            return $this->success('已批准并完成调店', $ret);
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

    /**
     * 写接口会话身份：必须带 id/level/admin_type 供超管门禁与幂等 operator_id 使用
     * @return array{id:mixed,level:mixed,admin_type:mixed,account?:mixed,real_name?:mixed}
     */
    protected function writeAdminInfo(): array
    {
        $info = is_array($this->adminInfo) ? $this->adminInfo : [];
        return [
            'id' => $info['id'] ?? null,
            'level' => $info['level'] ?? null,
            'admin_type' => $info['admin_type'] ?? null,
            'account' => $info['account'] ?? '',
            'real_name' => $info['real_name'] ?? '',
        ];
    }

    // ========== I2 授权中心 / 角色发布 / 资源选择 ==========

    public function employee_auth($id)
    {
        try {
            $svc = app()->make(\app\services\organization\EmployeeAuthCenterServices::class);
            return $this->success($svc->getAuthBundle((int)$id));
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function employee_auth_audits($id)
    {
        $page = (int)$this->request->param('page', 1);
        $limit = (int)$this->request->param('limit', 20);
        try {
            $svc = app()->make(\app\services\organization\EmployeeAuthCenterServices::class);
            return $this->success($svc->listAudits((int)$id, $page, $limit));
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function employee_auth_platform($id)
    {
        $data = $this->request->postMore([
            [['admin_id', 'd'], 0],
            [['action', 's'], 'bind'],
            [['roles', 'a'], []],
            [['status', 'd'], -1],
            [['pwd_modified', 'd'], 0],
            [['request_token', 's'], ''],
            [['reason', 's'], ''],
        ]);
        if ((int)$data['status'] < 0) {
            unset($data['status']);
        }
        try {
            $svc = app()->make(\app\services\organization\EmployeeAuthCenterServices::class);
            $ret = $svc->savePlatformAuth(
                (int)$id,
                $data,
                $this->writeAdminInfo(),
                $this->buildWriteRequestCtx((string)($data['request_token'] ?? ''))
            );
            return $this->success($ret['msg'], $ret['data']);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function employee_auth_store($id)
    {
        $data = $this->request->postMore([
            [['staff_id', 'd'], 0],
            [['store_id', 'd'], 0],
            [['roles', 'a'], []],
            [['status', 'd'], -1],
            [['request_token', 's'], ''],
            [['reason', 's'], ''],
        ]);
        if ((int)$data['status'] < 0) {
            unset($data['status']);
        }
        try {
            $svc = app()->make(\app\services\organization\EmployeeAuthCenterServices::class);
            $ret = $svc->saveStoreBackendAuth(
                (int)$id,
                $data,
                $this->writeAdminInfo(),
                $this->buildWriteRequestCtx((string)($data['request_token'] ?? ''))
            );
            return $this->success($ret['msg'], $ret['data']);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function employee_auth_cashier($id)
    {
        $data = $this->request->postMore([
            [['staff_id', 'd'], 0],
            [['is_cashier', 'd'], 0],
            [['roles', 'a'], []],
            [['pwd_modified', 'd'], 0],
            [['request_token', 's'], ''],
            [['reason', 's'], ''],
        ]);
        try {
            $svc = app()->make(\app\services\organization\EmployeeAuthCenterServices::class);
            $ret = $svc->saveCashierAuth(
                (int)$id,
                $data,
                $this->writeAdminInfo(),
                $this->buildWriteRequestCtx((string)($data['request_token'] ?? ''))
            );
            return $this->success($ret['msg'], $ret['data']);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function employee_auth_mobile($id)
    {
        $data = $this->request->postMore([
            [['action', 's'], 'save'],
            [['scope_mode', 's'], 'all'],
            [['org_ids', 'a'], []],
            [['store_ids', 'a'], []],
            [['role_name', 's'], ''],
            [['rules', 's'], ''],
            [['status', 'd'], 1],
            [['request_token', 's'], ''],
            [['reason', 's'], ''],
        ]);
        try {
            $svc = app()->make(\app\services\organization\EmployeeAuthCenterServices::class);
            $ret = $svc->saveMobileAuth(
                (int)$id,
                $data,
                $this->writeAdminInfo(),
                $this->buildWriteRequestCtx((string)($data['request_token'] ?? ''))
            );
            return $this->success($ret['msg'], $ret['data']);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function role_templates()
    {
        try {
            $svc = app()->make(\app\services\organization\SystemRolePublishServices::class);
            return $this->success($svc->listTemplates([
                'keyword' => (string)$this->request->param('keyword', ''),
                'status' => $this->request->param('status', null),
                'page' => (int)$this->request->param('page', 1),
                'limit' => (int)$this->request->param('limit', 20),
            ]));
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function role_template_menus()
    {
        try {
            $svc = app()->make(\app\services\organization\SystemRolePublishServices::class);
            return $this->success($svc->getTemplateMenus());
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function role_template_detail($id)
    {
        try {
            $svc = app()->make(\app\services\organization\SystemRolePublishServices::class);
            return $this->success($svc->getTemplateDetail((int)$id));
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function role_template_save()
    {
        $data = $this->request->postMore([
            [['id', 'd'], 0],
            [['role_name', 's'], ''],
            [['remark', 's'], ''],
            [['status', 'd'], 1],
            [['allow_store_select', 'd'], 0],
            [['rules', 'a'], []],
            [['cashier_rules', 'a'], []],
            [['mall_rules', 'a'], []],
            [['checked_menus', 'a'], []],
            [['checked_cashier_menus', 'a'], []],
            [['checked_mall_menus', 'a'], []],
            [['request_token', 's'], ''],
        ]);
        try {
            $svc = app()->make(\app\services\organization\SystemRolePublishServices::class);
            $ret = $svc->saveTemplate(
                $data,
                $this->writeAdminInfo(),
                $this->buildWriteRequestCtx((string)($data['request_token'] ?? ''))
            );
            return $this->success($ret['msg'], $ret['data']);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function role_template_disable($id)
    {
        $data = $this->request->postMore([
            [['request_token', 's'], ''],
        ]);
        try {
            $svc = app()->make(\app\services\organization\SystemRolePublishServices::class);
            $ret = $svc->disableTemplate(
                (int)$id,
                $data,
                $this->writeAdminInfo(),
                $this->buildWriteRequestCtx((string)($data['request_token'] ?? ''))
            );
            return $this->success($ret['msg'], $ret['data']);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function role_template_publish()
    {
        $data = $this->request->postMore([
            [['template_role_id', 'd'], 0],
            [['store_id', 'd'], 0],
            [['store_ids', 'a'], []],
            [['org_id', 'd'], 0],
            [['channel', 's'], 'store_backend'],
            [['allow_store_select', 'd'], 1],
            [['request_token', 's'], ''],
        ]);
        try {
            $svc = app()->make(\app\services\organization\SystemRolePublishServices::class);
            $ret = $svc->publish(
                $data,
                $this->writeAdminInfo(),
                $this->buildWriteRequestCtx((string)($data['request_token'] ?? ''))
            );
            return $this->success($ret['msg'], $ret['data']);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function role_publish_disable($id)
    {
        $data = $this->request->postMore([
            [['request_token', 's'], ''],
        ]);
        try {
            $svc = app()->make(\app\services\organization\SystemRolePublishServices::class);
            $ret = $svc->disable(
                (int)$id,
                $data,
                $this->writeAdminInfo(),
                $this->buildWriteRequestCtx((string)($data['request_token'] ?? ''))
            );
            return $this->success($ret['msg'], $ret['data']);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function role_publishes()
    {
        try {
            $svc = app()->make(\app\services\organization\SystemRolePublishServices::class);
            return $this->success(['list' => $svc->listPublishes(
                (int)$this->request->param('template_role_id', 0),
                (int)$this->request->param('store_id', 0)
            )]);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function resource_selector()
    {
        try {
            $svc = app()->make(\app\services\organization\OrganizationResourceSelectorServices::class);
            return $this->success($svc->search([
                'resource' => (string)$this->request->param('resource', 'employee'),
                'keyword' => (string)$this->request->param('keyword', ''),
                'page' => (int)$this->request->param('page', 1),
                'limit' => (int)$this->request->param('limit', 20),
                'ids' => $this->request->param('ids', []),
                'disabled_ids' => $this->request->param('disabled_ids', []),
                'scope_org_id' => (int)$this->request->param('scope_org_id', 0),
            ], $this->writeAdminInfo()));
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ========== I2 岗位策略 / 人员岗位 / 数据权限 / 任职 / 运营停用 ==========

    public function job_positions()
    {
        try {
            $svc = app()->make(\app\services\organization\JobPositionPolicyServices::class);
            return $this->success($svc->listPositions([
                'keyword' => (string)$this->request->param('keyword', ''),
                'status' => $this->request->param('status', null),
                'page' => (int)$this->request->param('page', 1),
                'limit' => (int)$this->request->param('limit', 20),
            ]));
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function job_position_menus()
    {
        try {
            $svc = app()->make(\app\services\organization\JobPositionPolicyServices::class);
            return $this->success($svc->getPositionMenus());
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function job_position_detail($id)
    {
        try {
            $svc = app()->make(\app\services\organization\JobPositionPolicyServices::class);
            return $this->success($svc->getPositionDetail((int)$id));
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function job_position_save()
    {
        $data = $this->request->postMore([
            [['id', 'd'], 0],
            [['name', 's'], ''],
            [['status', 'd'], 1],
            [['remark', 's'], ''],
            [['allow_store_select', 'd'], 0],
            [['is_store_manager', 'd'], 0],
            [['status_only', 'd'], 0],
            [['use_platform', 'd'], 0],
            [['use_store', 'd'], 0],
            [['use_cashier', 'd'], 0],
            [['use_mobile', 'd'], 0],
            [['channel_rules', 'a'], []],
            [['platform_rules', 'a'], []],
            [['store_v3_rules', 'a'], []],
            [['store_rules', 'a'], []],
            [['cashier_rules', 'a'], []],
            [['mobile_rules', 'a'], []],
            [['request_token', 's'], ''],
        ]);
        try {
            $svc = app()->make(\app\services\organization\JobPositionPolicyServices::class);
            if ((int)($data['status_only'] ?? 0) === 1) {
                $ret = $svc->setPositionStatus(
                    (int)($data['id'] ?? 0),
                    (int)($data['status'] ?? 1),
                    $this->writeAdminInfo(),
                    $this->buildWriteRequestCtx((string)($data['request_token'] ?? ''))
                );
                return $this->success($ret['msg'], $ret['data']);
            }
            $ret = $svc->savePosition(
                $data,
                $this->writeAdminInfo(),
                $this->buildWriteRequestCtx((string)($data['request_token'] ?? ''))
            );
            return $this->success($ret['msg'], $ret['data']);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function job_position_publish()
    {
        $data = $this->request->postMore([
            [['position_id', 'd'], 0],
            [['scope_type', 's'], 'store'],
            [['scope_id', 'd'], 0],
            [['request_token', 's'], ''],
        ]);
        try {
            $svc = app()->make(\app\services\organization\JobPositionPolicyServices::class);
            $ret = $svc->publishPosition(
                $data,
                $this->writeAdminInfo(),
                $this->buildWriteRequestCtx((string)($data['request_token'] ?? ''))
            );
            return $this->success($ret['msg'], $ret['data']);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function job_position_publish_disable($id)
    {
        $data = $this->request->postMore([
            [['request_token', 's'], ''],
        ]);
        try {
            $svc = app()->make(\app\services\organization\JobPositionPolicyServices::class);
            $ret = $svc->disablePublish(
                (int)$id,
                $data,
                $this->writeAdminInfo(),
                $this->buildWriteRequestCtx((string)($data['request_token'] ?? ''))
            );
            return $this->success($ret['msg'], $ret['data']);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function employee_auth_jobs($id)
    {
        $data = $this->request->postMore([
            [['staff_id', 'd'], 0],
            [['store_id', 'd'], 0],
            [['position_ids', 'a'], []],
            [['request_token', 's'], ''],
            [['reason', 's'], ''],
        ]);
        try {
            $svc = app()->make(\app\services\organization\StaffJobPositionServices::class);
            $ret = $svc->saveStaffJobs(
                (int)$id,
                (int)$data['staff_id'],
                (int)$data['store_id'],
                is_array($data['position_ids']) ? $data['position_ids'] : [],
                $this->writeAdminInfo(),
                $this->buildWriteRequestCtx((string)($data['request_token'] ?? '')),
                'hq',
                true
            );
            return $this->success($ret['msg'], $ret['data']);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function employee_auth_data_scope($id)
    {
        $data = $this->request->postMore([
            [['source_type', 's'], 'hq'],
            [['source_store_id', 'd'], 0],
            [['scope_mode', 's'], 'personal'],
            [['org_ids', 'a'], []],
            [['store_ids', 'a'], []],
            [['request_token', 's'], ''],
            [['reason', 's'], ''],
        ]);
        try {
            $svc = app()->make(\app\services\organization\EmployeeDataScopeServices::class);
            $ret = $svc->saveScope(
                (int)$id,
                $data,
                $this->writeAdminInfo(),
                $this->buildWriteRequestCtx((string)($data['request_token'] ?? '')),
                true
            );
            return $this->success($ret['msg'], $ret['data']);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function employee_auth_entries($id)
    {
        $data = $this->request->postMore([
            [['staff_id', 'd'], 0],
            [['entries', 'a'], []],
            [['request_token', 's'], ''],
            [['reason', 's'], ''],
        ]);
        try {
            $svc = app()->make(\app\services\organization\StaffJobPositionServices::class);
            $ret = $svc->saveChannelEntries(
                (int)$id,
                (int)$data['staff_id'],
                is_array($data['entries']) ? $data['entries'] : [],
                $this->writeAdminInfo(),
                $this->buildWriteRequestCtx((string)($data['request_token'] ?? '')),
                'hq',
                true
            );
            return $this->success($ret['msg'], $ret['data']);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function employee_auth_tenure($id)
    {
        $data = $this->request->postMore([
            [['staff_id', 'd'], 0],
            [['action', 's'], ''],
            [['reason', 's'], ''],
            [['request_token', 's'], ''],
        ]);
        try {
            $svc = app()->make(\app\services\organization\StaffTenureServices::class);
            $ret = $svc->runAction(
                (int)$id,
                $data,
                $this->writeAdminInfo(),
                $this->buildWriteRequestCtx((string)($data['request_token'] ?? '')),
                true
            );
            return $this->success($ret['msg'], $ret['data']);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function org_ops_status($id)
    {
        $data = $this->request->postMore([
            [['status', 'd'], 1],
            [['reason', 's'], ''],
            [['request_token', 's'], ''],
        ]);
        try {
            $svc = app()->make(\app\services\organization\OrganizationOpsStatusServices::class);
            $ret = $svc->setOrganizationStatus(
                (int)$id,
                (int)$data['status'],
                (string)$data['reason'],
                $this->writeAdminInfo(),
                $this->buildWriteRequestCtx((string)($data['request_token'] ?? ''))
            );
            return $this->success($ret['msg'], $ret['data']);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function store_ops_status($id)
    {
        $data = $this->request->postMore([
            [['status', 'd'], 1],
            [['reason', 's'], ''],
            [['request_token', 's'], ''],
        ]);
        try {
            $svc = app()->make(\app\services\organization\OrganizationOpsStatusServices::class);
            $ret = $svc->setStoreBusinessStatus(
                (int)$id,
                (int)$data['status'],
                (string)$data['reason'],
                $this->writeAdminInfo(),
                $this->buildWriteRequestCtx((string)($data['request_token'] ?? ''))
            );
            return $this->success($ret['msg'], $ret['data']);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }
}
