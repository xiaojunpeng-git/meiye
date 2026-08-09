<?php

namespace app\services\cashier\v3\member;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\cashier\CashierV3EntitlementActualAmountAllocator;
use app\services\cashier\v3\checkout\provider\CashierV3MemberBalanceProvider;
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
    public function read(
        int $memberId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope,
        array $detailQuery = []
    ): array {
        if ($memberId <= 0) {
            throw $this->notFound();
        }
        $this->assertVisible($memberId, $dataScope);

        $member = Db::name('user')
            ->where('uid', $memberId)
            ->field('uid,nickname,real_name,phone,bar_code,belong_store_id,integral,level,sex,birthday,wechat_account,addres,mark,add_time,extend_info,status,is_del,delete_time')
            ->find();
        if (!$this->isActiveMember($member)) {
            throw $this->notFound();
        }

        $storeId = (int)($member['belong_store_id'] ?? 0);
        if ($storeId <= 0) {
            $storeId = $operatorScope->storeId();
        }
        $detailQuery = $this->detailQuery($detailQuery);
        $cards = $this->cards($memberId, $operatorScope->tenantId(), $dataScope, $detailQuery['tab'] === 'assets' ? $detailQuery['keyword'] : '', $detailQuery['tab'] === 'assets' ? $detailQuery['status'] : 'active', $detailQuery['tab'] === 'assets' && $detailQuery['status'] === '' ? $detailQuery['dateFrom'] : '', $detailQuery['tab'] === 'assets' && $detailQuery['status'] === '' ? $detailQuery['dateTo'] : '');
        $services = $this->services($memberId, $operatorScope->tenantId(), $dataScope, $detailQuery['tab'] === 'writeoff' ? $detailQuery['keyword'] : '', $detailQuery['tab'] === 'writeoff' ? $detailQuery['dateFrom'] : '', $detailQuery['tab'] === 'writeoff' ? $detailQuery['dateTo'] : '');
        $gifts = $this->gifts($memberId, $operatorScope->tenantId(), $dataScope, $detailQuery['tab'] === 'gift' ? $detailQuery['keyword'] : '', $detailQuery['tab'] === 'gift' ? $detailQuery['status'] : 'active', $detailQuery['tab'] === 'gift' ? $detailQuery['dateFrom'] : '', $detailQuery['tab'] === 'gift' ? $detailQuery['dateTo'] : '');
        $care = $this->care($memberId, $operatorScope->tenantId(), $dataScope, $detailQuery['tab'] === 'care' ? $detailQuery['keyword'] : '', $detailQuery['tab'] === 'care' ? $detailQuery['status'] : '', $detailQuery['tab'] === 'care' && $detailQuery['status'] === '' ? $detailQuery['dateFrom'] : '', $detailQuery['tab'] === 'care' && $detailQuery['status'] === '' ? $detailQuery['dateTo'] : '');
        $balanceChanges = $this->balanceChanges($memberId, $operatorScope->tenantId(), $dataScope);
        $cardOperations = $this->cardOperations(
            $memberId,
            $operatorScope->tenantId(),
            $dataScope,
            $detailQuery['tab'] === 'card-operations' ? $detailQuery['keyword'] : ''
        );
        $overview = $this->overview($memberId, $operatorScope->tenantId(), $services, $dataScope);
        $remainingTimes = 0;
        $remainingAmount = '0.00';
        $activeCardCount = 0;
        foreach ($cards as $card) {
            if (($card['statusCode'] ?? '') !== 'enabled') continue;
            $activeCardCount++;
            if (($card['cardRuleType'] ?? '') !== 'time') {
                $remainingTimes += (int)($card['remainingTimes'] ?? 0);
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
            : '';
        $balanceSnapshot = (new CashierV3MemberBalanceProvider())->readSnapshot(
            $memberId,
            $operatorScope,
            $dataScope
        );
        $balance = $this->moneyFromCents((int)$balanceSnapshot['totalCents']);
        $principalBalance = $this->moneyFromCents((int)$balanceSnapshot['principalCents']);
        $giftBalance = $this->moneyFromCents((int)$balanceSnapshot['giftCents']);

        return [
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
                'totalConsumptionAmount' => $overview['totalConsumptionAmount'],
                'latestPurchaseDate' => $overview['latestPurchaseDate'],
                'visitCount' => $overview['visitCount'],
                'latestVisitDate' => $overview['latestVisitDate'],
            ],
            'profile' => [
                'memberLevel' => $levelName,
                'genderLabel' => $this->genderLabel((int)($member['sex'] ?? 0)),
                'birthday' => $this->dateOnly((int)($member['birthday'] ?? 0)),
                'wechat' => (string)($member['wechat_account'] ?? ''),
                'tags' => [],
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
            // 余额记录只读取 V3 不可变 balance_changed 事实；本金和赠金拆分不从
            // 页面、订单或当前余额反推。
            'balanceChanges' => $balanceChanges,
            'cardOperations' => $cardOperations,
            'serviceRecords' => $services,
            'giftRecords' => $gifts,
            'careRecords' => $care['records'],
            'careTasks' => $care['tasks'],
            'latestVisit' => $overview['latestVisit'],
            'careReminder' => $care['reminder'],
            'tabStates' => [
                'profile' => ['total' => 1, 'dataStatus' => 'authoritative'],
                'assets' => ['total' => count($cards), 'dataStatus' => 'authoritative'],
                'balance-changes' => ['total' => count($balanceChanges), 'dataStatus' => 'authoritative'],
                'card-operations' => ['total' => count($cardOperations), 'dataStatus' => 'authoritative'],
                'writeoff' => ['total' => count($services), 'dataStatus' => 'authoritative'],
                'care' => ['total' => count($care['records']), 'dataStatus' => 'authoritative'],
                'gift' => ['total' => count($gifts), 'dataStatus' => 'authoritative'],
            ],
            'dataAsOf' => date('c'),
            'detailQuery' => $detailQuery,
            'projectionContractVersion' => 'cashier-v3-member-detail-v3',
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function services(int $memberId, string $tenantId, CashierV3DataScopeContext $dataScope, string $keyword = '', string $dateFrom = '', string $dateTo = ''): array
    {
        $query = Db::name('cashier_v3_entitlement_service_fact')->alias('sf')
            ->leftJoin(
                'cashier_v3_entitlement_writeoff_fact wf',
                'wf.tenant_id = sf.tenant_id AND wf.checkout_request_id = sf.checkout_request_id AND wf.source_line_id = sf.source_line_id'
            )
            ->where('sf.tenant_id', $tenantId)->where('sf.member_id', $memberId)
            ->where('sf.service_status', 'completed');
        $this->applyStoreScope($query, 'sf.store_id', $dataScope);
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
    private function balanceChanges(int $memberId, string $tenantId, CashierV3DataScopeContext $dataScope): array
    {
        $query = Db::name('cashier_v3_balance_fact')
            ->where('tenant_id', $tenantId)
            ->where('member_id', $memberId)
            ->where('status', 'effective');
        $this->applyStoreScope($query, 'store_id', $dataScope);
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
        CashierV3DataScopeContext $dataScope,
        string $keyword = ''
    ): array {
        $query = Db::name('cashier_v3_card_operation')
            ->where('tenant_id', $tenantId)
            ->where(function ($memberQuery) use ($memberId): void {
                $memberQuery->where('member_id_before', $memberId)
                    ->whereOr('member_id_after', $memberId);
            });
        $this->applyStoreScope($query, 'store_id', $dataScope);
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
    private function gifts(int $memberId, string $tenantId, CashierV3DataScopeContext $dataScope, string $keyword = '', string $status = 'active', string $dateFrom = '', string $dateTo = ''): array
    {
        $result = [];
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
            }
            $this->applyStoreScope($query, 'gf.store_id', $dataScope);
            $this->applyDetailKeyword($query, $keyword, ['gf.content_name_snapshot', 'ga.gift_no', 'ga.' . $source['reason']]);
            $fields = [
                'gf.id,gf.gift_fact_id,gf.source_id,gf.source_detail_id,gf.gift_kind,gf.content_name_snapshot,gf.quantity',
                'gf.content_snapshot_json,gf.business_date,gf.settled_at,gf.recorded_at,gf.status,ss.name AS store_name',
                'ga.gift_no,ga.' . $source['reason'] . ' AS reason_snapshot',
            ];
            if ($isDirectGift) {
                $fields[] = 'gi.content_snapshot_json AS item_content_snapshot_json,ga.validity_end AS authority_validity_end';
            }
            $rows = $query
                ->field(implode(',', $fields))
                ->order('gf.settled_at desc,gf.id desc')->limit(100)->select()->toArray();
            foreach ($rows as $row) {
                $snapshotJson = $isDirectGift
                    ? ($row['item_content_snapshot_json'] ?: $row['content_snapshot_json'])
                    : $row['content_snapshot_json'];
                $snapshot = json_decode((string)$snapshotJson, true);
                $snapshot = is_array($snapshot) ? $snapshot : [];
                $validityEnd = (int)($snapshot['validityEnd'] ?? $snapshot['validity_end'] ?? 0);
                if ($validityEnd <= 0 && $isDirectGift) {
                    $validityEnd = (int)($row['authority_validity_end'] ?? 0);
                }
                $result[] = [
                    'id' => 'gift:' . (string)$row['id'],
                    'giftNo' => (string)$row['gift_no'],
                    'businessDate' => (string)$row['business_date'],
                    'sourceLabel' => $source['source'] === 'direct_gift' ? '直接赠送' : '充值赠送',
                    'storeName' => (string)($row['store_name'] ?? ''),
                    'reason' => (string)($row['reason_snapshot'] ?? ''),
                    'statusLabel' => $this->giftStatusLabel((string)($row['status'] ?? 'effective')),
                    'createdAt' => $this->dateTime((int)$row['recorded_at']),
                    'contents' => [[
                        'typeLabel' => $this->giftKindLabel((string)$row['gift_kind']),
                        'name' => (string)$row['content_name_snapshot'],
                        'quantity' => (int)$row['quantity'],
                        'effectiveAt' => $this->dateTime((int)$row['settled_at']),
                        'expiresAt' => $validityEnd > 0 ? $this->dateTime($validityEnd) : null,
                        'statusLabel' => $this->giftStatusLabel((string)($row['status'] ?? 'effective')),
                    ]],
                    '_issuedAt' => (int)$row['settled_at'],
                ];
            }
        }
        usort($result, static function (array $left, array $right): int {
            return (int)$right['_issuedAt'] <=> (int)$left['_issuedAt'];
        });
        foreach ($result as &$row) unset($row['_issuedAt']);
        unset($row);
        return $result;
    }

    /** @return array{records:array<int,array<string,mixed>>,tasks:array<int,array<string,mixed>>,reminder:array<string,mixed>} */
    private function care(int $memberId, string $tenantId, CashierV3DataScopeContext $dataScope, string $keyword = '', string $status = '', string $dateFrom = '', string $dateTo = ''): array
    {
        $records = [];
        // 客情记录本身代表已经发生的跟进；“未完成”只显示尚未完成的任务，
        // 不把已完成的历史记录伪装成待办。
        if ($status !== 'unfinished') {
            $recordQuery = Db::name('customer_care_record')->where('tenant_id', $tenantId)
                ->where('member_id', $memberId)->where('status', 'NORMAL');
            $this->applyStoreScope($recordQuery, 'business_store_id', $dataScope);
            $this->applyDetailKeyword($recordQuery, $keyword, ['record_no', 'record_type', 'summary', 'detail', 'follower_name_snapshot']);
            if ($dateFrom !== '') $recordQuery->where('followed_at', '>=', strtotime($dateFrom . ' 00:00:00'));
            if ($dateTo !== '') $recordQuery->where('followed_at', '<=', strtotime($dateTo . ' 23:59:59'));
            $records = $recordQuery
                ->field('id,record_no,record_type,summary,detail,followed_at,follower_name_snapshot,business_store_name_snapshot,status')
                ->order('followed_at desc,id desc')->limit(100)->select()->toArray();
        }
        $taskQuery = Db::name('customer_care_task')->where('tenant_id', $tenantId)
            ->where('member_id', $memberId)->where('is_visible', 1);
        $this->applyStoreScope($taskQuery, 'business_store_id', $dataScope);
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

    /** @param array<int,array<string,mixed>> $services @return array<string,mixed> */
    private function overview(int $memberId, string $tenantId, array $services, CashierV3DataScopeContext $dataScope): array
    {
        $salesQuery = Db::name('cashier_v3_sale_fact')->where('tenant_id', $tenantId)
            ->where('member_id', $memberId)->where('status', 'effective');
        $this->applyStoreScope($salesQuery, 'store_id', $dataScope);
        $sales = (array)$salesQuery
            ->field('COALESCE(SUM(sale_amount_cents),0) AS total_cents,MAX(business_date) AS latest_date')->find();
        $visitQuery = Db::name('cashier_v3_entitlement_service_fact')->where('tenant_id', $tenantId)
            ->where('member_id', $memberId)->where('service_status', 'completed');
        $this->applyStoreScope($visitQuery, 'store_id', $dataScope);
        $visits = (array)$visitQuery->field('COUNT(DISTINCT business_date) AS visit_count')->find();
        $latest = $services[0] ?? [];
        return [
            'totalConsumptionAmount' => $this->moneyFromCents((int)($sales['total_cents'] ?? 0)),
            'latestPurchaseDate' => (string)($sales['latest_date'] ?? ''),
            'visitCount' => (int)($visits['visit_count'] ?? 0),
            'latestVisitDate' => (string)($latest['businessDate'] ?? ''),
            'latestVisit' => $latest ? [
                'visitedAt' => (string)$latest['completedAt'], 'storeName' => (string)$latest['storeName'],
                'projectName' => (string)$latest['projectName'], 'craftsmenSummary' => (string)$latest['craftsmenSummary'],
            ] : [],
        ];
    }

    /** @return array<int,array{key:string,label:string,value:string}> */
    private function customFields($raw): array
    {
        $decoded = is_array($raw) ? $raw : json_decode((string)$raw, true);
        if (!is_array($decoded)) return [];
        $fields = [];
        foreach ($decoded as $key => $value) {
            if (!is_scalar($value) || trim((string)$value) === '') continue;
            $fields[] = ['key' => (string)$key, 'label' => (string)$key, 'value' => (string)$value];
        }
        return $fields;
    }

    private function genderLabel(int $sex): string
    {
        return $sex === 1 ? '男' : ($sex === 2 ? '女' : '');
    }

    private function dateOnly(int $timestamp): string
    {
        return $timestamp > 0 ? date('Y-m-d', $timestamp) : '';
    }

    private function assertVisible(int $memberId, CashierV3DataScopeContext $dataScope): void
    {
        if ($dataScope->authorizationMode() === CashierV3DataScopeContext::MODE_ALL) {
            return;
        }
        if ($dataScope->authorizationMode() !== CashierV3DataScopeContext::MODE_STORES) {
            throw $this->notFound();
        }
        $storeIds = array_values(array_unique(array_filter(array_map('intval', (array)$dataScope->visibleStoreIds()))));
        if (!$storeIds || !Db::name('store_user')
            ->where('uid', $memberId)
            ->where('status', 1)
            ->whereIn('store_id', $storeIds)
            ->value('uid')) {
            throw $this->notFound();
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function cards(int $memberId, string $tenantId, CashierV3DataScopeContext $dataScope, string $keyword = '', string $status = 'active', string $dateFrom = '', string $dateTo = ''): array
    {
        $now = time();
        $activeOnly = $status === 'active';
        $holderQuery = Db::name('user_card_holder')->alias('h')
            ->join('store_order o', 'o.id = h.oid')
            ->where('h.uid', $memberId)
            ->where('h.is_del', 0)
            ->where('h.store_id', '>', 0)
            ->where('o.uid', $memberId)
            ->where('o.paid', 1)
            ->where('o.is_del', 0)
            ->where('o.is_system_del', 0)
            ->where('o.is_user_del', 0)
            ->where('o.card_upgrade_use_oid', 0);
        if ($activeOnly) {
            $holderQuery->where('h.write_surplus_times', '>', 0)->where('o.refund_status', 0)->where('o.terminal_action', 0);
        }
        if ($dateFrom !== '') $holderQuery->where('h.add_time', '>=', strtotime($dateFrom . ' 00:00:00'));
        if ($dateTo !== '') $holderQuery->where('h.add_time', '<=', strtotime($dateTo . ' 23:59:59'));
        $this->applyStoreScope($holderQuery, 'h.store_id', $dataScope);
        $this->applyDetailKeyword($holderQuery, $keyword, ['h.card_name', 'h.card_no']);
        $holders = $holderQuery
            ->field('h.id,h.oid,h.card_name,h.card_no,h.store_id,h.write_start,h.write_end,h.add_time,o.pay_price as order_pay_price')
            ->order('h.id', 'asc')
            ->select()
            ->toArray();

        $holderByOrder = [];
        foreach ($holders as $holder) {
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
            ->where('current_member_id', $memberId)
            ->whereIn('card_holder_id', $holderIds)
            ->column('card_status', 'card_holder_id');
        $cardRuleTypeByHolder = Db::name('cashier_v3_card_rule_state')
            ->where('tenant_id', $tenantId)
            ->where('member_id', $memberId)
            ->whereIn('card_holder_id', $holderIds)
            ->column('rule_type', 'card_holder_id');

        $projectsByOrder = [];
        $rows = Db::name('store_order_cart_info')
            ->whereIn('oid', array_keys($holderByOrder))
            ->where('cart_type', 2)
            ->where('product_type', 6)
            ->where('is_writeoff', 0)
            ->field('id,oid,cart_info,write_times,write_surplus_times,write_start,write_end,pay_price')
            ->order('oid asc,id asc')
            ->select()
            ->toArray();
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
                $amount = CashierV3EntitlementActualAmountAllocator::remaining(
                    $this->money($row['pay_price'] ?? 0),
                    $total,
                    $total - $remaining
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
                'usedTimes' => $total - $remaining,
                'remainingTimes' => $remaining,
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
            $remainingAmount = '0.00';
            foreach ($projects as $project) {
                $remainingTimes += (int)$project['remainingTimes'];
                $remainingAmount = bcadd($remainingAmount, (string)$project['remainingAmount'], 2);
            }
            $holderId = (int)($holder['id'] ?? 0);
            $cardRuleType = (string)($cardRuleTypeByHolder[$holderId] ?? '');
            $isTimeCard = $cardRuleType === 'time';
            if ($isTimeCard) {
                $remainingAmount = $this->money($holder['order_pay_price'] ?? 0);
            }
            $statusCode = (string)($cardStatusByHolder[$holderId] ?? 'enabled');
            $cards[] = [
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
                'remainingAmount' => $remainingAmount,
                'purchaseBatchNo' => (string)$orderId,
                'projects' => $projects,
            ];
        }
        return $cards;
    }

    private function applyStoreScope($query, string $column, CashierV3DataScopeContext $dataScope): void
    {
        if ($dataScope->authorizationMode() === CashierV3DataScopeContext::MODE_ALL) {
            return;
        }
        $storeIds = array_values(array_unique(array_filter(array_map(
            'intval',
            (array)$dataScope->visibleStoreIds()
        ))));
        if (!$storeIds) {
            throw $this->notFound();
        }
        $query->whereIn($column, $storeIds);
    }

    /** @return array{tab:string,keyword:string,status:string,dateFrom:string,dateTo:string} */
    private function detailQuery(array $raw): array
    {
        $tab = trim((string)($raw['tab'] ?? ''));
        $allowedTabs = ['assets', 'card-operations', 'sales', 'writeoff', 'care', 'debt', 'gift', 'points'];
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
        $labels = [
            'recharge' => '充值', 'recharge_credit' => '充值', 'balance_payment' => '余额支付',
            'order_void' => '订单作废返还', 'order_refund' => '订单退款返还',
            'balance_restored' => '余额返还', 'recharge_debt_repayment_credit' => '欠款补交转余额',
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
        $labels = ['sales' => '销售订单', 'recharge' => '充值订单', 'debt_repayment' => '欠款补交'];
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
