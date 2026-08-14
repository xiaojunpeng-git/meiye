import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import test from 'node:test'

import { mobileRoot } from './helpers.mjs'

const customerPage = path.join(
	mobileRoot,
	'src',
	'merchant',
	'pages',
	'customers',
	'index.uvue'
)

test('merchant customer page keeps the confirmed tabs and unified query fields', () => {
	const content = fs.readFileSync(customerPage, 'utf8')
	for (const label of ['专属客户', '我的客群', '客户列表']) {
		assert.equal(content.includes(`label: '${label}'`), true, label)
	}
	const fieldKeys = [
		'member_name', 'phone', 'member_no', 'birthday', 'member_status', 'member_level',
		'member_tag', 'store', 'exclusive_service_staff', 'account_balance',
		'active_card_count', 'remaining_project_times', 'remaining_project_amount',
		'debt_amount', 'total_consumption_amount', 'visit_count',
		'latest_purchase_date', 'last_service_staff', 'latest_visit_date', 'created_at'
	]
	for (const fieldKey of fieldKeys) {
		assert.equal(content.includes(`key: '${fieldKey}'`), true, fieldKey)
	}
	assert.equal(content.includes('fixture'), false)
	assert.equal(content.includes('mock'), false)
	assert.equal(content.includes('新建客户'), true)
	assert.equal(content.includes('createMobileCustomer'), true)
	assert.equal(content.includes('scroll-view class="customer-page__content" scroll-y'), true)
	assert.equal(content.includes('displayConfiguration'), true)
	assert.equal(content.includes('additionalCardFields(record)'), true)
	assert.equal(content.includes('openCustomerDetail(record)'), true)
	assert.equal(content.includes('查看详情'), false)
	assert.equal(content.includes('customerInitial(record)'), true)
	assert.equal(content.includes('customerMetrics(record)'), true)
	for (const label of ['总余额', '有效卡项', '剩余次数', '当前欠款']) assert.equal(content.includes(label), true, label)
	assert.equal(content.includes("return '¥' + wholeNumber(value)"), true)
	assert.equal(content.includes('visibleFieldKeys.value.indexOf(definition.key)'), true)
	assert.equal(content.includes('fieldAliases.value[key]'), true)
	assert.equal(content.includes('@tap="openAudience(audience)"'), true)
	assert.equal(content.includes('queryMobileCustomerAudienceMembers'), true)
	assert.equal(content.includes('queryMobileCustomerAudienceOverview'), true)
	assert.equal(content.includes('返回客群'), true)
	assert.equal(content.includes('groupEntryMessage'), true)
	assert.equal(content.includes("'customer-group-entry__command--disabled': creatingGroup"), true)
	assert.equal(content.includes("groupEntryMessage.value = String(result.message || '客群保存失败。')"), true)
	assert.equal(content.includes("loadMessage.value = String(result.message || '客群保存失败。')"), false)
	assert.equal(content.includes('正在创建...'), true)
	assert.equal(content.includes('列表字段'), true)
	assert.equal(content.includes("defaultVisibleFieldKeys = ['member_name', 'phone', 'birthday'"), true)
	assert.equal(content.includes("visibleFieldKeys.value.indexOf(definition.key) < 0"), true)
	assert.equal(content.includes("v-if=\"activeTab === 'list'\" class=\"customer-page__field-settings\""), true)
	assert.equal(content.includes('>数据设置</text>'), false)
	for (const marker of ['numberOperatorOptions', "value: 'between', label: '介于'", 'dateShortcutOptions', 'birthdayShortcutOptions', 'mobile_date_', 'mobile_birthday_']) {
		assert.equal(content.includes(marker), true, marker)
	}
	assert.equal(content.includes("value: 'inactive_days', label: '超过指定天数未到店'"), true)
	assert.equal(content.includes("value: 'inactive_purchase_days', label: '超过指定天数未购买'"), true)
	assert.equal(content.includes('isRollingDayShortcut(field.key)'), true)
	assert.equal(content.includes('输入未到店天数'), true)
	assert.equal(content.includes('输入未购买天数'), true)
	assert.equal(content.includes('customer-filter__operator-option--active'), true)
	assert.equal(content.includes('setFilterOperatorValue(field.key, option.value)'), true)
	assert.equal(content.includes('picker v-if="!isBirthdayField(field.key) && !isRollingDayShortcut(field.key)" class="customer-filter__operator"'), false)
	assert.equal(content.includes('mobileHttpResultRequiresMerchantLogin'), true)
	assert.equal(content.includes('openMerchantBootstrapForCustomers'), true)
	assert.equal(content.includes('clearMerchantRoot()'), true)
	assert.equal(content.includes('dateShortcutLabelsFor(field.key)'), true)
})

