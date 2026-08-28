<?php

declare(strict_types=1);

namespace app\services\mobile\merchant;

use app\services\employee\EmployeeInternalAccountServices;
use app\services\mobile\protocol\MobileApiException;
use app\services\mobile\protocol\MobileApiResponse;
use app\services\organization\OrganizationOpsStatusServices;
use app\services\organization\JobPositionPolicyServices;
use app\services\organization\StaffJobPositionServices;
use mohe\exceptions\AdminException;
use think\facade\Db;

/** Issues and maintains the new merchant bearer session; no legacy token is accepted. */
final class MobileMerchantSessionServices
{
    /**
     * Employee accounts are a first-class merchant sign-in method.  The
     * resulting browser session still has the same binding, authorization and
     * context validation as the SMS exchange; legacy cashier tokens never
     * cross into the mobile API.
     */
    public function passwordLogin(array $input, array $meta): array
    {
        $account = trim((string)($input['account'] ?? ''));
        $password = (string)($input['pwd'] ?? '');
        $installationId = $this->opaque($input['installationId'] ?? null, 'installationId');
        $idempotencyKey = $this->opaque($input['idempotencyKey'] ?? null, 'idempotencyKey');
        if ($account === '' || $password === '') {
            throw MobileApiException::business('AUTH_REQUIRED', '请输入员工账号和密码。');
        }

        try {
            /** @var EmployeeInternalAccountServices $accounts */
            $accounts = app()->make(EmployeeInternalAccountServices::class);
            $authenticated = $accounts->authenticateByPassword($account, $password);
        } catch (AdminException $exception) {
            throw MobileApiException::business('AUTH_REQUIRED', '员工账号或密码错误。');
        }

        $employeeId = (int)$authenticated['employee_id'];
        // Password login authenticates the employee account directly.  It must
        // not require a legacy member uid just to issue the merchant session.
        $root = $this->issueMerchantSession(
            $employeeId,
            ['app_session_id' => null, 'installation_digest' => hash('sha256', $installationId)],
            null,
            $meta,
            $idempotencyKey
        );
        $accounts->touchLogin((int)($authenticated['account_row']['id'] ?? 0), (string)app('request')->ip());
        return $root;
    }

    public function create(array $input, array $meta): array
    {
        $key = $this->opaque($input['idempotencyKey'] ?? null, 'idempotencyKey');
        $app = $this->appSession((string)$meta['appSession'], $meta);
        $verification = Db::name('mobile_phone_verification')->where('app_session_id', (string)$app['app_session_id'])
            ->where('state', 'ACTIVE')->order('verified_at', 'desc')->find();
        if (!$verification || (int)$verification['expires_at'] < time() || (int)$verification['employee_id_snapshot'] <= 0) {
            throw MobileApiException::business('PHONE_VERIFICATION_REQUIRED', '请重新完成手机号验证后进入商家端。');
        }
        $employeeId = (int)$verification['employee_id_snapshot'];
        return $this->issueMerchantSession($employeeId, $app, $verification, $meta, $key);
    }

