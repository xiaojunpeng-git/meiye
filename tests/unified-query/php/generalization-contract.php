<?php

/**
 * 统一查询多页面泛化合同。
 *
 * 使用合成库存页面与 provider 验证框架扩展点，不依赖真实库存源码或库存表。
 */
require '/tests/lib/boot-env.php';
require '/var/www/html/vendor/autoload.php';
require '/tests/lib/_lib.php';

use app\services\query\StructuredExpressionEvaluator;
use app\services\query\StructuredExpressionValidator;
use app\services\query\UnifiedQueryAccessPolicy;
use app\services\query\UnifiedQueryCommandCoordinator;
use app\services\query\UnifiedQueryContextFactory;
use app\services\query\UnifiedQueryCustomFieldServices;
use app\services\query\UnifiedQueryException;
use app\services\query\UnifiedQueryExecutionServices;
use app\services\query\UnifiedQueryFieldAliasServices;
use app\services\query\UnifiedQueryFieldReferenceServices;
use app\services\query\UnifiedQueryExportTaskServices;
use app\services\query\UnifiedQueryExportWorkerServices;
use app\services\query\UnifiedQueryJson;
use app\services\query\UnifiedQueryPageRegistrar;
use app\services\query\UnifiedQueryPagePermissionResolver;
use app\services\query\UnifiedQueryPagePermissionResolverRegistry;
use app\services\query\UnifiedQueryPageRegistry;
use app\services\query\UnifiedQueryPreferenceServices;
use app\services\query\UnifiedQueryProvider;
use app\services\query\UnifiedQueryProviderRegistry;
use app\services\query\UnifiedQueryRuntime;
use app\services\query\UnifiedQueryWorkerContextResolver;
use app\services\query\UnifiedQueryWorkerContextResolverRegistry;
use think\facade\Db;

c1aBootThinkApp('/var/www/html/');
date_default_timezone_set('Asia/Shanghai');

function uqGenFailure(callable $callback): array
{
    try {
        $callback();
    } catch (UnifiedQueryException $exception) {
        return [
            'class' => get_class($exception),
            'code' => $exception->getErrorCode(),
            'message' => $exception->getMessage(),
        ];
    } catch (\Throwable $throwable) {
        return [
            'class' => get_class($throwable),
            'code' => '',
            'message' => $throwable->getMessage(),
        ];
    }
    return ['class' => '', 'code' => '', 'message' => ''];
}

function uqGenMetrics(): array
{
    return [
        ['code' => 'account_balance', 'name' => '账户余额', 'aliases' => ['会员余额']],
        ['code' => 'inventory_amount', 'name' => '库存金额', 'aliases' => []],
    ];
}

final class UqGenInventoryRegistrar implements UnifiedQueryPageRegistrar
{
    /** @var bool */
    public $registeredBeforeFreeze = false;

    public function register(UnifiedQueryPageRegistry $registry): void
    {
        $this->registeredBeforeFreeze = !$registry->isFrozen();
        $registry->registerPage('inventory_batch_stock', '批次库存', [
            UnifiedQueryPageRegistry::field('product_name', '商品名称', 'text', true, true),
            UnifiedQueryPageRegistry::field('batch_no', '批次号', 'text', true, true),
            UnifiedQueryPageRegistry::field('expiry_date', '到期日期', 'date', true, false),
            UnifiedQueryPageRegistry::field('remaining_quantity', '结存数量', 'decimal', true, false),
            UnifiedQueryPageRegistry::field(
                'unit_cost',
                '批次单位成本',
                'amount',
                true,
                false,
                [],
                'inventory.cost.read'
            ),
            UnifiedQueryPageRegistry::field(
                'cost_category',
                '成本类别',
                'text',
                true,
                false,
                [],
                'inventory.cost.read'
            ),
        ], 'batch_stock_id', [
            'keywordFields' => ['product_name', 'batch_no'],
            'requiredFeature' => 'inventory_batch_stock',
            'exportFeature' => 'inventory_batch_stock_export',
            'scopeDimensions' => ['location_ids'],
        ]);
    }
}

final class UqGenUnknownKeywordRegistrar implements UnifiedQueryPageRegistrar
{
    public function register(UnifiedQueryPageRegistry $registry): void
    {
        $registry->registerPage('invalid_keyword_page', '坏关键字页', [
            UnifiedQueryPageRegistry::field('product_name', '商品名称', 'text', true, true),
        ], 'row_id', [
            'keywordFields' => ['member_name'],
        ]);
    }
}

final class UqGenRecordingProvider implements UnifiedQueryProvider
{
    /** @var string */
    private $pageCode;

    /** @var int */
    public $queryCalls = 0;

    /** @var array<int,array> */
    public $frozenCalls = [];

    public function __construct(string $pageCode)
    {
        $this->pageCode = $pageCode;
    }

    public function pageCode(): string
    {
        return $this->pageCode;
    }

    public function query(array $context, array $payload): array
    {
        $this->queryCalls++;
        return [
            'pageCode' => $this->pageCode,
            'records' => [],
            'total' => 0,
        ];
    }

    public function executeFrozenPlan(
        array $context,
        array $plan,
        string $exportScope,
        array $fieldKeys
    ): array {
        $this->frozenCalls[] = compact('context', 'plan', 'exportScope', 'fieldKeys');
        $row = [];
        foreach ($fieldKeys as $fieldKey) {
            $row[(string)$fieldKey] = $this->pageCode . ':' . (string)$fieldKey;
        }
        return [
            'exportRows' => [$row],
            'summaries' => [],
        ];
    }
}

final class UqGenPermissionResolver implements UnifiedQueryPagePermissionResolver
{
    /** @var string */
    private $pageCode;

    /** @var array */
    private $authorization;

    /** @var array<int,array> */
    public $calls = [];

    public function __construct(string $pageCode, array $authorization)
    {
        $this->pageCode = $pageCode;
        $this->authorization = $authorization;
    }

    public function pageCode(): string
    {
        return $this->pageCode;
    }

    public function authorize(array $trustedContext, array $pageDefinition): array
    {
        $this->calls[] = compact('trustedContext', 'pageDefinition');
        return $this->authorization;
    }
}

final class UqGenWorkerContextResolver implements UnifiedQueryWorkerContextResolver
{
    /** @var string */
    private $pageCode;

    /** @var array */
    private $context;

    /** @var array<int,array> */
    public $calls = [];

    public function __construct(string $pageCode, array $context)
    {
        $this->pageCode = $pageCode;
        $this->context = $context;
    }

    public function pageCode(): string
    {
        return $this->pageCode;
    }

    public function resolve(array $authoritativeTask): array
    {
        $this->calls[] = $authoritativeTask;
        return $this->context;
    }
}

final class UqGenTaskServices extends UnifiedQueryExportTaskServices
{
    /** @var array<string,array> */
    private $claims;

    /** @var array<int,array> */
    public $claimCalls = [];

    /** @var array<int,array> */
    public $preflightCalls = [];

    /** @var array<int,array> */
    public $completeCalls = [];

    /** @var array<int,array> */
    public $failCalls = [];

    /** @var array<int,array> */
    public $failPendingCalls = [];

    /** @var array<int,array> */
    public $renewCalls = [];

    public function __construct(array $claims)
    {
        $this->claims = $claims;
    }

    public function claim(array $rawContext, string $taskNo): array
    {
        $this->claimCalls[] = compact('rawContext', 'taskNo');
        if (!isset($this->claims[$taskNo])) {
            throw new \RuntimeException('合成任务缺少 claim：' . $taskNo);
        }
        return $this->claims[$taskNo];
    }

    public function preflightFrozenExecution(
        array $rawContext,
        array $task,
        array $plan,
        array $fieldSnapshot
    ): void {
        $this->preflightCalls[] = compact(
            'rawContext',
            'task',
            'plan',
            'fieldSnapshot'
        );
    }

    public function complete(
        string $tenantId,
        string $taskNo,
        int $resultCount,
        string $storageKey,
        int $expiresAt,
        string $leaseToken
    ): void {
        $this->completeCalls[] = compact(
            'tenantId',
            'taskNo',
            'resultCount',
            'storageKey',
            'expiresAt',
            'leaseToken'
        );
    }

    public function fail(
        string $tenantId,
        string $taskNo,
        string $reason,
        string $leaseToken
    ): void {
        $this->failCalls[] = compact('tenantId', 'taskNo', 'reason', 'leaseToken');
    }

    public function failPending(string $tenantId, string $taskNo, string $reason): void
    {
        $this->failPendingCalls[] = compact('tenantId', 'taskNo', 'reason');
    }

    public function failExpiredRunning(string $tenantId, string $taskNo, string $reason): bool
    {
        return false;
    }

    public function renewLease(
        string $tenantId,
        string $taskNo,
        string $leaseToken,
        int $seconds = 300
    ): int {
        $this->renewCalls[] = compact('tenantId', 'taskNo', 'leaseToken', 'seconds');
        return time() + $seconds;
    }
}

final class UqGenWorker extends UnifiedQueryExportWorkerServices
{
    /** @var array<string,int> */
    public $writeCalls = [];

    public function __construct(
        UnifiedQueryExportTaskServices $tasks,
        UnifiedQueryProviderRegistry $providers,
        UnifiedQueryWorkerContextResolverRegistry $contextResolvers
    ) {
        parent::__construct($tasks, $providers, $contextResolvers);
    }

    protected function writeXlsx(
        string $taskNo,
        string $workerToken,
        array $fieldSnapshot,
        array $rows,
        array $summaries,
        $includeSummary = null,
        ?callable $heartbeat = null
    ): string {
        $this->writeCalls[$taskNo] = (int)($this->writeCalls[$taskNo] ?? 0) + 1;
        if ($heartbeat !== null) {
            $heartbeat(true);
        }
        return 'unified-query-exports/2026/07/' . $taskNo . '-' . $workerToken . '.xlsx';
    }
}

final class UqGenPreferenceServices extends UnifiedQueryPreferenceServices
{
    /** @var array<int,array{context:array,payload:array}> */
    public $saveCalls = [];

    public function __construct()
    {
    }

    public function save(array $context, array $payload): array
    {
        $this->saveCalls[] = compact('context', 'payload');
        return ['pageCode' => (string)($payload['pageCode'] ?? '')];
    }
}

function uqGenClaim(
    string $taskNo,
    string $pageCode,
    string $planPageCode,
    string $fieldKey,
    string $tokenCharacter
): array {
    return [
        'task_no' => $taskNo,
        'tenant_id' => 'tenant-uq-gen',
        'page_code' => $pageCode,
        'export_scope' => 'query',
        'query_payload' => UnifiedQueryJson::encode([
            'page_code' => $planPageCode,
            'query_cutoff_date' => '2026-07-28',
            'permission_must_be_injected_before_calculation' => true,
        ]),
        'field_snapshot' => UnifiedQueryJson::encode([[
            'key' => $fieldKey,
            'label' => $fieldKey,
            'type' => 'text',
        ]]),
        'workerToken' => str_repeat($tokenCharacter, 64),
        'effectiveDataScope' => [
            'visible_store_ids' => [8],
            'ancestor_organization_ids' => ['org-8'],
            'scope_dimensions' => $pageCode === 'inventory_batch_stock'
                ? ['location_ids' => ['location-8']]
                : [],
        ],
        'query_cutoff_date' => '2026-07-28',
        'data_as_of' => 1785258000,
        'include_summary' => 0,
    ];
}

function uqGenSeedTask(string $taskNo, string $pageCode): void
{
    Db::name(UnifiedQueryExportTaskServices::TABLE)->insert([
        'task_no' => $taskNo,
        'tenant_id' => 'tenant-uq-gen',
        'account_id' => 1,
        'operator_id' => 1,
        'origin_store_id' => 8,
        'origin_organization_id' => 'org-8',
        'page_code' => $pageCode,
        'export_scope' => 'query',
        'status' => 'pending',
        'query_payload' => '{}',
        'field_snapshot' => '[]',
        'alias_snapshot' => '{}',
        'permission_fingerprint' => hash('sha256', 'uq-gen'),
        'permission_version' => 'uq-gen-v1',
        'frozen_scope' => '{}',
        'query_cutoff_date' => '2026-07-28',
        'data_as_of' => 1785258000,
        'file_name' => $taskNo . '.xlsx',
        'created_at' => 1785258000,
        'updated_at' => 1785258000,
    ]);
}

function uqGenTrustedContext(
    string $pageCode,
    array $features = [],
    array $overrides = []
): array
{
    return array_merge([
        'tenant_id' => 'tenant-uq-gen',
        'account_id' => 101,
        'operator_id' => 101,
        'store_id' => 8,
        'organization_id' => '3',
        'visible_store_ids' => [8],
        'ancestor_organization_ids' => ['3'],
        'shareable_store_ids' => [8],
        'shareable_organization_ids' => ['3'],
        'permission_version' => 'uq-gen-permission-v1',
        'granted_features' => $features,
        'manage_shared_fields' => false,
        'share_tenant_fields' => false,
        'scope_dimensions' => $pageCode === 'inventory_batch_stock'
            ? ['location_ids' => ['location-8']]
            : [],
        'query_cutoff_date' => '2026-07-28',
        'data_as_of' => 1785258000,
    ], $overrides);
}

function uqGenWorkerContext(string $pageCode, array $overrides = []): array
{
    return array_merge([
        'tenant_id' => 'tenant-uq-gen',
        'account_id' => 1,
        'operator_id' => 1,
        'store_id' => 8,
        'organization_id' => 'org-8',
        'visible_store_ids' => [8],
        'ancestor_organization_ids' => ['org-8'],
        'permissions' => [
            UnifiedQueryAccessPolicy::PAGE_POLICY,
            UnifiedQueryAccessPolicy::EXPORT,
        ],
        'permission_version' => 'uq-gen-current-v1',
        'page_code' => $pageCode,
        'scope_dimensions' => $pageCode === 'inventory_batch_stock'
            ? ['location_ids' => ['location-8']]
            : [],
        'query_cutoff_date' => date('Y-m-d'),
        'data_as_of' => time(),
    ], $overrides);
}

