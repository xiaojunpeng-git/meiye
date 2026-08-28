<?php

namespace app\services\mobile\customer;

use app\services\query\UnifiedQueryException;
use app\services\query\UnifiedQueryRuntime;
use app\services\query\provider\MemberUnifiedQueryProvider;
use think\exception\ValidateException;
use think\facade\Db;
use DateTimeImmutable;
use DateTimeZone;

/** Mobile facade over the one member-list provider; it never owns member SQL. */
final class MobileCustomerQueryServices
{
    public function query(array $merchantContext, array $payload, bool $exclusiveOnly = false): array
    {
        $context = $this->unifiedContextForMerchant($merchantContext, $this->customerStoreIds($merchantContext));
        $runtime = UnifiedQueryRuntime::runtime();
        $saved = $runtime['preferences']->load($context, MemberUnifiedQueryProvider::PAGE_CODE);
        $payload = $this->queryPayload($payload, (array)($saved['settings'] ?? []));
        $provider = $runtime['providers']->resolve(MemberUnifiedQueryProvider::PAGE_CODE);
        $page = $provider->query($context, $payload);
        $page['displayConfiguration'] = [
            'visibleFields' => array_values((array)($saved['settings']['visibleFields'] ?? [])),
            'fieldAliases' => $runtime['aliases']->aliases($context, MemberUnifiedQueryProvider::PAGE_CODE),
        ];
        if ($exclusiveOnly) {
            $page['records'] = array_values(array_filter($page['records'], function (array $record) use ($merchantContext): bool {
                return $this->isExclusiveToStaff((int)$record['memberId'], $merchantContext);
            }));
            $page['total'] = count($page['records']);
        }
        return $page;
    }

    public function queryAudience(array $merchantContext, array $validatedRule, array $payload): array
    {
        return $this->queryAudienceForStoreIds(
            $merchantContext,
            $validatedRule,
            $payload,
            $this->customerStoreIds($merchantContext)
        );
    }

    /**
     * Resolves one of the five built-in audiences without persisting a row.
     * The resulting filters still flow through the same member-list provider,
     * permission injection and projection as custom audiences.
     *
     * @param int[] $storeIds
     */
    public function querySystemAudienceForStoreIds(array $merchantContext, string $systemKey, array $payload, array $storeIds): array
    {
        if (!MobileCustomerAudienceServices::isSystemKey($systemKey)) {
            throw new ValidateException('系统客群标识无效。');
        }
        $today = new DateTimeImmutable('today', new DateTimeZone('Asia/Shanghai'));
        $filters = [];
        if ($systemKey === MobileCustomerAudienceServices::SYSTEM_BIRTHDAY_THIS_MONTH) {
            $filters[] = ['field_key' => 'birthday_month_day', 'operator' => 'starts_with', 'value' => $today->format('m-')];
        } elseif ($systemKey === MobileCustomerAudienceServices::SYSTEM_SLEEPING_90D) {
            // A missing latest date is not a sleeping customer: the platform
            // definition requires at least one historical valid service. The
            // lower bound excludes the empty projection value without using
            // an invalid empty date literal.
            $filters[] = ['field_key' => 'latest_visit_date', 'operator' => 'between', 'value' => ['1900-01-01', $today->modify('-90 days')->format('Y-m-d')]];
        } elseif ($systemKey === MobileCustomerAudienceServices::SYSTEM_FOLLOWUP_7D) {
            // Follow-up is driven by either a recent valid purchase or a
            // completed service fact; the query engine applies this OR.
            $filters[] = ['field_key' => 'latest_purchase_date', 'operator' => 'greater_or_equal', 'value' => $today->modify('-6 days')->format('Y-m-d')];
            $filters[] = ['field_key' => 'latest_visit_date', 'operator' => 'greater_or_equal', 'value' => $today->modify('-6 days')->format('Y-m-d')];
            $filterRelation = 'any';
        } elseif ($systemKey === MobileCustomerAudienceServices::SYSTEM_INVITE_30D) {
            $filters[] = ['field_key' => 'latest_visit_date', 'operator' => 'between', 'value' => [$today->modify('-30 days')->format('Y-m-d'), $today->modify('-8 days')->format('Y-m-d')]];
        } elseif ($systemKey === MobileCustomerAudienceServices::SYSTEM_BENEFIT_LOW_BALANCE) {
            $filters[] = ['field_key' => 'remaining_project_amount', 'operator' => 'greater_than', 'value' => '0'];
            $filters[] = ['field_key' => 'remaining_project_amount', 'operator' => 'less_than', 'value' => '500'];
        }
        $rule = [
            'page_code' => MemberUnifiedQueryProvider::PAGE_CODE,
            'permission_must_be_injected_before_calculation' => true,
            'filters' => $filters,
            'filter_relation' => $filterRelation ?? 'all',
            'top_filters' => [], 'keyword_filters' => [], 'sorts' => [['field_key' => 'member_id', 'direction' => 'asc']],
            'domain_scope' => ['data_scope' => 'normal'],
        ];
        return $this->queryAudienceForStoreIds($merchantContext, $rule, $payload, $storeIds);
    }

