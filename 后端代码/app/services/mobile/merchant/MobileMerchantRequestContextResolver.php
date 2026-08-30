<?php

namespace app\services\mobile\merchant;

use app\services\organization\JobPositionPolicyServices;
use app\services\organization\OrganizationOpsStatusServices;
use app\services\organization\StaffJobPositionServices;
use app\services\mobile\protocol\MobileApiException;
use think\facade\Db;

/**
 * Read-only MERCHANT_SESSION verifier for /api/mobile/*.
 *
 * This class deliberately has no dependency on MerchantEntryServices or any
 * legacy cashier/api token. The auth domain is the only issuer of the tables
 * consumed here; missing migration state is rejected rather than downgraded.
 */
final class MobileMerchantRequestContextResolver
{
    public const CONTRACT_VERSION = 'mobile-merchant-v1';

    /**
     * @return array{employeeId:int,accountId:int,operatorId:int,staffId:int,staffName:string,storeId:int,organizationId:string,visibleStoreIds:int[],dataScopeMode:string,permissionVersion:string,availableActions:string[],session:array,requestMetadata:array}
     */
    public function resolve($request): array
    {
        $meta = $this->metadata($request);
        $session = $this->merchantSession($meta);
        $projection = $this->projection($session, $meta);
        $context = $this->businessContext($projection);
        $employeeId = (int)$session['employee_id'];
        $staffId = (int)$context['staff_id'];
        $storeId = (int)$context['store_id'];
        if ($employeeId <= 0 || $storeId <= 0) {
            throw MobileApiException::business('MERCHANT_SESSION_EXPIRED', '商家会话上下文不完整，请重新进入商家端。', 'SESSION_TIMEOUT');
        }
        $this->assertSessionCurrent($session, $employeeId, $meta);
        $mobileAuth = $this->mobileAuthorization($employeeId);
        if ($staffId > 0) {
            $staff = $this->currentStaff($employeeId, $staffId, $storeId);
            $this->assertCurrentMobileJob($staffId, $mobileAuth);
            $this->assertBusinessEnabled($storeId);
            $dataScope = $this->employeeDataScope($employeeId, $storeId);
            $staffName = $this->staffName($staff);
        } else {
            $dataScope = $this->managerDataScope($employeeId, $mobileAuth, $storeId);
            $staffName = $this->employeeName($employeeId);
        }
        $actions = $this->availableActions($mobileAuth, $dataScope['mode']);
        $accountId = $this->internalAccountId($employeeId);
        return [
            'employeeId' => $employeeId,
            'accountId' => $accountId,
            'operatorId' => $employeeId,
            'staffId' => $staffId,
            'staffName' => $staffName,
            'storeId' => $storeId,
            'organizationId' => (string)$context['organization_id'],
            'visibleStoreIds' => $dataScope['stores'],
            'dataScopeMode' => $dataScope['mode'],
            'permissionVersion' => 'mobile:' . (int)$session['auth_version']
                . ':binding:' . (int)$session['employee_phone_binding_version'],
            'availableActions' => $actions,
            'session' => $session,
            'requestMetadata' => $meta,
        ];
    }

    private function internalAccountId(int $employeeId): int
    {
        $accountId = (int)Db::name('employee_internal_account')
            ->where('employee_id', $employeeId)
            ->where('status', 1)
            ->where('is_del', 0)
            ->value('id');
        if ($accountId <= 0) {
            throw MobileApiException::business('AUTH_VERSION_CHANGED', '员工统一账号已失效，请重新进入商家端。');
        }
        return $accountId;
    }

    public function assertAction(array $context, string $action): void
    {
        if (!in_array($action, (array)($context['availableActions'] ?? []), true)) {
            throw MobileApiException::business('MOBILE_JOB_FUNCTION_MISSING', '当前账号没有该手机端操作权限。');
        }
    }

