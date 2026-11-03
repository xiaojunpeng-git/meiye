<?php
declare(strict_types=1);
namespace app\services\cashier\v3\order;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3DataScopeContext;
use think\facade\Db;

/**
 * 充值/补交适配到既有收款方式更正投影；不生成或覆盖收款、余额、欠款事实。
 * 只有可关联到本单成功收款事实的明细可更正，历史名称不能用来推测渠道。
 */
final class CashierV3BusinessPaymentCorrectionSource
{
    /** 业务身份保持类型前缀，避免充值、两类补交与销售主键碰撞。 */
    public static function identity(string $id): ?array
    {
        if (preg_match('/^recharge:([1-9][0-9]*)$/D', $id, $m)) {
            return ['table' => 'user_recharge', 'key' => 'id', 'id' => $m[1],
                'factOrder' => 'RCH:' . $m[1], 'document' => 'recharge'];
        }
        if (preg_match('/^v3-(sales-)?supplement:((DRP|RRP)-[a-f0-9]{40})$/Di', $id, $m)
            && (($m[1] !== '') === (strtoupper($m[3]) === 'DRP'))) {
            return ['table' => $m[1] !== '' ? 'cashier_v3_debt_repayment' : 'cashier_v3_recharge_debt_repayment',
                'key' => 'repayment_id', 'id' => $m[2], 'factOrder' => $m[2],
                'document' => $m[1] !== '' ? 'debt_repayment' : 'recharge_debt_repayment'];
        }
        return null;
    }

    /** 与作废/退款锁定同一权威主单，平台适配器定位门店后仍在此重新校验。 */
    public function order(string $id, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope, bool $lock): array
    {
        $identity = self::identity($id);
        if (!$identity) throw CashierV3CommandException::invalidContext('充值或补交标识无效。');
        $q = Db::name($identity['table'])->where($identity['key'], $identity['id'])->where('store_id', $operator->storeId());
        if ($identity['document'] !== 'recharge') $q->where('tenant_id', $scope->tenantId());
        $row = $lock ? $q->lock(true)->find() : $q->find();
        if (!$row) throw CashierV3CommandException::invalidContext('找不到当前门店的充值或补交记录。');
        if ($identity['document'] === 'recharge') {
            $lifecycle = Db::name('cashier_v3_order_lifecycle_operation')->where('tenant_id', $scope->tenantId())
                ->where('source_type', 'recharge')->where('source_order_id', (string)$row['id'])
                ->whereIn('operation_type', ['void', 'refund'])->where('status', 'succeeded');
            if ($lock) $lifecycle->lock(true);
            if ((int)$row['paid'] !== 1 || (int)$row['terminal_action'] !== 0
                || bccomp((string)$row['refund_price'], '0', 2) > 0 || $lifecycle->find()) {
                throw CashierV3CommandException::invalidContext('未支付、已退款或已作废充值不能更正收款方式。');
            }
            return ['order_no' => (string)$row['order_id'], 'member_id' => (int)$row['uid']];
        }
        if ((string)$row['status'] !== 'succeeded') throw CashierV3CommandException::invalidContext('已作废或未成功补交不能更正收款方式。');
        return ['order_no' => (string)$row['repayment_no'], 'member_id' => (int)$row['member_id']];
    }

    /** 稳定虚拟收款键仅标识已存在的事实，不伪造一笔新收款或修改原指纹。 */
    public static function collection(array $fact, string $name = ''): array
    {
        return ['collection_id' => 'CPC-' . sha1('business-payment:' . $fact['tenant_id'] . ':' . $fact['fact_id']),
            'payment_method' => (string)$fact['payment_method'],
            'payment_method_name_snapshot' => $name !== '' ? $name : (string)(\app\services\cashier\v3\config\CashierV3BusinessConfigServices::PAYMENT_METHODS[$fact['payment_method']] ?? $fact['payment_method']),
            'amount_cents' => (int)$fact['amount_cents'], 'fact_id' => (string)$fact['fact_id'],
            'source_line_id' => (string)$fact['source_line_id'],
            'immutable_fingerprint' => (string)$fact['immutable_fingerprint']];
    }

