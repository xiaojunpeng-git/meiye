<?php
declare(strict_types=1);

namespace app\services\cashier\v3\card;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\cashier\CashierV3CashierReadinessGuard;
use app\services\cashier\v3\cashier\CashierV3EntitlementResourceVersionProvider;
use app\services\cashier\v3\cashier\CashierV3SaleCatalogServices;
use app\services\cashier\v3\order\settlement\CashierV3SalesOrderPlanV1;
use app\services\user\CardNumberServices;
use think\facade\Db;

/**
 * Formal current-right issuer for a settled V3 card sale.
 *
 * V3 sales/payment/fact tables remain the accounting authority.  The legacy
 * order, holder and cart rows written here are deliberately a current-right
 * projection consumed by the existing write-off and entitlement readers.
 * Everything runs in the caller's final checkout transaction: a card is
 * never visible unless the matching V3 sale and payment also succeed.
 */
final class CashierV3CardPurchaseIssuanceServices
{
    public const CONTRACT_VERSION = 'cashier-v3-card-purchase-issuance-v1';
    public const RECEIPT_TABLE = 'cashier_v3_card_purchase_receipt';
    private const CUSTOM_CONFIGURATION_TABLE = 'cashier_v3_custom_card_configuration';
    private const TIME_CARD_COMPATIBILITY_CAPACITY = 1000000;

    /** @var CashierV3SaleCatalogServices */
    private $catalog;

    /** @var CashierV3EntitlementResourceVersionProvider */
    private $versions;

    /** @var CardNumberServices */
    private $cardNumbers;

    /** @var CashierV3IssuedCardRuleStateServices */
    private $ruleStates;

    public function __construct(
        CashierV3SaleCatalogServices $catalog = null,
        CashierV3EntitlementResourceVersionProvider $versions = null,
        CardNumberServices $cardNumbers = null,
        CashierV3IssuedCardRuleStateServices $ruleStates = null
    ) {
        $this->catalog = $catalog ?: new CashierV3SaleCatalogServices();
        $this->versions = $versions ?: new CashierV3EntitlementResourceVersionProvider(
            new CashierV3CashierReadinessGuard()
        );
        $this->cardNumbers = $cardNumbers ?: new CardNumberServices();
        $this->ruleStates = $ruleStates ?: new CashierV3IssuedCardRuleStateServices();
    }

    /**
     * @param array $aggregate locked checkout aggregate
     * @param array $salesResult persisted V3 sales writer result
     */
    public function issueInTx(
        array $aggregate,
        CashierV3SalesOrderPlanV1 $salesPlan,
        array $salesResult,
        string $commandIdempotencyKey,
        int $occurredAt,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        CashierV3TransactionGuard::assertInTransaction('cardPurchaseIssuance');
        $hasCard = false;
        foreach ($salesPlan->lines() as $candidate) {
            if (is_array($candidate) && (string)($candidate['item_type'] ?? '') === 'card') {
                $hasCard = true;
                break;
            }
        }
        // Ordinary retail checkout does not depend on this optional domain
        // table. It remains available while a store has not installed the
        // card-purchase migration yet.
        if (!$hasCard) {
            return $this->emptyResult();
        }
        $this->assertReady();
        $header = $salesPlan->header();
        $lockedRequest = is_array($aggregate['request'] ?? null) ? $aggregate['request'] : [];
        $workspaceId = trim((string)($lockedRequest['workspace_id'] ?? ''));
        if ($workspaceId === ''
            || (string)($lockedRequest['tenant_id'] ?? '') !== (string)($header['tenant_id'] ?? '')
            || (int)($lockedRequest['store_id'] ?? 0) !== (int)($header['store_id'] ?? 0)
            || (int)($lockedRequest['member_id'] ?? 0) !== (int)($header['member_id'] ?? 0)
            || (string)($lockedRequest['request_id'] ?? '') !== (string)($header['checkout_request_id'] ?? '')) {
            throw self::failure('card_purchase_workspace_scope_mismatch');
        }
        $memberId = (int)($header['member_id'] ?? 0);
        if ($memberId <= 0) {
            foreach ($salesPlan->lines() as $line) {
                if ((string)($line['item_type'] ?? '') === 'card') {
                    throw self::failure('card_purchase_member_required');
                }
            }
            return $this->emptyResult();
        }
        if ((string)($header['tenant_id'] ?? '') !== $dataScope->tenantId()
            || (int)($header['store_id'] ?? 0) !== $operatorScope->storeId()
            || (string)($salesResult['orderId'] ?? '') !== (string)($header['order_id'] ?? '')) {
            throw self::failure('card_purchase_sales_scope_mismatch');
        }
        $workspaceLineKeys = $this->workspaceLineKeysByCheckoutLineId(
            (array)($aggregate['lines'] ?? [])
        );
        $member = $this->lockActiveMember($memberId);
        $issued = [];
        foreach ($salesPlan->lines() as $salesLine) {
            if (!is_array($salesLine) || (string)($salesLine['item_type'] ?? '') !== 'card') {
                continue;
            }
            $quantity = (int)($salesLine['quantity'] ?? 0);
            if ($quantity <= 0 || $quantity > 1000) {
                throw self::failure('card_purchase_quantity_invalid');
            }
            for ($issueNo = 1; $issueNo <= $quantity; $issueNo++) {
                $issued[] = $this->issueOneInTx(
                    $header,
                    $salesLine,
                    $issueNo,
                    $workspaceId,
                    (string)($workspaceLineKeys[(string)($salesLine['checkout_line_id'] ?? '')] ?? ''),
                    $member,
                    $commandIdempotencyKey,
                    $occurredAt,
                    $operatorScope,
                    $dataScope
                );
            }
        }
        return [
            'contractVersion' => self::CONTRACT_VERSION,
            'issuedCardCount' => count($issued),
            'receipts' => $issued,
            'replayed' => count(array_filter($issued, static function (array $row): bool {
                return !empty($row['replayed']);
            })) === count($issued),
        ];
    }

