<?php
declare(strict_types=1);

require __DIR__ . '/mysql-fixture.php';

use app\services\cashier\v3\settlement\CashierV3CheckoutSettlementKernel;
use app\services\cashier\v3\settlement\ThinkPhpCashierV3CheckoutRequestRepository;
use think\facade\Db;

$label = $argv[1] ?? '';
$coord = getenv('CHECKOUT_REQUEST_CONCURRENCY_DIR') ?: '';
if ($label === '' || $coord === '' || !is_dir($coord)) {
    fwrite(STDERR, "CHECKOUT_WORKER_ARGUMENTS_INVALID\n");
    exit(2);
}
file_put_contents($coord . '/ready-' . $label, (string)getmypid());
$deadline = microtime(true) + 30.0;
while (!is_file($coord . '/release')) {
    if (microtime(true) >= $deadline) {
        fwrite(STDERR, "CHECKOUT_WORKER_BARRIER_TIMEOUT label={$label}\n");
        exit(3);
    }
    usleep(20000);
}

$repository = new ThinkPhpCashierV3CheckoutRequestRepository();
$operator = checkoutAuthorityOperator();
$scope = checkoutAuthorityScope();
$command = checkoutAuthorityCommand(900);
$snapshot = checkoutAuthoritySnapshot(1);
$sources = checkoutAuthoritySources([
    ['service_order', 'SO-CONCURRENT', 'service_origin'],
]);
$secret = 'checkout-authority-concurrency-secret-32-bytes';

try {
    $result = Db::transaction(static function () use (
        $repository,
        $operator,
        $scope,
        $command,
        $snapshot,
        $sources,
        $secret
    ): array {
        $current = $repository->lockCurrentForKernelInTx(
            '',
            $command['idempotencyKey'],
            $operator,
            $scope
        );
        $plan = CashierV3CheckoutSettlementKernel::saveDraft(
            $command,
            $snapshot,
            $current,
            $secret
        );
        return $repository->persistKernelPlanInTx($plan, $sources, $operator, $scope);
    });
    echo 'CHECKOUT_WORKER_RESULT=' . json_encode([
        'ok' => true,
        'label' => $label,
        'requestId' => $result['requestId'],
        'requestVersion' => $result['requestVersion'],
        'replayed' => $result['replayed'],
        'persistenceMode' => $result['persistenceMode'],
    ], JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
} catch (Throwable $throwable) {
    fwrite(STDERR, 'CHECKOUT_WORKER_FAILURE=' . get_class($throwable)
        . ':' . $throwable->getMessage() . "\n");
    exit(1);
}
