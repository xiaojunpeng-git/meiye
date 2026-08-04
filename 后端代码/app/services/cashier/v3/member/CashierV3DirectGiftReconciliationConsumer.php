<?php
declare(strict_types=1);

namespace app\services\cashier\v3\member;

use think\facade\Db;

/**
 * Post-commit reconciliation for each direct-gift outbox event.
 *
 * The synchronous command writes authority, compatibility projection, event
 * and fact atomically. The outbox consumer independently confirms that the
 * immutable chain still exists before its consumer-once audit is marked done.
 */
final class CashierV3DirectGiftReconciliationConsumer
{
    public function __invoke(array $event): bool
    {
        if ((string)($event['event_type'] ?? '') !== 'gift.issued'
            || (string)($event['aggregate_type'] ?? '') !== 'direct_gift'
            || (string)($event['source_type'] ?? '') !== 'submit-direct-gift') {
            return false;
        }
        $payload = json_decode((string)($event['payload'] ?? ''), true);
        if (!is_array($payload)) return false;
        $giftId = trim((string)($payload['giftId'] ?? ''));
        $itemId = trim((string)($payload['itemId'] ?? ''));
        if ($giftId === '' || $itemId === '') return false;
        $tenantId = trim((string)($event['tenant_id'] ?? ''));
        $authority = Db::name('cashier_v3_direct_gift_authority')
            ->where('tenant_id', $tenantId)->where('gift_id', $giftId)->where('status', 'issued')->find();
        $item = Db::name('cashier_v3_direct_gift_item')
            ->where('gift_id', $giftId)->where('item_id', $itemId)->where('status', 'issued')->find();
        $fact = Db::name('cashier_v3_gift_fact')
            ->where('tenant_id', $tenantId)->where('natural_key', 'direct_gift:' . $itemId)
            ->where('source_type', 'direct_gift')->where('source_id', $giftId)->where('status', 'effective')->find();
        return is_array($authority) && is_array($item) && is_array($fact)
            && (string)($fact['business_event_no'] ?? '') === (string)($event['event_no'] ?? '');
    }
}
