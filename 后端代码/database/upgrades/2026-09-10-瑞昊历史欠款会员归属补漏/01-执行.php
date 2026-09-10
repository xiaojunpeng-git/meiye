<?php
declare(strict_types=1);

/**
 * Repair legacy JSJ debts missed because the previous importer restricted the
 * originating card shop. Run with no argument for a read-only precheck.
 */

$execute = in_array('--execute', $argv ?? [], true);
$allowRuihao = in_array('--allow-ruihao', $argv ?? [], true);
$envPath = getenv('MOHE_REPAIR_ENV_PATH') ?: '/var/www/html/.env';
$env = parse_ini_file($envPath, true);
if (!is_array($env)) {
    throw new RuntimeException('unable to read database environment: ' . $envPath);
}
$db = $env['DATABASE'] ?? [];
$database = (string)($db['DATABASE'] ?? '');
if ($database !== 'xinruihao' && !($database === 'ruihao' && $allowRuihao)) {
    throw new RuntimeException('refuse: target must be xinruihao; ruihao requires --allow-ruihao');
}

$pdo = new PDO(
    'mysql:host=' . $db['HOSTNAME'] . ';port=' . $db['HOSTPORT'] . ';dbname=' . $database . ';charset=utf8mb4',
    $db['USERNAME'],
    $db['PASSWORD'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_general_ci');

$lockName = 'rh_member_affiliation_debt_repair_' . $database;
if ((int)$pdo->query('SELECT GET_LOCK(' . $pdo->quote($lockName) . ', 30)')->fetchColumn() !== 1) {
    throw new RuntimeException('could not obtain migration lock');
}

/** @return array<string, int> */
function loadMap(PDO $pdo, string $sql, string $key, string $value): array
{
    $result = [];
    foreach ($pdo->query($sql) as $row) {
        $result[(string)$row[$key]] = (int)$row[$value];
    }
    return $result;
}

/** @return array<int, array<string, mixed>> */
function sourceRows(PDO $pdo, array $shops, string $sql): array
{
    $rows = [];
    foreach (array_chunk(array_values($shops), 20) as $chunk) {
        $marks = implode(',', array_fill(0, count($chunk), '?'));
        $statement = $pdo->prepare(sprintf($sql, $marks));
        $statement->execute($chunk);
        foreach ($statement as $row) {
            $rows[] = $row;
        }
    }
    return $rows;
}

try {
    $memberBySource = loadMap(
        $pdo,
        'SELECT source_customer_id,target_uid FROM map_rh_customer',
        'source_customer_id',
        'target_uid'
    );
    $storeBySourceShop = loadMap(
        $pdo,
        "SELECT source_shop_id,target_store_id FROM map_rh_store WHERE confirmed=1 AND source_shop_id IS NOT NULL AND source_shop_id<>''",
        'source_shop_id',
        'target_store_id'
    );
    if (!$storeBySourceShop) {
        throw new RuntimeException('no confirmed source-store mappings');
    }

    $productByCardClass = loadMap(
        $pdo,
        "SELECT source_id,target_product_id FROM map_rh_product WHERE source_table='bd_CardClass'",
        'source_id',
        'target_product_id'
    );
    $existingDebtKeys = [];
    foreach ($pdo->query("SELECT id,remark FROM eb_store_debt WHERE debt_no LIKE 'RHD%' AND remark LIKE 'source_key=%'") as $row) {
        $existingDebtKeys[substr((string)$row['remark'], 11)] = (int)$row['id'];
    }

    $sourceShops = array_keys($storeBySourceShop);
    $cardRows = sourceRows(
        $pdo,
        $sourceShops,
        'SELECT cd.Id AS source_id,cd.CustomerId AS source_customer_id,cd.CardClassId AS source_card_class_id,cd.NonePay AS amount,UNIX_TIMESTAMP(cd.RegisterDate) AS event_ts,c.Shop AS member_source_shop,cc.CardKindName AS product_name
         FROM jsj.bd_CardDetail cd
         JOIN jsj.bd_Customers c ON c.Id=cd.CustomerId
         JOIN jsj.bd_CardClass cc ON cc.Id=cd.CardClassId
         WHERE c.Shop IN (%s) AND ABS(COALESCE(cd.NonePay,0))>=0.001'
    );
    $rechargeRows = sourceRows(
        $pdo,
        $sourceShops,
        'SELECT r.MDID AS source_id,r.CardDetailId AS source_card_detail_id,COALESCE(r.CustomerId,cd.CustomerId) AS source_customer_id,cd.CardClassId AS source_card_class_id,r.NonePay AS amount,UNIX_TIMESTAMP(r.IssueDate) AS event_ts,c.Shop AS member_source_shop,\'旧库充值欠款\' AS product_name
         FROM jsj.SS_CustomerAddMoney r
         JOIN jsj.bd_CardDetail cd ON cd.Id=r.CardDetailId
         JOIN jsj.bd_Customers c ON c.Id=COALESCE(r.CustomerId,cd.CustomerId)
         WHERE c.Shop IN (%s) AND COALESCE(r.flag,0)<>-1 AND COALESCE(r.AddMoney,0)<>0 AND ABS(COALESCE(r.NonePay,0))>=0.001'
    );

    // Do not preload every historical entitlement order: that exceeds the
    // constrained CLI memory limit. Load only card origins referenced by this
    // source batch.
    $sourceCardIds = [];
    foreach ($cardRows as $row) {
        $sourceCardIds[(string)$row['source_id']] = true;
    }
    foreach ($rechargeRows as $row) {
        $sourceCardIds[(string)$row['source_card_detail_id']] = true;
    }
    $originOrderByCardId = [];
    foreach (array_chunk(array_keys($sourceCardIds), 500) as $chunk) {
        $marks = implode(',', array_fill(0, count($chunk), '?'));
        $orders = $pdo->prepare("SELECT id,order_id,store_id,uid FROM eb_store_order WHERE order_id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ")");
        $orders->execute(array_map(static fn(string $sourceId): string => 'RH-RELOAD-102-CARD-' . $sourceId, $chunk));
        foreach ($orders as $row) {
            $sourceId = substr((string)$row['order_id'], strlen('RH-RELOAD-102-CARD-'));
            $originOrderByCardId[$sourceId] = $row;
        }
    }
    $mainCartByOrderId = [];
    foreach (array_chunk(array_column($originOrderByCardId, 'id'), 500) as $chunk) {
        if (!$chunk) {
            continue;
        }
        $carts = $pdo->prepare('SELECT id,oid,product_id FROM eb_store_order_cart_info WHERE oid IN (' . implode(',', array_fill(0, count($chunk), '?')) . ') AND product_type=5 ORDER BY id');
        $carts->execute($chunk);
        foreach ($carts as $row) {
            $mainCartByOrderId[(int)$row['oid']] = $row;
        }
    }

    $candidates = [];
    $blocked = [];
    $buildCandidate = static function (array $row, string $kind) use (&$candidates, &$blocked, $memberBySource, $storeBySourceShop, $originOrderByCardId, $mainCartByOrderId, $productByCardClass, $existingDebtKeys): void {
        $sourceId = (string)$row['source_id'];
        $sourceKey = $kind === 'CARD' ? 'CARD:' . $sourceId : 'RECH:' . $sourceId;
        if (isset($existingDebtKeys[$sourceKey])) {
            return;
        }
        $sourceCustomerId = (string)$row['source_customer_id'];
        $uid = $memberBySource[$sourceCustomerId] ?? 0;
        $cardId = $kind === 'CARD' ? $sourceId : (string)$row['source_card_detail_id'];
        $origin = $originOrderByCardId[$cardId] ?? null;
        $originCart = $origin ? ($mainCartByOrderId[(int)$origin['id']] ?? null) : null;
        $memberStoreId = $storeBySourceShop[(string)$row['member_source_shop']] ?? 0;
        $storeId = $origin && (int)$origin['uid'] === $uid ? (int)$origin['store_id'] : $memberStoreId;
        if ($uid <= 0 || $storeId <= 0) {
            $blocked[] = ['source_key' => $sourceKey, 'reason' => $uid <= 0 ? 'missing_member_map' : 'missing_member_store_map'];
            return;
        }
        $candidates[] = [
            'source_key' => $sourceKey,
            'source_kind' => $kind,
            'source_card_id' => $cardId,
            'uid' => $uid,
            'store_id' => $storeId,
            'product_id' => $originCart ? (int)$originCart['product_id'] : (int)($productByCardClass[(string)$row['source_card_class_id']] ?? 0),
            'product_name' => (string)$row['product_name'],
            'amount' => round((float)$row['amount']),
            'event_ts' => max(0, (int)$row['event_ts']),
            'origin_order' => $origin && (int)$origin['uid'] === $uid ? $origin : null,
            'origin_cart' => $origin && (int)$origin['uid'] === $uid ? $originCart : null,
        ];
    };
    foreach ($cardRows as $row) {
        $buildCandidate($row, 'CARD');
    }
    foreach ($rechargeRows as $row) {
        $buildCandidate($row, 'RECH');
    }

    $summary = [
        'database' => $database,
        'source_card_rows' => count($cardRows),
        'source_recharge_rows' => count($rechargeRows),
        'existing_rows_skipped' => count($cardRows) + count($rechargeRows) - count($candidates) - count($blocked),
        'repairable_rows' => count($candidates),
        'repairable_amount' => array_sum(array_column($candidates, 'amount')),
        'direct_entitlement_links' => count(array_filter($candidates, static fn(array $row): bool => $row['origin_order'] !== null && $row['origin_cart'] !== null)),
        'blocked_rows' => count($blocked),
        'blocked_samples' => array_slice($blocked, 0, 20),
    ];
    if (!$execute) {
        echo json_encode(['status' => 'precheck'] + $summary, JSON_UNESCAPED_UNICODE) . PHP_EOL;
        return;
    }

    $orderInsert = $pdo->prepare("INSERT INTO eb_store_order(type,order_id,store_id,uid,real_name,user_phone,total_num,total_price,pay_price,debt_amount,repaid_debt_amount,paid,pay_type,status,is_del,add_time,pay_time,mark,remark,`unique`,shipping_type,channel_type,product_type)
      SELECT 11,?,?,u.uid,LEFT(COALESCE(u.real_name,''),32),LEFT(COALESCE(u.phone,''),18),1,?,?,?,?,1,'migration',0,0,?,?, 'RH-RELOAD-102-DEBT-AFFILIATION',?,?,4,'migration',5 FROM eb_user u WHERE u.uid=?");
    $cartInsert = $pdo->prepare("INSERT INTO eb_store_order_cart_info(uid,oid,cart_id,cart_type,type,relation_id,product_id,product_type,sku_unique,is_card,cart_num,total_price,settle_price,pay_price,debt_amount,repaid_debt_amount,cart_info,`unique`,add_time,source_type)
      VALUES(?,?,?,0,0,?,?,?,?,1,1,?,?,?,?,0,JSON_OBJECT('source_type','migration','source_key',?,'source_card_detail_id',?),?,?, 'migration')");
    $debtInsert = $pdo->prepare('INSERT INTO eb_store_debt(debt_no,order_id,order_sn,uid,store_id,staff_id,total_debt,repaid_debt,status,remark,add_time,update_time) VALUES(?,?,?,?,?,0,?,0,0,?,?,?)');
    $itemInsert = $pdo->prepare('INSERT INTO eb_store_debt_item(debt_id,order_id,cart_info_id,product_id,product_type,product_name,cart_num,debt_amount,repaid_debt,add_time,update_time) VALUES(?,?,?,?,5,?,1,?,0,?,?)');
    $authorityInsert = $pdo->prepare("INSERT INTO eb_cashier_v3_debt_authority(debt_id,debt_no,tenant_id,store_id,member_id,sales_order_record_id,sales_order_id,sales_order_no_snapshot,checkout_request_id,checkout_command_idempotency_key,policy_version,authority_fingerprint,created_at,updated_at) VALUES(?,?,'0',?,?,0,?,?,?,?,1,SHA2(CONCAT(?,'|',?,'|',?),256),?,?)");
    $originOrderDebtUpdate = $pdo->prepare('UPDATE eb_store_order SET debt_amount=?,repaid_debt_amount=0 WHERE id=?');
    $originCartDebtUpdate = $pdo->prepare('UPDATE eb_store_order_cart_info SET debt_amount=?,repaid_debt_amount=0 WHERE id=?');

    $written = 0;
    foreach (array_chunk($candidates, 500) as $batch) {
        $pdo->beginTransaction();
        try {
            foreach ($batch as $row) {
                $amount = (float)$row['amount'];
                $eventTs = (int)$row['event_ts'];
                $orderId = 0;
                $orderNo = '';
                $cartInfoId = 0;
                if ($row['origin_order'] !== null && $row['origin_cart'] !== null) {
                    $orderId = (int)$row['origin_order']['id'];
                    $orderNo = (string)$row['origin_order']['order_id'];
                    $cartInfoId = (int)$row['origin_cart']['id'];
                } else {
                    $orderNo = 'RH-DEBT-AFF-' . substr(md5($row['source_key']), 0, 24);
                    $orderInsert->execute([$orderNo, $row['store_id'], max($amount, 0), max($amount, 0), $amount, 0, $eventTs, $eventTs, 'source_key=' . $row['source_key'], md5($orderNo), $row['uid']]);
                    $orderId = (int)$pdo->lastInsertId();
                    $cartInsert->execute([$row['uid'], $orderId, 'RH-DEBT-CART-AFF-' . substr(md5($row['source_key']), 0, 24), $row['store_id'], $row['product_id'], 5, '', max($amount, 0), max($amount, 0), max($amount, 0), $amount, $row['source_key'], $row['source_card_id'], md5('RH-DEBT-CART-AFF-' . $row['source_key']), $eventTs]);
                    $cartInfoId = (int)$pdo->lastInsertId();
                }
                $debtNo = 'RHD' . substr(md5('RH-RELOAD-102-DEBT-AFFILIATION|' . $row['source_key']), 0, 29);
                $debtInsert->execute([$debtNo, $orderId, substr($orderNo, 0, 32), $row['uid'], $row['store_id'], $amount, 'source_key=' . $row['source_key'], $eventTs, $eventTs]);
                $debtId = (int)$pdo->lastInsertId();
                $itemInsert->execute([$debtId, $orderId, $cartInfoId, $row['product_id'], $row['product_name'], $amount, $eventTs, $eventTs]);
                $authorityInsert->execute([$debtId, $debtNo, $row['store_id'], $row['uid'], $orderNo, substr($orderNo, 0, 64), 'RHD-AFF-REQ-' . $debtId, 'RHD-AFF-CMD-' . $debtId, $debtNo, $row['store_id'], $row['uid'], time(), time()]);
                if ($row['origin_order'] !== null && $row['origin_cart'] !== null) {
                    $originOrderDebtUpdate->execute([$amount, $orderId]);
                    $originCartDebtUpdate->execute([$amount, $cartInfoId]);
                }
                $written++;
            }
            $pdo->commit();
        } catch (Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }
    }
    echo json_encode(['status' => 'ok', 'written_rows' => $written] + $summary, JSON_UNESCAPED_UNICODE) . PHP_EOL;
} finally {
    $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lockName) . ')');
}
