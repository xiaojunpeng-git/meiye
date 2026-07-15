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

    public function resolve(Request $request)
    {
        $uid = $request->hasMacro('uid') ? (int)$request->uid() : 0;
        $params = $request->getMore([
            ['store_ids', ''],
            ['org_ids', ''],
            ['excluded_store_ids', ''],
            ['legacy_agent_id', 0],
        ]);
        $allowed = [];
        if ($uid > 0) {
            $allowed = $this->services->getResolvedStoreIdsByUid($uid);
        } elseif ((int)($params['legacy_agent_id'] ?? 0) > 0) {
            $allowed = $this->services->getResolvedStoreIdsByLegacyAgentId((int)$params['legacy_agent_id']);
        }
        $resolved = $this->services->resolveStoreIdsFromFilter($params, $allowed);
        return app('json')->success([
            'resolved_store_ids' => $resolved,
            'allowed_store_ids' => $allowed,
            'realtime' => true,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
