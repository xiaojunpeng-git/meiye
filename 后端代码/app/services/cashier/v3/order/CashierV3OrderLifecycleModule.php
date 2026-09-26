<?php

namespace app\services\cashier\v3\order;

use app\services\cashier\v3\CashierV3ActionDispatcher;
use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\cashier\CashierV3CashierWorkspaceServices;
use app\services\cashier\v3\cashier\CashierV3SaleCatalogServices;
use app\services\cashier\v3\checkout\provider\CashierV3MemberBalanceProvider;
use app\services\cashier\v3\registry\CashierV3ContextPolicy;

final class CashierV3OrderLifecycleModule
{
    public static function install(CashierV3ActionDispatcher $dispatcher, CashierV3CashierWorkspaceServices $workspace): void
    {
        $service = new CashierV3OrderLifecycleServices($workspace, new CashierV3SaleCatalogServices());
        $serviceVoid = new CashierV3ServiceRecordVoidServices();
        $orderCenterVoid = new CashierV3OrderCenterVoidServices();
        $serviceCraftsmanAdjustment = new CashierV3ServiceRecordCraftsmanAdjustmentServices();
        $supplementPersonnelAdjustment = new CashierV3SupplementSalespersonAdjustmentServices();
        $dispatcher->versionServices()->registerProvider(
            CashierV3SupplementSalespersonAdjustmentVersionProvider::KIND,
            new CashierV3SupplementSalespersonAdjustmentVersionProvider()
        );
        $dispatcher->versionServices()->registerProvider('sales_order', new CashierV3OrderLifecycleVersionProvider());
        $dispatcher->versionServices()->registerProvider(
            CashierV3ServiceRecordCraftsmanAdjustmentVersionProvider::KIND,
            new CashierV3ServiceRecordCraftsmanAdjustmentVersionProvider()
        );
        foreach (['adjust-sales-order-personnel','update-sales-order-note','refund-sales-order','void-sales-order','reopen-sales-order'] as $action) {
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
            $entry = $service->personnelAdjustmentEntry((array)($scope['payload'] ?? []), $scope['operator_scope'], $scope['data_scope']);
            // The personnel editor is opened through a direct projection rather
            // than the order-center root query. Publish the same authoritative
            // sales-order version here so the following save can build a
            // trusted command context; never fall back to the browser row's
            // stale revision.
            return [
                'data' => ['orderPersonnelAdjustment' => $entry],
                'versions' => [[
                    'kind' => 'sales_order',
                    'id' => (string)$entry['salesOrderId'],
                    'version' => (int)$entry['recordVersion'],
                ]],
                'message' => '订单人员调整资料已读取。',
            ];
        });
        if ($handlers->hasProjection('open-supplement-personnel-adjustment')) throw new \LogicException('supplement personnel adjustment projection duplicate');
        $handlers->registerProjection('open-supplement-personnel-adjustment', static function (array $scope) use ($supplementPersonnelAdjustment): array {
            $entry = $supplementPersonnelAdjustment->entry((array)($scope['payload'] ?? []), $scope['operator_scope'], $scope['data_scope']);
            return ['data' => ['personnelAdjustment' => $entry], 'versions' => [[
                'kind' => CashierV3SupplementSalespersonAdjustmentVersionProvider::KIND,
                'id' => (string)$entry['recordId'], 'version' => (int)$entry['recordVersion'],
            ]], 'message' => '补交销售人调整资料已读取。'];
        });
        if ($handlers->hasCommand('adjust-supplement-personnel') || $dispatcher->policies()->has('adjust-supplement-personnel')) throw new \LogicException('supplement personnel adjustment command duplicate');
        $handlers->registerCommand('adjust-supplement-personnel', static function (array $scope) use ($supplementPersonnelAdjustment): array {
            $result = $supplementPersonnelAdjustment->executeInTx($scope);
            return ['data' => ['supplementPersonnelAdjustment' => $result], 'business_no' => (string)$result['operationNo'], 'touched' => ['supplement_record'], 'message' => (string)$result['message']];
        });
        $supplementPolicy = new CashierV3ContextPolicy('adjust-supplement-personnel', ['debt_repayment'], [], static function (array $payload): array {
            $id = trim((string)($payload['recordId'] ?? $payload['repaymentId'] ?? ''));
            if (strpos($id, ':') !== false) $id = substr($id, strrpos($id, ':') + 1);
            if ($id === '') throw CashierV3CommandException::invalidContext('未找到需要调整的补交记录，请重新打开。', ['reason' => 'supplement_personnel_record_missing']);
            return ['required' => ['debt_repayment'], 'allowed' => [], 'identities' => [['role' => 'supplement_record', 'kind' => 'debt_repayment', 'id' => $id, 'required' => true]], 'required_read_roles' => ['supplement_record'], 'required_touched_roles' => ['supplement_record']];
        }, ['debt_repayment'], ['supplement_record'], ['debt_repayment']);
        $supplementPolicy->configureServerResourceDiscovery([$supplementPersonnelAdjustment, 'discover'], ['supplement_record'], ['debt_repayment']);
        $dispatcher->policies()->register($supplementPolicy);

