<?php

namespace app\services\cashier\v3\hang;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;

final class CashierV3HangPreparationServices
{
    public const CONTRACT_VERSION = 'cashier-v3-empty-room-hang-preparation-v1';
    public const INTENT_SOURCE = 'room_status_idle';

    /** @var CashierV3HangPreparationProvider */
    private $provider;

    public function __construct(CashierV3HangPreparationProvider $provider)
    {
        $this->provider = $provider;
    }

    public function prepareEmptyRoomCashier(
        array $payload,
        string $stateContextId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        $intentId = $this->requestId($payload['roomOpenIntentId'] ?? $payload['intentId'] ?? null, 'roomOpenIntentId');
        $roomId = $this->positiveInt($payload['roomId'] ?? null, 'roomId');
        $expectedRoomVersion = $this->positiveInt($payload['roomVersion'] ?? null, 'roomVersion');
        $candidate = $this->requireCandidate(
            $this->provider->roomCandidates($operatorScope, $dataScope),
            $roomId
        );
        $this->assertSelectableVersion($candidate, $expectedRoomVersion, null);

        $intent = $this->roomIntent($candidate, $intentId, $stateContextId);
        return [
            'data' => ['roomOpenIntent' => $intent],
            'versions' => $this->candidateVersions([$candidate]),
            'navigation' => [
                'routeName' => 'cashier-v3-cashier',
                'query' => $this->intentQuery($intent),
            ],
            'message' => '房间已带入收银台；当前尚未占用，挂单成功后才会开始服务。',
        ];
    }

    /**
     * Direct draft hanging deliberately does not reuse the old preparation
     * workflow.  Starting service still needs one authoritative, current
     * room check at the exact moment the draft is saved, however.  Return the
     * server-discovered snapshot so callers never depend on route-query
     * versions that may have become stale while the cashier was adding items.
     */
    public function currentSelectableRoomForDirectHang(
        $roomId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        $roomId = $this->positiveInt($roomId, 'roomId');
        $candidate = $this->requireCandidate(
            $this->provider->roomCandidates($operatorScope, $dataScope),
            $roomId
        );
        $this->assertSelectableVersion(
            $candidate,
            $this->positiveInt($candidate['roomVersion'] ?? null, 'roomVersion'),
            $this->positiveInt($candidate['roomTimeSlotVersion'] ?? null, 'roomTimeSlotVersion')
        );
        return $candidate;
    }

