<?php

namespace app\services\cashier\v3\service;

final class CashierV3ServiceOrderState
{
    public const OPEN = 'OPEN';
    public const IN_SERVICE = 'IN_SERVICE';
    public const PENDING_CHECKOUT = 'PENDING_CHECKOUT';
    public const COMPLETED = 'COMPLETED';
    public const CANCELLED = 'CANCELLED';
    public const VOIDED = 'VOIDED';

    public const LINE_ACTIVE = 'ACTIVE';
    public const LINE_RELEASED = 'RELEASED';

    public static function activeOrderStatuses(): array
    {
        return [self::OPEN, self::IN_SERVICE, self::PENDING_CHECKOUT];
    }

    public static function allOrderStatuses(): array
    {
        return array_merge(self::activeOrderStatuses(), [
            self::COMPLETED,
            self::CANCELLED,
            self::VOIDED,
        ]);
    }

    public static function isActiveOrder(string $status): bool
    {
        return in_array($status, self::activeOrderStatuses(), true);
    }

    public static function isActiveLine(string $status): bool
    {
        return $status === self::LINE_ACTIVE;
    }

    public static function assertOrdinaryTransition(string $from, string $to): void
    {
        $allowed = [
            self::OPEN => [self::IN_SERVICE, self::PENDING_CHECKOUT, self::CANCELLED, self::VOIDED],
            self::IN_SERVICE => [self::PENDING_CHECKOUT, self::CANCELLED, self::VOIDED],
            self::PENDING_CHECKOUT => [self::IN_SERVICE, self::CANCELLED, self::VOIDED],
            self::COMPLETED => [],
            self::CANCELLED => [],
            self::VOIDED => [],
        ];
        if (!isset($allowed[$from]) || !in_array($to, $allowed[$from], true)) {
            throw new CashierV3ServiceOrderAuthorityException('service_order_transition_invalid', [
                'from' => $from,
                'to' => $to,
            ]);
        }
        if ($to === self::COMPLETED) {
            throw new CashierV3ServiceOrderAuthorityException('service_order_completion_requires_business_transaction');
        }
    }

    public static function assertBusinessCompletionTransition(
        string $from,
        bool $allActiveLinesReleased
    ): void {
        if (!self::isActiveOrder($from)) {
            throw new CashierV3ServiceOrderAuthorityException(
                'service_order_completion_source_status_invalid',
                ['from' => $from]
            );
        }
        if (!$allActiveLinesReleased) {
            throw new CashierV3ServiceOrderAuthorityException(
                'service_order_completion_active_lines_remain'
            );
        }
    }
}
