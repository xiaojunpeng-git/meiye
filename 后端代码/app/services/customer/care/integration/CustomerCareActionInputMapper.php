<?php

namespace app\services\customer\care\integration;

use app\services\customer\care\CustomerCareClock;
use app\services\customer\care\CustomerCareCodeRegistry;
use app\services\customer\care\query\CustomerCareProjectionContract;
use app\services\customer\care\query\CustomerCareProjectionErrorCode;
use app\services\customer\care\query\CustomerCareProjectionException;
use app\services\customer\care\query\CustomerCareQueryRepository;
use app\services\customer\care\query\CustomerCareQueryScope;

/** Maps page payloads to the strict Phase A command contract using server snapshots. */
final class CustomerCareActionInputMapper
{
    public const QUERY = 'query-care-workbench';
    public const CREATE_TASK = 'create-care-task';
    public const START_TASK = 'start-care-task';
    public const COMPLETE_TASK = 'complete-care-task';
    public const REASSIGN_TASK = 'reassign-care-task';
    public const VOID_TASK = 'void-care-task';
    public const DELETE_TASK = 'delete-care-task';
    public const CREATE_RECORD = 'create-care-record';
    public const VOID_RECORD = 'void-care-record';

    /** @var CustomerCareQueryRepository */
    private $repository;

    /** @var CustomerCareClock */
    private $clock;

    public function __construct(CustomerCareQueryRepository $repository, CustomerCareClock $clock)
    {
        $this->repository = $repository;
        $this->clock = $clock;
    }

    /** @return array{method:string,command:array} */
    public function map(
        string $action,
        CustomerCareQueryScope $scope,
        array $payload,
        string $gatewayIdempotencyKey
    ): array {
        $methodMap = [
            self::CREATE_TASK => 'createTask',
            self::START_TASK => 'startTask',
            self::COMPLETE_TASK => 'completeTask',
            self::REASSIGN_TASK => 'reassignTask',
            self::VOID_TASK => 'voidTask',
            self::DELETE_TASK => 'deleteTask',
            self::CREATE_RECORD => 'createRecord',
            self::VOID_RECORD => 'voidRecord',
        ];
        if (!isset($methodMap[$action])) {
            throw new CustomerCareProjectionException(
                CustomerCareProjectionErrorCode::ACTION_UNSUPPORTED,
                '客情动作未注册。',
                ['action' => $action]
            );
        }
        $idempotencyKey = $this->domainIdempotencyKey(
            $action,
            $scope->tenantId(),
            $gatewayIdempotencyKey
        );
        switch ($action) {
            case self::CREATE_TASK:
                $command = $this->createTask($scope, $payload, $idempotencyKey);
                break;
            case self::START_TASK:
                $command = $this->existingTask($payload, $idempotencyKey);
                break;
            case self::COMPLETE_TASK:
                $command = $this->completeTask($scope, $payload, $idempotencyKey);
                break;
            case self::REASSIGN_TASK:
                $command = array_merge($this->existingTask($payload, $idempotencyKey), [
                    'targetStaffId' => $this->positive(
                        $payload['assigneeId'] ?? $payload['targetStaffId'] ?? null,
                        'assigneeId'
                    ),
                    'reason' => $this->text($payload['reason'] ?? null, 'reason', 255, true),
                ]);
                break;
            case self::VOID_TASK:
                $command = array_merge($this->existingTask($payload, $idempotencyKey), [
                    'reason' => $this->text($payload['reason'] ?? null, 'reason', 255, true),
                ]);
                break;
            case self::DELETE_TASK:
                $reason = trim((string)($payload['reason'] ?? ''));
                $command = array_merge($this->existingTask($payload, $idempotencyKey), [
                    'reason' => $reason !== '' ? $this->text($reason, 'reason', 255, true)
                        : '用户确认删除未开始任务',
                ]);
                break;
            case self::CREATE_RECORD:
                $command = $this->createRecord($scope, $payload, $idempotencyKey);
                break;
            case self::VOID_RECORD:
                $command = $this->voidRecord($scope, $payload, $idempotencyKey);
                break;
            default:
                throw new \LogicException('unreachable customer-care action');
        }
        return ['method' => $methodMap[$action], 'command' => $command];
    }

