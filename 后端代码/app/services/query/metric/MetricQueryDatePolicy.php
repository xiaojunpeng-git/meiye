<?php
namespace app\services\query\metric;

/** Single execution date contract. Semantic understanding does not apply this budget. */
final class MetricQueryDatePolicy
{
    public const MAX_DAYS = 366; // inclusive of both endpoints
    public const TIMEZONE = 'Asia/Shanghai';

    public static function date($value): \DateTimeImmutable
    {
        if (!is_string($value) || !preg_match('/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}$/D', $value)) self::fail('METRIC_QUERY_RANGE_INVALID');
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone(self::TIMEZONE));
        if (!$date || $date->format('Y-m-d') !== $value) self::fail('METRIC_QUERY_RANGE_INVALID');
        return $date;
    }

    /** Shape/calendar validation only; preserves long, early and future user ranges. */
    public static function normalize(array $range): array
    {
        $keys = array_keys($range); sort($keys);
        if ($keys !== ['end', 'start']) self::fail('METRIC_QUERY_RANGE_INVALID');
        self::date($range['start']); self::date($range['end']);
        if ($range['start'] > $range['end']) self::fail('METRIC_QUERY_RANGE_REVERSED');
        return $range;
    }

    /** Validates calendar/span only, never clamps to historical ingestion dates.
     * Each comparison period is checked independently against the same rules. */
    public static function assertExecutable(array $range, ?string $today = null): void
    {
        self::normalize($range);
        $today = $today ?? (new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE)))->format('Y-m-d');
        self::date($today);
        // An explicit date ending in the future cannot be reinterpreted as a
        // shorter historical request. Report that user-visible boundary first,
        // even if the requested range is also too long.
        if ($range['end'] > $today) self::fail('METRIC_QUERY_FUTURE_UNAVAILABLE');
        $days = self::date($range['start'])->diff(self::date($range['end']))->days + 1;
        if ($days > self::MAX_DAYS) self::fail('METRIC_QUERY_RANGE_TOO_LONG');
    }

    /** Protocol translation, not a second set of execution rules. */
    public static function aiReason(string $reason): string
    {
        return [
            'METRIC_QUERY_RANGE_INVALID' => 'AI_DATE_INVALID',
            'METRIC_QUERY_RANGE_REVERSED' => 'AI_DATE_REVERSED',
            'METRIC_QUERY_RANGE_TOO_LONG' => 'AI_DATE_RANGE_TOO_LONG',
            'METRIC_QUERY_FUTURE_UNAVAILABLE' => 'AI_FUTURE_ACTUALS_UNAVAILABLE',
        ][$reason] ?? $reason;
    }

    private static function fail(string $reason): void
    {
        throw new MetricQueryContractException($reason, '查询日期不符合当前数据读取条件。');
    }
}
