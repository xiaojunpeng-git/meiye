<?php
declare(strict_types=1);

namespace app\services\cashier\v3\order;

use app\model\order\StoreOrderTerminalOperation;
use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\cashier\CashierV3EntitlementResourceVersionProvider;
use app\services\cashier\v3\event\CashierV3BusinessEventExecution;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use app\services\cashier\v3\service\ThinkPhpCashierV3ServiceOrderRepository;
use think\facade\Db;

/**
 * Proves and revokes unused gifts issued by a new V3 recharge.
 *
 * Refunds deliberately leave gifts untouched. A void is all-or-nothing: every
 * gift projection is locked and proven unused before any lifecycle write.
 */
final class CashierV3RechargeGiftReversalServices
{
    private const AUTHORITY_TABLE = 'cashier_v3_recharge_gift_authority';
    private const ITEM_TABLE = 'cashier_v3_recharge_gift_item';
    private const FACT_TABLE = 'cashier_v3_gift_fact';
    private const AUDIT_TABLE = 'cashier_v3_recharge_gift_reversal';
    private const COUPON_MAPPING_TABLE = 'cashier_v3_recharge_gift_coupon_issue_mapping';

    /** @return array<string,mixed> */
    public function prepare(array $source, string $action, CashierV3DataScopeContext $scope): array
    {
        CashierV3TransactionGuard::assertInTransaction('rechargeGiftReversal.prepare');
        if (!in_array($action, ['refund-recharge-order', 'void-recharge-order'], true)) {
            throw self::failure('recharge_gift_reversal_action_invalid');
        }
        if ($action === 'refund-recharge-order') {
            return ['authority' => [], 'items' => [], 'revokedItemCount' => 0];
        }

        $authority = (array)Db::name(self::AUTHORITY_TABLE)
            ->where('tenant_id', $scope->tenantId())
            ->where('recharge_id', (int)$source['rechargeId'])
            ->lock(true)->find();
        if (!$authority) {
            return ['authority' => [], 'items' => [], 'revokedItemCount' => 0];
        }
        if ((string)($authority['status'] ?? '') !== 'issued'
            || (int)($authority['store_id'] ?? 0) !== (int)$source['storeId']
            || (int)($authority['member_id'] ?? 0) !== (int)$source['memberId']
            || (string)($authority['recharge_order_no_snapshot'] ?? '') !== (string)$source['orderNo']) {
            throw self::failure('recharge_gift_reversal_authority_mismatch');
        }

        $rows = Db::name(self::ITEM_TABLE)->where('gift_id', (string)$authority['gift_id'])
            ->lock(true)->order('item_no', 'asc')->select()->toArray();
        if ($rows === []) throw self::failure('recharge_gift_reversal_items_missing');
        $prepared = [];
        foreach ($rows as $item) {
            if ((string)($item['status'] ?? '') !== 'issued') {
                throw self::failure('recharge_gift_reversal_item_not_active');
            }
            $fact = (array)Db::name(self::FACT_TABLE)->where('tenant_id', $scope->tenantId())
                ->where('source_type', 'recharge_gift')->where('source_id', (string)$authority['gift_id'])
                ->where('source_detail_id', (string)$item['item_id'])->where('status', 'effective')
                ->lock(true)->find();
            if (!$fact || (int)($fact['recharge_id'] ?? 0) !== (int)$source['rechargeId']
                || (string)($fact['reversal_of'] ?? '') !== '') {
                throw self::failure('recharge_gift_reversal_fact_mismatch');
            }
            $kind = (string)($item['gift_kind'] ?? '');
            if ($kind === 'project') {
                $projection = $this->lockUnusedProject($item, $source, $scope);
            } elseif ($kind === 'coupon') {
                $projection = $this->lockUnusedCoupons($item, $source, $scope);
            } else {
                // Physical delivery/consumption has no authoritative usage state.
                throw self::failure('recharge_gift_reversal_unprovable_kind');
            }
            $prepared[] = ['item' => $item, 'fact' => $fact, 'projection' => $projection];
        }
        return ['authority' => $authority, 'items' => $prepared, 'revokedItemCount' => count($prepared)];
    }

