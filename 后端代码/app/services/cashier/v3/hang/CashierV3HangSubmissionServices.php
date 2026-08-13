<?php

namespace app\services\cashier\v3\hang;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\cashier\CashierV3CashierMemberSummaryServices;
use app\services\cashier\v3\cashier\CashierV3CashierWorkspaceServices;
use app\services\cashier\v3\event\CashierV3BusinessEventExecution;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use app\services\cashier\v3\hang\authority\CashierV3HangOrderAuthorityException;
use app\services\cashier\v3\hang\authority\CashierV3HangOrderPlanV1;
use app\services\cashier\v3\hang\authority\CashierV3HangOrderRepository;
use app\services\cashier\v3\hang\authority\ThinkPhpCashierV3HangOrderRepository;
use app\services\room\guard\RoomOpenServiceGuardAuthority;
use app\services\room\guard\RoomOpenServiceGuardException;
use think\facade\Db;

/** Atomic V3 hang-order submission and optional empty-room occupation. */
final class CashierV3HangSubmissionServices
{
    /** @var CashierV3CashierWorkspaceServices */
    private $workspace;

    /** @var null|CashierV3HangPreparationServices */
    private $preparations;

    /** @var CashierV3HangOrderRepository */
    private $hangOrders;

    /** @var RoomOpenServiceGuardAuthority */
    private $roomGuard;

    /** @var CashierV3CashierMemberSummaryServices */
    private $members;

    public function __construct(
        CashierV3CashierWorkspaceServices $workspace,
        ?CashierV3HangPreparationServices $preparations = null,
        ?CashierV3HangOrderRepository $hangOrders = null,
        ?RoomOpenServiceGuardAuthority $roomGuard = null,
        ?CashierV3CashierMemberSummaryServices $members = null
    ) {
        $this->workspace = $workspace;
        $this->preparations = $preparations;
        $this->hangOrders = $hangOrders ?: new ThinkPhpCashierV3HangOrderRepository();
        $this->roomGuard = $roomGuard ?: new RoomOpenServiceGuardAuthority();
        $this->members = $members ?: new CashierV3CashierMemberSummaryServices();
    }

