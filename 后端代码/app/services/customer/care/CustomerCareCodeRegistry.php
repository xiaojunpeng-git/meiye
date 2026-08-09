<?php

namespace app\services\customer\care;

/** 客情领域持久化代码白名单；页面字典只能从这些稳定代码派生。 */
final class CustomerCareCodeRegistry
{
    private const CODES = [
        'taskType' => ['FOLLOWUP', 'DAILY_FOLLOWUP', 'INVITATION', 'SERVICE_FEEDBACK'],
        'sourceType' => ['MANUAL', 'SERVICE_COMPLETED', 'PREVIOUS_FOLLOWUP'],
        'recordType' => ['FOLLOWUP', 'DAILY_FOLLOWUP', 'INVITATION', 'SERVICE_FEEDBACK'],
        'followupMethod' => ['PHONE', 'WECHAT', 'IN_STORE', 'OTHER'],
        'resultCode' => ['SATISFIED', 'INTENTIONAL', 'FOLLOW_UP', 'APPOINTMENT_SUCCESS'],
        'relatedBusinessType' => ['MEMBER', 'ORDER', 'SERVICE', 'RESERVATION', 'CARE_RECORD'],
    ];

    public static function assertKnown(string $dimension, string $code): string
    {
        if (!isset(self::CODES[$dimension]) || !in_array($code, self::CODES[$dimension], true)) {
            throw new CustomerCareDomainException(
                CustomerCareErrorCode::INVALID_ARGUMENT,
                '客情业务代码不在后端白名单中。',
                ['field' => $dimension, 'code' => $code]
            );
        }
        return $code;
    }

    /** @return string[] */
    public static function codes(string $dimension): array
    {
        return self::CODES[$dimension] ?? [];
    }
}
