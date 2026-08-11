<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$service = (string)file_get_contents(
    $root . '/后端代码/app/services/cashier/v3/settlement/CashierV3SaleProjectServiceCompletionServices.php'
);
$submission = (string)file_get_contents(
    $root . '/后端代码/app/services/cashier/v3/settlement/CashierV3SaleOnlyCheckoutSubmissionServices.php'
);
$records = (string)file_get_contents(
    $root . '/后端代码/app/services/cashier/v3/order/CashierV3OrderCenterRecordQueryServices.php'
);

$passed = 0;
$failed = 0;
$check = static function (string $name, bool $ok) use (&$passed, &$failed): void {
    if ($ok) {
        $passed++;
        echo "PASS {$name}\n";
        return;
    }
    $failed++;
    echo "FAIL {$name}\n";
};

$check('cash project service writer exists and uses the immutable service fact table',
    strpos($service, 'final class CashierV3SaleProjectServiceCompletionServices') !== false
    && strpos($service, "SERVICE_TABLE = 'cashier_v3_entitlement_service_fact'") !== false);
$check('only project sale lines create immediate services',
    strpos($service, "(string)(\$line['item_type'] ?? '') !== 'project'") !== false);
$check('guest and member service facts retain the settled sales snapshot',
    strpos($service, "'member_id' => (int)\$header['member_id']") !== false
    && strpos($service, "'member_name_snapshot' => (string)\$header['member_name_snapshot']") !== false
    && strpos($service, "'source_document_type' => 'sales_order'") !== false);
$check('service record numbers are allocated from the source fact and replay is immutable',
    strpos($service, "'sale_project_service_fact'") !== false
    && strpos($service, 'sale_project_service_replay_conflict') !== false
    && strpos($service, "'service_status' => 'completed'") !== false);
$check('paid project services preserve the selected craftsmen and primary craftsman',
    strpos($service, 'CashierV3CheckoutCraftsmenSnapshot::decode(') !== false
    && strpos($service, "'primary_craftsman_staff_id' => \$primaryCraftsmanStaffId") !== false
    && strpos($records, "?? \$item['name']") !== false
    && strpos($records, "'（点）'") !== false
    && strpos($records, "'（轮）'") !== false);
$check('sale checkout persists the service fact in the same transaction and records a service event',
    strpos($submission, '$this->saleProjectServices->completeInTx(') !== false
    && strpos($submission, "'event_type' => 'service.completed'") !== false
    && strpos($submission, "'eb_cashier_v3_entitlement_service_fact'") !== false);
$check('cash-purchased project completion also updates customer lifecycle in the same transaction',
    strpos($service, 'CustomerLifecycleFactServices') !== false
    && strpos($service, 'recordServiceCompletionInTx') !== false
    && strpos($service, "'checkout_request_id' => (string)\$header['checkout_request_id']") !== false);
$check('service records label cash purchase projects without misrepresenting a card writeoff',
    strpos($records, "'sf.source_document_type'") !== false
    && strpos($records, "return '现金购买项目';") !== false);

echo "sale-project-service-completion-contract: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
