<?php
/** 写模式必须忽略只读投影缓存，继续走欠款和共享池权威读取。 */
declare(strict_types=1);

namespace app\services {
    class BaseServices {}
}

namespace app\services\cashier\v3\card {
    final class CashierV3CardRuleEntitlementAuthorityServices
    {
        public static $calls = 0;
        public function debtLimitForDetail(array $detail, array $order, bool $lock = true): ?int
        {
            ++self::$calls;
            return 2;
        }
    }
}

namespace {
    require_once __DIR__.'/../../../后端代码/app/services/order/store/WriteOffOrderServices.php';
    use app\services\order\store\WriteOffOrderServices;
    use app\services\cashier\v3\card\CashierV3CardRuleEntitlementAuthorityServices as Rule;

    /** Substitute only the external debt fetch to inspect the public guard. */
    final class ProbeWriteOff extends WriteOffOrderServices
    {
        public $cancelledArguments = [];
        public function __construct() {}
        protected function resolveCartPendingDebtForWriteoff(array $cartRow, float $orderPendingDebt = 0,
            array $orderInfo = [], array $orderCartRows = [], bool $allowRowLock = true,
            ?string $cancelledDebt = null): float
        {
            $this->cancelledArguments[] = ['lock'=>$allowRowLock,'cancelled'=>$cancelledDebt];
            return 0.0;
        }
    }

    $service = new ProbeWriteOff();
    $cart = ['id'=>7,'oid'=>101,'write_surplus_times'=>5,'cart_info'=>'{}'];
    $order = ['id'=>101,'uid'=>9];
    // The deliberately wrong cached pool limit/amount would change a write
    // result if accepted. Write mode must call its authority and get 2.
    $write = $service->calcEffectiveWriteSurplusTimes($cart,0.0,$order,'50.00',[],true,
        '999.00','999.00',1,true);
    if ($write !== 2 || Rule::$calls !== 1
        || $service->cancelledArguments[0] !== ['lock'=>true,'cancelled'=>null]) {
        throw new \RuntimeException('write mode reused a read-only override');
    }
    // Explicit read mode may reuse the verified limit and cancelled amount.
    $read = $service->calcEffectiveWriteSurplusTimes($cart,0.0,$order,'50.00',[],false,
        '0.00','3.00',1,true);
    if ($read !== 1 || Rule::$calls !== 1
        || $service->cancelledArguments[1] !== ['lock'=>false,'cancelled'=>'3.00']) {
        throw new \RuntimeException('read mode did not reuse its verified override');
    }
    $service->usableEntitlementAmount($cart,$order,'50.00',0.0,[],true,'999.00');
    $service->usableEntitlementAmount($cart,$order,'50.00',0.0,[],false,'3.00');
    if ($service->cancelledArguments[2] !== ['lock'=>true,'cancelled'=>null]
        || $service->cancelledArguments[3] !== ['lock'=>false,'cancelled'=>'3.00']) {
        throw new \RuntimeException('usable amount bypassed write debt recheck');
    }
    echo "round4-writeoff-read-override-guard-contract PASS\n";
}
