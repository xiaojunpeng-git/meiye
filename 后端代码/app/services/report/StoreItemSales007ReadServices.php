<?php

declare(strict_types=1);

namespace app\services\report;

use think\facade\Db;

/**
 * 007 门店品项销售分析的 V3 只读候选：原成交行给销售数量，原结账收款分摊给销售业绩。
 * 调用方必须注入已认证的门店全集；本类只收窄权限，绝不从请求参数取得可扩大范围的门店。
 */
final class StoreItemSales007ReadServices
{
    private $saleReader;
    private $cashReader;
    private $categoryResolver;
    private $coverageReader;

    /** 可注入只读事实源以验证归组和守恒；生产默认使用统一 V3 原结账事实。 */
    public function __construct(?callable $saleReader = null, ?callable $cashReader = null,
        ?callable $categoryResolver = null, ?callable $coverageReader = null)
    {
        $this->saleReader = $saleReader ?: [$this, 'saleFacts'];
        $this->cashReader = $cashReader ?: [$this, 'originalCash'];
        $this->categoryResolver = $categoryResolver ?: static function (array $links): array {
            return (new StoreReportCurrentProductCategoryServices())->resolve($links);
        };
        // Local 007 snapshot type-5 SQL and the manually opened page both have
        // five columns. Eligibility is audited per old cart detail and V3 line.
        $this->coverageReader = $coverageReader ?: [new StoreItemSales007SourceCoverageServices(), 'read'];
    }

    /**
     * 完整读取授权门店的原销售行；员工个人范围必须由未来专用事实查询实现，不能退化为全店结果。
     * 现金业绩仅取原销售订单结账当时的有效正向收款分摊，金额保留分精度后才转换为元。
     */
    public function read(array $stores, array $range, array $employeeIds = []): array
    {
        $stores = $this->stores($stores);
        $range = $this->range($range);
        if ($employeeIds !== []) throw new \InvalidArgumentException('门店品项销售分析暂不支持员工个人权限范围');
        $fields = $this->fields();
        if ($stores === []) return $this->result([], $fields, $stores, $range, []);
        return Db::transaction(function () use ($stores, $range, $fields): array {
            $sales = ($this->saleReader)($stores, $range);
            $this->checkSize($sales);
            $coverage=($this->coverageReader)($stores,$range,$sales);
            if(!($coverage['complete']??false))return $this->failClosed($fields,$stores,$range,$coverage);
            // Old SQL excludes some paid carts (old-card payment, invalid
            // product type, etc.). V3 facts for those carts must not leak into
            // this legacy-compatible result merely because they were imported.
            $eligible=array_fill_keys($coverage['eligible_legacy_fact_ids']??[],true);
            $sales=array_values(array_filter($sales,static function(array $sale)use($eligible):bool{
                return (string)($sale['source_document_type']??'')!=='legacy_store_order'
                    ||isset($eligible[(string)($sale['fact_id']??'')]);
            }));
            $cash = ($this->cashReader)($stores, $range, $sales);
            $this->checkSize($cash['payments'] ?? []);
            $this->checkSize($cash['allocations'] ?? []);
            $links = [];
            foreach ($sales as $index => $sale) {
                $links[$index] = ['store_id'=>(int)($sale['store_id'] ?? 0),
                    'product_id'=>(int)($sale['item_id'] ?? 0)];
            }
            $categories = ($this->categoryResolver)($links);
            $result=$this->result($sales, $fields, $stores, $range, $cash, $categories);
            $result['source_coverage']=$coverage;
            return $result;
        });
    }

    /** 未认证旧销售人口时，列表/合计/导出都不能显示 V3 子集。 */
    private function failClosed(array $fields,array $stores,array $range,array $coverage):array
    {
        $result=$this->result([],$fields,$stores,$range,[]);
        $result['rows']=[];$result['sum_fields']=[];
        $result['gaps']=$coverage['gaps']??[['code'=>'STORE_ITEM_007_SOURCE_COVERAGE_UNVERIFIED',
            'message'=>'007 门店品项旧销售人口覆盖未认证。']];
        $result['complete']=false;$result['source_coverage']=$coverage;
        return $result;
    }

