<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$read = static function (string $path) use ($root): string {
    $value = file_get_contents($root . '/' . $path);
    if (!is_string($value)) throw new RuntimeException('missing:' . $path);
    return $value;
};
$failed = 0;
$assert = static function (string $id, bool $ok) use (&$failed): void {
    if ($ok) { echo "PASS {$id}\n"; return; }
    $failed++; fwrite(STDERR, "FAIL {$id}\n");
};

$routes = $read('后端代码/route/api-mobile.php');
$auth = $read('后端代码/app/services/mobile/auth/MobileAuthServices.php');
$sessions = $read('后端代码/app/services/mobile/merchant/MobileMerchantSessionServices.php');
$revocation = $read('后端代码/app/services/mobile/merchant/MobileAuthRevocationServices.php');
$merchantResolver = $read('后端代码/app/services/mobile/merchant/MobileMerchantRequestContextResolver.php');
$organizationStoreScope = $read('后端代码/app/services/mobile/merchant/MobileMerchantOrganizationStoreScopeServices.php');
$jobs = $read('后端代码/app/services/organization/StaffJobPositionServices.php');
$tenure = $read('后端代码/app/services/organization/StaffTenureServices.php');
$employeeWrite = $read('后端代码/app/services/employee/EmployeeStaffWriteServices.php');
$transport = $read('后端代码/app/http/middleware/mobile/MobileTransportEnvelopeMiddleware.php');
$exceptionHandler = $read('后端代码/app/ExceptionHandle.php');
$response = $read('后端代码/app/services/mobile/protocol/MobileApiResponse.php');
$metadata = $read('后端代码/app/services/mobile/protocol/MobileRequestMetadata.php');
$cors = $read('后端代码/config/cookie.php');
$authContract = json_decode($read('前端代码/mobile-vue3/src/shared/contracts/mobile-auth-v1.contract.json'), true);
$merchantContract = json_decode($read('前端代码/mobile-vue3/src/merchant/contracts/mobile-merchant-v1.contract.json'), true);