test('merchant workbench exposes the customer route', () => {
	const pages = JSON.parse(fs.readFileSync(path.join(mobileRoot, 'pages.json'), 'utf8'))
	const paths = pages.pages.map((page) => page.path)
	assert.equal(paths.includes('src/merchant/pages/customers/index'), true)

	const workbench = fs.readFileSync(
		path.join(mobileRoot, 'src', 'merchant', 'pages', 'workbench', 'index.uvue'),
		'utf8'
	)
	assert.equal(workbench.includes('url="/src/merchant/pages/customers/index"'), true)
})

test('customer data settings page uses only the controlled unified-query transport', () => {
	const pages = JSON.parse(fs.readFileSync(path.join(mobileRoot, 'pages.json'), 'utf8'))
	const paths = pages.pages.map((page) => page.path)
	const customer = fs.readFileSync(customerPage, 'utf8')
	const settings = fs.readFileSync(
		path.join(mobileRoot, 'src', 'merchant', 'pages', 'customer-settings', 'index.uvue'),
		'utf8'
	)
	assert.equal(paths.includes('src/merchant/pages/customer-settings/index'), true)
	assert.equal(paths.includes('src/merchant/pages/customer-detail/index'), true)
	assert.equal(customer.includes('url="/src/merchant/pages/customer-settings/index"'), true)
	assert.equal(customer.includes('列表字段'), true)
	assert.equal(settings.includes('queryMobileCustomerUnifiedQueryCapabilities'), true)
	assert.equal(settings.includes('dispatchMobileCustomerUnifiedQueryCommand'), true)
	assert.equal(settings.includes("action: 'save-unified-query-settings'"), true)
	assert.equal(settings.includes("action: 'save-unified-query-field-aliases'"), true)
	assert.equal(settings.includes('保存设置'), true)
	assert.equal(settings.includes("saveMessage.value = '列表字段已保存。'"), true)
	assert.equal(settings.includes('storeId'), false)
	assert.equal(settings.includes('organizationId'), false)
})

