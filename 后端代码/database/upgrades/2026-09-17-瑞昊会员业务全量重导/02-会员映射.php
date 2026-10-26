<?php
declare(strict_types=1);

/**
 * Resolve every live JSJ customer key to one ruihao member before asset writes.
 * The phone merge and member-store choice are shared by all later imports;
 * this table stores identifiers and decisions, never a copy of JSJ assets.
 */
$args = array_fill_keys(array_slice($argv, 1), true);
if (!isset($args['--execute'], $args['--allow-ruihao'], $args['--direct-jsj'])) {
    fwrite(STDERR, "Refusing to write without --execute --allow-ruihao --direct-jsj\n");
    exit(2);
}
$envPath = getenv('RH_ENV_FILE') ?: '/www/wwwroot/rh.cc3798.com/.env';
$env = parse_ini_file($envPath, true, INI_SCANNER_RAW);
$db = $env['DATABASE'] ?? [];
$target = getenv('RH_TARGET_DATABASE') ?: (string)($db['DATABASE'] ?? '');
if ($target !== 'ruihao') {
    throw new RuntimeException("target guard failed: expected ruihao, got {$target}");
}
$pdo = new PDO(
    'mysql:host=' . $db['HOSTNAME'] . ';port=' . $db['HOSTPORT'] . ';dbname=ruihao;charset=utf8mb4',
    $db['USERNAME'], $db['PASSWORD'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS mig_jsj_20260917_member_resolution (
    source_customer_id varchar(50) NOT NULL PRIMARY KEY,
    target_uid int unsigned NOT NULL DEFAULT 0,
    target_store_id int unsigned NOT NULL DEFAULT 0,
    status varchar(32) NOT NULL,
    reason varchar(255) NOT NULL DEFAULT '',
    source_phone varchar(32) NOT NULL DEFAULT '',
    resolved_phone varchar(32) NOT NULL DEFAULT '',
    updated_at datetime NOT NULL,
    KEY idx_status(status), KEY idx_uid(target_uid), KEY idx_store(target_store_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
$pdo->exec("CREATE TABLE IF NOT EXISTS mig_jsj_20260917_store_override (
    source_shop_id varchar(50) NOT NULL PRIMARY KEY,
    target_store_id int unsigned NOT NULL,
    reason varchar(255) NOT NULL,
    created_at datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
// JSJ presale rows contain 720092000090 although the JSJ shop master only has
// 720091000090. The identical terminal shop number 000090 maps to target store 56.
$pdo->exec("INSERT INTO mig_jsj_20260917_store_override(source_shop_id,target_store_id,reason,created_at)
    VALUES('720092000090',56,'JSJ typo alias: shop master 720091000090 (河南-安阳万达店)',NOW())
    ON DUPLICATE KEY UPDATE target_store_id=VALUES(target_store_id),reason=VALUES(reason)");
$pdo->exec("CREATE TABLE IF NOT EXISTS mig_jsj_20260917_member_merge (
    source_phone varchar(32) NOT NULL,
    master_source_customer_id varchar(50) NOT NULL,
    merged_source_customer_id varchar(50) NOT NULL,
    target_uid int unsigned NOT NULL DEFAULT 0,
    target_store_id int unsigned NOT NULL DEFAULT 0,
    merge_reason varchar(255) NOT NULL,
    updated_at datetime NOT NULL,
    PRIMARY KEY(source_phone,merged_source_customer_id), KEY idx_master(master_source_customer_id), KEY idx_uid(target_uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

// Build the complete member profile work set from JSJ at execution time. New
// profiles without an asset must still reach ruihao; asset imports later use
// the same resolution table. The ruihao tables written here are mappings and
// audit data, never a JSJ fact copy.
$members = $pdo->query("SELECT CONVERT(c.Id USING utf8mb4) source_customer_id,
    LEFT(COALESCE(NULLIF(TRIM(c.Name),''),NULLIF(TRIM(c.FullName),''),c.Id),128) name,
    LEFT(TRIM(COALESCE(c.Mobile,'')),32) phone,LEFT(COALESCE(c.Sex,''),16) sex,
    LEFT(COALESCE(c.BirthDate,''),40) birthday,CONVERT(COALESCE(c.Shop,'') USING utf8mb4) source_shop_id,
    CONVERT(COALESCE(c.lastShop,'') USING utf8mb4) source_last_shop,
    COALESCE(c.LastModTime,c.LastDate,c.IssueDate,'1970-01-01 00:00:00') source_profile_at
 FROM jsj.bd_Customers c ORDER BY c.Id")->fetchAll();
$knownMemberIds = array_fill_keys(array_map(static fn(array $row): string => (string)$row['source_customer_id'], $members), true);
// A few legacy card rows can reference a customer key whose profile row has
// not reached bd_Customers yet. Keep that asset importable with a stable
// placeholder profile and derive its store from the asset evidence below.
$assetOnly = $pdo->query("SELECT CONVERT(cd.CustomerId USING utf8mb4) source_customer_id,CONVERT(COALESCE(cd.ShopId,'') USING utf8mb4) source_shop_id FROM jsj.bd_CardDetail cd WHERE COALESCE(cd.fstate,0)<>-1 AND (cd.EffectiveDate>='2026-09-17' OR cd.EffectiveDate IS NULL)
 UNION SELECT CONVERT(g.CustomerId USING utf8mb4),CONVERT(COALESCE(g.Shop,'') USING utf8mb4) FROM jsj.SS_SendProject g WHERE g.StartDate<='2026-09-17' AND g.EffectiveDate>='2026-09-17' AND g.Times-COALESCE(g.ConsumeQuantity,0)>0 AND g.Fstate='0'
 UNION SELECT CONVERT(g.CustomerId USING utf8mb4),CONVERT(COALESCE(g.Shop,'') USING utf8mb4) FROM jsj.SS_SendGoods g WHERE g.EffectiveDate>='2026-09-17' AND g.Fstate='0' AND g.Quantity-COALESCE(g.ConsumeQuantity,0)>0
 UNION SELECT CONVERT(g.CustomerId USING utf8mb4),CONVERT(COALESCE(g.Shop,'') USING utf8mb4) FROM jsj.SS_StoreGoods g WHERE g.EffectiveDate>='2026-09-17' AND g.Quantity-COALESCE(g.ConsumeQuantity,0)>0 AND g.Fstate<>'-1' AND COALESCE(g.Salefstate,'')<>'作废'
 UNION SELECT CONVERT(g.CustomerId USING utf8mb4),CONVERT(COALESCE(g.ShopId,'') USING utf8mb4) FROM jsj.SS_PreSaleGoods g WHERE g.Quantity-COALESCE(g.consumequantity,0)>0 AND g.Fstate<>'-1'
 UNION SELECT CONVERT(r.CustomerId USING utf8mb4),'' FROM jsj.SS_CustomerAddMoney r WHERE COALESCE(r.flag,0)<>-1 AND COALESCE(r.AddMoney,0)<>0 AND ABS(COALESCE(r.NonePay,0))>=0.001")->fetchAll();
foreach ($assetOnly as $asset) {
    $sourceId=(string)$asset['source_customer_id'];
    if ($sourceId==='' || isset($knownMemberIds[$sourceId])) continue;
    $members[]=['source_customer_id'=>$sourceId,'name'=>$sourceId,'phone'=>'','sex'=>'','birthday'=>'','source_shop_id'=>(string)$asset['source_shop_id'],'source_last_shop'=>'','source_profile_at'=>'1970-01-01 00:00:00'];
    $knownMemberIds[$sourceId]=true;
}
$existingMap = [];
foreach ($pdo->query('SELECT source_customer_id,target_uid FROM map_rh_customer') as $row) {
    $existingMap[(string)$row['source_customer_id']] = (int)$row['target_uid'];
}
$users = [];
$phoneUsers = [];
foreach ($pdo->query("SELECT uid,phone,belong_store_id FROM eb_user WHERE is_del=0") as $row) {
    $uid = (int)$row['uid'];
    $phone = trim((string)$row['phone']);
    $users[$uid] = ['phone' => $phone, 'store' => (int)$row['belong_store_id']];
    if ($phone !== '') {
        $phoneUsers[$phone][$uid] = true;
    }
}
$storeMap = [];
foreach ($pdo->query('SELECT source_shop_id,target_store_id FROM map_rh_store WHERE confirmed=1') as $row) {
    $storeMap[(string)$row['source_shop_id']] = (int)$row['target_store_id'];
}
foreach ($pdo->query('SELECT source_shop_id,target_store_id FROM mig_jsj_20260917_store_override') as $row) {
    $storeMap[(string)$row['source_shop_id']] = (int)$row['target_store_id'];
}
$lastShop = [];
$sourceProfile = [];
foreach ($members as $member) {
    $lastShop[(string)$member['source_customer_id']] = (string)$member['source_last_shop'];
    $sourceProfile[(string)$member['source_customer_id']] = (string)$member['source_profile_at'];
}

// Preserve every mapped asset shop. A member without a mapped customer shop is
// accepted only when all mapped asset shops resolve to one target store.
$assetStores = [];
$assetMemberIds = [];
$assetShopSql = "SELECT CONVERT(cd.CustomerId USING utf8mb4) source_customer_id,CONVERT(COALESCE(cd.ShopId,'') USING utf8mb4) source_shop_id
    FROM jsj.bd_CardDetail cd JOIN jsj.bd_ProjectAndTimes pt ON pt.CardDetailId=cd.Id JOIN jsj.bd_ProjectSet ps ON ps.Id=pt.ProjectId
    WHERE COALESCE(cd.fstate,0)<>-1 AND (cd.EffectiveDate>='2026-09-17' OR cd.EffectiveDate IS NULL) AND cd.pflag<>'0' AND COALESCE(pt.ShiFouUse,'')='' AND pt.Quantity-COALESCE(pt.UseQuantity,0)>0 AND cd.CardClassId<>'0091000112'
    UNION ALL SELECT CONVERT(p.CustomerId USING utf8mb4),CONVERT(COALESCE(p.Shop,'') USING utf8mb4) FROM jsj.SS_SendProject p JOIN jsj.bd_ProjectSet ps ON ps.Id=p.ProjectId WHERE p.StartDate<='2026-09-17' AND p.EffectiveDate>='2026-09-17' AND p.Times-COALESCE(p.ConsumeQuantity,0)>0 AND p.Fstate='0'
    UNION ALL SELECT CONVERT(cd.CustomerId USING utf8mb4),CONVERT(COALESCE(cd.ShopId,'') USING utf8mb4) FROM jsj.bd_CardDetail cd WHERE ABS(COALESCE(cd.NonePay,0))>=0.001 OR cd.CardClassId='0091000112'
    UNION ALL SELECT CONVERT(cd.CustomerId USING utf8mb4),CONVERT(COALESCE(cd.ShopId,'') USING utf8mb4) FROM jsj.SS_CustomerAddMoney r JOIN jsj.bd_CardDetail cd ON cd.Id=r.CardDetailId WHERE COALESCE(r.flag,0)<>-1 AND COALESCE(r.AddMoney,0)<>0 AND ABS(COALESCE(r.NonePay,0))>=0.001
    UNION ALL SELECT CONVERT(g.CustomerId USING utf8mb4),CONVERT(COALESCE(g.Shop,'') USING utf8mb4) FROM jsj.SS_SendGoods g JOIN jsj.bd_goods bg ON bg.Id=g.GoodsId WHERE g.EffectiveDate>='2026-09-17' AND g.Fstate='0' AND g.Quantity-COALESCE(g.ConsumeQuantity,0)>0
    UNION ALL SELECT CONVERT(g.CustomerId USING utf8mb4),CONVERT(COALESCE(g.Shop,'') USING utf8mb4) FROM jsj.SS_StoreGoods g JOIN jsj.bd_goods bg ON bg.Id=g.GoodsId WHERE g.EffectiveDate>='2026-09-17' AND g.Quantity-COALESCE(g.ConsumeQuantity,0)>0 AND g.Fstate<>'-1' AND COALESCE(g.Salefstate,'')<>'作废'
    UNION ALL SELECT CONVERT(g.CustomerId USING utf8mb4),CONVERT(COALESCE(g.ShopId,'') USING utf8mb4) FROM jsj.SS_PreSaleGoods g JOIN jsj.bd_goods bg ON bg.Id=g.GoodsId WHERE g.Quantity-COALESCE(g.consumequantity,0)>0 AND g.Fstate<>'-1'";
foreach ($pdo->query($assetShopSql) as $row) {
    $assetMemberIds[(string)$row['source_customer_id']] = true;
    $store = $storeMap[(string)$row['source_shop_id']] ?? 0;
    if ($store > 0) {
        $assetStores[(string)$row['source_customer_id']][$store] = true;
    }
}
// Balance ownership comes directly from the current JSJ card rows. The later
// asset import independently aggregates amounts from JSJ, so this map must
// never depend on an earlier frozen copy of the source facts.
$balanceShopSql = "SELECT CONVERT(cd.CustomerId USING utf8mb4) source_customer_id,CONVERT(cd.ShopId USING utf8mb4) source_shop_id
    FROM jsj.bd_CardDetail cd JOIN jsj.bd_CardClass cc ON cc.Id=cd.CardClassId
    WHERE COALESCE(cd.fstate,0)<>-1 AND (cd.EffectiveDate>='2026-09-17' OR cd.EffectiveDate IS NULL)
      AND cd.pflag='0' AND (LEFT(cc.CardKindNo,3)<>'003' OR (LEFT(cc.CardKindNo,3)='003' AND COALESCE(cd.Balance,0)<>0))";
foreach ($pdo->query($balanceShopSql) as $row) {
    $assetMemberIds[(string)$row['source_customer_id']] = true;
    $store = $storeMap[(string)$row['source_shop_id']] ?? 0;
    if ($store > 0) $assetStores[(string)$row['source_customer_id']][$store] = true;
}

$missingPhoneCounts = [];
$missingPhoneMembers = [];
foreach ($members as $member) {
    $sourceId = (string)$member['source_customer_id'];
    if (($existingMap[$sourceId] ?? 0) > 0) {
        continue;
    }
    $phone = trim((string)$member['phone']);
    if ($phone !== '') {
        $missingPhoneCounts[$phone] = ($missingPhoneCounts[$phone] ?? 0) + 1;
        $missingPhoneMembers[$phone][] = $sourceId;
    }
}
$duplicateMaster = [];
foreach ($missingPhoneMembers as $phone => $sourceIds) {
    if (count($sourceIds) < 2) continue;
    usort($sourceIds, static function (string $left, string $right) use ($sourceProfile): int {
        $dateCompare = strcmp((string)($sourceProfile[$right] ?? ''), (string)($sourceProfile[$left] ?? ''));
        return $dateCompare !== 0 ? $dateCompare : strcmp($right, $left);
    });
    $duplicateMaster[$phone] = $sourceIds[0];
}
// Process the chosen master first so all same-phone source rows can share its uid.
usort($members, static function (array $left, array $right) use ($duplicateMaster): int {
    $leftPhone = trim((string)$left['phone']);
    $rightPhone = trim((string)$right['phone']);
    $leftMaster = ($duplicateMaster[$leftPhone] ?? '') === (string)$left['source_customer_id'];
    $rightMaster = ($duplicateMaster[$rightPhone] ?? '') === (string)$right['source_customer_id'];
    if ($leftMaster !== $rightMaster) return $leftMaster ? -1 : 1;
    if ($leftPhone === $rightPhone && $leftMaster !== $rightMaster) return $leftMaster ? -1 : 1;
    return strcmp((string)$left['source_customer_id'], (string)$right['source_customer_id']);
});

function targetStore(array $member, int $uid, array $users, array $storeMap, array $assetStores, array $lastShop): array
{
    if ($uid > 0 && (($users[$uid]['store'] ?? 0) > 0)) {
        return [(int)$users[$uid]['store'], 'existing member belong_store_id'];
    }
    $sourceId = (string)$member['source_customer_id'];
    $customerStore = $storeMap[(string)$member['source_shop_id']] ?? 0;
    if ($customerStore > 0) {
        return [$customerStore, 'source member shop'];
    }
    $lastStore = $storeMap[(string)($lastShop[$sourceId] ?? '')] ?? 0;
    if ($lastStore > 0) {
        return [$lastStore, 'source member lastShop'];
    }
    $stores = array_keys($assetStores[$sourceId] ?? []);
    if (count($stores) === 1) {
        return [(int)$stores[0], 'unique mapped asset shop'];
    }
    if (count($stores) > 1) {
        return [0, 'multiple mapped asset shops: ' . implode(',', $stores)];
    }
    return [0, 'no mapped member or asset shop'];
}

$insertUser = $pdo->prepare(
    'INSERT INTO eb_user(phone,nickname,real_name,sex,birthday,add_time,status,is_del,now_money,give_money,ben_money,belong_store_id)
     VALUES(?,?,?,?,?,UNIX_TIMESTAMP(),1,0,0,0,0,?)'
);
$insertStoreUser = $pdo->prepare(
    'INSERT INTO eb_store_user(store_id,uid,status,add_time)
     SELECT ?,?,1,UNIX_TIMESTAMP() FROM DUAL
     WHERE NOT EXISTS(SELECT 1 FROM eb_store_user WHERE store_id=? AND uid=?)'
);
$upsertMap = $pdo->prepare(
    "INSERT INTO map_rh_customer(source_customer_id,target_uid,merge_phone,is_master,batch_no)
     VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE target_uid=VALUES(target_uid),merge_phone=VALUES(merge_phone),batch_no=VALUES(batch_no)"
);
$upsertResolution = $pdo->prepare(
    "INSERT INTO mig_jsj_20260917_member_resolution
     (source_customer_id,target_uid,target_store_id,status,reason,source_phone,resolved_phone,updated_at)
     VALUES(?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE target_uid=VALUES(target_uid),target_store_id=VALUES(target_store_id),
     status=VALUES(status),reason=VALUES(reason),source_phone=VALUES(source_phone),resolved_phone=VALUES(resolved_phone),updated_at=NOW()"
);
$upsertMerge = $pdo->prepare(
    "INSERT INTO mig_jsj_20260917_member_merge(source_phone,master_source_customer_id,merged_source_customer_id,target_uid,target_store_id,merge_reason,updated_at)
     VALUES(?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE master_source_customer_id=VALUES(master_source_customer_id),target_uid=VALUES(target_uid),target_store_id=VALUES(target_store_id),merge_reason=VALUES(merge_reason),updated_at=NOW()"
);
$updateBelong = $pdo->prepare('UPDATE eb_user SET belong_store_id=? WHERE uid=? AND belong_store_id=0');
$updateProfile = $pdo->prepare('UPDATE eb_user SET nickname=?,real_name=?,sex=?,birthday=? WHERE uid=?');

$stats = ['existing' => 0, 'reused_phone' => 0, 'created' => 0, 'blocked_source_phone' => 0, 'blocked_target_phone' => 0, 'blocked_store' => 0, 'broken_existing_map' => 0];
$mergedUidByPhone = [];
$mergedStoreByPhone = [];
$pdo->beginTransaction();
try {
    foreach ($members as $member) {
        $sourceId = (string)$member['source_customer_id'];
        $sourcePhone = trim((string)$member['phone']);
        $uid = (int)($existingMap[$sourceId] ?? 0);
        $status = 'MAPPED_EXISTING';
        $reason = 'existing source mapping';
        $resolvedPhone = $uid > 0 ? (string)($users[$uid]['phone'] ?? '') : '';
        $duplicateMasterSourceId = $duplicateMaster[$sourcePhone] ?? '';
        $isDuplicatePhone = $duplicateMasterSourceId !== '';
        $isMaster = !$isDuplicatePhone || $duplicateMasterSourceId === $sourceId;

        if ($uid > 0 && !isset($users[$uid])) {
            // The stale source mapping is not reusable. Re-enter the same guarded
            // unique-phone/create path and replace the mapping after resolution.
            $uid = 0;
            $status = 'REPAIRED_STALE_MAP';
            $reason = 'replaced stale map_rh_customer target_uid';
            $resolvedPhone = '';
            $stats['broken_existing_map']++;
        }

        $sexRaw = (string)$member['sex'];
        $sex = in_array($sexRaw, ['1', '男', 'M', 'm'], true) ? 1 : (in_array($sexRaw, ['2', '女', 'F', 'f'], true) ? 2 : 0);
        if ($uid === 0) {
            if ($isDuplicatePhone && !$isMaster) {
                $uid = (int)($mergedUidByPhone[$sourcePhone] ?? 0);
                $storeId = (int)($mergedStoreByPhone[$sourcePhone] ?? 0);
                if ($uid <= 0 || $storeId <= 0) {
                    throw new RuntimeException('duplicate-phone master was not resolved first: ' . $sourcePhone);
                }
                $resolvedPhone = $sourcePhone;
                $insertStoreUser->execute([$storeId, $uid, $storeId, $uid]);
                $upsertMap->execute([$sourceId, $uid, $resolvedPhone, 0, 'JSJ-20260917-R1']);
                $existingMap[$sourceId] = $uid;
                $upsertResolution->execute([$sourceId, $uid, $storeId, 'MERGED_SOURCE_PHONE', 'merged into latest source profile ' . $duplicateMasterSourceId, $sourcePhone, $resolvedPhone]);
                $upsertMerge->execute([$sourcePhone, $duplicateMasterSourceId, $sourceId, $uid, $storeId, 'same source phone; product manager confirmed one target member']);
                continue;
            }
            $candidates = array_keys($phoneUsers[$sourcePhone] ?? []);
            if (count($candidates) > 1) {
                $upsertResolution->execute([$sourceId, 0, 0, 'BLOCKED_TARGET_PHONE', 'phone matches multiple target members', $sourcePhone, '']);
                $stats['blocked_target_phone']++;
                continue;
            }
            if (count($candidates) === 1) {
                $uid = (int)$candidates[0];
                $resolvedPhone = $sourcePhone;
                $status = 'MAPPED_PHONE';
                $reason = 'unique target phone';
            } else {
                $resolvedPhone = $sourcePhone !== '' ? $sourcePhone : 'RH-' . $sourceId;
                $status = 'CREATED';
                $reason = $sourcePhone !== '' ? 'unique source phone' : 'blank phone replaced by stable source key';
            }
        }

        [$storeId, $storeReason] = targetStore($member, $uid, $users, $storeMap, $assetStores, $lastShop);
        // A profile-only customer may legitimately have no mapped shop yet;
        // keep that profile with belong_store_id=0. Asset-bearing members must
        // resolve a single target store, otherwise their assets would be split
        // or silently dropped.
        if ($storeId <= 0 && isset($assetMemberIds[$sourceId])) {
            $upsertResolution->execute([$sourceId, $uid, 0, 'BLOCKED_STORE', $storeReason, $sourcePhone, $resolvedPhone]);
            $stats['blocked_store']++;
            continue;
        }

        if ($uid === 0) {
            $name = (string)$member['name'];
            $insertUser->execute([$resolvedPhone, mb_substr($name, 0, 60), mb_substr($name, 0, 25), $sex, 0, $storeId]);
            $uid = (int)$pdo->lastInsertId();
            $users[$uid] = ['phone' => $resolvedPhone, 'store' => $storeId];
            $phoneUsers[$resolvedPhone][$uid] = true;
            $stats['created']++;
        } elseif ($status === 'MAPPED_PHONE') {
            $stats['reused_phone']++;
        } else {
            $stats['existing']++;
        }
        // Keep profile fields current for incremental member syncs, while
        // never replacing a valid target phone with a blank source value.
        $profileName = trim((string)$member['name']);
        if ($profileName !== '') {
            $birthdayRaw = trim((string)$member['birthday']);
            $birthday = preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $birthdayRaw) === 1 && $birthdayRaw !== '0000-00-00' ? $birthdayRaw : 0;
            $updateProfile->execute([mb_substr($profileName, 0, 60), mb_substr($profileName, 0, 25), $sex, $birthday, $uid]);
        }

        if ($storeId > 0) {
            $updateBelong->execute([$storeId, $uid]);
            $insertStoreUser->execute([$storeId, $uid, $storeId, $uid]);
        }
        $upsertMap->execute([$sourceId, $uid, $resolvedPhone, 1, 'JSJ-20260917-R1']);
        $existingMap[$sourceId] = $uid;
        $upsertResolution->execute([$sourceId, $uid, $storeId, $status, $reason . '; ' . $storeReason, $sourcePhone, $resolvedPhone]);
        if ($isDuplicatePhone && $isMaster) {
            $mergedUidByPhone[$sourcePhone] = $uid;
            $mergedStoreByPhone[$sourcePhone] = $storeId;
            $upsertMerge->execute([$sourcePhone, $sourceId, $sourceId, $uid, $storeId, 'same source phone; product manager confirmed one target member']);
        }
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}

$statusCounts = $pdo->query('SELECT status,COUNT(*) rows_count FROM mig_jsj_20260917_member_resolution GROUP BY status ORDER BY status')->fetchAll();
echo json_encode(['stats' => $stats, 'status_counts' => $statusCounts], JSON_UNESCAPED_UNICODE) . PHP_EOL;
