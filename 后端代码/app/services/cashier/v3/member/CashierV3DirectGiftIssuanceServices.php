<?php
declare(strict_types=1);

namespace app\services\cashier\v3\member;

use app\services\cashier\v3\CashierV3BusinessDocumentNumberServices;
use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\cashier\CashierV3CashierReadinessGuard;
use app\services\cashier\v3\cashier\CashierV3EntitlementResourceVersionProvider;
use app\services\cashier\v3\event\CashierV3BusinessEventExecution;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use think\facade\Db;

/**
 * Independent gifts are their own authority. They never become a sale,
 * collection or performance record; legacy rows are compatibility benefit
 * projections only and are bound one-to-one to immutable gift items.
 */
final class CashierV3DirectGiftIssuanceServices
{
    public const CONTRACT_VERSION = 'cashier-v3-direct-gift-issuance-v1';
    private const AUTHORITY_TABLE = 'cashier_v3_direct_gift_authority';
    private const ITEM_TABLE = 'cashier_v3_direct_gift_item';
    private const FACT_TABLE = 'cashier_v3_gift_fact';

    /** @var CashierV3RechargeGiftIssuanceServices */
    private $projectionWriter;

    public function __construct(?CashierV3RechargeGiftIssuanceServices $projectionWriter = null)
    {
        $this->projectionWriter = $projectionWriter ?: new CashierV3RechargeGiftIssuanceServices();
    }

