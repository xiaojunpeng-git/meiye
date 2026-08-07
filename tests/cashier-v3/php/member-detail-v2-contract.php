<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$detail = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/member/CashierV3MemberDetailQueryServices.php');
$module = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/member/CashierV3MemberModule.php');
$passed = 0;
$failed = 0;

function memberDetailV2Ok(string $name, bool $condition): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS {$name}\n";
        return;
    }
    $failed++;
    echo "FAIL {$name}\n";
}

memberDetailV2Ok('member detail exposes only authoritative V3 service records',
    strpos($detail, "Db::name('cashier_v3_entitlement_service_fact')") !== false
    && strpos($detail, "->where('service_status', 'completed')") !== false
    && strpos($detail, "'service_record_no'") !== false
    && strpos($detail, "'service_fact_id'") === false);
memberDetailV2Ok('service records join card snapshots from the matching writeoff authority',
    strpos($detail, "->leftJoin(") !== false
    && strpos($detail, "'cashier_v3_entitlement_writeoff_fact wf'") !== false
    && strpos($detail, 'wf.tenant_id = sf.tenant_id AND wf.checkout_request_id = sf.checkout_request_id AND wf.source_line_id = sf.source_line_id') !== false
    && strpos($detail, "'wf.source_name_snapshot AS source_name_snapshot'") !== false
    && strpos($detail, "'wf.source_code_snapshot AS source_code_snapshot'") !== false);
memberDetailV2Ok('member detail exposes V3 gift facts from both approved gift authorities',
    strpos($detail, "Db::name('cashier_v3_gift_fact')") !== false
    && strpos($detail, "'cashier_v3_direct_gift_authority'") !== false
    && strpos($detail, "'cashier_v3_recharge_gift_authority'") !== false);
memberDetailV2Ok('member detail exposes formal care records and tasks without fabricating data',
    strpos($detail, "Db::name('customer_care_record')") !== false
    && strpos($detail, "Db::name('customer_care_task')") !== false
    && strpos($detail, "->where('status', 'NORMAL')") !== false
    && strpos($detail, "->field('task_key,task_no") !== false
    && strpos($detail, "'id' => 'care-task:' . (string)\$row['task_key']") !== false
    && strpos($detail, "'careRecords' => \$care['records']") !== false);
memberDetailV2Ok('overview is derived from service and settled sales authorities',
    strpos($detail, "Db::name('cashier_v3_sale_fact')") !== false
    && strpos($detail, "->where('status', 'effective')") !== false
    && strpos($detail, "COUNT(DISTINCT business_date) AS visit_count") !== false
    && strpos($detail, "'totalConsumptionAmount'") !== false);
memberDetailV2Ok('member balance projection reuses the formal balance authority snapshot',
    strpos($detail, 'new CashierV3MemberBalanceProvider()') !== false
    && strpos($detail, 'readSnapshot(') !== false
    && strpos($detail, "'balanceVersion' => (int)\$balanceSnapshot['accountVersion']") !== false);
memberDetailV2Ok('balance changes only read immutable effective V3 balance facts with principal and bonus snapshots',
    strpos($detail, "Db::name('cashier_v3_balance_fact')") !== false
    && strpos($detail, "->where('status', 'effective')") !== false
    && strpos($detail, 'principal_delta_cents,bonus_delta_cents,principal_after_cents,bonus_after_cents') !== false
    && strpos($detail, "'principalDelta' => \$this->moneyFromCents(\$principalDelta)") !== false
    && strpos($detail, "'bonusAfter' => \$this->moneyFromCents(\$bonusAfter)") !== false);
memberDetailV2Ok('balance changes expose stable server-owned YE display numbers',
    strpos($detail, "'changeNo' => \$this->balanceChangeDisplayNo(") !== false
    && strpos($detail, "return 'YE' . \$date->format('ymd')") !== false);
memberDetailV2Ok('card operation records read the immutable authority with member and store scope',
    strpos($detail, "Db::name('cashier_v3_card_operation')") !== false
    && strpos($detail, "->where('member_id_before', \$memberId)") !== false
    && strpos($detail, "->whereOr('member_id_after', \$memberId)") !== false
    && strpos($detail, "\$this->applyStoreScope(\$query, 'store_id', \$dataScope)") !== false
    && strpos($detail, "'card_transfer' => '卡转让'") !== false
    && strpos($detail, "'cardOperations' => \$cardOperations") !== false);
memberDetailV2Ok('labor performance joins only the matching completed service line authority',
    strpos($detail, "Db::name('cashier_v3_performance_fact')") !== false
    && strpos($detail, "->where('performance_type', 'labor_performance_allocated')") !== false
    && strpos($detail, "->where('fact_direction', 'forward')") !== false
    && strpos($detail, "->whereIn('checkout_request_id'") !== false
    && strpos($detail, "->whereIn('source_line_id'") !== false
    && strpos($detail, "'laborPerformanceStatus' => \$labor === null ? 'pending' : 'recorded'") !== false);
memberDetailV2Ok('craftsman point-round and customer-care enum labels are server-owned Chinese projections',
    strpos($detail, "'isPointCustomer'") !== false
    && strpos($detail, "'（点）'") !== false
    && strpos($detail, "'（轮）'") !== false
    && strpos($detail, "'（未标注）'") !== false
    && strpos($detail, "'DAILY_FOLLOWUP' => '日常跟进'") !== false
    && strpos($detail, "'UNSTARTED' => '未开始'") !== false
    && strpos($detail, "'project' => '项目'") !== false
    && strpos($detail, "'coupon' => '优惠券'") !== false);
memberDetailV2Ok('member-detail action still delegates debt and sales tabs to dedicated authorities',
    strpos($module, "(string)(\$payload['tab'] ?? '') === 'debt'") !== false
    && strpos($module, "(string)(\$payload['tab'] ?? '') === 'sales'") !== false
    && strpos($module, "'memberCenter' => ['detail' => \$detail]") !== false);
memberDetailV2Ok('all store-bound member facts reuse the data-scope store gate',
    substr_count($detail, '$this->applyStoreScope(') >= 7
    && strpos($detail, "'gf.store_id', \$dataScope") !== false
    && strpos($detail, "'business_store_id', \$dataScope") !== false
    && strpos($detail, "'h.store_id', \$dataScope") !== false
    && strpos($detail, 'CashierV3DataScopeContext::MODE_ALL') !== false);

echo "ASSERT_PASSED={$passed}\n";
echo "ASSERT_FAILED={$failed}\n";
exit($failed === 0 ? 0 : 1);
