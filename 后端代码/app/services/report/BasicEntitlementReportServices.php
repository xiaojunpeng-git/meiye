<?php
declare(strict_types=1);

namespace app\services\report;

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\cashier\v3\member\CashierV3MemberDetailQueryServices;
use think\facade\Db;

/**
 * R51 current assets, projected from the shared cashier authority, never from
 * old report SQL. Member belonging store is the reporting grain; card source
 * stores must also be authorized. No writes, paging or historical estimates.
 */
final class BasicEntitlementReportServices
{
    private const AS_OF = 'basic_unconsumed_as_of_detail';
    /** Internal-only source for 007 unconsumed component rows; not a menu code. */
    public const UNCONSUMED_COMPONENT_SOURCE = 'internal_unconsumed_components_007';
    /** Internal-only source for the same card population in store summary. */
    public const UNCONSUMED_SUMMARY_SOURCE = 'internal_unconsumed_summary_007';

    /** Only genuinely readable reports may be registered by the Host. */
    public static function codes(): array
    {
        return ['basic_unconsumed_summary', 'basic_unconsumed_detail', self::AS_OF, 'basic_customer_entitlements'];
    }

    /** Static metadata is available before beginning a database read. */
    public static function definitions(string $code): array
    {
        if ($code === self::UNCONSUMED_COMPONENT_SOURCE) $code = 'basic_customer_entitlements';
        if ($code === self::UNCONSUMED_SUMMARY_SOURCE) $code = 'basic_unconsumed_summary';
        self::assertCode($code);
        $columns = [
            ['store_id', '所属门店ID', 'integer', false, false, false],
            ['store_name', '所属门店', 'text', true, false, false],
        ];
        if ($code !== 'basic_unconsumed_summary') {
            $columns = array_merge($columns, [
                ['member_id', '会员ID', 'integer', false, false, false],
                ['customer_name', '客户', 'text', true, false, false],
                ['phone', '手机', 'text', true, false, false],
            ]);
        }
        if (in_array($code, ['basic_unconsumed_detail', self::AS_OF, 'basic_customer_entitlements'], true)) {
            $columns = array_merge($columns, [
                ['asset_type', '权益类型', 'text', true, false, false],
                ['card_holder_id', '持卡实例ID', 'integer', false, false, false],
                ['card_store_id', '卡项来源门店ID', 'integer', false, false, false],
                ['card_no', '卡号', 'text', true, false, false],
                ['card_name', '卡名称', 'text', true, false, false],
            ]);
        }
        if ($code === 'basic_customer_entitlements') {
            $columns = array_merge($columns, [
                ['category_id', '当前分类ID', 'integer', false, false, false],
                ['category_name', '分类', 'text', true, false, false],
                ['project_detail_id', '权益项目明细ID', 'integer', false, false, false],
                ['product_id', '项目商品ID', 'integer', false, false, false],
                ['project_name', '项目', 'text', true, false, false],
                ['remaining_times', '剩余次数', 'integer', true, false, false],
                ['quantity_scope', '次数口径', 'text', true, false, false],
                ['origin_order_id', '原订单ID', 'integer', false, false, false],
                ['order_no', '订单号', 'text', true, false, false],
            ]);
        }
        $columns = array_merge($columns, [
            ['principal_amount', '本金（元）', 'decimal', true, true, true],
            ['gift_amount', '赠金（元）', 'decimal', true, true, true],
            ['balance_amount', '储值余额（元）', 'decimal', true, true, true],
            ['rights_amount', '剩余卡项权益（元）', 'decimal', true, true, true],
            ['total_assets', '权益合计（元）', 'decimal', true, true, true],
        ]);
        // Project values on choice/time cards may share one pool. Preserve the
        // authority values, but never sum alternatives into a fictitious asset.
        if ($code === 'basic_customer_entitlements') {
            $columns = array_values(array_filter($columns, static function (array $c): bool { return $c[0] !== 'total_assets'; }));
            foreach ($columns as &$column) if ($column[0] === 'rights_amount') {
                $column[1] = '剩余项目权益（元）'; $column[5] = false;
            }
            unset($column);
            foreach ($columns as &$column) {
                if ($column[0] === 'store_name') $column[1] = '门店';
                if ($column[0] === 'customer_name') $column[1] = '姓名';
                if ($column[0] === 'phone') $column[1] = '电话';
            }
            unset($column);
        }
        return array_map(static function (array $c): array {
            return ['key' => $c[0], 'label' => $c[1], 'type' => $c[2],
                'defaultVisible' => $c[3], 'defaultQuick' => in_array($c[0], ['store_name', 'customer_name', 'phone', 'card_no'], true),
                'width' => $c[4] ? 170 : 150,
                'fixed' => in_array($c[0], ['store_name', 'customer_name'], true) ? 'left' : '',
                'money' => $c[4], 'summable' => $c[5]];
        }, $columns);
    }

