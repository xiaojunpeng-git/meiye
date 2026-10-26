<?php

namespace app\services\cashier\v3\event;

/** Single route fingerprint algorithm shared by event writers and consumers. */
final class CashierV3EventRouteFingerprint
{
    public static function calculate(string $sourceType, string $eventType, array $consumerCodes): string
    {
        $consumers = array_values(array_unique(array_map('strval', $consumerCodes)));
        sort($consumers, SORT_STRING);
        return hash('sha256', $sourceType . '|' . $eventType . '|' . implode(',', $consumers));
    }
}
