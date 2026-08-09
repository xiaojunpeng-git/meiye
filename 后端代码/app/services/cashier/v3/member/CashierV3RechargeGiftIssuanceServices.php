<?php
declare(strict_types=1);

namespace app\services\cashier\v3\member;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3BusinessDocumentNumberServices;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\cashier\CashierV3CashierReadinessGuard;
use app\services\cashier\v3\cashier\CashierV3EntitlementResourceVersionProvider;
use app\services\cashier\v3\event\CashierV3BusinessEventExecution;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use app\services\other\StoreGiftConfigServices;
use app\services\user\CardNumberServices;
use think\facade\Db;

/**
 * Issues gifts selected by a V3 recharge package.
 *
 * The authority tables are the source of truth. Legacy order/card/coupon rows
 * written here are compatibility projections for the existing member benefit,
 * coupon and gift-record readers; they are never sales, payment or performance
 * records. Every projection is uniquely bound to one immutable gift item.
 */
final class CashierV3RechargeGiftIssuanceServices
{
    public const CONTRACT_VERSION = 'cashier-v3-recharge-gift-issuance-v1';
    private const AUTHORITY_TABLE = 'cashier_v3_recharge_gift_authority';
    private const ITEM_TABLE = 'cashier_v3_recharge_gift_item';
    private const FACT_TABLE = 'cashier_v3_gift_fact';
    private const COUPON_MAPPING_TABLE = 'cashier_v3_recharge_gift_coupon_issue_mapping';

    /** @var CardNumberServices */
    private $cardNumbers;

    /** @var CashierV3EntitlementResourceVersionProvider */
    private $versions;

    public function __construct(
        ?CardNumberServices $cardNumbers = null,
        ?CashierV3EntitlementResourceVersionProvider $versions = null
    ) {
        $this->cardNumbers = $cardNumbers ?: new CardNumberServices();
        $this->versions = $versions ?: new CashierV3EntitlementResourceVersionProvider(
            new CashierV3CashierReadinessGuard()
        );
    }

    /**
     * @return array{giftId:string,items:array<int,array>,issuedCount:int,replayed:bool}
     */
    public function issueInTx(
        int $rechargeId,
        string $rechargeOrderNo,
        int $packageId,
        int $memberId,
        array $member,
        string $commandIdempotencyKey,
        int $occurredAt,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope,
        CashierV3BusinessEventRecorder $eventRecorder,
        CashierV3BusinessEventExecution $eventExecution,
        array $eventContract
    ): array {
        CashierV3TransactionGuard::assertInTransaction('rechargeGift.issueInTx');
        if ($rechargeId <= 0 || $packageId <= 0 || $memberId <= 0 || $commandIdempotencyKey === '') {
            throw self::failure('recharge_gift_input_invalid', '充值赠送信息无效，本次操作已取消。');
        }
        if ($operatorScope->tenantId() !== $dataScope->tenantId() || !$dataScope->allowsStore($operatorScope->storeId())) {
            throw self::failure('recharge_gift_scope_denied', '当前门店没有发放充值赠送的权限。');
        }

        $config = app()->make(StoreGiftConfigServices::class)->getConfig(
            StoreGiftConfigServices::GIFT_TYPE_RECHARGE,
            $packageId
        );
        $items = $this->normalizeItems($config, $operatorScope, $occurredAt);
        if ($items === []) {
            return ['giftId' => '', 'items' => [], 'issuedCount' => 0, 'replayed' => false];
        }

        $giftId = 'RGI-' . strtoupper(substr(hash('sha256', implode('|', [
            $operatorScope->tenantId(), (string)$rechargeId, $commandIdempotencyKey,
        ])), 0, 40));
        $fingerprint = hash('sha256', $this->json([
            'contractVersion' => self::CONTRACT_VERSION,
            'giftId' => $giftId,
            'tenantId' => $operatorScope->tenantId(),
            'storeId' => $operatorScope->storeId(),
            'memberId' => $memberId,
            'rechargeId' => $rechargeId,
            'packageId' => $packageId,
            'items' => $items,
        ]));
        $existing = Db::name(self::AUTHORITY_TABLE)
            ->where('tenant_id', $operatorScope->tenantId())
            ->where('recharge_id', $rechargeId)
            ->lock(true)->find();
        if ($existing) {
            return $this->replay($existing, $items, $giftId, $fingerprint, $eventRecorder, $eventExecution, $eventContract);
        }
        $giftNo = (new CashierV3BusinessDocumentNumberServices())->allocateForSourceInTx(
            $operatorScope->tenantId(),
            CashierV3BusinessDocumentNumberServices::GIFT,
            'recharge_gift',
            (string)$rechargeId,
            date('Y-m-d', $occurredAt),
            $occurredAt
        );

        $authority = [
            'gift_id' => $giftId,
            'gift_no' => $giftNo,
            'tenant_id' => $operatorScope->tenantId(),
            'store_id' => $operatorScope->storeId(),
            'member_id' => $memberId,
            'recharge_id' => $rechargeId,
            'recharge_order_no_snapshot' => $rechargeOrderNo,
            'package_id_snapshot' => $packageId,
            'configuration_snapshot_json' => $this->json(['product' => $config['product'] ?? [], 'coupon' => $config['coupon'] ?? []]),
            'command_idempotency_key' => $commandIdempotencyKey,
            'immutable_fingerprint' => $fingerprint,
            'status' => 'issued',
            'occurred_at' => $occurredAt,
            'settled_at' => $occurredAt,
            'recorded_at' => $occurredAt,
            'created_at' => $occurredAt,
            'updated_at' => $occurredAt,
        ];
        try {
            $id = (int)Db::name(self::AUTHORITY_TABLE)->insertGetId($authority);
            if ($id <= 0) throw self::failure('recharge_gift_authority_insert_failed', '充值赠送记录创建失败，本次操作已取消。');
        } catch (\Throwable $exception) {
            if (!$this->duplicate($exception)) throw $exception;
            $raced = Db::name(self::AUTHORITY_TABLE)->where('tenant_id', $operatorScope->tenantId())
                ->where('recharge_id', $rechargeId)->lock(true)->find();
            if (!$raced) throw $exception;
            return $this->replay($raced, $items, $giftId, $fingerprint, $eventRecorder, $eventExecution, $eventContract);
        }

        $issued = [];
        foreach ($items as $item) {
            $issued[] = $this->issueItemInTx(
                $giftId, $item, $memberId, $member, $rechargeId, $rechargeOrderNo, $occurredAt,
                $operatorScope, $dataScope, $eventRecorder, $eventExecution, $eventContract
            );
        }
        return ['giftId' => $giftId, 'items' => $issued, 'issuedCount' => count($issued), 'replayed' => false];
    }