test('customer detail re-queries the selected member within the current merchant scope', () => {
	const detail = fs.readFileSync(
		path.join(mobileRoot, 'src', 'merchant', 'pages', 'customer-detail', 'index.uvue'),
		'utf8'
	)
	assert.equal(detail.includes('queryMobileCustomers(false'), true)
	assert.equal(detail.includes('当前数据权限范围内'), true)
	assert.equal(detail.includes('客户详情'), true)
	assert.equal(detail.includes("import { currentMerchantRoot, merchantRequestSession } from '../../../shared/platform/mobile-merchant-session.uts'"), true)
	assert.equal(detail.includes('openMerchantBootstrapForCustomerDetail'), true)
	assert.equal(detail.includes('if (merchantLoginIsRequired()) { openMerchantBootstrapForCustomerDetail(memberId.value, lookup.value); return }'), true)
	for (const label of ['近况', '资料', '客情', '服务', '记录']) {
		assert.equal(detail.includes(`label: '${label}'`), true, label)
	}
	assert.equal(detail.includes("label: '资产'"), false)
	assert.equal(detail.includes('accountBalance'), true)
	assert.equal(detail.includes('activeCardCount'), true)
	assert.equal(detail.includes('remainingProjectTimes'), true)
	assert.equal(detail.includes('debtAmount'), true)
	assert.equal(detail.includes('openCustomerCare'), true)
	assert.equal(detail.includes('openMerchantCustomerCare'), true)
	assert.equal(detail.includes('queryMobileCustomerCare'), true)
	assert.equal(detail.includes('未完成的跟进任务'), true)
	assert.equal(detail.includes("statusGroup: 'open'"), true)
	assert.equal(detail.includes('openMerchantCustomerCare(memberId.value)'), true)
	assert.equal(detail.includes('callMobilePhone'), true)
	assert.equal(detail.includes('充值、赠送、改价或结账入口'), false)
	assert.equal(detail.includes('queryMobileCustomerServiceRecords'), true)
	assert.equal(detail.includes('queryMobileCustomerRecentSummary'), true)
	assert.equal(detail.includes('queryMobileCustomerOrderRecords'), true)
	assert.equal(detail.includes('openMerchantCustomerOrderDetail'), true)
	assert.equal(detail.includes("return '¥' + wholeMoney(purchase.amount)"), true)
	assert.equal(detail.includes("orderNo + ' · ' + amount"), false)
	assert.equal(detail.includes('全部订单记录'), true)
	assert.equal(detail.includes("label: '最近服务'"), true)
	assert.equal(detail.includes("label: '最近购买'"), true)
	assert.equal(detail.includes('queryMobileCustomerAssetRecords'), true)
	assert.equal(detail.includes('openProfileEditor'), true)
	assert.equal(detail.includes("openMerchantCustomerProfile('edit'"), true)
	assert.equal(detail.includes('record.orderNo'), false)
	assert.equal(detail.includes('record.remark'), false)
	for (const label of ['权益明细', '余额异动', '欠款明细']) assert.equal(detail.includes(label), true, label)
	assert.equal(detail.includes("key: 'total_balance', label: '总余额'"), true)
	assert.equal(detail.includes("composition: '余额 ¥'"), true)
	assert.equal(detail.includes("' · 次卡 ¥'"), true)
	assert.equal(detail.includes('wholeMoney(record.balanceAfter)'), true)
	assert.equal(detail.includes('wholeMoney(record.remainingAmount)'), true)
	for (const removedCopy of ['最近动态', '仅显示当前账号可查看的信息', '客情提醒', '资产与权益', '资产概览', '明细查询']) {
		assert.equal(detail.includes(removedCopy), false, removedCopy)
	}
	assert.equal(detail.includes('余额异动仅显示可审计的 V3 记录'), true)
	assert.equal(detail.includes('CASHIER_V3_COMPLETED_SERVICE_FACT'), false)
})

test('customer profile uses the complete server-defined profile schema for both create and edit', () => {
	const profile = fs.readFileSync(path.join(mobileRoot, 'src', 'merchant', 'pages', 'customer-profile', 'index.uvue'), 'utf8')
	const customers = fs.readFileSync(path.join(mobileRoot, 'src', 'merchant', 'pages', 'customers', 'index.uvue'), 'utf8')
	assert.equal(profile.includes('queryMobileCustomerProfileDraft'), true)
	assert.equal(profile.includes('queryMobileCustomerProfile'), true)
	assert.equal(profile.includes('createMobileCustomerProfile'), true)
	assert.equal(profile.includes('updateMobileCustomerProfile'), true)
	assert.equal(profile.includes('customFields'), true)
	assert.equal(profile.includes('profileVersion'), true)
	assert.equal(profile.includes('idempotencyKey'), true)
	assert.equal(profile.includes('current login'), false)
	assert.equal(customers.includes("openMerchantCustomerProfile('create')"), true)
	assert.equal(customers.includes('customerEntryVisible'), false)
})

test('merchant login has a fixed customer-detail return route without accepting arbitrary navigation', () => {
	const bootstrap = fs.readFileSync(
		path.join(mobileRoot, 'src', 'app', 'pages', 'bootstrap', 'index.uvue'),
		'utf8'
	)
	const navigation = fs.readFileSync(
		path.join(mobileRoot, 'src', 'shared', 'platform', 'mobile-navigation.uts'),
		'utf8'
	)
	assert.equal(navigation.includes('function openMerchantBootstrapForCustomerDetail(memberId : string, lookup : string)'), true)
	assert.equal(navigation.includes('function openMerchantBootstrapForCustomers()'), true)
	assert.equal(navigation.includes('returnTarget=customers'), true)
	assert.equal(navigation.includes('returnTarget=customer-detail'), true)
	assert.equal(navigation.includes('encodeURIComponent(memberId)'), true)
	assert.equal(navigation.includes('function openMerchantCustomerDetailAfterBootstrap(memberId : string, lookup : string)'), true)
	assert.equal(bootstrap.includes("target == 'customer-detail' && memberId.length > 0 && lookup.length > 0"), true)
	assert.equal(bootstrap.includes('openMerchantCustomerDetailAfterBootstrap(returnMemberId.value, returnLookup.value)'), true)
	assert.equal(bootstrap.includes("target == 'customers'"), true)
	assert.equal(bootstrap.includes('openMerchantCustomers()'), true)
	assert.equal(bootstrap.includes('options.url'), false)
})

