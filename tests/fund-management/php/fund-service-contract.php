<?php
$root = dirname(__DIR__, 3);
$service = file_get_contents($root . '/后端代码/app/services/fund/StoreFundServices.php');
$scope = file_get_contents($root . '/后端代码/app/services/fund/FundPlatformScopeServices.php');
$migration = file_get_contents($root . '/后端代码/database/upgrades/2026-08-15-门店费用资金管理/02-正式升级.sql');
foreach (['store_fund_subject','store_fund_document','store_fund_document_line','TEAM_BUILDING'] as $needle) if (strpos($migration, $needle) === false) throw new RuntimeException('migration missing '.$needle);
foreach (["'APPROVED'", "'DRAFT'", 'function reverse', 'source_document_id', 'amount_cents', 'assertDocumentOperator', 'fund_document_already_reversed'] as $needle) if (strpos($service, $needle) === false) throw new RuntimeException('service missing '.$needle);
// 单据列表必须批量读取当前页明细，避免逐单查询并保护“按单据分页”的口径。
if (strpos($service, "whereIn('document_id', \$documentIds)") === false || strpos($service, "\$row['lines']") === false || strpos($service, "\$row['line_count']") === false) throw new RuntimeException('grouped document lines missing');
if (strpos($service, 'private const DOCUMENT_PAGE_SIZE = 100') === false || strpos($service, '$limit=self::DOCUMENT_PAGE_SIZE') === false) throw new RuntimeException('document page size must be fixed at 100 by server');
if (strpos($service, 'private const LEDGER_PAGE_SIZE = 100') === false || strpos($service, '$limit=self::LEDGER_PAGE_SIZE') === false || strpos($service, '$this->ledger($scope,$filters,false)') === false) throw new RuntimeException('ledger page size or full export boundary missing');
if (strpos($service, "'l.subject_type_snapshot'") === false) throw new RuntimeException('ledger subject type filter missing');
if (strpos($service, 'function openingBalance') === false || strpos($service, "'opening_balance'") === false) throw new RuntimeException('ledger opening balance missing');
foreach (['income_amount_cents', 'expense_amount_cents', 'net_amount_cents', 'function exportLedger', 'function exportReport', 'function writeXlsx', 'function exportFilePath', 'ExportServices::class', "'xlsx'"] as $needle) if (strpos($service, $needle) === false) throw new RuntimeException('fund report/export missing '.$needle);
if (substr_count($service, '$withStore=true;') < 2 || strpos($service, "['key'=>'store_name','label'=>'门店']") === false) throw new RuntimeException('fund export missing store column');
if (strpos($scope, 'selectedStoreScope') === false || strpos($scope, 'pickerTree') === false || strpos($scope, 'fund_store_required') === false) throw new RuntimeException('platform fund store scope missing');
echo "fund-service-contract: PASS\n";