    private function createTask(
        CustomerCareQueryScope $scope,
        array $payload,
        string $idempotencyKey
    ): array {
        if (!$scope->canCreateTask()) {
            throw $this->forbidden('当前账号没有新增客情任务权限。');
        }
        $memberId = $this->positive($payload['memberId'] ?? null, 'memberId');
        $member = $this->repository->authorizedMember(
            $scope,
            $memberId,
            $scope->operationStoreId()
        );
        if ($member === null) {
            throw new CustomerCareProjectionException(
                CustomerCareProjectionErrorCode::MEMBER_NOT_FOUND,
                '会员不存在或不在当前门店的数据权限范围内。',
                ['memberId' => $memberId]
            );
        }
        $taskType = CustomerCareCodeRegistry::assertKnown(
            'taskType',
            $this->code($payload['taskTypeCode'] ?? null, 'taskTypeCode')
        );
        $ownerStaffId = $this->positive($payload['ownerId'] ?? null, 'ownerId');
        return [
            'idempotencyKey' => $idempotencyKey,
            'taskKey' => $this->naturalKey('TASK', $scope->tenantId(), $idempotencyKey),
            'businessStoreId' => $scope->operationStoreId(),
            'businessStoreName' => $scope->operationStoreName(),
            'businessOrganizationId' => $scope->operationOrganizationId(),
            'businessOrganizationPath' => $scope->operationOrganizationPath(),
            'businessOrganizationName' => $scope->operationOrganizationName(),
            'memberId' => $memberId,
            'memberName' => (string)$member['member_name'],
            'ownerStaffId' => $ownerStaffId,
            'taskType' => $taskType,
            'sourceType' => 'MANUAL',
            'sourceId' => 'MANUAL:' . $memberId . ':' . substr(hash('sha256', $idempotencyKey), 0, 24),
            'title' => CustomerCareProjectionContract::taskLabel($taskType),
            'note' => $this->text($payload['content'] ?? '', 'content', 500, false),
            'plannedAt' => $this->dateTime(
                $payload['plannedAt'] ?? null,
                'plannedAt',
                $scope->businessTimezone()
            ),
            'plannedTimezone' => $scope->businessTimezone(),
        ];
    }

    private function completeTask(
        CustomerCareQueryScope $scope,
        array $payload,
        string $idempotencyKey
    ): array {
        $existing = $this->existingTask($payload, $idempotencyKey);
        $task = $this->repository->authorizedTask($scope, $existing['taskId']);
        if ($task === null) {
            throw new CustomerCareProjectionException(
                CustomerCareProjectionErrorCode::TASK_NOT_FOUND,
                '客情任务不存在或当前账号无权查看。'
            );
        }
        $content = $this->text($payload['content'] ?? null, 'content', 1000, true);
        $related = $this->relatedFromTask($task);
        $resultCode = $this->availableResultCode($payload['resultCode'] ?? null);
        $createNextTask = $payload['createNextTask'] ?? false;
        if (!is_bool($createNextTask)) {
            throw $this->invalid('createNextTask');
        }
        $command = array_merge($existing, [
            'recordKey' => $this->naturalKey('RECORD', $scope->tenantId(), $idempotencyKey),
            'recordType' => (string)$task['task_type'],
            'followedAt' => $this->clock->now(),
            'followupMethod' => $this->code($payload['methodCode'] ?? null, 'methodCode'),
            'resultCode' => $resultCode,
            'summary' => $this->truncate($content, 255),
            'detail' => $content,
            'relatedBusinessType' => $related['type'],
            'relatedBusinessId' => $related['id'],
            'relatedBusinessLabel' => $related['label'],
            'createNextTask' => $createNextTask,
        ]);
        if ($createNextTask) {
            $command['nextPlannedAt'] = $this->dateTime(
                $payload['nextPlannedAt'] ?? null,
                'nextPlannedAt',
                $scope->businessTimezone()
            );
            $command['nextOwnerId'] = $this->positive($payload['nextOwnerId'] ?? null, 'nextOwnerId');
        }
        return $command;
    }