    private function issueOneInTx(
        array $header,
        array $salesLine,
        int $issueNo,
        string $workspaceId,
        string $workspaceLineKey,
        array $member,
        string $commandIdempotencyKey,
        int $occurredAt,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        $salesOrderLineId = trim((string)($salesLine['order_line_id'] ?? ''));
        $skuId = (int)($salesLine['catalog_sku_id'] ?? 0);
        if ($salesOrderLineId === '' || $skuId <= 0 || $issueNo <= 0) {
            throw self::failure('card_purchase_sales_line_invalid');
        }
        $receiptId = 'CPU-' . strtoupper(substr(hash('sha256', implode('|', [
            $header['tenant_id'], $salesOrderLineId, (string)$issueNo,
        ])), 0, 40));
        $existing = Db::name(self::RECEIPT_TABLE)
            ->where('tenant_id', (string)$header['tenant_id'])
            ->where('receipt_id', $receiptId)
            ->lock(true)
            ->find();
        if ($existing) {
            return $this->replay($existing, $header, $salesLine, $issueNo, $commandIdempotencyKey);
        }

        $customConfiguration = $this->lockCustomConfiguration(
            $header,
            $workspaceId,
            $workspaceLineKey
        );
        // Re-read under the transaction and require the exact priced catalog
        // definition that the checkout request froze.  The Gateway already
        // locked the same catalog resources; this is an explicit defence
        // against a direct service call with a forged V3 order line.
        $line = $customConfiguration
            ? $this->catalog->customCardIssuanceLineInTx($customConfiguration, $operatorScope, $dataScope)
            : $this->catalog->selectSaleLineInTx(
                $skuId,
                'card-issue:' . strtolower($receiptId),
                $operatorScope,
                $dataScope
            );
        $snapshot = is_array($line['authority_snapshot'] ?? null)
            ? $line['authority_snapshot'] : [];
        $purchase = is_array($snapshot['cardPurchase'] ?? null) ? $snapshot['cardPurchase'] : [];
        $this->assertSalesLineMatchesCatalog($salesLine, $line, $purchase, $header, $dataScope);

        $legacyOrderId = $this->insertLegacyOrder(
            $header,
            $salesLine,
            $issueNo,
            $member,
            $occurredAt
        );
        $validity = $this->validity($purchase, $occurredAt);
        $baseCartId = $this->insertBaseCart(
            $legacyOrderId,
            $header,
            $salesLine,
            $line,
            $validity,
            $issueNo,
            $occurredAt
        );
        $components = $this->insertComponents(
            $legacyOrderId,
            $header,
            $purchase,
            $validity,
            $issueNo,
            $occurredAt
        );
        $reportCategoryComponents = $this->reportCategoryComponents(
            (array)($components['issuedComponents'] ?? [])
        );
        $holder = $this->insertHolder(
            $legacyOrderId,
            $header,
            $salesLine,
            $line,
            $validity,
            $components,
            $issueNo,
            $occurredAt
        );
        if ($customConfiguration) {
            $this->insertCustomCardState($header, $holder, $legacyOrderId, $validity, $purchase, $occurredAt);
        }
        $ruleState = $this->ruleStates->issueInTx(
            $receiptId,
            $header,
            $salesLine,
            $line,
            $purchase,
            $validity,
            $components,
            $holder,
            $occurredAt
        );
        $this->synchronizeVersions(
            (int)$holder['holderId'],
            (array)$components['benefitDetailIds'],
            $operatorScope,
            $dataScope
        );
        $result = [
            'receiptId' => $receiptId,
            'salesOrderLineId' => $salesOrderLineId,
            'issueNo' => $issueNo,
            'legacyOrderId' => $legacyOrderId,
            'holderId' => (int)$holder['holderId'],
            'cardNo' => (string)$holder['cardNo'],
            'baseCartId' => $baseCartId,
            'benefitDetailIds' => array_values((array)$components['benefitDetailIds']),
            'reportCategoryComponents' => $reportCategoryComponents,
            'debtAmountCents' => 0,
            'cardRuleStateId' => (string)($ruleState['stateId'] ?? ''),
            'cardRuleType' => (string)($ruleState['ruleType'] ?? ''),
            'replayed' => false,
        ];
        if ($customConfiguration) {
            $this->settleCustomConfiguration($customConfiguration, $header, $salesLine, (int)$holder['holderId'], $occurredAt);
        }
        $fingerprint = self::fingerprint([
            'contractVersion' => self::CONTRACT_VERSION,
            'receiptId' => $receiptId,
            'header' => $header,
            'salesLine' => $salesLine,
            'issueNo' => $issueNo,
            'catalog' => $snapshot,
            'result' => $result,
        ]);
        try {
            $id = (int)Db::name(self::RECEIPT_TABLE)->insertGetId([
                'receipt_id' => $receiptId,
                'tenant_id' => (string)$header['tenant_id'],
                'checkout_request_id' => (string)$header['checkout_request_id'],
                'sales_order_id' => (string)$header['order_id'],
                'sales_order_line_id' => $salesOrderLineId,
                'issue_no' => $issueNo,
                'command_idempotency_key' => $commandIdempotencyKey,
                'immutable_fingerprint' => $fingerprint,
                'contract_version' => self::CONTRACT_VERSION,
                'store_id' => (int)$header['store_id'],
                'member_id' => (int)$header['member_id'],
                'catalog_product_id' => (int)$line['catalog_product_id'],
                'catalog_sku_id' => $skuId,
                'legacy_order_id' => $legacyOrderId,
                'card_holder_id' => (int)$holder['holderId'],
                'base_cart_id' => $baseCartId,
                'benefit_detail_ids_json' => $this->json(array_values((array)$components['benefitDetailIds'])),
                'result_snapshot_json' => $this->json($result),
                'status' => 'completed',
                'occurred_at' => $occurredAt,
                'settled_at' => $occurredAt,
                'recorded_at' => $occurredAt,
                'add_time' => $occurredAt,
                'update_time' => $occurredAt,
            ]);
            if ($id <= 0) {
                throw self::failure('card_purchase_receipt_insert_failed');
            }
        } catch (\Throwable $exception) {
            if (!$this->duplicate($exception)) {
                throw $exception;
            }
            $raced = Db::name(self::RECEIPT_TABLE)
                ->where('tenant_id', (string)$header['tenant_id'])
                ->where('receipt_id', $receiptId)
                ->lock(true)
                ->find();
            if (!$raced) {
                throw $exception;
            }
            return $this->replay($raced, $header, $salesLine, $issueNo, $commandIdempotencyKey);
        }
        return $result;
    }

