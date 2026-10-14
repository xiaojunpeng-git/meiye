<?php
declare(strict_types=1);

// Run in the local cashier backend container; this probe reads the existing refund only.
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();

use app\services\report\StoreUnifiedReportServices;
use think\facade\Db;

if ((string)Db::query('SELECT DATABASE() db')[0]['db'] !== 'ruihao') {
    throw new RuntimeException('This probe is restricted to the local ruihao database.');
}
$input = ['report' => 'member_consumption_detail', 'start_date' => '2026-10-13',
    'end_date' => '2026-10-13', 'page' => 1, 'page_size' => 20];
$service = new StoreUnifiedReportServices();
$page = $service->query(23, $input);
$export = $service->export(23, $input);
foreach (['page' => $page, 'export' => $export] as $kind => $result) {
    $rows = array_values(array_filter($result['records'], static function (array $row): bool {
        return (string)($row['order_no_snapshot'] ?? '') === 'TK2610130001';
    }));
    if (count($rows) !== 1 || (string)($rows[0]['sales_manager_name'] ?? '') !== '李倩'
        || (string)($rows[0]['guide_names'] ?? '') !== '李倩、江慧芳'
        || (string)($rows[0]['guide_round_no'] ?? '') !== '') {
        throw new RuntimeException("{$kind} must show the refund's latest manager and guides without a guide round.");
    }
    echo "PASS {$kind} shows TK2610130001 manager 李倩 and guides 李倩、江慧芳\n";
}
$otherStore = $service->query(126, $input);
if (in_array('TK2610130001', array_column($otherStore['records'], 'order_no_snapshot'), true)) {
    throw new RuntimeException('Another store must not read the refund.');
}
echo "PASS store scope stays isolated\n";