    /**
     * The caller supplies only a server-projected subset from its current
     * hierarchy node. This method intentionally remains the only path that
     * turns a dynamic audience rule into current members.
     *
     * @param int[] $storeIds
     */
    public function queryAudienceForStoreIds(array $merchantContext, array $validatedRule, array $payload, array $storeIds): array
    {
        $context = $this->unifiedContextForMerchant($merchantContext, $storeIds);
        if (($validatedRule['page_code'] ?? '') !== MemberUnifiedQueryProvider::PAGE_CODE
            || empty($validatedRule['permission_must_be_injected_before_calculation'])) {
            throw new ValidateException('客群筛选规则无效，请重新创建客群。');
        }
        foreach (['employeeId', 'accountId', 'storeScope', 'organizationScope', 'permissionScope'] as $blocked) {
            if (array_key_exists($blocked, $payload)) {
                throw new ValidateException('客户端不得提交数据权限字段。');
            }
        }
        $runtime = UnifiedQueryRuntime::runtime();
        $saved = $runtime['preferences']->load($context, MemberUnifiedQueryProvider::PAGE_CODE);
		$ruleForToday = $validatedRule;
		if (isset($validatedRule['mobile_source_filters']) && is_array($validatedRule['mobile_source_filters'])) {
			$ruleForToday = $this->queryPayload([
				'filters' => $validatedRule['mobile_source_filters'],
				'filterRelation' => (string)($validatedRule['mobile_source_filter_relation'] ?? 'all'),
				'sorts' => (array)($validatedRule['sorts'] ?? []),
			]);
		}
        $query = [
            'pageCode' => MemberUnifiedQueryProvider::PAGE_CODE,
            'page' => max(1, (int)($payload['page'] ?? 1)),
            'limit' => min(100, max(1, (int)($payload['limit'] ?? 30))),
            'keyword' => trim((string)($payload['keyword'] ?? '')),
			'filters' => (array)($ruleForToday['filters'] ?? []),
			'topFilterConditions' => (array)($ruleForToday['topFilterConditions'] ?? ($validatedRule['top_filters'] ?? [])),
			'keywordFilters' => (array)($ruleForToday['keywordFilters'] ?? ($validatedRule['keyword_filters'] ?? [])),
			'filterRelation' => (string)($ruleForToday['filterRelation'] ?? ($validatedRule['filter_relation'] ?? 'all')),
			'sorts' => (array)($ruleForToday['sorts'] ?? ($validatedRule['sorts'] ?? [['field_key' => 'member_id', 'direction' => 'asc']])),
            'groupBy' => [],
            'summaries' => [],
            'visibleFields' => [],
            'dataScope' => 'normal',
            'businessStatus' => '',
            'quickFilters' => [],
        ];
        $provider = $runtime['providers']->resolve(MemberUnifiedQueryProvider::PAGE_CODE);
        $page = $provider->query($context, $query);
        $page['displayConfiguration'] = [
            'visibleFields' => array_values((array)($saved['settings']['visibleFields'] ?? [])),
            'fieldAliases' => $runtime['aliases']->aliases($context, MemberUnifiedQueryProvider::PAGE_CODE),
        ];
        return $page;
    }

    /** @param int[] $storeIds */
    public function audienceMemberCount(array $merchantContext, array $validatedRule, array $storeIds): int
    {
        $page = $this->queryAudienceForStoreIds($merchantContext, $validatedRule, ['page' => 1, 'limit' => 1], $storeIds);
        return max(0, (int)($page['total'] ?? 0));
    }

    /**
     * Revalidates a target member independently of a page query. Detail tabs
     * must not trust a member id from the mobile route or a previously loaded
     * list response.
     */
    public function assertMemberVisible(array $merchantContext, int $memberId): void
    {
        if ($memberId <= 0) {
            throw new ValidateException('客户参数无效。');
        }
        $stores = $this->customerStoreIds($merchantContext);
        $visible = $stores !== [] && Db::name('store_user')
            ->where('uid', $memberId)
            ->where('status', 1)
            ->whereIn('store_id', $stores)
            ->count() > 0;
        if (!$visible || !Db::name('user')->where('uid', $memberId)->where('is_del', 0)->count()) {
            throw new ValidateException('该客户不在当前数据权限范围内，或数据已发生变化。');
        }
    }

