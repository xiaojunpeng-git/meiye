<?php

namespace app\services\cashier\v3\member;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\card\CashierV3CardRuleEntitlementAuthorityServices;
use app\services\cashier\v3\card\CashierV3CardComponentPurchaseAmountAuthority;
use app\services\cashier\v3\card\CashierV3CardSaleActualAmountServices;
use app\services\cashier\v3\cashier\CashierV3EntitlementActualAmountAllocator;
use app\services\cashier\v3\order\CashierV3CardRefundQuotaPolicy;
use app\services\cashier\v3\checkout\provider\CashierV3MemberBalanceProvider;
use app\services\query\metric\StoreCustomerVisitEligibility;
use mohe\services\SystemConfigService;
use think\facade\Db;

/**
 * 会员详情的只读资产投影。
 *
 * 旧卡的当前权益仍以 user_card_holder + store_order_cart_info 为权威源；这里不
 * 创建 V3 影子版本，也不修改任何权益、订单或事实记录。会员列表摘要和详情资产
 * 采用同一套有效订单、有效期和剩余次数过滤，避免出现摘要有次数而资产页为空。
 */
final class CashierV3MemberDetailQueryServices
{
    /**
     * One card's operation timeline uses its physical holder and benefit-detail IDs.
     * The member-wide card-operation tab cannot safely stand in for this history:
     * writeoffs and refunds are facts in other tables, and card numbers may repeat.
     */
    public function cardHistory(int $memberId, int $holderId, int $page,
        CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope): array
    {
        $this->assertVisible($memberId, $operator, $scope);
        $holder = (array)Db::name('user_card_holder')->where('id', $holderId)
            ->where('uid', $memberId)->find();
        if (!$holder) throw $this->notFound();
        $order = (array)Db::name('store_order')->where('id', (int)$holder['oid'])
            ->where('uid', $memberId)->find();
        if (!$order) throw $this->notFound();
        $tenantId = $scope->tenantId();
        $detailIds = array_map('intval', Db::name('store_order_cart_info')
            ->where('oid', (int)$holder['oid'])->where('cart_type', 2)->where('product_type', 6)->column('id'));
        // A V3 card purchase belongs to one issued holder and one immutable sales line.
        // Legacy cards have no receipt, so their missing line remark remains blank.
        $purchaseRemark = (string)(Db::name('cashier_v3_card_purchase_receipt')->alias('receipt')
            ->leftJoin('cashier_v3_sales_order_line line',
                'line.tenant_id=receipt.tenant_id AND line.order_line_id=receipt.sales_order_line_id')
            ->where('receipt.tenant_id', $tenantId)->where('receipt.card_holder_id', $holderId)
            ->value('line.detail_remark_snapshot') ?? '');
        $rows = [];
        $append = static function (string $key, string $type, string $content, string $amount,
            string $date, int $time, string $store, string $operatorName, string $result,
            string $detailRemark = '') use (&$rows): void {
            $rows[] = compact('key', 'type', 'content', 'amount', 'date', 'time', 'store', 'operatorName', 'result', 'detailRemark');
        };
        // History can show the source purchase amount, but settlement and
        // refundable value keep using the zero-valued technical carrier.
        $oldPaid = \app\services\cashier\v3\card\CashierV3HistoricalEntitlementScopePolicy::sourcePaidPrice(
            $holderId, $tenantId
        );
        $append('card:' . $holderId, '购卡', (string)$holder['card_name'],
            $oldPaid ?? (string)($order['pay_price'] ?? '0.00'), date('Y-m-d', (int)$holder['add_time']),
            (int)$holder['add_time'], (string)Db::name('system_store')->where('id', (int)$holder['store_id'])->value('name'),
            '', '卡号：' . (string)$holder['card_no'], $purchaseRemark);
        // Non-cart card operations retain their own reason or collection note;
        // do not borrow the originating sale line's remark for a later event.
        foreach (Db::name('cashier_v3_card_operation')->where('tenant_id', $tenantId)
            ->where('source_card_holder_id', $holderId)->select()->toArray() as $row) {
            $append('operation:' . $row['operation_id'], $this->cardOperationTypeLabel((string)$row['operation_type']),
                (string)$row['target_catalog_name_snapshot'], $this->moneyFromCents((int)$row['settlement_delta_cents']),
                (string)$row['business_date'], (int)$row['occurred_at'], (string)$row['store_name_snapshot'],
                (string)$row['operator_name_snapshot'], (string)$row['reason_snapshot'], (string)$row['reason_snapshot']);
        }
        foreach (Db::name('cashier_v3_refund_adjustment')->where('tenant_id', $tenantId)
            ->where('card_holder_id', $holderId)->where('status', 'effective')->select()->toArray() as $row) {
            $append('refund:' . $row['adjustment_id'], '退款', (string)$row['target_name_snapshot'],
                $this->moneyFromCents((int)$row['refund_amount_cents']), (string)$row['business_date'],
                (int)$row['occurred_at'], (string)Db::name('system_store')->where('id', (int)$row['store_id'])->value('name'),
                (string)Db::name('system_store_staff')->where('id', (int)$row['operator_id'])->value('staff_name'),
                ((int)$row['void_target'] ? '作废本卡' : '不作废') . '；' . (string)$row['reason_snapshot'],
                (string)$row['reason_snapshot']);
        }
        if ($detailIds !== []) {
            $used = Db::name('cashier_v3_entitlement_service_fact')->alias('sf')
                ->join('cashier_v3_checkout_line_draft dl',
                    'dl.line_id=sf.source_line_id AND dl.tenant_id=sf.tenant_id AND dl.draft_status=\'committed\'')
                ->where('sf.tenant_id', $tenantId)->where('sf.member_id', $memberId)
                ->whereIn('dl.entitlement_source_detail_id', $detailIds)
                ->field('sf.service_fact_id,sf.project_name_snapshot,sf.quantity,sf.business_date,sf.occurred_at,sf.store_name_snapshot,sf.operator_name_snapshot,sf.detail_remark_snapshot')
                ->select()->toArray();
            foreach ($used as $row) {
                $append('writeoff:' . $row['service_fact_id'], '核销',
                    (string)$row['project_name_snapshot'] . ' ×' . (int)$row['quantity'], '',
                    (string)$row['business_date'], (int)$row['occurred_at'],
                    (string)$row['store_name_snapshot'], (string)$row['operator_name_snapshot'], '已完成',
                    (string)($row['detail_remark_snapshot'] ?? ''));
            }
        }
        $debts = Db::name('store_debt')->where('order_id', (int)$holder['oid'])->column('id');
        if ($debts !== []) {
            $repayments = Db::name('cashier_v3_debt_repayment')->where('tenant_id', $tenantId)
                ->whereIn('debt_id', $debts)->where('status', 'succeeded')->select()->toArray();
            $repaymentRemarks = [];
            $repaymentIds = array_column($repayments, 'repayment_id');
            if ($repaymentIds !== []) {
                // Repayment remarks belong to collection lines, not the repayment header.
                foreach (Db::name('cashier_v3_debt_repayment_collection')->whereIn('repayment_id', $repaymentIds)
                    ->field('repayment_id,remark_snapshot')->order('payment_line_no asc')->select()->toArray() as $collection) {
                    $remark = trim((string)$collection['remark_snapshot']);
                    if ($remark !== '') $repaymentRemarks[(string)$collection['repayment_id']][$remark] = $remark;
                }
            }
            foreach ($repayments as $row) {
                $append('repayment:' . $row['repayment_id'], '补交', (string)$row['repayment_no'],
                    $this->moneyFromCents((int)$row['repayment_amount_cents']), (string)$row['business_date'],
                    (int)$row['occurred_at'], (string)$row['store_name_snapshot'],
                    (string)$row['operator_name_snapshot'], '已完成',
                    implode('；', $repaymentRemarks[(string)$row['repayment_id']] ?? []));
            }
            foreach (Db::name('cashier_v3_debt_cancellation')->where('tenant_id', $tenantId)
                ->whereIn('debt_id', $debts)->select()->toArray() as $row) {
                $append('debt-cancel:' . $row['cancellation_id'], '取消欠款', (string)$row['debt_no_snapshot'],
                    $this->moneyFromCents((int)$row['cancelled_debt_cents']), (string)$row['business_date'],
                    (int)$row['occurred_at'], (string)$row['store_name_snapshot'],
                    (string)$row['operator_name_snapshot'], (string)$row['reason_snapshot'], (string)$row['reason_snapshot']);
            }
        }
        usort($rows, static fn(array $a, array $b): int => [$b['time'], $b['key']] <=> [$a['time'], $a['key']]);
        $page = max(1, $page);
        return ['holderId' => $holderId, 'cardNo' => (string)$holder['card_no'],
            'records' => array_slice($rows, ($page - 1) * 50, 50),
            'total' => count($rows), 'page' => $page, 'pageSize' => 50];
    }
    /**
     * AI uses the same authoritative balance/card readers as cashier detail,
     * but does not load unrelated service, care or history tabs for each member.
     * The belonging store is the member's actual registration value: an unset
     * store must never be replaced with the operator's current store.
     */
    public function readAiRights(
        int $memberId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        return $this->readAiRightsSet([$memberId],$operatorScope,$dataScope)[$memberId];
    }

    /**
     * AI set reads share the authoritative balance and card projections used
     * by a single detail; every identity is checked before assembling rows.
     * The caller must supply only identities revalidated against the live
     * member/store relation; no partial set is returned on a missing member.
     *
     * @param array<int,int> $memberIds
     * @return array<int,array<string,mixed>>
     */
    public function readAiRightsSet(array $memberIds,CashierV3OperatorScope $operatorScope,CashierV3DataScopeContext $dataScope,?callable $checkpoint=null): array
    {
        // Deny an unusable detail scope before the shared balance query.
        foreach ($memberIds as $memberId) {
            if (!is_int($memberId) || $memberId<=0) throw $this->notFound();
            $this->assertAiVisible($memberId, $dataScope);
        }
        $details=[];
        // The balance authority accepts at most 100 identities per read. Fetch
        // member labels, stores and cards at the same batch grain so a large
        // verified result does not turn into three queries per member.
        $asOf=date('c');
        foreach (array_chunk($memberIds,100) as $batch) {
            if ($checkpoint!==null) $checkpoint();
            $snapshots=(new CashierV3MemberBalanceProvider())->readSnapshots($batch,$operatorScope,$dataScope);
            $members=Db::name('user')->whereIn('uid',$batch)
                ->field('uid,nickname,real_name,belong_store_id,status,is_del,delete_time')->select()->toArray();
            $membersById=[];$storeIds=[];
            foreach ($members as $member) {
                $membersById[(int)$member['uid']]=$member;
                if ((int)$member['belong_store_id']>0) $storeIds[]=(int)$member['belong_store_id'];
            }
            $storeNames=$storeIds?Db::name('system_store')->whereIn('id',array_unique($storeIds))->column('name','id'):[];
            $cardsByMember=$this->cardsForMembers($batch,$operatorScope->tenantId(),'','active','','',$operatorScope->storeId());
            foreach ($batch as $memberId) {
                if ($checkpoint!==null) $checkpoint();
                $details[$memberId]=$this->readAiRightsWithBalance($memberId,$dataScope,
                    $snapshots[$memberId],$cardsByMember[$memberId]??[], $membersById[$memberId]??null,$storeNames,$asOf);
            }
        }
        return $details;
    }