    /**
     * Freeze the category used by the six operating reports on each issued
     * card receipt. A card's outer catalog category must not replace the
     * category of its contained service projects.
     *
     * @param array<int,array{detailId:int,snapshot:array}> $issuedComponents
     * @return array<int,array{productId:int,projectNameSnapshot:string,componentCount:int,categoryIdSnapshot:int,categoryNameSnapshot:string,allocationWeightCents:int}>
     */
    private function reportCategoryComponents(array $issuedComponents): array
    {
        $result = [];
        $hasPositiveWeight = false;
        foreach ($issuedComponents as $issuedComponent) {
            $component = is_array($issuedComponent['snapshot'] ?? null)
                ? $issuedComponent['snapshot'] : [];
            $productId = (int)($component['productId'] ?? 0);
            if ($productId <= 0 || (int)($component['productType'] ?? -1) !== 6) {
                continue;
            }
            $projectName = trim((string)($component['nameSnapshot'] ?? ''));
            if ($projectName === '') {
                throw self::failure('card_purchase_component_report_name_missing');
            }
            $categoryId = (int)($component['categoryIdSnapshot'] ?? 0);
            $categoryName = trim((string)($component['categoryNameSnapshot'] ?? ''));
            if ($categoryId <= 0 || $categoryName === '') {
                [$categoryId, $categoryName] = $this->lockProjectCategorySnapshot($productId);
            }
            $weight = array_key_exists('configuredAmountCents', $component)
                ? (int)$component['configuredAmountCents']
                : $this->multiply(
                    (int)($component['configuredPriceCents'] ?? 0),
                    max(1, (int)($component['writeTimes'] ?? 0))
                );
            if ($weight < 0) {
                throw self::failure('card_purchase_component_report_weight_invalid');
            }
            $hasPositiveWeight = $hasPositiveWeight || $weight > 0;
            $result[] = [
                'productId' => $productId,
                'projectNameSnapshot' => $projectName,
                'componentCount' => max(0, (int)($component['writeTimes'] ?? 0)),
                'categoryIdSnapshot' => $categoryId,
                'categoryNameSnapshot' => $categoryName,
                'allocationWeightCents' => $weight,
            ];
        }
        // A zero-price card still needs a visible, zero-value category fact.
        // Equal unit weights make that deterministic without inventing money.
        if ($result !== [] && !$hasPositiveWeight) {
            foreach ($result as &$component) {
                $component['allocationWeightCents'] = 1;
            }
            unset($component);
        }
        return $result;
    }

    /** @return array{0:int,1:string} */
    private function lockProjectCategorySnapshot(int $projectId): array
    {
        $project = Db::name('store_product')
            ->where('id', $projectId)
            ->lock(true)
            ->field('id,pid,cate_id')
            ->find();
        $categoryIds = $this->categoryIds((string)($project['cate_id'] ?? ''));
        if ($categoryIds === [] && (int)($project['pid'] ?? 0) > 0) {
            $parent = Db::name('store_product')
                ->where('id', (int)$project['pid'])
                ->lock(true)
                ->field('cate_id')
                ->find();
            $categoryIds = $this->categoryIds((string)($parent['cate_id'] ?? ''));
        }
        $categoryId = (int)($categoryIds[0] ?? 0);
        $category = $categoryId > 0
            ? Db::name('store_product_category')->where('id', $categoryId)->lock(true)->field('id,cate_name')->find()
            : null;
        $categoryName = trim((string)($category['cate_name'] ?? ''));
        if ($categoryId <= 0 || $categoryName === '') {
            throw self::failure('card_purchase_component_report_category_missing');
        }
        return [$categoryId, $categoryName];
    }

