<?php

namespace app\services\cashier\v3\cashier;

use app\services\cashier\v3\card\CashierV3CardRuleEntitlementAuthorityServices;
use app\services\cashier\v3\card\CashierV3CardSaleActualAmountServices;
use app\services\cashier\v3\order\CashierV3CardRefundQuotaPolicy;
use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3CrossStoreEntitlementPolicy;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResourceScope;
use app\services\cashier\v3\CashierV3ResourceVersionServices;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\order\store\WriteOffOrderServices;
use think\facade\Db;

/**
 * 收银台“增加卡内项目”的权威权益投影与草稿行复核。
 */
final class CashierV3EntitlementProjectionServices
{
    private const MAX_SELECTED_LINES = 50;
    private const CUSTOM_CARD_SHELL_PRODUCT_ID = 8154;

    /** @var CashierV3CashierReadinessGuard */
    private $readiness;
    /** @var CashierV3CashierWorkspaceServices */
    private $workspace;
    /** @var CashierV3EntitlementResourceVersionProvider */
    private $provider;
    /** @var CashierV3ResourceVersionServices */
    private $versions;
    /** @var CashierV3CardRuleEntitlementAuthorityServices */
    private $cardRules;

    public function __construct(
        CashierV3CashierReadinessGuard $readiness,
        CashierV3CashierWorkspaceServices $workspace,
        CashierV3EntitlementResourceVersionProvider $provider,
        CashierV3ResourceVersionServices $versions,
        ?CashierV3CardRuleEntitlementAuthorityServices $cardRules = null
    ) {
        $this->readiness = $readiness;
        $this->workspace = $workspace;
        $this->provider = $provider;
        $this->versions = $versions;
        $this->cardRules = $cardRules ?: new CashierV3CardRuleEntitlementAuthorityServices();
    }

    /**
     * 预约项目选择器复用“使用权益”的完整可用性投影。
     *
     * 这里只返回仍有物理剩余次数的跨店权益来源；欠款、有效期和卡状态不会
     * 把项目静默删除，而是通过 selectable/disabledReason 明确告诉预约端。
     * 该读取不签发版本、不写收银草稿，也不占用或扣减任何权益。
     */
    public function reservationSources(
        int $memberId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        $this->readiness->assertReady();
        $snapshot = $this->loadRows(
            $memberId,
            $operatorScope,
            $dataScope->tenantId(),
            false,
            null,
            null,
            true,
            true,
            false,
            false,
            false
        );
        return $this->buildSources($snapshot, [], [], $dataScope->tenantId(), false, true);
    }

