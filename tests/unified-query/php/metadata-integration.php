<?php

/**
 * 统一查询元数据、引用和导出任务 MySQL 集成门禁。
 */
require '/tests/lib/boot-env.php';
require '/var/www/html/vendor/autoload.php';
require '/tests/lib/_lib.php';

use app\services\metric\MetricDictionaryServices;
use app\services\query\StructuredExpressionEvaluator;
use app\services\query\StructuredExpressionValidator;
use app\services\query\UnifiedQueryAccessPolicy;
use app\services\query\UnifiedQueryCustomFieldServices;
use app\services\query\UnifiedQueryException;
use app\services\query\UnifiedQueryExecutionServices;
use app\services\query\UnifiedQueryExportTaskServices;
use app\services\query\UnifiedQueryFieldAliasServices;
use app\services\query\UnifiedQueryFieldReferenceServices;
use app\services\query\UnifiedQueryJson;
use app\services\query\UnifiedQueryPageRegistry;
use app\services\query\UnifiedQueryPreferenceServices;
use app\services\query\provider\MemberUnifiedQueryProvider;
use think\facade\Db;

c1aBootThinkApp('/var/www/html/');
date_default_timezone_set('Asia/Shanghai');

function uqMetaSection(string $title): void
{
    echo "\n== {$title} ==\n";
}

function uqMetaCode(callable $callable): string
{
    try {
        $callable();
    } catch (UnifiedQueryException $exception) {
        return $exception->getErrorCode();
    } catch (\Throwable $throwable) {
        return 'THROWABLE:' . get_class($throwable) . ':' . $throwable->getMessage();
    }
    return '';
}

function uqMetaThrows(callable $callable): string
{
    try {
        $callable();
    } catch (\Throwable $throwable) {
        return get_class($throwable) . ':' . $throwable->getMessage();
    }
    return '';
}

/**
 * definitionsForQuery 是投影内部的最后一道字段版本解析；用反射只验证其公开查询
 * 输入语义，避免为测试新增可被生产调用的旁路。
 */
function uqMetaMemberDefinitions(
    MemberUnifiedQueryProvider $provider,
    array $context,
    array $payload
): array {
    $method = new \ReflectionMethod(MemberUnifiedQueryProvider::class, 'definitionsForQuery');
    $method->setAccessible(true);
    return (array)$method->invoke($provider, $context, $payload);
}

function uqMetaContext(
    int $accountId,
    int $storeId,
    array $visibleStores,
    array $permissions = [],
    string $tenantId = '0'
): array {
    return [
        'tenant_id' => $tenantId,
        'account_id' => $accountId,
        'operator_id' => $accountId,
        'store_id' => $storeId,
        'organization_id' => $storeId === 8 ? '3' : '4',
        'visible_store_ids' => $visibleStores,
        'ancestor_organization_ids' => $storeId === 8 ? ['1', '2', '3'] : ['1', '4'],
        'shareable_store_ids' => $visibleStores,
        'shareable_organization_ids' => $storeId === 8 ? ['3'] : ['4'],
        'permissions' => array_values(array_unique(array_merge([
            UnifiedQueryAccessPolicy::PAGE_POLICY,
        ], $permissions))),
        'query_cutoff_date' => '2026-07-28',
        'data_as_of' => 1785258000,
        'permission_version' => 'perm-' . $accountId . '-1',
        'query_preference_version' => 1,
    ];
}

function uqMetaExpression(string $divisor = '3'): array
{
    return [
        'type' => 'binary',
        'operator' => 'divide',
        'left' => ['type' => 'field', 'fieldKey' => 'account_balance'],
        'right' => ['type' => 'literal', 'valueType' => 'decimal', 'value' => $divisor],
        'nullMode' => 'empty',
        'returnType' => 'amount',
    ];
}

function uqMetaManagementFlags(array $field): array
{
    return [
        'canEdit' => $field['canEdit'] ?? null,
        'canChangeStatus' => $field['canChangeStatus'] ?? null,
        'canArchive' => $field['canArchive'] ?? null,
    ];
}

function uqMetaResetTables(): void
{
    foreach ([
        'unified_query_field_reference',
        'unified_query_export_task',
        'unified_query_field_alias',
        'unified_query_field_alias_set',
        'unified_query_preference',
        'unified_query_custom_field_version',
        'unified_query_custom_field',
    ] as $table) {
        Db::execute('DELETE FROM `eb_' . $table . '`');
    }
}

$metricDefinitions = (new MetricDictionaryServices())->getDefinitions();
$metricDefinitions[] = ['code' => 'account_balance', 'name' => '账户余额', 'aliases' => ['会员余额']];
$metricDefinitions[] = ['code' => 'debt_amount', 'name' => '欠款金额', 'aliases' => []];
$metricDefinitions[] = ['code' => 'total_consumption_amount', 'name' => '总消费金额', 'aliases' => []];
$metricDefinitions[] = ['code' => 'visit_count', 'name' => '到店次数', 'aliases' => []];

$registry = UnifiedQueryPageRegistry::withDefaults($metricDefinitions);
$validator = new StructuredExpressionValidator($registry);
$evaluator = new StructuredExpressionEvaluator();
$access = new UnifiedQueryAccessPolicy();
$references = new UnifiedQueryFieldReferenceServices();
$customFields = new UnifiedQueryCustomFieldServices($registry, $validator, $access, $references);
$execution = new UnifiedQueryExecutionServices($registry, $validator, $evaluator);
$aliases = new UnifiedQueryFieldAliasServices($registry, $customFields, $access);
$preferences = new UnifiedQueryPreferenceServices(
    $registry,
    $customFields,
    $execution,
    $access,
    $references
);
$exports = new UnifiedQueryExportTaskServices(
    $registry,
    $customFields,
    $aliases,
    $references,
    $execution,
    $preferences,
    $access
);
$memberProvider = new MemberUnifiedQueryProvider($execution, $customFields, $preferences);

$manager = uqMetaContext(101, 8, [8], [
    UnifiedQueryAccessPolicy::MANAGE_SHARED,
    UnifiedQueryAccessPolicy::EXPORT,
]);
$sameStoreUser = uqMetaContext(102, 8, [8]);
$otherStoreUser = uqMetaContext(103, 9, [9]);
$otherStoreManager = uqMetaContext(107, 9, [9], [
    UnifiedQueryAccessPolicy::MANAGE_SHARED,
]);
$tenantManager = uqMetaContext(104, 8, [8], [
    UnifiedQueryAccessPolicy::MANAGE_SHARED,
    UnifiedQueryAccessPolicy::SHARE_TENANT,
    UnifiedQueryAccessPolicy::EXPORT,
]);
$otherTenant = uqMetaContext(105, 8, [8], [UnifiedQueryAccessPolicy::EXPORT], 'tenant-b');
$literalUser = uqMetaContext(108, 8, [8], [UnifiedQueryAccessPolicy::EXPORT]);
$sharedExporter = uqMetaContext(109, 8, [8], [UnifiedQueryAccessPolicy::EXPORT]);

