<?php

namespace app\services\cashier\v3\order;

use app\services\cashier\v3\CashierV3ActionDispatcher;
use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\cashier\CashierV3CashierWorkspaceServices;
use app\services\cashier\v3\cashier\CashierV3SaleCatalogServices;
use app\services\cashier\v3\registry\CashierV3ContextPolicy;

final class CashierV3OrderLifecycleModule
{
    public static function install(CashierV3ActionDispatcher $dispatcher, CashierV3CashierWorkspaceServices $workspace): void
    {
        $service = new CashierV3OrderLifecycleServices($workspace, new CashierV3SaleCatalogServices());
        $dispatcher->versionServices()->registerProvider('sales_order', new CashierV3OrderLifecycleVersionProvider());
        foreach (['adjust-sales-order-personnel','refund-sales-order','void-sales-order','reopen-sales-order'] as $action) {
            $isReopen = $action === 'reopen-sales-order';
            $requiredKinds = $isReopen ? ['sales_order', 'cashier_workspace'] : ['sales_order'];
            if ($dispatcher->handlers()->hasCommand($action) || $dispatcher->policies()->has($action)) throw new \LogicException('order lifecycle duplicate action: ' . $action);
            $dispatcher->handlers()->registerCommand($action, static function (array $scope) use ($service, $action, $isReopen): array {
                $result = $service->executeInTx($action, $scope);
                return ['data' => ['orderLifecycle' => $result], 'business_no' => (string)$result['operationNo'], 'touched' => $isReopen ? ['sales_order', 'cashier_workspace'] : (array)($result['touchedRoles'] ?? ['sales_order']), 'message' => (string)$result['message']];
            });
            $policy = new CashierV3ContextPolicy($action, $requiredKinds, [], static function (array $payload, array $base) use ($isReopen): array {
                $orderId = trim((string)($payload['orderId'] ?? $payload['salesOrderId'] ?? ''));
                if ($orderId === '') {
                    throw CashierV3CommandException::invalidContext('未找到需要操作的销售订单，请重新打开订单详情。', ['reason' => 'sales_order_identity_missing']);
                }
                $identities = [[
                    'role' => 'sales_order',
                    'kind' => 'sales_order',
                    'id' => $orderId,
                    'required' => true,
                ]];
                $roles = ['sales_order'];
                if ($isReopen) {
                    $workspaceId = trim((string)($base['session']['workspace_id'] ?? ''));
                    if ($workspaceId === '') {
                        throw CashierV3CommandException::invalidContext('当前收银工作台会话无效，请刷新页面后再重开订单。', ['reason' => 'reopen_workspace_identity_missing']);
                    }
                    $identities[] = [
                        'role' => 'cashier_workspace',
                        'kind' => 'cashier_workspace',
                        'id' => $workspaceId,
                        'required' => true,
                    ];
                    $roles[] = 'cashier_workspace';
                }
                return [
                    'required' => $roles,
                    'allowed' => [],
                    'identities' => $identities,
                    'required_read_roles' => $roles,
                    'required_touched_roles' => $roles,
                ];
            }, $requiredKinds, $requiredKinds, $requiredKinds);
            $policy->configureServerResourceDiscovery([$service, 'discover'], ['sales_order', 'member_balance'], ['sales_order', 'member_balance']);
            $dispatcher->policies()->register($policy);
        }
        foreach (['open-order-debt-settlements','open-debt-settlements'] as $action) {
            if ($dispatcher->handlers()->hasProjection($action)) throw new \LogicException('order debt entry duplicate projection: ' . $action);
            $dispatcher->handlers()->registerProjection($action, static function (array $scope) use ($service): array { return ['data' => ['orderDebtRepayment' => $service->debtRepaymentEntry((array)($scope['payload'] ?? []), $scope['operator_scope'], $scope['data_scope'])], 'message' => '订单欠款补交资料已读取。']; });
        }
        $handlers = $dispatcher->handlers();
        if ($handlers->hasProjection('open-sales-order-personnel-adjustment')) throw new \LogicException('order personnel adjustment projection duplicate');
        $handlers->registerProjection('open-sales-order-personnel-adjustment', static function (array $scope) use ($service): array {
            return ['data' => ['orderPersonnelAdjustment' => $service->personnelAdjustmentEntry((array)($scope['payload'] ?? []), $scope['operator_scope'], $scope['data_scope'])], 'message' => '订单人员调整资料已读取。'];
        });

    }
}
