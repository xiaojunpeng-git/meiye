<?php

namespace app\services\query;

use think\facade\Db;

/**
 * 账号 + 页面唯一查询设置正本，对齐 save-member-query-settings。
 */
class UnifiedQueryPreferenceServices
{
    public const TABLE = 'unified_query_preference';

    /** @var UnifiedQueryPageRegistry */
    protected $registry;

    /** @var UnifiedQueryCustomFieldServices */
    protected $customFields;

    /** @var UnifiedQueryExecutionServices */
    protected $execution;

    /** @var UnifiedQueryAccessPolicy */
    protected $access;

    /** @var UnifiedQueryFieldReferenceServices */
    protected $references;

    public function __construct(
        UnifiedQueryPageRegistry $registry,
        UnifiedQueryCustomFieldServices $customFields,
        UnifiedQueryExecutionServices $execution,
        UnifiedQueryAccessPolicy $access,
        UnifiedQueryFieldReferenceServices $references
    ) {
        $this->registry = $registry;
        $this->customFields = $customFields;
        $this->execution = $execution;
        $this->access = $access;
        $this->references = $references;
    }

    public function save(array $rawContext, array $payload): array
    {
        $context = $this->access->normalizeContext($rawContext);
        $this->access->assertPageAccess($context);
        $pageCode = trim((string)($payload['pageCode'] ?? ($payload['page_code'] ?? '')));
        $this->registry->page($pageCode);
        // 并发由外层 query_preference/<page_code> 网关资源锁唯一承担。
        // 此值来自已锁定的服务端 context，只证明调用未绕过网关，不与内部审计版本比较。
        $gatewayVersion = (int)($context['query_preference_version'] ?? 0);
        if ($gatewayVersion <= 0) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_GATEWAY_VERSION_REQUIRED',
                '查询设置缺少并发保护，请刷新后重试。',
                []
            );
        }
        $settings = is_array($payload['settings'] ?? null) ? $payload['settings'] : $payload;
        $normalized = $this->normalizeSettings($context, $pageCode, $settings, $payload);
        $settingsJson = UnifiedQueryJson::encode($normalized['settings']);
        $versionsJson = UnifiedQueryJson::encode($normalized['custom_field_versions']);
        $consumerId = 'account:' . $context['account_id'] . ':' . $pageCode;

        return Db::transaction(function () use (
            $context,
            $pageCode,
            $normalized,
            $settingsJson,
            $versionsJson,
            $consumerId
        ) {
            $row = Db::name(self::TABLE)
                ->where('tenant_id', $context['tenant_id'])
                ->where('account_id', $context['account_id'])
                ->where('page_code', $pageCode)
                ->lock(true)
                ->find();
            if (!$row) {
                $id = (int)Db::name(self::TABLE)->insertGetId([
                    'tenant_id' => $context['tenant_id'],
                    'account_id' => $context['account_id'],
                    'page_code' => $pageCode,
                    'settings' => UnifiedQueryJson::encode($this->defaultSettings(
                        $context,
                        $pageCode
                    )),
                    'settings_hash' => hash('sha256', UnifiedQueryJson::encode(
                        $this->defaultSettings($context, $pageCode)
                    )),
                    'referenced_field_versions' => '[]',
                    'current_version' => 1,
                    'created_at' => time(),
                    'updated_at' => time(),
                ]);
                $row = ['id' => $id, 'current_version' => 1];
            }
            $newVersion = (int)$row['current_version'] + 1;
            $updated = Db::name(self::TABLE)
                ->where('id', (int)$row['id'])
                ->where('current_version', (int)$row['current_version'])
                ->update([
                    'settings' => $settingsJson,
                    'settings_hash' => hash('sha256', $settingsJson),
                    'referenced_field_versions' => $versionsJson,
                    'current_version' => $newVersion,
                    'updated_at' => time(),
                ]);
            if ((int)$updated !== 1) {
                throw new \RuntimeException('查询设置审计版本推进失败');
            }
            $this->references->register(
                $context['tenant_id'],
                'saved_query',
                $consumerId,
                $normalized['custom_field_versions'],
                (string)$normalized['settings']['queryCutoffDate']
            );
            return [
                'pageCode' => $pageCode,
                'settings' => $normalized['settings'],
                'customFieldVersions' => $normalized['custom_field_versions'],
                'settingsVersion' => $newVersion,
                'invalidReferences' => [],
            ];
        });
    }

    public function load(array $rawContext, string $pageCode): array
    {
        $context = $this->access->normalizeContext($rawContext);
        $this->access->assertPageAccess($context);
        $this->registry->page($pageCode);
        $row = Db::name(self::TABLE)
            ->where('tenant_id', $context['tenant_id'])
            ->where('account_id', $context['account_id'])
            ->where('page_code', $pageCode)
            ->find();
        if (!$row) {
            return [
                'pageCode' => $pageCode,
                'settings' => $this->defaultSettings($context, $pageCode),
                'customFieldVersions' => [],
                'settingsVersion' => 1,
                'invalidReferences' => [],
            ];
        }
        $consumerId = 'account:' . $context['account_id'] . ':' . $pageCode;
        $invalid = Db::name(UnifiedQueryFieldReferenceServices::TABLE)
            ->where('tenant_id', $context['tenant_id'])
            ->where('consumer_type', 'saved_query')
            ->where('consumer_id', $consumerId)
            ->whereIn('status', ['invalid', 'upgrade_available'])
            ->field('field_key,field_version,status,invalid_reason')
            ->order('id asc')
            ->select()
            ->toArray();
        $settings = UnifiedQueryJson::decode((string)$row['settings']);
        // 截止日是每次查询的服务端时点，不允许被旧偏好永久冻结。
        $settings['queryCutoffDate'] = $this->trustedCutoffDate($context);
        return [
            'pageCode' => $pageCode,
            'settings' => $settings,
            'customFieldVersions' => UnifiedQueryJson::decode(
                (string)$row['referenced_field_versions']
            ),
            'settingsVersion' => (int)$row['current_version'],
            'invalidReferences' => array_map(function (array $reference): array {
                return [
                    'fieldKey' => (string)$reference['field_key'],
                    'version' => (int)$reference['field_version'],
                    'status' => (string)$reference['status'],
                    'reason' => (string)$reference['invalid_reason'],
                ];
            }, $invalid),
        ];
    }

    /**
     * 将当前账号、当前页面的一个“有可用新版本”引用升级到服务端决议的最新版本。
     *
     * 前端只能提交 fieldKey，不能提交目标版本、表达式或设置内容。这里先锁住偏好、
     * 引用和字段本体，再从字段当前版本重新校验原有设置，最后复用 save() 的同一
     * 规范化/引用登记路径原子落库。因此列表、分页、合计、筛选、排序、分组和导出
     * 会在下一次读取偏好时使用完全相同的新版本，绝不静默改写其他账号或其他页面。
     */
    public function upgradeReference(array $rawContext, array $payload): array
    {
        $context = $this->access->normalizeContext($rawContext);
        $this->access->assertPageAccess($context);
        $pageCode = trim((string)($payload['pageCode'] ?? ($payload['page_code'] ?? '')));
        $this->registry->page($pageCode);
        $fieldKey = trim((string)($payload['fieldKey'] ?? ($payload['field_key'] ?? '')));
        if (!preg_match('/^cf_[a-f0-9]{20,40}$/D', $fieldKey)) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_REFERENCE_UPGRADE_INVALID',
                '要升级的字段引用无效，请刷新后重试。',
                []
            );
        }
        // 与保存设置相同，宿主 Gateway 的 query_preference 资源锁是唯一并发口径；
        // 不能用客户端 expectedVersion 或 fieldVersion 替代它。
        if ((int)($context['query_preference_version'] ?? 0) <= 0) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_GATEWAY_VERSION_REQUIRED',
                '查询设置缺少并发保护，请刷新后重试。',
                []
            );
        }
        $consumerId = 'account:' . $context['account_id'] . ':' . $pageCode;

        $result = Db::transaction(function () use ($context, $pageCode, $fieldKey, $consumerId): array {
            $preference = Db::name(self::TABLE)
                ->where('tenant_id', $context['tenant_id'])
                ->where('account_id', $context['account_id'])
                ->where('page_code', $pageCode)
                ->lock(true)
                ->find();
            if (!$preference) {
                throw new UnifiedQueryException(
                    'UNIFIED_QUERY_REFERENCE_UPGRADE_NOT_AVAILABLE',
                    '当前没有可升级的已保存字段引用，请刷新后重试。',
                    ['field_key' => $fieldKey]
                );
            }

            $reference = Db::name(UnifiedQueryFieldReferenceServices::TABLE)
                ->where('tenant_id', $context['tenant_id'])
                ->where('consumer_type', 'saved_query')
                ->where('consumer_id', $consumerId)
                ->where('field_key', $fieldKey)
                ->lock(true)
                ->find();
            if (!$reference || (string)$reference['status'] !== 'upgrade_available') {
                throw new UnifiedQueryException(
                    'UNIFIED_QUERY_REFERENCE_UPGRADE_NOT_AVAILABLE',
                    '该字段当前没有可升级的新版本，请刷新后重试。',
                    ['field_key' => $fieldKey]
                );
            }

            $settings = UnifiedQueryJson::decode((string)$preference['settings']);
            $referencedKeys = (new UnifiedQueryCustomFieldKeyCollector())
                ->collectFromSettings($settings);
            if (!in_array($fieldKey, $referencedKeys, true)) {
                // 旧引用可能在一次保存后被释放但尚未来得及反映到页面；将它显式收口，
                // 不能把这种行误认为仍可升级。
                $this->releaseReferenceRow((int)$reference['id']);
                return [
                    'pageCode' => $pageCode,
                    'upgradedReference' => [
                        'fieldKey' => $fieldKey,
                        'alreadyRemoved' => true,
                    ],
                    'querySettings' => $this->load($context, $pageCode),
                ];
            }

            $field = Db::name(UnifiedQueryCustomFieldServices::TABLE)
                ->where('id', (int)$reference['custom_field_id'])
                ->where('tenant_id', $context['tenant_id'])
                ->lock(true)
                ->find();
            if (!$field
                || (string)$field['field_key'] !== $fieldKey
                || (string)$field['page_code'] !== $pageCode
                || (string)$field['status'] !== 'active'
                || !$this->access->canSeeRow($context, $field)) {
                $this->markReferenceUnavailable(
                    (int)$reference['id'],
                    '该字段已停用、失效或你已无权查看，不能升级；可移除此引用。'
                );
                return $this->unavailableUpgradeResult(
                    $fieldKey,
                    '该字段已停用、失效或你已无权查看，不能升级；可移除此引用。'
                );
            }

            $previousVersion = (int)$reference['field_version'];
            $currentVersion = (int)$field['current_version'];
            if ($currentVersion === $previousVersion) {
                // 防御历史遗留状态：真正已经是当前版本时恢复 active，而不是继续显示
                // 一个无法执行的“升级”按钮。
                Db::name(UnifiedQueryFieldReferenceServices::TABLE)
                    ->where('id', (int)$reference['id'])
                    ->where('status', 'upgrade_available')
                    ->update([
                        'status' => 'active',
                        'invalid_reason' => '',
                        'updated_at' => time(),
                    ]);
                return [
                    'pageCode' => $pageCode,
                    'upgradedReference' => [
                        'fieldKey' => $fieldKey,
                        'previousVersion' => $previousVersion,
                        'version' => $currentVersion,
                        'alreadyCurrent' => true,
                    ],
                    'querySettings' => $this->load($context, $pageCode),
                ];
            }
            if ($currentVersion < $previousVersion) {
                $this->markReferenceUnavailable(
                    (int)$reference['id'],
                    '字段版本状态异常，不能升级；请移除此引用后重新配置。'
                );
                return $this->unavailableUpgradeResult(
                    $fieldKey,
                    '字段版本状态异常，不能升级；请移除此引用后重新配置。'
                );
            }

            try {
                // 此调用会复核当前版本的表达式、来源字段契约和当前账号权限。字段行
                // 已在本事务中锁定，因此 current_version 不会在复核与保存间漂移。
                $this->customFields->versionDefinition(
                    $context,
                    $pageCode,
                    $fieldKey,
                    $currentVersion
                );
            } catch (UnifiedQueryException $exception) {
                $this->markReferenceUnavailable(
                    (int)$reference['id'],
                    '该字段当前不可升级：' . $exception->getMessage() . '；可移除此引用。'
                );
                return $this->unavailableUpgradeResult(
                    $fieldKey,
                    '该字段当前不可升级，请移除此引用或恢复权限后重试。',
                    $exception->getErrorCode()
                );
            }

            $pinnedVersions = UnifiedQueryJson::decode(
                (string)$preference['referenced_field_versions']
            );
            // 客户端 payload 中即使伪造 targetVersion/fieldVersion/customFieldVersions 也
            // 完全不会被读取；唯一可信目标是上面被锁住的 field.current_version。
            $pinnedVersions[$fieldKey] = $currentVersion;
            $trustedContext = $context;
            $trustedContext['trusted_custom_field_versions'] = $pinnedVersions;
            try {
                $saved = $this->save($trustedContext, [
                    'pageCode' => $pageCode,
                    'settings' => $settings,
                ]);
            } catch (UnifiedQueryException $exception) {
                // 新版本若与原筛选/排序/分组/合计不兼容，不允许半升级或降级丢条件；
                // 保留 upgrade_available，让用户处理设置后再次明确选择升级。
                throw new UnifiedQueryException(
                    'UNIFIED_QUERY_REFERENCE_UPGRADE_INCOMPATIBLE',
                    '新版本无法兼容当前已保存的查询设置，请先调整或移除该字段引用后再升级。',
                    [
                        'field_key' => $fieldKey,
                        'cause' => $exception->getErrorCode(),
                    ]
                );
            }
            $saved['upgradedReference'] = [
                'fieldKey' => $fieldKey,
                'previousVersion' => $previousVersion,
                'version' => $currentVersion,
            ];
            // save() 已在同一事务内通过 register() 把新版本写入引用表；这里返回其
            // 他仍需处理的引用，避免页面把多字段升级提示静默吞掉。
            $saved['invalidReferences'] = $this->load($trustedContext, $pageCode)['invalidReferences'];
            return $saved;
        });
        if (isset($result['__reference_upgrade_failure'])) {
            $failure = (array)$result['__reference_upgrade_failure'];
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_REFERENCE_UPGRADE_UNAVAILABLE',
                (string)($failure['message'] ?? '该字段当前不能升级，请移除此引用后重试。'),
                [
                    'field_key' => $fieldKey,
                    'cause' => (string)($failure['cause'] ?? ''),
                ]
            );
        }
        return $result;
    }

    protected function unavailableUpgradeResult(string $fieldKey, string $message, string $cause = ''): array
    {
        return [
            '__reference_upgrade_failure' => [
                'field_key' => $fieldKey,
                'message' => $message,
                'cause' => $cause,
            ],
        ];
    }

    protected function markReferenceUnavailable(int $referenceId, string $reason): void
    {
        Db::name(UnifiedQueryFieldReferenceServices::TABLE)
            ->where('id', $referenceId)
            ->where('status', 'upgrade_available')
            ->update([
                'status' => 'invalid',
                'invalid_reason' => $reason,
                'updated_at' => time(),
            ]);
    }

    protected function releaseReferenceRow(int $referenceId): void
    {
        Db::name(UnifiedQueryFieldReferenceServices::TABLE)
            ->where('id', $referenceId)
            ->where('status', '<>', 'released')
            ->update([
                'status' => 'released',
                'invalid_reason' => '',
                'updated_at' => time(),
            ]);
    }

    protected function normalizeSettings(
        array $context,
        string $pageCode,
        array $settings,
        array $payload
    ): array {
        $allowedKeys = [
            'pageCode', 'page_code', 'expectedSettingsVersion', 'expected_settings_version',
            'expectedVersion', 'settings', 'customFieldVersions', 'visibleFields', 'quickFields',
            'filters', 'filterRelation', 'sorts', 'groupBy', 'summaries', 'queryCutoffDate',
            'schemaVersion',
        ];
        foreach (array_keys($settings) as $key) {
            if (!in_array($key, $allowedKeys, true)) {
                throw new UnifiedQueryException(
                    'UNIFIED_QUERY_SETTINGS_INVALID',
                    '查询设置包含不支持的配置。',
                    ['key' => $key]
                );
            }
        }
        $cutoffDate = $this->trustedCutoffDate($context);
        if (isset($settings['schemaVersion'])
            && (string)$settings['schemaVersion'] !== $this->registry->schemaVersion()) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_SCHEMA_VERSION_CONFLICT',
                '查询设置版本已更新，请刷新页面后再保存。',
                [
                    'expected' => $this->registry->schemaVersion(),
                    'received' => (string)$settings['schemaVersion'],
                ]
            );
        }
        $visibleFields = $this->stringList($settings['visibleFields'] ?? [], 100, '显示字段');
        $quickFields = $this->stringList($settings['quickFields'] ?? [], 8, '顶部查询字段');
        $referencedCustomKeys = (new UnifiedQueryCustomFieldKeyCollector())
            ->collectFromSettings($settings);
        // 仅接受 handler 从服务端上下文注入的可信升级选择；忽略客户端自报版本。
        $requestedVersions = is_array($context['trusted_custom_field_versions'] ?? null)
            ? $context['trusted_custom_field_versions']
            : [];
        $existingPinnedVersions = $this->existingPinnedVersions($context, $pageCode);
        $visibleCustomVersions = [];
        foreach ($this->customFields->listVisible($context, $pageCode, true) as $visibleCustom) {
            $visibleCustomVersions[(string)$visibleCustom['key']] = (int)$visibleCustom['version'];
        }
        $definitions = [];
        $customVersions = [];
        foreach ($referencedCustomKeys as $fieldKey) {
            $version = (int)($requestedVersions[$fieldKey]
                ?? ($existingPinnedVersions[$fieldKey] ?? ($visibleCustomVersions[$fieldKey] ?? 0)));
            if ($version <= 0) {
                throw new UnifiedQueryException(
                    'UNIFIED_QUERY_FIELD_VERSION_REQUIRED',
                    '保存查询时必须固定自定义字段版本。',
                    ['field_key' => $fieldKey]
                );
            }
            $definition = $this->customFields->versionDefinition(
                $context,
                $pageCode,
                $fieldKey,
                $version
            );
            $definition['page_code'] = $pageCode;
            $definitions[] = $definition;
            $customVersions[$fieldKey] = $version;
        }
        $plan = $this->execution->validatedPlan(
            $pageCode,
            $definitions,
            [
                'filters' => is_array($settings['filters'] ?? null) ? $settings['filters'] : [],
                'filterRelation' => (string)($settings['filterRelation'] ?? 'all'),
                'sorts' => is_array($settings['sorts'] ?? null) ? $settings['sorts'] : [],
                'groupBy' => is_array($settings['groupBy'] ?? null) ? $settings['groupBy'] : [],
                'summaries' => is_array($settings['summaries'] ?? null) ? $settings['summaries'] : [],
            ],
            array_merge($context, ['query_cutoff_date' => $cutoffDate])
        );
        $available = $this->availableFields($context, $pageCode);
        foreach ($visibleFields as $fieldKey) {
            $this->assertAvailableOperation($available, $fieldKey, 'display');
        }
        foreach ($quickFields as $fieldKey) {
            $this->assertAvailableOperation($available, $fieldKey, 'quick');
        }
        $summaries = array_map(function (array $summary): array {
            $reverse = [
                'sum' => 'sum',
                'average' => 'avg',
                'minimum' => 'min',
                'maximum' => 'max',
                'count' => 'count',
            ];
            return [
                'field' => $summary['field_key'],
                'aggregation' => $reverse[$summary['operator']],
            ];
        }, $plan['summaries']);
        $sorts = array_map(function (array $sort): array {
            return [
                'field' => $sort['field_key'],
                'direction' => $sort['direction'],
            ];
        }, array_values(array_filter($plan['sorts'], function (array $sort) use ($pageCode): bool {
            return $sort['field_key'] !== $this->registry->page($pageCode)['stableRowKey'];
        })));
        $filters = array_map(function (array $filter): array {
            return [
                'fieldKey' => $filter['field_key'],
                'operator' => $filter['operator'],
                'value' => $filter['value'],
            ];
        }, $plan['filters']);
        return [
            'settings' => [
                'visibleFields' => $visibleFields,
                'quickFields' => $quickFields,
                'filters' => $filters,
                'filterRelation' => $plan['filter_relation'],
                'sorts' => $sorts,
                'groupBy' => $plan['groups'],
                'summaries' => $summaries,
                'queryCutoffDate' => $cutoffDate,
                'schemaVersion' => $this->registry->schemaVersion(),
            ],
            'custom_field_versions' => $customVersions,
        ];
    }

    protected function defaultSettings(array $context, string $pageCode): array
    {
        $visible = [];
        $quick = [];
        foreach ($this->registry->fieldsForCapability($pageCode, $context['permissions']) as $field) {
            if (!empty($field['defaultVisible'])) {
                $visible[] = (string)$field['key'];
            }
            if (!empty($field['defaultQuick'])) {
                $quick[] = (string)$field['key'];
            }
        }
        return [
            'visibleFields' => $visible,
            'quickFields' => $quick,
            'filters' => [],
            'filterRelation' => 'all',
            // 页面未显式保存业务排序时，由执行器追加该页面的 stableRowKey，
            // 不能假设所有统一查询页面都存在 created_at。
            'sorts' => [],
            'groupBy' => [],
            'summaries' => [],
            'queryCutoffDate' => $this->trustedCutoffDate($context),
            'schemaVersion' => $this->registry->schemaVersion(),
        ];
    }

    protected function availableFields(array $context, string $pageCode): array
    {
        $fields = array_merge(
            $this->registry->fieldsForCapability($pageCode, $context['permissions']),
            $this->customFields->listVisible($context, $pageCode, false)
        );
        $available = [];
        foreach ($fields as $field) {
            $available[(string)$field['key']] = (array)$field['allowedOperations'];
        }
        return $available;
    }

    protected function assertAvailableOperation(array $available, string $fieldKey, string $operation): void
    {
        if (!isset($available[$fieldKey]) || !in_array($operation, $available[$fieldKey], true)) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_FIELD_OPERATION_FORBIDDEN',
                '查询设置包含已不可用的字段。',
                ['field_key' => $fieldKey, 'operation' => $operation]
            );
        }
    }

    protected function stringList($values, int $max, string $label): array
    {
        if (!is_array($values) || count($values) > $max) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_SETTINGS_INVALID',
                $label . '数量不合法。',
                ['max' => $max]
            );
        }
        $result = array_values(array_unique(array_map('strval', $values)));
        foreach ($result as $value) {
            if (!preg_match('/^(?:[a-z][a-z0-9_]{1,63}|cf_[a-f0-9]{20,40})$/D', $value)) {
                throw new UnifiedQueryException(
                    'UNIFIED_QUERY_SETTINGS_INVALID',
                    $label . '包含无效字段。',
                    ['field_key' => $value]
                );
            }
        }
        return $result;
    }

    protected function existingPinnedVersions(array $context, string $pageCode): array
    {
        $json = Db::name(self::TABLE)
            ->where('tenant_id', $context['tenant_id'])
            ->where('account_id', $context['account_id'])
            ->where('page_code', $pageCode)
            ->value('referenced_field_versions');
        return $json ? UnifiedQueryJson::decode((string)$json) : [];
    }

    protected function trustedCutoffDate(array $context): string
    {
        $value = (string)($context['query_cutoff_date'] ?? '');
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false
            || ($errors !== false
                && ((int)$errors['warning_count'] > 0 || (int)$errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_CUTOFF_DATE_INVALID',
                '查询截止日期无效，请刷新后重试。',
                []
            );
        }
        return $value;
    }

    protected function versionConflict(int $expected, int $current = 0): UnifiedQueryException
    {
        return new UnifiedQueryException(
            'UNIFIED_QUERY_SETTINGS_VERSION_CONFLICT',
            '查询设置已在其他窗口更新，请刷新后再保存。',
            ['expected_version' => $expected, 'current_version' => $current]
        );
    }
}