    private function replay(array $authority, array $items, string $expectedGiftId, string $fingerprint, CashierV3BusinessEventRecorder $eventRecorder, CashierV3BusinessEventExecution $eventExecution, array $eventContract): array
    {
        if ((string)($authority['gift_id'] ?? '') !== $expectedGiftId
            || (string)($authority['immutable_fingerprint'] ?? '') !== $fingerprint
            || (string)($authority['status'] ?? '') !== 'issued') {
            throw self::failure('recharge_gift_replay_conflict', '充值赠送记录与原始请求不一致，不能重复发放。');
        }
        $rows = Db::name(self::ITEM_TABLE)->where('gift_id', $expectedGiftId)->order('item_no asc')->lock(true)->select()->toArray();
        if (count($rows) !== count($items)) {
            throw self::failure('recharge_gift_replay_incomplete', '充值赠送结果不完整，请保留现场后联系管理员。');
        }
        $result = [];
        foreach ($rows as $row) {
            $this->assertItemReplay($row, $items[(int)$row['item_no'] - 1] ?? []);
            if ((string)($row['gift_kind'] ?? '') === 'coupon') {
                $this->assertCouponIssueMappingReplay($row);
            }
            $this->assertFactReplay($row, $authority);
            $result[] = $this->itemResult($row, true);
            $this->recordGiftEvent($eventRecorder, $eventExecution, $eventContract, $authority, $row);
        }
        return ['giftId' => $expectedGiftId, 'items' => $result, 'issuedCount' => count($result), 'replayed' => true];
    }

    private function issueItemInTx(string $giftId, array $item, int $memberId, array $member, int $rechargeId, string $rechargeOrderNo, int $now, CashierV3OperatorScope $operatorScope, CashierV3DataScopeContext $dataScope, CashierV3BusinessEventRecorder $eventRecorder, CashierV3BusinessEventExecution $eventExecution, array $eventContract): array
    {
        $itemNo = (int)$item['itemNo'];
        $itemId = $giftId . '-' . str_pad((string)$itemNo, 3, '0', STR_PAD_LEFT);
        $projection = $item['kind'] === 'coupon'
            ? $this->issueCouponInTx($item, $memberId, $now)
            : $this->issueProductInTx($itemId, $item, $memberId, $member, $rechargeId, $rechargeOrderNo, $now, $operatorScope, $dataScope);
        $row = [
            'gift_id' => $giftId,
            'item_no' => $itemNo,
            'item_id' => $itemId,
            'gift_kind' => $item['kind'],
            'catalog_product_id' => (int)$item['productId'],
            'catalog_product_type' => (int)$item['productType'],
            'coupon_issue_id' => (int)$item['couponIssueId'],
            'quantity' => (int)$item['quantity'],
            'content_name_snapshot' => (string)$item['name'],
            'content_snapshot_json' => $this->json($item),
            'legacy_order_id' => (int)$projection['legacyOrderId'],
            'card_holder_id' => (int)$projection['holderId'],
            'benefit_detail_id' => (int)$projection['benefitDetailId'],
            'coupon_user_ids_json' => $this->json($projection['couponUserIds']),
            'status' => 'issued',
            'voided_at' => 0,
            'void_reason_snapshot' => '',
            'occurred_at' => $now,
            'settled_at' => $now,
            'recorded_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        try {
            $id = (int)Db::name(self::ITEM_TABLE)->insertGetId($row);
            if ($id <= 0) throw self::failure('recharge_gift_item_insert_failed', '充值赠送明细创建失败，本次操作已取消。');
        } catch (\Throwable $exception) {
            if (!$this->duplicate($exception)) throw $exception;
            throw self::failure('recharge_gift_item_duplicate', '充值赠送明细重复，本次操作已取消。');
        }
        $row['id'] = $id;
        if ($item['kind'] === 'coupon') {
            $this->insertCouponIssueMappings($giftId, $itemId, $rechargeId, $memberId, $operatorScope, (array)($projection['couponIssueMappings'] ?? []), $now);
        }
        $authority = (array)Db::name(self::AUTHORITY_TABLE)->where('gift_id', $giftId)->lock(true)->find();
        if (!$authority) throw self::failure('recharge_gift_authority_missing', '充值赠送记录缺失，本次操作已取消。');
        $event = $this->recordGiftEvent($eventRecorder, $eventExecution, $eventContract, $authority, $row);
        $this->insertGiftFact($row, $authority, $event, $operatorScope);
        return $this->itemResult($row, false);
    }

