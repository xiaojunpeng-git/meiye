<?php
/**
 * Legacy service import contract: a completed work's linked item snapshots
 * supply its visible names, while card-only works must not repeat a fake card
 * source or fabricate a different performed project.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../scripts/import/customer_008_morestore_service_history.php';

function expectHistory(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$card = ['id' => 84774, 'buy_num' => 1, 'cart_info' => json_encode([
    'product_type' => 4, 'productInfo' => ['product_type' => 4, 'store_name' => '面部卡（单项）'],
], JSON_UNESCAPED_UNICODE)];
$projectCard = ['id' => 84775, 'buy_num' => 1, 'cart_info' => json_encode([
    'product_type' => 4, 'productInfo' => ['product_type' => 4, 'store_name' => '王牌 · 胶原瓷光肌'],
], JSON_UNESCAPED_UNICODE)];
$work = ['tmp_sn' => 'tmp777550704683253760', 'order_id' => 0];
[$name, $source, $times] = serviceHistoryProject($work, [
    's:' . $work['tmp_sn'] => [$card, $projectCard],
]);
expectHistory($name === '面部卡（单项）、王牌 · 胶原瓷光肌', 'PoPo old work item names changed');
expectHistory($source === '', 'Card-only old work must not repeat item names as card source');
expectHistory($times === 2, 'Two consumed old work items must retain their quantity');

$project = ['id' => 9, 'buy_num' => 1, 'cart_info' => json_encode([
    'product_type' => 0, 'productInfo' => ['product_type' => 0, 'store_name' => '护理项目'],
], JSON_UNESCAPED_UNICODE)];
[$name, $source, $times] = serviceHistoryProject($work, [
    's:' . $work['tmp_sn'] => [$card, $project],
]);
expectHistory($name === '护理项目' && $source === '面部卡（单项）' && $times === 1,
    'Mixed card and project must display the project with its card source');

[$name, $source, $times] = serviceHistoryProject(['tmp_sn' => '', 'order_id' => 33], []);
expectHistory($name === '服务项目未记录' && $source === '' && $times === 1,
    'Missing old item details must be explicit rather than invented');

// PDO returns MySQL integer columns as strings. The source and target must
// still produce identical row hashes so online verification detects real drift.
$sourceRow = [
    'source_work_id' => 51080, 'source_member_id' => 421, 'member_id' => 421,
    'source_store_id' => 46, 'store_name_snapshot' => '苏州东环路店',
    'project_name_snapshot' => '面部卡（单项）、王牌 · 胶原瓷光肌',
    'source_card_name_snapshot' => '', 'quantity' => 2,
    'craftsman_name_snapshot' => '', 'business_date' => '2026-04-21',
    'completed_at' => 1776754404,
];
$targetRow = array_map('strval', $sourceRow);
expectHistory(serviceHistoryCanonical($sourceRow) === serviceHistoryCanonical($targetRow),
    'Source and target integer representations must hash identically');

echo "service-history-contract PASS\n";
