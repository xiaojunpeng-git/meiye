<?php
declare(strict_types=1);

namespace app\services\cashier\v3\reservation;

use app\services\cashier\v3\card\CashierV3HistoricalEntitlementScopePolicy;
use app\services\cashier\v3\card\CashierV3CardRuleEntitlementAuthorityServices;
use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\organization\OrganizationScopeService;
use app\services\product\product\StoreProductReservationServices;
use think\exception\ValidateException;
use think\facade\Db;

/**
 * Historical rights have no sales store. Offer only operating stores in the
 * imported right's owner organization, then recheck that scope at submission.
 */
final class ImportedEntitlementReservationStoreServices
{
    /** Return the authoritative holder ID, or zero for an unrelated order. */
    public function holderId(int $uid, int $orderId, int $detailId): int
    {
        if ($uid <= 0 || $orderId <= 0 || $detailId <= 0) return 0;
        $order = Db::name('store_order')->where('id', $orderId)->where('uid', $uid)
            ->where('paid', 1)->where('is_del', 0)->where('is_user_del', 0)
            ->where('refund_status', 0)->where('terminal_action', 0)
            ->where('is_system_del', 1)->where('store_id', 0)->find();
        if (!$order) return 0;
        $detail = Db::name('store_order_cart_info')->where('id', $detailId)
            ->where('oid', $orderId)->where('cart_type', 2)->where('product_type', 6)
            ->where('is_writeoff', 0)->where('write_surplus_times', '>', 0)->find();
        if (!$detail) return 0;
        $holder = Db::name('user_card_holder')->where('oid', $orderId)
            ->where('uid', $uid)->where('is_del', 0)->where('write_surplus_times', '>', 0)
            ->field('id')->find();
        if (!$holder) return 0;
        $holderId = (int)$holder['id'];
        if (!CashierV3HistoricalEntitlementScopePolicy::isImportedHolder(
            $holderId, CashierV3ScopeResolver::TENANT_SCOPE_ID
        )) return 0;
        return (int)Db::name('migration_entitlement_scope')
            ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->where('card_holder_id', $holderId)->where('target_uid', $uid)
            ->where('status', 'active')->count() === 1 ? $holderId : 0;
    }

    /** Options are derived from the import scope, never from the member's home store. */
    public function availableStores(int $uid, int $orderId, int $detailId): array
    {
        $holderId = $this->holderId($uid, $orderId, $detailId);
        if ($holderId <= 0) return [];
        $ownerOrgId = (int)Db::name('migration_entitlement_scope')
            ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->where('card_holder_id', $holderId)->where('target_uid', $uid)
            ->where('status', 'active')->value('owner_org_id');
        if ($ownerOrgId <= 0) return [];
        // A disabled ancestor revokes every descendant. Mirror the write-side
        // historical policy instead of listing a store that booking rejects.
        $orgIds = app()->make(OrganizationScopeService::class)->getOrgIds($ownerOrgId, true);
        $orgRows = Db::name('organization')->whereIn('id', $orgIds)
            ->field('id,pid,status,is_del')->select()->toArray();
        $orgById = [];
        foreach ($orgRows as $row) $orgById[(int)$row['id']] = $row;
        $allowedOrgIds = [];
        foreach ($orgById as $orgId => $row) {
            $current = $orgId;
            $seen = [];
            for ($depth = 0; $depth < 64 && $current > 0; $depth++) {
                if (isset($seen[$current]) || !isset($orgById[$current])
                    || (int)$orgById[$current]['status'] !== 1
                    || (int)$orgById[$current]['is_del'] !== 0) break;
                if ($current === $ownerOrgId) {
                    $allowedOrgIds[] = $orgId;
                    break;
                }
                $seen[$current] = true;
                $current = (int)$orgById[$current]['pid'];
            }
        }
        if (!$allowedOrgIds) return [];
        $storeIds = Db::name('organization_store')->whereIn('org_id', $allowedOrgIds)->column('store_id');
        if (!$storeIds) return [];
        return Db::name('system_store')->whereIn('id', $storeIds)
            ->where('is_show', 1)->where('is_del', 0)->where('business_status', 1)
            ->field('id,name')->order('id asc')->select()->toArray();
    }