    /**
     * Read a complete, authorized member-summary set without returning card
     * rows. The live cashier card projection is authoritative: an index may
     * lag a source change, so its cached amount must not be presented as the
     * current detail or used to silently replace the exported screen value.
     * Explicit card-rights views still use readAiRightsSet().
     */
    public function readAiRightsSummarySet(array $memberIds,CashierV3OperatorScope $operatorScope,CashierV3DataScopeContext $dataScope,?callable $checkpoint=null): array
    {
        if ($memberIds===[] || count($memberIds)>\app\services\query\metric\RegisteredMetricReadServices::rightsDetailLimit()
            || count(array_unique($memberIds))!==count($memberIds)) throw $this->notFound();
        foreach ($memberIds as $memberId) {
            if (!is_int($memberId) || $memberId<=0) throw $this->notFound();
            $this->assertAiVisible($memberId,$dataScope);
        }
        $details=[];$asOf=date('c');
        // The versioned rights index contains these exact cashier-projected
        // card totals. Recheck every signed member against the current store
        // scope and overlay dirty/time-transitioned rows before using it;
        // without a ready index, retain the live cashier authority below.
        $visibleStores=$dataScope->visibleStoreIds();
        if ($dataScope->authorizationMode()===CashierV3DataScopeContext::MODE_ALL) {
            $visibleStores=array_map('intval',Db::name('system_store')->column('id'));
        }
        $indexedSummaries=is_array($visibleStores) && $visibleStores!==[]
            ?(new \app\services\query\metric\AiMemberRightsAmountIndex())->summaryForMembers(
                $operatorScope->tenantId(),$visibleStores,$memberIds):null;
        foreach (array_chunk($memberIds,100) as $batch) {
            if ($checkpoint!==null) $checkpoint();
            // A live fallback still computes the same three totals in batches
            // and never exposes card rows in the summary/Excel projection.
            $cardSummaries=$indexedSummaries!==null
                ?array_intersect_key($indexedSummaries,array_fill_keys($batch,true))
                :$this->readAiRightsSummariesForIndex($batch,$operatorScope->tenantId());
            $snapshots=(new CashierV3MemberBalanceProvider())->readSnapshots($batch,$operatorScope,$dataScope);
            $members=Db::name('user')->whereIn('uid',$batch)
                ->field('uid,nickname,real_name,belong_store_id,status,is_del,delete_time')->select()->toArray();
            $byId=[];$storeIds=[];
            foreach ($members as $member) {
                $byId[(int)$member['uid']]=$member;
                if ((int)$member['belong_store_id']>0) $storeIds[]=(int)$member['belong_store_id'];
            }
            $storeNames=$storeIds?Db::name('system_store')->whereIn('id',array_unique($storeIds))->column('name','id'):[];
            foreach ($batch as $id) {
                // A batch is bounded to 100 members. Check once per batch and
                // again before publication; per-row run/permission reads used
                // to consume the entire answer budget for large export sets.
                if (!isset($cardSummaries[$id],$snapshots[$id])) throw $this->notFound();
                $details[$id]=$this->readAiRightsWithBalance($id,$dataScope,$snapshots[$id],[],
                    $byId[$id]??null,$storeNames,$asOf,$cardSummaries[$id]);
            }
        }
        return $details;
    }

    /** Assemble only authority-validated, batch-read balance and card rows. */
    private function readAiRightsWithBalance(int $memberId,CashierV3DataScopeContext $dataScope,array $balanceSnapshot,array $cards,?array $member,array $storeNames,string $asOf,?array $cardSummary=null): array
    {
        if ($memberId <= 0) throw $this->notFound();
        $this->assertAiVisible($memberId, $dataScope);
        if (!$this->isActiveMember($member)) throw $this->notFound();

        if ($cardSummary!==null) {
            $remainingTimes=$cardSummary['remaining_project_times'];
            $remainingAmount=$this->moneyFromCents($cardSummary['amount_cents']);
            $activeCardCount=$cardSummary['active_card_count'];
        } else {
            $remainingTimes = 0;
            $remainingAmount = '0.00';
            $activeCardCount = 0;
            foreach ($cards as $card) {
                if (($card['statusCode'] ?? '') !== 'enabled') continue;
                $activeCardCount++;
                if (($card['cardRuleType'] ?? '') !== 'time') $remainingTimes += (int)($card['debtLimitedRemainingTimes'] ?? $card['remainingTimes'] ?? 0);
                $remainingAmount = bcadd($remainingAmount, (string)($card['remainingAmount'] ?? '0.00'), 2);
            }
        }
        $storeId = (int)($member['belong_store_id'] ?? 0);
        $storeName = $storeId > 0 ? (string)($storeNames[$storeId]??'') : '';
        $name = trim((string)($member['real_name'] ?? ''));
        if ($name === '') $name = trim((string)($member['nickname'] ?? ''));
        return [
            'member' => [
                'memberId' => $memberId, 'name' => $name !== '' ? $name : '未命名会员',
                'storeId' => $storeId, 'storeName' => $storeName,
            ],
            'summary' => [
                'accountBalance' => $this->moneyFromCents((int)$balanceSnapshot['totalCents']),
                'principalBalance' => $this->moneyFromCents((int)$balanceSnapshot['principalCents']),
                'giftBalance' => $this->moneyFromCents((int)$balanceSnapshot['giftCents']),
                'activeCardCount' => $activeCardCount,
                'remainingProjectTimes' => $remainingTimes,
                'remainingProjectAmount' => $remainingAmount,
            ],
            'cards' => $cards,
            'dataAsOf' => $asOf,
            'projectionContractVersion' => 'cashier-v3-member-detail-v3',
        ];
    }

    public function read(
        int $memberId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope,
        array $detailQuery = []
    ): array {
        if ($memberId <= 0) {
            throw $this->notFound();
        }
        $this->assertVisible($memberId, $operatorScope, $dataScope);

        $member = Db::name('user')
            ->where('uid', $memberId)
            ->field('uid,nickname,real_name,phone,bar_code,belong_store_id,integral,level,sex,birthday,card_id,wechat_account,addres,mark,add_time,extend_info,status,is_del,delete_time')
            ->find();
        if (!$this->isActiveMember($member)) {
            throw $this->notFound();
        }

        $storeId = (int)($member['belong_store_id'] ?? 0);
        if ($storeId <= 0) {
            $storeId = $operatorScope->storeId();
        }
        $detailQuery = $this->detailQuery($detailQuery);
        // Explicit tab reads return the active tab and shared summary only.
        // A missing tab keeps the complete legacy projection for callers that
        // intentionally request it; no business authority or scope changes.
        $tab = $detailQuery['tab'];
        $loadAll = $tab === '';
        $cards = $this->cards($memberId, $operatorScope->tenantId(), $detailQuery['tab'] === 'assets' ? $detailQuery['keyword'] : '', $detailQuery['tab'] === 'assets' ? $detailQuery['status'] : 'active', $detailQuery['tab'] === 'assets' && $detailQuery['status'] === '' ? $detailQuery['dateFrom'] : '', $detailQuery['tab'] === 'assets' && $detailQuery['status'] === '' ? $detailQuery['dateTo'] : '', $operatorScope->storeId());
        $services = ($loadAll || $tab === 'writeoff')
            ? $this->services($memberId, $operatorScope->tenantId(), $tab === 'writeoff' ? $detailQuery['keyword'] : '', $tab === 'writeoff' ? $detailQuery['dateFrom'] : '', $tab === 'writeoff' ? $detailQuery['dateTo'] : '')
            : null;
        // “有效”代表会员当前仍持有的赠送/预售，不受查询区缓存的日期限制；
        // “全部”才允许按业务日期追查历史。后端也强制此口径，避免旧前端误传日期。
        $giftStatus = $tab === 'gift' ? $detailQuery['status'] : 'active';
        $gifts = ($loadAll || $tab === 'gift')
            ? $this->gifts($memberId, $operatorScope->tenantId(),
                $tab === 'gift' ? $detailQuery['keyword'] : '', $giftStatus,
                $tab === 'gift' && $giftStatus === '' ? $detailQuery['dateFrom'] : '',
                $tab === 'gift' && $giftStatus === '' ? $detailQuery['dateTo'] : '')
            : null;
        $care = ($loadAll || $tab === 'profile' || $tab === 'care')
            ? $this->care($memberId, $operatorScope->tenantId(), $tab === 'care' ? $detailQuery['keyword'] : '', $tab === 'care' ? $detailQuery['status'] : '', $tab === 'care' && $detailQuery['status'] === '' ? $detailQuery['dateFrom'] : '', $tab === 'care' && $detailQuery['status'] === '' ? $detailQuery['dateTo'] : '')
            : null;
        $balanceChanges = ($loadAll || $tab === 'balance-changes')
            ? $this->balanceChanges($memberId, $operatorScope->tenantId())
            : null;
        // 券实例属于会员当前持有权益；只在优惠券页签读取，不能从赠送事实反推可用券。
        $coupons = $detailQuery['tab'] === 'coupons'
            ? $this->coupons($memberId, $detailQuery['keyword'], $detailQuery['status'],
                $detailQuery['status'] === '' ? $detailQuery['dateFrom'] : '',
                $detailQuery['status'] === '' ? $detailQuery['dateTo'] : '')
            : [];
        $cardOperations = ($loadAll || $tab === 'card-operations')
            ? $this->cardOperations($memberId, $operatorScope->tenantId(), $tab === 'card-operations' ? $detailQuery['keyword'] : '')
            : null;
        $overview = $this->overview($memberId, $operatorScope->tenantId());
        $remainingTimes = 0;
        $remainingAmount = '0.00';
        $activeCardCount = 0;
        foreach ($cards as $card) {
            if (($card['statusCode'] ?? '') !== 'enabled') continue;
            $activeCardCount++;
            if (($card['cardRuleType'] ?? '') !== 'time') {
                $remainingTimes += (int)($card['debtLimitedRemainingTimes'] ?? $card['remainingTimes'] ?? 0);
            }
            $remainingAmount = bcadd($remainingAmount, (string)($card['remainingAmount'] ?? '0.00'), 2);
        }

        $name = trim((string)($member['real_name'] ?? ''));
        if ($name === '') {
            $name = trim((string)($member['nickname'] ?? ''));
        }
        $storeName = $storeId > 0
            ? (string)Db::name('system_store')->where('id', $storeId)->value('name')
            : '';
        $levelName = (int)($member['level'] ?? 0) > 0
            ? (string)Db::name('system_user_level')->where('id', (int)$member['level'])->value('name')
            : '普通会员';
        $tagNames = $this->memberTagNames($memberId);
        $balanceSnapshot = (new CashierV3MemberBalanceProvider())->readSnapshot(
            $memberId,
            $operatorScope,
            $dataScope
        );
        $balance = $this->moneyFromCents((int)$balanceSnapshot['totalCents']);
        $principalBalance = $this->moneyFromCents((int)$balanceSnapshot['principalCents']);
        $giftBalance = $this->moneyFromCents((int)$balanceSnapshot['giftCents']);

        $detail = [
            'member' => [
                'id' => (string)$memberId,
                'memberId' => $memberId,
                'name' => $name !== '' ? $name : '未命名会员',
                'phone' => (string)($member['phone'] ?? ''),
                'memberNo' => (string)($member['bar_code'] ?? ''),
                'statusLabel' => '正常',
                'storeId' => $storeId,
                'storeName' => $storeName,
                'accountBalance' => $balance,
                'principalBalance' => $principalBalance,
                'giftBalance' => $giftBalance,
                'currentPoints' => (int)($member['integral'] ?? 0),
                'balanceVersion' => (int)$balanceSnapshot['accountVersion'],
            ],
            'summary' => [
                'accountBalance' => $balance,
                'principalBalance' => $principalBalance,
                'giftBalance' => $giftBalance,
                'cardBenefitAmount' => $remainingAmount,
                'remainingProjectTimes' => $remainingTimes,
                'remainingProjectAmount' => $remainingAmount,
                'activeCardCount' => $activeCardCount,
                'totalRechargeAmount' => $overview['totalRechargeAmount'],
                'totalConsumptionAmount' => $overview['totalConsumptionAmount'],
                'latestPurchaseDate' => $overview['latestPurchaseDate'],
                'visitCount' => $overview['visitCount'],
                'latestVisitDate' => $overview['latestVisitDate'],
            ],
            'profile' => [
                'memberLevel' => $levelName,
                'genderLabel' => $this->genderLabel((int)($member['sex'] ?? 0)),
                'birthday' => $this->dateOnly((int)($member['birthday'] ?? 0)),
                'idCard' => (string)($member['card_id'] ?? ''),
                'wechat' => (string)($member['wechat_account'] ?? ''),
                'tags' => $tagNames,
                'createdAt' => $this->dateTime((int)($member['add_time'] ?? 0)),
                'address' => (string)($member['addres'] ?? ''),
                'remark' => (string)($member['mark'] ?? ''),
                'customFields' => $this->customFields($member['extend_info'] ?? ''),
                'relation' => [
                    'storeName' => $storeName,
                    'exclusiveServiceStaff' => '',
                    'updatedAt' => '',
                ],
            ],
            'cards' => $cards,
            'coupons' => $coupons,
            'latestVisit' => $overview['latestVisit'],
            'tabStates' => [
                'profile' => ['total' => 1, 'dataStatus' => 'authoritative'],
                'assets' => ['total' => count($cards), 'dataStatus' => 'authoritative'],
                'coupons' => ['total' => count($coupons), 'dataStatus' => 'authoritative'],
            ],
            'dataAsOf' => date('c'),
            'detailQuery' => $detailQuery,
            'projectionContractVersion' => 'cashier-v3-member-detail-v3',
        ];
        if ($balanceChanges !== null) {
            // 余额记录只读取 V3 不可变 balance_changed 事实；本金和赠金拆分不从页面反推。
            $detail['balanceChanges'] = $balanceChanges;
            $detail['tabStates']['balance-changes'] = ['total' => count($balanceChanges), 'dataStatus' => 'authoritative'];
        }
        if ($cardOperations !== null) {
            $detail['cardOperations'] = $cardOperations;
            $detail['tabStates']['card-operations'] = ['total' => count($cardOperations), 'dataStatus' => 'authoritative'];
        }
        if ($services !== null) {
            $detail['serviceRecords'] = $services;
            $detail['tabStates']['writeoff'] = ['total' => count($services), 'dataStatus' => 'authoritative'];
        }
        if ($gifts !== null) {
            $detail['giftRecords'] = $gifts;
            $detail['tabStates']['gift'] = ['total' => count($gifts), 'dataStatus' => 'authoritative'];
        }
        if ($care !== null) {
            $detail['careRecords'] = $care['records'];
            $detail['careTasks'] = $care['tasks'];
            $detail['careReminder'] = $care['reminder'];
            $detail['tabStates']['care'] = ['total' => count($care['records']), 'dataStatus' => 'authoritative'];
        }
        return $detail;
    }

