<?php

namespace app\controller\admin\v1\user;

use app\controller\admin\AuthController;
use app\model\store\SystemStore;
use app\services\cashier\v3\member\CustomerReferrerProfileServices;
use app\services\organization\OrganizationScopeService;

/** 平台顾客资料的受控 V3 推荐人入口，禁止复用旧 user/user 直写接口。 */
final class CustomerReferrerProfile extends AuthController
{
    public function read(int $memberId, CustomerReferrerProfileServices $services)
    {
        try {
            return $this->success($services->read($memberId, $this->allowedStoreIds()));
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function update(int $memberId, CustomerReferrerProfileServices $services)
    {
        [$referrerMemberId, $idempotencyKey] = $this->request->postMore([['referrerMemberId', 0], ['idempotencyKey', '']]);
        try {
            return $this->success('推荐人已保存', $services->update(
                $memberId, $referrerMemberId, $this->allowedStoreIds(), (int)$this->adminId,
                (array)$this->adminInfo, (string)$idempotencyKey
            ));
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        } catch (\Throwable $e) {
            return $this->fail('推荐人保存失败，请稍后重试。');
        }
    }

    private function allowedStoreIds(): array
    {
        /** @var OrganizationScopeService $scope */
        $scope = app()->make(OrganizationScopeService::class);
        if ((int)$this->adminType === 3 && (int)$this->agentId > 0) {
            return array_values(array_unique(array_map('intval', $scope->getResolvedStoreIdsByLegacyAgentId((int)$this->agentId))));
        }
        return array_values(array_unique(array_map('intval', SystemStore::where('is_del', 0)->where('name', '<>', '总部')->column('id') ?: [])));
    }
}
