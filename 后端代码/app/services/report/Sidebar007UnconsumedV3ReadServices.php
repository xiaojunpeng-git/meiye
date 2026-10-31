<?php
declare(strict_types=1);

namespace app\services\report;

use app\services\cashier\v3\card\CashierV3CardSaleActualAmountServices;
use app\services\cashier\v3\card\CashierV3CardComponentPurchaseAmountAuthority;
use app\services\cashier\v3\card\CashierV3CardRuleEntitlementAuthorityServices;
use app\services\cashier\v3\CashierV3ScopeResolver;
use think\facade\Db;

/**
 * 007 三张未消耗表的字段投影。当前权益和储值由 V3 会员权威读取，
 * 逐原卡项目的购买及已使用额从原组件分配和真实核销事实独立取得。
 * 不用购买减余额伪造核销，也不把今天的余额冒充历史余额。
 * Host 负责生成可信权限上下文；本类再次验证返回行没有越出已授权门店。
 */
final class Sidebar007UnconsumedV3ReadServices
{
    private const CODES = [
        'basic_unconsumed_summary', 'basic_unconsumed_detail', 'basic_unconsumed_as_of_detail',
    ];

    /** 纯静态目录入口，Host 不需为显示列而执行会员与卡项查询。 */
    public static function codes(): array { return self::CODES; }

    /** 007 可见列与可合计性；明细的会员余额重复展示，不能直接逐行加总。 */
    public function definitions(string $code): array
    {
        if (!in_array($code, self::CODES, true)) throw new \InvalidArgumentException('未登记未消耗报表');
        $fields = $code === 'basic_unconsumed_summary'
            ? $this->fields(['store_name','principal_amount','gift_amount','balance_amount','rights_amount'],
                ['门店','本金','赠金','余额','疗程权益金额'], [1,2,3,4])
            : $this->fields(['store_name','customer_name','phone','principal_amount','gift_amount','card_no',
                'card_name','purchase_amount','used_amount','rights_amount'],
                ['门店','客户','手机','本金','赠金','卡号','卡名称','购买金额','已使用金额','疗程权益金额'],
                [3,4,7,8,9], ['purchase_amount','used_amount']);
        $hidden = $code === 'basic_unconsumed_summary' ? ['store_id','row_id']
            : ['store_id','member_id','card_holder_id','project_detail_id','row_id'];
        foreach ($hidden as $key) $fields[] = ['key'=>$key, 'label'=>$key, 'type'=>$key === 'row_id' ? 'text' : 'integer',
            'money'=>false, 'defaultVisible'=>false, 'summable'=>false];
        return $fields;
    }