    /** Booking must use the current operating-store policy even if the picker is stale. */
    public function assertSelectedStore(int $uid, int $orderId, int $detailId, int $storeId): void
    {
        $holderId = $this->holderId($uid, $orderId, $detailId);
        if ($holderId <= 0 || $storeId <= 0 || !in_array($holderId,
            CashierV3HistoricalEntitlementScopePolicy::allowedHolderIds(
                [$holderId], CashierV3ScopeResolver::TENANT_SCOPE_ID, $storeId
            ), true)) {
            throw new ValidateException('该历史权益不能在所选门店预约，请重新选择渼约营业门店。');
        }
    }

    /**
     * Resolve one owned right for a historical booking. Both picker and submit
     * use this boundary: hidden carriers require import scope, ordinary orders
     * retain the configured cross-store rule; neither can supply foreign rights.
     */
    public function reservationDetail(int $uid, int $detailId, int $storeId, int $at = 0): array
    {
        if ($uid <= 0 || $detailId <= 0 || $storeId <= 0) {
            throw new ValidateException('请先选择预约门店。');
        }
        $detail = Db::name('store_order_cart_info')->where('id', $detailId)
            ->where('cart_type', 2)->where('product_type', 6)
            ->where('is_writeoff', 0)->where('write_surplus_times', '>', 0)->find();
        $order = $detail ? Db::name('store_order')->where('id', (int)$detail['oid'])
            ->where('uid', $uid)->where('paid', 1)->where('is_del', 0)
            ->where('is_user_del', 0)->where('refund_status', 0)->where('terminal_action', 0)
            ->where('card_upgrade_use_oid', 0)->whereIn('is_system_del', [0, 1])->find() : null;
        if (!$detail || !$order) throw new ValidateException('项目不存在或权益已失效。');
        $at = $at > 0 ? $at : time();
        $this->assertValidityWindow($detail, $at);
        if ((int)$order['is_system_del'] === 1) {
            $this->assertSelectedStore($uid, (int)$order['id'], $detailId, $storeId);
            $holder = Db::name('user_card_holder')->where('oid', (int)$order['id'])
                ->where('uid', $uid)->where('is_del', 0)->find();
            $this->assertValidityWindow($holder ?: [], $at);
        } elseif ((int)$order['store_id'] !== $storeId && (int)sys_config('cross_store_verification', 1) !== 1) {
            throw new ValidateException('该已购项目不能在所选门店预约。');
        }
        $product = Db::name('store_product')->where('id', (int)$detail['product_id'])
            ->field('id,pid,project_service_duration,addon_service_duration')->find();
        if (!$product) throw new ValidateException('预约项目不存在。');
        $durations = app()->make(StoreProductReservationServices::class)->fillServiceDuration($product);
        $detail['_project_duration'] = (int)$durations['project_service_duration'];
        $detail['_duration'] = (int)$durations['addon_service_duration'];
        $detail['_order'] = $order;
        // Issued rules, not today's catalogue, own shared pools, choice kinds
        // and time-card validity. Legacy imports without a rule keep raw rights.
        $holder = $holder ?? Db::name('user_card_holder')->where('oid', (int)$order['id'])
            ->where('uid', $uid)->where('is_del', 0)->find();
        $detail['_holder'] = $holder ?: [];
        $detail['_authority'] = $holder ? (new CashierV3CardRuleEntitlementAuthorityServices())
            ->authorityForDetail(CashierV3ScopeResolver::TENANT_SCOPE_ID, (int)$holder['id'], $detailId) : null;
        if ($detail['_authority']) {
            $a = $detail['_authority'];
            if (!$a['choiceAvailable'] || $a['stateStatus'] !== 'active' || (int)$a['remainingTimes'] <= 0) {
                throw new ValidateException('该卡项目当前不可预约。');
            }
            $this->assertValidityWindow(['write_start' => $a['validFrom'], 'write_end' => $a['validThrough']], $at);
        }
        return $detail;
    }

    /** A right must still be valid on the booked service date, not just today. */
    private function assertValidityWindow(array $right, int $at): void
    {
        if (((int)($right['write_start'] ?? 0) > 0 && (int)$right['write_start'] > $at)
            || ((int)($right['write_end'] ?? 0) > 0 && (int)$right['write_end'] < $at)) {
            throw new ValidateException('项目不在权益有效期内。');
        }
    }