    /** Builds the strict context expected by the shared customer-care domain. */
    public function customerCareContext(array $context): array
    {
        $store = Db::name('system_store')->where('id', (int)$context['storeId'])
            ->where('is_del', 0)->field('id,name')->find();
        $organization = Db::name('organization')->where('id', (int)$context['organizationId'])
            ->where('is_del', 0)->field('id,pid,name')->find();
        if (!is_array($store) || !is_array($organization)) {
            throw MobileApiException::business('STORE_DISABLED', '当前门店或组织已失效，请重新进入商家端。');
        }
        $actions = (array)$context['availableActions'];
        // A customer-care view grant covers direct formal record capture. Task
        // lifecycle writes remain limited to an attributable store staff member.
        $canView = in_array('CUSTOMER_CARE_VIEW', $actions, true);
        $canWrite = (int)$context['staffId'] > 0
            && in_array('CUSTOMER_CARE_WRITE', $actions, true);
        $canCreateRecord = $canView;
        return [
            'tenantId' => '0', 'staffId' => (int)$context['staffId'],
            'employeeId' => (int)$context['employeeId'], 'staffName' => (string)$context['staffName'],
            'operationStoreId' => (int)$context['storeId'], 'operationStoreName' => (string)$store['name'],
            'operationOrganizationId' => (string)$organization['id'],
            'operationOrganizationPath' => '/' . (string)$organization['id'],
            'operationOrganizationName' => trim((string)$organization['name']) ?: '未命名组织',
            'allowedBusinessStoreIds' => (array)$context['visibleStoreIds'],
            'businessTimezone' => 'Asia/Shanghai',
            'canViewAllTasks' => $context['dataScopeMode'] === 'STORES'
                && in_array('CUSTOMER_CARE_VIEW', $actions, true),
            'canCreateTask' => $canWrite, 'canCreateRecord' => $canCreateRecord,
            'canReassign' => false,
            // The projection always applies visibleStoreIds on the server.  A
            // data-scope label must not hide those scoped metrics from an
            // otherwise authorized mobile customer-care user.
            'canViewStatistics' => in_array('CUSTOMER_CARE_VIEW', $actions, true),
        ];
    }