$assert('MA-SESSION-01',
    str_contains($routes, "captcha/challenges") && str_contains($routes, "sms/challenges")
    && str_contains($routes, "Route::post('session'") && str_contains($routes, "context/switch")
    && str_contains($routes, 'MobilePublicMiddleware') && str_contains($routes, 'MobileAppSessionMiddleware')
    && str_contains($routes, 'MobileMerchantSessionMiddleware') && str_contains($routes, 'ROUTE_NOT_FOUND')
);
$assert('MA-SESSION-02',
    str_contains($metadata, "X-Mobile-App-Session") && str_contains($metadata, "Authorization")
    && str_contains($metadata, "CREDENTIAL_CONFLICT") && str_contains($metadata, "CREDENTIAL_FORBIDDEN")
    && str_contains($metadata, "X-Mobile-Active-Context-Id") && str_contains($metadata, "X-Mobile-State-Context-Id")
);
$assert('MA-SESSION-02-CORS',
    str_contains($cors, 'X-Mobile-Contract-Version') && str_contains($cors, 'X-Mobile-Client-Session-Id')
    && str_contains($cors, 'X-Mobile-Platform') && str_contains($cors, 'X-Mobile-Request-Id')
    && str_contains($cors, 'X-Mobile-App-Session') && str_contains($cors, 'X-Mobile-Active-Context-Id')
    && str_contains($cors, 'X-Mobile-State-Context-Id')
);
$assert('MA-SESSION-03',
    str_contains($auth, "code_hmac") && str_contains($auth, "merchantEligibilitySeconds") === false
    && str_contains($auth, "SMS_PROVIDER_UNKNOWN") && str_contains($auth, "delivery_state' => 'PENDING'")
    && str_contains($auth, 'Db::transaction(function () use ($captcha') && str_contains($auth, '$this->sms->send($phone, $code)')
);
$assert('MA-SESSION-04',
    str_contains($sessions, "REPLACED_BY_NEW_DEVICE") && str_contains($sessions, "phone_binding_version")
    && str_contains($sessions, "merchant_consume_idempotency_hash") && str_contains($sessions, "TOKEN_OWNER_MISMATCH")
    && str_contains($sessions, "'expiresAt' => (string)\$session['expires_at']") && str_contains($sessions, "'authVersion' => (int)\$session['auth_version']")
    && str_contains($sessions, "MobileMerchantRequestContextResolver") === false
);
$assert('MA-SESSION-05',
    str_contains($transport, 'MobileApiException') && str_contains($transport, 'MobileApiResponse::failure')
    && str_contains($exceptionHandler, 'MobileApiResponse::failure')
    && str_contains($exceptionHandler, 'MobileApiResponse::validationFailure')
    && str_contains($response, 'public static function failure')
    && str_contains($response, 'public static function validationFailure')
);
$assert('MA-SESSION-05',
    str_contains($jobs, 'MobileAuthRevocationServices') && str_contains($jobs, "revokeAllInCurrentTransaction(\$employeeId, 'AUTH_CHANGED')")
    && !str_contains($tenure, '结构未就绪时不阻断任职写路径')
    && str_contains($employeeWrite, "'EMPLOYEE_DISABLED'")
);
$assert('MA-SESSION-05',
    str_contains($revocation, 'revokeAllInCurrentTransaction') && str_contains($revocation, "->lock(true)")
    && str_contains($revocation, "'auth_version' => \$authVersion") && str_contains($revocation, "'merchant_session_epoch' => \$epoch")
    && str_contains($revocation, "'current_session_id' => null") && !str_contains($revocation, 'catch (\\Throwable $exception) { }')
);
$assert('MA-SESSION-05-NEW-EMPLOYEE',
    str_contains($revocation, "Db::name('employee_mobile_auth_state')->insert")
    && str_contains($revocation, "'phone_binding_version' => 1")
    && str_contains($revocation, '员工认证状态初始化失败')
);
$assert('MA-SESSION-06',
    is_array($authContract) && ($authContract['endpoints']['createCaptchaChallenge']['success']['required'] ?? []) === ['contractVersion', 'challengeId', 'expiresAt']
    && ($authContract['endpoints']['verifyCaptchaChallenge']['success']['required'] ?? []) === ['contractVersion', 'challengeId', 'captchaProof', 'expiresAt']
    && ($merchantContract['endpoints']['createMerchantSession']['requestBodyRequired'] ?? []) === ['idempotencyKey']
);
$assert('MA-SESSION-07',
    str_contains($merchantResolver, "'MERCHANT_SESSION_EXPIRED'")
    && str_contains($merchantResolver, "'TOKEN_OWNER_MISMATCH'")
    && str_contains($merchantResolver, "'AUTH_VERSION_CHANGED'")
    && str_contains($merchantResolver, "'STORE_NOT_ALLOWED'")
    && str_contains($merchantResolver, "'MOBILE_JOB_FUNCTION_MISSING'")
    && !str_contains($merchantResolver, 'ValidateException')
);
$assert('MA-MANAGER-CONTEXT-01',
    str_contains($sessions, 'defaultEligibleManagerOperationContext')
    && str_contains($sessions, "['staff_id', '=', 0]")
    && str_contains($sessions, 'managerVisibleStoreIds')
    && str_contains($sessions, 'employeeDataScopeStoreIds')
    && !str_contains($sessions, 'mobileScopeStoreIds')
    && !str_contains($sessions, 'isStoreWithinMobileScope')
    && !str_contains($sessions, 'array_intersect($dataScopeIds')
    && str_contains($sessions, 'assertContextStillAllowed')
    && str_contains($sessions, "'updated_at' => \$now")
);
$assert('MA-MANAGER-CONTEXT-02',
    str_contains($merchantResolver, 'if ($staffId > 0)')
    && str_contains($merchantResolver, 'managerDataScope')
    && str_contains($merchantResolver, 'explicitDataScopeStoreIds')
    && str_contains($merchantResolver, 'staff_id=0')
    && str_contains($merchantResolver, "'visibleStoreIds' => \$dataScope['stores']")
);
$assert('MA-MANAGER-CONTEXT-03',
    str_contains($organizationStoreScope, 'OrganizationScopeService::class')
    && str_contains($organizationStoreScope, 'getOrgStoreIds($organizationId, true)')
    && str_contains($sessions, 'MobileMerchantOrganizationStoreScopeServices::class')
    && str_contains($merchantResolver, 'MobileMerchantOrganizationStoreScopeServices::class')
    && !str_contains($sessions, "whereIn('org_id', \$orgIds)->column('store_id')")
    && !str_contains($merchantResolver, "whereIn('org_id', \$orgIds)->column('store_id')")
);
$assert('MA-MANAGER-CONTEXT-04',
    str_contains($merchantResolver, "->field('name')->find()")
    && !str_contains($merchantResolver, "field('name,real_name,nickname')")
);
$assert('MA-MANAGER-CONTEXT-05',
    str_contains($merchantResolver, "Db::name('employee_internal_account')")
    && str_contains($merchantResolver, "'accountId' => \$accountId")
    && str_contains($merchantResolver, "'operatorId' => \$employeeId")
    && !str_contains($merchantResolver, "'accountId' => \$staffId")
);
exit($failed === 0 ? 0 : 1);