    /**
     * Read the member-owned coupon instances, including direct/recharge gifts and transfers.
     * Gift documents are issuance history, not spendable entitlement authority; status and
     * validity always come from the current coupon instance. A bounded list avoids loading
     * an unbounded lifetime history into every member-detail request.
     *
     * @return array<int,array<string,mixed>>
     */
    private function coupons(int $memberId, string $keyword, string $status, string $dateFrom, string $dateTo): array
    {
        $now = time();
        $query = Db::name('store_coupon_user')->alias('cu')
            ->leftJoin('store_coupon_issue ci', 'ci.id=cu.cid')
            ->where('cu.uid', $memberId);
        if ($status === 'active') {
            $query->where('cu.status', 0)->where('cu.is_fail', 0)
                ->where(function ($q): void { $q->where('cu.use_time', 0)->whereOr('ci.coupon_type', 3); })
                ->where(function ($q) use ($now): void { $q->where('cu.start_time', 0)->whereOr('cu.start_time', '<=', $now); })
                ->where(function ($q) use ($now): void { $q->where('cu.end_time', 0)->whereOr('cu.end_time', '>=', $now); });
        }
        $this->applyDetailKeyword($query, $keyword, ['cu.coupon_title']);
        if ($dateFrom !== '') $query->where('cu.add_time', '>=', strtotime($dateFrom . ' 00:00:00'));
        if ($dateTo !== '') $query->where('cu.add_time', '<=', strtotime($dateTo . ' 23:59:59'));
        $rows = $query->field('cu.id,cu.coupon_title,cu.coupon_price,cu.start_time,cu.end_time,cu.use_time,cu.status,cu.is_fail,ci.coupon_type')
            ->order('cu.add_time desc,cu.id desc')->limit(200)->select()->toArray();
        // Used timestamps do not exhaust an allowance; read its current account
        // without rewriting the original coupon face or renewing validity.
        $ids = array_column(array_filter($rows, static fn(array $row): bool => (int)($row['coupon_type'] ?? 0) === 3), 'id');
        $remaining = (new \app\services\activity\coupon\StoreCouponAllowanceServices())->remainingByCouponIds($ids);
        return array_map(function (array $row) use ($now, $remaining): array {
            $couponType = $row['coupon_type'] === null ? 0 : (int)$row['coupon_type'];
            $start = (int)$row['start_time'];
            $end = (int)$row['end_time'];
            $statusCode = (int)$row['status'];
            $used = $statusCode === 1 || ($couponType !== 3 && (int)$row['use_time'] > 0);
            $voided = (int)$row['is_fail'] !== 0 || $statusCode === 2;
            $statusLabel = $voided ? '已作废' : ($used ? '已使用'
                : ($statusCode !== 0 ? '不可用'
                    : ($end > 0 && $end < $now ? '已过期' : ($start > $now ? '未生效' : '有效'))));
            return [
                'id' => 'coupon:' . (int)$row['id'], 'couponNo' => (string)$row['id'],
                'couponName' => (string)$row['coupon_title'],
                'couponTypeLabel' => $couponType === 3 ? '多次抵用券' : ($couponType === 2 ? '折扣券' : ($couponType === 1 ? '满减券' : '未标注')),
                // 折扣券的 coupon_price 是百分比而非金额；不可格式化成元。
                'couponAmountLabel' => $couponType === 2
                    ? rtrim(rtrim((string)$row['coupon_price'], '0'), '.') . '%'
                    : ($couponType === 3 ? '剩余 ¥' . intdiv((int)($remaining[(int)$row['id']]
                        ?? \app\services\activity\coupon\StoreCouponAllowanceServices::wholeCents($row['coupon_price'])), 100)
                        : ($couponType === 0 ? '未标注' : null)),
                'couponAmount' => $couponType === 1 ? (string)$row['coupon_price'] : null,
                'effectiveAt' => $start > 0 ? $this->dateTime($start) : null,
                'expiresAt' => $end > 0 ? $this->dateTime($end) : null,
                'statusLabel' => $statusLabel,
            ];
        }, $rows);
    }

    /** @return array<int,array<string,mixed>> */
    private function services(int $memberId, string $tenantId, string $keyword = '', string $dateFrom = '', string $dateTo = ''): array
    {
        $query = Db::name('cashier_v3_entitlement_service_fact')->alias('sf')
            ->leftJoin(
                'cashier_v3_entitlement_writeoff_fact wf',
                'wf.tenant_id = sf.tenant_id AND wf.checkout_request_id = sf.checkout_request_id AND wf.source_line_id = sf.source_line_id'
            )
            ->where('sf.tenant_id', $tenantId)->where('sf.member_id', $memberId)
            ->where('sf.service_status', 'completed');
        // 会员详情按会员汇集各店已完成服务；结账与核销的门店授权仍由写命令校验。
        $this->applyDetailKeyword($query, $keyword, [
            'sf.service_record_no', 'sf.project_name_snapshot', 'sf.store_name_snapshot',
            'sf.operator_name_snapshot', 'sf.craftsmen_snapshot_json', 'sf.document_no_snapshot',
            'wf.source_name_snapshot', 'wf.source_code_snapshot',
        ]);
        if ($dateFrom !== '') $query->where('sf.business_date', '>=', $dateFrom);
        if ($dateTo !== '') $query->where('sf.business_date', '<=', $dateTo);
        $rows = $query
            ->field(implode(',', [
                'sf.id', 'sf.checkout_request_id', 'sf.source_line_id', 'sf.service_record_no',
                'sf.project_name_snapshot', 'wf.source_name_snapshot AS source_name_snapshot',
                'wf.source_code_snapshot AS source_code_snapshot', 'sf.store_name_snapshot',
                'sf.quantity', 'sf.craftsmen_snapshot_json', 'sf.service_status',
                'sf.detail_remark_snapshot',
                'sf.business_date', 'sf.settled_at', 'sf.occurred_at',
                'sf.operator_name_snapshot', 'sf.document_no_snapshot',
            ]))
            ->order('sf.settled_at desc,sf.id desc')->limit(100)->select()->toArray();
        $laborByServiceLine = $this->laborPerformanceByServiceLine($rows, $tenantId);
        return array_map(function (array $row) use ($laborByServiceLine): array {
            $labor = $laborByServiceLine[$this->serviceLineKey($row)] ?? null;
            return [
                'id' => 'service:' . (string)$row['id'],
                // Old ESF ids intentionally never leave the authority table.
                'serviceNo' => (string)($row['service_record_no'] ?? ''),
                'businessDate' => (string)$row['business_date'],
                'projectName' => (string)$row['project_name_snapshot'],
                'sourceLabel' => '卡项权益服务',
                'sourceNo' => (string)($row['document_no_snapshot'] ?? ''),
                'cardName' => (string)($row['source_name_snapshot'] ?? ''),
                'cardNo' => (string)($row['source_code_snapshot'] ?? ''),
                'usedTimes' => (int)$row['quantity'],
                'storeName' => (string)$row['store_name_snapshot'],
                'craftsmenSummary' => $this->craftsmenSummary((string)($row['craftsmen_snapshot_json'] ?? ''), (array)($labor['names'] ?? [])),
                'laborPerformanceAmount' => $labor === null ? null : $this->moneyFromCents((int)$labor['amountCents']),
                'laborPerformanceStatus' => $labor === null ? 'pending' : 'recorded',
                // A service's cart-line note is persisted on its own completed fact.
                'detailRemark' => (string)($row['detail_remark_snapshot'] ?? ''),
                'operatorName' => (string)$row['operator_name_snapshot'],
                'statusLabel' => '已完成',
                'completedAt' => $this->dateTime((int)($row['settled_at'] ?: $row['occurred_at'])),
            ];
        }, $rows);
    }

    /**
     * 劳动业绩的粒度是“一个完成服务明细分给一名手艺人的一笔事实”。不能按
     * 订单总额、当前规则或页面人员临时重算；服务与业绩只通过同一完成事务留下的
     * checkout_request_id + source_line_id 关联。
     *
     * @param array<int,array<string,mixed>> $serviceRows
     * @return array<string,array{amountCents:int,names:array<int,string>}>
     */
    private function laborPerformanceByServiceLine(array $serviceRows, string $tenantId): array
    {
        $checkoutIds = [];
        $lineIds = [];
        foreach ($serviceRows as $row) {
            $checkoutId = trim((string)($row['checkout_request_id'] ?? ''));
            $lineId = trim((string)($row['source_line_id'] ?? ''));
            if ($checkoutId === '' || $lineId === '') continue;
            $checkoutIds[$checkoutId] = $checkoutId;
            $lineIds[$lineId] = $lineId;
        }
        if ($checkoutIds === [] || $lineIds === []) return [];

        $facts = Db::name('cashier_v3_performance_fact')
            ->where('tenant_id', $tenantId)
            ->where('performance_type', 'labor_performance_allocated')
            ->where('fact_direction', 'forward')
            ->where('status', 'effective')
            ->whereIn('checkout_request_id', array_values($checkoutIds))
            ->whereIn('source_line_id', array_values($lineIds))
            ->field('checkout_request_id,source_line_id,amount_cents,employee_name_snapshot,role_snapshot')
            ->select()->toArray();
        $result = [];
        foreach ($facts as $fact) {
            $key = $this->serviceLineKey($fact);
            if ($key === '') continue;
            if (!isset($result[$key])) $result[$key] = ['amountCents' => 0, 'names' => []];
            $result[$key]['amountCents'] += (int)($fact['amount_cents'] ?? 0);
            $name = trim((string)($fact['employee_name_snapshot'] ?? ''));
            if ($name !== '' && !in_array($name, $result[$key]['names'], true)) {
                $role = strtolower(trim((string)($fact['role_snapshot'] ?? '')));
                $result[$key]['names'][] = str_contains($role, ':point') || str_contains($role, 'point')
                    ? $name . '（点）'
                    : ($role !== '' ? $name . '（轮）' : $name);
            }
        }
        return $result;
    }