    private function metadata($request): array
    {
        $resolved = $request->mobileRequestMetadata ?? null;
        if (is_array($resolved) && isset($resolved['merchantToken'], $resolved['clientSessionId'], $resolved['activeContextId'], $resolved['stateContextId'])) {
            return $resolved;
        }
        $version = trim((string)$request->header('X-Mobile-Contract-Version', ''));
        if ($version !== self::CONTRACT_VERSION) {
            throw MobileApiException::business('CONTRACT_VERSION_UNSUPPORTED', '手机端接口版本不匹配，请升级后重试。');
        }
        $meta = [];
        foreach ([
            'X-Mobile-Client-Session-Id' => 'clientSessionId',
            'X-Mobile-Platform' => 'platform', 'X-Mobile-Request-Id' => 'requestId',
            'X-Mobile-Active-Context-Id' => 'activeContextId',
            'X-Mobile-State-Context-Id' => 'stateContextId',
        ] as $header => $key) {
            $value = trim((string)$request->header($header, ''));
            if ($value === '' || strlen($value) > 128 || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $value)) {
                throw MobileApiException::protocol('CONTRACT_HEADER_INVALID', '手机端请求上下文字段无效。', $key);
            }
            $meta[$key] = $value;
        }
        if (trim((string)$request->header('X-Mobile-App-Session', '')) !== ''
            || trim((string)$request->header('X-Merchant-Token', '')) !== '') {
            throw MobileApiException::protocol('CREDENTIAL_CONFLICT', '商家端请求凭据不符合安全协议。', 'headers');
        }
        $authorization = trim((string)$request->header('Authorization', ''));
        if (!preg_match('/^Bearer ([A-Za-z0-9._~-]{24,256})$/D', $authorization, $matches)) {
            throw MobileApiException::business('AUTH_REQUIRED', '商家会话无效，请重新进入。');
        }
        $meta['merchantToken'] = $matches[1];
        return $meta;
    }

    private function merchantSession(array $meta): array
    {
        $row = Db::name('mobile_merchant_session')
            ->where('token_hash', hash('sha256', $meta['merchantToken']))->find();
        if (!is_array($row) || (string)($row['state'] ?? '') !== 'ACTIVE'
            || (int)($row['expires_at'] ?? 0) < time()) {
            throw MobileApiException::business('MERCHANT_SESSION_EXPIRED', '商家会话已失效，请重新进入商家端。', 'SESSION_TIMEOUT');
        }
        if (!hash_equals((string)$row['client_session_id_hash'], hash('sha256', $meta['clientSessionId']))) {
            throw MobileApiException::business('TOKEN_OWNER_MISMATCH', '商家会话与当前客户端不匹配。');
        }
        return $row;
    }

    private function projection(array $session, array $meta): array
    {
        $row = Db::name('mobile_merchant_session_projection')
            ->where('session_id', (string)$session['session_id'])->where('state', 'ACTIVE')->find();
        if (!is_array($row) || !hash_equals((string)$row['active_context_id'], $meta['activeContextId'])
            || !hash_equals((string)$row['state_context_id'], $meta['stateContextId'])) {
            throw MobileApiException::business('ACTIVE_CONTEXT_MISMATCH', '商家上下文已变化，请重新进入。');
        }
        return $row;
    }

    private function businessContext(array $projection): array
    {
        $row = Db::name('mobile_merchant_business_context')
            ->where('context_id', (string)$projection['business_context_id'])
            ->where('state', 'ACTIVE')->find();
        if (!is_array($row) || (string)($row['context_type'] ?? '') !== 'STORE') {
            throw MobileApiException::business('NO_VALID_ASSIGNMENT', '当前商家业务上下文不可用。');
        }
        return $row;
    }

    private function assertSessionCurrent(array $session, int $employeeId, array $meta): void
    {
        $lease = Db::name('mobile_merchant_lease')->where('employee_id', $employeeId)->find();
        $state = Db::name('employee_mobile_auth_state')->where('employee_id', $employeeId)->find();
        $employee = Db::name('employee')->where('id', $employeeId)->where('is_del', 0)->find();
        if (!is_array($lease) || !is_array($state) || !is_array($employee)
            || (int)($employee['status'] ?? 0) !== 1
            || !hash_equals((string)$lease['current_session_id'], (string)$session['session_id'])
            || (int)$lease['current_epoch'] !== (int)$session['session_epoch']
            || (int)$state['auth_version'] !== (int)$session['auth_version']
            || (int)$state['phone_binding_version'] !== (int)$session['employee_phone_binding_version']) {
            throw MobileApiException::business('AUTH_VERSION_CHANGED', '商家会话授权已变化，请重新进入。');
        }
    }

    private function currentStaff(int $employeeId, int $staffId, int $storeId): array
    {
        $row = Db::name('system_store_staff')->where('id', $staffId)->where('employee_id', $employeeId)
            ->where('store_id', $storeId)->where('status', 1)->where('is_del', 0)->find();
        if (!is_array($row)) {
            throw MobileApiException::business('NO_VALID_ASSIGNMENT', '当前员工没有有效任职，请重新进入商家端。');
        }
        return $row;
    }

    private function mobileAuthorization(int $employeeId): array
    {
        $row = Db::name('employee_mobile_auth')->where('employee_id', $employeeId)
            ->where('status', 1)->where('is_del', 0)->find();
        if (!is_array($row)) {
            throw MobileApiException::business('MOBILE_JOB_FUNCTION_MISSING', '当前员工没有有效手机端授权。');
        }
        return $row;
    }

    private function assertCurrentMobileJob(int $staffId, array $mobileAuth): void
    {
        /** @var StaffJobPositionServices $jobs */
        $jobs = app()->make(StaffJobPositionServices::class);
        $rules = $this->positiveIds($jobs->computeChannelRulesUnion($staffId, JobPositionPolicyServices::CHANNEL_MOBILE));
        $granted = $this->positiveIds(explode(',', (string)($mobileAuth['rules'] ?? '')));
        if (!$rules || array_values(array_diff($rules, $granted)) !== []) {
            throw MobileApiException::business('MOBILE_JOB_FUNCTION_MISSING', '当前任职未配置手机端岗位功能。');
        }
    }

    private function assertBusinessEnabled(int $storeId): void
    {
        /** @var OrganizationOpsStatusServices $ops */
        $ops = app()->make(OrganizationOpsStatusServices::class);
        if (!$ops->isStoreBusinessEnabled($storeId)) {
            throw MobileApiException::business('STORE_DISABLED', '当前门店或所属组织已停用。');
        }
    }

    /** @return array{mode:string,stores:int[]} */
    private function employeeDataScope(int $employeeId, int $storeId): array
    {
        $rows = Db::name('employee_data_scope')->where('employee_id', $employeeId)
            ->where('status', 1)->where('is_del', 0)->field('scope_mode,org_ids,source_store_id')->select()->toArray();
        $stores = [];
        foreach ($rows as $row) {
            $mode = (string)($row['scope_mode'] ?? 'personal');
            if ($mode === 'store_self') {
                $stores[] = (int)($row['source_store_id'] ?? 0);
            } elseif ($mode === 'store') {
                $stores = array_merge($stores, Db::name('system_store_staff')->where('employee_id', $employeeId)
                    ->where('status', 1)->where('is_del', 0)->column('store_id'));
            } elseif ($mode === 'org') {
                $orgIds = $this->positiveIds(json_decode((string)($row['org_ids'] ?? '[]'), true) ?: []);
                if ($orgIds) $stores = array_merge($stores, $this->organizationStoreIds($orgIds));
            }
        }
        $stores = $this->positiveIds($stores);
        $isolated = $this->positiveIds(Db::name('employee_store_isolation')->where('employee_id', $employeeId)
            ->where('status', 1)->where('is_del', 0)->column('store_id'));
        $stores = array_values(array_diff($stores, $isolated));
        if ($stores === []) {
            return ['mode' => 'PERSONAL_SELF', 'stores' => [$storeId]];
        }
        if (!in_array($storeId, $stores, true)) {
            throw MobileApiException::business('STORE_NOT_ALLOWED', '当前门店不在员工数据权限范围内。');
        }
        return ['mode' => 'STORES', 'stores' => [$storeId]];
    }

    /**
     * Organization-direct managers operate with staff_id=0. Their mobile
     * switch/rules remain mandatory; organization scope only defines visible
     * stores and never grants entry by itself.
     *
     * @return array{mode:string,stores:int[]}
     */
    private function managerDataScope(int $employeeId, array $mobileAuth, int $operationStoreId): array
    {
        $ruleIds = $this->positiveIds(explode(',', (string)($mobileAuth['rules'] ?? '')));
        if ($ruleIds === []) {
            throw MobileApiException::business('MOBILE_JOB_FUNCTION_MISSING', '当前员工没有有效手机端岗位功能。');
        }
        $dataScopeStoreIds = $this->explicitDataScopeStoreIds($employeeId);
        $visibleStoreIds = $dataScopeStoreIds;
        /** @var OrganizationOpsStatusServices $ops */
        $ops = app()->make(OrganizationOpsStatusServices::class);
        $visibleStoreIds = array_values(array_filter($visibleStoreIds, static fn(int $storeId): bool => $ops->isStoreBusinessEnabled($storeId)));
        sort($visibleStoreIds, SORT_NUMERIC);
        if ($visibleStoreIds === [] || !in_array($operationStoreId, $visibleStoreIds, true)) {
            throw MobileApiException::business('STORE_NOT_ALLOWED', '当前门店不在员工数据权限范围内。');
        }
        return ['mode' => 'STORES', 'stores' => $visibleStoreIds];
    }

    /** @return int[] */
    private function explicitDataScopeStoreIds(int $employeeId): array
    {
        $rows = Db::name('employee_data_scope')->where('employee_id', $employeeId)->where('status', 1)->where('is_del', 0)
            ->field('scope_mode,org_ids,source_store_id')->select()->toArray();
        $storeIds = [];
        foreach ($rows as $row) {
            $mode = (string)($row['scope_mode'] ?? 'personal');
            if ($mode === 'store_self') $storeIds[] = (int)($row['source_store_id'] ?? 0);
            if ($mode === 'store') {
                $storeIds = array_merge($storeIds, Db::name('system_store_staff')->where('employee_id', $employeeId)
                    ->where('status', 1)->where('is_del', 0)->column('store_id'));
            }
            if ($mode === 'org') {
                $orgIds = $this->positiveIds(json_decode((string)($row['org_ids'] ?? '[]'), true) ?: []);
                if ($orgIds) $storeIds = array_merge($storeIds, $this->organizationStoreIds($orgIds));
            }
        }
        $isolated = $this->positiveIds(Db::name('employee_store_isolation')->where('employee_id', $employeeId)
            ->where('status', 1)->where('is_del', 0)->column('store_id'));
        return array_values(array_diff($this->positiveIds($storeIds), $isolated));
    }

    private function employeeName(int $employeeId): string
    {
        $employee = Db::name('employee')->where('id', $employeeId)->where('is_del', 0)->field('name')->find() ?: [];
        $name = trim((string)($employee['name'] ?? ''));
        if ($name !== '') return $name;
        return '员工' . $employeeId;
    }

    private function availableActions(array $mobileAuth, string $scopeMode): array
    {
        $rules = $this->positiveIds(explode(',', (string)($mobileAuth['rules'] ?? '')));
        /** @var MobileMerchantCapabilityCatalog $catalog */
        $catalog = app()->make(MobileMerchantCapabilityCatalog::class);
        return $catalog->availableActions($rules, $scopeMode);
    }

    private function staffName(array $staff): string
    {
        foreach (['staff_name', 'real_name', 'name', 'nickname'] as $field) {
            $name = trim((string)($staff[$field] ?? ''));
            if ($name !== '') return $name;
        }
        return '员工' . (int)$staff['id'];
    }

    private function positiveIds(array $values): array
    {
        $ids = [];
        foreach ($values as $value) { $id = (int)$value; if ($id > 0) $ids[$id] = $id; }
        ksort($ids, SORT_NUMERIC);
        return array_values($ids);
    }

    /** @return int[] */
    private function organizationStoreIds(array $organizationIds): array
    {
        /** @var MobileMerchantOrganizationStoreScopeServices $scope */
        $scope = app()->make(MobileMerchantOrganizationStoreScopeServices::class);
        return $scope->resolve($organizationIds);
    }
}
