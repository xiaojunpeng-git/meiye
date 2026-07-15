<?php
namespace app\controller\admin\v1\organization;

use app\controller\admin\AuthController;
use app\services\organization\OrganizationManageServices;
use app\services\organization\OrganizationMigrateServices;
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
        return $this->success($this->services->getTree());
    }

    public function counts()
    {
        return $this->success($this->services->getRegionCountsMap());
    }

    public function save($id = 0)
    {
        $data = $this->request->postMore([
            [['pid', 'd'], 0],
            [['name', 's'], ''],
            [['sort', 'd'], 0],
        ]);
        try {
            $orgId = $this->services->saveOrganization(
                (int)$id,
                $data,
                (int)($this->adminId ?? 0),
                (string)($this->adminInfo['account'] ?? '')
            );
            return $this->success('保存成功', ['id' => $orgId]);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function delete($id)
    {
        try {
            $this->services->deleteOrganization(
                (int)$id,
                (int)($this->adminId ?? 0),
                (string)($this->adminInfo['account'] ?? '')
            );
            return $this->success('删除成功');
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function bind_store()
    {
        [$storeId, $orgId] = $this->request->postMore([
            ['store_id', 0],
            ['org_id', 0],
        ], true);
        try {
            $this->services->bindStoreToOrg(
                (int)$storeId,
                (int)$orgId,
                (int)($this->adminId ?? 0),
                (string)($this->adminInfo['account'] ?? '')
            );
            return $this->success('绑定成功');
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
        [$storeIds] = $this->request->postMore([
            ['store_ids', []],
        ], true);
        if (!is_array($storeIds)) {
            $storeIds = array_filter(array_map('intval', explode(',', (string)$storeIds)));
        }
        try {
            $this->services->saveAdminStoreExcludes(
                (int)$orgAdminId,
                $storeIds,
                (int)($this->adminId ?? 0),
                (string)($this->adminInfo['account'] ?? '')
            );
            return $this->success('保存成功');
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function save_admin_excludes_by_agent($legacyAgentId)
    {
        [$storeIds] = $this->request->postMore([
            ['store_ids', []],
        ], true);
        if (!is_array($storeIds)) {
            $storeIds = array_filter(array_map('intval', explode(',', (string)$storeIds)));
        }
        try {
            $this->services->saveAdminStoreExcludesByLegacyAgentId(
                (int)$legacyAgentId,
                $storeIds,
                (int)($this->adminId ?? 0),
                (string)($this->adminInfo['account'] ?? '')
            );
            return $this->success('保存成功');
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function overview()
    {
        [$orgId] = $this->request->getMore([
            ['org_id', 0],
        ], true);
        try {
            return $this->success($this->services->getOrgOverview((int)$orgId));
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function change_log()
    {
        [$orgId, $page, $limit] = $this->request->getMore([
            ['org_id', 0],
            ['page', 1],
            ['limit', 20],
        ], true);
        return $this->success($this->services->getChangeLog((int)$orgId, (int)$page, (int)$limit));
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
            return $this->success('迁移完成', $report);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }
}