    /** 只读取正向有效成交事实，排除冲销和成功作废；不靠旧订单 SQL 拼经营结果。 */
    private function saleFacts(array $stores, array $range): array
    {
        $query = Db::name('cashier_v3_sale_fact')->alias('s')
            ->leftJoin('system_store st', 'st.id=s.store_id')
            ->where('s.tenant_id', '0')->whereIn('s.store_id', $stores)
            ->whereBetween('s.business_date', [$range['start'], $range['end']])
            ->where('s.status', 'effective')->where('s.fact_direction', 'forward')
            ->whereIn('s.source_type', ['project', 'product', 'card'])
            ->whereNotExists(function ($reversal): void {
                $reversal->name('cashier_v3_sale_fact')->alias('sale_reversal')
                    ->whereRaw('sale_reversal.tenant_id=s.tenant_id')
                    ->whereRaw('sale_reversal.reversal_of=s.fact_id')
                    ->where('sale_reversal.fact_direction', 'reversal')
                    ->where('sale_reversal.status', 'effective');
            });
        (new StoreReportNormalDataScopeServices())->excludeVoidedSalesOrderFacts($query, 's.tenant_id', 's.order_id');
        return $query->fieldRaw('s.fact_id,s.store_id,s.order_id,s.source_document_type,s.source_line_id,'
            . 's.sale_amount_cents,s.debt_amount_cents,COALESCE(st.name,s.store_name_snapshot) store_name,'
            . 's.item_id,s.item_name_snapshot item_name,s.quantity,s.source_type')
            ->limit(10001)->select()->toArray();
    }

    /**
     * 007 的现金条件属于原订单，不属于后续欠款补交日。先读原单有效收款，再读每笔原收款
     * 到原销售行的冻结分摊；0 分分摊也保留，以区别真实零分与未回填分摊事实。
     */
    private function originalCash(array $stores, array $range, array $sales): array
    {
        $orders = array_values(array_unique(array_filter(array_map('strval', array_column($sales, 'order_id')))));
        $saleIds = array_values(array_unique(array_filter(array_map('strval', array_column($sales, 'fact_id')))));
        if ($orders === [] || $saleIds === []) return ['payments'=>[], 'allocations'=>[], 'balances'=>[]];
        $payments = [];
        foreach (array_chunk($orders, 500) as $chunk) {
            $batch = Db::name('cashier_v3_payment_fact')->alias('p')
                ->where('p.tenant_id', '0')->whereIn('p.store_id', $stores)->whereIn('p.order_id', $chunk)
                // 原 V3 单与已迁 007 原单各用自身来源；debt_repayment
                // 是后补，绝不按原单金额混入。
                ->whereIn('p.source_document_type', ['cashier_snapshot', 'sales_order', 'legacy_store_order'])
                ->where('p.fact_type', 'payment_collected')
                ->where('p.fact_direction', 'forward')->where('p.status', 'effective')
                ->where('p.amount_cents', '>', 0)
                ->whereNotIn('p.payment_method', ['other_collection', 'old_card_entry'])
                ->field('p.fact_id,p.store_id,p.order_id,p.source_document_type,p.amount_cents')
                ->limit(10001)->select()->toArray();
            $this->checkSize($batch);
            $payments = array_merge($payments, $batch);
            $this->checkSize($payments);
        }
        $paymentIds = array_column($payments, 'fact_id');
        $allocations = [];
        foreach (array_chunk($paymentIds, 500) as $paymentChunk) {
            foreach (array_chunk($saleIds, 500) as $saleChunk) {
                $batch = Db::name('cashier_v3_payment_sale_allocation_fact')->alias('a')
                    ->where('a.tenant_id', '0')->whereIn('a.store_id', $stores)
                    ->whereIn('a.payment_fact_id', $paymentChunk)->whereIn('a.sale_fact_id', $saleChunk)
                    ->where('a.fact_direction', 'forward')->where('a.status', 'effective')
                    ->field('a.allocation_fact_id,a.payment_fact_id,a.sale_fact_id,a.store_id,a.amount_cents')
                    ->limit(10001)->select()->toArray();
                $this->checkSize($batch);
                $allocations = array_merge($allocations, $batch);
                $this->checkSize($allocations);
            }
        }
        // 无现金收款时，以原销售行的余额分摊与欠款事实证明“确实无现金”；
        // 若金额仍有缺口，只标来源缺失，不把缺失收款伪装成全余额订单。
        $lineIds = array_values(array_unique(array_filter(array_map('strval', array_column($sales, 'source_line_id')))));
        $balanceByLine = [];
        foreach (array_chunk($lineIds, 500) as $lineChunk) {
            $lines = Db::name('cashier_v3_sales_order_line')->alias('l')
                ->leftJoin('cashier_v3_order_balance_allocation b',
                    'b.tenant_id=l.tenant_id AND b.store_id=l.store_id AND b.order_line_id=l.order_line_id')
                ->where('l.tenant_id', '0')->whereIn('l.store_id', $stores)
                ->whereIn('l.checkout_line_id', $lineChunk)->where('l.line_direction', 'forward')
                ->field('l.store_id,l.order_id,l.checkout_line_id,b.amount_cents')->limit(10001)->select()->toArray();
            $this->checkSize($lines);
            foreach ($lines as $line) {
                $key = json_encode([(int)$line['store_id'], (string)$line['order_id'],
                    (string)$line['checkout_line_id']]);
                $balanceByLine[$key] = ($balanceByLine[$key] ?? 0) + (int)($line['amount_cents'] ?? 0);
            }
        }
        $balances = [];
        foreach ($sales as $sale) {
            $key = json_encode([(int)$sale['store_id'], (string)$sale['order_id'],
                (string)($sale['source_line_id'] ?? '')]);
            if (isset($balanceByLine[$key])) $balances[(string)$sale['fact_id']] = $balanceByLine[$key];
        }
        return ['payments'=>$payments, 'allocations'=>$allocations, 'balances'=>$balances];
    }