    /**
     * $stores is a server-authorized narrowing; _report_scope is a trusted
     * CashierV3DataScopeContext or Host context array (never browser input).
     * Current assets ignore period filters explicitly, not as-of filters.
     * The caller/Provider owns a consistent read transaction and all execution
     * filtering, sorting, summaries and pagination of this complete population.
     */
    public function read(string $code, array $stores, array $input): array
    {
        $includeExhaustedForReport = in_array($code,
            [self::UNCONSUMED_COMPONENT_SOURCE, self::UNCONSUMED_SUMMARY_SOURCE], true);
        if ($code === self::UNCONSUMED_COMPONENT_SOURCE) $code = 'basic_customer_entitlements';
        if ($code === self::UNCONSUMED_SUMMARY_SOURCE) $code = 'basic_unconsumed_summary';
        $fields = self::definitions($code);
        foreach (['start_date', 'end_date', 'as_of_date'] as $key) {
            if (isset($input[$key]) && $input[$key] !== '') self::date($input[$key]);
        }
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
        // Product confirmed today-only delivery on 2026-10-23. Retain the
        // existing code for Runtime compatibility, but reject any other day
        // even if a caller bypasses the UI. No date means an intraday live read.
        if (!empty($input['as_of_date']) && $input['as_of_date'] !== $today) {
            throw new \LogicException('ENTITLEMENT_AS_OF_UNAVAILABLE: 本报表仅支持当天实时资产，不提供历史或未来日期查询。');
        }
        $scope = $this->scope($input['_report_scope'] ?? null, $stores);
        $operator = new CashierV3OperatorScope($stores[0], $scope->operatorId(), '', $scope->tenantId());
        $reader = new CashierV3MemberDetailQueryServices();
        // Belonging-store attribution avoids repeating one balance in all
        // stores where a member has an active relation. The subquery avoids fanout.
        $populationQuery = Db::name('user')->alias('u')
                ->where('u.status', 1)->where('u.is_del', 0)->whereIn('u.belong_store_id', $stores)
                // Match the shared balance authority's current-member boundary.
                // Old timestamp/integer schemas represent "not deleted" in
                // different ways; CAST avoids implicit datetime conversion.
                // This same population drives count, rows, totals and export.
                ->whereRaw("(u.delete_time IS NULL OR CAST(u.delete_time AS CHAR) IN ('', '0', '0000-00-00 00:00:00'))")
                ->whereIn('u.uid', static function ($q) use ($stores): void {
                    $q->name('store_user')->where('status', 1)->whereIn('store_id', $stores)->field('uid');
                });
        if ((int)(clone $populationQuery)->count('u.uid') > 10000) {
            throw new \RuntimeException('ENTITLEMENT_SOURCE_LIMIT_EXCEEDED: 源会员超过10000，请缩小门店范围。');
        }
        $rows = []; $groups = []; $cursor = 0; $population = 0;
        do {
            $members = (clone $populationQuery)->where('u.uid', '>', $cursor)
                ->field('u.uid,u.phone,u.belong_store_id')->order('u.uid', 'asc')->limit(100)->select()->toArray();
            if (!$members) break;
            $population += count($members);
            // The Runtime cannot execute over a silently truncated population.
            // Until SQL aggregation is available, oversized reads fail explicitly.
            if ($population > 10000) throw new \RuntimeException('ENTITLEMENT_SOURCE_LIMIT_EXCEEDED: 源会员超过10000，请缩小门店范围。');
            $ids = array_map('intval', array_column($members, 'uid'));
            $details = $reader->readAiRightsSet($ids, $operator, $scope, null, $includeExhaustedForReport);
            // The shared member reader intentionally includes cross-store cards.
            // Verify numeric holder identity, never infer its scope from a name.
            if ($code === 'basic_customer_entitlements') {
                $holders = Db::name('user_card_holder')->whereIn('uid', $ids)->column('id,uid,oid,store_id', 'id');
                $projectSources = $this->customerProjectSources($details, $holders, $stores);
            } else {
                $holders = Db::name('user_card_holder')->whereIn('uid', $ids)->column('store_id', 'id');
            }
            foreach ($members as $member) {
                $id = (int)$member['uid']; $cursor = $id;
                if (!isset($details[$id])) throw new \RuntimeException('ENTITLEMENT_AUTHORITY_INCOMPLETE');
                $detail = $details[$id]; $store = (int)$member['belong_store_id'];
                if ((int)$detail['member']['memberId'] !== $id || (int)$detail['member']['storeId'] !== $store) {
                    throw new \RuntimeException('ENTITLEMENT_MEMBER_IDENTITY_CHANGED');
                }
                $base = ['store_id' => $store, 'store_name' => $detail['member']['storeName'],
                    'member_id' => $id, 'customer_name' => $detail['member']['name'], 'phone' => (string)$member['phone']];
                $summary = $detail['summary'];
                $principal = self::cents($summary['principalBalance']); $gift = self::cents($summary['giftBalance']);
                $balance = self::cents($summary['accountBalance']);
                if (self::add($principal, $gift) !== $balance) throw new \RuntimeException('ENTITLEMENT_BALANCE_INVARIANT');
                $rights = 0; $cardRows = [];
                foreach ($detail['cards'] as $card) {
                    if ($card['statusCode'] !== 'enabled') continue;
                    $holder = (int)$card['id'];
                    if (!array_key_exists($holder, $holders)) throw new \RuntimeException('ENTITLEMENT_HOLDER_IDENTITY_MISSING');
                    $cardStore = (int)($code === 'basic_customer_entitlements' ? $holders[$holder]['store_id'] : $holders[$holder]);
                    // Storeless migrated rights require an explicit attribution
                    // authority. Never silently classify them under the viewer.
                    if ($cardStore <= 0) throw new \RuntimeException('ENTITLEMENT_HOLDER_STORE_UNRESOLVED');
                    if (!in_array($cardStore, $stores, true)) continue;
                    if ($code === 'basic_customer_entitlements') {
                        foreach ($card['projects'] as $project) {
                            $projectId = (int)$project['id'];
                            if (!isset($projectSources[$projectId]) || (int)$holders[$holder]['uid'] !== $id
                                || (int)$projectSources[$projectId]['origin_order_id'] !== (int)$holders[$holder]['oid']) {
                                throw new \RuntimeException('ENTITLEMENT_PROJECT_IDENTITY_MISMATCH');
                            }
                            if (!is_int($project['remainingTimes']) || $project['remainingTimes'] < 0) {
                                throw new \RuntimeException('ENTITLEMENT_PROJECT_TIMES_INVALID');
                            }
                            $amount = self::cents($project['remainingAmount']);
                            $cardRows[] = $base + ['row_id' => 'entitlement:project:' . $holder . ':' . $projectId,
                                'asset_type' => '卡内项目', 'card_holder_id' => $holder, 'card_store_id' => $cardStore,
                                'card_no' => $card['cardNo'], 'card_name' => $card['cardName'],
                                'project_detail_id' => $projectId, 'project_name' => $project['projectName'],
                                'remaining_times' => $project['remainingTimes'],
                                'quantity_scope' => ($card['cardRuleType'] ?? '') === 'choice_count' ? '共享次数池（不可相加）'
                                    : (($card['cardRuleType'] ?? '') === 'time' ? '期限权益（不作为可用次数合计）' : '项目独立次数'),
                                'principal_amount' => null, 'gift_amount' => null, 'balance_amount' => null,
                                'rights_amount' => $amount / 100] + $projectSources[$projectId];
                            if (count($rows) + count($cardRows) >= 10000) {
                                throw new \RuntimeException('ENTITLEMENT_SOURCE_LIMIT_EXCEEDED: 源权益行超过10000，请缩小门店范围。');
                            }
                        }
                        continue;
                    }
                    $amount = self::cents($card['remainingAmount']); $rights = self::add($rights, $amount);
                    $cardRows[] = $base + ['row_id' => 'entitlement:card:' . $holder, 'asset_type' => '卡项',
                        'card_holder_id' => $holder, 'card_store_id' => $cardStore,
                        'card_no' => $card['cardNo'], 'card_name' => $card['cardName'],
                        'principal_amount' => null, 'gift_amount' => null, 'balance_amount' => null,
                        'rights_amount' => $amount / 100, 'total_assets' => $amount / 100];
                }
                $amounts = ['principal_amount' => $principal, 'gift_amount' => $gift,
                    'balance_amount' => $balance, 'rights_amount' => $rights, 'total_assets' => self::add($balance, $rights)];
                if ($code === 'basic_unconsumed_summary') {
                    if (!isset($groups[$store])) $groups[$store] = ['base' => ['row_id' => 'entitlement:store:' . $store,
                        'store_id' => $store, 'store_name' => $base['store_name']], 'cents' => array_fill_keys(array_keys($amounts), 0)];
                    foreach ($amounts as $key => $value) $groups[$store]['cents'][$key] = self::add($groups[$store]['cents'][$key], $value);
                } elseif ($code === 'basic_customer_entitlements') {
                    // Store principal/bonus once even when the member has many
                    // projects/cards. Project values stay untouched and nullable
                    // balance cells cannot multiply the report's wallet total.
                    $rows[] = $base + ['row_id' => 'entitlement:balance:' . $id, 'asset_type' => '储值',
                        'card_holder_id' => null, 'card_store_id' => null, 'card_no' => '', 'card_name' => '',
                        'category_id' => null, 'category_name' => '', 'project_detail_id' => null, 'product_id' => null,
                        'project_name' => '', 'remaining_times' => null, 'quantity_scope' => '',
                        'origin_order_id' => null, 'order_no' => '', 'principal_amount' => $principal / 100,
                        'gift_amount' => $gift / 100, 'balance_amount' => $balance / 100, 'rights_amount' => null];
                    foreach ($cardRows as $row) $rows[] = $row;
                } else {
                    // One distinct balance row per member prevents multiplying
                    // principal/gift across cards; null means not this row's asset.
                    $rows[] = $base + ['row_id' => 'entitlement:balance:' . $id, 'asset_type' => '储值',
                        'card_holder_id' => null, 'card_store_id' => null, 'card_no' => '', 'card_name' => '',
                        'principal_amount' => $principal / 100, 'gift_amount' => $gift / 100,
                        'balance_amount' => $balance / 100, 'rights_amount' => null, 'total_assets' => $balance / 100];
                    foreach ($cardRows as $row) $rows[] = $row;
                }
                if (count($rows) > 10000) throw new \RuntimeException('ENTITLEMENT_SOURCE_LIMIT_EXCEEDED: 源权益行超过10000，请缩小门店范围。');
            }
        } while (count($members) === 100);
        ksort($groups, SORT_NUMERIC);
        foreach ($groups as $group) $rows[] = $group['base'] + self::yuan($group['cents']);
        $sources = self::sources($fields);
        if ($code === 'basic_customer_entitlements') {
            $sources['rights_amount'] = '每条卡内项目由统一会员权益服务返回的当前剩余金额，单位元保留分；共享或期限权益不保证可相加，因此本列不提供合计；储值行不适用。';
            $sources['asset_type'] = '独立储值行保存本会员本金与赠金，每条卡内项目另占一行；本金、赠金不重复分配到项目行。';
        }
        return ['rows' => $rows, 'fields' => $fields, 'field_sources' => $sources,
            'sum_fields' => array_column(array_filter($fields, static function (array $f): bool { return $f['summable']; }), 'key'),
            'time_mode' => 'current', 'as_of_date' => $today, 'historical_as_of_supported' => false,
            'as_of_semantics' => '按产品已确认范围读取查询当天的日内实时资产，不代表当天闭店余额，不提供历史日期查询。',
            'period_filter_applied' => false, 'data_as_of' => time(),
            'coverage_start' => null, 'complete' => true, 'drilldowns' => [],
            'limitations' => [
                '本次已确认仅交付查询当天的实时资产，不提供历史日期查询，也不代表当天闭店余额。',
                '归集到会员当前所属门店；卡项来源门店也须在本次授权范围内。',
                '当前报表不包含优惠券、独立赠品和预售库存；不生成没有权威依据的购买额或已使用额。',
                '储值和卡项在明细中分行展示，空金额表示本行不适用；储值不随卡项重复计算。',
            ],
            'metric_version' => $includeExhaustedForReport ? 'basic-unconsumed-project-all-status-read-v1'
                : ($code === 'basic_customer_entitlements' ? 'basic-customer-project-entitlement-current-v1' : 'basic-entitlement-current-v1'),
            'coverage' => '当前有效会员储值与当前有效卡项；会员所属店与卡项来源店均限本次授权门店。非优惠券、赠品及预售库存报表。'];
    }

