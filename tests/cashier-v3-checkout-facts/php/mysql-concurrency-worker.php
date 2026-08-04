<?php
declare(strict_types=1);

require_once __DIR__ . '/mysql-bootstrap.php';
require_once __DIR__ . '/fixture.php';

use app\services\cashier\v3\fact\CashierV3CheckoutFactPlanV1;
use app\services\cashier\v3\fact\ThinkPhpCashierV3CheckoutFactRepository;
use think\facade\Db;

checkoutFactMysqlBoot();
$mode = $argv[1] ?? '';
if ($mode === 'setup') {
    checkoutFactReset();
    checkoutFactInsertEvent(checkoutFactInput());
    echo "CHECKOUT_FACT_CONCURRENCY_SETUP\n";
    exit(0);
}
if ($mode !== 'write') {
    fwrite(STDERR, "mode must be setup or write\n");
    exit(2);
}

$plan = CashierV3CheckoutFactPlanV1::fromInternalAuthority(checkoutFactInput());
$repository = new ThinkPhpCashierV3CheckoutFactRepository();
$result = Db::transaction(static function () use ($repository, $plan): array {
    return $repository->persistInTx($plan, checkoutFactOperator(), checkoutFactScope());
});
echo json_encode($result, JSON_UNESCAPED_SLASHES) . "\n";
