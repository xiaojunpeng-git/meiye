<?php
declare(strict_types=1);

/**
 * Read-only post-import reconciliation for 008 member identity, gift balance,
 * points and migrated integral bills. `--source-uid=N` prints one old→new
 * identity and expected member API values without exposing phone or names.
 */
require __DIR__ . '/customer_008_morestore_member_points.php';

function m008VerifyReadOnly(array $argv): array
{
    $sourceUid = 421;
    foreach (array_slice($argv, 1) as $arg) {
        if (strpos($arg, '--source-uid=') === 0) {
            $sourceUid = (int)substr($arg, strlen('--source-uid='));
        }
    }
    if ($sourceUid <= 0) throw new RuntimeException('Positive source UID required');
    $source = m008Connect(M008_SOURCE_ENV, 'morestore', 3306);
    $target = m008Connect(M008_TARGET_ENV, 'mohe_008', 3307);
    $storeMap = m008StoreMap(__DIR__ . '/customer_008_morestore_catalog_plan.json');
    [$members, $bills, $storeUsers, $routineUsers, $currentDigest] = m008Source($source, $storeMap);
    $batch = $target->query('SELECT * FROM `' . M008_BATCH_TABLE
        . '` WHERE batch_key=\'008-morestore-member-points-r1\'')->fetch();
    if (!$batch) throw new RuntimeException('008 member import batch is absent');

    $mapping = [];
    $groups = [];
    foreach ($target->query('SELECT source_uid,target_uid,source_store_id,target_store_id,
        source_balance_yuan,source_integral,is_master FROM `' . M008_MAP_TABLE
        . '` ORDER BY source_uid') as $row) {
        $old = (int)$row['source_uid'];
        $new = (int)$row['target_uid'];
        $mapping[$old] = $row;
        if (!isset($groups[$new])) {
            $groups[$new] = ['gift' => 0, 'points' => 0, 'source_uids' => [],
                'store_id' => (int)$row['target_store_id']];
        }
        $groups[$new]['gift'] += (int)$row['source_balance_yuan'];
        $groups[$new]['points'] += (int)$row['source_integral'];
        $groups[$new]['source_uids'][] = $old;
    }
    $targetMembers = [];
    foreach ($target->query('SELECT uid,phone,now_money,ben_money,give_money,integral,belong_store_id
        FROM eb_user ORDER BY uid') as $row) {
        $targetMembers[(int)$row['uid']] = $row;
    }
    $memberDifferences = [];
    foreach ($groups as $uid => $expected) {
        $actual = $targetMembers[$uid] ?? null;
        if (!$actual || (int)$actual['now_money'] !== $expected['gift']
            || (int)$actual['give_money'] !== $expected['gift']
            || (int)$actual['ben_money'] !== 0
            || (int)$actual['integral'] !== $expected['points']
            || (int)$actual['belong_store_id'] !== $expected['store_id']) {
            $memberDifferences[] = $uid;
        }
    }
    foreach ($targetMembers as $uid => $_) {
        if (!isset($groups[$uid])) $memberDifferences[] = $uid;
    }
    $targetBills = [];
    foreach ($target->query("SELECT id,uid,link_id,pm,title,category,type,number,balance,
        mark,add_time,status,take,frozen_time,obtain_time FROM eb_user_bill
        WHERE category='integral' ORDER BY id") as $row) {
        $targetBills[(int)$row['id']] = $row;
    }
    $billDifferences = [];
    foreach ($bills as $bill) {
        $id = (int)$bill['id'];
        if (!isset($mapping[(int)$bill['uid']])) continue;
        $new = $targetBills[$id] ?? null;
        if (!$new || (int)$new['uid'] !== (int)$mapping[(int)$bill['uid']]['target_uid']
            || (int)$new['pm'] !== (int)$bill['pm']
            || (string)$new['number'] !== (string)$bill['number']
            || (int)$new['add_time'] !== (int)$bill['add_time']
            || (int)$new['obtain_time'] !== (int)$bill['add_time']
            || (int)$new['frozen_time'] !== 0) {
            $billDifferences[] = $id;
        }
    }
    $identityDifferences = [];
    $boundIdentities = [];
    foreach ($target->query('SELECT id,phone_digest,bound_uid,state,identity_version
        FROM eb_user_phone_identity ORDER BY id') as $identity) {
        $uid = (int)$identity['bound_uid'];
        $boundIdentities[$uid] = true;
        $member = $targetMembers[$uid] ?? null;
        if (!$member || (string)$identity['state'] !== 'BOUND'
            || (int)$identity['identity_version'] !== 1) {
            $identityDifferences[] = $uid;
            continue;
        }
        // Phones stay in process memory for matching; output includes only UID.
        if (hash('sha256', trim((string)$member['phone']))
            !== (string)$identity['phone_digest']) {
            $identityDifferences[] = $uid;
        }
    }
    foreach ($groups as $uid => $_) {
        $value = trim((string)($targetMembers[$uid]['phone'] ?? ''));
        if (preg_match('/^1[3-9][0-9]{9}$/D', $value) === 1
            && !isset($boundIdentities[$uid])) {
            $identityDifferences[] = $uid;
        }
    }
    $auditMismatch = (int)$target->query("SELECT COUNT(*) FROM eb_user_phone_identity i
        LEFT JOIN eb_user_phone_identity_audit a ON a.identity_id=i.id
         AND a.phone_digest=i.phone_digest AND a.after_uid=i.bound_uid
         AND a.before_uid IS NULL AND a.before_state='UNBOUND'
         AND a.after_state='BOUND' AND a.action='IMPORT_BIND'
         AND a.before_version=0 AND a.after_version=1
        WHERE a.id IS NULL")->fetchColumn();
    $sourceRoutine = [];
    foreach ($routineUsers as $row) {
        if ((int)$row['is_del'] === 0) $sourceRoutine[(int)$row['id']] = $row;
    }
    $targetRoutine = [];
    foreach ($target->query("SELECT id,uid,openid,unionid,is_del,user_type
        FROM eb_wechat_user WHERE user_type='routine'") as $row) {
        $targetRoutine[(int)$row['id']] = $row;
    }
    $routineDifferences = [];
    foreach ($sourceRoutine as $id => $old) {
        $new = $targetRoutine[$id] ?? null;
        $sourceMap = $mapping[(int)$old['uid']] ?? null;
        if (!$new || !$sourceMap
            || (int)$new['uid'] !== (int)$sourceMap['target_uid']
            || (string)$new['openid'] !== (string)$old['openid']
            || (string)$new['unionid'] !== (string)$old['unionid']
            || (int)$new['is_del'] !== 0) $routineDifferences[] = $id;
    }
    foreach ($targetRoutine as $id => $_) {
        if (!isset($sourceRoutine[$id])) $routineDifferences[] = $id;
    }
    $source->commit();
    $sampleMap = $mapping[$sourceUid] ?? null;
    $sampleTarget = $sampleMap ? $targetMembers[(int)$sampleMap['target_uid']] ?? null : null;
    $result = [
        'instance' => '008.cc3798.com / mohe_008:3307',
        'batch_source_sha256' => $batch['source_sha256'],
        'current_source_sha256' => $currentDigest,
        'source_unchanged_since_import' => hash_equals((string)$batch['source_sha256'], $currentDigest),
        'mapped_source_members' => count($mapping),
        'target_members' => count($targetMembers),
        'member_difference_count' => count($memberDifferences),
        'member_difference_target_uids_first20' => array_slice($memberDifferences, 0, 20),
        'target_integral_bill_count' => count($targetBills),
        'integral_bill_difference_count' => count($billDifferences),
        'integral_bill_difference_ids_first20' => array_slice($billDifferences, 0, 20),
        'orphan_integral_bill_count' => (int)$target->query('SELECT COUNT(*) FROM `'
            . M008_BILL_SKIP_TABLE . '`')->fetchColumn(),
        'bound_phone_identity_count' => count($boundIdentities),
        'phone_identity_audit_count' => (int)$target->query('SELECT COUNT(*)
            FROM eb_user_phone_identity_audit')->fetchColumn(),
        'phone_identity_difference_count' => count($identityDifferences),
        'phone_identity_difference_uids_first20' => array_slice($identityDifferences, 0, 20),
        'phone_identity_audit_mismatch_count' => $auditMismatch,
        'active_source_routine_identity_count' => count($sourceRoutine),
        'target_routine_identity_count' => count($targetRoutine),
        'routine_identity_difference_count' => count($routineDifferences),
        'routine_identity_difference_ids_first20' => array_slice($routineDifferences, 0, 20),
        'sample_source_uid' => $sourceUid,
        'sample_target_uid' => $sampleMap ? (int)$sampleMap['target_uid'] : null,
        'sample_group_source_uids' => $sampleMap
            ? $groups[(int)$sampleMap['target_uid']]['source_uids'] : [],
        'sample_routine_identity_count' => $sampleMap ? count(array_filter(
            $targetRoutine,
            static fn(array $row): bool => (int)$row['uid'] === (int)$sampleMap['target_uid'])) : 0,
        'sample_expected_member_api' => $sampleTarget ? [
            'now_money' => (int)$sampleTarget['now_money'],
            'ben_money' => (int)$sampleTarget['ben_money'],
            'give_money' => (int)$sampleTarget['give_money'],
            'integral' => (int)$sampleTarget['integral'],
        ] : null,
    ];
    if (!hash_equals((string)$batch['source_sha256'], $currentDigest)
        || count($mapping) !== (int)$batch['source_members']
        || count($targetMembers) !== (int)$batch['target_members']
        || count($targetBills) !== (int)$batch['integral_bills']
        || $memberDifferences || $billDifferences || $identityDifferences
        || $routineDifferences
        || $auditMismatch !== 0
        || count($boundIdentities) !== (int)$target->query('SELECT COUNT(*)
            FROM eb_user_phone_identity_audit')->fetchColumn()) {
        $result['result'] = 'FAIL';
    } else {
        $result['result'] = 'PASS';
    }
    return $result;
}

try {
    $result = m008VerifyReadOnly($argv);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    if ($result['result'] !== 'PASS') exit(1);
} catch (Throwable $error) {
    fwrite(STDERR, 'member verification stopped: ' . $error->getMessage() . "\n");
    exit(1);
}
