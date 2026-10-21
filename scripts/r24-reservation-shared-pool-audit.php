<?php
declare(strict_types=1);

/**
 * Read-only repair preparation for reservation consumption of issued shared cards.
 * Explicit --all or --holder-id=N is required. This command never changes facts,
 * pools or caches; complex histories are flagged for individual reconciliation.
 */
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__) . '/后端代码';
require $backend . '/vendor/autoload.php';
(new think\App($backend . '/'))->initialize();
$options = getopt('', ['all', 'holder-id:']);
$holderId = (int)($options['holder-id'] ?? 0);
if ($holderId <= 0 && !array_key_exists('all', $options)) throw new InvalidArgumentException('Use --holder-id=N or --all');
$c = config('database.connections.mysql');
$db = new PDO('mysql:host=' . $c['hostname'] . ';port=' . $c['hostport'] . ';dbname=' . $c['database'] . ';charset=utf8mb4',
    $c['username'], $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$read = static function (string $sql, array $args = []) use ($db): array {
    $q = $db->prepare($sql); $q->execute($args); return $q->fetchAll(PDO::FETCH_ASSOC);
};
// SQL-level read-only transaction also rejects an accidental future write.
$db->exec('SET TRANSACTION READ ONLY');
$db->beginTransaction();
try {
    $prefix = (string)($c['prefix'] ?? 'eb_');
    if (!preg_match('/^[a-zA-Z0-9_]+$/D', $prefix)) throw new LogicException('Invalid table prefix');
    $states = $read("SELECT DISTINCT s.tenant_id,s.id,s.card_holder_id,s.shared_total_times,s.shared_remaining_times,s.state_version,s.status,
        h.card_no,h.write_surplus_times AS legacy_remaining,o.pay_price
        FROM {$prefix}cashier_v3_card_rule_state s
        JOIN {$prefix}user_card_holder h ON h.id=s.card_holder_id
        JOIN {$prefix}store_order o ON o.id=h.oid
        JOIN {$prefix}cashier_v3_entitlement_writeoff_fact w ON w.tenant_id=s.tenant_id AND w.holder_id=s.card_holder_id
        WHERE s.rule_type='choice_count' AND w.source_document_type='reservation'"
        . ($holderId > 0 ? ' AND s.card_holder_id=?' : '') . ' ORDER BY s.card_holder_id', $holderId > 0 ? [$holderId] : []);
    $result = [];
    foreach ($states as $state) {
        $id = (int)$state['card_holder_id']; $tenant = (string)$state['tenant_id'];
        $facts = $read("SELECT writeoff_id,quantity,actual_entitlement_amount_cents,source_document_type,document_no_snapshot,
            source_detail_id,status,occurred_at FROM {$prefix}cashier_v3_entitlement_writeoff_fact
            WHERE tenant_id=? AND holder_id=? ORDER BY occurred_at,id", [$tenant, $id]);
        $reversals = $read("SELECT original_writeoff_id,quantity FROM {$prefix}cashier_v3_entitlement_reversal_fact
            WHERE tenant_id=? AND holder_id=?", [$tenant, $id]);
        $refunds = $read("SELECT refund_amount_cents,times_removed FROM {$prefix}cashier_v3_refund_adjustment
            WHERE tenant_id=? AND card_holder_id=? AND status='effective'", [$tenant, $id]);
        $operations = $read("SELECT operation_id FROM {$prefix}cashier_v3_card_operation
            WHERE tenant_id=? AND source_card_holder_id=?", [$tenant, $id]);
        // 手艺人已被后续调整时，历史纠正必须保留该调整；不能整笔作废重做
        // 并套用当前人员配置，把已经确认的手艺人业绩重新覆盖。
        $serviceAdjustments = $read("SELECT COUNT(DISTINCT a.id) AS n
            FROM {$prefix}cashier_v3_service_record_adjustment_operation a
            JOIN {$prefix}cashier_v3_entitlement_service_fact sf ON sf.id=a.service_fact_id AND sf.tenant_id=a.tenant_id
            JOIN {$prefix}cashier_v3_entitlement_writeoff_fact wf
                ON wf.tenant_id=sf.tenant_id AND wf.checkout_request_id=sf.checkout_request_id AND wf.source_line_id=sf.source_line_id
            WHERE wf.tenant_id=? AND wf.holder_id=?", [$tenant, $id]);
        $used = 0; $writtenCents = 0; $amountRows = []; $flags = [];
        if ($reversals) $flags[] = 'has_service_reversal';
        if ($refunds) $flags[] = 'has_refund';
        if ($operations) $flags[] = 'has_card_operation';
        $total = (int)$state['shared_total_times'];
        $price = (string)$state['pay_price'];
        if (!preg_match('/^(0|[1-9][0-9]*)(\.0{1,2})?$/D', $price)) $flags[] = 'non_whole_yuan_purchase';
        foreach ($facts as $fact) {
            if ($fact['status'] !== 'effective') { $flags[] = 'non_effective_writeoff'; continue; }
            $quantity = (int)$fact['quantity'];
            $expected = null;
            // Only an unambiguous original pool can be replayed by simple use sequence.
            if (!$flags && $total > 0 && $used + $quantity <= $total) {
                $expected = (int)bcmul(
                    \app\services\cashier\v3\cashier\CashierV3EntitlementActualAmountAllocator::allocate($price, $total, $used, $quantity),
                    '100', 0
                );
            }
            $amountRows[] = ['writeoff_id' => $fact['writeoff_id'], 'document_no' => $fact['document_no_snapshot'],
                'source_type' => $fact['source_document_type'], 'quantity' => $quantity,
                'written_cents' => (int)$fact['actual_entitlement_amount_cents'], 'expected_cents' => $expected];
            $used += $quantity; $writtenCents += (int)$fact['actual_entitlement_amount_cents'];
        }
        $restored = array_sum(array_column($reversals, 'quantity'));
        $removed = array_sum(array_column($refunds, 'times_removed'));
        $expectedRemaining = $total - $used + $restored - $removed;
        if ($expectedRemaining < 0 || $expectedRemaining > $total) $flags[] = 'pool_overdrawn_or_ambiguous';
        // 存在后续变更时简单总次公式仅是线索，不能成为自动修库的目标值。
        if ($flags) {
            $expectedRemaining = null;
            foreach ($amountRows as &$amountRow) $amountRow['expected_cents'] = null;
            unset($amountRow);
        }
        $result[] = ['holder_id' => $id, 'card_no' => $state['card_no'], 'rule_version' => (int)$state['state_version'],
            'rule_remaining' => (int)$state['shared_remaining_times'], 'legacy_remaining' => (int)$state['legacy_remaining'],
            'expected_remaining' => $expectedRemaining, 'written_cents' => $writtenCents,
            'review_flags' => array_values(array_unique($flags)),
            'followup_service_adjustments' => (int)($serviceAdjustments[0]['n'] ?? 0),
            'manual_review_required' => true, 'facts' => $amountRows];
    }
    echo json_encode(['mode' => 'read_only', 'data_as_of' => date('c'), 'database' => $c['database'], 'candidate_count' => count($result),
        'candidates' => $result], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
} finally { $db->rollBack(); }