    public function validatedAudienceRule(array $merchantContext, array $payload): array
    {
        $context = $this->unifiedContextForMerchant($merchantContext, $this->customerStoreIds($merchantContext));
		$sourceFilters = array_values((array)($payload['filters'] ?? []));
		$sourceFilterRelation = (string)($payload['filterRelation'] ?? 'all');
        $payload = $this->queryPayload($payload);
        $runtime = UnifiedQueryRuntime::runtime();
        $definitions = [];
        $plan = $runtime['execution']->validatedPlan(
            MemberUnifiedQueryProvider::PAGE_CODE,
            $definitions,
            $payload,
            $context
        );
        $plan['page_code'] = MemberUnifiedQueryProvider::PAGE_CODE;
        $plan['permission_must_be_injected_before_calculation'] = true;
		$plan['mobile_source_filters'] = $sourceFilters;
		$plan['mobile_source_filter_relation'] = $sourceFilterRelation;
        return $plan;
    }

    /**
     * Trusted unified-query context for the mobile merchant customer page.
     *
     * This deliberately remains unavailable to PERSONAL_SELF: granting query
     * metadata management must never turn a personal customer scope into a
     * store-wide member query scope.
     */
    public function unifiedContextForMerchant(array $merchant, array $storeIds = []): array
    {
        $stores = $storeIds !== [] ? $storeIds : $this->customerStoreIds($merchant);
        if (!$stores) {
            throw new ValidateException('当前数据权限范围内没有可查看的客户门店。');
        }
        $runtime = UnifiedQueryRuntime::runtime();
        return $runtime['contextFactory']->make([
            'tenant_id' => '0',
            'account_id' => (int)$merchant['accountId'],
            'operator_id' => (int)$merchant['operatorId'],
            'store_id' => (int)$merchant['storeId'],
            'organization_id' => (string)$merchant['organizationId'],
            'visible_store_ids' => $stores,
            'ancestor_organization_ids' => [(string)$merchant['organizationId']],
            'shareable_store_ids' => $stores,
            'shareable_organization_ids' => [(string)$merchant['organizationId']],
            'permission_version' => (string)$merchant['permissionVersion'],
            // 已接入统一查询的手机页面开放查询设置、字段别名、自定义字段
            // 与导出；数据范围仍只由服务端上下文注入，客户端不能扩大。
            // member_list 的导出能力由该页面的 cashier.v3.member 功能码
            // 推导；unified_query.export 是框架内部保留权限，不能由宿主注入。
            'granted_features' => ['cashier.v3.member'],
            'manage_shared_fields' => true,
            'share_tenant_fields' => false,
            'scope_dimensions' => [],
        ], ['pageCode' => MemberUnifiedQueryProvider::PAGE_CODE]);
    }

    /** Read-only capability projection; its scope is always server injected. */
    public function unifiedCapabilities(array $merchant): array
    {
        $context = $this->unifiedContextForMerchant($merchant, $this->customerStoreIds($merchant));
        $runtime = UnifiedQueryRuntime::runtime();
        $settings = $runtime['preferences']->load($context, MemberUnifiedQueryProvider::PAGE_CODE);
        $capability = $runtime['capabilities']->build(
            $context,
            MemberUnifiedQueryProvider::PAGE_CODE,
            (int)$settings['settingsVersion'],
            time()
        );
        $capability['commandContext'] = [
            'kind' => 'query_preference',
            'id' => MemberUnifiedQueryProvider::PAGE_CODE,
        ];
        return $capability;
    }

    private function queryPayload(array $payload, array $savedSettings = []): array
    {
        foreach (['employeeId', 'accountId', 'storeScope', 'organizationScope', 'permissionScope'] as $blocked) {
            if (array_key_exists($blocked, $payload)) {
                throw new ValidateException('客户端不得提交数据权限字段。');
            }
        }
        $filters = $this->normalizeMobileFilterPresets((array)($payload['filters'] ?? []));
        return [
            'pageCode' => MemberUnifiedQueryProvider::PAGE_CODE,
            'page' => max(1, (int)($payload['page'] ?? 1)),
            'limit' => min(100, max(1, (int)($payload['limit'] ?? 20))),
            'keyword' => trim((string)($payload['keyword'] ?? '')),
            // Saved settings are server-side account preferences.  A mobile
            // request may only append a transient filter, never replace a
            // stored filter or widen the data scope.
            'filters' => array_merge(
                (array)($savedSettings['filters'] ?? []),
                $filters
            ),
            'topFilterConditions' => (array)($payload['topFilterConditions'] ?? []),
            'keywordFilters' => (array)($payload['keywordFilters'] ?? []),
            'filterRelation' => (string)($savedSettings['filterRelation'] ?? ($payload['filterRelation'] ?? 'all')),
            'sorts' => !empty($savedSettings['sorts'])
                ? (array)$savedSettings['sorts']
                : (array)($payload['sorts'] ?? [['field_key' => 'member_id', 'direction' => 'asc']]),
            'groupBy' => (array)($savedSettings['groupBy'] ?? []),
            'summaries' => (array)($savedSettings['summaries'] ?? []),
            'visibleFields' => (array)($savedSettings['visibleFields'] ?? []),
            'dataScope' => 'normal',
            'businessStatus' => '',
            'quickFilters' => [],
        ];
    }

