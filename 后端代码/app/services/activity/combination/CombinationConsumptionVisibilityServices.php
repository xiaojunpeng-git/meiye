<?php
declare(strict_types=1);

namespace app\services\activity\combination;

/**
 * Consumption lists expose a paid group order only after its group has
 * completed and its rights receipt has been issued. Group management, member
 * payment and refund readers intentionally continue to see pending orders.
 */
class CombinationConsumptionVisibilityServices
{
    /**
     * Apply the same group visibility rule before list count, paging, detail
     * or export. $orderTable is a trusted query alias, never request input.
     */
    public static function apply($query, string $orderTable): void
    {
        $orderId = $orderTable . '.id';
        $orderType = $orderTable . '.type';
        $query->where(function ($visible) use ($orderId, $orderType): void {
            $visible->where($orderType, '<>', 3)->whereOr(function ($group) use ($orderId): void {
                $group->whereExists(function ($receipt) use ($orderId): void {
                    // New group orders receive this row at creation. No historical
                    // group migration or legacy status inference is attempted.
                    $receipt->name('combination_order_fulfillment')->alias('group_receipt')
                        ->whereRaw('group_receipt.order_id = ' . $orderId)
                        ->whereIn('group_receipt.state', ['issued', 'delivering']);
                });
            });
        });
    }
}
