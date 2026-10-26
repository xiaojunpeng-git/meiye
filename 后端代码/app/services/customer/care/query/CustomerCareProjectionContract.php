<?php

namespace app\services\customer\care\query;

use app\services\customer\care\CustomerCareRecordState;
use app\services\customer\care\CustomerCareTaskState;

final class CustomerCareProjectionContract
{
    public const CONTRACT_VERSION = 'customer-care.v1';
    public const BUSINESS_TIMEZONE = 'Asia/Shanghai';
    public const MAX_PAGE_SIZE = 100;
    public const DEFAULT_PAGE_SIZE = 100;

    public const VIEW_TASKS = 'tasks';
    public const VIEW_CUSTOMERS = 'customers';
    public const VIEW_RECORDS = 'records';
    public const VIEW_STATISTICS = 'statistics';
    public const VIEW_SETTINGS = 'settings';

    public const STATISTICS_METRIC_OPEN_WORKLOAD = 'care_open_workload';
    public const STATISTICS_METRIC_COMPLETED = 'care_completed';
    public const STATISTICS_METRIC_OVERDUE = 'care_overdue_current';
    public const STATISTICS_METRIC_ACTIVITY_RECORDS = 'care_activity_records';
    public const STATISTICS_METRIC_EMPLOYEE_OPEN_WORKLOAD = 'employee_current_open_workload';
    public const STATISTICS_METRIC_EMPLOYEE_COMPLETED = 'employee_completed_by_actual_follower';
    public const STATISTICS_METRIC_EMPLOYEE_OVERDUE = 'employee_current_overdue';

    public const BUCKET_TODAY = 'today';
    public const BUCKET_OVERDUE = 'overdue';
    public const BUCKET_FUTURE = 'future';
    public const BUCKET_COMPLETED = 'completed';
    public const BUCKET_ALL = 'all';

    private const TASK_LABELS = [
        'FOLLOWUP' => '回访',
        'DAILY_FOLLOWUP' => '日常跟进',
        'INVITATION' => '邀约',
        'SERVICE_FEEDBACK' => '服务后反馈',
    ];

    private const SOURCE_LABELS = [
        'MANUAL' => '手工创建',
        'SERVICE_COMPLETED' => '服务后自动产生',
        'PREVIOUS_FOLLOWUP' => '上次跟进生成',
    ];

    private const STATUS_LABELS = [
        CustomerCareTaskState::UNSTARTED => '未开始',
        CustomerCareTaskState::IN_PROGRESS => '进行中',
        CustomerCareTaskState::COMPLETED => '已完成',
        CustomerCareTaskState::VOIDED => '已作废',
    ];

    private const RECORD_STATUS_LABELS = [
        CustomerCareRecordState::NORMAL => '正常',
        CustomerCareRecordState::VOIDED => '已作废',
    ];

    private const METHOD_LABELS = [
        'PHONE' => '电话',
        'WECHAT' => '微信',
        'IN_STORE' => '到店沟通',
        'OTHER' => '其他',
    ];

    private const RESULT_LABELS = [
        'SATISFIED' => '满意',
        'INTENTIONAL' => '有意向',
        'FOLLOW_UP' => '需要继续跟进',
        'APPOINTMENT_SUCCESS' => '预约成功',
    ];

    public static function normalizeWorkbenchRequest(array $request, CustomerCareQueryScope $scope): array
    {
        $view = self::enum(
            $request['view'] ?? self::VIEW_TASKS,
            'view',
            [self::VIEW_TASKS, self::VIEW_CUSTOMERS, self::VIEW_RECORDS,
                self::VIEW_STATISTICS, self::VIEW_SETTINGS]
        );
        $query = $request['query'] ?? [];
        if (!is_array($query) || self::isList($query)) {
            throw self::invalid('query');
        }

        return [
            'view' => $view,
            'taskQuery' => self::normalizeTaskQuery(
                $view === self::VIEW_TASKS ? $query : [],
                $scope
            ),
            'customerQuery' => self::normalizeCustomerQuery(
                $view === self::VIEW_CUSTOMERS ? $query : []
            ),
            'recordQuery' => self::normalizeRecordQuery(
                $view === self::VIEW_RECORDS ? $query : [],
                $scope
            ),
            'statisticsQuery' => self::normalizeStatisticsQuery(
                $view === self::VIEW_STATISTICS ? $query : [],
                $scope
            ),
        ];
    }