    /**
     * Authority project IDs identify legacy cart details, not catalogue IDs.
     * Verify each detail's order first, then reuse the shared current-category
     * resolver on its persisted product identity (including cross-store items).
     * No card-shell category or product-name matching is permitted.
     */
    private function customerProjectSources(array $details, array $holders, array $stores): array
    {
        $ids = []; $orderIds = [];
        foreach ($details as $detail) foreach ($detail['cards'] as $card) {
            $holder = $holders[(int)$card['id']] ?? null;
            if ($card['statusCode'] !== 'enabled' || !$holder || !in_array((int)$holder['store_id'], $stores, true)) continue;
            $orderIds[(int)$holder['oid']] = true;
            foreach ($card['projects'] as $project) $ids[(int)$project['id']] = true;
        }
        $orders = []; $items = [];
        foreach (array_chunk(array_keys($orderIds), 300) as $chunk) {
            foreach (Db::name('store_order')->whereIn('id', $chunk)->field('id,order_id')->select()->toArray() as $order) {
                $orders[(int)$order['id']] = (string)$order['order_id'];
            }
        }
        foreach (array_chunk(array_keys($ids), 300) as $chunk) {
            foreach (Db::name('store_order_cart_info')->whereIn('id', $chunk)->where('cart_type', 2)->where('product_type', 6)
                ->field('id,oid,product_id')->select()->toArray() as $item) $items[(int)$item['id']] = $item;
        }
        $links = [];
        foreach ($items as $id => $item) $links[$id] = ['product_id' => (int)$item['product_id']];
        $categories = (new StoreReportCurrentProductCategoryServices())->resolveVerifiedFactItems($links);
        $result = [];
        foreach ($items as $id => $item) {
            $orderId = (int)$item['oid'];
            if (!isset($orders[$orderId]) || $orders[$orderId] === '') throw new \RuntimeException('ENTITLEMENT_PROJECT_ORDER_MISSING');
            $result[$id] = ['origin_order_id' => $orderId, 'order_no' => $orders[$orderId],
                'product_id' => (int)$item['product_id'], 'category_id' => $categories[$id]['id'] ?? null,
                'category_name' => $categories[$id]['path'] ?? '未分类'];
        }
        return $result;
    }

