<?php

namespace app\services\query\provider;

use app\services\query\UnifiedQueryCustomFieldKeyCollector;
use app\services\query\UnifiedQueryCustomFieldServices;
use app\services\query\UnifiedQueryException;
use app\services\query\UnifiedQueryExecutionServices;
use app\services\query\UnifiedQueryPreferenceServices;
use app\services\query\UnifiedQueryProvider;
use app\services\system\SystemRoleServices;
use think\facade\Db;

/**
 * Current-store staff read model. The store boundary comes only from trusted
 * context and is applied before unified-query filtering or calculations.
 */
class StaffUnifiedQueryProvider implements UnifiedQueryProvider
{
    public const PAGE_CODE = 'staff_list';

    /** @var UnifiedQueryExecutionServices */
    protected $execution;

    /** @var UnifiedQueryCustomFieldServices */
    protected $customFields;

    /** @var UnifiedQueryPreferenceServices */
    protected $preferences;

    public function __construct(
        UnifiedQueryExecutionServices $execution,
        UnifiedQueryCustomFieldServices $customFields,
        UnifiedQueryPreferenceServices $preferences
    ) {
        $this->execution = $execution;
        $this->customFields = $customFields;
        $this->preferences = $preferences;
    }

    public function pageCode(): string
    {
        return self::PAGE_CODE;
    }

    public function query(array $context, array $payload): array
    {
        $payload['pageCode'] = self::PAGE_CODE;
        $definitions = $this->definitionsForQuery($context, $payload);
        $result = $this->execution->execute(
            self::PAGE_CODE,
            $this->authorizedSourceRows($context),
            $definitions,
            $this->executionPayload($payload),
            $context,
            function (array $row, array $scope): bool {
                return $this->rowAllowed($row, $scope);
            }
        );
        return $this->result($context, $result);
    }

