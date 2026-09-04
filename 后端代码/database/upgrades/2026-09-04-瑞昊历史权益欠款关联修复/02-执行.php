<?php
declare(strict_types=1);

/**
 * Run against xinruihao by default. Running against production ruihao requires
 * the explicit --allow-ruihao argument.
 * It intentionally keeps RH-DEBT-MIG technical orders: no delete occurs here.
 */

$envPath = getenv('MOHE_REPAIR_ENV_PATH') ?: '/var/www/html/.env';
$env = parse_ini_file($envPath, true);
if (!is_array($env)) {
    throw new RuntimeException('unable to read database environment: ' . $envPath);
}
$db = $env['DATABASE'] ?? [];
$database = (string)($db['DATABASE'] ?? '');
$allowRuihao = in_array('--allow-ruihao', $argv ?? [], true);
$skipBackup = in_array('--skip-backup', $argv ?? [], true);
if ($database !== 'xinruihao' && !($database === 'ruihao' && $allowRuihao)) {
    throw new RuntimeException('refuse: this repair is restricted to xinruihao; ruihao requires --allow-ruihao');
}

$pdo = new PDO(
    'mysql:host=' . $db['HOSTNAME'] . ';port=' . $db['HOSTPORT'] . ';dbname=' . $db['DATABASE'] . ';charset=utf8mb4',
    $db['USERNAME'],
    $db['PASSWORD'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$lockName = 'rh_card_debt_link_repair_' . $database;
if ((int)$pdo->query("SELECT GET_LOCK(" . $pdo->quote($lockName) . ", 30)")->fetchColumn() !== 1) {
    throw new RuntimeException('could not obtain migration lock');
}

try {
    if (!$skipBackup) {
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS eb_mig_rh_card_debt_link_backup (
  debt_id INT UNSIGNED NOT NULL,
  old_debt_order_id INT UNSIGNED NOT NULL,
  old_debt_order_sn VARCHAR(64) NOT NULL,
  debt_item_id INT UNSIGNED NOT NULL,
  old_item_order_id INT UNSIGNED NOT NULL,
  old_item_cart_info_id INT UNSIGNED NOT NULL,
  target_order_id INT UNSIGNED NOT NULL,
  target_card_cart_info_id INT UNSIGNED NOT NULL,
  old_target_order_debt DECIMAL(14,2) NOT NULL,
  old_target_order_repaid DECIMAL(14,2) NOT NULL,
  old_target_cart_debt DECIMAL(14,2) NOT NULL,
  old_target_cart_repaid DECIMAL(14,2) NOT NULL,
  backed_up_at INT UNSIGNED NOT NULL,
  PRIMARY KEY (debt_id),
  KEY idx_target_order (target_order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL
        );
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS eb_mig_rh_card_debt_link_repay_backup (
  repay_id INT UNSIGNED NOT NULL,
  debt_id INT UNSIGNED NOT NULL,
  old_order_id INT UNSIGNED NOT NULL,
  old_order_sn VARCHAR(64) NOT NULL,
  backed_up_at INT UNSIGNED NOT NULL,
  PRIMARY KEY (repay_id),
  KEY idx_debt_id (debt_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL
        );
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS eb_mig_rh_card_debt_link_authority_backup (
  authority_id BIGINT UNSIGNED NOT NULL,
  debt_id INT UNSIGNED NOT NULL,
  old_sales_order_id VARCHAR(128) NOT NULL,
  old_sales_order_no_snapshot VARCHAR(128) NOT NULL,
  backed_up_at INT UNSIGNED NOT NULL,
  PRIMARY KEY (authority_id),
  KEY idx_debt_id (debt_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL
        );
    }

    $pdo->exec('DROP TEMPORARY TABLE IF EXISTS tmp_rh_card_debt_link_repair');
    $pdo->exec(<<<'SQL'
CREATE TEMPORARY TABLE tmp_rh_card_debt_link_repair AS
SELECT
  d.id AS debt_id,
  d.order_id AS old_debt_order_id,
  MIN(h.oid) AS target_order_id,
  MIN(ci.id) AS target_card_cart_info_id,
  MIN(i.id) AS debt_item_id,
  COUNT(DISTINCT h.oid) AS target_order_count,
  COUNT(DISTINCT ci.id) AS target_card_cart_count,
  COUNT(DISTINCT i.id) AS debt_item_count
FROM eb_store_debt d
JOIN eb_store_debt_item i ON i.debt_id=d.id
JOIN eb_user_card_holder h
  ON h.uid=d.uid
 AND h.product_id=i.product_id
 AND h.product_type=i.product_type
 AND h.is_del=0
JOIN eb_store_order_cart_info ci
  ON ci.oid=h.oid
 AND ci.product_id=i.product_id
 AND ci.product_type=i.product_type
WHERE d.debt_no LIKE 'RHD%'
  AND d.remark LIKE 'source_key=CARD:%'
  AND d.total_debt>=0
  AND d.repaid_debt>=0
  AND d.total_debt>=d.repaid_debt
  AND d.order_id<>h.oid
  AND ci.cart_info LIKE CONCAT('%card_detail_id=', SUBSTRING_INDEX(d.remark,'CARD:',-1), '%')
GROUP BY d.id
HAVING COUNT(DISTINCT h.oid)=1
   AND COUNT(DISTINCT ci.id)=1
   AND COUNT(DISTINCT i.id)=1
SQL
    );
    $pdo->exec('ALTER TABLE tmp_rh_card_debt_link_repair ADD PRIMARY KEY(debt_id), ADD UNIQUE KEY uk_target_order(target_order_id)');
    $pdo->exec(<<<'SQL'
DELETE c
FROM tmp_rh_card_debt_link_repair c
JOIN eb_store_debt other
  ON other.order_id=c.target_order_id
 AND other.id<>c.debt_id
SQL
    );

    $candidateCount = (int)$pdo->query('SELECT COUNT(*) FROM tmp_rh_card_debt_link_repair')->fetchColumn();
    if ($candidateCount === 0) {
        echo json_encode(['status' => 'noop', 'updated_debts' => 0], JSON_UNESCAPED_UNICODE) . PHP_EOL;
        return;
    }

    $pdo->beginTransaction();
    if (!$skipBackup) {
        $pdo->exec(<<<'SQL'
INSERT IGNORE INTO eb_mig_rh_card_debt_link_backup
  (debt_id,old_debt_order_id,old_debt_order_sn,debt_item_id,old_item_order_id,old_item_cart_info_id,
   target_order_id,target_card_cart_info_id,old_target_order_debt,old_target_order_repaid,
   old_target_cart_debt,old_target_cart_repaid,backed_up_at)
SELECT d.id,d.order_id,d.order_sn,i.id,i.order_id,i.cart_info_id,
       c.target_order_id,c.target_card_cart_info_id,o.debt_amount,o.repaid_debt_amount,
       ci.debt_amount,ci.repaid_debt_amount,UNIX_TIMESTAMP()
FROM tmp_rh_card_debt_link_repair c
JOIN eb_store_debt d ON d.id=c.debt_id
JOIN eb_store_debt_item i ON i.id=c.debt_item_id
JOIN eb_store_order o ON o.id=c.target_order_id
JOIN eb_store_order_cart_info ci ON ci.id=c.target_card_cart_info_id
SQL
        );
        $pdo->exec(<<<'SQL'
INSERT IGNORE INTO eb_mig_rh_card_debt_link_repay_backup
  (repay_id,debt_id,old_order_id,old_order_sn,backed_up_at)
SELECT r.id,r.debt_id,r.order_id,r.order_sn,UNIX_TIMESTAMP()
FROM eb_store_debt_repay r
JOIN tmp_rh_card_debt_link_repair c ON c.debt_id=r.debt_id
SQL
        );
        $pdo->exec(<<<'SQL'
INSERT IGNORE INTO eb_mig_rh_card_debt_link_authority_backup
  (authority_id,debt_id,old_sales_order_id,old_sales_order_no_snapshot,backed_up_at)
SELECT a.id,a.debt_id,a.sales_order_id,a.sales_order_no_snapshot,UNIX_TIMESTAMP()
FROM eb_cashier_v3_debt_authority a
JOIN tmp_rh_card_debt_link_repair c ON c.debt_id=a.debt_id
SQL
        );
    }

    $pdo->exec(<<<'SQL'
UPDATE eb_store_debt d
JOIN tmp_rh_card_debt_link_repair c ON c.debt_id=d.id
JOIN eb_store_order o ON o.id=c.target_order_id
SET d.order_id=c.target_order_id,
    d.order_sn=LEFT(o.order_id,32),
    d.update_time=UNIX_TIMESTAMP()
SQL
    );
    $pdo->exec(<<<'SQL'
UPDATE eb_store_debt_item i
JOIN tmp_rh_card_debt_link_repair c ON c.debt_item_id=i.id
SET i.order_id=c.target_order_id,
    i.cart_info_id=c.target_card_cart_info_id,
    i.update_time=UNIX_TIMESTAMP()
SQL
    );
    $pdo->exec(<<<'SQL'
UPDATE eb_store_order o
JOIN tmp_rh_card_debt_link_repair c ON c.target_order_id=o.id
JOIN eb_store_debt d ON d.id=c.debt_id
SET o.debt_amount=d.total_debt,
    o.repaid_debt_amount=d.repaid_debt
SQL
    );
    $pdo->exec(<<<'SQL'
UPDATE eb_store_order_cart_info ci
JOIN tmp_rh_card_debt_link_repair c ON c.target_card_cart_info_id=ci.id
JOIN eb_store_debt d ON d.id=c.debt_id
SET ci.debt_amount=d.total_debt,
    ci.repaid_debt_amount=d.repaid_debt
SQL
    );
    $pdo->exec(<<<'SQL'
UPDATE eb_store_debt_repay r
JOIN tmp_rh_card_debt_link_repair c ON c.debt_id=r.debt_id
JOIN eb_store_order o ON o.id=c.target_order_id
SET r.order_id=c.target_order_id,
    r.order_sn=LEFT(o.order_id,32)
SQL
    );
    $pdo->exec(<<<'SQL'
UPDATE eb_cashier_v3_debt_authority a
JOIN tmp_rh_card_debt_link_repair c ON c.debt_id=a.debt_id
JOIN eb_store_order o ON o.id=c.target_order_id
SET a.sales_order_id=LEFT(o.order_id,64),
    a.sales_order_no_snapshot=LEFT(o.order_id,64),
    a.updated_at=UNIX_TIMESTAMP()
SQL
    );
    $pdo->commit();
    echo json_encode(['status' => 'ok', 'updated_debts' => $candidateCount], JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
} finally {
    $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lockName) . ')');
}