    /** Validate Host scope; narrowing cannot become ALL or self-participant. */
    private function scope($trusted, array &$stores): CashierV3DataScopeContext
    {
        $stores = self::ids($stores);
        if (is_array($trusted)) {
            $mode = $trusted['basic_scope_mode'] ?? $trusted['authorization_mode'] ?? $trusted['mode'] ?? '';
            if (!in_array($mode, ['stores', 'all'], true) || (int)($trusted['operator_id'] ?? 0) < 1
                || (string)($trusted['tenant_id'] ?? '') !== CashierV3ScopeResolver::TENANT_SCOPE_ID) {
                throw new \InvalidArgumentException('ENTITLEMENT_TRUSTED_SCOPE_REQUIRED');
            }
            // Host already resolves $stores server-side. A supplied independent
            // visible set additionally gates it; browser scope must never reach here.
            $allowed = self::ids($trusted['visible_store_ids'] ?? $stores);
            if (array_diff($stores, $allowed)) throw new \InvalidArgumentException('ENTITLEMENT_STORE_DENIED');
            $trusted = new CashierV3DataScopeContext((int)$trusted['operator_id'], 0, $stores[0],
                (string)$trusted['tenant_id'], '', $stores, 'stores', [], false, '',
                (string)($trusted['permission_version'] ?? ''), [], [], true);
        }
        if (!$trusted instanceof CashierV3DataScopeContext || $trusted->operatorId() < 1
            || $trusted->tenantId() !== CashierV3ScopeResolver::TENANT_SCOPE_ID
            || !in_array($trusted->authorizationMode(), ['stores', 'all'], true)) {
            throw new \InvalidArgumentException('ENTITLEMENT_TRUSTED_SCOPE_REQUIRED');
        }
        foreach ($stores as $store) if (!$trusted->allowsStore($store)) throw new \InvalidArgumentException('ENTITLEMENT_STORE_DENIED');
        // Reader requires a real operating-store anchor even for platform scope;
        // it is chosen only from the already authorized narrowing, never input IDs.
        return new CashierV3DataScopeContext($trusted->operatorId(), 0, $stores[0], $trusted->tenantId(), '',
            $stores, 'stores', [], false, '', $trusted->permissionVersion(), [], [], true);
    }