    public function submitInTx(array $scope): array
    {
        CashierV3TransactionGuard::assertInTransaction('hangOrderSubmission');
        $operatorScope = $scope['operator_scope'] ?? null;
        $dataScope = $scope['data_scope'] ?? null;
        $eventRecorder = $scope['event_recorder'] ?? null;
        $eventExecution = $scope['event_execution'] ?? null;
        if (!$operatorScope instanceof CashierV3OperatorScope
            || !$dataScope instanceof CashierV3DataScopeContext
            || !$eventRecorder instanceof CashierV3BusinessEventRecorder
            || !$eventExecution instanceof CashierV3BusinessEventExecution) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ACTION_DEPENDENCY_NOT_READY,
                '挂单服务尚未准备完成，请稍后重试。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'hang_submission_scope_incomplete']
            );
        }
        if (!$this->preparations instanceof CashierV3HangPreparationServices) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ACTION_DEPENDENCY_NOT_READY,
                '挂单服务尚未准备完成，请稍后重试。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'hang_submission_preparation_missing']
            );
        }

        $payload = is_array($scope['payload'] ?? null) ? $scope['payload'] : [];
        $stateContextId = trim((string)($scope['state_context_id'] ?? ''));
        $workspaceId = \app\services\cashier\v3\CashierV3CheckoutWorkspaceIdentity::id(
            $operatorScope->storeId(),
            $stateContextId
        );
        $this->assertWorkspaceContext((array)($scope['contexts'] ?? []), $workspaceId);

        $transferred = $this->workspace->transferToHangInTx(
            $workspaceId,
            $stateContextId,
            $operatorScope,
            trim((string)($payload['cartLineFingerprint'] ?? ''))
        );
        $lockedDraft = (array)($transferred['hangDraft'] ?? []);
        $revalidated = $this->preparations->revalidateSubmissionInTx(
            $payload,
            $lockedDraft,
            $stateContextId,
            $operatorScope,
            $dataScope
        );

        $now = time();
        $businessDate = (new \DateTimeImmutable('@' . $now))
            ->setTimezone(new \DateTimeZone('Asia/Shanghai'))
            ->format('Y-m-d');
        $dimensions = $this->dimensions($operatorScope, $dataScope);
        $memberId = (int)($lockedDraft['memberId'] ?? 0);
        $memberName = '';
        if ($memberId > 0) {
            $memberName = trim((string)$this->members->read(
                $memberId,
                $operatorScope->storeId()
            )['name']);
        }

        $command = [
            'commandIdempotencyKey' => trim((string)($scope['idempotency_key'] ?? '')),
            'preparationRequestId' => trim((string)($payload['preparationRequestId'] ?? '')),
            'preparationToken' => trim((string)($payload['preparationToken'] ?? '')),
            'mode' => trim((string)($payload['mode'] ?? '')),
            'businessDate' => $businessDate,
            'businessTimezone' => 'Asia/Shanghai',
            'occurredAt' => $now,
            'recordedAt' => $now,
            'organizationPathSnapshot' => $dimensions['organizationPath'],
            'organizationNameSnapshot' => $dimensions['organizationName'],
            'storeNameSnapshot' => $dimensions['storeName'],
            'memberNameSnapshot' => $memberName,
            'operatorNameSnapshot' => $dimensions['operatorName'],
        ];

        $guardResult = null;
        if ($command['mode'] === CashierV3HangOrderPlanV1::MODE_START_SERVICE) {
            $chosenRoom = is_array($revalidated['chosenRoom'] ?? null)
                ? $revalidated['chosenRoom']
                : [];
            $identity = CashierV3HangOrderPlanV1::identity(
                $dataScope->tenantId(),
                (string)$command['preparationRequestId'],
                $businessDate
            );
            try {
                $guardResult = $this->roomGuard->claimInTx(
                    (int)$chosenRoom['roomId'],
                    (string)$chosenRoom['roomTimeSlotId'],
                    (int)$chosenRoom['roomTimeSlotVersion'],
                    RoomOpenServiceGuardAuthority::OWNER_HANG_ORDER,
                    (string)$identity['hangOrderId'],
                    $operatorScope,
                    $dataScope
                );
            } catch (RoomOpenServiceGuardException $exception) {
                throw $this->roomFailure($exception);
            }
            if ((int)($guardResult['roomVersion'] ?? 0) !== (int)$chosenRoom['roomVersion']) {
                throw CashierV3CommandException::versionConflict(
                    '该房间资料已经变化，购物车已保留，请重新选择空闲房间。',
                    ['reason' => 'hang_room_definition_changed', 'room_id' => $chosenRoom['roomId']]
                );
            }
            $command = array_merge($command, [
                'roomId' => (int)$guardResult['roomId'],
                'roomNameSnapshot' => (string)$guardResult['roomName'],
                'roomVersion' => (int)$guardResult['roomVersion'],
                'roomTimeSlotId' => (string)$guardResult['slotKey'],
                'roomTimeSlotVersion' => (int)$chosenRoom['roomTimeSlotVersion'],
                'roomGuardFingerprint' => $this->guardFingerprint($guardResult),
            ]);
        }

        try {
            $plan = CashierV3HangOrderPlanV1::fromLockedDraft(
                $command,
                $lockedDraft,
                $operatorScope,
                $dataScope,
                (array)($transferred['frozenWorkspaceRows'] ?? [])
            );
            $persisted = $this->hangOrders->persistInTx($plan, $operatorScope, $dataScope);
        } catch (CashierV3HangOrderAuthorityException $exception) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                '挂单资料写入失败，本次操作已回滚，请重试。',
                CashierV3ResultCode::STATUS_FAILED,
                array_merge(['reason' => $exception->reason()], $exception->detail())
            );
        }

        $header = $plan->header();
        $eventContract = is_array($scope['event_contract'] ?? null)
            ? $scope['event_contract']
            : [];
        $eventRecorder->recordInTx($eventExecution, $eventContract, [
            'event_type' => 'hang_order.created',
            'aggregate_type' => 'hang_order',
            'aggregate_id' => (string)$header['hang_order_id'],
            'aggregate_version' => 1,
            'source_type' => 'submit-hang-order',
            'source_id' => (string)$header['hang_order_id'],
            'member_id' => $memberId,
            'business_date' => $businessDate,
            'occurred_at' => $now,
            'settled_at' => $now,
            'recorded_at' => $now,
            'aggregate_name_snapshot' => (string)$header['hang_order_no'],
            'store_name_snapshot' => $dimensions['storeName'],
            'payload' => [
                'hangOrderId' => (string)$header['hang_order_id'],
                'hangOrderNo' => (string)$header['hang_order_no'],
                'mode' => (string)$header['hang_mode'],
                'lineCount' => (int)$header['line_count'],
                'totalQuantity' => (int)$header['total_quantity'],
                'roomId' => (int)$header['room_id'],
                'roomTimeSlotId' => (string)$header['room_time_slot_id'],
                'workspaceLineFingerprint' => (string)$header['workspace_line_fingerprint'],
            ],
        ]);
        if ($guardResult !== null) {
            $eventRecorder->recordInTx($eventExecution, $eventContract, [
                'event_type' => 'room.occupied',
                'aggregate_type' => 'room',
                'aggregate_id' => (string)$header['room_id'],
                'aggregate_version' => 1,
                'source_type' => 'submit-hang-order',
                'source_id' => (string)$header['hang_order_id'],
                'member_id' => $memberId,
                'business_date' => $businessDate,
                'occurred_at' => $now,
                'settled_at' => $now,
                'recorded_at' => $now,
                'aggregate_name_snapshot' => (string)$header['room_name_snapshot'],
                'store_name_snapshot' => $dimensions['storeName'],
                'payload' => [
                    'roomId' => (int)$header['room_id'],
                    'roomTimeSlotId' => (string)$header['room_time_slot_id'],
                    'roomTimeSlotVersionBefore' => (int)$header['room_time_slot_version'],
                    'roomTimeSlotVersionAfter' => (int)$guardResult['slotVersion'],
                    'ownerKind' => RoomOpenServiceGuardAuthority::OWNER_HANG_ORDER,
                    'ownerId' => (string)$header['hang_order_id'],
                    'guardFingerprint' => (string)$header['room_guard_fingerprint'],
                ],
            ]);
        }

        return [
            'hangOrder' => $persisted,
            'roomOccupation' => $guardResult,
            'cashierDraft' => (array)($transferred['cashierDraft'] ?? []),
        ];
    }

    /**
     * 普通挂单是收银草稿的直接快照：只保存当前已在购物车内的行，并清空
     * 当前购物车。目录、库存、权益、价格和结账资格都只在随后结账时校验。
     */
    public function saveDraftDirectInTx(array $scope): array
    {
        CashierV3TransactionGuard::assertInTransaction('hangOrderDirectDraftSave');
        $operatorScope = $scope['operator_scope'] ?? null;
        $dataScope = $scope['data_scope'] ?? null;
        $stateContextId = trim((string)($scope['state_context_id'] ?? ''));
        $commandKey = trim((string)($scope['idempotency_key'] ?? ''));
        $payload = is_array($scope['payload'] ?? null) ? $scope['payload'] : [];
        if (!$operatorScope instanceof CashierV3OperatorScope
            || !$dataScope instanceof CashierV3DataScopeContext
            || $stateContextId === ''
            || preg_match('/^[A-Za-z0-9:_-]{16,128}$/D', $commandKey) !== 1) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ACTION_DEPENDENCY_NOT_READY,
                '挂单草稿保存请求无效，请重新点击挂单。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'hang_direct_draft_scope_invalid']
            );
        }

        $workspaceId = \app\services\cashier\v3\CashierV3CheckoutWorkspaceIdentity::id(
            $operatorScope->storeId(),
            $stateContextId
        );
        // 挂单始终只是购物车草稿。即使从房间入口进入，也只保存房间关联，
        // 不创建服务、不占房、不读取或校验当前房态。
        $mode = CashierV3HangOrderPlanV1::MODE_NORMAL;
        // 直接从当前工作台锁定的草稿写快照；不走挂单准备或业务重校验。
        $transferred = $this->workspace->transferToHangInTx(
            $workspaceId,
            $stateContextId,
            $operatorScope,
            '',
            true,
            false
        );
        $lockedDraft = (array)($transferred['hangDraft'] ?? []);
        $now = time();
        $businessDate = (new \DateTimeImmutable('@' . $now))
            ->setTimezone(new \DateTimeZone('Asia/Shanghai'))
            ->format('Y-m-d');
        $dimensions = $this->dimensions($operatorScope, $dataScope);
        $memberId = (int)($lockedDraft['memberId'] ?? 0);
        $memberName = '';
        if ($memberId > 0) {
            $memberName = trim((string)($this->members->read($memberId, $operatorScope->storeId())['name'] ?? ''));
        }
        $preparationRequestId = 'HANG_DRAFT_' . substr(hash('sha256', $commandKey), 0, 48);
        $command = [
            'commandIdempotencyKey' => $commandKey,
            'preparationRequestId' => $preparationRequestId,
            'preparationToken' => hash('sha256', 'hang-direct-draft:' . $commandKey),
            'mode' => $mode,
            'businessDate' => $businessDate,
            'businessTimezone' => 'Asia/Shanghai',
            'occurredAt' => $now,
            'recordedAt' => $now,
            'organizationPathSnapshot' => $dimensions['organizationPath'],
            'organizationNameSnapshot' => $dimensions['organizationName'],
            'storeNameSnapshot' => $dimensions['storeName'],
            'memberNameSnapshot' => $memberName,
            'operatorNameSnapshot' => $dimensions['operatorName'],
        ];
        if ((int)($payload['roomId'] ?? 0) > 0) {
            $command = array_merge($command, [
                'roomId' => (int)$payload['roomId'],
                'roomNameSnapshot' => trim((string)($payload['roomNameSnapshot'] ?? '')),
                // 这些字段只作草稿历史快照；不会参与房态版本或占用校验。
                'roomVersion' => (int)($payload['roomVersion'] ?? 0),
                'roomTimeSlotId' => trim((string)($payload['roomTimeSlotId'] ?? '')),
                'roomTimeSlotVersion' => (int)($payload['roomTimeSlotVersion'] ?? 0),
                'roomGuardFingerprint' => hash('sha256', 'hang-direct-room:' . $commandKey),
            ]);
        }
        try {
            $plan = CashierV3HangOrderPlanV1::fromLockedDraft(
                $command,
                $lockedDraft,
                $operatorScope,
                $dataScope,
                (array)($transferred['frozenWorkspaceRows'] ?? [])
            );
            $persisted = $this->hangOrders->persistInTx($plan, $operatorScope, $dataScope);
        } catch (CashierV3HangOrderAuthorityException $exception) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                '挂单草稿保存失败，本次操作已回滚，请重试。',
                CashierV3ResultCode::STATUS_FAILED,
                array_merge(['reason' => $exception->reason()], $exception->detail())
            );
        }

        return [
            'hangOrder' => $persisted,
            'roomOccupation' => null,
            'cashierDraft' => (array)($transferred['cashierDraft'] ?? []),
        ];
    }

    private function assertWorkspaceContext(array $contexts, string $workspaceId): void
    {
        foreach ($contexts as $context) {
            if ((string)($context['kind'] ?? '') === 'cashier_workspace'
                && hash_equals($workspaceId, (string)($context['id'] ?? ''))) {
                return;
            }
        }
        throw CashierV3CommandException::invalidContext(
            '当前收银工作台已经变化，请刷新后重试。',
            ['reason' => 'hang_workspace_context_mismatch']
        );
    }

    private function dimensions(
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        $storeName = trim((string)Db::name('system_store')
            ->where('id', $operatorScope->storeId())
            ->value('name'));
        $operatorName = '';
        foreach (['staff_name', 'real_name', 'name', 'account'] as $field) {
            $operatorName = trim((string)($dataScope->operatorProfile()[$field] ?? ''));
            if ($operatorName !== '') {
                break;
            }
        }
        $organizationId = (int)$operatorScope->organizationId();
        $organizationName = '';
        $path = [];
        $seen = [];
        while ($organizationId > 0 && count($path) < 64) {
            if (isset($seen[$organizationId])) {
                throw $this->incomplete('hang_organization_cycle');
            }
            $seen[$organizationId] = true;
            $node = Db::name('organization')
                ->where('id', $organizationId)
                ->where('is_del', 0)
                ->field('id,pid,name')
                ->find();
            if (!$node || trim((string)($node['name'] ?? '')) === '') {
                throw $this->incomplete('hang_organization_missing');
            }
            if ($organizationName === '') {
                $organizationName = trim((string)$node['name']);
            }
            $path[] = (int)$node['id'];
            $organizationId = (int)($node['pid'] ?? 0);
        }
        if ($storeName === '' || $operatorName === '' || !$path || $organizationId > 0) {
            throw $this->incomplete('hang_dimension_snapshot_incomplete');
        }
        return [
            'storeName' => $storeName,
            'operatorName' => $operatorName,
            'organizationName' => $organizationName,
            'organizationPath' => '/' . implode('/', array_reverse($path)) . '/',
        ];
    }

    private function guardFingerprint(array $guard): string
    {
        return hash('sha256', json_encode([
            'contractVersion' => (string)($guard['contractVersion'] ?? ''),
            'roomId' => (int)($guard['roomId'] ?? 0),
            'roomVersion' => (int)($guard['roomVersion'] ?? 0),
            'slotKey' => (string)($guard['slotKey'] ?? ''),
            'slotVersion' => (int)($guard['slotVersion'] ?? 0),
            'occupied' => (bool)($guard['occupied'] ?? false),
            'ownerKind' => (string)($guard['ownerKind'] ?? ''),
            'ownerId' => (string)($guard['ownerId'] ?? ''),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function roomFailure(RoomOpenServiceGuardException $exception): CashierV3CommandException
    {
        $reason = $exception->reason();
        if (strpos($reason, 'conflict') !== false
            || strpos($reason, 'version') !== false
            || strpos($reason, 'mismatch') !== false) {
            return CashierV3CommandException::versionConflict(
                '该房间已被占用或状态已经变化，购物车已保留，请重新选择空闲房间。',
                array_merge(['reason' => $reason], $exception->detail())
            );
        }
        return new CashierV3CommandException(
            CashierV3ResultCode::ACTION_DEPENDENCY_NOT_READY,
            '房间状态暂时无法确认，购物车已保留，请稍后重试。',
            CashierV3ResultCode::STATUS_FAILED,
            array_merge(['reason' => $reason], $exception->detail())
        );
    }

    private function incomplete(string $reason): CashierV3CommandException
    {
        return new CashierV3CommandException(
            CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
            '挂单资料不完整，本次操作已回滚，请刷新后重试。',
            CashierV3ResultCode::STATUS_FAILED,
            ['reason' => $reason]
        );
    }
}
