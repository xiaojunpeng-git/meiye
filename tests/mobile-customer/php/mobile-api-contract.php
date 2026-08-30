<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$read = static function (string $relative) use ($root): string {
    $content = file_get_contents($root . '/' . $relative);
    if (!is_string($content)) {
        throw new RuntimeException('missing_source:' . $relative);
    }
    return $content;
};
$failed = 0;
$assert = static function (string $id, bool $condition) use (&$failed): void {
    if ($condition) {
        echo "PASS {$id}\n";
        return;
    }
    $failed++;
    fwrite(STDERR, "FAIL {$id}\n");
};

$resolver = $read('后端代码/app/services/mobile/merchant/MobileMerchantRequestContextResolver.php');
$query = $read('后端代码/app/services/mobile/customer/MobileCustomerQueryServices.php');
$serviceRecords = $read('后端代码/app/services/mobile/customer/MobileCustomerServiceRecordServices.php');
$recentSummary = $read('后端代码/app/services/mobile/customer/MobileCustomerRecentSummaryServices.php');
$orderRecords = $read('后端代码/app/services/mobile/customer/MobileCustomerOrderRecordServices.php');
$assetRecords = $read('后端代码/app/services/mobile/customer/MobileCustomerAssetRecordServices.php');
$profiles = $read('后端代码/app/services/mobile/customer/MobileCustomerProfileServices.php');
$avatarUploads = $read('后端代码/app/services/mobile/customer/MobileCustomerAvatarUploadServices.php');
$audienceOverview = $read('后端代码/app/services/mobile/customer/MobileCustomerAudienceOverviewServices.php');
$memberProvider = $read('后端代码/app/services/query/provider/MemberUnifiedQueryProvider.php');
$memberRegistrar = $read('后端代码/app/services/query/provider/MemberUnifiedQueryPageRegistrar.php');
$controller = $read('后端代码/app/controller/mobile/merchant/Customer.php');
$command = $read('后端代码/app/services/mobile/customer/MobileCustomerUnifiedQueryCommandServices.php');
$routes = $read('后端代码/route/api-mobile.php');
$exceptionHandle = $read('后端代码/app/ExceptionHandle.php');
$contract = json_decode($read('前端代码/mobile-vue3/src/merchant/contracts/mobile-customer-v1.contract.json'), true);

