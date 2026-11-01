<?php
namespace app\services\order;

use app\services\cashier\v3\bootstrap\CashierV3Bootstrap;
use app\services\cashier\v3\CashierV3CommandFailureEnvelopeServices;
use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3IdempotencyKeyServices;
use app\services\cashier\v3\CashierV3ResourceScope;
use think\facade\Db;

/** 只适配管理员身份和业务门店；前后端共用动作、事务、导出和查询实现。 */
final class PlatformOrderCenterActionServices
{
    public function dispatch(int $adminId, array $body): array
    {
        $original = $body;
        try {
            $scopes = app()->make(PlatformOrderCenterReadServices::class);
            [$anchor, $dataScope] = $scopes->trustedScopes($adminId);
            $dispatcher = CashierV3Bootstrap::dispatcher();
            $storeId = $this->targetStore($body, $anchor, $dataScope, $dispatcher);
            [$operator, $scope] = $scopes->trustedScopes($adminId, $storeId);
            // 管理员与店员可有相同数字ID，命令回执/会话必须稳定隔离。
            // 仍使用共用 gateway 的标准UUID格式，不建立第二套交易回执或业务逻辑。
            $keys = new CashierV3IdempotencyKeyServices();
            $originalKey = (string)($body['command']['idempotencyKey'] ?? '');
            if ($originalKey !== '') {
                $keys->normalizeIdempotencyKey($originalKey);
                $body['command']['idempotencyKey'] = $this->identityKey('CMD', $adminId, $originalKey);
            }
            $originalResultKey = (string)($body['originalIdempotencyKey'] ?? '');
            if ($originalResultKey !== '') {
                $keys->normalizeIdempotencyKey($originalResultKey);
                $body['originalIdempotencyKey'] = $this->identityKey('CMD', $adminId, $originalResultKey);
            }
            $client = (string)($body['clientSessionId'] ?? '');
            $keys->normalizeClientSessionId($client);
            $body['clientSessionId'] = $this->identityKey('SESSION', $adminId, $client);
            unset($body['stateContextId'], $body['operationStoreId']);
            $result = $dispatcher->dispatch($body, [
                'store_id' => $operator->storeId(), 'operator_id' => $adminId,
                'operator_profile' => $scope->operatorProfile(),
                'client_session_id' => $body['clientSessionId'], 'state_context_id' => '',
            ]);
            if ($originalKey !== '') {
                $result['idempotencyKey'] = $originalKey;
                $result['boundIdempotencyKey'] = $originalKey;
            }
            if ($originalResultKey !== '') $result['boundOriginalIdempotencyKey'] = $originalResultKey;
            if (isset($result['data']['exportTask']['downloadUrl'])) {
                $result['data']['exportTask']['downloadUrl'] = str_replace('/cashierapi/v3/unified-query/exports/',
                    '/adminapi/store/order-center/exports/', $result['data']['exportTask']['downloadUrl']);
            }
            return $result;
        } catch (CashierV3CommandException $e) {
            return (new CashierV3CommandFailureEnvelopeServices())->fromException($original, $e);
        } catch (\app\services\query\UnifiedQueryException $e) {
            return (new CashierV3CommandFailureEnvelopeServices())->fromException($original,
                new CashierV3CommandException($e->getErrorCode(), $e->getMessage()));
        } catch (\Throwable $e) {
            return (new CashierV3CommandFailureEnvelopeServices())->fromThrowable($original, $e);
        }
    }

    /** 同一请求重试映射到同一有效UUID；身份域隔离不改变浏览器请求绑定。 */
    private function identityKey(string $prefix, int $adminId, string $key): string
    {
        $h = hash('sha256', 'platform-order-center:' . $adminId . ':' . $prefix . ':' . $key);
        return $prefix . '-' . substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-5' . substr($h, 13, 3)
            . '-a' . substr($h, 17, 3) . '-' . substr($h, 20, 12);
    }

