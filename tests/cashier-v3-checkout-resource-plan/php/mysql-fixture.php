<?php
declare(strict_types=1);

require '/tests/cashier-v3/lib/boot-env.php';
require '/var/www/html/vendor/autoload.php';
require '/tests/cashier-v3/lib/_lib.php';

use app\services\cashier\v3\settlement\CashierV3CheckoutVerifiedResourcePlan;
use think\facade\Db;

c1aBootThinkApp('/var/www/html/');

function checkoutResourcePlanRequestId(): string
{
    return 'CKR-' . str_repeat('1', 40);
}

function checkoutResourceAuthorityRow(
    string $kind,
    string $id,
    int $lockOrder,
    int $version,
    array $roles,
    string $accessMode = 'read',
    string $scopeType = 'tenant',
    string $scopeId = '0'
): array {
    return [
        'tenantId' => '0',
        'storeId' => 7,
        'kind' => $kind,
        'id' => $id,
        'scopeType' => $scopeType,
        'scopeId' => $scopeId,
        'lockOrder' => $lockOrder,
        'expectedVersion' => $version,
        'roles' => $roles,
        'accessMode' => $accessMode,
        'providerContractVersion' => 'provider-' . $kind . '-v1',
        'authorityFingerprint' => hash('sha256', $kind . '|' . $id . '|' . $version),
    ];
}

function checkoutResourcePlan(int $boundVersion, int $variant = 1): CashierV3CheckoutVerifiedResourcePlan
{
    return CashierV3CheckoutVerifiedResourcePlan::fromServerVerifiedAuthorityRows('0', 7, $boundVersion, [
        checkoutResourceAuthorityRow('member', '93', 10, 4 + $variant, ['member'], 'read'),
        checkoutResourceAuthorityRow(
            'inventory_batch',
            '10',
            55,
            8 + $variant,
            ['inventory_batch:2:10'],
            'mutate',
            'store',
            '7'
        ),
        checkoutResourceAuthorityRow(
            'inventory_batch',
            '2',
            55,
            10 + $variant,
            ['inventory_batch:2:2'],
            'mutate',
            'store',
            '7'
        ),
        checkoutResourceAuthorityRow(
            'member',
            '93',
            10,
            4 + $variant,
            ['checkout_member'],
            'mutate'
        ),
    ]);
}

function checkoutResourceReset(): void
{
    Db::execute('DELETE FROM `eb_cashier_v3_checkout_resource_plan_row`');
    Db::execute('DELETE FROM `eb_cashier_v3_checkout_resource_plan`');
    Db::execute('DELETE FROM `eb_cashier_v3_checkout_request`');
}

function checkoutResourceInsertRequest(int $version, string $status): void
{
    Db::name('cashier_v3_checkout_request')->insert([
        'request_id' => checkoutResourcePlanRequestId(),
        'tenant_id' => '0',
        'store_id' => 7,
        'request_version' => $version,
        'request_status' => $status,
        'business_date' => '2026-07-29',
    ]);
}

function checkoutResourceReason(callable $callback): string
{
    try {
        $callback();
    } catch (\app\services\cashier\v3\settlement\CashierV3CheckoutSettlementContractException $exception) {
        return $exception->reason();
    } catch (Throwable $throwable) {
        return 'UNEXPECTED:' . get_class($throwable) . ':' . $throwable->getMessage();
    }
    return '';
}