        $recharge = new CashierV3RechargeOrderLifecycleServices();
        $rechargePersonnel = new CashierV3RechargePersonnelAdjustmentServices();
        $dispatcher->versionServices()->registerProvider(
            CashierV3RechargeOrderLifecycleVersionProvider::KIND,
            new CashierV3RechargeOrderLifecycleVersionProvider()
        );
        foreach (['refund-recharge-order', 'void-recharge-order'] as $action) {
            if ($dispatcher->handlers()->hasCommand($action) || $dispatcher->policies()->has($action)) {
                throw new \LogicException('recharge order lifecycle duplicate action: ' . $action);
            }
            $dispatcher->handlers()->registerCommand($action, static function (array $scope) use ($recharge, $action): array {
                $result = $recharge->executeInTx($action, $scope);
                return ['data' => ['rechargeOrderLifecycle' => $result], 'business_no' => (string)$result['operationNo'],
                    'touched' => (array)($result['touchedRoles'] ?? ['recharge_order']), 'message' => (string)$result['message']];
            });
            $policy = new CashierV3ContextPolicy($action, ['recharge_order', 'member_balance'], [], static function (array $payload) use ($action): array {
                $rechargeId = trim((string)($payload['rechargeId'] ?? ''));
                $memberId = trim((string)($payload['memberId'] ?? ''));
                if (preg_match('/^[1-9][0-9]*$/D', $rechargeId) !== 1 || preg_match('/^[1-9][0-9]*$/D', $memberId) !== 1) {
                    throw CashierV3CommandException::invalidContext('充值订单或会员资料无效，请重新打开订单详情。');
                }
                $touchesBalance = $action === 'void-recharge-order';
                if (!$touchesBalance) {
                    foreach (['principalRefundAmount', 'bonusRefundAmount'] as $field) {
                        $amount = trim((string)($payload[$field] ?? '0'));
                        if (preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/D', $amount) === 1 && bccomp($amount, '0', 2) === 1) {
                            $touchesBalance = true;
                            break;
                        }
                    }
                }
                $touchedRoles = ['recharge_order'];
                if ($touchesBalance) $touchedRoles[] = 'member_balance';
                return ['required' => ['recharge_order', 'member_balance'], 'allowed' => [], 'identities' => [
                    ['role' => 'recharge_order', 'kind' => 'recharge_order', 'id' => $rechargeId, 'required' => true],
                    ['role' => 'member_balance', 'kind' => CashierV3MemberBalanceProvider::KIND, 'id' => $memberId, 'required' => true],
                ], 'required_read_roles' => ['recharge_order', 'member_balance'],
                    'required_touched_roles' => $touchedRoles];
            }, [], ['recharge_order', 'member_balance'], ['recharge_order', 'member_balance']);
            $policy->configureServerResourceDiscovery([$recharge, 'discover'], ['recharge_order', 'member_balance'], ['recharge_order', 'member_balance']);
            $dispatcher->policies()->register($policy);
        }

