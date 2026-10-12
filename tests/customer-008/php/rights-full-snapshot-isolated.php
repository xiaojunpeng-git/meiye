<?php
declare(strict_types=1);

/**
 * Rehearse the entire frozen old-H5 entitlement set against disposable MySQL 5.7.
 * Catalogue rows are a read-only export of 008 and member IDs are local synthetic
 * identities; this test never connects to or mutates either customer database.
 */
require_once __DIR__ . '/../../../scripts/import/customer_008_morestore_rights_apply.php';

function rightsFullTestMain(array $argv): void
{
    $file = rightsPrepareOption($argv, 'snapshot-file', '');
    $sha = rightsPrepareOption($argv, 'expected-source-sha', '');
    if ($file === '' || $sha === '') throw new RuntimeException('Frozen snapshot and SHA required');
    $rows = rightsSnapshot($file, $sha);
    $target = new PDO('mysql:host=127.0.0.1;port=13318;dbname=mohe_008;charset=utf8mb4',
        'root', 'rights-test-local', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    if ((int)$target->query('SELECT COUNT(*) FROM eb_store_product')->fetchColumn() < 3000
        || (int)$target->query('SELECT COUNT(*) FROM eb_user_card_holder')->fetchColumn() !== 0) {
        throw new RuntimeException('Disposable full catalogue fixture not ready or not clean');
    }
    [$mapping, $activeOldStores] = rightsPrepareMapping(RIGHTS_MAPPING_FILE, RIGHTS_CATALOG_PLAN_FILE);
    $mapping = rightsCatalog($target, $mapping);
    $sourceUids = array_values(array_unique(array_map('intval', array_column($rows, 'source_uid'))));
    // The separate member batch owns real identity resolution. Local identity
    // rows only prove every source UID joins to exactly one target UID here.
    $target->exec('CREATE TABLE IF NOT EXISTS ' . RIGHTS_MEMBER_MAP . ' (
        source_uid int unsigned NOT NULL PRIMARY KEY,
        target_uid int unsigned NOT NULL,
        source_store_id int unsigned NOT NULL DEFAULT 0,
        target_store_id int unsigned NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $target->exec('TRUNCATE TABLE ' . RIGHTS_MEMBER_MAP);
    $insertUser = $target->prepare('INSERT INTO eb_user (uid,phone,nickname,status,is_del)
        VALUES (?,?,?,1,0)');
    $insertMap = $target->prepare('INSERT INTO ' . RIGHTS_MEMBER_MAP . '
        (source_uid,target_uid) VALUES (?,?)');
    // The reviewed duplicate-phone resolution maps source 376's three old
    // details to target 318. Keep all source detail keys distinct after merge.
    $mergedSourceToTarget = [27 => 16, 390 => 16, 3525 => 16,
        126 => 50, 147 => 50, 104 => 90, 376 => 318];
    $targetUids = [];
    foreach ($sourceUids as $uid) {
        $targetUid = $mergedSourceToTarget[$uid] ?? $uid;
        $targetUids[$targetUid] = true;
        $insertMap->execute([$uid, $targetUid]);
    }
    foreach (array_keys($targetUids) as $uid) {
        $insertUser->execute([$uid, '139' . str_pad((string)$uid, 8, '0', STR_PAD_LEFT),
            '隔离权益会员' . $uid]);
    }
    $members = rightsMemberMap($target, $sourceUids);
    $owner = rightsOwnerOrg($target);
    $target->beginTransaction();
    $hidden = [];
    $written = 0;
    try {
        foreach ($rows as $source) {
            $id = (int)$source['source_product_id'];
            if (rightsApplyOne($target, $source, $members[(int)$source['source_uid']],
                $owner, $mapping[$id] ?? [], $hidden)) $written++;
            if ($written > 0 && $written % 500 === 0) {
                fwrite(STDERR, 'Imported ' . $written . ' frozen rights locally' . PHP_EOL);
            }
        }
        $verified = rightsVerify($target, $rows, $members, $owner);
        if ($written !== 6531 || $verified['source_lines'] !== 6531
            || $verified['remaining_times'] !== 61228
            || $verified['sample_uid_421_lines'] !== 15) {
            throw new RuntimeException('Frozen business totals differ');
        }
        $target->commit();
    } catch (Throwable $error) {
        if ($target->inTransaction()) $target->rollBack();
        throw $error;
    }
    $metrics = $target->query("SELECT COUNT(*) n, COALESCE(SUM(pay_price),0) revenue,
        SUM(is_system_del=0) ordinary_order_count FROM eb_store_order
        WHERE mark='migration_entitlement'")->fetch(PDO::FETCH_ASSOC);
    if ((int)$metrics['n'] !== 6531 || (float)$metrics['revenue'] !== 0.0
        || (int)$metrics['ordinary_order_count'] !== 0) {
        throw new RuntimeException('Technical carriers entered ordinary sales');
    }
    $merged = $target->query('SELECT COUNT(*) FROM ' . RIGHTS_SCOPE_TABLE
        . ' s JOIN eb_user_card_holder h ON h.id=s.card_holder_id'
        . ' WHERE s.source_uid=376 AND s.target_uid=318 AND h.uid=318')->fetchColumn();
    if ((int)$merged !== 3) throw new RuntimeException('Three duplicate-phone source rights did not merge');
    $closedOldStoreRights = 0;
    $expiryOverrides = 0;
    foreach ($rows as $source) {
        if (!isset($activeOldStores[(int)$source['source_store_id']])) $closedOldStoreRights++;
        if ((int)$source['source_write_end'] > 0
            && (int)$source['source_write_end'] < time()) {
            $expiryOverrides++;
            $end = $target->prepare('SELECT h.write_end FROM ' . RIGHTS_SCOPE_TABLE
                . ' s JOIN eb_user_card_holder h ON h.id=s.card_holder_id'
                . ' WHERE s.source_detail_id=?');
            $end->execute([(int)$source['source_detail_id']]);
            if ((int)$end->fetchColumn() !== 0) {
                throw new RuntimeException('H5-visible expired source was not extended');
            }
        }
    }
    if ($closedOldStoreRights !== 419 || $expiryOverrides !== 6) {
        throw new RuntimeException('Reviewed closed-store or expiry exception coverage changed');
    }
    echo rightsJson(['source_sha256' => $sha, 'source_lines' => $written,
        'remaining_times' => $verified['remaining_times'],
        'sample_uid_421_lines' => $verified['sample_uid_421_lines'],
        'merged_source_uid_376_to_target_318_lines' => (int)$merged,
        'closed_old_store_rights' => $closedOldStoreRights,
        'expiry_overrides' => $expiryOverrides,
        'hidden_catalogue_count' => count($hidden), 'technical_order_revenue' => $metrics['revenue']]) . PHP_EOL;
}

try {
    rightsFullTestMain($argv);
} catch (Throwable $error) {
    fwrite(STDERR, 'Disposable full rights rehearsal failed: ' . $error->getMessage() . PHP_EOL);
    exit(2);
}