    /**
     * 实时明细只允许查询上海时区当天。可注入读取器只供独立合同测试；
     * 正式调用固定复用 BasicEntitlementReportServices 的会员/项目权益权威读取。
     *
     * @param int[] $authorizedStoreIds Host 已裁剪门店范围
     */
    public function read(string $code, array $authorizedStoreIds, array $input, ?callable $authorityRead = null,
        ?callable $amountRead = null): array
    {
        if (!in_array($code, self::CODES, true)) throw new \InvalidArgumentException('未登记未消耗报表');
        $stores = $this->storeIds($authorizedStoreIds);
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
        if ($code === 'basic_unconsumed_as_of_detail' && isset($input['as_of_date'])
            && $input['as_of_date'] !== '' && $input['as_of_date'] !== $today) {
            throw new \LogicException('未消耗实时明细仅支持当天查询');
        }
        $authorityRead ??= static fn(string $page, array $scope, array $filter): array
            => (new BasicEntitlementReportServices())->read($page, $scope, $filter);
        $sourceCode = $code === 'basic_unconsumed_summary' ? BasicEntitlementReportServices::UNCONSUMED_SUMMARY_SOURCE
            : BasicEntitlementReportServices::UNCONSUMED_COMPONENT_SOURCE;
        try {
            $source = $authorityRead($sourceCode, $stores, $input);
        } catch (\RuntimeException $error) {
            // 已迁持卡实例若没有可证来源门店，整张未消耗表返回明确缺口；不借会员所属店推断或局部加总。
            if ($error->getMessage() !== 'ENTITLEMENT_HOLDER_STORE_UNRESOLVED') throw $error;
            $empty = ['rows' => []];
            $result = $code === 'basic_unconsumed_summary'
                ? $this->summary($empty, $stores, $today)
                : $this->detail($code, $empty, $stores, $today, null);
            $gap = ['code' => 'ENTITLEMENT_HOLDER_STORE_UNRESOLVED',
                'message' => '部分持卡项目缺少可核实的归属门店，当前未消耗统计暂不完整。'];
            // 共享页面以label展示业务原因，message保留给查询审计。
            $gap['label'] = $gap['message'];
            $result['complete'] = false;
            $result['data_gaps'] = [$gap];
            $result['gaps'] = [$gap];
            $result['sum_fields'] = [];
            return $result;
        }
        if (!is_array($source) || !isset($source['rows']) || !is_array($source['rows'])
            || ($source['time_mode'] ?? null) !== 'current' || ($source['complete'] ?? false) !== true) {
            throw new \LogicException('V3 当前权益来源不完整');
        }
        $this->assertSourceRows($source['rows'], $stores);
        return $code === 'basic_unconsumed_summary'
            ? $this->summary($source, $stores, $today)
            : $this->detail($code, $source, $stores, $today, $amountRead);
    }

    /** 007 统计表的五列直接来自 V3 当前会员储值与卡项剩余权益。 */
    private function summary(array $source, array $stores, string $today): array
    {
        $keys = ['store_name', 'principal_amount', 'gift_amount', 'balance_amount', 'rights_amount'];
        $fields = $this->definitions('basic_unconsumed_summary');
        $rows = [];
        foreach ($source['rows'] as $item) {
            $row = ['row_id' => (string)($item['row_id'] ?? ''), 'store_id' => (int)$item['store_id']];
            foreach ($keys as $key) $row[$key] = $this->required($item, $key);
            $rows[] = $row;
        }
        return $this->result('basic_unconsumed_summary', $fields, $rows, [], $stores, $today,
            ['principal_amount'=>'当前有效会员储值本金。', 'gift_amount'=>'当前有效会员储值赠金。',
                'balance_amount'=>'当前可用本金加赠金。',
                'rights_amount'=>'当前有效卡项的剩余权益金额，失效权益不计入。']);
    }