    /** 原结账收款按事实 ID 归回销售行；卡项仍以原销售卡项计一次销售数量。 */
    private function result(array $sales, array $fields, array $stores, array $range, array $cash,
        array $categories = []): array
    {
        $authorized = array_fill_keys($stores, true);
        $byFact = [];
        $gaps = [];
        foreach ($sales as $index => $sale) {
            $factId = trim((string)($sale['fact_id'] ?? ''));
            $storeId = (int)($sale['store_id'] ?? 0);
            if ($factId === '' || !isset($authorized[$storeId]) || isset($byFact[$factId])) {
                throw new \LogicException('门店品项销售事实身份或权限异常');
            }
            $byFact[$factId] = ['sale'=>$sale, 'category'=>$categories[$index] ?? []];
        }
        $payments = []; $byOrder = [];
        foreach ($cash['payments'] ?? [] as $payment) {
            $paymentId = trim((string)($payment['fact_id'] ?? ''));
            $storeId = (int)($payment['store_id'] ?? 0);
            $orderId = trim((string)($payment['order_id'] ?? ''));
            if ($paymentId === '' || !isset($authorized[$storeId]) || $orderId === ''
                || (int)($payment['amount_cents'] ?? 0) <= 0 || isset($payments[$paymentId])) {
                throw new \LogicException('原结账有效收款身份或权限异常');
            }
            $payments[$paymentId] = $payment;
            $byOrder[$storeId . ':' . $orderId][$paymentId] = true;
        }
        $cashByFact = []; $allocated = [];
        foreach ($cash['allocations'] ?? [] as $receipt) {
            $factId = (string)($receipt['sale_fact_id'] ?? '');
            $paymentId = (string)($receipt['payment_fact_id'] ?? '');
            if (!isset($byFact[$factId]) || !isset($payments[$paymentId])) {
                throw new \LogicException('原结账收款分摊缺少有效原事实');
            }
            $sale = $byFact[$factId]['sale'];
            if ((int)($receipt['store_id'] ?? 0) !== (int)$sale['store_id']
                || (int)$payments[$paymentId]['store_id'] !== (int)$sale['store_id']
                || (string)$payments[$paymentId]['order_id'] !== (string)$sale['order_id']
                || ((string)($sale['source_document_type'] ?? '') !== ''
                    && (string)($payments[$paymentId]['source_document_type'] ?? '') !== (string)$sale['source_document_type'])) {
                throw new \LogicException('门店品项销售收款归属异常');
            }
            if (isset($allocated[$paymentId][$factId])) throw new \LogicException('原结账收款分摊重复');
            $amount = (int)($receipt['amount_cents'] ?? 0);
            if ($amount < 0) throw new \LogicException('原结账正向收款中出现负数');
            $allocated[$paymentId][$factId] = true;
            $cashByFact[$factId] = ($cashByFact[$factId] ?? 0) + $amount;
        }
        $grouped = [];
        foreach ($byFact as $factId => $entry) {
            $sale = $entry['sale']; $category = $entry['category'];
            $storeId = (int)$sale['store_id']; $itemId = (int)($sale['item_id'] ?? 0);
            $orderPayments = $byOrder[$storeId . ':' . (string)($sale['order_id'] ?? '')] ?? [];
            // 007 资格先由原购物车覆盖审计限定。合格的非旧卡原单即使
            // 现金为 0 也保留销量；欠款/余额不能解释差额时标来源缺口。
            $missingOriginalPayment = false;
            if ($orderPayments === []) {
                $saleAmount = (int)($sale['sale_amount_cents'] ?? 0);
                $debtAmount = (int)($sale['debt_amount_cents'] ?? 0);
                $balance = $cash['balances'][$factId] ?? null;
                if ($saleAmount > 0 && $saleAmount > $debtAmount + (int)($balance ?? 0)) {
                    $missingOriginalPayment = true;
                    $gaps[] = ['fact_id'=>$factId, 'code'=>'ORIGINAL_PAYMENT_EVIDENCE_MISSING',
                        'message'=>'原销售金额未被欠款和已证实的余额分摊覆盖，且缺少有效原结账收款；不能判为真实零现金。'];
                }
            }
            $missingAllocation = false;
            foreach ($orderPayments as $paymentId => $_) if (!isset($allocated[$paymentId][$factId])) {
                $missingAllocation = true;
                $gaps[] = ['fact_id'=>$factId, 'code'=>'ORIGINAL_PAYMENT_ALLOCATION_MISSING',
                    'message'=>'原订单有有效结账收款，但该销售行缺少对应分摊事实；销售业绩不可显示为0。'];
            }
            $itemName = trim((string)($sale['item_name'] ?? ''));
            $categoryId = (int)($category['id'] ?? 0);
            $categoryPath = (string)($category['path'] ?? '');
            if ($itemId <= 0 || $itemName === '' || $categoryId <= 0) {
                $gaps[] = ['fact_id'=>$factId, 'code'=>'STORE_ITEM_IDENTITY_UNRESOLVED',
                    'message'=>'原成交品项或当前分类无法可靠定位；保留原销售事实，不猜名称或分类。'];
            }
            // 身份不明时单事实独立成行，避免多个无身份品项被错误合并。
            $identity = $itemId > 0 && $itemName !== '' && $categoryId > 0
                ? [$storeId, $categoryId, $itemId, $itemName]
                : [$storeId, 'unresolved', $factId];
            $key = hash('sha256', json_encode($identity, JSON_UNESCAPED_UNICODE));
            if (!isset($grouped[$key])) {
                $grouped[$key] = ['row_id'=>'store_item_007:'.$key, 'store_id'=>$storeId,
                    'store_name'=>(string)($sale['store_name'] ?? ''), 'category_id'=>$categoryId,
                    'category_path'=>$categoryPath, 'item_id'=>$itemId, 'item_name'=>$itemName,
                    'sales_quantity'=>0.0, 'sales_performance_cents'=>0, 'sales_performance_missing'=>false];
            }
            $quantity = $sale['quantity'] ?? null;
            if (!is_numeric($quantity) || (float)$quantity < 0) throw new \LogicException('原成交数量无效');
            $grouped[$key]['sales_quantity'] += (float)$quantity;
            $grouped[$key]['sales_performance_cents'] += $cashByFact[$factId] ?? 0;
            $grouped[$key]['sales_performance_missing'] = $grouped[$key]['sales_performance_missing']
                || $missingAllocation || $missingOriginalPayment;
        }
        $this->checkSize($grouped);
        $rows = [];
        foreach ($grouped as $row) {
            $row['sales_quantity'] = round($row['sales_quantity'], 2, PHP_ROUND_HALF_UP);
            $row['sales_performance'] = $row['sales_performance_missing']
                ? null : $row['sales_performance_cents'] / 100;
            unset($row['sales_performance_cents'], $row['sales_performance_missing']);
            $rows[] = $row;
        }
        usort($rows, static fn(array $a, array $b): int => strcmp($a['row_id'], $b['row_id']));
        return ['rows'=>$rows, 'fields'=>$fields,
            'field_sources'=>[
                'store_name'=>'当前门店名称；门店授权按不可变门店ID控制。',
                'category_path'=>'原成交品项关联的平台商品当前分类；关联缺失时留空并提示来源不完整。',
                'item_name'=>'原销售事实保存的品项名称快照。',
                'sales_quantity'=>'有效正向原销售事实的成交数量；卡项按原卡项成交行计数，不以组件数冒充销量。',
                'sales_performance'=>'原销售订单成功结账时有效正向收款按原销售事实ID冻结的分摊；欠款后补不计入。',
                'store_id'=>'授权门店的不可变ID，仅作精确筛选和稳定行键。',
                'category_id'=>'当前平台商品分类ID；关联缺失时为0并提示来源不完整。',
                'item_id'=>'原销售事实保存的品项ID；关联缺失时为0并提示来源不完整。',
            ],
            'complete'=>$gaps === [], 'gaps'=>$gaps,
            'sum_fields'=>array_values(array_filter(['sales_quantity', $this->hasCashGap($gaps) ? null : 'sales_performance'])),
            'metric_version'=>'store-item-007-v3-candidate-1',
            'coverage_start'=>StoreUnifiedReportServices::COVERAGE_START,
            'data_as_of'=>date('c'), 'scope'=>['store_ids'=>$stores, 'date_range'=>$range],
            'fact_completeness'=>'仅V3有效正式事实；不以旧订单或旧SQL补齐未回填历史。',
            'limitations'=>[
                '007 旧SQL按门店名、分类名、展示名归组；本表按门店ID、当前分类ID、原品项ID和名称快照生成稳定行键，不合并同名异品项。',
                '007 旧SQL以原订单缓存现金金额及组合卡规则计算销售业绩；本表只取原销售订单结账收款的正式逐行分摊，旧单未回填及部分退款口径仍须逐单核对。',
                '卡项销售数量只计原卡成交行；卡内组件金额不能反推出组件销售数量。',
            ]];
    }

