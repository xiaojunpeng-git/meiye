<?php

namespace app\services\customer\care;

final class CustomerCareTaskState
{
    public const UNSTARTED = 'UNSTARTED';
    public const IN_PROGRESS = 'IN_PROGRESS';
    public const COMPLETED = 'COMPLETED';
    public const VOIDED = 'VOIDED';

    public static function all(): array
    {
        return [self::UNSTARTED, self::IN_PROGRESS, self::COMPLETED, self::VOIDED];
    }

    public static function assertKnown(string $status): void
    {
        if (!in_array($status, self::all(), true)) {
            throw new CustomerCareDomainException(
                CustomerCareErrorCode::INVALID_TASK_TRANSITION,
                '客情任务状态无效。',
                ['status' => $status]
            );
        }
    }

    public static function assertCanStart(string $status): void
    {
        self::assertTransition($status, self::IN_PROGRESS, [self::UNSTARTED]);
    }

    public static function assertCanComplete(string $status): void
    {
        self::assertTransition($status, self::COMPLETED, [self::IN_PROGRESS]);
    }

    public static function assertCanVoid(string $status): void
    {
        self::assertTransition($status, self::VOIDED, [self::IN_PROGRESS]);
    }

    public static function assertCanDelete(string $status): void
    {
        self::assertKnown($status);
        if ($status !== self::UNSTARTED) {
            throw new CustomerCareDomainException(
                CustomerCareErrorCode::INVALID_TASK_TRANSITION,
                '只有未开始的客情任务可以删除。',
                ['status' => $status, 'operation' => 'DELETE_TASK']
            );
        }
    }

    public static function assertCanReassign(string $status): void
    {
        self::assertKnown($status);
        if (!in_array($status, [self::UNSTARTED, self::IN_PROGRESS], true)) {
            throw new CustomerCareDomainException(
                CustomerCareErrorCode::INVALID_TASK_TRANSITION,
                '只有未开始或进行中的客情任务可以转派。',
                ['status' => $status, 'operation' => 'REASSIGN_TASK']
            );
        }
    }

    public static function isOverdue(string $status, int $plannedAt, int $now): bool
    {
        self::assertKnown($status);
        return in_array($status, [self::UNSTARTED, self::IN_PROGRESS], true)
            && $plannedAt > 0
            && $plannedAt < $now;
    }

    private static function assertTransition(string $from, string $to, array $allowedFrom): void
    {
        self::assertKnown($from);
        if (!in_array($from, $allowedFrom, true)) {
            throw new CustomerCareDomainException(
                CustomerCareErrorCode::INVALID_TASK_TRANSITION,
                '客情任务当前状态不允许执行该操作。',
                ['from' => $from, 'to' => $to]
            );
        }
    }
}