    public function executeFrozenPlan(
        array $context,
        array $plan,
        string $exportScope,
        array $fieldKeys
    ): array {
        if ((string)($plan['page_code'] ?? '') !== self::PAGE_CODE) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_EXPORT_PLAN_INVALID',
                '导出查询计划与员工列表不匹配。',
                []
            );
        }
        $query = [
            'page' => (int)($plan['pagination']['page'] ?? 1),
            'limit' => (int)($plan['pagination']['limit'] ?? 20),
            'filters' => (array)($plan['filters'] ?? []),
            'topFilterConditions' => (array)($plan['top_filters'] ?? []),
            'keywordFilters' => (array)($plan['keyword_filters'] ?? []),
            'filterRelation' => (string)($plan['filter_relation'] ?? 'all'),
            'sorts' => (array)($plan['sorts'] ?? []),
            'groupBy' => (array)($plan['groups'] ?? []),
            'summaries' => (array)($plan['summaries'] ?? []),
            'dataScope' => (string)($plan['domain_scope']['data_scope'] ?? 'normal'),
            'businessStatus' => (string)($plan['domain_scope']['business_status'] ?? ''),
            'quickFilters' => (array)($plan['quick_filters'] ?? []),
            'visibleFields' => (array)($plan['visible_fields'] ?? []),
            'export' => [
                'scope' => $exportScope,
                'fields' => array_values(array_unique(array_map('strval', $fieldKeys))),
            ],
        ];
        return $this->execution->execute(
            self::PAGE_CODE,
            $this->authorizedSourceRows($context),
            (array)($plan['custom_definitions'] ?? []),
            $query,
            $context,
            function (array $row, array $scope): bool {
                return $this->rowAllowed($row, $scope);
            }
        );
    }

    protected function authorizedSourceRows(array $context): array
    {
        $storeId = (int)($context['store_id'] ?? 0);
        if ($storeId <= 0) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_PERMISSION_DENIED',
                '当前门店登录范围无效，请重新登录。',
                []
            );
        }
        $visible = $context['visible_store_ids'] ?? [];
        $allStores = !empty($context['all_stores']) || $visible === null;
        if (!$allStores && !in_array($storeId, array_map('intval', (array)$visible), true)) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_PERMISSION_DENIED',
                '当前账号无权查看该门店员工。',
                []
            );
        }

        $rows = Db::name('system_store_staff')->alias('ss')
            ->leftJoin('employee e', 'e.id = ss.employee_id')
            ->leftJoin('system_store s', 's.id = ss.store_id')
            ->leftJoin('user u', 'u.uid = ss.uid')
            ->leftJoin('position p', 'p.id = ss.position')
            ->leftJoin('position_level pl', 'pl.id = ss.position_level')
            ->where('ss.store_id', $storeId)
            ->where('ss.is_del', 0)
            ->field([
                'ss.*',
                'e.name' => 'employee_name',
                's.name' => 'store_name_value',
                'u.nickname' => 'user_nickname',
                'p.name' => 'position_name',
                'pl.name' => 'position_level_name',
            ])
            ->order('ss.id', 'asc')
            ->limit(UnifiedQueryExecutionServices::MAX_SOURCE_ROWS + 1)
            ->select()
            ->toArray();
        if (count($rows) > UnifiedQueryExecutionServices::MAX_SOURCE_ROWS) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_SOURCE_WINDOW_TOO_LARGE',
                '当前门店员工数量超过安全查询上限，请联系管理员。',
                ['max_rows' => UnifiedQueryExecutionServices::MAX_SOURCE_ROWS]
            );
        }

        /** @var SystemRoleServices $roleServices */
        $roleServices = app()->make(SystemRoleServices::class);
        $roleMap = $roleServices->getRoleArray([
            'type' => 1,
            'store_id' => $storeId,
            'status' => 1,
        ]);
        $staffIds = array_values(array_unique(array_filter(array_map(
            static function (array $row): int {
                return (int)($row['id'] ?? 0);
            },
            $rows
        ))));
        $customerCountByStaff = [];
        if ($staffIds) {
            $customerCounts = Db::name('user')
                ->whereIn('salesman_id', $staffIds)
                ->field('salesman_id,COUNT(*) AS customer_num')
                ->group('salesman_id')
                ->select()
                ->toArray();
            foreach ($customerCounts as $customerCount) {
                $customerCountByStaff[(int)$customerCount['salesman_id']] =
                    (int)$customerCount['customer_num'];
            }
        }
        $records = [];
        foreach ($rows as $row) {
            $roleNames = [];
            $roleIds = $row['roles'] ?? [];
            if (!is_array($roleIds)) {
                $decoded = json_decode((string)$roleIds, true);
                $roleIds = is_array($decoded)
                    ? $decoded
                    : array_filter(array_map('intval', explode(',', (string)$roleIds)));
            }
            foreach ($roleIds as $roleId) {
                if (isset($roleMap[(int)$roleId])) {
                    $roleNames[] = (string)$roleMap[(int)$roleId];
                }
            }
            $staffName = trim((string)($row['employee_name'] ?? ''))
                ?: trim((string)($row['staff_name'] ?? ''));
            $records[] = [
                'staff_id' => (int)$row['id'],
                'store_name' => (string)($row['store_name_value'] ?? ''),
                'staff_name' => $staffName,
                'nickname' => (string)($row['user_nickname'] ?? ''),
                'phone' => (string)($row['phone'] ?? ''),
                'roles' => (int)($row['level'] ?? 1) === 0
                    ? '超级管理员'
                    : ($roleNames ? implode(',', $roleNames) : '-'),
                'position_label' => (string)($row['position_name'] ?? '-'),
                'position_level_label' => (string)($row['position_level_name'] ?? '-'),
                'is_manager' => (int)($row['is_manager'] ?? 0) === 1 ? '是' : '否',
                'cashier_salesperson_enabled' => (int)($row['cashier_salesperson_enabled'] ?? 1) === 1 ? '是' : '否',
                'cashier_craftsman_enabled' => (int)($row['cashier_craftsman_enabled'] ?? 1) === 1 ? '是' : '否',
                'status' => (int)($row['status'] ?? 0) === 1 ? '在职' : '离职',
                'is_fencheng' => (int)($row['is_fencheng'] ?? 0) === 1 ? '参与' : '不参与',
                'employee_number' => (string)($row['employee_number'] ?? ''),
                'join_date' => $this->date($row['join_date'] ?? ''),
                'id_card' => (string)($row['id_card'] ?? ''),
                'birthday_date' => $this->date($row['birthday_date'] ?? ''),
                'age' => (int)($row['age'] ?? 0),
                'join_area' => (string)($row['join_area'] ?? ''),
                'birthday_area' => (string)($row['birthday_area'] ?? ''),
                'now_area' => (string)($row['now_area'] ?? ''),
                'contract_begin' => $this->date($row['contract_begin'] ?? ''),
                'contract_end' => $this->date($row['contract_end'] ?? ''),
                'uid' => (int)($row['uid'] ?? 0),
                'account' => (string)($row['account'] ?? ''),
                'has_pwd' => !empty($row['pwd']) ? '已设置' : '未设置',
                'is_customer' => (int)($row['is_customer'] ?? 0) === 1 ? '是' : '否',
                'is_reservable' => (int)($row['is_reservable'] ?? 0) === 1 ? '是' : '否',
                'customer_num' => (int)($customerCountByStaff[(int)$row['id']] ?? 0),
                'department' => (string)($row['department'] ?? ''),
                'salary_status' => (int)($row['salary_status'] ?? 0) === 1 ? '是' : '否',
                'birthday_type' => (int)($row['birthday_type'] ?? 0) === 1
                    ? '农历'
                    : ((int)($row['birthday_type'] ?? 0) === 2 ? '新历' : '-'),
                '_store_id' => (int)$row['store_id'],
                '_status_code' => (int)($row['status'] ?? 0) === 1 ? 'active' : 'inactive',
                '_salesperson_enabled' => (int)($row['cashier_salesperson_enabled'] ?? 1),
                '_craftsman_enabled' => (int)($row['cashier_craftsman_enabled'] ?? 1),
            ];
        }
        return $records;
    }

    protected function rowAllowed(array $row, array $scope): bool
    {
        if ((int)($row['_store_id'] ?? 0) !== (int)($scope['store_id'] ?? 0)) {
            return false;
        }
        if ((string)($scope['requested_data_scope'] ?? 'normal') === 'normal') {
            return (string)($row['_status_code'] ?? '') === 'active';
        }
        $status = trim((string)($scope['requested_business_status'] ?? ''));
        if ($status === '') {
            return true;
        }
        $map = ['在职' => 'active', 'active' => 'active', '离职' => 'inactive', 'inactive' => 'inactive'];
        return ($map[$status] ?? '') === (string)($row['_status_code'] ?? '');
    }

    protected function result(array $context, array $result): array
    {
        $preference = $this->preferences->load($context, self::PAGE_CODE);
        return [
            'records' => array_map(function (array $row): array {
                $row['id'] = (string)$row['staff_id'];
                $row['staffId'] = (int)$row['staff_id'];
                $row['salespersonEnabled'] = (int)($row['_salesperson_enabled'] ?? 0) === 1;
                $row['craftsmanEnabled'] = (int)($row['_craftsman_enabled'] ?? 0) === 1;
                foreach (array_keys($row) as $key) {
                    if (strpos((string)$key, '_') === 0) {
                        unset($row[$key]);
                    }
                }
                return $row;
            }, $result['rows']),
            'total' => (int)$result['pagination']['total'],
            'page' => (int)$result['pagination']['page'],
            'pageSize' => (int)$result['pagination']['limit'],
            'isLoading' => false,
            'statusOptions' => [
                ['value' => '在职', 'label' => '在职', 'normal' => true],
                ['value' => '离职', 'label' => '离职', 'normal' => false],
            ],
            'querySettings' => $preference,
            'summaries' => $result['summaries'],
            'groups' => $result['groups'],
            'queryCutoffDate' => $result['queryCutoffDate'],
            'dataAsOf' => $result['dataAsOf'],
            'metricVersion' => $result['schemaVersion'],
            'aggregationCaughtUp' => true,
            'consistencyFingerprint' => $result['consistencyFingerprint'],
            'security' => $result['security'],
        ];
    }

    protected function definitionsForQuery(array $context, array $payload): array
    {
        $candidate = $payload;
        unset($candidate['fieldVersions'], $candidate['field_versions']);
        $keys = (new UnifiedQueryCustomFieldKeyCollector())->collectFromQuery($candidate);
        if (!$keys) {
            return [];
        }
        $preference = $this->preferences->load($context, self::PAGE_CODE);
        $pinned = (array)($preference['customFieldVersions'] ?? []);
        $visible = [];
        foreach ($this->customFields->listVisible($context, self::PAGE_CODE, true) as $field) {
            $visible[(string)$field['key']] = $field;
        }
        $definitions = [];
        foreach ($keys as $fieldKey) {
            if (!isset($visible[$fieldKey])) {
                throw new UnifiedQueryException(
                    'UNIFIED_QUERY_CUSTOM_FIELD_UNAVAILABLE',
                    '查询使用的自定义字段已不可用，请重新设置。',
                    ['field_key' => $fieldKey]
                );
            }
            $version = (int)($pinned[$fieldKey] ?? $visible[$fieldKey]['version'] ?? 0);
            $definition = $this->customFields->versionDefinition(
                $context,
                self::PAGE_CODE,
                $fieldKey,
                $version
            );
            $definition['page_code'] = self::PAGE_CODE;
            $definitions[] = $definition;
        }
        return $definitions;
    }

    protected function executionPayload(array $payload): array
    {
        unset($payload['queryCutoffDate'], $payload['query_cutoff_date']);
        return $payload;
    }

    protected function date($value): string
    {
        if ($value === null || $value === '' || $value === '0000-00-00') {
            return '';
        }
        if (is_numeric($value)) {
            return (int)$value > 0 ? date('Y-m-d', (int)$value) : '';
        }
        return substr((string)$value, 0, 10);
    }
}
