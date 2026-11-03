<?php
declare(strict_types=1);
namespace app\services\report;

use app\services\query\UnifiedQueryExecutionServices;
use app\services\query\UnifiedQueryPageRegistry;
use app\services\query\StructuredExpressionValidator;
use app\services\query\StructuredExpressionEvaluator;
use app\services\report\query\BasicBusinessQueryProvider;
use app\services\report\query\BasicRegistrationQueryProvider;
use think\facade\Db;

/** 门店对账统一投影：后端拥有动态列、精确下钻与完整导出，前端不计算经营金额。 */
final class StoreReconciliationReportServices
{
    public const CODE = 'basic_store_reconciliation';
    private $source;

    /** 可注入冻结的收款证据做离线对账；正式路径始终在当前数据库的一致读取内。 */
    public function __construct(?callable $source = null) { $this->source = $source; }

    /** 目录静态声明不访问数据库；动态门店/日期列只在已认证查询后登记。 */
    public function definitions(string $code): array
    {
        if ($code !== self::CODE) throw new \InvalidArgumentException('未配置该门店对账单');
        return [self::field('payment_name', '支付方式', 'text', false, true), self::field('total_amount', '合计', 'decimal', true, true)];
    }

    /** 已认证范围是唯一授权输入；严格解析两种日期模式后，在同一快照内完成查询与导出。 */
    public function query(array $context, array $payload): array
    {
        $read = function () use ($context, $payload): array {
            $allowed = BasicRegistrationQueryProvider::stores($context);
            if (!in_array($context['basic_scope_mode'] ?? '', ['all', 'stores'], true)) throw new \InvalidArgumentException('当前权限不支持门店对账');
            $range = BasicBusinessQueryProvider::dates($payload, (string)$context['query_cutoff_date']);
            $mode = (string)($payload['reconciliation_mode'] ?? 'stores');
            if (!in_array($mode, ['stores', 'days'], true)) throw new \InvalidArgumentException('对账展示方式无效');
            $selected = $payload['reconciliation_store_id'] ?? 0;
            if (!is_scalar($selected) || !preg_match('/^\d+$/D', (string)$selected)) throw new \InvalidArgumentException('请选择有效门店');
            $selected = (int)$selected;
            if ($mode === 'days') {
                if (!in_array($selected, $allowed, true)) throw new \InvalidArgumentException('该门店不在可查询范围内');
                $month = (string)($payload['month'] ?? substr($range['start_date'], 0, 7));
                if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $month)) throw new \InvalidArgumentException('请选择有效月份');
                $first = new \DateTimeImmutable($month . '-01', new \DateTimeZone('Asia/Shanghai'));
                $range = ['start_date' => $month . '-01', 'end_date' => $first->format('Y-m-t'), 'as_of_date' => $first->format('Y-m-t')];
                if ($range['start_date'] > $context['query_cutoff_date']) throw new \InvalidArgumentException('不能查询未来月份');
                $ids = [$selected];
            } else {
                if ($selected !== 0) throw new \InvalidArgumentException('多门店模式不能携带单店参数');
                $ids = $allowed;
            }
            // 当前月份仍显示完整日历，但未来日不允许读入任何预填或异常事实。
            $sourceRange = $range;
            $sourceRange['end_date'] = min($range['end_date'], (string)$context['query_cutoff_date']);
            if ($this->source) $source = ($this->source)($ids, $sourceRange, $context);
            else {
                $reader = new StoreReconciliationReceiptReader();
                $storeRows = $reader->stores($ids); $methods = $reader->checkoutMethods();
                $receipts = $reader->receipts(array_map('intval', array_column($storeRows, 'id')), $methods, $sourceRange, (string)$context['tenant_id']);
                $methodScope = array_fill_keys(array_column($methods, 'code'), true);
                // 当前收银方式和两项刷单登记各占独立行；停用方式不混入其他渠道。
                // 历史Reader仍返回原事实，其他现金/每日汇总范围与金额保持不变。
                $source = ['stores' => $storeRows, 'methods' => $methods,
                    'receipts' => array_values(array_filter($receipts, static function (array $receipt) use ($methodScope): bool {
                        return isset($methodScope[$receipt['method_code']]);
                    }))];
            }
            if ($mode === 'days' && !in_array($selected, array_map('intval', array_column($source['stores'], 'id')), true)) {
                throw new \InvalidArgumentException('该门店未启用对账统计');
            }
            return $this->project($context, $payload, $source, $range, $mode, $selected);
        };
        return $this->source ? $read() : Db::transaction($read);
    }

    /** 每个单元格从同一事实集合选择，组合收款的正合计条件也保持007的单元格边界。 */
    private function project(array $context, array $payload, array $source, array $range, string $mode, int $selected): array
    {
        $stores = $source['stores']; $methods = $stores ? $source['methods'] : []; $receipts = $source['receipts'];
        if (count($stores) > 250 || count($methods) > 200) throw new \InvalidArgumentException('对账目录过大，请缩小门店范围');
        $ids = array_map('intval', array_column($stores, 'id'));
        if (array_diff($ids, BasicRegistrationQueryProvider::stores($context))) throw new \InvalidArgumentException('对账门店超出当前权限');
        $names = array_column($stores, 'name', 'id'); $methodIndex = array_column($methods, null, 'code');
        $seen = [];
        $groupedReceipts = [];
        foreach ($receipts as $receipt) {
            if (!isset($names[$receipt['store_id']]) || !isset($methodIndex[$receipt['method_code']])
                || !is_int($receipt['amount_cents']) || empty($receipt['receipt_id']) || isset($seen[$receipt['receipt_id']])
                || $receipt['business_date'] < $range['start_date'] || $receipt['business_date'] > $range['end_date']) {
                throw new \InvalidArgumentException('对账明细身份、范围或金额异常');
            }
            $seen[$receipt['receipt_id']] = true;
            $cellKey = $mode === 'days' ? 'day_' . (int)substr($receipt['business_date'], 8, 2) : 'store_' . $receipt['store_id'];
            $groupedReceipts[$receipt['method_code']][$cellKey][] = $receipt;
        }
        // 只校验本次查询内容是否变化，不保存第二份业务快照或信任客户端金额。
        // 原始查询与后续下钻/导出之间发生收款、退款或目录变化时，要求刷新后再核对。
        $fingerprintRows = $receipts;
        usort($fingerprintRows, static function ($a, $b): int { return strcmp($a['receipt_id'], $b['receipt_id']); });
        $fingerprint = hash('sha256', json_encode([$stores, $methods, $fingerprintRows, $range], JSON_THROW_ON_ERROR));
        if (isset($payload['expected_fingerprint']) && (!is_string($payload['expected_fingerprint']) || !hash_equals($fingerprint, $payload['expected_fingerprint']))) {
            throw new \InvalidArgumentException('对账数据已变化，请刷新汇总后重试');
        }
        $fields = $this->definitions(self::CODE); $cells = [];
        if ($mode === 'days') {
            foreach (StoreReconciliationMatrix::days(substr($range['start_date'], 0, 7)) as $day) {
                $key = 'day_' . $day['id'];
                $fields[] = self::field($key, $day['id'] . '号', 'decimal', true);
                $cells[$key] = ['store_id' => $selected, 'start_date' => $day['name'], 'end_date' => $day['name']];
            }
        } else foreach ($stores as $store) {
            $key = 'store_' . $store['id'];
            $fields[] = self::field($key, (string)$store['name'], 'decimal', true);
            $cells[$key] = ['store_id' => (int)$store['id'], 'start_date' => $range['start_date'], 'end_date' => $range['end_date']];
        }
        $rows = []; $cellReceipts = [];
        foreach ($methods as $index => $method) {
            $row = ['row_id' => $method['code'], 'method_code' => $method['code'], 'payment_name' => $method['name'], 'sort_order' => $index];
            $total = 0;
            foreach ($cells as $key => $cell) {
                // 单次按稳定代码/门店/日期索引事实，不让宽表为每一格重复扫描全量明细。
                $facts = $groupedReceipts[$method['code']][$key] ?? [];
                $combined = 0;
                foreach ($facts as $fact) if (!empty($fact['combined'])) $combined = self::add($combined, $fact['amount_cents']);
                if ($combined <= 0) $facts = array_values(array_filter($facts, static function ($r): bool { return empty($r['combined']); }));
                $matrix = StoreReconciliationMatrix::build([['id' => $cell['store_id'], 'name' => $names[$cell['store_id']]]], [$method], $facts);
                $amount = $matrix['rows'][0]['total_cents'];
                $row[$key] = self::yuan($amount); $total = self::add($total, $amount);
                $cellReceipts[$method['code']][$key] = $facts;
            }
            $row['total_amount'] = self::yuan($total); $rows[] = $row;
        }
        $query = $payload;
        foreach (['pageCode','start_date','end_date','as_of_date','reconciliation_mode','reconciliation_store_id','month','detail','detail_page','expected_fingerprint'] as $key) unset($query[$key]);
        // 先验证矩阵已提交条件，明细不能绕过用户筛选直接探测未展示的行。
        $detail = $payload['detail'] ?? null;
        if ($detail !== null) { unset($query['export'], $query['visibleFields']); $query['page'] = 1; $query['limit'] = 200; }
        $result = $this->execute($fields, $rows, $query, $context, $detail !== null);
        if ($detail !== null) {
            if (!is_array($detail) || array_diff(array_keys($detail), ['method_code','column_key'])
                || !is_string($detail['method_code'] ?? null) || !is_string($detail['column_key'] ?? null)) throw new \InvalidArgumentException('下钻条件无效');
            $method = $detail['method_code']; $key = $detail['column_key'];
            if (!isset($cells[$key]) || !in_array($method, array_column($result['rows'], 'method_code'), true)) throw new \InvalidArgumentException('下钻条件不属于当前查询结果');
            $records = [];
            foreach ($cellReceipts[$method][$key] as $receipt) $records[] = [
                'row_id' => $receipt['receipt_id'], 'document_no' => $receipt['document_no'], 'business_date' => $receipt['business_date'],
                'store_name' => $names[$receipt['store_id']], 'payment_name' => $methodIndex[$method]['name'],
                'source_kind' => $receipt['source_kind'], 'amount' => self::yuan($receipt['amount_cents'])];
            // 跳到订单中心时使用同一格已经核实的原单号集合；这只是查询身份，
            // 不把收款金额当作订单金额，也不修改原单或扩充下钻权限。
            $navigationDocuments = array_values(array_unique(array_filter(array_column($records, 'document_no'), static function ($number): bool {
                return is_string($number) && $number !== '' && strpos($number, '登记-') !== 0;
            })));
            $columnLabel = array_column($fields, 'label', 'key')[$key];
            $fields = self::detailFields();
            $detailQuery = ['page' => $payload['detail_page'] ?? 1, 'summaries' => [['field' => 'amount', 'aggregation' => 'sum']],
                'sorts' => [['field' => 'business_date', 'direction' => 'desc']]];
            if (isset($payload['export'])) $detailQuery['export'] = $payload['export'];
            $result = $this->execute($fields, $records, $detailQuery, $context);
            $result['detail_context'] = $cells[$key] + [
                'payment_name' => $methodIndex[$method]['name'], 'column_label' => $columnLabel,
                // 单店逐日矩阵的列名是日期；订单中心门店范围必须使用原门店名称。
                'store_name' => $names[$cells[$key]['store_id']] ?? '',
            ];
            $result['navigation_document_nos'] = $navigationDocuments;
        }
        $sources = [];
        foreach ($fields as $field) $sources[$field['key']] = self::sourceDescription($field['key']);
        foreach ($fields as &$field) if (isset($cells[$field['key']])) $field['drilldown'] = ['column_key' => $field['key']];
        unset($field);
        return $result + ['queryFields' => $fields, 'field_sources' => $sources, 'store_options' => $stores,
            'receipt_fingerprint' => $fingerprint,
            'range' => $range, 'reconciliation_mode' => $mode,
            'table_layout' => ['fixed' => true, 'header_height' => 42, 'summary_position' => $detail === null ? 'none' : 'first_row'],
            'source_kind' => 'legacy_receipt_compatibility_and_unified_collections', 'complete' => true,
            'coverage_start' => null, 'coverage' => '按收银当前启用方式核对实际收款，会员订单第三方支付单列，另列抖音和大众刷单登记；历史订单沿用原收款资格，新业务读取成功收款，赠送不计入。',
            'data_as_of' => $context['data_as_of'] ?? time(), 'aggregation_caught_up' => true,
            'metric_version' => 'store-reconciliation-channel-and-brush-v3'];
    }

    /** 仅从本次已授权列生成查询白名单，动态门店编号不能成为任意SQL或越权导出字段。 */
    private function execute(array $fields, array $rows, array $query, array $context, bool $verifyAllMethods = false): array
    {
        $registry = new UnifiedQueryPageRegistry(); $definitions = [];
        foreach ($fields as $field) $definitions[] = UnifiedQueryPageRegistry::field($field['key'], $field['label'], $field['type'], true, $field['defaultQuick']);
        foreach (['row_id','method_code'] as $key) $definitions[] = UnifiedQueryPageRegistry::field($key, $key, 'text');
        $definitions[] = UnifiedQueryPageRegistry::field('sort_order', '显示顺序', 'integer');
        $registry->registerPage(self::CODE, '门店对账单', $definitions, 'row_id', ['requiredFeature' => 'cashier.v3.data']);
        $registry->freeze();
        $execution = new UnifiedQueryExecutionServices($registry, new StructuredExpressionValidator($registry), new StructuredExpressionEvaluator());
        // 普通列表统一20条；只有内部下钻资格核对读取完整的有界支付目录。
        $query['limit'] = $verifyAllMethods ? 200 : 20;
        if (empty($query['sorts'])) $query['sorts'] = [['field' => 'sort_order', 'direction' => 'asc']];
        // 集团可超过100家门店；不放宽全系统查询限制，也不丢掉宽表右侧门店。
        // 同一已授权、已投影事实集分列调用原执行器，稳定行键一致后拼回完整导出。
        if (isset($query['export']['fields']) && is_array($query['export']['fields']) && count($query['export']['fields']) > 100) {
            $wanted = $query['export']['fields'];
            if (count($wanted) > 252 || array_filter($wanted, static function ($key): bool { return !is_string($key); })) {
                throw new \InvalidArgumentException('导出字段数量或标识无效');
            }
            $merged = []; $result = null; $identity = null;
            foreach (array_chunk(array_values(array_unique($wanted)), 99) as $chunk) {
                $partQuery = $query; $partQuery['export']['fields'] = array_values(array_unique(array_merge(['row_id'], $chunk)));
                $part = $execution->execute(self::CODE, $rows, [], $partQuery, $context, static function (): bool { return true; });
                $keys = array_column($part['exportRows'], 'row_id');
                if ($identity !== null && $identity !== $keys) throw new \RuntimeException('宽表导出行身份不一致');
                $identity = $keys; $result = $part;
                foreach ($part['exportRows'] as $index => $record) $merged[$index] = array_replace($merged[$index] ?? [], $record);
            }
            $selected = array_fill_keys($wanted, true);
            $result['exportRows'] = array_map(static function ($row) use ($selected): array { return array_intersect_key($row, $selected); }, $merged);
            return $result;
        }
        return $execution->execute(self::CODE, $rows, [], $query, $context, static function (): bool { return true; });
    }

    /** 明细固定列与导出白名单共用；单据身份、来源和金额足以逐笔解释所点单元格。 */
    public static function detailFields(): array
    {
        $out = [];
        foreach (['business_date'=>'日期','store_name'=>'门店','document_no'=>'单据号','payment_name'=>'支付方式','source_kind'=>'收款来源','amount'=>'金额'] as $key=>$label)
            $out[] = self::field($key, $label, $key === 'amount' ? 'decimal' : ($key === 'business_date' ? 'date' : 'text'), $key === 'amount', in_array($key,['business_date','document_no'],true));
        return $out;
    }

    private static function field(string $key, string $label, string $type, bool $money = false, bool $fixed = false): array
    {
        return ['key'=>$key,'label'=>$label,'type'=>$type,'defaultVisible'=>true,'defaultQuick'=>$key==='payment_name',
            'width'=>$key==='document_no'?230:140,'fixed'=>$fixed?'left':'','money'=>$money,'summable'=>false];
    }

    /** 用户可见来源只表达业务语义，不把内部表名、字段名或SQL暴露成使用说明。 */
    public static function sourceDescription(string $key): string
    {
        if ($key === 'payment_name') return '实际收款行与收银当前启用方式共用名称和顺序；会员端第三方支付按创建时冻结分类单列。末尾抖音、大众刷单来自人工登记，不是结账收款方式。';
        if ($key === 'total_amount') return '本行当前查询范围内全部门店或日期的金额合计，按原始分精度汇总。';
        if ($key === 'business_date') return '历史单取原下单日，新销售取原单业务日；补交及充值等独立单据取各自业务日。';
        if ($key === 'document_no') return '解释所点收款金额的原单据号。';
        if ($key === 'store_name') return '当前查询中已获授权且启用统计的门店。';
        if ($key === 'source_kind') return '该笔金额来自历史订单、历史组合支付、新成功收款或独立的刷单登记；刷单不是实际到账。';
        return '对应门店或日期的实际收款，或末尾独立刷单行的登记金额；历史单排除未支付、退款、删除、旧卡和余额，组合支付按实际通道拆分；不把刷单并入收款。';
    }

    /** 转换只在输出边界进行，禁止二进制浮点参与金额统计。 */
    private static function yuan(int $cents): string
    {
        $sign=$cents<0?'-':''; $absolute=abs($cents);
        return $sign . intdiv($absolute,100) . '.' . str_pad((string)($absolute%100),2,'0',STR_PAD_LEFT);
    }
    private static function add(int $a,int $b):int
    {
        if (($b>0&&$a>PHP_INT_MAX-$b)||($b<0&&$a<PHP_INT_MIN-$b)) throw new \OverflowException('对账金额超出支持范围');
        return $a+$b;
    }
}