    /** @return array<int,array> */
    private function normalizeItems(array $config, CashierV3OperatorScope $operatorScope, int $now): array
    {
        $out = [];
        $seen = [];
        foreach ((array)($config['product'] ?? []) as $entry) {
            if (!is_array($entry)) throw self::failure('recharge_gift_product_config_invalid', '充值套餐赠品配置无效，请先修正套餐配置。');
            $productId = (int)($entry['id'] ?? 0); $quantity = (int)($entry['num'] ?? 0); $declaredType = (int)($entry['product_type'] ?? -1);
            if ($productId <= 0 || $quantity <= 0 || $quantity > 1000 || isset($seen['p:' . $productId])) throw self::failure('recharge_gift_product_config_invalid', '充值套餐赠品配置无效，请先修正套餐配置。');
            $product = (array)Db::name('store_product')->where('id', $productId)->where('relation_id', $operatorScope->storeId())->where('is_del', 0)->lock(true)->find();
            if (!$product || (int)($product['product_type'] ?? -1) !== $declaredType) throw self::failure('recharge_gift_product_unavailable', '充值套餐中的赠品已删除、类型变化或不属于当前门店。');
            $sku = (array)Db::name('store_product_attr_value')->where('product_id', $productId)->where('type', 0)->order('id asc')->lock(true)->find();
            if (!$sku) throw self::failure('recharge_gift_product_sku_missing', '充值套餐赠品缺少有效规格，不能发放。');
            $validity = $this->validity($entry, $now);
            $out[] = [
                'itemNo' => count($out) + 1, 'kind' => $declaredType === 6 ? 'project' : 'product',
                'productId' => $productId, 'productType' => $declaredType, 'couponIssueId' => 0,
                'quantity' => $quantity, 'name' => trim((string)($product['store_name'] ?? '')) ?: '赠送内容',
                'skuUnique' => (string)($sku['unique'] ?? ''), 'skuWriteTimes' => max(1, (int)($sku['write_times'] ?? 1)),
                'validityStart' => $validity['start'], 'validityEnd' => $validity['end'],
            ];
            $seen['p:' . $productId] = true;
        }
        foreach ((array)($config['coupon'] ?? []) as $entry) {
            if (!is_array($entry)) throw self::failure('recharge_gift_coupon_config_invalid', '充值套餐赠券配置无效，请先修正套餐配置。');
            $couponId = (int)($entry['id'] ?? 0); $quantity = (int)($entry['num'] ?? 0);
            if ($couponId <= 0 || $quantity <= 0 || $quantity > 1000 || isset($seen['c:' . $couponId])) throw self::failure('recharge_gift_coupon_config_invalid', '充值套餐赠券配置无效，请先修正套餐配置。');
            $coupon = (array)Db::name('store_coupon_issue')->where('id', $couponId)->where('status', 1)->where('is_del', 0)->lock(true)->find();
            if (!$coupon) throw self::failure('recharge_gift_coupon_unavailable', '充值套餐中的优惠券已停用或删除。');
            $validity = $this->validity($entry, $now);
            $out[] = [
                'itemNo' => count($out) + 1, 'kind' => 'coupon', 'productId' => 0, 'productType' => 0,
                'couponIssueId' => $couponId, 'quantity' => $quantity,
                'name' => trim((string)($coupon['title'] ?? '')) ?: '赠送优惠券', 'skuUnique' => '', 'skuWriteTimes' => 0,
                'validityStart' => $validity['start'], 'validityEnd' => $validity['end'],
            ];
            $seen['c:' . $couponId] = true;
        }
        if (count($out) > 100) throw self::failure('recharge_gift_item_count_exceeded', '充值套餐赠送内容过多，不能发放。');
        return $out;
    }