    public static function normalizeTaskQuery(array $query, CustomerCareQueryScope $scope): array
    {
        $requestedScope = self::enum(
            $query['scope'] ?? ($scope->canViewAllTasks() ? 'all' : 'my'),
            'scope',
            ['my', 'all']
        );
        if ($requestedScope === 'all' && !$scope->canViewAllTasks()) {
            throw new CustomerCareProjectionException(
                CustomerCareProjectionErrorCode::QUERY_FORBIDDEN,
                '当前账号没有全部客情任务查看权限。',
                ['field' => 'scope']
            );
        }
        $statusRaw = $query['status'] ?? '';
        if (!is_string($statusRaw)) {
            throw self::invalid('status');
        }
        $status = trim($statusRaw);
        if ($status !== '' && !in_array($status, CustomerCareTaskState::all(), true)) {
            throw self::invalid('status');
        }
		$statusGroup = self::enum($query['statusGroup'] ?? '', 'statusGroup', ['', 'open']);
        $plannedRange = self::timeRange(
            $query['plannedFrom'] ?? '',
            $query['plannedTo'] ?? '',
            'plannedFrom',
            'plannedTo',
            $scope->businessTimezone()
        );
        return [
            'scope' => $requestedScope,
            'bucket' => self::enum(
                $query['bucket'] ?? self::BUCKET_ALL,
                'bucket',
                [self::BUCKET_TODAY, self::BUCKET_OVERDUE, self::BUCKET_FUTURE,
                    self::BUCKET_COMPLETED, self::BUCKET_ALL]
            ),
            'keyword' => self::keyword($query['keyword'] ?? ''),
            'status' => $status,
			'statusGroup' => $statusGroup,
			'memberId' => self::optionalPositiveInt($query['memberId'] ?? null, 'memberId'),
            'plannedFrom' => $plannedRange['from'],
            'plannedTo' => $plannedRange['to'],
            'pageSize' => self::pageSize($query['pageSize'] ?? self::DEFAULT_PAGE_SIZE),
            'cursor' => self::cursor($query['cursor'] ?? ''),
        ];
    }

    public static function normalizeCustomerQuery(array $query): array
    {
        return [
            'keyword' => self::keyword($query['keyword'] ?? ''),
            'memberId' => self::optionalPositiveInt($query['memberId'] ?? null, 'memberId'),
            'pageSize' => self::pageSize($query['pageSize'] ?? self::DEFAULT_PAGE_SIZE),
            'cursor' => self::cursor($query['cursor'] ?? ''),
        ];
    }

    public static function normalizeRecordQuery(array $query, CustomerCareQueryScope $scope): array
    {
        $followedRange = self::timeRange(
            $query['followedFrom'] ?? '',
            $query['followedTo'] ?? '',
            'followedFrom',
            'followedTo',
            $scope->businessTimezone()
        );
        return [
            'keyword' => self::keyword($query['keyword'] ?? ''),
            'memberId' => self::optionalPositiveInt($query['memberId'] ?? null, 'memberId'),
            'stream' => self::enum($query['stream'] ?? 'human', 'stream', ['human', 'appointment']),
            'dataScope' => self::enum($query['dataScope'] ?? 'normal', 'dataScope', ['normal', 'all']),
            'followedFrom' => $followedRange['from'],
            'followedTo' => $followedRange['to'],
            'pageSize' => self::pageSize($query['pageSize'] ?? self::DEFAULT_PAGE_SIZE),
            'cursor' => self::cursor($query['cursor'] ?? ''),
        ];
    }