    /**
     * Issue the employee-owned merchant session.  A nullable app session and
     * verification are intentional for password login; SMS/member login still
     * supplies both and keeps the legacy identity checks in place.
     */
    private function issueMerchantSession(int $employeeId, array $app, ?array $verification, array $meta, string $key): array
    {
        $requestHash = hash('sha256', ($verification ? 'create:' . (string)$app['app_session_id'] . ':' . (string)$verification['verification_id'] : 'password:') . $employeeId . ':' . (string)$meta['clientSessionId'] . ':' . (string)($app['installation_digest'] ?? ''));
        return Db::transaction(function () use ($key, $app, $verification, $employeeId, $meta, $requestHash): array {
            $receipt = Db::name('mobile_merchant_idempotency')->where('employee_id', $employeeId)->where('command_code', 'CREATE_SESSION')
                ->where('idempotency_key_hash', hash('sha256', $key))->lock(true)->find();
            if ($receipt) {
                if (!hash_equals((string)$receipt['request_hash'], $requestHash)) {
                    throw MobileApiException::protocol('IDEMPOTENCY_KEY_CONFLICT', '本次会话标识已用于其他请求。', 'idempotencyKey');
                }
                return $this->decryptReceipt((string)$receipt['response_ciphertext']);
            }
            $now = time();
            $employee = Db::name('employee')->where('id', $employeeId)->where('is_del', 0)->lock(true)->find();
            if (!$employee) throw MobileApiException::business('EMPLOYEE_NOT_FOUND', '未找到员工身份。');
            if ((int)($employee['status'] ?? 0) !== 1) throw MobileApiException::business('EMPLOYEE_DISABLED', '员工已停用。');
            $state = Db::name('employee_mobile_auth_state')->where('employee_id', $employeeId)->lock(true)->find();
            if (!$state) {
                throw MobileApiException::business('MOBILE_ENTRY_DISABLED', '当前员工未开通手机端。');
            }
            if ($verification !== null) {
                $binding = Db::name('employee_phone_binding')->where('employee_id', $employeeId)->where('state', 'BOUND')->lock(true)->find();
                $identity = Db::name('user_phone_identity')->where('phone_digest', (string)$verification['verified_phone_digest'])->lock(true)->find();
                if (!$binding || !$identity
                    || !hash_equals((string)$binding['phone_digest'], (string)$verification['verified_phone_digest'])
                    || (int)$verification['employee_phone_binding_version_snapshot'] !== (int)$state['phone_binding_version']
                    || (int)$verification['identity_version'] !== (int)$identity['identity_version']) {
                    throw MobileApiException::business('PHONE_VERIFICATION_REQUIRED', '手机号验证已失效，请重新验证。');
                }
            }
            $context = $this->currentContext($employeeId, $now);
            $lease = $this->lockOrCreateLease($employeeId, $now);
            $oldSessionId = (string)($lease['current_session_id'] ?? '');
            $epoch = (int)$lease['current_epoch'] + 1;
            if ($oldSessionId !== '') {
                Db::name('mobile_merchant_session')->where('session_id', $oldSessionId)->where('state', 'ACTIVE')->update([
                    'state' => 'REPLACED', 'revoked_at' => $now, 'session_end_cause' => 'REPLACED_BY_NEW_DEVICE', 'updated_at' => $now,
                ]);
            }
            $sessionId = $this->uuid();
            $token = $this->token();
            $projection = ['activeContextId' => $this->uuid(), 'stateContextId' => $this->uuid()];
            $expiresAt = $now + (int)config('mobile_auth.merchant_session_seconds');
            Db::name('mobile_merchant_session')->insert([
                'session_id' => $sessionId, 'employee_id' => $employeeId, 'origin_app_session_id' => $app['app_session_id'] ?? null,
                'token_hash' => hash('sha256', $token), 'client_session_id_hash' => hash('sha256', (string)$meta['clientSessionId']),
                'installation_digest' => (string)$app['installation_digest'], 'auth_version' => (int)$state['auth_version'],
                'session_epoch' => $epoch, 'employee_phone_binding_version' => (int)$state['phone_binding_version'],
                'elevation_use_id' => $this->uuid(), 'verification_id' => $verification['verification_id'] ?? null, 'state' => 'ACTIVE',
                'expires_at' => $expiresAt, 'revoked_at' => 0, 'session_end_cause' => 'NONE', 'created_at' => $now, 'updated_at' => $now,
            ]);
            Db::name('mobile_merchant_lease')->where('id', (int)$lease['id'])->update([
                'current_session_id' => $sessionId, 'current_epoch' => $epoch, 'state' => 'ACTIVE', 'updated_at' => $now,
            ]);
            Db::name('mobile_merchant_session_projection')->insert([
                'session_id' => $sessionId, 'business_context_id' => (string)$context['context_id'],
                'active_context_id' => $projection['activeContextId'], 'state_context_id' => $projection['stateContextId'],
                'state_revision' => 1, 'state' => 'ACTIVE', 'created_at' => $now, 'updated_at' => $now,
            ]);
            if ($verification !== null) {
                Db::name('mobile_phone_verification')->where('id', (int)$verification['id'])->update([
                    'state' => 'CONSUMED', 'merchant_consumed_at' => $now, 'merchant_consume_idempotency_hash' => hash('sha256', $key), 'updated_at' => $now,
                ]);
            }
            Db::name('mobile_merchant_security_audit')->insert([
                'employee_id' => $employeeId, 'actor_employee_id' => 0,
                'event_code' => $oldSessionId === '' ? 'MERCHANT_SESSION_CREATED' : 'MERCHANT_SESSION_REPLACED',
                'session_id' => $sessionId, 'lease_epoch_before' => (int)$lease['current_epoch'], 'lease_epoch_after' => $epoch,
                'auth_version_before' => (int)$state['auth_version'], 'auth_version_after' => (int)$state['auth_version'],
                'installation_digest' => (string)$app['installation_digest'], 'request_id' => (string)$meta['requestId'],
                'reason_code' => $oldSessionId === '' ? 'NONE' : 'REPLACED_BY_NEW_DEVICE', 'payload' => '{}', 'occurred_at' => $now, 'recorded_at' => $now,
            ]);
            $session = Db::name('mobile_merchant_session')->where('session_id', $sessionId)->find();
            $projectionRow = Db::name('mobile_merchant_session_projection')->where('session_id', $sessionId)->find();
            $root = $this->root($session ?: [], $projectionRow ?: [], $token, 'READY');
            Db::name('mobile_merchant_idempotency')->insert([
                'employee_id' => $employeeId, 'actor_employee_id' => 0, 'command_code' => 'CREATE_SESSION',
                'idempotency_key_hash' => hash('sha256', $key), 'request_hash' => $requestHash, 'state' => 'SUCCEEDED',
                'response_key_version' => (string)config('mobile_auth.hmac_key_version'), 'response_ciphertext' => $this->encryptReceipt($root),
                'expires_at' => $expiresAt, 'created_at' => $now, 'updated_at' => $now,
            ]);
            return $root;
        });
    }