        // 充值订单销售人沿用统一 PersonnelPerformanceOverlay，但事实来源为
        // recharge 的 sales_performance_allocated，不复用充值主表 staff_id（该字段是操作人）。
        if ($handlers->hasProjection('open-recharge-personnel-adjustment')) {
            throw new \LogicException('recharge personnel adjustment projection duplicate');
        }
        $handlers->registerProjection('open-recharge-personnel-adjustment', static function (array $scope) use ($rechargePersonnel): array {
            $entry = $rechargePersonnel->entry((array)($scope['payload'] ?? []), $scope['operator_scope'], $scope['data_scope']);
            return [
                'data' => ['personnelAdjustment' => $entry],
                'versions' => [[
                    'kind' => CashierV3RechargeOrderLifecycleVersionProvider::KIND,
                    'id' => (string)$entry['recordId'], 'version' => (int)$entry['recordVersion'],
                ]],
                'message' => '充值订单销售人资料已读取。',
            ];
        });
        if ($handlers->hasCommand('adjust-recharge-personnel') || $dispatcher->policies()->has('adjust-recharge-personnel')) {
            throw new \LogicException('recharge personnel adjustment command duplicate');
        }
        $handlers->registerCommand('adjust-recharge-personnel', static function (array $scope) use ($rechargePersonnel): array {
            $result = $rechargePersonnel->executeInTx($scope);
            return [
                'data' => ['personnelAdjustment' => $result],
                'business_no' => (string)$result['operationNo'],
                'touched' => ['recharge_order'], 'message' => (string)$result['message'],
            ];
        });
        $rechargePersonnelPolicy = new CashierV3ContextPolicy(
            'adjust-recharge-personnel', ['recharge_order'], [], static function (array $payload): array {
                $recordId = trim((string)($payload['recordId'] ?? $payload['rechargeId'] ?? ''));
                if (preg_match('/^recharge:([1-9][0-9]*)$/D', $recordId, $match)) $recordId = $match[1];
                if (preg_match('/^[1-9][0-9]*$/D', $recordId) !== 1) {
                    throw CashierV3CommandException::invalidContext('未找到需要修改的充值订单，请重新打开订单。');
                }
                return [
                    'identities' => [['role' => 'recharge_order', 'kind' => CashierV3RechargeOrderLifecycleVersionProvider::KIND, 'id' => $recordId, 'required' => true]],
                    'required_read_roles' => ['recharge_order'], 'required_touched_roles' => ['recharge_order'],
                ];
            }, ['recharge_order'], [], ['recharge_order']
        );
        $rechargePersonnelPolicy->configureServerResourceDiscovery([$rechargePersonnel, 'discover'], ['recharge_order'], ['recharge_order']);
        $dispatcher->policies()->register($rechargePersonnelPolicy);