    /** Strict identities prevent numeric coercion from broadening permissions. */
    private static function ids(array $ids): array
    {
        foreach ($ids as $id) if ((!is_int($id) && (!is_string($id) || !ctype_digit($id))) || (int)$id < 1
            || (string)(int)$id !== (string)$id) throw new \InvalidArgumentException('ENTITLEMENT_STORE_INVALID');
        if (!$ids) throw new \InvalidArgumentException('ENTITLEMENT_SCOPE_EMPTY');
        $ids = array_values(array_unique(array_map('intval', $ids))); sort($ids, SORT_NUMERIC); return $ids;
    }

    /** Decimal authority values become exact cents before any arithmetic. */
    private static function cents($amount): int
    {
        if (!is_string($amount) || !preg_match('/^\d+(?:\.\d{1,2})?$/D', $amount)) throw new \RuntimeException('ENTITLEMENT_AMOUNT_INVALID');
        $cents = bcmul($amount, '100', 0);
        if (bccomp($cents, (string)PHP_INT_MAX, 0) > 0) throw new \OverflowException('ENTITLEMENT_AMOUNT_OVERFLOW');
        return (int)$cents;
    }

    /** Fail rather than let integer overflow turn exact money into floats. */
    private static function add(int $a, int $b): int
    {
        if ($a > PHP_INT_MAX - $b) throw new \OverflowException('ENTITLEMENT_AMOUNT_OVERFLOW');
        return $a + $b;
    }