    /** Shared pools reserve visits across all sibling projects, never once per
     * displayed row. Time-card virtual counters are not user-facing balances. */
    public function availableQuantity(int $uid, array $detail): int
    {
        $ids = [(int)$detail['id']];
        $a = $detail['_authority'] ?? null;
        $choice = $this->choiceConstraint($uid, $detail);
        if ($choice && $choice['cost'] > $choice['available']) return 0;
        if ($a && $a['ruleType'] === 'choice_count') {
            $all = (new CashierV3CardRuleEntitlementAuthorityServices())->authoritiesForHolder(
                CashierV3ScopeResolver::TENANT_SCOPE_ID, (int)$detail['_holder']['id']);
            $ids = [];
            foreach ($all as $id => $other) if ((int)$other['combinationGroupNo'] === (int)$a['combinationGroupNo']) $ids[] = (int)$id;
        }
        $reserved = Db::name('cashier_v3_reservation_line')->alias('l')
            ->join('cashier_v3_reservation r', 'r.id=l.reservation_id')
            ->where('l.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->whereIn('l.entitlement_source_detail_id', $ids)
            ->where('r.member_id', $uid)
            ->where('r.lifecycle_generation', CashierV3ReservationLifecycleServices::GENERATION)
            ->whereIn('r.status', ['PENDING_CONFIRMATION', 'UNSTARTED', 'IN_SERVICE'])
            ->sum('l.quantity');
        $remaining = $a ? (int)$a['remainingTimes'] : (int)$detail['write_surplus_times'];
        return max(0, (int)floor($remaining - (float)$reserved));
    }

    /** Pending new project kinds occupy selection slots without permanently
     * selecting a card component. Cancelling a reservation releases the slot. */
    public function choiceConstraint(int $uid, array $detail): ?array
    {
        $a = $detail['_authority'] ?? null;
        if (!$a || $a['ruleType'] !== 'choice_kind' || $a['selectionStatus'] !== 'candidate') return null;
        $authority = new CashierV3CardRuleEntitlementAuthorityServices();
        $holderId = (int)$detail['_holder']['id'];
        $snapshot = $authority->snapshotForHolder(CashierV3ScopeResolver::TENANT_SCOPE_ID, $holderId);
        $state = $snapshot['state'];
        $limit = (int)$a['combinationGroupNo'] > 0 ? (int)$a['combinationChoiceLimit'] : (int)$state['choice_limit'];
        $selected = (int)$a['combinationGroupNo'] > 0 ? (int)$a['combinationSelectedKindCount'] : (int)$state['selected_kind_count'];
        $ids = [];
        foreach ($authority->authoritiesForHolder(CashierV3ScopeResolver::TENANT_SCOPE_ID, $holderId) as $id => $other) {
            if ((int)$other['combinationGroupNo'] === (int)$a['combinationGroupNo'] && $other['selectionStatus'] === 'candidate') $ids[] = (int)$id;
        }
        $pending = Db::name('cashier_v3_reservation_line')->alias('l')
            ->join('cashier_v3_reservation r', 'r.id=l.reservation_id')
            ->where('l.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->whereIn('l.entitlement_source_detail_id', $ids)->where('r.member_id', $uid)
            ->where('r.lifecycle_generation', CashierV3ReservationLifecycleServices::GENERATION)
            ->whereIn('r.status', ['PENDING_CONFIRMATION', 'UNSTARTED', 'IN_SERVICE'])
            ->column('l.entitlement_source_detail_id');
        $pending = array_values(array_unique(array_map('intval', $pending)));
        return ['key' => 'kinds:' . $holderId . ':' . (int)$a['combinationGroupNo'],
            'cost' => in_array((int)$detail['id'], $pending, true) ? 0 : 1,
            'available' => max(0, $limit - $selected - count($pending))];
    }

    /** Stable pool key is backend-only validation identity, never a displayed
     * card name. Different purchases and combination groups cannot merge. */
    public function reservationPoolKey(array $detail): string
    {
        $a = $detail['_authority'] ?? null;
        return $a && $a['ruleType'] === 'choice_count'
            ? 'card:' . (int)$detail['oid'] . ':group:' . (int)$a['combinationGroupNo']
            : 'detail:' . (int)$detail['id'];
    }

    /** Display data comes from the issued holder/snapshot. Do not sum children
     * of a shared pool, guess a legacy rule from its name or expose money data. */
    private function pickerCard(array $detail): array
    {
        $order = $detail['_order']; $holder = $detail['_holder'];
        if ((int)$order['type'] !== 11 && (int)$order['product_type'] !== 5) return [];
        $main = Db::name('store_order_cart_info')->where('oid', (int)$order['id'])->where('cart_type', 0)->value('cart_info');
        $snapshot = json_decode((string)$main, true) ?: [];
        $name = trim((string)($holder['card_name'] ?? '')) ?: trim((string)($snapshot['productInfo']['store_name'] ?? '')) ?: '卡项';
        $a = $detail['_authority'] ?? null;
        $type = $a['cardRuleType'] ?? 'legacy';
        $labels = ['normal' => '普通卡', 'choice_kind' => '任选种数卡', 'choice_count' => '任选次数卡', 'time' => '时间卡', 'combination' => '组合卡', 'legacy' => '卡项'];
        $remainingText = isset($holder['write_surplus_times']) ? '剩余' . (int)$holder['write_surplus_times'] . '次' : '';
        if ($type === 'normal') {
            $all = (new CashierV3CardRuleEntitlementAuthorityServices())->authoritiesForHolder(
                CashierV3ScopeResolver::TENANT_SCOPE_ID, (int)$holder['id']);
            $remainingText = '剩余' . array_sum(array_column($all, 'remainingTimes')) . '次';
        }
        if ($type === 'choice_count') $remainingText = '共享可用' . $this->availableQuantity((int)$order['uid'], $detail) . '次';
        if ($type === 'time') $remainingText = '有效期内不限次';
        if ($type === 'combination') $remainingText = '按组使用';
        if ($type === 'choice_kind') {
            $issued = (new CashierV3CardRuleEntitlementAuthorityServices())->snapshotForHolder(
                CashierV3ScopeResolver::TENANT_SCOPE_ID, (int)$holder['id']);
            $state = $issued['state'];
            $remainingText = '已选' . (int)$state['selected_kind_count'] . '/' . (int)$state['choice_limit'] . '种';
        }
        return ['name' => $name, 'number' => trim((string)($holder['card_no'] ?? '')),
            'rule_type' => $type, 'rule_label' => $labels[$type] ?? '卡项', 'remaining_text' => $remainingText];
    }

    private function pickerItemPresentation(array $detail, int $available): array
    {
        $a = $detail['_authority'] ?? null;
        $text = '剩余' . $available . '次';
        if ($a && $a['ruleType'] === 'time') $text = '有效期内不限次';
        if ($a && $a['ruleType'] === 'choice_count') $text = '共享可用' . $available . '次';
        if ($a && $a['ruleType'] === 'choice_kind' && $a['selectionStatus'] === 'candidate') $text = '待选 · —';
        $ruleLabel = (string)($a['sourceKindLabel'] ?? '');
        if ($a && (int)$a['combinationGroupNo'] > 0) {
            if ($a['ruleType'] === 'choice_count') $ruleLabel .= ' · 共享可用' . $available . '次';
            if ($a['ruleType'] === 'choice_kind') $ruleLabel .= ' · 已选' . (int)$a['combinationSelectedKindCount'] . '/' . (int)$a['combinationChoiceLimit'] . '种';
        }
        return ['remaining_text' => $text, 'group_no'  => (int)($a['combinationGroupNo'] ?? 0),
            'group_name' => (string)($a['combinationGroupName'] ?? ''),
            'rule_label' => $ruleLabel,
            'selection_status' => (string)($a['selectionStatus'] ?? '')];
    }

    /**
     * Read-only card projection: pending visits do not consume physical uses.
     * Reuse picker/submit quota authority; count direct visits only once in the
     * card total, while shared-pool occupancy is labelled separately per child.
     */
    public function withCardBenefitsReservationUsage(int $uid, array $rows): array
    {
        if ($uid <= 0) throw new ValidateException('请先登录。');
        $projects = array_filter($rows, static function (array $row): bool {
            return (int)($row['product_type'] ?? 0) === 6;
        });
        if (!$projects) return $rows;
        $orderIds = array_values(array_unique(array_map('intval', array_column($projects, 'oid'))));
        $owned = Db::name('store_order')->where('uid', $uid)->whereIn('id', $orderIds)->column('id');
        if (count($owned) !== count($orderIds)) throw new ValidateException('无权查看该项目。');
        $holders = Db::name('user_card_holder')->where('uid', $uid)->whereIn('oid', $orderIds)
            ->where('is_del', 0)->select()->toArray();
        $holderByOrder = []; $rulesByOrder = []; $allIds = array_map('intval', array_column($projects, 'id'));
        foreach ($holders as $holder) {
            $oid = (int)$holder['oid'];
            // Match reservationDetail's first active holder, not a later row
            // that happens to share its order ID in historical data.
            if (isset($holderByOrder[$oid])) continue;
            $holderByOrder[$oid] = $holder;
            $rulesByOrder[$oid] = (new CashierV3CardRuleEntitlementAuthorityServices())
                ->authoritiesForHolder(CashierV3ScopeResolver::TENANT_SCOPE_ID, (int)$holder['id']);
            $allIds = array_merge($allIds, array_map('intval', array_keys($rulesByOrder[$oid])));
        }
        // Same tenant, generation and statuses as availableQuantity. Batch the
        // display counts; neither cancelled nor another member's visits enter it.
        $pendingRows = Db::name('cashier_v3_reservation_line')->alias('l')
            ->join('cashier_v3_reservation r', 'r.id=l.reservation_id')
            ->where('l.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->whereIn('l.entitlement_source_detail_id', array_values(array_unique($allIds)))
            ->where('r.member_id', $uid)->where('r.lifecycle_generation', CashierV3ReservationLifecycleServices::GENERATION)
            ->whereIn('r.status', ['PENDING_CONFIRMATION', 'UNSTARTED', 'IN_SERVICE'])
            ->field('l.entitlement_source_detail_id, SUM(l.quantity) AS reserved_quantity')
            ->group('l.entitlement_source_detail_id')->select()->toArray();
        $pending = [];
        foreach ($pendingRows as $row) $pending[(int)$row['entitlement_source_detail_id']] = (int)ceil((float)$row['reserved_quantity']);
        foreach ($rows as &$row) {
            if ((int)($row['product_type'] ?? 0) !== 6) continue;
            $oid = (int)$row['oid']; $id = (int)$row['id'];
            $detail = $row; $detail['_holder'] = $holderByOrder[$oid] ?? [];
            $detail['_authority'] = $rulesByOrder[$oid][$id] ?? null;
            $a = $detail['_authority']; $type = $a['ruleType'] ?? 'legacy';
            $available = $this->availableQuantity($uid, $detail);
            $poolOccupied = $pending[$id] ?? 0;
            if ($type === 'choice_count') {
                $poolOccupied = 0;
                foreach ($rulesByOrder[$oid] as $otherId => $other) {
                    if ((int)$other['combinationGroupNo'] === (int)$a['combinationGroupNo']) $poolOccupied += $pending[(int)$otherId] ?? 0;
                }
            }
            $reason = ''; $now = time();
            if ((int)($row['write_start'] ?? 0) > $now) $reason = '权益未生效';
            elseif ((int)($row['write_end'] ?? 0) > 0 && (int)$row['write_end'] < $now) $reason = '权益已过期';
            elseif ((int)($row['is_writeoff'] ?? 0) === 1 || (int)$row['write_surplus_times'] <= 0) $reason = '次数已用完';
            elseif ($a && (!$a['choiceAvailable'] || $a['stateStatus'] !== 'active' || (int)$a['remainingTimes'] <= 0)) $reason = '权益不可预约';
            elseif ($a && ((int)$a['validFrom'] > $now || ((int)$a['validThrough'] > 0 && (int)$a['validThrough'] < $now))) $reason = '不在有效期内';
            elseif ($available <= 0) $reason = $poolOccupied > 0 ? '已预约占用' : '可选项目已达上限';
            if ($reason !== '') $available = 0;
            $row['waiting_service'] = $pending[$id] ?? 0;
            $row['reservation_usage'] = ['known' => true, 'occupied_quantity' => $pending[$id] ?? 0,
                'shared_occupied_quantity' => $poolOccupied, 'available_quantity' => $available,
                'available_text' => $type === 'time' && $available > 0 ? '不限次' : (string)$available . '次',
                'shared_pool' => $type === 'choice_count', 'disabled_reason' => $reason];
        }
        unset($row);
        return $rows;
    }

    /**
     * Historical picker uses read-only owned-right queries, not the legacy
     * write-off list's consistency repair. Main context is checked server-side.
     */
    public function addonOptions(int $uid, int $orderId, int $mainDetailId, int $storeId): array
    {
        $this->assertSelectedStore($uid, $orderId, $mainDetailId, $storeId);
        return $this->ownedAddonOptions($uid, $orderId, $mainDetailId, $storeId);
    }

    /**
     * A purchased main right authorizes this picker, never another member's ID.
     * Every candidate independently passes the same scope/validity rule used at
     * submission. Ordinary mains may therefore use valid imported add-ons too.
     */
    public function ownedAddonOptions(int $uid, int $orderId, int $mainDetailId, int $storeId, int $mainQuantity = 1): array
    {
        $main = $this->reservationDetail($uid, $mainDetailId, $storeId);
        if ((int)$main['oid'] !== $orderId) {
            throw new ValidateException('主项目与订单不匹配。');
        }
        $mainPool = $this->reservationPoolKey($main);
        $mainChoice = $this->choiceConstraint($uid, $main);
        $candidates = Db::name('store_order_cart_info')->alias('c')
            ->join('store_order o', 'o.id=c.oid')->where('o.uid', $uid)
            ->where('o.paid', 1)->where('o.is_del', 0)->where('o.is_user_del', 0)
            ->where('o.refund_status', 0)->where('o.terminal_action', 0)
            ->whereIn('o.is_system_del', [0, 1])->where('c.cart_type', 2)
            ->where('c.product_type', 6)->where('c.is_writeoff', 0)
            ->where('c.write_surplus_times', '>', 0)->where('c.id', '<>', $mainDetailId)
            ->order('c.id desc')->column('c.id');
        $groups = [];
        foreach ($candidates as $candidate) {
            try {
                $detail = $this->reservationDetail($uid, (int)$candidate, $storeId);
            } catch (ValidateException $unavailable) {
                continue;
            }
            $available = $this->availableQuantity($uid, $detail);
            $beforeMain = $available;
            $pool = $this->reservationPoolKey($detail);
            // This draft's main project already occupies the same shared pool.
            // These are read hints only; submission recomputes the whole demand.
            if ($pool === $mainPool) $available = max(0, $available - max(1, $mainQuantity));
            $choice = $this->choiceConstraint($uid, $detail);
            if ($choice && $mainChoice && $choice['key'] === $mainChoice['key']) {
                $choice['available'] = max(0, $choice['available'] - $mainChoice['cost']);
                if ($choice['cost'] > $choice['available']) continue;
            }
            // Keep occupied rights visible without granting quota. Choice-kind
            // restrictions retain their existing exclusion; only reservation
            // occupancy is exposed as a disabled, explanatory picker row.
            if ($choice && $choice['cost'] > $choice['available']) continue;
            $disabledReason = $available > 0 ? '' : ($beforeMain > 0
                ? '主项目已占用可用次数' : '已预约占用');
            $order = $detail['_order'];
            $oid = (int)$order['id'];
            if (!isset($groups[$oid])) {
                $groups[$oid] = ['order' => ['id' => $oid, 'type' => (int)$order['type'],
                    'product_type' => (int)$order['product_type'],
                    'card' => $this->pickerCard($detail)], 'cart_info' => []];
            }
            if ($pool === $mainPool && ($detail['_authority']['cardRuleType'] ?? '') === 'choice_count') {
                $groups[$oid]['order']['card']['remaining_text'] = '共享可用' . $available . '次';
            }
            $presentation = $this->pickerItemPresentation($detail, $available);
            // Booking occupancy has not consumed the physical entitlement.
            // Show its remaining uses alongside the reason, never as zero uses.
            if ($disabledReason !== '' && ($detail['_authority']['ruleType'] ?? '') !== 'time') {
                $presentation['remaining_text'] = '剩余' . (int)($detail['_authority']['remainingTimes'] ?? $detail['write_surplus_times']) . '次';
            }
            $presentation += ['disabled' => $available <= 0, 'disabled_reason' => $disabledReason,
                'pool_key' => $pool, 'available_quantity' => $available,
                'choice_key' => $choice['key'] ?? '', 'choice_cost' => $choice['cost'] ?? 0,
                'choice_available' => $choice['available'] ?? 0];
            $snapshot = json_decode((string)$detail['cart_info'], true) ?: [];
            $snapshot['productInfo']['addon_service_duration'] = $detail['_duration'];
            // Return only the picker contract, not the carrier's financial or
            // migration internals. IDs are revalidated again on submission.
            $groups[$oid]['cart_info'][] = [
                'id' => (int)$detail['id'], 'oid' => $oid,
                'product_id' => (int)$detail['product_id'], 'product_type' => 6,
                'sku_unique' => (string)$detail['sku_unique'],
                'is_gift' => (int)$detail['is_gift'], 'is_writeoff' => 0,
                'write_surplus_times' => $available, 'cart_info' => $snapshot,
                'addon_service_duration' => (int)$detail['_duration'],
                // Presentation only: no new client value is used as a right or quota.
                'card_presentation' => $presentation,
            ];
        }
        return ['list' => array_values($groups)];
    }
}