    /** @return array{entitlementSelector:array,versions:array} */
    public function openSelector(
        array $payload,
        string $stateContextId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        $this->readiness->assertReady();
        $memberId = $this->positiveId($payload['memberId'] ?? null, 'memberId');
        $requestId = trim((string)($payload['selectorRequestId'] ?? ''));
        if (!$this->validSelectorRequestId($requestId)) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ENTITLEMENT_SELECTOR_REQUEST_REQUIRED,
                '权益选择请求无效，请重新打开。',
                CashierV3ResultCode::STATUS_FAILED
            );
        }
        // 选择器同时承载“有效卡 / 全部”两个视图，因此打开时必须返回
        // 当前会员全部未删除权益。是否可加入购物车由投影的 selectable
        // 明确表达，不能在查询阶段删掉已用完、已到期或已停用的卡。
        $cardOperationMode = trim((string)($payload['cardOperationMode'] ?? ''));
        $includeDisabledCards = true;
        $includeUnavailableCards = true;
        // The selector stays read-only with respect to the cashier draft.  It
        // does, however, publish the current card-holder resource versions:
        // direct card operations (notably project replacement) submit from
        // this screen and must never guess a version from a display row.
        $snapshot = $this->loadRows(
            $memberId,
            $operatorScope,
            $operatorScope->tenantId(),
            false,
            null,
            null,
            $includeDisabledCards,
            true,
            $includeUnavailableCards,
            false,
            true
        );
        // 这个选择器既展示可核销项目，也承担卡延期、转让、停用和启用的
        // 来源卡选择。后四种操作可以面对没有项目明细的时间卡，因此不能
        // 只为“有购物车明细”的 holder 签发版本；否则页面能选中卡，却在
        // 提交时因为没有既有资源版本被 Gateway 拒绝。仍只同步存在有效
        // 来源销售单的 holder，避免旧孤儿 holder 影响同会员的可用卡。
        $ordersById = [];
        $holderIdsForProjection = [];
        foreach ((array)($snapshot['orders'] ?? []) as $order) {
            $orderId = (int)($order['id'] ?? 0);
            if ($orderId > 0 && $this->sourceOrderUnavailableReason($order) === '') {
                $ordersById[$orderId] = true;
            }
        }
        foreach ((array)($snapshot['holders'] ?? []) as $holder) {
            $orderId = (int)($holder['oid'] ?? 0);
            $holderId = (int)($holder['id'] ?? 0);
            if ($holderId > 0 && isset($ordersById[$orderId])) {
                $holderIdsForProjection[$holderId] = true;
            }
        }
        $holderVersions = Db::transaction(function () use ($holderIdsForProjection, $operatorScope, $dataScope, $snapshot, $ordersById): array {
            $versions = [];
            foreach (array_keys($holderIdsForProjection) as $holderId) {
                $versions[$holderId] = $this->provider->synchronizeProjectionVersion(
                    'card_holder',
                    (string)$holderId,
                    $operatorScope,
                    $dataScope
                );
            }
            // 修复旧替换目标没有项目版本的存量情况。只同步当前有效来源单
            // 上尚有余次的操作生成权益；不写业务事实，不接受客户端伪造版本。
            foreach ((array)($snapshot['carts'] ?? []) as $cart) {
                $detailSnapshot = json_decode((string)($cart['cart_info'] ?? ''), true) ?: [];
                if (!isset($ordersById[(int)($cart['oid'] ?? 0)])
                    || (int)($cart['write_surplus_times'] ?? 0) <= 0
                    || !CashierV3EntitlementActualAmountAllocator::usesIndependentAmountSnapshot($detailSnapshot)) {
                    continue;
                }
                $this->provider->synchronizeProjectionVersion(
                    'member_benefit_pool', (string)$cart['id'], $operatorScope, $dataScope, true
                );
            }
            return $versions;
        });
        $sources = $this->buildSources($snapshot, [], [], $operatorScope->tenantId(), false, true);
        // 历史项目赠送没有卡实例；以导入明细自身作为独立权益展示，不补造卡。
        // 最终结账仍须在事务内锁定这条明细并复核剩余次数。
        $sources = array_merge($sources, $this->legacyGiftSources($memberId, $operatorScope));
        // 展示行不能充当并发版本来源。选择器把本次读取时签发的 holder
        // 版本作为命令上下文返回，前端仅从中挑选用户最终选中的来源卡；
        // Gateway 仍会在写事务内核对该版本并拒绝任何过期操作。
        $commandContexts = array_map(static function (int $version, int $holderId): array {
            return [
                'kind' => 'card_holder',
                'id' => (string)$holderId,
                'expectedVersion' => $version,
            ];
        }, $holderVersions, array_keys($holderVersions));
        return [
            'entitlementSelector' => [
                'ready' => true,
                'selectorRequestId' => $requestId,
                'selectorToken' => hash('sha256', implode("\0", [
                    'cashier-v3-entitlement-display-v1',
                    (string)$memberId,
                    $requestId,
                ])),
                'member' => $this->publicMember($snapshot['member']),
                'sources' => $sources,
                'commandContexts' => $commandContexts,
                'dataAsOf' => date('c'),
            ],
            'versions' => array_map(static function (int $version, int $holderId): array {
                return [
                    'kind' => 'card_holder',
                    'id' => (string)$holderId,
                    'version' => $version,
                ];
            }, $holderVersions, array_keys($holderVersions)),
        ];
    }

    /**
     * Gateway 已按 member -> benefit -> holder -> workspace 全序锁定并校验版本；
     * 本方法在同一事务中重读权威行、欠款和预约占用后生成可持久化草稿。
     *
     * @return array<int,array>
     */
    public function validateSelectedLinesInTx(
        array $payload,
        array $contexts,
        string $stateContextId,
        string $workspaceId,
        CashierV3OperatorScope $operatorScope
    ): array {
        CashierV3TransactionGuard::assertInTransaction('validateEntitlementDraftLines');
        $this->readiness->assertReady();
        $memberId = $this->positiveId($payload['memberId'] ?? null, 'memberId');
        $requestId = trim((string)($payload['selectorRequestId'] ?? ''));
        $token = trim((string)($payload['selectorToken'] ?? ''));
        $addIntentId = trim((string)($payload['addIntentId'] ?? ''));
        $expectedToken = self::selectorBindingToken($stateContextId, $workspaceId, $memberId, $requestId);
        if (!$this->validSelectorRequestId($requestId)
            || $token === ''
            || preg_match('/^[A-Za-z0-9_-]{16,64}$/D', $addIntentId) !== 1
            || !hash_equals($expectedToken, $token)) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ENTITLEMENT_SELECTOR_SESSION_EXPIRED,
                '权益选择会话已失效，请关闭后重新打开。',
                CashierV3ResultCode::STATUS_CONFLICT,
                ['reason' => 'selector_binding_invalid']
            );
        }
        $requested = isset($payload['lines']) && is_array($payload['lines']) ? $payload['lines'] : [];
        if (!$requested || count($requested) > self::MAX_SELECTED_LINES) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ENTITLEMENT_LINE_INVALID,
                '请选择有效的卡内项目后再加入购物车。',
                CashierV3ResultCode::STATUS_FAILED
            );
        }

        // 选择器打开时已经读取了最新权益展示快照。这里仅校验请求结构，
        // 不再次锁定权益来源、不判断可用次数，最终结账事务才读取权威权益。
        $seen = [];
        foreach ($requested as $line) {
            $holderId = $this->positiveId($line['entitlementInstanceId'] ?? null, 'entitlementInstanceId');
            $detailId = $this->positiveId($line['entitlementSourceDetailId'] ?? null, 'entitlementSourceDetailId');
            $key = $holderId . ':' . $detailId;
            $seen[$key] = true;
        }

        $validated = [];
        foreach ($requested as $line) {
            $holderId = (int)$line['entitlementInstanceId'];
            $detailId = (int)$line['entitlementSourceDetailId'];
            $sourceVersion = (int)$line['entitlementSourceVersion'];
            $detailVersion = (int)$line['projectVersion'];
            $quantity = (int)$line['quantity'];
            $projectId = $this->positiveId($line['projectId'] ?? null, 'projectId');
            $displaySnapshot = is_array($line['displaySnapshot'] ?? null)
                ? $line['displaySnapshot']
                : (is_array($line['display_snapshot'] ?? null) ? $line['display_snapshot'] : []);
            if ($sourceVersion <= 0 || $detailVersion <= 0 || $quantity <= 0 || !$displaySnapshot) {
                throw new CashierV3CommandException(
                    CashierV3ResultCode::ENTITLEMENT_LINE_INVALID,
                    '权益项目草稿快照不完整，请重新打开使用权益。',
                    CashierV3ResultCode::STATUS_FAILED,
                    ['holder_id' => $holderId, 'source_detail_id' => $detailId, 'project_id' => $projectId]
                );
            }
            $validated[] = [
                'add_intent_id' => $addIntentId,
                'holder_id' => $holderId,
                'source_detail_id' => $detailId,
                'project_id' => $projectId,
                'quantity' => $quantity,
                'source_version' => $sourceVersion,
                'detail_version' => $detailVersion,
                // 权益选择只确认权益身份与次数；购物车服务设置必须走独立写命令。
                'service_object' => 'self',
                'craftsmen' => [],
                'display_snapshot' => $displaySnapshot,
            ];
        }
        return $validated;
    }

    public function assertDraftLineQuantityInTx(
        array $line,
        int $quantity,
        int $aggregateQuantity,
        array $contexts,
        CashierV3OperatorScope $operatorScope
    ): void {
        CashierV3TransactionGuard::assertInTransaction('validateEntitlementDraftQuantity');
        $this->readiness->assertReady();
        $memberId = (int)($line['member_id'] ?? 0);
        $holderId = (int)($line['holder_id'] ?? 0);
        $detailId = (int)($line['source_detail_id'] ?? 0);
        $projectId = (int)($line['project_id'] ?? 0);
        $sourceVersion = (int)($line['source_version'] ?? 0);
        $detailVersion = (int)($line['detail_version'] ?? 0);
        if ($memberId <= 0 || $holderId <= 0 || $detailId <= 0 || $projectId <= 0
            || $sourceVersion <= 0 || $detailVersion <= 0 || $quantity <= 0
            || $aggregateQuantity < $quantity) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                '卡内项目草稿不完整，请删除后重新选择。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'entitlement_draft_line_incomplete']
            );
        }

        $contextMap = [];
        foreach ($contexts as $context) {
            $kind = (string)($context['kind'] ?? '');
            $id = (string)($context['id'] ?? '');
            $version = (int)($context['expected_version'] ?? $context['expectedVersion'] ?? 0);
            if ($kind !== '' && $id !== '') {
                $contextMap[$kind . ':' . $id] = $version;
            }
        }
        if (($contextMap['member:' . $memberId] ?? 0) <= 0
            || ($contextMap['card_holder:' . $holderId] ?? 0) !== $sourceVersion
            || ($contextMap['member_benefit_pool:' . $detailId] ?? 0) !== $detailVersion) {
            throw CashierV3CommandException::invalidContext(
                '卡内项目版本已变化，请重新打开后选择。',
                ['reason' => 'entitlement_draft_context_mismatch']
            );
        }

        // Gateway has already locked member -> benefit pool -> card holder in the
        // canonical order. Re-locking those rows here after cashier_workspace
        // would invert the global order; this pass is exact post-lock
        // revalidation only.
        // Revalidate the selected row against the complete source-card
        // snapshot.  Debt is allocated among every project of the source
        // order, so loading only the clicked detail would make an order-level
        // debt look as though it belonged wholly to that one project.
        $snapshot = $this->loadRows(
            $memberId,
            $operatorScope,
            $operatorScope->tenantId(),
            false,
            [$holderId],
            null,
            false,
            true,
            true
        );
        $sources = $this->buildSources(
            $snapshot,
            [$holderId => $sourceVersion],
            [$detailId => $detailVersion],
            $operatorScope->tenantId()
        );
        $pair = null;
        foreach ($sources as $source) {
            if ((int)($source['entitlementInstanceId'] ?? 0) !== $holderId) {
                continue;
            }
            foreach ((array)($source['projects'] ?? []) as $project) {
                if ((int)($project['entitlementSourceDetailId'] ?? 0) === $detailId) {
                    $pair = $project;
                    break 2;
                }
            }
        }
        if (!$pair || (int)($pair['projectId'] ?? 0) !== $projectId
            || empty($pair['selectable'])
            || $aggregateQuantity > (int)($pair['availableTimes'] ?? 0)) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ENTITLEMENT_SELECTION_CHANGED,
                '卡内项目可用次数已经变化，请重新打开后选择。',
                CashierV3ResultCode::STATUS_CONFLICT,
                ['holder_id' => $holderId, 'source_detail_id' => $detailId]
            );
        }
        $displaySnapshot = json_decode((string)($line['display_snapshot_json'] ?? ''), true);
        if (!is_array($displaySnapshot)
            || (string)($displaySnapshot['purchaseAmount'] ?? '') !== (string)($pair['purchaseAmount'] ?? '')
            || (int)($displaySnapshot['totalPurchaseTimes'] ?? 0) !== (int)($pair['totalPurchaseTimes'] ?? 0)
            || (int)($displaySnapshot['consumedTimesAtSelection'] ?? -1)
                !== (int)($pair['consumedTimesAtSelection'] ?? -2)
            || (int)($displaySnapshot['amountSourceVersion'] ?? 0) !== $detailVersion
            || (string)($displaySnapshot['amountCalculationVersion'] ?? '')
                !== (string)($pair['amountCalculationVersion'] ?? '')
            || (bool)($displaySnapshot['isGift'] ?? false) !== !empty($pair['isGift'])
            || (string)($displaySnapshot['giftSourceType'] ?? '')
                !== (!empty($pair['isGift']) ? 'holder_backed' : 'none')
            || (int)($displaySnapshot['sourceDetailId'] ?? 0) !== $detailId
            || (int)($displaySnapshot['detailVersion'] ?? 0) !== $detailVersion) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                '权益项目实际金额分摊快照不完整，请删除后重新选择。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'entitlement_amount_snapshot_mismatch', 'line_id' => (string)($line['line_key'] ?? '')]
            );
        }
    }

    /**
     * @param int[]|null $holderFilter
     * @param int[]|null $detailFilter
     * @return array{member:array,holders:array,orders:array,carts:array,reservations:array,debts:array,actualSaleAmounts:array}
     */
    private function loadRows(
        int $memberId,
        CashierV3OperatorScope $operatorScope,
        string $tenantId,
        bool $lock,
        array $holderFilter = null,
        array $detailFilter = null,
        bool $includeDisabledCards = false,
        bool $includeOperationalState = true,
        bool $includeUnavailableCards = false,
        bool $strictCardOperationState = true,
        bool $includeDisplayOnlyCards = false
    ): array {
        if ($lock) {
            CashierV3TransactionGuard::assertInTransaction('loadEntitlementRowsLocked');
        }
        $memberQuery = Db::name('user')
            ->field('uid,nickname,real_name,phone,avatar,status,is_del,delete_time')
            ->where('uid', $memberId);
        if ($lock) {
            $memberQuery->lock(true);
        }
        $member = $memberQuery->find();
        if (!$this->activeMember($member)) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::RESOURCE_NOT_FOUND,
                '该会员不存在或当前不可用。',
                CashierV3ResultCode::STATUS_FAILED
            );
        }

        $holderQuery = Db::name('user_card_holder')
            ->field('id,uid,oid,card_name,card_no,store_id,product_id,product_type,write_times,write_surplus_times,write_start,write_end,is_del')
            ->where('uid', $memberId)
            ->where('is_del', 0);
        if (!$includeUnavailableCards) {
            $holderQuery->where('write_surplus_times', '>', 0);
        }
        // 跨店核销关闭时，必须在发现阶段就裁掉其它门店卡实例。
        // 否则后续会先给不可见卡实例同步版本，再以“资源不存在”中断整个
        // 选择器，导致本店仍可用权益也无法展示。
        if ($holderFilter !== null) {
            $holderQuery->whereIn('id', $holderFilter ?: [-1]);
        }
        if ($lock) {
            $holderQuery->lock(true);
        }
        $holders = $this->applyCardOperationStates(
            $this->rows($holderQuery->order('id desc')->select()),
            $tenantId,
            $lock,
            $includeDisabledCards,
            $strictCardOperationState,
            $includeDisplayOnlyCards
        );
        // Existing cards still follow their recorded store. Only a database
        // migration scope may authorize a storeless historical holder, and
        // only inside the operator's current organization branch.
        $zeroStoreIds = [];
        foreach ($holders as $holder) {
            if ((int)$holder['store_id'] === 0) $zeroStoreIds[] = (int)$holder['id'];
        }
        $allowedHistorical = array_fill_keys(
            \app\services\cashier\v3\card\CashierV3HistoricalEntitlementScopePolicy::allowedHolderIds(
                $zeroStoreIds, $tenantId, $operatorScope->storeId()
            ), true
        );
        $holders = array_values(array_filter($holders, function (array $holder) use ($allowedHistorical, $operatorScope): bool {
            $storeId = (int)$holder['store_id'];
            return $storeId === 0
                ? isset($allowedHistorical[(int)$holder['id']])
                : ($storeId > 0 && ($this->crossStoreEnabled() || $storeId === $operatorScope->storeId()));
        }));
        $holderCountsByOrder = [];
        foreach ($holders as $holder) {
            $orderId = (int)($holder['oid'] ?? 0);
            $holderCountsByOrder[$orderId] = (int)($holderCountsByOrder[$orderId] ?? 0) + 1;
            if ($orderId > 0 && $holderCountsByOrder[$orderId] > 1) {
                throw new CashierV3CommandException(
                    CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                    '该订单存在重复卡实例，请联系管理员核对后再使用权益。',
                    CashierV3ResultCode::STATUS_FAILED,
                    ['order_id' => $orderId, 'reason' => 'duplicate_active_card_holder']
                );
            }
        }
        $orderIds = array_values(array_unique(array_map('intval', array_column($holders, 'oid'))));

        $orders = [];
        if ($orderIds) {
            $orderQuery = Db::name('store_order')
                ->field('id,uid,store_id,paid,is_del,is_system_del,is_user_del,refund_status,terminal_action,card_upgrade_use_oid,order_id,mark,pay_price,cash_pay_price,yue_pay_price,debt_amount,repaid_debt_amount')
                ->whereIn('id', $orderIds);
            if (!$includeDisplayOnlyCards) {
                $orderQuery
                    ->where('paid', 1)
                    ->where('is_del', 0)
                    ->where('is_user_del', 0)
                    ->where('refund_status', 0)
                    ->where('terminal_action', 0)
                    ->where('card_upgrade_use_oid', 0);
            }
            if ($lock) {
                $orderQuery->lock(true);
            }
            $orders = $this->rows($orderQuery->order('id asc')->select());
            $allowedOrderIds = [];
            foreach ($holders as $holder) {
                $allowedOrderIds[(int)$holder['oid']] = (int)$holder['store_id'];
            }
            $orders = array_values(array_filter($orders, function (array $order) use ($allowedOrderIds, $operatorScope): bool {
                $orderId = (int)$order['id'];
                $storeId = (int)$order['store_id'];
                if (!array_key_exists($orderId, $allowedOrderIds)) return false;
                return $allowedOrderIds[$orderId] === 0
                    ? $storeId === 0
                    : ($storeId > 0 && ($this->crossStoreEnabled() || $storeId === $operatorScope->storeId()));
            }));
        }
        $validOrderIds = array_values(array_unique(array_map('intval', array_column($orders, 'id'))));

        $carts = [];
        if ($validOrderIds) {
            $cartQuery = Db::name('store_order_cart_info')
                ->field('id,oid,cart_id,product_id,cart_type,product_type,cart_info,write_times,write_surplus_times,is_writeoff,write_start,write_end,pay_price,debt_amount,repaid_debt_amount,is_gift')
                ->whereIn('oid', $validOrderIds)
                ->where('cart_type', 2)
                ->where('product_type', 6);
            if (!$includeUnavailableCards) {
                $cartQuery->where('is_writeoff', 0)
                    ->where('write_surplus_times', '>', 0);
            }
            if ($detailFilter !== null) {
                $cartQuery->whereIn('id', $detailFilter ?: [-1]);
            }
            if ($lock) {
                $cartQuery->lock(true);
            }
            $carts = $this->rows($cartQuery->order('id asc')->select());
        }
        $cartIds = array_values(array_unique(array_map('intval', array_column($carts, 'id'))));
        $amountCarts = $carts;
        if ($validOrderIds) {
            // Whole-yuan normalization must see every component of the sold
            // card, including exhausted rows hidden from the selector. Using
            // only visible rows would move the rounding remainder after use.
            $amountCarts = $this->rows(Db::name('store_order_cart_info')
                ->field('id,oid,product_id,cart_info')
                ->whereIn('oid', $validOrderIds)
                ->where('cart_type', 2)
                ->where('product_type', 6)
                ->order('oid asc,id asc')
                ->select());
        }
        $reservations = [];
        // Reservations are deliberately not part of the cashier authority.
        // The displayed and settleable amount is the current card balance.

        $debts = [];
        if ($includeOperationalState && $validOrderIds) {
            $debtQuery = Db::name('store_debt')
                ->field('id,order_id,uid,store_id,status,total_debt,repaid_debt')
                ->whereIn('order_id', $validOrderIds);
            // 欠款同样只读取当前快照；旧还款路径按 debt -> order -> cart 加锁。
            $debts = $this->rows($debtQuery->order('order_id asc,id asc')->select());
        }
        $debtCounts = [];
        foreach ($debts as $debt) {
            $orderId = (int)$debt['order_id'];
            $debtCounts[$orderId] = (int)($debtCounts[$orderId] ?? 0) + 1;
            if ($debtCounts[$orderId] > 1) {
                throw new CashierV3CommandException(
                    CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                    '该订单存在重复欠款记录，请联系管理员核对后再使用权益。',
                    CashierV3ResultCode::STATUS_FAILED,
                    ['order_id' => $orderId, 'reason' => 'duplicate_order_debt']
                );
            }
        }
        return [
            'member' => $member,
            'holders' => $holders,
            'orders' => $orders,
            'carts' => $carts,
            'reservations' => $reservations,
            'debts' => $debts,
            'actualSaleAmounts' => (new CashierV3CardSaleActualAmountServices())->wholeYuanForOrders(
                $orders,
                $amountCarts
            ),
        ];
    }

    /**
     * V3 card state is an optional overlay until its migration is installed.
     * It controls current availability while the historic order remains the
     * source of purchase facts. A transfer has already updated holder.uid in
     * the same operation transaction, so no historic order UID is consulted.
     *
     * @param array<int,array> $holders
     * @return array<int,array>
     */
    private function applyCardOperationStates(
        array $holders,
        string $tenantId,
        bool $lock,
        bool $includeDisabledCards = false,
        bool $strict = true,
        bool $includeDisplayOnlyCards = false
    ): array
    {
        if ($holders === []) {
            return [];
        }
        $ids = array_values(array_unique(array_filter(array_map(static function (array $holder): int {
            return (int)($holder['id'] ?? 0);
        }, $holders))));
        if ($ids === []) {
            return [];
        }
        try {
            $query = Db::name('cashier_v3_card_state')
                ->where('tenant_id', $tenantId)
                ->whereIn('card_holder_id', $ids);
            if ($lock) {
                $query->lock(true);
            }
            $rows = $this->rows($query->select());
        } catch (\Throwable $exception) {
            $message = strtolower($exception->getMessage());
            if (strpos($message, 'cashier_v3_card_state') !== false
                && (strpos($message, 'doesn\'t exist') !== false || strpos($message, 'not found') !== false)) {
                return $holders;
            }
            throw $exception;
        }
        $states = [];
        foreach ($rows as $state) {
            $states[(int)($state['card_holder_id'] ?? 0)] = $state;
        }
        $out = [];
        foreach ($holders as $holder) {
            $state = $states[(int)($holder['id'] ?? 0)] ?? null;
            if ($state === null) {
                $out[] = $holder;
                continue;
            }
            if ((int)($state['origin_order_id'] ?? 0) !== (int)($holder['oid'] ?? 0)
                || (int)($state['current_member_id'] ?? 0) !== (int)($holder['uid'] ?? 0)
                || !in_array((string)($state['card_status'] ?? ''), ['enabled', 'disabled', 'upgraded'], true)) {
                if (!$strict) {
                    $holder['card_operation_status'] = 'enabled';
                    $out[] = $holder;
                    continue;
                }
                throw CashierV3CommandException::versionConflict(
                    '会员卡当前状态已经变化，请重新打开后选择。',
                    ['reason' => 'card_state_projection_mismatch', 'holder_id' => (int)($holder['id'] ?? 0)]
                );
            }
            // 已升级卡的原权益已经在升级结算中转入目标销售单。即使“全部”
            // 视图需要展示不可用卡，也不能把它作为权益来源或同步资源版本：
            // 原订单已被标记为升级来源，不再具备独立可用资源的当前版本。
            if ((string)$state['card_status'] === 'upgraded') {
                if ($includeDisplayOnlyCards) {
                    $holder['card_operation_status'] = 'upgraded';
                    $holder['write_start'] = (int)$state['effective_write_start'];
                    $holder['write_end'] = (int)$state['effective_write_end'];
                    $out[] = $holder;
                }
                continue;
            }
            if ((string)$state['card_status'] !== 'enabled') {
                if (!$includeDisabledCards) {
                    continue;
                }
                $holder['card_operation_status'] = (string)$state['card_status'];
            } else {
                $holder['card_operation_status'] = 'enabled';
            }
            $holder['write_start'] = (int)$state['effective_write_start'];
            $holder['write_end'] = (int)$state['effective_write_end'];
            $out[] = $holder;
        }
        return $out;
    }

    private function buildSources(
        array $snapshot,
        array $holderVersions,
        array $detailVersions,
        string $tenantId,
        bool $includeVersionFields = true,
        bool $includeDisplayOnlySources = false
    ): array
    {
        $orders = [];
        foreach ($snapshot['orders'] as $order) {
            $orders[(int)$order['id']] = $order;
        }
        $holdersByOrder = [];
        foreach ($snapshot['holders'] as $holder) {
            $holdersByOrder[(int)$holder['oid']] = $holder;
        }
        // 已售定制卡的 holder.product_id 指向专用隐藏壳；按商品 ID 批量核对
        // 壳的来源，不能仅凭卡名、有效期或次数猜测，以免同名普通卡被误标。
        $customCardProductIds = [];
        $holderProductIds = array_values(array_unique(array_filter(array_map(static function (array $holder): int {
            return (int)($holder['product_id'] ?? 0);
        }, $snapshot['holders']))));
        if ($holderProductIds) {
            foreach ($this->rows(Db::name('store_product')->field('id,pid')
                ->whereIn('id', $holderProductIds)->select()) as $product) {
                if ((int)$product['id'] === self::CUSTOM_CARD_SHELL_PRODUCT_ID
                    || (int)$product['pid'] === self::CUSTOM_CARD_SHELL_PRODUCT_ID) {
                    $customCardProductIds[(int)$product['id']] = true;
                }
            }
        }
        $debts = [];
        foreach ($snapshot['debts'] as $debt) {
            $debts[(int)$debt['order_id']] = $debt;
        }
        $writeoff = $this->writeoffServices();
        $cartsByOrder = [];
        foreach ($snapshot['carts'] as $cart) {
            $cartsByOrder[(int)($cart['oid'] ?? 0)][] = $cart;
        }
        $ruleAuthoritiesByHolder = [];
        foreach ($snapshot['holders'] as $holder) {
            $holderId = (int)($holder['id'] ?? 0);
            if ($holderId > 0) {
                $ruleAuthoritiesByHolder[$holderId] = $this->cardRules->authoritiesForHolder(
                    $tenantId,
                    $holderId,
                    false
                );
            }
        }
        // Shared-count refunds belong to the whole card, not any of its project
        // carts. Batch their effective facts so source totals cannot re-sum stale carts.
        $sharedRefunds = [];
        $holderIds = array_keys($ruleAuthoritiesByHolder);
        if ($holderIds) {
            foreach (Db::name('cashier_v3_refund_adjustment')->where('tenant_id', $tenantId)
                ->whereIn('card_holder_id', $holderIds)->where('status', 'effective')
                ->field('card_holder_id,refund_amount_cents,times_removed')->select()->toArray() as $refund) {
                $id = (int)$refund['card_holder_id'];
                $sharedRefunds[$id]['returned'] = ($sharedRefunds[$id]['returned'] ?? 0) + (int)$refund['refund_amount_cents'];
                $sharedRefunds[$id]['removed'] = ($sharedRefunds[$id]['removed'] ?? 0) + (int)$refund['times_removed'];
            }
        }
        $projectsByHolder = [];
        $pendingByOrder = [];
        $cancelledByOrder = [];
        $sharedLimitByHolder = [];
        $now = time();
        foreach ($snapshot['carts'] as $cart) {
            $detailId = (int)$cart['id'];
            $order = $orders[(int)$cart['oid']] ?? null;
            $holder = $holdersByOrder[(int)$cart['oid']] ?? null;
            if (!$order || !$holder || ($includeVersionFields
                && (empty($detailVersions[$detailId]) || empty($holderVersions[(int)$holder['id']])))) {
                continue;
            }
            $ruleAuthority = $ruleAuthoritiesByHolder[(int)$holder['id']][$detailId] ?? null;
            $sourceOrderUnavailableReason = $this->sourceOrderUnavailableReason($order);
            $orderId = (int)$order['id'];
            // 同张卡的多个项目共享一笔来源待还额；列表只读一次，提交时仍重新查锁定债务。
            if (!array_key_exists($orderId, $pendingByOrder)) {
                $pendingByOrder[$orderId] = $this->pendingDebt($order, $debts[$orderId] ?? null);
            }
            $pendingDebt = $pendingByOrder[$orderId];
            if (!array_key_exists($orderId, $cancelledByOrder)) {
                $cancelledByOrder[$orderId] = (new CashierV3CardOriginDebtResolver())
                    ->cancelledCardDebt($order, (int)($order['uid'] ?? 0), (string)($order['tenant_id'] ?? '0'), false);
            }
            $decoded = is_string($cart['cart_info'] ?? null)
                ? json_decode((string)$cart['cart_info'], true)
                : ($cart['cart_info'] ?? []);
            $decoded = is_array($decoded) ? $decoded : [];
            // 新替换虽按整元分摊，仍使用目标项目自身金额/次数，不能退回整卡共享池。
            $centCapableOperationDetail = CashierV3EntitlementActualAmountAllocator::usesIndependentAmountSnapshot($decoded);
            // Replacement/upgrade-created details are independently purchased
            // entitlement lines. Their own write surplus is authoritative for
            // selection and amount allocation; a choice-count card's shared
            // remaining count only governs whether the rule stays available.
            $rawSurplus = $centCapableOperationDetail
                ? max(0, (int)$cart['write_surplus_times'])
                : (is_array($ruleAuthority)
                    ? max(0, (int)$ruleAuthority['remainingTimes'])
                    : max(0, (int)$cart['write_surplus_times']));
            $name = trim((string)($decoded['productInfo']['store_name'] ?? ''));
            if ($name === '') {
                $name = '项目';
            }
            // Operation-created benefits carry their authoritative cents in
            // cart_info. Do not replace that snapshot with the card-rule
            // whole-yuan projection for this one explicit source type.
            $actualSaleAmountCents = (int)($snapshot['actualSaleAmounts'][$detailId] ?? -1);
            $sharedPurchase = is_array($ruleAuthority)
                && (string)$ruleAuthority['ruleType'] === 'choice_count'
                && !$centCapableOperationDetail;
            $holderId = (int)$holder['id'];
            $sharedKey = $holderId . ':' . (int)($ruleAuthority['combinationGroupNo'] ?? 0);
            if ($sharedPurchase && !array_key_exists($sharedKey, $sharedLimitByHolder)) {
                // 同一任选次卡的项目共享次数限制；只读投影复用一次，提交仍逐笔重新核验。
                $sharedLimitByHolder[$sharedKey] = $this->cardRules->debtLimitForDetail($cart, $order, false);
            }
            if ($sharedPurchase) {
                // 任选次数卡只有一份整卡购买金额。项目分摊事实不能再除以
                // 共享总次数，否则选择桃花灸和背部 spa 会得出不同单次金额。
                $sharedSnapshot = $decoded;
                $sharedSnapshot['r26RefundedCents'] = (int)($sharedRefunds[(int)$holder['id']]['returned'] ?? 0);
                $sharedSnapshot['r26RefundRemovedTimes'] = (int)($sharedRefunds[(int)$holder['id']]['removed'] ?? 0);
                $amounts = CashierV3SharedCardPurchaseAmount::project($order, $ruleAuthority, $sharedSnapshot)
                    ?? [
                        'purchaseAmount' => null, 'remainingAmount' => null,
                        'totalPurchaseTimes' => 0, 'consumedTimesAtSelection' => 0,
                        'calculationVersion' => 'shared-card-purchase-invalid-v1',
                    ];
            } elseif ($actualSaleAmountCents >= 0) {
                $amounts = $this->projectActualSaleAmount($cart, $rawSurplus, $actualSaleAmountCents, $decoded);
            } else {
                $amounts = $centCapableOperationDetail
                    ? $this->projectAmounts($cart, $rawSurplus)
                    : (is_array($ruleAuthority)
                        ? $this->ruleProjectAmounts($ruleAuthority)
                        : $this->projectAmounts($cart, $rawSurplus));
            }
            $effective = $writeoff->calcEffectiveWriteSurplusTimes(
                $cart,
                (float)$pendingDebt,
                $order,
                $amounts['remainingAmount'],
                $cartsByOrder[$orderId] ?? [],
                false,
                $pendingDebt,
                $cancelledByOrder[$orderId],
                $sharedPurchase ? $sharedLimitByHolder[$sharedKey] : null
            );
            if (is_array($ruleAuthority)) {
                $effective = min($rawSurplus, max(0, (int)$effective));
            }
            // 未完成历史纠正的共享卡不得因为规则池漏扣而继续出售服务。
            // 持卡旧投影已为零时整池停用，保留原余次供审计，避免扩大错扣。
            if ($sharedPurchase) $effective = min($effective, max(0, (int)$holder['write_surplus_times']));
            // 兼容字段继续表达不可用余次；差额本身不能证明存在待还欠款，
            // 下方提示必须结合实际来源待还额，不能误导已核销会员去补交。
            $debtBlocked = max(0, $rawSurplus - $effective);
            $reservationOccupied = 0;
            $available = max(0, $effective);
            $validity = is_array($ruleAuthority)
                ? $this->ruleValidity($ruleAuthority)
                : $this->effectiveValidity($holder, $cart);
            $start = (int)$validity['start'];
            $end = (int)$validity['end'];
            $invalidValidity = $end > 0 && $start > $end;
            $expired = $invalidValidity || ($start > 0 && $now < $start) || ($end > 0 && $now > $end);
            $invalidAmount = $amounts['purchaseAmount'] === null
                || $amounts['remainingAmount'] === null
                || $amounts['totalPurchaseTimes'] <= 0;
            $reason = '';
            if ($sourceOrderUnavailableReason !== '') {
                $reason = $sourceOrderUnavailableReason;
            } elseif ($invalidValidity) {
                $reason = '权益有效期异常';
            } elseif ($expired) {
                $reason = $start > $now ? '未到可用时间' : '权益已过期';
            } elseif ($invalidAmount) {
                $reason = '权益实际金额不完整';
            } elseif ($sharedPurchase && $rawSurplus === 0
                && (string)$ruleAuthority['stateStatus'] === 'exhausted') {
                $reason = '共享次数已用完';
            } elseif (is_array($ruleAuthority) && (string)$ruleAuthority['stateStatus'] !== 'active') {
                $reason = '卡项当前不可用';
            } elseif (is_array($ruleAuthority) && empty($ruleAuthority['choiceAvailable'])) {
                $reason = '已达到任选项目种数';
            } elseif ((int)($decoded['block_writeoff_on_debt'] ?? 0) === 1
                && bccomp((string)$pendingDebt, '0', 2) > 0) {
                // 与核销提交读取同一已售卡快照及卡来源待还额，不能以会员总欠款误拦其他卡。
                $reason = '该卡有欠款，请先结清';
            } elseif ($available <= 0 && $debtBlocked > 0 && bccomp((string)$pendingDebt, '0', 2) > 0) {
                $reason = '欠款限制后暂无可用次数';
            } elseif ($available <= 0 && ($rawSurplus > max(0, (int)$cart['write_surplus_times'])
                || ($sharedPurchase && $rawSurplus > max(0, (int)$holder['write_surplus_times'])))) {
                $reason = '权益次数记录不一致，请联系管理员核对';
            } elseif ($available <= 0) {
                $reason = '暂无可用次数';
            }
            $project = [
                'id' => $detailId,
                'projectId' => (int)$cart['product_id'],
                'entitlementSourceDetailId' => $detailId,
                'sourceType' => (string)($decoded['sourceType'] ?? ''),
                'name' => $name,
                'remainingTimes' => $rawSurplus,
                // 债务只限制可兑现次数，不改写核销/作废依赖的物理余次。
                'debtLimitedRemainingTimes' => $effective,
                'purchaseTimes' => max(0, (int)$cart['write_times']),
                'purchaseAmount' => $amounts['purchaseAmount'],
                'cardRuleType' => (string)($ruleAuthority['cardRuleType'] ?? ''),
                'combinationGroupNo' => (int)($ruleAuthority['combinationGroupNo'] ?? 0),
                'combinationGroupName' => (string)($ruleAuthority['combinationGroupName'] ?? ''),
                'combinationGroupRule' => (string)($ruleAuthority['ruleType'] ?? ''),
                // 前置提示只读已售卡组状态；正式核销仍在事务内复核种数上限。
                'combinationChoiceLimit' => (int)($ruleAuthority['combinationChoiceLimit'] ?? 0),
                'combinationSelectedKindCount' => (int)($ruleAuthority['combinationSelectedKindCount'] ?? 0),
                'combinationSelectionStatus' => (string)($ruleAuthority['selectionStatus'] ?? ''),
                'combinationRawRemainingAmount' => $amounts['remainingAmount'],
                // 可用金额扣除未付/已取消部分；购买金额及每次原分摊快照仍保留历史。
                'remainingAmount' => $writeoff->usableEntitlementAmount($cart, $order, $amounts['remainingAmount'], (float)$pendingDebt, $cartsByOrder[(int)$order['id']] ?? []),
                'totalPurchaseTimes' => $amounts['totalPurchaseTimes'],
                'consumedTimesAtSelection' => $amounts['consumedTimesAtSelection'],
                'amountCalculationVersion' => $amounts['calculationVersion'],
                'occupiedTimes' => $reservationOccupied,
                'availableTimes' => $available,
                'debtBlockedTimes' => $debtBlocked,
                'debtRestrictionLabel' => $debtBlocked > 0 && bccomp((string)$pendingDebt, '0', 2) > 0
                    ? sprintf('欠款限制 %d 次', $debtBlocked) : '',
                'validThroughLabel' => $validity['label'],
                'expiryDate' => $this->expiryDate($end),
                'orderRemark' => trim((string)($order['mark'] ?? '')),
                'isGift' => (int)($cart['is_gift'] ?? 0) === 1,
                'selectable' => $sourceOrderUnavailableReason === ''
                    && !$expired && !$invalidAmount && $available > 0
                    && (!is_array($ruleAuthority)
                        || ((string)$ruleAuthority['stateStatus'] === 'active'
                            && !empty($ruleAuthority['choiceAvailable']))),
                'disabled' => $sourceOrderUnavailableReason !== ''
                    || $expired || $invalidAmount || $available <= 0
                    || (is_array($ruleAuthority)
                        && ((string)$ruleAuthority['stateStatus'] !== 'active'
                            || empty($ruleAuthority['choiceAvailable']))),
                'disabledReason' => $reason,
                // cardRuleType 保留整卡类型；组合内生效规则已由 combinationGroupRule 单独提供。
                // 覆写为组规则会使共享组金额在父卡汇总时被当成多个独立项目重复计入。
                'writeoffAmount' => is_array($ruleAuthority)
                    ? $this->centsToMoney((int)$ruleAuthority['writeoffAmountCents'])
                    : null,
                'unlimited' => is_array($ruleAuthority) && !empty($ruleAuthority['unlimited']),
                'serviceObject' => '本人',
                'craftsmen' => [],
                'craftsmenSummary' => '待分配',
            ];
            if ($includeVersionFields) {
                $project['version'] = (int)$detailVersions[$detailId];
                $project['amountSourceVersion'] = (int)$detailVersions[$detailId];
            }
            if (is_array($ruleAuthority) && !$centCapableOperationDetail) {
                // Issued-card components normally inherit their purchase count
                // from the rule authority. Operation-created details are the
                // exception because they own a separate paid count and amount.
                $project['purchaseTimes'] = (int)$ruleAuthority['totalTimes'];
            }
            // 单价仅展示原购买平均价，不使用余次、可用余额或核销计价；不进入扣款快照。
            $project['unitPrice'] = $this->purchaseUnitPrice(
                $project['purchaseAmount'],
                (int)$project['totalPurchaseTimes'],
                !empty($project['unlimited'])
            );
            $projectsByHolder[(int)$holder['id']][] = $project;
        }

        $sources = [];
        foreach ($snapshot['holders'] as $holder) {
            $holderId = (int)$holder['id'];
            $projects = $projectsByHolder[$holderId] ?? [];
            if ((!$projects && !$includeDisplayOnlySources)
                || ($includeVersionFields && empty($holderVersions[$holderId]))) {
                continue;
            }
            $operationStatus = (string)($holder['card_operation_status'] ?? 'enabled');
            $cardDisabled = $operationStatus !== 'enabled';
            $order = $orders[(int)$holder['oid']] ?? [];
            // 权益事实必须同时具备仍有效的来源销售单。卡升级后，原订单会被
            // 权威查询排除，但历史 holder 可能仍保留物理余次；此时不能把
            // 孤立 holder 当作可预约权益，更不能用空订单继续计算欠款。
            if ($order === []) {
                continue;
            }
            $sourceOrderUnavailableReason = $this->sourceOrderUnavailableReason($order);
            $remaining = max(0, (int)($holder['write_surplus_times'] ?? 0));
            $purchaseTimes = max(0, (int)($holder['write_times'] ?? 0));
            $occupiedTimes = 0;
            $availableTimes = 0;
            $selectable = false;
            foreach ($projects as $project) {
                $occupiedTimes += (int)$project['occupiedTimes'];
                $availableTimes += (int)$project['availableTimes'];
                $selectable = $selectable || !empty($project['selectable']);
            }
            if ($cardDisabled) {
                $selectable = false;
            }
            if ($purchaseTimes <= 0) {
                $purchaseTimes = array_sum(array_map(static function (array $project): int {
                    return max(0, (int)($project['purchaseTimes'] ?? 0));
                }, $projects));
            }
            $kind = $this->sourceKind($holder, $projects);
            $holderRuleAuthorities = $ruleAuthoritiesByHolder[$holderId] ?? [];
            $holderRuleAuthority = $holderRuleAuthorities ? reset($holderRuleAuthorities) : null;
            if (is_array($holderRuleAuthority) && $kind['code'] !== 'gift') {
                $kind = [
                    'code' => (string)$holderRuleAuthority['sourceKind'],
                    'label' => (string)$holderRuleAuthority['sourceKindLabel'],
                ];
                // 组合卡父行只展示目前最多可兑现的组次数，避免已封锁候选项目虚增余次。
                $remaining = (string)($holderRuleAuthority['cardRuleType'] ?? '') === 'combination'
                    ? CashierV3CombinationRemainingTimes::fromAuthorities($holderRuleAuthorities)
                    : (int)$holderRuleAuthority['remainingTimes'];
                $purchaseTimes = (string)($holderRuleAuthority['cardRuleType'] ?? '') === 'combination'
                    ? (int)$holder['write_times'] : (int)$holderRuleAuthority['totalTimes'];
            }
            $debtLimitedRemaining = min($remaining, $availableTimes);
            if (is_array($holderRuleAuthority) && (string)$holderRuleAuthority['ruleType'] === 'choice_count'
                && (string)($holderRuleAuthority['cardRuleType'] ?? '') !== 'combination') {
                // 任选次数卡各项目共享一池；不能把每行可用次数相加成多份余次。
                $firstDetailId = (int)($projects[0]['id'] ?? 0);
                foreach ($cartsByOrder[(int)$order['id']] ?? [] as $sourceCart) {
                    if ((int)$sourceCart['id'] !== $firstDetailId) continue;
                    $sharedLimit = $this->cardRules->debtLimitForDetail($sourceCart, $order);
                    if ($sharedLimit !== null) $debtLimitedRemaining = min($remaining, $sharedLimit);
                    break;
                }
            }
            // 欠款权威归属为该卡来源销售订单。它是卡级余额，不能复制到
            // 每个项目明细，否则一个卡含多个项目时会造成重复展示。
            $outstandingDebtAmount = $this->pendingDebt(
                $order,
                $debts[(int)($order['id'] ?? 0)] ?? null
            );
            $sourceAmounts = !$projects
                ? ['purchaseAmount' => null, 'remainingAmount' => null, 'calculationVersion' => 'display-only-card-v1']
                : ($kind['code'] === 'time_card'
                ? $this->timeCardSourceAmounts($order)
                : $this->sourceAmounts($projects));
            if (is_array($holderRuleAuthority) && (string)$holderRuleAuthority['ruleType'] === 'choice_count'
                && (string)($holderRuleAuthority['cardRuleType'] ?? '') !== 'combination') {
                $returned = (int)($sharedRefunds[$holderId]['returned'] ?? 0);
                $removed = (int)($sharedRefunds[$holderId]['removed'] ?? 0);
                $shared = CashierV3SharedCardPurchaseAmount::project($order, $holderRuleAuthority, [
                    'r26RefundedCents' => $returned,
                    'r26RefundRemovedTimes' => $removed,
                ]);
                if ($shared === null) {
                    throw new CashierV3CommandException(CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                        '本卡共享次数与购买金额不一致，请联系管理员核对。', CashierV3ResultCode::STATUS_FAILED,
                        ['reason' => 'entitlement_shared_purchase_invalid']);
                }
                if ($returned > 0) {
                    try {
                        $quota = CashierV3CardRefundQuotaPolicy::sharedPool($shared['purchaseAmount'],
                            $purchaseTimes, $remaining, $returned, $removed);
                        if ($this->centsToMoney($quota['remainingValueCents']) !== $shared['remainingAmount']) {
                            throw new \InvalidArgumentException('shared refund value differs from writeoff pool');
                        }
                    } catch (\InvalidArgumentException $error) {
                        throw new CashierV3CommandException(CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                            '本卡共享次数与退款金额不一致，请联系管理员核对。', CashierV3ResultCode::STATUS_FAILED,
                            ['reason' => 'entitlement_shared_refund_value_mismatch']);
                    }
                }
                // 项目选项各自展示同一共享池，但父卡只能计一次；独立购买的
                // 替换/升级项目仍沿用自己的金额快照，不并入共享次数分母。
                $independentProjects = array_values(array_filter($projects, static function (array $project): bool {
                    return CashierV3EntitlementActualAmountAllocator::usesIndependentAmountSnapshot($project);
                }));
                $independent = $independentProjects ? $this->sourceAmounts($independentProjects)
                    : ['purchaseAmount' => '0.00', 'remainingAmount' => '0.00'];
                $blocked = bcadd($outstandingDebtAmount, $this->cancelledDebt($order, null, $tenantId), 2);
                $sharedRemaining = bcsub($shared['remainingAmount'], $blocked, 2);
                $sharedRemaining = bccomp($sharedRemaining, '0', 2) > 0 ? $sharedRemaining : '0.00';
                $sourceAmounts = [
                    'purchaseAmount' => $independent['purchaseAmount'] === null ? null
                        : bcadd($shared['purchaseAmount'], $independent['purchaseAmount'], 2),
                    'remainingAmount' => $independent['remainingAmount'] === null ? null
                        : bcadd($cardDisabled ? '0.00' : $sharedRemaining, $independent['remainingAmount'], 2),
                    'calculationVersion' => CashierV3SharedCardPurchaseAmount::CALCULATION_VERSION,
                ];
            }
            if (is_array($holderRuleAuthority) && (string)($holderRuleAuthority['cardRuleType'] ?? '') === 'combination'
                && $sourceAmounts['remainingAmount'] !== null) {
                $blocked = bcadd($outstandingDebtAmount, $this->cancelledDebt($order, null, $tenantId), 2);
                $usable = bcsub($sourceAmounts['remainingAmount'], $blocked, 2);
                $sourceAmounts['remainingAmount'] = bccomp($usable, '0', 2) > 0 ? $usable : '0.00';
            }
            // 时间卡父行没有项目金额汇总，仍须排除不可再使用的欠款/取消额。
            if ($kind['code'] === 'time_card' && $sourceAmounts['remainingAmount'] !== null) {
                $blocked = bcadd($outstandingDebtAmount, $this->cancelledDebt($order, null, $tenantId), 2);
                $usable = bcsub($sourceAmounts['remainingAmount'], $blocked, 2);
                $sourceAmounts['remainingAmount'] = bccomp($usable, '0', 2) > 0 ? $usable : '0.00';
            }
            $sourceValidity = $this->effectiveValidity($holder, []);
            $sourceDisabledReason = $sourceOrderUnavailableReason !== ''
                ? $sourceOrderUnavailableReason
                : ($operationStatus === 'upgraded'
                    ? '已升级为其他卡项，当前不可使用'
                    : ($cardDisabled ? '卡项已停用' : ''));
            $sources[] = [
                'id' => $holderId,
                'entitlementInstanceId' => $holderId,
                'entitlementInstanceType' => 'card_holder',
                'sourceType' => 'card_holder',
                'sourceKind' => $kind['code'],
                'sourceKindLabel' => $kind['label'],
                'isCustomCard' => isset($customCardProductIds[(int)($holder['product_id'] ?? 0)]),
                'name' => trim((string)$holder['card_name']) !== '' ? (string)$holder['card_name'] : '会员卡项',
                'fullCardNo' => (string)$holder['card_no'],
                'reference' => (string)$holder['card_no'],
                'remainingTimes' => $remaining,
                'debtLimitedRemainingTimes' => $debtLimitedRemaining,
                'purchaseTimes' => $purchaseTimes,
                'purchaseAmount' => $sourceAmounts['purchaseAmount'],
                'unitPrice' => $this->purchaseUnitPrice(
                    $sourceAmounts['purchaseAmount'],
                    $purchaseTimes,
                    is_array($holderRuleAuthority) && !empty($holderRuleAuthority['unlimited'])
                ),
                'remainingAmount' => $sourceAmounts['remainingAmount'],
                'outstandingDebtAmount' => $outstandingDebtAmount,
                'cancelledDebtAmount' => $this->cancelledDebt($order, $debts[(int)($order['id'] ?? 0)] ?? null, $tenantId),
                'amountCalculationVersion' => $sourceAmounts['calculationVersion'],
                'cardRuleType' => is_array($holderRuleAuthority)
                    ? (string)$holderRuleAuthority['ruleType']
                    : '',
                'unlimited' => is_array($holderRuleAuthority) && !empty($holderRuleAuthority['unlimited']),
                'occupiedTimes' => $occupiedTimes,
                'availableTimes' => $availableTimes,
                'selectable' => !$cardDisabled && $sourceOrderUnavailableReason === '' && $selectable,
                'disabled' => $cardDisabled || $sourceOrderUnavailableReason !== '',
                'disabledReason' => $sourceDisabledReason,
                'statusCode' => $operationStatus === 'upgraded'
                    ? 'upgraded'
                    : (($cardDisabled || $sourceOrderUnavailableReason !== '') ? 'disabled' : ($selectable ? 'enabled' : 'unavailable')),
                'status' => $operationStatus === 'upgraded'
                    ? '已升级'
                    : (($cardDisabled || $sourceOrderUnavailableReason !== '') ? '不可用' : ($selectable ? '可用' : '不可用')),
                'expiryText' => $sourceValidity['label'],
                'expiryDate' => $this->expiryDate((int)$sourceValidity['end']),
                'orderRemark' => trim((string)($order['mark'] ?? '')),
                'projects' => $projects,
            ];
            if ($includeVersionFields) {
                $sources[array_key_last($sources)]['version'] = (int)$holderVersions[$holderId];
            }
        }
        return $sources;
    }

    /**
     * 历史导入项目赠送的剩余次数以原明细为权威；来源单仅证明发放仍有效。
     * 这里只投影仍可用的明细，不把赠送伪装为销售卡项或购买金额。
     */
    private function legacyGiftSources(int $memberId, CashierV3OperatorScope $operatorScope): array
    {
        $query = Db::name('store_order_cart_info')->alias('ci')
            ->join('store_order o', 'o.id = ci.oid AND o.uid = ci.uid')
            ->where('ci.uid', $memberId)
            // 与关联条件等价；显式限定订单会员，让历史赠送查询走订单 uid 索引。
            ->where('o.uid', $memberId)
            ->where('ci.cart_type', 2)
            ->where('ci.product_type', 6)
            ->where('ci.is_gift', 1)
            ->where('ci.source_type', 'migration')
            ->where('ci.cart_id', 'like', 'RH-PG-%')
            ->where('ci.write_surplus_times', '>', 0)
            ->where('ci.is_writeoff', 0)
            ->where('o.paid', 1)
            ->where('o.is_del', 0)
            ->where('o.is_system_del', 0)
            ->where('o.is_user_del', 0)
            ->where('o.refund_status', 0)
            ->where('o.terminal_action', 0)
            ->where('o.card_upgrade_use_oid', 0);
        if (!$this->crossStoreEnabled()) {
            $query->where('o.store_id', $operatorScope->storeId());
        }
        $rows = $this->rows($query->field('ci.id,ci.oid,ci.product_id,ci.cart_info,ci.write_times,ci.write_surplus_times,o.order_id,o.store_id')
            ->order('ci.id desc')->select());
        $sources = [];
        foreach ($rows as $row) {
            $detailId = (int)$row['id'];
            $remaining = (int)$row['write_surplus_times'];
            $total = (int)$row['write_times'];
            if ($detailId <= 0 || (int)$row['product_id'] <= 0 || $total <= 0 || $remaining > $total) {
                continue;
            }
            $snapshot = json_decode((string)$row['cart_info'], true);
            $name = trim((string)($snapshot['productInfo']['store_name'] ?? $snapshot['project_name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $sourceNo = (string)$row['order_id'];
            $project = [
                'id' => $detailId, 'projectId' => (int)$row['product_id'],
                'entitlementSourceDetailId' => $detailId,
                'sourceType' => 'migrated_project_gift', 'name' => $name,
                'remainingTimes' => $remaining, 'purchaseTimes' => $total,
                'purchaseAmount' => '0.00', 'remainingAmount' => '0.00',
                'totalPurchaseTimes' => $total, 'consumedTimesAtSelection' => $total - $remaining,
                'amountCalculationVersion' => 'independent-gift-'
                    . \app\services\cashier\v3\checkout\CashierV3EntitlementCompletionKernel::AMOUNT_CALCULATION_VERSION,
                'occupiedTimes' => 0, 'availableTimes' => $remaining, 'debtBlockedTimes' => 0,
                'debtRestrictionLabel' => '', 'validThroughLabel' => '长期有效', 'expiryDate' => '',
                'orderRemark' => '', 'isGift' => true, 'selectable' => true,
                'disabled' => false, 'disabledReason' => '', 'unitPrice' => '0',
                'serviceObject' => '本人', 'craftsmen' => [], 'craftsmenSummary' => '待分配',
            ];
            $sources[] = [
                'id' => 'independent-gift:' . $detailId, 'entitlementInstanceId' => $detailId,
                'entitlementInstanceType' => 'independent_gift', 'sourceType' => 'independent_gift',
                'sourceKind' => 'gift', 'sourceKindLabel' => '赠送',
                'name' => $name, 'fullCardNo' => $sourceNo, 'reference' => $sourceNo,
                'remainingTimes' => $remaining, 'purchaseTimes' => $total,
                'purchaseAmount' => '0.00', 'unitPrice' => '0', 'remainingAmount' => '0.00',
                'outstandingDebtAmount' => '0.00', 'cancelledDebtAmount' => '0.00',
                'amountCalculationVersion' => 'independent-gift-'
                    . \app\services\cashier\v3\checkout\CashierV3EntitlementCompletionKernel::AMOUNT_CALCULATION_VERSION,
                'occupiedTimes' => 0, 'availableTimes' => $remaining,
                'selectable' => true, 'disabled' => false, 'disabledReason' => '',
                'statusCode' => 'enabled', 'status' => '可用',
                'expiryText' => '长期有效', 'expiryDate' => '', 'orderRemark' => '',
                'projects' => [$project],
            ];
        }
        return $sources;
    }

    /**
     * 原购买金额÷原购买次数的只读整数展示价，直接截取整数部分，
     * 与尾差归末次规则保持一致；绝不回写或替代实际扣款及尾差分摊。
     * 无可信购买金额、无正数原次数或不限次权益返回 null，不猜测目录价。
     */
    private function purchaseUnitPrice(?string $purchaseAmount, int $purchaseTimes, bool $unlimited): ?string
    {
        if ($unlimited || $purchaseTimes <= 0 || $purchaseAmount === null
            || preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/D', $purchaseAmount) !== 1) {
            return null;
        }
        return bcdiv($purchaseAmount, (string)$purchaseTimes, 0);
    }

    private function ruleProjectAmounts(array $authority): array
    {
        $purchaseCents = (int)($authority['purchaseAmountCents'] ?? -1);
        $totalTimes = (int)($authority['totalTimes'] ?? 0);
        $remainingTimes = (int)($authority['remainingTimes'] ?? -1);
        if ($purchaseCents < 0 || $purchaseCents % 100 !== 0
            || $totalTimes <= 0 || $remainingTimes < 0 || $remainingTimes > $totalTimes) {
            return [
                'purchaseAmount' => null,
                'remainingAmount' => null,
                'totalPurchaseTimes' => 0,
                'consumedTimesAtSelection' => 0,
                'calculationVersion' => 'issued-card-rule-invalid-v1',
            ];
        }
        $purchaseAmount = $this->centsToMoney($purchaseCents);
        $consumedTimes = $totalTimes - $remainingTimes;
        return [
            'purchaseAmount' => $purchaseAmount,
            'remainingAmount' => CashierV3EntitlementActualAmountAllocator::remainingForSnapshot(
                $purchaseAmount,
                $totalTimes,
                $consumedTimes,
                $authority
            ),
            'totalPurchaseTimes' => $totalTimes,
            'consumedTimesAtSelection' => $consumedTimes,
            'calculationVersion' => 'issued-card-rule-' . (string)$authority['ruleType'] . '-'
                . CashierV3EntitlementActualAmountAllocator::CALCULATION_VERSION,
        ];
    }

    /** @return array{start:int,end:int,label:string} */
    private function ruleValidity(array $authority): array
    {
        $start = max(0, (int)($authority['validFrom'] ?? 0));
        $end = max(0, (int)($authority['validThrough'] ?? 0));
        return [
            'start' => $start,
            'end' => $end,
            'label' => $end > 0 && $start > $end
                ? '有效期异常'
                : $this->validThroughLabel($start, $end),
        ];
    }

    private function centsToMoney(int $cents): string
    {
        if ($cents < 0) {
            throw new \InvalidArgumentException('amount cents must be nonnegative');
        }
        return bcdiv((string)$cents, '100', 2);
    }

    /**
     * 购物车金额仅使用服务端已固化的权益明细金额或历史导入快照；前端不得
     * 根据名称、次数或来源卡自行估算。无可信金额时返回 null，由页面显示“—”。
     *
     * @return array{
     *   purchaseAmount:?string,
     *   remainingAmount:?string,
     *   totalPurchaseTimes:int,
     *   consumedTimesAtSelection:int,
     *   calculationVersion:string
     * }
     */
    private function projectAmounts(array $cart, int $remainingTimes): array
    {
        $snapshot = is_string($cart['cart_info'] ?? null)
            ? json_decode((string)$cart['cart_info'], true)
            : ($cart['cart_info'] ?? []);
        $snapshot = is_array($snapshot) ? $snapshot : [];
        $legacySource = is_array($snapshot['rh_source'] ?? null) ? $snapshot['rh_source'] : [];
        $amount = null;
        $version = 'legacy-cart-line-payment-' . CashierV3EntitlementActualAmountAllocator::CALCULATION_VERSION;
        if (CashierV3EntitlementActualAmountAllocator::isCentCapableSnapshot($snapshot)) {
            $version = 'operation-cent-' . CashierV3EntitlementActualAmountAllocator::CALCULATION_VERSION;
        }
        if (array_key_exists('source_line_paid_amount', $legacySource)) {
            $amount = CashierV3EntitlementActualAmountAllocator::isCentCapableSnapshot($snapshot)
                ? $this->nonnegativeCentMoney($legacySource['source_line_paid_amount'])
                : $this->nonnegativeMoney($legacySource['source_line_paid_amount']);
            if ($amount === null) {
                return [
                    'purchaseAmount' => null,
                    'remainingAmount' => null,
                    'totalPurchaseTimes' => 0,
                    'consumedTimesAtSelection' => 0,
                    'calculationVersion' => 'rh-source-line-payment-invalid-v1',
                ];
            }
            $version = 'rh-source-line-payment-' . CashierV3EntitlementActualAmountAllocator::CALCULATION_VERSION;
        }
        if ($amount === null) {
            $amount = CashierV3EntitlementActualAmountAllocator::isCentCapableSnapshot($snapshot)
                ? $this->nonnegativeCentMoney($cart['pay_price'] ?? null)
                : $this->nonnegativeMoney($cart['pay_price'] ?? null);
        }
        if ($amount === null) {
            return [
                'purchaseAmount' => null,
                'remainingAmount' => null,
                'totalPurchaseTimes' => 0,
                'consumedTimesAtSelection' => 0,
                'calculationVersion' => $version,
            ];
        }
        $purchaseTimes = max(0, (int)($cart['write_times'] ?? 0));
        if ($purchaseTimes <= 0 || $remainingTimes > $purchaseTimes) {
            return [
                'purchaseAmount' => $amount,
                'remainingAmount' => null,
                'totalPurchaseTimes' => $purchaseTimes,
                'consumedTimesAtSelection' => 0,
                'calculationVersion' => $version,
            ];
        }
        $consumedTimes = $purchaseTimes - $remainingTimes;
        return [
            'purchaseAmount' => $amount,
            'remainingAmount' => CashierV3EntitlementActualAmountAllocator::remainingForSnapshot(
                $amount,
                $purchaseTimes,
                $consumedTimes,
                $snapshot
            ),
            'totalPurchaseTimes' => $purchaseTimes,
            'consumedTimesAtSelection' => $consumedTimes,
            'calculationVersion' => $version,
        ];
    }

    /**
     * Project the immutable actual sale allocation for a card component.
     * Configured project value remains a reporting weight only; it must not
     * replace the amount actually paid after a manual card-price change.
     *
     * @return array{purchaseAmount:?string,remainingAmount:?string,totalPurchaseTimes:int,consumedTimesAtSelection:int,calculationVersion:string}
     */
    private function projectActualSaleAmount(
        array $cart,
        int $remainingTimes,
        int $amountCents,
        array $snapshot
    ): array {
        $purchaseTimes = max(0, (int)($cart['write_times'] ?? 0));
        $version = 'card-sale-actual-amount-' . CashierV3EntitlementActualAmountAllocator::CALCULATION_VERSION;
        if ($amountCents < 0 || $purchaseTimes <= 0 || $remainingTimes > $purchaseTimes) {
            return [
                'purchaseAmount' => null,
                'remainingAmount' => null,
                'totalPurchaseTimes' => $purchaseTimes,
                'consumedTimesAtSelection' => 0,
                'calculationVersion' => $version,
            ];
        }
        $purchaseAmount = $this->centsToMoney($amountCents);
        $consumedTimes = $purchaseTimes - $remainingTimes;
        $allocationSnapshot = $snapshot;
        if ($amountCents % 100 !== 0) {
            // The final completion kernel reads this version to preserve cents;
            // selector and settlement must expose the same allocation contract.
            $version = 'operation-cent-' . $version;
            $allocationSnapshot['amountCalculationVersion'] = $version;
        }
        return [
            'purchaseAmount' => $purchaseAmount,
            'remainingAmount' => CashierV3EntitlementActualAmountAllocator::remainingForSnapshot(
                $purchaseAmount,
                $purchaseTimes,
                $consumedTimes,
                $allocationSnapshot
            ),
            'totalPurchaseTimes' => $purchaseTimes,
            'consumedTimesAtSelection' => $consumedTimes,
            'calculationVersion' => $version,
        ];
    }

    private function expiryDate(int $end): string
    {
        return $end > 0 ? date('Y-m-d', $end) : '';
    }

    /** @return array{purchaseAmount:?string,remainingAmount:?string,calculationVersion:string} */
    private function sourceAmounts(array $projects): array
    {
        $purchase = '0.00';
        $remaining = '0.00';
        $versions = [];
        $countedCombinationGroups = [];
        foreach ($projects as $project) {
            $groupNo = (int)($project['combinationGroupNo'] ?? 0);
            if (($project['cardRuleType'] ?? '') === 'combination'
                && ($project['combinationGroupRule'] ?? '') === 'choice_count') {
                if (isset($countedCombinationGroups[$groupNo])) continue;
                $countedCombinationGroups[$groupNo] = true;
            }
            $projectPurchase = $project['purchaseAmount'] ?? null;
            $projectRemaining = ($project['cardRuleType'] ?? '') === 'combination'
                ? ($project['combinationRawRemainingAmount'] ?? null)
                : ($project['remainingAmount'] ?? null);
            if (!is_string($projectPurchase) || !is_string($projectRemaining)) {
                return [
                    'purchaseAmount' => null,
                    'remainingAmount' => null,
                    'calculationVersion' => 'legacy-source-sum-v1',
                ];
            }
            $purchase = bcadd($purchase, $projectPurchase, 2);
            $remaining = bcadd($remaining, $projectRemaining, 2);
            $versions[(string)($project['amountCalculationVersion'] ?? '')] = true;
        }
        return [
            'purchaseAmount' => $purchase,
            'remainingAmount' => $remaining,
            'calculationVersion' => count($versions) === 1
                ? (string)array_key_first($versions)
                : 'legacy-source-sum-v1',
        ];
    }

    /** @return array{purchaseAmount:?string,remainingAmount:?string,calculationVersion:string} */
    private function timeCardSourceAmounts(array $order): array
    {
        $paid = $this->nonnegativeMoney($order['pay_price'] ?? null);
        if ($paid === null) {
            return [
                'purchaseAmount' => null,
                'remainingAmount' => null,
                'calculationVersion' => 'time-card-order-paid-v1-invalid',
            ];
        }
        return [
            'purchaseAmount' => $paid,
            'remainingAmount' => $paid,
            'calculationVersion' => 'time-card-order-paid-v1',
        ];
    }

    /**
     * The selector's “全部” view is an asset history view.  It must show an
     * existing card even when its origin order is no longer an entitlement
     * authority, but that row must never become selectable or receive a
     * resource-version context for a write command.
     */
    private function sourceOrderUnavailableReason(array $order): string
    {
        if ($order === []) {
            return '来源订单已不存在，当前不可使用';
        }
        if ((int)($order['card_upgrade_use_oid'] ?? 0) > 0) {
            return '已升级为其他卡项，当前不可使用';
        }
        // A migration carrier is hidden from ordinary orders with
        // is_system_del=1. Its holder still needs a separate scope grant before
        // it reaches this selector; no other deleted order becomes usable.
        $hiddenHistorical = (int)($order['store_id'] ?? -1) === 0
            && (string)($order['mark'] ?? '') === 'migration_entitlement'
            && (int)($order['is_system_del'] ?? 0) === 1;
        if ((int)($order['terminal_action'] ?? 0) !== 0
            || (int)($order['is_del'] ?? 0) !== 0
            || ((int)($order['is_system_del'] ?? 0) !== 0 && !$hiddenHistorical)
            || (int)($order['is_user_del'] ?? 0) !== 0) {
            return '原订单已作废，当前不可使用';
        }
        if ((int)($order['refund_status'] ?? 0) !== 0) {
            return '原订单已退款，当前不可使用';
        }
        if ((int)($order['paid'] ?? 0) !== 1) {
            return '原订单未完成支付，当前不可使用';
        }
        return '';
    }

    /** @return array{code:string,label:?string} */
    private function sourceKind(array $holder, array $projects): array
    {
        $allGift = count($projects) > 0;
        foreach ($projects as $project) {
            if (empty($project['isGift'])) {
                $allGift = false;
                break;
            }
        }
        if ($allGift) {
            return ['code' => 'gift', 'label' => '赠送'];
        }
        if ((int)($holder['product_type'] ?? 0) === 4) {
            return ['code' => 'count_card', 'label' => '次卡'];
        }
        // 历史表没有可复用的时间卡／定制卡分类列。write_valid 只是有效期规则，
        // 不能替代类别；未知来源宁可明确未知，也不能伪造四分类之一。
        return ['code' => 'unknown', 'label' => null];
    }

    /** @return array{start:int,end:int,label:string} */
    private function effectiveValidity(array $holder, array $cart): array
    {
        $holderStart = max(0, (int)($holder['write_start'] ?? 0));
        $holderEnd = max(0, (int)($holder['write_end'] ?? 0));
        $cartStart = max(0, (int)($cart['write_start'] ?? 0));
        $cartEnd = max(0, (int)($cart['write_end'] ?? 0));
        $start = max($holderStart, $cartStart);
        $ends = array_values(array_filter([$holderEnd, $cartEnd], static function (int $value): bool {
            return $value > 0;
        }));
        $end = $ends ? min($ends) : 0;
        if ($end > 0 && $start > $end) {
            return ['start' => $start, 'end' => $end, 'label' => '有效期异常'];
        }
        return ['start' => $start, 'end' => $end, 'label' => $this->validThroughLabel($start, $end)];
    }

    private function nonnegativeMoney($value): ?string
    {
        if (is_bool($value) || is_array($value) || is_object($value) || $value === null) {
            return null;
        }
        $raw = trim((string)$value);
        if (preg_match('/^(?:0|[1-9][0-9]*)(?:\.0{1,2})?$/D', $raw) !== 1) {
            return null;
        }
        return bcadd($raw, '0', 2);
    }

    private function nonnegativeCentMoney($value): ?string
    {
        if (is_bool($value) || is_array($value) || is_object($value) || $value === null) {
            return null;
        }
        $raw = trim((string)$value);
        if (preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/D', $raw) !== 1) {
            return null;
        }
        return bcadd($raw, '0', 2);
    }

    /** 展示 R49 取消的剩余欠款，绝不以原欠款、当前余额或已补交额代替取消事实。 */
    private function cancelledDebt(array $order, $debt, string $tenantId): string
    {
        // 显示与最终核销共用审计解析，禁止保留另一套取消金额计算。
        return (new CashierV3CardOriginDebtResolver())->cancelledCardDebt($order, (int)($order['uid'] ?? 0), $tenantId);
    }

    /** 当前可补交额仍使用原权威路径；新增取消展示不得改变核销与欠款业务口径。 */
    private function pendingDebt(array $order, $debt): string
    {
        $v3CardDebt = (new CashierV3CardOriginDebtResolver())->pendingV3CardDebt(
            $order,
            (int)($order['uid'] ?? 0)
        );
        if ($v3CardDebt !== null) {
            return $v3CardDebt;
        }
        if (is_array($debt)) {
            if ((int)$debt['status'] !== 0) {
                return '0.00';
            }
            $pending = bcsub((string)$debt['total_debt'], (string)$debt['repaid_debt'], 2);
            return bccomp($pending, '0', 2) > 0 ? $pending : '0.00';
        }
        $pending = bcsub((string)$order['debt_amount'], (string)$order['repaid_debt_amount'], 2);
        return bccomp($pending, '0', 2) > 0 ? $pending : '0.00';
    }

    private function writeoffServices(): WriteOffOrderServices
    {
        try {
            $service = app()->make(WriteOffOrderServices::class);
        } catch (\Throwable $exception) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ACTION_DEPENDENCY_NOT_READY,
                '权益次数与欠款校验服务未就绪，请稍后重试。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'writeoff_debt_service_missing']
            );
        }
        if (!$service instanceof WriteOffOrderServices) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ACTION_DEPENDENCY_NOT_READY,
                '权益次数与欠款校验服务未就绪，请稍后重试。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'writeoff_debt_service_invalid']
            );
        }
        return $service;
    }

    private function publicMember(array $member): array
    {
        $name = trim((string)($member['real_name'] ?? ''));
        if ($name === '') {
            $name = trim((string)($member['nickname'] ?? ''));
        }
        return [
            'id' => (int)$member['uid'],
            'memberId' => (int)$member['uid'],
            'name' => $name !== '' ? $name : '会员',
            'phone' => (string)$member['phone'],
            'avatar' => (string)$member['avatar'],
        ];
    }

    private function activeMember($row): bool
    {
        if (!$row || !is_array($row) || (int)($row['uid'] ?? 0) <= 0
            || (int)($row['status'] ?? 0) !== 1 || (int)($row['is_del'] ?? 0) !== 0) {
            return false;
        }
        $deleteTime = $row['delete_time'] ?? null;
        return $deleteTime === null || $deleteTime === '' || $deleteTime === 0 || $deleteTime === '0'
            || $deleteTime === '0000-00-00 00:00:00';
    }

    private function crossStoreEnabled(): bool
    {
        return CashierV3CrossStoreEntitlementPolicy::enabled();
    }

    private function workspaceId(string $stateContextId, CashierV3OperatorScope $operatorScope): string
    {
        if ($stateContextId === '') {
            throw new CashierV3CommandException(
                CashierV3ResultCode::CLIENT_SESSION_REQUIRED,
                '当前收银工作台会话无效，请刷新页面后重试。'
            );
        }
        return \app\services\cashier\v3\CashierV3CheckoutWorkspaceIdentity::id(
            $operatorScope->storeId(),
            $stateContextId
        );
    }

    private function positiveId($value, string $field): int
    {
        if (is_bool($value) || is_array($value) || $value === null) {
            throw $this->invalidLineField($field);
        }
        $raw = trim((string)$value);
        if (preg_match('/^[1-9][0-9]*$/', $raw) !== 1 || (string)(int)$raw !== $raw) {
            throw $this->invalidLineField($field);
        }
        return (int)$raw;
    }

    private function invalidLineField(string $field): CashierV3CommandException
    {
        return new CashierV3CommandException(
            CashierV3ResultCode::ENTITLEMENT_LINE_INVALID,
            '卡内项目明细无效，请重新打开后选择。',
            CashierV3ResultCode::STATUS_FAILED,
            ['field' => $field]
        );
    }

    private function validSelectorRequestId(string $requestId): bool
    {
        return preg_match(
            '/^ENTITLEMENT_SELECTOR-[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/',
            $requestId
        ) === 1;
    }

    public static function selectorBindingToken(
        string $stateContextId,
        string $workspaceId,
        int $memberId,
        string $requestId
    ): string {
        return hash('sha256', implode("\0", [
            'cashier-v3-entitlement-selector-v1',
            $stateContextId,
            $workspaceId,
            (string)$memberId,
            $requestId,
        ]));
    }

    private function validThroughLabel(int $start, int $end): string
    {
        if ($start <= 0 && $end <= 0) {
            return '长期有效';
        }
        if ($start > 0 && $end > 0) {
            return date('Y-m-d', $start) . ' 至 ' . date('Y-m-d', $end);
        }
        return $end > 0 ? '有效至 ' . date('Y-m-d', $end) : date('Y-m-d', $start) . ' 起可用';
    }

    /** @return array<int,array> */
    private function rows($rows): array
    {
        if (is_object($rows) && method_exists($rows, 'toArray')) {
            $rows = $rows->toArray();
        }
        return is_array($rows) ? array_values($rows) : [];
    }

    private function dedupeContexts(array $contexts): array
    {
        $out = [];
        foreach ($contexts as $context) {
            $key = (string)$context['kind'] . ':' . (string)$context['id'];
            $out[$key] = $context;
        }
        return array_values($out);
    }
}
