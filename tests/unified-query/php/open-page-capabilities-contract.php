<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/后端代码/app/services/query/UnifiedQueryException.php';
require_once dirname(__DIR__, 3) . '/后端代码/app/services/query/UnifiedQueryAccessPolicy.php';

use app\services\query\UnifiedQueryAccessPolicy;

$policy = new UnifiedQueryAccessPolicy();
$context = $policy->normalizeContext([
    'tenant_id' => 'tenant-rh',
    'account_id' => 1001,
    'operator_id' => 1001,
    'store_id' => 179,
    'visible_store_ids' => [179],
    'permissions' => [UnifiedQueryAccessPolicy::PAGE_POLICY],
]);
$permissions = $policy->capabilityPermissions($context);

$expectedOpen = [
    'createCustomField',
    'editCustomField',
    'changeCustomFieldStatus',
    'archiveCustomField',
    'renameFields',
    'export',
];
foreach ($expectedOpen as $capability) {
    if (($permissions[$capability] ?? false) !== true) {
        throw new RuntimeException($capability . ' should be open for an authorized unified-query page');
    }
}

if (($permissions['shareCustomField'] ?? true) !== false) {
    throw new RuntimeException('shared-field publishing must retain its server-side scope permission');
}
if (($context['tenant_id'] ?? '') !== 'tenant-rh'
    || ($context['visible_store_ids'] ?? []) !== [179]) {
    throw new RuntimeException('opening page capabilities must not alter server-enforced data scope');
}

$withoutPage = $policy->normalizeContext([
    'tenant_id' => 'tenant-rh',
    'account_id' => 1001,
    'operator_id' => 1001,
    'store_id' => 179,
    'visible_store_ids' => [179],
    'permissions' => [],
]);
$closedPermissions = $policy->capabilityPermissions($withoutPage);
foreach ($expectedOpen as $capability) {
    if (($closedPermissions[$capability] ?? true) !== false) {
        throw new RuntimeException($capability . ' must remain closed when the page itself is unavailable');
    }
}

echo "PASS unified-query open page capabilities\n";
