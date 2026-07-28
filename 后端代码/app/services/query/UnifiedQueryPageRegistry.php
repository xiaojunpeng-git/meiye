<?php

namespace app\services\query;

/**
 * 服务端统一查询页面与源字段白名单。
 *
 * 字段 key 是查询投影合同，不是客户端可传入的 SQL 列名。领域查询提供器必须先
 * 生成这些 key，再交给统一查询执行器处理。
 */
class UnifiedQueryPageRegistry
{
    public const SCHEMA_VERSION = 'unified-query-2026-07-28-v1';

    /** @var array<string,array> */
    protected $pages = [];

    /** @var array<string,bool> */
    protected $reservedMetricCodes = [];

    /** @var array<string,bool> */
    protected $reservedMetricLabels = [];

    /** @var bool */
    protected $frozen = false;

    /** @var bool */
    protected $metricDictionaryLoaded = false;

    public function __construct(array $metricDefinitions = [])
    {
        $this->metricDictionaryLoaded = !empty($metricDefinitions);
        foreach ($metricDefinitions as $metric) {
            $code = strtolower(trim((string)($metric['code'] ?? '')));
            $label = $this->normalizeLabel(
                (string)($metric['name'] ?? ($metric['label'] ?? ''))
            );
            if ($code !== '') {
                $this->reservedMetricCodes[$code] = true;
            }
            if ($label !== '') {
                $this->reservedMetricLabels[$label] = true;
            }
            $aliases = is_array($metric['aliases'] ?? null) ? $metric['aliases'] : [];
            foreach ($aliases as $aliasKey => $aliasValue) {
                if (!is_int($aliasKey) && trim((string)$aliasKey) !== '') {
                    $this->reservedMetricCodes[strtolower(trim((string)$aliasKey))] = true;
                }
                $alias = $this->normalizeLabel((string)$aliasValue);
                if ($alias !== '') {
                    $this->reservedMetricLabels[$alias] = true;
                }
                $aliasCode = strtolower(trim((string)$aliasValue));
                if (preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $aliasCode)) {
                    $this->reservedMetricCodes[$aliasCode] = true;
                }
            }
        }
    }

    public static function withDefaults(array $metricDefinitions = []): self
    {
        $registry = new self($metricDefinitions);
        $registry->registerPage('member_list', '会员列表', [
            self::field('member_name', '会员姓名', 'text', true, true),
            self::field('phone', '完整手机号', 'text', true, true),
            self::field('member_no', '会员编号', 'text', true, true),
            self::field('member_status', '会员状态', 'text', true, false),
            self::field('member_level', '会员等级', 'text', true, false),
            self::field('member_tag', '会员标签', 'text', true, false),
            self::field('store', '归属门店', 'text', true, false),
            self::field('exclusive_service_staff', '专属服务人', 'text', true, false),
            self::field('account_balance', '账户余额', 'amount', true, false, [], '', true),
            self::field('active_card_count', '有效卡项数量', 'integer', true, false),
            self::field('remaining_project_times', '剩余项目次数', 'integer', true, false),
            self::field('remaining_project_amount', '剩余项目金额', 'amount', true, false),
            self::field('debt_amount', '欠款金额', 'amount', true, false, [], '', true),
            self::field('total_consumption_amount', '总消费金额', 'amount', true, false, [], '', true),
            self::field('visit_count', '到店次数', 'integer', true, false, [], '', true),
            self::field('latest_purchase_date', '最近购买日期', 'date', true, false),
            self::field('last_service_staff', '上次服务人员', 'text', true, false),
            self::field('latest_visit_date', '最近到店日期', 'date', true, false),
            self::field('created_at', '建档时间', 'datetime', true, false),
        ], 'member_id');
        $registry->freeze();
        return $registry;
    }

    public static function field(
        string $key,
        string $label,
        string $type,
        bool $defaultVisible = false,
        bool $defaultQuick = false,
        array $allowedOperations = [],
        string $permission = '',
        bool $systemMetric = false
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'type' => $type,
            'defaultVisible' => $defaultVisible,
            'defaultQuick' => $defaultQuick,
            'allowedOperations' => $allowedOperations,
            'permission' => $permission,
            'systemMetric' => $systemMetric,
        ];
    }

    public function registerPage(string $pageCode, string $label, array $fields, string $stableRowKey): void
    {
        if ($this->frozen) {
            throw new \LogicException('统一查询页面白名单已冻结');
        }
        $this->assertIdentifier($pageCode, 'page_code');
        $this->assertIdentifier($stableRowKey, 'stable_row_key');
        if (isset($this->pages[$pageCode])) {
            throw new \LogicException('统一查询页面重复登记：' . $pageCode);
        }
        if ($label === '' || $this->textLength($label) > 64) {
            throw new \InvalidArgumentException('统一查询页面名称不合法');
        }

        $normalized = [];
        foreach ($fields as $field) {
            $item = $this->normalizeField($field);
            if (isset($normalized[$item['key']])) {
                throw new \LogicException('统一查询字段重复登记：' . $item['key']);
            }
            $normalized[$item['key']] = $item;
            if (!empty($item['systemMetric'])) {
                $this->reservedMetricCodes[strtolower($item['key'])] = true;
                $this->reservedMetricLabels[$this->normalizeLabel($item['label'])] = true;
            }
        }
        if (!isset($normalized[$stableRowKey])) {
            // 稳定键必须能由领域投影提供，但默认不暴露给页面。
            $normalized[$stableRowKey] = $this->normalizeField([
                'key' => $stableRowKey,
                'label' => '记录标识',
                'type' => 'integer',
                'defaultVisible' => false,
                'defaultQuick' => false,
                'allowedOperations' => ['sort'],
                'hidden' => true,
            ]);
        }
        $this->pages[$pageCode] = [
            'pageCode' => $pageCode,
            'label' => $label,
            'stableRowKey' => $stableRowKey,
            'fields' => $normalized,
        ];
    }

    public function freeze(): void
    {
        $this->frozen = true;
    }

    public function page(string $pageCode): array
    {
        if (!isset($this->pages[$pageCode])) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_PAGE_NOT_ALLOWED',
                '当前页面尚未开放统一查询。',
                ['page_code' => $pageCode]
            );
        }
        return $this->pages[$pageCode];
    }

    public function fieldDefinition(string $pageCode, string $fieldKey): array
    {
        $page = $this->page($pageCode);
        if (!isset($page['fields'][$fieldKey])) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_FIELD_NOT_ALLOWED',
                '所选字段已不可用，请重新选择。',
                ['page_code' => $pageCode, 'field_key' => $fieldKey]
            );
        }
        return $page['fields'][$fieldKey];
    }

    public function assertReadable(string $pageCode, string $fieldKey, array $grantedPermissions): array
    {
        $field = $this->fieldDefinition($pageCode, $fieldKey);
        if (!empty($field['hidden'])) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_FIELD_FORBIDDEN',
                '该字段仅供系统内部使用，不能用于查询规则。',
                ['page_code' => $pageCode, 'field_key' => $fieldKey]
            );
        }
        $permission = (string)$field['permission'];
        if ($permission !== '' && !in_array('*', $grantedPermissions, true)
            && !in_array($permission, $grantedPermissions, true)) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_FIELD_FORBIDDEN',
                '你无权使用该字段，请重新选择。',
                ['page_code' => $pageCode, 'field_key' => $fieldKey]
            );
        }
        return $field;
    }

    public function fieldsForCapability(string $pageCode, array $grantedPermissions): array
    {
        $fields = [];
        foreach ($this->page($pageCode)['fields'] as $field) {
            if (!empty($field['hidden'])) {
                continue;
            }
            $permission = (string)$field['permission'];
            if ($permission !== '' && !in_array('*', $grantedPermissions, true)
                && !in_array($permission, $grantedPermissions, true)) {
                continue;
            }
            $fields[] = $this->capabilityField($field);
        }
        return $fields;
    }

    public function isReservedMetricCode(string $code): bool
    {
        return isset($this->reservedMetricCodes[strtolower(trim($code))]);
    }

    public function isReservedMetricLabel(string $label): bool
    {
        return isset($this->reservedMetricLabels[$this->normalizeLabel($label)]);
    }

    public function assertCustomIdentityAllowed(string $fieldKey, string $label): void
    {
        $this->assertMetricDictionaryLoaded();
        if ($this->isReservedMetricCode($fieldKey) || $this->isReservedMetricLabel($label)) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_SYSTEM_METRIC_RESERVED',
                '该名称属于系统指标，请更换自定义字段名称。',
                ['field_key' => $fieldKey, 'label' => $label]
            );
        }
    }

    /**
     * 自定义字段不能与当前页面任何系统源字段同名。
     *
     * 系统指标仍由 assertCustomIdentityAllowed 返回更严格的保留名错误；
     * 普通系统字段使用重名提示，避免列表、筛选和导出出现两个同名字段。
     */
    public function assertCustomFieldNameAllowed(
        string $pageCode,
        string $fieldKey,
        string $label
    ): void {
        $this->assertCustomIdentityAllowed($fieldKey, $label);
        $nameKey = $this->normalizeLabel($label);
        foreach ($this->page($pageCode)['fields'] as $source) {
            if ($this->normalizeLabel((string)$source['label']) === $nameKey) {
                throw new UnifiedQueryException(
                    'UNIFIED_QUERY_FIELD_NAME_DUPLICATE',
                    '该名称已被系统字段使用，请更换自定义字段名称。',
                    [
                        'field_key' => $fieldKey,
                        'source_field_key' => (string)$source['key'],
                        'label' => $label,
                    ]
                );
            }
        }
    }

    public function assertAliasAllowed(string $pageCode, string $fieldKey, string $alias): void
    {
        $this->assertMetricDictionaryLoaded();
        $source = $this->fieldDefinition($pageCode, $fieldKey);
        if ($this->isReservedMetricLabel($alias)
            && (empty($source['systemMetric']) || $this->normalizeLabel($source['label']) !== $this->normalizeLabel($alias))) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_SYSTEM_METRIC_RESERVED',
                '该名称属于系统指标，不能用于其他字段。',
                ['field_key' => $fieldKey, 'alias' => $alias]
            );
        }
    }

    public function schemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    protected function normalizeField(array $field): array
    {
        $key = trim((string)($field['key'] ?? ''));
        $this->assertIdentifier($key, 'field_key');
        $label = trim((string)($field['label'] ?? ''));
        if ($label === '' || $this->textLength($label) > 64) {
            throw new \InvalidArgumentException('统一查询字段名称不合法：' . $key);
        }
        $type = self::normalizeType((string)($field['type'] ?? ''));
        $operations = $field['allowedOperations'] ?? [];
        if (!$operations) {
            $operations = $this->defaultOperations($type);
        }
        $allowed = ['display', 'quick', 'filter', 'sort', 'group', 'summary', 'export'];
        foreach ($operations as $operation) {
            if (!in_array($operation, $allowed, true)) {
                throw new \InvalidArgumentException('统一查询字段操作不合法：' . $operation);
            }
        }
        return [
            'key' => $key,
            'label' => $label,
            'type' => $type,
            'allowedOperations' => array_values(array_unique($operations)),
            'defaultVisible' => !empty($field['defaultVisible']),
            'defaultQuick' => !empty($field['defaultQuick']),
            'customFieldId' => null,
            'version' => 1,
            'status' => 'active',
            'permission' => trim((string)($field['permission'] ?? '')),
            'systemMetric' => !empty($field['systemMetric']),
            'hidden' => !empty($field['hidden']),
        ];
    }

    protected function capabilityField(array $field): array
    {
        $operations = $field['allowedOperations'];
        return [
            'key' => $field['key'],
            'label' => $field['label'],
            'type' => $field['type'],
            'allowedOperations' => $operations,
            'capabilities' => [
                'list' => in_array('display', $operations, true),
                'quickFilter' => in_array('quick', $operations, true),
                'filter' => in_array('filter', $operations, true),
                'sort' => in_array('sort', $operations, true),
                'group' => in_array('group', $operations, true),
                'summary' => in_array('summary', $operations, true),
                'export' => in_array('export', $operations, true),
                'customInput' => in_array($field['type'], [
                    'text', 'integer', 'decimal', 'amount', 'date', 'datetime',
                ], true),
            ],
            'defaultVisible' => $field['defaultVisible'],
            'defaultQuick' => $field['defaultQuick'],
            'customFieldId' => $field['customFieldId'],
            'version' => $field['version'],
            'status' => $field['status'],
        ];
    }

    protected function defaultOperations(string $type): array
    {
        $operations = ['display', 'filter', 'sort', 'export'];
        if (in_array($type, ['text', 'date', 'datetime', 'integer', 'decimal', 'amount'], true)) {
            $operations[] = 'quick';
        }
        if (in_array($type, ['text', 'date', 'datetime', 'boolean'], true)) {
            $operations[] = 'group';
        }
        if (in_array($type, ['integer', 'decimal', 'amount'], true)) {
            $operations[] = 'summary';
        }
        return $operations;
    }

    public static function normalizeType(string $type): string
    {
        $map = [
            'string' => 'text',
            'text' => 'text',
            'money' => 'amount',
            'amount' => 'amount',
            'number' => 'decimal',
            'decimal' => 'decimal',
            'integer' => 'integer',
            'date' => 'date',
            'datetime' => 'datetime',
            'bool' => 'boolean',
            'boolean' => 'boolean',
        ];
        $normalized = $map[strtolower(trim($type))] ?? '';
        if ($normalized === '') {
            throw new \InvalidArgumentException('统一查询字段类型不合法：' . $type);
        }
        return $normalized;
    }

    protected function assertIdentifier(string $value, string $kind): void
    {
        if (!preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $value)) {
            throw new \InvalidArgumentException($kind . ' 必须是稳定的 ASCII 标识');
        }
    }

    protected function normalizeLabel(string $label): string
    {
        return strtolower(preg_replace('/\s+/u', '', trim($label)));
    }

    protected function assertMetricDictionaryLoaded(): void
    {
        if (!$this->metricDictionaryLoaded) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_METRIC_DICTIONARY_REQUIRED',
                '系统指标目录尚未就绪，暂时不能新增或改名字段。',
                []
            );
        }
    }

    protected function textLength(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
