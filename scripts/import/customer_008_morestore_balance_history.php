<?php
/**
 * One-time 008 import of old Meiyue money rows into a display-only projection.
 * Never writes the current member balance, V3 financial facts, or old source.
 * Usage: php customer_008_morestore_balance_history.php preflight|check|apply|verify
 */
declare(strict_types=1);
require_once __DIR__ . '/customer_008_morestore_service_history.php';
date_default_timezone_set('Asia/Shanghai');

const BALANCE_PLAN = __DIR__ . '/customer_008_morestore_balance_history_plan.json';
const BALANCE_SQL = __DIR__ . '/../../后端代码/database/upgrades/2026-10-13-008渼约余额历史读取/02-正式升级.sql';
const BALANCE_KEY = '008-morestore-balance-history-r1';
const BALANCE_UPGRADE = '20261013-008-morestore-balance-history-r1';
const BALANCE_TABLE = 'eb_mobile_customer_legacy_balance_record';
const BALANCE_BATCH = 'mig_morestore_20261013_balance_history_batch';
const BALANCE_SKIP = 'mig_morestore_20261013_balance_history_skip';

/** Normalize all money to two decimal places before fingerprinting and writing. */
function balanceCanonical(array $row): string
{
    $normalized = [];
    foreach (['source_money_id','source_member_id','member_id','source_store_id','pm','occurred_at'] as $key) {
        $normalized[$key] = (int)$row[$key];
    }
    foreach (['store_name_snapshot','source_type','title_snapshot','mark_snapshot','source_link_id'] as $key) {
        $normalized[$key] = (string)$row[$key];
    }
    foreach (['amount_yuan','balance_after_yuan'] as $key) {
        $normalized[$key] = number_format((float)$row[$key], 2, '.', '');
    }
    return json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

/** A source snapshot freezes the one-time cutoff and records unmapped orphan IDs. */
function balanceSnapshot(PDO $source, array $map, int $cutoff): array
{
    $source->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $source->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
    try {
        $rows = []; $skipped = []; $hash = hash_init('sha256'); $skipHash = hash_init('sha256'); $last = 0;
        do {
            $query = $source->prepare('SELECT m.id,m.uid,m.link_id,m.type,m.title,m.number,m.balance,m.pm,m.mark,m.add_time,m.store_id,s.name store_name '
                . 'FROM eb_user_money m LEFT JOIN eb_system_store s ON s.id=m.store_id '
                . 'WHERE m.id>? AND m.status=1 AND m.add_time<=? ORDER BY m.id LIMIT 500');
            $query->execute([$last, $cutoff]);
            $batch = $query->fetchAll();
            foreach ($batch as $sourceRow) {
                $last = (int)$sourceRow['id']; $uid = (int)$sourceRow['uid'];
                if (!isset($map[$uid])) {
                    $skip = [$last, $uid, 'UNMAPPED_MEMBER'];
                    $skipped[] = $skip;
                    hash_update($skipHash, implode('|', $skip) . "\n");
                    continue;
                }
                if ((int)$sourceRow['add_time'] <= 0 || !in_array((int)$sourceRow['pm'], [0,1], true)
                    || (float)$sourceRow['number'] <= 0 || (float)$sourceRow['balance'] < 0) {
                    throw new RuntimeException('Invalid old money row: ' . $last);
                }
                $row = [
                    'source_money_id' => $last, 'source_member_id' => $uid, 'member_id' => $map[$uid],
                    'source_store_id' => max(0, (int)$sourceRow['store_id']),
                    'store_name_snapshot' => mb_substr(trim((string)($sourceRow['store_name'] ?? '')) ?: '门店未记录', 0, 120),
                    'source_type' => mb_substr((string)$sourceRow['type'], 0, 64),
                    'title_snapshot' => mb_substr((string)$sourceRow['title'], 0, 64),
                    'mark_snapshot' => mb_substr((string)$sourceRow['mark'], 0, 512),
                    'source_link_id' => mb_substr((string)$sourceRow['link_id'], 0, 32),
                    'pm' => (int)$sourceRow['pm'],
                    'amount_yuan' => number_format((float)$sourceRow['number'], 2, '.', ''),
                    'balance_after_yuan' => number_format((float)$sourceRow['balance'], 2, '.', ''),
                    'occurred_at' => (int)$sourceRow['add_time'],
                ];
                $canonical = balanceCanonical($row);
                $row['source_row_sha256'] = hash('sha256', $canonical);
                hash_update($hash, $canonical . "\n");
                $rows[] = $row;
            }
        } while (count($batch) === 500);
        $source->exec('COMMIT');
        return [$rows, $skipped, hash_final($hash), hash_final($skipHash)];
    } catch (Throwable $error) {
        $source->exec('ROLLBACK');
        throw $error;
    }
}

/** Product-accepted member map and PoPo example anchor the import scope. */
function balancePlan(array $rows, array $skipped): void
{
    $plan = json_decode((string)file_get_contents(BALANCE_PLAN), true, 512, JSON_THROW_ON_ERROR);
    if (($plan['batch_key'] ?? '') !== BALANCE_KEY || ($plan['target_instance'] ?? '') !== '008.cc3798.com'
        || ($plan['source_database'] ?? '') !== 'morestore' || ($plan['target_database'] ?? '') !== 'mohe_008'
        || ($plan['source_cutoff_policy'] ?? '') !== 'consistent_snapshot_at_apply'
        || ($plan['read_only_projection'] ?? false) !== true
        || count($rows) + count($skipped) < (int)($plan['minimum_source_rows'] ?? PHP_INT_MAX)
        || count($rows) < (int)($plan['minimum_imported_rows'] ?? PHP_INT_MAX)
        || count($skipped) > (int)($plan['maximum_skipped_rows'] ?? -1)) {
        throw new RuntimeException('Balance source or scope differs from accepted plan');
    }
    $popo = array_filter($rows, static function (array $row): bool {
        return $row['source_member_id'] === 421 && $row['member_id'] === 421;
    });
    if (count($popo) < (int)($plan['required_sample_min_rows'] ?? PHP_INT_MAX)) {
        throw new RuntimeException('PoPo historical balance is missing');
    }
}

/** A changed old account balance means the member migration anchor is stale. */
function balanceAccountGuard(PDO $source, PDO $target): void
{
    $query = $target->query('SELECT source_uid,source_balance_yuan FROM mig_morestore_20261013_member_map');
    $expected = [];
    foreach ($query as $row) $expected[(int)$row['source_uid']] = (int)$row['source_balance_yuan'];
    $actual = $source->query('SELECT uid,now_money FROM eb_user');
    foreach ($actual as $row) {
        $uid = (int)$row['uid'];
        // The accepted member migration copied the old H5 intval(now_money)
        // display value, not rounded cents. Keep that exact baseline rule.
        if (isset($expected[$uid]) && (int)(string)$row['now_money'] !== $expected[$uid]) {
            throw new RuntimeException('Old member balance changed since accepted migration: ' . $uid);
        }
    }
}

/** All projection rows, orphan audit, batch digest and upgrade marker commit atomically. */
function balanceApply(PDO $target, array $rows, array $skipped, string $hash, string $skipHash, int $cutoff): void
{
    $commit = trim((string)getenv('MOHE_RELEASE_COMMIT'));
    if (!preg_match('/^[0-9a-f]{40}$/', $commit)) throw new RuntimeException('Accepted release commit required');
    $lock = (int)$target->query("SELECT GET_LOCK('m008_morestore_balance_history_r1',10)")->fetchColumn();
    if ($lock !== 1) throw new RuntimeException('Cannot acquire import lock');
    try {
        foreach ([BALANCE_TABLE, BALANCE_BATCH, BALANCE_SKIP] as $table) {
            if ((int)$target->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn() !== 0) {
                throw new RuntimeException('Balance import target is not empty');
            }
        }
        $marker = $target->prepare('SELECT COUNT(*) FROM eb_database_upgrade_log WHERE upgrade_key=?');
        $marker->execute([BALANCE_UPGRADE]);
        if ((int)$marker->fetchColumn() !== 0) throw new RuntimeException('Balance upgrade marker already exists');
        $target->beginTransaction();
        try {
            $insert = $target->prepare('INSERT INTO ' . BALANCE_TABLE . ' (source_system,source_money_id,source_member_id,member_id,source_store_id,store_name_snapshot,source_type,title_snapshot,mark_snapshot,source_link_id,pm,amount_yuan,balance_after_yuan,occurred_at,source_row_sha256,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())');
            foreach ($rows as $row) {
                $insert->execute(['morestore',$row['source_money_id'],$row['source_member_id'],$row['member_id'],$row['source_store_id'],$row['store_name_snapshot'],$row['source_type'],$row['title_snapshot'],$row['mark_snapshot'],$row['source_link_id'],$row['pm'],$row['amount_yuan'],$row['balance_after_yuan'],$row['occurred_at'],$row['source_row_sha256']]);
            }
            $skipInsert = $target->prepare('INSERT INTO ' . BALANCE_SKIP . ' (source_money_id,source_member_id,reason,created_at) VALUES (?,?,?,NOW())');
            foreach ($skipped as $skip) $skipInsert->execute($skip);
            $batchInsert = $target->prepare('INSERT INTO ' . BALANCE_BATCH . ' (batch_key,source_sha256,skipped_sha256,source_rows,imported_rows,skipped_rows,source_cutoff_at,created_at) VALUES (?,?,?,?,?,?,?,NOW())');
            $batchInsert->execute([BALANCE_KEY,$hash,$skipHash,count($rows)+count($skipped),count($rows),count($skipped),$cutoff]);
            $upgrade = $target->prepare('INSERT INTO eb_database_upgrade_log (upgrade_key,title,task_file,sql_checksum,code_version,executed_at,executed_by,result_note,created_at) VALUES (?,?,?,?,?,NOW(),?,?,NOW())');
            $upgrade->execute([BALANCE_UPGRADE,'008 渼约余额历史只读投影','后端代码/database/upgrades/2026-10-13-008渼约余额历史读取/02-正式升级.sql',hash_file('sha256',BALANCE_SQL),$commit,'Codex',json_encode(['source_sha256'=>$hash,'source_rows'=>count($rows)+count($skipped),'imported_rows'=>count($rows),'skipped_rows'=>count($skipped),'source_cutoff_at'=>$cutoff,'target'=>'mohe_008'],JSON_THROW_ON_ERROR)]);
            $target->commit();
        } catch (Throwable $error) {
            $target->rollBack();
            throw $error;
        }
    } finally {
        $target->query("SELECT RELEASE_LOCK('m008_morestore_balance_history_r1')");
    }
}

/** Recompute every imported row and skipped ID, including the PoPo sample. */
function balanceVerify(PDO $target): array
{
    $batch = $target->query("SELECT * FROM " . BALANCE_BATCH . " WHERE batch_key='" . BALANCE_KEY . "'")->fetch();
    $marker = $target->query("SELECT sql_checksum,code_version FROM eb_database_upgrade_log WHERE upgrade_key='" . BALANCE_UPGRADE . "'")->fetch();
    if (!$batch || !$marker || $marker['sql_checksum'] !== hash_file('sha256', BALANCE_SQL)
        || !preg_match('/^[0-9a-f]{40}$/', (string)$marker['code_version'])) throw new RuntimeException('Balance batch or upgrade marker differs');
    $hash = hash_init('sha256'); $count = 0; $popo = 0;
    foreach ($target->query('SELECT * FROM ' . BALANCE_TABLE . ' ORDER BY source_money_id') as $row) {
        $canonical = balanceCanonical($row);
        if ($row['source_system'] !== 'morestore' || hash('sha256', $canonical) !== $row['source_row_sha256']) {
            throw new RuntimeException('Balance imported row differs: ' . $row['source_money_id']);
        }
        hash_update($hash, $canonical . "\n"); $count++;
        if ((int)$row['source_member_id'] === 421 && (int)$row['member_id'] === 421) $popo++;
    }
    $skipHash = hash_init('sha256'); $skipCount = 0;
    foreach ($target->query('SELECT source_money_id,source_member_id,reason FROM ' . BALANCE_SKIP . ' ORDER BY source_money_id') as $row) {
        hash_update($skipHash, implode('|', [(int)$row['source_money_id'],(int)$row['source_member_id'],(string)$row['reason']]) . "\n"); $skipCount++;
    }
    if ($count !== (int)$batch['imported_rows'] || $skipCount !== (int)$batch['skipped_rows']
        || $count+$skipCount !== (int)$batch['source_rows'] || hash_final($hash) !== $batch['source_sha256']
        || hash_final($skipHash) !== $batch['skipped_sha256'] || $popo < 47) {
        throw new RuntimeException('Balance counts, hashes or PoPo sample differ');
    }
    return ['source_rows'=>(int)$batch['source_rows'],'imported_rows'=>$count,'skipped_rows'=>$skipCount,'source_sha256'=>$batch['source_sha256'],'source_cutoff_at'=>(int)$batch['source_cutoff_at'],'popo_rows'=>$popo];
}

/** Check is read-only; apply requires a separately accepted and authorized release. */
function balanceMain(array $argv): void
{
    $mode = (string)($argv[1] ?? '');
    if (!in_array($mode,['preflight','check','apply','verify'],true)) throw new RuntimeException('Usage: preflight|check|apply|verify');
    $target = serviceHistoryDb(SERVICE_TARGET_ENV,'mohe_008',3307);
    if ($mode === 'preflight') {
        $tables = [];
        $exists = $target->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
        foreach ([BALANCE_TABLE,BALANCE_BATCH,BALANCE_SKIP] as $table) { $exists->execute([$table]); $tables[$table] = (int)$exists->fetchColumn(); }
        $marker = $target->prepare('SELECT COUNT(*) FROM eb_database_upgrade_log WHERE upgrade_key=?'); $marker->execute([BALANCE_UPGRADE]);
        echo json_encode(['target'=>'mohe_008:3307','tables'=>$tables,'upgrade_marker'=>(int)$marker->fetchColumn()]),PHP_EOL; return;
    }
    if ($mode === 'verify') { echo json_encode(balanceVerify($target)),PHP_EOL; return; }
    if ($mode === 'apply' && (int)$target->query('SELECT COUNT(*) FROM ' . BALANCE_BATCH)->fetchColumn() > 0) {
        echo json_encode(balanceVerify($target)),PHP_EOL; return;
    }
    $cutoff = time(); $source = serviceHistoryDb(SERVICE_SOURCE_ENV,'morestore',3306);
    [$rows,$skipped,$hash,$skipHash] = balanceSnapshot($source,serviceHistoryMembers($target),$cutoff);
    balancePlan($rows,$skipped);
    balanceAccountGuard($source,$target);
    if ($mode === 'check') {
        $popo = count(array_filter($rows,static function (array $row): bool { return $row['member_id'] === 421 && $row['source_member_id'] === 421; }));
        echo json_encode(['source_rows'=>count($rows)+count($skipped),'importable_rows'=>count($rows),'skipped_rows'=>count($skipped),'source_sha256'=>$hash,'preview_cutoff_at'=>$cutoff,'popo_rows'=>$popo]),PHP_EOL; return;
    }
    balanceApply($target,$rows,$skipped,$hash,$skipHash,$cutoff);
    echo json_encode(balanceVerify($target)),PHP_EOL;
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try { balanceMain($argv); }
    catch (Throwable $error) { fwrite(STDERR,$error->getMessage().PHP_EOL); exit(1); }
}
