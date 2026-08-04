<?php
declare(strict_types=1);

namespace think\facade {
    final class Db
    {
        /** @var object|null */
        public static $connection;

        public static function connect()
        {
            return self::$connection;
        }
    }
}

namespace app\services\cashier\v3 {
    final class CashierV3ResultCode
    {
        public const COMMAND_TRANSACTION_REQUIRED = 'COMMAND_TRANSACTION_REQUIRED';
        public const STATUS_FAILED = 'failed';
    }

    final class CashierV3CommandException extends \RuntimeException
    {
        public function __construct(...$arguments)
        {
            parent::__construct((string)($arguments[1] ?? ''));
        }
    }
}

namespace {
    $backendRoot = dirname(__DIR__, 3) . '/后端代码';
    require_once $backendRoot . '/app/services/cashier/v3/CashierV3TransactionGuard.php';

    final class TransactionGuardProbePdo extends \PDO
    {
        /** @var bool */
        private $active;

        public function __construct(bool $active)
        {
            $this->active = $active;
        }

        public function inTransaction(): bool
        {
            return $this->active;
        }
    }

    final class TransactionGuardDynamicProxy
    {
        /** @var \PDO */
        private $pdo;

        public function __construct(\PDO $pdo)
        {
            $this->pdo = $pdo;
        }

        public function __call(string $method, array $arguments)
        {
            if ($method === 'getPdo') {
                return $this->pdo;
            }
            throw new \BadMethodCallException($method);
        }
    }

    final class TransactionGuardUnavailableProxy
    {
        public function __call(string $method, array $arguments)
        {
            throw new \BadMethodCallException($method);
        }
    }

    $passed = 0;
    $failed = 0;
    $check = static function (string $name, bool $condition) use (&$passed, &$failed): void {
        if ($condition) {
            $passed++;
            echo '[PASS] ' . $name . PHP_EOL;
            return;
        }
        $failed++;
        echo '[FAIL] ' . $name . PHP_EOL;
    };

    $activeProxy = new TransactionGuardDynamicProxy(new TransactionGuardProbePdo(true));
    $check(
        'TX-GUARD-01 Swoole-style proxy does not advertise getPdo directly',
        !method_exists($activeProxy, 'getPdo')
    );
    \think\facade\Db::$connection = $activeProxy;
    $check(
        'TX-GUARD-02 dynamic proxy delegates to an active underlying PDO transaction',
        \app\services\cashier\v3\CashierV3TransactionGuard::isInTransaction() === true
    );

    \think\facade\Db::$connection = new TransactionGuardDynamicProxy(new TransactionGuardProbePdo(false));
    $check(
        'TX-GUARD-03 inactive underlying PDO remains rejected',
        \app\services\cashier\v3\CashierV3TransactionGuard::isInTransaction() === false
    );

    \think\facade\Db::$connection = new TransactionGuardUnavailableProxy();
    $check(
        'TX-GUARD-04 an unavailable proxy fails closed',
        \app\services\cashier\v3\CashierV3TransactionGuard::isInTransaction() === false
    );

    echo sprintf('transaction-guard-swoole-proxy-contract: %d passed, %d failed', $passed, $failed) . PHP_EOL;
    exit($failed > 0 ? 1 : 0);
}