function uqGenWorkerResolverRegistry(
    UnifiedQueryPageRegistry $pages,
    array $inventoryContext
): UnifiedQueryWorkerContextResolverRegistry {
    $resolvers = new UnifiedQueryWorkerContextResolverRegistry($pages);
    $resolvers->register(new UqGenWorkerContextResolver(
        'member_list',
        uqGenWorkerContext('member_list')
    ));
    $resolvers->register(new UqGenWorkerContextResolver(
        'inventory_batch_stock',
        $inventoryContext
    ));
    $resolvers->freeze();
    return $resolvers;
}

function uqGenResolvedContext(
    UnifiedQueryPageRegistry $pages,
    array $authorization,
    string $pageCode = 'inventory_batch_stock'
): array {
    $resolvers = new UnifiedQueryPagePermissionResolverRegistry($pages);
    $resolvers->register(new UqGenPermissionResolver($pageCode, $authorization));
    $resolvers->freeze();
    return (new UnifiedQueryContextFactory($pages, $resolvers))->make(
        uqGenTrustedContext($pageCode),
        ['pageCode' => $pageCode]
    );
}

function uqGenCommandCoordinator(
    UnifiedQueryPageRegistry $pages,
    UnifiedQueryPreferenceServices $preferences = null
): UnifiedQueryCommandCoordinator {
    if ($preferences === null) {
        $preferences = (new ReflectionClass(UnifiedQueryPreferenceServices::class))
            ->newInstanceWithoutConstructor();
    }
    return new UnifiedQueryCommandCoordinator(
        $pages,
        $preferences,
        (new ReflectionClass(UnifiedQueryFieldAliasServices::class))
            ->newInstanceWithoutConstructor(),
        (new ReflectionClass(UnifiedQueryCustomFieldServices::class))
            ->newInstanceWithoutConstructor(),
        (new ReflectionClass(UnifiedQueryExportTaskServices::class))
            ->newInstanceWithoutConstructor()
    );
}

function uqGenExportTasks(
    UnifiedQueryPageRegistry $pages
): UnifiedQueryExportTaskServices {
    return uqGenServiceGraph($pages)['exports'];
}

function uqGenServiceGraph(UnifiedQueryPageRegistry $pages): array
{
    $validator = new StructuredExpressionValidator($pages);
    $evaluator = new StructuredExpressionEvaluator();
    $access = new UnifiedQueryAccessPolicy();
    $references = new UnifiedQueryFieldReferenceServices();
    $customFields = new UnifiedQueryCustomFieldServices(
        $pages,
        $validator,
        $access,
        $references
    );
    $execution = new UnifiedQueryExecutionServices($pages, $validator, $evaluator);
    $preferences = new UnifiedQueryPreferenceServices(
        $pages,
        $customFields,
        $execution,
        $access,
        $references
    );
    $aliases = new UnifiedQueryFieldAliasServices($pages, $customFields, $access);
    $exports = new UnifiedQueryExportTaskServices(
        $pages,
        $customFields,
        $aliases,
        $references,
        $execution,
        $preferences,
        $access
    );
    return compact(
        'validator',
        'evaluator',
        'access',
        'references',
        'customFields',
        'execution',
        'preferences',
        'aliases',
        'exports'
    );
}

$taskNos = [
    'inventory' => 'uqe_' . str_repeat('1', 32),
    'member' => 'uqe_' . str_repeat('2', 32),
    'unknown' => 'uqe_' . str_repeat('3', 32),
    'mismatch' => 'uqe_' . str_repeat('4', 32),
    'cross_page' => 'uqe_' . str_repeat('5', 32),
    'context_invalid' => 'uqe_' . str_repeat('6', 32),
];
$dynamicTaskNos = [];
$dynamicFieldKeys = [];