    private function serviceLineKey(array $row): string
    {
        $checkoutId = trim((string)($row['checkout_request_id'] ?? ''));
        $lineId = trim((string)($row['source_line_id'] ?? ''));
        return $checkoutId === '' || $lineId === '' ? '' : $checkoutId . ':' . $lineId;
    }

    /** @return array<int,array<string,mixed>> */
    private function balanceChanges(int $memberId, string $tenantId): array
    {
        $query = Db::name('cashier_v3_balance_fact')
            ->where('tenant_id', $tenantId)
            ->where('member_id', $memberId)
            ->where('status', 'effective');
        // 余额属于会员统一账户，详情流水须与顶部全量余额保持同一跨店口径。
        $rows = $query
            ->field('id,fact_id,business_date,balance_change_type,fact_direction,principal_delta_cents,bonus_delta_cents,principal_after_cents,bonus_after_cents,order_no_snapshot,source_document_type,operator_name_snapshot,occurred_at,settled_at,recorded_at')
            ->order('settled_at desc,id desc')->limit(100)->select()->toArray();

        return array_map(function (array $row): array {
            $principalDelta = (int)($row['principal_delta_cents'] ?? 0);
            $bonusDelta = (int)($row['bonus_delta_cents'] ?? 0);
            $principalAfter = (int)($row['principal_after_cents'] ?? 0);
            $bonusAfter = (int)($row['bonus_after_cents'] ?? 0);
            return [
                'id' => 'balance:' . (string)$row['id'],
                'changeNo' => $this->balanceChangeDisplayNo(
                    (string)($row['business_date'] ?? ''),
                    (int)($row['id'] ?? 0)
                ),
                'businessDate' => (string)($row['business_date'] ?? ''),
                'typeLabel' => $this->balanceChangeTypeLabel((string)($row['balance_change_type'] ?? ''), (string)($row['fact_direction'] ?? '')),
                'changeAmount' => $this->moneyFromCents($principalDelta + $bonusDelta),
                'principalDelta' => $this->moneyFromCents($principalDelta),
                'bonusDelta' => $this->moneyFromCents($bonusDelta),
                'principalAfter' => $this->moneyFromCents($principalAfter),
                'bonusAfter' => $this->moneyFromCents($bonusAfter),
                'balanceAfter' => $this->moneyFromCents($principalAfter + $bonusAfter),
                'sourceNo' => (string)($row['order_no_snapshot'] ?? ''),
                'sourceLabel' => $this->sourceDocumentLabel((string)($row['source_document_type'] ?? '')),
                'operatorName' => (string)($row['operator_name_snapshot'] ?? ''),
                'occurredAt' => $this->dateTime((int)($row['settled_at'] ?: $row['occurred_at'] ?: $row['recorded_at'])),
            ];
        }, $rows);
    }

    /** @return array<int,array<string,mixed>> */
    private function cardOperations(
        int $memberId,
        string $tenantId,
        string $keyword = ''
    ): array {
        $query = Db::name('cashier_v3_card_operation')
            ->where('tenant_id', $tenantId)
            ->where(function ($memberQuery) use ($memberId): void {
                $memberQuery->where('member_id_before', $memberId)
                    ->whereOr('member_id_after', $memberId);
            });
        // 卡操作只按本会员的转出/转入身份读取，不按办理门店隐藏历史。
        $this->applyDetailKeyword($query, $keyword, [
            'operation_no', 'card_name_snapshot', 'card_no_snapshot',
            'target_catalog_name_snapshot', 'member_name_before_snapshot',
            'member_name_after_snapshot', 'store_name_snapshot',
            'operator_name_snapshot', 'reason_snapshot',
        ]);
        $rows = $query
            ->field(implode(',', [
                'id', 'operation_id', 'operation_no', 'operation_type', 'operation_status',
                'member_id_before', 'member_id_after', 'member_name_before_snapshot',
                'member_name_after_snapshot', 'card_name_snapshot', 'card_no_snapshot',
                'card_status_before', 'card_status_after', 'write_end_before', 'write_end_after',
                'target_catalog_name_snapshot', 'settlement_delta_cents', 'store_name_snapshot',
                'operator_name_snapshot', 'reason_snapshot', 'business_date', 'occurred_at',
                'settled_at', 'recorded_at',
            ]))
            ->order('occurred_at desc,id desc')->limit(100)->select()->toArray();

        return array_map(function (array $row) use ($memberId): array {
            return [
                'id' => (string)($row['operation_id'] ?? ''),
                'operationNo' => (string)($row['operation_no'] ?? ''),
                'businessDate' => (string)($row['business_date'] ?? ''),
                'typeLabel' => $this->cardOperationTypeLabel((string)($row['operation_type'] ?? '')),
                'cardName' => (string)($row['card_name_snapshot'] ?? ''),
                'cardNo' => (string)($row['card_no_snapshot'] ?? ''),
                'targetContent' => $this->cardOperationTargetContent($row),
                'relatedMemberName' => (int)($row['member_id_before'] ?? 0) === $memberId
                    ? (string)($row['member_name_after_snapshot'] ?? '')
                    : (string)($row['member_name_before_snapshot'] ?? ''),
                'amount' => $this->moneyFromCents((int)($row['settlement_delta_cents'] ?? 0)),
                'storeName' => (string)($row['store_name_snapshot'] ?? ''),
                'operatorName' => (string)($row['operator_name_snapshot'] ?? ''),
                'reason' => (string)($row['reason_snapshot'] ?? ''),
                'statusLabel' => $this->cardOperationStatusLabel((string)($row['operation_status'] ?? '')),
                'completedAt' => $this->dateTime((int)($row['settled_at'] ?: $row['occurred_at'] ?: $row['recorded_at'])),
            ];
        }, $rows);
    }

    /** @return array<int,array<string,mixed>> */
    private function gifts(int $memberId, string $tenantId, string $keyword = '', string $status = 'active', string $dateFrom = '', string $dateTo = ''): array
    {
        $result = [];
        $issuedGiftItems = [];
        foreach ([
            ['authority' => 'cashier_v3_direct_gift_authority', 'source' => 'direct_gift', 'reason' => 'reason_snapshot'],
            ['authority' => 'cashier_v3_recharge_gift_authority', 'source' => 'recharge_gift', 'reason' => 'recharge_order_no_snapshot'],
        ] as $source) {
            $isDirectGift = $source['source'] === 'direct_gift';
            $query = Db::name('cashier_v3_gift_fact')->alias('gf')
                ->join($source['authority'] . ' ga', 'ga.gift_id=gf.source_id')
                ->leftJoin('system_store ss', 'ss.id=gf.store_id')
                ->where('gf.tenant_id', $tenantId)->where('gf.member_id', $memberId)
                ->where('gf.source_type', $source['source']);
            if ($status === 'active') $query->where('gf.status', 'effective');
            if ($dateFrom !== '') $query->where('gf.business_date', '>=', $dateFrom);
            if ($dateTo !== '') $query->where('gf.business_date', '<=', $dateTo);
            if ($isDirectGift) {
                // Direct gifts can have different expiries in one batch. The item
                // snapshot is the display authority for an individual gift fact.
                $query->leftJoin(
                    'cashier_v3_direct_gift_item gi',
                    'gi.gift_id=gf.source_id AND gi.item_id=gf.source_detail_id'
                );
                // Product-gift claim eligibility may be manually voided after partial
                // delivery. Hide that closed right from active assets without erasing
                // the original issuance or delivered inventory history.
                $query->leftJoin('cashier_v3_presale_claimable_line pc',
                    "pc.tenant_id=gf.tenant_id AND pc.source_kind='GIFT' AND pc.gift_id=gf.source_id AND pc.gift_item_id=gf.source_detail_id");
                if ($status === 'active') $query->whereRaw("(pc.id IS NULL OR pc.claim_status <> 'VOIDED')");
            }
            // 已授权打开会员详情后，展示该会员所有门店的赠送事实。
            $this->applyDetailKeyword($query, $keyword, ['gf.content_name_snapshot', 'ga.gift_no', 'ga.' . $source['reason']]);
            $fields = [
                'gf.id,gf.gift_fact_id,gf.source_id,gf.source_detail_id,gf.gift_kind,gf.content_name_snapshot,gf.quantity',
                'gf.content_snapshot_json,gf.business_date,gf.settled_at,gf.recorded_at,gf.status,ss.name AS store_name',
                'ga.gift_no,ga.' . $source['reason'] . ' AS reason_snapshot',
            ];
            if ($isDirectGift) {
                $fields[] = 'gi.content_snapshot_json AS item_content_snapshot_json,ga.validity_end AS authority_validity_end,pc.claim_status AS claimable_status';
            }
            $rows = $query
                ->field(implode(',', $fields))
                ->order('gf.settled_at desc,gf.id desc')->select()->toArray();
            foreach ($rows as $row) {
                if ($isDirectGift) {
                    // 商品赠送同时有赠送事实和领用投影；详情只展示一次赠送。
                    $issuedGiftItems[(string)$row['source_id'] . ':' . (string)$row['source_detail_id']] = true;
                }
                $snapshotJson = $isDirectGift
                    ? ($row['item_content_snapshot_json'] ?: $row['content_snapshot_json'])
                    : $row['content_snapshot_json'];
                $snapshot = json_decode((string)$snapshotJson, true);
                $snapshot = is_array($snapshot) ? $snapshot : [];
                $validityEnd = (int)($snapshot['validityEnd'] ?? $snapshot['validity_end'] ?? 0);
                if ($validityEnd <= 0 && $isDirectGift) {
                    $validityEnd = (int)($row['authority_validity_end'] ?? 0);
                }
                $giftLabel = $isDirectGift && (string)($row['claimable_status'] ?? '') === 'VOIDED'
                    ? '已作废' : $this->giftStatusLabel((string)($row['status'] ?? 'effective'));
                $result[] = [
                    'id' => 'gift:' . (string)$row['id'],
                    'giftNo' => (string)$row['gift_no'],
                    'businessDate' => (string)$row['business_date'],
                    'sourceLabel' => $source['source'] === 'direct_gift' ? '直接赠送' : '充值赠送',
                    'storeName' => (string)($row['store_name'] ?? ''),
                    'reason' => (string)($row['reason_snapshot'] ?? ''),
                    'statusLabel' => $giftLabel,
                    'createdAt' => $this->dateTime((int)$row['recorded_at']),
                    'contents' => [[
                        'typeLabel' => $this->giftKindLabel((string)$row['gift_kind']),
                        'name' => (string)$row['content_name_snapshot'],
                        'quantity' => (int)$row['quantity'],
                        'effectiveAt' => $this->dateTime((int)$row['settled_at']),
                        'expiresAt' => $validityEnd > 0 ? $this->dateTime($validityEnd) : null,
                        'statusLabel' => $giftLabel,
                    ]],
                    '_issuedAt' => (int)$row['settled_at'],
                ];
            }
        }
        // 旧系统导入只创建了现有权益的承载记录，没有伪造 V3 赠送事实。
        // 因此补读历史项目赠送与商品赠送/预售的原始承载；只做展示合并，
        // 不反写、重发权益，也不把迁入订单算成新销售事实。
        $result = array_merge($result,
            $this->migratedProjectGifts($memberId, $keyword, $status, $dateFrom, $dateTo),
            $this->claimableGiftAndPresale($memberId, $tenantId, $keyword, $status, $dateFrom, $dateTo, $issuedGiftItems));
        usort($result, static function (array $left, array $right): int {
            return (int)$right['_issuedAt'] <=> (int)$left['_issuedAt'];
        });
        foreach ($result as &$row) unset($row['_issuedAt']);
        unset($row);
        return $result;
    }