    public function bootstrap(array $meta): array
    {
        return Db::transaction(function () use ($meta): array {
            [$session, $projection] = $this->currentSessionAndProjection($meta, true);
            $revision = (int)$projection['state_revision'] + 1;
            Db::name('mobile_merchant_session_projection')->where('id', (int)$projection['id'])->update(['state_revision' => $revision, 'updated_at' => time()]);
            $projection['state_revision'] = $revision;
            return $this->root($session, $projection, (string)$meta['merchantToken'], 'BOOTSTRAPPED');
        });
    }

    public function switchContext(array $input, array $meta): array
    {
        $target = $this->opaque($input['targetContextId'] ?? null, 'targetContextId');
        $expectedVersion = (int)($input['expectedAuthVersion'] ?? 0);
        $key = $this->opaque($input['idempotencyKey'] ?? null, 'idempotencyKey');
        if ($expectedVersion <= 0) throw MobileApiException::protocol('INVALID_REQUEST_FIELD', '授权版本无效。', 'expectedAuthVersion');
        return Db::transaction(function () use ($target, $expectedVersion, $key, $meta): array {
            [$session, $projection] = $this->currentSessionAndProjection($meta, true);
            if ($expectedVersion !== (int)$session['auth_version']) throw MobileApiException::business('AUTH_VERSION_CHANGED', '员工授权已变化，请重新进入。');
            if (hash_equals((string)$projection['business_context_id'], $target)) {
                throw MobileApiException::protocol('INVALID_REQUEST_FIELD', '不能切换到当前上下文。', 'targetContextId');
            }
            $context = Db::name('mobile_merchant_business_context')->where('context_id', $target)->where('employee_id', (int)$session['employee_id'])
                ->where('state', 'ACTIVE')->lock(true)->find();
            if (!$context) throw MobileApiException::business('NO_VALID_ASSIGNMENT', '目标商家上下文不可用。');
            $this->assertContextStillAllowed((int)$session['employee_id'], $context);
            $now = time();
            Db::name('mobile_merchant_business_context')->where('id', (int)$context['id'])->update(['updated_at' => $now]);
            Db::name('mobile_merchant_session_projection')->where('id', (int)$projection['id'])->update([
                'business_context_id' => $target, 'active_context_id' => $this->uuid(), 'state_context_id' => $this->uuid(),
                'state_revision' => 1, 'updated_at' => $now,
            ]);
            $updated = Db::name('mobile_merchant_session_projection')->where('id', (int)$projection['id'])->find();
            return $this->root($session, $updated ?: [], (string)$meta['merchantToken'], 'CONTEXT_SWITCHED');
        });
    }

    public function logout(array $input, array $meta): array
    {
        $this->opaque($input['idempotencyKey'] ?? null, 'idempotencyKey');
        Db::transaction(function () use ($meta): void {
            [$session] = $this->currentSessionAndProjection($meta, true);
            $now = time();
            Db::name('mobile_merchant_session')->where('id', (int)$session['id'])->update([
                'state' => 'SIGNED_OUT', 'revoked_at' => $now, 'session_end_cause' => 'SIGNED_OUT', 'updated_at' => $now,
            ]);
            Db::name('mobile_merchant_lease')->where('employee_id', (int)$session['employee_id'])->where('current_session_id', (string)$session['session_id'])->update([
                'current_session_id' => null, 'state' => 'REVOKED', 'updated_at' => $now,
            ]);
        });
        return ['success' => true];
    }

