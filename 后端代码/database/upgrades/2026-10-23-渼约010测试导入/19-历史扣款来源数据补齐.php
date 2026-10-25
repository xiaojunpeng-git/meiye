<?php
declare(strict_types=1);

/**
 * Data-only repair of reviewed historical payment-source snapshots.
 * No user funds, rights, business configuration or lifecycle algorithm is modified.
 * The complete fixed plan is mandatory; drift aborts the transaction. Rehearsal always rolls back.
 */
function repair010HistoricalPaymentSources(PDO $db, string $planPath, string $mode): array
{
    $planSha = 'a58f4e35ea2defdc30a8e6536be22584d0e2f86eab6a6dac95368ee6831309da';
    if (PHP_SAPI !== 'cli' || !in_array($mode, ['rehearse', 'apply'], true)
        || !hash_equals($planSha, (string)hash_file('sha256', $planPath))) {
        throw new RuntimeException('FIXED_PLAN_REQUIRED');
    }
    $database = (string)$db->query('SELECT DATABASE()')->fetchColumn();
    if (($mode === 'apply' && $database !== 'mohe_010_app')
        || ($mode === 'rehearse' && $database !== 'mohe_010_migration_test_refresh')) {
        throw new RuntimeException('010_DATABASE_BOUNDARY');
    }
    $plan = json_decode((string)file_get_contents($planPath), true, 512, JSON_THROW_ON_ERROR);
    if ($plan['count'] !== 22421 || count($plan['rows']) !== 22421) {
        throw new RuntimeException('FIXED_ROW_COUNT_REQUIRED');
    }
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Audit is the only new table. DDL is outside the business transaction and never alters existing schemas.
    $db->exec('CREATE TABLE IF NOT EXISTS codex_010_history_payment_source_audit (
        order_id INT UNSIGNED PRIMARY KEY, plan_sha CHAR(64) NOT NULL,
        source_uid INT UNSIGNED NOT NULL, rule_name VARCHAR(64) NOT NULL,
        before_source_json TEXT NOT NULL, after_source_json TEXT NOT NULL,
        unchanged_fields_sha CHAR(64) NOT NULL, evidence_json TEXT NOT NULL,
        applied_at INT UNSIGNED NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $keys = ['paid_ben_amount', 'paid_give_amount', 'paid_balance_ready'];
    $nonSourceHash = static function (array $row) use ($keys): string {
        foreach ($keys as $key) { unset($row[$key]); }
        ksort($row);
        return hash('sha256', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    };
    $sourceValues = static function (array $row) use ($keys): array {
        $values = [];
        foreach ($keys as $key) { $values[$key] = (string)$row[$key]; }
        return $values;
    };
    $json = static function ($value): string {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    };
    $select = $db->prepare("SELECT o.*,
        EXISTS(SELECT 1 FROM eb_store_order child WHERE child.pid=o.id) __has_children,
        EXISTS(SELECT 1 FROM eb_store_order_status st WHERE st.oid=o.id AND st.change_type='card_transfer') __has_transfer
        FROM eb_store_order o WHERE o.id=? FOR UPDATE");
    $auditSelect = $db->prepare('SELECT * FROM codex_010_history_payment_source_audit WHERE order_id=? FOR UPDATE');
    $update = $db->prepare('UPDATE eb_store_order SET paid_ben_amount=?,paid_give_amount=?,paid_balance_ready=1 WHERE id=?');
    $insert = $db->prepare('INSERT INTO codex_010_history_payment_source_audit
        (order_id,plan_sha,source_uid,rule_name,before_source_json,after_source_json,unchanged_fields_sha,evidence_json,applied_at)
        VALUES (?,?,?,?,?,?,?,?,?)');
    // Read the transaction's own view, rather than checking only after rollback:
    // a trigger or unexpected side effect must not mutate funds, rights or foundation rows.
    $protectedHash = static function () use ($db): array {
        $hashes = [];
        // Historical cart snapshots are large: stream rows instead of buffering an entire table.
        $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        try {
        foreach (['eb_user','eb_user_money','eb_user_card_holder','eb_store_order_writeoff',
            'eb_store_order_cart_info','eb_store_order_refund','eb_system_store','eb_system_store_staff',
            'eb_system_admin','eb_system_menus','eb_store_product','eb_system_config'] as $table) {
            $hash = hash_init('sha256');
            $query = $db->query('SELECT * FROM `' . $table . '` ORDER BY 1');
            while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
                hash_update($hash, json_encode($row, JSON_THROW_ON_ERROR) . "\n");
            }
            $query->closeCursor();
            $hashes[$table] = hash_final($hash);
        }
        } finally {
            $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        }
        return $hashes;
    };
    $applied = 0; $replayed = 0;
    $db->beginTransaction();
    try {
        $protectedBefore = $protectedHash();
        foreach ($plan['rows'] as $candidate) {
            $id = $candidate['orderId'];
            $select->execute([$id]);
            $before = $select->fetch(PDO::FETCH_ASSOC);
            if (!$before) { throw new RuntimeException('ORDER_MISSING:' . $id); }
            $auditSelect->execute([$id]);
            $previous = $auditSelect->fetch(PDO::FETCH_ASSOC);
            if ($previous) {
                // Re-running must never overwrite later business changes or add another source allocation.
                if ($previous['plan_sha'] !== $planSha || $sourceValues($before) !== $sourceValues($candidate['after'])) {
                    throw new RuntimeException('REPLAY_SOURCE_DRIFT:' . $id);
                }
                $replayed++;
                continue;
            }
            if ((int)$before['__has_children'] !== 0 || (int)$before['__has_transfer'] !== 0) {
                throw new RuntimeException('PAYMENT_LINEAGE_CHANGED:' . $id);
            }
            foreach ($candidate['expected'] as $key => $value) {
                if (!array_key_exists($key, $before) || (string)$before[$key] !== (string)$value) {
                    throw new RuntimeException('ORDER_BASELINE_CHANGED:' . $id . ':' . $key);
                }
            }
            if (bccomp(bcadd($candidate['after']['paid_ben_amount'], $candidate['after']['paid_give_amount'], 2), $before['yue_pay_price'], 2) !== 0) {
                throw new RuntimeException('SOURCE_TOTAL_NOT_CLOSED:' . $id);
            }
            $unchangedHash = $nonSourceHash($before);
            $update->execute([$candidate['after']['paid_ben_amount'], $candidate['after']['paid_give_amount'], $id]);
            if ($update->rowCount() !== 1) { throw new RuntimeException('EXPECTED_ONE_UPDATE:' . $id); }
            $select->execute([$id]);
            $after = $select->fetch(PDO::FETCH_ASSOC);
            if ($nonSourceHash($after) !== $unchangedHash || $sourceValues($after) !== $sourceValues($candidate['after'])) {
                throw new RuntimeException('UNEXPECTED_FIELD_CHANGE:' . $id);
            }
            $insert->execute([$id, $planSha, $candidate['sourceMemberId'], $candidate['rule'],
                $json($sourceValues($before)), $json($sourceValues($after)), $unchangedHash, $json($candidate['evidence']), time()]);
            $applied++;
        }
        $auditCount = (int)$db->query("SELECT COUNT(*) FROM codex_010_history_payment_source_audit WHERE plan_sha='" . $planSha . "'")->fetchColumn();
        if ($auditCount !== 22421) { throw new RuntimeException('AUDIT_COUNT_MISMATCH'); }
        if ($protectedBefore !== $protectedHash()) { throw new RuntimeException('PROTECTED_TABLE_CHANGED'); }
        if ($mode === 'rehearse') { $db->rollBack(); }
        else { $db->commit(); }
        return ['database' => $database, 'mode' => $mode, 'applied' => $applied, 'replayed' => $replayed,
            'auditCountInTransaction' => $auditCount, 'rolledBack' => $mode === 'rehearse',
            'otherOrderFieldsUnchanged' => true, 'protectedTablesUnchangedInTransaction' => count($protectedBefore), 'planSHA256' => $planSha];
    } catch (Throwable $error) {
        if ($db->inTransaction()) { $db->rollBack(); }
        throw $error;
    }
}