    /**
     * 历史项目赠送以已支付的会员订单明细为权威承载，导入时没有对应 V3 gift_fact。
     * 仅识别明确标记为迁入的赠送项目，避免把正常收银随单赠送重复展示。
     * 会员详情按会员展示跨店历史；此只读路径不改变权益使用门店的校验。
     */
    private function migratedProjectGifts(int $memberId, string $keyword, string $status, string $dateFrom, string $dateTo): array
    {
        // Begin with this member's indexed orders. Starting at cart details
        // made MySQL scan paid orders across all members before finding gifts.
        $query = Db::name('store_order')->alias('o')->force('uid')
            ->join('store_order_cart_info ci', 'ci.oid=o.id AND ci.uid=o.uid')
            ->leftJoin('system_store ss', 'ss.id=o.store_id')
            ->where('o.uid', $memberId)->where('ci.cart_type', 2)
            ->where('ci.product_type', 6)->where('ci.is_gift', 1)
            ->where('ci.source_type', 'migration')->where('o.paid', 1)
            ->where('o.is_del', 0)->where('o.is_system_del', 0)->where('o.is_user_del', 0);
        if ($status === 'active') $query->where('ci.write_surplus_times', '>', 0)->where('o.refund_status', 0)->where('o.terminal_action', 0);
        if ($dateFrom !== '') $query->where('ci.add_time', '>=', strtotime($dateFrom . ' 00:00:00'));
        if ($dateTo !== '') $query->where('ci.add_time', '<=', strtotime($dateTo . ' 23:59:59'));
        $this->applyDetailKeyword($query, $keyword, ['o.order_id', 'ci.cart_info']);
        $rows = $query->field('ci.id,ci.cart_info,ci.write_surplus_times,ci.add_time,o.order_id,o.store_id,o.refund_status,o.terminal_action,ss.name AS store_name')
            ->order('ci.add_time desc,ci.id desc')->select()->toArray();
        $result = [];
        foreach ($rows as $row) {
            $remaining = max(0, (int)$row['write_surplus_times']);
            $effective = $remaining > 0 && (int)$row['refund_status'] === 0 && (int)$row['terminal_action'] === 0;
            $issuedAt = (int)$row['add_time'];
            $label = $effective ? '有效' : '已结束';
            $result[] = [
                'id' => 'migrated-project-gift:' . (string)$row['id'],
                'giftNo' => (string)$row['order_id'],
                'businessDate' => $this->dateOnly($issuedAt),
                'sourceType' => 'migrated_project_gift',
                'sourceLabel' => '历史项目赠送',
                'sourceOrderNo' => (string)$row['order_id'],
                'storeName' => (string)($row['store_name'] ?? ''),
                'reason' => '历史导入',
                'statusLabel' => $label,
                'createdAt' => $this->dateTime($issuedAt),
                'contents' => [[
                    'typeLabel' => '项目', 'name' => $this->projectName($row['cart_info'] ?? ''),
                    'quantity' => $remaining, 'effectiveAt' => $this->dateTime($issuedAt),
                    'expiresAt' => null, 'statusLabel' => $label,
                ]],
                '_issuedAt' => $issuedAt,
            ];
        }
        return $result;
    }

    /**
     * 商品赠送和预售以可领用行为权威；导入数据和正常结账数据共用该表。
     * 仅返回会员本人的全门店行，数量展示剩余可领用量，不将领用
     * 记录反算成新的赠送或销售，也不修改库存。
     */
    private function claimableGiftAndPresale(int $memberId, string $tenantId, string $keyword, string $status, string $dateFrom, string $dateTo, array $issuedGiftItems): array
    {
        $query = Db::name('cashier_v3_presale_claimable_line')
            ->where('tenant_id', $tenantId)->where('member_id', $memberId)
            ->whereIn('source_kind', ['GIFT', 'PRESALE']);
        if ($status === 'active') {
            $query->whereIn('claim_status', ['OPEN', 'AVAILABLE'])
                ->whereRaw('quantity > claimed_quantity');
        }
        if ($dateFrom !== '') $query->where('business_date', '>=', $dateFrom);
        if ($dateTo !== '') $query->where('business_date', '<=', $dateTo);
        $this->applyDetailKeyword($query, $keyword, ['product_name_snapshot', 'source_order_no_snapshot']);
        $rows = $query->field('id,claimable_line_id,source_kind,gift_id,gift_item_id,source_order_no_snapshot,store_name_snapshot,product_name_snapshot,quantity,claimed_quantity,claim_status,business_date,settled_at,recorded_at')
            ->order('settled_at desc,id desc')->select()->toArray();
        $result = [];
        foreach ($rows as $row) {
            if ((string)$row['source_kind'] === 'GIFT'
                && isset($issuedGiftItems[(string)$row['gift_id'] . ':' . (string)$row['gift_item_id']])) continue;
            $remaining = max(0, (int)$row['quantity'] - (int)$row['claimed_quantity']);
            $state = strtoupper((string)$row['claim_status']);
            $label = in_array($state, ['OPEN', 'AVAILABLE'], true) && $remaining > 0
                ? '有效' : (in_array($state, ['CLOSED_AFTER_SALE_REVERSAL', 'VOIDED'], true) ? '已作废' : '已领完');
            $isGift = (string)$row['source_kind'] === 'GIFT';
            // 旧寄存与预售共用可领用表，历史来源单号明确标注 STORED 时
            // 保留“寄存”名称；不把它错误宣称为预售或再制造一笔赠送事实。
            $isStored = !$isGift && str_contains(strtoupper((string)$row['source_order_no_snapshot']), '-STORED-');
            $issuedAt = (int)$row['settled_at'];
            $result[] = [
                'id' => 'claimable:' . (string)$row['claimable_line_id'],
                'giftNo' => (string)$row['source_order_no_snapshot'],
                'businessDate' => (string)$row['business_date'],
                'sourceType' => $isGift ? 'claimable_gift' : ($isStored ? 'claimable_stored' : 'claimable_presale'),
                'sourceLabel' => $isGift ? '商品赠送' : ($isStored ? '商品寄存' : '商品预售'),
                'sourceOrderNo' => (string)$row['source_order_no_snapshot'],
                'storeName' => (string)$row['store_name_snapshot'],
                'reason' => '', 'statusLabel' => $label,
                'createdAt' => $this->dateTime((int)$row['recorded_at']),
                'contents' => [[
                    'typeLabel' => '产品', 'name' => (string)$row['product_name_snapshot'],
                    'quantity' => $remaining, 'effectiveAt' => $this->dateTime($issuedAt),
                    'expiresAt' => null, 'statusLabel' => $label,
                ]],
                '_issuedAt' => $issuedAt,
            ];
        }
        return $result;
    }

    /** @return array{records:array<int,array<string,mixed>>,tasks:array<int,array<string,mixed>>,reminder:array<string,mixed>} */
    private function care(int $memberId, string $tenantId, string $keyword = '', string $status = '', string $dateFrom = '', string $dateTo = ''): array
    {
        $records = [];
        // 客情记录本身代表已经发生的跟进；“未完成”只显示尚未完成的任务，
        // 不把已完成的历史记录伪装成待办。
        if ($status !== 'unfinished') {
            $recordQuery = Db::name('customer_care_record')->where('tenant_id', $tenantId)
                ->where('member_id', $memberId)->where('status', 'NORMAL');
            // 客情详情回顾会员在各店的跟进，任务编辑权限不由此只读投影授予。
            $this->applyDetailKeyword($recordQuery, $keyword, ['record_no', 'record_type', 'summary', 'detail', 'follower_name_snapshot']);
            if ($dateFrom !== '') $recordQuery->where('followed_at', '>=', strtotime($dateFrom . ' 00:00:00'));
            if ($dateTo !== '') $recordQuery->where('followed_at', '<=', strtotime($dateTo . ' 23:59:59'));
            $records = $recordQuery
                ->field('id,record_no,record_type,summary,detail,followed_at,follower_name_snapshot,business_store_name_snapshot,status')
                ->order('followed_at desc,id desc')->limit(100)->select()->toArray();
        }
        $taskQuery = Db::name('customer_care_task')->where('tenant_id', $tenantId)
            ->where('member_id', $memberId)->where('is_visible', 1);
        $this->applyDetailKeyword($taskQuery, $keyword, ['task_no', 'title', 'task_type', 'owner_name_snapshot']);
        if ($status === 'unfinished') $taskQuery->whereIn('status', ['UNSTARTED', 'PENDING', 'IN_PROGRESS']);
        if ($dateFrom !== '') $taskQuery->where('planned_at', '>=', strtotime($dateFrom . ' 00:00:00'));
        if ($dateTo !== '') $taskQuery->where('planned_at', '<=', strtotime($dateTo . ' 23:59:59'));
        $tasks = $taskQuery
            ->field('task_key,task_no,title,task_type,status,planned_at,owner_name_snapshot,business_store_name_snapshot')
            ->order('planned_at desc,task_key desc')->limit(100)->select()->toArray();
        $recordDtos = array_map(function (array $row): array {
            return [
                'id' => 'care-record:' . (string)$row['id'], 'recordNo' => (string)($row['record_no'] ?? ''),
                'typeLabel' => $this->careTypeLabel((string)$row['record_type']), 'content' => (string)($row['detail'] ?: $row['summary']),
                'followedAt' => $this->dateTime((int)$row['followed_at']), 'actualFollowerName' => (string)$row['follower_name_snapshot'],
                'storeName' => (string)$row['business_store_name_snapshot'], 'statusLabel' => '正常',
            ];
        }, $records);
        $taskDtos = array_map(function (array $row): array {
            return [
                'id' => 'care-task:' . (string)$row['task_key'], 'taskNo' => (string)($row['task_no'] ?? ''),
                'title' => (string)$row['title'], 'typeLabel' => $this->careTypeLabel((string)$row['task_type']), 'statusLabel' => $this->careTaskStatusLabel((string)$row['status']),
                'plannedAt' => $this->dateTime((int)$row['planned_at']), 'ownerName' => (string)$row['owner_name_snapshot'],
                'storeName' => (string)$row['business_store_name_snapshot'],
            ];
        }, $tasks);
        return ['records' => $recordDtos, 'tasks' => $taskDtos, 'reminder' => $taskDtos ? [
            'nextReminderAt' => (string)$taskDtos[0]['plannedAt'], 'managerName' => (string)$taskDtos[0]['ownerName'], 'content' => (string)$taskDtos[0]['title'],
        ] : []];
    }

    /** @return array<string,mixed> */
    private function overview(int $memberId, string $tenantId): array
    {
        // All-store lifetime recharge principal comes from successful balance
        // facts, including paid recharge-debt credits and signed reversals.
        // Never add gift balances, unpaid debt, current balance or list rows.
        $rechargeCents = (int)Db::name('cashier_v3_balance_fact')
            ->where('tenant_id', $tenantId)->where('member_id', $memberId)
            ->where('status', 'effective')
            ->whereIn('balance_change_type', ['recharge_credit', 'recharge_debt_repayment_credit'])
            ->sum('principal_delta_cents');
        $salesQuery = Db::name('cashier_v3_sale_fact')->where('tenant_id', $tenantId)
            ->where('member_id', $memberId)->where('status', 'effective');
        // 顶部消费与到店汇总必须覆盖和各页签相同的会员全门店事实。
        $sales = (array)$salesQuery
            ->field('COALESCE(SUM(sale_amount_cents),0) AS total_cents,MAX(business_date) AS latest_date')->find();
        $visitQuery = Db::name('cashier_v3_entitlement_service_fact')->alias('sf')
            ->where('sf.tenant_id', $tenantId)->where('sf.member_id', $memberId)
            ->where('sf.service_status', 'completed');
        // 详情页的到店摘要独立于实际服务列表；体验可留在历史列表，但不占门店到店次数。
        StoreCustomerVisitEligibility::apply($visitQuery, 'sf');
        $visits = (array)$visitQuery->fieldRaw('COUNT(DISTINCT sf.business_date) AS visit_count,MAX(sf.business_date) AS latest_date')->find();
        $latestQuery = Db::name('cashier_v3_entitlement_service_fact')->alias('sf')
            ->where('sf.tenant_id', $tenantId)->where('sf.member_id', $memberId)
            ->where('sf.service_status', 'completed');
        StoreCustomerVisitEligibility::apply($latestQuery, 'sf');
        $latest = (array)$latestQuery->field('sf.project_name_snapshot,sf.store_name_snapshot,sf.craftsmen_snapshot_json,sf.business_date,sf.settled_at,sf.occurred_at')
            ->order('sf.business_date desc,sf.settled_at desc,sf.id desc')->find();
        return [
            'totalRechargeAmount' => $this->moneyFromCents($rechargeCents),
            'totalConsumptionAmount' => $this->moneyFromCents((int)($sales['total_cents'] ?? 0)),
            'latestPurchaseDate' => (string)($sales['latest_date'] ?? ''),
            'visitCount' => (int)($visits['visit_count'] ?? 0),
            'latestVisitDate' => (string)($visits['latest_date'] ?? ''),
            'latestVisit' => $latest ? [
                'visitedAt' => $this->dateTime((int)($latest['settled_at'] ?: $latest['occurred_at'])),
                'storeName' => (string)$latest['store_name_snapshot'],
                'projectName' => (string)$latest['project_name_snapshot'],
                'craftsmenSummary' => $this->craftsmenSummary((string)($latest['craftsmen_snapshot_json'] ?? '')),
            ] : [],
        ];
    }

