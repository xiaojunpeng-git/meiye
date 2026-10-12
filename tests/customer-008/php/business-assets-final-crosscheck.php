<?php
declare(strict_types=1);

/**
 * Independent, read-only 008 asset reconciliation against the frozen old H5
 * rights list and the member batch's per-source-UID mapping. It does not
 * create a member, right, bill, order or migration marker.
 *
 * Usage: php business-assets-final-crosscheck.php
 *   --snapshot-file=/private/rights.jsonl --expected-source-sha=<sha256>
 */

/** Parse a required named argument without interpreting it as shell text. */
function assetOption(array $argv, string $key): string
{
    $prefix = '--' . $key . '=';
    foreach ($argv as $arg) {
        if (strpos($arg, $prefix) === 0) return substr($arg, strlen($prefix));
    }
    throw new RuntimeException('Missing --' . $key);
}

/** Isolation may pass a fixture env; both paths must still prove 008 schema and port. */
function assetOptionalOption(array $argv, string $key, string $default): string
{
    $prefix = '--' . $key . '=';
    foreach ($argv as $arg) {
        if (strpos($arg, $prefix) === 0) return substr($arg, strlen($prefix));
    }
    return $default;
}

/** Refuse any environment other than the dedicated 008 MySQL instance. */
function assetTarget(string $envPath): PDO
{
    $env = parse_ini_file($envPath);
    if (!is_array($env) || ($env['DATABASE'] ?? '') !== 'mohe_008'
        || (int)($env['HOSTPORT'] ?? 0) !== 3307) {
        throw new RuntimeException('Target is not mohe_008:3307');
    }
    $db = new PDO('mysql:host=' . $env['HOSTNAME'] . ';port=3307;dbname=mohe_008;charset=utf8mb4',
        $env['USERNAME'], $env['PASSWORD'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    $identity = $db->query('SELECT DATABASE() db,@@port port')->fetch();
    if (($identity['db'] ?? '') !== 'mohe_008' || (int)$identity['port'] !== 3307) {
        throw new RuntimeException('Connected database identity differs');
    }
    return $db;
}

/** Read the exact old H5 list frozen before import; keep names out of output. */
function assetSnapshot(string $file, string $expectedSha): array
{
    if (preg_match('/^[a-f0-9]{64}$/D', $expectedSha) !== 1 || !is_file($file)
        || !hash_equals($expectedSha, (string)hash_file('sha256', $file))) {
        throw new RuntimeException('Frozen rights snapshot fingerprint differs');
    }
    $rows = [];
    $handle = fopen($file, 'r');
    if ($handle === false) throw new RuntimeException('Snapshot unavailable');
    try {
        while (($line = fgets($handle)) !== false) {
            $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $id = (int)($row['source_detail_id'] ?? 0);
            if ($id <= 0 || isset($rows[$id])) throw new RuntimeException('Duplicate source detail');
            $rows[$id] = $row;
        }
    } finally {
        fclose($handle);
    }
    if (!$rows) throw new RuntimeException('Empty frozen rights snapshot');
    return $rows;
}

/** Compare current member assets to every source UID, including merged phones. */
function assetMembers(PDO $db): array
{
    $map = [];
    $expected = [];
    foreach ($db->query('SELECT source_uid,target_uid,source_balance_yuan,source_integral
        FROM mig_morestore_20261013_member_map') as $row) {
        $sourceUid = (int)$row['source_uid'];
        $targetUid = (int)$row['target_uid'];
        if ($sourceUid <= 0 || $targetUid <= 0 || isset($map[$sourceUid])) {
            throw new RuntimeException('Member mapping is not one-to-one by old UID');
        }
        $map[$sourceUid] = $targetUid;
        $expected[$targetUid]['gift'] = ($expected[$targetUid]['gift'] ?? 0) + (int)$row['source_balance_yuan'];
        $expected[$targetUid]['points'] = ($expected[$targetUid]['points'] ?? 0) + (int)$row['source_integral'];
    }
    $actualCount = 0;
    $gift = 0;
    $points = 0;
    foreach ($db->query('SELECT uid,now_money,ben_money,give_money,integral FROM eb_user') as $row) {
        $uid = (int)$row['uid'];
        if (!isset($expected[$uid])) throw new RuntimeException('Unexpected target member ' . $uid);
        $want = $expected[$uid];
        if ((int)$row['now_money'] !== $want['gift']
            || (int)$row['give_money'] !== $want['gift']
            || (int)$row['ben_money'] !== 0
            || (int)$row['integral'] !== $want['points']) {
            throw new RuntimeException('Member asset mismatch at target UID ' . $uid);
        }
        $actualCount++;
        $gift += $want['gift'];
        $points += $want['points'];
    }
    if (count($map) !== 5729 || $actualCount !== count($expected) || $actualCount !== 5722
        || $gift !== 590340 || $points !== 124793 || ($map[421] ?? 0) !== 421) {
        throw new RuntimeException('Member batch cardinality or totals differ');
    }
    if ($expected[421]['gift'] !== 13692 || $expected[421]['points'] !== 4950) {
        throw new RuntimeException('Provided member sample balance or points differ');
    }
    return [$map, ['source_members' => count($map), 'target_members' => $actualCount,
        'gift_balance_yuan' => $gift, 'principal_yuan' => 0, 'points' => $points,
        'sample_uid_421' => ['gift_balance_yuan' => 13692, 'points' => 4950]]];
}

/**
 * Match every purchased line to one migration scope and one current holder.
 * Holder remaining times are the current balance for a single old H5 line;
 * a choice card's component lines are candidates, not extra grants.
 */
function assetRights(PDO $db, array $source, array $memberMap): array
{
    $seen = [];
    $remaining = 0;
    $sample = [];
    $expiredOverrides = [];
    $ownerIds = $db->query("SELECT id FROM eb_organization WHERE name='渼约' AND status=1 AND is_del=0")
        ->fetchAll(PDO::FETCH_COLUMN);
    if (count($ownerIds) !== 1) throw new RuntimeException('008 渼约 organization is not unique');
    $ownerId = (int)$ownerIds[0];
    $query = $db->query("SELECT s.source_detail_id,s.source_uid,s.target_uid,s.source_snapshot_json,
        s.owner_org_id,
        h.id holder_id,h.uid holder_uid,h.card_name,h.write_surplus_times,h.write_end,h.store_id,h.is_del
        FROM eb_migration_entitlement_scope s
        JOIN eb_user_card_holder h ON h.id=s.card_holder_id
        WHERE s.tenant_id='0' AND s.source_system='morestore' AND s.status='active'");
    foreach ($query as $row) {
        $id = (int)$row['source_detail_id'];
        if (!isset($source[$id]) || isset($seen[$id])) {
            throw new RuntimeException('Unexpected or repeated target right ' . $id);
        }
        $old = $source[$id];
        $snapshot = json_decode((string)$row['source_snapshot_json'], true, 512, JSON_THROW_ON_ERROR);
        $uid = (int)$old['source_uid'];
        $targetUid = $memberMap[$uid] ?? 0;
        if (!is_array($snapshot) || (int)$row['source_uid'] !== $uid
            || (int)$row['target_uid'] !== $targetUid || (int)$row['holder_uid'] !== $targetUid
            || (int)$row['owner_org_id'] !== $ownerId
            || (int)$row['store_id'] !== 0 || (int)$row['is_del'] !== 0
            || (string)$row['card_name'] !== (string)$old['purchase_name']
            || (int)$row['write_surplus_times'] !== (int)$old['source_remaining_times']
            || (int)$row['write_end'] !== (int)$old['target_write_end']
            || (int)($snapshot['source_detail_id'] ?? 0) !== $id
            || (int)($snapshot['source_remaining_times'] ?? -1) !== (int)$old['source_remaining_times']) {
            throw new RuntimeException('Right detail differs from old H5 at ' . $id);
        }
        foreach ($old as $key => $value) {
            if (!array_key_exists($key, $snapshot) || $snapshot[$key] !== $value) {
                throw new RuntimeException('Frozen right audit differs at ' . $id . ':' . $key);
            }
        }
        $seen[$id] = true;
        $remaining += (int)$row['write_surplus_times'];
        if ((int)$old['source_write_end'] > 0 && (int)$old['target_write_end'] === 0) {
            $expiredOverrides[] = $id;
        }
        if ($uid === 421) {
            $sample[$id] = ['remaining' => (int)$row['write_surplus_times'],
                'name_sha256' => hash('sha256', (string)$row['card_name'])];
        }
    }
    sort($expiredOverrides, SORT_NUMERIC);
    if (count($seen) !== count($source) || count($seen) !== 6531 || $remaining !== 61228
        || count($sample) !== 15
        || $expiredOverrides !== [11082, 11083, 11084, 11087, 11127, 11356]) {
        throw new RuntimeException('Rights count, total remaining, or provided sample differs');
    }
    foreach ($source as $id => $old) {
        if (!isset($seen[$id])) throw new RuntimeException('Missing target right ' . $id);
        if ((int)$old['source_uid'] === 421
            && (($sample[$id]['remaining'] ?? null) !== (int)$old['source_remaining_times']
                || ($sample[$id]['name_sha256'] ?? '') !== hash('sha256', (string)$old['purchase_name']))) {
            throw new RuntimeException('Provided sample right differs at ' . $id);
        }
    }
    ksort($sample, SORT_NUMERIC);
    return ['rights' => count($seen), 'remaining_times' => $remaining,
        'expired_but_available_extended' => count($expiredOverrides),
        'sample_uid_421' => ['rights' => count($sample), 'detail_ids' => array_keys($sample)]];
}

/** Historical carriers may support redemption but must not become new sales. */
function assetCarrierIsolation(PDO $db): array
{
    $carriers = $db->query("SELECT COUNT(*) n,
        SUM(CASE WHEN is_system_del=1 AND pay_price=0 AND total_price=0
          AND pay_type='migration' THEN 0 ELSE 1 END) invalid
        FROM eb_store_order WHERE mark='migration_entitlement'")->fetch();
    if ((int)$carriers['n'] !== 6531 || (int)$carriers['invalid'] !== 0) {
        throw new RuntimeException('Historical order carriers entered normal sales');
    }
    $bills = (int)$db->query("SELECT COUNT(*) FROM eb_user_bill WHERE category='integral'")->fetchColumn();
    $identities = (int)$db->query("SELECT COUNT(*) FROM eb_user_phone_identity WHERE state='BOUND'")->fetchColumn();
    $routine = (int)$db->query("SELECT COUNT(*) FROM eb_wechat_user WHERE user_type='routine' AND is_del=0")->fetchColumn();
    if ($bills !== 5410 || $identities !== 4622 || $routine !== 4650) {
        throw new RuntimeException('Historical point bills or member login identities differ');
    }
    return ['technical_carriers' => (int)$carriers['n'],
        'historical_integral_bills' => $bills, 'bound_phone_identities' => $identities,
        'same_app_routine_identities' => $routine];
}

try {
    $source = assetSnapshot(assetOption($argv, 'snapshot-file'),
        assetOption($argv, 'expected-source-sha'));
    $db = assetTarget(assetOptionalOption($argv, 'target-env',
        '/www/wwwroot/008.cc3798.com/.env'));
    [$map, $members] = assetMembers($db);
    $rights = assetRights($db, $source, $map);
    $isolation = assetCarrierIsolation($db);
    echo json_encode(['result' => 'PASS', 'members' => $members,
        'rights' => $rights, 'isolation' => $isolation],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, '008 final crosscheck failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
