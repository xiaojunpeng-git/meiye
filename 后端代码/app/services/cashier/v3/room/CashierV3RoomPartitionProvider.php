<?php

namespace app\services\cashier\v3\room;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\hang\CashierV3HangPreparationProvider;
use app\services\cashier\v3\hang\CashierV3LegacyRoomReadProvider;
use app\services\cashier\v3\projection\CashierV3RootPartitionProvider;
use think\facade\Db;

/** Current V3 room map: room configuration plus V3 open-service guards only. */
final class CashierV3RoomPartitionProvider implements CashierV3RootPartitionProvider
{
    public const CONTRACT_VERSION = 'cashier-v3-room-partition-v1';

    /** @var CashierV3HangPreparationProvider */
    private $rooms;

    /** @var CashierV3RoomReservationReadServices */
    private $reservations;

    public function __construct(
        ?CashierV3HangPreparationProvider $rooms = null,
        ?CashierV3RoomReservationReadServices $reservations = null
    )
    {
        $this->rooms = $rooms ?: new CashierV3LegacyRoomReadProvider();
        $this->reservations = $reservations ?: new CashierV3RoomReservationReadServices();
    }

    public function partitionKey(): string
    {
        return 'room';
    }

    public function readPartition(
        string $stateContextId,
        string $stateRevision,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope,
        array $hints = []
    ): array {
        $candidates = $this->candidates($operatorScope, $dataScope);
        $categories = $this->categories($candidates, $operatorScope->storeId());
        return [
            'ready' => true,
            'payload' => [
                'contractVersion' => self::CONTRACT_VERSION,
                'availability' => [
                    'contractVersion' => self::CONTRACT_VERSION,
                    'status' => 'active',
                    'reasonCode' => '',
                    'dataLoaded' => true,
                    'businessFactsIncluded' => true,
                ],
                'refreshedAt' => date('c'),
                'staleMessage' => '',
                'pollingIntervalSeconds' => 10,
                'pendingAssignmentCount' => 0,
                'pendingAssignments' => [],
                'unassignedList' => [
                    'records' => [],
                    'total' => 0,
                    'page' => 1,
                    'pageSize' => 20,
                    'refreshedAt' => date('c'),
                    'staleMessage' => '',
                ],
                'categories' => $categories,
                'detail' => null,
                'assignment' => null,
            ],
            'public_versions' => $this->publicVersions($candidates),
        ];
    }

    public function detail(
        array $payload,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        $roomId = $this->positiveInt($payload['roomId'] ?? $payload['id'] ?? null);
        $expectedVersion = $this->optionalPositiveInt(
            $payload['roomVersion'] ?? $payload['revision'] ?? null
        );
        foreach ($this->candidates($operatorScope, $dataScope) as $room) {
            if ((int)($room['roomId'] ?? 0) !== $roomId) {
                continue;
            }
            if ($expectedVersion !== null && (int)$room['roomVersion'] !== $expectedVersion) {
                throw CashierV3CommandException::versionConflict(
                    '该房间资料已经变化，请刷新房态后重试。',
                    ['reason' => 'room_detail_version_changed', 'room_id' => $roomId]
                );
            }
            $detail = $room;
            $detail['roomName'] = (string)$room['name'];
            $detail['pendingAssignments'] = [];
            $detail['historyReservations'] = [];
            $detail['upcomingReservations'] = [];
            $detail['serviceOrder'] = null;
            $detail['actions'] = [];
            return [
                'detail' => $detail,
                'versions' => $this->publicVersions([$room]),
            ];
        }
        throw new CashierV3CommandException(
            CashierV3ResultCode::RESOURCE_NOT_FOUND,
            '该房间不存在、已停用或不属于当前门店。',
            CashierV3ResultCode::STATUS_FAILED,
            ['kind' => 'room', 'id' => $roomId]
        );
    }

