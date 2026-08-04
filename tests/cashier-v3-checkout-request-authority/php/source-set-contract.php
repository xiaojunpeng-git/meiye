<?php
declare(strict_types=1);

$backend = getenv('CHECKOUT_REQUEST_BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require_once $backend . '/app/services/cashier/v3/settlement/CashierV3CheckoutSettlementContractException.php';
require_once $backend . '/app/services/cashier/v3/settlement/CashierV3CheckoutSettlementCanonicalizer.php';
require_once $backend . '/app/services/cashier/v3/settlement/CashierV3CheckoutVerifiedSourceSet.php';

use app\services\cashier\v3\settlement\CashierV3CheckoutSettlementContractException;
use app\services\cashier\v3\settlement\CashierV3CheckoutVerifiedSourceSet;

$passed = 0;
$failed = 0;
function sourceSetOk(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS {$name}\n";
        return;
    }
    $failed++;
    echo "FAIL {$name}" . ($detail === '' ? '' : ": {$detail}") . "\n";
}
function sourceSetReason(callable $callback): string
{
    try {
        $callback();
    } catch (CashierV3CheckoutSettlementContractException $exception) {
        return $exception->reason();
    } catch (Throwable $throwable) {
        return 'UNEXPECTED:' . get_class($throwable) . ':' . $throwable->getMessage();
    }
    return '';
}
function verifiedSource(
    string $kind,
    string $id,
    string $role,
    string $tenant = '0',
    int $store = 7,
    int $sourceVersion = 1
): array
{
    return [
        'tenantId' => $tenant,
        'storeId' => $store,
        'kind' => $kind,
        'id' => $id,
        'sourceVersion' => $sourceVersion,
        'role' => $role,
    ];
}

$rows = [
    verifiedSource('room', '12', 'service_room'),
    verifiedSource('reservation', 'RES-9', 'reservation_origin'),
    verifiedSource('service_order', 'SO-3', 'service_origin'),
    verifiedSource('hang_order', 'HO-8', 'hang_origin'),
];
$set = CashierV3CheckoutVerifiedSourceSet::fromServerVerifiedAuthorityRows('0', 7, $rows);
sourceSetOk('all four source kinds are supported',
    CashierV3CheckoutVerifiedSourceSet::supportedKinds()
        === ['service_order', 'hang_order', 'reservation', 'room']);
sourceSetOk('references use canonical resource lock order',
    array_column($set->references(), 'kind')
        === ['service_order', 'hang_order', 'reservation', 'room']);
sourceSetOk('Gateway projection removes role but retains stable kind and id',
    $set->gatewaySources()[0] === ['kind' => 'service_order', 'id' => 'SO-3']
        && !array_key_exists('role', $set->gatewaySources()[0]));
sourceSetOk('references preserve the authoritative source version',
    $set->references()[0]['sourceVersion'] === 1);

$shuffled = array_reverse($rows);
$same = CashierV3CheckoutVerifiedSourceSet::fromServerVerifiedAuthorityRows('0', 7, $shuffled);
sourceSetOk('source-set fingerprint is input-order independent',
    hash_equals($set->fingerprint(), $same->fingerprint()));
sourceSetOk('row fingerprint binds kind id and role',
    CashierV3CheckoutVerifiedSourceSet::referenceFingerprint('room', '12', 1, 'service_room')
        !== CashierV3CheckoutVerifiedSourceSet::referenceFingerprint('room', '12', 1, 'other_role'));
sourceSetOk('row fingerprint binds the source version',
    CashierV3CheckoutVerifiedSourceSet::referenceFingerprint('room', '12', 1, 'service_room')
        !== CashierV3CheckoutVerifiedSourceSet::referenceFingerprint('room', '12', 2, 'service_room'));

$empty = CashierV3CheckoutVerifiedSourceSet::fromServerVerifiedAuthorityRows('0', 7, []);
sourceSetOk('plain cashier checkout accepts an empty navigation source set',
    $empty->references() === []
        && $empty->gatewaySources() === []
        && preg_match('/^[a-f0-9]{64}$/D', $empty->fingerprint()) === 1);
sourceSetOk('unknown source kind is rejected', sourceSetReason(static function (): void {
    CashierV3CheckoutVerifiedSourceSet::fromServerVerifiedAuthorityRows(
        '0', 7, [verifiedSource('arbitrary_url', '1', 'origin')]
    );
}) === 'checkout_source_kind_invalid');
sourceSetOk('non-positive source version is rejected', sourceSetReason(static function (): void {
    CashierV3CheckoutVerifiedSourceSet::fromServerVerifiedAuthorityRows(
        '0', 7, [verifiedSource('room', '12', 'service_room', '0', 7, 0)]
    );
}) === 'checkout_source_positive_int_invalid');
sourceSetOk('duplicate kind id is rejected even with another role', sourceSetReason(static function (): void {
    CashierV3CheckoutVerifiedSourceSet::fromServerVerifiedAuthorityRows('0', 7, [
        verifiedSource('service_order', 'SO-1', 'primary'),
        verifiedSource('service_order', 'SO-1', 'secondary'),
    ]);
}) === 'checkout_source_duplicate');
sourceSetOk('cross-store authority row is rejected', sourceSetReason(static function (): void {
    CashierV3CheckoutVerifiedSourceSet::fromServerVerifiedAuthorityRows(
        '0', 7, [verifiedSource('room', '12', 'service_room', '0', 8)]
    );
}) === 'checkout_source_scope_mismatch');
sourceSetOk('cross-tenant authority row is rejected', sourceSetReason(static function (): void {
    CashierV3CheckoutVerifiedSourceSet::fromServerVerifiedAuthorityRows(
        '0', 7, [verifiedSource('reservation', 'R-1', 'origin', 'OTHER', 7)]
    );
}) === 'checkout_source_scope_mismatch');
sourceSetOk('URL-shaped source ID is rejected', in_array(sourceSetReason(static function (): void {
    CashierV3CheckoutVerifiedSourceSet::fromServerVerifiedAuthorityRows(
        '0', 7, [verifiedSource('service_order', 'https://client.invalid/1', 'origin')]
    );
}), ['checkout_source_id_url_forbidden', 'checkout_source_token_invalid'], true));
sourceSetOk('extra client URL field is rejected by exact shape', sourceSetReason(static function (): void {
    $source = verifiedSource('service_order', 'SO-1', 'origin');
    $source['url'] = 'https://client.invalid/1';
    CashierV3CheckoutVerifiedSourceSet::fromServerVerifiedAuthorityRows('0', 7, [$source]);
}) === 'checkout_source_shape_invalid');

echo "CHECKOUT_SOURCE_SET passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