    private function appSession(string $token, array $meta): array
    {
        $row = Db::name('mobile_app_session')->where('token_hash', hash('sha256', $token))->find();
        if (!$row || (string)$row['state'] !== 'ACTIVE' || (int)$row['expires_at'] < time()
            || !hash_equals((string)$row['client_session_id_hash'], hash('sha256', (string)$meta['clientSessionId']))) {
            throw MobileApiException::business('AUTH_REQUIRED', 'App 会话已失效，请重新登录。');
        }
        return $row;
    }

    /** @return array{0:array,1:array} */
    private function currentSessionAndProjection(array $meta, bool $lock): array
    {
        $session = Db::name('mobile_merchant_session')->where('token_hash', hash('sha256', (string)$meta['merchantToken']))->when($lock, fn($q) => $q->lock(true))->find();
        if (!$session || (string)$session['state'] !== 'ACTIVE' || (int)$session['expires_at'] < time()) {
            throw MobileApiException::business('MERCHANT_SESSION_EXPIRED', '商家会话已失效，请重新进入。', 'SESSION_TIMEOUT');
        }
        if (!hash_equals((string)$session['client_session_id_hash'], hash('sha256', (string)$meta['clientSessionId']))) {
            throw MobileApiException::business('TOKEN_OWNER_MISMATCH', '商家会话不属于当前客户端。');
        }
        $lease = Db::name('mobile_merchant_lease')->where('employee_id', (int)$session['employee_id'])->when($lock, fn($q) => $q->lock(true))->find();
        $state = Db::name('employee_mobile_auth_state')->where('employee_id', (int)$session['employee_id'])->when($lock, fn($q) => $q->lock(true))->find();
        if (!$lease || !$state || !hash_equals((string)$lease['current_session_id'], (string)$session['session_id'])
            || (int)$lease['current_epoch'] !== (int)$session['session_epoch']) {
            throw MobileApiException::business('MERCHANT_SESSION_EXPIRED', '商家会话已被替换或退出。', 'REPLACED_BY_NEW_DEVICE');
        }
        if ((int)$state['auth_version'] !== (int)$session['auth_version'] || (int)$state['phone_binding_version'] !== (int)$session['employee_phone_binding_version']) {
            throw MobileApiException::business('AUTH_VERSION_CHANGED', '员工授权已变化，请重新进入。');
        }
        $projection = Db::name('mobile_merchant_session_projection')->where('session_id', (string)$session['session_id'])->where('state', 'ACTIVE')->when($lock, fn($q) => $q->lock(true))->find();
        if (!$projection || !hash_equals((string)$projection['active_context_id'], (string)$meta['activeContextId'])
            || !hash_equals((string)$projection['state_context_id'], (string)$meta['stateContextId'])) {
            throw MobileApiException::business('ACTIVE_CONTEXT_MISMATCH', '商家上下文已变化，请重新进入。');
        }
        return [$session, $projection];
    }