try {
    $registrar = new UqGenInventoryRegistrar();
    $pages = UnifiedQueryPageRegistry::withDefaults(uqGenMetrics(), [$registrar]);
    $pageCodes = $pages->pageCodes();
    sort($pageCodes);
    $duplicatePage = uqGenFailure(function (): void {
        UnifiedQueryPageRegistry::withDefaults(uqGenMetrics(), [
            new UqGenInventoryRegistrar(),
            new UqGenInventoryRegistrar(),
        ]);
    });
    $latePage = uqGenFailure(function () use ($pages): void {
        (new UqGenInventoryRegistrar())->register($pages);
    });
    ok(
        '页面登记器在统一冻结前装载全部页面，重复和冻结后登记均拒绝',
        $registrar->registeredBeforeFreeze
            && $pages->isFrozen()
            && $pageCodes === ['inventory_batch_stock', 'member_list']
            && $duplicatePage['class'] === LogicException::class
            && $latePage['class'] === LogicException::class,
        json_encode(compact('pageCodes', 'duplicatePage', 'latePage'), JSON_UNESCAPED_UNICODE),
        'UQ-MULTI-01'
    );

    $validator = new StructuredExpressionValidator($pages);
    $execution = new UnifiedQueryExecutionServices(
        $pages,
        $validator,
        new StructuredExpressionEvaluator()
    );
    $inventoryKeyword = $execution->normalizeUiQuery(
        'inventory_batch_stock',
        ['keyword' => 'BATCH-001'],
        ['inventory.cost.read']
    );
    $memberKeyword = $execution->normalizeUiQuery(
        'member_list',
        ['keyword' => 'M001'],
        []
    );
    $inventoryKeywordFields = array_column($inventoryKeyword['keywordFilters'], 'field');
    $memberKeywordFields = array_column($memberKeyword['keywordFilters'], 'field');
    $crossPageField = uqGenFailure(function () use ($validator): void {
        $validator->validate('inventory_batch_stock', [
            'type' => 'field',
            'key' => 'member_name',
        ], 'text');
    });
    $unknownKeyword = uqGenFailure(function (): void {
        UnifiedQueryPageRegistry::withDefaults(uqGenMetrics(), [
            new UqGenUnknownKeywordRegistrar(),
        ]);
    });
    ok(
        '综合搜索字段由页面白名单提供且合成库存页不访问会员字段',
        $inventoryKeywordFields === ['product_name', 'batch_no']
            && $memberKeywordFields === ['member_name', 'phone', 'member_no']
            && !array_intersect($inventoryKeywordFields, $memberKeywordFields)
            && $crossPageField['code'] === 'UNIFIED_QUERY_FIELD_NOT_ALLOWED'
            && $unknownKeyword['class'] !== '',
        json_encode(compact(
            'inventoryKeywordFields',
            'memberKeywordFields',
            'crossPageField',
            'unknownKeyword'
        ), JSON_UNESCAPED_UNICODE),
        'UQ-MULTI-02'
    );

    $visibleCustomKey = 'cf_' . str_repeat('f', 24);
    $visibleCustomDefinition = [
        'field_key' => $visibleCustomKey,
        'name' => '商品名称副本',
        'return_type' => 'text',
        'page_code' => 'inventory_batch_stock',
        'version' => 1,
        'status' => 'active',
        'expression' => ['type' => 'field', 'key' => 'product_name'],
    ];
    $validVisibleFieldsPlan = $execution->validatedPlan(
        'inventory_batch_stock',
        [$visibleCustomDefinition],
        [
            'page' => 1,
            'limit' => 20,
            'visibleFields' => ['batch_no', $visibleCustomKey],
        ],
        [
            'permissions' => [],
            'query_cutoff_date' => '2026-07-28',
        ]
    );
    $invalidVisibleFields = [
        'unauthorized' => [
            'fields' => ['unit_cost'],
            'definitions' => [],
            'code' => 'UNIFIED_QUERY_FIELD_OPERATION_FORBIDDEN',
        ],
        'unknown' => [
            'fields' => ['unknown_inventory_field'],
            'definitions' => [],
            'code' => 'UNIFIED_QUERY_FIELD_OPERATION_FORBIDDEN',
        ],
        'not_displayable' => [
            'fields' => ['batch_stock_id'],
            'definitions' => [],
            'code' => 'UNIFIED_QUERY_FIELD_OPERATION_FORBIDDEN',
        ],
        'duplicate' => [
            'fields' => ['batch_no', 'batch_no'],
            'definitions' => [],
            'code' => 'UNIFIED_QUERY_VISIBLE_FIELDS_INVALID',
        ],
        'unfrozen_custom' => [
            'fields' => ['cf_' . str_repeat('e', 24)],
            'definitions' => [],
            'code' => 'UNIFIED_QUERY_FIELD_OPERATION_FORBIDDEN',
        ],
        'non_string' => [
            'fields' => [123],
            'definitions' => [],
            'code' => 'UNIFIED_QUERY_VISIBLE_FIELDS_INVALID',
        ],
    ];
    $invalidVisibleFieldFailures = [];
    foreach ($invalidVisibleFields as $case => $expectation) {
        $invalidVisibleFieldFailures[$case] = uqGenFailure(function () use (
            $execution,
            $expectation
        ): void {
            $execution->validatedPlan(
                'inventory_batch_stock',
                $expectation['definitions'],
                [
                    'page' => 1,
                    'limit' => 20,
                    'visibleFields' => $expectation['fields'],
                ],
                [
                    'permissions' => [],
                    'query_cutoff_date' => '2026-07-28',
                ]
            );
        });
    }
    $visibleFieldsPlanContractPassed = ($validVisibleFieldsPlan['visible_fields'] ?? null)
        === ['batch_no', $visibleCustomKey];
    foreach ($invalidVisibleFields as $case => $expectation) {
        if (($invalidVisibleFieldFailures[$case]['code'] ?? '') !== $expectation['code']) {
            $visibleFieldsPlanContractPassed = false;
            break;
        }
    }

    $memberProvider = new UqGenRecordingProvider('member_list');
    $inventoryProvider = new UqGenRecordingProvider('inventory_batch_stock');
    $incompleteProviders = new UnifiedQueryProviderRegistry($pages);
    $incompleteProviders->register($memberProvider);
    $missingProvider = uqGenFailure(function () use ($incompleteProviders): void {
        $incompleteProviders->resolve('inventory_batch_stock');
    });
    $incompleteFreeze = uqGenFailure(function () use ($incompleteProviders): void {
        $incompleteProviders->freeze();
    });

    $providers = new UnifiedQueryProviderRegistry($pages);
    $providers->register($memberProvider);
    $duplicateProvider = uqGenFailure(function () use ($providers): void {
        $providers->register(new UqGenRecordingProvider('member_list'));
    });
    $providers->register($inventoryProvider);
    $providers->freeze();
    $lateProvider = uqGenFailure(function () use ($providers): void {
        $providers->register(new UqGenRecordingProvider('inventory_batch_stock'));
    });
    $wrongPageProviders = new UnifiedQueryProviderRegistry($pages);
    $wrongPageProvider = uqGenFailure(function () use ($wrongPageProviders): void {
        $wrongPageProviders->register(new UqGenRecordingProvider('unknown_page'));
    });
    $providerPageCodes = $providers->pageCodes();
    sort($providerPageCodes);
    ok(
        'provider 缺失、重复、错页和冻结后登记均 fail-closed',
        $missingProvider['code'] === 'UNIFIED_QUERY_PROVIDER_NOT_AVAILABLE'
            && $incompleteFreeze['class'] === LogicException::class
            && $duplicateProvider['class'] === LogicException::class
            && $lateProvider['class'] === LogicException::class
            && $wrongPageProvider['code'] === 'UNIFIED_QUERY_PAGE_NOT_ALLOWED'
            && $providers->isFrozen()
            && $providerPageCodes === ['inventory_batch_stock', 'member_list']
            && $providers->resolve('member_list') === $memberProvider
            && $providers->resolve('inventory_batch_stock') === $inventoryProvider,
        json_encode(compact(
            'missingProvider',
            'incompleteFreeze',
            'duplicateProvider',
            'lateProvider',
            'wrongPageProvider',
            'providerPageCodes'
        ), JSON_UNESCAPED_UNICODE),
        'UQ-MULTI-03'
    );

    $inventoryPermissionContext = uqGenResolvedContext($pages, [
        'pageAllowed' => true,
        'exportAllowed' => true,
        'permissions' => ['inventory.cost.read'],
    ]);
    $inventoryPermissions = (array)($inventoryPermissionContext['permissions'] ?? []);
    $inventoryWithoutResolver = (new UnifiedQueryContextFactory($pages))->make(
        uqGenTrustedContext('inventory_batch_stock'),
        ['pageCode' => 'inventory_batch_stock']
    );
    $memberFeatureFallback = (new UnifiedQueryContextFactory($pages))->make(
        uqGenTrustedContext('member_list', ['cashier.v3.member']),
        ['pageCode' => 'member_list']
    );
    $inventoryWithoutResolverPermissions = (array)(
        $inventoryWithoutResolver['permissions'] ?? []
    );
    $memberFeaturePermissions = (array)($memberFeatureFallback['permissions'] ?? []);
    ok(
        '页面 resolver 只追加当前页字段权限且无 resolver 时按服务端 feature 失败关闭',
        in_array('inventory.cost.read', $inventoryPermissions, true)
            && in_array(UnifiedQueryAccessPolicy::PAGE_POLICY, $inventoryPermissions, true)
            && in_array(UnifiedQueryAccessPolicy::EXPORT, $inventoryPermissions, true)
            && !in_array(
                UnifiedQueryAccessPolicy::PAGE_POLICY,
                $inventoryWithoutResolverPermissions,
                true
            )
            && !in_array(
                UnifiedQueryAccessPolicy::EXPORT,
                $inventoryWithoutResolverPermissions,
                true
            )
            && in_array(
                UnifiedQueryAccessPolicy::PAGE_POLICY,
                $memberFeaturePermissions,
                true
            )
            && in_array(
                UnifiedQueryAccessPolicy::EXPORT,
                $memberFeaturePermissions,
                true
            ),
        json_encode(compact(
            'inventoryPermissions',
            'inventoryWithoutResolverPermissions',
            'memberFeaturePermissions'
        ), JSON_UNESCAPED_UNICODE),
        'UQ-PERM-RESOLVER-01'
    );

    $hostPermissionResolvers = new UnifiedQueryPagePermissionResolverRegistry($pages);
    $hostPermissionResolvers->register(new UqGenPermissionResolver(
        'inventory_batch_stock',
        [
            'pageAllowed' => true,
            'exportAllowed' => true,
            'permissions' => ['inventory.cost.read'],
        ]
    ));
    $hostPermissionResolvers->freeze();
    $hostContextFactory = new UnifiedQueryContextFactory(
        $pages,
        $hostPermissionResolvers
    );
    $hostTrustedContext = uqGenTrustedContext('inventory_batch_stock');
    $hostValidContext = $hostContextFactory->make(
        $hostTrustedContext,
        ['pageCode' => 'inventory_batch_stock']
    );
    $hostPayloadForgeryContext = $hostContextFactory->make(
        $hostTrustedContext,
        [
            'pageCode' => 'inventory_batch_stock',
            'permissions' => ['*'],
            'visible_store_ids' => null,
            'ancestor_organization_ids' => ['forged-org'],
            'scope_dimensions' => ['location_ids' => ['forged-location']],
            'query_cutoff_date' => '2099-12-31',
            'data_as_of' => PHP_INT_MAX,
        ]
    );
    $invalidHostTrustedContexts = [
        'unknown_key' => array_merge($hostTrustedContext, ['unexpected' => true]),
        'wildcard_feature' => array_merge($hostTrustedContext, [
            'granted_features' => ['*'],
        ]),
        'invalid_visible_stores' => array_merge($hostTrustedContext, [
            'visible_store_ids' => '8',
        ]),
        'invalid_scope_dimensions' => array_merge($hostTrustedContext, [
            'scope_dimensions' => ['location_ids' => 'location-8'],
        ]),
    ];
    $invalidHostContextFailures = [];
    foreach ($invalidHostTrustedContexts as $case => $trustedContext) {
        $invalidHostContextFailures[$case] = uqGenFailure(function () use (
            $hostContextFactory,
            $trustedContext
        ): void {
            $hostContextFactory->make(
                $trustedContext,
                ['pageCode' => 'inventory_batch_stock']
            );
        });
    }
    $invalidHostContextCodes = array_column(array_intersect_key(
        $invalidHostContextFailures,
        array_flip(['unknown_key', 'wildcard_feature', 'invalid_visible_stores'])
    ), 'code');
    ok(
        '中立上下文工厂只接受宿主白名单且忽略 payload 伪造权限与范围',
        $hostValidContext === $hostPayloadForgeryContext
            && ($hostValidContext['page_code'] ?? '') === 'inventory_batch_stock'
            && ($hostValidContext['visible_store_ids'] ?? null) === [8]
            && ($hostTrustedContext['ancestor_organization_ids'] ?? null) === ['3']
            && ($hostValidContext['ancestor_organization_ids'] ?? null) === ['3']
            && ($hostValidContext['scope_dimensions'] ?? null) === [
                'location_ids' => ['location-8'],
            ]
            && ($hostValidContext['query_cutoff_date'] ?? '') === '2026-07-28'
            && (int)($hostValidContext['data_as_of'] ?? 0) === 1785258000
            && in_array('inventory.cost.read', $hostValidContext['permissions'], true)
            && count($invalidHostContextCodes) === 3
            && count(array_unique($invalidHostContextCodes)) === 1
            && reset($invalidHostContextCodes) === 'UNIFIED_QUERY_CONTEXT_INVALID'
            && ($invalidHostContextFailures['invalid_scope_dimensions']['code'] ?? '')
                === 'UNIFIED_QUERY_SCOPE_DIMENSION_INVALID',
        json_encode(compact(
            'hostValidContext',
            'hostPayloadForgeryContext',
            'invalidHostContextFailures'
        ), JSON_UNESCAPED_UNICODE),
        'UQ-HOST-01'
    );

    $invalidResolverAuthorizations = [
        'wildcard' => [
            'pageAllowed' => true,
            'exportAllowed' => false,
            'permissions' => ['*'],
        ],
        'page_policy' => [
            'pageAllowed' => true,
            'exportAllowed' => false,
            'permissions' => [UnifiedQueryAccessPolicy::PAGE_POLICY],
        ],
        'export' => [
            'pageAllowed' => true,
            'exportAllowed' => false,
            'permissions' => [UnifiedQueryAccessPolicy::EXPORT],
        ],
        'manage_shared' => [
            'pageAllowed' => true,
            'exportAllowed' => false,
            'permissions' => [UnifiedQueryAccessPolicy::MANAGE_SHARED],
        ],
        'share_tenant' => [
            'pageAllowed' => true,
            'exportAllowed' => false,
            'permissions' => [UnifiedQueryAccessPolicy::SHARE_TENANT],
        ],
        'invalid_format' => [
            'pageAllowed' => true,
            'exportAllowed' => false,
            'permissions' => ['Bad Permission'],
        ],
        'cross_page_permission' => [
            'pageAllowed' => true,
            'exportAllowed' => false,
            'permissions' => ['member.private.read'],
        ],
        'unknown_key' => [
            'pageAllowed' => true,
            'exportAllowed' => false,
            'permissions' => [],
            'unexpected' => true,
        ],
        'invalid_shape' => [
            'pageAllowed' => 'yes',
            'exportAllowed' => false,
            'permissions' => [],
        ],
        'export_without_page' => [
            'pageAllowed' => false,
            'exportAllowed' => true,
            'permissions' => [],
        ],
    ];
    $invalidResolverFailures = [];
    foreach ($invalidResolverAuthorizations as $case => $authorization) {
        $invalidResolverFailures[$case] = uqGenFailure(function () use (
            $pages,
            $authorization
        ): void {
            uqGenResolvedContext($pages, $authorization);
        });
    }
    $wrongPageResolver = uqGenFailure(function () use ($pages): void {
        $resolvers = new UnifiedQueryPagePermissionResolverRegistry($pages);
        $resolvers->register(new UqGenPermissionResolver('unknown_page', [
            'pageAllowed' => false,
            'exportAllowed' => false,
            'permissions' => [],
        ]));
    });
    $invalidResolverCodes = array_column($invalidResolverFailures, 'code');
    ok(
        '页面 resolver 拒绝保留权限、跨页权限、畸形返回和错误页面登记',
        count($invalidResolverCodes) === count($invalidResolverAuthorizations)
            && count(array_unique($invalidResolverCodes)) === 1
            && reset($invalidResolverCodes) === 'UNIFIED_QUERY_PERMISSION_RESOLVER_INVALID'
            && $wrongPageResolver['code'] === 'UNIFIED_QUERY_PAGE_NOT_ALLOWED',
        json_encode(compact(
            'invalidResolverFailures',
            'wrongPageResolver'
        ), JSON_UNESCAPED_UNICODE),
        'UQ-PERM-RESOLVER-02'
    );

    $memberWorkerResolver = new UqGenWorkerContextResolver(
        'member_list',
        uqGenWorkerContext('member_list')
    );
    $inventoryWorkerResolver = new UqGenWorkerContextResolver(
        'inventory_batch_stock',
        uqGenWorkerContext('inventory_batch_stock')
    );
    $incompleteWorkerResolvers = new UnifiedQueryWorkerContextResolverRegistry($pages);
    $incompleteWorkerResolvers->register($memberWorkerResolver);
    $missingWorkerResolver = uqGenFailure(function () use (
        $incompleteWorkerResolvers
    ): void {
        $incompleteWorkerResolvers->resolve('inventory_batch_stock');
    });
    $incompleteWorkerResolverFreeze = uqGenFailure(function () use (
        $incompleteWorkerResolvers
    ): void {
        $incompleteWorkerResolvers->freeze();
    });

    $workerResolvers = new UnifiedQueryWorkerContextResolverRegistry($pages);
    $workerResolvers->register($memberWorkerResolver);
    $duplicateWorkerResolver = uqGenFailure(function () use ($workerResolvers): void {
        $workerResolvers->register(new UqGenWorkerContextResolver(
            'member_list',
            uqGenWorkerContext('member_list')
        ));
    });
    $workerResolvers->register($inventoryWorkerResolver);
    $workerResolvers->freeze();
    $lateWorkerResolver = uqGenFailure(function () use ($workerResolvers): void {
        $workerResolvers->register(new UqGenWorkerContextResolver(
            'inventory_batch_stock',
            uqGenWorkerContext('inventory_batch_stock')
        ));
    });
    $wrongPageWorkerResolvers = new UnifiedQueryWorkerContextResolverRegistry($pages);
    $wrongPageWorkerResolver = uqGenFailure(function () use (
        $wrongPageWorkerResolvers
    ): void {
        $wrongPageWorkerResolvers->register(new UqGenWorkerContextResolver(
            'unknown_page',
            uqGenWorkerContext('unknown_page')
        ));
    });
    $inventoryWorkerContext = $inventoryWorkerResolver->resolve([
        'task_no' => 'synthetic-inventory-task',
        'tenant_id' => 'tenant-uq-gen',
        'account_id' => 1,
        'operator_id' => 1,
        'page_code' => 'inventory_batch_stock',
    ]);
    $workerResolverPageCodes = $workerResolvers->pageCodes();
    sort($workerResolverPageCodes);
    ok(
        'Worker context resolver 一页一个并在冻结后拒绝缺失、重复、错页和晚登记',
        $missingWorkerResolver['code']
            === 'UNIFIED_QUERY_WORKER_CONTEXT_RESOLVER_NOT_AVAILABLE'
            && $incompleteWorkerResolverFreeze['class'] === LogicException::class
            && $duplicateWorkerResolver['class'] === LogicException::class
            && $lateWorkerResolver['class'] === LogicException::class
            && $wrongPageWorkerResolver['code'] === 'UNIFIED_QUERY_PAGE_NOT_ALLOWED'
            && $workerResolvers->isFrozen()
            && $workerResolverPageCodes === ['inventory_batch_stock', 'member_list']
            && $workerResolvers->resolve('member_list') === $memberWorkerResolver
            && $workerResolvers->resolve('inventory_batch_stock')
                === $inventoryWorkerResolver
            && ($inventoryWorkerContext['page_code'] ?? '')
                === 'inventory_batch_stock'
            && ($inventoryWorkerContext['scope_dimensions']['location_ids'] ?? [])
                === ['location-8']
            && !array_key_exists('origin_store_id', $inventoryWorkerResolver->calls[0] ?? []),
        json_encode(compact(
            'missingWorkerResolver',
            'incompleteWorkerResolverFreeze',
            'duplicateWorkerResolver',
            'lateWorkerResolver',
            'wrongPageWorkerResolver',
            'workerResolverPageCodes',
            'inventoryWorkerContext'
        ), JSON_UNESCAPED_UNICODE),
        'UQ-WORKER-RESOLVER-01'
    );

    $workerSource = (string)file_get_contents(
        '/var/www/html/app/services/query/UnifiedQueryExportWorkerServices.php'
    );
    $workerForbiddenDependencies = array_values(array_filter([
        'SystemStoreStaff',
        'Cashier',
        'Member',
        'UnifiedQueryModule',
    ], function (string $dependency) use ($workerSource): bool {
        return strpos($workerSource, $dependency) !== false;
    }));
    $workerCommandSource = (string)file_get_contents(
        '/var/www/html/app/command/UnifiedQueryExportWorker.php'
    );
    $workerCommandForbiddenDependencies = array_values(array_filter([
        'UnifiedQueryModule',
        'services\\cashier',
        'Cashier',
        'Member',
    ], function (string $dependency) use ($workerCommandSource): bool {
        return strpos($workerCommandSource, $dependency) !== false;
    }));
    ok(
        '核心导出 Worker 与 CLI 不依赖会员模型、Cashier 或收银查询模块',
        $workerSource !== ''
            && $workerForbiddenDependencies === []
            && $workerCommandSource !== ''
            && strpos($workerCommandSource, 'UnifiedQueryRuntime::runtime()') !== false
            && $workerCommandForbiddenDependencies === [],
        json_encode(compact(
            'workerForbiddenDependencies',
            'workerCommandForbiddenDependencies'
        ), JSON_UNESCAPED_UNICODE),
        'UQ-WORKER-CORE-01'
    );

    $neutralCoreSources = [
        'runtime' => (string)file_get_contents(
            '/var/www/html/app/services/query/UnifiedQueryRuntime.php'
        ),
        'context_factory' => (string)file_get_contents(
            '/var/www/html/app/services/query/UnifiedQueryContextFactory.php'
        ),
        'command_coordinator' => (string)file_get_contents(
            '/var/www/html/app/services/query/UnifiedQueryCommandCoordinator.php'
        ),
    ];
    $neutralCoreLeaks = [];
    foreach ($neutralCoreSources as $sourceName => $source) {
        foreach (['Cashier', 'Member', 'services\\cashier', 'provider\\Member'] as $needle) {
            if (strpos($source, $needle) !== false) {
                $neutralCoreLeaks[] = $sourceName . ':' . $needle;
            }
        }
    }
    ok(
        '中立 Runtime、ContextFactory 与 CommandCoordinator 零收银或会员依赖',
        !in_array('', $neutralCoreSources, true) && $neutralCoreLeaks === [],
        json_encode(compact('neutralCoreLeaks'), JSON_UNESCAPED_UNICODE),
        'UQ-HOST-02'
    );

    $normalizedInventoryScope = $pages->normalizeScopeDimensions(
        'inventory_batch_stock',
        ['location_ids' => ['location-2', 'location-1']]
    );
    $memberScope = $pages->normalizeScopeDimensions('member_list', []);
    $invalidScopes = [
        'missing' => [],
        'unknown' => [
            'location_ids' => ['location-1'],
            'warehouse_ids' => ['warehouse-1'],
        ],
        'scalar' => ['location_ids' => 'location-1'],
        'associative' => ['location_ids' => ['first' => 'location-1']],
        'invalid_value' => ['location_ids' => [['id' => 'location-1']]],
        'duplicate' => [
            'location_ids' => ['location-2', 'location-1', 'location-2'],
        ],
    ];
    $invalidScopeFailures = [];
    foreach ($invalidScopes as $case => $scopeDimensions) {
        $invalidScopeFailures[$case] = uqGenFailure(function () use (
            $pages,
            $scopeDimensions
        ): void {
            $pages->normalizeScopeDimensions('inventory_batch_stock', $scopeDimensions);
        });
    }
    $emptyScope = uqGenFailure(function () use ($pages): void {
        $pages->assertScopeDimensionsNotEmpty(
            'inventory_batch_stock',
            $pages->normalizeScopeDimensions(
                'inventory_batch_stock',
                ['location_ids' => []]
            )
        );
    });
    $invalidScopeCodes = array_column($invalidScopeFailures, 'code');
    ok(
        '页面 scopeDimensions 规范化稳定且缺失、未知或非法维度失败关闭',
        $normalizedInventoryScope === [
            'location_ids' => ['location-1', 'location-2'],
        ]
            && $memberScope === []
            && count($invalidScopeCodes) === count($invalidScopes)
            && count(array_unique($invalidScopeCodes)) === 1
            && reset($invalidScopeCodes) === 'UNIFIED_QUERY_SCOPE_DIMENSION_INVALID'
            && $emptyScope['code'] === 'UNIFIED_QUERY_SCOPE_EMPTY',
        json_encode(compact(
            'normalizedInventoryScope',
            'memberScope',
            'invalidScopeFailures',
            'emptyScope'
        ), JSON_UNESCAPED_UNICODE),
        'UQ-SCOPE-01'
    );

    $scopeGraph = uqGenServiceGraph($pages);
    /** @var UnifiedQueryExportTaskServices $scopeExports */
    $scopeExports = $scopeGraph['exports'];
    $scopeCreateContext = uqGenWorkerContext('inventory_batch_stock', [
        'scope_dimensions' => [
            'location_ids' => ['location-1', 'location-2'],
        ],
        'query_cutoff_date' => '2026-07-28',
        'data_as_of' => 1785258000,
    ]);
    $scopeCreated = $scopeExports->create($scopeCreateContext, [
        'pageCode' => 'inventory_batch_stock',
        'scope' => 'query',
        'queryCutoffDate' => '2026-07-28',
        'query' => [
            'pageCode' => 'inventory_batch_stock',
            'page' => 1,
            'pageSize' => 20,
        ],
        'fields' => ['batch_no'],
        'includeSummary' => false,
        'fileName' => '库存库位范围冻结.xlsx',
    ]);
    $scopeTaskNo = (string)($scopeCreated['taskId'] ?? '');
    if ($scopeTaskNo !== '') {
        $dynamicTaskNos[] = $scopeTaskNo;
    }
    $scopeTaskBeforeClaim = (array)Db::name(UnifiedQueryExportTaskServices::TABLE)
        ->where('task_no', $scopeTaskNo)
        ->find();
    $scopeFrozen = $scopeTaskBeforeClaim
        ? UnifiedQueryJson::decode((string)$scopeTaskBeforeClaim['frozen_scope'])
        : [];
    $scopeCurrentContext = uqGenWorkerContext('inventory_batch_stock', [
        // 当前权限扩大到 location-3，也只能消费创建时冻结的交集。
        'scope_dimensions' => [
            'location_ids' => ['location-2', 'location-3'],
        ],
    ]);
    $scopeClaim = $scopeExports->claim($scopeCurrentContext, $scopeTaskNo);
    ok(
        '导出创建冻结页面维度且领取时只保留冻结范围与当前范围交集',
        $scopeTaskNo !== ''
            && ($scopeFrozen['scope_dimensions'] ?? null) === [
                'location_ids' => ['location-1', 'location-2'],
            ]
            && ($scopeClaim['effectiveDataScope']['scope_dimensions'] ?? null) === [
                'location_ids' => ['location-2'],
            ]
            && ($scopeClaim['page_code'] ?? '') === 'inventory_batch_stock'
            && ($scopeClaim['query_cutoff_date'] ?? '') === '2026-07-28',
        json_encode(compact(
            'scopeCreated',
            'scopeFrozen',
            'scopeClaim'
        ), JSON_UNESCAPED_UNICODE),
        'UQ-SCOPE-02'
    );

    $validFrozenScope = [
        'all_stores' => false,
        'visible_store_ids' => [8],
        'ancestor_organization_ids' => ['org-8'],
        'scope_dimensions' => [
            'location_ids' => ['location-8'],
        ],
    ];
    $missingFrozenScopeKey = $validFrozenScope;
    unset($missingFrozenScopeKey['all_stores']);
    $extraFrozenScopeKey = array_merge($validFrozenScope, ['unexpected_scope' => []]);
    $frozenScopeTamperCases = [
        'all_stores_string' => array_merge($validFrozenScope, ['all_stores' => 'false']),
        'missing_key' => $missingFrozenScopeKey,
        'extra_key' => $extraFrozenScopeKey,
        'all_with_store_list' => array_merge($validFrozenScope, [
            'all_stores' => true,
            'visible_store_ids' => [8],
        ]),
        'limited_with_null_stores' => array_merge($validFrozenScope, [
            'all_stores' => false,
            'visible_store_ids' => null,
        ]),
        'associative_stores' => array_merge($validFrozenScope, [
            'visible_store_ids' => ['first' => 8],
        ]),
        'zero_store' => array_merge($validFrozenScope, [
            'visible_store_ids' => [0],
        ]),
        'too_many_stores' => array_merge($validFrozenScope, [
            'visible_store_ids' => range(
                1,
                UnifiedQueryPageRegistry::MAX_SCOPE_DIMENSION_VALUES + 1
            ),
        ]),
        'invalid_organization' => array_merge($validFrozenScope, [
            'ancestor_organization_ids' => ['bad organization'],
        ]),
        'invalid_scope_dimensions' => array_merge($validFrozenScope, [
            'scope_dimensions' => ['location_ids' => 'location-8'],
        ]),
    ];
    $tamperMemberProvider = new UqGenRecordingProvider('member_list');
    $tamperInventoryProvider = new UqGenRecordingProvider('inventory_batch_stock');
    $tamperProviders = new UnifiedQueryProviderRegistry($pages);
    $tamperProviders->register($tamperMemberProvider);
    $tamperProviders->register($tamperInventoryProvider);
    $tamperProviders->freeze();
    $tamperWorker = new UqGenWorker(
        $scopeExports,
        $tamperProviders,
        uqGenWorkerResolverRegistry(
            $pages,
            uqGenWorkerContext('inventory_batch_stock')
        )
    );
    $frozenScopeTamperResults = [];
    foreach ($frozenScopeTamperCases as $case => $frozenScope) {
        $tamperTaskNo = 'uqe_' . substr(hash('sha256', 'uq-scope-tamper-' . $case), 0, 32);
        Db::name(UnifiedQueryExportTaskServices::TABLE)
            ->where('task_no', $tamperTaskNo)
            ->delete();
        uqGenSeedTask($tamperTaskNo, 'inventory_batch_stock');
        $dynamicTaskNos[] = $tamperTaskNo;
        Db::name(UnifiedQueryExportTaskServices::TABLE)
            ->where('task_no', $tamperTaskNo)
            ->update(['frozen_scope' => UnifiedQueryJson::encode($frozenScope)]);
        $result = $tamperWorker->processOne($tamperTaskNo);
        $frozenScopeTamperResults[$case] = [
            'errorCode' => (string)($result['errorCode'] ?? ''),
            'status' => (string)Db::name(UnifiedQueryExportTaskServices::TABLE)
                ->where('task_no', $tamperTaskNo)
                ->value('status'),
        ];
    }
    $frozenScopeTamperPassed = true;
    foreach ($frozenScopeTamperResults as $result) {
        if ($result['errorCode'] !== 'UNIFIED_QUERY_FROZEN_SCOPE_INVALID'
            || $result['status'] !== 'failed') {
            $frozenScopeTamperPassed = false;
            break;
        }
    }
    ok(
        '冻结范围严格拒绝类型、键集合、全量语义、列表和值域篡改',
        $frozenScopeTamperPassed
            && count($tamperInventoryProvider->frozenCalls) === 0
            && $tamperWorker->writeCalls === [],
        json_encode(compact('frozenScopeTamperResults'), JSON_UNESCAPED_UNICODE),
        'UQ-SCOPE-03'
    );

    $scopeIsolationTask = $scopeExports->create($scopeCreateContext, [
        'pageCode' => 'inventory_batch_stock',
        'scope' => 'query',
        'queryCutoffDate' => '2026-07-28',
        'query' => [
            'pageCode' => 'inventory_batch_stock',
            'page' => 1,
            'pageSize' => 20,
        ],
        'fields' => ['batch_no'],
        'includeSummary' => false,
        'fileName' => '宿主范围隔离.xlsx',
    ]);
    $scopeIsolationTaskNo = (string)($scopeIsolationTask['taskId'] ?? '');
    if ($scopeIsolationTaskNo !== '') {
        $dynamicTaskNos[] = $scopeIsolationTaskNo;
    }
    $expandedHostContext = uqGenWorkerContext('inventory_batch_stock', [
        'visible_store_ids' => [8, 9],
        'ancestor_organization_ids' => ['org-8', 'org-9'],
        'scope_dimensions' => [
            'location_ids' => ['location-2', 'location-3'],
        ],
        'authorization_mode' => 'all',
        'shareable_store_ids' => null,
        'shareable_organization_ids' => ['org-8', 'org-9'],
        'location_claim' => ['location-1', 'location-2', 'location-3'],
        'employee_data_scope' => ['all' => true],
        'is_super_admin' => true,
    ]);
    $scopeIsolationMemberProvider = new UqGenRecordingProvider('member_list');
    $scopeIsolationInventoryProvider = new UqGenRecordingProvider(
        'inventory_batch_stock'
    );
    $scopeIsolationProviders = new UnifiedQueryProviderRegistry($pages);
    $scopeIsolationProviders->register($scopeIsolationMemberProvider);
    $scopeIsolationProviders->register($scopeIsolationInventoryProvider);
    $scopeIsolationProviders->freeze();
    $scopeIsolationWorker = new UqGenWorker(
        $scopeExports,
        $scopeIsolationProviders,
        uqGenWorkerResolverRegistry($pages, $expandedHostContext)
    );
    $scopeIsolationResult = $scopeIsolationWorker->processOne($scopeIsolationTaskNo);
    $scopeIsolationCall = (array)(
        $scopeIsolationInventoryProvider->frozenCalls[0] ?? []
    );
    $scopeIsolationProviderContext = (array)($scopeIsolationCall['context'] ?? []);
    $scopeIsolationEffective = (array)(
        $scopeIsolationProviderContext['effective_data_scope'] ?? []
    );
    $hostScopeKeys = [
        'authorization_mode',
        'shareable_store_ids',
        'shareable_organization_ids',
        'location_claim',
        'employee_data_scope',
        'is_super_admin',
    ];
    $leakedHostScopeKeys = array_values(array_filter(
        $hostScopeKeys,
        function (string $key) use ($scopeIsolationProviderContext): bool {
            return array_key_exists($key, $scopeIsolationProviderContext);
        }
    ));
    ok(
        'provider 只接收冻结范围与当前范围交集且宿主附加宽 scope 被剥离',
        ($scopeIsolationResult['status'] ?? '') === 'succeeded'
            && count($scopeIsolationInventoryProvider->frozenCalls) === 1
            && ($scopeIsolationProviderContext['visible_store_ids'] ?? null) === [8]
            && ($scopeIsolationProviderContext['all_stores'] ?? null) === false
            && ($scopeIsolationProviderContext['ancestor_organization_ids'] ?? null)
                === ['org-8']
            && ($scopeIsolationProviderContext['scope_dimensions'] ?? null) === [
                'location_ids' => ['location-2'],
            ]
            && ($scopeIsolationEffective['visible_store_ids'] ?? null) === [8]
            && ($scopeIsolationEffective['scope_dimensions'] ?? null) === [
                'location_ids' => ['location-2'],
            ]
            && $leakedHostScopeKeys === []
            && strpos(
                UnifiedQueryJson::encode($scopeIsolationProviderContext),
                'location-3'
            ) === false
            && (int)($scopeIsolationWorker->writeCalls[$scopeIsolationTaskNo] ?? 0)
                === 1,
        json_encode(compact(
            'scopeIsolationResult',
            'scopeIsolationProviderContext',
            'scopeIsolationEffective',
            'leakedHostScopeKeys'
        ), JSON_UNESCAPED_UNICODE),
        'UQ-SCOPE-04'
    );

    $preflightCreateContext = uqGenWorkerContext('inventory_batch_stock', [
        'permissions' => [
            UnifiedQueryAccessPolicy::PAGE_POLICY,
            UnifiedQueryAccessPolicy::EXPORT,
            'inventory.cost.read',
        ],
        'scope_dimensions' => [
            'location_ids' => ['location-1', 'location-2'],
        ],
        'query_cutoff_date' => '2026-07-28',
        'data_as_of' => 1785258000,
    ]);
    /** @var UnifiedQueryCustomFieldServices $preflightCustomFields */
    $preflightCustomFields = $scopeGraph['customFields'];
    $preflightAmountField = $preflightCustomFields->save($preflightCreateContext, [
        'pageCode' => 'inventory_batch_stock',
        'name' => '受控成本金额测试',
        'returnType' => 'amount',
        'visibility' => 'personal',
        'expression' => ['type' => 'field', 'key' => 'unit_cost'],
    ]);
    $preflightAmountKey = (string)($preflightAmountField['key'] ?? '');
    if ($preflightAmountKey !== '') {
        $dynamicFieldKeys[] = $preflightAmountKey;
    }
    $preflightTextField = $preflightCustomFields->save($preflightCreateContext, [
        'pageCode' => 'inventory_batch_stock',
        'name' => '受控成本分类测试',
        'returnType' => 'text',
        'visibility' => 'personal',
        'expression' => ['type' => 'field', 'key' => 'cost_category'],
    ]);
    $preflightTextKey = (string)($preflightTextField['key'] ?? '');
    if ($preflightTextKey !== '') {
        $dynamicFieldKeys[] = $preflightTextKey;
    }
    $preflightTaskPayload = [
        'pageCode' => 'inventory_batch_stock',
        'scope' => 'query',
        'queryCutoffDate' => '2026-07-28',
        'fields' => ['batch_no'],
        'includeSummary' => false,
    ];
    $systemPreflightTask = $scopeExports->create(
        $preflightCreateContext,
        array_merge($preflightTaskPayload, [
            'fileName' => '系统字段失权预检.xlsx',
            'query' => [
                'pageCode' => 'inventory_batch_stock',
                'page' => 1,
                'pageSize' => 20,
                'filters' => [[
                    'field' => 'unit_cost',
                    'operator' => 'gte',
                    'value' => '0.00',
                ]],
                'sorts' => [[
                    'field' => 'unit_cost',
                    'direction' => 'desc',
                ]],
                'groupBy' => ['cost_category'],
                'summaries' => [[
                    'field' => 'unit_cost',
                    'aggregation' => 'sum',
                ]],
            ],
        ])
    );
    $customPreflightTask = $scopeExports->create(
        $preflightCreateContext,
        array_merge($preflightTaskPayload, [
            'fileName' => '自定义字段失权预检.xlsx',
            'query' => [
                'pageCode' => 'inventory_batch_stock',
                'page' => 1,
                'pageSize' => 20,
                'filters' => [[
                    'field' => $preflightAmountKey,
                    'operator' => 'gte',
                    'value' => '0.00',
                ]],
                'sorts' => [[
                    'field' => $preflightAmountKey,
                    'direction' => 'desc',
                ]],
                'groupBy' => [$preflightTextKey],
                'summaries' => [[
                    'field' => $preflightAmountKey,
                    'aggregation' => 'sum',
                ]],
            ],
        ])
    );
    $visiblePreflightTask = $scopeExports->create(
        $preflightCreateContext,
        array_merge($preflightTaskPayload, [
            'fileName' => '显示字段失权预检.xlsx',
            'query' => [
                'pageCode' => 'inventory_batch_stock',
                'page' => 1,
                'pageSize' => 20,
                'visibleFields' => ['unit_cost'],
            ],
        ])
    );
    $systemPreflightTaskNo = (string)($systemPreflightTask['taskId'] ?? '');
    $customPreflightTaskNo = (string)($customPreflightTask['taskId'] ?? '');
    $visiblePreflightTaskNo = (string)($visiblePreflightTask['taskId'] ?? '');
    foreach ([
        $systemPreflightTaskNo,
        $customPreflightTaskNo,
        $visiblePreflightTaskNo,
    ] as $taskNo) {
        if ($taskNo !== '') {
            $dynamicTaskNos[] = $taskNo;
        }
    }
    $preflightTaskRows = Db::name(UnifiedQueryExportTaskServices::TABLE)
        ->whereIn('task_no', [
            $systemPreflightTaskNo,
            $customPreflightTaskNo,
            $visiblePreflightTaskNo,
        ])
        ->select()
        ->toArray();
    $preflightTaskIndex = array_column($preflightTaskRows, null, 'task_no');
    $systemPreflightPlan = UnifiedQueryJson::decode((string)(
        $preflightTaskIndex[$systemPreflightTaskNo]['query_payload'] ?? ''
    ));
    $customPreflightPlan = UnifiedQueryJson::decode((string)(
        $preflightTaskIndex[$customPreflightTaskNo]['query_payload'] ?? ''
    ));
    $customPreflightSnapshot = UnifiedQueryJson::decode((string)(
        $preflightTaskIndex[$customPreflightTaskNo]['field_snapshot'] ?? ''
    ));
    $visiblePreflightPlan = UnifiedQueryJson::decode((string)(
        $preflightTaskIndex[$visiblePreflightTaskNo]['query_payload'] ?? ''
    ));
    $visiblePreflightSnapshot = UnifiedQueryJson::decode((string)(
        $preflightTaskIndex[$visiblePreflightTaskNo]['field_snapshot'] ?? ''
    ));

    $preflightMemberProvider = new UqGenRecordingProvider('member_list');
    $preflightInventoryProvider = new UqGenRecordingProvider('inventory_batch_stock');
    $preflightProviders = new UnifiedQueryProviderRegistry($pages);
    $preflightProviders->register($preflightMemberProvider);
    $preflightProviders->register($preflightInventoryProvider);
    $preflightProviders->freeze();
    $revokedCostResolver = new UqGenWorkerContextResolver(
        'inventory_batch_stock',
        uqGenWorkerContext('inventory_batch_stock', [
            'scope_dimensions' => [
                'location_ids' => ['location-2', 'location-3'],
            ],
        ])
    );
    $preflightResolvers = new UnifiedQueryWorkerContextResolverRegistry($pages);
    $preflightResolvers->register(new UqGenWorkerContextResolver(
        'member_list',
        uqGenWorkerContext('member_list')
    ));
    $preflightResolvers->register($revokedCostResolver);
    $preflightResolvers->freeze();
    $preflightWorker = new UqGenWorker(
        $scopeExports,
        $preflightProviders,
        $preflightResolvers
    );
    $systemPreflightResult = $preflightWorker->processOne($systemPreflightTaskNo);
    $customPreflightResult = $preflightWorker->processOne($customPreflightTaskNo);
    $visiblePreflightResult = $preflightWorker->processOne($visiblePreflightTaskNo);
    $preflightStatuses = Db::name(UnifiedQueryExportTaskServices::TABLE)
        ->whereIn('task_no', [
            $systemPreflightTaskNo,
            $customPreflightTaskNo,
            $visiblePreflightTaskNo,
        ])
        ->column('status', 'task_no');
    $customPlanReferences = array_map(function (array $definition): array {
        return (array)($definition['referenced_fields'] ?? []);
    }, (array)($customPreflightPlan['custom_definitions'] ?? []));
    $customRequiredPermissionRows = Db::name(
        UnifiedQueryCustomFieldServices::VERSION_TABLE
    )
        ->whereIn('field_key', [$preflightAmountKey, $preflightTextKey])
        ->column('required_permissions', 'field_key');
    $customRequiredPermissions = [];
    foreach ([$preflightAmountKey, $preflightTextKey] as $fieldKey) {
        $customRequiredPermissions[$fieldKey] = UnifiedQueryJson::decode((string)(
            $customRequiredPermissionRows[$fieldKey] ?? ''
        ));
    }
    ok(
        'Worker 在 provider 前重验冻结计划中导出列外的系统与自定义字段权限',
        ($systemPreflightResult['errorCode'] ?? '')
            === 'UNIFIED_QUERY_EXPORT_PREFLIGHT_FORBIDDEN'
            && ($customPreflightResult['errorCode'] ?? '')
                === 'UNIFIED_QUERY_EXPORT_PREFLIGHT_FORBIDDEN'
            && ($preflightStatuses[$systemPreflightTaskNo] ?? '') === 'failed'
            && ($preflightStatuses[$customPreflightTaskNo] ?? '') === 'failed'
            && ($systemPreflightPlan['groups'] ?? []) === ['cost_category']
            && in_array(
                'unit_cost',
                array_column((array)($systemPreflightPlan['filters'] ?? []), 'field_key'),
                true
            )
            && in_array(
                'unit_cost',
                array_column((array)($systemPreflightPlan['sorts'] ?? []), 'field_key'),
                true
            )
            && in_array(
                'unit_cost',
                array_column((array)($systemPreflightPlan['summaries'] ?? []), 'field_key'),
                true
            )
            && count((array)($customPreflightPlan['custom_definitions'] ?? [])) === 2
            && $customPlanReferences === [
                ['unit_cost'],
                ['cost_category'],
            ]
            && ($customRequiredPermissions[$preflightAmountKey] ?? [])
                === ['inventory.cost.read']
            && ($customRequiredPermissions[$preflightTextKey] ?? [])
                === ['inventory.cost.read']
            && array_column($customPreflightSnapshot, 'key') === ['batch_no']
            && count($revokedCostResolver->calls) === 3
            && count($preflightInventoryProvider->frozenCalls) === 0
            && $preflightWorker->writeCalls === [],
        json_encode(compact(
            'systemPreflightResult',
            'customPreflightResult',
            'systemPreflightPlan',
            'customPreflightPlan',
            'customPreflightSnapshot',
            'customPlanReferences',
            'customRequiredPermissions',
            'preflightStatuses'
        ), JSON_UNESCAPED_UNICODE),
        'UQ-WORKER-PREFLIGHT-01'
    );
    ok(
        'visibleFields 仅接受有权可展示的唯一系统字段或已冻结自定义字段',
        $visibleFieldsPlanContractPassed
            && ($visiblePreflightResult['errorCode'] ?? '')
                === 'UNIFIED_QUERY_EXPORT_PREFLIGHT_FORBIDDEN'
            && ($preflightStatuses[$visiblePreflightTaskNo] ?? '') === 'failed'
            && ($visiblePreflightPlan['visible_fields'] ?? null) === ['unit_cost']
            && array_column($visiblePreflightSnapshot, 'key') === ['batch_no']
            && count($preflightInventoryProvider->frozenCalls) === 0
            && $preflightWorker->writeCalls === [],
        json_encode(compact(
            'validVisibleFieldsPlan',
            'invalidVisibleFieldFailures',
            'visiblePreflightResult',
            'visiblePreflightPlan',
            'visiblePreflightSnapshot'
        ), JSON_UNESCAPED_UNICODE),
        'UQ-VISIBLE-FIELDS-01'
    );

    $downloadSystemTask = $scopeExports->create($preflightCreateContext, [
        'pageCode' => 'inventory_batch_stock',
        'scope' => 'query',
        'queryCutoffDate' => '2026-07-28',
        'query' => [
            'pageCode' => 'inventory_batch_stock',
            'page' => 1,
            'pageSize' => 20,
            'visibleFields' => ['unit_cost'],
        ],
        'fields' => ['unit_cost'],
        'includeSummary' => false,
        'fileName' => '系统字段下载复核.xlsx',
    ]);
    $downloadCustomTask = $scopeExports->create($preflightCreateContext, [
        'pageCode' => 'inventory_batch_stock',
        'scope' => 'query',
        'queryCutoffDate' => '2026-07-28',
        'query' => [
            'pageCode' => 'inventory_batch_stock',
            'page' => 1,
            'pageSize' => 20,
            'visibleFields' => [$preflightAmountKey],
        ],
        'fields' => [$preflightAmountKey],
        'includeSummary' => false,
        'fileName' => '自定义字段冻结下载.xlsx',
    ]);
    $downloadSystemTaskNo = (string)($downloadSystemTask['taskId'] ?? '');
    $downloadCustomTaskNo = (string)($downloadCustomTask['taskId'] ?? '');
    foreach ([$downloadSystemTaskNo, $downloadCustomTaskNo] as $taskNo) {
        if ($taskNo !== '') {
            $dynamicTaskNos[] = $taskNo;
        }
    }
    $downloadMemberProvider = new UqGenRecordingProvider('member_list');
    $downloadInventoryProvider = new UqGenRecordingProvider('inventory_batch_stock');
    $downloadProviders = new UnifiedQueryProviderRegistry($pages);
    $downloadProviders->register($downloadMemberProvider);
    $downloadProviders->register($downloadInventoryProvider);
    $downloadProviders->freeze();
    $downloadWorker = new UqGenWorker(
        $scopeExports,
        $downloadProviders,
        uqGenWorkerResolverRegistry($pages, $preflightCreateContext)
    );
    $downloadSystemWorkerResult = $downloadWorker->processOne($downloadSystemTaskNo);
    $downloadCustomWorkerResult = $downloadWorker->processOne($downloadCustomTaskNo);
    $validSystemDescriptor = $scopeExports->resolveDownloadDescriptor(
        $preflightCreateContext,
        $downloadSystemTaskNo
    );
    $validCustomDescriptor = $scopeExports->resolveDownloadDescriptor(
        $preflightCreateContext,
        $downloadCustomTaskNo
    );
    $sourcePermissionRevokedContext = array_merge($preflightCreateContext, [
        'permissions' => [
            UnifiedQueryAccessPolicy::PAGE_POLICY,
            UnifiedQueryAccessPolicy::EXPORT,
        ],
    ]);
    $systemSourceRevoked = uqGenFailure(function () use (
        $scopeExports,
        $sourcePermissionRevokedContext,
        $downloadSystemTaskNo
    ): void {
        $scopeExports->resolveDownloadDescriptor(
            $sourcePermissionRevokedContext,
            $downloadSystemTaskNo
        );
    });
    $customSourceRevoked = uqGenFailure(function () use (
        $scopeExports,
        $sourcePermissionRevokedContext,
        $downloadCustomTaskNo
    ): void {
        $scopeExports->resolveDownloadDescriptor(
            $sourcePermissionRevokedContext,
            $downloadCustomTaskNo
        );
    });
    $pagePermissionRevoked = uqGenFailure(function () use (
        $scopeExports,
        $preflightCreateContext,
        $downloadSystemTaskNo
    ): void {
        $scopeExports->resolveDownloadDescriptor(array_merge(
            $preflightCreateContext,
            ['permissions' => [
                UnifiedQueryAccessPolicy::EXPORT,
                'inventory.cost.read',
            ]]
        ), $downloadSystemTaskNo);
    });
    $exportPermissionAliasNotRequired = $scopeExports->resolveDownloadDescriptor(array_merge(
        $preflightCreateContext,
        ['permissions' => [
            UnifiedQueryAccessPolicy::PAGE_POLICY,
            'inventory.cost.read',
        ]]
    ), $downloadSystemTaskNo);

    $inactiveDownloadField = $preflightCustomFields->changeStatus(
        $preflightCreateContext,
        [
            'fieldKey' => $preflightAmountKey,
            'expectedVersion' => 1,
            'status' => 'inactive',
        ]
    );
    $inactiveCustomDescriptor = $scopeExports->resolveDownloadDescriptor(
        $preflightCreateContext,
        $downloadCustomTaskNo
    );
    $restoredDownloadField = $preflightCustomFields->changeStatus(
        $preflightCreateContext,
        [
            'fieldKey' => $preflightAmountKey,
            'expectedVersion' => (int)($inactiveDownloadField['version'] ?? 0),
            'status' => 'active',
        ]
    );
    $renamedDownloadField = $preflightCustomFields->save(
        $preflightCreateContext,
        [
            'pageCode' => 'inventory_batch_stock',
            'fieldKey' => $preflightAmountKey,
            'expectedVersion' => (int)($restoredDownloadField['version'] ?? 0),
            'name' => '受控成本金额测试新版',
            'returnType' => 'amount',
            'visibility' => 'personal',
            'expression' => ['type' => 'field', 'key' => 'unit_cost'],
        ]
    );
    $renamedCustomDescriptor = $scopeExports->resolveDownloadDescriptor(
        $preflightCreateContext,
        $downloadCustomTaskNo
    );
    $archivedDownloadField = $preflightCustomFields->archive(
        $preflightCreateContext,
        [
            'fieldKey' => $preflightAmountKey,
            'expectedVersion' => (int)($renamedDownloadField['version'] ?? 0),
        ]
    );
    $archivedCustomDescriptor = $scopeExports->resolveDownloadDescriptor(
        $preflightCreateContext,
        $downloadCustomTaskNo
    );
    Db::name(UnifiedQueryCustomFieldServices::VERSION_TABLE)
        ->where('field_key', $preflightAmountKey)
        ->where('version', 1)
        ->delete();
    $deletedVersionCustomDescriptor = $scopeExports->resolveDownloadDescriptor(
        $preflightCreateContext,
        $downloadCustomTaskNo
    );
    $downloadProviderCallsAfterGeneration = count($downloadInventoryProvider->frozenCalls);
    $downloadWriteCallsAfterGeneration = array_sum($downloadWorker->writeCalls);
    $frozenCustomStorageKey = (string)($validCustomDescriptor['storageKey'] ?? '');
    ok(
        '下载重验当前页面与系统源权限且自定义字段后续变化不影响成功文件',
        ($downloadSystemWorkerResult['status'] ?? '') === 'succeeded'
            && ($downloadCustomWorkerResult['status'] ?? '') === 'succeeded'
            && ($validSystemDescriptor['storageKey'] ?? '')
                === ($downloadSystemWorkerResult['storageKey'] ?? '')
            && $frozenCustomStorageKey
                === (string)($downloadCustomWorkerResult['storageKey'] ?? '')
            && $systemSourceRevoked['code']
                === 'UNIFIED_QUERY_EXPORT_DOWNLOAD_FIELD_FORBIDDEN'
            && $customSourceRevoked['code']
                === 'UNIFIED_QUERY_EXPORT_DOWNLOAD_FIELD_FORBIDDEN'
            && $pagePermissionRevoked['code'] === 'UNIFIED_QUERY_FORBIDDEN'
            && ($exportPermissionAliasNotRequired['storageKey'] ?? '')
                === ($downloadSystemWorkerResult['storageKey'] ?? '')
            && (string)($inactiveCustomDescriptor['storageKey'] ?? '')
                === $frozenCustomStorageKey
            && (string)($renamedCustomDescriptor['storageKey'] ?? '')
                === $frozenCustomStorageKey
            && (string)($archivedCustomDescriptor['storageKey'] ?? '')
                === $frozenCustomStorageKey
            && (string)($deletedVersionCustomDescriptor['storageKey'] ?? '')
                === $frozenCustomStorageKey
            && ($archivedDownloadField['status'] ?? '') === 'archived'
            && $downloadProviderCallsAfterGeneration === 2
            && $downloadWriteCallsAfterGeneration === 2
            && count($downloadInventoryProvider->frozenCalls) === 2
            && array_sum($downloadWorker->writeCalls) === 2,
        json_encode(compact(
            'downloadSystemWorkerResult',
            'downloadCustomWorkerResult',
            'validSystemDescriptor',
            'validCustomDescriptor',
            'systemSourceRevoked',
            'customSourceRevoked',
            'pagePermissionRevoked',
            'exportPermissionAliasNotRequired',
            'inactiveDownloadField',
            'restoredDownloadField',
            'renamedDownloadField',
            'archivedDownloadField',
            'inactiveCustomDescriptor',
            'renamedCustomDescriptor',
            'archivedCustomDescriptor',
            'deletedVersionCustomDescriptor'
        ), JSON_UNESCAPED_UNICODE),
        'UQ-DOWNLOAD-PREFLIGHT-01'
    );

    $downloadScopeCreateContext = uqGenWorkerContext('inventory_batch_stock', [
        'visible_store_ids' => [8, 9, 10],
        'ancestor_organization_ids' => ['org-8', 'org-9', 'org-10'],
        'scope_dimensions' => [
            'location_ids' => ['location-1', 'location-2', 'location-3'],
        ],
        'query_cutoff_date' => '2026-07-28',
        'data_as_of' => 1785258000,
    ]);
    $downloadScopeGenerationContext = uqGenWorkerContext(
        'inventory_batch_stock',
        [
            'visible_store_ids' => [8, 9],
            'ancestor_organization_ids' => ['org-8', 'org-9'],
            'scope_dimensions' => [
                'location_ids' => ['location-1', 'location-2'],
            ],
            'query_cutoff_date' => '2026-07-28',
            'data_as_of' => 1785258000,
        ]
    );
    $downloadScopePayload = [
        'pageCode' => 'inventory_batch_stock',
        'scope' => 'query',
        'queryCutoffDate' => '2026-07-28',
        'query' => [
            'pageCode' => 'inventory_batch_stock',
            'page' => 1,
            'pageSize' => 20,
            'visibleFields' => ['batch_no'],
        ],
        'fields' => ['batch_no'],
        'includeSummary' => false,
        'fileName' => '下载范围单调收窄.xlsx',
    ];
    $downloadScopeTask = $scopeExports->create(
        $downloadScopeCreateContext,
        $downloadScopePayload
    );
    $downloadScopeTaskNo = (string)($downloadScopeTask['taskId'] ?? '');
    if ($downloadScopeTaskNo !== '') {
        $dynamicTaskNos[] = $downloadScopeTaskNo;
    }
    $downloadScopeMemberProvider = new UqGenRecordingProvider('member_list');
    $downloadScopeInventoryProvider = new UqGenRecordingProvider(
        'inventory_batch_stock'
    );
    $downloadScopeProviders = new UnifiedQueryProviderRegistry($pages);
    $downloadScopeProviders->register($downloadScopeMemberProvider);
    $downloadScopeProviders->register($downloadScopeInventoryProvider);
    $downloadScopeProviders->freeze();
    $downloadScopeWorker = new UqGenWorker(
        $scopeExports,
        $downloadScopeProviders,
        uqGenWorkerResolverRegistry($pages, $downloadScopeGenerationContext)
    );
    $downloadScopeWorkerResult = $downloadScopeWorker->processOne(
        $downloadScopeTaskNo
    );
    $downloadScopeRow = (array)Db::name(UnifiedQueryExportTaskServices::TABLE)
        ->where('task_no', $downloadScopeTaskNo)
        ->find();
    $downloadScopeFrozenAfterGeneration = UnifiedQueryJson::decode(
        (string)($downloadScopeRow['frozen_scope'] ?? '')
    );
    $downloadScopeSameDescriptor = $scopeExports->resolveDownloadDescriptor(
        $downloadScopeGenerationContext,
        $downloadScopeTaskNo
    );
    $downloadScopeSupersetContext = uqGenWorkerContext(
        'inventory_batch_stock',
        [
            'visible_store_ids' => [8, 9, 10, 11],
            'ancestor_organization_ids' => ['org-8', 'org-9', 'org-10', 'org-11'],
            'scope_dimensions' => [
                'location_ids' => [
                    'location-1', 'location-2', 'location-3', 'location-4',
                ],
            ],
        ]
    );
    $downloadScopeSupersetDescriptor = $scopeExports->resolveDownloadDescriptor(
        $downloadScopeSupersetContext,
        $downloadScopeTaskNo
    );
    $downloadScopeAllStoresContext = array_merge($downloadScopeSupersetContext, [
        'visible_store_ids' => null,
    ]);
    $downloadScopeAllStoresDescriptor = $scopeExports->resolveDownloadDescriptor(
        $downloadScopeAllStoresContext,
        $downloadScopeTaskNo
    );
    $downloadScopeShrinkContexts = [
        'store' => array_merge($downloadScopeGenerationContext, [
            'visible_store_ids' => [8],
        ]),
        'organization' => array_merge($downloadScopeGenerationContext, [
            'ancestor_organization_ids' => ['org-8'],
        ]),
        'location' => array_merge($downloadScopeGenerationContext, [
            'scope_dimensions' => ['location_ids' => ['location-1']],
        ]),
    ];
    $downloadScopeShrinkFailures = [];
    foreach ($downloadScopeShrinkContexts as $case => $scopeContext) {
        $downloadScopeShrinkFailures[$case] = uqGenFailure(function () use (
            $scopeExports,
            $scopeContext,
            $downloadScopeTaskNo
        ): void {
            $scopeExports->resolveDownloadDescriptor(
                $scopeContext,
                $downloadScopeTaskNo
            );
        });
    }
    $downloadScopeProviderCalls = count(
        $downloadScopeInventoryProvider->frozenCalls
    );
    $downloadScopeWriteCalls = array_sum($downloadScopeWorker->writeCalls);

    $reclaimScopeTask = $scopeExports->create(
        $downloadScopeCreateContext,
        array_merge($downloadScopePayload, [
            'fileName' => '重领范围单调收窄.xlsx',
        ])
    );
    $reclaimScopeTaskNo = (string)($reclaimScopeTask['taskId'] ?? '');
    if ($reclaimScopeTaskNo !== '') {
        $dynamicTaskNos[] = $reclaimScopeTaskNo;
    }
    $firstReclaim = $scopeExports->claim(
        $downloadScopeGenerationContext,
        $reclaimScopeTaskNo
    );
    $firstReclaimRow = (array)Db::name(UnifiedQueryExportTaskServices::TABLE)
        ->where('task_no', $reclaimScopeTaskNo)
        ->find();
    $firstReclaimFrozen = UnifiedQueryJson::decode(
        (string)($firstReclaimRow['frozen_scope'] ?? '')
    );
    Db::name(UnifiedQueryExportTaskServices::TABLE)
        ->where('task_no', $reclaimScopeTaskNo)
        ->update(['lease_expires_at' => time() - 1]);
    $narrowReclaimContext = array_merge($downloadScopeGenerationContext, [
        'visible_store_ids' => [8],
        'ancestor_organization_ids' => ['org-8'],
        'scope_dimensions' => ['location_ids' => ['location-1']],
    ]);
    $secondReclaim = $scopeExports->claim(
        $narrowReclaimContext,
        $reclaimScopeTaskNo
    );
    $secondReclaimRow = (array)Db::name(UnifiedQueryExportTaskServices::TABLE)
        ->where('task_no', $reclaimScopeTaskNo)
        ->find();
    $secondReclaimFrozen = UnifiedQueryJson::decode(
        (string)($secondReclaimRow['frozen_scope'] ?? '')
    );
    Db::name(UnifiedQueryExportTaskServices::TABLE)
        ->where('task_no', $reclaimScopeTaskNo)
        ->update(['lease_expires_at' => time() - 1]);
    $thirdReclaim = $scopeExports->claim(
        $downloadScopeAllStoresContext,
        $reclaimScopeTaskNo
    );
    $thirdReclaimRow = (array)Db::name(UnifiedQueryExportTaskServices::TABLE)
        ->where('task_no', $reclaimScopeTaskNo)
        ->find();
    $thirdReclaimFrozen = UnifiedQueryJson::decode(
        (string)($thirdReclaimRow['frozen_scope'] ?? '')
    );

    $legacyScopeTask = $scopeExports->create(
        $downloadScopeCreateContext,
        array_merge($downloadScopePayload, [
            'fileName' => '历史成功任务保守拒绝.xlsx',
        ])
    );
    $legacyScopeTaskNo = (string)($legacyScopeTask['taskId'] ?? '');
    if ($legacyScopeTaskNo !== '') {
        $dynamicTaskNos[] = $legacyScopeTaskNo;
    }
    Db::name(UnifiedQueryExportTaskServices::TABLE)
        ->where('task_no', $legacyScopeTaskNo)
        ->update([
            'status' => 'succeeded',
            'storage_key' => 'unified-query-exports/2026/07/'
                . $legacyScopeTaskNo . '-' . str_repeat('f', 64) . '.xlsx',
            'completed_at' => time(),
            'expires_at' => time() + 3600,
            'updated_at' => time(),
        ]);
    $legacyScopeDownloadFailure = uqGenFailure(function () use (
        $scopeExports,
        $downloadScopeGenerationContext,
        $legacyScopeTaskNo
    ): void {
        $scopeExports->resolveDownloadDescriptor(
            $downloadScopeGenerationContext,
            $legacyScopeTaskNo
        );
    });

    $expectedGeneratedScope = [
        'all_stores' => false,
        'ancestor_organization_ids' => ['org-8', 'org-9'],
        'scope_dimensions' => [
            'location_ids' => ['location-1', 'location-2'],
        ],
        'visible_store_ids' => [8, 9],
    ];
    $expectedNarrowReclaimScope = [
        'all_stores' => false,
        'ancestor_organization_ids' => ['org-8'],
        'scope_dimensions' => ['location_ids' => ['location-1']],
        'visible_store_ids' => [8],
    ];
    $downloadScopeShrinkCodes = array_column(
        $downloadScopeShrinkFailures,
        'code'
    );
    $downloadScopeStorageKey = (string)(
        $downloadScopeWorkerResult['storageKey'] ?? ''
    );
    ok(
        '生成与重领单调收窄冻结范围且下载重验当前门店、组织和页面维度',
        ($downloadScopeWorkerResult['status'] ?? '') === 'succeeded'
            && $downloadScopeFrozenAfterGeneration === $expectedGeneratedScope
            && (string)($downloadScopeSameDescriptor['storageKey'] ?? '')
                === $downloadScopeStorageKey
            && (string)($downloadScopeSupersetDescriptor['storageKey'] ?? '')
                === $downloadScopeStorageKey
            && (string)($downloadScopeAllStoresDescriptor['storageKey'] ?? '')
                === $downloadScopeStorageKey
            && count($downloadScopeShrinkCodes) === 3
            && count(array_unique($downloadScopeShrinkCodes)) === 1
            && reset($downloadScopeShrinkCodes)
                === 'UNIFIED_QUERY_EXPORT_DOWNLOAD_SCOPE_REVOKED'
            && $downloadScopeProviderCalls === 1
            && $downloadScopeWriteCalls === 1
            && count($downloadScopeInventoryProvider->frozenCalls) === 1
            && array_sum($downloadScopeWorker->writeCalls) === 1
            && $firstReclaimFrozen === $expectedGeneratedScope
            && ($firstReclaim['effectiveDataScope']['scope_dimensions'] ?? null)
                === $expectedGeneratedScope['scope_dimensions']
            && $secondReclaimFrozen === $expectedNarrowReclaimScope
            && ($secondReclaim['effectiveDataScope']['scope_dimensions'] ?? null)
                === $expectedNarrowReclaimScope['scope_dimensions']
            && $thirdReclaimFrozen === $expectedNarrowReclaimScope
            && ($thirdReclaim['effectiveDataScope']['visible_store_ids'] ?? null)
                === [8]
            && (int)($thirdReclaimRow['attempt_count'] ?? 0) === 3
            && $legacyScopeDownloadFailure['code']
                === 'UNIFIED_QUERY_EXPORT_DOWNLOAD_SCOPE_REVOKED',
        json_encode(compact(
            'downloadScopeWorkerResult',
            'downloadScopeFrozenAfterGeneration',
            'downloadScopeShrinkFailures',
            'firstReclaimFrozen',
            'secondReclaimFrozen',
            'thirdReclaimFrozen',
            'legacyScopeDownloadFailure'
        ), JSON_UNESCAPED_UNICODE),
        'UQ-DOWNLOAD-SCOPE-01'
    );

    $legacyMemberContext = uqGenWorkerContext('member_list', [
        'visible_store_ids' => [8],
        'ancestor_organization_ids' => ['org-8'],
        'scope_dimensions' => [],
        'query_cutoff_date' => '2026-07-28',
        'data_as_of' => 1785258000,
    ]);
    $legacyMemberPayload = [
        'pageCode' => 'member_list',
        'scope' => 'query',
        'queryCutoffDate' => '2026-07-28',
        'query' => [
            'pageCode' => 'member_list',
            'page' => 1,
            'pageSize' => 20,
            'visibleFields' => ['member_name'],
        ],
        'fields' => ['member_name'],
        'includeSummary' => false,
        'fileName' => '会员历史冻结范围.xlsx',
    ];
    $legacyThreeKeyScope = [
        'all_stores' => false,
        'visible_store_ids' => [8],
        'ancestor_organization_ids' => ['org-8'],
    ];
    $legacyMemberPendingTask = $scopeExports->create(
        $legacyMemberContext,
        $legacyMemberPayload
    );
    $legacyMemberPendingTaskNo = (string)(
        $legacyMemberPendingTask['taskId'] ?? ''
    );
    $legacyMemberRunningTask = $scopeExports->create(
        $legacyMemberContext,
        array_merge($legacyMemberPayload, [
            'fileName' => '会员历史运行任务.xlsx',
        ])
    );
    $legacyMemberRunningTaskNo = (string)(
        $legacyMemberRunningTask['taskId'] ?? ''
    );
    $legacyMemberSucceededTask = $scopeExports->create(
        $legacyMemberContext,
        array_merge($legacyMemberPayload, [
            'fileName' => '会员历史成功任务.xlsx',
        ])
    );
    $legacyMemberSucceededTaskNo = (string)(
        $legacyMemberSucceededTask['taskId'] ?? ''
    );
    $scopedMissingDimensionsTask = $scopeExports->create(
        $downloadScopeGenerationContext,
        array_merge($downloadScopePayload, [
            'fileName' => '库存缺失冻结维度.xlsx',
        ])
    );
    $scopedMissingDimensionsTaskNo = (string)(
        $scopedMissingDimensionsTask['taskId'] ?? ''
    );
    foreach ([
        $legacyMemberPendingTaskNo,
        $legacyMemberRunningTaskNo,
        $legacyMemberSucceededTaskNo,
        $scopedMissingDimensionsTaskNo,
    ] as $taskNo) {
        if ($taskNo !== '') {
            $dynamicTaskNos[] = $taskNo;
        }
    }
    $legacyScopeJson = UnifiedQueryJson::encode($legacyThreeKeyScope);
    Db::name(UnifiedQueryExportTaskServices::TABLE)
        ->where('task_no', $legacyMemberPendingTaskNo)
        ->update(['frozen_scope' => $legacyScopeJson]);
    Db::name(UnifiedQueryExportTaskServices::TABLE)
        ->where('task_no', $legacyMemberRunningTaskNo)
        ->update([
            'status' => 'running',
            'frozen_scope' => $legacyScopeJson,
            'lease_token' => str_repeat('c', 64),
            'lease_expires_at' => time() - 1,
            'attempt_count' => 1,
        ]);
    $legacySucceededStorageKey = 'unified-query-exports/2026/07/'
        . $legacyMemberSucceededTaskNo . '-' . str_repeat('d', 64) . '.xlsx';
    Db::name(UnifiedQueryExportTaskServices::TABLE)
        ->where('task_no', $legacyMemberSucceededTaskNo)
        ->update([
            'status' => 'succeeded',
            'frozen_scope' => $legacyScopeJson,
            'storage_key' => $legacySucceededStorageKey,
            'completed_at' => time(),
            'expires_at' => time() + 3600,
            'updated_at' => time(),
        ]);
    Db::name(UnifiedQueryExportTaskServices::TABLE)
        ->where('task_no', $scopedMissingDimensionsTaskNo)
        ->update(['frozen_scope' => $legacyScopeJson]);
    $legacyMemberPendingClaim = $scopeExports->claim(
        $legacyMemberContext,
        $legacyMemberPendingTaskNo
    );
    $legacyMemberRunningClaim = $scopeExports->claim(
        $legacyMemberContext,
        $legacyMemberRunningTaskNo
    );
    $legacyMemberPendingFrozen = UnifiedQueryJson::decode((string)(
        Db::name(UnifiedQueryExportTaskServices::TABLE)
            ->where('task_no', $legacyMemberPendingTaskNo)
            ->value('frozen_scope')
    ));
    $legacyMemberRunningFrozen = UnifiedQueryJson::decode((string)(
        Db::name(UnifiedQueryExportTaskServices::TABLE)
            ->where('task_no', $legacyMemberRunningTaskNo)
            ->value('frozen_scope')
    ));
    $legacyMemberSucceededDescriptor = $scopeExports->resolveDownloadDescriptor(
        $legacyMemberContext,
        $legacyMemberSucceededTaskNo
    );
    $scopedMissingDimensionsFailure = uqGenFailure(function () use (
        $scopeExports,
        $downloadScopeGenerationContext,
        $scopedMissingDimensionsTaskNo
    ): void {
        $scopeExports->claim(
            $downloadScopeGenerationContext,
            $scopedMissingDimensionsTaskNo
        );
    });
    $legacyCanonicalMemberScope = [
        'all_stores' => false,
        'ancestor_organization_ids' => ['org-8'],
        'scope_dimensions' => [],
        'visible_store_ids' => [8],
    ];
    ok(
        '旧会员三键冻结范围可领取重领和下载且有页面维度时不得缺省',
        ($legacyMemberPendingClaim['claimed'] ?? false) === true
            && ($legacyMemberRunningClaim['claimed'] ?? false) === true
            && $legacyMemberPendingFrozen === $legacyCanonicalMemberScope
            && $legacyMemberRunningFrozen === $legacyCanonicalMemberScope
            && (string)($legacyMemberSucceededDescriptor['storageKey'] ?? '')
                === $legacySucceededStorageKey
            && $scopedMissingDimensionsFailure['code']
                === 'UNIFIED_QUERY_FROZEN_SCOPE_INVALID',
        json_encode(compact(
            'legacyMemberPendingFrozen',
            'legacyMemberRunningFrozen',
            'legacyMemberSucceededDescriptor',
            'scopedMissingDimensionsFailure'
        ), JSON_UNESCAPED_UNICODE),
        'UQ-FROZEN-SCOPE-COMPAT-01'
    );

    Db::name(UnifiedQueryExportTaskServices::TABLE)
        ->whereIn('task_no', array_values($taskNos))
        ->delete();
    uqGenSeedTask($taskNos['inventory'], 'inventory_batch_stock');
    uqGenSeedTask($taskNos['member'], 'member_list');
    uqGenSeedTask($taskNos['unknown'], 'unknown_page');
    uqGenSeedTask($taskNos['mismatch'], 'inventory_batch_stock');
    uqGenSeedTask($taskNos['cross_page'], 'inventory_batch_stock');
    uqGenSeedTask($taskNos['context_invalid'], 'inventory_batch_stock');
    $crossPageStorageKey = 'unified-query-exports/2026/07/'
        . $taskNos['cross_page'] . '-' . str_repeat('e', 64) . '.xlsx';
    Db::name(UnifiedQueryExportTaskServices::TABLE)
        ->where('task_no', $taskNos['cross_page'])
        ->update([
            'status' => 'succeeded',
            'storage_key' => $crossPageStorageKey,
            'completed_at' => time(),
            'expires_at' => time() + 3600,
            'updated_at' => time(),
        ]);

    $exportTasks = uqGenExportTasks($pages);
    $exportContext = [
        'tenant_id' => 'tenant-uq-gen',
        'account_id' => 1,
        'operator_id' => 1,
        'store_id' => 8,
        'organization_id' => 'org-8',
        'visible_store_ids' => [8],
        'ancestor_organization_ids' => ['org-8'],
        'permissions' => [
            UnifiedQueryAccessPolicy::PAGE_POLICY,
            UnifiedQueryAccessPolicy::EXPORT,
        ],
    ];
    $missingPageFind = uqGenFailure(function () use (
        $exportTasks,
        $exportContext,
        $taskNos
    ): void {
        $exportTasks->findForAccount($exportContext, $taskNos['cross_page']);
    });
    $missingPageDownload = uqGenFailure(function () use (
        $exportTasks,
        $exportContext,
        $taskNos
    ): void {
        $exportTasks->resolveDownload($exportContext, $taskNos['cross_page']);
    });
    $memberExportContext = array_merge($exportContext, ['page_code' => 'member_list']);
    $memberPageFind = uqGenFailure(function () use (
        $exportTasks,
        $memberExportContext,
        $taskNos
    ): void {
        $exportTasks->findForAccount($memberExportContext, $taskNos['cross_page']);
    });
    $memberPageDownload = uqGenFailure(function () use (
        $exportTasks,
        $memberExportContext,
        $taskNos
    ): void {
        $exportTasks->resolveDownload($memberExportContext, $taskNos['cross_page']);
    });
    $inventoryExportContext = array_merge(
        $exportContext,
        ['page_code' => 'inventory_batch_stock']
    );
    $inventoryExportTask = $exportTasks->findForAccount(
        $inventoryExportContext,
        $taskNos['cross_page']
    );
    ok(
        '导出轮询和下载绑定当前页面且缺页或跨页均失败关闭',
        $missingPageFind['code'] === 'UNIFIED_QUERY_EXPORT_PAGE_MISMATCH'
            && $missingPageDownload['code'] === 'UNIFIED_QUERY_EXPORT_PAGE_MISMATCH'
            && $memberPageFind['code'] === 'UNIFIED_QUERY_EXPORT_PAGE_MISMATCH'
            && $memberPageDownload['code'] === 'UNIFIED_QUERY_EXPORT_PAGE_MISMATCH'
            && ($inventoryExportTask['pageCode'] ?? '') === 'inventory_batch_stock',
        json_encode(compact(
            'missingPageFind',
            'missingPageDownload',
            'memberPageFind',
            'memberPageDownload',
            'inventoryExportTask'
        ), JSON_UNESCAPED_UNICODE),
        'UQ-EXPORT-PAGE-01'
    );

    $validWorkerContext = uqGenWorkerContext('inventory_batch_stock');
    $missingVisibleStores = $validWorkerContext;
    unset($missingVisibleStores['visible_store_ids']);
    $invalidWorkerContexts = [
        'tenant_mismatch' => [
            'context' => array_merge($validWorkerContext, ['tenant_id' => 'tenant-other']),
            'code' => 'UNIFIED_QUERY_WORKER_CONTEXT_INVALID',
        ],
        'account_mismatch' => [
            'context' => array_merge($validWorkerContext, ['account_id' => 2]),
            'code' => 'UNIFIED_QUERY_WORKER_CONTEXT_INVALID',
        ],
        'operator_mismatch' => [
            'context' => array_merge($validWorkerContext, ['operator_id' => 2]),
            'code' => 'UNIFIED_QUERY_WORKER_CONTEXT_INVALID',
        ],
        'page_mismatch' => [
            'context' => array_merge($validWorkerContext, ['page_code' => 'member_list']),
            'code' => 'UNIFIED_QUERY_WORKER_CONTEXT_INVALID',
        ],
        'wildcard_permission' => [
            'context' => array_merge($validWorkerContext, [
                'permissions' => [
                    '*',
                    UnifiedQueryAccessPolicy::PAGE_POLICY,
                    UnifiedQueryAccessPolicy::EXPORT,
                ],
            ]),
            'code' => 'UNIFIED_QUERY_WORKER_CONTEXT_INVALID',
        ],
        'page_permission_revoked' => [
            'context' => array_merge($validWorkerContext, [
                'permissions' => [UnifiedQueryAccessPolicy::EXPORT],
            ]),
            'code' => 'UNIFIED_QUERY_EXPORT_PERMISSION_REVOKED',
        ],
        'export_permission_revoked' => [
            'context' => array_merge($validWorkerContext, [
                'permissions' => [UnifiedQueryAccessPolicy::PAGE_POLICY],
            ]),
            'code' => 'UNIFIED_QUERY_EXPORT_PERMISSION_REVOKED',
        ],
        'visible_stores_missing' => [
            'context' => $missingVisibleStores,
            'code' => 'UNIFIED_QUERY_WORKER_CONTEXT_INVALID',
        ],
        'visible_stores_invalid' => [
            'context' => array_merge($validWorkerContext, [
                'visible_store_ids' => ['first' => 8],
            ]),
            'code' => 'UNIFIED_QUERY_WORKER_CONTEXT_INVALID',
        ],
        'scope_missing' => [
            'context' => array_merge($validWorkerContext, ['scope_dimensions' => []]),
            'code' => 'UNIFIED_QUERY_WORKER_CONTEXT_INVALID',
        ],
        'scope_unknown' => [
            'context' => array_merge($validWorkerContext, [
                'scope_dimensions' => [
                    'location_ids' => ['location-8'],
                    'warehouse_ids' => ['warehouse-8'],
                ],
            ]),
            'code' => 'UNIFIED_QUERY_WORKER_CONTEXT_INVALID',
        ],
        'scope_empty' => [
            'context' => array_merge($validWorkerContext, [
                'scope_dimensions' => ['location_ids' => []],
            ]),
            'code' => 'UNIFIED_QUERY_WORKER_CONTEXT_INVALID',
        ],
        'permission_version_missing' => [
            'context' => array_merge($validWorkerContext, ['permission_version' => '']),
            'code' => 'UNIFIED_QUERY_WORKER_CONTEXT_INVALID',
        ],
        'cutoff_invalid' => [
            'context' => array_merge($validWorkerContext, [
                'query_cutoff_date' => '2099-02-30',
            ]),
            'code' => 'UNIFIED_QUERY_WORKER_CONTEXT_INVALID',
        ],
        'data_as_of_invalid' => [
            'context' => array_merge($validWorkerContext, ['data_as_of' => 0]),
            'code' => 'UNIFIED_QUERY_WORKER_CONTEXT_INVALID',
        ],
    ];
    $invalidWorkerResults = [];
    $invalidWorkerClaimCount = 0;
    $invalidWorkerWriteCount = 0;
    $inventoryCallsBeforeInvalidContexts = count($inventoryProvider->frozenCalls);
    foreach ($invalidWorkerContexts as $case => $expectation) {
        $invalidContextTasks = new UqGenTaskServices([]);
        $invalidContextWorker = new UqGenWorker(
            $invalidContextTasks,
            $providers,
            uqGenWorkerResolverRegistry($pages, $expectation['context'])
        );
        $result = $invalidContextWorker->processOne($taskNos['context_invalid']);
        $invalidWorkerResults[$case] = [
            'expected' => $expectation['code'],
            'actual' => (string)($result['errorCode'] ?? ''),
            'diagnostic' => (string)($result['diagnostic'] ?? ''),
        ];
        $invalidWorkerClaimCount += count($invalidContextTasks->claimCalls);
        $invalidWorkerWriteCount += array_sum($invalidContextWorker->writeCalls);
    }
    $invalidWorkerCodesMatch = true;
    foreach ($invalidWorkerResults as $result) {
        if ($result['actual'] !== $result['expected']) {
            $invalidWorkerCodesMatch = false;
            break;
        }
    }
    ok(
        'Worker 在 claim 和业务读取前重验身份、权限、范围与统计时点',
        $invalidWorkerCodesMatch
            && $invalidWorkerClaimCount === 0
            && count($inventoryProvider->frozenCalls) === $inventoryCallsBeforeInvalidContexts
            && $invalidWorkerWriteCount === 0,
        json_encode(compact(
            'invalidWorkerResults',
            'invalidWorkerClaimCount',
            'invalidWorkerWriteCount'
        ), JSON_UNESCAPED_UNICODE),
        'UQ-WORKER-CONTEXT-01'
    );

    $claims = [
        $taskNos['inventory'] => uqGenClaim(
            $taskNos['inventory'],
            'inventory_batch_stock',
            'inventory_batch_stock',
            'batch_no',
            'a'
        ),
        $taskNos['member'] => uqGenClaim(
            $taskNos['member'],
            'member_list',
            'member_list',
            'member_name',
            'b'
        ),
        $taskNos['mismatch'] => uqGenClaim(
            $taskNos['mismatch'],
            'inventory_batch_stock',
            'member_list',
            'batch_no',
            'd'
        ),
    ];
    $tasks = new UqGenTaskServices($claims);
    $worker = new UqGenWorker(
        $tasks,
        $providers,
        $workerResolvers
    );
    $inventoryResult = $worker->processOne($taskNos['inventory']);
    $memberResult = $worker->processOne($taskNos['member']);
    $unknownResult = $worker->processOne($taskNos['unknown']);
    $mismatchResult = $worker->processOne($taskNos['mismatch']);
    $claimedTaskNos = array_column($tasks->claimCalls, 'taskNo');
    $preflightCallCount = count($tasks->preflightCalls);
    ok(
        'worker 按任务 page_code 分派且未知页或冻结计划错页均不生成文件',
        ($inventoryResult['status'] ?? '') === 'succeeded'
            && ($memberResult['status'] ?? '') === 'succeeded'
            && ($unknownResult['errorCode'] ?? '') === 'UNIFIED_QUERY_PAGE_NOT_ALLOWED'
            && ($mismatchResult['errorCode'] ?? '') === 'UNIFIED_QUERY_EXPORT_PLAN_INVALID'
            && count($inventoryProvider->frozenCalls) === 1
            && count($memberProvider->frozenCalls) === 1
            && (int)($worker->writeCalls[$taskNos['inventory']] ?? 0) === 1
            && (int)($worker->writeCalls[$taskNos['member']] ?? 0) === 1
            && !isset($worker->writeCalls[$taskNos['unknown']])
            && !isset($worker->writeCalls[$taskNos['mismatch']])
            && !in_array($taskNos['unknown'], $claimedTaskNos, true)
            && in_array($taskNos['mismatch'], $claimedTaskNos, true)
            && $preflightCallCount === 2
            && count($tasks->failPendingCalls) === 1
            && count($tasks->failCalls) === 1,
        json_encode(compact(
            'inventoryResult',
            'memberResult',
            'unknownResult',
            'mismatchResult',
            'claimedTaskNos',
            'preflightCallCount'
        ), JSON_UNESCAPED_UNICODE),
        'UQ-MULTI-04'
    );

    $inventoryCall = $inventoryProvider->frozenCalls[0] ?? [];
    $workerContext = (array)($inventoryCall['context'] ?? []);
    $workerPlan = (array)($inventoryCall['plan'] ?? []);
    UnifiedQueryRuntime::resetForTests();
    $runtime = UnifiedQueryRuntime::runtime();
    $runtimeProviders = $runtime['providers'] ?? null;
    $runtimeWorkerContextResolvers = $runtime['workerContextResolvers'] ?? null;
    $runtimeCommandCoordinator = $runtime['commandCoordinator'] ?? null;
    ok(
        'worker 使用当前权限和截止日执行冻结计划且 Runtime 仅暴露中立注册表',
        ($workerContext['visible_store_ids'] ?? null) === [8]
            && ($workerContext['all_stores'] ?? null) === false
            && ($workerContext['ancestor_organization_ids'] ?? null) === ['org-8']
            && ($workerContext['scope_dimensions'] ?? null) === [
                'location_ids' => ['location-8'],
            ]
            && ($workerContext['query_cutoff_date'] ?? '') === '2026-07-28'
            && (int)($workerContext['data_as_of'] ?? 0) === 1785258000
            && ($workerPlan['page_code'] ?? '') === 'inventory_batch_stock'
            && ($inventoryCall['fieldKeys'] ?? null) === ['batch_no']
            && $runtimeProviders instanceof UnifiedQueryProviderRegistry
            && $runtimeProviders->isFrozen()
            && !array_key_exists('memberProvider', $runtime)
            && $runtimeProviders->resolve('member_list') instanceof UnifiedQueryProvider
            && UnifiedQueryRuntime::service('providers') === $runtimeProviders
            && $runtimeWorkerContextResolvers
                instanceof UnifiedQueryWorkerContextResolverRegistry
            && $runtimeWorkerContextResolvers->isFrozen()
            && $runtimeWorkerContextResolvers->resolve('member_list')
                instanceof UnifiedQueryWorkerContextResolver
            && UnifiedQueryRuntime::service('workerContextResolvers')
                === $runtimeWorkerContextResolvers,
        json_encode([
            'workerContext' => $workerContext,
            'workerPlan' => $workerPlan,
            'runtimeProviderPages' => $runtimeProviders instanceof UnifiedQueryProviderRegistry
                ? $runtimeProviders->pageCodes()
                : [],
            'runtimeWorkerResolverPages' => $runtimeWorkerContextResolvers
                instanceof UnifiedQueryWorkerContextResolverRegistry
                ? $runtimeWorkerContextResolvers->pageCodes()
                : [],
        ], JSON_UNESCAPED_UNICODE),
        'UQ-MULTI-05'
    );

    $commandPreferences = new UqGenPreferenceServices();
    $commandCoordinator = uqGenCommandCoordinator($pages, $commandPreferences);
    $supportedActions = $commandCoordinator->supportedActions();
    $unknownCommand = uqGenFailure(function () use ($commandCoordinator): void {
        $commandCoordinator->dispatch('unknown-unified-query-command', [], []);
    });
    $invalidGatewayVersions = [];
    foreach (['missing' => null, 'zero' => 0, 'negative' => -1] as $case => $version) {
        $context = [];
        if ($version !== null) {
            $context['query_preference_version'] = $version;
        }
        $invalidGatewayVersions[$case] = uqGenFailure(function () use (
            $commandCoordinator,
            $context
        ): void {
            $commandCoordinator->dispatch(
                UnifiedQueryCommandCoordinator::SAVE_SETTINGS,
                $context,
                ['pageCode' => 'inventory_batch_stock']
            );
        });
    }
    $pageConflict = uqGenFailure(function () use ($commandCoordinator): void {
        $commandCoordinator->dispatch(
            UnifiedQueryCommandCoordinator::SAVE_SETTINGS,
            [
                'query_preference_version' => 1,
                'page_code' => 'member_list',
            ],
            ['pageCode' => 'inventory_batch_stock']
        );
    });
    $inventorySaveResult = $commandCoordinator->dispatch(
        UnifiedQueryCommandCoordinator::SAVE_SETTINGS,
        [
            'query_preference_version' => 1,
            'page_code' => 'inventory_batch_stock',
        ],
        ['pageCode' => 'inventory_batch_stock']
    );
    $legacyCoreFailure = uqGenFailure(function () use ($commandCoordinator): void {
        $commandCoordinator->dispatch(
            'save-member-query-settings',
            [
                'query_preference_version' => 1,
                'page_code' => 'inventory_batch_stock',
            ],
            ['pageCode' => 'inventory_batch_stock']
        );
    });
    $cashierUnifiedQuerySource = (string)file_get_contents(
        '/var/www/html/app/services/cashier/v3/query/UnifiedQueryModule.php'
    );
    $invalidGatewayVersionCodes = array_column($invalidGatewayVersions, 'code');
    ok(
        '中立命令支持库存页且会员 legacy 动作只由收银宿主映射',
        in_array(
            UnifiedQueryCommandCoordinator::SAVE_SETTINGS,
            $supportedActions,
            true
        )
            && !in_array('save-member-query-settings', $supportedActions, true)
            && $unknownCommand['code'] === 'UNIFIED_QUERY_COMMAND_NOT_ALLOWED'
            && count($invalidGatewayVersionCodes) === 3
            && count(array_unique($invalidGatewayVersionCodes)) === 1
            && reset($invalidGatewayVersionCodes)
                === 'UNIFIED_QUERY_GATEWAY_VERSION_REQUIRED'
            && $pageConflict['code'] === 'UNIFIED_QUERY_PAGE_CONFLICT'
            && ($inventorySaveResult['data']['pageCode'] ?? '')
                === 'inventory_batch_stock'
            && count($commandPreferences->saveCalls) === 1
            && ($commandPreferences->saveCalls[0]['payload']['pageCode'] ?? '')
                === 'inventory_batch_stock'
            && $legacyCoreFailure['code'] === 'UNIFIED_QUERY_COMMAND_NOT_ALLOWED'
            && strpos(
                $neutralCoreSources['command_coordinator'],
                'save-member-query-settings'
            ) === false
            && strpos(
                $cashierUnifiedQuerySource,
                "private const LEGACY_SAVE_MEMBER_SETTINGS = 'save-member-query-settings';"
            ) !== false
            && strpos(
                $cashierUnifiedQuerySource,
                "if (\$payload['pageCode'] !== MemberUnifiedQueryProvider::PAGE_CODE)"
            ) !== false
            && strpos(
                $cashierUnifiedQuerySource,
                '$coordinatorAction = UnifiedQueryCommandCoordinator::SAVE_SETTINGS;'
            ) !== false
            && $runtimeCommandCoordinator instanceof UnifiedQueryCommandCoordinator
            && UnifiedQueryRuntime::service('commandCoordinator')
                === $runtimeCommandCoordinator,
        json_encode(compact(
            'supportedActions',
            'unknownCommand',
            'invalidGatewayVersions',
            'pageConflict',
            'inventorySaveResult',
            'legacyCoreFailure'
        ), JSON_UNESCAPED_UNICODE),
        'UQ-CMD-01'
    );
} catch (\Throwable $throwable) {
    ok(
        '统一查询多页面泛化合同未发生未捕获异常',
        false,
        get_class($throwable) . ': ' . $throwable->getMessage()
            . "\n" . $throwable->getTraceAsString()
    );
} finally {
    try {
        Db::name(UnifiedQueryExportTaskServices::TABLE)
            ->whereIn('task_no', array_values($taskNos))
            ->delete();
        if ($dynamicTaskNos) {
            Db::name('unified_query_field_reference')
                ->where('consumer_type', 'export_task')
                ->whereIn('consumer_id', $dynamicTaskNos)
                ->delete();
            Db::name(UnifiedQueryExportTaskServices::TABLE)
                ->whereIn('task_no', $dynamicTaskNos)
                ->delete();
        }
        if ($dynamicFieldKeys) {
            Db::name('unified_query_field_reference')
                ->whereIn('field_key', $dynamicFieldKeys)
                ->delete();
            Db::name('unified_query_custom_field_version')
                ->whereIn('field_key', $dynamicFieldKeys)
                ->delete();
            Db::name('unified_query_custom_field')
                ->whereIn('field_key', $dynamicFieldKeys)
                ->delete();
        }
    } catch (\Throwable $ignored) {
        // 主异常由上方断言报告；清理保持幂等。
    }
}

finish('unified-query-generalization-contract');