    /** @return array{legacyOrderId:int,holderId:int,benefitDetailId:int,couponUserIds:array} */
    private function issueProductInTx(string $itemId, array $item, int $memberId, array $member, int $rechargeId, string $rechargeOrderNo, int $now, CashierV3OperatorScope $operatorScope, CashierV3DataScopeContext $dataScope, string $projectionSource = 'cashier_v3_recharge_gift', string $projectionTitle = 'V3充值套餐赠送'): array
    {
        $legacyOrderId = $this->insertGiftProjectionOrder($itemId, $memberId, $member, $item, $rechargeId, $rechargeOrderNo, $now, $operatorScope, $projectionSource, $projectionTitle);
        $cartId = 'v3g' . substr(hash('sha256', $itemId), 0, 28);
        $isProject = $item['kind'] === 'project';
        $times = $isProject ? (int)$item['quantity'] * (int)$item['skuWriteTimes'] : 0;
        $cartInfo = [
            'sourceType' => $projectionSource, 'giftItemId' => $itemId,
            'product_id' => (int)$item['productId'], 'product_type' => (int)$item['productType'],
            'product_attr_unique' => (string)$item['skuUnique'], 'write_times' => $times,
            'pay_price' => '0.00', 'productInfo' => [
                'id' => (int)$item['productId'], 'store_name' => (string)$item['name'],
                'product_type' => (int)$item['productType'], 'attrInfo' => ['unique' => (string)$item['skuUnique']],
            ],
        ];
        $cartRow = $this->giftCartRow($legacyOrderId, $memberId, $cartId, $item, $cartInfo, $times, $now, $operatorScope->storeId(), $isProject, $projectionSource);
        $benefitDetailId = (int)Db::name('store_order_cart_info')->insertGetId($cartRow);
        if ($benefitDetailId <= 0) throw self::failure('recharge_gift_projection_cart_insert_failed', '赠品权益投影写入失败，本次操作已取消。');
        Db::name('store_order')->where('id', $legacyOrderId)->update(['cart_id' => $this->json([(string)$benefitDetailId])]);
        $holderId = 0;
        if ($isProject) {
            $holderData = [
                'uid' => $memberId, 'oid' => $legacyOrderId, 'card_name' => (string)$item['name'],
                'store_id' => $operatorScope->storeId(), 'product_id' => (int)$item['productId'], 'product_type' => 5,
                'verify_code' => strtoupper(substr(hash('sha256', 'gift-verify:' . $itemId), 0, 12)),
                'write_valid' => (int)$item['validityEnd'] > 0 ? 1 : 0,
                'write_days' => 0, 'write_start' => (int)$item['validityStart'], 'write_end' => (int)$item['validityEnd'],
                'write_times' => $times, 'write_surplus_times' => $times, 'is_del' => 0, 'add_time' => $now,
            ];
            $holderId = $this->cardNumbers->withAllocateRetry(function (string $cardNo) use ($holderData): int {
                $holderData['card_no'] = $cardNo;
                return (int)Db::name('user_card_holder')->insertGetId($holderData);
            }, null, 'cashier_v3_recharge_gift');
            if ($holderId <= 0) throw self::failure('recharge_gift_holder_insert_failed', '赠送项目权益创建失败，本次操作已取消。');
            $this->versions->synchronizeProjectionVersion('card_holder', (string)$holderId, $operatorScope, $dataScope);
            $this->versions->synchronizeProjectionVersion('member_benefit_pool', (string)$benefitDetailId, $operatorScope, $dataScope);
        }
        return ['legacyOrderId' => $legacyOrderId, 'holderId' => $holderId, 'benefitDetailId' => $benefitDetailId, 'couponUserIds' => []];
    }

    /**
     * Shared compatibility projection for an independently issued gift.
     * The caller owns its authority row, event and fact; this method only
     * materializes member-visible project/product/coupon benefits.
     *
     * @return array{legacyOrderId:int,holderId:int,benefitDetailId:int,couponUserIds:array}
     */
    public function issueDirectProjectionInTx(
        string $itemId,
        array $item,
        int $memberId,
        array $member,
        int $now,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        CashierV3TransactionGuard::assertInTransaction('directGift.issueProjectionInTx');
        if (($item['kind'] ?? '') === 'coupon') {
            return $this->issueCouponInTx($item, $memberId, $now, 'cashier_v3_direct_gift');
        }
        return $this->issueProductInTx(
            $itemId,
            $item,
            $memberId,
            $member,
            0,
            'DIRECT',
            $now,
            $operatorScope,
            $dataScope,
            'cashier_v3_direct_gift',
            'V3独立赠送'
        );
    }

