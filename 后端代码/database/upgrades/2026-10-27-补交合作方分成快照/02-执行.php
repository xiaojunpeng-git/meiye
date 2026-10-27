<?php
declare(strict_types=1);

use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\cashier\CashierV3MemberDebtProjectionServices;
use app\services\report\StoreReportPartnerCategorySnapshotServices;
use think\facade\Db;

/**
 * 为销售欠款补交增加不可变合作方分成快照，并只回填能核实为唯一平台分类的历史记录。
 * 默认只检查；必须显式传 --apply --database=当前库名。脚本不修改欠款本金、收款或余额。
 */
require dirname(__DIR__, 3) . '/vendor/autoload.php';
$root = dirname(__DIR__, 3) . '/';
$app = new think\App($root);
$app->env->load($root . '.env');
$app->initialize();
$args = getopt('', ['apply', 'database:']);
$database = (string)Db::query('SELECT DATABASE() db')[0]['db'];
if (!isset($args['database']) || (string)$args['database'] !== $database) {
    throw new RuntimeException('必须使用 --database=当前库名明确目标；本脚本不连接其他实例。');
}
$prefix = (string)Db::getConfig('connections.mysql.prefix');
$table = $prefix . 'cashier_v3_debt_repayment_item_settlement';
if (!preg_match('/^[a-zA-Z0-9_]+$/D', $table)) throw new RuntimeException('非法表名');
$definitions = [
    'cash_performance_amount_cents' => "bigint NOT NULL DEFAULT 0 COMMENT '本次补交现金业绩（分）'",
    'category_id_snapshot' => "bigint unsigned NOT NULL DEFAULT 0 COMMENT '补交时核实的平台分类'",
    'category_path_snapshot' => "varchar(512) NOT NULL DEFAULT '' COMMENT '补交时分类路径'",
    'partner_category_id_snapshot' => "bigint unsigned NOT NULL DEFAULT 0 COMMENT '合作方有效分类'",
    'partner_category_name_snapshot' => "varchar(128) NOT NULL DEFAULT '' COMMENT '合作方分类名称'",
    'partner_category_path_snapshot' => "varchar(512) NOT NULL DEFAULT '' COMMENT '合作方分类路径'",
    'partner_default_ratio_snapshot' => "tinyint unsigned NOT NULL DEFAULT 0 COMMENT '合作方比例快照'",
    'partner_config_version_snapshot' => "bigint unsigned NOT NULL DEFAULT 0 COMMENT '合作方配置版本快照'",
    'partner_share_amount_cents' => "bigint NOT NULL DEFAULT 0 COMMENT '补交合作方分成（分）'",
    'partner_snapshot_frozen_at' => "bigint unsigned NOT NULL DEFAULT 0 COMMENT '分成快照冻结时间'",
];
$existing = array_column(Db::query('SHOW COLUMNS FROM `' . $table . '`'), null, 'Field');
$added = [];
if (isset($args['apply'])) {
    foreach ($definitions as $column => $definition) {
        if (!isset($existing[$column])) {
            Db::execute('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition);
            $added[] = $column;
        }
    }
    // ThinkPHP caches table fields on first access. Force-refresh after ALTER so
    // the same idempotent invocation can safely write the newly added snapshots.
    if ($added !== []) Db::connect()->getSchemaInfo($table, true);
}
$schemaReady = isset($existing['partner_snapshot_frozen_at']) || isset($args['apply']);
$pending = $schemaReady ? (int)Db::name('cashier_v3_debt_repayment_item_settlement')->alias('settlement')
    ->join('cashier_v3_debt_repayment repayment', "repayment.tenant_id=settlement.tenant_id AND repayment.repayment_id=settlement.repayment_id AND repayment.status='succeeded'")
    ->where('settlement.partner_snapshot_frozen_at', 0)->count() : 0;
$backfilled = $unresolved = 0;
if (isset($args['apply']) && $pending > 0) {
    $repaymentIds = Db::name('cashier_v3_debt_repayment_item_settlement')->alias('settlement')
        ->join('cashier_v3_debt_repayment repayment', "repayment.tenant_id=settlement.tenant_id AND repayment.repayment_id=settlement.repayment_id AND repayment.status='succeeded'")
        ->where('settlement.partner_snapshot_frozen_at', 0)->distinct(true)->column('settlement.repayment_id');
    foreach ($repaymentIds as $repaymentId) {
        Db::transaction(function () use ($repaymentId, &$backfilled, &$unresolved): void {
            CashierV3TransactionGuard::assertInTransaction('supplementPartnerSnapshotBackfill');
            $repayment = (array)Db::name('cashier_v3_debt_repayment')->where('repayment_id', (string)$repaymentId)
                ->where('status', 'succeeded')->field('repayment_id,tenant_id,debt_id,settled_at')->lock(true)->find();
            if (!$repayment) return;
            $categories = array_values(array_filter(
                (array)((new CashierV3MemberDebtProjectionServices())->currentCategoriesForDebts([(int)$repayment['debt_id']])[(int)$repayment['debt_id']] ?? []),
                static fn(array $category): bool => (int)($category['id'] ?? 0) > 0
            ));
            $ids = array_values(array_unique(array_map(static fn(array $category): int => (int)$category['id'], $categories)));
            $exact = count($ids) === 1 ? $categories[0] : null;
            $resolver = new StoreReportPartnerCategorySnapshotServices();
            $rows = Db::name('cashier_v3_debt_repayment_item_settlement')->where('tenant_id', (string)$repayment['tenant_id'])
                ->where('repayment_id', (string)$repaymentId)->where('partner_snapshot_frozen_at', 0)->lock(true)->select()->toArray();
            foreach ($rows as $row) {
                $cash = (int)$row['amount_cents'] - (int)$row['balance_amount_cents'];
                if ($cash < 0) throw new RuntimeException('补交现金分摊为负，停止迁移：' . $repaymentId);
                $partner = $exact === null ? [
                    'partner_category_id_snapshot'=>0,'partner_category_name_snapshot'=>'','partner_category_path_snapshot'=>'',
                    'partner_default_ratio_snapshot'=>0,'partner_config_version_snapshot'=>0,'partner_share_amount_cents'=>0,
                ] : $resolver->resolveInTx((string)$repayment['tenant_id'], (int)$exact['id'], $cash);
                Db::name('cashier_v3_debt_repayment_item_settlement')->where('id', (int)$row['id'])->update(array_merge([
                    'cash_performance_amount_cents'=>$cash,'category_id_snapshot'=>(int)($exact['id'] ?? 0),
                    'category_path_snapshot'=>(string)($exact['path'] ?? ''),
                    'partner_snapshot_frozen_at'=>max(1, (int)$repayment['settled_at']),
                ], $partner));
                $backfilled++;
                if ($exact === null) $unresolved++;
            }
        });
    }
}
echo json_encode(['migration'=>'20261027-supplement-partner-snapshot','database'=>$database,
    'apply'=>isset($args['apply']),'added_columns'=>$added,'pending_before'=>$pending,
    'schema_ready'=>$schemaReady,'backfilled_rows'=>$backfilled,'unresolved_rows'=>$unresolved], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
