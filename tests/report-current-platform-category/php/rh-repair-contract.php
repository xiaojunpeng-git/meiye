<?php

declare(strict_types=1);

// Pure contract checks: no database connection and no production mutation.
putenv('RH_REPAIR_LIB_ONLY=1');
require dirname(__DIR__, 3) . '/后端代码/database/upgrades/2026-10-13-瑞昊商品分类与卡项分摊修复/02-修复.php';

function rhCheck(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
    echo 'PASS ', $message, PHP_EOL;
}

rhCheck(rhAllocate(1, ['project:9' => 1, 'project:2' => 1])
    === ['project:9' => 0, 'project:2' => 1], 'cent remainder follows stable project key');
rhCheck(rhAllocate(168000, ['receipt-a' => 1, 'receipt-b' => 1])
    === ['receipt-a' => 84000, 'receipt-b' => 84000], 'sale is conserved across receipts');
rhCheck(array_sum(rhAllocate(70000, ['project:1' => 100, 'project:2' => 300])) === 70000,
    'original checkout cash is conserved across items');

$categories = [26302 => ['pid' => 0, 'cate_name' => '生美'],
    26332 => ['pid' => 26302, 'cate_name' => '卡项']];
rhCheck(rhCategoryPath(26332, $categories) === '生美 / 卡项', 'current platform path resolves');
rhCheck(rhCategoryPath(26312, $categories) === '', 'invalid platform category stays blank');

$sale = ['fact_id' => 'CFS-test', 'tenant_id' => '0', 'organization_id' => '1',
    'store_id' => 1, 'member_id' => 2, 'order_id' => 'SO-test', 'source_line_id' => 'line-test'];
$allocation = ['issue_no' => 1, 'category_id' => 26332, 'product_id' => 97181,
    'receipt_id' => 'receipt-test'];
$categoryFact = rhFactBase($sale, $allocation, 'category');
$itemFact = rhFactBase($sale, $allocation, 'item');
rhCheck($categoryFact['natural_key'] === 'card-sale-category:CFS-test:1:26332'
    && $itemFact['natural_key'] === 'card-sale-item:CFS-test:1:97181',
    'repair uses writer-compatible category and item natural keys');
rhCheck($categoryFact['component_product_id'] === 97181,
    'category fact retains the receipt component product ID');