    /** 业务资源的权威门店优先；浏览器提示只用于选择器，仍由服务端数据范围核验。 */
    private function targetStore(array $body, $anchor, $scope, $dispatcher): int
    {
        if (!empty($body['serviceFactId'])) {
            $id = Db::name('cashier_v3_entitlement_service_fact')->where('tenant_id', $anchor->tenantId())->where('id', (int)$body['serviceFactId'])->value('store_id');
            if ($id) return (int)$id;
        }
        $orderId = (string)($body['orderId'] ?? '');
        $checkout = (string)($body['checkoutRequestId'] ?? '');
        if (strpos($orderId, 'service:') === 0) $checkout = substr($orderId, 8);
        if ($checkout !== '') {
            $id = Db::name('cashier_v3_entitlement_service_fact')->where('tenant_id', $anchor->tenantId())->where('checkout_request_id', $checkout)->value('store_id');
            if ($id) return (int)$id;
        }
        if ($orderId !== '') {
            $id = Db::name('cashier_v3_sales_order')->where('tenant_id', $anchor->tenantId())->where('order_id', $orderId)->value('store_id');
            if ($id) return (int)$id;
        }
        // 充值、退款、赠送与卡操作使用各自权威身份，不能沿用第一家门店。
        // 这里只定位门店；可见范围、可操作状态与锁仍在共用命令中复核。
        $record = (string)($body['recordId'] ?? '');
        $type = (string)($body['recordType'] ?? '');
        $action = (string)($body['action'] ?? '');
        $recharge = (int)($body['rechargeId'] ?? 0);
        if (!$recharge && ($type === 'recharge' || strpos($action, 'recharge') !== false)) {
            $recharge = (int)preg_replace('/^recharge:/', '', $record);
        }
        if ($recharge > 0) {
            $id = Db::name('user_recharge')->where('id', $recharge)->value('store_id');
            if ($id) return (int)$id;
        }
        $lookups = [
            ['/^CSO-/i', 'cashier_v3_sales_order', 'order_id', $record],
            ['/^refund:/', 'cashier_v3_order_lifecycle_operation', 'operation_id', preg_replace('/^refund:/', '', $record)],
            ['/^OLO-/i', 'cashier_v3_order_lifecycle_operation', 'operation_id', $record],
            ['/^v3-sales-supplement:/', 'cashier_v3_debt_repayment', 'repayment_id', preg_replace('/^v3-sales-supplement:/', '', $record)],
            ['/^v3-supplement:/', 'cashier_v3_recharge_debt_repayment', 'repayment_id', preg_replace('/^v3-supplement:/', '', $record)],
            ['/^DRP-/i', 'cashier_v3_debt_repayment', 'repayment_id', $record],
            ['/^RRP-/i', 'cashier_v3_recharge_debt_repayment', 'repayment_id', $record],
            ['/^v3-direct-gift:/', 'cashier_v3_gift_fact', 'gift_fact_id', preg_replace('/^v3-direct-gift:/', '', $record)],
            ['/^card-operation:/', 'cashier_v3_card_operation', 'id', preg_replace('/^card-operation:/', '', $record)],
        ];
        foreach ($lookups as [$pattern, $table, $column, $value]) {
            if (!preg_match($pattern, $record)) continue;
            $id = Db::name($table)->where('tenant_id', $anchor->tenantId())->where($column, $value)->value('store_id');
            if ($id) return (int)$id;
        }
        if ($type === 'service' && $record !== '') {
            $id = Db::name('cashier_v3_entitlement_service_fact')->where('tenant_id', $anchor->tenantId())
                ->where('checkout_request_id', $record)->value('store_id');
            if ($id) return (int)$id;
        }
        if (!empty($body['debtId'])) {
            $id = Db::name('store_debt')->where('id', (int)$body['debtId'])->value('store_id');
            if ($id) return (int)$id;
        }
        foreach ((array)($body['command']['contexts'] ?? []) as $context) {
            if (empty($context['kind']) || empty($context['id'])) continue;
            $resource = $dispatcher->scopeResolver()->resolveFor((string)$context['kind'], (string)$context['id'], $anchor, $scope);
            if ($resource->type() === CashierV3ResourceScope::TYPE_STORE) return (int)$resource->id();
        }
        return (int)($body['operationStoreId'] ?? 0) ?: $anchor->storeId();
    }
}
