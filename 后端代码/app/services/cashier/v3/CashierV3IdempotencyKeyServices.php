<?php
// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------

namespace app\services\cashier\v3;

/**
 * 幂等键与浏览器工作台会话标识的格式校验。
 *
 * 约定：已登记大写前缀 + 标准 UUID，总长不超过 128。
 * 空值、自由文本、未登记前缀、非标准 UUID 一律拒绝，不做「猜一个键继续执行」。
 */
class CashierV3IdempotencyKeyServices
{
    public const MAX_LENGTH = 128;

    /**
     * 已登记前缀。取自前端 cashierV3Bridge.js createCashierV3CommandId 的默认值
     * 与 Vue 3 源码中实际调用的前缀，未登记前缀视为自由文本拒绝。
     */
    private const REGISTERED_PREFIXES = [
        // cashierV3Bridge.js createCashierV3CommandId 默认前缀
        'CMD',
        // 结账
        'CHECKOUT',
        'CHECKOUT_PREPARE',
        'CHECKOUT_BALANCE_RECOVERY',
        'DEBT_REPAY_PREPARE',
        'SERVICE_CHECKOUT',
        'SERVICE_CHECKOUT_PREPARE',
        // 挂单
        'HANG',
        'HANG_PREPARE',
        // 服务
        'SERVICE',
        'SERVICE_ACTION',
        'SERVICE_LINE',
        'SERVICE_PREPARE',
        // 预约
        'RESERVATION',
        'RESERVATION_ACTION',
        'RESERVATION_PLAN',
        'RESERVATION_EDITOR',
        // 房间
        'ROOM_ACTION',
        'ROOM_ASSIGNMENT',
        'ROOM_CASHIER_INTENT',
        'ROOM_PREPARE',
        // 核销
        'WRITEOFF',
        'WRITEOFF_PREPARE',
        'ADD_ENTITLEMENT',
        'ENTITLEMENT_ADD',
        'ENTITLEMENT_SELECTOR',
        'REMOVE_CART_LINE',
        'CLEAR_CART',
        'CHANGE_CART_QUANTITY',
        'CART_SERVICE_SETTINGS',
        // 收银草稿编辑
        'CASHIER_LINE_DEBT',
        'APPLY_LINE_COUPON',
        'REMOVE_LINE_COUPON',
        'CASHIER_APPLY_SALESPEOPLE_ALL',
        'CASHIER_APPLY_CRAFTSMEN_ALL',
        'CASHIER_APPLY_PERSONNEL_ALL',
        'CASHIER_MORE',
        // 卡操作（转让、延期、停用、启用及后续升级/替换）
        'CARD_OPERATION',
        // 统一查询
        'QUERY_ENTITY',
    ];

    /** 浏览器工作台会话标识前缀，见 cashierV3Bridge.js:1485 */
    private const CLIENT_SESSION_PREFIX = 'SESSION';

    private const UUID_PATTERN = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}';

    /**
     * @return string 规范化后的幂等键（前缀保持大写，UUID 统一小写）
     */
    public function normalizeIdempotencyKey(string $rawKey): string
    {
        $key = trim($rawKey);
        if ($key === '') {
            throw new CashierV3CommandException(
                CashierV3ResultCode::INVALID_IDEMPOTENCY_KEY,
                '本次操作缺少请求标识，请刷新当前工作台后重试。'
            );
        }
        if (strlen($key) > self::MAX_LENGTH) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::INVALID_IDEMPOTENCY_KEY,
                '本次操作的请求标识长度超出限制。'
            );
        }
        $matched = [];
        if (!preg_match('/^([A-Z][A-Z0-9_]{0,31})-(' . self::UUID_PATTERN . ')$/', $key, $matched)) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::INVALID_IDEMPOTENCY_KEY,
                '本次操作的请求标识格式无效。'
            );
        }
        $prefix = $matched[1];
        if (!in_array($prefix, self::REGISTERED_PREFIXES, true)) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::INVALID_IDEMPOTENCY_KEY,
                '本次操作的请求标识前缀未登记。',
                CashierV3ResultCode::STATUS_FAILED,
                ['prefix' => $prefix]
            );
        }
        return $prefix . '-' . strtolower($matched[2]);
    }

    /**
     * 浏览器工作台会话标识：每个标签页稳定唯一，用于生成 stateContextId。
     */
    public function normalizeClientSessionId(string $rawSessionId): string
    {
        $sessionId = trim($rawSessionId);
        if ($sessionId === '') {
            throw new CashierV3CommandException(
                CashierV3ResultCode::CLIENT_SESSION_REQUIRED,
                '缺少当前工作台会话标识，请刷新页面后重试。'
            );
        }
        if (strlen($sessionId) > self::MAX_LENGTH) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::CLIENT_SESSION_REQUIRED,
                '当前工作台会话标识长度超出限制。'
            );
        }
        $matched = [];
        if (!preg_match('/^(' . self::CLIENT_SESSION_PREFIX . ')-(' . self::UUID_PATTERN . ')$/', $sessionId, $matched)) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::CLIENT_SESSION_REQUIRED,
                '当前工作台会话标识格式无效，请刷新页面后重试。'
            );
        }
        return self::CLIENT_SESSION_PREFIX . '-' . strtolower($matched[2]);
    }

    /**
     * @return string[]
     */
    public static function registeredPrefixes(): array
    {
        return self::REGISTERED_PREFIXES;
    }
}
