<?php
namespace app\services\cashier\v3;

/**
 * 写命令唯一请求规范化阶段。
 *
 * 别名类型非法或双别名冲突立即拒绝；规范化结果供 policy／permission／contexts／handler 共用。
 * 原始 payload 不得进入业务 handler。
 */
class CashierV3RequestNormalizer
{
    /** 房间安排允许的 mode 穷举合同 */
    public const ROOM_MODES = ['assign', 'change', 'remove', 'keep_unassigned'];

    /**
     * @return array{normalized:array,warnings:string[]}
     */
    public static function normalize(string $canonicalAction, array $payload): array
    {
        $out = $payload;
        $warnings = [];

        // 通用：拒绝布尔／数组冒充 ID 的别名（AliasResolver 已覆盖主要路径）
        self::assertNoIllegalAliasTypes($out, [
            'serviceOrderId', 'service_order_id',
            'checkoutRequestId', 'checkout_request_id',
            'hangOrderId', 'hang_order_id',
            'reservationId', 'reservation_id',
            'roomId', 'room_id',
            'currentRoomId', 'current_room_id',
            'targetRoomId', 'target_room_id',
            'selectorEntry', 'selector_entry',
        ]);

        if ($canonicalAction === 'save-service-room-assignment') {
            $out = self::normalizeRoomAssignment($out);
        }

        // selectorEntry：只保留 canonical 键；双别名冲突拒绝
        if (CashierV3AliasResolver::hasAnyKey($out, ['selectorEntry', 'selector_entry'])) {
            $entry = CashierV3AliasResolver::resolveEnum($out, ['selectorEntry', 'selector_entry'], false);
            unset($out['selector_entry']);
            if ($entry !== '') {
                $out['selectorEntry'] = $entry;
            }
        }

        return ['normalized' => $out, 'warnings' => $warnings];
    }

    /**
     * 房间安排：禁止静默把一个业务 mode 改成另一个；矛盾组合立即拒绝。
     */
    protected static function normalizeRoomAssignment(array $payload): array
    {
        $mode = CashierV3AliasResolver::resolveEnum(
            $payload,
            ['assignmentMode', 'assignment_mode', 'mode'],
            true,
            self::ROOM_MODES
        );
        $currentRoomId = CashierV3AliasResolver::resolveNullableId(
            $payload,
            ['currentRoomId', 'current_room_id']
        );
        $targetRoomId = CashierV3AliasResolver::resolveNullableId(
            $payload,
            ['targetRoomId', 'target_room_id']
        );
        $hasTarget = CashierV3AliasResolver::hasAnyKey($payload, ['targetRoomId', 'target_room_id']);
        $hasCurrent = CashierV3AliasResolver::hasAnyKey($payload, ['currentRoomId', 'current_room_id']);

        switch ($mode) {
            case 'assign':
                if ($hasCurrent && $currentRoomId !== null && $currentRoomId !== '') {
                    throw CashierV3CommandException::invalidContext(
                        '分配房间时不得夹带当前房间对象。',
                        ['reason' => 'assign_forbids_current_room', 'mode' => $mode]
                    );
                }
                if ($targetRoomId === null || $targetRoomId === '') {
                    throw CashierV3CommandException::invalidContext(
                        '分配房间时必须指定目标房间。',
                        ['reason' => 'assign_requires_target_room', 'mode' => $mode]
                    );
                }
                break;
            case 'change':
                if ($currentRoomId === null || $currentRoomId === '' || $targetRoomId === null || $targetRoomId === '') {
                    throw CashierV3CommandException::invalidContext(
                        '换房必须同时指定当前房间与目标房间。',
                        ['reason' => 'change_requires_current_and_target', 'mode' => $mode]
                    );
                }
                if ($currentRoomId === $targetRoomId) {
                    throw CashierV3CommandException::invalidContext(
                        '换房的当前房间与目标房间不能相同。',
                        ['reason' => 'change_same_room', 'mode' => $mode]
                    );
                }
                break;
            case 'remove':
                if ($currentRoomId === null || $currentRoomId === '') {
                    throw CashierV3CommandException::invalidContext(
                        '移除房间占用时必须锁定当前房间。',
                        ['reason' => 'remove_requires_current', 'mode' => $mode]
                    );
                }
                if ($hasTarget && $targetRoomId !== null && $targetRoomId !== '') {
                    throw CashierV3CommandException::invalidContext(
                        '移除房间占用时不得夹带目标房间。',
                        ['reason' => 'remove_forbids_target_room', 'mode' => $mode]
                    );
                }
                break;
            case 'keep_unassigned':
                if ($hasTarget && $targetRoomId !== null && $targetRoomId !== '') {
                    throw CashierV3CommandException::invalidContext(
                        '保持待分配时不得夹带房间对象。',
                        ['reason' => 'keep_unassigned_forbids_room', 'mode' => $mode]
                    );
                }
                if ($hasCurrent && $currentRoomId !== null && $currentRoomId !== '') {
                    throw CashierV3CommandException::invalidContext(
                        '保持待分配时不得夹带当前房间。',
                        ['reason' => 'keep_unassigned_forbids_current', 'mode' => $mode]
                    );
                }
                break;
        }

        $payload['assignmentMode'] = $mode;
        $payload['mode'] = $mode;
        unset($payload['assignment_mode']);
        if ($hasCurrent) {
            $payload['currentRoomId'] = $currentRoomId;
            unset($payload['current_room_id']);
        }
        if ($hasTarget) {
            $payload['targetRoomId'] = $targetRoomId;
            unset($payload['target_room_id']);
        }
        return $payload;
    }

    /** @param string[] $keys */
    protected static function assertNoIllegalAliasTypes(array $payload, array $keys): void
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }
            $raw = $payload[$key];
            if (is_array($raw) || is_bool($raw)) {
                throw CashierV3CommandException::invalidContext(
                    '本次操作的对象标识格式无效，请刷新当前工作台后重试。',
                    ['reason' => 'payload_id_alias_type_invalid', 'key' => $key]
                );
            }
        }
    }
}