    /** @return array<int,array{key:string,label:string,value:string}> */
    private function customFields($raw): array
    {
        $storedRows = is_array($raw) ? $raw : json_decode((string)$raw, true);
        $stored = [];
        foreach ((array)$storedRows as $key => $row) {
            if (is_array($row)) {
                $param = trim((string)($row['param'] ?? $row['key'] ?? $row['info'] ?? ''));
                $info = trim((string)($row['info'] ?? ''));
                if ($param !== '') $stored[$param] = $row['value'] ?? '';
                if ($info !== '') $stored[$info] = $row['value'] ?? '';
                continue;
            }
            if (is_scalar($row)) $stored[(string)$key] = $row;
        }

        $fields = [];
        foreach ((array)SystemConfigService::get('user_extend_info', []) as $index => $definition) {
            if (!is_array($definition) || empty($definition['use'])) continue;
            $param = trim((string)($definition['param'] ?? $definition['key'] ?? $definition['info'] ?? ''));
            $label = trim((string)($definition['info'] ?? $definition['label'] ?? $definition['name'] ?? ''));
            if ($param === '' || $label === '' || $this->isBuiltInProfileField($param)) continue;
            $value = $stored[$param] ?? $stored[$label] ?? '';
            $fields[] = [
                'key' => $param !== '' ? $param : ('profile-field-' . $index),
                'label' => $label,
                'value' => $this->customFieldValue($value, $definition),
            ];
        }
        return $fields;
    }

    /** @return string[] */
    private function memberTagNames(int $memberId): array
    {
        if ($memberId <= 0) return [];
        $rows = Db::name('user_label_relation')->alias('relation')
            ->join('user_label label', 'label.id = relation.label_id')
            ->where('relation.uid', $memberId)
            ->where('relation.type', 0)
            ->where('relation.relation_id', 0)
            ->field('label.label_name')
            ->order('label.id asc')
            ->select();
        $rows = is_object($rows) && method_exists($rows, 'toArray') ? $rows->toArray() : (array)$rows;
        $names = [];
        foreach ($rows as $row) {
            $name = trim((string)($row['label_name'] ?? ''));
            if ($name !== '') $names[$name] = $name;
        }
        return array_values($names);
    }

    private function isBuiltInProfileField(string $field): bool
    {
        return in_array($field, ['real_name', 'sex', 'birthday', 'card_id', 'address', 'mark'], true);
    }

    private function customFieldValue($value, array $definition): string
    {
        if (is_array($value)) $values = $value;
        elseif ($value === null || $value === '') return '';
        else $values = preg_split('/[,，]/u', (string)$value) ?: [];

        $labels = [];
        foreach ((array)($definition['singlearr'] ?? $definition['options'] ?? []) as $key => $option) {
            if (is_array($option)) {
                $optionValue = (string)($option['value'] ?? $option['id'] ?? $key);
                $optionLabel = (string)($option['label'] ?? $option['name'] ?? $optionValue);
            } else {
                $optionValue = (string)$key;
                $optionLabel = (string)$option;
            }
            $labels[$optionValue] = $optionLabel;
        }
        $display = [];
        foreach ($values as $item) {
            $item = trim((string)$item);
            if ($item !== '') $display[] = $labels[$item] ?? $item;
        }
        return implode('、', array_values(array_unique($display)));
    }

    private function genderLabel(int $sex): string
    {
        return $sex === 1 ? '男' : ($sex === 2 ? '女' : '');
    }

    private function dateOnly(int $timestamp): string
    {
        return $timestamp > 0 ? date('Y-m-d', $timestamp) : '';
    }

    /** AI candidates were scoped by their caller; do not widen that separate read path. */
    private function assertAiVisible(int $memberId, CashierV3DataScopeContext $dataScope): void
    {
        if ($memberId > 0 && in_array($dataScope->authorizationMode(), [
            CashierV3DataScopeContext::MODE_ALL,
            CashierV3DataScopeContext::MODE_STORES,
            CashierV3DataScopeContext::MODE_SELF_PARTICIPANT,
        ], true)) return;
        throw $this->notFound();
    }

    /** Member-detail cross-store history requires an authenticated current-store session. */
    private function assertVisible(int $memberId, CashierV3OperatorScope $operatorScope, CashierV3DataScopeContext $dataScope): void
    {
        if ($memberId > 0 && $operatorScope->tenantId() !== ''
            && $operatorScope->tenantId() === $dataScope->tenantId()
            && $operatorScope->storeId() === $dataScope->forcedStoreId()
            && $dataScope->allowsStore($operatorScope->storeId())
            && in_array($dataScope->authorizationMode(), [CashierV3DataScopeContext::MODE_ALL, CashierV3DataScopeContext::MODE_STORES], true)) {
            // Only an authenticated store session may expand an exact member's
            // history across stores. SELF_PARTICIPANT/NONE cannot use the member
            // id as a shortcut to another store's private records.
            return;
        }
        throw $this->notFound();
    }

    /**
     * Read the exact cents displayed as “剩余项目金额” for a bounded, already
     * permission-scoped candidate set. Recheck the current member/store
     * relation before reading rights from all stores; a caller cannot turn
     * this into an unscoped member-directory lookup.
     *
     * @param array<int,int> $memberIds
     * @param array<int,int> $visibleStoreIds
     * @return array<int,int> member ID => remaining project amount in cents
     */
    public function readAiRemainingProjectAmounts(array $memberIds, string $tenantId, array $visibleStoreIds): array
    {
        $memberIds=array_values(array_unique($memberIds));
        $visibleStoreIds=array_values(array_unique($visibleStoreIds));
        if ($tenantId==='' || !$memberIds || count($memberIds)>100 || !$visibleStoreIds) throw $this->notFound();
        foreach ($memberIds as $id) if (!is_int($id) || $id<1) throw $this->notFound();
        foreach ($visibleStoreIds as $id) if (!is_int($id) || $id<1) throw $this->notFound();
        // The relation is the same access boundary as AI member detail. The
        // card read below deliberately includes this verified member's other
        // stores, matching the amount column the customer already sees.
        $visible=Db::name('user')->alias('u')->whereIn('u.uid',$memberIds)
            ->where('u.status',1)->where('u.is_del',0)
            ->whereIn('u.uid',function($query)use($visibleStoreIds):void {
                $query->name('store_user')->where('status',1)->whereIn('store_id',$visibleStoreIds)->field('uid');
            })->column('u.uid');
        if (count(array_unique(array_map('intval',$visible)))!==count($memberIds)) throw $this->notFound();
        // Only the already-scoped public reader may expose these amounts.
        // The shared summary projection is unscoped solely for server-owned
        // index refresh and must never become a client-facing endpoint.
        $summary=$this->readAiRightsSummariesForIndex($memberIds,$tenantId);
        $amounts=[];
        foreach ($summary as $id=>$row) $amounts[$id]=$row['amount_cents'];
        return $amounts;
    }

    /**
     * Freeze the three card-derived summary values together. The AI index
     * preserves their common version and time boundary; no separate metric
     * formula is introduced for the fast member-summary presentation.
     *
     * @return array<int,array{amount_cents:int,active_card_count:int,remaining_project_times:int}>
     */
    public function readAiRightsSummariesForIndex(array $memberIds,string $tenantId): array
    {
        $memberIds=array_values(array_unique($memberIds));
        if ($tenantId==='' || !$memberIds || count($memberIds)>100) throw $this->notFound();
        foreach ($memberIds as $id) if (!is_int($id) || $id<1) throw $this->notFound();
        $summaries=[];
        $cardsByMember=$this->cardsForMembers($memberIds,$tenantId,'','active','','');
        foreach ($memberIds as $memberId) {
            $total='0.00';$activeCards=0;$remainingTimes=0;
            foreach ($cardsByMember[$memberId]??[] as $card) {
                if (($card['statusCode']??'')!=='enabled') continue;
                ++$activeCards;
                if (($card['cardRuleType']??'')!=='time') $remainingTimes+=(int)($card['debtLimitedRemainingTimes']??$card['remainingTimes']);
                $total=bcadd($total,(string)$card['remainingAmount'],2);
            }
            $cents=bcmul($total,'100',0);
            if (bccomp($cents,(string)PHP_INT_MAX,0)>0) throw new \RuntimeException('AI_METRIC_OVERFLOW');
            $summaries[$memberId]=['amount_cents'=>(int)$cents,
                'active_card_count'=>$activeCards,'remaining_project_times'=>$remainingTimes];
        }
        return $summaries;
    }

    /** Active card rights are cross-store only after the caller verifies member visibility. */
    private function cards(int $memberId, string $tenantId, string $keyword = '', string $status = 'active', string $dateFrom = '', string $dateTo = '', int $viewerStoreId = 0): array
    {
        return $this->cardsForMembers([$memberId],$tenantId,$keyword,$status,$dateFrom,$dateTo,$viewerStoreId)[$memberId]??[];
    }

