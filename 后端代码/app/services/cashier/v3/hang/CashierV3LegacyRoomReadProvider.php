<?php

namespace app\services\cashier\v3\hang;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResourceScope;
use app\services\cashier\v3\CashierV3ResourceVersionServices;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\hang\authority\ThinkPhpCashierV3HangOrderRepository;
use app\services\room\guard\RoomOpenServiceGuardAuthority;
use app\services\room\guard\RoomOpenServiceGuardException;
use app\services\room\guard\RoomOpenServiceGuardVersionProvider;
use think\facade\Db;

/**
 * V3 房间配置与开放服务占用的只读提供器。
 *
 * table_qrcode 只作为当前门店启用房间的配置源。运行中占用只读取 V3
 * room guard；旧预约、旧桌码订单和历史挂单不参与本合同。
 * 类名为在途代码兼容保留，不代表继续兼容旧预约占用。
 */
final class CashierV3LegacyRoomReadProvider implements CashierV3HangPreparationProvider
{
    public const AUTHORITY_VERSION = 'cashier-v3-room-config-guard-authority-v1';

    /** @var RoomOpenServiceGuardAuthority */
    private $roomGuards;

    /** @var RoomOpenServiceGuardVersionProvider */
    private $roomGuardVersions;

    public function __construct(
        ?RoomOpenServiceGuardAuthority $roomGuards = null,
        ?RoomOpenServiceGuardVersionProvider $roomGuardVersions = null
    ) {
        $this->roomGuards = $roomGuards ?: new RoomOpenServiceGuardAuthority();
        $this->roomGuardVersions = $roomGuardVersions ?: new RoomOpenServiceGuardVersionProvider();
    }