    /** 事务内在主单之后锁原收款事实；来源类型、租户及门店必须同时匹配。 */
    public function collections(string $id, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope, bool $lock): array
    {
        $i = self::identity($id);
        $authority = $this->order($id, $operator, $scope, $lock);
        $names = $this->originalNames([$i['factOrder'] => ['record' => $id, 'document' => $i['document']]], [$operator->storeId()], $scope->tenantId());
        $q = Db::name('cashier_v3_payment_fact')->force('idx_order_line')->where('tenant_id', $scope->tenantId())
            ->where('store_id', $operator->storeId())->where('order_id', $i['factOrder'])
            ->where('source_document_type', $i['document'])->where('fact_direction', 'forward')
            ->whereIn('fact_type', ['payment_collected', 'payment_received'])->where('status', 'effective')
            ->order('source_line_id', 'asc')->order('id', 'asc');
        if ($lock) $q->lock(true);
        $rows = [];
        foreach ($q->select()->toArray() as $fact) {
            if ((int)$fact['member_id'] !== $authority['member_id']) throw CashierV3CommandException::invalidContext('收款会员与原单不一致，不能更正。');
            $rows[] = self::collection($fact, $names[$fact['order_id']][$fact['source_line_id']] ?? '');
        }
        return $rows;
    }