    private function currentContext(int $employeeId, int $now): array
    {
        $auth = Db::name('employee_mobile_auth')->where('employee_id', $employeeId)->where('status', 1)->where('is_del', 0)->find();
        if (!$auth) throw MobileApiException::business('MOBILE_JOB_FUNCTION_MISSING', '当前员工没有手机端功能授权。');
        $staff = $this->defaultEligibleMobileStaff($employeeId, $auth);
        if (!$staff) {
            return $this->defaultEligibleManagerOperationContext($employeeId, $auth, $now);
        }
        /** @var StaffJobPositionServices $jobs */
        $jobs = app()->make(StaffJobPositionServices::class);
        $requiredRules = $this->positiveIds($jobs->computeChannelRulesUnion((int)$staff['id'], JobPositionPolicyServices::CHANNEL_MOBILE));
        $grantedRules = $this->positiveIds(explode(',', (string)($auth['rules'] ?? '')));
        if (!$requiredRules || array_values(array_diff($requiredRules, $grantedRules)) !== []) {
            throw MobileApiException::business('MOBILE_JOB_FUNCTION_MISSING', '当前任职未配置完整手机端岗位功能。');
        }
        $storeId = (int)$staff['store_id'];
        /** @var OrganizationOpsStatusServices $operations */
        $operations = app()->make(OrganizationOpsStatusServices::class);
        if (!$operations->isStoreBusinessEnabled($storeId)) throw MobileApiException::business('STORE_DISABLED', '当前门店或所属组织已停用。');
        $this->assertMobileScope($auth, $employeeId, $storeId);
        $this->assertEmployeeDataScope($employeeId, $storeId);
        $organizationId = (int)Db::name('organization_store')->where('store_id', $storeId)->order('id', 'asc')->value('org_id');
        $existing = Db::name('mobile_merchant_business_context')->where([
            ['employee_id', '=', $employeeId], ['context_type', '=', 'STORE'], ['staff_id', '=', (int)$staff['id']], ['store_id', '=', $storeId], ['organization_id', '=', $organizationId],
        ])->find();
        if ($existing && (string)$existing['state'] === 'ACTIVE') return $existing;
        $contextId = $this->uuid();
        Db::name('mobile_merchant_business_context')->insert([
            'context_id' => $contextId, 'employee_id' => $employeeId, 'context_type' => 'STORE', 'staff_id' => (int)$staff['id'],
            'store_id' => $storeId, 'organization_id' => $organizationId, 'label' => (string)($staff['store_name'] ?? ('门店 ' . $storeId)),
            'state' => 'ACTIVE', 'created_at' => $now, 'closed_at' => 0, 'updated_at' => $now,
        ]);
        return Db::name('mobile_merchant_business_context')->where('context_id', $contextId)->find() ?: [];
    }

    /**
     * A staff member can hold several active store appointments.  The most
     * recent appointment is not automatically a mobile appointment, so the
     * default must be selected from the effective mobile-eligible set.
     */
    private function defaultEligibleMobileStaff(int $employeeId, array $auth): ?array
    {
        /** @var StaffJobPositionServices $jobs */
        $jobs = app()->make(StaffJobPositionServices::class);
        $grantedRules = $this->positiveIds(explode(',', (string)($auth['rules'] ?? '')));
        $staffRows = Db::name('system_store_staff')->where('employee_id', $employeeId)
            ->where('is_del', 0)->where('status', 1)->where('store_id', '>', 0)->order('id', 'desc')->select()->toArray();
        foreach ($staffRows as $staff) {
            $staffId = (int)$staff['id'];
            $storeId = (int)$staff['store_id'];
            if (!$this->isStoreWithinMobileScope($auth, $storeId) || !$this->isStoreWithinEmployeeDataScope($employeeId, $storeId) || $this->isEmployeeStoreIsolated($employeeId, $storeId)) continue;
            $requiredRules = $this->positiveIds($jobs->computeChannelRulesUnion($staffId, JobPositionPolicyServices::CHANNEL_MOBILE));
            if ($requiredRules === [] || array_values(array_diff($requiredRules, $grantedRules)) !== []) continue;
            /** @var OrganizationOpsStatusServices $operations */
            $operations = app()->make(OrganizationOpsStatusServices::class);
            if ($operations->isStoreBusinessEnabled($storeId)) return $staff;
        }
        return null;
    }

    /**
     * An organization-direct employee has no system_store_staff record. The
     * employee mobile switch remains the entry gate; data scope only narrows
     * stores available for an operation. staff_id=0 is deliberate and never
     * creates a fake appointment.
     */
    private function defaultEligibleManagerOperationContext(int $employeeId, array $auth, int $now): array
    {
        if ($this->positiveIds(explode(',', (string)($auth['rules'] ?? ''))) === []) {
            throw MobileApiException::business('MOBILE_JOB_FUNCTION_MISSING', '当前员工没有有效手机端岗位功能。');
        }
        $visibleStoreIds = $this->managerVisibleStoreIds($employeeId, $auth);
        if ($visibleStoreIds === []) {
            throw MobileApiException::business('STORE_NOT_ALLOWED', '当前数据权限范围内没有可操作的门店。');
        }

        // Last valid selection wins. First entry uses deterministic store-id order.
        $existing = Db::name('mobile_merchant_business_context')->where('employee_id', $employeeId)
            ->where('context_type', 'STORE')->where('staff_id', 0)->where('state', 'ACTIVE')
            ->whereIn('store_id', $visibleStoreIds)->order('updated_at', 'desc')->order('id', 'desc')->find();
        // Materialize only the currently allowed choices. Old contexts remain
        // historical rows but are rejected by assertContextStillAllowed.
        foreach ($visibleStoreIds as $storeId) {
            $this->upsertManagerOperationContext($employeeId, (int)$storeId, $now);
        }
        if (is_array($existing)) return $existing;
        return $this->upsertManagerOperationContext($employeeId, (int)$visibleStoreIds[0], $now);
    }