    /**
     * 按原卡项目组件稳定 ID 展示，余额只按同一会员身份关联；多组件
     * 重复的本金赠金不逐行合计。购买、核销与剩余各自来自独立权威，
     * 不以一列倒推另一列；任一原事实缺失时保留显式缺口。
     */
    private function detail(string $code, array $source, array $stores, string $today, ?callable $amountRead): array
    {
        $keys = ['store_name','customer_name','phone','principal_amount','gift_amount','card_no',
            'card_name','purchase_amount','used_amount','rights_amount'];
        $fields = $this->definitions($code);
        $balances = []; $projects = []; $seen = [];
        foreach ($source['rows'] as $item) {
            $member = (int)($item['member_id'] ?? 0);
            if ($member < 1) throw new \LogicException('V3 权益会员身份缺失');
            if (($item['asset_type'] ?? '') === '储值') {
                if (isset($balances[$member])) throw new \LogicException('会员储值行重复');
                $balances[$member] = $item;
            } elseif (($item['asset_type'] ?? '') === '卡内项目') {
                $holder = (int)($item['card_holder_id'] ?? 0);
                $project = (int)($item['project_detail_id'] ?? 0);
                $identity = $holder.':'.$project;
                if ($holder < 1 || $project < 1 || isset($seen[$identity])
                    || !in_array((int)($item['store_id'] ?? 0), $stores, true)) {
                    throw new \LogicException('V3 项目组件身份重复或越权');
                }
                $seen[$identity] = true; $projects[] = $item;
            } else {
                throw new \LogicException('V3 未消耗来源出现未知权益类型');
            }
        }
        // 无卡储值会员不需要卡项金额事实；测试注入与正式读取的结果合同相同。
        $amounts = $projects ? ($amountRead ? $amountRead($projects, $stores, $source)
            : $this->componentAmounts($projects, $stores)) : [];
        if (!is_array($amounts)) throw new \LogicException('项目组件金额权威返回无效');
        $rows = []; $gaps = [];
        $membersWithCards = [];
        foreach ($projects as $card) {
            $member = (int)$card['member_id']; $balance = $balances[$member] ?? null;
            $holder = (int)$card['card_holder_id']; $project = (int)$card['project_detail_id'];
            $amount = $amounts[$project] ?? [];
            if (array_key_exists('eligible', $amount) && $amount['eligible'] === false) continue;
            $membersWithCards[$member] = true;
            $row = ['row_id'=>'unconsumed:project:'.$holder.':'.$project, 'store_id'=>(int)$card['store_id'],
                'member_id'=>$member, 'card_holder_id'=>$holder, 'project_detail_id'=>$project,
                'store_name'=>$this->required($card,'store_name'),
                'customer_name'=>$this->required($card,'customer_name'), 'phone'=>$this->required($card,'phone'),
                'principal_amount'=>$balance === null ? null : $this->required($balance,'principal_amount'),
                'gift_amount'=>$balance === null ? null : $this->required($balance,'gift_amount'),
                'card_no'=>$this->required($card,'card_no'), 'card_name'=>$this->required($card,'card_name'),
                'purchase_amount'=>$amount['purchase_amount'] ?? null,
                'used_amount'=>$amount['used_amount'] ?? null,
                'rights_amount'=>$this->required($card,'rights_amount')];
            foreach (['purchase_amount','used_amount'] as $key) {
                if ($row[$key] === null) $gaps[] = ['row_id'=>$row['row_id'],'field'=>$key,
                    'reason'=>'原卡项目组件缺少已核实的购买或有效核销事实'];
            }
            if ($balance === null) {
                $gaps[] = ['row_id'=>$row['row_id'],'field'=>'principal_amount','reason'=>'缺少同一会员的 V3 储值权威行'];
                $gaps[] = ['row_id'=>$row['row_id'],'field'=>'gift_amount','reason'=>'缺少同一会员的 V3 储值权威行'];
            }
            $rows[] = $row;
        }
        // 007 普通明细保留仅储值会员；实时明细原人口只有卡项，不借此分支扩大。
        foreach (($code === 'basic_unconsumed_detail' ? $balances : []) as $member => $balance) {
            if (isset($membersWithCards[$member])) continue;
            $principal = $this->required($balance, 'principal_amount');
            $gift = $this->required($balance, 'gift_amount');
            if ((float)$principal === 0.0 && (float)$gift === 0.0) continue;
            $rows[] = ['row_id'=>'unconsumed:balance:'.(int)$balance['store_id'].':'.$member,
                'store_id'=>(int)$balance['store_id'], 'member_id'=>$member, 'card_holder_id'=>null,
                'store_name'=>$this->required($balance,'store_name'),
                'customer_name'=>$this->required($balance,'customer_name'), 'phone'=>$this->required($balance,'phone'),
                'principal_amount'=>$principal, 'gift_amount'=>$gift, 'card_no'=>'',
                'card_name'=>'仅储值余额（无符合条件卡项）', 'purchase_amount'=>0,
                'used_amount'=>0, 'rights_amount'=>0];
        }
        return $this->result($code, $fields, $rows, $gaps, $stores, $today, [
            'principal_amount'=>'同一会员当前储值本金；多项目行重复展示，不参与明细合计。',
            'gift_amount'=>'同一会员当前储值赠金；多项目行重复展示，不参与明细合计。',
            'purchase_amount'=>'该原卡项目实际分配到的购买金额；历史卡以可追溯原购买记录为准。',
            'used_amount'=>'该原卡项目已完成且未作废的服务核销金额；历史核销未完成核对时显示缺口。',
            'rights_amount'=>'该原卡项目当前可用的剩余疗程权益金额。',
        ]);
    }