    /**
     * Statistics detail is read-only and only accepts a fixed metric vocabulary.
     * Staff filtering can narrow the server-authorized team scope, never widen it.
     */
    public static function normalizeStatisticsQuery(array $query, CustomerCareQueryScope $scope): array
    {
        $metric = $query['metric'] ?? '';
        if (!is_string($metric)) {
            throw self::invalid('metric');
        }
        $metric = trim($metric);
        if ($metric === '') {
            return [
                'metric' => '',
                'staffId' => 0,
                'pageSize' => self::DEFAULT_PAGE_SIZE,
                'cursor' => '',
            ];
        }
        if (!$scope->canViewStatistics()) {
            throw new CustomerCareProjectionException(
                CustomerCareProjectionErrorCode::QUERY_FORBIDDEN,
                '当前账号没有跟进统计查看权限。',
                ['field' => 'metric']
            );
        }
        $employeeMetrics = [
            self::STATISTICS_METRIC_EMPLOYEE_OPEN_WORKLOAD,
            self::STATISTICS_METRIC_EMPLOYEE_COMPLETED,
            self::STATISTICS_METRIC_EMPLOYEE_OVERDUE,
        ];
        $allowed = array_merge([
            self::STATISTICS_METRIC_OPEN_WORKLOAD,
            self::STATISTICS_METRIC_COMPLETED,
            self::STATISTICS_METRIC_OVERDUE,
            self::STATISTICS_METRIC_ACTIVITY_RECORDS,
        ], $employeeMetrics);
        if (!in_array($metric, $allowed, true)) {
            throw self::invalid('metric');
        }
        $staffId = self::optionalPositiveInt($query['staffId'] ?? null, 'staffId');
        if (in_array($metric, $employeeMetrics, true) && $staffId <= 0) {
            throw self::invalid('staffId');
        }
        if (!in_array($metric, $employeeMetrics, true) && $staffId !== 0) {
            throw self::invalid('staffId');
        }
        return [
            'metric' => $metric,
            'staffId' => $staffId,
            'pageSize' => self::pageSize($query['pageSize'] ?? self::DEFAULT_PAGE_SIZE),
            'cursor' => self::cursor($query['cursor'] ?? ''),
        ];
    }

    public static function bucket(string $status, int $plannedAt, int $now, int $dayEnd): string
    {
        if ($status === CustomerCareTaskState::COMPLETED) {
            return self::BUCKET_COMPLETED;
        }
        if (!in_array($status, [CustomerCareTaskState::UNSTARTED, CustomerCareTaskState::IN_PROGRESS], true)) {
            return self::BUCKET_ALL;
        }
        if ($plannedAt < $now) {
            return self::BUCKET_OVERDUE;
        }
        if ($plannedAt <= $dayEnd) {
            return self::BUCKET_TODAY;
        }
        return self::BUCKET_FUTURE;
    }

    public static function dayEnd(int $now, string $timezone): int
    {
        return (new \DateTimeImmutable('@' . $now))
            ->setTimezone(new \DateTimeZone($timezone))
            ->setTime(23, 59, 59)
            ->getTimestamp();
    }

    public static function dateTime(int $timestamp, string $timezone, bool $seconds = false): string
    {
        if ($timestamp <= 0) {
            return '';
        }
        return (new \DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new \DateTimeZone($timezone))
            ->format($seconds ? 'Y-m-d H:i:s' : 'Y-m-d H:i');
    }

    public static function taskLabel(string $code): string
    {
        return self::TASK_LABELS[$code] ?? $code;
    }

    public static function sourceLabel(string $code): string
    {
        return self::SOURCE_LABELS[$code] ?? $code;
    }

    public static function taskStatusLabel(string $code): string
    {
        return self::STATUS_LABELS[$code] ?? $code;
    }

    public static function recordStatusLabel(string $code): string
    {
        return self::RECORD_STATUS_LABELS[$code] ?? $code;
    }

    public static function methodLabel(string $code): string
    {
        return self::METHOD_LABELS[$code] ?? $code;
    }

    public static function resultLabel(string $code): string
    {
        return self::RESULT_LABELS[$code] ?? $code;
    }