    /**
     * Shared single-member and AI batch projection. Holder, component, rule
     * and actual-sale reads are batched; the same amount authority and status
     * rules serve cashier details and the AI amount predicate.
     *
     * @param array<int,int> $memberIds
     * @return array<int,array<int,array>>
     */
    private function cardsForMembers(array $memberIds, string $tenantId, string $keyword, string $status, string $dateFrom, string $dateTo, int $viewerStoreId = 0): array
    {
        $now = time();
        $activeOnly = $status === 'active';
        $holderQuery = Db::name('user_card_holder')->alias('h')
            ->join('store_order o', 'o.id = h.oid')
            ->whereIn('h.uid', $memberIds)
            // 整卡转让只改变当前持卡人；原购卡人仍留在订单上供债务归属和审计。
            ->where('o.paid', 1)
            ->where('o.is_del', 0)
            ->where('o.is_user_del', 0)
            ->where('o.card_upgrade_use_oid', 0);
        if ($activeOnly) {
            // Voided cards remain readable under "all" for card-specific audit,
            // but must never re-enter the active rights selector.
            $holderQuery->where('h.is_del', 0);
            $holderQuery->where('h.write_surplus_times', '>', 0)->where('o.refund_status', 0)->where('o.terminal_action', 0);
        }
        if ($dateFrom !== '') $holderQuery->where('h.add_time', '>=', strtotime($dateFrom . ' 00:00:00'));
        if ($dateTo !== '') $holderQuery->where('h.add_time', '<=', strtotime($dateTo . ' 23:59:59'));
        // 会员详情的卡项权益跟随已通过可见性校验的会员读取；卡项历史可能
        // 来自多个门店，不能再用当前操作门店过滤，否则摘要有权益而明细为空。
        // 详情入口先验证当前门店会话，再按同一会员聚合全门店历史；
        // AI 候选读取和所有写命令继续走各自权限门禁。
        $this->applyDetailKeyword($holderQuery, $keyword, ['h.card_name', 'h.card_no']);
        $holders = $holderQuery
            ->field('h.id,h.uid,h.oid,h.card_name,h.card_no,h.store_id,h.write_start,h.write_end,h.add_time,o.pay_price as order_pay_price,o.store_id as order_store_id,o.is_system_del,o.mark')
            ->order('h.id', 'asc')
            ->select()
            ->toArray();

        // Storeless migrated holders need the viewer's actual operating store
        // under their owner organization. Internal index reads have no viewer
        // store and therefore exclude them rather than widening visibility.
        $zeroStoreIds = [];
        foreach ($holders as $holder) {
            if ((int)$holder['store_id'] === 0) $zeroStoreIds[] = (int)$holder['id'];
        }
        $allowedZeroStore = array_fill_keys(
            \app\services\cashier\v3\card\CashierV3HistoricalEntitlementScopePolicy::allowedHolderIds(
                $zeroStoreIds, $tenantId, $viewerStoreId
            ), true
        );
        $holderByOrder = [];
        foreach ($holders as $holder) {
            $hiddenHistorical = (int)$holder['is_system_del'] === 1
                && (int)$holder['store_id'] === 0
                && (int)$holder['order_store_id'] === 0
                && (string)$holder['mark'] === 'migration_entitlement'
                && isset($allowedZeroStore[(int)$holder['id']]);
            // Normal system-deleted orders remain excluded from the member
            // detail, while the separate migration scope authorizes a hidden
            // carrier only in the owner's organization.
            if ((int)$holder['is_system_del'] !== 0 && !$hiddenHistorical) continue;
            if ((int)$holder['store_id'] <= 0 && !isset($allowedZeroStore[(int)$holder['id']])) {
                continue;
            }
            $start = max(0, (int)($holder['write_start'] ?? 0));
            $end = max(0, (int)($holder['write_end'] ?? 0));
            if ($activeOnly && (($start > 0 && $start > $now) || ($end > 0 && $end < $now))) {
                continue;
            }
            $holderByOrder[(int)$holder['oid']] = $holder;
        }
        if (!$holderByOrder) {
            return [];
        }
        $holderIds = array_values(array_unique(array_map(static function (array $holder): int {
            return (int)($holder['id'] ?? 0);
        }, $holderByOrder)));
        $cardStatusByHolder = Db::name('cashier_v3_card_state')
            ->where('tenant_id', $tenantId)
            ->whereIn('current_member_id', $memberIds)
            ->whereIn('card_holder_id', $holderIds)
            ->column('card_status', 'card_holder_id');
        $cardRules = Db::name('cashier_v3_card_rule_state')
            ->where('tenant_id', $tenantId)
            // 规则签发快照保留原会员，转让后必须按持卡实例识别卡规则。
            ->whereIn('card_holder_id', $holderIds)
            ->field('card_holder_id,rule_type,shared_total_times,shared_remaining_times')->select()->toArray();
        $cardRulesByHolder = [];
        foreach ($cardRules as $cardRule) $cardRulesByHolder[(int)$cardRule['card_holder_id']] = $cardRule;
        $sharedRefunds = [];
        foreach (Db::name('cashier_v3_refund_adjustment')->where('tenant_id', $tenantId)
            ->whereIn('card_holder_id', $holderIds)->where('status', 'effective')
            ->field('card_holder_id,refund_amount_cents,times_removed')->select()->toArray() as $refund) {
            $id = (int)$refund['card_holder_id'];
            $sharedRefunds[$id]['returned'] = ($sharedRefunds[$id]['returned'] ?? 0) + (int)$refund['refund_amount_cents'];
            $sharedRefunds[$id]['removed'] = ($sharedRefunds[$id]['removed'] ?? 0) + (int)$refund['times_removed'];
        }
        $ruleAuthoritiesByHolder = (new CashierV3CardRuleEntitlementAuthorityServices())
            ->authoritiesForHolders($tenantId,$holderIds);

        $projectsByOrder = [];
        $rows = Db::name('store_order_cart_info')
            ->whereIn('oid', array_keys($holderByOrder))
            ->where('cart_type', 2)
            ->where('product_type', 6)
            ->where('is_writeoff', 0)
            // product_id is required to join the immutable card-sale component
            // allocation back to this legacy cart detail.
            ->field('id,oid,product_id,cart_info,write_times,write_surplus_times,write_start,write_end,pay_price')
            ->order('oid asc,id asc')
            ->select()
            ->toArray();
        $rowsByOrder = [];
        $rowsById = [];
        foreach ($rows as $row) {
            $rowsByOrder[(int)$row['oid']][] = $row;
            $rowsById[(int)$row['id']] = $row;
        }
        // R50：详情与收银共用可用金额守卫，历史购买金额/次数不改写。
        $originOrders = Db::name('store_order')->whereIn('id', array_keys($holderByOrder))
            ->column('id,uid,store_id,pay_price,debt_amount,repaid_debt_amount', 'id');
        foreach ($originOrders as &$originOrder) { $originOrder['tenant_id'] = $tenantId; }
        unset($originOrder);
        // Used-up components stay part of the original whole-yuan allocation;
        // otherwise opening member detail after a service would move the yuan
        // remainder to another project and disagree with final settlement.
        $amountRows = Db::name('store_order_cart_info')
            ->whereIn('oid', array_keys($holderByOrder))->where('cart_type', 2)
            ->where('product_type', 6)->field('id,oid,product_id,cart_info')
            ->order('oid asc,id asc')->select()->toArray();
        $actualSaleAmounts = (new CashierV3CardSaleActualAmountServices())->wholeYuanForOrders(
            array_values($originOrders),
            $amountRows
        );
        $debtRows = Db::name('store_debt')->whereIn('order_id', array_keys($holderByOrder))
            ->field('order_id,status,total_debt,repaid_debt')->select()->toArray();
        $debtByOrder = [];
        foreach ($debtRows as $debtRow) $debtByOrder[(int)$debtRow['order_id']] = $debtRow;
        $debtResolver = new \app\services\cashier\v3\cashier\CashierV3CardOriginDebtResolver();
        $pendingByOrder = [];
        foreach ($originOrders as $orderId => $originOrder) {
            $v3Pending = $debtResolver->pendingV3CardDebt($originOrder, (int)$originOrder['uid']);
            if ($v3Pending !== null) {
                $pendingByOrder[(int)$orderId] = $v3Pending;
                continue;
            }
            $debt = $debtByOrder[(int)$orderId] ?? null;
            $total = $debt ? (string)$debt['total_debt'] : (string)($originOrder['debt_amount'] ?? '0');
            $repaid = $debt ? (string)$debt['repaid_debt'] : (string)($originOrder['repaid_debt_amount'] ?? '0');
            $pending = $debt && (int)$debt['status'] !== 0 ? '0.00' : bcsub($total, $repaid, 2);
            $pendingByOrder[(int)$orderId] = bccomp($pending, '0', 2) > 0 ? $pending : '0.00';
        }
        $writeoff = app()->make(\app\services\order\store\WriteOffOrderServices::class);
        foreach ($rows as $row) {
            $start = max(0, (int)($row['write_start'] ?? 0));
            $end = max(0, (int)($row['write_end'] ?? 0));
            $total = max(0, (int)($row['write_times'] ?? 0));
            $remaining = max(0, (int)($row['write_surplus_times'] ?? 0));
            if ($total <= 0 || $remaining < 0 || $remaining > $total
                || ($activeOnly && (($start > 0 && $start > $now) || ($end > 0 && $end < $now)))) {
                continue;
            }
            try {
                $snapshot = is_string($row['cart_info'] ?? null)
                    ? json_decode((string)$row['cart_info'], true)
                    : ($row['cart_info'] ?? []);
                $snapshot = is_array($snapshot) ? $snapshot : [];
                $amountAuthority = (new CashierV3CardComponentPurchaseAmountAuthority())->resolve(
                    $row,
                    $snapshot,
                    $actualSaleAmounts,
                    $ruleAuthoritiesByHolder[(int)($holderByOrder[(int)($row['oid'] ?? 0)]['id'] ?? 0)][(int)($row['id'] ?? 0)] ?? null
                );
                // Formal rule authority supplies the immutable sale amount, while
                // refunds live on the cart's current snapshot. Both are needed.
                $allocationSnapshot = $amountAuthority['allocationSnapshot'];
                $allocationSnapshot['r26RefundedCents'] = (int)($snapshot['r26RefundedCents'] ?? 0);
                $allocationSnapshot['r26RefundRemovedTimes'] = (int)($snapshot['r26RefundRemovedTimes'] ?? 0);
                $amount = CashierV3EntitlementActualAmountAllocator::remainingForSnapshot(
                    $this->moneyFromCents($amountAuthority['purchaseAmountCents']),
                    $total,
                    $total - $remaining,
                    $allocationSnapshot
                );
                $orderId = (int)$row['oid'];
                $originOrder = (array)($originOrders[$orderId] ?? []);
                $pending = (float)($pendingByOrder[$orderId] ?? 0);
                $effective = $writeoff->calcEffectiveWriteSurplusTimes(
                    $row, $pending, $originOrder, $amount, $rowsByOrder[$orderId] ?? [], false
                );
                $amount = $writeoff->usableEntitlementAmount(
                    $row, $originOrder, $amount, $pending, $rowsByOrder[$orderId] ?? [], false
                );
            } catch (\InvalidArgumentException $exception) {
                throw new CashierV3CommandException(
                    CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                    '会员卡项存在非整数金额，请联系管理员核对后再操作。',
                    CashierV3ResultCode::STATUS_FAILED,
                    ['reason' => 'member_card_purchase_amount_not_whole_yuan']
                );
            }
            $projectsByOrder[(int)$row['oid']][] = [
                'id' => (string)($row['id'] ?? ''),
                'projectName' => $this->projectName($row['cart_info'] ?? ''),
                'purchaseTimes' => $total,
                'usedTimes' => $total - $remaining - (int)($snapshot['r26RefundRemovedTimes'] ?? 0),
                'remainingTimes' => $remaining,
                'debtLimitedRemainingTimes' => min($remaining, max(0, $effective)),
                'remainingAmount' => $amount,
            ];
        }

        $stores = Db::name('system_store')
            ->whereIn('id', array_values(array_unique(array_map(static function (array $holder): int {
                return (int)$holder['store_id'];
            }, $holderByOrder))))
            ->column('name', 'id');
        $cards = [];
        foreach ($holderByOrder as $orderId => $holder) {
            $projects = $projectsByOrder[$orderId] ?? [];
            if (!$projects && $activeOnly) {
                continue;
            }
            $remainingTimes = 0;
            $debtLimitedRemainingTimes = 0;
            $remainingAmount = '0.00';
            foreach ($projects as $project) {
                $remainingTimes += (int)$project['remainingTimes'];
                $debtLimitedRemainingTimes += (int)$project['debtLimitedRemainingTimes'];
                $remainingAmount = bcadd($remainingAmount, (string)$project['remainingAmount'], 2);
            }
            $holderId = (int)($holder['id'] ?? 0);
            $cardRule = (array)($cardRulesByHolder[$holderId] ?? []);
            $cardRuleType = (string)($cardRule['rule_type'] ?? '');
            if ($cardRuleType === 'choice_count' && $projects) {
                // 多项目共享同一次数池和整卡金额；退款只更新共享池，不改旧项目行。
                $remainingTimes = (int)$cardRule['shared_remaining_times'];
                $refund = (array)($sharedRefunds[$holderId] ?? []);
                if ((int)($refund['returned'] ?? 0) > 0
                    && (string)($cardStatusByHolder[$holderId] ?? 'enabled') === 'enabled') {
                    try {
                        $quota = CashierV3CardRefundQuotaPolicy::sharedPool(
                            (string)($holder['order_pay_price'] ?? ''), (int)$cardRule['shared_total_times'],
                            $remainingTimes, (int)$refund['returned'], (int)($refund['removed'] ?? 0));
                    } catch (\InvalidArgumentException $error) {
                        throw new CashierV3CommandException(CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                            '本卡共享次数与退款金额不一致，请联系管理员核对。', CashierV3ResultCode::STATUS_FAILED,
                            ['reason' => 'member_shared_refund_value_mismatch']);
                    }
                    $remainingAmount = $this->moneyFromCents($quota['remainingValueCents']);
                } elseif ((string)($cardStatusByHolder[$holderId] ?? 'enabled') !== 'enabled') {
                    $remainingAmount = '0.00';
                }
                foreach ($projects as &$project) {
                    $project['remainingTimes'] = min((int)$project['remainingTimes'], $remainingTimes);
                    $project['debtLimitedRemainingTimes'] = min((int)$project['debtLimitedRemainingTimes'], $remainingTimes);
                }
                unset($project);
                $firstRow = $rowsById[(int)$projects[0]['id']] ?? null;
                $originOrder = (array)($originOrders[(int)$orderId] ?? []);
                if ($firstRow && $originOrder) {
                    // Member/report projections share the debt limit, but must
                    // not acquire checkout locks inside a read-only snapshot.
                    $sharedLimit = (new CashierV3CardRuleEntitlementAuthorityServices())
                        ->debtLimitForDetail($firstRow, $originOrder, false);
                    if ($sharedLimit !== null) $debtLimitedRemainingTimes = $sharedLimit;
                }
            }
            $isTimeCard = $cardRuleType === 'time';
            if ($isTimeCard) {
                $remainingAmount = $this->money($holder['order_pay_price'] ?? 0);
                $cancelled = (new \app\services\cashier\v3\cashier\CashierV3CardOriginDebtResolver())->cancelledCardDebt(
                    (array)($originOrders[(int)$orderId] ?? []),
                    (int)($originOrders[(int)$orderId]['uid'] ?? 0), $tenantId
                );
                $remainingAmount = max('0.00', bcsub($remainingAmount, $cancelled, 2));
                // A time-card refund changes value but does not fabricate usage.
                $refundedCents = (int)Db::name('cashier_v3_refund_adjustment')
                    ->where('tenant_id', $tenantId)->where('card_holder_id', $holderId)
                    ->where('status', 'effective')->sum('refund_amount_cents');
                $remainingAmount = max('0.00', bcsub($remainingAmount,
                    $this->moneyFromCents($refundedCents), 2));
            }
            $statusCode = (string)($cardStatusByHolder[$holderId] ?? 'enabled');
            $cards[(int)$holder['uid']][] = [
                'id' => (string)($holder['id'] ?? ''),
                'cardNo' => (string)($holder['card_no'] ?? ''),
                'cardName' => trim((string)($holder['card_name'] ?? '')) ?: '会员卡项',
                'statusCode' => $statusCode,
                'statusLabel' => $statusCode === 'disabled' ? '已停用' : (($holder['write_end'] ?? 0) > 0 && (int)$holder['write_end'] < $now ? '已过期' : ($remainingTimes > 0 ? '有效' : '已用完')),
                'cardRuleType' => $cardRuleType,
                'isTimeCard' => $isTimeCard,
                'storeName' => (string)($stores[(int)$holder['store_id']] ?? ''),
                'openedAt' => $this->dateTime($holder['add_time'] ?? 0),
                'expiresAt' => $this->dateTime($holder['write_end'] ?? 0, '长期有效'),
                'remainingTimes' => $remainingTimes,
                'debtLimitedRemainingTimes' => $debtLimitedRemainingTimes,
                'remainingAmount' => $remainingAmount,
                'purchaseBatchNo' => (string)$orderId,
                'projects' => $projects,
            ];
        }
        return $cards;
    }