    private function issueCouponInTx(array $item, int $memberId, int $now, string $projectionSource = 'cashier_v3_recharge_gift'): array
    {
        $coupon = (array)Db::name('store_coupon_issue')->where('id', (int)$item['couponIssueId'])->where('status', 1)->where('is_del', 0)->lock(true)->find();
        if (!$coupon) throw self::failure('recharge_gift_coupon_unavailable', '充值套餐中的优惠券已停用或删除。');
        $ids = [];
        $issueMappings = [];
        for ($i = 0; $i < (int)$item['quantity']; $i++) {
            // total_count=0 is the existing unlimited-issue convention;
            // is_permanent controls validity, not inventory capacity.
            if ((int)($coupon['total_count'] ?? 0) > 0) {
                $updated = Db::name('store_coupon_issue')->where('id', (int)$coupon['id'])->where('remain_count', '>', 0)->dec('remain_count', 1)->update();
                if ((int)$updated !== 1) throw self::failure('recharge_gift_coupon_stock_exhausted', '充值套餐赠送优惠券库存不足，本次操作已取消。');
            }
            $couponUserId = (int)Db::name('store_coupon_user')->insertGetId([
                'cid' => (int)$coupon['id'], 'uid' => $memberId, 'coupon_title' => (string)$coupon['title'],
                'coupon_price' => (string)$coupon['coupon_price'], 'use_min_price' => (string)$coupon['use_min_price'],
                'add_time' => $now, 'start_time' => (int)$item['validityStart'], 'end_time' => (int)$item['validityEnd'],
                'use_time' => 0, 'type' => $projectionSource, 'status' => 0, 'is_fail' => 0, 'oid' => 0,
            ]);
            if ($couponUserId <= 0) throw self::failure('recharge_gift_coupon_insert_failed', '赠送优惠券写入失败，本次操作已取消。');
            $couponIssueUserId = (int)Db::name('store_coupon_issue_user')->insertGetId([
                'uid' => $memberId, 'issue_coupon_id' => (int)$coupon['id'], 'add_time' => $now,
            ]);
            if ($couponIssueUserId <= 0) throw self::failure('recharge_gift_coupon_issue_log_insert_failed', '赠送优惠券记录写入失败，本次操作已取消。');
            $ids[] = $couponUserId;
            $issueMappings[] = ['couponUserId' => $couponUserId, 'couponIssueUserId' => $couponIssueUserId, 'couponIssueId' => (int)$coupon['id']];
        }
        return ['legacyOrderId' => 0, 'holderId' => 0, 'benefitDetailId' => 0, 'couponUserIds' => $ids, 'couponIssueMappings' => $issueMappings];
    }

    private function insertCouponIssueMappings(string $giftId, string $itemId, int $rechargeId, int $memberId, CashierV3OperatorScope $operator, array $mappings, int $now): void
    {
        if ($mappings === []) throw self::failure('recharge_gift_coupon_issue_mapping_missing', '赠送优惠券领取映射缺失，本次操作已取消。');
        $rows = [];
        foreach ($mappings as $sequence => $mapping) {
            $couponUserId = (int)($mapping['couponUserId'] ?? 0);
            $couponIssueUserId = (int)($mapping['couponIssueUserId'] ?? 0);
            $couponIssueId = (int)($mapping['couponIssueId'] ?? 0);
            if ($couponUserId <= 0 || $couponIssueUserId <= 0 || $couponIssueId <= 0) {
                throw self::failure('recharge_gift_coupon_issue_mapping_invalid', '赠送优惠券领取映射无效，本次操作已取消。');
            }
            $rows[] = [
                'mapping_id' => 'RGCM-' . strtoupper(substr(hash('sha256', $itemId . '|' . $couponUserId . '|' . $couponIssueUserId), 0, 40)),
                'tenant_id' => $operator->tenantId(), 'store_id' => $operator->storeId(), 'member_id' => $memberId,
                'recharge_id' => $rechargeId, 'gift_id' => $giftId, 'gift_item_id' => $itemId, 'item_sequence' => $sequence + 1,
                'coupon_issue_id' => $couponIssueId, 'coupon_user_id' => $couponUserId, 'coupon_issue_user_id' => $couponIssueUserId,
                'status' => 'issued', 'void_operation_id' => '', 'voided_at' => 0, 'created_at' => $now, 'updated_at' => $now,
            ];
        }
        if ((int)Db::name(self::COUPON_MAPPING_TABLE)->insertAll($rows) !== count($rows)) {
            throw self::failure('recharge_gift_coupon_issue_mapping_insert_failed', '赠送优惠券领取映射写入失败，本次操作已取消。');
        }
    }

    private function assertCouponIssueMappingReplay(array $item): void
    {
        $couponIds = json_decode((string)($item['coupon_user_ids_json'] ?? ''), true);
        $couponIds = is_array($couponIds) ? array_values(array_unique(array_map('intval', $couponIds))) : [];
        $mappings = Db::name(self::COUPON_MAPPING_TABLE)->where('gift_item_id', (string)$item['item_id'])
            ->where('status', 'issued')->lock(true)->order('item_sequence', 'asc')->select()->toArray();
        $mappedIds = array_values(array_map('intval', array_column($mappings, 'coupon_user_id')));
        sort($couponIds, SORT_NUMERIC);
        sort($mappedIds, SORT_NUMERIC);
        if ($couponIds === [] || $couponIds !== $mappedIds || count($mappings) !== (int)$item['quantity']) {
            throw self::failure('recharge_gift_coupon_issue_mapping_replay_incomplete', '充值赠券领取映射不完整，不能重复发放。');
        }
    }