    private function candidates(CashierV3OperatorScope $operatorScope, CashierV3DataScopeContext $dataScope): array
    {
        $activeReservations = $this->reservations->activeByRoom($operatorScope, $dataScope);
        $result = [];
        foreach ($this->rooms->roomCandidates($operatorScope, $dataScope) as $room) {
            $roomId = (int)($room['roomId'] ?? $room['id'] ?? 0);
            $reservationServices = $roomId > 0 ? (array)($activeReservations[$roomId] ?? []) : [];
            if (!$reservationServices) {
                $result[] = $room;
                continue;
            }
            $primary = $reservationServices[0];
            $guardService = trim((string)($room['ownerKind'] ?? '')) !== '' ? [[
                'sourceType' => 'room_guard',
                'memberName' => (string)($room['memberName'] ?? ''),
                'serviceStartedAt' => (string)($room['serviceStartedAt'] ?? ''),
            ]] : [];
            $room['activeReservationServices'] = $reservationServices;
            $room['activeServiceSources'] = array_merge($reservationServices, $guardService);
            $room['activeServiceCount'] = count($room['activeServiceSources']);
            $room['serviceSource'] = 'reservation';
            $room['memberName'] = (string)($primary['memberName'] ?? '');
            $room['memberPhone'] = (string)($primary['memberPhone'] ?? '');
            $room['reservationId'] = (int)($primary['reservationId'] ?? 0);
            $room['reservationNo'] = (string)($primary['reservationNo'] ?? '');
            $room['reservationVersion'] = (int)($primary['reservationVersion'] ?? 0);
            $room['projectSummary'] = (string)($primary['projectSummary'] ?? '');
            $room['serviceStartedAt'] = (string)($primary['serviceStartedAt'] ?? '');
            $room['serviceDuration'] = (string)($primary['serviceStartedAt'] ?? '');
            $room['primaryCraftsman'] = '预约服务';
            $room['pendingWriteoffCount'] = 0;
            $room['newConsumptionAmount'] = 0;
            $room['status'] = '服务中';
            $room['statusLabel'] = '服务中';
            $room['roomStatus'] = '服务中';
            $result[] = $room;
        }
        return $result;
    }

    private function categories(array $rooms, int $storeId): array
    {
        $categoryIds = [];
        foreach ($rooms as $room) {
            $categoryId = (int)($room['categoryId'] ?? 0);
            $categoryIds[$categoryId] = $categoryId;
        }
        $names = [];
        $positiveIds = array_values(array_filter($categoryIds, static function (int $id): bool {
            return $id > 0;
        }));
        if ($positiveIds) {
            $rows = Db::name('category')
                ->whereIn('id', $positiveIds)
                ->where('relation_id', $storeId)
                ->where('group', 6)
                ->where('type', 1)
                ->where('is_show', 1)
                ->field('id,name')
                ->order('sort desc,id asc')
                ->select();
            if (is_object($rows) && method_exists($rows, 'toArray')) {
                $rows = $rows->toArray();
            }
            foreach (is_array($rows) ? $rows : [] as $row) {
                $id = (int)($row['id'] ?? 0);
                $name = trim((string)($row['name'] ?? ''));
                if ($id > 0 && $name !== '' && isset($categoryIds[$id])) {
                    $names[$id] = $name;
                }
            }
        }

        $grouped = [];
        foreach ($rooms as $room) {
            $categoryId = (int)($room['categoryId'] ?? 0);
            if (!isset($grouped[$categoryId])) {
                $grouped[$categoryId] = [
                    'id' => $categoryId > 0 ? $categoryId : 'uncategorized',
                    'name' => $names[$categoryId] ?? ($categoryId > 0 ? '房间分类 ' . $categoryId : '其他房间'),
                    'rooms' => [],
                ];
            }
            $room['categoryName'] = $grouped[$categoryId]['name'];
            $grouped[$categoryId]['rooms'][] = $room;
        }
        return array_values($grouped);
    }

    private function publicVersions(array $rooms): array
    {
        $versions = [];
        foreach ($rooms as $room) {
            foreach ((array)($room['commandContexts'] ?? []) as $context) {
                $kind = trim((string)($context['kind'] ?? ''));
                $id = trim((string)($context['id'] ?? ''));
                $version = (int)($context['expectedVersion'] ?? 0);
                if ($kind === '' || $id === '' || $version <= 0) {
                    throw new \RuntimeException('room_partition_public_version_invalid');
                }
                $versions[$kind . ':' . $id] = [
                    'kind' => $kind,
                    'id' => $id,
                    'version' => $version,
                ];
            }
        }
        return array_values($versions);
    }

    private function positiveInt($value): int
    {
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/D', trim($value)) === 1) {
            $value = (int)$value;
        }
        if (!is_int($value) || $value <= 0) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::INVALID_COMMAND_CONTEXT,
                '房间标识无效，请刷新房态后重试。'
            );
        }
        return $value;
    }

    private function optionalPositiveInt($value)
    {
        if ($value === null || trim((string)$value) === '') {
            return null;
        }
        return $this->positiveInt($value);
    }
}
