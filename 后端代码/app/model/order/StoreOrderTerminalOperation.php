<?php
declare(strict_types=1);

namespace app\model\order;

use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;

/**
 * 订单退款/作废统一终态操作
 */
class StoreOrderTerminalOperation extends BaseModel
{
    use ModelTrait;

    protected $pk = 'id';
    protected $name = 'store_order_terminal_operation';

    /** 表内时间为 Unix 整型，禁止 ORM 按日期字符串转换 */
    protected $autoWriteTimestamp = false;

    public const ACTION_REFUND = 1;
    public const ACTION_VOID = 2;

    public const BUSINESS_ORDER = 1;
    public const BUSINESS_RECHARGE = 2;
    public const BUSINESS_DEBT_REPAY = 3;

    public const STATE_INIT = 0;
    public const STATE_BALANCE_PENDING = 1;
    public const STATE_CHANNEL_PENDING = 2;
    public const STATE_LOCAL_CLOSING = 3;
    public const STATE_SUCCESS = 4;
    public const STATE_FAILED_RETRYABLE = 5;
    public const STATE_NEED_MANUAL = 6;

    public const SOURCE_MOBILE = 1;
    public const SOURCE_STORE = 2;
    public const SOURCE_CASHIER = 3;
    public const SOURCE_ADMIN = 4;

    /** @return int[] */
    public static function processingStates(): array
    {
        return [
            self::STATE_INIT,
            self::STATE_BALANCE_PENDING,
            self::STATE_CHANNEL_PENDING,
            self::STATE_LOCAL_CLOSING,
        ];
    }

    /** @return int[] */
    public static function resumableStates(): array
    {
        return array_merge(self::processingStates(), [
            self::STATE_FAILED_RETRYABLE,
            self::STATE_NEED_MANUAL,
        ]);
    }
}
