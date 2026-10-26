<?php
namespace app\controller\api\v1\organization;

use app\Request;
use app\services\organization\OrganizationScopeService;

class OrganizationScope
{
    /** @var OrganizationScopeService */
    protected $services;

    public function __construct(OrganizationScopeService $services)
    {
        $this->services = $services;
    }

    /**
     * 当前用户可管组织/门店树（手机端共用选择组件）
     */
    public function tree(Request $request)
    {
        $uid = $request->hasMacro('uid') ? (int)$request->uid() : 0;
        $allowed = $uid > 0 ? $this->services->getResolvedStoreIdsByUid($uid) : [];
        $tree = $this->services->buildPickerTree($allowed);
        return app('json')->success([
            'tree' => $tree,
            'allowed_store_ids' => $allowed,
            'allowed_store_count' => count($allowed),
            'migrated' => $this->services->isMigrated(),
            'realtime' => true,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 解析门店范围（前端交互后后端二次校验）
     * 分析类筛选：realtime=true，每次重新解析
     * 创建/分配类：前端应固化返回的 resolved_store_ids 快照
     */
    public function resolve(Request $request)
    {
        $uid = $request->hasMacro('uid') ? (int)$request->uid() : 0;
        $params = $request->param([
            'store_ids' => '',
            'org_ids' => '',
            'excluded_store_ids' => '',
            'legacy_agent_id' => 0,
            'snapshot' => 0,
        ]);
        $allowed = [];
        if ($uid > 0) {
            $allowed = $this->services->getResolvedStoreIdsByUid($uid);
        } elseif ((int)($params['legacy_agent_id'] ?? 0) > 0) {
            $allowed = $this->services->getResolvedStoreIdsByLegacyAgentId((int)$params['legacy_agent_id']);
        }
        $resolved = $this->services->resolveStoreIdsFromFilter($params, $allowed);
        $isSnapshot = (int)($params['snapshot'] ?? 0) === 1;
        return app('json')->success([
            'org_ids' => $this->services->parseIdListPublic($params['org_ids'] ?? ''),
            'store_ids' => $this->services->parseIdListPublic($params['store_ids'] ?? ''),
            'excluded_store_ids' => $this->services->parseIdListPublic($params['excluded_store_ids'] ?? ''),
            'resolved_store_ids' => $resolved,
            'allowed_store_ids' => $allowed,
            'realtime' => !$isSnapshot,
            'snapshot' => $isSnapshot,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