    /**
     * 购买额优先采用 V3 完成销售的逐组件分配；旧卡只有可核验的原单金额
     * 才走历史金额路径。已用额只读取有效 V3 权益核销事实，旧核销原单仅
     * 用于覆盖审计，不参与数值；有无法映射的旧核销则返回字段缺口。
     */
    private function componentAmounts(array $projects, array $stores): array
    {
        $byDetail = []; $orderIds = []; $holderIds = [];
        foreach ($projects as $project) {
            $id = (int)$project['project_detail_id']; $orderId = (int)($project['origin_order_id'] ?? 0);
            $holderId = (int)$project['card_holder_id'];
            if ($orderId < 1 || $holderId < 1 || isset($byDetail[$id]))
                throw new \LogicException('原卡项目来源身份缺失或重复');
            $byDetail[$id] = $project; $orderIds[$orderId] = true; $holderIds[$holderId] = true;
        }
        $orders = []; $carts = []; $formalOrders = [];
        foreach (array_chunk(array_keys($orderIds), 200) as $chunk) {
            foreach (Db::name('store_order')->whereIn('id', $chunk)
                ->field('id,uid,store_id,order_type,paid,is_del,is_system_del,refund_status,pay_price,debt_amount,repaid_debt_amount')->select()->toArray() as $order) {
                $order['tenant_id'] = CashierV3ScopeResolver::TENANT_SCOPE_ID;
                $orders[(int)$order['id']] = $order;
            }
            // Full original component set is required by whole-yuan allocation:
            // excluding used-up lines would shift the final yuan to another row.
            foreach (Db::name('store_order_cart_info')->whereIn('oid', $chunk)
                ->where('cart_type', 2)->where('product_type', 6)
                ->field('id,oid,product_id,cart_info,pay_price')->order('oid asc,id asc')->select()->toArray() as $cart)
                $carts[(int)$cart['id']] = $cart;
            foreach (Db::name('cashier_v3_card_purchase_receipt')->whereIn('legacy_order_id', $chunk)
                ->where('status', 'completed')->field('legacy_order_id')->select()->toArray() as $receipt)
                $formalOrders[(int)$receipt['legacy_order_id']] = true;
        }
        $sale = (new CashierV3CardSaleActualAmountServices())->wholeYuanForOrders(array_values($orders), array_values($carts));
        $rules = (new CashierV3CardRuleEntitlementAuthorityServices())->authoritiesForHolders(
            CashierV3ScopeResolver::TENANT_SCOPE_ID, array_keys($holderIds));
        $v3Used = []; $legacyMapped = []; $invalidUsed = []; $v3Facts = []; $writeoffIds = [];
        foreach (array_chunk(array_keys($holderIds), 200) as $chunkHolderIds) {
            foreach (Db::name('cashier_v3_entitlement_writeoff_fact')->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
                // The fact index starts with tenant and holder. Adding a
                // second large IN list on detail makes MySQL scan the tenant;
                // every returned detail is verified against the authorized
                // component set below instead. Each holder is read once.
                ->whereIn('holder_id', $chunkHolderIds)->where('status', 'effective')
                ->field('writeoff_id,source_detail_id,holder_id,origin_order_id,member_id,is_gift,source_document_type,source_document_id,actual_entitlement_amount_cents')
                ->select()->toArray() as $fact) {
                $id = (int)$fact['source_detail_id']; $project = $byDetail[$id] ?? null;
                if (!$project) continue;
                if ((int)$fact['holder_id'] !== (int)$project['card_holder_id']
                    || (int)$fact['origin_order_id'] !== (int)$project['origin_order_id']
                    || (int)$fact['member_id'] !== (int)$project['member_id']) {
                    $invalidUsed[$id] = true; continue;
                }
                $writeoffId = (string)$fact['writeoff_id'];
                if ($writeoffId === '' || isset($v3Facts[$writeoffId])) {
                    $invalidUsed[$id] = true; continue;
                }
                $v3Facts[$writeoffId] = $fact; $writeoffIds[] = $writeoffId;
                if (in_array((string)$fact['source_document_type'], ['legacy_writeoff','legacy_paid_project'], true)) {
                    $legacyId = (int)$fact['source_document_id'];
                    if ($legacyId < 1 || isset($legacyMapped[$legacyId])) $invalidUsed[$id] = true;
                    else $legacyMapped[$legacyId] = $id;
                }
            }
        }
        // 旧核销统一走共享007兼容Reader；它核实原单、组件、持卡、终态与
        // 已迁同源排重，不能在本表复制旧SQL或以购卡额减当前余额猜已用额。
        $historical = (new HistoricalCardService007ReadServices())->readComponents(array_values($byDetail));
        foreach ($historical['gaps'] as $gap) {
            $detail = (int)($gap['project_detail_id'] ?? 0);
            if (isset($byDetail[$detail])) $invalidUsed[$detail] = true;
            else foreach ($byDetail as $id => $_) $invalidUsed[$id] = true;
        }
        foreach ($historical['rows'] as $receipt) {
            $id = (int)$receipt['project_detail_id'];
            if (!isset($byDetail[$id])) throw new \LogicException('历史核销投影越出授权组件');
            $amount = $receipt['amount_cents'];
            if (!is_int($amount) || $amount < 0 || ($v3Used[$id] ?? 0) > PHP_INT_MAX - $amount)
                throw new \LogicException('历史已用金额无效或溢出');
            $v3Used[$id] = ($v3Used[$id] ?? 0) + $amount;
        }
        // 服务作废保留原核销事实，再以返还事实加成功操作证明取消；
        // 仅有返还草稿不能从已用额中扣除。
        $reversed = [];
        foreach (array_chunk($writeoffIds, 200) as $chunk) {
            $reversals = Db::name('cashier_v3_entitlement_reversal_fact')
                ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
                ->whereIn('original_writeoff_id', $chunk)
                ->field('original_writeoff_id,operation_id')->select()->toArray();
            if (!$reversals) continue;
            $operationIds = array_values(array_unique(array_column($reversals, 'operation_id')));
            $operations = Db::name('cashier_v3_service_record_void_operation')
                ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
                ->whereIn('operation_id', $operationIds)->field('operation_id,status')->select()->toArray();
            $succeeded = [];
            foreach ($operations as $operation) if ((string)$operation['status'] === 'succeeded')
                $succeeded[(string)$operation['operation_id']] = true;
            foreach ($reversals as $reversal) if (isset($succeeded[(string)$reversal['operation_id']]))
                $reversed[(string)$reversal['original_writeoff_id']] = true;
        }
        foreach ($v3Facts as $writeoffId => $fact) {
            if (isset($reversed[$writeoffId])) continue;
            $id = (int)$fact['source_detail_id'];
            $v3Used[$id] = ($v3Used[$id] ?? 0) + (int)$fact['actual_entitlement_amount_cents'];
        }
        $out = [];
        foreach ($byDetail as $id => $project) {
            $cart = $carts[$id] ?? null; $order = $orders[(int)$project['origin_order_id']] ?? null;
            if (!$cart || !$order || (int)$cart['oid'] !== (int)$order['id']) continue;
            // 原购买额随合法转卡保留；订单买家不应被改成现持卡人。
            if ((int)$order['uid'] !== (int)$project['member_id']
                && !(new ReportCardTransferProofServices())->memberIds((int)$project['card_holder_id'],
                    (int)$order['id'], (int)$order['uid'], (int)$project['member_id'])) continue;
            // 权威源已按当前会员所属店裁剪并校验持卡/组件身份；007允许本店
            // 会员持有外店购买的卡，不能再次按原购店过滤而伪造金额缺口。
            if (($project['imported_entitlement'] ?? false) === true) {
                // 隐藏技术单的0元不是历史购买额。只有单一原组件可直接使用
                // 迁入审计保存的购买金额；多组件无分配证明时不重复整卡金额。
                $componentCount = 0;
                foreach ($carts as $component) if ((int)$component['oid'] === (int)$order['id']) ++$componentCount;
                $sourcePaid = \app\services\cashier\v3\card\CashierV3HistoricalEntitlementScopePolicy::sourcePaidPrice(
                    (int)$project['card_holder_id'], CashierV3ScopeResolver::TENANT_SCOPE_ID);
                if ($sourcePaid !== null) {
                    $sourcePaidCents = $this->moneyCents($sourcePaid);
                    // 原总实付确为0时，每组件购买额必为0，无需猜分配权重。
                    if ($componentCount === 1 || $sourcePaidCents === 0)
                        $out[$id]['purchase_amount'] = $sourcePaidCents / 100;
                }
                // 当前余次是导入截面，不证明导入前已用金额为0；历史核销未迁
                // 到完整可追溯事实前，不能仅累加导入后的核销冒充全历史已用额。
                $out[$id]['used_amount'] = null;
                continue;
            }
            // 007 only exposes paid, nondeleted, nonrefunded original orders.
            // Its unconsumed SQL does not add the separate rights-table gift
            // exclusion, so keep this report's card population independent.
            if ((int)$order['order_type'] !== 0 || (int)$order['paid'] !== 1
                || (int)$order['is_del'] !== 0 || (int)$order['is_system_del'] !== 0
                || (int)$order['refund_status'] !== 0) {
                $out[$id]['eligible'] = false; continue;
            }
            $snapshot = json_decode((string)($cart['cart_info'] ?? ''), true);
            if (!is_array($snapshot)) $snapshot = [];
            if (isset($formalOrders[(int)$order['id']]) && !array_key_exists($id, $sale)
                && !isset($rules[(int)$project['card_holder_id']][$id])) {
                // An issued new card with a missing sale allocation cannot
                // silently fall back to the mutable legacy cart price.
                $out[$id]['purchase_amount'] = null;
            } else {
                try {
                    $purchase = (new CashierV3CardComponentPurchaseAmountAuthority())->resolve(
                        $cart, $snapshot, $sale, $rules[(int)$project['card_holder_id']][$id] ?? null);
                    $out[$id]['purchase_amount'] = $purchase['purchaseAmountCents'] / 100;
                } catch (\InvalidArgumentException $e) {
                    // A present formal sale/rule authority must never be silently
                    // replaced by the old cart price if its amount is invalid.
                    if (!array_key_exists($id, $sale) && !isset($rules[(int)$project['card_holder_id']][$id])) {
                        // Historic cent components remain readable only when an
                        // exact source paid amount exists; never round a catalog price.
                        $legacy = is_array($snapshot['rh_source'] ?? null) ? $snapshot['rh_source'] : [];
                        $raw = $legacy['source_line_paid_amount'] ?? ($cart['pay_price'] ?? null);
                        try { if ($raw !== null) $out[$id]['purchase_amount'] = $this->moneyCents($raw) / 100; }
                        catch (\LogicException $ignored) { /* Invalid historical evidence stays a row-level gap. */ }
                    }
                }
            }
            // 新事实与经核实且未重复的旧来源由同一后端累加，缺源行保持显式缺口。
            if (!isset($invalidUsed[$id]))
                $out[$id]['used_amount'] = ($v3Used[$id] ?? 0) / 100;
        }
        return $out;
    }