    /** @return array<int,int> */
    private function categoryIds(string $value): array
    {
        $ids = [];
        foreach (explode(',', $value) as $candidate) {
            $id = (int)trim($candidate);
            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    private function insertLegacyOrder(
        array $header,
        array $salesLine,
        int $issueNo,
        array $member,
        int $now
    ): int
    {
        $lineId = (string)$salesLine['order_line_id'];
        $seed = strtolower($lineId . ':' . $issueNo);
        $saleCents = $this->divideExactly((int)$salesLine['sale_amount_cents'], (int)$salesLine['quantity']);
        $originalCents = $this->divideExactly((int)$salesLine['original_amount_cents'], (int)$salesLine['quantity']);
        $discountCents = $this->divideExactly((int)$salesLine['discount_amount_cents'], (int)$salesLine['quantity']);
        $collectedCents = $saleCents;
        $orderId = 'v3c' . substr(hash('sha256', $seed), 0, 28);
        $unique = md5('cashier-v3-card:' . $seed);
        $verifyCode = strtoupper(substr(hash('sha256', 'verify:' . $seed), 0, 12));
        $name = trim((string)($member['real_name'] ?? $member['nickname'] ?? ''));
        if ($name === '') {
            $name = '会员' . (int)$header['member_id'];
        }
        $id = (int)Db::name('store_order')->insertGetId([
            'type' => 11,
            'pid' => 0,
            'order_id' => $orderId,
            'supplier_id' => 0,
            'store_id' => (int)$header['store_id'],
            'trade_no' => '',
            'uid' => (int)$header['member_id'],
            'real_name' => $name,
            'user_phone' => trim((string)($member['phone'] ?? '')),
            'user_address' => '',
            'user_location' => '',
            'cart_id' => '[]',
            'activity_id' => 0,
            'activity_append' => '',
            'freight_price' => '0.00',
            'total_num' => 1,
            'total_price' => self::money($originalCents),
            'settle_price' => self::money($saleCents),
            'total_postage' => '0.00',
            'pay_price' => self::money($collectedCents),
            'cash_pay_price' => self::money($collectedCents),
            'debt_amount' => '0.00',
            'repaid_debt_amount' => '0.00',
            'is_debt_repay' => 0,
            'debt_repay_origin_order_id' => 0,
            'debt_repay_item_id' => 0,
            'yue_pay_price' => '0.00',
            'paid_ben_amount' => '0.00',
            'paid_give_amount' => '0.00',
            'paid_balance_ready' => 0,
            'reopen_source_order_id' => 0,
            'pay_postage' => '0.00',
            'pay_integral' => 0,
            'deduction_price' => self::money($discountCents),
            'coupon_id' => 0,
            'coupon_price' => '0.00',
            'promotions_price' => '0.00',
            'first_order_price' => '0.00',
            'change_price' => '0.00',
            'service_price' => '0.00',
            'paid' => 1,
            'inventory_handled' => 0,
            'sales_handled' => 1,
            'pay_type' => 'cashier_v3',
            'status' => 0,
            'refund_status' => 0,
            'card_upgrade_use_oid' => 0,
            'service_object' => '本人',
            'refund_type' => 0,
            'terminal_action' => 0,
            'terminal_operation_id' => 0,
            'terminal_action_time' => 0,
            'refund_express' => '',
            'refund_reason_wap_explain' => '',
            'refund_reason_time' => 0,
            'refund_reason_wap' => '',
            'refund_reason' => '',
            'refund_price' => '0.00',
            'delivery_name' => '',
            'delivery_code' => '',
            'delivery_type' => '',
            'delivery_id' => '',
            'fictitious_content' => '',
            'delivery_uid' => 0,
            'gain_integral' => '0.00',
            'use_integral' => '0.00',
            'back_integral' => '0.00',
            'spread_uid' => 0,
            'spread_two_uid' => 0,
            'one_brokerage' => '0.00',
            'two_brokerage' => '0.00',
            'mark' => 'V3卡项正式签发',
            'is_del' => 0,
            'is_user_del' => 0,
            'unique' => $unique,
            'remark' => 'V3 sales order ' . (string)$header['order_no'],
            'mer_id' => 0,
            'is_mer_check' => 0,
            'pink_id' => 0,
            'cost' => '0.00',
            'verify_code' => $verifyCode,
            'staff_id' => 0,
            'shipping_type' => 2,
            'store_delivery_type' => 0,
            'clerk_id' => 0,
            'is_channel' => 5,
            'is_remind' => 0,
            'is_system_del' => 0,
            'channel_type' => 'cashier_v3',
            'province' => '',
            'kuaidi_label' => '',
            'product_type' => 5,
            'custom_form_title' => '',
            'custom_form' => '[[]]',
            'system_form_type' => 1,
            'give_integral' => 0,
            'give_coupon' => '',
            'erp_id' => 0,
            'erp_order_id' => '',
            'kuaidi_task_id' => '',
            'kuaidi_order_id' => 0,
            'is_stock_up' => 0,
            'reservation_type' => 2,
            'reservation_time' => 0,
            'reservation_time_id' => 0,
            'reservation_show_time' => '',
            'service_staff_id' => 0,
            'reservation_status' => -1,
            'shipping_time' => 0,
            'pay_time' => $now,
            'delivery_time' => 0,
            'estimate_time' => '',
            'add_time' => $now,
            'selected_product' => (string)$salesLine['item_id'],
            'cash_choose' => 0,
            'remark_info' => '[]',
            'source' => 0,
            'yeji' => '[]',
            'service_yeji' => '[]',
            'order_type' => 0,
            'link_id' => 0,
            'link_order' => 0,
            'back_reason' => '',
            'is_budan' => 0,
            'is_gendan' => 0,
            'gendan_staff_id' => 0,
            'yue_money' => '0.00',
            'is_auto' => 0,
        ]);
        if ($id <= 0) {
            throw self::failure('card_purchase_legacy_order_insert_failed');
        }
        return $id;
    }

    private function insertBaseCart(
        int $orderId,
        array $header,
        array $salesLine,
        array $line,
        array $validity,
        int $issueNo,
        int $now
    ): int {
        $saleCents = $this->divideExactly((int)$salesLine['sale_amount_cents'], (int)$salesLine['quantity']);
        $originalCents = $this->divideExactly((int)$salesLine['original_amount_cents'], (int)$salesLine['quantity']);
        $discountCents = $this->divideExactly((int)$salesLine['discount_amount_cents'], (int)$salesLine['quantity']);
        $collectedCents = $saleCents;
        $cartId = 'v3b' . substr(hash('sha256', (string)$salesLine['order_line_id'] . ':' . $issueNo), 0, 28);
        $cartInfo = [
            'sourceType' => 'cashier_v3_card_purchase',
            'salesOrderId' => (string)$header['order_id'],
            'salesOrderLineId' => (string)$salesLine['order_line_id'],
            'issueNo' => $issueNo,
            'product_id' => (int)$line['catalog_product_id'],
            'product_attr_unique' => (string)($line['authority_snapshot']['sku']['unique'] ?? ''),
            'productInfo' => [
                'id' => (int)$line['catalog_product_id'],
                'store_name' => (string)($line['display_snapshot']['name'] ?? $salesLine['item_name_snapshot']),
                'product_type' => 5,
            ],
            'attrInfo' => [
                'unique' => (string)($line['authority_snapshot']['sku']['unique'] ?? ''),
            ],
            'truePrice' => self::money($originalCents),
            'pay_price' => self::money($collectedCents),
            'write_times' => 0,
            'write_start' => $validity['writeStart'],
            'write_end' => $validity['writeEnd'],
        ];
        $id = (int)Db::name('store_order_cart_info')->insertGetId([
            'uid' => (int)$header['member_id'],
            'oid' => $orderId,
            'cart_id' => $cartId,
            'cart_type' => 0,
            'type' => 1,
            'relation_id' => (int)$header['store_id'],
            'staff_id' => 0,
            'delivery_id' => 0,
            'product_id' => (int)$line['catalog_product_id'],
            'product_type' => 5,
            'sku_unique' => (string)($line['authority_snapshot']['sku']['unique'] ?? ''),
            'promotions_id' => '',
            'is_gift' => 0,
            'is_card' => 0,
            'is_support_refund' => 0,
            'old_cart_id' => '',
            'cart_num' => 1,
            'total_price' => self::money($originalCents),
            'settle_price' => self::money($saleCents),
            'pay_price' => self::money($saleCents),
            'yue_pay_amount' => '0.00',
            'card_upgrade_amount' => self::money($discountCents),
            'cash_pay_amount' => self::money($collectedCents),
            'debt_amount' => '0.00',
            'repaid_debt_amount' => '0.00',
            'pay_postage' => '0.00',
            'member_price' => self::money($saleCents),
            'deduction_price' => self::money($discountCents),
            'coupon_price' => '0.00',
            'promotions_price' => '0.00',
            'first_order_price' => '0.00',
            'change_price' => '0.00',
            'service_price' => '0.00',
            'refund_num' => 0,
            'surplus_num' => 0,
            'split_surplus_num' => 0,
            'split_status' => 0,
            'write_times' => 0,
            'write_surplus_times' => 0,
            'write_start' => $validity['writeStart'],
            'write_end' => $validity['writeEnd'],
            'is_advent_sms' => 0,
            'is_expire_sms' => 0,
            'is_writeoff' => 0,
            'source_type' => 'cashier_v3',
            'replacement_id' => 0,
            'writeoff_time' => 0,
            'reservation_type' => 2,
            'reservation_time' => 0,
            'reservation_time_id' => 0,
            'cart_info' => $this->json($cartInfo),
            'unique' => md5('cashier-v3-card-base:' . $cartId),
            'add_time' => $now,
        ]);
        if ($id <= 0) {
            throw self::failure('card_purchase_base_cart_insert_failed');
        }
        Db::name('store_order')->where('id', $orderId)->update(['cart_id' => $this->json([(string)$id])]);
        return $id;
    }

    private function insertComponents(
        int $orderId,
        array $header,
        array $purchase,
        array $validity,
        int $issueNo,
        int $now
    ): array {
        $benefitIds = [];
        $issuedComponents = [];
        $allTimes = 0;
        $isCustomCard = (string)($purchase['sourceKind'] ?? '') === 'custom_card';
        $ruleType = trim((string)($purchase['ruleType'] ?? ''));
        foreach ((array)($purchase['components'] ?? []) as $index => $component) {
            if (!is_array($component)) {
                throw self::failure('card_purchase_component_invalid');
            }
            $productId = (int)($component['productId'] ?? 0);
            $skuUnique = trim((string)($component['skuUnique'] ?? ''));
            $productType = (int)($component['productType'] ?? -1);
            $times = (int)($component['writeTimes'] ?? 0);
            $priceCents = (int)($isCustomCard
                ? ($component['configuredAmountCents'] ?? -1)
                : ($component['configuredPriceCents'] ?? -1));
            $usesIndependentTimes = $isCustomCard
                || $ruleType === ''
                || in_array($ruleType, ['normal', 'choice_kind'], true);
            if ($productId <= 0 || $skuUnique === '' || !in_array($productType, [0, 6], true)
                || ($usesIndependentTimes ? $times <= 0 : $times !== 0)
                || $priceCents < 0) {
                throw self::failure('card_purchase_component_snapshot_invalid');
            }
            $amount = $isCustomCard
                ? (int)($component['configuredAmountCents'] ?? -1)
                : ($usesIndependentTimes ? $this->multiply($priceCents, $times) : $priceCents);
            if ($amount < 0) {
                throw self::failure('card_purchase_component_amount_invalid');
            }
            $cartId = 'v3r' . substr(hash('sha256', $orderId . ':' . $index . ':' . $issueNo), 0, 28);
            $legacyTimes = $times;
            if ($ruleType === 'choice_count') {
                $legacyTimes = (int)($purchase['sharedTimes'] ?? 0);
            } elseif ($ruleType === 'time') {
                $legacyTimes = self::TIME_CARD_COMPATIBILITY_CAPACITY;
            }
            $cartInfo = [
                'sourceType' => 'cashier_v3_card_purchase',
                'cardProductId' => (int)($purchase['productId'] ?? 0),
                'product_id' => $productId,
                'product_type' => $productType,
                'product_attr_unique' => $skuUnique,
                'write_times' => $times,
                'legacy_projection_times' => $legacyTimes,
                'card_rule_type' => $ruleType,
                'writeoff_amount_cents' => (int)($component['writeoffAmountCents'] ?? 0),
                'pay_price' => self::money($amount),
                'productInfo' => [
                    'id' => $productId,
                    'store_name' => (string)($component['nameSnapshot'] ?? ''),
                    'product_type' => $productType,
                    'attrInfo' => ['unique' => $skuUnique],
                ],
            ];
            $id = (int)Db::name('store_order_cart_info')->insertGetId([
                'uid' => (int)$header['member_id'],
                'oid' => $orderId,
                'cart_id' => $cartId,
                'cart_type' => 2,
                'type' => 1,
                'relation_id' => (int)$header['store_id'],
                'staff_id' => 0,
                'delivery_id' => 0,
                'product_id' => $productId,
                'product_type' => $productType,
                'sku_unique' => $skuUnique,
                'promotions_id' => '',
                'is_gift' => 0,
                'is_card' => 1,
                'is_support_refund' => 0,
                'old_cart_id' => '',
                'cart_num' => $legacyTimes,
                'total_price' => self::money($amount),
                'settle_price' => self::money($amount),
                'pay_price' => self::money($amount),
                'yue_pay_amount' => '0.00',
                'card_upgrade_amount' => '0.00',
                'cash_pay_amount' => '0.00',
                'debt_amount' => '0.00',
                'repaid_debt_amount' => '0.00',
                'pay_postage' => '0.00',
                'member_price' => self::money($amount),
                'deduction_price' => '0.00',
                'coupon_price' => '0.00',
                'promotions_price' => '0.00',
                'first_order_price' => '0.00',
                'change_price' => '0.00',
                'service_price' => '0.00',
                'refund_num' => 0,
                'surplus_num' => $legacyTimes,
                'split_surplus_num' => $legacyTimes,
                'split_status' => 0,
                'write_times' => $legacyTimes,
                'write_surplus_times' => $legacyTimes,
                'write_start' => $validity['writeStart'],
                'write_end' => $validity['writeEnd'],
                'is_advent_sms' => 0,
                'is_expire_sms' => 0,
                'is_writeoff' => 0,
                'source_type' => 'cashier_v3',
                'replacement_id' => 0,
                'writeoff_time' => 0,
                'reservation_type' => 2,
                'reservation_time' => 0,
                'reservation_time_id' => 0,
                'cart_info' => $this->json($cartInfo),
                'unique' => md5('cashier-v3-card-component:' . $cartId),
                'add_time' => $now,
            ]);
            if ($id <= 0) {
                throw self::failure('card_purchase_component_insert_failed');
            }
            if ($productType === 6) {
                $benefitIds[] = $id;
            }
            $issuedComponents[] = ['detailId' => $id, 'snapshot' => $component];
            $allTimes += $legacyTimes;
        }
        if ($ruleType === 'choice_count') {
            $allTimes = (int)($purchase['sharedTimes'] ?? 0);
        } elseif ($ruleType === 'time') {
            $allTimes = self::TIME_CARD_COMPATIBILITY_CAPACITY;
        }
        return [
            'benefitDetailIds' => $benefitIds,
            'issuedComponents' => $issuedComponents,
            'allWriteTimes' => $allTimes,
        ];
    }

    private function insertHolder(
        int $orderId,
        array $header,
        array $salesLine,
        array $line,
        array $validity,
        array $components,
        int $issueNo,
        int $now
    ): array {
        $holderData = [
            'uid' => (int)$header['member_id'],
            'oid' => $orderId,
            'card_name' => (string)($line['authority_snapshot']['cardPurchase']['cardNameSnapshot']
                ?? $line['display_snapshot']['name']
                ?? $salesLine['item_name_snapshot']),
            'store_id' => (int)$header['store_id'],
            'product_id' => (int)$line['catalog_product_id'],
            'product_type' => 5,
            'verify_code' => strtoupper(substr(hash('sha256', (string)$salesLine['order_line_id'] . ':' . $issueNo), 0, 12)),
            'write_valid' => $validity['writeValid'],
            'write_days' => $validity['writeDays'],
            'write_start' => $validity['writeStart'],
            'write_end' => $validity['writeEnd'],
            'write_times' => (int)$components['allWriteTimes'],
            'write_surplus_times' => (int)$components['allWriteTimes'],
            'is_del' => 0,
            'add_time' => $now,
        ];
        $holderId = $this->cardNumbers->withAllocateRetry(function (string $cardNo) use ($holderData): int {
            $holderData['card_no'] = $cardNo;
            return (int)Db::name('user_card_holder')->insertGetId($holderData);
        }, null, 'cashier_v3_card_purchase');
        if ($holderId <= 0) {
            throw self::failure('card_purchase_holder_insert_failed');
        }
        $cardNo = trim((string)Db::name('user_card_holder')->where('id', $holderId)->value('card_no'));
        if (!$this->cardNumbers->isValidCardNo($cardNo)) {
            throw self::failure('card_purchase_holder_number_invalid');
        }
        return ['holderId' => $holderId, 'cardNo' => $cardNo];
    }

    private function synchronizeVersions(
        int $holderId,
        array $benefitDetailIds,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): void {
        $this->versions->synchronizeProjectionVersion(
            'card_holder',
            (string)$holderId,
            $operatorScope,
            $dataScope
        );
        foreach ($benefitDetailIds as $detailId) {
            $this->versions->synchronizeProjectionVersion(
                'member_benefit_pool',
                (string)(int)$detailId,
                $operatorScope,
                $dataScope
            );
        }
    }

    private function assertSalesLineMatchesCatalog(
        array $salesLine,
        array $line,
        array $purchase,
        array $header,
        CashierV3DataScopeContext $dataScope
    ): void
    {
        $sourceKind = (string)($purchase['sourceKind'] ?? '');
        $isCustom = $sourceKind === 'custom_card';
        $identityMismatch = ($isCustom
                ? (int)($line['catalog_product_type'] ?? -1) !== 6
                : (int)($line['catalog_product_type'] ?? -1) !== 5)
            || (int)($salesLine['item_id'] ?? 0) !== (int)($line['catalog_product_id'] ?? 0)
            || (int)($salesLine['catalog_sku_id'] ?? 0) !== (int)($line['catalog_sku_id'] ?? 0)
            || (int)($salesLine['item_version'] ?? 0) !== (int)($line['source_version'] ?? 0);
        $fullPriceCents = $this->multiply(
            (int)($line['unit_price_cents'] ?? -1),
            (int)($salesLine['quantity'] ?? 0)
        );
        $ordinaryPriceMatches = (int)($salesLine['sale_amount_cents'] ?? -1) === $fullPriceCents;
        $auditedPriceChangeMatches = !$isCustom
            && $this->isAuthorizedManualPriceSettlement($salesLine, $line);
        $boundUpgrade = $this->isBoundCardUpgradeSettlement(
            $salesLine,
            $line,
            $header,
            $dataScope
        );
        if ($identityMismatch
            || (!$isCustom && $sourceKind !== 'card_package')
            || !is_array($purchase['components'] ?? null)
            || !$purchase['components']
            || (!$ordinaryPriceMatches && !$auditedPriceChangeMatches && !$boundUpgrade)) {
            throw self::failure('card_purchase_catalog_authority_changed');
        }
    }

    private function isAuthorizedManualPriceSettlement(array $salesLine, array $line): bool
    {
        $quantity = (int)($salesLine['quantity'] ?? 0);
        $saleAmount = (int)($salesLine['sale_amount_cents'] ?? -1);
        $configuredCost = (int)($salesLine['configured_cost_cents'] ?? -1);
        $currentCost = (int)($line['configured_cost_cents'] ?? -2);
        if ($quantity <= 0 || $saleAmount < 0 || $configuredCost < 0
            || $configuredCost !== $currentCost
            || ($configuredCost > 0 && $quantity > intdiv(PHP_INT_MAX, $configuredCost))) {
            return false;
        }

        return (int)($salesLine['price_changed_at'] ?? 0) > 0
            && (int)($salesLine['price_changed_by'] ?? 0) > 0
            && trim((string)($salesLine['price_changed_by_name_snapshot'] ?? '')) !== ''
            && trim((string)($salesLine['price_change_reason'] ?? '')) !== ''
            && $saleAmount >= $configuredCost * $quantity;
    }

    /**
     * An upgrade credit is valid only for the pending card-operation row bound
     * by checkout preparation. It cannot be reproduced by an ordinary card
     * sale that happens to use the same member or catalogue SKU.
     */
    private function isBoundCardUpgradeSettlement(
        array $salesLine,
        array $line,
        array $header,
        CashierV3DataScopeContext $dataScope
    ): bool {
        if ((int)($salesLine['quantity'] ?? 0) !== 1
            || (string)($header['tenant_id'] ?? '') !== $dataScope->tenantId()
            || (string)($header['checkout_request_id'] ?? '') === ''
            || (int)($salesLine['original_amount_cents'] ?? -1) !== (int)($line['unit_price_cents'] ?? -2)) {
            return false;
        }
        $operation = Db::name('cashier_v3_card_operation')
            ->where('tenant_id', $dataScope->tenantId())
            ->where('checkout_request_id', (string)$header['checkout_request_id'])
            ->where('operation_type', 'card_upgrade')
            ->where('operation_status', 'awaiting_checkout')
            ->lock(true)
            ->find();
        $couponDiscount = (int)($salesLine['coupon_discount_cents'] ?? 0);
        if (!$operation
            || (int)($operation['store_id'] ?? 0) !== (int)($header['store_id'] ?? 0)
            || (int)($operation['member_id_before'] ?? 0) !== (int)($header['member_id'] ?? 0)
            || (int)($operation['target_catalog_id'] ?? 0) !== (int)($salesLine['item_id'] ?? 0)
            || (int)($operation['target_price_cents'] ?? -1) !== (int)($salesLine['original_amount_cents'] ?? -2)
            || $couponDiscount < 0
            || $couponDiscount > (int)($operation['target_price_cents'] ?? -1)
            || (int)($salesLine['sale_amount_cents'] ?? -1)
                !== (int)($operation['target_price_cents'] ?? -2) - $couponDiscount) {
            return false;
        }
        return true;
    }

    /** @return array<string,string> checkout line id => workspace line key */
    private function workspaceLineKeysByCheckoutLineId(array $checkoutLines): array
    {
        $result = [];
        foreach ($checkoutLines as $line) {
            if (!is_array($line) || (string)($line['line_role'] ?? '') !== 'sale') {
                continue;
            }
            $checkoutLineId = trim((string)($line['line_id'] ?? ''));
            $authorityKey = trim((string)($line['authority_key'] ?? ''));
            if ($checkoutLineId === '' || strpos($authorityKey, 'sale:') !== 0) {
                continue;
            }
            $workspaceLineKey = substr($authorityKey, strlen('sale:'));
            if ($workspaceLineKey === '' || isset($result[$checkoutLineId])) {
                throw self::failure('card_purchase_workspace_line_binding_invalid');
            }
            $result[$checkoutLineId] = $workspaceLineKey;
        }
        return $result;
    }

    private function lockCustomConfiguration(
        array $header,
        string $workspaceId,
        string $workspaceLineKey
    ): ?array {
        if ($workspaceLineKey === '') {
            return null;
        }
        $row = Db::name(self::CUSTOM_CONFIGURATION_TABLE)
            ->where('tenant_id', (string)$header['tenant_id'])
            ->where('store_id', (int)$header['store_id'])
            ->where('workspace_id', $workspaceId)
            ->where('workspace_line_key', $workspaceLineKey)
            ->lock(true)
            ->find();
        if (!$row) {
            return null;
        }
        $row = (array)$row;
        if ((string)($row['status'] ?? '') !== 'in_cart'
            || (int)($row['member_id'] ?? 0) !== (int)$header['member_id']
            || (int)($row['resource_version'] ?? 0) <= 0) {
            throw self::failure('custom_card_configuration_not_issuable');
        }
        $snapshot = json_decode((string)($row['configuration_snapshot_json'] ?? ''), true);
        if (!is_array($snapshot) || (int)($snapshot['totalAmountCents'] ?? 0) <= 0
            || !is_array($snapshot['components'] ?? null)
            || !$snapshot['components']) {
            throw self::failure('custom_card_configuration_snapshot_invalid');
        }
        $row['configuration_snapshot'] = $snapshot;
        return $row;
    }

    private function settleCustomConfiguration(
        array $configuration,
        array $header,
        array $salesLine,
        int $holderId,
        int $now
    ): void {
        $updated = Db::name(self::CUSTOM_CONFIGURATION_TABLE)
            ->where('configuration_id', (string)$configuration['configuration_id'])
            ->where('tenant_id', (string)$header['tenant_id'])
            ->where('status', 'in_cart')
            ->where('resource_version', (int)$configuration['resource_version'])
            ->update([
                'status' => 'settled',
                'resource_version' => (int)$configuration['resource_version'] + 1,
                'checkout_request_id' => (string)$header['checkout_request_id'],
                'sales_order_line_id' => (string)$salesLine['order_line_id'],
                'card_holder_id' => $holderId,
                'settled_at' => $now,
                'update_time' => $now,
            ]);
        if ((int)$updated !== 1) {
            throw self::failure('custom_card_configuration_settlement_race');
        }
    }

    private function insertCustomCardState(
        array $header,
        array $holder,
        int $legacyOrderId,
        array $validity,
        array $purchase,
        int $now
    ): void {
        $status = !empty($purchase['activateOnPurchase']) ? 'enabled' : 'disabled';
        $inserted = Db::name('cashier_v3_card_state')->insert([
            'tenant_id' => (string)$header['tenant_id'],
            'card_holder_id' => (int)$holder['holderId'],
            'origin_order_id' => $legacyOrderId,
            'origin_member_id' => (int)$header['member_id'],
            'current_member_id' => (int)$header['member_id'],
            'card_status' => $status,
            'status_reason_snapshot' => $status === 'enabled' ? '' : '购卡时选择暂不开卡',
            'effective_write_start' => (int)$validity['writeStart'],
            'effective_write_end' => (int)$validity['writeEnd'],
            'current_version' => 1,
            'last_operation_id' => '',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ((int)$inserted !== 1) {
            throw self::failure('custom_card_state_insert_failed');
        }
    }

    private function validity(array $purchase, int $now): array
    {
        // 加购阶段只冻结卡项的有效期规则；到成功结账并签发权益时，才以
        // 可信成交时间计算“购买后 N 天有效”的真实起止时间。
        $validity = is_array($purchase['validity'] ?? null) ? $purchase['validity'] : [];
        $mode = (int)($validity['writeValid'] ?? 0);
        $days = (int)($validity['writeDays'] ?? 0);
        if (!in_array($mode, [1, 2, 3], true) || ($mode === 2 && $days <= 0)) {
            throw self::failure('card_purchase_validity_invalid');
        }
        if ($mode === 3) {
            $start = (int)($validity['writeStart'] ?? 0);
            $end = (int)($validity['writeEnd'] ?? 0);
            // Custom-card configurations deliberately use writeStart=0 as a
            // stable pre-settlement snapshot.  The actual activation boundary
            // is the successful checkout time, never the earlier cart read.
            if ($start === 0 && (string)($purchase['sourceKind'] ?? '') === 'custom_card') {
                $start = $now;
            }
            if ($start <= 0 || $end <= $start) {
                throw self::failure('card_purchase_fixed_validity_invalid');
            }
            return ['writeValid' => 3, 'writeDays' => 0, 'writeStart' => $start, 'writeEnd' => $end];
        }
        if ($mode === 2) {
            if ($days > intdiv(PHP_INT_MAX - $now, 86400)) {
                throw self::failure('card_purchase_validity_overflow');
            }
            return ['writeValid' => 2, 'writeDays' => $days, 'writeStart' => $now, 'writeEnd' => $now + $days * 86400];
        }
        return ['writeValid' => 1, 'writeDays' => 0, 'writeStart' => $now, 'writeEnd' => 0];
    }

    private function lockActiveMember(int $memberId): array
    {
        $member = Db::name('user')->where('uid', $memberId)->where('status', 1)->where('is_del', 0)->lock(true)->find();
        if (!$member) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::RESOURCE_NOT_FOUND,
                '会员当前不可用，卡项不能完成签发。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'card_purchase_member_not_active']
            );
        }
        return (array)$member;
    }

    private function replay(array $existing, array $header, array $salesLine, int $issueNo, string $commandKey): array
    {
        foreach ([
            'checkout_request_id' => (string)$header['checkout_request_id'],
            'sales_order_id' => (string)$header['order_id'],
            'sales_order_line_id' => (string)$salesLine['order_line_id'],
            'issue_no' => $issueNo,
            'command_idempotency_key' => $commandKey,
            'status' => 'completed',
        ] as $field => $expected) {
            if ((string)($existing[$field] ?? '') !== (string)$expected) {
                throw self::failure('card_purchase_idempotency_conflict', ['field' => $field]);
            }
        }
        $result = json_decode((string)($existing['result_snapshot_json'] ?? ''), true);
        if (!is_array($result) || (int)($result['holderId'] ?? 0) <= 0 || (int)($result['legacyOrderId'] ?? 0) <= 0) {
            throw self::failure('card_purchase_receipt_snapshot_invalid');
        }
        $result['replayed'] = true;
        return $result;
    }

    private function assertReady(): void
    {
        $row = Db::query(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',
            ['eb_' . self::RECEIPT_TABLE]
        );
        if (!$row) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ACTION_DEPENDENCY_NOT_READY,
                '卡项正式签发底座尚未完成本地升级，请联系管理员。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'card_purchase_receipt_table_missing']
            );
        }
    }