    /** Original labels are display snapshots only; identities and amounts always come from the linked fact. */
    private function originalNames(array $identities, array $stores, string $tenant): array
    {
        $names = []; $recharges = []; $repayments = [];
        foreach ($identities as $order => $i) {
            if ($i['document'] === 'recharge') $recharges[] = (int)substr($order, 4);
            if ($i['document'] === 'debt_repayment') $repayments[] = $order;
        }
        if ($recharges) foreach (Db::name('user_recharge')->whereIn('id', $recharges)->whereIn('store_id', $stores)
            ->field('id,combination_info')->select()->toArray() as $r) {
            $lines = json_decode((string)$r['combination_info'], true);
            if (!is_array($lines)) continue;
            foreach (array_values($lines) as $index => $line) {
                if (!is_array($line)) continue;
                $name = trim((string)($line['paymentMethodNameSnapshot'] ?? $line['name'] ?? ''));
                if (strpos($name, '\\u') !== false) {
                    $name = preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', static function (array $m): string {
                        $decoded = json_decode('"' . $m[0] . '"'); return is_string($decoded) ? $decoded : $m[0];
                    }, $name);
                }
                if ($name !== '') $names['RCH:' . $r['id']]['RCH:' . $r['id'] . ':payment:' . ($index + 1)] = $name;
            }
        }
        if ($repayments) foreach (Db::name('cashier_v3_debt_repayment_collection')->alias('c')
            ->join('cashier_v3_debt_repayment h', 'h.repayment_id=c.repayment_id')->where('h.tenant_id', $tenant)
            ->whereIn('h.store_id', $stores)->whereIn('c.repayment_id', $repayments)
            ->field('c.repayment_id,c.payment_line_no,c.payment_method_name_snapshot')->select()->toArray() as $r) {
            $names[$r['repayment_id']][$r['repayment_id'] . ':payment:' . $r['payment_line_no']] = (string)$r['payment_method_name_snapshot'];
        }
        return $names;
    }

    /**
     * 列表/详情/导出共用批量读取；只装饰调用方已按权限裁剪的主单。
     * 没有统一事实的历史记录保留原显示，不从名称或余额拼造可编辑收款。
     */
    public function decorate(array $records, string $tenant, bool $writable = true): array
    {
        $identities = []; $stores = [];
        foreach ($records as $r) {
            $i = self::identity((string)$r['id']);
            if ($i) { $identities[$i['factOrder']] = ['record' => $r['id'], 'document' => $i['document'], 'store' => (int)($r['storeId'] ?? 0), 'member' => (int)($r['memberId'] ?? 0)]; }
        }
        if (!$identities) return $records;
        // The query service passes only authorized records; bind each fact back to
        // the exact document and store identity before exposing an editing key.
        foreach ($records as $r) if (!empty($r['storeId'])) $stores[] = (int)$r['storeId'];
        if (!$stores) return $records;
        $facts = Db::name('cashier_v3_payment_fact')->where('tenant_id', $tenant)
            ->whereIn('store_id', array_unique($stores))->whereIn('order_id', array_keys($identities))
            ->where('fact_direction', 'forward')->where('status', 'effective')
            ->whereIn('fact_type', ['payment_collected', 'payment_received'])->order('id', 'asc')->select()->toArray();
        $names = $this->originalNames($identities, $stores, $tenant);
        $byRecord = [];
        foreach ($facts as $f) {
            $i = $identities[$f['order_id']];
            if ($f['source_document_type'] === $i['document'] && (int)$f['store_id'] === $i['store'] && (int)$f['member_id'] === $i['member']) $byRecord[$i['record']][] = self::collection($f, $names[$f['order_id']][$f['source_line_id']] ?? '');
        }
        // Balance tenders do not create actual collection facts, but must remain visible
        // next to corrected cash in a mixed sales-debt repayment. They stay non-editable.
        $balances = [];
        $repayments = array_keys(array_filter($identities, static function (array $i): bool { return $i['document'] === 'debt_repayment'; }));
        if ($repayments) foreach (Db::name('cashier_v3_debt_repayment_collection')->alias('bc')
            ->join('cashier_v3_debt_repayment bh', 'bh.repayment_id=bc.repayment_id')->where('bh.tenant_id', $tenant)
            ->whereIn('bc.repayment_id', $repayments)->where('bc.payment_method', 'member_balance')
            ->where('bc.collection_status', 'succeeded')->field('bc.*')->select()->toArray() as $b) {
            $balances[$identities[$b['repayment_id']]['record']][] = $b;
        }
        $corrections = new CashierV3PaymentMethodCorrectionServices();
        // 经营列表按权威渠道代码读取本站配置名；原收款/更正快照原样保留供审计和并发比较。
        // 含历史“现金”误命名的非现金方式仍由原更正服务解释，不能被当前别名掩盖。
        $displayNames = array_column((new \app\services\cashier\v3\config\CashierV3BusinessConfigServices())->accountingMethods(), 'displayName', 'code');
        foreach ($records as &$r) {
            $collections = $byRecord[$r['id']] ?? [];
            if (!$collections) continue;
            $collections = $corrections->effectiveCollections($collections, $tenant);
            $r['paymentDetails'] = array_map(static function (array $c) use ($displayNames): array {
                return ['id' => $c['collection_id'], 'paymentMethodCode' => $c['payment_method'],
                    'methodName' => $c['payment_method'] === 'other_collection'
                        ? $c['payment_method_name_snapshot'] : ($displayNames[$c['payment_method']] ?? $c['payment_method_name_snapshot']),
                    'amount' => bcdiv((string)$c['amount_cents'], '100', 2)];
            }, $collections);
            $editable = ($r['orderStatus'] ?? '') === '正常' || ($r['paymentStatus'] ?? '') === '补交成功';
            $r['paymentMethodEditable'] = $writable && $editable;
            foreach ($balances[$r['id']] ?? [] as $b) $r['paymentDetails'][] = [
                'id' => $b['collection_id'], 'paymentMethodCode' => 'member_balance',
                'methodName' => '余额支付', 'amount' => bcdiv((string)$b['amount_cents'], '100', 2)];
            $r['paymentMethod'] = implode('、', array_values(array_unique(array_column($r['paymentDetails'], 'methodName'))));
        }
        unset($r);
        return $records;
    }
}