    private static function yuan(array $amounts): array
    {
        return array_map(static function (int $cents) { return $cents / 100; }, $amounts);
    }

    /** Impossible calendar days and relative-date syntax cannot change time semantics. */
    private static function date($value): void
    {
        if (!is_string($value)) throw new \InvalidArgumentException('ENTITLEMENT_DATE_INVALID');
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('Asia/Shanghai'));
        if (!$date || $date->format('Y-m-d') !== $value) throw new \InvalidArgumentException('ENTITLEMENT_DATE_INVALID');
    }

    private static function assertCode(string $code): void
    {
        if (!in_array($code, array_merge(self::codes(), [self::AS_OF]), true)) throw new \InvalidArgumentException('ENTITLEMENT_REPORT_UNKNOWN');
    }

    /** Every published column has a business explanation, including nullable cells. */
    private static function sources(array $fields): array
    {
        $sources = [
            'store_id' => '会员当前所属门店的稳定标识，仅限本次有权查询的门店。',
            'store_name' => '会员当前所属门店名称，不按购卡店重复归集储值余额。',
            'member_id' => '当前有效会员的稳定标识。', 'customer_name' => '会员当前姓名，未填写时沿用会员档案名称。',
            'phone' => '当前有效会员档案中的手机号码。', 'asset_type' => '储值与卡项分别占一行，储值不重复分配到每张卡。',
            'card_holder_id' => '当前持卡实例的稳定标识，转让后随当前持卡人展示。',
            'card_store_id' => '卡项来源门店的稳定标识，仅保留本次授权门店内的卡项。',
            'card_no' => '当前持卡实例的卡号；储值行不适用。', 'card_name' => '当前持卡实例保存的卡名称；储值行不适用。',
            'principal_amount' => '会员当前可用储值本金，单位元保留分；明细只在储值行计一次。',
            'gift_amount' => '会员当前可用储值赠金，单位元保留分；明细只在储值行计一次。',
            'balance_amount' => '当前储值本金加赠金，与会员余额权威结果一致；卡项行不适用。',
            'rights_amount' => '当前有效卡项按统一权益规则计算的剩余金额，已考虑核销、退款、作废、有效期与欠款限制；仅计授权来源门店卡项，储值行不适用。',
            'total_assets' => '本行储值余额与剩余卡项权益之和，单位元保留分；不包含优惠券、赠品及预售库存。',
        ];
        $sources += [
            'category_id' => '由本条项目的商品身份关联平台商品当前分类的稳定标识；关联不明时为空。',
            'category_name' => '由本条项目对应的平台商品读取当前分类路径，不使用卡项外壳分类；无法核实时显示未分类并保留原权益。',
            'project_detail_id' => '当前持卡实例内的权益项目明细标识，不是商品编号。',
            'product_id' => '经核对原订单后的卡内项目明细保存的项目商品标识，不按名称猜测。',
            'project_name' => '统一会员权益服务返回的卡内项目名称，不以当前商品改名替换。',
            'remaining_times' => '统一会员权益服务返回的该项目当前剩余次数；任选项目可能共享同一次数池，不能逐行相加。',
            'quantity_scope' => '说明本项目使用独立次数、共享次数池或期限权益；共享与期限项目不提供次数合计。',
            'origin_order_id' => '持卡实例和项目明细共同核实的原订单稳定标识。',
            'order_no' => '该卡内项目所属原订单实际保存的订单号，不将数字订单ID冒充订单号。',
        ];
        $result = [];
        foreach ($fields as $field) $result[$field['key']] = $sources[$field['key']];
        return $result;
    }
}
