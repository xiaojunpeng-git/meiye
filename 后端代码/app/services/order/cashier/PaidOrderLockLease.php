<?php
declare(strict_types=1);

namespace app\services\order\cashier;

use app\services\order\StoreOrderSuccessServices;
use think\exception\ValidateException;
use think\facade\Db;

/**
 * 支付事务内订单行锁租约。
 *
 * 禁止外部裸颁发。锁单（FOR UPDATE）仅允许在
 * StoreOrderSuccessServices::lockOrderAndIssueLease 内完成；
 * 本类只在已锁行上绑定租约。
 */
final class PaidOrderLockLease
{
    /** @var array<string, int> token => orderId */
    private static $active = [];

    /** @var string */
    private $token;

    /** @var int */
    private $orderId;

    private function __construct(string $token, int $orderId)
    {
        $this->token = $token;
        $this->orderId = $orderId;
    }

    /**
     * 在已 FOR UPDATE 的订单行上绑定租约。
     * 仅应由 StoreOrderSuccessServices::lockOrderAndIssueLease 调用。
     *
     * @param StoreOrderSuccessServices $issuer 类型约束：禁止其它服务伪造调用链
     */
    public static function bindFromLockedOrder(StoreOrderSuccessServices $issuer, int $orderId, array $lockedRow): self
    {
        // issuer 类型即授权边界：FOR UPDATE 必须已在 StoreOrderSuccessServices::lockOrderAndIssueLease 完成
        if (!$issuer instanceof StoreOrderSuccessServices) {
            throw new ValidateException('PaidOrderLockLease 仅允许支付成功服务绑定');
        }
        if ($orderId <= 0) {
            throw new ValidateException('订单不存在');
        }
        try {
            $pdo = Db::getPdo();
            if (!$pdo || !$pdo->inTransaction()) {
                throw new ValidateException('PaidOrderLockLease 必须在数据库事务内颁发');
            }
        } catch (ValidateException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ValidateException('PaidOrderLockLease 事务检测失败');
        }
        if ((int)($lockedRow['id'] ?? 0) !== $orderId) {
            throw new ValidateException('订单锁快照不一致');
        }

        $token = bin2hex(random_bytes(16));
        self::$active[$token] = $orderId;
        return new self($token, $orderId);
    }

    public function orderId(): int
    {
        return $this->orderId;
    }

    public function isValidFor(int $orderId): bool
    {
        if ($orderId <= 0 || $orderId !== $this->orderId) {
            return false;
        }
        return isset(self::$active[$this->token]) && self::$active[$this->token] === $orderId;
    }

    public function release(): void
    {
        unset(self::$active[$this->token]);
    }
}