    private function createRecord(
        CustomerCareQueryScope $scope,
        array $payload,
        string $idempotencyKey
    ): array {
        if (!$scope->canCreateRecord()) {
            throw $this->forbidden('当前账号没有新增客情记录权限。');
        }
        $memberId = $this->positive($payload['memberId'] ?? null, 'memberId');
        $member = $this->repository->authorizedMember(
            $scope,
            $memberId,
            $scope->operationStoreId()
        );
        if ($member === null) {
            throw new CustomerCareProjectionException(
                CustomerCareProjectionErrorCode::MEMBER_NOT_FOUND,
                '会员不存在或不在当前门店的数据权限范围内。',
                ['memberId' => $memberId]
            );
        }
        $content = $this->text($payload['content'] ?? null, 'content', 1000, true);
        return [
            'idempotencyKey' => $idempotencyKey,
            'recordKey' => $this->naturalKey('RECORD', $scope->tenantId(), $idempotencyKey),
            'businessStoreId' => $scope->operationStoreId(),
            'businessStoreName' => $scope->operationStoreName(),
            'businessOrganizationId' => $scope->operationOrganizationId(),
            'businessOrganizationPath' => $scope->operationOrganizationPath(),
            'businessOrganizationName' => $scope->operationOrganizationName(),
            'businessTimezone' => $scope->businessTimezone(),
            'memberId' => $memberId,
            'memberName' => (string)$member['member_name'],
            'recordType' => $this->code($payload['recordTypeCode'] ?? null, 'recordTypeCode'),
            'followedAt' => $this->dateTime(
                $payload['followedAt'] ?? null,
                'followedAt',
                $scope->businessTimezone()
            ),
            'followupMethod' => $this->code($payload['methodCode'] ?? null, 'methodCode'),
            'resultCode' => $this->availableResultCode($payload['resultCode'] ?? null),
            'summary' => $this->truncate($content, 255),
            'detail' => $content,
            'serviceBeforePhotos' => $this->photoUrls($payload['beforePhotos'] ?? []),
            'serviceAfterPhotos' => $this->photoUrls($payload['afterPhotos'] ?? []),
            'relatedBusinessType' => '',
            'relatedBusinessId' => '',
            'relatedBusinessLabel' => '',
        ];
    }

    private function voidRecord(
        CustomerCareQueryScope $scope,
        array $payload,
        string $idempotencyKey
    ): array {
        $recordId = $this->positive($payload['recordId'] ?? null, 'recordId');
        $record = $this->repository->authorizedRecord($scope, $recordId);
        if ($record === null) {
            throw new CustomerCareProjectionException(
                CustomerCareProjectionErrorCode::RECORD_NOT_FOUND,
                '客情记录不存在或当前账号无权查看。'
            );
        }
        $taskId = (int)($record['task_id'] ?? 0);
        $command = [
            'idempotencyKey' => $idempotencyKey,
            'recordId' => $recordId,
            'reason' => $this->text($payload['reason'] ?? null, 'reason', 255, true),
        ];
        if ($taskId > 0) {
            // Both versions are explicit resources. Never substitute a freshly-read version.
            $command['taskId'] = $taskId;
            $command['expectedVersion'] = $this->positive(
                $payload['expectedTaskVersion'] ?? $payload['expectedVersion'] ?? null,
                'expectedTaskVersion'
            );
            $command['expectedRecordVersion'] = $this->positive(
                $payload['expectedRecordVersion'] ?? null,
                'expectedRecordVersion'
            );
        } else {
            $command['expectedRecordVersion'] = $this->positive(
                $payload['expectedRecordVersion'] ?? $payload['expectedVersion'] ?? null,
                'expectedRecordVersion'
            );
        }
        return $command;
    }

    /** @return string[] */
    private function photoUrls($value): array
    {
        if ($value === null || $value === '') return [];
        if (!is_array($value)) {
            throw $this->invalid('photos', '服务照片地址无效。');
        }
        $urls = [];
        foreach ($value as $url) {
            if (!is_string($url)) throw $this->invalid('photos', '服务照片地址无效。');
            $url = trim($url);
            if ($url === '' || strlen($url) > 1024 || !preg_match('/^https?:\/\/[^\s]+$/D', $url)) {
                throw $this->invalid('photos', '服务照片地址无效。');
            }
            $urls[$url] = $url;
        }
        return array_values($urls);
    }

    private function existingTask(array $payload, string $idempotencyKey): array
    {
        return [
            'idempotencyKey' => $idempotencyKey,
            'taskId' => $this->positive($payload['taskId'] ?? null, 'taskId'),
            'expectedVersion' => $this->positive($payload['expectedVersion'] ?? null, 'expectedVersion'),
        ];
    }