    public function workspaceVersion(
        string $workspaceId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): int {
        $this->assertScope($operatorScope, $dataScope);
        $version = Db::name(CashierV3ResourceVersionServices::TABLE)
            ->where('scope_type', CashierV3ResourceScope::TYPE_STORE)
            ->where('scope_id', (string)$operatorScope->storeId())
            ->where('resource_kind', 'cashier_workspace')
            ->where('resource_id', $workspaceId)
            ->value('current_version');
        $version = (int)$version;
        if ($version <= 0) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ACTION_DEPENDENCY_NOT_READY,
                '当前收银工作台版本尚未准备完成，请刷新页面后重试。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'cashier_workspace_version_missing']
            );
        }
        return $version;
    }

    public function roomCandidates(
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        $this->assertScope($operatorScope, $dataScope);
        $storeId = $operatorScope->storeId();
        try {
            $rooms = Db::name('table_qrcode')
                ->where('store_id', $storeId)
                ->where('is_del', 0)
                ->where('is_using', 1)
                ->field('id,store_id,cate_id,remarks,table_number,seat_num,is_using,is_del,add_time')
                ->order('id asc')
                ->select();
            $rooms = $this->rows($rooms);

            $v3Occupancies = $this->roomGuards->activeOccupancies($operatorScope, $dataScope);
            $hangOrders = $this->hangOrderSnapshots($v3Occupancies, $dataScope, $storeId);
        } catch (CashierV3CommandException $exception) {
            throw $exception;
        } catch (RoomOpenServiceGuardException $exception) {
            throw $this->guardUnavailable($exception->reason());
        } catch (\Throwable $exception) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ACTION_DEPENDENCY_NOT_READY,
                '房间数据暂时无法读取，请刷新房态后重试。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'v3_room_authority_unavailable']
            );
        }

        $candidates = [];
        foreach ($rooms as $room) {
            $roomId = (int)($room['id'] ?? 0);
            if ($roomId <= 0 || (int)($room['store_id'] ?? 0) !== $storeId) {
                continue;
            }
            $occupation = is_array($v3Occupancies[$roomId] ?? null)
                ? $v3Occupancies[$roomId]
                : null;
            $v3Occupied = $occupation !== null;
            $selectable = !$v3Occupied;
            $name = trim((string)($room['remarks'] ?? ''));
            if ($name === '') {
                $number = trim((string)($room['table_number'] ?? ''));
                $name = $number === '' ? '房间 ' . $roomId : '房间 ' . $number;
            }
            $slotId = 'open-service:' . $roomId;
            try {
                // Candidate and submit-time discovery must use one room version.
                $roomVersion = $this->roomGuardVersions->discoverVersion(
                    RoomOpenServiceGuardVersionProvider::KIND_ROOM,
                    (string)$roomId,
                    $operatorScope,
                    $dataScope
                );
                $slotVersion = $this->roomGuardVersions->discoverVersion(
                    RoomOpenServiceGuardVersionProvider::KIND_SLOT,
                    $slotId,
                    $operatorScope,
                    $dataScope
                );
            } catch (RoomOpenServiceGuardException $exception) {
                throw $this->guardUnavailable($exception->reason());
            } catch (\Throwable $exception) {
                throw $this->guardUnavailable('room_guard_version_unavailable');
            }
            $contexts = [
                ['kind' => 'room', 'id' => (string)$roomId, 'expectedVersion' => $roomVersion],
                ['kind' => 'room_time_slot', 'id' => $slotId, 'expectedVersion' => $slotVersion],
            ];
            $hangOrder = $occupation !== null
                && (string)($occupation['ownerKind'] ?? '') === RoomOpenServiceGuardAuthority::OWNER_HANG_ORDER
                ? ($hangOrders[(string)($occupation['ownerId'] ?? '')] ?? null)
                : null;
            $candidates[] = [
                'id' => $roomId,
                'roomId' => $roomId,
                'name' => $name,
                'categoryId' => (int)($room['cate_id'] ?? 0),
                'categoryName' => '房间',
                'enabled' => true,
                'status' => $selectable ? '空闲' : '服务中',
                'statusLabel' => $selectable ? '空闲' : '服务中',
                'roomStatus' => $selectable ? '空闲' : '服务中',
                'selectable' => $selectable,
                'canSelect' => $selectable,
                'disabledReason' => $selectable ? '' : '该房间当前已被占用',
                'availabilityDescription' => $selectable ? '当前空闲' : '当前已占用',
                'revision' => $roomVersion,
                'roomVersion' => $roomVersion,
                'roomTimeSlotId' => $slotId,
                'roomTimeSlotVersion' => $slotVersion,
                'commandContexts' => $contexts,
                'authorityVersion' => self::AUTHORITY_VERSION,
                'ownerKind' => $occupation['ownerKind'] ?? '',
                'ownerId' => $occupation['ownerId'] ?? '',
                'occupiedAt' => (int)($occupation['occupiedAt'] ?? 0),
                'hangOrderId' => is_array($hangOrder) ? (string)$hangOrder['hang_order_id'] : '',
                'hangOrderNo' => is_array($hangOrder) ? (string)$hangOrder['hang_order_no'] : '',
                'hangOrderVersion' => is_array($hangOrder) ? (int)$hangOrder['hang_version'] : 0,
                'memberId' => is_array($hangOrder) ? (int)$hangOrder['member_id'] : 0,
                'memberName' => is_array($hangOrder) ? (string)$hangOrder['member_name_snapshot'] : '',
                'serviceStartedAt' => is_array($hangOrder)
                    ? date('Y-m-d H:i:s', (int)$hangOrder['occurred_at'])
                    : '',
                'pendingWriteoffCount' => 0,
                'newConsumptionAmount' => 0,
                'actions' => [],
            ];
        }
        return $candidates;
    }

    private function hangOrderSnapshots(array $occupancies, CashierV3DataScopeContext $dataScope, int $storeId): array
    {
        $ids = [];
        foreach ($occupancies as $occupation) {
            if (is_array($occupation)
                && (string)($occupation['ownerKind'] ?? '') === RoomOpenServiceGuardAuthority::OWNER_HANG_ORDER
                && trim((string)($occupation['ownerId'] ?? '')) !== '') {
                $ids[(string)$occupation['ownerId']] = (string)$occupation['ownerId'];
            }
        }
        if (!$ids) {
            return [];
        }
        $rows = $this->rows(Db::name(ThinkPhpCashierV3HangOrderRepository::HEADER_TABLE)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('store_id', $storeId)
            ->whereIn('hang_order_id', array_values($ids))
            ->where('hang_status', 'service_in_progress')
            ->field('hang_order_id,hang_order_no,hang_version,member_id,member_name_snapshot,occurred_at')
            ->select());
        $result = [];
        foreach ($rows as $row) {
            $id = trim((string)($row['hang_order_id'] ?? ''));
            if ($id === '' || isset($result[$id]) || !isset($ids[$id])) {
                throw new \RuntimeException('v3_room_hang_snapshot_invalid');
            }
            $result[$id] = $row;
        }
        foreach ($ids as $id) {
            if (!isset($result[$id])) {
                throw new \RuntimeException('v3_room_hang_owner_missing');
            }
        }
        return $result;
    }

    private function guardUnavailable(string $reason): CashierV3CommandException
    {
        return new CashierV3CommandException(
            CashierV3ResultCode::ACTION_DEPENDENCY_NOT_READY,
            '房间占用状态暂时无法读取，请刷新房态后重试。',
            CashierV3ResultCode::STATUS_FAILED,
            ['reason' => $reason]
        );
    }

    private function assertScope(
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): void {
        $storeId = $operatorScope->storeId();
        if ($dataScope->forcedStoreId() !== $storeId || !$dataScope->allowsStore($storeId)) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::PERMISSION_DENIED,
                '当前账号无权查看该门店房间。',
                CashierV3ResultCode::STATUS_FAILED
            );
        }
    }

    private function rows($rows): array
    {
        if (is_object($rows) && method_exists($rows, 'toArray')) {
            $rows = $rows->toArray();
        }
        return is_array($rows) ? array_values($rows) : [];
    }

}