    public function prepareHangOrder(
        array $payload,
        array $draft,
        string $stateContextId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        $preparationRequestId = $this->requestId($payload['preparationRequestId'] ?? null, 'preparationRequestId');
        $workspaceId = \app\services\cashier\v3\CashierV3CheckoutWorkspaceIdentity::id(
            $operatorScope->storeId(),
            $stateContextId
        );
        if (($draft['complete'] ?? false) !== true
            || (string)($draft['workspaceId'] ?? '') !== $workspaceId
            || (string)($draft['stateContextId'] ?? '') !== $stateContextId
            || !is_array($draft['lines'] ?? null)
            || count($draft['lines']) === 0) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::INVALID_COMMAND_CONTEXT,
                '请先添加需要挂单的项目。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'hang_order_cart_empty_or_incomplete']
            );
        }

        $workspaceVersion = $this->provider->workspaceVersion($workspaceId, $operatorScope, $dataScope);
        $candidates = $this->provider->roomCandidates($operatorScope, $dataScope);
        $preferred = $this->preferredCandidate($payload, $candidates);
        $workspaceContext = [
            'kind' => 'cashier_workspace',
            'id' => $workspaceId,
            'expectedVersion' => $workspaceVersion,
        ];
        $snapshot = [
            'contractVersion' => self::CONTRACT_VERSION,
            'preparationReady' => true,
            'snapshotReady' => true,
            'preparationRequestId' => $preparationRequestId,
            'status' => 'editing',
            'eventless' => true,
            'cartPreserved' => true,
            'cartSelectedCount' => count($draft['lines']),
            'cartLineFingerprint' => (string)($draft['lineFingerprint'] ?? ''),
            'startServiceAvailable' => true,
            'startServiceUnavailableReason' => '',
            'preferredMode' => $preferred ? 'start_service' : 'normal',
            'preferredRoomId' => $preferred['roomId'] ?? null,
            'preferredRoomVersion' => $preferred['roomVersion'] ?? null,
            'preferredRoomTimeSlotId' => $preferred['roomTimeSlotId'] ?? null,
            'preferredRoomTimeSlotVersion' => $preferred['roomTimeSlotVersion'] ?? null,
            'roomCandidates' => array_values($candidates),
            'commandContexts' => [$workspaceContext],
            'businessEffects' => [
                'roomOccupied' => false,
                'salesCreated' => false,
                'serviceCreated' => false,
                'writeoffCreated' => false,
                'performanceCreated' => false,
                'eventCreated' => false,
                'outboxCreated' => false,
            ],
        ];
        $snapshot['preparationToken'] = hash('sha256', json_encode([
            'contractVersion' => self::CONTRACT_VERSION,
            'stateContextId' => $stateContextId,
            'requestId' => $preparationRequestId,
            'workspaceId' => $workspaceId,
            'workspaceVersion' => $workspaceVersion,
            'lineFingerprint' => $snapshot['cartLineFingerprint'],
            'preferredRoomId' => $snapshot['preferredRoomId'],
            'candidateVersions' => $this->candidateVersions($candidates),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return [
            'data' => ['hangOrderPreparation' => $snapshot],
            'versions' => array_merge(
                [['kind' => 'cashier_workspace', 'id' => $workspaceId, 'version' => $workspaceVersion]],
                $this->candidateVersions($candidates)
            ),
            'message' => '挂单准备已完成；尚未占用房间，也未产生订单、服务或业绩。',
        ];
    }

    /**
     * Rebuilds the eventless preparation from current authorities while the
     * caller owns the submit transaction. The prepared room and the room the
     * operator finally chose are deliberately separate: users may switch to a
     * different room that was present in the same frozen preparation.
     */
    public function revalidateSubmissionInTx(
        array $payload,
        array $lockedDraft,
        string $stateContextId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        $preparationRequestId = $this->requestId(
            $payload['preparationRequestId'] ?? null,
            'preparationRequestId'
        );
        $expectedToken = trim((string)($payload['preparationToken'] ?? ''));
        $expectedFingerprint = trim((string)($payload['cartLineFingerprint'] ?? ''));
        if (preg_match('/^[0-9a-f]{64}$/D', $expectedToken) !== 1
            || preg_match('/^[0-9a-f]{64}$/D', $expectedFingerprint) !== 1) {
            throw $this->invalidIntent('hang_submission_snapshot_invalid');
        }

        $preparePayload = ['preparationRequestId' => $preparationRequestId];
        $preparedRoomId = $this->optionalPositiveInt($payload['preparedRoomId'] ?? null);
        if ($preparedRoomId !== null) {
            $preparePayload = array_merge($preparePayload, [
                'roomOpenIntentSource' => self::INTENT_SOURCE,
                'roomOpenIntentId' => $preparationRequestId,
                'preferredRoomId' => $preparedRoomId,
                'preferredRoomVersion' => $this->positiveInt(
                    $payload['preparedRoomVersion'] ?? null,
                    'preparedRoomVersion'
                ),
                'preferredRoomTimeSlotId' => trim((string)($payload['preparedRoomTimeSlotId'] ?? '')),
                'preferredRoomTimeSlotVersion' => $this->positiveInt(
                    $payload['preparedRoomTimeSlotVersion'] ?? null,
                    'preparedRoomTimeSlotVersion'
                ),
            ]);
        }

        $pack = $this->prepareHangOrder(
            $preparePayload,
            $lockedDraft,
            $stateContextId,
            $operatorScope,
            $dataScope
        );
        $snapshot = (array)($pack['data']['hangOrderPreparation'] ?? []);
        if (!hash_equals((string)($snapshot['preparationToken'] ?? ''), $expectedToken)
            || !hash_equals((string)($snapshot['cartLineFingerprint'] ?? ''), $expectedFingerprint)) {
            throw CashierV3CommandException::versionConflict(
                '挂单准备信息已经变化，购物车已保留，请重新打开挂单页面。',
                ['reason' => 'hang_preparation_snapshot_changed']
            );
        }

        $mode = trim((string)($payload['mode'] ?? ''));
        $chosen = null;
        if ($mode === 'start_service') {
            $roomId = $this->positiveInt($payload['roomId'] ?? null, 'roomId');
            $roomVersion = $this->positiveInt($payload['roomVersion'] ?? null, 'roomVersion');
            $slotId = trim((string)($payload['roomTimeSlotId'] ?? ''));
            $slotVersion = $this->positiveInt(
                $payload['roomTimeSlotVersion'] ?? null,
                'roomTimeSlotVersion'
            );
            $chosen = $this->requireCandidate((array)($snapshot['roomCandidates'] ?? []), $roomId);
            $this->assertSelectableVersion($chosen, $roomVersion, $slotVersion);
            if ($slotId === '' || !hash_equals((string)$chosen['roomTimeSlotId'], $slotId)) {
                throw CashierV3CommandException::versionConflict(
                    '该房间状态已经变化，购物车已保留，请重新选择空闲房间。',
                    ['reason' => 'hang_room_time_slot_changed', 'room_id' => $roomId]
                );
            }
        } elseif ($mode !== 'normal') {
            throw $this->invalidIntent('hang_mode_invalid');
        }

        return ['snapshot' => $snapshot, 'chosenRoom' => $chosen];
    }

    private function preferredCandidate(array $payload, array $candidates)
    {
        $hasIntent = isset($payload['roomOpenIntentSource'])
            || isset($payload['roomOpenIntentId'])
            || isset($payload['preferredRoomId'])
            || isset($payload['preferredRoomVersion']);
        if (!$hasIntent) {
            return null;
        }
        if ((string)($payload['roomOpenIntentSource'] ?? '') !== self::INTENT_SOURCE) {
            throw $this->invalidIntent('room_open_intent_source_invalid');
        }
        $this->requestId($payload['roomOpenIntentId'] ?? null, 'roomOpenIntentId');
        $roomId = $this->positiveInt($payload['preferredRoomId'] ?? null, 'preferredRoomId');
        $roomVersion = $this->positiveInt($payload['preferredRoomVersion'] ?? null, 'preferredRoomVersion');
        $slotId = trim((string)($payload['preferredRoomTimeSlotId'] ?? ''));
        $slotVersion = $this->positiveInt($payload['preferredRoomTimeSlotVersion'] ?? null, 'preferredRoomTimeSlotVersion');
        if ($slotId === '' || strlen($slotId) > 128) {
            throw $this->invalidIntent('preferred_room_time_slot_invalid');
        }
        $candidate = $this->requireCandidate($candidates, $roomId);
        $this->assertSelectableVersion($candidate, $roomVersion, $slotVersion);
        if (!hash_equals((string)$candidate['roomTimeSlotId'], $slotId)) {
            throw CashierV3CommandException::versionConflict(
                '该房间状态已经变化，购物车已保留，请重新从房态图开单。',
                ['reason' => 'preferred_room_time_slot_changed']
            );
        }
        return $candidate;
    }

    private function assertSelectableVersion(array $candidate, int $roomVersion, $slotVersion): void
    {
        if (($candidate['selectable'] ?? false) !== true || ($candidate['canSelect'] ?? false) !== true) {
            throw CashierV3CommandException::versionConflict(
                '该房间已被占用，购物车已保留，请重新选择空闲房间。',
                ['reason' => 'room_no_longer_idle', 'room_id' => $candidate['roomId'] ?? null]
            );
        }
        if ((int)($candidate['roomVersion'] ?? 0) !== $roomVersion
            || ($slotVersion !== null && (int)($candidate['roomTimeSlotVersion'] ?? 0) !== (int)$slotVersion)) {
            throw CashierV3CommandException::versionConflict(
                '该房间状态已经变化，购物车已保留，请重新从房态图开单。',
                ['reason' => 'room_open_intent_version_changed', 'room_id' => $candidate['roomId'] ?? null]
            );
        }
    }

    private function requireCandidate(array $candidates, int $roomId): array
    {
        foreach ($candidates as $candidate) {
            if (is_array($candidate) && (int)($candidate['roomId'] ?? $candidate['id'] ?? 0) === $roomId) {
                return $candidate;
            }
        }
        throw new CashierV3CommandException(
            CashierV3ResultCode::RESOURCE_NOT_FOUND,
            '该房间不存在、已停用或不属于当前门店。',
            CashierV3ResultCode::STATUS_FAILED,
            ['kind' => 'room', 'id' => $roomId]
        );
    }

    private function roomIntent(array $candidate, string $intentId, string $stateContextId): array
    {
        return [
            'contractVersion' => self::CONTRACT_VERSION,
            'source' => self::INTENT_SOURCE,
            'intentId' => $intentId,
            'stateContextId' => $stateContextId,
            'roomId' => $candidate['roomId'],
            'roomName' => (string)$candidate['name'],
            'roomVersion' => (int)$candidate['roomVersion'],
            'roomTimeSlotId' => (string)$candidate['roomTimeSlotId'],
            'roomTimeSlotVersion' => (int)$candidate['roomTimeSlotVersion'],
            'eventless' => true,
            'roomOccupied' => false,
            'cartReset' => false,
        ];
    }

    private function intentQuery(array $intent): array
    {
        return [
            'roomOpenIntent' => '1',
            'roomOpenIntentSource' => (string)$intent['source'],
            'roomOpenIntentId' => (string)$intent['intentId'],
            'preferredRoomId' => (string)$intent['roomId'],
            'preferredRoomName' => (string)$intent['roomName'],
            'preferredRoomVersion' => (string)$intent['roomVersion'],
            'preferredRoomTimeSlotId' => (string)$intent['roomTimeSlotId'],
            'preferredRoomTimeSlotVersion' => (string)$intent['roomTimeSlotVersion'],
        ];
    }

    private function candidateVersions(array $candidates): array
    {
        $versions = [];
        $seen = [];
        foreach ($candidates as $candidate) {
            foreach ((array)($candidate['commandContexts'] ?? []) as $context) {
                $kind = trim((string)($context['kind'] ?? ''));
                $id = trim((string)($context['id'] ?? ''));
                $version = (int)($context['expectedVersion'] ?? 0);
                $key = $kind . ':' . $id;
                if ($kind === '' || $id === '' || $version <= 0 || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $versions[] = ['kind' => $kind, 'id' => $id, 'version' => $version];
            }
        }
        return $versions;
    }

    private function requestId($value, string $field): string
    {
        $value = trim((string)$value);
        if (preg_match('/^[A-Za-z0-9_-]{16,128}$/D', $value) !== 1) {
            throw $this->invalidIntent($field . '_invalid');
        }
        return $value;
    }

    private function positiveInt($value, string $field): int
    {
        if (is_string($value)) {
            $value = trim($value);
            if (preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
                throw $this->invalidIntent($field . '_invalid');
            }
            $value = (int)$value;
        }
        if (!is_int($value) || $value <= 0) {
            throw $this->invalidIntent($field . '_invalid');
        }
        return $value;
    }

    private function optionalPositiveInt($value)
    {
        if ($value === null || trim((string)$value) === '') {
            return null;
        }
        return $this->positiveInt($value, 'preparedRoomId');
    }

    private function invalidIntent(string $reason): CashierV3CommandException
    {
        return new CashierV3CommandException(
            CashierV3ResultCode::INVALID_COMMAND_CONTEXT,
            '房间开单信息无效，请重新从房态图开单。',
            CashierV3ResultCode::STATUS_FAILED,
            ['reason' => $reason]
        );
    }
}