test('merchant workbench keeps the confirmed goal and reservation entry boundaries', () => {
	const workbench = fs.readFileSync(
		path.join(mobileRoot, 'src', 'merchant', 'pages', 'workbench', 'index.uvue'),
		'utf8'
	)
	const reservations = fs.readFileSync(
		path.join(mobileRoot, 'src', 'merchant', 'pages', 'reservations', 'index.uvue'),
		'utf8'
	)
	const pages = JSON.parse(fs.readFileSync(path.join(mobileRoot, 'pages.json'), 'utf8'))
	const paths = pages.pages.map((page) => page.path)

	assert.equal(workbench.includes('url="/src/merchant/pages/reservations/index"'), true)
	assert.equal(workbench.includes('url="/src/merchant/pages/goals/index"'), true)
	assert.equal(paths.includes('src/merchant/pages/goals/index'), true)
	assert.equal(paths.includes('src/merchant/pages/reservations/index'), true)
	assert.equal(reservations.includes('cashier_v3_reservation'), true)
	assert.equal(reservations.includes('queryMobileReservations'), true)
	assert.equal(reservations.includes('createMobileReservation'), true)
	assert.equal(reservations.includes('start-reservation-service'), false)
	assert.equal(reservations.includes('finish-service-completion'), false)
	assert.equal(reservations.includes('prepare-reservation-checkout'), false)
	assert.equal(reservations.includes('cancel-reservation'), false)
})

test('each merchant primary page keeps the confirmed five-way navigation', () => {
	const primaryPages = ['customers', 'warehouse', 'workbench', 'goals', 'profile']
	const labels = ['数仓', '客户', '工作台', '目标', '我的']
	for (const page of primaryPages) {
		const content = fs.readFileSync(
			path.join(mobileRoot, 'src', 'merchant', 'pages', page, 'index.uvue'),
			'utf8'
		)
		for (const label of labels) assert.equal(content.includes(`>${label}</text>`), true, `${page}:${label}`)
	}
})

