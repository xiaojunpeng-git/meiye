<?php

/**
 * 统一查询真实 Gateway、会员投影、XLSX worker 与下载权限集成门禁。
 */
require __DIR__ . '/../../cashier-v3/lib/boot-env.php';
require '/var/www/html/vendor/autoload.php';
require __DIR__ . '/../../cashier-v3/lib/_lib.php';
require __DIR__ . '/../../cashier-v3/lib/TestGraphFactory.php';
require __DIR__ . '/../../cashier-v3/lib/MemberIntegrationFixture.php';

use app\services\cashier\v3\query\UnifiedQueryModule;
use app\services\cashier\v3\CashierV3CommandException;
use app\services\query\UnifiedQueryAccessPolicy;
use app\services\query\UnifiedQueryException;
use app\services\query\UnifiedQueryExportTaskServices;
use app\services\query\UnifiedQueryExportWorkerServices;
use app\services\query\UnifiedQueryJson;
use app\services\query\UnifiedQueryProviderRegistry;
use app\services\query\UnifiedQueryWorkerContextResolverRegistry;
use app\controller\cashier\v3\Command as UnifiedQueryCommandController;
use app\services\system\SystemMenusServices;
use C1A\CashierV3\Test\MemberIntegrationFixture;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use think\facade\Db;
use think\response\File as FileResponse;

c1aBootThinkApp('/var/www/html/');
date_default_timezone_set('Asia/Shanghai');

/**
 * 保持生产 FeatureResolver 的菜单链路：普通测试账号只通过容器内的菜单服务
 * 获得会员入口，不能伪造 profile.unique_auth。
 */
final class UqGwTestSystemMenusServices extends SystemMenusServices
{
    public function __construct()
    {
    }

    public function getMenusList($roleId, int $level, int $type = 1, int $adminType = 0)
    {
        $roles = is_array($roleId) ? $roleId : [$roleId];
        if ($type === 3 && $level > 0 && in_array(901, array_map('intval', $roles), true)) {
            return [[], ['cashier-recharge-index']];
        }
        return [[], []];
    }
}

MemberIntegrationFixture::bindTestAdapters();
$container = app();
$menuServices = new UqGwTestSystemMenusServices();
if (method_exists($container, 'instance')) {
    $container->instance(SystemMenusServices::class, $menuServices);
} elseif (method_exists($container, 'bindTo')) {
    $container->bindTo(SystemMenusServices::class, $menuServices);
} else {
    throw new \RuntimeException('TEST_MENU_CONTAINER_BIND_UNAVAILABLE');
}

final class UqGwAmbiguousCompleteTasks extends UnifiedQueryExportTaskServices
{
    /** @var UnifiedQueryExportTaskServices */
    private $delegate;

    public function __construct(UnifiedQueryExportTaskServices $delegate)
    {
        $this->delegate = $delegate;
    }

    public function claim(array $rawContext, string $taskNo): array
    {
        return $this->delegate->claim($rawContext, $taskNo);
    }

    public function preflightFrozenExecution(
        array $rawContext,
        array $task,
        array $plan,
        array $fieldSnapshot
    ): void {
        $this->delegate->preflightFrozenExecution(
            $rawContext,
            $task,
            $plan,
            $fieldSnapshot
        );
    }

    public function renewLease(
        string $tenantId,
        string $taskNo,
        string $leaseToken,
        int $seconds = 300
    ): int {
        return $this->delegate->renewLease($tenantId, $taskNo, $leaseToken, $seconds);
    }

    public function complete(
        string $tenantId,
        string $taskNo,
        int $resultCount,
        string $storageKey,
        int $expiresAt,
        string $leaseToken
    ): void {
        $this->delegate->complete(
            $tenantId,
            $taskNo,
            $resultCount,
            $storageKey,
            $expiresAt,
            $leaseToken
        );
        throw new \RuntimeException('模拟提交成功后连接中断');
    }

    public function fail(
        string $tenantId,
        string $taskNo,
        string $reason,
        string $leaseToken
    ): void {
        $this->delegate->fail($tenantId, $taskNo, $reason, $leaseToken);
    }

    public function failPending(string $tenantId, string $taskNo, string $reason): void
    {
        $this->delegate->failPending($tenantId, $taskNo, $reason);
    }
}

/**
 * 模拟 complete 前恰好失租：旧 worker 的对象尚未写入任务行，必须只清理自己的
 * token 对象；随后新的 worker 应可接管同一任务并产生不同的对象键。
 */
final class UqGwExpiredLeaseCompleteTasks extends UnifiedQueryExportTaskServices
{
    /** @var UnifiedQueryExportTaskServices */
    private $delegate;

    /** @var string */
    public $attemptedStorageKey = '';

    public function __construct(UnifiedQueryExportTaskServices $delegate)
    {
        $this->delegate = $delegate;
    }

    public function claim(array $rawContext, string $taskNo): array
    {
        return $this->delegate->claim($rawContext, $taskNo);
    }

    public function preflightFrozenExecution(
        array $rawContext,
        array $task,
        array $plan,
        array $fieldSnapshot
    ): void {
        $this->delegate->preflightFrozenExecution(
            $rawContext,
            $task,
            $plan,
            $fieldSnapshot
        );
    }

    public function renewLease(
        string $tenantId,
        string $taskNo,
        string $leaseToken,
        int $seconds = 300
    ): int {
        return $this->delegate->renewLease($tenantId, $taskNo, $leaseToken, $seconds);
    }

    public function complete(
        string $tenantId,
        string $taskNo,
        int $resultCount,
        string $storageKey,
        int $expiresAt,
        string $leaseToken
    ): void {
        $this->attemptedStorageKey = $storageKey;
        $expired = Db::name(UnifiedQueryExportTaskServices::TABLE)
            ->where('tenant_id', $tenantId)
            ->where('task_no', $taskNo)
            ->where('status', 'running')
            ->where('lease_token', $leaseToken)
            ->update([
                'lease_expires_at' => time() - 1,
                'updated_at' => time(),
            ]);
        if ((int)$expired !== 1) {
            throw new \RuntimeException('测试未能制造失租状态');
        }
        // 仍走生产 CAS，确保回归覆盖的是 complete() 因失租返回失败的真实路径。
        $this->delegate->complete(
            $tenantId,
            $taskNo,
            $resultCount,
            $storageKey,
            $expiresAt,
            $leaseToken
        );
    }

    public function fail(
        string $tenantId,
        string $taskNo,
        string $reason,
        string $leaseToken
    ): void {
        $this->delegate->fail($tenantId, $taskNo, $reason, $leaseToken);
    }

    public function failPending(string $tenantId, string $taskNo, string $reason): void
    {
        $this->delegate->failPending($tenantId, $taskNo, $reason);
    }
}

/**
 * 真实 worker 续租路径的观察代理；只记录委派给生产任务服务的 CAS，不伪造结果。
 */
final class UqGwLeaseTrackingTasks extends UnifiedQueryExportTaskServices
{
    /** @var UnifiedQueryExportTaskServices */
    private $delegate;

    /** @var array<int,array<string,mixed>> */
    public $renewals = [];

    /** @var array<int,array<string,mixed>> */
    public $claimContexts = [];

    public function __construct(UnifiedQueryExportTaskServices $delegate)
    {
        $this->delegate = $delegate;
    }

    public function claim(array $rawContext, string $taskNo): array
    {
        $this->claimContexts[] = $rawContext;
        return $this->delegate->claim($rawContext, $taskNo);
    }

    public function preflightFrozenExecution(
        array $rawContext,
        array $task,
        array $plan,
        array $fieldSnapshot
    ): void {
        $this->delegate->preflightFrozenExecution(
            $rawContext,
            $task,
            $plan,
            $fieldSnapshot
        );
    }

    public function renewLease(
        string $tenantId,
        string $taskNo,
        string $leaseToken,
        int $seconds = 300
    ): int {
        $this->renewals[] = [
            'tenantId' => $tenantId,
            'taskNo' => $taskNo,
            'leaseToken' => $leaseToken,
            'seconds' => $seconds,
        ];
        return $this->delegate->renewLease($tenantId, $taskNo, $leaseToken, $seconds);
    }

    public function complete(
        string $tenantId,
        string $taskNo,
        int $resultCount,
        string $storageKey,
        int $expiresAt,
        string $leaseToken
    ): void {
        $this->delegate->complete(
            $tenantId,
            $taskNo,
            $resultCount,
            $storageKey,
            $expiresAt,
            $leaseToken
        );
    }

    public function fail(
        string $tenantId,
        string $taskNo,
        string $reason,
        string $leaseToken
    ): void {
        $this->delegate->fail($tenantId, $taskNo, $reason, $leaseToken);
    }

    public function failPending(string $tenantId, string $taskNo, string $reason): void
    {
        $this->delegate->failPending($tenantId, $taskNo, $reason);
    }

    public function failExpiredRunning(string $tenantId, string $taskNo, string $reason): bool
    {
        return $this->delegate->failExpiredRunning($tenantId, $taskNo, $reason);
    }
}

function uqGwSection(string $title): void
{
    echo "\n== {$title} ==\n";
}

function uqGwRequestId(string $prefix): string
{
    return $prefix . '-' . uuid();
}

