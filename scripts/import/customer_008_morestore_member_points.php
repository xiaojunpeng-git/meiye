<?php
declare(strict_types=1);

/**
 * 008 渼约会员、赠金与积分历史导入。
 *
 * 每个旧 UID 先按旧会员端口径截取余额整数，再按确认过的同手机号组汇总。
 * 积分主档采用旧会员当前值；历史流水只作到期依据与审计，不重复加分。
 * 本脚本只处理会员资产，不生成销售、充值或经营事实。
 *
 * 先运行 --plan 固定来源指纹；正式执行还须提供已验收的指纹及显式目标守卫。
 */

const M008_SOURCE_ENV = '/www/wwwroot/morestore.cc3798.com/.env';
const M008_TARGET_ENV = '/www/wwwroot/008.cc3798.com/.env';
const M008_MAP_TABLE = 'mig_morestore_20261013_member_map';
const M008_BILL_SKIP_TABLE = 'mig_morestore_20261013_integral_bill_skip';
const M008_BATCH_TABLE = 'mig_morestore_20261013_member_batch';

/** Open only the intended instance and verify both the schema and TCP port. */
function m008Connect(string $envPath, string $schema, int $port): PDO
{
    $env = parse_ini_file($envPath);
    if (!is_array($env) || ($env['DATABASE'] ?? '') !== $schema
        || (int)($env['HOSTPORT'] ?? 0) !== $port) {
        throw new RuntimeException('Instance environment identity changed: ' . $schema);
    }
    $pdo = new PDO('mysql:host=' . $env['HOSTNAME'] . ';port=' . $port
        . ';dbname=' . $schema . ';charset=utf8mb4', $env['USERNAME'], $env['PASSWORD'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    $identity = $pdo->query('SELECT DATABASE() schema_name, @@port tcp_port')->fetch();
    if (($identity['schema_name'] ?? '') !== $schema || (int)$identity['tcp_port'] !== $port) {
        throw new RuntimeException('Connected MySQL identity changed: ' . $schema);
    }
    return $pdo;
}

/** Fetch only the source fields whose values are copied or audited. */
function m008Source(PDO $source, array $storeMap): array
{
    $source->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $source->beginTransaction();
    $members = $source->query("SELECT uid,account,pwd,real_name,nickname,avatar,phone,
        birthday,card_id,mark,add_time,sex,status,store_id,now_money,integral,
        addres,provincials,province,city,area,street,user_type,login_type
        FROM eb_user WHERE is_del=0 ORDER BY uid")->fetchAll();
    $bills = $source->query("SELECT id,uid,link_id,pm,title,category,type,number,
        balance,mark,add_time,status,take FROM eb_user_bill
        WHERE category='integral' ORDER BY id")->fetchAll();
    $storeUsers = $source->query("SELECT su.id,su.uid,su.store_id,su.status,su.add_time
        FROM eb_store_user su JOIN eb_user u ON u.uid=su.uid AND u.is_del=0
        ORDER BY su.id")->fetchAll();
    // OpenID is AppID-scoped. Only the previously verified same-AppID routine
    // identities are in scope; other WeChat/app/Apple identities stay out.
    $routineUsers = $source->query("SELECT id,uid,unionid,openid,nickname,headimgurl,
        sex,city,language,province,country,remark,groupid,tagid_list,subscribe,
        subscribe_time,add_time,second,user_type,is_complete,is_del
        FROM eb_wechat_user WHERE user_type='routine' ORDER BY id")->fetchAll();
    // The store mapping changes a member's access context, so it belongs to
    // the same reviewed fingerprint as the source rows.
    $fingerprint = hash('sha256', json_encode([
        $members, $bills, $storeUsers, $routineUsers, $storeMap],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    return [$members, $bills, $storeUsers, $routineUsers, $fingerprint];
}

/** The approved catalogue mapping is the only source of active old-store IDs. */
function m008StoreMap(string $catalogPlanPath): array
{
    // The read-only stdin rehearsal has no deployed source tree. Its map is
    // injected from the reviewed local JSON; the write path requires the file.
    if (!is_file($catalogPlanPath)) {
        $json = getenv('M008_CATALOG_STORE_MAP_JSON') ?: '';
        $candidate = json_decode($json, true);
        if (!is_array($candidate) || count($candidate) !== 22) {
            throw new RuntimeException('Approved 008 store map unavailable');
        }
        $plan = ['source_database' => 'morestore', 'target_database' => 'mohe_008',
            'store_map' => $candidate];
    } else {
    $plan = json_decode((string)file_get_contents($catalogPlanPath), true, 512, JSON_THROW_ON_ERROR);
    }
    if (($plan['source_database'] ?? '') !== 'morestore'
        || ($plan['target_database'] ?? '') !== 'mohe_008'
        || count($plan['store_map'] ?? []) !== 22) {
        throw new RuntimeException('Approved 008 store map missing or changed');
    }
    $map = [];
    foreach ($plan['store_map'] as $sourceId => $targetId) {
        if ((int)$sourceId <= 0 || (int)$targetId <= 0) {
            throw new RuntimeException('Invalid approved store ID');
        }
        $map[(int)$sourceId] = (int)$targetId;
    }
    return $map;
}

/**
 * Assign one deterministic target UID per exact duplicate phone group.
 * Empty or invalid phones are never merged. Group totals are built from each
 * old account's displayed balance and current integral, before any merge.
 */
function m008Resolution(array $members, array $storeUsers, array $storeMap): array
{
    $phoneGroups = [];
    foreach ($members as $row) {
        $uid = (int)$row['uid'];
        $phone = trim((string)$row['phone']);
        if (preg_match('/^1[3-9][0-9]{9}$/D', $phone) === 1) {
            $phoneGroups[$phone][] = $uid;
        }
    }
    $memberships = [];
    foreach ($storeUsers as $row) {
        $targetStore = $storeMap[(int)$row['store_id']] ?? 0;
        if ($targetStore > 0 && (int)$row['status'] === 1) {
            $memberships[(int)$row['uid']][$targetStore] = true;
        }
    }
    $rows = [];
    $groupMembers = [];
    foreach ($members as $row) {
        $uid = (int)$row['uid'];
        $phone = trim((string)$row['phone']);
        $group = $phoneGroups[$phone] ?? [];
        $master = count($group) > 1 ? min($group) : $uid;
        $balance = (int)(string)$row['now_money']; // 旧会员端 userInfo() 的 intval 口径。
        $points = (int)$row['integral'];
        if ($balance < 0 || $points < 0) {
            throw new RuntimeException('Unexpected negative member asset: ' . $uid);
        }
        $rows[$uid] = [
            'source_uid' => $uid, 'target_uid' => $master, 'master_source_uid' => $master,
            'source_phone' => $phone, 'source_store_id' => (int)$row['store_id'],
            'target_store_id' => 0, 'source_balance_yuan' => $balance,
            'source_integral' => $points, 'is_master' => $uid === $master ? 1 : 0,
            'source_nickname' => (string)$row['nickname'],
            'source_real_name' => (string)$row['real_name'],
            'source_profile_sha256' => hash('sha256', json_encode($row,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
        ];
        $groupMembers[$master][] = $uid;
    }
    $groups = [];
    foreach ($groupMembers as $master => $sourceUids) {
        sort($sourceUids, SORT_NUMERIC);
        $targetStore = 0;
        // Prefer the representative's old operating store, then any sibling's
        // operating store, then an explicitly related operating store.
        foreach ($sourceUids as $uid) {
            $targetStore = $storeMap[$rows[$uid]['source_store_id']] ?? 0;
            if ($targetStore > 0) break;
        }
        if ($targetStore === 0) {
            foreach ($sourceUids as $uid) {
                $candidateStores = array_keys($memberships[$uid] ?? []);
                sort($candidateStores, SORT_NUMERIC);
                if ($candidateStores) {
                    $targetStore = $candidateStores[0];
                    break;
                }
            }
        }
        $balance = 0;
        $points = 0;
        foreach ($sourceUids as $uid) {
            $rows[$uid]['target_store_id'] = $targetStore;
            $balance += $rows[$uid]['source_balance_yuan'];
            $points += $rows[$uid]['source_integral'];
        }
        $groups[$master] = [
            'target_uid' => $master, 'source_uids' => $sourceUids,
            'target_store_id' => $targetStore, 'balance_yuan' => $balance,
            'integral' => $points,
        ];
    }
    ksort($rows, SORT_NUMERIC);
    ksort($groups, SORT_NUMERIC);
    return [$rows, $groups, $memberships];
}

/** Summarize the fixed work set without printing member PII. */
function m008Summary(array $members, array $bills, array $routineUsers,
                     array $map, array $groups, string $digest): array
{
    $bySource = array_fill_keys(array_keys($map), true);
    $validBills = 0;
    $orphans = [];
    foreach ($bills as $bill) {
        if (isset($bySource[(int)$bill['uid']])) $validBills++;
        else $orphans[] = (int)$bill['id'];
    }
    $activeRoutine = 0;
    $inactiveRoutine = 0;
    $routineOpenids = [];
    $routineUnionOwners = [];
    foreach ($routineUsers as $routine) {
        if ((int)$routine['is_del'] !== 0) {
            $inactiveRoutine++;
            continue;
        }
        $oldUid = (int)$routine['uid'];
        $openid = trim((string)$routine['openid']);
        $unionid = trim((string)$routine['unionid']);
        if (!isset($map[$oldUid]) || $openid === '' || isset($routineOpenids[$openid])) {
            throw new RuntimeException('Routine identity orphan, blank OpenID or duplicate OpenID');
        }
        $routineOpenids[$openid] = true;
        if ($unionid !== '') {
            $targetUid = (int)$map[$oldUid]['target_uid'];
            if (isset($routineUnionOwners[$unionid])
                && $routineUnionOwners[$unionid] !== $targetUid) {
                throw new RuntimeException('Routine UnionID points to different target members');
            }
            $routineUnionOwners[$unionid] = $targetUid;
        }
        $activeRoutine++;
    }
    $mergedGroups = 0;
    $mergedSources = 0;
    $noStore = 0;
    $balance = 0;
    $points = 0;
    $boundPhones = 0;
    foreach ($groups as $group) {
        if (count($group['source_uids']) > 1) {
            $mergedGroups++;
            $mergedSources += count($group['source_uids']);
        }
        if ($group['target_store_id'] === 0) $noStore++;
        $balance += $group['balance_yuan'];
        $points += $group['integral'];
        $masterPhone = (string)$map[$group['target_uid']]['source_phone'];
        if (preg_match('/^1[3-9][0-9]{9}$/D', $masterPhone) === 1) $boundPhones++;
    }
    return [
        'source_database' => 'morestore', 'target_database' => 'mohe_008',
        'source_sha256' => $digest, 'source_members' => count($members),
        'target_members' => count($groups), 'merged_phone_groups' => $mergedGroups,
        'merged_source_members' => $mergedSources, 'members_without_mapped_store' => $noStore,
        'gift_balance_yuan' => $balance, 'principal_yuan' => 0, 'current_integral' => $points,
        'bound_phone_identities' => $boundPhones,
        'source_integral_bills' => count($bills), 'importable_integral_bills' => $validBills,
        'source_routine_identities' => count($routineUsers),
        'imported_routine_identities' => $activeRoutine,
        'inactive_routine_identities' => $inactiveRoutine,
        'orphan_bill_ids' => $orphans,
        'sample_421' => isset($map[421]) ? [
            'target_uid' => $map[421]['target_uid'],
            'source_balance_yuan' => $map[421]['source_balance_yuan'],
            'source_integral' => $map[421]['source_integral'],
        ] : null,
    ];
}

/** Guard against ever layering an initial balance onto existing 008 assets. */
function m008AssertEmptyTarget(PDO $target, array $storeMap): void
{
    foreach (['eb_user', 'eb_user_bill', 'eb_user_money', 'eb_store_user',
                 'eb_user_phone_identity', 'eb_user_phone_identity_audit',
                 'eb_wechat_user',
                 M008_MAP_TABLE, M008_BATCH_TABLE] as $table) {
        $count = (int)$target->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
        if ($count !== 0) throw new RuntimeException('008 member asset table is not empty: ' . $table);
    }
    $stmt = $target->prepare('SELECT COUNT(*) FROM eb_system_store WHERE id=? AND is_del=0');
    foreach (array_unique(array_values($storeMap)) as $storeId) {
        $stmt->execute([$storeId]);
        if ((int)$stmt->fetchColumn() !== 1) {
            throw new RuntimeException('Mapped 008 store missing: ' . $storeId);
        }
    }
}

/** Execute the single SQL migration正本 before the data transaction. */
function m008CreateAuditTables(PDO $target): void
{
    $sqlPath = dirname(__DIR__, 2) . '/后端代码/database/upgrades/'
        . '2026-10-13-008渼约会员积分导入/01-映射与审计.sql';
    if (!is_file($sqlPath)) {
        throw new RuntimeException('008 member audit SQL migration missing');
    }
    $sql = (string)file_get_contents($sqlPath);
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    if (count($statements) !== 3) {
        throw new RuntimeException('008 member audit SQL migration changed shape');
    }
    foreach ($statements as $statement) $target->exec($statement);
}

/** Apply the frozen initial state in one transaction; duplicate runs fail closed. */
function m008Apply(PDO $target, array $members, array $bills, array $storeUsers,
                   array $routineUsers,
                   array $map, array $groups, array $memberships,
                   array $summary, array $storeMap): void
{
    m008CreateAuditTables($target);
    m008AssertEmptyTarget($target, $storeMap);
    $target->beginTransaction();
    try {
        $sourceByUid = [];
        foreach ($members as $row) $sourceByUid[(int)$row['uid']] = $row;
        $insertMember = $target->prepare('INSERT INTO eb_user
            (uid,account,pwd,real_name,nickname,avatar,phone,birthday,card_id,mark,
             add_time,sex,status,is_del,now_money,ben_money,give_money,integral,
             belong_store_id,addres,provincials,province,city,area,street,
             user_type,login_type,balance_version)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,0,?,?,?,?,?,?,?,?,?,?,?,?,?,1)');
        foreach ($groups as $master => $group) {
            $profile = $sourceByUid[$master];
            // The main profile keeps the representative's original channel.
            // Mini-program login resolves through eb_wechat_user OpenID, so a
            // merged sibling's routine identity does not rewrite this field.
            $insertMember->execute([
                $master, $profile['account'], $profile['pwd'], $profile['real_name'],
                $profile['nickname'], $profile['avatar'], $profile['phone'],
                $profile['birthday'], $profile['card_id'], $profile['mark'],
                $profile['add_time'], $profile['sex'], $profile['status'],
                $group['balance_yuan'], 0, $group['balance_yuan'], $group['integral'],
                $group['target_store_id'], $profile['addres'], $profile['provincials'],
                $profile['province'], $profile['city'], $profile['area'], $profile['street'],
                $profile['user_type'], $profile['login_type'],
            ]);
        }
        $insertRoutine = $target->prepare('INSERT INTO eb_wechat_user
            (id,uid,unionid,openid,nickname,headimgurl,sex,city,language,province,
             country,remark,groupid,tagid_list,subscribe,subscribe_time,add_time,
             second,user_type,is_complete,is_del)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0)');
        foreach ($routineUsers as $routine) {
            if ((int)$routine['is_del'] !== 0) continue;
            $oldUid = (int)$routine['uid'];
            $insertRoutine->execute([
                $routine['id'], $map[$oldUid]['target_uid'], $routine['unionid'],
                $routine['openid'], $routine['nickname'], $routine['headimgurl'],
                $routine['sex'], $routine['city'], $routine['language'],
                $routine['province'], $routine['country'], $routine['remark'],
                $routine['groupid'], $routine['tagid_list'], $routine['subscribe'],
                $routine['subscribe_time'], $routine['add_time'], $routine['second'],
                $routine['user_type'], $routine['is_complete'],
            ]);
        }
        // The new mobile login reads canonical phone identities, not merely
        // eb_user.phone. Create one bound identity per unique valid target
        // phone, with a durable import audit in this same transaction.
        $insertIdentity = $target->prepare('INSERT INTO eb_user_phone_identity
            (phone_digest,bound_uid,state,identity_version,bound_at,released_at,
             created_at,updated_at) VALUES (?,?,\'BOUND\',1,?,0,?,?)');
        $insertIdentityAudit = $target->prepare('INSERT INTO eb_user_phone_identity_audit
            (identity_id,phone_digest,before_uid,after_uid,before_state,after_state,
             before_version,after_version,action,operator_type,operator_id,source,
             request_id,occurred_at,recorded_at)
            VALUES (?, ?, NULL, ?, \'UNBOUND\', \'BOUND\', 0, 1,
                    \'IMPORT_BIND\', \'SYSTEM\', 0, \'008_MORESTORE_IMPORT\', ?, ?, ?)');
        $boundAt = time();
        foreach ($groups as $master => $group) {
            $phone = trim((string)$sourceByUid[$master]['phone']);
            if (preg_match('/^1[3-9][0-9]{9}$/D', $phone) !== 1) continue;
            $digest = hash('sha256', $phone);
            $insertIdentity->execute([$digest, $master, $boundAt, $boundAt, $boundAt]);
            $identityId = (int)$target->lastInsertId();
            $requestId = hash('sha256', '008-morestore-member-phone:' . $master . ':' . $digest);
            $insertIdentityAudit->execute([
                $identityId, $digest, $master, $requestId, $boundAt, $boundAt,
            ]);
        }
        $insertMap = $target->prepare('INSERT INTO `' . M008_MAP_TABLE . '`
            (source_uid,target_uid,master_source_uid,source_phone,source_store_id,
             target_store_id,source_balance_yuan,source_integral,source_nickname,
             source_real_name,source_profile_sha256,is_master,created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())');
        foreach ($map as $row) {
            $insertMap->execute([
                $row['source_uid'], $row['target_uid'], $row['master_source_uid'],
                $row['source_phone'], $row['source_store_id'], $row['target_store_id'],
                $row['source_balance_yuan'], $row['source_integral'],
                $row['source_nickname'], $row['source_real_name'],
                $row['source_profile_sha256'], $row['is_master'],
            ]);
        }
        $storeLinks = [];
        foreach ($groups as $master => $group) {
            if ($group['target_store_id'] > 0) {
                $storeLinks[$master . ':' . $group['target_store_id']] =
                    [$master, $group['target_store_id']];
            }
            foreach ($group['source_uids'] as $uid) {
                foreach (array_keys($memberships[$uid] ?? []) as $storeId) {
                    $storeLinks[$master . ':' . $storeId] = [$master, $storeId];
                }
            }
        }
        $insertStoreLink = $target->prepare('INSERT INTO eb_store_user
            (store_id,uid,status,add_time) VALUES (?,?,1,UNIX_TIMESTAMP())');
        foreach ($storeLinks as [$uid, $storeId]) {
            $insertStoreLink->execute([$storeId, $uid]);
        }
        $insertBill = $target->prepare('INSERT INTO eb_user_bill
            (id,uid,link_id,pm,title,category,type,number,balance,mark,add_time,
             status,take,frozen_time,obtain_time)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,0,?)');
        $skipBill = $target->prepare('INSERT INTO `' . M008_BILL_SKIP_TABLE . '`
            (source_bill_id,source_uid,reason,created_at) VALUES (?, ?, ?, NOW())');
        foreach ($bills as $bill) {
            $sourceUid = (int)$bill['uid'];
            if (!isset($map[$sourceUid])) {
                $skipBill->execute([(int)$bill['id'], $sourceUid, 'missing_or_deleted_member']);
                continue;
            }
            $insertBill->execute([
                $bill['id'], $map[$sourceUid]['target_uid'], $bill['link_id'],
                $bill['pm'], $bill['title'], $bill['category'], $bill['type'],
                $bill['number'], $bill['balance'], $bill['mark'], $bill['add_time'],
                $bill['status'], $bill['take'], $bill['add_time'],
            ]);
        }
        $scriptHash = hash_file('sha256', __FILE__);
        $batch = $target->prepare('INSERT INTO `' . M008_BATCH_TABLE . '`
            (batch_key,source_sha256,script_sha256,source_members,target_members,
             gift_balance_yuan,current_integral,integral_bills,created_at)
            VALUES (?,?,?,?,?,?,?,?,NOW())');
        $batch->execute([
            '008-morestore-member-points-r1', $summary['source_sha256'], $scriptHash,
            $summary['source_members'], $summary['target_members'],
            $summary['gift_balance_yuan'], $summary['current_integral'],
            $summary['importable_integral_bills'],
        ]);
        m008Verify($target, $map, $groups, $routineUsers, $summary);
        $target->commit();
    } catch (Throwable $error) {
        if ($target->inTransaction()) $target->rollBack();
        throw $error;
    }
}

/** Fail the transaction on any lost source identity or altered asset total. */
function m008Verify(PDO $target, array $map, array $groups,
                    array $routineUsers, array $summary): void
{
    $row = $target->query('SELECT COUNT(*) members,COALESCE(SUM(now_money),0) balance,
        COALESCE(SUM(give_money),0) gift,COALESCE(SUM(ben_money),0) principal,
        COALESCE(SUM(integral),0) points,
        COALESCE(SUM(CASE WHEN now_money=ben_money+give_money THEN 0 ELSE 1 END),0) mismatches
        FROM eb_user')->fetch();
    if ((int)$row['members'] !== count($groups)
        || (int)$row['balance'] !== $summary['gift_balance_yuan']
        || (int)$row['gift'] !== $summary['gift_balance_yuan']
        || (int)$row['principal'] !== 0 || (int)$row['points'] !== $summary['current_integral']
        || (int)$row['mismatches'] !== 0) {
        throw new RuntimeException('008 member asset totals differ from frozen source');
    }
    $checks = [
        M008_MAP_TABLE => count($map),
        'eb_user_bill' => $summary['importable_integral_bills'],
        'eb_wechat_user' => $summary['imported_routine_identities'],
        'eb_user_phone_identity' => $summary['bound_phone_identities'],
        'eb_user_phone_identity_audit' => $summary['bound_phone_identities'],
        M008_BILL_SKIP_TABLE => count($summary['orphan_bill_ids']),
    ];
    foreach ($checks as $table => $expected) {
        if ((int)$target->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn() !== $expected) {
            throw new RuntimeException('008 row-count check failed: ' . $table);
        }
    }
    $perMemberDifferences = (int)$target->query('SELECT COUNT(*) FROM eb_user u
        JOIN (SELECT target_uid,SUM(source_balance_yuan) gift,
                     SUM(source_integral) points
              FROM `' . M008_MAP_TABLE . '` GROUP BY target_uid) x ON x.target_uid=u.uid
        WHERE u.now_money<>x.gift OR u.give_money<>x.gift
           OR u.ben_money<>0 OR u.integral<>x.points')->fetchColumn();
    if ($perMemberDifferences !== 0) {
        throw new RuntimeException('008 individual member assets differ from source mapping');
    }
    $identityDifferences = (int)$target->query("SELECT COUNT(*) FROM eb_user_phone_identity i
        LEFT JOIN eb_user u ON u.uid=i.bound_uid
        WHERE i.state<>'BOUND' OR i.identity_version<>1 OR u.uid IS NULL
           OR SHA2(TRIM(u.phone),256)<>i.phone_digest")->fetchColumn();
    if ($identityDifferences !== 0) {
        throw new RuntimeException('008 canonical phone identities do not match target users');
    }
    $auditDifferences = (int)$target->query("SELECT COUNT(*) FROM eb_user_phone_identity i
        LEFT JOIN eb_user_phone_identity_audit a ON a.identity_id=i.id
         AND a.phone_digest=i.phone_digest AND a.after_uid=i.bound_uid
         AND a.before_uid IS NULL AND a.before_state='UNBOUND'
         AND a.after_state='BOUND' AND a.action='IMPORT_BIND'
         AND a.before_version=0 AND a.after_version=1
        WHERE a.id IS NULL")->fetchColumn();
    if ($auditDifferences !== 0) {
        throw new RuntimeException('008 imported phone binding audit is incomplete');
    }
    $routineById = [];
    foreach ($routineUsers as $row) {
        if ((int)$row['is_del'] === 0) $routineById[(int)$row['id']] = $row;
    }
    foreach ($target->query("SELECT id,uid,openid,unionid,is_del,user_type
        FROM eb_wechat_user WHERE user_type='routine'") as $row) {
        $old = $routineById[(int)$row['id']] ?? null;
        if (!$old || (int)$row['uid'] !== (int)$map[(int)$old['uid']]['target_uid']
            || (string)$row['openid'] !== (string)$old['openid']
            || (string)$row['unionid'] !== (string)$old['unionid']
            || (int)$row['is_del'] !== 0) {
            throw new RuntimeException('008 routine OpenID identity did not retain old owner');
        }
    }
    if (isset($map[421])) {
        $statement = $target->prepare('SELECT now_money,ben_money,give_money,integral
            FROM eb_user WHERE uid=?');
        $statement->execute([$map[421]['target_uid']]);
        $sample = $statement->fetch();
        if (!$sample || (int)$sample['now_money'] !== 13692
            || (int)$sample['give_money'] !== 13692
            || (int)$sample['ben_money'] !== 0 || (int)$sample['integral'] !== 4950) {
            throw new RuntimeException('008 UID421 browser sample changed or failed');
        }
    }
}

/** Read-only source plan, or guarded one-time import after fixed-version acceptance. */
function m008Main(array $argv): void
{
    $args = array_slice($argv, 1);
    $apply = in_array('--execute', $args, true);
    if ($apply && !in_array('--allow-mohe-008', $args, true)) {
        throw new RuntimeException('Missing explicit --allow-mohe-008');
    }
    $expectedDigest = '';
    $expectedScript = '';
    foreach ($args as $arg) {
        if (strpos($arg, '--source-sha256=') === 0) {
            $expectedDigest = substr($arg, strlen('--source-sha256='));
        } elseif (strpos($arg, '--script-sha256=') === 0) {
            $expectedScript = substr($arg, strlen('--script-sha256='));
        }
    }
    if ($apply && (preg_match('/^[a-f0-9]{64}$/D', $expectedDigest) !== 1
        || preg_match('/^[a-f0-9]{64}$/D', $expectedScript) !== 1
        || !hash_equals($expectedScript, hash_file('sha256', __FILE__)))) {
        throw new RuntimeException('Fixed source and script SHA-256 required for execution');
    }
    $catalogPlan = dirname(__FILE__) . '/customer_008_morestore_catalog_plan.json';
    if ($apply && !is_file($catalogPlan)) {
        throw new RuntimeException('Write execution requires deployed approved catalogue plan');
    }
    $storeMap = m008StoreMap($catalogPlan);
    $source = m008Connect(M008_SOURCE_ENV, 'morestore', 3306);
    [$members, $bills, $storeUsers, $routineUsers, $digest] = m008Source($source, $storeMap);
    [$map, $groups, $memberships] = m008Resolution($members, $storeUsers, $storeMap);
    $summary = m008Summary($members, $bills, $routineUsers, $map, $groups, $digest);
    if (!$apply) {
        $source->commit();
        echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
        return;
    }
    if (!hash_equals($expectedDigest, $digest)) {
        throw new RuntimeException('Source changed after fixed plan; replan and reaccept');
    }
    $target = m008Connect(M008_TARGET_ENV, 'mohe_008', 3307);
    if ((int)$target->query("SELECT GET_LOCK('008_morestore_member_points',15)")->fetchColumn() !== 1) {
        throw new RuntimeException('008 member import lock unavailable');
    }
    try {
        m008Apply($target, $members, $bills, $storeUsers, $routineUsers, $map, $groups,
            $memberships, $summary, $storeMap);
        $source->commit();
        echo json_encode(['result' => 'committed'] + $summary,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";
    } finally {
        $target->query("SELECT RELEASE_LOCK('008_morestore_member_points')");
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        m008Main($argv);
    } catch (Throwable $error) {
        fwrite(STDERR, 'member import stopped: ' . $error->getMessage() . "\n");
        exit(1);
    }
}