    /** @return array{tab:string,keyword:string,status:string,dateFrom:string,dateTo:string} */
    private function detailQuery(array $raw): array
    {
        $tab = trim((string)($raw['tab'] ?? ''));
        $allowedTabs = ['profile', 'archive', 'assets', 'card-operations', 'coupons', 'sales', 'writeoff', 'care', 'debt', 'gift', 'points', 'balance-changes'];
        $keyword = trim((string)($raw['keyword'] ?? ''));
        $status = trim((string)($raw['status'] ?? ''));
        if (!in_array($status, ['', 'active', 'unfinished'], true)) $status = '';
        $dateFrom = $this->validDate((string)($raw['dateFrom'] ?? ''));
        $dateTo = $this->validDate((string)($raw['dateTo'] ?? ''));
        if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        return [
            'tab' => in_array($tab, $allowedTabs, true) ? $tab : '',
            'keyword' => mb_substr($keyword, 0, 80),
            'status' => $status,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
        ];
    }

    private function validDate(string $value): string
    {
        $value = trim($value);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
    }

    private function applyDetailKeyword($query, string $keyword, array $columns): void
    {
        $keyword = trim($keyword);
        if ($keyword === '' || $columns === []) return;
        $like = '%' . addcslashes($keyword, "\\%_") . '%';
        $query->where(function ($where) use ($columns, $like): void {
            foreach (array_values($columns) as $index => $column) {
                if ($index === 0) $where->where($column, 'like', $like);
                else $where->whereOr($column, 'like', $like);
            }
        });
    }

    private function craftsmenSummary(string $json, array $fallbackNames = []): string
    {
        $values = json_decode($json, true);
        if (!is_array($values)) return implode('、', array_values(array_unique(array_filter($fallbackNames))));
        $names = [];
        foreach ($values as $value) {
            if (!is_array($value)) continue;
            $name = trim((string)($value['name'] ?? $value['staff_name_snapshot'] ?? $value['staffName'] ?? $value['staff_name'] ?? ''));
            if ($name === '') continue;
            // 只展示持久化的分配类型。旧快照没有该字段时不能从金额或
            // 人员反推，明确标记为未标注，避免把历史数据误写成轮次。
            if (array_key_exists('isPointCustomer', $value)) {
                $label = $name . ((bool)$value['isPointCustomer'] ? '（点）' : '（轮）');
            } elseif (array_key_exists('is_point_customer', $value)) {
                $label = $name . ((bool)$value['is_point_customer'] ? '（点）' : '（轮）');
            } else {
                $label = $name . '（未标注）';
            }
            if (!in_array($label, $names, true)) $names[] = $label;
        }
        $summary = implode('、', $names);
        if ($summary !== '' && !str_contains($summary, '未标注')) return $summary;
        return $fallbackNames !== [] ? implode('、', array_values(array_unique(array_filter($fallbackNames)))) : $summary;
    }

    private function giftKindLabel(string $value): string
    {
        $normalized = strtolower(trim($value));
        $labels = ['project' => '项目', 'product' => '产品', 'coupon' => '优惠券'];
        return $labels[$normalized] ?? (trim($value) !== '' ? $value : '赠送内容');
    }

    private function giftStatusLabel(string $value): string
    {
        $normalized = strtolower(trim($value));
        return [
            'effective' => '已生效',
            'issued' => '已生效',
            'revoked' => '已作废',
            'voided' => '已作废',
            'cancelled' => '已取消',
            'expired' => '已过期',
        ][$normalized] ?? (trim($value) !== '' ? $value : '—');
    }

    private function careTypeLabel(string $value): string
    {
        $normalized = strtoupper(trim($value));
        $labels = [
            'DAILY_FOLLOWUP' => '日常跟进', 'FOLLOW_UP' => '回访', 'INVITATION' => '邀约',
            'SERVICE_FEEDBACK' => '服务后反馈', 'MANUAL' => '人工跟进',
        ];
        return $labels[$normalized] ?? (trim($value) !== '' ? $value : '—');
    }

    private function careTaskStatusLabel(string $value): string
    {
        $normalized = strtoupper(trim($value));
        $labels = ['UNSTARTED' => '未开始', 'PENDING' => '待处理', 'IN_PROGRESS' => '进行中', 'COMPLETED' => '已完成', 'CANCELLED' => '已取消'];
        return $labels[$normalized] ?? (trim($value) !== '' ? $value : '—');
    }

    private function balanceChangeTypeLabel(string $value, string $direction): string
    {
        $normalized = strtolower(trim($value));
        // Checkout balance deductions use order_payment in immutable facts;
        // keep that code for reconciliation and translate only the UI label.
        $labels = [
            'recharge' => '充值', 'recharge_credit' => '充值', 'balance_payment' => '余额支付',
            'order_payment' => '余额支付',
            'order_void' => '订单作废返还', 'order_refund' => '订单退款返还',
            'balance_restored' => '余额返还', 'recharge_debt_repayment_credit' => '欠款补交转余额',
            // 退款仍保留原始类型码用于对账，会员页面只展示可理解的业务名称。
            'manual_refund_credit' => '退款转入余额',
            'manual_refund_debit' => '余额退款扣减',
        ];
        $label = $labels[$normalized] ?? (trim($value) !== '' ? $value : '余额变动');
        return strtolower(trim($direction)) === 'reversal' && !str_contains($label, '返还') ? $label . '冲销' : $label;
    }

    private function balanceChangeDisplayNo(string $businessDate, int $rowId): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $businessDate);
        if (!$date || $date->format('Y-m-d') !== $businessDate || $rowId <= 0) {
            return '';
        }
        return 'YE' . $date->format('ymd') . str_pad((string)$rowId, 6, '0', STR_PAD_LEFT);
    }

    private function cardOperationTypeLabel(string $value): string
    {
        $labels = [
            'card_upgrade' => '卡升级', 'card_extension' => '卡延期',
            'card_transfer' => '卡转让', 'card_disable' => '卡停用',
            'card_enable' => '卡启用', 'project_replacement' => '项目替换',
            'project_upgrade' => '项目升级',
        ];
        $key = strtolower(trim($value));
        return $labels[$key] ?? (trim($value) !== '' ? $value : '卡操作');
    }

    private function cardOperationStatusLabel(string $value): string
    {
        $labels = [
            'succeeded' => '已完成', 'awaiting_checkout' => '待结账',
            'failed' => '失败', 'cancelled' => '已取消',
        ];
        $key = strtolower(trim($value));
        return $labels[$key] ?? (trim($value) !== '' ? $value : '—');
    }

    private function cardOperationTargetContent(array $row): string
    {
        $type = strtolower(trim((string)($row['operation_type'] ?? '')));
        if ($type === 'card_transfer') {
            return (string)($row['member_name_after_snapshot'] ?? '');
        }
        if ($type === 'card_extension') {
            return $this->dateOnly((int)($row['write_end_after'] ?? 0));
        }
        if ($type === 'card_disable' || $type === 'card_enable') {
            return $this->cardStatusLabel((string)($row['card_status_after'] ?? ''));
        }
        return (string)($row['target_catalog_name_snapshot'] ?? '');
    }

    private function cardStatusLabel(string $value): string
    {
        $labels = ['enabled' => '启用', 'disabled' => '停用'];
        $key = strtolower(trim($value));
        return $labels[$key] ?? (trim($value) !== '' ? $value : '—');
    }

    private function sourceDocumentLabel(string $value): string
    {
        $labels = ['sales' => '销售订单', 'recharge' => '充值订单',
            'debt_repayment' => '欠款补交', 'manual_refund' => '退款记录'];
        $key = strtolower(trim($value));
        return $labels[$key] ?? (trim($value) !== '' ? $value : '—');
    }

    private function projectName($cartInfo): string
    {
        $decoded = is_array($cartInfo) ? $cartInfo : json_decode((string)$cartInfo, true);
        $decoded = is_array($decoded) ? $decoded : [];
        $name = trim((string)($decoded['productInfo']['store_name'] ?? ''));
        return $name !== '' ? $name : '项目';
    }

    private function isActiveMember($member): bool
    {
        if (!is_array($member) || (int)($member['uid'] ?? 0) <= 0
            || (int)($member['status'] ?? 0) !== 1 || (int)($member['is_del'] ?? 0) !== 0) {
            return false;
        }
        $deleteTime = $member['delete_time'] ?? null;
        return $deleteTime === null || $deleteTime === '' || $deleteTime === 0 || $deleteTime === '0'
            || $deleteTime === '0000-00-00 00:00:00';
    }

    private function money($value): string
    {
        $raw = trim((string)$value);
        if (!preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,8})?$/', $raw)) {
            return '0.00';
        }
        return bcadd($raw, '0', 2);
    }

    private function moneyFromCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $absolute = abs($cents);
        return $sign . intdiv($absolute, 100) . '.' . str_pad((string)($absolute % 100), 2, '0', STR_PAD_LEFT);
    }

    private function dateTime($value, string $empty = ''): string
    {
        $timestamp = (int)$value;
        return $timestamp > 0 ? date('Y-m-d H:i:s', $timestamp) : $empty;
    }

    private function notFound(): CashierV3CommandException
    {
        return new CashierV3CommandException(
            CashierV3ResultCode::RESOURCE_NOT_FOUND,
            '该会员不存在或当前不可见。',
            CashierV3ResultCode::STATUS_FAILED
        );
    }
}
