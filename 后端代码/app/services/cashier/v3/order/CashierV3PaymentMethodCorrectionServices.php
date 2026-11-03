<?php
declare(strict_types=1);

namespace app\services\cashier\v3\order;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\config\CashierV3BusinessConfigServices;
use app\services\cashier\v3\event\CashierV3BusinessEventExecution;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use think\facade\Db;

/**
 * 更正已到账消费、充值及补交收款的记账方式。金额、时间、结账草稿及初始指纹均不变；
 * 有效方式由可重建投影提供；原收款、payment_collected 和销售分摊事实保持原样。
 */
final class CashierV3PaymentMethodCorrectionServices
{
    private const TABLE = 'cashier_v3_payment_method_correction';
    private const CURRENT_TABLE = 'cashier_v3_payment_method_current';
    private const ACTUAL_METHODS = [
        'cash', 'unionpay', 'enterprise_wechat', 'wechat', 'alipay', 'dianping_voucher', 'douyin_voucher',
        'bank_transfer', 'other_income', 'partner_collection',
    ];

    /** 编辑入口与提交都从权威订单和当前配置读取；历史停用方式可显示但不能再选。 */
    public function entry(array $payload, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope): array
    {
        $orderId = $this->orderId($payload);
        $order = $this->order($orderId, $operator, $scope, false);
        $collectionId = trim((string)($payload['collectionId'] ?? ''));
        $query = Db::name('cashier_v3_payment_collection')->where('tenant_id', $scope->tenantId())
            ->where('sales_order_id', $orderId)->where('collection_direction', 'forward')
            ->where('collection_status', 'settled');
        if ($collectionId !== '') $query->where('collection_id', $collectionId);
        // Non-sales documents share the correction transaction/projection, with their own authoritative identity.
        $collections = CashierV3BusinessPaymentCorrectionSource::identity($orderId)
            ? (new CashierV3BusinessPaymentCorrectionSource())->collections($orderId, $operator, $scope, false)
            : $query->order('payment_line_no', 'asc')->select()->toArray();
        if ($collectionId !== '') $collections = array_values(array_filter($collections, static function (array $row) use ($collectionId): bool { return $row['collection_id'] === $collectionId; }));
        $collections = $this->effectiveCollections($collections, $scope->tenantId());
        $history = Db::name(self::TABLE)->where('tenant_id', $scope->tenantId())
            ->where('sales_order_id', $orderId)->order('id', 'desc')->limit(100)->select()->toArray();
        $methods = array_values(array_filter((new CashierV3BusinessConfigServices())->accountingMethods(true),
            static function (array $method): bool { return in_array((string)$method['code'], self::ACTUAL_METHODS, true); }));
        return [
            'orderId' => $orderId, 'orderNo' => (string)$order['order_no'],
            'payments' => array_map(static function (array $row): array {
                return [
                    'id' => (string)$row['collection_id'], 'paymentMethodCode' => (string)$row['payment_method'],
                    'methodName' => (string)$row['payment_method_name_snapshot'],
                    'amountCents' => (int)$row['amount_cents'],
                    'editable' => (int)$row['amount_cents'] > 0
                        && in_array((string)$row['payment_method'], self::ACTUAL_METHODS, true),
                ];
            }, $collections),
            'methods' => array_map(static function (array $row): array {
                return ['code' => (string)$row['code'], 'name' => (string)$row['displayName']];
            }, $methods),
            'history' => array_map(static function (array $row): array {
                return [
                    'collectionId' => (string)$row['collection_id'],
                    'beforeName' => (string)$row['before_name_snapshot'],
                    'afterName' => (string)$row['after_name_snapshot'],
                    'reason' => (string)$row['reason'],
                    'operatorName' => (string)$row['operator_name_snapshot'],
                    'occurredAt' => (int)$row['occurred_at'],
                ];
            }, $history),
        ];
    }

    /** 列表、详情和导出对同一批原始收款叠加当前有效方式，不改动原始事实。 */
    public function effectiveCollections(array $collections, string $tenantId): array
    {
        if ($collections === []) return [];
        $ids = array_values(array_unique(array_map(static function (array $row): string {
            return (string)$row['collection_id'];
        }, $collections)));
        $current = Db::name(self::CURRENT_TABLE)->where('tenant_id', $tenantId)
            ->whereIn('collection_id', $ids)->select()->toArray();
        $byId = [];
        foreach ($current as $row) $byId[(string)$row['collection_id']] = $row;
        foreach ($collections as &$collection) {
            $effective = $byId[(string)$collection['collection_id']] ?? null;
            if (!$effective) continue;
            if ((int)$effective['amount_cents'] !== (int)$collection['amount_cents']) {
                throw new \RuntimeException('payment_method_projection_amount_mismatch');
            }
            $collection['payment_method'] = (string)$effective['current_method'];
            $collection['payment_method_name_snapshot'] = (string)$effective['current_name_snapshot'];
        }
        unset($collection);
        return $collections;
    }