test('mobile customer contract keeps customer scope server enforced', () => {
	const contract = JSON.parse(fs.readFileSync(
		path.join(mobileRoot, 'src', 'shared', 'contracts', 'mobile-customer-v1.contract.json'),
		'utf8'
	))
	assert.equal(contract.contractVersion, 'mobile-customer-v1')
	assert.equal(contract.requestMetadataProfile, 'MERCHANT_SESSION')
	assert.equal(contract.dataAuthority.memberQueryPageCode, 'member_list')
	assert.equal(contract.dataAuthority.dataScope, 'SERVER_ENFORCED_INTERSECTION')
	assert.deepEqual(contract.tabs, ['EXCLUSIVE_CUSTOMERS', 'MY_AUDIENCES', 'CUSTOMER_LIST'])
	assert.equal(contract.customerFields.length, 20)
	assert.equal(contract.customerFields.includes('birthday'), true)
	assert.equal(contract.audience.membership, 'DYNAMIC_QUERY')
	assert.equal(contract.audience.membershipMustNotBePersisted, true)
	assert.equal(contract.audience.currentDataScopeAppliedOnEveryQuery, true)
	assert.equal(contract.audience.relativeDateRulesReevaluatedOnEveryQuery, true)
	assert.deepEqual(contract.audience.relativeDateDaysRange, {minimum: 1, maximum: 3650, integerOnly: true})
	assert.deepEqual(contract.dataAuthority.unifiedQueryCapabilities, ['QUERY_SETTINGS', 'CUSTOM_FIELDS', 'FIELD_ALIASES', 'EXPORT'])
	assert.equal(contract.dataAuthority.capabilitiesMustNotDependOnFineGrainedRole, true)
	assert.equal(contract.endpoints.queryCustomers.authorization, 'MERCHANT_SESSION_AND_SERVER_DATA_SCOPE')
	assert.equal(contract.endpoints.queryCustomerServiceRecords.authorization, 'MERCHANT_SESSION_AND_SERVER_DATA_SCOPE')
	assert.equal(contract.endpoints.queryCustomerServiceRecords.authority, 'CASHIER_V3_COMPLETED_SERVICE_FACT')
	assert.equal(contract.endpoints.queryCustomerRecentSummary.authorization, 'MERCHANT_SESSION_CURRENT_INSTANCE')
	assert.deepEqual(contract.endpoints.queryCustomerRecentSummary.authority, ['CASHIER_V3_COMPLETED_SERVICE_FACT', 'CASHIER_V3_SALE_FACT'])
	assert.equal(contract.endpoints.queryCustomerOrderRecords.authorization, 'MERCHANT_SESSION_CURRENT_INSTANCE')
	assert.equal(contract.endpoints.queryCustomerOrderRecords.authority, 'STORE_ORDER_MEMBER_HISTORY')
	assert.equal(contract.endpoints.queryCustomerOrderRecordDetail.authorization, 'MERCHANT_SESSION_CURRENT_INSTANCE')
	assert.deepEqual(contract.endpoints.queryCustomerOrderRecordDetail.requestBodyRequired, ['memberId', 'orderId'])
	assert.equal(contract.endpoints.queryCustomerOrderRecordDetail.includesHistoricalStatuses, true)
	assert.equal(contract.endpoints.queryCustomerAssetRecords.authorization, 'MERCHANT_SESSION_AND_SERVER_DATA_SCOPE')
	assert.deepEqual(contract.endpoints.queryCustomerAssetRecords.requestBodyRequired, ['memberId', 'detailType'])
	assert.deepEqual(contract.endpoints.queryCustomerAssetRecords.authorityByDetailType, {
		entitlements: 'CURRENT_CARD_ENTITLEMENT', balance_changes: 'CASHIER_V3_BALANCE_FACT', debts: 'STORE_DEBT'
	})
	assert.equal(contract.endpoints.customerProfileDraft.authorization, 'MERCHANT_SESSION_AND_SERVER_DATA_SCOPE')
	assert.equal(contract.endpoints.customerProfileDetail.authorization, 'MERCHANT_SESSION_AND_SERVER_DATA_SCOPE')
	assert.deepEqual(contract.endpoints.createCustomerProfile.requestBodyRequired, ['name', 'phone', 'idempotencyKey'])
	assert.deepEqual(contract.endpoints.updateCustomerProfile.requestBodyRequired, ['expectedVersion', 'idempotencyKey'])
	for (const forbiddenField of ['employeeId', 'accountId', 'storeScope', 'organizationScope', 'permissionScope']) {
		assert.equal(contract.dataAuthority.clientMustNotProvide.includes(forbiddenField), true, forbiddenField)
	}
})

test('manager customer queries use the canonical employee account instead of staff id zero', () => {
	const resolver = fs.readFileSync(path.join(
		mobileRoot, '..', '..', '后端代码', 'app', 'services', 'mobile', 'merchant',
		'MobileMerchantRequestContextResolver.php'
	), 'utf8')
	const queryService = fs.readFileSync(path.join(
		mobileRoot, '..', '..', '后端代码', 'app', 'services', 'mobile', 'customer',
		'MobileCustomerQueryServices.php'
	), 'utf8')
	assert.equal(resolver.includes("Db::name('employee_internal_account')"), true)
	assert.equal(resolver.includes("'accountId' => $accountId"), true)
	assert.equal(resolver.includes("'operatorId' => $employeeId"), true)
	assert.equal(resolver.includes("'accountId' => $staffId"), false)
	assert.equal(queryService.includes("'operator_id' => (int)$merchant['operatorId']"), true)
})