    private function emptyResult(): array
    {
        return ['contractVersion' => self::CONTRACT_VERSION, 'issuedCardCount' => 0, 'receipts' => [], 'replayed' => false];
    }

    private static function money(int $cents): string
    {
        if ($cents < 0) {
            throw self::failure('card_purchase_money_negative');
        }
        return bcdiv((string)$cents, '100', 2);
    }

    private function multiply(int $left, int $right): int
    {
        if ($left < 0 || $right <= 0 || ($left > 0 && $right > intdiv(PHP_INT_MAX, $left))) {
            throw self::failure('card_purchase_amount_overflow');
        }
        return $left * $right;
    }

    private function divideExactly(int $amount, int $quantity): int
    {
        if ($amount < 0 || $quantity <= 0 || $amount % $quantity !== 0) {
            throw self::failure('card_purchase_unit_amount_invalid');
        }
        return intdiv($amount, $quantity);
    }

    private function json(array $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw self::failure('card_purchase_json_encode_failed');
        }
        return $json;
    }

    private static function fingerprint(array $value): string
    {
        $canonical = self::canonicalize($value);
        $json = json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw self::failure('card_purchase_fingerprint_encode_failed');
        }
        return hash('sha256', $json);
    }

    private static function canonicalize($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_keys($value) === ($value ? range(0, count($value) - 1) : [])) {
            return array_map([self::class, 'canonicalize'], $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }
        return $value;
    }

    private function duplicate(\Throwable $exception): bool
    {
        return (string)$exception->getCode() === '23000'
            || stripos($exception->getMessage(), 'Duplicate entry') !== false;
    }

    private static function failure(string $reason, array $detail = []): CashierV3CommandException
    {
        $detail['reason'] = $reason;
        return new CashierV3CommandException(
            CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
            '卡项权益签发未完成，本次结账已全部回滚，请重试。',
            CashierV3ResultCode::STATUS_FAILED,
            $detail
        );
    }
}