$assert('MC-API-01',
	str_contains($resolver, "public const CONTRACT_VERSION = 'mobile-merchant-v1'")
	&& str_contains($resolver, "header('Authorization'")
	&& str_contains($resolver, 'mobile_merchant_session')
	&& str_contains($resolver, 'mobile_merchant_session_projection')
    && str_contains($resolver, "X-Mobile-Active-Context-Id")
    && str_contains($resolver, "X-Mobile-State-Context-Id")
    && str_contains($resolver, "header('X-Merchant-Token'")
);
$assert('MC-API-02',
    str_contains($query, "['employeeId', 'accountId', 'storeScope', 'organizationScope', 'permissionScope']")
    && str_contains($query, "MemberUnifiedQueryProvider::PAGE_CODE")
    && !str_contains($query, "Db::name('user')->insert")
    && !str_contains($query, "Db::name('user')->update")
	&& str_contains($query, "mobile_source_filters")
	&& str_contains($query, "inactive_30_days")
	&& str_contains($query, "inactive_purchase_30_days")
	&& str_contains($query, "inactive_days")
	&& str_contains($query, "inactive_purchase_days")
	&& str_contains($query, "inactiveDaysInput")
	&& str_contains($query, '$days > 3650')
	&& str_contains($query, "modify('-' . \$days . ' days')")
);
$assert('MC-API-03',
	str_contains($controller, "validatedAudienceRule")
	&& str_contains($controller, "queryAudience")
	&& str_contains($controller, "assertAction(")
	&& str_contains($routes, 'api/mobile/merchant')
	&& str_contains($routes, "customers/exclusive/query")
    && str_contains($routes, "customer-audiences/:audienceId")
    && str_contains($routes, "customer-audiences/:audienceId/members/query")
    && str_contains($routes, "customer-care/workbench")
);
$assert('MC-API-04',
    str_contains($query, "'granted_features' => ['cashier.v3.member']")
    && str_contains($query, "'manage_shared_fields' => true")
    && str_contains($query, '框架内部保留权限')
    && is_array($contract)
    && ($contract['dataAuthority']['capabilitiesMustNotDependOnFineGrainedRole'] ?? false) === true
    && ($contract['dataAuthority']['dataScope'] ?? '') === 'SERVER_ENFORCED_INTERSECTION'
    && ($contract['endpoints']['queryCustomers']['authorization'] ?? '') === 'MERCHANT_SESSION_AND_SERVER_DATA_SCOPE'
    && str_contains($exceptionHandle, 'UnifiedQueryException')
    && str_contains($exceptionHandle, 'MobileApiResponse::validationFailure($e, $request)')
);
$assert('MC-API-05',
    str_contains($query, 'public function unifiedCapabilities')
    && str_contains($query, 'PERSONAL_SELF')
    && str_contains($query, 'must never turn a personal customer scope into a')
    && str_contains($controller, 'unifiedQueryCapabilities')
    && str_contains($controller, 'unifiedQueryCommand')
    && str_contains($routes, 'customers/unified-query-capabilities')
    && str_contains($routes, 'customers/unified-query/commands')
);
$assert('MC-API-06',
    str_contains($command, "private const RECEIPT_TABLE = 'mobile_customer_unified_query_command_receipt'")
    && str_contains($command, 'Db::transaction')
    && str_contains($command, '->lock(true)')
    && str_contains($command, 'MOBILE_CUSTOMER_UNIFIED_QUERY_CLIENT_SCOPE_FORBIDDEN')
    && str_contains($command, "'pageCode' => MemberUnifiedQueryProvider::PAGE_CODE")
    && is_array($contract)
    && ($contract['endpoints']['unifiedQueryCommand']['authorization'] ?? '') === 'MERCHANT_SESSION_AND_SERVER_DATA_SCOPE'
    && count($contract['unifiedQueryCommand']['supportedActions'] ?? []) === 7
    && ($contract['unifiedQueryCommand']['personalScopeMustNotExpand'] ?? false) === true
);
$assert('MC-API-07',
    str_contains($query, "\$runtime['preferences']->load(\$context, MemberUnifiedQueryProvider::PAGE_CODE)")
    && str_contains($query, "'filters' => array_merge(")
    && str_contains($query, "'visibleFields' => (array)(\$savedSettings['visibleFields'] ?? [])")
    && str_contains($query, 'never replace a')
    && str_contains($query, 'widen the data scope')
);
$assert('MC-API-08',
    str_contains($query, 'public function assertMemberVisible')
    && str_contains($serviceRecords, "cashier_v3_entitlement_service_fact")
    && str_contains($serviceRecords, "->where('sf.service_status', 'completed')")
    && str_contains($serviceRecords, "CASHIER_V3_COMPLETED_SERVICE_FACT")
    && str_contains($controller, 'public function serviceRecords()')
    && str_contains($routes, "customers/service-records")
    && is_array($contract)
    && ($contract['endpoints']['queryCustomerServiceRecords']['authority'] ?? '') === 'CASHIER_V3_COMPLETED_SERVICE_FACT'
    && ($contract['endpoints']['queryCustomerServiceRecords']['authorization'] ?? '') === 'MERCHANT_SESSION_AND_SERVER_DATA_SCOPE'
);
$assert('MC-API-09',
    str_contains($assetRecords, "private const TYPES = ['entitlements', 'balance_changes', 'debts']")
    && str_contains($assetRecords, 'assertMemberVisible')
    && str_contains($assetRecords, "Db::name('cashier_v3_balance_fact')")
    && str_contains($assetRecords, "CASHIER_V3_BALANCE_FACT")
    && str_contains($assetRecords, "Db::name('store_debt')")
    && str_contains($assetRecords, "CURRENT_CARD_ENTITLEMENT")
    && str_contains($controller, 'public function assetRecords()')
    && str_contains($routes, 'customers/asset-records')
    && is_array($contract)
    && ($contract['endpoints']['queryCustomerAssetRecords']['authorization'] ?? '') === 'MERCHANT_SESSION_AND_SERVER_DATA_SCOPE'
    && ($contract['endpoints']['queryCustomerAssetRecords']['authorityByDetailType']['balance_changes'] ?? '') === 'CASHIER_V3_BALANCE_FACT'
);
$assert('MC-API-10',
    str_contains($memberProvider, "'u.add_time', 'u.level', 'u.birthday'")
    && str_contains($memberProvider, "'birthday' => \$this->dateValue")
    && str_contains($memberProvider, "'birthday' => (string)\$row['birthday']")
    && str_contains($memberRegistrar, "field('birthday', '生日', 'date'")
    && is_array($contract)
    && in_array('birthday', (array)($contract['customerFields'] ?? []), true)
);
$assert('MC-API-11',
    str_contains($recentSummary, "cashier_v3_entitlement_service_fact")
    && str_contains($recentSummary, "->where('service_status', 'completed')")
    && str_contains($recentSummary, "cashier_v3_sale_fact")
    && str_contains($recentSummary, "->where('fact_type', 'sale_completed')")
    && str_contains($recentSummary, "->where('fact_direction', 'forward')")
    && str_contains($recentSummary, "->where('status', 'effective')")
    && !str_contains($recentSummary, 'assertMemberVisible')
    && str_contains($controller, 'public function recentSummary()')
    && str_contains($routes, 'customers/recent-summary')
    && is_array($contract)
    && ($contract['endpoints']['queryCustomerRecentSummary']['authorization'] ?? '') === 'MERCHANT_SESSION_CURRENT_INSTANCE'
    && ($contract['endpoints']['queryCustomerRecentSummary']['storeScope'] ?? '') === 'NOT_APPLIED_BY_PRODUCT_DECISION'
);
$assert('MC-API-12',
    str_contains($orderRecords, "Db::name('store_order')->where('uid', \$memberId)")
    && !str_contains($orderRecords, 'assertMemberVisible')
    && str_contains($orderRecords, "'V3 recharge '")
    && str_contains($controller, 'public function orderRecords()')
    && str_contains($routes, 'customers/order-records')
    && is_array($contract)
    && ($contract['endpoints']['queryCustomerOrderRecords']['authorization'] ?? '') === 'MERCHANT_SESSION_CURRENT_INSTANCE'
    && ($contract['endpoints']['queryCustomerOrderRecords']['includesHistoricalStatuses'] ?? false) === true
);
$assert('MC-API-13',
    str_contains($orderRecords, 'public function detail(array $payload)')
    && str_contains($orderRecords, "->where('id', \$orderId)")
    && str_contains($orderRecords, "->where('uid', \$memberId)")
    && str_contains($orderRecords, 'private function detailItems')
    && !str_contains($orderRecords, 'assertMemberVisible')
    && str_contains($controller, 'public function orderRecordDetail()')
    && str_contains($routes, 'customers/order-record-detail')
    && is_array($contract)
    && ($contract['endpoints']['queryCustomerOrderRecordDetail']['authorization'] ?? '') === 'MERCHANT_SESSION_CURRENT_INSTANCE'
    && ($contract['endpoints']['queryCustomerOrderRecordDetail']['storeScope'] ?? '') === 'NOT_APPLIED_BY_PRODUCT_DECISION'
);
$assert('MC-API-14',
    str_contains($profiles, 'public function create(array $merchant, array $payload): array')
    && str_contains($profiles, 'public function update(array $merchant, int $memberId, array $payload): array')
    && str_contains($profiles, 'assertMemberVisible($merchant, $memberId)')
    && str_contains($profiles, 'replacePhoneIdentity')
    && str_contains($profiles, 'json_encode($extendInfo')
    && str_contains($profiles, "Db::transaction")
    && str_contains($profiles, "mobile_merchant_idempotency")
    && str_contains($profiles, "member_exclusive_service_change")
    && str_contains($profiles, "MobileCustomerAvatarUploadServices::COMMAND_CODE")
    && str_contains($avatarUploads, "public const COMMAND_CODE = 'UPLOAD_CUSTOMER_PROFILE_AVATAR'")
    && str_contains($avatarUploads, 'SystemAttachmentServices')
    && str_contains($controller, 'public function profileDraft()')
    && str_contains($controller, 'public function profileAvatarUpload()')
    && str_contains($controller, 'public function profileDetail(int $memberId)')
    && str_contains($controller, 'public function profileCreate()')
    && str_contains($controller, 'public function profileUpdate(int $memberId)')
    && str_contains($routes, "customers/profile-draft")
    && str_contains($routes, "customers/profile-avatar")
    && str_contains($routes, "customers/:memberId/profile")
    && is_array($contract)
    && ($contract['endpoints']['createCustomerProfile']['authorization'] ?? '') === 'MERCHANT_SESSION_AND_SERVER_DATA_SCOPE'
    && ($contract['endpoints']['updateCustomerProfile']['authorization'] ?? '') === 'MERCHANT_SESSION_AND_SERVER_DATA_SCOPE'
    && ($contract['endpoints']['customerProfileAvatarUpload']['requestBodyRequired'] ?? []) === ['idempotencyKey', 'file']
);
$assert('MC-API-15',
    !str_contains($orderRecords, "'orderNo' =>")
    && !str_contains($orderRecords, "'remark' =>")
);
$assert('MC-API-16',
    str_contains($query, 'normalizeMobileFilterPresets')
    && str_contains($query, "mobile_date_")
    && str_contains($query, "mobile_birthday_")
    && str_contains($query, "birthday_month_day")
    && str_contains($query, 'MemberUnifiedQueryProvider::BUSINESS_TIME_ZONE')
    && str_contains($memberProvider, "'birthday_month_day' => \$this->birthdayMonthDay")
    && str_contains($memberRegistrar, "field('birthday_month_day', '生日（月日）', 'text'")
);
$assert('MC-API-17',
    str_contains($audienceOverview, 'private function systemAudienceCount')
    && str_contains($audienceOverview, '$this->customers->querySystemAudienceForStoreIds(')
    && str_contains($audienceOverview, "['page' => 1, 'limit' => 1]")
    && str_contains($audienceOverview, "return (int)(\$page['total'] ?? 0);")
    && !str_contains($audienceOverview, 'return 222;')
    && !str_contains($audienceOverview, 'return 3;')
);
$assert('MC-API-18',
    str_contains($query, 'The top-left store context is the customer page')
    && str_contains($query, 'in_array($activeStoreId, $authorized, true)')
    && str_contains($audienceOverview, 'currentStoreScope($merchant')
    && str_contains($audienceOverview, 'private function hasNodeSelection')
    && str_contains($audienceOverview, 'if (!$this->hasNodeSelection($input))')
);

exit($failed === 0 ? 0 : 1);
