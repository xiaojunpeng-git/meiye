<?php

namespace app\services\cashier\v3\hang;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\room\guard\RoomOpenServiceGuardVersionProvider;

/** Adds room and slot locks to submit-hang-order without trusting client identities. */
final class CashierV3HangRoomResourceDiscovery
{
    public const CONTRACT_VERSION = 'cashier-v3-hang-room-resource-discovery-v1';

    /** @var RoomOpenServiceGuardVersionProvider */
    private $versions;

    public function __construct(?RoomOpenServiceGuardVersionProvider $versions = null)
    {
        $this->versions = $versions ?: new RoomOpenServiceGuardVersionProvider();
    }

    public function discover(array $scope): array
    {
        $operator = $scope['operator_scope'] ?? null;
        $dataScope = $scope['data_scope'] ?? null;
        $payload = is_array($scope['payload'] ?? null) ? $scope['payload'] : [];
        $contexts = is_array($scope['contexts'] ?? null) ? $scope['contexts'] : [];
        if (!$operator instanceof CashierV3OperatorScope || !$dataScope instanceof CashierV3DataScopeContext) {
            throw CashierV3CommandException::invalidContext(
                '挂单房间资源上下文不完整，请刷新后重试。',
                ['reason' => 'hang_room_discovery_scope_invalid']
            );
        }
        $workspace = $this->workspaceResource($contexts);
        $mode = trim((string)($payload['mode'] ?? ''));
        if ($mode === 'normal') {
            return ['contractVersion' => self::CONTRACT_VERSION, 'resources' => [$workspace]];
        }
        if ($mode !== 'start_service') {
            throw CashierV3CommandException::invalidContext(
                '挂单方式无效，请重新打开挂单页面。',
                ['reason' => 'hang_room_discovery_mode_invalid']
            );
        }

        $roomId = $this->positiveInt($payload['roomId'] ?? null, 'room_id');
        $slotId = trim((string)($payload['roomTimeSlotId'] ?? ''));
        $expectedRoom = $this->positiveInt($payload['roomVersion'] ?? null, 'room_version');
        $expectedSlot = $this->positiveInt(
            $payload['roomTimeSlotVersion'] ?? null,
            'room_time_slot_version'
        );
        if (!hash_equals('open-service:' . $roomId, $slotId)) {
            throw CashierV3CommandException::invalidContext(
                '房间时段标识无效，请重新选择房间。',
                ['reason' => 'hang_room_discovery_slot_mismatch']
            );
        }
        $currentRoom = $this->versions->discoverVersion(
            RoomOpenServiceGuardVersionProvider::KIND_ROOM,
            (string)$roomId,
            $operator,
            $dataScope
        );
        $currentSlot = $this->versions->discoverVersion(
            RoomOpenServiceGuardVersionProvider::KIND_SLOT,
            $slotId,
            $operator,
            $dataScope
        );
        if ($currentRoom !== $expectedRoom || $currentSlot !== $expectedSlot) {
            throw CashierV3CommandException::versionConflict(
                '该房间已被占用或状态已经变化，请重新选择空闲房间。',
                [
                    'reason' => 'hang_room_discovery_version_changed',
                    'room_id' => $roomId,
                    'expected_room_version' => $expectedRoom,
                    'current_room_version' => $currentRoom,
                    'expected_slot_version' => $expectedSlot,
                    'current_slot_version' => $currentSlot,
                ]
            );
        }

        return [
            'contractVersion' => self::CONTRACT_VERSION,
            'resources' => [
                $this->resource(
                    RoomOpenServiceGuardVersionProvider::KIND_ROOM,
                    (string)$roomId,
                    $currentRoom,
                    ['target_room'],
                    'read',
                    $dataScope
                ),
                $this->resource(
                    RoomOpenServiceGuardVersionProvider::KIND_SLOT,
                    $slotId,
                    $currentSlot,
                    ['target_room_time_slot'],
                    'mutate',
                    $dataScope
                ),
                $workspace,
            ],
        ];
    }

    private function workspaceResource(array $contexts): array
    {
        foreach ($contexts as $context) {
            if ((string)($context['kind'] ?? '') !== 'cashier_workspace') {
                continue;
            }
            $id = trim((string)($context['id'] ?? ''));
            $version = (int)($context['expected_version'] ?? $context['expectedVersion'] ?? 0);
            if ($id !== '' && $version > 0) {
                return [
                    'kind' => 'cashier_workspace',
                    'id' => $id,
                    'expectedVersion' => $version,
                    'roles' => ['cashier_workspace'],
                    'accessMode' => 'mutate',
                    'providerContractVersion' => self::CONTRACT_VERSION,
                    'authorityFingerprint' => hash('sha256', 'cashier_workspace|' . $id . '|' . $version),
                ];
            }
        }
        throw CashierV3CommandException::invalidContext(
            '当前收银工作台版本缺失，请刷新后重试。',
            ['reason' => 'hang_room_discovery_workspace_missing']
        );
    }

    private function resource(
        string $kind,
        string $id,
        int $version,
        array $roles,
        string $accessMode,
        CashierV3DataScopeContext $dataScope
    ): array {
        return [
            'kind' => $kind,
            'id' => $id,
            'expectedVersion' => $version,
            'roles' => $roles,
            'accessMode' => $accessMode,
            'providerContractVersion' => $this->versions->contractVersion(),
            'authorityFingerprint' => hash('sha256', implode('|', [
                $this->versions->contractVersion(),
                $dataScope->tenantId(),
                (string)$dataScope->forcedStoreId(),
                $kind,
                $id,
                (string)$version,
            ])),
        ];
    }

    private function positiveInt($value, string $field): int
    {
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/D', trim($value)) === 1) {
            $value = (int)$value;
        }
        if (!is_int($value) || $value <= 0) {
            throw CashierV3CommandException::invalidContext(
                '挂单房间版本无效，请重新选择房间。',
                ['reason' => 'hang_room_discovery_' . $field . '_invalid']
            );
        }
        return $value;
    }
}
