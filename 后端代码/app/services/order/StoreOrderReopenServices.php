<?php
declare(strict_types=1);

namespace app\services\order;

use app\dao\order\StoreOrderReopenDraftDao;
use app\model\order\StoreOrderReopenDraft;
use app\model\order\StoreOrderTerminalOperation;
use app\services\BaseServices;
use app\services\order\cashier\CashierOrderServices;
use app\services\order\terminal\OrderTerminalError;
use app\services\store\SystemStoreStaffServices;
use think\facade\Db;
use think\facade\Log;

/**
 * 作废后重新开单：草稿、幂等、收银加载、支付绑定（阶段 4）
 */
class StoreOrderReopenServices extends BaseServices
{
    /** 草稿有效期：24 小时 */
    public const DRAFT_TTL_SEC = 86400;

    public function __construct(StoreOrderReopenDraftDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 创建或返回同一有效重开草稿（幂等）
     * 强约束：一张作废原单仅一条草稿行（uk_source_order_id）；已结账不可再次重开。
     *
     * @param array $input store_order_id, store_scope, operator_type, operator_id, source_type
     */
    public function createOrGetDraft(array $input): array
    {
        $sourceOrderId = (int)($input['store_order_id'] ?? 0);
        $storeScope = (int)($input['store_scope'] ?? 0);
        if ($sourceOrderId <= 0) {
            OrderTerminalError::throw(OrderTerminalError::ORDER_NOT_FOUND);
        }

        return Db::transaction(function () use ($sourceOrderId, $storeScope, $input) {
            $order = Db::name('store_order')->where('id', $sourceOrderId)->lock(true)->find();
            if (!$order) {
                OrderTerminalError::throw(OrderTerminalError::ORDER_NOT_FOUND);
            }
            $order = is_array($order) ? $order : $order->toArray();
            $this->assertReopenAllowed($order, $storeScope);
            $this->assertNotAlreadyReopenedPaid($sourceOrderId);

            $now = time();
            $draft = $this->dao->getBySourceOrderId($sourceOrderId, true);
            if ($draft) {
                $draft = is_array($draft) ? $draft : $draft->toArray();
                if ((int)$draft['state'] === StoreOrderReopenDraft::STATE_CHECKED_OUT) {
                    OrderTerminalError::throw(OrderTerminalError::REOPEN_ALREADY_DONE);
                }

                $newOid = (int)($draft['new_order_id'] ?? 0);
                // 已绑定新订单：不得因过期再开第二份草稿
                if ($newOid > 0) {
                    $paid = (int)Db::name('store_order')->where('id', $newOid)->value('paid');
                    if ($paid === 1) {
                        $this->closeDraftCheckedOut((int)$draft['id'], $newOid, $sourceOrderId, $now);
                        OrderTerminalError::throw(OrderTerminalError::REOPEN_ALREADY_DONE);
                    }
                    // 纠正误标 EXPIRED，并取消自动过期
                    if ((int)$draft['state'] === StoreOrderReopenDraft::STATE_EXPIRED
                        || ((int)$draft['expire_time'] > 0 && (int)$draft['expire_time'] < $now)) {
                        Db::name('store_order_reopen_draft')->where('id', (int)$draft['id'])->update([
                            'state' => StoreOrderReopenDraft::STATE_LOADED,
                            'expire_time' => 0,
                            'update_time' => $now,
                        ]);
                        $draft['state'] = StoreOrderReopenDraft::STATE_LOADED;
                        $draft['expire_time'] = 0;
                    }
                    return $this->formatPendingPayDraftResponse($draft, $order, false);
                }

                // 未绑定：仅此时允许 24h 过期后原位重建（保持 source_order_id 唯一）
                if ($this->isUnboundDraftExpired($draft, $now)) {
                    $built = $this->buildSnapshotAndValidations($order);
                    $token = $this->makeDraftToken($sourceOrderId);
                    Db::name('store_order_reopen_draft')->where('id', (int)$draft['id'])->update([
                        'draft_token' => $token,
                        'terminal_operation_id' => (int)($order['terminal_operation_id'] ?? 0),
                        'operator_type' => (string)($input['operator_type'] ?? ''),
                        'operator_id' => (int)($input['operator_id'] ?? 0),
                        'uid' => (int)($order['uid'] ?? 0),
                        'state' => StoreOrderReopenDraft::STATE_DRAFT,
                        'snapshot_json' => json_encode($built['snapshot'], JSON_UNESCAPED_UNICODE),
                        'skipped_coupons_json' => json_encode($built['skipped_coupons'], JSON_UNESCAPED_UNICODE),
                        'invalid_items_json' => json_encode($built['invalid_items'], JSON_UNESCAPED_UNICODE),
                        'invalid_staff_json' => json_encode($built['invalid_staff'], JSON_UNESCAPED_UNICODE),
                        'new_order_id' => 0,
                        'expire_time' => $now + self::DRAFT_TTL_SEC,
                        'update_time' => $now,
                    ]);
                    $draft = Db::name('store_order_reopen_draft')->where('id', (int)$draft['id'])->find();
                    $draft = is_array($draft) ? $draft : $draft->toArray();
                    return $this->formatDraftResponse($draft, $order, true);
                }

                return $this->formatDraftResponse($draft, $order, false);
            }

            $built = $this->buildSnapshotAndValidations($order);
            $token = $this->makeDraftToken($sourceOrderId);
            $row = [
                'draft_token' => $token,
                'source_order_id' => $sourceOrderId,
                'terminal_operation_id' => (int)($order['terminal_operation_id'] ?? 0),
                'store_id' => (int)($order['store_id'] ?? 0),
                'operator_type' => (string)($input['operator_type'] ?? ''),
                'operator_id' => (int)($input['operator_id'] ?? 0),
                'uid' => (int)($order['uid'] ?? 0),
                'state' => StoreOrderReopenDraft::STATE_DRAFT,
                'snapshot_json' => json_encode($built['snapshot'], JSON_UNESCAPED_UNICODE),
                'skipped_coupons_json' => json_encode($built['skipped_coupons'], JSON_UNESCAPED_UNICODE),
                'invalid_items_json' => json_encode($built['invalid_items'], JSON_UNESCAPED_UNICODE),
                'invalid_staff_json' => json_encode($built['invalid_staff'], JSON_UNESCAPED_UNICODE),
                'new_order_id' => 0,
                'expire_time' => $now + self::DRAFT_TTL_SEC,
                'create_time' => $now,
                'update_time' => $now,
            ];
            try {
                $id = (int)Db::name('store_order_reopen_draft')->insertGetId($row);
            } catch (\Throwable $e) {
                // 唯一约束冲突：并发下另一事务已插入，改为读取返回
                $again = $this->dao->getBySourceOrderId($sourceOrderId, true);
                if (!$again) {
                    throw $e;
                }
                $again = is_array($again) ? $again : $again->toArray();
                if ((int)$again['state'] === StoreOrderReopenDraft::STATE_CHECKED_OUT) {
                    OrderTerminalError::throw(OrderTerminalError::REOPEN_ALREADY_DONE);
                }
                return $this->formatDraftResponse($again, $order, false);
            }
            $row['id'] = $id;
            return $this->formatDraftResponse($row, $order, true);
        });
    }

    /**
     * 收银台按 token 加载草稿：重建购物车并重新计价（不复制旧价/旧支付）
     */
    public function loadDraft(string $token, array $input = []): array
    {
        $token = trim($token);
        if ($token === '') {
            OrderTerminalError::throw(OrderTerminalError::REOPEN_DRAFT_NOT_FOUND);
        }
        $storeScope = (int)($input['store_scope'] ?? 0);
        $staffId = (int)($input['staff_id'] ?? 0);

        return Db::transaction(function () use ($token, $storeScope, $staffId) {
            $draft = $this->dao->getByToken($token, true);
            if (!$draft) {
                OrderTerminalError::throw(OrderTerminalError::REOPEN_DRAFT_NOT_FOUND);
            }
            $draft = is_array($draft) ? $draft : $draft->toArray();
            $now = time();
            if ((int)$draft['state'] === StoreOrderReopenDraft::STATE_CHECKED_OUT) {
                OrderTerminalError::throw(OrderTerminalError::REOPEN_ALREADY_DONE);
            }
            // 仅未绑定草稿可过期；已绑定未支付单不得因过期拒绝加载
            if ((int)($draft['new_order_id'] ?? 0) === 0 && $this->isUnboundDraftExpired($draft, $now)) {
                Db::name('store_order_reopen_draft')->where('id', (int)$draft['id'])->update([
                    'state' => StoreOrderReopenDraft::STATE_EXPIRED,
                    'update_time' => $now,
                ]);
                OrderTerminalError::throw(OrderTerminalError::REOPEN_DRAFT_EXPIRED);
            }
            if ((int)($draft['new_order_id'] ?? 0) > 0
                && ((int)$draft['state'] === StoreOrderReopenDraft::STATE_EXPIRED
                    || ((int)$draft['expire_time'] > 0 && (int)$draft['expire_time'] < $now))) {
                Db::name('store_order_reopen_draft')->where('id', (int)$draft['id'])->update([
                    'state' => StoreOrderReopenDraft::STATE_LOADED,
                    'expire_time' => 0,
                    'update_time' => $now,
                ]);
                $draft['state'] = StoreOrderReopenDraft::STATE_LOADED;
                $draft['expire_time'] = 0;
            }
            if ($storeScope > 0 && (int)$draft['store_id'] !== $storeScope) {
                OrderTerminalError::throw(OrderTerminalError::STORE_SCOPE_DENIED);
            }

            $order = Db::name('store_order')->where('id', (int)$draft['source_order_id'])->find();
            if (!$order) {
                OrderTerminalError::throw(OrderTerminalError::ORDER_NOT_FOUND);
            }
            $order = is_array($order) ? $order : $order->toArray();
            $this->assertReopenAllowed($order, $storeScope);

            $snapshot = json_decode((string)($draft['snapshot_json'] ?? ''), true) ?: [];
            $prevCartIds = array_values(array_filter(array_map('intval', (array)($snapshot['loaded_cart_ids'] ?? []))));

            // 重新校验（商品/人员/券可能在草稿创建后变化）
            $recheck = $this->buildSnapshotAndValidations($order);
            $invalidItems = $recheck['invalid_items'];
            $invalidStaff = $recheck['invalid_staff'];
            $skippedCoupons = $recheck['skipped_coupons'];
            $snapshot = $recheck['snapshot'];

            $uid = (int)$draft['uid'];
            $storeId = (int)$draft['store_id'];
            if ($prevCartIds) {
                /** @var StoreCartServices $cartSvc */
                $cartSvc = app()->make(StoreCartServices::class);
                $cartSvc->removeUserCart($uid, $prevCartIds);
            }

            $loadStaffId = $staffId > 0 ? $staffId : (int)($snapshot['cashier_staff_id'] ?? 0);
            $added = $this->rebuildCashierCart($uid, $storeId, $loadStaffId, $snapshot, $invalidItems, $invalidStaff);
            $snapshot['loaded_cart_ids'] = $added['cart_ids'];
            $snapshot['loaded_at'] = $now;

            Db::name('store_order_reopen_draft')->where('id', (int)$draft['id'])->update([
                'state' => StoreOrderReopenDraft::STATE_LOADED,
                'snapshot_json' => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
                'skipped_coupons_json' => json_encode($skippedCoupons, JSON_UNESCAPED_UNICODE),
                'invalid_items_json' => json_encode($invalidItems, JSON_UNESCAPED_UNICODE),
                'invalid_staff_json' => json_encode($invalidStaff, JSON_UNESCAPED_UNICODE),
                'update_time' => $now,
            ]);

            $compute = [];
            $messages = $this->buildUserMessages($skippedCoupons, $invalidItems, $invalidStaff);
            if (!empty($added['cart_ids'])) {
                try {
                    /** @var CashierOrderServices $cashier */
                    $cashier = app()->make(CashierOrderServices::class);
                    $couponId = (int)($added['coupon_id'] ?? 0);
                    $compute = $cashier->computeOrder(
                        $uid,
                        $storeId,
                        $added['cart_ids'],
                        false,
                        $couponId > 0,
                        [],
                        $couponId,
                        false
                    );
                } catch (\Throwable $e) {
                    Log::warning('[reopen_compute] ' . $e->getMessage());
                    $messages[] = '购物车已加载，但当前计价未能完成，请在收银台确认商品后重试计价。';
                }
            } else {
                $messages[] = '没有可加载的有效商品，请根据待处理清单重新选择后再结账。';
            }

            $selectedProduct = array_values(array_filter(array_map(
                'intval',
                (array)($added['selected_product'] ?? $snapshot['selected_product'] ?? [])
            )));
            $sendAll = (array)($added['send_all'] ?? $snapshot['send_all'] ?? ['product' => [], 'coupon' => []]);

            return [
                'ok' => true,
                'draft_token' => (string)$draft['draft_token'],
                'source_order_id' => (int)$draft['source_order_id'],
                'uid' => $uid,
                'store_id' => $storeId,
                'state' => StoreOrderReopenDraft::STATE_LOADED,
                'shell_kind' => (string)($added['shell_kind'] ?? $snapshot['shell_kind'] ?? 'normal'),
                'cart_ids' => $added['cart_ids'],
                'coupon_id' => (int)($added['coupon_id'] ?? 0),
                'skipped_coupons' => $skippedCoupons,
                'invalid_items' => $invalidItems,
                'invalid_staff' => $invalidStaff,
                'messages' => $messages,
                'pending_item_action_required' => count($invalidItems) > 0,
                'yeji' => $snapshot['yeji'] ?? [],
                'service_yeji' => $snapshot['service_yeji'] ?? [],
                'send_all' => $sendAll,
                'give_ids' => $snapshot['give_ids'] ?? [],
                'selected_product' => $selectedProduct,
                'remark' => (string)($snapshot['remark'] ?? ''),
                'compute' => $compute,
                'expire_time' => (int)$draft['expire_time'],
                'copied_pay_type' => null,
                'copied_pay_price' => null,
                'copied_change_price' => null,
            ];
        });
    }

    /**
     * 创建新订单时写入来源并占位草稿（支付成功前防双开）
     * 锁顺序：新订单行 → 草稿行 → 原作废订单只读校验
     */
    public function attachOnCreateOrder(int $newOrderId, string $draftToken, int $storeId = 0): void
    {
        $draftToken = trim($draftToken);
        if ($draftToken === '' || $newOrderId <= 0) {
            return;
        }
        $runner = function () use ($newOrderId, $draftToken, $storeId) {
            $newOrder = Db::name('store_order')->where('id', $newOrderId)->lock(true)->find();
            if (!$newOrder) {
                OrderTerminalError::throw(OrderTerminalError::ORDER_NOT_FOUND);
            }
            $newOrder = is_array($newOrder) ? $newOrder : $newOrder->toArray();
            if ((int)($newOrder['paid'] ?? 0) === 1) {
                OrderTerminalError::throw(OrderTerminalError::REOPEN_ATTACH_ORDER_PAID);
            }
            if ($storeId > 0 && (int)($newOrder['store_id'] ?? 0) !== $storeId) {
                OrderTerminalError::throw(OrderTerminalError::STORE_SCOPE_DENIED);
            }

            $draft = $this->dao->getByToken($draftToken, true);
            if (!$draft) {
                OrderTerminalError::throw(OrderTerminalError::REOPEN_DRAFT_NOT_FOUND);
            }
            $draft = is_array($draft) ? $draft : $draft->toArray();
            $now = time();
            if ((int)$draft['state'] === StoreOrderReopenDraft::STATE_CHECKED_OUT) {
                OrderTerminalError::throw(OrderTerminalError::REOPEN_ALREADY_DONE);
            }
            // 未绑定才校验过期；已绑定未支付单允许继续 attach/支付
            if ((int)($draft['new_order_id'] ?? 0) === 0 && $this->isUnboundDraftExpired($draft, $now)) {
                OrderTerminalError::throw(OrderTerminalError::REOPEN_DRAFT_EXPIRED);
            }
            if (!in_array((int)$draft['state'], [
                StoreOrderReopenDraft::STATE_DRAFT,
                StoreOrderReopenDraft::STATE_LOADED,
                StoreOrderReopenDraft::STATE_EXPIRED,
            ], true)) {
                OrderTerminalError::throw(OrderTerminalError::REOPEN_ATTACH_DRAFT_STATE);
            }
            // 已绑定却误标 EXPIRED：允许继续绑定同一未支付单
            if ((int)$draft['state'] === StoreOrderReopenDraft::STATE_EXPIRED
                && (int)($draft['new_order_id'] ?? 0) === 0) {
                OrderTerminalError::throw(OrderTerminalError::REOPEN_DRAFT_EXPIRED);
            }
            if ((int)$draft['store_id'] !== (int)($newOrder['store_id'] ?? 0)) {
                OrderTerminalError::throw(OrderTerminalError::STORE_SCOPE_DENIED);
            }
            if ((int)$draft['uid'] !== (int)($newOrder['uid'] ?? 0)) {
                OrderTerminalError::throw(OrderTerminalError::REOPEN_ATTACH_UID_MISMATCH);
            }

            $sourceId = (int)$draft['source_order_id'];
            $existingSource = (int)($newOrder['reopen_source_order_id'] ?? 0);
            if ($existingSource > 0 && $existingSource !== $sourceId) {
                OrderTerminalError::throw(OrderTerminalError::REOPEN_ATTACH_SOURCE_CONFLICT);
            }

            $existingNew = (int)($draft['new_order_id'] ?? 0);
            if ($existingNew > 0 && $existingNew !== $newOrderId) {
                OrderTerminalError::throw(OrderTerminalError::REOPEN_ALREADY_BOUND);
            }

            $source = Db::name('store_order')->where('id', $sourceId)->find();
            if (!$source) {
                OrderTerminalError::throw(OrderTerminalError::ORDER_NOT_FOUND);
            }
            $source = is_array($source) ? $source : $source->toArray();
            $this->assertReopenAllowed($source, (int)($newOrder['store_id'] ?? 0));

            Db::name('store_order')->where('id', $newOrderId)->update([
                'reopen_source_order_id' => $sourceId,
            ]);
            if ($existingNew === 0) {
                $n = Db::name('store_order_reopen_draft')
                    ->where('id', (int)$draft['id'])
                    ->where('new_order_id', 0)
                    ->whereIn('state', [
                        StoreOrderReopenDraft::STATE_DRAFT,
                        StoreOrderReopenDraft::STATE_LOADED,
                        StoreOrderReopenDraft::STATE_EXPIRED,
                    ])
                    ->update([
                        'new_order_id' => $newOrderId,
                        'state' => StoreOrderReopenDraft::STATE_LOADED,
                        'expire_time' => 0, // 绑定后取消自动过期
                        'update_time' => $now,
                    ]);
                if ($n <= 0) {
                    OrderTerminalError::throw(OrderTerminalError::REOPEN_ALREADY_BOUND);
                }
            } else {
                // 同单重入：确保不过期
                Db::name('store_order_reopen_draft')->where('id', (int)$draft['id'])->update([
                    'expire_time' => 0,
                    'state' => StoreOrderReopenDraft::STATE_LOADED,
                    'update_time' => $now,
                ]);
            }
        };
        if ($this->isInDbTransaction()) {
            $runner();
        } else {
            Db::transaction($runner);
        }
    }

    /**
     * 支付成功同事务关闭草稿（幂等）。调用方须已持有新订单行锁，或本方法自行加锁。
     * 锁顺序：新订单行 → 草稿行。
     * reopen_source_order_id>0 时必须找到匹配草稿，不得静默跳过。
     */
    public function bindOnPaySuccess(int $newOrderId): void
    {
        if ($newOrderId <= 0) {
            return;
        }
        $runner = function () use ($newOrderId) {
            $order = Db::name('store_order')->where('id', $newOrderId)->lock(true)->find();
            if (!$order) {
                return;
            }
            $order = is_array($order) ? $order : $order->toArray();
            if ((int)($order['paid'] ?? 0) !== 1) {
                return;
            }

            $sourceId = (int)($order['reopen_source_order_id'] ?? 0);
            $draft = Db::name('store_order_reopen_draft')
                ->where('new_order_id', $newOrderId)
                ->lock(true)
                ->find();

            if (!$draft && $sourceId > 0) {
                $draft = Db::name('store_order_reopen_draft')
                    ->where('source_order_id', $sourceId)
                    ->where(function ($q) use ($newOrderId) {
                        $q->where('new_order_id', $newOrderId)->whereOr('new_order_id', 0);
                    })
                    ->lock(true)
                    ->find();
            }

            // 非重开订单：无来源且无草稿，正常返回
            if (!$draft && $sourceId <= 0) {
                return;
            }
            if (!$draft) {
                OrderTerminalError::throw(OrderTerminalError::REOPEN_BIND_FAILED, 'draft_missing source=' . $sourceId);
            }
            $draft = is_array($draft) ? $draft : $draft->toArray();
            $draftSource = (int)$draft['source_order_id'];
            $draftNew = (int)($draft['new_order_id'] ?? 0);

            if ($sourceId > 0 && $draftSource !== $sourceId) {
                OrderTerminalError::throw(OrderTerminalError::REOPEN_BIND_FAILED, 'source_conflict');
            }
            if ($draftNew > 0 && $draftNew !== $newOrderId) {
                OrderTerminalError::throw(OrderTerminalError::REOPEN_BIND_FAILED, 'new_order_conflict');
            }
            if ($sourceId <= 0) {
                Db::name('store_order')->where('id', $newOrderId)->update([
                    'reopen_source_order_id' => $draftSource,
                ]);
                $sourceId = $draftSource;
            }

            if ((int)$draft['state'] === StoreOrderReopenDraft::STATE_CHECKED_OUT
                && $draftNew === $newOrderId) {
                $this->assertNoOtherActiveDraft($sourceId, (int)$draft['id']);
                return;
            }

            $now = time();
            // 含误标 EXPIRED：支付成功仍应收口为 CHECKED_OUT
            $n = Db::name('store_order_reopen_draft')
                ->where('id', (int)$draft['id'])
                ->where('source_order_id', $sourceId)
                ->where(function ($q) use ($newOrderId) {
                    $q->where('new_order_id', $newOrderId)->whereOr('new_order_id', 0);
                })
                ->whereIn('state', [
                    StoreOrderReopenDraft::STATE_DRAFT,
                    StoreOrderReopenDraft::STATE_LOADED,
                    StoreOrderReopenDraft::STATE_EXPIRED,
                ])
                ->update([
                    'state' => StoreOrderReopenDraft::STATE_CHECKED_OUT,
                    'new_order_id' => $newOrderId,
                    'expire_time' => 0,
                    'update_time' => $now,
                ]);
            if ($n <= 0) {
                $again = Db::name('store_order_reopen_draft')->where('id', (int)$draft['id'])->find();
                $again = $again ? (is_array($again) ? $again : $again->toArray()) : [];
                if ((int)($again['state'] ?? -1) === StoreOrderReopenDraft::STATE_CHECKED_OUT
                    && (int)($again['new_order_id'] ?? 0) === $newOrderId
                    && (int)($again['source_order_id'] ?? 0) === $sourceId) {
                    $this->assertNoOtherActiveDraft($sourceId, (int)$draft['id']);
                    return;
                }
                OrderTerminalError::throw(OrderTerminalError::REOPEN_BIND_FAILED, 'update_rows=0');
            }
            $this->assertNoOtherActiveDraft($sourceId, (int)$draft['id']);
        };
        if ($this->isInDbTransaction()) {
            $runner();
        } else {
            Db::transaction($runner);
        }
    }

    protected function isInDbTransaction(): bool
    {
        try {
            $pdo = Db::getPdo();
            return $pdo && $pdo->inTransaction();
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function assertReopenAllowed(array $order, int $storeScope = 0): void
    {
        if ((int)($order['is_del'] ?? 0) === 1 || (int)($order['is_system_del'] ?? 0) === 1) {
            OrderTerminalError::throw(OrderTerminalError::ORDER_DELETED);
        }
        if ($storeScope > 0 && (int)($order['store_id'] ?? 0) !== $storeScope) {
            OrderTerminalError::throw(OrderTerminalError::STORE_SCOPE_DENIED);
        }
        if ((int)($order['order_type'] ?? 0) === 1) {
            OrderTerminalError::throw(OrderTerminalError::REOPEN_RECHARGE_DENIED);
        }
        if (!empty($order['is_debt_repay'])) {
            OrderTerminalError::throw(OrderTerminalError::REOPEN_DEBT_DENIED);
        }
        if ((int)($order['terminal_action'] ?? 0) !== StoreOrderTerminalOperation::ACTION_VOID) {
            OrderTerminalError::throw(OrderTerminalError::REOPEN_NOT_VOID);
        }
        /** @var StoreOrderTerminalOperationServices $terminal */
        $terminal = app()->make(StoreOrderTerminalOperationServices::class);
        if (!$terminal->canReopen($order)) {
            OrderTerminalError::throw(OrderTerminalError::REOPEN_NOT_ALLOWED);
        }
    }

    /**
     * 已存在已支付的重开子单则禁止再次重开
     */
    protected function assertNotAlreadyReopenedPaid(int $sourceOrderId): void
    {
        $paidChild = (int)Db::name('store_order')
            ->where('reopen_source_order_id', $sourceOrderId)
            ->where('paid', 1)
            ->where('is_del', 0)
            ->value('id');
        if ($paidChild > 0) {
            OrderTerminalError::throw(OrderTerminalError::REOPEN_ALREADY_DONE);
        }
    }

    /** 未绑定草稿是否过期（绑定后 new_order_id>0 永不过期） */
    protected function isUnboundDraftExpired(array $draft, int $now): bool
    {
        if ((int)($draft['new_order_id'] ?? 0) > 0) {
            return false;
        }
        if ((int)($draft['state'] ?? 0) === StoreOrderReopenDraft::STATE_EXPIRED) {
            return true;
        }
        $expire = (int)($draft['expire_time'] ?? 0);
        return $expire > 0 && $expire < $now;
    }

    protected function formatPendingPayDraftResponse(array $draft, array $order, bool $created): array
    {
        $resp = $this->formatDraftResponse($draft, $order, $created);
        $newOid = (int)($draft['new_order_id'] ?? 0);
        $resp['pending_pay_order_id'] = $newOid;
        $resp['pending_pay'] = true;
        $resp['messages'][] = '已有未支付的重开订单，请到收银台继续支付，不要重复开单。';
        return $resp;
    }

    protected function closeDraftCheckedOut(int $draftId, int $newOrderId, int $sourceOrderId, int $now): void
    {
        Db::name('store_order_reopen_draft')->where('id', $draftId)->update([
            'state' => StoreOrderReopenDraft::STATE_CHECKED_OUT,
            'new_order_id' => $newOrderId,
            'expire_time' => 0,
            'update_time' => $now,
        ]);
        $this->assertNoOtherActiveDraft($sourceOrderId, $draftId);
    }

    /**
     * 同一原单不得再存在 DRAFT/LOADED 活动草稿
     */
    protected function assertNoOtherActiveDraft(int $sourceOrderId, int $keepDraftId): void
    {
        $cnt = (int)Db::name('store_order_reopen_draft')
            ->where('source_order_id', $sourceOrderId)
            ->where('id', '<>', $keepDraftId)
            ->whereIn('state', [
                StoreOrderReopenDraft::STATE_DRAFT,
                StoreOrderReopenDraft::STATE_LOADED,
            ])
            ->count();
        if ($cnt > 0) {
            // 唯一约束下理论上不应发生；若历史脏数据则强行过期
            Db::name('store_order_reopen_draft')
                ->where('source_order_id', $sourceOrderId)
                ->where('id', '<>', $keepDraftId)
                ->whereIn('state', [
                    StoreOrderReopenDraft::STATE_DRAFT,
                    StoreOrderReopenDraft::STATE_LOADED,
                ])
                ->update([
                    'state' => StoreOrderReopenDraft::STATE_EXPIRED,
                    'update_time' => time(),
                ]);
        }
    }

    protected function formatDraftResponse(array $draft, array $order, bool $created): array
    {
        return [
            'ok' => true,
            'created' => $created,
            'draft_token' => (string)$draft['draft_token'],
            'source_order_id' => (int)$draft['source_order_id'],
            'uid' => (int)$draft['uid'],
            'store_id' => (int)$draft['store_id'],
            'state' => (int)$draft['state'],
            'expire_time' => (int)$draft['expire_time'],
            'new_order_id' => (int)($draft['new_order_id'] ?? 0),
            'skipped_coupons' => json_decode((string)($draft['skipped_coupons_json'] ?? ''), true) ?: [],
            'invalid_items' => json_decode((string)($draft['invalid_items_json'] ?? ''), true) ?: [],
            'invalid_staff' => json_decode((string)($draft['invalid_staff_json'] ?? ''), true) ?: [],
            'messages' => $this->buildUserMessages(
                json_decode((string)($draft['skipped_coupons_json'] ?? ''), true) ?: [],
                json_decode((string)($draft['invalid_items_json'] ?? ''), true) ?: [],
                json_decode((string)($draft['invalid_staff_json'] ?? ''), true) ?: []
            ),
            'can_reopen' => true,
            'order_type' => (int)($order['type'] ?? 0),
            'product_type' => (int)($order['product_type'] ?? 0),
        ];
    }

    /**
     * 统一解析 selected_product：数组 / JSON 数组 / 逗号字符串 / 单个数字
     * @return int[]
     */
    public function parseSelectedProductIds($raw): array
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return [];
        }
        if (is_array($raw)) {
            return array_values(array_unique(array_filter(array_map('intval', $raw))));
        }
        if (is_int($raw) || is_float($raw)) {
            $id = (int)$raw;
            return $id > 0 ? [$id] : [];
        }
        if (!is_string($raw)) {
            return [];
        }
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        if ($raw[0] === '[') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return array_values(array_unique(array_filter(array_map('intval', $decoded))));
            }
        }
        if (strpos($raw, ',') !== false) {
            return array_values(array_unique(array_filter(array_map('intval', explode(',', $raw)))));
        }
        if (ctype_digit($raw) || preg_match('/^-?\d+$/', $raw)) {
            $id = (int)$raw;
            return $id > 0 ? [$id] : [];
        }
        return [];
    }

    /**
     * @return array{snapshot:array,skipped_coupons:array,invalid_items:array,invalid_staff:array}
     */
    protected function buildSnapshotAndValidations(array $order): array
    {
        $oid = (int)$order['id'];
        $cartRows = Db::name('store_order_cart_info')->where('oid', $oid)->select()->toArray();
        $buyLines = [];
        $cardInnerLines = [];
        $invalidItems = [];
        /** @var StoreCartServices $cartSvc */
        $cartSvc = app()->make(StoreCartServices::class);

        foreach ($cartRows as $row) {
            $info = is_string($row['cart_info'] ?? null)
                ? (json_decode((string)$row['cart_info'], true) ?: [])
                : (array)($row['cart_info'] ?? []);
            $productId = (int)($row['product_id'] ?? $info['product_id'] ?? 0);
            $sku = (string)($row['sku_unique'] ?? $info['product_attr_unique'] ?? '');
            $cartNum = (int)($row['cart_num'] ?? $info['cart_num'] ?? 1);
            $cartType = (int)($row['cart_type'] ?? $info['cart_type'] ?? 0);
            $productType = (int)($row['product_type'] ?? $info['product_type'] ?? ($info['productInfo']['product_type'] ?? 0));
            $name = (string)($info['productInfo']['store_name'] ?? ('商品' . $productId));
            $lineStaff = (int)($info['staff_id'] ?? 0);
            $serviceStaff = (int)($info['service_staff_id'] ?? 0);
            $line = [
                'product_id' => $productId,
                'unique' => $sku,
                'cart_num' => max(1, $cartNum),
                'cart_type' => $cartType,
                'product_type' => $productType,
                'is_gift' => (int)($row['is_gift'] ?? 0) === 1 || $cartType === 1,
                'staff_id' => $lineStaff,
                'service_staff_id' => $serviceStaff,
                'name' => $name,
            ];

            // 卡内项目：只用于恢复 selected_product / 数量，不得进购物车 lines
            if ($cartType === 2) {
                $invalid = $this->checkProductSku($productId, $sku, $name);
                if ($invalid !== null) {
                    $invalid['reason'] = '卡内所选项目已失效，请重新选择后再结账。';
                    $invalidItems[] = $invalid;
                    continue;
                }
                $cardInnerLines[] = $line;
                continue;
            }

            $invalid = $this->checkProductSku($productId, $sku, $name);
            if ($invalid !== null) {
                $invalidItems[] = $invalid;
                continue;
            }
            $buyLines[] = $line;
        }

        // 壳类型按原单全部行识别（含失效壳），避免失效时误判为 normal 而把卡内项加进购物车
        $shellKind = 'normal';
        $shellProductId = 0;
        foreach ($cartRows as $row) {
            $ct = (int)($row['cart_type'] ?? 0);
            if ($ct !== 0) {
                continue;
            }
            $shellProductId = (int)($row['product_id'] ?? 0);
            if ($shellProductId <= 0) {
                continue;
            }
            if ($cartSvc->isGiftProjectShellByProductId($shellProductId)) {
                $shellKind = 'gift_project';
                break;
            }
            if ($this->isCustomCardShellProductId($shellProductId)) {
                $shellKind = 'custom_card';
                break;
            }
            $pt = (int)($row['product_type'] ?? 0);
            if ($pt === 5 || (int)($order['type'] ?? 0) === 11 || (int)($order['product_type'] ?? 0) === 5) {
                $shellKind = 'card';
                break;
            }
        }

        $invalidStaff = [];
        $yeji = $this->decodeJsonField($order['yeji'] ?? null);
        $serviceYeji = $this->decodeJsonField($order['service_yeji'] ?? null);
        $validYeji = [];
        foreach ($yeji as $row) {
            $sid = (int)($row['staff_id'] ?? $row['id'] ?? 0);
            if ($sid <= 0) {
                continue;
            }
            if ($this->isStaffActive($sid, (int)$order['store_id'])) {
                $validYeji[] = $row;
            } else {
                $invalidStaff[] = [
                    'staff_id' => $sid,
                    'role' => 'sales',
                    'name' => (string)($row['staff_name'] ?? $row['name'] ?? ''),
                    'reason' => '销售人员已离职或停用，请重新选择。',
                ];
            }
        }
        $validServiceYeji = [];
        foreach ($serviceYeji as $row) {
            $sid = (int)($row['staff_id'] ?? $row['id'] ?? 0);
            if ($sid <= 0) {
                continue;
            }
            if ($this->isStaffActive($sid, (int)$order['store_id'])) {
                $validServiceYeji[] = $row;
            } else {
                $invalidStaff[] = [
                    'staff_id' => $sid,
                    'role' => 'artisan',
                    'name' => (string)($row['staff_name'] ?? $row['name'] ?? ''),
                    'reason' => '手艺人已离职或停用，请重新选择。',
                ];
            }
        }

        foreach ($buyLines as &$line) {
            if ($line['staff_id'] > 0 && !$this->isStaffActive($line['staff_id'], (int)$order['store_id'])) {
                $invalidStaff[] = [
                    'staff_id' => $line['staff_id'],
                    'role' => 'sales',
                    'name' => '',
                    'reason' => '销售人员已离职或停用，请重新选择。',
                    'product_id' => $line['product_id'],
                ];
                $line['staff_id'] = 0;
            }
            if ($line['service_staff_id'] > 0 && !$this->isStaffActive($line['service_staff_id'], (int)$order['store_id'])) {
                $invalidStaff[] = [
                    'staff_id' => $line['service_staff_id'],
                    'role' => 'artisan',
                    'name' => '',
                    'reason' => '手艺人已离职或停用，请重新选择。',
                    'product_id' => $line['product_id'],
                ];
                $line['service_staff_id'] = 0;
            }
        }
        unset($line);

        $skippedCoupons = [];
        $couponId = (int)($order['coupon_id'] ?? 0);
        $validCouponId = 0;
        if ($couponId > 0) {
            $cu = Db::name('store_coupon_user')->where('id', $couponId)->find();
            if (!$cu) {
                $skippedCoupons[] = [
                    'coupon_user_id' => $couponId,
                    'reason' => '原优惠券不存在，已跳过，结账金额将按当前规则重新计算。',
                ];
            } else {
                $cu = is_array($cu) ? $cu : $cu->toArray();
                $status = (int)($cu['status'] ?? 0);
                $isFail = (int)($cu['is_fail'] ?? 0);
                $end = (int)($cu['end_time'] ?? 0);
                if ($isFail === 1 || $status !== 0 || ($end > 0 && $end < time())
                    || (int)($cu['uid'] ?? 0) !== (int)$order['uid']) {
                    $skippedCoupons[] = [
                        'coupon_user_id' => $couponId,
                        'coupon_title' => (string)($cu['coupon_title'] ?? ''),
                        'reason' => '原优惠券已失效、已使用或不适用，已跳过，结账金额将按当前规则重新计算。',
                    ];
                } else {
                    $validCouponId = $couponId;
                }
            }
        }

        $selectedProduct = $this->parseSelectedProductIds($order['selected_product'] ?? null);
        if (!$selectedProduct && $cardInnerLines) {
            foreach ($cardInnerLines as $il) {
                $selectedProduct[] = (int)$il['product_id'];
            }
            $selectedProduct = array_values(array_unique(array_filter($selectedProduct)));
        }

        $sendAll = $this->decodeJsonField($order['send_all'] ?? null);
        if (!isset($sendAll['product']) || !is_array($sendAll['product'])) {
            $sendAll = ['product' => [], 'coupon' => is_array($sendAll['coupon'] ?? null) ? $sendAll['coupon'] : []];
        }
        // 卡/赠送壳：用卡内行数量补齐 send_all，供赠送项目壳结账展开
        if (in_array($shellKind, ['gift_project', 'custom_card'], true) && $cardInnerLines) {
            $byId = [];
            foreach ($cardInnerLines as $il) {
                $pid = (int)$il['product_id'];
                if ($pid <= 0) {
                    continue;
                }
                if (!isset($byId[$pid])) {
                    $byId[$pid] = [
                        'id' => $pid,
                        'num' => (int)$il['cart_num'],
                        'product_type' => (int)$il['product_type'],
                        'store_name' => (string)$il['name'],
                    ];
                } else {
                    $byId[$pid]['num'] += (int)$il['cart_num'];
                }
            }
            if (empty($sendAll['product'])) {
                $sendAll['product'] = array_values($byId);
            }
        }

        $snapshot = [
            'source_order_id' => $oid,
            'uid' => (int)$order['uid'],
            'store_id' => (int)$order['store_id'],
            'order_type' => (int)($order['type'] ?? 0),
            'product_type' => (int)($order['product_type'] ?? 0),
            'shell_kind' => $shellKind,
            'shell_product_id' => $shellProductId,
            'cashier_staff_id' => (int)($order['staff_id'] ?? 0),
            // 仅可购买行（含普通赠送 cart_type=1）；不含 cart_type=2
            'lines' => $buyLines,
            'card_inner_lines' => $cardInnerLines,
            'yeji' => $validYeji,
            'service_yeji' => $validServiceYeji,
            'send_all' => $sendAll,
            'give_ids' => [],
            'selected_product' => $selectedProduct,
            'coupon_id' => $validCouponId,
            'remark' => (string)($order['mark'] ?? $order['remark'] ?? ''),
            'forbid_copy' => ['pay_type', 'pay_price', 'change_price', 'coupon_price', 'yue_pay_price', 'cash_pay_price'],
        ];

        return [
            'snapshot' => $snapshot,
            'skipped_coupons' => $skippedCoupons,
            'invalid_items' => $invalidItems,
            'invalid_staff' => $this->uniqueStaffList($invalidStaff),
        ];
    }

    /**
     * @param array $invalidItems 引用：加载失败的也会追加
     */
    protected function rebuildCashierCart(
        int $uid,
        int $storeId,
        int $staffId,
        array $snapshot,
        array &$invalidItems,
        array $invalidStaff
    ): array {
        /** @var StoreCartServices $cartSvc */
        $cartSvc = app()->make(StoreCartServices::class);
        /** @var CashierOrderServices $cashier */
        $cashier = app()->make(CashierOrderServices::class);
        $cartIds = [];
        $fallbackStaff = $staffId > 0 ? $staffId : 0;
        $shellKind = (string)($snapshot['shell_kind'] ?? 'normal');

        foreach ((array)($snapshot['lines'] ?? []) as $line) {
            $cartType = (int)($line['cart_type'] ?? 0);
            // 卡内项目禁止作为独立购物车商品
            if ($cartType === 2) {
                continue;
            }
            // 卡项/定制卡/赠送项目卡：只加壳行 cart_type=0
            if (in_array($shellKind, ['card', 'custom_card', 'gift_project'], true) && $cartType !== 0) {
                continue;
            }
            $productId = (int)($line['product_id'] ?? 0);
            $unique = (string)($line['unique'] ?? '');
            $num = max(1, (int)($line['cart_num'] ?? 1));
            $lineStaff = (int)($line['staff_id'] ?? 0);
            if ($lineStaff <= 0) {
                $lineStaff = $fallbackStaff;
            }
            $serviceStaff = (int)($line['service_staff_id'] ?? 0);
            try {
                $cartSvc->setItem('store_id', $storeId)
                    ->setItem('staff_id', $lineStaff)
                    ->setItem('tourist_uid', '')
                    ->setItem('cart_type', $cartType)
                    ->setItem('is_check_reservation_time', 0);
                if ($serviceStaff > 0) {
                    $cartSvc->setItem('service_staff_id', $serviceStaff);
                }
                if (!empty($line['is_gift']) || $cartType === 1) {
                    $cartSvc->setItem('price', 0)
                        ->setItem('allow_explicit_zero_price', 1)
                        ->setItem('is_send_gift', 1);
                }
                [$cartId] = $cartSvc->setCart($uid, $productId, $num, $unique, 0, false, 0, 0);
                $cartSvc->reset();
                if ((int)$cartId > 0) {
                    $cartIds[] = (int)$cartId;
                }
            } catch (\Throwable $e) {
                $cartSvc->reset();
                $invalidItems[] = [
                    'product_id' => $productId,
                    'unique' => $unique,
                    'name' => (string)($line['name'] ?? ''),
                    'reason' => '商品无法加入购物车：' . $this->plainCartError($e->getMessage()),
                ];
            }
        }

        // 普通单的 send_all 赠送可走 addSend；卡壳/定制/赠送项目壳由结账 selected_product 展开，禁止把卡内项加进购物车
        $sendAll = (array)($snapshot['send_all'] ?? []);
        if ($shellKind === 'normal' && !empty($sendAll['product']) && is_array($sendAll['product'])) {
            try {
                $giftIds = $cashier->addSend($sendAll, $uid, $storeId);
                foreach ((array)$giftIds as $gid) {
                    if ((int)$gid > 0) {
                        $cartIds[] = (int)$gid;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('[reopen_add_send] ' . $e->getMessage());
            }
        }

        return [
            'cart_ids' => array_values(array_unique($cartIds)),
            'coupon_id' => (int)($snapshot['coupon_id'] ?? 0),
            'selected_product' => array_values(array_filter(array_map('intval', (array)($snapshot['selected_product'] ?? [])))),
            'send_all' => $sendAll,
            'shell_kind' => $shellKind,
        ];
    }

    protected function isCustomCardShellProductId(int $productId): bool
    {
        if ($productId <= 0) {
            return false;
        }
        if ($productId === 8154) {
            return true;
        }
        $p = Db::name('store_product')->where('id', $productId)->field('id,pid,store_name')->find();
        if (!$p) {
            return false;
        }
        return (int)($p['pid'] ?? 0) === 8154
            && mb_strpos((string)($p['store_name'] ?? ''), '定制卡') !== false;
    }

    protected function checkProductSku(int $productId, string $sku, string $name): ?array
    {
        if ($productId <= 0) {
            return [
                'product_id' => $productId,
                'unique' => $sku,
                'name' => $name,
                'reason' => '商品不存在，请删除或重新选择后再结账。',
            ];
        }
        $product = Db::name('store_product')->where('id', $productId)->find();
        if (!$product || (int)($product['is_del'] ?? 0) === 1) {
            return [
                'product_id' => $productId,
                'unique' => $sku,
                'name' => $name,
                'reason' => '商品已删除或不存在，请删除或重新选择后再结账。',
            ];
        }
        if ((int)($product['is_show'] ?? 0) !== 1 || (int)($product['is_verify'] ?? 0) !== 1) {
            return [
                'product_id' => $productId,
                'unique' => $sku,
                'name' => (string)($product['store_name'] ?? $name),
                'reason' => '商品已下架或未通过审核，请删除或重新选择后再结账。',
            ];
        }
        if ($sku !== '') {
            $attr = Db::name('store_product_attr_value')
                ->where('product_id', $productId)
                ->where('unique', $sku)
                ->where('type', 0)
                ->find();
            if (!$attr) {
                return [
                    'product_id' => $productId,
                    'unique' => $sku,
                    'name' => (string)($product['store_name'] ?? $name),
                    'reason' => '商品规格已失效，请重新选择规格后再结账。',
                ];
            }
        }
        return null;
    }

    protected function isStaffActive(int $staffId, int $storeId): bool
    {
        if ($staffId <= 0) {
            return false;
        }
        $q = Db::name('system_store_staff')
            ->where('id', $staffId)
            ->where('is_del', 0)
            ->where('status', 1);
        if ($storeId > 0) {
            $q->where('store_id', $storeId);
        }
        return (bool)$q->find();
    }

    protected function decodeJsonField($raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    protected function uniqueStaffList(array $list): array
    {
        $seen = [];
        $out = [];
        foreach ($list as $row) {
            $key = ((int)($row['staff_id'] ?? 0)) . ':' . ((string)($row['role'] ?? ''));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = 1;
            $out[] = $row;
        }
        return $out;
    }

    protected function buildUserMessages(array $skippedCoupons, array $invalidItems, array $invalidStaff): array
    {
        $messages = [];
        foreach ($skippedCoupons as $c) {
            $messages[] = (string)($c['reason'] ?? '原优惠券已跳过，金额将重新计算。');
        }
        if ($invalidItems) {
            $messages[] = '有 ' . count($invalidItems) . ' 个商品或规格需要处理，请查看待处理清单后删除或重新选择。';
        }
        foreach ($invalidStaff as $s) {
            $messages[] = (string)($s['reason'] ?? '销售或手艺人需重新选择。');
        }
        return array_values(array_unique($messages));
    }

    protected function plainCartError(string $msg): string
    {
        $msg = trim($msg);
        if ($msg === '') {
            return '请重新选择该商品。';
        }
        // 去掉技术细节
        if (preg_match('/SQLSTATE|stack|Exception/i', $msg)) {
            return '请重新选择该商品。';
        }
        return mb_substr($msg, 0, 80);
    }

    protected function makeDraftToken(int $sourceOrderId): string
    {
        return 'RO' . $sourceOrderId . date('YmdHis') . substr(bin2hex(random_bytes(8)), 0, 12);
    }
}
