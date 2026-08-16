<?php
$root = dirname(__DIR__, 3);
$service = file_get_contents($root . '/后端代码/app/services/fund/StoreFundServices.php');
$scope = file_get_contents($root . '/后端代码/app/services/fund/FundPlatformScopeServices.php');
$migration = file_get_contents($root . '/后端代码/database/upgrades/2026-08-15-门店费用资金管理/02-正式升级.sql');
foreach (['store_fund_subject','store_fund_document','store_fund_document_line','TEAM_BUILDING'] as $needle) if (strpos($migration, $needle) === false) throw new RuntimeException('migration missing '.$needle);
foreach (["'APPROVED'", "'DRAFT'", 'function reverse', 'source_document_id', 'amount_cents', 'assertDocumentOperator', 'fund_document_already_reversed'] as $needle) if (strpos($service, $needle) === false) throw new RuntimeException('service missing '.$needle);
if (strpos($service, "'l.subject_type_snapshot'") === false) throw new RuntimeException('ledger subject type filter missing');
if (strpos($service, 'function openingBalance') === false || strpos($service, "'opening_balance'") === false) throw new RuntimeException('ledger opening balance missing');
foreach (['income_amount_cents', 'expense_amount_cents', 'net_amount_cents', 'function exportLedger', 'function exportReport', 'function writeXlsx', 'function exportFilePath', 'ExportServices::class', "'xlsx'"] as $needle) if (strpos($service, $needle) === false) throw new RuntimeException('fund report/export missing '.$needle);
if (substr_count($service, '$withStore=true;') < 2 || strpos($service, "['key'=>'store_name','label'=>'门店']") === false) throw new RuntimeException('fund export missing store column');
if (strpos($scope, 'selectedStoreScope') === false || strpos($scope, 'pickerTree') === false || strpos($scope, 'fund_store_required') === false) throw new RuntimeException('platform fund store scope missing');
echo "fund-service-contract: PASS\n";