    /** Gateway 事务内锁订单、收款及相关事实，并用客户端看到的旧值防止并发覆盖。 */
    public function executeInTx(array $context): array
    {
        CashierV3TransactionGuard::assertInTransaction('paymentMethodCorrection');
        $operator = $context['operator_scope'] ?? null;
        $scope = $context['data_scope'] ?? null;
        $recorder = $context['event_recorder'] ?? null;
        $execution = $context['event_execution'] ?? null;
        if (!$operator instanceof CashierV3OperatorScope || !$scope instanceof CashierV3DataScopeContext
            || !$recorder instanceof CashierV3BusinessEventRecorder || !$execution instanceof CashierV3BusinessEventExecution) {
            throw CashierV3CommandException::invalidContext('收款方式更正服务未就绪，请刷新订单中心。');
        }
        $payload = (array)($context['payload'] ?? []);
        $orderId = $this->orderId($payload);
        $collectionId = trim((string)($payload['collectionId'] ?? ''));
        $before = trim((string)($payload['expectedMethod'] ?? ''));
        $after = trim((string)($payload['paymentMethod'] ?? ''));
        $reason = trim((string)($payload['reason'] ?? ''));
        $key = trim((string)($context['idempotency_key'] ?? ''));
        if (!preg_match('/^CPC-[a-f0-9]{40}$/Di', $collectionId)
            || !in_array($before, self::ACTUAL_METHODS, true)
            || !in_array($after, self::ACTUAL_METHODS, true)
            || $before === $after || $reason === '' || mb_strlen($reason) > 255 || $key === '') {
            throw CashierV3CommandException::invalidContext('请选择有效的新收款方式并填写更正原因。');
        }
        $fingerprint = hash('sha256', json_encode([$orderId, $collectionId, $before, $after, $reason], JSON_UNESCAPED_UNICODE));
        $replay = Db::name(self::TABLE)->where('tenant_id', $scope->tenantId())
            ->where('command_idempotency_key', $key)->lock(true)->find();
        if ($replay) {
            if (!hash_equals((string)$replay['request_fingerprint'], $fingerprint)) {
                throw CashierV3CommandException::invalidContext('同一次更正请求的内容已变化，请刷新后重试。');
            }
            return ['operationId' => (string)$replay['operation_id'], 'orderId' => $orderId, 'replayed' => true];
        }
        $order = $this->order($orderId, $operator, $scope, true);
        $businessSource = CashierV3BusinessPaymentCorrectionSource::identity($orderId);
        $collection = null;
        if ($businessSource) {
            $candidates = (new CashierV3BusinessPaymentCorrectionSource())->collections($orderId, $operator, $scope, true);
            $collection = null;
            foreach ($candidates as $candidate) if ($candidate['collection_id'] === $collectionId) $collection = $candidate;
        } else {
            $collection = Db::name('cashier_v3_payment_collection')->where('tenant_id', $scope->tenantId())
                ->where('sales_order_id', $orderId)->where('collection_id', $collectionId)
                ->where('collection_direction', 'forward')->where('collection_status', 'settled')->lock(true)->find();
        }
        if (!$collection || (int)$collection['amount_cents'] <= 0) {
            throw CashierV3CommandException::invalidContext('该订单没有可更正的正式收款，请刷新列表。');
        }
        $current = Db::name(self::CURRENT_TABLE)->where('tenant_id', $scope->tenantId())
            ->where('collection_id', $collectionId)->lock(true)->find();
        $effectiveBefore = (string)($current['current_method'] ?? $collection['payment_method']);
        $beforeName = (string)($current['current_name_snapshot'] ?? $collection['payment_method_name_snapshot']);
        if ($effectiveBefore !== $before) {
            throw CashierV3CommandException::versionConflict('收款方式已被其他操作修改，请刷新后重试。');
        }
        if ($current && ((string)$current['payment_fact_id'] === ''
            || (int)$current['amount_cents'] !== (int)$collection['amount_cents'])) {
            throw CashierV3CommandException::invalidContext('收款方式投影与原始金额不一致，不能更正。');
        }
        // 初始结账草稿仍是原始业务证据；即使本笔多次更正，也不能篡改其付款快照。
        $original = $businessSource ? ['payment_method' => $collection['payment_method']] : Db::name('cashier_v3_checkout_payment_draft')->where('tenant_id', $scope->tenantId())
            ->where('payment_draft_id', (string)($collection['checkout_payment_draft_id'] ?? ''))
            ->where('request_id', (string)($order['checkout_request_id'] ?? ''))->find();
        // Recharge/repayment original tender is the linked immutable payment fact; no checkout draft is fabricated.
        if (!$original || !in_array((string)$original['payment_method'], self::ACTUAL_METHODS, true)) {
            throw CashierV3CommandException::invalidContext('原始收款凭据缺失，不能更正。');
        }
        $method = (new CashierV3BusinessConfigServices())->resolveAccountingMethodSnapshot($after, true);
        $factQuery = Db::name('cashier_v3_payment_fact')->where('tenant_id', $scope->tenantId())
            ->where('order_id', $businessSource['factOrder'] ?? $orderId)->where('source_line_id', $collection['source_line_id'] ?? $collectionId)
            ->whereIn('fact_type', ['payment_collected', 'payment_received'])->where('fact_direction', 'forward')
            ->where('status', 'effective');
        if ($businessSource) $factQuery->where('fact_id', $collection['fact_id'])->where('source_document_type', $businessSource['document'])->where('store_id', $operator->storeId());
        $fact = $factQuery->lock(true)->find();
        if (!$fact || (string)$fact['payment_method'] !== (string)$collection['payment_method']
            || (int)$fact['amount_cents'] !== (int)$collection['amount_cents']) {
            throw CashierV3CommandException::invalidContext('统一收款事实与正式收款不一致，不能更正。');
        }
        // 反向事实保持原方式；统一 Reader 通过 reversal_of 找到此笔有效方式后抵销。
        // The forward fact is already locked by its unique identity. Read reversals
        // through their dedicated index: an OR scan could lock unrelated receipts.
        $facts = array_merge([$fact], Db::name('cashier_v3_payment_fact')->force('idx_reversal')
            ->where('tenant_id', $scope->tenantId())->where('reversal_of', (string)$fact['fact_id'])
            ->order('id', 'asc')->lock(true)->select()->toArray());
        $factIds = [];
        foreach ($facts as $row) {
            if ((string)$row['payment_method'] !== (string)$collection['payment_method']) {
                throw CashierV3CommandException::invalidContext('关联退款的收款方式不一致，不能更正。');
            }
            $factIds[] = (string)$row['fact_id'];
        }
        $allocations = Db::name('cashier_v3_payment_sale_allocation_fact')
            ->where('tenant_id', $scope->tenantId())->whereIn('payment_fact_id', $factIds)
            ->lock(true)->select()->toArray();
        foreach ($allocations as $row) {
            if ((string)$row['payment_method'] !== (string)$collection['payment_method']) {
                throw CashierV3CommandException::invalidContext('销售分摊收款方式不一致，不能更正。');
            }
        }
        $now = time();
        $operationId = 'PMC-' . strtoupper(substr(hash('sha256', $scope->tenantId() . '|' . $key), 0, 40));
        $operatorName = trim((string)($scope->operatorProfile()['name']
            ?? $scope->operatorProfile()['real_name'] ?? $scope->operatorProfile()['staff_name'] ?? ''));
        if ($operatorName === '') $operatorName = '操作人#' . $operator->operatorId();
        // 只写当前有效方式投影和追加审计；历史收款及经营事实不参与 UPDATE。
        if ($current) {
            if ((string)$current['payment_fact_id'] !== (string)$fact['fact_id']
                || (int)Db::name(self::CURRENT_TABLE)->where('id', (int)$current['id'])
                    ->where('current_method', $before)->update([
                        'current_method' => $after, 'current_name_snapshot' => (string)$method['displayNameSnapshot'],
                        'last_operation_id' => $operationId, 'updated_at' => $now,
                    ]) !== 1) {
                throw CashierV3CommandException::versionConflict('收款方式已变化，请刷新后重试。');
            }
        } else {
            Db::name(self::CURRENT_TABLE)->insert([
                'tenant_id' => $scope->tenantId(), 'store_id' => $operator->storeId(),
                'sales_order_id' => $orderId, 'collection_id' => $collectionId,
                'payment_fact_id' => (string)$fact['fact_id'],
                'current_method' => $after, 'current_name_snapshot' => (string)$method['displayNameSnapshot'],
                'amount_cents' => (int)$collection['amount_cents'],
                'last_operation_id' => $operationId, 'updated_at' => $now,
            ]);
        }
        Db::name(self::TABLE)->insert([
            'operation_id' => $operationId, 'tenant_id' => $scope->tenantId(), 'store_id' => $operator->storeId(),
            'sales_order_id' => $orderId, 'collection_id' => $collectionId,
            'payment_fact_id' => (string)$fact['fact_id'],
            'original_collection_fingerprint' => (string)$collection['immutable_fingerprint'],
            'original_method' => (string)$original['payment_method'],
            'before_method' => $before, 'before_name_snapshot' => $beforeName,
            'after_method' => $after, 'after_name_snapshot' => (string)$method['displayNameSnapshot'],
            'amount_cents' => (int)$collection['amount_cents'], 'reason' => $reason,
            'operator_id' => $operator->operatorId(), 'operator_name_snapshot' => mb_substr($operatorName, 0, 128),
            'command_idempotency_key' => $key, 'request_fingerprint' => $fingerprint, 'occurred_at' => $now,
        ]);
        $recorder->recordInTx($execution, (array)($context['event_contract'] ?? []), [
            'event_type' => 'payment_collection.method_corrected', 'aggregate_type' => 'payment_method_correction',
            'aggregate_id' => $operationId, 'aggregate_version' => 1, 'event_version' => 1,
            'source_type' => 'correct-sales-payment-method', 'source_id' => $operationId,
            'member_id' => (int)$order['member_id'], 'business_date' => date('Y-m-d', $now),
            'occurred_at' => $now, 'settled_at' => $now, 'recorded_at' => $now,
            'aggregate_name_snapshot' => (string)$order['order_no'],
            'payload' => ['operationId' => $operationId, 'orderId' => $orderId,
                'collectionId' => $collectionId, 'beforeMethod' => $before, 'afterMethod' => $after,
                'amountCents' => (int)$collection['amount_cents'], 'reason' => $reason],
        ]);
        return ['operationId' => $operationId, 'orderId' => $orderId, 'replayed' => false];
    }