    private function insertGiftProjectionOrder(string $itemId, int $memberId, array $member, array $item, int $rechargeId, string $rechargeOrderNo, int $now, CashierV3OperatorScope $scope, string $projectionSource = 'cashier_v3_recharge_gift', string $projectionTitle = 'V3充值套餐赠送'): int
    {
        $orderNo = 'V3G' . strtoupper(substr(hash('sha256', $itemId), 0, 24));
        $name = trim((string)($member['real_name'] ?? '')) ?: trim((string)($member['nickname'] ?? ''));
        $id = (int)Db::name('store_order')->insertGetId([
            'type' => 11, 'pid' => 0, 'order_id' => $orderNo, 'supplier_id' => 0, 'store_id' => $scope->storeId(),
            'trade_no' => '', 'uid' => $memberId, 'real_name' => $name ?: ('会员' . $memberId), 'user_phone' => (string)($member['phone'] ?? ''),
            'user_address' => '', 'user_location' => '', 'cart_id' => '[]', 'activity_id' => 0, 'activity_append' => '',
            'freight_price' => '0.00', 'total_num' => (int)$item['quantity'], 'total_price' => '0.00', 'settle_price' => '0.00',
            'total_postage' => '0.00', 'pay_price' => '0.00', 'cash_pay_price' => '0.00', 'debt_amount' => '0.00', 'repaid_debt_amount' => '0.00',
            'is_debt_repay' => 0, 'debt_repay_origin_order_id' => 0, 'debt_repay_item_id' => 0, 'yue_pay_price' => '0.00',
            'paid_ben_amount' => '0.00', 'paid_give_amount' => '0.00', 'paid_balance_ready' => 0, 'reopen_source_order_id' => 0,
            'pay_postage' => '0.00', 'pay_integral' => 0, 'deduction_price' => '0.00', 'coupon_id' => 0, 'coupon_price' => '0.00',
            'promotions_price' => '0.00', 'first_order_price' => '0.00', 'change_price' => '0.00', 'service_price' => '0.00',
            'paid' => 1, 'inventory_handled' => 0, 'sales_handled' => 1, 'pay_type' => 'cashier_v3_gift', 'status' => 0,
            'refund_status' => 0, 'card_upgrade_use_oid' => 0, 'service_object' => '本人', 'refund_type' => 0, 'terminal_action' => 0,
            'terminal_operation_id' => 0, 'terminal_action_time' => 0, 'refund_express' => '', 'refund_reason_wap_explain' => '',
            'refund_reason_time' => 0, 'refund_reason_wap' => '', 'refund_reason' => '', 'refund_price' => '0.00',
            'delivery_name' => '', 'delivery_code' => '', 'delivery_type' => '', 'delivery_id' => '', 'fictitious_content' => '', 'delivery_uid' => 0,
            'gain_integral' => '0.00', 'use_integral' => '0.00', 'back_integral' => '0.00', 'spread_uid' => 0, 'spread_two_uid' => 0,
            'one_brokerage' => '0.00', 'two_brokerage' => '0.00', 'mark' => $projectionTitle, 'is_del' => 0, 'is_user_del' => 0,
            'unique' => md5($projectionSource . ':' . $itemId), 'remark' => $projectionTitle . ' / ' . $rechargeOrderNo . ' / ' . $rechargeId,
            'mer_id' => 0, 'is_mer_check' => 0, 'pink_id' => 0, 'cost' => '0.00', 'verify_code' => strtoupper(substr(hash('sha256', 'gift:' . $itemId), 0, 12)),
            'staff_id' => $scope->operatorId(), 'shipping_type' => 2, 'store_delivery_type' => 0, 'clerk_id' => 0, 'is_channel' => 5,
            'is_remind' => 0, 'is_system_del' => 0, 'channel_type' => $projectionSource, 'province' => '', 'kuaidi_label' => '',
            'product_type' => (int)$item['productType'], 'custom_form_title' => '', 'custom_form' => '[[]]', 'system_form_type' => 1,
            'give_integral' => 0, 'give_coupon' => '', 'erp_id' => 0, 'erp_order_id' => '', 'kuaidi_task_id' => '', 'kuaidi_order_id' => 0,
            'is_stock_up' => 0, 'reservation_type' => 2, 'reservation_time' => 0, 'reservation_time_id' => 0, 'reservation_show_time' => '',
            'service_staff_id' => 0, 'reservation_status' => -1, 'shipping_time' => 0, 'pay_time' => $now, 'delivery_time' => 0,
            'estimate_time' => '', 'add_time' => $now, 'selected_product' => (string)$item['productId'], 'cash_choose' => 0,
            'remark_info' => '[]', 'source' => 0, 'yeji' => '[]', 'service_yeji' => '[]', 'is_gendan' => 0, 'gendan_staff_id' => 0,
            'send_all' => $this->json(['recharge_id' => $rechargeId, 'gift_item_id' => $itemId, 'source_type' => $projectionSource]),
        ]);
        if ($id <= 0) throw self::failure('recharge_gift_projection_order_insert_failed', '赠品投影订单写入失败，本次操作已取消。');
        return $id;
    }