    /** 报表默认仅显示 007 五列；内部身份键保留供精确筛选、导出及下钻。 */
    /** 不查库的五列契约，列表、合计和导出共用。 */
    public static function definitions(): array { return self::fields(); }

    public static function fields(): array
    {
        $spec = [
            ['store_name','门店','text',false,false], ['category_path','分类','text',false,false],
            ['item_name','名称','text',false,false], ['sales_quantity','销售数量','decimal',false,true],
            ['sales_performance','销售业绩','decimal',true,true],
        ];
        $fields = [];
        foreach ($spec as [$key,$label,$type,$money,$summable]) {
            $fields[] = ['key'=>$key,'label'=>$label,'type'=>$type,'defaultVisible'=>true,
                'defaultQuick'=>$key==='store_name' || $key==='item_name', 'width'=>150,
                'fixed'=>$key==='store_name'?'left':'', 'money'=>$money, 'summable'=>$summable];
        }
        foreach (['store_id','category_id','item_id'] as $key) {
            $fields[] = ['key'=>$key,'label'=>$key,'type'=>'integer','defaultVisible'=>false,
                'defaultQuick'=>false,'width'=>100,'fixed'=>'','money'=>false,'summable'=>false];
        }
        return $fields;
    }

    private function stores(array $stores): array
    {
        $ids = [];
        foreach ($stores as $store) {
            if ((!is_int($store) && !(is_string($store) && ctype_digit($store))) || (int)$store <= 0) {
                throw new \InvalidArgumentException('授权门店无效');
            }
            $ids[(int)$store] = (int)$store;
        }
        return array_values($ids);
    }