        if ($dispatcher->handlers()->hasCommand('void-service-record') || $dispatcher->policies()->has('void-service-record')) {
            throw new \LogicException('service record void duplicate action');
        }
        $dispatcher->handlers()->registerCommand('void-service-record', static function (array $scope) use ($serviceVoid): array {
            $result = $serviceVoid->executeInTx('void-service-record', $scope);
            return [
                'data' => ['serviceRecordVoid' => $result],
                'business_no' => (string)$result['operationNo'],
                'touched' => (array)($result['touchedRoles'] ?? ['cashier_workspace']),
                'message' => (string)$result['message'],
            ];
        });
        $serviceVoidPolicy = new CashierV3ContextPolicy(
            'void-service-record',
            [],
            [],
            static function (array $payload): array {
                // 外部命令只允许整单作废；逐条冲销原语仅供整单事务内部调用。
                $requestId = trim((string)($payload['checkoutRequestId'] ?? ''));
                if (preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $requestId) !== 1) {
                    throw CashierV3CommandException::invalidContext('不允许单独作废服务记录，请到消费订单整单作废。', ['reason' => 'service_void_requires_checkout_group']);
                }
                return [
                    'required' => [], 'allowed' => [], 'identities' => [],
                    'required_read_roles' => [], 'required_touched_roles' => [],
                    'allows_empty_contexts' => true,
                ];
            },
            [],
            [],
            [],
            true
        );
        $dispatcher->policies()->register($serviceVoidPolicy);

        foreach (['void-order-center-supplement', 'void-order-center-gift'] as $action) {
            if ($handlers->hasCommand($action) || $dispatcher->policies()->has($action)) {
                throw new \LogicException('order center void duplicate action: ' . $action);
            }
            $dispatcher->handlers()->registerCommand($action, static function (array $scope) use ($orderCenterVoid, $action): array {
                $result = $orderCenterVoid->executeInTx($action, $scope);
                return [
                    'data' => ['orderCenterVoid' => $result],
                    'business_no' => (string)($result['business_no'] ?? ''),
                    'touched' => (array)($result['touched'] ?? ['order_center']),
                    'message' => (string)($result['message'] ?? '作废成功。'),
                ];
            });
            if ($action === 'void-order-center-supplement') {
                // Order-center cancellation is an independent repayment
                // mutation. It must not inherit the cashier workspace
                // version, which is commonly stale while another tab edits
                // the cart. The server discovers and locks the repayment.
                $policy = new CashierV3ContextPolicy($action, [], [], static function (): array {
                    return [
                        'required' => [], 'allowed' => ['debt_repayment'], 'identities' => [],
                        'required_read_roles' => [], 'required_touched_roles' => ['supplement_record'],
                        'allows_empty_contexts' => true, 'allow_empty_server_resource_discovery' => true,
                    ];
                }, ['supplement_record'], ['supplement_record'], ['debt_repayment'], true);
                $policy->configureServerResourceDiscovery([$orderCenterVoid, 'discover'], ['supplement_record'], ['debt_repayment']);
                $dispatcher->policies()->register($policy);
                continue;
            }
            $policy = new CashierV3ContextPolicy($action, ['cashier_workspace'], [], static function (array $payload, array $base): array {
                $workspaceId = trim((string)($base['session']['workspace_id'] ?? ''));
                if ($workspaceId === '') {
                    throw CashierV3CommandException::invalidContext('当前收银工作台会话无效，请刷新页面后重试。', ['reason' => 'order_center_void_workspace_missing']);
                }
                $recordId = trim((string)($payload['recordId'] ?? $payload['id'] ?? ''));
                if ($recordId === '') {
                    throw CashierV3CommandException::invalidContext('未找到需要作废的记录，请重新打开记录。', ['reason' => 'order_center_void_record_missing']);
                }
                return [
                    'identities' => [['role' => 'cashier_workspace', 'kind' => 'cashier_workspace', 'id' => $workspaceId, 'required' => true]],
                    'required_read_roles' => ['cashier_workspace'], 'required_touched_roles' => ['cashier_workspace'],
                ];
            }, ['cashier_workspace'], [], ['cashier_workspace']);
            $dispatcher->policies()->register($policy);
        }

        if ($handlers->hasProjection('open-service-record-craftsman-adjustment')) {
            throw new \LogicException('service record craftsman adjustment projection duplicate');
        }
        $handlers->registerProjection('open-service-record-craftsman-adjustment', static function (array $scope) use ($serviceCraftsmanAdjustment): array {
            $entry = $serviceCraftsmanAdjustment->entry(
                (array)($scope['payload'] ?? []), $scope['operator_scope'], $scope['data_scope']
            );
            return [
                'data' => ['serviceRecordCraftsmanAdjustment' => $entry],
                'versions' => [[
                    'kind' => CashierV3ServiceRecordCraftsmanAdjustmentVersionProvider::KIND,
                    'id' => (string)$entry['serviceFactId'],
                    'version' => (int)$entry['recordVersion'],
                ]],
                'message' => '服务记录手艺人分配已读取。',
            ];
        });
        if ($handlers->hasCommand('adjust-service-record-craftsmen') || $dispatcher->policies()->has('adjust-service-record-craftsmen')) {
            throw new \LogicException('service record craftsman adjustment command duplicate');
        }
        $handlers->registerCommand('adjust-service-record-craftsmen', static function (array $scope) use ($serviceCraftsmanAdjustment): array {
            $result = $serviceCraftsmanAdjustment->executeInTx($scope);
            return [
                'data' => ['serviceRecordCraftsmanAdjustment' => $result],
                'business_no' => (string)$result['operationNo'],
                'touched' => (array)($result['touchedRoles'] ?? ['service_record']),
                'message' => (string)$result['message'],
            ];
        });
        $serviceAdjustmentPolicy = new CashierV3ContextPolicy(
            'adjust-service-record-craftsmen',
            ['service_record'],
            [],
            static function (array $payload): array {
                $serviceFactId = trim((string)($payload['serviceFactId'] ?? $payload['service_fact_id'] ?? ''));
                if (preg_match('/^[1-9][0-9]*$/D', $serviceFactId) !== 1) {
                    throw CashierV3CommandException::invalidContext('未找到需要修改的服务记录，请重新打开记录。');
                }
                return [
                    'identities' => [[
                        'role' => 'service_record', 'kind' => CashierV3ServiceRecordCraftsmanAdjustmentVersionProvider::KIND,
                        'id' => $serviceFactId, 'required' => true,
                    ]],
                    'required_read_roles' => ['service_record'],
                    'required_touched_roles' => ['service_record'],
                ];
            },
            ['service_record'],
            [],
            []
        );
        $dispatcher->policies()->register($serviceAdjustmentPolicy);
    }
}
