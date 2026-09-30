<?php
declare(strict_types=1);

/** Enforces one active member per phone with set-based reference rewrites. */
$arguments = array_fill_keys(array_slice($argv, 1), true);
if (!isset($arguments['--execute'], $arguments['--allow-ruihao'], $arguments['--after-import'])) {
    fwrite(STDERR, "Refusing to write without --execute --allow-ruihao --after-import\n");
    exit(2);
}
$env = parse_ini_file(getenv('RH_ENV_FILE') ?: '/www/wwwroot/rh.cc3798.com/.env', true, INI_SCANNER_RAW);
$database = $env['DATABASE'] ?? [];
$target = getenv('RH_TARGET_DATABASE') ?: ($database['DATABASE'] ?? '');
if ($target !== 'ruihao') throw new RuntimeException("target guard failed: $target");
$pdo = new PDO('mysql:host=' . $database['HOSTNAME'] . ';port=' . $database['HOSTPORT'] . ';dbname=ruihao;charset=utf8mb4', $database['USERNAME'], $database['PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

$pdo->exec('CREATE TEMPORARY TABLE tmp_rh_member_phone_merge(redundant_uid INT UNSIGNED NOT NULL PRIMARY KEY,master_uid INT UNSIGNED NOT NULL,KEY idx_master(master_uid)) ENGINE=InnoDB');
$pdo->exec('CREATE TEMPORARY TABLE tmp_rh_member_phone_total(master_uid INT UNSIGNED NOT NULL PRIMARY KEY,now_money DECIMAL(20,2) NOT NULL,ben_money DECIMAL(20,2) NOT NULL,give_money DECIMAL(20,2) NOT NULL) ENGINE=InnoDB');
$groups = $pdo->query("SELECT phone FROM eb_user WHERE phone<>'' GROUP BY phone HAVING COUNT(*)>1 ORDER BY phone")->fetchAll();
$members = $pdo->prepare("SELECT u.uid,u.now_money,u.ben_money,u.give_money,u.status,u.is_del,COALESCE(MAX(m.is_master),0) source_master,COUNT(DISTINCT o.id) order_count,COUNT(DISTINCT h.id) holder_count FROM eb_user u LEFT JOIN map_rh_customer m ON m.target_uid=u.uid LEFT JOIN eb_store_order o ON o.uid=u.uid LEFT JOIN eb_user_card_holder h ON h.uid=u.uid AND h.is_del=0 WHERE u.phone=? GROUP BY u.uid,u.now_money,u.ben_money,u.give_money,u.status,u.is_del");
$pair = $pdo->prepare('INSERT INTO tmp_rh_member_phone_merge(redundant_uid,master_uid) VALUES(?,?)');
$total = $pdo->prepare('INSERT INTO tmp_rh_member_phone_total(master_uid,now_money,ben_money,give_money) VALUES(?,?,?,?)');
foreach ($groups as $group) {
    $members->execute([(string)$group['phone']]);
    $rows = $members->fetchAll();
    usort($rows, static function (array $left, array $right): int {
        $leftScore = ((int)$left['source_master'] * 100000000) + ((int)$left['holder_count'] * 10000) + ((int)$left['order_count'] * 10) + (((float)$left['now_money'] > 0) ? 1 : 0);
        $rightScore = ((int)$right['source_master'] * 100000000) + ((int)$right['holder_count'] * 10000) + ((int)$right['order_count'] * 10) + (((float)$right['now_money'] > 0) ? 1 : 0);
        return $rightScore <=> $leftScore ?: ((int)$left['uid'] <=> (int)$right['uid']);
    });
    $master = array_shift($rows);
    if (!$master) continue;
    $now = (float)$master['now_money']; $ben = (float)$master['ben_money']; $give = (float)$master['give_money'];
    foreach ($rows as $row) {
        $pair->execute([(int)$row['uid'], (int)$master['uid']]);
        $now += (float)$row['now_money']; $ben += (float)$row['ben_money']; $give += (float)$row['give_money'];
    }
    if (round($now, 2) !== round($ben + $give, 2)) throw new RuntimeException('merged balance invariant failed for ' . $group['phone']);
    $total->execute([(int)$master['uid'], $now, $ben, $give]);
}

$references = [
    ['eb_cashier_v3_card_rule_state','member_id'], ['eb_cashier_v3_debt_authority','member_id'], ['eb_cashier_v3_presale_claimable_line','member_id'],
    ['eb_mobile_app_session','uid'], ['eb_store_debt','uid'], ['eb_store_order','uid'], ['eb_store_order_cart_info','uid'], ['eb_store_product_log','uid'], ['eb_user_card_holder','uid'],
    ['map_rh_customer','target_uid'], ['mig_jsj_20260917_member_resolution','target_uid'], ['mig_jsj_20260917_member_merge','target_uid'],
];
$pdo->beginTransaction();
try {
    // Store and member-store associations have (store_id,uid)/(uid) uniqueness, so retain the existing master row first.
    $pdo->exec('DELETE d FROM eb_store_user d JOIN tmp_rh_member_phone_merge m ON m.redundant_uid=d.uid JOIN eb_store_user k ON k.store_id=d.store_id AND k.uid=m.master_uid');
    $pdo->exec('UPDATE eb_store_user d JOIN tmp_rh_member_phone_merge m ON m.redundant_uid=d.uid SET d.uid=m.master_uid');
    $pdo->exec('DELETE d FROM eb_user_belong_store d JOIN tmp_rh_member_phone_merge m ON m.redundant_uid=d.uid JOIN eb_user_belong_store k ON k.uid=m.master_uid');
    $pdo->exec('UPDATE eb_user_belong_store d JOIN tmp_rh_member_phone_merge m ON m.redundant_uid=d.uid SET d.uid=m.master_uid');
    foreach ($references as [$table, $column]) {
        $pdo->exec("UPDATE `$table` d JOIN tmp_rh_member_phone_merge m ON m.redundant_uid=d.`$column` SET d.`$column`=m.master_uid");
    }
    $pdo->exec('UPDATE eb_user u JOIN tmp_rh_member_phone_total t ON t.master_uid=u.uid SET u.now_money=t.now_money,u.ben_money=t.ben_money,u.give_money=t.give_money');
    $pdo->exec("UPDATE eb_user u JOIN tmp_rh_member_phone_merge m ON m.redundant_uid=u.uid SET u.phone='',u.now_money=0,u.ben_money=0,u.give_money=0,u.status=0,u.is_del=1");
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $exception;
}
$duplicatePhones = (int)$pdo->query("SELECT COUNT(*) FROM (SELECT phone FROM eb_user WHERE phone<>'' GROUP BY phone HAVING COUNT(*)>1) x")->fetchColumn();
$invalidBalances = (int)$pdo->query('SELECT COUNT(*) FROM eb_user WHERE now_money<>ben_money+give_money')->fetchColumn();
if ($duplicatePhones !== 0 || $invalidBalances !== 0) throw new RuntimeException('post-merge verification failed');
echo json_encode(['merged_members'=>(int)$pdo->query('SELECT COUNT(*) FROM tmp_rh_member_phone_merge')->fetchColumn(),'duplicate_phones'=>$duplicatePhones,'invalid_balances'=>$invalidBalances], JSON_UNESCAPED_UNICODE), PHP_EOL;