function uqGwProjection(
    $dispatcher,
    string $action,
    array $payload,
    array &$session
): array {
    $request = array_merge($payload, [
        'action' => $action,
        'clientSessionId' => (string)$session['client_session_id'],
        'stateContextId' => (string)($session['state_context_id'] ?? ''),
        'correlationId' => uqGwRequestId('CORR'),
    ]);
    $envelope = $dispatcher->dispatch($request, $session);
    if ((string)($session['state_context_id'] ?? '') === '') {
        $session['state_context_id'] = (string)($envelope['stateContextId'] ?? '');
    }
    return ['request' => $request, 'envelope' => $envelope];
}

function uqGwPreferenceVersion(array $envelope): int
{
    foreach ((array)($envelope['versions'] ?? []) as $version) {
        if ((string)($version['kind'] ?? '') === 'query_preference'
            && (string)($version['id'] ?? '') === 'member_list') {
            return (int)($version['version'] ?? 0);
        }
    }
    return 0;
}

function uqGwCommand(
    $dispatcher,
    string $action,
    array $payload,
    int $expectedVersion,
    array &$session
): array {
    $request = array_merge($payload, [
        'action' => $action,
        'clientSessionId' => (string)$session['client_session_id'],
        'stateContextId' => (string)($session['state_context_id'] ?? ''),
        'correlationId' => uqGwRequestId('CORR'),
        'command' => [
            'action' => $action,
            'idempotencyKey' => uqGwRequestId('CMD'),
            'contexts' => [[
                'kind' => 'query_preference',
                'id' => 'member_list',
                'expectedVersion' => $expectedVersion,
            ]],
        ],
    ]);
    return [
        'request' => $request,
        'envelope' => $dispatcher->dispatch($request, $session),
    ];
}

function uqGwResultData(array $envelope): array
{
    return is_array($envelope['data'] ?? null) ? $envelope['data'] : [];
}

function uqGwCapability(array $envelope): array
{
    $data = uqGwResultData($envelope);
    return is_array($data['unifiedQueryCapability'] ?? null)
        ? $data['unifiedQueryCapability']
        : [];
}

function uqGwCode(callable $callback): string
{
    try {
        $callback();
    } catch (CashierV3CommandException $exception) {
        return $exception->getResultCode();
    } catch (UnifiedQueryException $exception) {
        return $exception->getErrorCode();
    } catch (\Throwable $throwable) {
        return 'THROWABLE:' . get_class($throwable) . ':' . $throwable->getMessage();
    }
    return '';
}

function uqGwDownloadController($dispatcher, array $session): UnifiedQueryCommandController
{
    $reflection = new \ReflectionClass(UnifiedQueryCommandController::class);
    /** @var UnifiedQueryCommandController $controller */
    $controller = $reflection->newInstanceWithoutConstructor();
    $values = [
        'dispatcher' => $dispatcher,
        'storeId' => (int)($session['store_id'] ?? 0),
        'cashierId' => (int)($session['operator_id'] ?? 0),
        'cashierInfo' => is_array($session['operator_profile'] ?? null)
            ? $session['operator_profile']
            : [],
    ];
    foreach ($values as $name => $value) {
        $class = $reflection;
        while ($class && !$class->hasProperty($name)) {
            $class = $class->getParentClass();
        }
        if (!$class) {
            throw new \RuntimeException('控制器缺少测试所需属性：' . $name);
        }
        $property = $class->getProperty($name);
        $property->setAccessible(true);
        $property->setValue($controller, $value);
    }
    return $controller;
}

function uqGwReset(): void
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
    Db::name('cashier_v3_command_receipt')
        ->whereIn('action', [
            'save-unified-query-custom-field',
            'save-member-query-settings',
            'create-unified-query-export',
        ])
        ->delete();
    Db::name('cashier_v3_resource_version')
        ->where('resource_kind', 'query_preference')
        ->delete();
}

function uqGwTableExists(string $table): bool
{
    $rows = Db::query(
        'SELECT COUNT(*) AS c FROM information_schema.TABLES'
        . ' WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',
        [$table]
    );
    return (int)($rows[0]['c'] ?? 0) === 1;
}

function uqGwWorkbookRows(string $path, int $rowCount): array
{
    $workbook = IOFactory::load($path);
    try {
        $sheet = $workbook->getActiveSheet();
        $rows = [];
        for ($row = 2; $row <= $rowCount + 1; $row++) {
            $rows[] = [
                'name' => (string)$sheet->getCell('A' . $row)->getValue(),
                'nameType' => (string)$sheet->getCell('A' . $row)->getDataType(),
                'phone' => (string)$sheet->getCell('B' . $row)->getValue(),
                'phoneType' => (string)$sheet->getCell('B' . $row)->getDataType(),
                'customValue' => $sheet->getCell('C' . $row)->getValue(),
                'customType' => (string)$sheet->getCell('C' . $row)->getDataType(),
            ];
        }
        return [
            'headers' => [
                (string)$sheet->getCell('A1')->getValue(),
                (string)$sheet->getCell('B1')->getValue(),
                (string)$sheet->getCell('C1')->getValue(),
            ],
            'headerTypes' => [
                (string)$sheet->getCell('A1')->getDataType(),
                (string)$sheet->getCell('B1')->getDataType(),
                (string)$sheet->getCell('C1')->getDataType(),
            ],
            'rows' => $rows,
        ];
    } finally {
        $workbook->disconnectWorksheets();
    }
}