    /** @return array<string,mixed> */
    private function lockUnusedProject(array $item, array $source, CashierV3DataScopeContext $scope): array
    {
        $holderId = (int)($item['card_holder_id'] ?? 0);
        $detailId = (int)($item['benefit_detail_id'] ?? 0);
        $orderId = (int)($item['legacy_order_id'] ?? 0);
        if ($holderId <= 0 || $detailId <= 0 || $orderId <= 0) throw self::failure('recharge_gift_project_projection_missing');
        $occupation = new ThinkPhpCashierV3ServiceOrderRepository();
        $guard = $occupation->lockOrCreateEntitlementGuard($scope->tenantId(), $detailId);
        $detail = (array)Db::name('store_order_cart_info')->where('id', $detailId)->where('oid', $orderId)->lock(true)->find();
        $order = (array)Db::name('store_order')->where('id', $orderId)->lock(true)->find();
        $holder = (array)Db::name('user_card_holder')->where('id', $holderId)->lock(true)->find();
        $detailSnapshot = json_decode((string)($detail['cart_info'] ?? ''), true);
        if (!$holder || !$order || !$detail
            || (int)($holder['uid'] ?? 0) !== (int)$source['memberId']
            || (int)($holder['oid'] ?? 0) !== $orderId
            || (int)($holder['store_id'] ?? 0) !== (int)$source['storeId']
            || (int)($holder['product_id'] ?? 0) !== (int)$item['catalog_product_id']
            || (int)($holder['is_del'] ?? -1) !== 0
            || (int)($holder['write_surplus_times'] ?? -1) !== (int)($holder['write_times'] ?? -2)
            || (int)($order['uid'] ?? 0) !== (int)$source['memberId']
            || (int)($order['store_id'] ?? 0) !== (int)$source['storeId']
            || (string)($order['channel_type'] ?? '') !== 'cashier_v3_recharge_gift'
            || (int)($order['paid'] ?? 0) !== 1
            || (int)($order['refund_status'] ?? -1) !== 0
            || (int)($order['terminal_action'] ?? -1) !== 0
            || (int)($detail['uid'] ?? 0) !== (int)$source['memberId']
            || (int)($detail['relation_id'] ?? 0) !== (int)$source['storeId']
            || (int)($detail['product_id'] ?? 0) !== (int)$item['catalog_product_id']
            || (string)($detail['source_type'] ?? '') !== 'cashier_v3_recharge_gift'
            || !is_array($detailSnapshot)
            || (string)($detailSnapshot['giftItemId'] ?? '') !== (string)$item['item_id']
            || (int)($detail['write_times'] ?? -1) !== (int)($holder['write_times'] ?? -2)
            || (int)($detail['write_surplus_times'] ?? -1) !== (int)($detail['write_times'] ?? -2)
            || (int)($detail['surplus_num'] ?? -1) !== (int)($detail['cart_num'] ?? -2)
            || (int)($detail['split_surplus_num'] ?? -1) !== (int)($detail['cart_num'] ?? -2)
            || (int)($detail['split_status'] ?? -1) !== 0
            || (int)($detail['is_writeoff'] ?? -1) !== 0
            || (int)($detail['writeoff_time'] ?? -1) !== 0
            || (int)($detail['refund_num'] ?? -1) !== 0
            || (int)($detail['replacement_id'] ?? -1) !== 0) {
            throw self::failure('recharge_gift_project_already_changed');
        }
        if (Db::name('cashier_v3_entitlement_writeoff_fact')->where('tenant_id', $scope->tenantId())
                ->where('source_detail_id', (string)$detailId)->count() > 0
            || Db::name('cashier_v3_service_order_line')->where('tenant_id', $scope->tenantId())
                ->where('entitlement_source_detail_id', (string)$detailId)->count() > 0
            || Db::name('store_reservation_order')->where('cart_info_id', $detailId)
                ->where('is_del', 0)->where('is_system_del', 0)->count() > 0
            || Db::name('cashier_v3_card_operation')->where('tenant_id', $scope->tenantId())
                ->where('source_card_holder_id', $holderId)->count() > 0) {
            throw self::failure('recharge_gift_project_downstream_use_exists');
        }

        $versionRows = [];
        foreach ([['member_benefit_pool', $detailId], ['card_holder', $holderId]] as $resource) {
            $version = (array)Db::name(CashierV3EntitlementResourceVersionProvider::VERSION_TABLE)
                ->where('resource_kind', $resource[0])->where('resource_id', (string)$resource[1])->lock(true)->find();
            if (!$version || (int)($version['member_id'] ?? 0) !== (int)$source['memberId']
                || (int)($version['current_version'] ?? 0) <= 0) {
                throw self::failure('recharge_gift_project_version_missing');
            }
            $versionRows[$resource[0]] = $version;
        }
        $cardState = (array)Db::name('cashier_v3_card_state')->where('tenant_id', $scope->tenantId())
            ->where('card_holder_id', $holderId)->lock(true)->find();
        if ($cardState && ((string)($cardState['card_status'] ?? '') !== 'enabled'
            || (int)($cardState['origin_order_id'] ?? 0) !== $orderId
            || (int)($cardState['current_member_id'] ?? 0) !== (int)$source['memberId'])) {
            throw self::failure('recharge_gift_project_card_state_changed');
        }

        $rule = (array)Db::name('cashier_v3_card_rule_state')->where('tenant_id', $scope->tenantId())
            ->where('card_holder_id', $holderId)->lock(true)->find();
        $components = [];
        if ($rule) {
            if ((int)($rule['selected_kind_count'] ?? -1) !== 0
                || (int)($rule['shared_remaining_times'] ?? -1) !== (int)($rule['shared_total_times'] ?? -2)
                || (string)($rule['status'] ?? '') !== 'active') {
                throw self::failure('recharge_gift_project_rule_used');
            }
            $components = Db::name('cashier_v3_card_rule_component')->where('tenant_id', $scope->tenantId())
                ->where('rule_state_id', (int)$rule['id'])->lock(true)->order('id', 'asc')->select()->toArray();
            foreach ($components as $component) {
                if ((int)($component['remaining_times'] ?? -1) !== (int)($component['total_times'] ?? -2)
                    || (int)($component['selected_at'] ?? -1) !== 0
                    || (string)($component['status'] ?? '') !== 'active') {
                    throw self::failure('recharge_gift_project_rule_component_used');
                }
            }
        }
        return ['kind' => 'project', 'holder' => $holder, 'order' => $order, 'detail' => $detail,
            'guard' => $guard, 'versionRows' => $versionRows, 'cardState' => $cardState,
            'rule' => $rule, 'components' => $components];
    }