    private function giftCartRow(int $orderId, int $memberId, string $cartId, array $item, array $cartInfo, int $times, int $now, int $storeId, bool $isProject, string $projectionSource = 'cashier_v3_recharge_gift'): array
    {
        return [
            'uid' => $memberId, 'oid' => $orderId, 'cart_id' => $cartId, 'cart_type' => $isProject ? 2 : 1, 'type' => 1,
            'relation_id' => $storeId, 'staff_id' => 0, 'delivery_id' => 0, 'product_id' => (int)$item['productId'], 'product_type' => (int)$item['productType'],
            'sku_unique' => (string)$item['skuUnique'], 'promotions_id' => '', 'is_gift' => 1, 'is_card' => $isProject ? 1 : 0,
            'is_support_refund' => 0, 'old_cart_id' => '', 'cart_num' => $isProject ? $times : (int)$item['quantity'],
            'total_price' => '0.00', 'settle_price' => '0.00', 'pay_price' => '0.00', 'yue_pay_amount' => '0.00',
            'card_upgrade_amount' => '0.00', 'cash_pay_amount' => '0.00', 'debt_amount' => '0.00', 'repaid_debt_amount' => '0.00',
            'pay_postage' => '0.00', 'member_price' => '0.00', 'deduction_price' => '0.00', 'coupon_price' => '0.00', 'promotions_price' => '0.00',
            'first_order_price' => '0.00', 'change_price' => '0.00', 'service_price' => '0.00', 'refund_num' => 0,
            'surplus_num' => $times, 'split_surplus_num' => $times, 'split_status' => 0, 'write_times' => $times, 'write_surplus_times' => $times,
            'write_start' => (int)$item['validityStart'], 'write_end' => (int)$item['validityEnd'], 'is_advent_sms' => 0, 'is_expire_sms' => 0,
            'is_writeoff' => 0, 'source_type' => $projectionSource, 'replacement_id' => 0, 'writeoff_time' => 0, 'reservation_type' => 2,
            'reservation_time' => 0, 'reservation_time_id' => 0, 'cart_info' => $this->json($cartInfo),
            'unique' => md5($projectionSource . '-cart:' . $cartId), 'add_time' => $now,
        ];
    }

    private function recordGiftEvent(CashierV3BusinessEventRecorder $recorder, CashierV3BusinessEventExecution $execution, array $contract, array $authority, array $item): array
    {
        return $recorder->recordInTx($execution, $contract, [
            'event_type' => 'gift.issued', 'aggregate_type' => 'recharge_gift', 'aggregate_id' => (string)$authority['gift_id'],
            'aggregate_version' => 1, 'event_version' => 1, 'detail_id' => (string)$item['item_id'], 'source_type' => 'submit-recharge',
            'source_id' => 'RCH:' . (int)$authority['recharge_id'], 'member_id' => (int)$authority['member_id'],
            'business_date' => date('Y-m-d', (int)$item['occurred_at']), 'occurred_at' => (int)$item['occurred_at'],
            'settled_at' => (int)$item['settled_at'], 'recorded_at' => (int)$item['recorded_at'],
            'aggregate_name_snapshot' => (string)$item['content_name_snapshot'],
            'store_name_snapshot' => (string)Db::name('system_store')->where('id', (int)$authority['store_id'])->value('name'),
            'payload' => ['contractVersion' => self::CONTRACT_VERSION, 'giftId' => (string)$authority['gift_id'], 'itemId' => (string)$item['item_id'], 'rechargeId' => (int)$authority['recharge_id'], 'giftKind' => (string)$item['gift_kind'], 'quantity' => (int)$item['quantity']],
        ]);
    }