    /** @return int[] */
    private function managerVisibleStoreIds(int $employeeId, array $auth): array
    {
        // Scope is not an entry grant. This method is only reached after the
        // active employee_mobile_auth check in currentContext/switchContext.
        $dataScopeIds = $this->employeeDataScopeStoreIds($employeeId);
        if ($dataScopeIds === []) return [];
        $storeIds = array_values(array_intersect($dataScopeIds, $this->mobileScopeStoreIds($auth)));
        /** @var OrganizationOpsStatusServices $operations */
        $operations = app()->make(OrganizationOpsStatusServices::class);
        $storeIds = array_values(array_filter($storeIds, static fn(int $storeId): bool => $operations->isStoreBusinessEnabled($storeId)));
        sort($storeIds, SORT_NUMERIC);
        return $storeIds;
    }

    private function upsertManagerOperationContext(int $employeeId, int $storeId, int $now): array
    {
        $organizationId = (int)Db::name('organization_store')->where('store_id', $storeId)->order('id', 'asc')->value('org_id');
        $store = Db::name('system_store')->where('id', $storeId)->where('is_del', 0)->field('name')->find() ?: [];
        $existing = Db::name('mobile_merchant_business_context')->where([
            ['employee_id', '=', $employeeId], ['context_type', '=', 'STORE'], ['staff_id', '=', 0], ['store_id', '=', $storeId], ['organization_id', '=', $organizationId],
        ])->find();
        if ($existing) {
            if ((string)$existing['state'] !== 'ACTIVE') {
                Db::name('mobile_merchant_business_context')->where('id', (int)$existing['id'])->update(['state' => 'ACTIVE', 'closed_at' => 0, 'updated_at' => $now]);
            }
            return Db::name('mobile_merchant_business_context')->where('id', (int)$existing['id'])->find() ?: [];
        }
        $contextId = $this->uuid();
        Db::name('mobile_merchant_business_context')->insert([
            'context_id' => $contextId, 'employee_id' => $employeeId, 'context_type' => 'STORE', 'staff_id' => 0,
            'store_id' => $storeId, 'organization_id' => $organizationId, 'label' => (string)($store['name'] ?? ('门店 ' . $storeId)),
            'state' => 'ACTIVE', 'created_at' => $now, 'closed_at' => 0, 'updated_at' => $now,
        ]);
        return Db::name('mobile_merchant_business_context')->where('context_id', $contextId)->find() ?: [];
    }

    private function lockOrCreateLease(int $employeeId, int $now): array
    {
        $lease = Db::name('mobile_merchant_lease')->where('employee_id', $employeeId)->lock(true)->find();
        if ($lease) return $lease;
        try { Db::name('mobile_merchant_lease')->insert(['employee_id' => $employeeId, 'current_session_id' => null, 'current_epoch' => 1, 'state' => 'EMPTY', 'created_at' => $now, 'updated_at' => $now]); } catch (\Throwable $e) { }
        $lease = Db::name('mobile_merchant_lease')->where('employee_id', $employeeId)->lock(true)->find();
        if (!$lease) throw MobileApiException::business('AUTH_REQUIRED', '商家会话租约初始化失败。');
        return $lease;
    }

