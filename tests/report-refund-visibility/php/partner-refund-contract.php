<?php

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);
require dirname(__DIR__, 3) . '/后端代码/vendor/autoload.php';

function partnerRefundCheck(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
    echo "PASS {$message}\n";
}

$method = new ReflectionMethod(\app\services\report\StoreMemberConsumptionRefundReadServices::class,
    'partnerShareCents');
$reader = new \app\services\report\StoreMemberConsumptionRefundReadServices();
partnerRefundCheck($method->invoke($reader, -33300, 42, 30) === -9990,
    'refund reverses the original thirty-percent partner share');
partnerRefundCheck($method->invoke($reader, -1, 42, 50) === -1,
    'negative share rounds half-up at one cent');
partnerRefundCheck($method->invoke($reader, -33300, 0, 30) === 0,
    'unattributed refund cannot invent a partner share');
partnerRefundCheck($method->invoke($reader, -33300, 42, 0) === 0,
    'an original zero-percent partner ratio stays zero');

$readerSource = (string)file_get_contents(dirname(__DIR__, 3)
    . '/后端代码/app/services/report/StoreMemberConsumptionRefundReadServices.php');
$reportSource = (string)file_get_contents(dirname(__DIR__, 3)
    . '/后端代码/app/services/report/StoreUnifiedReportServices.php');
partnerRefundCheck(str_contains($readerSource, 'component_product_id=refund_cart.product_id')
    && str_contains($readerSource, 'partner_default_ratio_snapshot'),
    'partner filter and ratio use the refunded component snapshot');
partnerRefundCheck(str_contains($reportSource, "if ((int)\$refund['partner_category_id_snapshot'] <= 0")
    && str_contains($reportSource, "'sale_amount_cents'] = (int)\$refund['amount_cents']"),
    'partner reports append only verified dated negative refund facts');
