<?php

namespace app\services\customer\care;

/**
 * 客情命令的纯输入合同。
 *
 * 权限范围和组织快照必须由共享接入层从登录态/组织主数据注入，不能直接采用
 * 页面提交值。本类只负责对已经注入的可信上下文做 fail-closed 结构校验。
 */
final class CustomerCareCommandContract
{
    /** 手工录入不接受早于约五年的跟进时间；历史导入必须另走受控迁移。 */
    public const MAX_FOLLOWED_AT_AGE_SECONDS = 158112000;

    private const UUID_PATTERN = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}';

    private const PREFIXES = [
        'CREATE_TASK' => 'CARE_CREATE_TASK',
        'CREATE_RECORD' => 'CARE_CREATE_RECORD',
        'START_TASK' => 'CARE_START_TASK',
        'COMPLETE_TASK' => 'CARE_COMPLETE_TASK',
        'DELETE_TASK' => 'CARE_DELETE_TASK',
        'VOID_TASK' => 'CARE_VOID_TASK',
        'REASSIGN_TASK' => 'CARE_REASSIGN_TASK',
        'VOID_RECORD' => 'CARE_VOID_RECORD',
    ];

    public static function normalizeActor(array $actor): array
    {
        $allowed = $actor['allowedBusinessStoreIds'] ?? null;
        if (!is_array($allowed) || self::isAssociative($allowed) || $allowed === []) {
            throw self::invalid('allowedBusinessStoreIds', '后端强制业务门店范围不能为空。');
        }
        $allowedStores = [];
        foreach ($allowed as $storeId) {
            $id = self::positiveInteger($storeId, 'allowedBusinessStoreIds');
            $allowedStores[$id] = $id;
        }
        ksort($allowedStores, SORT_NUMERIC);

        if (!array_key_exists('canReassign', $actor) || !is_bool($actor['canReassign'])) {
            throw self::invalid('canReassign', '转派权限必须由后端明确注入。');
        }

        return [
            'tenantId' => self::asciiIdentifier($actor['tenantId'] ?? null, 'tenantId', 32),
            'staffId' => self::positiveInteger($actor['staffId'] ?? null, 'staffId'),
            'employeeId' => self::positiveInteger($actor['employeeId'] ?? null, 'employeeId'),
            'staffName' => self::text($actor['staffName'] ?? null, 'staffName', 64, true),
            'operationStoreId' => self::positiveInteger(
                $actor['operationStoreId'] ?? null,
                'operationStoreId'
            ),
            'operationStoreName' => self::text(
                $actor['operationStoreName'] ?? null,
                'operationStoreName',
                100,
                true
            ),
            'operationOrganizationId' => self::asciiIdentifier(
                $actor['operationOrganizationId'] ?? null,
                'operationOrganizationId',
                32
            ),
            'operationOrganizationPath' => self::organizationPath(
                $actor['operationOrganizationPath'] ?? null,
                'operationOrganizationPath'
            ),
            'operationOrganizationName' => self::text(
                $actor['operationOrganizationName'] ?? null,
                'operationOrganizationName',
                100,
                true
            ),
            'allowedBusinessStoreIds' => array_values($allowedStores),
            'canReassign' => $actor['canReassign'],
        ];
    }

    public static function normalizeCreateTask(array $command): array
    {
        return [
            'idempotencyKey' => self::idempotencyKey('CREATE_TASK', $command['idempotencyKey'] ?? null),
            'taskKey' => self::naturalKey($command['taskKey'] ?? null, 'taskKey'),
            'businessStoreId' => self::positiveInteger(
                $command['businessStoreId'] ?? null,
                'businessStoreId'
            ),
            'businessStoreName' => self::text(
                $command['businessStoreName'] ?? null,
                'businessStoreName',
                100,
                true
            ),
            'businessOrganizationId' => self::asciiIdentifier(
                $command['businessOrganizationId'] ?? null,
                'businessOrganizationId',
                32
            ),
            'businessOrganizationPath' => self::organizationPath(
                $command['businessOrganizationPath'] ?? null,
                'businessOrganizationPath'
            ),
            'businessOrganizationName' => self::text(
                $command['businessOrganizationName'] ?? null,
                'businessOrganizationName',
                100,
                true
            ),
            'memberId' => self::positiveInteger($command['memberId'] ?? null, 'memberId'),
            'memberName' => self::text($command['memberName'] ?? null, 'memberName', 100, true),
            'ownerStaffId' => self::positiveInteger($command['ownerStaffId'] ?? null, 'ownerStaffId'),
            'taskType' => self::registeredCode($command['taskType'] ?? null, 'taskType'),
            'sourceType' => self::registeredCode($command['sourceType'] ?? null, 'sourceType'),
            'sourceId' => self::asciiIdentifier($command['sourceId'] ?? null, 'sourceId', 64),
            'title' => self::text($command['title'] ?? null, 'title', 128, true),
            'note' => self::text($command['note'] ?? '', 'note', 500, false),
            'plannedAt' => self::positiveInteger($command['plannedAt'] ?? null, 'plannedAt'),
            'plannedTimezone' => self::timezone($command['plannedTimezone'] ?? null),
        ];
    }

    public static function normalizeStartTask(array $command): array
    {
        return self::existingTask('START_TASK', $command);
    }

    public static function normalizeCompleteTask(array $command): array
    {
        $normalized = self::existingTask('COMPLETE_TASK', $command);
        $normalized['recordKey'] = self::naturalKey($command['recordKey'] ?? null, 'recordKey');
        $normalized = array_merge($normalized, self::recordContent($command));
        $createNextTask = $command['createNextTask'] ?? false;
        if (!is_bool($createNextTask)) {
            throw self::invalid('createNextTask', '是否创建下一次跟进任务必须是明确布尔值。');
        }
        $normalized['createNextTask'] = $createNextTask;
        if ($createNextTask) {
            $normalized['nextPlannedAt'] = self::positiveInteger(
                $command['nextPlannedAt'] ?? null,
                'nextPlannedAt'
            );
            $normalized['nextOwnerId'] = self::positiveInteger(
                $command['nextOwnerId'] ?? null,
                'nextOwnerId'
            );
        } else {
            foreach (['nextPlannedAt', 'nextOwnerId'] as $field) {
                $value = $command[$field] ?? null;
                if ($value !== null && $value !== '' && $value !== 0) {
                    throw self::invalid($field, '未创建下一次任务时不能提交下一任务字段。');
                }
            }
            $normalized['nextPlannedAt'] = 0;
            $normalized['nextOwnerId'] = 0;
        }
        return $normalized;
    }

    public static function normalizeCreateRecord(array $command): array
    {
        $normalized = [
            'idempotencyKey' => self::idempotencyKey(
                'CREATE_RECORD',
                $command['idempotencyKey'] ?? null
            ),
            'recordKey' => self::naturalKey($command['recordKey'] ?? null, 'recordKey'),
            'businessStoreId' => self::positiveInteger(
                $command['businessStoreId'] ?? null,
                'businessStoreId'
            ),
            'businessStoreName' => self::text(
                $command['businessStoreName'] ?? null,
                'businessStoreName',
                100,
                true
            ),
            'businessOrganizationId' => self::asciiIdentifier(
                $command['businessOrganizationId'] ?? null,
                'businessOrganizationId',
                32
            ),
            'businessOrganizationPath' => self::organizationPath(
                $command['businessOrganizationPath'] ?? null,
                'businessOrganizationPath'
            ),
            'businessOrganizationName' => self::text(
                $command['businessOrganizationName'] ?? null,
                'businessOrganizationName',
                100,
                true
            ),
            'businessTimezone' => self::timezone($command['businessTimezone'] ?? null),
            'memberId' => self::positiveInteger($command['memberId'] ?? null, 'memberId'),
            'memberName' => self::text($command['memberName'] ?? null, 'memberName', 100, true),
        ];
        return array_merge($normalized, self::recordContent($command));
    }

    public static function assertFollowedAt(int $followedAt, int $now): void
    {
        if ($followedAt > $now) {
            throw self::invalid('followedAt', '实际跟进时间不能晚于当前时间。');
        }
        if ($followedAt < $now - self::MAX_FOLLOWED_AT_AGE_SECONDS) {
            throw self::invalid('followedAt', '实际跟进时间超出手工录入允许的历史范围。');
        }
    }

    public static function assertNextPlannedAt(int $nextPlannedAt, int $now): void
    {
        if ($nextPlannedAt <= $now) {
            throw self::invalid('nextPlannedAt', '下一次跟进时间必须晚于当前时间。');
        }
    }

    public static function normalizeDeleteTask(array $command): array
    {
        $normalized = self::existingTask('DELETE_TASK', $command);
        $normalized['reason'] = self::text($command['reason'] ?? null, 'reason', 255, true);
        return $normalized;
    }

    public static function normalizeVoidTask(array $command): array
    {
        $normalized = self::existingTask('VOID_TASK', $command);
        $normalized['reason'] = self::text($command['reason'] ?? null, 'reason', 255, true);
        return $normalized;
    }

    public static function normalizeReassignTask(array $command): array
    {
        $normalized = self::existingTask('REASSIGN_TASK', $command);
        $normalized['targetStaffId'] = self::positiveInteger(
            $command['targetStaffId'] ?? null,
            'targetStaffId'
        );
        $normalized['reason'] = self::text($command['reason'] ?? null, 'reason', 255, true);
        return $normalized;
    }

    public static function normalizeVoidRecord(array $command): array
    {
        $hasTaskId = array_key_exists('taskId', $command) && $command['taskId'] !== null;
        $hasTaskVersion = array_key_exists('expectedVersion', $command)
            && $command['expectedVersion'] !== null;
        if ($hasTaskId !== $hasTaskVersion) {
            throw self::invalid(
                'taskContext',
                '有关联任务时必须同时提供任务标识和正版本；独立记录不得伪造零版本。'
            );
        }
        $normalized = [
            'idempotencyKey' => self::idempotencyKey(
                'VOID_RECORD',
                $command['idempotencyKey'] ?? null
            ),
            'taskId' => $hasTaskId
                ? self::positiveInteger($command['taskId'], 'taskId')
                : null,
            'expectedVersion' => $hasTaskVersion
                ? self::positiveInteger($command['expectedVersion'], 'expectedVersion')
                : null,
        ];
        $normalized['recordId'] = self::positiveInteger($command['recordId'] ?? null, 'recordId');
        $normalized['expectedRecordVersion'] = self::positiveInteger(
            $command['expectedRecordVersion'] ?? null,
            'expectedRecordVersion'
        );
        $normalized['reason'] = self::text($command['reason'] ?? null, 'reason', 255, true);
        return $normalized;
    }

    public static function fingerprint(string $operationType, array $actor, array $command): string
    {
        $identity = [
            'tenantId' => $actor['tenantId'],
            'staffId' => $actor['staffId'],
            'employeeId' => $actor['employeeId'],
            'operationStoreId' => $actor['operationStoreId'],
        ];
        $payload = self::sortRecursively([
            'operationType' => $operationType,
            'actor' => $identity,
            'command' => $command,
        ]);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw self::invalid('command', '客情命令无法生成稳定请求指纹。');
        }
        return hash('sha256', $json);
    }

    private static function existingTask(string $operationType, array $command): array
    {
        return [
            'idempotencyKey' => self::idempotencyKey(
                $operationType,
                $command['idempotencyKey'] ?? null
            ),
            'taskId' => self::positiveInteger($command['taskId'] ?? null, 'taskId'),
            'expectedVersion' => self::positiveInteger(
                $command['expectedVersion'] ?? null,
                'expectedVersion'
            ),
        ];
    }

    private static function recordContent(array $command): array
    {
        $relatedTypeRaw = $command['relatedBusinessType'] ?? '';
        $relatedIdRaw = $command['relatedBusinessId'] ?? '';
        $relatedLabelRaw = $command['relatedBusinessLabel'] ?? '';
        $relatedEmpty = $relatedTypeRaw === '' && $relatedIdRaw === '' && $relatedLabelRaw === '';
        if (!$relatedEmpty
            && ($relatedTypeRaw === '' || $relatedIdRaw === '' || $relatedLabelRaw === '')) {
            throw self::invalid('relatedBusiness', '关联业务必须同时提供类型、标识和名称快照。');
        }

        return [
            'recordType' => self::registeredCode($command['recordType'] ?? null, 'recordType'),
            'followedAt' => self::positiveInteger($command['followedAt'] ?? null, 'followedAt'),
            'followupMethod' => self::registeredCode($command['followupMethod'] ?? null, 'followupMethod'),
            'resultCode' => self::registeredCode($command['resultCode'] ?? null, 'resultCode'),
            'summary' => self::text($command['summary'] ?? null, 'summary', 255, true),
            'detail' => self::text($command['detail'] ?? '', 'detail', 4000, false),
            'serviceBeforePhotos' => self::photoUrls($command['serviceBeforePhotos'] ?? [], 'serviceBeforePhotos'),
            'serviceAfterPhotos' => self::photoUrls($command['serviceAfterPhotos'] ?? [], 'serviceAfterPhotos'),
            'relatedBusinessType' => $relatedEmpty
                ? ''
                : self::registeredCode($relatedTypeRaw, 'relatedBusinessType'),
            'relatedBusinessId' => $relatedEmpty
                ? ''
                : self::asciiIdentifier($relatedIdRaw, 'relatedBusinessId', 64),
            'relatedBusinessLabel' => $relatedEmpty
                ? ''
                : self::text($relatedLabelRaw, 'relatedBusinessLabel', 128, true),
        ];
    }

    /** @return string[] */
    private static function photoUrls($value, string $field): array
    {
        if ($value === null || $value === '') return [];
        if (!is_array($value) || count($value) > 9) {
            throw self::invalid($field, '服务照片最多保存 9 张。');
        }
        $urls = [];
        foreach ($value as $url) {
            if (!is_string($url)) throw self::invalid($field, '服务照片地址无效。');
            $url = trim($url);
            if ($url === '' || strlen($url) > 1024 || !preg_match('/^https?:\/\/[^\s]+$/D', $url)) {
                throw self::invalid($field, '服务照片地址无效。');
            }
            $urls[$url] = $url;
        }
        return array_values($urls);
    }

    private static function idempotencyKey(string $operationType, $value): string
    {
        $prefix = self::PREFIXES[$operationType] ?? '';
        $key = is_string($value) ? trim($value) : '';
        $matched = [];
        if ($prefix === '' || !preg_match('/^(' . preg_quote($prefix, '/') . ')-(' . self::UUID_PATTERN . ')$/', $key, $matched)) {
            throw new CustomerCareDomainException(
                CustomerCareErrorCode::INVALID_IDEMPOTENCY_KEY,
                '客情命令请求标识无效。',
                ['operationType' => $operationType]
            );
        }
        return $prefix . '-' . strtolower($matched[2]);
    }

    private static function naturalKey($value, string $field): string
    {
        $key = is_string($value) ? trim($value) : '';
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9:._-]{7,63}$/D', $key)) {
            throw self::invalid($field, '客情业务自然键无效。');
        }
        return $key;
    }

    private static function asciiIdentifier($value, string $field, int $maxLength): string
    {
        $identifier = is_string($value) || is_int($value) ? trim((string)$value) : '';
        if ($identifier === ''
            || strlen($identifier) > $maxLength
            || !preg_match('/^[A-Za-z0-9][A-Za-z0-9:._-]*$/D', $identifier)) {
            throw self::invalid($field, '客情标识字段无效。');
        }
        return $identifier;
    }

    private static function organizationPath($value, string $field): string
    {
        $path = is_string($value) ? trim($value) : '';
        if ($path === ''
            || strlen($path) > 191
            || !preg_match('/^[A-Za-z0-9_\.\/:\-]+$/D', $path)) {
            throw self::invalid($field, '组织路径无效。');
        }
        return $path;
    }

    private static function enumToken($value, string $field, int $maxLength): string
    {
        $token = is_string($value) ? trim($value) : '';
        if ($token === ''
            || strlen($token) > $maxLength
            || !preg_match('/^[A-Z][A-Z0-9_]*$/D', $token)) {
            throw self::invalid($field, '客情枚举值无效。');
        }
        return $token;
    }

    private static function registeredCode($value, string $field): string
    {
        return CustomerCareCodeRegistry::assertKnown(
            $field,
            self::enumToken($value, $field, 32)
        );
    }

    private static function timezone($value): string
    {
        $timezone = is_string($value) ? trim($value) : '';
        if ($timezone === '' || strlen($timezone) > 32) {
            throw self::invalid('plannedTimezone', '客情任务业务时区无效。');
        }
        try {
            new \DateTimeZone($timezone);
        } catch (\Throwable $throwable) {
            throw self::invalid('plannedTimezone', '客情任务业务时区无效。');
        }
        return $timezone;
    }

    private static function positiveInteger($value, string $field): int
    {
        if (is_int($value)) {
            $number = $value;
        } elseif (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value)) {
            $number = (int)$value;
        } else {
            throw self::invalid($field, '客情正整数参数无效。');
        }
        if ($number <= 0) {
            throw self::invalid($field, '客情正整数参数无效。');
        }
        if (!is_int($value) && (string)$number !== (string)$value) {
            throw self::invalid($field, '客情正整数参数无效。');
        }
        return $number;
    }

    private static function text($value, string $field, int $maxLength, bool $required): string
    {
        if (!is_string($value)) {
            throw self::invalid($field, '客情文本字段无效。');
        }
        $text = trim($value);
        $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
        if (($required && $text === '') || $length > $maxLength || strpos($text, "\0") !== false) {
            throw self::invalid($field, '客情文本字段无效。');
        }
        return $text;
    }

    private static function sortRecursively(array $value): array
    {
        if (!self::isAssociative($value)) {
            return array_map(static function ($item) {
                return is_array($item) ? self::sortRecursively($item) : $item;
            }, $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sortRecursively($item);
            }
        }
        return $value;
    }

    private static function isAssociative(array $value): bool
    {
        return array_keys($value) !== ($value ? range(0, count($value) - 1) : []);
    }

    private static function invalid(string $field, string $message): CustomerCareDomainException
    {
        return new CustomerCareDomainException(
            CustomerCareErrorCode::INVALID_ARGUMENT,
            $message,
            ['field' => $field]
        );
    }
}
