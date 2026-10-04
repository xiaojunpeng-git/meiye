<?php
declare(strict_types=1);

/**
 * 第22轮本地真实库事务冒烟：走正式领域服务和事件合同，所有试运行均回滚。
 * 从门店既有单据选样，避免人工插入经济事实；`--persist-six` 由验收准备时显式使用。
 */
require '/var/www/html/vendor/autoload.php';

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use app\services\cashier\v3\manifest\CashierV3ActionManifest;
use app\services\cashier\v3\order\CashierV3OrderAttributionCorrectionServices;
use think\facade\Db;

$app = new think\App('/var/www/html/');
$app->env->load('/var/www/html/.env');
$app->initialize();
$persist = in_array('--persist-six', $argv, true);
$verify = in_array('--verify-six', $argv, true);
$tenant = '0';
$service = new CashierV3OrderAttributionCorrectionServices();
$recorder = new CashierV3BusinessEventRecorder();
$cases = [
    ['sales', 'cashier_v3_sales_order', 'order_id', 'CSO-7ffa6e1289a7925b3f3be532e939dde605dadf67', 18, '2026-10-02', 3],
    ['sales', 'cashier_v3_sales_order', 'order_id', 'CSO-a60b343107f0bd861cc48601f81913a976fcb78b', 164, '2026-10-01', 3],
    ['recharge', 'user_recharge', 'id', '194', 127, '2026-10-03', 3],
    ['recharge', 'user_recharge', 'id', '187', 132, '2023-03-02', 3],
    ['supplement', 'cashier_v3_debt_repayment', 'repayment_id', 'DRP-5d3896dc4232eabed15cda8335cfb9dd36ddda77', 55, '2026-10-02', 0],
    ['supplement', 'cashier_v3_debt_repayment', 'repayment_id', 'DRP-bc75f90ed252e7e4aeab8e2b9665a6718d3f5038', 55, '2026-10-02', 0],
];
// 六笔样例一经保留，默认入口转为只读复核；不能再次拿旧值提交或清理审计记录。
if (!$persist && !$verify) {
    $verify = true;
    foreach ($cases as [$type, , , $id]) {
        if (!Db::name('cashier_v3_order_attribution_change')->where('root_record_type', $type)
            ->where('root_record_id', $id)->count()) {
            $verify = false;
            break;
        }
    }
}
$results = [];
foreach ($cases as [$type, $table, $idColumn, $id, $storeId, $targetDate, $targetSource]) {
    $row = Db::name($table)->where($idColumn, $id)->find();
    if (!$row || (int)$row['store_id'] !== $storeId) throw new RuntimeException('fixture missing: ' . $id);
    $operatorId = (int)Db::name('system_store_staff')->where('store_id', $storeId)->where('status', 1)->value('id');
    if (!$operatorId) throw new RuntimeException('operator missing: ' . $storeId);
    $organizationId = (string)Db::name('organization_store')->where('store_id', $storeId)->value('org_id');
    if ($organizationId === '' || $organizationId === '0') throw new RuntimeException('organization missing: ' . $storeId);
    $operator = new CashierV3OperatorScope($storeId, $operatorId, $organizationId, $tenant);
    $operatorProfile = (array)Db::name('system_store_staff')->where('id', $operatorId)->find();
    $scope = new CashierV3DataScopeContext($operatorId, $operatorId, $storeId, $tenant, $organizationId, [$storeId],
        CashierV3DataScopeContext::MODE_STORES, [], false, '', 'local-r22', ['cashier.v3.order_center'], $operatorProfile);
    if ($verify) {
        if ((string)$row['business_date'] !== $targetDate) throw new RuntimeException('date not retained: ' . $id);
        if ($type !== 'supplement' && (int)$row['business_source_primary_id'] !== $targetSource) {
            throw new RuntimeException('source not retained: ' . $id);
        }
        $history = $service->history(['recordType' => $type, 'recordId' => $id], $operator, $scope);
        $needed = $type === 'supplement' ? 1 : 2;
        if (count($history['changes']) < $needed) throw new RuntimeException('history not visible: ' . $id);
        if ($type === 'sales') {
            $eventNo = (string)Db::name('cashier_v3_business_event')->where('source_type', 'submit-checkout')
                ->where('aggregate_id', $id)->value('event_no');
            if ($eventNo === '') throw new RuntimeException('original sale event missing: ' . $id);
            foreach (['cashier_v3_sale_fact', 'cashier_v3_payment_fact', 'cashier_v3_balance_fact'] as $factTable) {
                if (Db::name($factTable)->where('business_event_no', $eventNo)
                    ->where('fact_direction', 'forward')->where('business_date', '<>', $targetDate)->count()) {
                    throw new RuntimeException($factTable . ' report date not moved: ' . $id);
                }
                if (Db::name($factTable)->where('order_id', $id)
                    ->where('business_source_primary_id', '<>', $targetSource)->count()) {
                    throw new RuntimeException($factTable . ' source not moved: ' . $id);
                }
            }
            foreach (Db::name('cashier_v3_debt_repayment')->where('sales_order_id', $id)->select()->toArray() as $repayment) {
                if ((int)$repayment['business_source_primary_id'] !== $targetSource) {
                    throw new RuntimeException('related supplement source not retained: ' . $id);
                }
                $linkedHistory = $service->history(['recordType' => 'supplement',
                    'recordId' => (string)$repayment['repayment_id']], $operator, $scope);
                if (!array_filter($linkedHistory['changes'], static function (array $change): bool {
                    return $change['field'] === 'source';
                })) throw new RuntimeException('related supplement source history not visible: ' . $id);
            }
        } elseif ($type === 'recharge') {
            foreach (['cashier_v3_payment_fact', 'cashier_v3_balance_fact', 'cashier_v3_performance_fact'] as $factTable) {
                if (Db::name($factTable)->where('order_id', 'RCH:' . $id)
                    ->where('business_source_primary_id', '<>', $targetSource)->count()) {
                    throw new RuntimeException($factTable . ' recharge source not moved: ' . $id);
                }
            }
        } else {
            if (Db::name('cashier_v3_payment_fact')->where('order_id', $id)
                ->where('fact_direction', 'forward')->where('business_date', '<>', $targetDate)->count()) {
                throw new RuntimeException('repayment report fact not moved: ' . $id);
            }
        }
        $results[] = ['type' => $type, 'recordId' => $id, 'historyCount' => count($history['changes'])];
        continue;
    }
    $action = $type === 'sales' ? 'correct-sales-order-attribution'
        : ($type === 'recharge' ? 'correct-recharge-order-attribution' : 'correct-debt-repayment-date');
    $operations = [['business_date', (string)$row['business_date'], $targetDate, 0]];
    if ($type !== 'supplement') {
        $oldSource = (string)($row['business_source_secondary_name_snapshot'] ?: $row['business_source_primary_name_snapshot'] ?: $row['business_source_label_snapshot']);
        $sourceName = (string)Db::name('cashier_v3_business_source')->where('id', $targetSource)->value('name');
        if ($oldSource === $sourceName) $targetSource = 4;
        $operations[] = ['source', $oldSource, '', $targetSource];
    }
    foreach ($operations as [$field, $expected, $newDate, $sourceId]) {
        $key = 'R22-LOCAL-' . strtoupper($type) . '-' . substr(hash('sha256', $id . '|' . $field), 0, 22);
        $payload = ['recordType' => $type, 'recordId' => $id, 'field' => $field,
            'expectedValue' => $expected, 'newValue' => $newDate,
            'expectedPrimarySourceId' => (int)($row['business_source_primary_id'] ?? 0),
            'expectedSecondarySourceId' => (int)($row['business_source_secondary_id'] ?? 0),
            'primarySourceId' => $sourceId, 'secondarySourceId' => 0];
        Db::startTrans();
        try {
            $execution = $recorder->newExecution($action, $key, $operator, $scope, 'R22-local-acceptance');
            $result = $service->executeInTx(['operator_scope' => $operator, 'data_scope' => $scope,
                'event_recorder' => $recorder, 'event_execution' => $execution,
                'event_contract' => CashierV3ActionManifest::eventContractFor($action),
                'action' => $action, 'payload' => $payload, 'idempotency_key' => $key]);
            $changed = (array)Db::name($table)->where($idColumn, $id)->find();
            if ($field === 'business_date' && (string)$changed['business_date'] !== $newDate) {
                throw new RuntimeException('main business date was not updated');
            }
            if ($field === 'source' && (int)$changed['business_source_primary_id'] !== $sourceId) {
                throw new RuntimeException('main source was not updated');
            }
            $auditCount = (int)Db::name('cashier_v3_order_attribution_change')
                ->where('operation_id', $result['operationId'])->count();
            if ($auditCount < 1) throw new RuntimeException('audit row was not inserted');
            if ($field === 'source' && $type === 'sales') {
                $linked = Db::name('cashier_v3_debt_repayment')->where('sales_order_id', $id)->select()->toArray();
                foreach ($linked as $repayment) {
                    if ((int)$repayment['business_source_primary_id'] !== $sourceId) {
                        throw new RuntimeException('linked repayment source was not updated');
                    }
                    if ((int)Db::name('cashier_v3_order_attribution_change')
                        ->where('operation_id', $result['operationId'])->where('record_id', $repayment['repayment_id'])->count() < 1) {
                        throw new RuntimeException('linked repayment audit was not inserted');
                    }
                }
            }
            if ($persist) Db::commit(); else Db::rollback();
            if (!$persist) {
                $rolledBack = (array)Db::name($table)->where($idColumn, $id)->find();
                $original = $field === 'business_date' ? (string)$row['business_date'] : (int)$row['business_source_primary_id'];
                $restored = $field === 'business_date' ? (string)$rolledBack['business_date'] : (int)$rolledBack['business_source_primary_id'];
                if ($original !== $restored) throw new RuntimeException('rollback did not restore main record');
                if (Db::name('cashier_v3_order_attribution_change')->where('operation_id', $result['operationId'])->count()) {
                    throw new RuntimeException('rollback left an audit row');
                }
            }
            $results[] = ['type' => $type, 'recordId' => $id, 'field' => $field, 'old' => $expected,
                'new' => $field === 'source' ? (string)Db::name('cashier_v3_business_source')->where('id', $sourceId)->value('name') : $newDate,
                'operationId' => $result['operationId']];
        } catch (Throwable $error) {
            Db::rollback();
            $detail = $error instanceof app\services\cashier\v3\CashierV3CommandException ? json_encode($error->getDetail()) : '';
            throw new RuntimeException($type . '/' . $id . '/' . $field . ': ' . $error->getMessage() . ' ' . $detail, 0, $error);
        }
    }
}
if ($verify) {
    // 最后一个门店上下文不能查看别店销售的更正记录；旧日期再提交也必须并发拒绝。
    try {
        $service->history(['recordType' => 'sales', 'recordId' => $cases[0][3]], $operator, $scope);
        throw new RuntimeException('cross-store history was allowed');
    } catch (app\services\cashier\v3\CashierV3CommandException $expected) {
        $results[] = ['guard' => 'cross-store history denied'];
    }
    Db::startTrans();
    try {
        $key = 'R22-STALE-CAS-VERIFY';
        $execution = $recorder->newExecution('correct-debt-repayment-date', $key, $operator, $scope, 'R22-local-verify');
        $service->executeInTx(['operator_scope' => $operator, 'data_scope' => $scope,
            'event_recorder' => $recorder, 'event_execution' => $execution,
            'event_contract' => CashierV3ActionManifest::eventContractFor('correct-debt-repayment-date'),
            'action' => 'correct-debt-repayment-date', 'idempotency_key' => $key,
            'payload' => ['recordType' => 'supplement', 'recordId' => $cases[5][3],
                'field' => 'business_date', 'expectedValue' => '2026-10-01', 'newValue' => '2026-10-03']]);
        throw new RuntimeException('stale business date was accepted');
    } catch (app\services\cashier\v3\CashierV3CommandException $expected) {
        $results[] = ['guard' => 'stale business date denied'];
    } finally {
        Db::rollback();
    }
}
echo json_encode(['persisted' => $persist, 'verified' => $verify, 'operations' => $results],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