    private function root(array $session, array $projection, string $token, string $reason): array
    {
        $employee = Db::name('employee')->where('id', (int)$session['employee_id'])->find() ?: [];
        $context = Db::name('mobile_merchant_business_context')->where('context_id', (string)$projection['business_context_id'])->find() ?: [];
        $contexts = Db::name('mobile_merchant_business_context')->where('employee_id', (int)$session['employee_id'])->where('state', 'ACTIVE')
            ->field('context_id,label,staff_id,store_id')->order('created_at', 'asc')->select()->toArray();
        $auth = Db::name('employee_mobile_auth')->where('employee_id', (int)$session['employee_id'])->where('status', 1)->where('is_del', 0)->find() ?: [];
        $managerStoreIds = $this->managerVisibleStoreIds((int)$session['employee_id'], $auth);
        $contexts = array_values(array_filter($contexts, static function (array $row) use ($managerStoreIds): bool {
            return (int)($row['staff_id'] ?? 0) > 0 || in_array((int)($row['store_id'] ?? 0), $managerStoreIds, true);
        }));
        $contextSummary = array_map(static fn(array $row): array => ['contextId' => (string)$row['context_id'], 'label' => (string)$row['label']], $contexts);
        $actions = $this->availableActions((int)$session['employee_id']);
        $scopeDigest = hash('sha256', (string)$projection['business_context_id'] . ':' . (string)$session['auth_version']);
        return [
            'contractVersion' => MobileApiResponse::MERCHANT_CONTRACT, 'merchantToken' => $token, 'expiresAt' => (string)$session['expires_at'],
            'employee' => ['id' => (string)$session['employee_id'], 'name' => (string)($employee['name'] ?? $employee['real_name'] ?? '员工'), 'avatar' => (string)($employee['avatar'] ?? ''), 'status' => 'ACTIVE'],
            'authVersion' => (int)$session['auth_version'], 'activeContextId' => (string)$projection['active_context_id'],
            'activeContext' => ['contextId' => (string)$context['context_id'], 'storeId' => (string)($context['store_id'] ?? 0), 'organizationId' => (string)($context['organization_id'] ?? 0), 'staffId' => (string)($context['staff_id'] ?? 0)],
            'contexts' => $contextSummary, 'capabilities' => $actions, 'dataScope' => ['authorized' => true, 'scopeEntryCount' => 1, 'scopeDigest' => $scopeDigest],
            'availableActions' => $actions, 'reasonCode' => $reason, 'stateContextId' => (string)$projection['state_context_id'], 'stateRevision' => (string)$projection['state_revision'],
        ];
    }

    private function availableActions(int $employeeId): array
    {
        $auth = Db::name('employee_mobile_auth')->where('employee_id', $employeeId)->where('status', 1)->where('is_del', 0)->find();
        $ruleIds = array_values(array_filter(array_map('intval', explode(',', (string)($auth['rules'] ?? '')))));
        /** @var MobileMerchantCapabilityCatalog $catalog */
        $catalog = app()->make(MobileMerchantCapabilityCatalog::class);
        return $catalog->availableActions($ruleIds, $this->isPersonalScope($employeeId) ? 'PERSONAL_SELF' : 'STORES');
    }

    /** Revalidate scope and business status for every context switch. */
    private function assertContextStillAllowed(int $employeeId, array $context): void
    {
        $storeId = (int)($context['store_id'] ?? 0);
        if ($storeId <= 0 || (string)($context['context_type'] ?? '') !== 'STORE') {
            throw MobileApiException::business('NO_VALID_ASSIGNMENT', '目标商家上下文不可用。');
        }
        $auth = Db::name('employee_mobile_auth')->where('employee_id', $employeeId)->where('status', 1)->where('is_del', 0)->find();
        if (!is_array($auth)) throw MobileApiException::business('MOBILE_ENTRY_DISABLED', '当前员工未开通手机端。');
        if ((int)($context['staff_id'] ?? 0) === 0) {
            if (!in_array($storeId, $this->managerVisibleStoreIds($employeeId, $auth), true)) {
                throw MobileApiException::business('STORE_NOT_ALLOWED', '目标门店不在当前数据权限范围内。');
            }
            return;
        }
        $staff = $this->defaultEligibleMobileStaff($employeeId, $auth);
        if (!$staff || (int)$staff['id'] !== (int)$context['staff_id'] || (int)$staff['store_id'] !== $storeId) {
            throw MobileApiException::business('NO_VALID_ASSIGNMENT', '目标任职已失效，请重新进入商家端。');
        }
    }

    private function assertMobileScope(array $auth, int $employeeId, int $storeId): void
    {
        if (!$this->isStoreWithinMobileScope($auth, $storeId)) throw MobileApiException::business('STORE_NOT_ALLOWED', '当前门店不在手机端授权范围内。');
        if ($this->isEmployeeStoreIsolated($employeeId, $storeId)) throw MobileApiException::business('STORE_NOT_ALLOWED', '当前门店不在员工数据权限范围内。');
    }

    private function isStoreWithinMobileScope(array $auth, int $storeId): bool
    {
        $mode = (string)($auth['scope_mode'] ?? '');
        if ($mode === 'all') return true;
        $allowed = [];
        if ($mode === 'store') $allowed = json_decode((string)($auth['store_ids'] ?? '[]'), true) ?: [];
        if ($mode === 'org') {
            $orgIds = json_decode((string)($auth['org_ids'] ?? '[]'), true) ?: [];
            if ($orgIds) $allowed = $this->organizationStoreIds($orgIds);
        }
        return in_array($storeId, $this->positiveIds($allowed), true);
    }