    /**
     * Customer permissions are deliberately store-based: a personal mobile
     * appointment may open its current employment store, but never a client
     * supplied store. "PERSONAL_SELF" therefore must not be interpreted as
     * an exclusive-service-member list in the customer module.
     *
     * @return int[]
     */
    private function customerStoreIds(array $merchant): array
    {
        $stores = array_values(array_filter(array_map('intval', (array)($merchant['visibleStoreIds'] ?? []))));
        if ($stores === []) {
            $activeStoreId = (int)($merchant['storeId'] ?? 0);
            if ($activeStoreId > 0) {
                $stores[] = $activeStoreId;
            }
        }
        $stores = array_values(array_unique(array_filter($stores, static function (int $storeId): bool {
            return $storeId > 0;
        })));
        sort($stores, SORT_NUMERIC);
        return $stores;
    }

    /**
     * The mobile page may express date shortcuts, but never their SQL or an
     * arbitrary server-side range. Convert each whitelisted shortcut into the
     * same standard filters used by the unified query executor.
     */
    private function normalizeMobileFilterPresets(array $filters): array
    {
        $result = [];
        foreach ($filters as $filter) {
            if (!is_array($filter)) {
                throw new ValidateException('筛选条件格式无效。');
            }
            $field = (string)($filter['field_key'] ?? ($filter['fieldKey'] ?? ''));
            $operator = strtolower(trim((string)($filter['operator'] ?? '')));
            if (strpos($operator, 'mobile_birthday_') === 0) {
                $result = array_merge($result, $this->birthdayShortcutFilters($field, substr($operator, strlen('mobile_birthday_'))));
                continue;
            }
            if (strpos($operator, 'mobile_date_') === 0) {
                $result = array_merge($result, $this->datePresetFilters($field, substr($operator, strlen('mobile_date_')), $filter));
                continue;
            }
            $result[] = $filter;
        }
        return $result;
    }

    private function birthdayShortcutFilters(string $field, string $shortcut): array
    {
        if ($field !== 'birthday') {
            throw new ValidateException('生日快捷筛选字段无效。');
        }
        $today = $this->businessToday();
        if ($shortcut === 'today') {
            return [['field_key' => 'birthday_month_day', 'operator' => 'equal', 'value' => $today->format('m-d')]];
        }
        if ($shortcut === 'this_month') {
            return [['field_key' => 'birthday_month_day', 'operator' => 'starts_with', 'value' => $today->format('m') . '-']];
        }
        if ($shortcut === 'this_week') {
            $monday = $today->modify('monday this week');
            $values = [];
            for ($day = 0; $day < 7; $day++) {
                $values[] = $monday->modify('+' . $day . ' days')->format('m-d');
            }
            return [['field_key' => 'birthday_month_day', 'operator' => 'in', 'value' => array_values(array_unique($values))]];
        }
        throw new ValidateException('生日快捷筛选类型无效。');
    }

