<?php
declare(strict_types=1);

/*
 * Completes only the source members referenced by the time-card batch when
 * their historical source id has no target map.  This mirrors the approved
 * member-import matching rule: exact unique phone -> reuse; otherwise create;
 * ambiguity is blocked and never duplicated.
 */
const RH_TIME_CARD_CLASS_ID = '0091000112';
const RH_MEMBER_BATCH = 'RH-RELOAD-102-MEMBER-V1';

$execute = in_array('--execute', $argv, true);
$allowRuihao = in_array('--allow-ruihao', $argv, true);
$envPath = getenv('MOHE_TIMECARD_ENV_PATH') ?: '/var/www/html/.env';
$env = parse_ini_file($envPath, true);
if (!is_array($env) || empty($env['DATABASE'])) throw new RuntimeException('database env unavailable');
$db = $env['DATABASE'];
if ((string)($db['DATABASE'] ?? '') !== 'ruihao' || !$allowRuihao) throw new RuntimeException('refuse: ruihao requires --allow-ruihao');
$pdo = new PDO('mysql:host='.$db['HOSTNAME'].';port='.$db['HOSTPORT'].';dbname=ruihao;charset=utf8mb4', $db['USERNAME'], $db['PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_general_ci');

$lock = $pdo->query("SELECT GET_LOCK('rh_time_card_member_map_v1',30)")->fetchColumn();
if ((int)$lock !== 1) throw new RuntimeException('member-map lock unavailable');
try {
    $source = $pdo->prepare("SELECT CONVERT(c.Id USING utf8mb4) source_customer_id,
        TRIM(COALESCE(c.Mobile,'')) phone,
        LEFT(COALESCE(NULLIF(TRIM(c.Name),''),NULLIF(TRIM(c.FullName),''),c.Id),128) member_name,
        c.Sex sex_raw,
        CONVERT(c.Shop USING utf8mb4) member_source_shop_id,
        CONVERT(MIN(cd.ShopId) USING utf8mb4) card_source_shop_id
      FROM jsj.bd_CardDetail cd
      JOIN jsj.bd_Customers c ON c.Id=cd.CustomerId
      LEFT JOIN map_rh_customer m ON CONVERT(m.source_customer_id USING utf8mb4)=CONVERT(c.Id USING utf8mb4)
      WHERE CONVERT(cd.CardClassId USING utf8mb4)=? AND m.source_customer_id IS NULL
      GROUP BY c.Id,c.Mobile,c.Name,c.FullName,c.Sex,c.Shop");
    $source->execute([RH_TIME_CARD_CLASS_ID]);
    $rows = $source->fetchAll();

    $storeMap = [];
    foreach ($pdo->query("SELECT source_shop_id,target_store_id FROM map_rh_store WHERE confirmed=1 AND source_shop_id IS NOT NULL AND source_shop_id<>''") as $row) {
        $sourceShop = (string)$row['source_shop_id'];
        $targetStore = (int)$row['target_store_id'];
        if (isset($storeMap[$sourceShop]) && $storeMap[$sourceShop] !== $targetStore) {
            $storeMap[$sourceShop] = 0;
        } else {
            $storeMap[$sourceShop] = $targetStore;
        }
    }
    $identifiers = [];
    foreach ($rows as &$row) {
        $row['identifier'] = $row['phone'] !== '' ? $row['phone'] : 'RH-'.$row['source_customer_id'];
        $identifiers[$row['identifier']] = true;
    }
    unset($row);
    $usersByPhone = [];
    foreach (array_chunk(array_keys($identifiers), 500) as $chunk) {
        $stmt = $pdo->prepare('SELECT uid,phone FROM eb_user WHERE is_del=0 AND phone IN ('.implode(',', array_fill(0, count($chunk), '?')).')');
        $stmt->execute($chunk);
        foreach ($stmt as $user) $usersByPhone[(string)$user['phone']][] = (int)$user['uid'];
    }
    $sourceIdentifierCount = [];
    foreach ($rows as $row) $sourceIdentifierCount[$row['identifier']] = ($sourceIdentifierCount[$row['identifier']] ?? 0) + 1;
    $plans = [];
    $blocked = [];
    foreach ($rows as $row) {
        $sourceId = (string)$row['source_customer_id'];
        $targetStore = (int)($storeMap[(string)$row['member_source_shop_id']] ?? 0);
        if ($targetStore <= 0) $targetStore = (int)($storeMap[(string)$row['card_source_shop_id']] ?? 0);
        $matches = array_values(array_unique($usersByPhone[$row['identifier']] ?? []));
        if ($targetStore <= 0) { $blocked[]=['source_customer_id'=>$sourceId,'reason'=>'missing_or_ambiguous_source_store']; continue; }
        if (count($matches) > 1) { $blocked[]=['source_customer_id'=>$sourceId,'reason'=>'ambiguous_target_phone']; continue; }
        if (count($matches) === 0 && ($sourceIdentifierCount[$row['identifier']] ?? 0) > 1) { $blocked[]=['source_customer_id'=>$sourceId,'reason'=>'ambiguous_source_phone']; continue; }
        $plans[] = $row + ['target_store_id'=>$targetStore,'existing_uid'=>$matches[0] ?? 0];
    }
    $summary=['database'=>'ruihao','missing_member_sources'=>count($rows),'repairable'=>count($plans),'reuse_unique_phone'=>count(array_filter($plans, static fn(array $row): bool => (int)$row['existing_uid'] > 0)),'create_member'=>count(array_filter($plans, static fn(array $row): bool => (int)$row['existing_uid'] === 0)),'blocked'=>count($blocked),'blocked_samples'=>array_slice($blocked,0,20)];
    if (!$execute) { echo json_encode(['status'=>'precheck']+$summary, JSON_UNESCAPED_UNICODE).PHP_EOL; exit; }

    $insertUser = $pdo->prepare('INSERT INTO eb_user(phone,nickname,real_name,sex,birthday,add_time,status,is_del,now_money,give_money,ben_money) VALUES(?,?,?,?,0,UNIX_TIMESTAMP(),1,0,0,0,0)');
    $upsertMap = $pdo->prepare('INSERT INTO map_rh_customer(source_customer_id,target_uid,merge_phone,is_master,batch_no) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE target_uid=VALUES(target_uid),merge_phone=VALUES(merge_phone),is_master=VALUES(is_master),batch_no=VALUES(batch_no)');
    $storeUserExists = $pdo->prepare('SELECT id FROM eb_store_user WHERE store_id=? AND uid=? LIMIT 1');
    $storeUserInsert = $pdo->prepare('INSERT INTO eb_store_user(store_id,uid,status,add_time) VALUES(?,?,1,UNIX_TIMESTAMP())');
    $written = 0;
    foreach ($plans as $plan) {
        $pdo->beginTransaction();
        try {
            $uid = (int)$plan['existing_uid'];
            if ($uid <= 0) {
                $sex = in_array((string)$plan['sex_raw'], ['1','男','M','m'], true) ? 1 : (in_array((string)$plan['sex_raw'], ['2','女','F','f'], true) ? 2 : 0);
                $name = (string)$plan['member_name'];
                $insertUser->execute([$plan['identifier'], substr($name,0,60), substr($name,0,25), $sex]);
                $uid = (int)$pdo->lastInsertId();
            }
            $upsertMap->execute([$plan['source_customer_id'],$uid,$plan['identifier'],1,RH_MEMBER_BATCH]);
            $storeUserExists->execute([$plan['target_store_id'],$uid]);
            if (!$storeUserExists->fetchColumn()) $storeUserInsert->execute([$plan['target_store_id'],$uid]);
            $pdo->commit();
            $written++;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }
    echo json_encode(['status'=>'ok','written'=>$written]+$summary, JSON_UNESCAPED_UNICODE).PHP_EOL;
} finally {
    $pdo->query("SELECT RELEASE_LOCK('rh_time_card_member_map_v1')");
}
