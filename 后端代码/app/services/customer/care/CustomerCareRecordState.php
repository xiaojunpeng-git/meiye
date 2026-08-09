<?php

namespace app\services\customer\care;

final class CustomerCareRecordState
{
    public const NORMAL = 'NORMAL';
    public const VOIDED = 'VOIDED';

    public static function all(): array
    {
        return [self::NORMAL, self::VOIDED];
    }

    public static function assertCanVoid(string $status): void
    {
        if ($status !== self::NORMAL) {
            throw new CustomerCareDomainException(
                CustomerCareErrorCode::INVALID_RECORD_TRANSITION,
                '只有正常的客情记录可以作废。',
                ['from' => $status, 'to' => self::VOIDED]
            );
        }
    }
}
