<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/后端代码/vendor/autoload.php';

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\query\UnifiedQueryContextFactory;
use app\services\query\provider\MemberUnifiedQueryContextFactory;

/** 隔离数据库，只验证会员查询上下文写入的异步身份合同。 */
final class R20CoreContextFixture extends UnifiedQueryContextFactory
{
    public function __construct()
    {
    }

    public function make(array $trustedContext, array $payload = []): array
    {
        return $trustedContext + ['page_code' => (string)($payload['pageCode'] ?? '')];
    }
}

final class R20MemberContextFixture extends MemberUnifiedQueryContextFactory
{
    protected function organizationScopeIds($visibleStoreIds, string $currentOrganizationId): array
    {
        return ['175'];
    }

    protected function allStoreIds(): array
    {
        return [23];
    }
}

function r20MemberExportAssert(string $label, bool $passed): void
{
    if (!$passed) {
        throw new RuntimeException($label);
    }
    echo "PASS {$label}\n";
}

$operator = new CashierV3OperatorScope(23, 610, '175', '0');
$organizationProfile = [
    'employee_id' => 1715,
    'level' => 1,
    '_cashier_v3_organization' => 1,
];
$dataScope = new CashierV3DataScopeContext(
    610,
    1715,
    23,
    '0',
    '175',
    [23],
    CashierV3DataScopeContext::MODE_STORES,
    ['mode' => 'stores', 'store_ids' => [23]],
    false,
    '',
    'roles:test',
    ['cashier.v3.member'],
    $organizationProfile
);
$context = (new R20MemberContextFixture(new R20CoreContextFixture()))->make(
    $operator,
    $dataScope,
    ['pageCode' => 'member_list']
);

r20MemberExportAssert(
    'organization query context records its authoritative worker identity kind',
    ($context['permission_version'] ?? '') === 'organization:roles:test'
        && ($context['visible_store_ids'] ?? []) === [23]
);

$workerSource = file_get_contents(
    dirname(__DIR__, 3) . '/后端代码/app/services/query/provider/MemberUnifiedQueryWorkerContextResolver.php'
);
r20MemberExportAssert(
    'worker resolves organization tasks from the store-session authority before staff ids',
    strpos($workerSource, "strpos(\$permissionVersion, 'organization:') === 0") !== false
        && strpos($workerSource, "Db::name('cashier_v3_store_session')") !== false
        && strpos($workerSource, "->where('store_id', \$originStoreId)") !== false
        && strpos($workerSource, "SystemStoreStaff::where('id', \$operatorId)") !== false
);

echo "R20 member export worker identity contract passed.\n";
