<?php
namespace app\services\cashier\v3\projection;

/**
 * 完整根 state schema —— 与前端 EMPTY_BOOTSTRAP 交叉校验的唯一合同。
 *
 * PHP 空对象分区必须保持 object（stdClass），JSON 编码为 {}，不得变成 []。
 */
class CashierV3RootStateContract
{
    /** 与前端 EMPTY_BOOTSTRAP 键集合一致（顺序无关） */
    public const REQUIRED_KEYS = [
        'stateContextId',
        'stateRevision',
        'readOnly',
        'sessionMode',
        'storeName',
        'currentStore',
        'featurePermissions',
        'operationPermissions',
        'workspace',
        'operator',
        'pendingHangCount',
        'cashier',
        'serviceCompletion',
        'writeoff',
        'room',
        'reservation',
        'memberCenter',
        'memberSelector',
        'queryEntitySelector',
        'managementCenter',
        'businessDashboard',
        'hangOrders',
        'orderCenter',
    ];

    /** 必须是对象（assoc / stdClass），禁止数组伪装 */
    public const OBJECT_KEYS = [
        'currentStore',
        'featurePermissions',
        'operationPermissions',
        'workspace',
        'operator',
        'cashier',
        'serviceCompletion',
        'writeoff',
        'room',
        'reservation',
        'memberCenter',
        'memberSelector',
        'queryEntitySelector',
        'managementCenter',
        'businessDashboard',
        'hangOrders',
        'orderCenter',
    ];

    /**
     * @return string[] 违规说明；空=通过
     */
    public static function validate(array $state): array
    {
        $problems = [];
        foreach (self::REQUIRED_KEYS as $key) {
            if (!array_key_exists($key, $state)) {
                $problems[] = 'missing:' . $key;
            }
        }
        // 禁止错误合同键
        if (array_key_exists('members', $state)) {
            $problems[] = 'forbidden_key:members';
        }
        if (($state['stateContextId'] ?? '') === '' || !is_string($state['stateContextId'] ?? null)) {
            $problems[] = 'stateContextId_invalid';
        }
        $rev = $state['stateRevision'] ?? null;
        if (!is_string($rev) || !preg_match('/^[1-9][0-9]*$/', $rev)) {
            $problems[] = 'stateRevision_invalid';
        }
        if (!is_int($state['pendingHangCount'] ?? null) && !ctype_digit((string)($state['pendingHangCount'] ?? ''))) {
            $problems[] = 'pendingHangCount_invalid';
        }
        foreach (self::OBJECT_KEYS as $key) {
            if (!array_key_exists($key, $state)) {
                continue;
            }
            $value = $state[$key];
            if ($value instanceof \stdClass) {
                continue;
            }
            if (!is_array($value)) {
                $problems[] = $key . '_not_object';
                continue;
            }
            // 纯列表数组不得充当对象分区
            if ($value !== [] && array_keys($value) === range(0, count($value) - 1)) {
                $problems[] = $key . '_list_not_object';
            }
        }
        $workspace = $state['workspace'] ?? null;
        if (is_array($workspace) || $workspace instanceof \stdClass) {
            $ws = (array)$workspace;
            $wsId = (string)($ws['id'] ?? '');
            if ($wsId === '' || strpos($wsId, 'ws:') !== 0) {
                $problems[] = 'workspace_id_not_canonical';
            }
            $wsRev = $ws['revision'] ?? null;
            if (!is_int($wsRev) || $wsRev < 1) {
                $problems[] = 'workspace_revision_not_positive';
            }
        }
        return $problems;
    }

    public static function isCompleteRoot(array $state): bool
    {
        return self::validate($state) === [];
    }

    /**
     * 空壳完整根。对象分区用 stdClass，保证 json_encode 为 {}。
     *
     * @param array $extras 已由 RootDomainAssembler 提供的权威字段
     */
    public static function emptyRoot(string $stateContextId, string $stateRevision, array $extras = []): array
    {
        $emptyObj = static function () {
            return new \stdClass();
        };
        $emptyQueryPage = static function () {
            return [
                'records' => [],
                'total' => 0,
                'page' => 1,
                'pageSize' => 20,
                'isLoading' => false,
            ];
        };

        return [
            'stateContextId' => $stateContextId,
            'stateRevision' => $stateRevision,
            'readOnly' => (bool)($extras['readOnly'] ?? false),
            'sessionMode' => (string)($extras['sessionMode'] ?? 'store_staff'),
            'storeName' => (string)($extras['storeName'] ?? ''),
            'currentStore' => $extras['currentStore'] ?? ['id' => null, 'name' => ''],
            'featurePermissions' => $extras['featurePermissions'] ?? new \stdClass(),
            'operationPermissions' => $extras['operationPermissions'] ?? new \stdClass(),
            'workspace' => $extras['workspace'] ?? [
                'id' => null,
                'revision' => 0,
                'status' => 'editing',
                'serverTime' => null,
            ],
            'operator' => $extras['operator'] ?? ['name' => '', 'roleName' => ''],
            'pendingHangCount' => (int)($extras['pendingHangCount'] ?? 0),
            'cashier' => $extras['cashier'] ?? $emptyObj(),
            'serviceCompletion' => $extras['serviceCompletion'] ?? [
                'serviceOrder' => null,
                'lines' => [],
                'commandContexts' => [],
            ],
            'writeoff' => $extras['writeoff'] ?? $emptyObj(),
            'room' => $extras['room'] ?? $emptyObj(),
            'reservation' => $extras['reservation'] ?? $emptyObj(),
            'memberCenter' => $extras['memberCenter'] ?? [
                'canBatchOperate' => false,
                'records' => [],
                'detail' => null,
            ],
            'memberSelector' => $extras['memberSelector'] ?? $emptyQueryPage(),
            'queryEntitySelector' => $extras['queryEntitySelector'] ?? [
                'person' => $emptyQueryPage(),
                'store' => $emptyQueryPage(),
                'organization' => $emptyQueryPage(),
            ],
            'managementCenter' => $extras['managementCenter'] ?? ['entries' => []],
            'businessDashboard' => $extras['businessDashboard'] ?? $emptyObj(),
            'hangOrders' => $extras['hangOrders'] ?? [
                'statusOptions' => [],
                'records' => [],
            ],
            'orderCenter' => $extras['orderCenter'] ?? [
                'businessTypes' => [],
                'salesOrders' => [],
                'salesOrderDetail' => null,
            ],
        ];
    }

    /**
     * 编码前把关联空数组转 stdClass，避免 JSON []。
     */
    public static function encodeReady(array $state): array
    {
        foreach (self::OBJECT_KEYS as $key) {
            if (!array_key_exists($key, $state)) {
                continue;
            }
            if (is_array($state[$key]) && $state[$key] === []) {
                $state[$key] = new \stdClass();
            }
        }
        if (isset($state['featurePermissions']) && is_array($state['featurePermissions']) && $state['featurePermissions'] === []) {
            $state['featurePermissions'] = new \stdClass();
        }
        return $state;
    }
}