try {
    uqMetaResetTables();

    uqMetaSection('personal/shared visibility and authority');
    $personal = $customFields->save($manager, [
        'pageCode' => 'member_list',
        'name' => '个人余额三分之一',
        'returnType' => 'amount',
        'visibility' => 'personal',
        'expression' => uqMetaExpression('3'),
    ]);
    $personalKey = (string)($personal['key'] ?? '');
    $managerPersonalKeys = array_column($customFields->listVisible($manager, 'member_list'), 'key');
    $sameStorePersonalKeys = array_column($customFields->listVisible($sameStoreUser, 'member_list'), 'key');
    $otherTenantPersonalKeys = array_column($customFields->listVisible($otherTenant, 'member_list'), 'key');
    $foreignEditCode = uqMetaCode(function () use ($customFields, $sameStoreUser, $personalKey): void {
        $customFields->save($sameStoreUser, [
            'pageCode' => 'member_list',
            'fieldKey' => $personalKey,
            'expectedVersion' => 1,
            'name' => '越权编辑',
            'returnType' => 'amount',
            'expression' => uqMetaExpression('2'),
        ]);
    });
    ok(
        '个人字段只对创建账号可见且他人不可编辑',
        preg_match('/^cf_[a-f0-9]{24}$/D', $personalKey) === 1
            && in_array($personalKey, $managerPersonalKeys, true)
            && !in_array($personalKey, $sameStorePersonalKeys, true)
            && !in_array($personalKey, $otherTenantPersonalKeys, true)
            && $foreignEditCode === 'UNIFIED_QUERY_FIELD_FORBIDDEN',
        json_encode(compact(
            'personalKey',
            'managerPersonalKeys',
            'sameStorePersonalKeys',
            'otherTenantPersonalKeys',
            'foreignEditCode'
        ), JSON_UNESCAPED_UNICODE),
        'UQ-PERM-02'
    );

    $shared = $customFields->save($manager, [
        'pageCode' => 'member_list',
        'name' => '本店共享余额',
        'returnType' => 'amount',
        'visibility' => 'shared',
        'shareScope' => 'store',
        'scopeId' => '8',
        'expression' => uqMetaExpression('2'),
    ]);
    $sharedKey = (string)($shared['key'] ?? '');
    $sameStoreShared = array_column($customFields->listVisible($sameStoreUser, 'member_list'), 'key');
    $otherStoreShared = array_column($customFields->listVisible($otherStoreUser, 'member_list'), 'key');
    $managerFieldIndex = array_column(
        $customFields->listVisible($manager, 'member_list'),
        null,
        'key'
    );
    $sameStoreFieldIndex = array_column(
        $customFields->listVisible($sameStoreUser, 'member_list'),
        null,
        'key'
    );
    $unshareCode = uqMetaCode(function () use ($customFields, $tenantManager, $sharedKey): void {
        $customFields->save($tenantManager, [
            'pageCode' => 'member_list',
            'fieldKey' => $sharedKey,
            'expectedVersion' => 1,
            'name' => '错误转个人',
            'returnType' => 'amount',
            'visibility' => 'personal',
            'expression' => uqMetaExpression('2'),
        ]);
    });
    $shareForbiddenCode = uqMetaCode(function () use ($customFields, $sameStoreUser): void {
        $customFields->save($sameStoreUser, [
            'pageCode' => 'member_list',
            'name' => '无权共享',
            'returnType' => 'amount',
            'visibility' => 'shared',
            'shareScope' => 'store',
            'scopeId' => '8',
            'expression' => uqMetaExpression('2'),
        ]);
    });
    $crossScopeManageCode = uqMetaCode(function () use (
        $customFields,
        $otherStoreManager,
        $sharedKey
    ): void {
        $customFields->save($otherStoreManager, [
            'pageCode' => 'member_list',
            'fieldKey' => $sharedKey,
            'expectedVersion' => 1,
            'name' => '越权修改本店共享字段',
            'returnType' => 'amount',
            'visibility' => 'shared',
            'shareScope' => 'store',
            'scopeId' => '9',
            'expression' => uqMetaExpression('2'),
        ]);
    });
    ok(
        '门店共享字段仅范围内可见且共享/转私有权限受控',
        in_array($sharedKey, $sameStoreShared, true)
            && !in_array($sharedKey, $otherStoreShared, true)
            && $unshareCode === 'UNIFIED_QUERY_OWNERSHIP_TRANSFER_FORBIDDEN'
            && $shareForbiddenCode === 'UNIFIED_QUERY_SHARE_FORBIDDEN',
        json_encode(compact(
            'sharedKey',
            'sameStoreShared',
            'otherStoreShared',
            'unshareCode',
            'shareForbiddenCode'
        ), JSON_UNESCAPED_UNICODE),
        'UQ-PERM-03'
    );
    ok(
        '其他门店管理员即使持共享管理权限也不能编辑本店共享字段',
        $crossScopeManageCode === 'UNIFIED_QUERY_FIELD_FORBIDDEN',
        $crossScopeManageCode,
        'UQ-PERM-05'
    );
    $managementFlags = [
        'personalOwner' => uqMetaManagementFlags((array)($managerFieldIndex[$personalKey] ?? [])),
        'sharedManager' => uqMetaManagementFlags((array)($managerFieldIndex[$sharedKey] ?? [])),
        'sharedViewer' => uqMetaManagementFlags((array)($sameStoreFieldIndex[$sharedKey] ?? [])),
    ];
    ok(
        '字段级管理标记区分个人本人、共享范围管理员与普通查看账号',
        $managementFlags === [
            'personalOwner' => [
                'canEdit' => true,
                'canChangeStatus' => true,
                'canArchive' => true,
            ],
            'sharedManager' => [
                'canEdit' => true,
                'canChangeStatus' => true,
                'canArchive' => true,
            ],
            'sharedViewer' => [
                'canEdit' => false,
                'canChangeStatus' => false,
                'canArchive' => false,
            ],
        ],
        json_encode($managementFlags, JSON_UNESCAPED_UNICODE),
        'UQ-PERM-06'
    );

    $explicitNone = uqMetaContext(106, 8, []);
    $noneVisible = array_column($customFields->listVisible($explicitNone, 'member_list'), 'key');
    ok(
        '后端显式空数据范围不回退当前门店',
        !in_array($sharedKey, $noneVisible, true),
        json_encode($noneVisible, JSON_UNESCAPED_UNICODE),
        'UQ-PERM-04'
    );

    uqMetaSection('name and immutable versions');
    $sameNameOtherAccount = $customFields->save($sameStoreUser, [
        'pageCode' => 'member_list',
        'name' => '个人余额三分之一',
        'returnType' => 'amount',
        'visibility' => 'personal',
        'expression' => uqMetaExpression('4'),
    ]);
    $duplicateCode = uqMetaCode(function () use ($customFields, $manager): void {
        $customFields->save($manager, [
            'pageCode' => 'member_list',
            'name' => '个人 余额 三分之一',
            'returnType' => 'amount',
            'visibility' => 'personal',
            'expression' => uqMetaExpression('5'),
        ]);
    });
    $metricCode = uqMetaCode(function () use ($customFields, $manager): void {
        $customFields->save($manager, [
            'pageCode' => 'member_list',
            'name' => '账户余额',
            'returnType' => 'amount',
            'visibility' => 'personal',
            'expression' => uqMetaExpression('5'),
        ]);
    });
    $systemFieldNameCode = uqMetaCode(function () use ($customFields, $manager): void {
        $customFields->save($manager, [
            'pageCode' => 'member_list',
            'name' => '会员姓名',
            'returnType' => 'text',
            'visibility' => 'personal',
            'expression' => ['type' => 'field', 'fieldKey' => 'member_name'],
        ]);
    });
    ok(
        '个人字段可跨账号同名，同账号归一重名、系统字段重名和系统指标冒名被拒绝',
        ($sameNameOtherAccount['key'] ?? '') !== ''
            && $duplicateCode === 'UNIFIED_QUERY_FIELD_NAME_DUPLICATE'
            && $systemFieldNameCode === 'UNIFIED_QUERY_FIELD_NAME_DUPLICATE'
            && $metricCode === 'UNIFIED_QUERY_SYSTEM_METRIC_RESERVED',
        json_encode(
            [$duplicateCode, $systemFieldNameCode, $metricCode, $sameNameOtherAccount],
            JSON_UNESCAPED_UNICODE
        ),
        'UQ-VER-01'
    );

    $updated = $customFields->save($manager, [
        'pageCode' => 'member_list',
        'fieldKey' => $personalKey,
        'expectedVersion' => 1,
        'name' => '个人余额四分之一',
        'returnType' => 'amount',
        'visibility' => 'personal',
        'expression' => uqMetaExpression('4'),
    ]);
    $staleCode = uqMetaCode(function () use ($customFields, $manager, $personalKey): void {
        $customFields->save($manager, [
            'pageCode' => 'member_list',
            'fieldKey' => $personalKey,
            'expectedVersion' => 1,
            'name' => '过期覆盖',
            'returnType' => 'amount',
            'visibility' => 'personal',
            'expression' => uqMetaExpression('9'),
        ]);
    });
    $history = $customFields->history($manager, 'member_list', $personalKey);
    $versionRows = Db::name('unified_query_custom_field_version')
        ->where('field_key', $personalKey)
        ->order('version asc')
        ->select()
        ->toArray();
    ok(
        '字段稳定 key 不变、版本追加且过期编辑冲突',
        ($updated['key'] ?? '') === $personalKey
            && (int)($updated['version'] ?? 0) === 2
            && $staleCode === 'UNIFIED_QUERY_VERSION_CONFLICT'
            && array_column($history, 'version') === [2, 1]
            && count($versionRows) === 2
            && ($versionRows[0]['expression_hash'] ?? '') !== ($versionRows[1]['expression_hash'] ?? ''),
        json_encode(compact('updated', 'staleCode', 'history'), JSON_UNESCAPED_UNICODE),
        'UQ-VER-02'
    );

    uqMetaSection('field aliases');
    $aliasSaved = $aliases->save($manager, [
        'pageCode' => 'member_list',
        'expectedAliasVersion' => 1,
        'aliases' => [
            'phone' => '联系电话',
            $personalKey => '四分之一余额',
        ],
    ]);
    $aliasRoundTrip = $aliases->aliases($manager, 'member_list');
    $staleAliasCode = uqMetaCode(function () use ($aliases, $manager): void {
        $aliases->save($manager, [
            'pageCode' => 'member_list',
            'expectedAliasVersion' => 1,
            'aliases' => ['phone' => '旧窗口号码'],
        ]);
    });
    $duplicateAliasCode = uqMetaCode(function () use ($aliases, $manager, $aliasSaved): void {
        $aliases->save($manager, [
            'pageCode' => 'member_list',
            'expectedAliasVersion' => (int)$aliasSaved['aliasVersion'],
            'aliases' => ['phone' => '会员姓名'],
        ]);
    });
    ok(
        '字段改名只改显示映射并受版本、重名门禁保护',
        (int)($aliasSaved['aliasVersion'] ?? 0) === 2
            && ($aliasRoundTrip['phone'] ?? '') === '联系电话'
            && ($aliasRoundTrip[$personalKey] ?? '') === '四分之一余额'
            && $staleAliasCode === 'UNIFIED_QUERY_ALIAS_VERSION_CONFLICT'
            && $duplicateAliasCode === 'UNIFIED_QUERY_ALIAS_DUPLICATE',
        json_encode(compact(
            'aliasSaved',
            'aliasRoundTrip',
            'staleAliasCode',
            'duplicateAliasCode'
        ), JSON_UNESCAPED_UNICODE),
        'UQ-ALIAS-01'
    );

    uqMetaSection('saved query version references');
    $settings = [
        'visibleFields' => ['member_name', 'phone', $personalKey],
        'quickFields' => ['phone'],
        'filters' => [[
            'field' => $personalKey,
            'operator' => 'gte',
            'value' => '1.00',
        ]],
        'filterRelation' => 'all',
        'sorts' => [['field' => $personalKey, 'direction' => 'desc']],
        'groupBy' => [],
        'summaries' => [['field' => $personalKey, 'aggregation' => 'sum']],
        'queryCutoffDate' => '2026-07-28',
        'schemaVersion' => $registry->schemaVersion(),
        'customFieldVersions' => [$personalKey => 999],
    ];
    $manager['trusted_custom_field_versions'] = [$personalKey => 1];
    $savedPreference = $preferences->save($manager, [
        'pageCode' => 'member_list',
        'settings' => $settings,
        'customFieldVersions' => [$personalKey => 999],
    ]);
    $loadedPreference = $preferences->load($manager, 'member_list');
    $reference = Db::name('unified_query_field_reference')
        ->where('consumer_type', 'saved_query')
        ->where('field_key', $personalKey)
        ->find();
    ok(
        '保存查询忽略客户端自报版本并冻结服务端可信版本',
        (int)($savedPreference['customFieldVersions'][$personalKey] ?? 0) === 1
            && (int)($loadedPreference['customFieldVersions'][$personalKey] ?? 0) === 1
            && (int)($reference['field_version'] ?? 0) === 1
            && ($reference['query_cutoff_date'] ?? '') === '2026-07-28',
        json_encode(compact('savedPreference', 'loadedPreference', 'reference'), JSON_UNESCAPED_UNICODE),
        'UQ-REF-01'
    );

    // 字段当前已是 v2，而保存查询固定 v1；必须提示升级但不可静默改口径。
    $loadedAfterUpgrade = $preferences->load($manager, 'member_list');
    $upgradeReference = $loadedAfterUpgrade['invalidReferences'][0] ?? [];
    ok(
        '字段升级后旧保存查询保持 v1 并明确提示可升级',
        (int)($loadedAfterUpgrade['customFieldVersions'][$personalKey] ?? 0) === 1
            && ($upgradeReference['fieldKey'] ?? '') === $personalKey
            && ($upgradeReference['status'] ?? '') === 'upgrade_available',
        json_encode($loadedAfterUpgrade, JSON_UNESCAPED_UNICODE),
        'UQ-REF-02'
    );

    uqMetaSection('structural custom field key collection');
    $literalKey = 'cf_' . str_repeat('a', 24);
    $literalSettings = [
        'visibleFields' => ['member_name', 'phone'],
        'quickFields' => ['phone'],
        'filters' => [[
            'field' => 'member_name',
            'operator' => 'contains',
            'value' => $literalKey,
        ]],
        'filterRelation' => 'all',
        'sorts' => [['field' => 'created_at', 'direction' => 'desc']],
        'groupBy' => [],
        'summaries' => [],
        'queryCutoffDate' => '2026-07-28',
        'schemaVersion' => $registry->schemaVersion(),
    ];
    $literalPreferenceError = uqMetaThrows(function () use (
        $preferences,
        $literalUser,
        $literalSettings
    ): void {
        $preferences->save($literalUser, [
            'pageCode' => 'member_list',
            'settings' => $literalSettings,
        ]);
    });
    $literalDefinitions = [];
    $literalDefinitionError = uqMetaThrows(function () use (
        $memberProvider,
        $literalUser,
        $literalKey,
        &$literalDefinitions
    ): void {
        $literalDefinitions = uqMetaMemberDefinitions($memberProvider, $literalUser, [
            'pageCode' => 'member_list',
            'keyword' => $literalKey,
            'filters' => [[
                'field' => 'member_name',
                'operator' => 'contains',
                'value' => $literalKey,
            ]],
            'sorts' => [['field' => 'member_id', 'direction' => 'asc']],
            'visibleFields' => ['member_name'],
        ]);
    });
    $literalExport = null;
    $literalExportError = uqMetaThrows(function () use (
        $exports,
        $literalUser,
        $literalKey,
        &$literalExport
    ): void {
        $literalExport = $exports->create($literalUser, [
            'pageCode' => 'member_list',
            'scope' => 'query',
            'queryCutoffDate' => '2026-07-28',
            'query' => [
                'page' => 1,
                'pageSize' => 20,
                'keyword' => $literalKey,
                'filters' => [[
                    'field' => 'member_name',
                    'operator' => 'contains',
                    'value' => $literalKey,
                ]],
            ],
            'fields' => ['phone'],
            'fileName' => '字面量字段键.xlsx',
        ]);
    });
    $literalTaskRow = $literalExport
        ? (array)Db::name('unified_query_export_task')
            ->where('task_no', (string)$literalExport['taskId'])
            ->find()
        : [];
    $literalPlan = $literalTaskRow
        ? UnifiedQueryJson::decode((string)$literalTaskRow['query_payload'])
        : [];
    $literalReferenceCount = $literalExport
        ? (int)Db::name('unified_query_field_reference')
            ->where('consumer_type', 'export_task')
            ->where('consumer_id', (string)$literalExport['taskId'])
            ->count()
        : -1;
    ok(
        '字段键只从结构化字段槽位提取，关键字和筛选值不被误认作字段',
        $literalPreferenceError === ''
            && $literalDefinitionError === ''
            && $literalDefinitions === []
            && $literalExportError === ''
            && is_array($literalExport)
            && (array)($literalPlan['custom_definitions'] ?? []) === []
            && $literalReferenceCount === 0,
        json_encode(compact(
            'literalKey',
            'literalPreferenceError',
            'literalDefinitionError',
            'literalDefinitions',
            'literalExportError',
            'literalExport',
            'literalPlan',
            'literalReferenceCount'
        ), JSON_UNESCAPED_UNICODE),
        'UQ-COL-01'
    );

    $quickFilterDefinitions = [];
    $quickFilterDefinitionError = uqMetaThrows(function () use (
        $memberProvider,
        $manager,
        $personalKey,
        &$quickFilterDefinitions
    ): void {
        $quickFilterDefinitions = uqMetaMemberDefinitions($memberProvider, $manager, [
            'pageCode' => 'member_list',
            'quickFilters' => [[
                'field' => $personalKey,
                'operator' => 'gte',
                'value' => '1.00',
            ]],
        ]);
    });
    ok(
        '快捷筛选中的自定义字段被结构化识别并装载其冻结定义',
        $quickFilterDefinitionError === ''
            && count($quickFilterDefinitions) === 1
            && (string)($quickFilterDefinitions[0]['field_key'] ?? '') === $personalKey
            && (int)($quickFilterDefinitions[0]['version'] ?? 0) === 1,
        json_encode(compact(
            'quickFilterDefinitionError',
            'quickFilterDefinitions',
            'personalKey'
        ), JSON_UNESCAPED_UNICODE),
        'UQ-COL-03'
    );

    $wideFilters = array_fill(0, 1200, [
        'field' => 'member_name',
        'operator' => 'contains',
        'value' => '宽条件',
    ]);
    $wideFilterCount = count($wideFilters);
    $wideCollectorCode = uqMetaCode(function () use (
        $preferences,
        $literalUser,
        $wideFilters,
        $registry
    ): void {
        $preferences->save($literalUser, [
            'pageCode' => 'member_list',
            'settings' => [
                'visibleFields' => ['member_name'],
                'quickFields' => [],
                'filters' => $wideFilters,
                'filterRelation' => 'all',
                'sorts' => [],
                'groupBy' => [],
                'summaries' => [],
                'queryCutoffDate' => '2026-07-28',
                'schemaVersion' => $registry->schemaVersion(),
            ],
        ]);
    });
    $deepQuerySettings = [];
    $deepCursor =& $deepQuerySettings;
    for ($depth = 0; $depth < 6; $depth++) {
        $deepCursor['querySettings'] = [];
        $deepCursor =& $deepCursor['querySettings'];
    }
    unset($deepCursor);
    $deepCollectorCode = uqMetaCode(function () use (
        $memberProvider,
        $literalUser,
        $deepQuerySettings
    ): void {
        uqMetaMemberDefinitions($memberProvider, $literalUser, [
            'pageCode' => 'member_list',
            'querySettings' => $deepQuerySettings,
        ]);
    });
    ok(
        '字段键提取在遍历前限制宽度和嵌套查询设置，不递归扫描任意值',
        $wideCollectorCode === 'UNIFIED_QUERY_QUERY_TOO_COMPLEX'
            && $deepCollectorCode === 'UNIFIED_QUERY_QUERY_TOO_COMPLEX',
        json_encode(compact(
            'wideCollectorCode',
            'deepCollectorCode',
            'wideFilterCount'
        ), JSON_UNESCAPED_UNICODE),
        'UQ-COL-02'
    );

    uqMetaSection('export snapshot and leases');
    $exportCreated = $exports->create($manager, [
        'pageCode' => 'member_list',
        'scope' => 'page',
        'queryCutoffDate' => '2026-07-28',
        'query' => [
            'pageCode' => 'member_list',
            'page' => 3,
            'pageSize' => 2,
            'keyword' => '林',
            'queryCutoffDate' => '2026-07-28',
            'topFilters' => [['field' => 'member_level', 'operator' => 'eq', 'value' => '金卡']],
            'querySettings' => [
                'filters' => [['field' => $personalKey, 'operator' => 'gte', 'value' => '1.00']],
                'filterRelation' => 'all',
                'sorts' => [['field' => $personalKey, 'direction' => 'desc']],
                'groupBy' => [],
                'summaries' => [['field' => $personalKey, 'aggregation' => 'sum']],
                'schemaVersion' => $registry->schemaVersion(),
            ],
            'fieldVersions' => [$personalKey => 999],
        ],
        'fields' => ['phone', $personalKey],
        'includeSummary' => true,
        'fileName' => '../会员=导出.xlsx',
    ]);
    $taskNo = (string)$exportCreated['taskId'];
    $taskRow = Db::name('unified_query_export_task')->where('task_no', $taskNo)->find();
    $plan = UnifiedQueryJson::decode((string)$taskRow['query_payload']);
    $headers = UnifiedQueryJson::decode((string)$taskRow['field_snapshot']);
    $customPlan = $plan['custom_definitions'][0] ?? [];
    $customHeader = $headers[1] ?? [];
    ok(
        '导出任务冻结页码、搜索、字段版本、别名与截止日期',
        ($plan['pagination'] ?? []) === ['limit' => 2, 'page' => 3]
            && count($plan['keyword_filters'] ?? []) === 3
            && (int)($customPlan['version'] ?? 0) === 1
            && (int)($customHeader['version'] ?? 0) === 1
            && ($customHeader['label'] ?? '') === '四分之一余额'
            && ($taskRow['query_cutoff_date'] ?? '') === '2026-07-28'
            && strpos((string)$taskRow['file_name'], '..') === false,
        json_encode(compact('plan', 'headers', 'taskRow'), JSON_UNESCAPED_UNICODE),
        'UQ-EXP-01'
    );

    $planOnlyField = $customFields->save($manager, [
        'pageCode' => 'member_list',
        'name' => '仅计划筛选分组状态',
        'returnType' => 'text',
        'visibility' => 'personal',
        'expression' => ['type' => 'field', 'fieldKey' => 'member_status'],
    ]);
    $planOnlyKey = (string)($planOnlyField['key'] ?? '');
    $planOnlyExport = $exports->create($manager, [
        'pageCode' => 'member_list',
        'scope' => 'page',
        'queryCutoffDate' => '2026-07-28',
        'query' => [
            'pageCode' => 'member_list',
            'page' => 1,
            'pageSize' => 2,
            'queryCutoffDate' => '2026-07-28',
            'filters' => [[
                'field' => $planOnlyKey,
                'operator' => 'eq',
                'value' => '正常',
            ]],
            'sorts' => [['field' => $planOnlyKey, 'direction' => 'asc']],
            'groupBy' => [$planOnlyKey],
        ],
        // 自定义字段只参与冻结计划，导出表头仍只有系统 phone。
        'fields' => ['phone'],
        'includeSummary' => false,
        'fileName' => '计划引用保护.xlsx',
    ]);
    $planOnlyTaskNo = (string)($planOnlyExport['taskId'] ?? '');
    $planOnlyTask = Db::name('unified_query_export_task')
        ->where('task_no', $planOnlyTaskNo)
        ->find();
    $planOnlyHeaders = UnifiedQueryJson::decode((string)($planOnlyTask['field_snapshot'] ?? '[]'));
    $planOnlyReferenceBefore = Db::name('unified_query_field_reference')
        ->where('consumer_type', 'export_task')
        ->where('consumer_id', $planOnlyTaskNo)
        ->where('field_key', $planOnlyKey)
        ->find();
    $planOnlyArchiveCode = uqMetaCode(function () use (
        $customFields,
        $manager,
        $planOnlyKey
    ): void {
        $customFields->archive($manager, [
            'fieldKey' => $planOnlyKey,
            'expectedVersion' => 1,
        ]);
    });
    $planOnlyInactive = $customFields->changeStatus($manager, [
        'fieldKey' => $planOnlyKey,
        'expectedVersion' => 1,
        'status' => 'inactive',
    ]);
    $planOnlyBlockedTask = Db::name('unified_query_export_task')
        ->where('task_no', $planOnlyTaskNo)
        ->find();
    $planOnlyReferenceAfter = Db::name('unified_query_field_reference')
        ->where('consumer_type', 'export_task')
        ->where('consumer_id', $planOnlyTaskNo)
        ->where('field_key', $planOnlyKey)
        ->find();
    $planOnlyRestored = $customFields->changeStatus($manager, [
        'fieldKey' => $planOnlyKey,
        'expectedVersion' => (int)($planOnlyInactive['version'] ?? 0),
        'status' => 'active',
    ]);
    ok(
        '仅用于筛选/排序/分组的自定义字段也冻结引用：归档受保护，停用会阻断 pending 导出',
        array_column($planOnlyHeaders, 'key') === ['phone']
            && (string)($planOnlyReferenceBefore['status'] ?? '') === 'active'
            && (int)($planOnlyReferenceBefore['field_version'] ?? 0) === 1
            && $planOnlyArchiveCode === 'UNIFIED_QUERY_FIELD_STILL_REFERENCED'
            && (string)($planOnlyBlockedTask['status'] ?? '') === 'blocked'
            && (string)($planOnlyReferenceAfter['status'] ?? '') === 'released'
            && (string)($planOnlyRestored['status'] ?? '') === 'active',
        json_encode(compact(
            'planOnlyTaskNo',
            'planOnlyHeaders',
            'planOnlyReferenceBefore',
            'planOnlyArchiveCode',
            'planOnlyInactive',
            'planOnlyBlockedTask',
            'planOnlyReferenceAfter',
            'planOnlyRestored'
        ), JSON_UNESCAPED_UNICODE),
        'UQ-EXP-04'
    );

    $wideRegistry = new UnifiedQueryPageRegistry($metricDefinitions);
    $wideFields = [];
    foreach (range(1, 26) as $index) {
        $wideFields[] = UnifiedQueryPageRegistry::field(
            'wide_field_' . $index,
            '宽导出字段' . $index,
            'text',
            true,
            false
        );
    }
    $wideRegistry->registerPage('wide_export_test', '宽导出测试', $wideFields, 'wide_row_id');
    $wideRegistry->freeze();
    $wideValidator = new StructuredExpressionValidator($wideRegistry);
    $wideExecution = new UnifiedQueryExecutionServices(
        $wideRegistry,
        $wideValidator,
        new StructuredExpressionEvaluator()
    );
    $wideCustomFields = new UnifiedQueryCustomFieldServices(
        $wideRegistry,
        $wideValidator,
        $access,
        $references
    );
    $wideAliases = new UnifiedQueryFieldAliasServices($wideRegistry, $wideCustomFields, $access);
    $widePreferences = new UnifiedQueryPreferenceServices(
        $wideRegistry,
        $wideCustomFields,
        $wideExecution,
        $access,
        $references
    );
    $wideExports = new UnifiedQueryExportTaskServices(
        $wideRegistry,
        $wideCustomFields,
        $wideAliases,
        $references,
        $wideExecution,
        $widePreferences,
        $access
    );
    $wideTaskCountBefore = (int)Db::name('unified_query_export_task')->count();
    $wideFieldKeys = array_column($wideFields, 'key');
    $wideExportCode = uqMetaCode(function () use (
        $wideExports,
        $manager,
        $wideFieldKeys
    ): void {
        $wideExports->create($manager, [
            'pageCode' => 'wide_export_test',
            'scope' => 'query',
            'queryCutoffDate' => '2026-07-28',
            'query' => [
                'pageCode' => 'wide_export_test',
                'page' => 1,
                'pageSize' => 20,
                'queryCutoffDate' => '2026-07-28',
            ],
            'fields' => $wideFieldKeys,
            'includeSummary' => true,
            'fileName' => '创建期单元格上限.xlsx',
        ]);
    });
    ok(
        '创建导出按冻结 scope 最大行数、字段快照、表头和合计行预检单元格预算',
        $wideExportCode === 'UNIFIED_QUERY_EXPORT_CELL_LIMIT_EXCEEDED'
            && count($wideFieldKeys)
                * (UnifiedQueryExportTaskServices::MAX_EXPORT_ROWS + 2)
                > UnifiedQueryExportTaskServices::MAX_EXPORT_CELLS
            && (int)Db::name('unified_query_export_task')->count() === $wideTaskCountBefore,
        json_encode(compact(
            'wideExportCode',
            'wideTaskCountBefore',
            'wideFieldKeys'
        ), JSON_UNESCAPED_UNICODE),
        'UQ-EXP-05'
    );

    $claimedFirst = $exports->claim($manager, $taskNo);
    $firstLease = (string)$claimedFirst['workerToken'];
    Db::name('unified_query_export_task')->where('task_no', $taskNo)->update([
        'lease_expires_at' => time() - 1,
    ]);
    $claimedSecond = $exports->claim($manager, $taskNo);
    $secondLease = (string)$claimedSecond['workerToken'];
    $lateComplete = uqMetaThrows(function () use ($exports, $taskNo, $firstLease): void {
        $exports->complete('0', $taskNo, 99, 'unified-query-exports/late.xlsx', time() + 3600, $firstLease);
    });
    $lateFail = uqMetaThrows(function () use ($exports, $taskNo, $firstLease): void {
        $exports->fail('0', $taskNo, 'late worker', $firstLease);
    });
    $exports->complete(
        '0',
        $taskNo,
        2,
        'unified-query-exports/0/101/' . $taskNo . '.xlsx',
        time() + 3600,
        $secondLease
    );
    $completed = $exports->findForAccount($manager, $taskNo);
    $storageKey = $exports->resolveDownload($manager, $taskNo);
    $downloadDescriptor = $exports->resolveDownloadDescriptor($manager, $taskNo);
    ok(
        '过期任务可接管且旧 worker 的完成/失败均被 fencing',
        $firstLease !== ''
            && $secondLease !== ''
            && $firstLease !== $secondLease
            && strpos($lateComplete, 'RuntimeException:') === 0
            && strpos($lateFail, 'RuntimeException:') === 0
            && ($completed['status'] ?? '') === 'succeeded'
            && (int)($completed['rowCount'] ?? 0) === 2
            && $storageKey === 'unified-query-exports/0/101/' . $taskNo . '.xlsx'
            && ($downloadDescriptor['storageKey'] ?? '') === $storageKey
            && ($downloadDescriptor['fileName'] ?? '') === (string)$taskRow['file_name']
            && strpos((string)($downloadDescriptor['fileName'] ?? ''), '..') === false,
        json_encode(compact(
            'firstLease',
            'secondLease',
            'lateComplete',
            'lateFail',
            'completed',
            'storageKey',
            'downloadDescriptor'
        ), JSON_UNESCAPED_UNICODE),
        'UQ-EXP-02'
    );

    $revoked = $manager;
    $revoked['permissions'] = [UnifiedQueryAccessPolicy::PAGE_POLICY];
    $revokedDownloadCode = uqMetaCode(function () use ($exports, $revoked, $taskNo): void {
        $exports->resolveDownload($revoked, $taskNo);
    });
    $crossAccountPollCode = uqMetaCode(function () use ($exports, $sameStoreUser, $taskNo): void {
        $exports->findForAccount($sameStoreUser, $taskNo);
    });
    $invalidStorage = uqMetaThrows(function () use ($exports): void {
        $exports->complete('0', 'missing', 1, '../escape.xlsx', time() + 60, 'fake');
    });
    ok(
        '轮询/下载按账号与当前权限重验且对象键限制在运行时目录',
        $revokedDownloadCode === 'UNIFIED_QUERY_EXPORT_FORBIDDEN'
            && in_array($crossAccountPollCode, [
                'UNIFIED_QUERY_EXPORT_FORBIDDEN',
                'UNIFIED_QUERY_EXPORT_NOT_FOUND',
            ], true)
            && strpos($invalidStorage, 'RuntimeException:') === 0,
        json_encode(compact('revokedDownloadCode', 'crossAccountPollCode', 'invalidStorage')),
        'UQ-EXP-03'
    );

    uqMetaSection('stop, restore and archive references');
    $pending = $exports->create($manager, [
        'pageCode' => 'member_list',
        'scope' => 'query',
        'queryCutoffDate' => '2026-07-28',
        'query' => [
            'page' => 1,
            'pageSize' => 20,
            'filters' => [['field' => $personalKey, 'operator' => 'gte', 'value' => '1.00']],
        ],
        'fields' => ['phone', $personalKey],
        'fileName' => '待停用.xlsx',
    ]);
    $inactive = $customFields->changeStatus($manager, [
        'fieldKey' => $personalKey,
        'expectedVersion' => 2,
        'status' => 'inactive',
    ]);
    $blockedTaskStatus = (string)Db::name('unified_query_export_task')
        ->where('task_no', (string)$pending['taskId'])
        ->value('status');
    $blockedExportReference = (array)Db::name('unified_query_field_reference')
        ->where('consumer_type', 'export_task')
        ->where('consumer_id', (string)$pending['taskId'])
        ->where('field_key', $personalKey)
        ->find();
    $blockedTaskSnapshot = (array)Db::name('unified_query_export_task')
        ->where('task_no', (string)$pending['taskId'])
        ->find();
    $blockedFieldSnapshot = $blockedTaskSnapshot
        ? UnifiedQueryJson::decode((string)$blockedTaskSnapshot['field_snapshot'])
        : [];
    $blockedQueryPlan = $blockedTaskSnapshot
        ? UnifiedQueryJson::decode((string)$blockedTaskSnapshot['query_payload'])
        : [];
    $inactiveDefinitionCode = uqMetaCode(function () use ($customFields, $manager, $personalKey): void {
        $customFields->versionDefinition($manager, 'member_list', $personalKey, 1);
    });
    $archiveBlockedCode = uqMetaCode(function () use ($customFields, $manager, $personalKey, $inactive): void {
        $customFields->archive($manager, [
            'fieldKey' => $personalKey,
            'expectedVersion' => (int)$inactive['version'],
        ]);
    });
    $restored = $customFields->changeStatus($manager, [
        'fieldKey' => $personalKey,
        'expectedVersion' => (int)$inactive['version'],
        'status' => 'active',
    ]);
    $restoredPreference = $preferences->load($manager, 'member_list');
    ok(
        '停用阻断 pending 导出与字段执行，终止导出自动释放引用而已保存查询仍阻断归档',
        $blockedTaskStatus === 'blocked'
            && ($blockedExportReference['status'] ?? '') === 'released'
            && strpos((string)($blockedExportReference['invalid_reason'] ?? ''), '冻结快照已保留') !== false
            && (int)($blockedExportReference['field_version'] ?? 0) === 1
            && ($blockedFieldSnapshot[1]['key'] ?? '') === $personalKey
            && (int)($blockedFieldSnapshot[1]['version'] ?? 0) === 1
            && (int)(($blockedQueryPlan['custom_definitions'][0]['version'] ?? 0)) === 1
            && $inactiveDefinitionCode === 'UNIFIED_QUERY_CUSTOM_FIELD_UNAVAILABLE'
            && $archiveBlockedCode === 'UNIFIED_QUERY_FIELD_STILL_REFERENCED'
            && ($restored['status'] ?? '') === 'active'
            && ($restoredPreference['invalidReferences'][0]['status'] ?? '') === 'upgrade_available',
        json_encode(compact(
            'blockedTaskStatus',
            'blockedExportReference',
            'blockedTaskSnapshot',
            'blockedFieldSnapshot',
            'blockedQueryPlan',
            'inactiveDefinitionCode',
            'archiveBlockedCode',
            'restored',
            'restoredPreference'
        ), JSON_UNESCAPED_UNICODE),
        'UQ-REF-03'
    );

    $manager['query_preference_version'] = 2;
    $preferences->save($manager, [
        'pageCode' => 'member_list',
        'settings' => [
            'visibleFields' => ['member_name', 'phone'],
            'quickFields' => ['phone'],
            'filters' => [],
            'filterRelation' => 'all',
            'sorts' => [['field' => 'created_at', 'direction' => 'desc']],
            'groupBy' => [],
            'summaries' => [],
            'queryCutoffDate' => '2026-07-28',
            'schemaVersion' => $registry->schemaVersion(),
        ],
    ]);
    $archived = $customFields->archive($manager, [
        'fieldKey' => $personalKey,
        'expectedVersion' => (int)$restored['version'],
    ]);
    $replacement = $customFields->save($manager, [
        'pageCode' => 'member_list',
        'name' => '个人余额四分之一',
        'returnType' => 'amount',
        'visibility' => 'personal',
        'expression' => uqMetaExpression('4'),
    ]);
    $releasedExportReference = (array)Db::name('unified_query_field_reference')
        ->where('consumer_type', 'export_task')
        ->where('consumer_id', (string)$pending['taskId'])
        ->where('field_key', $personalKey)
        ->find();
    $persistedBlockedTask = (array)Db::name('unified_query_export_task')
        ->where('task_no', (string)$pending['taskId'])
        ->find();
    ok(
        '移除保存查询后无需人工释放任务引用即可归档，终止任务仍保留冻结历史',
        ($archived['status'] ?? '') === 'archived'
            && ($replacement['key'] ?? '') !== ''
            && ($replacement['key'] ?? '') !== $personalKey
            && ($releasedExportReference['status'] ?? '') === 'released'
            && strpos((string)($releasedExportReference['invalid_reason'] ?? ''), '冻结快照已保留') !== false
            && ($persistedBlockedTask['status'] ?? '') === 'blocked'
            && ($persistedBlockedTask['error_reason'] ?? '') !== ''
            && (int)Db::name('unified_query_custom_field_version')
                ->where('field_key', $personalKey)
                ->count() === 5,
        json_encode(compact(
            'archived',
            'replacement',
            'releasedExportReference',
            'persistedBlockedTask'
        ), JSON_UNESCAPED_UNICODE),
        'UQ-REF-04'
    );

    uqMetaSection('custom field stop/export registration serialization');
    $raceShared = $customFields->save($manager, [
        'pageCode' => 'member_list',
        'name' => '并发导出筛选字段',
        'returnType' => 'amount',
        'visibility' => 'shared',
        'shareScope' => 'store',
        'scopeId' => '8',
        'expression' => uqMetaExpression('2'),
    ]);
    $raceKey = (string)($raceShared['key'] ?? '');
    // B 先从 active 字段建立冻结计划；A 随后停用时，必须看到该引用并阻断 pending
    // 任务。这里刻意不把字段选作导出列，覆盖筛选/排序等非列引用也需要冻结的分支。
    $racePending = $exports->create($sharedExporter, [
        'pageCode' => 'member_list',
        'scope' => 'query',
        'queryCutoffDate' => '2026-07-28',
        'query' => [
            'page' => 1,
            'pageSize' => 20,
            'filters' => [['field' => $raceKey, 'operator' => 'gte', 'value' => '1.00']],
        ],
        'fields' => ['phone'],
        'fileName' => '并发先建导出.xlsx',
    ]);
    $raceReferenceBeforeStop = (array)Db::name('unified_query_field_reference')
        ->where('consumer_type', 'export_task')
        ->where('consumer_id', (string)$racePending['taskId'])
        ->where('field_key', $raceKey)
        ->find();
    $raceStopped = $customFields->changeStatus($manager, [
        'fieldKey' => $raceKey,
        'expectedVersion' => 1,
        'status' => 'inactive',
    ]);
    $raceTaskAfterStop = (array)Db::name('unified_query_export_task')
        ->where('task_no', (string)$racePending['taskId'])
        ->find();
    $raceReferenceAfterStop = (array)Db::name('unified_query_field_reference')
        ->where('consumer_type', 'export_task')
        ->where('consumer_id', (string)$racePending['taskId'])
        ->where('field_key', $raceKey)
        ->find();
    $racePlan = UnifiedQueryJson::decode((string)($raceTaskAfterStop['query_payload'] ?? ''));

    $afterStopShared = $customFields->save($manager, [
        'pageCode' => 'member_list',
        'name' => '停用先发生导出字段',
        'returnType' => 'amount',
        'visibility' => 'shared',
        'shareScope' => 'store',
        'scopeId' => '8',
        'expression' => uqMetaExpression('5'),
    ]);
    $afterStopKey = (string)($afterStopShared['key'] ?? '');
    // 先读取版本定义模拟 B 已完成计划前置校验；A 取得同一字段锁并停用后，B 到达
    // register 必须重新锁行并拒绝，而不是写入一个已停用字段的 export_task 引用。
    $prevalidatedAfterStop = $customFields->versionDefinition(
        $sharedExporter,
        'member_list',
        $afterStopKey,
        1
    );
    $afterStop = $customFields->changeStatus($manager, [
        'fieldKey' => $afterStopKey,
        'expectedVersion' => 1,
        'status' => 'inactive',
    ]);
    $afterStopExportCode = uqMetaCode(function () use (
        $exports,
        $sharedExporter,
        $afterStopKey
    ): void {
        $exports->create($sharedExporter, [
            'pageCode' => 'member_list',
            'scope' => 'query',
            'queryCutoffDate' => '2026-07-28',
            'query' => [
                'page' => 1,
                'pageSize' => 20,
                'filters' => [[
                    'field' => $afterStopKey,
                    'operator' => 'gte',
                    'value' => '1.00',
                ]],
            ],
            'fields' => ['phone'],
            'fileName' => '并发后停用.xlsx',
        ]);
    });
    $afterStopRegisterCode = uqMetaCode(function () use (
        $references,
        $afterStopKey
    ): void {
        $references->register(
            '0',
            'export_task',
            'uqe_' . str_repeat('b', 32),
            [$afterStopKey => 1],
            '2026-07-28'
        );
    });
    $afterStopReferenceCount = (int)Db::name('unified_query_field_reference')
        ->where('consumer_type', 'export_task')
        ->where('consumer_id', 'uqe_' . str_repeat('b', 32))
        ->where('field_key', $afterStopKey)
        ->count();
    ok(
        '停用与两账号导出注册按字段锁串行：先导出则被阻断，先停用则新引用被拒绝',
        ($raceReferenceBeforeStop['status'] ?? '') === 'active'
            && ($raceStopped['status'] ?? '') === 'inactive'
            && ($raceTaskAfterStop['status'] ?? '') === 'blocked'
            && ($raceReferenceAfterStop['status'] ?? '') === 'released'
            && (int)(($racePlan['custom_definitions'][0]['version'] ?? 0)) === 1
            && ($prevalidatedAfterStop['field_key'] ?? '') === $afterStopKey
            && ($afterStop['status'] ?? '') === 'inactive'
            && $afterStopExportCode === 'UNIFIED_QUERY_CUSTOM_FIELD_UNAVAILABLE'
            && $afterStopRegisterCode === 'UNIFIED_QUERY_CUSTOM_FIELD_UNAVAILABLE'
            && $afterStopReferenceCount === 0,
        json_encode(compact(
            'raceShared',
            'racePending',
            'raceReferenceBeforeStop',
            'raceStopped',
            'raceTaskAfterStop',
            'raceReferenceAfterStop',
            'racePlan',
            'prevalidatedAfterStop',
            'afterStop',
            'afterStopExportCode',
            'afterStopRegisterCode',
            'afterStopReferenceCount'
        ), JSON_UNESCAPED_UNICODE),
        'UQ-RACE-01'
    );
} catch (\Throwable $throwable) {
    ok(
        '统一查询元数据集成未发生未捕获异常',
        false,
        get_class($throwable) . ': ' . $throwable->getMessage() . "\n" . $throwable->getTraceAsString()
    );
}

finish('unified-query-metadata-integration');