    /** @return array<string,mixed> */
    private function lockUnusedCoupons(array $item, array $source, CashierV3DataScopeContext $scope): array
    {
        $ids = json_decode((string)($item['coupon_user_ids_json'] ?? ''), true);
        if (!is_array($ids)) throw self::failure('recharge_gift_coupon_projection_invalid');
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids, SORT_NUMERIC);
        if ($ids === [] || count($ids) !== (int)$item['quantity'] || min($ids) <= 0) {
            throw self::failure('recharge_gift_coupon_projection_invalid');
        }
        $issue = (array)Db::name('store_coupon_issue')->where('id', (int)$item['coupon_issue_id'])->lock(true)->find();
        $coupons = Db::name('store_coupon_user')->whereIn('id', $ids)->lock(true)->order('id', 'asc')->select()->toArray();
        if (!$issue || count($coupons) !== count($ids)) throw self::failure('recharge_gift_coupon_projection_missing');
        $mappings = Db::name(self::COUPON_MAPPING_TABLE)->where('gift_item_id', (string)$item['item_id'])
            ->whereIn('coupon_user_id', $ids)->lock(true)->order('item_sequence', 'asc')->select()->toArray();
        if (count($mappings) !== count($ids)) throw self::failure('recharge_gift_coupon_issue_mapping_missing');
        $issueUserIds = array_values(array_map('intval', array_column($mappings, 'coupon_issue_user_id')));
        $issueUsers = Db::name('store_coupon_issue_user')->whereIn('id', $issueUserIds)
            ->lock(true)->order('id', 'asc')->select()->toArray();
        if (count($issueUsers) !== count($ids)) throw self::failure('recharge_gift_coupon_issue_log_missing');
        $issueUsersById = [];
        foreach ($issueUsers as $issueUser) $issueUsersById[(int)$issueUser['id']] = $issueUser;
        $mappedCouponIds = [];
        foreach ($mappings as $mapping) {
            $issueUser = $issueUsersById[(int)($mapping['coupon_issue_user_id'] ?? 0)] ?? [];
            if ((string)($mapping['status'] ?? '') !== 'issued'
                || (string)($mapping['tenant_id'] ?? '') !== $scope->tenantId()
                || (string)($mapping['gift_id'] ?? '') !== (string)$item['gift_id']
                || (int)($mapping['member_id'] ?? 0) !== (int)$source['memberId']
                || (int)($mapping['recharge_id'] ?? 0) !== (int)$source['rechargeId']
                || (int)($mapping['coupon_issue_id'] ?? 0) !== (int)$item['coupon_issue_id']
                || (int)($issueUser['uid'] ?? 0) !== (int)$source['memberId']
                || (int)($issueUser['issue_coupon_id'] ?? 0) !== (int)$item['coupon_issue_id']) {
                throw self::failure('recharge_gift_coupon_issue_mapping_invalid');
            }
            $mappedCouponIds[] = (int)$mapping['coupon_user_id'];
        }
        sort($mappedCouponIds, SORT_NUMERIC);
        if ($mappedCouponIds !== $ids) throw self::failure('recharge_gift_coupon_issue_mapping_mismatch');
        foreach ($coupons as $coupon) {
            if ((int)($coupon['uid'] ?? 0) !== (int)$source['memberId']
                || (int)($coupon['cid'] ?? 0) !== (int)$item['coupon_issue_id']
                || (string)($coupon['type'] ?? '') !== 'cashier_v3_recharge_gift'
                || (int)($coupon['use_time'] ?? -1) !== 0
                || (int)($coupon['status'] ?? -1) !== 0
                || (int)($coupon['is_fail'] ?? -1) !== 0
                || (int)($coupon['oid'] ?? -1) !== 0) {
                throw self::failure('recharge_gift_coupon_already_changed');
            }
        }
        $finite = (int)($issue['total_count'] ?? 0) > 0;
        if ($finite && (int)$issue['remain_count'] + count($coupons) > (int)$issue['total_count']) {
            throw self::failure('recharge_gift_coupon_inventory_mismatch');
        }
        return ['kind' => 'coupon', 'couponIds' => $ids, 'issueId' => (int)$issue['id'], 'finite' => $finite, 'issueMappings' => $mappings];
    }

    public function apply(
        array $source,
        string $action,
        array $prepared,
        string $operationId,
        string $commandKey,
        CashierV3BusinessEventRecorder $recorder,
        CashierV3BusinessEventExecution $execution,
        array $eventContract,
        CashierV3OperatorScope $operator,
        CashierV3DataScopeContext $scope,
        int $now,
        string $reason
    ): void {
        CashierV3TransactionGuard::assertInTransaction('rechargeGiftReversal.apply');
        if ($action !== 'void-recharge-order' || empty($prepared['authority'])) return;

        foreach ((array)$prepared['items'] as $entry) {
            $item = (array)$entry['item'];
            $projection = (array)$entry['projection'];
            $restoredCouponInventory = 0;
            if (($projection['kind'] ?? '') === 'project') {
                $this->revokeProject($projection, $operationId, $scope, $now);
            } elseif (($projection['kind'] ?? '') === 'coupon') {
                $restoredCouponInventory = $this->revokeCoupons($projection, $operationId, $now);
            } else {
                throw self::failure('recharge_gift_reversal_projection_invalid');
            }
            $giftEvent = $this->recordVoidEvent($recorder, $execution, $eventContract, $source, $item, (array)$entry['fact'], $operationId, $operator, $now);
            $reversalFact = $this->appendFactReversal((array)$entry['fact'], $operationId, $commandKey, $giftEvent, $operator, $now);
            $affected = Db::name(self::ITEM_TABLE)->where('id', (int)$item['id'])->where('status', 'issued')->update([
                'status' => 'voided', 'voided_at' => $now, 'void_reason_snapshot' => $reason, 'updated_at' => $now,
            ]);
            if ((int)$affected !== 1) throw self::failure('recharge_gift_reversal_item_race');
            $this->insertAudit($source, $item, $entry, $reversalFact, $restoredCouponInventory, $operationId, $commandKey, $operator, $scope, $now);
        }
        $authority = (array)$prepared['authority'];
        $affected = Db::name(self::AUTHORITY_TABLE)->where('id', (int)$authority['id'])->where('status', 'issued')
            ->update(['status' => 'voided', 'updated_at' => $now]);
        if ((int)$affected !== 1) throw self::failure('recharge_gift_reversal_authority_race');
    }

    private function revokeProject(array $projection, string $operationId, CashierV3DataScopeContext $scope, int $now): void
    {
        $holderId = (int)$projection['holder']['id'];
        $orderId = (int)$projection['order']['id'];
        if ((int)Db::name('user_card_holder')->where('id', $holderId)->where('is_del', 0)->update(['is_del' => 1]) !== 1) {
            throw self::failure('recharge_gift_project_holder_revoke_race');
        }
        if ((int)Db::name('store_order')->where('id', $orderId)->where('terminal_action', 0)->update([
            'terminal_action' => StoreOrderTerminalOperation::ACTION_VOID, 'terminal_action_time' => $now,
        ]) !== 1) throw self::failure('recharge_gift_project_order_revoke_race');
        if (!empty($projection['cardState'])) {
            $stateAffected = Db::name('cashier_v3_card_state')->where('id', (int)$projection['cardState']['id'])
                ->where('card_status', 'enabled')->where('current_version', (int)$projection['cardState']['current_version'])
                ->update(['card_status' => 'disabled', 'status_reason_snapshot' => '充值单作废撤销赠送权益',
                    'current_version' => Db::raw('current_version+1'), 'last_operation_id' => $operationId, 'updated_at' => $now]);
            if ((int)$stateAffected !== 1) throw self::failure('recharge_gift_project_card_state_revoke_race');
        }
        if (!empty($projection['rule'])) {
            $ruleAffected = Db::name('cashier_v3_card_rule_state')->where('id', (int)$projection['rule']['id'])->where('status', 'active')
                ->update(['status' => 'revoked', 'state_version' => Db::raw('state_version+1'), 'update_time' => $now]);
            if ((int)$ruleAffected !== 1) throw self::failure('recharge_gift_project_rule_revoke_race');
            $componentAffected = Db::name('cashier_v3_card_rule_component')->where('rule_state_id', (int)$projection['rule']['id'])->where('status', 'active')
                ->update(['status' => 'revoked', 'state_version' => Db::raw('state_version+1'), 'update_time' => $now]);
            if ((int)$componentAffected !== count((array)$projection['components'])) throw self::failure('recharge_gift_project_rule_component_revoke_race');
        }
        foreach ((array)$projection['versionRows'] as $kind => $version) {
            $affected = Db::name(CashierV3EntitlementResourceVersionProvider::VERSION_TABLE)
                ->where('id', (int)$version['id'])->where('current_version', (int)$version['current_version'])
                ->update(['source_fingerprint' => hash('sha256', 'recharge_gift_void|' . $operationId . '|' . $kind),
                    'current_version' => Db::raw('current_version+1'), 'last_action' => 'void-recharge-order', 'update_time' => $now]);
            if ((int)$affected !== 1) throw self::failure('recharge_gift_project_version_revoke_race');
        }
        $repository = new ThinkPhpCashierV3ServiceOrderRepository();
        $repository->bumpEntitlementGuardCas($scope->tenantId(), (int)$projection['detail']['id'], (int)$projection['guard']['current_version'], 'recharge_gift_voided', $now);
    }

    /** @return array<string,mixed> */
    private function recordVoidEvent(CashierV3BusinessEventRecorder $recorder, CashierV3BusinessEventExecution $execution, array $contract, array $source, array $item, array $fact, string $operationId, CashierV3OperatorScope $operator, int $now): array
    {
        return $recorder->recordInTx($execution, $contract, [
            'event_type' => 'gift.voided', 'aggregate_type' => 'recharge_gift', 'aggregate_id' => (string)$item['gift_id'],
            'aggregate_version' => 2, 'event_version' => 1, 'detail_id' => (string)$item['item_id'],
            'source_type' => 'void-recharge-order', 'source_id' => $operationId,
            'reversal_of' => (string)$fact['business_event_no'], 'member_id' => (int)$source['memberId'],
            'business_date' => date('Y-m-d', $now), 'occurred_at' => $now, 'settled_at' => $now, 'recorded_at' => $now,
            'aggregate_name_snapshot' => (string)$item['content_name_snapshot'],
            'store_name_snapshot' => (string)Db::name('system_store')->where('id', $operator->storeId())->value('name'),
            'payload' => ['giftId' => (string)$item['gift_id'], 'giftItemId' => (string)$item['item_id'],
                'rechargeId' => (int)$source['rechargeId'], 'giftKind' => (string)$item['gift_kind'],
                'quantity' => (int)$item['quantity'], 'operationId' => $operationId],
        ]);
    }

    private function revokeCoupons(array $projection, string $operationId, int $now): int
    {
        $ids = (array)$projection['couponIds'];
        $affected = Db::name('store_coupon_user')->whereIn('id', $ids)->where('use_time', 0)->where('status', 0)
            ->where('is_fail', 0)->where('oid', 0)->update(['status' => 2, 'is_fail' => 1]);
        if ((int)$affected !== count($ids)) throw self::failure('recharge_gift_coupon_revoke_race');
        $mappingIds = array_values(array_map('intval', array_column((array)$projection['issueMappings'], 'id')));
        $affected = Db::name(self::COUPON_MAPPING_TABLE)->whereIn('id', $mappingIds)->where('status', 'issued')
            ->update(['status' => 'voided', 'void_operation_id' => $operationId, 'voided_at' => $now, 'updated_at' => $now]);
        if ((int)$affected !== count($mappingIds)) throw self::failure('recharge_gift_coupon_issue_mapping_void_failed');
        if (empty($projection['finite'])) return 0;
        $affected = Db::name('store_coupon_issue')->where('id', (int)$projection['issueId'])
            ->inc('remain_count', count($ids))->update();
        if ((int)$affected !== 1) throw self::failure('recharge_gift_coupon_inventory_restore_failed');
        return count($ids);
    }

    /** @return array<string,mixed> */
    private function appendFactReversal(array $source, string $operationId, string $commandKey, array $event, CashierV3OperatorScope $operator, int $now): array
    {
        $row = $source;
        unset($row['id']);
        $row['gift_fact_id'] = 'GFR-' . strtoupper(substr(hash_hmac('sha256', (string)$source['gift_fact_id'] . '|' . $operationId, $this->secret()), 0, 40));
        $row['natural_key'] = 'recharge_gift_void:' . hash('sha256', (string)$source['gift_fact_id'] . '|' . $operationId);
        $row['fact_version'] = 1;
        $row['status'] = 'voided';
        $row['reversal_of'] = (string)$source['gift_fact_id'];
        $row['business_event_no'] = (string)$event['event_no'];
        $row['command_idempotency_key'] = $commandKey;
        $row['operator_id'] = $operator->operatorId();
        $row['business_date'] = date('Y-m-d', $now);
        $row['occurred_at'] = $now;
        $row['settled_at'] = $now;
        $row['recorded_at'] = $now;
        $row['created_at'] = $now;
        $row['updated_at'] = $now;
        if ((int)Db::name(self::FACT_TABLE)->insert($row) !== 1) throw self::failure('recharge_gift_fact_reversal_insert_failed');
        return $row;
    }

    private function insertAudit(array $source, array $item, array $entry, array $reversalFact, int $restoredCouponInventory, string $operationId, string $commandKey, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope, int $now): void
    {
        $row = [
            'reversal_id' => 'RGR-' . strtoupper(substr(hash_hmac('sha256', $operationId . '|' . $item['item_id'], $this->secret()), 0, 40)),
            'operation_id' => $operationId, 'tenant_id' => $scope->tenantId(), 'store_id' => $operator->storeId(),
            'member_id' => (int)$source['memberId'], 'recharge_id' => (int)$source['rechargeId'],
            'gift_id' => (string)$item['gift_id'], 'gift_item_id' => (string)$item['item_id'], 'gift_kind' => (string)$item['gift_kind'],
            'legacy_order_id' => (int)$item['legacy_order_id'], 'card_holder_id' => (int)$item['card_holder_id'],
            'benefit_detail_id' => (int)$item['benefit_detail_id'], 'coupon_user_ids_json' => (string)$item['coupon_user_ids_json'],
            'restored_coupon_inventory_count' => $restoredCouponInventory,
            'original_gift_fact_id' => (string)$entry['fact']['gift_fact_id'], 'reversal_gift_fact_id' => (string)$reversalFact['gift_fact_id'],
            'command_idempotency_key' => $commandKey, 'operator_id' => $operator->operatorId(), 'status' => 'revoked',
            'occurred_at' => $now, 'created_at' => $now,
        ];
        if ((int)Db::name(self::AUDIT_TABLE)->insert($row) !== 1) throw self::failure('recharge_gift_reversal_audit_insert_failed');
    }

    private function secret(): string
    {
        $secret = trim((string)config('cashier_v3.checkout_namespace_secret'));
        if (strlen($secret) < 32) throw self::failure('recharge_gift_reversal_secret_missing');
        return $secret;
    }

    private static function failure(string $reason): CashierV3CommandException
    {
        return new CashierV3CommandException(
            CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
            '充值赠送内容已有使用或变化，本次作废未提交任何变更。',
            CashierV3ResultCode::STATUS_FAILED,
            ['reason' => $reason]
        );
    }
}