    /** 平台和门店共用同一范围校验；平台仅由可信适配器定位目标门店。 */
    private function order(string $orderId, CashierV3OperatorScope $operator,
        CashierV3DataScopeContext $scope, bool $lock): array
    {
        if (!$scope->hasFeature('cashier.v3.order_center') || $scope->isReadOnlySession()
            || $operator->tenantId() !== $scope->tenantId()
            || $operator->storeId() !== $scope->forcedStoreId()
            || !$scope->allowsStore($operator->storeId())) {
            throw CashierV3CommandException::invalidContext('当前账号无权更正该门店的收款。');
        }
        if (CashierV3BusinessPaymentCorrectionSource::identity($orderId)) {
            return (new CashierV3BusinessPaymentCorrectionSource())->order($orderId, $operator, $scope, $lock);
        }
        $query = Db::name('cashier_v3_sales_order')->where('tenant_id', $scope->tenantId())
            ->where('store_id', $operator->storeId())->where('order_id', $orderId)
            ->where('order_status', 'settled')->where('order_direction', 'forward');
        if ($lock) $query->lock(true);
        $order = $query->find();
        if (!$order) throw CashierV3CommandException::invalidContext('正式销售订单不存在或不可更正。');
        $voided = Db::name('cashier_v3_order_lifecycle_operation')->where('tenant_id', $scope->tenantId())
            ->where('source_order_id', $orderId)->where('operation_type', 'void')
            ->where('status', 'succeeded')->find();
        if ($voided) throw CashierV3CommandException::invalidContext('已作废订单不能更正收款方式。');
        return $order;
    }

    private function orderId(array $payload): string
    {
        $id = trim((string)($payload['recordId'] ?? $payload['orderId'] ?? ''));
        if (CashierV3BusinessPaymentCorrectionSource::identity($id)) return $id;
        if (!preg_match('/^CSO-[a-f0-9]{40}$/Di', $id)) {
            throw CashierV3CommandException::invalidContext('订单标识无效，请刷新列表后重试。');
        }
        return $id;
    }
}