try {
    uqGwSection('runtime and schema readiness');
    $requiredTables = [
        'eb_cashier_v3_command_receipt',
        'eb_cashier_v3_resource_version',
        'eb_cashier_v3_state_context',
        'eb_unified_query_preference',
        'eb_unified_query_custom_field',
        'eb_unified_query_custom_field_version',
        'eb_unified_query_field_alias_set',
        'eb_unified_query_field_alias',
        'eb_unified_query_field_reference',
        'eb_unified_query_export_task',
    ];
    $missingTables = array_values(array_filter($requiredTables, function (string $table): bool {
        return !uqGwTableExists($table);
    }));
    $extensions = [
        'bcmath' => extension_loaded('bcmath'),
        'zip' => extension_loaded('zip'),
        'mbstring' => extension_loaded('mbstring'),
    ];
    ok(
        '统一查询迁移表与金额/XLSX运行时扩展就绪',
        $missingTables === [] && !in_array(false, $extensions, true),
        json_encode(compact('missingTables', 'extensions'), JSON_UNESCAPED_UNICODE),
        'UQ-GW-00'
    );
    if ($missingTables !== [] || in_array(false, $extensions, true)) {
        finish('unified-query-gateway-integration');
    }

    MemberIntegrationFixture::ensureLegacySchema();
    MemberIntegrationFixture::resetAndSeed();
    uqGwReset();

    $formulaNames = [
        '=2+2',
        '+SUM(A1:A2)',
        '-10+20',
        '@HYPERLINK',
        "\t=1+1",
        "\n=2+2",
    ];
    foreach ($formulaNames as $index => $name) {
        MemberIntegrationFixture::seedMember(
            801 + $index,
            $name,
            '13900000' . sprintf('%03d', $index + 1),
            MemberIntegrationFixture::STORE_ID
        );
    }
    MemberIntegrationFixture::seedMember(807, '其他状态-停用', '13900000807', 8, 0);
    MemberIntegrationFixture::seedMember(
        808,
        '其他状态-注销',
        '13900000808',
        8,
        1,
        1,
        '2026-07-28 00:00:00'
    );
    $userColumns = array_map(function (array $row): string {
        return (string)($row['Field'] ?? $row['field'] ?? '');
    }, Db::query('SHOW COLUMNS FROM `eb_user`'));
    if (in_array('now_money', $userColumns, true)) {
        foreach (range(801, 808) as $uid) {
            Db::name('user')->where('uid', $uid)->update([
                'now_money' => number_format(($uid - 800) * 10.25, 2, '.', ''),
            ]);
        }
    }

    $validPurchaseTime = strtotime(date('Y-m-d', strtotime('-4 days')) . ' 10:00:00');
    $rechargeTime = strtotime(date('Y-m-d', strtotime('-3 days')) . ' 10:00:00');
    $debtRepayTime = strtotime(date('Y-m-d', strtotime('-2 days')) . ' 10:00:00');
    $voidTime = strtotime(date('Y-m-d', strtotime('-1 day')) . ' 10:00:00');
    Db::name('store_order')->insertAll([
        [
            'uid' => 801,
            'store_id' => MemberIntegrationFixture::STORE_ID,
            'paid' => 1,
            'refund_status' => 0,
            'pid' => 0,
            'order_type' => 0,
            'is_debt_repay' => 0,
            'terminal_action' => 0,
            'order_id' => 'UQ-VALID-MIXED-PAYMENT',
            'pay_price' => '100.00',
            'cash_pay_price' => '40.00',
            'yue_pay_price' => '60.00',
            'add_time' => $validPurchaseTime,
            'pay_time' => $validPurchaseTime,
        ],
        [
            'uid' => 801,
            'store_id' => MemberIntegrationFixture::STORE_ID,
            'paid' => 1,
            'refund_status' => 0,
            'pid' => -2,
            'order_type' => 1,
            'is_debt_repay' => 0,
            'terminal_action' => 0,
            'order_id' => 'UQ-EXCLUDED-RECHARGE',
            'pay_price' => '500.00',
            'cash_pay_price' => '500.00',
            'yue_pay_price' => '0.00',
            'add_time' => $rechargeTime,
            'pay_time' => $rechargeTime,
        ],
        [
            'uid' => 801,
            'store_id' => MemberIntegrationFixture::STORE_ID,
            'paid' => 1,
            'refund_status' => 0,
            'pid' => 0,
            'order_type' => 0,
            'is_debt_repay' => 1,
            'terminal_action' => 0,
            'order_id' => 'UQ-EXCLUDED-DEBT-REPAY',
            'pay_price' => '30.00',
            'cash_pay_price' => '30.00',
            'yue_pay_price' => '0.00',
            'add_time' => $debtRepayTime,
            'pay_time' => $debtRepayTime,
        ],
        [
            'uid' => 801,
            'store_id' => MemberIntegrationFixture::STORE_ID,
            'paid' => 1,
            'refund_status' => 0,
            'pid' => 0,
            'order_type' => 0,
            'is_debt_repay' => 0,
            'terminal_action' => 2,
            'order_id' => 'UQ-EXCLUDED-VOID',
            'pay_price' => '90.00',
            'cash_pay_price' => '90.00',
            'yue_pay_price' => '0.00',
            'add_time' => $voidTime,
            'pay_time' => $voidTime,
        ],
    ]);

    $dispatcher = MemberIntegrationFixture::dispatcher();
    $session = MemberIntegrationFixture::projectionSession(1);
    $cutoffDate = date('Y-m-d');
    $futureDate = date('Y-m-d', strtotime('+10 days'));

    $forgedCapability = uqGwProjection($dispatcher, 'query-unified-query-capabilities', [
        'pageCode' => 'member_list',
        'queryCutoffDate' => $futureDate,
    ], $session);
    $forgedCapabilityData = uqGwCapability($forgedCapability['envelope']);
    ok(
        'capability 的统计截止日只由服务端签发，客户端未来日期不能覆盖',
        (string)($forgedCapabilityData['querySettings']['settings']['queryCutoffDate'] ?? '')
            === $cutoffDate
            && (string)($forgedCapabilityData['querySettings']['settings']['queryCutoffDate'] ?? '')
                !== $futureDate,
        json_encode(compact('cutoffDate', 'futureDate', 'forgedCapabilityData'), JSON_UNESCAPED_UNICODE),
        'UQ-CUTOFF-01'
    );

    uqGwSection('real Gateway custom field and settings round trip');
    $initialCapability = uqGwProjection($dispatcher, 'query-unified-query-capabilities', [
        'pageCode' => 'member_list',
    ], $session);
    $initialVersion = uqGwPreferenceVersion($initialCapability['envelope']);
    $createdField = uqGwCommand(
        $dispatcher,
        'save-unified-query-custom-field',
        [
            'pageCode' => 'member_list',
            'name' => '余额二分之一',
            'returnType' => 'amount',
            'visibility' => 'personal',
            'expression' => [
                'type' => 'binary',
                'operator' => 'divide',
                'left' => ['type' => 'field', 'fieldKey' => 'account_balance'],
                'right' => ['type' => 'literal', 'valueType' => 'decimal', 'value' => '2'],
                'nullMode' => 'empty',
                'returnType' => 'amount',
            ],
        ],
        $initialVersion,
        $session
    );
    $customFieldKey = (string)(uqGwResultData($createdField['envelope'])['key'] ?? '');

    $postPersonalCapability = uqGwProjection($dispatcher, 'query-unified-query-capabilities', [
        'pageCode' => 'member_list',
    ], $session);
    $sharedField = uqGwCommand(
        $dispatcher,
        'save-unified-query-custom-field',
        [
            'pageCode' => 'member_list',
            'name' => '门店共享余额四分之一',
            'returnType' => 'amount',
            'visibility' => 'shared',
            'shareScope' => 'store',
            'scopeId' => (string)MemberIntegrationFixture::STORE_ID,
            'expression' => [
                'type' => 'binary',
                'operator' => 'divide',
                'left' => ['type' => 'field', 'fieldKey' => 'account_balance'],
                'right' => ['type' => 'literal', 'valueType' => 'decimal', 'value' => '4'],
                'nullMode' => 'empty',
                'returnType' => 'amount',
            ],
        ],
        uqGwPreferenceVersion($postPersonalCapability['envelope']),
        $session
    );
    $sharedFieldKey = (string)(uqGwResultData($sharedField['envelope'])['key'] ?? '');

    $capabilityBefore = uqGwProjection($dispatcher, 'query-unified-query-capabilities', [
        'pageCode' => 'member_list',
    ], $session);
    $capabilityBeforeData = uqGwCapability($capabilityBefore['envelope']);
    $versionBefore = uqGwPreferenceVersion($capabilityBefore['envelope']);
    $managerFieldIndex = array_column(
        (array)($capabilityBeforeData['customFields'] ?? []),
        null,
        'key'
    );
    $ordinarySession = MemberIntegrationFixture::projectionSession(2);
    $ordinarySession['operator_profile']['level'] = 1;
    $ordinarySession['operator_profile']['roles'] = [901];
    $ordinaryCapability = uqGwProjection($dispatcher, 'query-unified-query-capabilities', [
        'pageCode' => 'member_list',
    ], $ordinarySession);
    $ordinaryCapabilityData = uqGwCapability($ordinaryCapability['envelope']);
    $ordinaryFieldIndex = array_column(
        (array)($ordinaryCapabilityData['customFields'] ?? []),
        null,
        'key'
    );
    ok(
        '生产 Bootstrap 中统一查询先接管 query-members 且 capability 携带可信版本',
        preg_match('/^cf_[a-f0-9]{24}$/D', $customFieldKey) === 1
            && $initialVersion > 0
            && $versionBefore > $initialVersion
            && (string)($capabilityBeforeData['pageCode'] ?? '') === 'member_list'
            && !empty($capabilityBeforeData['permissions']['export'])
            && in_array($customFieldKey, array_column(
                (array)($capabilityBeforeData['customFields'] ?? []),
                'key'
            ), true),
        json_encode(compact(
            'initialVersion',
            'versionBefore',
            'customFieldKey',
            'capabilityBeforeData'
        ), JSON_UNESCAPED_UNICODE),
        'UQ-GW-01'
    );
    $gatewayManagementFlags = [
        'personalOwner' => [
            'canEdit' => $managerFieldIndex[$customFieldKey]['canEdit'] ?? null,
            'canChangeStatus' => $managerFieldIndex[$customFieldKey]['canChangeStatus'] ?? null,
            'canArchive' => $managerFieldIndex[$customFieldKey]['canArchive'] ?? null,
        ],
        'sharedManager' => [
            'canEdit' => $managerFieldIndex[$sharedFieldKey]['canEdit'] ?? null,
            'canChangeStatus' => $managerFieldIndex[$sharedFieldKey]['canChangeStatus'] ?? null,
            'canArchive' => $managerFieldIndex[$sharedFieldKey]['canArchive'] ?? null,
        ],
        'sharedViewer' => [
            'canEdit' => $ordinaryFieldIndex[$sharedFieldKey]['canEdit'] ?? null,
            'canChangeStatus' => $ordinaryFieldIndex[$sharedFieldKey]['canChangeStatus'] ?? null,
            'canArchive' => $ordinaryFieldIndex[$sharedFieldKey]['canArchive'] ?? null,
        ],
    ];
    ok(
        'Gateway capability 透出个人本人、共享管理员和普通账号的字段级管理标记',
        preg_match('/^cf_[a-f0-9]{24}$/D', $sharedFieldKey) === 1
            && $gatewayManagementFlags === [
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
        json_encode(compact(
            'sharedFieldKey',
            'gatewayManagementFlags',
            'ordinaryCapabilityData'
        ), JSON_UNESCAPED_UNICODE),
        'UQ-GW-05'
    );

    $settings = [
        'visibleFields' => ['member_name', 'phone', 'member_status', $customFieldKey],
        'quickFields' => ['member_name', 'phone'],
        'filters' => [[
            'field' => $customFieldKey,
            'operator' => 'gte',
            'value' => '0.00',
        ]],
        'filterRelation' => 'all',
        'sorts' => [['field' => 'member_name', 'direction' => 'asc']],
        'groupBy' => ['member_status'],
        'summaries' => [['field' => $customFieldKey, 'aggregation' => 'sum']],
        'queryCutoffDate' => $cutoffDate,
        'schemaVersion' => (string)($capabilityBeforeData['schemaVersion'] ?? ''),
    ];
    $saveSettings = uqGwCommand(
        $dispatcher,
        'save-member-query-settings',
        ['pageCode' => 'member_list', 'settings' => $settings],
        $versionBefore,
        $session
    );
    $normalizedSavedSettings = (array)(uqGwResultData($saveSettings['envelope'])['settings'] ?? []);
    $capabilityAfter = uqGwProjection($dispatcher, 'query-unified-query-capabilities', [
        'pageCode' => 'member_list',
    ], $session);
    $capabilityAfterData = uqGwCapability($capabilityAfter['envelope']);
    $versionAfter = uqGwPreferenceVersion($capabilityAfter['envelope']);
    $roundTripSettings = (array)($capabilityAfterData['querySettings']['settings'] ?? []);
    ok(
        '查询设置通过真实命令保存、规范化并由 capability 原样回读',
        ($saveSettings['envelope']['result']['status'] ?? '') === 'success'
            && $versionAfter > $versionBefore
            && ($normalizedSavedSettings['filters'][0]['fieldKey'] ?? '') === $customFieldKey
            // 两端对象键排列不属于业务语义；以查询合同的稳定 JSON 判断往返一致。
            && UnifiedQueryJson::encode($normalizedSavedSettings)
                === UnifiedQueryJson::encode($roundTripSettings),
        json_encode(compact(
            'versionBefore',
            'versionAfter',
            'normalizedSavedSettings',
            'roundTripSettings'
        ), JSON_UNESCAPED_UNICODE),
        'UQ-GW-02'
    );

    uqGwSection('real member projection and business status controls');
    $queryInput = [
        'pageCode' => 'member_list',
        'page' => 1,
        'pageSize' => 3,
        'keyword' => '',
        'queryCutoffDate' => $cutoffDate,
        'querySettings' => $roundTripSettings,
        'dataScope' => 'all',
        'businessStatus' => '',
    ];
    $queryMembers = uqGwProjection($dispatcher, 'query-members', $queryInput, $session);
    $memberData = uqGwResultData($queryMembers['envelope']);
    $records = (array)($memberData['records'] ?? []);
    $statusValues = array_column((array)($memberData['statusOptions'] ?? []), 'value');
    $inactiveQuery = uqGwProjection($dispatcher, 'query-members', array_merge($queryInput, [
        'pageSize' => 20,
        'businessStatus' => '已停用',
    ]), $session);
    $inactiveData = uqGwResultData($inactiveQuery['envelope']);
    $normalQuery = uqGwProjection($dispatcher, 'query-members', array_merge($queryInput, [
        'pageSize' => 20,
        'dataScope' => 'normal',
    ]), $session);
    $normalData = uqGwResultData($normalQuery['envelope']);
    $forgedQuery = uqGwProjection($dispatcher, 'query-members', array_merge($queryInput, [
        'pageSize' => 20,
        'queryCutoffDate' => $futureDate,
    ]), $session);
    $invalidDateQuery = uqGwProjection($dispatcher, 'query-members', array_merge($queryInput, [
        'pageSize' => 20,
        'queryCutoffDate' => '2026-99-88',
    ]), $session);
    $forgedQueryData = uqGwResultData($forgedQuery['envelope']);
    $invalidDateQueryData = uqGwResultData($invalidDateQuery['envelope']);
    $purchaseQuery = uqGwProjection($dispatcher, 'query-members', array_merge($queryInput, [
        'pageSize' => 20,
        'keyword' => '13900000001',
    ]), $session);
    $purchaseRecords = (array)(uqGwResultData($purchaseQuery['envelope'])['records'] ?? []);
    $purchaseRecord = (array)($purchaseRecords[0] ?? []);
    ok(
        '列表分页、自定义字段、正常/全部切换与其他状态选项共用真实 provider',
        (int)($memberData['total'] ?? 0) === 8
            && count($records) === 3
            && count(array_intersect(['正常', '已停用', '已注销'], $statusValues)) === 3
            && count((array)($inactiveData['records'] ?? [])) === 1
            && (string)($inactiveData['records'][0]['status'] ?? '') === '已停用'
            && (int)($normalData['total'] ?? 0) === 6
            && array_reduce($records, function (bool $valid, array $record) use ($customFieldKey): bool {
                return $valid
                    && (string)($record['id'] ?? '') !== ''
                    && array_key_exists($customFieldKey, (array)($record['queryFieldValues'] ?? []));
            }, true),
        json_encode(compact('memberData', 'inactiveData', 'normalData'), JSON_UNESCAPED_UNICODE),
        'UQ-GW-03'
    );
    ok(
        '混合支付按有效成交额计一次，充值、欠款补交和作废不进入消费金额或最近消费日',
        count($purchaseRecords) === 1
            && (int)($purchaseRecord['memberId'] ?? 0) === 801
            && (string)($purchaseRecord['totalConsumptionAmount'] ?? '') === '100.00'
            && (string)($purchaseRecord['latestPurchaseDate'] ?? '')
                === date('Y-m-d', $validPurchaseTime),
        json_encode(compact(
            'purchaseRecord',
            'validPurchaseTime',
            'rechargeTime',
            'debtRepayTime',
            'voidTime'
        ), JSON_UNESCAPED_UNICODE),
        'UQ-GW-04'
    );
    ok(
        '列表查询忽略客户端未来/非法伪日期并持续使用服务端截止日',
        (string)($forgedQueryData['queryCutoffDate'] ?? '') === $cutoffDate
            && (string)($invalidDateQueryData['queryCutoffDate'] ?? '') === $cutoffDate
            && (int)($forgedQueryData['total'] ?? -1) === 8
            && (int)($invalidDateQueryData['total'] ?? -1) === 8,
        json_encode(compact(
            'cutoffDate',
            'futureDate',
            'forgedQueryData',
            'invalidDateQueryData'
        ), JSON_UNESCAPED_UNICODE),
        'UQ-CUTOFF-02'
    );

    uqGwSection('query export worker and cross-end fixture');
    $exportConfiguration = [
        'scope' => 'query',
        'fields' => ['member_name', 'phone', $customFieldKey],
        'includeSummary' => true,
        'fileName' => '统一查询安全导出.xlsx',
    ];
    $queryForExport = $queryInput;
    unset($queryForExport['queryCutoffDate']);
    $taskCountBeforeForgery = (int)Db::name('unified_query_export_task')->count();
    $forgedExportCode = uqGwCode(function () use (
        $dispatcher,
        $exportConfiguration,
        $queryForExport,
        $futureDate,
        $versionAfter,
        &$session
    ): void {
        uqGwCommand(
            $dispatcher,
            'create-unified-query-export',
            array_merge($exportConfiguration, [
                'pageCode' => 'member_list',
                'queryCutoffDate' => $futureDate,
                'query' => $queryForExport,
            ]),
            $versionAfter,
            $session
        );
    });
    $invalidExportCode = uqGwCode(function () use (
        $dispatcher,
        $exportConfiguration,
        $queryForExport,
        $versionAfter,
        &$session
    ): void {
        uqGwCommand(
            $dispatcher,
            'create-unified-query-export',
            array_merge($exportConfiguration, [
                'pageCode' => 'member_list',
                'queryCutoffDate' => 'not-a-date',
                'query' => $queryForExport,
            ]),
            $versionAfter,
            $session
        );
    });
    ok(
        '创建导出拒绝未来/非法客户端截止日且失败事务不落任务',
        $forgedExportCode === 'UNIFIED_QUERY_CUTOFF_DATE_CONFLICT'
            && $invalidExportCode === 'UNIFIED_QUERY_CUTOFF_DATE_CONFLICT'
            && (int)Db::name('unified_query_export_task')->count() === $taskCountBeforeForgery,
        json_encode(compact(
            'forgedExportCode',
            'invalidExportCode',
            'taskCountBeforeForgery'
        )),
        'UQ-CUTOFF-03'
    );
    $createExport = uqGwCommand(
        $dispatcher,
        'create-unified-query-export',
        array_merge($exportConfiguration, [
            'pageCode' => 'member_list',
            'queryCutoffDate' => $cutoffDate,
            'query' => $queryForExport,
        ]),
        $versionAfter,
        $session
    );
    $createdTask = (array)(uqGwResultData($createExport['envelope'])['exportTask'] ?? []);
    $taskNo = (string)($createdTask['taskId'] ?? '');
    $runtime = UnifiedQueryModule::runtime();
    $runtimeProviders = $runtime['providers'] ?? null;
    $runtimeWorkerContextResolvers = $runtime['workerContextResolvers'] ?? null;
    ok(
        '生产 Runtime 统一冻结 provider 与 Worker context resolver 映射',
        $runtimeProviders instanceof UnifiedQueryProviderRegistry
            && $runtimeProviders->isFrozen()
            && $runtimeWorkerContextResolvers
                instanceof UnifiedQueryWorkerContextResolverRegistry
            && $runtimeWorkerContextResolvers->isFrozen()
            && UnifiedQueryModule::service('providers') === $runtimeProviders
            && UnifiedQueryModule::service('workerContextResolvers')
                === $runtimeWorkerContextResolvers
            && $runtimeWorkerContextResolvers->resolve('member_list')->pageCode()
                === 'member_list',
        json_encode([
            'providerPages' => $runtimeProviders instanceof UnifiedQueryProviderRegistry
                ? $runtimeProviders->pageCodes()
                : [],
            'workerResolverPages' => $runtimeWorkerContextResolvers
                instanceof UnifiedQueryWorkerContextResolverRegistry
                ? $runtimeWorkerContextResolvers->pageCodes()
                : [],
        ], JSON_UNESCAPED_UNICODE),
        'UQ-WORKER-00'
    );
    $worker = new UnifiedQueryExportWorkerServices(
        $runtime['exports'],
        $runtimeProviders,
        $runtimeWorkerContextResolvers
    );
    $precisionTaskNo = 'uqe_' . str_repeat('9', 32);
    $writeXlsx = new ReflectionMethod(UnifiedQueryExportWorkerServices::class, 'writeXlsx');
    $writeXlsx->setAccessible(true);
    $precisionStorageKey = (string)$writeXlsx->invoke(
        $worker,
        $precisionTaskNo,
        str_repeat('a', 64),
        [
            ['key' => 'formula_text', 'label' => '公式样式文本', 'type' => 'text'],
            ['key' => 'oversized_amount', 'label' => '超15位金额', 'type' => 'amount'],
            ['key' => 'borderline_amount', 'label' => '15位小数金额', 'type' => 'amount'],
            ['key' => 'safe_amount', 'label' => '安全金额', 'type' => 'amount'],
        ],
        [[
            'formula_text' => '=1+1',
            'oversized_amount' => '12345678901234.56',
            // PHP float 中转会让该临界金额的分位发生改写，必须精确文本导出。
            'borderline_amount' => '1234567890123.45',
            'safe_amount' => '123456789012.34',
        ]],
        []
    );
    $precisionPath = $worker->absolutePath($precisionStorageKey);
    $precisionWorkbook = IOFactory::load($precisionPath);
    try {
        $precisionSheet = $precisionWorkbook->getActiveSheet();
        $precisionCells = [
            'formulaValue' => (string)$precisionSheet->getCell('A2')->getValue(),
            'formulaType' => (string)$precisionSheet->getCell('A2')->getDataType(),
            'oversizedValue' => (string)$precisionSheet->getCell('B2')->getValue(),
            'oversizedType' => (string)$precisionSheet->getCell('B2')->getDataType(),
            'borderlineValue' => (string)$precisionSheet->getCell('C2')->getValue(),
            'borderlineType' => (string)$precisionSheet->getCell('C2')->getDataType(),
            'safeValue' => number_format(
                (float)$precisionSheet->getCell('D2')->getValue(),
                2,
                '.',
                ''
            ),
            'safeType' => (string)$precisionSheet->getCell('D2')->getDataType(),
            'safeFormat' => (string)$precisionSheet->getStyle('D2')
                ->getNumberFormat()
                ->getFormatCode(),
        ];
    } finally {
        $precisionWorkbook->disconnectWorksheets();
    }
    $precisionArchive = new \ZipArchive();
    $precisionArchiveOpened = $precisionArchive->open($precisionPath) === true;
    $precisionSheetXml = $precisionArchiveOpened
        ? (string)$precisionArchive->getFromName('xl/worksheets/sheet1.xml')
        : '';
    if ($precisionArchiveOpened) {
        $precisionArchive->close();
    }
    ok(
        '超安全精度的金额精确文本导出，安全金额保留两位数值，公式样式文本无公式节点',
        $precisionCells === [
            'formulaValue' => '=1+1',
            'formulaType' => DataType::TYPE_STRING,
            'oversizedValue' => '12345678901234.56',
            'oversizedType' => DataType::TYPE_STRING,
            'borderlineValue' => '1234567890123.45',
            'borderlineType' => DataType::TYPE_STRING,
            'safeValue' => '123456789012.34',
            'safeType' => DataType::TYPE_NUMERIC,
            'safeFormat' => '#,##0.00',
        ]
            && $precisionArchiveOpened
            && $precisionSheetXml !== ''
            && preg_match('/<f(?:\s|>)/', $precisionSheetXml) !== 1,
        json_encode(compact(
            'precisionCells',
            'precisionArchiveOpened',
            'precisionStorageKey'
        ), JSON_UNESCAPED_UNICODE),
        'UQ-XLSX-02'
    );

    $heartbeatCalls = 0;
    $heartbeatStorageKey = (string)$writeXlsx->invoke(
        $worker,
        'uqe_' . str_repeat('7', 32),
        str_repeat('d', 64),
        [['key' => 'member_name', 'label' => '心跳字段', 'type' => 'text']],
        array_fill(0, UnifiedQueryExportWorkerServices::HEARTBEAT_ROW_INTERVAL + 1, [
            'member_name' => '续租检查',
        ]),
        [],
        false,
        function (bool $force = false) use (&$heartbeatCalls): void {
            $heartbeatCalls++;
        }
    );
    $heartbeatPath = $worker->absolutePath($heartbeatStorageKey);
    $heartbeatFileReady = is_file($heartbeatPath) && filesize($heartbeatPath) > 0;
    $heartbeatRemoved = $heartbeatFileReady && @unlink($heartbeatPath);
    $overBudgetSnapshot = [];
    foreach (range(1, UnifiedQueryExportTaskServices::MAX_EXPORT_FIELDS) as $index) {
        $overBudgetSnapshot[] = [
            'key' => 'field_' . $index,
            'label' => '资源字段' . $index,
            'type' => 'text',
        ];
    }
    $overBudgetRows = array_fill(0, 2500, []);
    $overBudgetCode = uqGwCode(function () use (
        $writeXlsx,
        $worker,
        $overBudgetSnapshot,
        $overBudgetRows
    ): void {
        $writeXlsx->invoke(
            $worker,
            'uqe_' . str_repeat('6', 32),
            str_repeat('e', 64),
            $overBudgetSnapshot,
            $overBudgetRows,
            ['field_1:sum' => '0'],
            true
        );
    });
    ok(
        'XLSX 按单元格（含表头和合计）硬限流，并在写表批次/落盘前后触发心跳',
        $overBudgetCode === 'UNIFIED_QUERY_EXPORT_CELL_LIMIT_EXCEEDED'
            && count($overBudgetSnapshot) * (count($overBudgetRows) + 2)
                > UnifiedQueryExportTaskServices::MAX_EXPORT_CELLS
            && $heartbeatCalls >= 4
            && $heartbeatFileReady
            && $heartbeatRemoved,
        json_encode(compact(
            'overBudgetCode',
            'heartbeatCalls',
            'heartbeatStorageKey',
            'heartbeatFileReady',
            'heartbeatRemoved'
        ), JSON_UNESCAPED_UNICODE),
        'UQ-XLSX-05'
    );
    $raceTaskNo = 'uqe_' . str_repeat('8', 32);
    $raceSnapshot = [['key' => 'member_name', 'label' => '会员姓名', 'type' => 'text']];
    $oldLeaseKey = (string)$writeXlsx->invoke(
        $worker,
        $raceTaskNo,
        str_repeat('b', 64),
        $raceSnapshot,
        [['member_name' => '旧租约结果']],
        []
    );
    $newLeaseKey = (string)$writeXlsx->invoke(
        $worker,
        $raceTaskNo,
        str_repeat('c', 64),
        $raceSnapshot,
        [['member_name' => '新租约结果']],
        []
    );
    $oldLeasePath = $worker->absolutePath($oldLeaseKey);
    $newLeasePath = $worker->absolutePath($newLeaseKey);
    $oldRemoved = is_file($oldLeasePath) && unlink($oldLeasePath);
    ok(
        '每个 worker 租约使用唯一 XLSX 对象键，清理旧租约不会删除新租约结果',
        $oldLeaseKey !== $newLeaseKey
            && strpos($oldLeaseKey, str_repeat('b', 64)) !== false
            && strpos($newLeaseKey, str_repeat('c', 64)) !== false
            && $oldRemoved
            && !is_file($oldLeasePath)
            && is_file($newLeasePath)
            && filesize($newLeasePath) > 0,
        json_encode(compact(
            'oldLeaseKey',
            'newLeaseKey',
            'oldLeasePath',
            'newLeasePath'
        ), JSON_UNESCAPED_UNICODE),
        'UQ-XLSX-03'
    );
    $workerResult = $taskNo === ''
        ? ['status' => 'failed', 'diagnostic' => 'create export did not return taskId']
        : $worker->processOne($taskNo);
    $pollExport = $taskNo === ''
        ? ['request' => [], 'envelope' => []]
        : uqGwProjection($dispatcher, 'query-unified-query-export-task', [
            'pageCode' => 'member_list',
            'taskId' => $taskNo,
        ], $session);
    $polledTask = (array)(uqGwResultData($pollExport['envelope'])['exportTask'] ?? []);
    $createdFrozenFields = (array)($createdTask['frozenFields'] ?? []);
    $polledFrozenFields = (array)($polledTask['frozenFields'] ?? []);
    $expectedFrozenFieldKeys = ['member_name', 'phone', $customFieldKey];
    $storageKey = (string)($workerResult['storageKey'] ?? '');
    $path = $storageKey === '' ? '' : $worker->absolutePath($storageKey);
    $workerReady = ($workerResult['status'] ?? '') === 'succeeded'
        && (int)($workerResult['rowCount'] ?? -1) === (int)($memberData['total'] ?? -2)
        && ($polledTask['status'] ?? '') === 'succeeded'
        && (int)($polledTask['rowCount'] ?? -1) === (int)($memberData['total'] ?? -2)
        && strpos((string)($polledTask['downloadUrl'] ?? ''),
            '/cashierapi/v3/unified-query/exports/') === 0
        && array_column($createdFrozenFields, 'key') === $expectedFrozenFieldKeys
        && array_column($polledFrozenFields, 'key') === $expectedFrozenFieldKeys
        && array_column($polledFrozenFields, 'label')
            === array_column($createdFrozenFields, 'label')
        && is_file($path)
        && filesize($path) > 0;
    ok(
        '当前查询导出使用冻结计划且结果行数与列表 total 一致',
        $workerReady,
        json_encode(compact(
            'createExport',
            'createdTask',
            'taskNo',
            'workerResult',
            'polledTask',
            'createdFrozenFields',
            'polledFrozenFields',
            'storageKey',
            'path'
        ), JSON_UNESCAPED_UNICODE),
        'UQ-WORKER-01'
    );
    if (!$workerReady) {
        throw new \RuntimeException(
            'UQ-WORKER-01 prerequisite failed: ' . json_encode(compact(
                'createExport',
                'createdTask',
                'taskNo',
                'workerResult',
                'polledTask',
                'createdFrozenFields',
                'polledFrozenFields',
                'storageKey',
                'path'
            ), JSON_UNESCAPED_UNICODE)
        );
    }

    $workbook = uqGwWorkbookRows($path, (int)$workerResult['rowCount']);
    $exportedNames = array_column($workbook['rows'], 'name');
    $missingFormulaNames = array_values(array_diff($formulaNames, $exportedNames));
    $unsafeTypes = array_values(array_filter($workbook['rows'], function (array $row): bool {
        return $row['nameType'] !== DataType::TYPE_STRING
            || $row['phoneType'] !== DataType::TYPE_STRING
            || $row['nameType'] === DataType::TYPE_FORMULA
            || $row['phoneType'] === DataType::TYPE_FORMULA;
    }));
    ok(
        '公式样式、加减号、@、制表符和换行值均写为显式字符串单元格',
        $missingFormulaNames === []
            && $unsafeTypes === []
            && $workbook['headerTypes'] === [
                DataType::TYPE_STRING,
                DataType::TYPE_STRING,
                DataType::TYPE_STRING,
            ],
        json_encode(compact('missingFormulaNames', 'unsafeTypes', 'workbook'), JSON_UNESCAPED_UNICODE),
        'UQ-XLSX-01'
    );

    $latestCapability = uqGwProjection($dispatcher, 'query-unified-query-capabilities', [
        'pageCode' => 'member_list',
    ], $session);
    $pageExport = uqGwCommand(
        $dispatcher,
        'create-unified-query-export',
        [
            'pageCode' => 'member_list',
            'scope' => 'page',
            'queryCutoffDate' => $cutoffDate,
            'query' => $queryForExport,
            'fields' => ['member_name', 'phone', $customFieldKey],
            'includeSummary' => false,
            'fileName' => '当前页.xlsx',
        ],
        uqGwPreferenceVersion($latestCapability['envelope']),
        $session
    );
    $pageTaskNo = (string)(uqGwResultData($pageExport['envelope'])['exportTask']['taskId'] ?? '');
    $leaseTrackingTasks = new UqGwLeaseTrackingTasks($runtime['exports']);
    $leaseTrackingWorker = new UnifiedQueryExportWorkerServices(
        $leaseTrackingTasks,
        $runtimeProviders,
        $runtimeWorkerContextResolvers
    );
    $pageWorkerResult = $leaseTrackingWorker->processOne($pageTaskNo);
    $pagePath = $leaseTrackingWorker->absolutePath((string)($pageWorkerResult['storageKey'] ?? ''));
    $pageWorkbook = uqGwWorkbookRows($pagePath, (int)($pageWorkerResult['rowCount'] ?? 0));
    $pageRecordNames = array_map(function (array $record): string {
        return (string)($record['name'] ?? '');
    }, $records);
    ok(
        '当前页导出与同一查询分页记录逐行一致',
        ($pageWorkerResult['status'] ?? '') === 'succeeded'
            && (int)($pageWorkerResult['rowCount'] ?? -1) === count($records)
            && array_column($pageWorkbook['rows'], 'name') === $pageRecordNames,
        json_encode(compact('pageWorkerResult', 'pageRecordNames', 'pageWorkbook'), JSON_UNESCAPED_UNICODE),
        'UQ-WORKER-02'
    );
    $leaseRenewalSeconds = array_values(array_unique(array_map(function (array $renewal): int {
        return (int)($renewal['seconds'] ?? 0);
    }, $leaseTrackingTasks->renewals)));
    $leaseClaimContext = (array)($leaseTrackingTasks->claimContexts[0] ?? []);
    ok(
        'worker 在冻结查询、写表和落盘阶段以同一租约持续续期，完成仍受 token CAS 保护',
        ($pageWorkerResult['status'] ?? '') === 'succeeded'
            && count($leaseTrackingTasks->renewals) >= 6
            && $leaseRenewalSeconds === [UnifiedQueryExportTaskServices::LEASE_SECONDS]
            && ($leaseClaimContext['page_code'] ?? '') === 'member_list'
            && ($leaseClaimContext['scope_dimensions'] ?? null) === [],
        json_encode([
            'renewals' => $leaseTrackingTasks->renewals,
            'leaseRenewalSeconds' => $leaseRenewalSeconds,
            'claimContext' => $leaseClaimContext,
            'maxTaskRuntimeSeconds' => UnifiedQueryExportWorkerServices::MAX_TASK_RUNTIME_SECONDS,
            'renewIntervalSeconds' => UnifiedQueryExportWorkerServices::LEASE_RENEW_INTERVAL_SECONDS,
        ], JSON_UNESCAPED_UNICODE),
        'UQ-WORKER-04'
    );

    $staleLeaseCapability = uqGwProjection($dispatcher, 'query-unified-query-capabilities', [
        'pageCode' => 'member_list',
    ], $session);
    $staleLeaseExport = uqGwCommand(
        $dispatcher,
        'create-unified-query-export',
        [
            'pageCode' => 'member_list',
            'scope' => 'page',
            'queryCutoffDate' => $cutoffDate,
            'query' => $queryForExport,
            'fields' => ['member_name'],
            'includeSummary' => false,
            'fileName' => '失租对象清理.xlsx',
        ],
        uqGwPreferenceVersion($staleLeaseCapability['envelope']),
        $session
    );
    $staleLeaseTaskNo = (string)(
        uqGwResultData($staleLeaseExport['envelope'])['exportTask']['taskId'] ?? ''
    );
    $expiredLeaseTasks = new UqGwExpiredLeaseCompleteTasks($runtime['exports']);
    $expiredLeaseWorker = new UnifiedQueryExportWorkerServices(
        $expiredLeaseTasks,
        $runtimeProviders,
        $runtimeWorkerContextResolvers
    );
    $staleLeaseResult = $expiredLeaseWorker->processOne($staleLeaseTaskNo);
    $staleLeaseTask = Db::name('unified_query_export_task')
        ->where('task_no', $staleLeaseTaskNo)
        ->find();
    $staleLeasePath = $expiredLeaseTasks->attemptedStorageKey === ''
        ? ''
        : $expiredLeaseWorker->absolutePath($expiredLeaseTasks->attemptedStorageKey);
    // 使用生产 task service 重新领取已过期行，验证旧对象已清且新 token 不会复用旧键。
    $staleLeaseRecovery = $worker->processOne($staleLeaseTaskNo);
    $staleLeaseRecoveredPath = (string)($staleLeaseRecovery['storageKey'] ?? '') === ''
        ? ''
        : $worker->absolutePath((string)$staleLeaseRecovery['storageKey']);
    ok(
        'complete 失租时只清理旧 token 的孤儿 XLSX，后续 worker 可接管并保留新结果',
        ($staleLeaseResult['status'] ?? '') === 'failed'
            && $expiredLeaseTasks->attemptedStorageKey !== ''
            && $staleLeasePath !== ''
            && !is_file($staleLeasePath)
            && (string)($staleLeaseTask['status'] ?? '') === 'running'
            && (int)($staleLeaseTask['lease_expires_at'] ?? 0) < time()
            && ($staleLeaseRecovery['status'] ?? '') === 'succeeded'
            && (string)($staleLeaseRecovery['storageKey'] ?? '') !== ''
            && (string)($staleLeaseRecovery['storageKey'] ?? '')
                !== $expiredLeaseTasks->attemptedStorageKey
            && $staleLeaseRecoveredPath !== ''
            && is_file($staleLeaseRecoveredPath)
            && filesize($staleLeaseRecoveredPath) > 0,
        json_encode(compact(
            'staleLeaseTaskNo',
            'staleLeaseResult',
            'staleLeaseTask',
            'staleLeasePath',
            'staleLeaseRecovery',
            'staleLeaseRecoveredPath'
        ), JSON_UNESCAPED_UNICODE),
        'UQ-XLSX-06'
    );

    $ambiguousCapability = uqGwProjection($dispatcher, 'query-unified-query-capabilities', [
        'pageCode' => 'member_list',
    ], $session);
    $ambiguousExport = uqGwCommand(
        $dispatcher,
        'create-unified-query-export',
        [
            'pageCode' => 'member_list',
            'scope' => 'page',
            'queryCutoffDate' => $cutoffDate,
            'query' => $queryForExport,
            'fields' => ['member_name'],
            'includeSummary' => false,
            'fileName' => '模糊提交保护.xlsx',
        ],
        uqGwPreferenceVersion($ambiguousCapability['envelope']),
        $session
    );
    $ambiguousTaskNo = (string)(
        uqGwResultData($ambiguousExport['envelope'])['exportTask']['taskId'] ?? ''
    );
    $ambiguousWorker = new UnifiedQueryExportWorkerServices(
        new UqGwAmbiguousCompleteTasks($runtime['exports']),
        $runtimeProviders,
        $runtimeWorkerContextResolvers
    );
    $ambiguousResult = $ambiguousWorker->processOne($ambiguousTaskNo);
    $ambiguousRow = Db::name('unified_query_export_task')
        ->where('task_no', $ambiguousTaskNo)
        ->find();
    $ambiguousPath = $ambiguousWorker->absolutePath(
        (string)($ambiguousRow['storage_key'] ?? '')
    );
    ok(
        'complete 已提交但调用方收到异常时按权威任务行恢复成功且不误删结果',
        ($ambiguousResult['status'] ?? '') === 'succeeded'
            && !empty($ambiguousResult['recoveredAfterAmbiguousCommit'])
            && ($ambiguousRow['status'] ?? '') === 'succeeded'
            && (string)($ambiguousRow['storage_key'] ?? '') !== ''
            && is_file($ambiguousPath)
            && filesize($ambiguousPath) > 0,
        json_encode(compact(
            'ambiguousResult',
            'ambiguousRow',
            'ambiguousPath'
        ), JSON_UNESCAPED_UNICODE),
        'UQ-WORKER-03'
    );

    $brokenCapability = uqGwProjection($dispatcher, 'query-unified-query-capabilities', [
        'pageCode' => 'member_list',
    ], $session);
    $brokenExport = uqGwCommand(
        $dispatcher,
        'create-unified-query-export',
        [
            'pageCode' => 'member_list',
            'scope' => 'page',
            'queryCutoffDate' => $cutoffDate,
            'query' => $queryForExport,
            'fields' => ['member_name'],
            'includeSummary' => false,
            'fileName' => '损坏截止日.xlsx',
        ],
        uqGwPreferenceVersion($brokenCapability['envelope']),
        $session
    );
    $brokenTaskNo = (string)(uqGwResultData($brokenExport['envelope'])['exportTask']['taskId'] ?? '');
    Db::name('unified_query_export_task')->where('task_no', $brokenTaskNo)->update([
        'query_cutoff_date' => 'invalid-date',
    ]);
    $brokenWorkerResult = $worker->processOne($brokenTaskNo);
    $brokenTask = Db::name('unified_query_export_task')->where('task_no', $brokenTaskNo)->find();
    ok(
        'Worker 只消费任务冻结截止日且冻结日期损坏时失败、不生成文件',
        ($brokenWorkerResult['status'] ?? '') === 'failed'
            && ($brokenTask['status'] ?? '') === 'failed'
            && (string)($brokenTask['storage_key'] ?? '') === ''
            && ($brokenWorkerResult['errorCode'] ?? '')
                === 'UNIFIED_QUERY_EXPORT_PLAN_INVALID'
            && strpos((string)($brokenWorkerResult['diagnostic'] ?? ''),
                'UnifiedQueryException') !== false
            && strpos((string)($brokenWorkerResult['diagnostic'] ?? ''),
                '导出任务的冻结统计时点不合法') !== false,
        json_encode(compact('brokenWorkerResult', 'brokenTask'), JSON_UNESCAPED_UNICODE),
        'UQ-CUTOFF-04'
    );

    uqGwSection('download authentication and current permission recheck');
    $operatorScope = MemberIntegrationFixture::operatorScope(1);
    $dataScope = MemberIntegrationFixture::dataScope($dispatcher, 1);
    $downloadContext = $runtime['contextFactory']->make($operatorScope, $dataScope, [
        'pageCode' => 'member_list',
        'queryCutoffDate' => $cutoffDate,
    ]);
    $downloadDescriptor = $runtime['exports']->resolveDownloadDescriptor(
        $downloadContext,
        $taskNo
    );
    $resolvedKey = (string)($downloadDescriptor['storageKey'] ?? '');
    $revokedContext = $downloadContext;
    $revokedContext['permissions'] = array_values(array_diff(
        (array)$revokedContext['permissions'],
        [UnifiedQueryAccessPolicy::PAGE_POLICY]
    ));
    $revokedCode = uqGwCode(function () use ($runtime, $revokedContext, $taskNo): void {
        $runtime['exports']->resolveDownload($revokedContext, $taskNo);
    });
    $routeSource = (string)file_get_contents('/var/www/html/route/cashier-v3.php');
    $controllerSource = (string)file_get_contents(
        '/var/www/html/app/controller/cashier/v3/Command.php'
    );
    ok(
        '下载仅位于登录/强制门店/角色中间件组并按当前权限重新鉴权',
        $resolvedKey === $storageKey
            && (string)($downloadDescriptor['fileName'] ?? '')
                === (string)($createdTask['fileName'] ?? '')
            && $revokedCode === 'UNIFIED_QUERY_FORBIDDEN'
            && strpos($routeSource,
                "Route::get('unified-query/exports/:taskNo/download'") !== false
            && strpos($routeSource, 'AuthTokenMiddleware::class') !== false
            && strpos($routeSource, 'ForceStoreSessionMiddleware::class') !== false
            && strpos($routeSource, 'CashierCheckRoleMiddleware::class') !== false
            && strpos($controllerSource, 'resolveDownloadDescriptor') !== false
            && strpos($controllerSource, '(int)$this->storeId') !== false
            && strpos($controllerSource, '(int)$this->cashierId') !== false,
        json_encode(compact(
            'resolvedKey',
            'storageKey',
            'downloadDescriptor',
            'revokedCode'
        ), JSON_UNESCAPED_UNICODE),
        'UQ-DL-01'
    );

    $downloadController = uqGwDownloadController($dispatcher, $session);
    $controllerResponse = $downloadController->downloadUnifiedQueryExport($taskNo);
    $controllerContent = $controllerResponse instanceof FileResponse
        ? $controllerResponse->getContent()
        : '';
    $controllerHeaders = $controllerResponse instanceof FileResponse
        ? $controllerResponse->getHeader()
        : [];
    $originalExpiresAt = (int)Db::name('unified_query_export_task')
        ->where('task_no', $taskNo)
        ->value('expires_at');
    $expiredResponse = null;
    try {
        Db::name('unified_query_export_task')
            ->where('task_no', $taskNo)
            ->update(['expires_at' => time() - 1]);
        $expiredResponse = $downloadController->downloadUnifiedQueryExport($taskNo);
    } finally {
        Db::name('unified_query_export_task')
            ->where('task_no', $taskNo)
            ->update(['expires_at' => $originalExpiresAt]);
    }
    $expiredPayload = is_object($expiredResponse)
        && method_exists($expiredResponse, 'getData')
        ? (array)$expiredResponse->getData()
        : [];
    ok(
        '真实下载 Controller 返回受控 XLSX 文件响应，任务过期后拒绝且不泄露文件',
        $controllerResponse instanceof FileResponse
            && (string)$controllerResponse->getData() === $path
            && hash('sha256', $controllerContent) === hash_file('sha256', $path)
            && ($controllerHeaders['Content-Type'] ?? '')
                === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            && (int)($controllerHeaders['Content-Length'] ?? -1) === filesize($path)
            && !($expiredResponse instanceof FileResponse)
            && (int)($expiredPayload['status'] ?? 0) === 400
            && (string)($expiredPayload['data']['code'] ?? '')
                === 'UNIFIED_QUERY_EXPORT_DOWNLOAD_UNAVAILABLE',
        json_encode(compact(
            'controllerHeaders',
            'expiredPayload',
            'originalExpiresAt',
            'path'
        ), JSON_UNESCAPED_UNICODE),
        'UQ-DL-02'
    );

    Db::name('unified_query_export_task')
        ->where('task_no', $ambiguousTaskNo)
        ->update(['expires_at' => time() - 1]);
    $cleanupMarked = $worker->cleanupExpired(20);
    Db::name('unified_query_export_task')
        ->where('task_no', $ambiguousTaskNo)
        ->where('status', 'expired')
        ->update([
            'updated_at' => time() - UnifiedQueryExportWorkerServices::CLEANUP_GRACE_SECONDS - 1,
        ]);
    $cleanupFirst = $worker->cleanupExpired(20);
    $expiredRow = Db::name('unified_query_export_task')
        ->where('task_no', $ambiguousTaskNo)
        ->find();
    $cleanupSecond = $worker->cleanupExpired(20);
    ok(
        '过期成功任务先 CAS 为 expired，再幂等删除私有 XLSX 并清对象键',
        ($expiredRow['status'] ?? '') === 'expired'
            && (string)($expiredRow['storage_key'] ?? '') === ''
            && !is_file($ambiguousPath)
            && (int)($cleanupMarked['markedExpired'] ?? 0) >= 1
            && (int)($cleanupMarked['deletedFiles'] ?? -1) === 0
            && (int)($cleanupFirst['markedExpired'] ?? -1) === 0
            && (int)($cleanupFirst['deletedFiles'] ?? 0) >= 1
            && (int)($cleanupSecond['retry'] ?? -1) === 0,
        json_encode(compact(
            'cleanupMarked',
            'cleanupFirst',
            'cleanupSecond',
            'expiredRow',
            'ambiguousPath'
        ), JSON_UNESCAPED_UNICODE),
        'UQ-XLSX-04'
    );

    $revokedWorkerCapability = uqGwProjection($dispatcher, 'query-unified-query-capabilities', [
        'pageCode' => 'member_list',
    ], $session);
    $revokedWorkerExport = uqGwCommand(
        $dispatcher,
        'create-unified-query-export',
        [
            'pageCode' => 'member_list',
            'scope' => 'page',
            'queryCutoffDate' => $cutoffDate,
            'query' => $queryForExport,
            'fields' => ['phone', $customFieldKey],
            'includeSummary' => false,
            'fileName' => '停用账号过期任务.xlsx',
        ],
        uqGwPreferenceVersion($revokedWorkerCapability['envelope']),
        $session
    );
    $revokedWorkerTaskNo = (string)(
        uqGwResultData($revokedWorkerExport['envelope'])['exportTask']['taskId'] ?? ''
    );
    $revokedWorkerClaim = $runtime['exports']->claim($downloadContext, $revokedWorkerTaskNo);
    $revokedWorkerReferenceBefore = Db::name('unified_query_field_reference')
        ->where('consumer_type', 'export_task')
        ->where('consumer_id', $revokedWorkerTaskNo)
        ->where('status', '<>', 'released')
        ->count();
    Db::name('unified_query_export_task')
        ->where('task_no', $revokedWorkerTaskNo)
        ->update(['lease_expires_at' => time() - 1]);
    Db::name('system_store_staff')->where('id', 1)->update(['status' => 0]);
    try {
        $revokedWorkerResult = $worker->processOne($revokedWorkerTaskNo);
    } finally {
        Db::name('system_store_staff')->where('id', 1)->update(['status' => 1]);
    }
    $revokedWorkerTask = Db::name('unified_query_export_task')
        ->where('task_no', $revokedWorkerTaskNo)
        ->find();
    $revokedWorkerReferenceStates = Db::name('unified_query_field_reference')
        ->where('consumer_type', 'export_task')
        ->where('consumer_id', $revokedWorkerTaskNo)
        ->column('status');
    $revokedWorkerRescan = $worker->processPending(1, $revokedWorkerTaskNo);
    ok(
        '停用账号遇到已过期 running 任务时严格 CAS 失败收口、释放字段引用且不再扫描',
        (string)($revokedWorkerClaim['workerToken'] ?? '') !== ''
            && (int)$revokedWorkerReferenceBefore >= 1
            && ($revokedWorkerResult['status'] ?? '') === 'failed'
            && (string)($revokedWorkerResult['errorCode'] ?? '')
                === 'UNIFIED_QUERY_EXPORT_PERMISSION_REVOKED'
            && (string)($revokedWorkerTask['status'] ?? '') === 'failed'
            && (string)($revokedWorkerTask['lease_token'] ?? '') === ''
            && (int)($revokedWorkerTask['lease_expires_at'] ?? -1) === 0
            && (string)($revokedWorkerTask['storage_key'] ?? '') === ''
            && strpos((string)($revokedWorkerTask['error_reason'] ?? ''), '账号已停用') !== false
            && $revokedWorkerReferenceStates !== []
            && !array_diff($revokedWorkerReferenceStates, ['released'])
            && (int)($revokedWorkerRescan['scanned'] ?? -1) === 0,
        json_encode(compact(
            'revokedWorkerTaskNo',
            'revokedWorkerClaim',
            'revokedWorkerReferenceBefore',
            'revokedWorkerResult',
            'revokedWorkerTask',
            'revokedWorkerReferenceStates',
            'revokedWorkerRescan'
        ), JSON_UNESCAPED_UNICODE),
        'UQ-WORKER-05'
    );

    uqGwSection('saved-query field version upgrade');
    // 先把已被保存查询冻结在 v1 的字段更新到 v2。随后升级命令只允许带稳定
    // fieldKey；这里故意夹带伪造目标版本，验证服务端不会把版本决定权交给浏览器。
    $upgradeStartCapability = uqGwProjection($dispatcher, 'query-unified-query-capabilities', [
        'pageCode' => 'member_list',
    ], $session);
    $upgradeField = uqGwCommand(
        $dispatcher,
        'save-unified-query-custom-field',
        [
            'pageCode' => 'member_list',
            'fieldKey' => $customFieldKey,
            'expectedVersion' => 1,
            'name' => '余额三分之一',
            'returnType' => 'amount',
            'visibility' => 'personal',
            'expression' => [
                'type' => 'binary',
                'operator' => 'divide',
                'left' => ['type' => 'field', 'fieldKey' => 'account_balance'],
                'right' => ['type' => 'literal', 'valueType' => 'decimal', 'value' => '3'],
                'nullMode' => 'empty',
                'returnType' => 'amount',
            ],
        ],
        uqGwPreferenceVersion($upgradeStartCapability['envelope']),
        $session
    );
    $upgradeAvailableCapability = uqGwProjection($dispatcher, 'query-unified-query-capabilities', [
        'pageCode' => 'member_list',
    ], $session);
    $upgradeAvailableData = uqGwCapability($upgradeAvailableCapability['envelope']);
    $upgradeAvailableReferences = (array)(
        $upgradeAvailableData['querySettings']['invalidReferences'] ?? []
    );
    $upgradeResult = uqGwCommand(
        $dispatcher,
        'upgrade-unified-query-field-reference',
        [
            'pageCode' => 'member_list',
            'fieldKey' => $customFieldKey,
            // 非合同字段不能改变目标版本；服务端只能采用锁定字段的 current_version。
            'targetVersion' => 1,
            'fieldVersion' => 1,
            'customFieldVersions' => [$customFieldKey => 1],
        ],
        uqGwPreferenceVersion($upgradeAvailableCapability['envelope']),
        $session
    );
    $upgradedCapability = uqGwProjection($dispatcher, 'query-unified-query-capabilities', [
        'pageCode' => 'member_list',
    ], $session);
    $upgradedCapabilityData = uqGwCapability($upgradedCapability['envelope']);
    $upgradedSettings = (array)(
        $upgradedCapabilityData['querySettings']['settings'] ?? []
    );
    $upgradedVersions = (array)(
        $upgradedCapabilityData['querySettings']['customFieldVersions'] ?? []
    );
    $upgradedReferences = (array)(
        $upgradedCapabilityData['querySettings']['invalidReferences'] ?? []
    );
    $upgradedReferenceRow = Db::name('unified_query_field_reference')
        ->where('consumer_type', 'saved_query')
        ->where('consumer_id', 'account:1:member_list')
        ->where('field_key', $customFieldKey)
        ->find();
    $upgradeResultData = uqGwResultData($upgradeResult['envelope']);
    ok(
        '真实 Gateway 只按稳定字段键升级保存查询版本，伪造目标版本不生效且筛选不丢失',
        ($upgradeField['envelope']['result']['status'] ?? '') === 'success'
            && ($upgradeResult['envelope']['result']['status'] ?? '') === 'success'
            && (int)($upgradeResultData['upgradedReference']['previousVersion'] ?? 0) === 1
            && (int)($upgradeResultData['upgradedReference']['version'] ?? 0) === 2
            && (int)($upgradedVersions[$customFieldKey] ?? 0) === 2
            && (string)($upgradedReferenceRow['status'] ?? '') === 'active'
            && (int)($upgradedReferenceRow['field_version'] ?? 0) === 2
            && ($upgradedSettings['filters'][0]['fieldKey'] ?? '') === $customFieldKey
            && ($upgradedSettings['filters'][0]['value'] ?? null) === '0.00'
            && (bool)array_filter($upgradeAvailableReferences, function (array $reference) use ($customFieldKey): bool {
                return ($reference['fieldKey'] ?? '') === $customFieldKey
                    && ($reference['status'] ?? '') === 'upgrade_available';
            })
            && !array_filter($upgradedReferences, function (array $reference) use ($customFieldKey): bool {
                return ($reference['fieldKey'] ?? '') === $customFieldKey;
            }),
        json_encode(compact(
            'upgradeField',
            'upgradeAvailableReferences',
            'upgradeResult',
            'upgradedVersions',
            'upgradedSettings',
            'upgradedReferences',
            'upgradedReferenceRow'
        ), JSON_UNESCAPED_UNICODE),
        'UQ-REF-03'
    );

    $fixture = [
        'producer' => 'php-gateway',
        'pageCode' => 'member_list',
        'entries' => [
            'capability_before' => array_merge($capabilityBefore, [
                'input' => ['pageCode' => 'member_list'],
            ]),
            'save_settings' => array_merge($saveSettings, [
                'input' => [
                    'settings' => $settings,
                    'expectedNormalizedFilters' => $normalizedSavedSettings['filters'] ?? [],
                ],
            ]),
            'capability_after' => array_merge($capabilityAfter, [
                'input' => ['pageCode' => 'member_list'],
            ]),
            'query_members' => array_merge($queryMembers, [
                'input' => $queryInput,
            ]),
            'create_export' => array_merge($createExport, [
                'input' => [
                    'query' => $queryInput,
                    'configuration' => $exportConfiguration,
                ],
            ]),
            'poll_export' => array_merge($pollExport, [
                'input' => ['pageCode' => 'member_list', 'taskId' => $taskNo],
            ]),
        ],
    ];
    $evidenceDir = rtrim((string)getenv('C1A_EVIDENCE_DIR'), '/');
    $fixturePath = $evidenceDir . '/unified-query-gateway-samples.json';
    $fixtureJson = json_encode(
        $fixture,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );
    $fixtureWritten = $evidenceDir !== ''
        && is_dir($evidenceDir)
        && is_string($fixtureJson)
        && file_put_contents($fixturePath, $fixtureJson) === strlen($fixtureJson);
    ok(
        'PHP Gateway 六步固定样本已写入隔离证据目录供真实 bridge 消费',
        $fixtureWritten && is_file($fixturePath) && filesize($fixturePath) > 0,
        $fixturePath,
        'UQ-XEND-00'
    );
} catch (\Throwable $throwable) {
    ok(
        '统一查询 Gateway 集成未发生未捕获异常',
        false,
        get_class($throwable) . ': ' . $throwable->getMessage()
            . "\n" . $throwable->getTraceAsString()
    );
}

finish('unified-query-gateway-integration');
