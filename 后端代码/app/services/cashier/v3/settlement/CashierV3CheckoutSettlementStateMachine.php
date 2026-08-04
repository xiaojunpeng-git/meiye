<?php

namespace app\services\cashier\v3\settlement;

/**
 * Checkout-request lifecycle contract.
 *
 * This package can currently persist only editing and ready_for_submit. All
 * later transitions are reserved for the future atomic orchestration layer.
 */
final class CashierV3CheckoutSettlementStateMachine
{
    public const EDITING = 'editing';
    public const READY_FOR_SUBMIT = 'ready_for_submit';
    public const SUBMITTING = 'submitting';
    public const SUCCEEDED = 'succeeded';
    public const FAILED = 'failed';
    public const CANCELLED = 'cancelled';

    private const TRANSITIONS = [
        self::EDITING => [self::EDITING, self::READY_FOR_SUBMIT, self::CANCELLED],
        self::READY_FOR_SUBMIT => [self::EDITING, self::READY_FOR_SUBMIT, self::SUBMITTING, self::CANCELLED],
        self::SUBMITTING => [self::SUCCEEDED, self::FAILED],
        self::FAILED => [self::EDITING, self::CANCELLED],
        self::SUCCEEDED => [],
        self::CANCELLED => [],
    ];

    public static function assertKnown(string $status): void
    {
        if (!array_key_exists($status, self::TRANSITIONS)) {
            throw new CashierV3CheckoutSettlementContractException(
                'checkout_request_status_invalid',
                ['status' => $status]
            );
        }
    }

    public static function assertDraftWritable(string $status): void
    {
        self::assertKnown($status);
        if (!in_array($status, [self::EDITING, self::READY_FOR_SUBMIT, self::FAILED], true)) {
            throw new CashierV3CheckoutSettlementContractException(
                'checkout_request_not_editable',
                ['status' => $status]
            );
        }
    }

    public static function assertTransition(string $from, string $to): void
    {
        self::assertKnown($from);
        self::assertKnown($to);
        if (!in_array($to, self::TRANSITIONS[$from], true)) {
            throw new CashierV3CheckoutSettlementContractException(
                'checkout_request_transition_invalid',
                ['from' => $from, 'to' => $to]
            );
        }
    }
}