    private function insertGiftFact(array $item, array $authority, array $event, CashierV3OperatorScope $scope): void
    {
        $row = [
            'gift_fact_id' => 'GF-' . strtoupper(substr(hash('sha256', (string)$item['item_id']), 0, 40)),
            'natural_key' => 'recharge_gift:' . (string)$item['item_id'], 'fact_version' => 1, 'status' => 'effective', 'reversal_of' => '',
            'source_type' => 'recharge_gift', 'source_id' => (string)$authority['gift_id'], 'source_detail_id' => (string)$item['item_id'],
            'business_event_no' => (string)$event['event_no'], 'command_idempotency_key' => (string)$authority['command_idempotency_key'],
            'tenant_id' => $scope->tenantId(), 'organization_id' => $scope->organizationId(), 'organization_path_snapshot' => (string)$scope->organizationId(),
            'store_id' => (int)$authority['store_id'], 'member_id' => (int)$authority['member_id'], 'operator_id' => $scope->operatorId(),
            'gift_kind' => (string)$item['gift_kind'], 'catalog_product_id' => (int)$item['catalog_product_id'], 'catalog_product_type' => (int)$item['catalog_product_type'],
            'coupon_issue_id' => (int)$item['coupon_issue_id'], 'quantity' => (int)$item['quantity'], 'content_name_snapshot' => (string)$item['content_name_snapshot'],
            'content_snapshot_json' => (string)$item['content_snapshot_json'], 'recharge_id' => (int)$authority['recharge_id'],
            'business_date' => date('Y-m-d', (int)$item['occurred_at']), 'occurred_at' => (int)$item['occurred_at'], 'settled_at' => (int)$item['settled_at'], 'recorded_at' => (int)$item['recorded_at'],
            'created_at' => (int)$item['recorded_at'], 'updated_at' => (int)$item['recorded_at'],
        ];
        try {
            $id = (int)Db::name(self::FACT_TABLE)->insertGetId($row);
            if ($id <= 0) throw self::failure('recharge_gift_fact_insert_failed', '充值赠送事实写入失败，本次操作已取消。');
        } catch (\Throwable $exception) {
            if (!$this->duplicate($exception)) throw $exception;
            $existing = Db::name(self::FACT_TABLE)->where('tenant_id', $scope->tenantId())->where('natural_key', $row['natural_key'])->lock(true)->find();
            if (!$existing || (string)$existing['gift_fact_id'] !== $row['gift_fact_id']) throw self::failure('recharge_gift_fact_replay_conflict', '充值赠送事实重复且不一致。');
        }
    }

    private function assertFactReplay(array $item, array $authority): void
    {
        $fact = Db::name(self::FACT_TABLE)->where('tenant_id', (string)$authority['tenant_id'])->where('natural_key', 'recharge_gift:' . (string)$item['item_id'])->lock(true)->find();
        if (!$fact || (string)$fact['status'] !== 'effective' || (string)$fact['source_id'] !== (string)$authority['gift_id']) throw self::failure('recharge_gift_fact_replay_incomplete', '充值赠送事实不完整，请保留现场后联系管理员。');
    }

    private function assertItemReplay(array $row, array $expected): void
    {
        foreach (['gift_kind' => 'kind', 'catalog_product_id' => 'productId', 'catalog_product_type' => 'productType', 'coupon_issue_id' => 'couponIssueId', 'quantity' => 'quantity', 'content_name_snapshot' => 'name'] as $column => $key) {
            if ((string)($row[$column] ?? '') !== (string)($expected[$key] ?? '')) throw self::failure('recharge_gift_item_replay_conflict', '充值赠送内容已变化，不能重复发放。');
        }
        if ((string)($row['status'] ?? '') !== 'issued') throw self::failure('recharge_gift_item_not_active', '充值赠送已经失效，不能重复发放。');
    }

    private function itemResult(array $row, bool $replayed): array
    {
        return ['itemId' => (string)$row['item_id'], 'kind' => (string)$row['gift_kind'], 'name' => (string)$row['content_name_snapshot'], 'quantity' => (int)$row['quantity'], 'holderId' => (int)$row['card_holder_id'], 'benefitDetailId' => (int)$row['benefit_detail_id'], 'couponUserIds' => $this->decode((string)$row['coupon_user_ids_json']), 'replayed' => $replayed];
    }

    private function validity(array $entry, int $now): array
    {
        $start = max(0, (int)($entry['begin_time'] ?? 0)); $end = max(0, (int)($entry['end_time'] ?? 0)); $days = max(0, (int)($entry['write_days'] ?? 0));
        if ($start === 0) $start = $now;
        if ($end === 0 && $days > 0) $end = $start + $days * 86400;
        if ($end > 0 && $start > $end) throw self::failure('recharge_gift_validity_invalid', '充值套餐赠送有效期无效，不能发放。');
        if ($end > 0 && $now > $end) throw self::failure('recharge_gift_validity_expired', '充值套餐赠送已经过期，不能发放。');
        return ['start' => $start, 'end' => $end];
    }

    private function json($value): string { $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); if ($json === false) throw self::failure('recharge_gift_json_encode_failed', '充值赠送快照编码失败。'); return $json; }
    private function decode(string $json): array { $value = json_decode($json, true); return is_array($value) ? $value : []; }
    private function duplicate(\Throwable $exception): bool { $message = strtolower($exception->getMessage()); return strpos($message, 'duplicate') !== false || strpos($message, '1062') !== false; }
    private static function failure(string $reason, string $message): CashierV3CommandException { return new CashierV3CommandException(CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE, $message, CashierV3ResultCode::STATUS_FAILED, ['reason' => $reason]); }
}