    private function range(array $range): array
    {
        foreach (['start','end'] as $key) {
            $value = $range[$key] ?? null;
            $date = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
            if (!$date || $date->format('Y-m-d') !== $value) throw new \InvalidArgumentException('日期须为有效YYYY-MM-DD');
        }
        if ($range['start'] > $range['end']) throw new \InvalidArgumentException('日期范围无效');
        if ($range['start'] < StoreUnifiedReportServices::COVERAGE_START) throw new \InvalidArgumentException('日期早于V3事实覆盖期');
        return ['start'=>$range['start'], 'end'=>$range['end']];
    }

    /** 只要一个分组的现金缺来源，就不能发布看似完整的金额合计。 */
    private function hasCashGap(array $gaps): bool
    {
        foreach ($gaps as $gap) if (in_array(($gap['code'] ?? ''),
            ['ORIGINAL_PAYMENT_ALLOCATION_MISSING','ORIGINAL_PAYMENT_EVIDENCE_MISSING'], true)) return true;
        return false;
    }

    /** 超过源预算即拒绝整张报表，不能用被截断的数据计算合计。 */
    private function checkSize(array $rows): void
    {
        if (count($rows) > 10000) throw new \InvalidArgumentException('基础报表源数据超过10000行，请缩小范围');
    }
}