    private function isEmployeeStoreIsolated(int $employeeId, int $storeId): bool
    {
        return (int)Db::name('employee_store_isolation')->where('employee_id', $employeeId)
            ->where('status', 1)->where('is_del', 0)->where('store_id', $storeId)->count() > 0;
    }

    /** @return int[] */
    private function mobileScopeStoreIds(array $auth): array
    {
        $mode = (string)($auth['scope_mode'] ?? '');
        if ($mode === 'all') return $this->positiveIds(Db::name('system_store')->where('is_del', 0)->column('id'));
        if ($mode === 'store') return $this->positiveIds(json_decode((string)($auth['store_ids'] ?? '[]'), true) ?: []);
        if ($mode === 'org') {
            $orgIds = $this->positiveIds(json_decode((string)($auth['org_ids'] ?? '[]'), true) ?: []);
            return $orgIds ? $this->organizationStoreIds($orgIds) : [];
        }
        return [];
    }

    /** @return int[] */
    private function employeeDataScopeStoreIds(int $employeeId): array
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

    private function isPersonalScope(int $employeeId): bool
    {
        $rows = Db::name('employee_data_scope')->where('employee_id', $employeeId)->where('status', 1)->where('is_del', 0)->field('scope_mode')->select()->toArray();
        if (!$rows) return true;
        foreach ($rows as $row) if (in_array((string)($row['scope_mode'] ?? ''), ['store_self', 'store', 'org'], true)) return false;
        return true;
    }

    private function assertEmployeeDataScope(int $employeeId, int $storeId): void
    {
        if (!$this->isStoreWithinEmployeeDataScope($employeeId, $storeId)) {
            throw MobileApiException::business('STORE_NOT_ALLOWED', '当前门店不在员工数据权限范围内。');
        }
    }

    private function isStoreWithinEmployeeDataScope(int $employeeId, int $storeId): bool
    {
        $rows = Db::name('employee_data_scope')->where('employee_id', $employeeId)->where('status', 1)->where('is_del', 0)
            ->field('scope_mode,org_ids,source_store_id')->select()->toArray();
        $stores = [];
        foreach ($rows as $row) {
            $mode = (string)($row['scope_mode'] ?? 'personal');
            if ($mode === 'store_self') $stores[] = (int)($row['source_store_id'] ?? 0);
            if ($mode === 'store') $stores = array_merge($stores, Db::name('system_store_staff')->where('employee_id', $employeeId)->where('status', 1)->where('is_del', 0)->column('store_id'));
            if ($mode === 'org') {
                $orgIds = $this->positiveIds(json_decode((string)($row['org_ids'] ?? '[]'), true) ?: []);
                if ($orgIds) $stores = array_merge($stores, $this->organizationStoreIds($orgIds));
            }
        }
        $stores = $this->positiveIds($stores);
        return $stores === [] || in_array($storeId, $stores, true);
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

    private function encryptReceipt(array $root): string
    {
        $key = hash('sha256', (string)config('mobile_auth.hmac_key'), true);
        $iv = random_bytes(12); $tag = '';
        $ciphertext = openssl_encrypt(json_encode($root, JSON_UNESCAPED_UNICODE), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) throw MobileApiException::business('AUTH_REQUIRED', '会话回执加密失败。');
        return base64_encode($iv . $tag . $ciphertext);
    }
    private function decryptReceipt(string $ciphertext): array
    {
        $bytes = base64_decode($ciphertext, true); if ($bytes === false || strlen($bytes) < 29) throw MobileApiException::business('AUTH_REQUIRED', '会话回执不可用。');
        $key = hash('sha256', (string)config('mobile_auth.hmac_key'), true);
        $json = openssl_decrypt(substr($bytes, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($bytes, 0, 12), substr($bytes, 12, 16));
        $data = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($data)) throw MobileApiException::business('AUTH_REQUIRED', '会话回执不可用。');
        return $data;
    }
    private function opaque($value, string $field): string { $value = trim((string)$value); if (!preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $value)) throw MobileApiException::protocol('INVALID_REQUEST_FIELD', '请求字段格式无效。', $field); return $value; }
    private function token(): string { return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='); }
    private function uuid(): string { $bytes = random_bytes(16); $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4)); }
}