    /** Exact legacy money parser keeps historical cents observable without float rounding. */
    private function moneyCents($raw): int
    {
        if (!is_string($raw) && !is_int($raw)) throw new \LogicException('历史金额无精确来源');
        $value = trim((string)$raw);
        if (!preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/D', $value))
            throw new \LogicException('历史金额格式无效');
        $cents = bcmul($value, '100', 0);
        if (bccomp($cents, (string)PHP_INT_MAX, 0) > 0) throw new \LogicException('历史金额超出范围');
        return (int)$cents;
    }

    private function result(string $code, array $fields, array $rows, array $gaps, array $stores,
        string $today, array $sources): array
    {
        $sourceTexts = ['store_name'=>'会员当前所属门店，门店及卡项来源均须在授权范围内。',
            'customer_name'=>'当前会员档案名称。','phone'=>'当前会员档案手机。',
            'card_no'=>'当前有效持卡实例卡号。','card_name'=>'当前有效持卡实例名称。',
            'store_id'=>'会员当前所属门店编号，用于权限和精确筛选。',
            'member_id'=>'会员稳定编号，用于关联同一会员的储值与卡项目。',
            'card_holder_id'=>'当前持卡实例编号，用于区分同会员多张卡。',
            'project_detail_id'=>'原卡项目组件编号，用于区分同卡多个项目。',
            'row_id'=>'按门店、会员、持卡实例或原项目组成的稳定行键。'] + $sources;
        $fieldSources = [];
        foreach ($fields as $field) $fieldSources[$field['key']] = $sourceTexts[$field['key']];
        return ['rows'=>$rows, 'fields'=>$fields, 'field_sources'=>$fieldSources,
            'sum_fields'=>array_column(array_filter($fields, static fn(array $f): bool => $f['summable']), 'key'),
            'table_layout'=>['summary_position'=>'first_row'],
            'complete'=>!$gaps, 'gaps'=>$gaps, 'data_gaps'=>$gaps,
            'time_mode'=>'current', 'as_of_date'=>$today,
            'historical_as_of_supported'=>false,
            'as_of_semantics'=>'仅查询当天日内当前权益，不代表历史日期或当天闭店余额。',
            'scope'=>['store_ids'=>$stores], 'metric_version'=>'sidebar-007-unconsumed-v3-projection-v1',
            'source_report'=>$code];
    }

    private function fields(array $keys, array $labels, array $moneyIndexes, array $summableOnly = []): array
    {
        $fields = [];
        foreach ($keys as $i => $key) $fields[] = ['key'=>$key, 'label'=>$labels[$i],
            'type'=>in_array($i,$moneyIndexes,true)?'decimal':'text',
            'money'=>in_array($i,$moneyIndexes,true), 'defaultVisible'=>true,
            'summable'=>in_array($i,$moneyIndexes,true) && (!$summableOnly || in_array($key,$summableOnly,true))];
        return $fields;
    }

    private function required(array $row, string $key)
    {
        if (!array_key_exists($key,$row) || $row[$key] === null) throw new \LogicException('V3 权益来源缺少字段：'.$key);
        return $row[$key];
    }

    private function assertSourceRows(array $rows, array $stores): void
    {
        $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !in_array((int)($row['store_id'] ?? 0), $stores, true)) {
                throw new \LogicException('V3 权益来源越权');
            }
            $key = (string)($row['row_id'] ?? '');
            if ($key === '' || isset($seen[$key])) throw new \LogicException('V3 权益行身份重复或缺失');
            $seen[$key] = true;
        }
    }

    private function storeIds(array $ids): array
    {
        if (!$ids) throw new \InvalidArgumentException('门店授权范围为空');
        foreach ($ids as $id) if (!is_int($id) || $id < 1) throw new \InvalidArgumentException('门店授权范围无效');
        return array_values(array_unique($ids));
    }
}