    /** @return array{giftId:string,giftNo:string,issuedCount:int,items:array,replayed:bool} */
    public function issueInTx(
        array $payload,
        array $member,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope,
        string $commandIdempotencyKey,
        CashierV3BusinessEventRecorder $eventRecorder,
        CashierV3BusinessEventExecution $eventExecution,
        array $eventContract
    ): array {
        CashierV3TransactionGuard::assertInTransaction('directGift.issueInTx');
        $memberId = (int)($member['uid'] ?? $member['id'] ?? 0);
        if ($memberId <= 0 || $commandIdempotencyKey === '') {
            throw self::failure('direct_gift_input_invalid', '赠送会员或请求标识无效，本次操作已取消。');
        }
        if ($operatorScope->tenantId() !== $dataScope->tenantId() || !$dataScope->allowsStore($operatorScope->storeId())) {
            throw self::failure('direct_gift_scope_denied', '当前门店没有办理赠送的权限。');
        }
        $handlingStoreId = (int)($payload['handlingStoreId'] ?? $payload['handling_store_id'] ?? 0);
        if ($handlingStoreId !== $operatorScope->storeId()) {
            throw self::failure('direct_gift_handling_store_invalid', '赠送必须在当前办理门店提交，请刷新后重试。');
        }
        $now = time();
        $reason = trim((string)($payload['reason'] ?? ''));
        if ($reason === '' || mb_strlen($reason) > 120) {
            throw self::failure('direct_gift_reason_invalid', '请填写不超过 120 个字符的赠送原因。');
        }
        // New clients carry validity per gift item. Keep a top-level fallback
        // for older clients, but never let it override an item's own date.
        $legacyValidityEnd = $this->validityEnd($payload['validityEnd'] ?? $payload['validity_end'] ?? '', $now);
        $items = $this->normalizeItems((array)($payload['items'] ?? []), $operatorScope, $now, $legacyValidityEnd);
        if ($items === []) {
            throw self::failure('direct_gift_items_empty', '请至少选择一项可赠送内容。');
        }
        $validityEnds = array_values(array_unique(array_map(static function (array $item): int {
            return (int)($item['validityEnd'] ?? 0);
        }, $items)));
        // The authority column is retained for compatibility with existing
        // readers. Mixed per-item dates are represented by 0; the item
        // snapshot remains the source of truth for each benefit's expiry.
        $authorityValidityEnd = count($validityEnds) === 1 ? (int)$validityEnds[0] : 0;
        $giftId = 'DGI-' . strtoupper(substr(hash('sha256', implode('|', [$operatorScope->tenantId(), $commandIdempotencyKey])), 0, 40));
        $fingerprint = hash('sha256', $this->json([
            'contractVersion' => self::CONTRACT_VERSION, 'giftId' => $giftId,
            'tenantId' => $operatorScope->tenantId(), 'storeId' => $operatorScope->storeId(),
            'memberId' => $memberId, 'reason' => $reason, 'validityEnd' => $authorityValidityEnd, 'items' => $items,
        ]));
        $existing = Db::name(self::AUTHORITY_TABLE)->where('tenant_id', $operatorScope->tenantId())
            ->where('command_idempotency_key', $commandIdempotencyKey)->lock(true)->find();
        if ($existing) return $this->replay($existing, $giftId, $fingerprint, $items);

        $giftNo = (new CashierV3BusinessDocumentNumberServices())->allocateForSourceInTx(
            $operatorScope->tenantId(), CashierV3BusinessDocumentNumberServices::GIFT,
            'direct_gift', $giftId, date('Y-m-d', $now), $now
        );
        try {
            $authorityId = (int)Db::name(self::AUTHORITY_TABLE)->insertGetId([
                'gift_id' => $giftId, 'gift_no' => $giftNo, 'tenant_id' => $operatorScope->tenantId(),
                'store_id' => $operatorScope->storeId(), 'member_id' => $memberId,
                'reason_snapshot' => $reason, 'validity_end' => $authorityValidityEnd,
                'command_idempotency_key' => $commandIdempotencyKey, 'immutable_fingerprint' => $fingerprint,
                'status' => 'issued', 'occurred_at' => $now, 'settled_at' => $now, 'recorded_at' => $now,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            if ($authorityId <= 0) throw self::failure('direct_gift_authority_insert_failed', '赠送主记录创建失败，本次操作已取消。');
        } catch (\Throwable $exception) {
            if (!$this->duplicate($exception)) throw $exception;
            $raced = Db::name(self::AUTHORITY_TABLE)->where('tenant_id', $operatorScope->tenantId())
                ->where('command_idempotency_key', $commandIdempotencyKey)->lock(true)->find();
            if (!$raced) throw $exception;
            return $this->replay($raced, $giftId, $fingerprint, $items);
        }

        $issued = [];
        foreach ($items as $item) {
            $issued[] = $this->issueItemInTx($giftId, $giftNo, $item, $member, $reason, $now, $operatorScope, $dataScope, $commandIdempotencyKey, $eventRecorder, $eventExecution, $eventContract);
        }
        return ['giftId' => $giftId, 'giftNo' => $giftNo, 'issuedCount' => count($issued), 'items' => $issued, 'replayed' => false];
    }

    private function normalizeItems(array $input, CashierV3OperatorScope $scope, int $now, int $legacyValidityEnd = 0): array
    {
        if (count($input) > 100) throw self::failure('direct_gift_item_count_exceeded', '一次最多赠送 100 项内容。');
        $items = []; $seen = [];
        foreach ($input as $raw) {
            if (!is_array($raw)) throw self::failure('direct_gift_item_invalid', '赠送内容格式无效。');
            $kind = trim((string)($raw['kind'] ?? ''));
            $quantityRaw = trim((string)($raw['quantity'] ?? ''));
            if (!in_array($kind, ['project', 'product', 'coupon'], true)
                || preg_match('/^[1-9][0-9]{0,2}$/D', $quantityRaw) !== 1) {
                throw self::failure('direct_gift_item_invalid', '赠送类型或数量无效。');
            }
            $quantity = (int)$quantityRaw;
            $itemValidityEnd = $this->validityEnd($raw['validityEnd'] ?? $raw['validity_end'] ?? '', $now);
            if ($itemValidityEnd === 0) $itemValidityEnd = $legacyValidityEnd;
            if ($kind === 'coupon') {
                $couponId = (int)($raw['couponIssueId'] ?? $raw['coupon_issue_id'] ?? 0);
                $coupon = (array)Db::name('store_coupon_issue')->where('id', $couponId)->where('status', 1)->where('is_del', 0)
                    ->whereIn('relation_id', [0, $scope->storeId()])->lock(true)->find();
                if (!$coupon || isset($seen['coupon:' . $couponId])) throw self::failure('direct_gift_coupon_unavailable', '所选优惠券已停用、删除或重复。');
                $items[] = ['itemNo' => count($items) + 1, 'kind' => 'coupon', 'productId' => 0, 'productType' => 0,
                    'couponIssueId' => $couponId, 'quantity' => $quantity, 'name' => trim((string)($coupon['title'] ?? '')) ?: '赠送优惠券',
                    'skuUnique' => '', 'skuWriteTimes' => 0, 'validityStart' => $now, 'validityEnd' => $itemValidityEnd,
                    'couponIssueStoreId' => (int)($coupon['relation_id'] ?? 0),
                    'couponApplicableStoreIds' => $this->storeIds($coupon['applicable_store_id'] ?? '')];
                $seen['coupon:' . $couponId] = true;
                continue;
            }
            $skuId = (int)($raw['catalogItemId'] ?? $raw['catalog_item_id'] ?? 0);
            $sku = (array)Db::name('store_product_attr_value')->where('id', $skuId)->where('type', 0)->lock(true)->find();
            $productId = (int)($sku['product_id'] ?? 0);
            $product = $productId > 0 ? (array)Db::name('store_product')->where('id', $productId)
                ->where('relation_id', $scope->storeId())->where('is_del', 0)->lock(true)->find() : [];
            $productType = (int)($product['product_type'] ?? -1);
            if (!$sku || !$product || ($kind === 'project' ? $productType !== 6 : $productType !== 0) || isset($seen[$kind . ':' . $skuId])) {
                throw self::failure('direct_gift_catalog_unavailable', '所选赠送品项已下架、类型变化、不属于当前门店或重复。');
            }
            $items[] = ['itemNo' => count($items) + 1, 'kind' => $kind, 'productId' => $productId, 'productType' => $productType,
                'couponIssueId' => 0, 'quantity' => $quantity, 'name' => trim((string)($product['store_name'] ?? '')) ?: '赠送内容',
                'skuUnique' => (string)($sku['unique'] ?? ''), 'skuWriteTimes' => $kind === 'project' ? max(1, (int)($sku['write_times'] ?? 1)) : 0,
                'validityStart' => $now, 'validityEnd' => $itemValidityEnd];
            $seen[$kind . ':' . $skuId] = true;
        }
        return $items;
    }

    private function issueItemInTx(string $giftId, string $giftNo, array $item, array $member, string $reason, int $now, CashierV3OperatorScope $scope, CashierV3DataScopeContext $dataScope, string $key, CashierV3BusinessEventRecorder $recorder, CashierV3BusinessEventExecution $execution, array $contract): array
    {
        $itemId = $giftId . '-' . str_pad((string)$item['itemNo'], 3, '0', STR_PAD_LEFT);
        $projection = $this->projectionWriter->issueDirectProjectionInTx($itemId, $item, (int)$member['uid'], $member, $now, $scope, $dataScope);
        $row = [
            'gift_id' => $giftId, 'item_no' => (int)$item['itemNo'], 'item_id' => $itemId, 'gift_kind' => $item['kind'],
            'catalog_product_id' => (int)$item['productId'], 'catalog_product_type' => (int)$item['productType'], 'coupon_issue_id' => (int)$item['couponIssueId'],
            'quantity' => (int)$item['quantity'], 'content_name_snapshot' => $item['name'], 'content_snapshot_json' => $this->json($item),
            'legacy_order_id' => (int)$projection['legacyOrderId'], 'card_holder_id' => (int)$projection['holderId'], 'benefit_detail_id' => (int)$projection['benefitDetailId'],
            'coupon_user_ids_json' => $this->json($projection['couponUserIds']), 'status' => 'issued', 'voided_at' => 0, 'void_reason_snapshot' => '',
            'occurred_at' => $now, 'settled_at' => $now, 'recorded_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ];
        try {
            $id = (int)Db::name(self::ITEM_TABLE)->insertGetId($row);
            if ($id <= 0) throw self::failure('direct_gift_item_insert_failed', '赠送明细创建失败，本次操作已取消。');
        } catch (\Throwable $exception) {
            if (!$this->duplicate($exception)) throw $exception;
            throw self::failure('direct_gift_item_duplicate', '赠送明细重复，本次操作已取消。');
        }
        $row['id'] = $id;
        $event = $recorder->recordInTx($execution, $contract, [
            'event_type' => 'gift.issued', 'aggregate_type' => 'direct_gift', 'aggregate_id' => $giftId, 'aggregate_version' => 1,
            'event_version' => 1, 'detail_id' => $itemId, 'source_type' => 'submit-direct-gift', 'source_id' => $giftId,
            'member_id' => (int)$member['uid'], 'business_date' => date('Y-m-d', $now), 'occurred_at' => $now, 'settled_at' => $now, 'recorded_at' => $now,
            'aggregate_name_snapshot' => $item['name'], 'store_name_snapshot' => (string)Db::name('system_store')->where('id', $scope->storeId())->value('name'),
            'payload' => ['contractVersion' => self::CONTRACT_VERSION, 'giftId' => $giftId, 'giftNo' => $giftNo, 'itemId' => $itemId, 'giftKind' => $item['kind'], 'quantity' => $item['quantity'], 'reason' => $reason],
        ]);
        $this->insertFact($row, $giftId, (int)$member['uid'], $scope, $key, $event);
        return ['itemId' => $itemId, 'kind' => $item['kind'], 'name' => $item['name'], 'quantity' => (int)$item['quantity'], 'holderId' => (int)$projection['holderId'], 'benefitDetailId' => (int)$projection['benefitDetailId']];
    }

    private function insertFact(array $item, string $giftId, int $memberId, CashierV3OperatorScope $scope, string $key, array $event): void
    {
        $row = ['gift_fact_id' => 'GF-' . strtoupper(substr(hash('sha256', (string)$item['item_id']), 0, 40)), 'natural_key' => 'direct_gift:' . (string)$item['item_id'], 'fact_version' => 1, 'status' => 'effective', 'reversal_of' => '',
            'source_type' => 'direct_gift', 'source_id' => $giftId, 'source_detail_id' => (string)$item['item_id'], 'business_event_no' => (string)$event['event_no'], 'command_idempotency_key' => $key,
            'tenant_id' => $scope->tenantId(), 'organization_id' => $scope->organizationId(), 'organization_path_snapshot' => (string)$scope->organizationId(), 'store_id' => $scope->storeId(), 'member_id' => $memberId, 'operator_id' => $scope->operatorId(),
            'gift_kind' => (string)$item['gift_kind'], 'catalog_product_id' => (int)$item['catalog_product_id'], 'catalog_product_type' => (int)$item['catalog_product_type'], 'coupon_issue_id' => (int)$item['coupon_issue_id'], 'quantity' => (int)$item['quantity'], 'content_name_snapshot' => (string)$item['content_name_snapshot'], 'content_snapshot_json' => (string)$item['content_snapshot_json'], 'recharge_id' => 0,
            'business_date' => date('Y-m-d', (int)$item['occurred_at']), 'occurred_at' => (int)$item['occurred_at'], 'settled_at' => (int)$item['settled_at'], 'recorded_at' => (int)$item['recorded_at'], 'created_at' => (int)$item['recorded_at'], 'updated_at' => (int)$item['recorded_at']];
        try {
            $id = (int)Db::name(self::FACT_TABLE)->insertGetId($row);
            if ($id <= 0) throw self::failure('direct_gift_fact_insert_failed', '赠送事实写入失败，本次操作已取消。');
        } catch (\Throwable $exception) {
            if (!$this->duplicate($exception)) throw $exception;
            $existing = Db::name(self::FACT_TABLE)->where('tenant_id', $scope->tenantId())
                ->where('natural_key', $row['natural_key'])->lock(true)->find();
            if (!$existing || (string)$existing['gift_fact_id'] !== $row['gift_fact_id']) {
                throw self::failure('direct_gift_fact_replay_conflict', '赠送事实重复且不一致。');
            }
        }
    }

    private function replay(array $authority, string $giftId, string $fingerprint, array $items): array
    {
        if ((string)($authority['gift_id'] ?? '') !== $giftId || (string)($authority['immutable_fingerprint'] ?? '') !== $fingerprint || (string)($authority['status'] ?? '') !== 'issued') {
            throw self::failure('direct_gift_replay_conflict', '赠送记录与原始请求不一致，不能重复发放。');
        }
        $rows = Db::name(self::ITEM_TABLE)->where('gift_id', $giftId)->order('item_no asc')->lock(true)->select()->toArray();
        if (count($rows) !== count($items)) throw self::failure('direct_gift_replay_incomplete', '赠送结果不完整，请保留现场后联系管理员。');
        foreach ($rows as $index => $row) {
            $this->assertItemReplay($row, $items[$index] ?? []);
            $this->assertFactReplay($row, $authority);
        }
        return ['giftId' => $giftId, 'giftNo' => (string)$authority['gift_no'], 'issuedCount' => count($rows), 'items' => array_map(static function (array $row): array { return ['itemId' => (string)$row['item_id'], 'kind' => (string)$row['gift_kind'], 'name' => (string)$row['content_name_snapshot'], 'quantity' => (int)$row['quantity'], 'holderId' => (int)$row['card_holder_id'], 'benefitDetailId' => (int)$row['benefit_detail_id']]; }, $rows), 'replayed' => true];
    }

    private function assertItemReplay(array $row, array $expected): void
    {
        foreach (['gift_kind' => 'kind', 'catalog_product_id' => 'productId', 'catalog_product_type' => 'productType', 'coupon_issue_id' => 'couponIssueId', 'quantity' => 'quantity', 'content_name_snapshot' => 'name'] as $column => $key) {
            if ((string)($row[$column] ?? '') !== (string)($expected[$key] ?? '')) {
                throw self::failure('direct_gift_item_replay_conflict', '赠送内容已变化，不能重复发放。');
            }
        }
        $snapshot = json_decode((string)($row['content_snapshot_json'] ?? ''), true);
        $rowValidityEnd = is_array($snapshot) ? (int)($snapshot['validityEnd'] ?? $snapshot['validity_end'] ?? 0) : 0;
        $expectedValidityEnd = (int)($expected['validityEnd'] ?? $expected['validity_end'] ?? 0);
        if ($rowValidityEnd !== $expectedValidityEnd) {
            throw self::failure('direct_gift_item_replay_conflict', '赠送有效期已变化，不能重复发放。');
        }
        if ((string)($row['status'] ?? '') !== 'issued') {
            throw self::failure('direct_gift_item_not_active', '赠送记录已经失效，不能重复发放。');
        }
    }

    private function assertFactReplay(array $item, array $authority): void
    {
        $fact = Db::name(self::FACT_TABLE)->where('tenant_id', (string)$authority['tenant_id'])
            ->where('natural_key', 'direct_gift:' . (string)$item['item_id'])->lock(true)->find();
        if (!$fact || (string)($fact['status'] ?? '') !== 'effective' || (string)($fact['source_id'] ?? '') !== (string)$authority['gift_id']) {
            throw self::failure('direct_gift_fact_replay_incomplete', '赠送事实不完整，请保留现场后联系管理员。');
        }
    }

    private function validityEnd($raw, int $now): int
    {
        $value = trim((string)$raw); if ($value === '') return 0;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) throw self::failure('direct_gift_validity_invalid', '赠送有效期格式无效。');
        $timestamp = strtotime($value . ' 23:59:59 Asia/Shanghai');
        if ($timestamp === false || $timestamp < $now) throw self::failure('direct_gift_validity_expired', '赠送有效期不能早于当前时间。');
        return $timestamp;
    }
    /** @return int[] */
    private function storeIds($value): array
    {
        $raw = is_array($value) ? $value : explode(',', (string)$value);
        $ids = [];
        foreach ($raw as $storeId) {
            $storeId = (int)$storeId;
            if ($storeId > 0) $ids[$storeId] = $storeId;
        }
        return array_values($ids);
    }
    private function json($value): string { $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); if ($json === false) throw self::failure('direct_gift_json_failed', '赠送快照编码失败。'); return $json; }
    private function duplicate(\Throwable $exception): bool { $message = strtolower($exception->getMessage()); return strpos($message, 'duplicate') !== false || strpos($message, '1062') !== false; }
    private static function failure(string $reason, string $message): CashierV3CommandException { return new CashierV3CommandException(CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE, $message, CashierV3ResultCode::STATUS_FAILED, ['reason' => $reason]); }
}