    private function datePresetFilters(string $field, string $preset, array $filter): array
    {
        $types = ['birthday' => 'date', 'latest_purchase_date' => 'date', 'latest_visit_date' => 'date', 'created_at' => 'datetime'];
        if (!isset($types[$field])) {
            throw new ValidateException('日期筛选字段无效。');
        }
        $type = $types[$field];
		if (in_array($preset, ['inactive_days', 'inactive_purchase_days', 'inactive_30_days', 'inactive_purchase_30_days'], true)) {
			$purchaseRule = in_array($preset, ['inactive_purchase_days', 'inactive_purchase_30_days'], true);
			$expectedField = $purchaseRule ? 'latest_purchase_date' : 'latest_visit_date';
			if ($field !== $expectedField) {
				throw new ValidateException($purchaseRule ? '未购买天数仅适用于最近购买日期。' : '未到店天数仅适用于最近到店日期。');
			}
			$legacyRule = in_array($preset, ['inactive_30_days', 'inactive_purchase_30_days'], true);
			$days = $legacyRule ? 30 : $this->inactiveDaysInput($filter['value'] ?? '');
			$cutoff = $this->businessToday()->modify('-' . $days . ' days')->format('Y-m-d');
			return [['field_key' => $field, 'operator' => 'less_or_equal', 'value' => $cutoff]];
		}
        if (in_array($preset, ['today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month', 'this_year'], true)) {
            if ($field === 'birthday') {
                throw new ValidateException('生日请使用今天、本周、本月或指定生日。');
            }
            [$from, $to] = $this->shortcutRange($preset);
            return [$this->dateRangeFilter($field, $type, $from, $to)];
        }
        $value = $this->dateInput($filter['value'] ?? '');
        if ($preset === 'between') {
            $valueTo = $this->dateInput($filter['valueTo'] ?? '');
            if ($value > $valueTo) {
                throw new ValidateException('结束日期不能早于开始日期。');
            }
            return [$this->dateRangeFilter($field, $type, $value, $valueTo)];
        }
        if (!in_array($preset, ['equal', 'greater_than', 'greater_or_equal', 'less_than', 'less_or_equal'], true)) {
            throw new ValidateException('日期筛选方式无效。');
        }
        if ($type === 'date') {
            return [['field_key' => $field, 'operator' => $preset, 'value' => $value]];
        }
        if ($preset === 'equal') {
            return [$this->dateRangeFilter($field, $type, $value, $value)];
        }
        $bound = $preset === 'greater_than' || $preset === 'less_or_equal'
            ? $value . ' 23:59:59'
            : $value . ' 00:00:00';
        return [['field_key' => $field, 'operator' => $preset, 'value' => $bound]];
    }

    private function dateRangeFilter(string $field, string $type, string $from, string $to): array
    {
        if ($type === 'datetime') {
            $from .= ' 00:00:00';
            $to .= ' 23:59:59';
        }
        return ['field_key' => $field, 'operator' => 'between', 'value' => [$from, $to]];
    }

    private function businessToday(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone(MemberUnifiedQueryProvider::BUSINESS_TIME_ZONE));
    }

	private function inactiveDaysInput($value): int
	{
		$value = trim((string)$value);
		if (!preg_match('/^[1-9][0-9]{0,3}$/', $value)) {
			throw new ValidateException('未到店或未购买天数必须填写1至3650的整数。');
		}
		$days = (int)$value;
		if ($days > 3650) {
			throw new ValidateException('未到店或未购买天数必须填写1至3650的整数。');
		}
		return $days;
	}

    private function shortcutRange(string $shortcut): array
    {
        $today = $this->businessToday();
        if ($shortcut === 'today') return [$today->format('Y-m-d'), $today->format('Y-m-d')];
        if ($shortcut === 'yesterday') { $day = $today->modify('-1 day'); return [$day->format('Y-m-d'), $day->format('Y-m-d')]; }
        if ($shortcut === 'this_week') { $from = $today->modify('monday this week'); return [$from->format('Y-m-d'), $from->modify('+6 days')->format('Y-m-d')]; }
        if ($shortcut === 'last_week') { $from = $today->modify('monday last week'); return [$from->format('Y-m-d'), $from->modify('+6 days')->format('Y-m-d')]; }
        if ($shortcut === 'this_month') { $from = $today->modify('first day of this month'); return [$from->format('Y-m-d'), $from->modify('last day of this month')->format('Y-m-d')]; }
        if ($shortcut === 'last_month') { $from = $today->modify('first day of last month'); return [$from->format('Y-m-d'), $from->modify('last day of last month')->format('Y-m-d')]; }
        if ($shortcut === 'this_year') { return [$today->format('Y') . '-01-01', $today->format('Y') . '-12-31']; }
        throw new ValidateException('日期快捷筛选类型无效。');
    }

    private function dateInput($value): string
    {
        $value = trim((string)$value);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone(MemberUnifiedQueryProvider::BUSINESS_TIME_ZONE));
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d') !== $value) {
            throw new ValidateException('日期必须使用 YYYY-MM-DD。');
        }
        return $value;
    }

    private function isExclusiveToStaff(int $memberId, array $merchant): bool
    {
        return $memberId > 0 && (int)Db::name('member_exclusive_service')
            ->where('member_id', $memberId)->where('staff_id', (int)$merchant['staffId'])
            ->where('store_id', (int)$merchant['storeId'])->where('status', 1)->count() > 0;
    }
}