    /** @return array{type:string,id:string,label:string} */
    private function relatedFromTask(array $task): array
    {
        $source = (string)($task['source_type'] ?? '');
        if ($source === 'SERVICE_COMPLETED') {
            return [
                'type' => 'SERVICE',
                'id' => (string)$task['source_id'],
                'label' => (string)$task['title'],
            ];
        }
        if ($source === 'PREVIOUS_FOLLOWUP') {
            return [
                'type' => 'CARE_RECORD',
                'id' => (string)$task['source_id'],
                'label' => (string)$task['title'],
            ];
        }
        return [
            'type' => 'MEMBER',
            'id' => (string)(int)$task['member_id'],
            'label' => (string)$task['title'],
        ];
    }

    private function domainIdempotencyKey(string $action, string $tenantId, string $raw): string
    {
        $prefixes = [
            self::CREATE_TASK => 'CARE_CREATE_TASK',
            self::START_TASK => 'CARE_START_TASK',
            self::COMPLETE_TASK => 'CARE_COMPLETE_TASK',
            self::REASSIGN_TASK => 'CARE_REASSIGN_TASK',
            self::VOID_TASK => 'CARE_VOID_TASK',
            self::DELETE_TASK => 'CARE_DELETE_TASK',
            self::CREATE_RECORD => 'CARE_CREATE_RECORD',
            self::VOID_RECORD => 'CARE_VOID_RECORD',
        ];
        $raw = trim($raw);
        if ($raw === '' || strlen($raw) > 200
            || !preg_match('/^[A-Za-z0-9][A-Za-z0-9:._-]{7,199}$/D', $raw)) {
            throw $this->invalid('idempotencyKey');
        }
        $hash = strtolower(substr(hash('sha256', $tenantId . "\0" . $action . "\0" . $raw), 0, 32));
        $hash[12] = '4';
        $hash[16] = '8';
        $uuid = substr($hash, 0, 8) . '-'
            . substr($hash, 8, 4) . '-'
            . substr($hash, 12, 4) . '-'
            . substr($hash, 16, 4) . '-'
            . substr($hash, 20, 12);
        return $prefixes[$action] . '-' . $uuid;
    }

    private function naturalKey(string $kind, string $tenantId, string $idempotencyKey): string
    {
        return 'CARE-' . $kind . '-' . substr(
            hash('sha256', $tenantId . "\0" . $idempotencyKey . "\0" . $kind),
            0,
            48
        );
    }

    private function dateTime($value, string $field, string $timezone): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            throw $this->invalid($field);
        }
        $value = trim($value);
        foreach (['!Y-m-d\TH:i', '!Y-m-d H:i', '!Y-m-d H:i:s'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value, new \DateTimeZone($timezone));
            $errors = \DateTimeImmutable::getLastErrors();
            if ($date instanceof \DateTimeImmutable
                && ($errors === false || ((int)$errors['warning_count'] === 0 && (int)$errors['error_count'] === 0))) {
                return $date->getTimestamp();
            }
        }
        throw $this->invalid($field);
    }

    private function positive($value, string $field): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value)) {
            $number = (int)$value;
            if ($number > 0 && (string)$number === $value) {
                return $number;
            }
        }
        throw $this->invalid($field);
    }

    private function code($value, string $field): string
    {
        $code = is_string($value) ? trim($value) : '';
        if (!preg_match('/^[A-Z][A-Z0-9_]{1,31}$/D', $code)) {
            throw $this->invalid($field);
        }
        return $code;
    }

    private function availableResultCode($value): string
    {
        return CustomerCareCodeRegistry::assertKnown(
            'resultCode',
            $this->code($value, 'resultCode')
        );
    }

    private function text($value, string $field, int $maxLength, bool $required): string
    {
        if (!is_string($value)) {
            throw $this->invalid($field);
        }
        $text = trim($value);
        $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
        if (($required && $text === '') || $length > $maxLength || strpos($text, "\0") !== false) {
            throw $this->invalid($field);
        }
        return $text;
    }

    private function truncate(string $value, int $maxLength): string
    {
        return function_exists('mb_substr')
            ? mb_substr($value, 0, $maxLength, 'UTF-8')
            : substr($value, 0, $maxLength);
    }

    private function invalid(string $field): CustomerCareProjectionException
    {
        return new CustomerCareProjectionException(
            CustomerCareProjectionErrorCode::INVALID_QUERY,
            '客情操作参数无效。',
            ['field' => $field]
        );
    }

    private function forbidden(string $message): CustomerCareProjectionException
    {
        return new CustomerCareProjectionException(
            CustomerCareProjectionErrorCode::QUERY_FORBIDDEN,
            $message
        );
    }
}