    public static function statusOptions(): array
    {
        $options = [['value' => '', 'label' => '全部状态']];
        foreach (CustomerCareTaskState::all() as $status) {
            $options[] = ['value' => $status, 'label' => self::taskStatusLabel($status)];
        }
        return $options;
    }

    public static function methodOptions(): array
    {
        $options = [];
        foreach (self::METHOD_LABELS as $value => $label) {
            $options[] = ['value' => $value, 'label' => $label];
        }
        return $options;
    }

    public static function resultOptions(): array
    {
        $options = [];
        foreach (self::RESULT_LABELS as $value => $label) {
            $options[] = ['value' => $value, 'label' => $label];
        }
        return $options;
    }

    public static function queryHash(string $view, CustomerCareQueryScope $scope, array $query): string
    {
        $canonical = $query;
        unset($canonical['cursor']);
        ksort($canonical, SORT_STRING);
        return hash('sha256', json_encode([
            'contractVersion' => self::CONTRACT_VERSION,
            'view' => $view,
            'scope' => $scope->fingerprint(),
            'query' => $canonical,
        ], JSON_UNESCAPED_SLASHES));
    }

    private static function enum($value, string $field, array $allowed): string
    {
        $value = is_string($value) ? trim($value) : '';
        if (!in_array($value, $allowed, true)) {
            throw self::invalid($field);
        }
        return $value;
    }

    private static function optionalPositiveInt($value, string $field): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        if (!is_int($value) && !(is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value))) {
            throw self::invalid($field);
        }
        return (int)$value;
    }

    private static function keyword($value): string
    {
        if (!is_string($value) || strpos($value, "\0") !== false) {
            throw self::invalid('keyword');
        }
        $value = trim($value);
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        if ($length > 100) {
            throw self::invalid('keyword');
        }
        return $value;
    }

    private static function pageSize($value): int
    {
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value)) {
            $value = (int)$value;
        }
        if (!is_int($value) || $value < 1 || $value > self::MAX_PAGE_SIZE) {
            throw self::invalid('pageSize');
        }
        return $value;
    }

    private static function cursor($value): string
    {
        if (!is_string($value) || strlen($value) > 2048 || strpos($value, "\0") !== false) {
            throw self::invalid('cursor');
        }
        return trim($value);
    }

    /** @return array{from:int,to:int} */
    private static function timeRange($from, $to, string $fromField, string $toField, string $timezone): array
    {
        $fromTimestamp = self::optionalDateTime($from, $fromField, $timezone, false);
        $toTimestamp = self::optionalDateTime($to, $toField, $timezone, true);
        if ($fromTimestamp > 0 && $toTimestamp > 0 && $fromTimestamp > $toTimestamp) {
            throw self::invalid($fromField);
        }
        return ['from' => $fromTimestamp, 'to' => $toTimestamp];
    }

    private static function optionalDateTime($value, string $field, string $timezone, bool $endOfDay): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        if (!is_string($value)) {
            throw self::invalid($field);
        }
        $value = trim($value);
        if ($value === '') {
            return 0;
        }
        foreach (['!Y-m-d\\TH:i', '!Y-m-d H:i', '!Y-m-d H:i:s', '!Y-m-d'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value, new \DateTimeZone($timezone));
            $errors = \DateTimeImmutable::getLastErrors();
            if ($date instanceof \DateTimeImmutable
                && ($errors === false || ((int)$errors['warning_count'] === 0 && (int)$errors['error_count'] === 0))) {
                return $format === '!Y-m-d' && $endOfDay
                    ? $date->setTime(23, 59, 59)->getTimestamp()
                    : $date->getTimestamp();
            }
        }
        throw self::invalid($field);
    }

    private static function isList(array $value): bool
    {
        return $value !== [] && array_keys($value) === range(0, count($value) - 1);
    }

    private static function invalid(string $field): CustomerCareProjectionException
    {
        return new CustomerCareProjectionException(
            CustomerCareProjectionErrorCode::INVALID_QUERY,
            '客情查询参数无效。',
            ['field' => $field]
        );
    }
}
