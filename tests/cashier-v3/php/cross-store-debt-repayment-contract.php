<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$repayment = file_get_contents($root . '/后端代码/app/services/cashier/v3/settlement/CashierV3DebtRepaymentServices.php');
$projection = file_get_contents($root . '/后端代码/app/services/cashier/v3/cashier/CashierV3MemberDebtProjectionServices.php');
$provider = file_get_contents($root . '/后端代码/app/services/cashier/v3/cashier/CashierV3EntitlementResourceVersionProvider.php');
$void = file_get_contents($root . '/后端代码/app/services/cashier/v3/order/CashierV3OrderCenterVoidServices.php');
$shell = file_get_contents($root . '/前端代码/cashier-v3/src/layouts/CashierShell.vue');
$bridge = file_get_contents($root . '/前端代码/cashier-v3/src/services/cashierV3Bridge.js');
$module = file_get_contents($root . '/后端代码/app/services/cashier/v3/cashier/CashierV3CashierModule.php');

function requireContains(string $source, string $needle, string $message): void
{
    if (strpos($source, $needle) === false) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function requireNotContains(string $source, string $needle, string $message): void
{
    if (strpos($source, $needle) !== false) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

requireContains($repayment, 'CashierV3CrossStoreEntitlementPolicy::enabled()', '还款必须以跨店策略判断来源门店。');
requireContains($repayment, '$sourceStoreId = (int)($authority[\'store_id\'] ?? 0);', '还款必须以欠款权威记录的门店作为来源。');
requireContains($repayment, '(int)$debt[\'store_id\'] !== $sourceStoreId', '还款必须校验欠款与权威来源一致。');
requireContains($repayment, '(int)$order[\'store_id\'] !== $sourceStoreId', '还款必须校验销售订单与来源一致。');
requireContains($repayment, '(int)$row[\'store_id\'] !== $sourceStoreId', '人员快照必须按来源门店校验。');
requireNotContains($repayment, '(int)$authority[\'store_id\'] !== $operator->storeId()', '还款不得把来源门店误判为当前收款门店。');
requireNotContains($repayment, '(int)$debt[\'store_id\'] !== $operator->storeId()', '还款不得要求跨店欠款属于当前收款门店。');
requireContains($repayment, 'Historical migration rows can legitimately have a zero order', '历史欠款还款必须允许缺失订单投影。');
requireContains($repayment, '$isMigrationAuthority = $salesOrderRecordId === 0;', '历史迁移欠款必须支持零订单记录 ID。');
requireContains($repayment, '($isMigrationAuthority && (int)$debt[\'order_id\'] <= 0)', '零订单记录 ID 仍必须绑定既有欠款主记录。');
requireContains($repayment, 'if (!$items || ($order &&', '订单投影缺失时仍须保留欠款明细和权威映射校验。');
requireContains($repayment, "if ((int)(\$authority['sales_order_record_id'] ?? 0) === 0 && !\$lines)", '迁移欠款可按当前权威快照补交，不依赖虚构的销售行。');
requireContains($repayment, "'migrationDebtWithoutV3SaleLine'", '迁移欠款的人员快照指纹必须稳定。');
requireContains($repayment, "if (!\$hasOriginalLine) {\n                return;", '迁移欠款不得虚构原销售行的收款分摊。');

requireContains($projection, 'a.store_id AS authority_store_id', '欠款明细必须读取权威来源门店。');
requireContains($projection, 'a.member_id AS authority_member_id', '欠款明细必须读取权威会员。');
requireContains($projection, 'historical migrations have the authority row but no longer retain a', '欠款明细不得因为历史订单投影缺失而隐藏权威欠款。');
requireContains($projection, '($salesOrderRecordId === 0 && $debtOrderId > 0)', '欠款明细必须识别零订单记录 ID 的迁移权威映射。');
requireContains($projection, '|| (int)($row[\'v3_order_member_id\'] ?? 0) === $memberId', '存在订单投影时仍必须校验会员归属。');

$debtBlockStart = strpos($provider, 'if ($kind === \'debt_record\')');
$debtBlockEnd = strpos($provider, "        \$query = Db::name('store_order_cart_info')", $debtBlockStart);
$debtBlock = substr($provider, $debtBlockStart, $debtBlockEnd - $debtBlockStart);
requireContains($debtBlock, 'a.store_id AS authority_store_id', '资源发现必须返回欠款权威来源门店。');
requireContains($debtBlock, 'd.store_id AS debt_store_id', '资源发现必须返回欠款记录来源门店。');
requireContains($debtBlock, 'allowsSourceStore((int)$debt[\'authority_store_id\']', '资源发现必须按来源门店执行跨店策略。');
requireNotContains($debtBlock, '->where(\'a.store_id\', $operatorScope->storeId())', '资源发现不得先按当前门店过滤跨店欠款。');

requireContains($void, '->where(\'store_id\', $operator->storeId())->lock(true)->find();', '作废必须仍锁定当前收款门店的补交记录。');
requireContains($void, 'CashierV3CrossStoreEntitlementPolicy::enabled()', '作废必须按跨店策略校验原欠款来源。');
requireNotContains($void, "Db::name('store_debt')->where('id', (int)\$row['debt_id'])->where('store_id', \$operator->storeId())", '作废不得要求原欠款属于当前收款门店。');

requireContains($bridge, "action === 'prepare-debt-repayment' || action === 'submit-debt-repayment'", '欠款补交准备和提交不得依赖浏览器版本上下文。');
requireContains($module, "'prepare-debt-repayment',\n            []", '欠款补交准备策略不得要求浏览器版本。');
requireContains($module, "'submit-debt-repayment',\n            []", '欠款补交提交策略不得要求浏览器版本。');
requireContains($module, "'checkoutSnapshot' => \$checkoutSnapshot", '欠款补交必须返回本次持久化的唯一结账快照。');
requireNotContains($shell, 'const currentCheckout = state.cashier?.checkout', '欠款补交不得从浏览器根状态恢复旧结账快照。');
requireContains($repayment, 'latestSnapshotVersion($debt)', '欠款补交准备必须使用刚锁定的最新欠款快照。');

$debtOpenStart = strpos($shell, 'async function openMemberDebt(memberId = null)');
$debtOpenEnd = strpos($shell, 'async function handleOpenMemberDebt', $debtOpenStart);
$debtOpenBlock = substr($shell, $debtOpenStart, $debtOpenEnd - $debtOpenStart);
requireContains($debtOpenBlock, 'preserveRootState: true', '欠款明细读取只能覆盖最新欠款快照，不能重置当前收银会员。');
requireContains($shell, 'silent: true', '欠款补交准备失败不得清空已打开的欠款明细。');
requireContains($shell, "requestCashierV3Action('query-cashier-member-summary', {\n        memberId,\n        // 选客后的摘要只补齐余额/欠款；不能以查询回包的默认游客根状态\n        // 覆盖浏览器正在编辑的收银客户。\n        preserveRootState: true", '选客摘要读取不能重置当前收银会员。');

echo "PASS cross-store debt repayment contract\n";
