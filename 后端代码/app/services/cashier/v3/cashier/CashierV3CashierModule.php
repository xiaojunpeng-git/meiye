<?php

namespace app\services\cashier\v3\cashier;

use app\services\cashier\v3\CashierV3ActionDispatcher;
use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\checkout\CashierV3EntitlementCompletionAuthorityAdapter;
use app\services\cashier\v3\checkout\persistence\ThinkPhpCashierV3EntitlementCompletionWriter;
use app\services\cashier\v3\checkout\provider\CashierV3EmployeeTypeAuthority;
use app\services\cashier\v3\checkout\provider\CashierV3EntitlementDebtGuardProvider;
use app\services\cashier\v3\checkout\provider\CashierV3EntitlementOccupationContributorVersionProvider;
use app\services\cashier\v3\checkout\provider\CashierV3EntitlementOccupationGuardVersionProvider;
use app\services\cashier\v3\checkout\provider\CashierV3EntitlementOccupationProvider;
use app\services\cashier\v3\checkout\provider\CashierV3InventoryCompletionGatewayAdapter;
use app\services\cashier\v3\checkout\provider\CashierV3InventoryResourceVersionProvider;
use app\services\cashier\v3\checkout\provider\CashierV3MemberBalanceProvider;
use app\services\cashier\v3\checkout\provider\CashierV3PerformanceRuleProvider;
use app\services\cashier\v3\checkout\provider\CashierV3StaffProfileProvider;
use app\services\cashier\v3\card\CashierV3CustomCardConfigurationServices;
use app\services\cashier\v3\card\CashierV3CustomCardConfigurationVersionProvider;
use app\services\cashier\v3\card\CashierV3CardRuleEntitlementAuthorityServices;
use app\services\cashier\v3\card\CashierV3CardOperationCheckoutSettlementServices;
use app\services\cashier\v3\projection\CashierV3RootDomainAssembler;
use app\services\cashier\v3\registry\CashierV3ContextPolicy;
use app\services\cashier\v3\service\CashierV3ServiceOrderOccupationAuthorityProvider;
use app\services\cashier\v3\service\ThinkPhpCashierV3EntitlementCompletionOccupationWriter;
use app\services\cashier\v3\service\ThinkPhpCashierV3ServiceOrderRepository;
use app\services\cashier\v3\settlement\CashierV3CheckoutPreparationServices;
use app\services\cashier\v3\settlement\CashierV3CheckoutPaymentDraftServices;
use app\services\cashier\v3\settlement\CashierV3CheckoutBalanceAuthorityDiscovery;
use app\services\cashier\v3\settlement\CashierV3CheckoutBalanceDraftServices;
use app\services\cashier\v3\settlement\CashierV3CheckoutProjectionServices;
use app\services\cashier\v3\settlement\CashierV3CheckoutResultQueryServices;
use app\services\cashier\v3\settlement\CashierV3CheckoutSubmissionOrchestrator;
use app\services\cashier\v3\settlement\CashierV3CheckoutSubmissionPreparationServices;
use app\services\cashier\v3\settlement\CashierV3CheckoutSubmissionResourceDiscoveryComposite;
use app\services\cashier\v3\settlement\ThinkPhpCashierV3CheckoutResultReadRepository;
use app\services\cashier\v3\settlement\ThinkPhpCashierV3CheckoutRequestRepository;
use app\services\cashier\v3\settlement\ThinkPhpCashierV3CheckoutResourcePlanRepository;
use app\services\cashier\v3\settlement\ThinkPhpCashierV3CheckoutSubmissionExecutionPort;
use think\facade\Db;

/**
 * C2 A1：权威权益查询与服务端购物车草稿安装器。
 */
final class CashierV3CashierModule
{
    public static function install(
        CashierV3ActionDispatcher $dispatcher,
        CashierV3RootDomainAssembler $assembler = null,
        CashierV3SaleCatalogServices $saleCatalog = null
    ): CashierV3CashierWorkspaceServices
    {
        $versionServices = $dispatcher->versionServices();
        if ($versionServices === null) {
            throw new \LogicException('C2 cashier module: version services missing');
        }
        $readiness = new CashierV3CashierReadinessGuard();
        $workspace = new CashierV3CashierWorkspaceServices($readiness);
        $cardOperationSettlements = new CashierV3CardOperationCheckoutSettlementServices();
        $saleCatalog = $saleCatalog ?: new CashierV3SaleCatalogServices(null, $readiness);
        $saleCatalogProvider = new CashierV3SaleCatalogResourceVersionProvider($readiness);
        foreach (CashierV3SaleCatalogResourceVersionProvider::KINDS as $kind) {
            $versionServices->registerProvider($kind, $saleCatalogProvider);
        }
        $customCardConfigurations = new CashierV3CustomCardConfigurationVersionProvider();
        $versionServices->registerProvider(
            CashierV3CustomCardConfigurationVersionProvider::KIND,
            $customCardConfigurations
        );
        $customCards = new CashierV3CustomCardConfigurationServices($saleCatalog);
        $cardRules = new CashierV3CardRuleEntitlementAuthorityServices();
        $provider = new CashierV3EntitlementResourceVersionProvider($readiness, $cardRules);
        foreach (CashierV3EntitlementResourceVersionProvider::KINDS as $kind) {
            $versionServices->registerProvider($kind, $provider);
        }
        $serviceOrderRepository = new ThinkPhpCashierV3ServiceOrderRepository();
        $staffProfiles = new CashierV3StaffProfileProvider(
            new CashierV3EmployeeTypeAuthority()
        );
        $performanceRules = new CashierV3PerformanceRuleProvider();
        $debtGuards = new CashierV3EntitlementDebtGuardProvider();
        $occupationGuards = new CashierV3EntitlementOccupationGuardVersionProvider(
            $serviceOrderRepository
        );
        $occupationContributors = new CashierV3EntitlementOccupationContributorVersionProvider();
        $inventoryVersions = new CashierV3InventoryResourceVersionProvider();
        $versionServices->registerProvider(CashierV3StaffProfileProvider::KIND, $staffProfiles);
        $versionServices->registerProvider(CashierV3PerformanceRuleProvider::KIND, $performanceRules);
        $versionServices->registerProvider(CashierV3EntitlementDebtGuardProvider::KIND, $debtGuards);
        $versionServices->registerProvider(
            CashierV3EntitlementOccupationGuardVersionProvider::KIND,
            $occupationGuards
        );
        foreach (CashierV3EntitlementOccupationContributorVersionProvider::KINDS as $kind) {
            $versionServices->registerProvider($kind, $occupationContributors);
        }
        foreach (CashierV3InventoryResourceVersionProvider::KINDS as $kind) {
            $versionServices->registerProvider($kind, $inventoryVersions);
        }
        $memberBalances = new CashierV3MemberBalanceProvider();
        $versionServices->registerProvider(CashierV3MemberBalanceProvider::KIND, $memberBalances);
        $inventoryCompletion = new CashierV3InventoryCompletionGatewayAdapter(
            null,
            null,
            null,
            $inventoryVersions
        );
        $entitlementAuthority = new CashierV3EntitlementCompletionAuthorityAdapter(
            $workspace,
            $staffProfiles,
            $performanceRules,
            $debtGuards,
            new CashierV3EntitlementOccupationProvider(
                new CashierV3ServiceOrderOccupationAuthorityProvider($serviceOrderRepository)
            ),
            $inventoryCompletion,
            $occupationGuards,
            $occupationContributors,
            null,
            $cardRules
        );
        $entitlementWriter = new ThinkPhpCashierV3EntitlementCompletionWriter(
            new ThinkPhpCashierV3EntitlementCompletionOccupationWriter(
                $serviceOrderRepository
            ),
            $cardRules
        );
        $projection = new CashierV3EntitlementProjectionServices(
            $readiness,
            $workspace,
            $provider,
            $versionServices,
            $cardRules
        );
        $resourcePlans = new ThinkPhpCashierV3CheckoutResourcePlanRepository();
        $checkoutRequests = new ThinkPhpCashierV3CheckoutRequestRepository($resourcePlans);
        $checkoutPreparation = new CashierV3CheckoutPreparationServices(
            $workspace,
            $saleCatalog,
            $projection,
            $checkoutRequests
        );
        $submissionDiscovery = new CashierV3CheckoutSubmissionResourceDiscoveryComposite(
            [$checkoutPreparation, 'discover'],
            [$entitlementAuthority, 'discover'],
            [new CashierV3CheckoutBalanceAuthorityDiscovery($memberBalances), 'discover']
        );
        $paymentDrafts = new CashierV3CheckoutPaymentDraftServices($checkoutRequests);
        $balanceDrafts = new CashierV3CheckoutBalanceDraftServices(
            $checkoutRequests,
            null,
            $memberBalances
        );
        $submissionPreparation = new CashierV3CheckoutSubmissionPreparationServices(
            $checkoutRequests,
            null,
            $resourcePlans
        );
        $checkoutExecutionPort = new ThinkPhpCashierV3CheckoutSubmissionExecutionPort(
            $workspace,
            $entitlementAuthority,
            null,
            $checkoutRequests,
            null,
            null,
            null,
            $inventoryCompletion,
            $entitlementWriter,
            '',
            null,
            new \app\services\cashier\v3\checkout\provider\CashierV3MemberBalanceWriterAdapter(
                $memberBalances
            )
        );
        $checkoutSubmission = new CashierV3CheckoutSubmissionOrchestrator(
            $checkoutExecutionPort
        );
        $checkoutResultReads = new ThinkPhpCashierV3CheckoutResultReadRepository();
        $checkoutResultQuery = new CashierV3CheckoutResultQueryServices($checkoutResultReads);
        $checkoutRootProjection = new CashierV3CheckoutProjectionServices(
            $checkoutRequests,
            $checkoutResultReads
        );
        $memberDebtProjection = new CashierV3MemberDebtProjectionServices();

        $handlers = $dispatcher->handlers();
        if ($handlers->hasProjection('open-member-debt-repayment')) {
            throw new \LogicException('C2 cashier module: member debt projection duplicate handler');
        }
        $handlers->registerProjection('open-member-debt-repayment', function (array $scope) use ($memberDebtProjection, $provider): array {
            $payload = is_array($scope['payload'] ?? null) ? $scope['payload'] : [];
            $memberId = (int)($payload['memberId'] ?? $payload['member_id'] ?? 0);
            return Db::transaction(function () use ($memberDebtProjection, $provider, $scope, $memberId): array {
                $snapshot = $memberDebtProjection->read(
                    $memberId,
                    $scope['operator_scope'],
                    $scope['data_scope']
                );
                // 补交命令需要 member + member_balance。欠款页必须公开二者的
                // 同一时点版本，否则前端会在发送前 fail-closed。
                $memberVersion = $provider->synchronizeProjectionVersion(
                    'member',
                    (string)$memberId,
                    $scope['operator_scope'],
                    $scope['data_scope']
                );
                $versions = [
                    [
                        'kind' => 'member',
                        'id' => (string)$memberId,
                        'version' => $memberVersion,
                    ],
                    [
                        'kind' => 'member_balance',
                        'id' => (string)$memberId,
                        'version' => (int)$snapshot['balanceVersion'],
                    ],
                ];
                foreach ((array)($snapshot['records'] ?? []) as $record) {
                    $debtId = (string)($record['debtId'] ?? $record['id'] ?? '');
                    $revision = (int)($record['recordVersion'] ?? $record['revision'] ?? 0);
                    if ($debtId !== '' && $revision > 0) {
                        $versions[] = [
                            'kind' => 'debt_record',
                            'id' => $debtId,
                            'version' => $revision,
                        ];
                    }
                }
                return [
                    'data' => ['debtSnapshot' => $snapshot],
                    'versions' => $versions,
                    'message' => '会员欠款已重新读取。',
                ];
            });
        });
        if ($handlers->hasCommand('choose-catalog-item')) {
            throw new \LogicException('C2 cashier module: choose-catalog-item duplicate handler');
        }
        $handlers->registerCommand('choose-catalog-item', function (array $scope) use ($workspace, $saleCatalog): array {
            $payload = is_array($scope['payload'] ?? null) ? $scope['payload'] : [];
            $workspaceId = self::workspaceContextId((array)($scope['contexts'] ?? []));
            $lockedDraft = $workspace->lockForSaleMutationInTx(
                $workspaceId,
                (string)($scope['state_context_id'] ?? ''),
                $scope['operator_scope']
            );
            $line = $saleCatalog->selectSaleLineAfterGatewayLocksInTx(
                $payload['itemId'] ?? null,
                (string)($scope['idempotency_key'] ?? ''),
                (array)($scope['contexts'] ?? []),
                $scope['operator_scope'],
                $scope['data_scope']
            );
            $workspace->assertCardSaleMemberInTx($lockedDraft, $line);
            $draft = $workspace->appendSaleLineInTx(
                $workspaceId,
                (string)($scope['state_context_id'] ?? ''),
                $scope['operator_scope'],
                $line
            );
            return [
                'data' => ['cashierDraft' => $draft],
                'touched' => ['cashier_workspace'],
                'message' => '商品已加入本次购物车。',
            ];
        });

        if ($handlers->hasCommand('create-custom-card-configuration')) {
            throw new \LogicException('C2 cashier module: create custom-card configuration duplicate handler');
        }
        $handlers->registerCommand('create-custom-card-configuration', function (array $scope) use ($workspace, $customCards): array {
            $payload = is_array($scope['payload'] ?? null) ? $scope['payload'] : [];
            $workspaceId = self::workspaceContextId((array)($scope['contexts'] ?? []));
            $workspace->lockForSaleMutationInTx(
                $workspaceId,
                (string)($scope['state_context_id'] ?? ''),
                $scope['operator_scope']
            );
            $line = $customCards->createSaleLineAfterGatewayLocksInTx(
                $payload,
                $workspaceId,
                (string)($scope['state_context_id'] ?? ''),
                (string)($scope['idempotency_key'] ?? ''),
                (array)($scope['contexts'] ?? []),
                $scope['operator_scope'],
                $scope['data_scope']
            );
            $draft = $workspace->appendSaleLineInTx(
                $workspaceId,
                (string)($scope['state_context_id'] ?? ''),
                $scope['operator_scope'],
                $line
            );
            return [
                'data' => ['cashierDraft' => $draft],
                'touched' => ['cashier_workspace'],
                'message' => '定制卡已加入本次购物车。',
            ];
        });

        if ($handlers->hasProjection('open-add-card-service-project')) {
            throw new \LogicException('C2 cashier module: open-add-card-service-project duplicate handler');
        }
        $handlers->registerProjection('open-add-card-service-project', function (array $scope) use ($projection): array {
            $pack = $projection->openSelector(
                is_array($scope['payload'] ?? null) ? $scope['payload'] : [],
                (string)($scope['state_context_id'] ?? ''),
                $scope['operator_scope'],
                $scope['data_scope']
            );
            return [
                'data' => ['entitlementSelector' => $pack['entitlementSelector']],
                'versions' => $pack['versions'],
                'message' => '会员权益已重新读取。',
            ];
        });

        if ($handlers->hasCommand('add-checkout-entitlement-lines')) {
            throw new \LogicException('C2 cashier module: add-checkout-entitlement-lines duplicate handler');
        }
        $handlers->registerCommand('add-checkout-entitlement-lines', function (array $scope) use ($workspace, $projection): array {
            $payload = is_array($scope['payload'] ?? null) ? $scope['payload'] : [];
            $workspaceId = self::workspaceContextId((array)($scope['contexts'] ?? []));
            $memberId = (int)($payload['memberId'] ?? 0);
            self::entitlementAppendIntent($payload);
            $operatorScope = $scope['operator_scope'];
            $stateContextId = (string)($scope['state_context_id'] ?? '');
            $workspace->assertSelectedMemberInTx(
                $workspaceId,
                $stateContextId,
                $operatorScope,
                $memberId
            );
            $lines = $projection->validateSelectedLinesInTx(
                $payload,
                (array)($scope['contexts'] ?? []),
                $stateContextId,
                $workspaceId,
                $operatorScope
            );
            $draft = $workspace->appendEntitlementLinesInTx(
                $workspaceId,
                $stateContextId,
                $operatorScope,
                $memberId,
                $lines
            );
            return [
                'data' => ['cashierDraft' => $draft],
                'touched' => ['cashier_workspace'],
                'message' => '卡内项目已加入本次购物车。',
            ];
        });

        if ($handlers->hasCommand('remove-cart-line')) {
            throw new \LogicException('C2 cashier module: remove cart line duplicate handler');
        }
        $handlers->registerCommand('remove-cart-line', function (array $scope) use ($workspace, $cardOperationSettlements): array {
            $payload = is_array($scope['payload'] ?? null) ? $scope['payload'] : [];
            $draft = $workspace->removeLineInTx(
                self::workspaceContextId((array)($scope['contexts'] ?? [])),
                (string)($scope['state_context_id'] ?? ''),
                $scope['operator_scope'],
                self::lineKey($payload['lineId'] ?? $payload['line_id'] ?? null),
                static function (array $line) use ($scope, $cardOperationSettlements): void {
                    $cardOperationSettlements->cancelForRemovedWorkspaceLineInTx(
                        $line,
                        $scope['operator_scope'],
                        $scope['data_scope']
                    );
                }
            );
            return [
                'data' => ['cashierDraft' => $draft],
                'touched' => ['cashier_workspace'],
                'message' => '购物车项目已删除。',
            ];
        });

        if ($handlers->hasCommand('change-cart-line-quantity')) {
            throw new \LogicException('C2 cashier module: change cart line quantity duplicate handler');
        }
        $handlers->registerCommand('change-cart-line-quantity', function (array $scope) use ($workspace, $projection, $saleCatalog): array {
            $payload = is_array($scope['payload'] ?? null) ? $scope['payload'] : [];
            $contexts = (array)($scope['contexts'] ?? []);
            $draft = $workspace->changeLineQuantityInTx(
                self::workspaceContextId($contexts),
                (string)($scope['state_context_id'] ?? ''),
                $scope['operator_scope'],
                self::lineKey($payload['lineId'] ?? $payload['line_id'] ?? null),
                self::quantityDelta($payload['delta'] ?? null),
                function (array $line, int $quantity, int $aggregateQuantity) use ($projection, $contexts, $scope): void {
                    $projection->assertDraftLineQuantityInTx(
                        $line,
                        $quantity,
                        $aggregateQuantity,
                        $contexts,
                        $scope['operator_scope']
                    );
                },
                function (array $line, int $quantity) use ($saleCatalog, $scope, $contexts): void {
                    $saleCatalog->assertStoredSaleQuantityAfterGatewayLocksInTx(
                        $line,
                        $quantity,
                        $contexts,
                        $scope['operator_scope'],
                        $scope['data_scope']
                    );
                }
            );
            return [
                'data' => ['cashierDraft' => $draft],
                'touched' => ['cashier_workspace'],
                'message' => '购物车数量已更新。',
            ];
        });

        if ($handlers->hasCommand('update-cart-line-service-settings')) {
            throw new \LogicException('C2 cashier module: update cart line service settings duplicate handler');
        }
        $handlers->registerCommand('update-cart-line-service-settings', function (array $scope) use ($workspace): array {
            $payload = is_array($scope['payload'] ?? null) ? $scope['payload'] : [];
            $draft = $workspace->updateLineServiceSettingsInTx(
                self::workspaceContextId((array)($scope['contexts'] ?? [])),
                (string)($scope['state_context_id'] ?? ''),
                $scope['operator_scope'],
                self::lineKey($payload['lineId'] ?? null),
                $payload
            );
            return [
                'data' => ['cashierDraft' => $draft],
                'touched' => ['cashier_workspace'],
                'message' => '购物车服务设置已更新。',
            ];
        });

        if ($handlers->hasCommand('prepare-checkout')) {
            throw new \LogicException('C2 cashier module: prepare-checkout duplicate handler');
        }
        $handlers->registerCommand('prepare-checkout', function (array $scope) use ($checkoutPreparation): array {
            $prepared = $checkoutPreparation->prepareInTx($scope);
                return [
                    'data' => ['checkoutPreparation' => $prepared],
                    'business_no' => (string)$prepared['checkoutRequestId'],
                    'touched' => ['cashier_workspace'],
                    // 结账请求创建后必须以同一工作台的完整权威投影接续。
                    // 不能让页面拿着创建前的 checkout_request 版本继续编辑收款。
                    'return_root_state' => true,
                    'message' => '结账信息已准备完成。',
                ];
            });

        foreach (['add-payment-method', 'update-payment-line', 'remove-payment-line'] as $action) {
            if ($handlers->hasCommand($action)) {
                throw new \LogicException('C2 cashier module: checkout payment draft handler duplicate');
            }
            $handlers->registerCommand($action, function (array $scope) use ($paymentDrafts, $action): array {
                $edited = $paymentDrafts->mutateInTx($action, $scope);
                return [
                    'data' => ['checkoutDraftEdit' => array_merge($edited, [
                        '_checkoutProjectionRequestId' => (string)$edited['checkoutRequestId'],
                    ])],
                    'business_no' => (string)$edited['checkoutRequestId'],
                    'touched' => ['cashier_workspace', 'checkout_request'],
                    // 只回读当前结账草稿投影；Dispatcher 在事务提交后补齐
                    // 新版本。不重建全量商品目录或其他工作台分区。
                    'message' => (string)($edited['message'] ?? '收款明细已更新。'),
                ];
            });
        }

        foreach (['apply-balance-payment', 'remove-balance-payment', 'update-balance-payment'] as $action) {
            if ($handlers->hasCommand($action)) {
                throw new \LogicException('C2 cashier module: checkout balance draft handler duplicate');
            }
            $handlers->registerCommand($action, function (array $scope) use ($balanceDrafts, $action): array {
                $edited = $balanceDrafts->mutateInTx($action, $scope);
                return [
                    'data' => ['checkoutDraftEdit' => array_merge($edited, [
                        '_checkoutProjectionRequestId' => (string)$edited['checkoutRequestId'],
                    ])],
                    'business_no' => (string)$edited['checkoutRequestId'],
                    // 草稿只保存“本单计划使用余额”，实际余额账务变更由
                    // submit-checkout 在最终资源锁与成功终态事务中完成。
                    'touched' => ['cashier_workspace', 'checkout_request'],
                    // 同外部收款草稿，事务提交后仅回读同一结账单的权威版本。
                    'message' => (string)$edited['message'],
                ];
            });
        }

        if ($handlers->hasCommand('return-to-payment-edit')) {
            throw new \LogicException('C2 cashier module: checkout payment-edit recovery handler duplicate');
        }
        $handlers->registerCommand('return-to-payment-edit', function (array $scope) use ($balanceDrafts): array {
            $recovered = $balanceDrafts->returnToPaymentEditInTx($scope);
            return [
                'data' => ['checkoutBalanceRecovery' => $recovered],
                'business_no' => (string)$recovered['checkoutRequestId'],
                // The command only rewrites an editable draft after the final
                // balance lock rejected submission. No settlement facts exist.
                'touched' => ['cashier_workspace', 'checkout_request'],
                'return_root_state' => true,
                'message' => (string)$recovered['message'],
            ];
        });

        if ($handlers->hasCommand('prepare-checkout-submission')) {
            throw new \LogicException('C2 cashier module: submission preparation handler duplicate');
        }
        $handlers->registerCommand('prepare-checkout-submission', function (array $scope) use ($submissionPreparation): array {
            $prepared = $submissionPreparation->prepareInTx($scope);
            return [
                'data' => ['checkoutSubmissionPreparation' => $prepared],
                'business_no' => (string)$prepared['checkoutRequestId'],
                'touched' => ['cashier_workspace', 'checkout_request'],
                // 最终提交前的校验同样会推进结账请求版本；返回完整状态保证
                // submit-checkout 只使用本次校验后的资源版本和令牌。
                'return_root_state' => true,
                'message' => (string)$prepared['message'],
            ];
        });

        if ($handlers->hasCommand('submit-checkout')) {
            throw new \LogicException('C2 cashier module: submit checkout handler duplicate');
        }
        $handlers->registerCommand('submit-checkout', function (array $scope) use ($checkoutSubmission): array {
            $submitted = $checkoutSubmission->submitInTx($scope);
            $composition = (string)($submitted['composition'] ?? '');
            $salesOrder = is_array($submitted['salesOrder'] ?? null)
                ? $submitted['salesOrder']
                : [];
            $entitlementCompletion = is_array($submitted['entitlementCompletion'] ?? null)
                ? $submitted['entitlementCompletion']
                : [];
            $businessNo = $composition === 'entitlement_only'
                ? (string)($entitlementCompletion['receiptId'] ?? '')
                : (string)($salesOrder['orderNo'] ?? '');
            if ($businessNo === '') {
                throw new CashierV3CommandException(
                    CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                    '结账结果不完整，本次操作已回滚，请刷新后重试。',
                    CashierV3ResultCode::STATUS_FAILED
                );
            }
            $message = $composition === 'entitlement_only'
                ? '权益使用成功，项目核销已完成。'
                : ($composition === 'mixed'
                    ? '收款及权益使用成功，销售订单已完成。'
                    : '收款成功，销售订单已完成。');
            $touched = ['cashier_workspace', 'checkout_request'];
            foreach ((array)($scope['contexts'] ?? []) as $context) {
                if (in_array('checkout_member_balance', (array)($context['roles'] ?? []), true)) {
                    $touched[] = 'checkout_member_balance';
                    break;
                }
            }
            if (is_array($submitted['hangOrder'] ?? null)) {
                $touched[] = 'hang_order';
            }
            return [
                'data' => [
                    'checkoutSubmission' => $submitted,
                    'cashierDraft' => $submitted['cashierDraft'],
                ],
                'business_no' => $businessNo,
                'touched' => $touched,
                'message' => $message,
            ];
        });

        if ($handlers->hasProjection('query-checkout-result')) {
            throw new \LogicException('C2 cashier module: checkout result query handler duplicate');
        }
        $handlers->registerProjection('query-checkout-result', function (array $scope) use ($checkoutResultQuery): array {
            $result = $checkoutResultQuery->query(
                is_array($scope['payload'] ?? null) ? $scope['payload'] : [],
                $scope['operator_scope'],
                $scope['data_scope']
            );
            return [
                'data' => ['checkoutResult' => $result],
                'message' => (string)($result['message'] ?? '结账结果已查询。'),
                'return_root_state' => (string)($result['status'] ?? '')
                    === CashierV3ResultCode::STATUS_SUCCESS,
            ];
        });

        if ($dispatcher->policies()->has('add-checkout-entitlement-lines')) {
            throw new \LogicException('C2 cashier module: add entitlement context policy duplicate');
        }
        $dispatcher->policies()->register(new CashierV3ContextPolicy(
            'add-checkout-entitlement-lines',
            ['cashier_workspace'],
            [],
            function (array $payload, array $base) use ($readiness): array {
                // 每个请求在 action 入口做一次 C2/旧权威结构检查；provider 不做 N 次元数据查询。
                $readiness->assertReady();
                $memberId = self::canonicalPositiveId($payload['memberId'] ?? null, 'memberId');
                $lines = isset($payload['lines']) && is_array($payload['lines']) ? $payload['lines'] : [];
                self::entitlementAppendIntent($payload);
                if (count($lines) !== 1) {
                    throw new CashierV3CommandException(
                        CashierV3ResultCode::ENTITLEMENT_LINE_INVALID,
                        '请选择有效的卡内项目后再加入购物车。',
                        CashierV3ResultCode::STATUS_FAILED
                    );
                }
                $workspaceId = trim((string)($base['session']['workspace_id'] ?? ''));
                if ($workspaceId === '') {
                    throw new CashierV3CommandException(
                        CashierV3ResultCode::CLIENT_SESSION_REQUIRED,
                        '当前收银工作台会话无效，请刷新页面后重试。'
                    );
                }
                $identities = [[
                    'role' => 'member',
                    'kind' => 'member',
                    'id' => (string)$memberId,
                    'required' => true,
                ]];
                $seen = [];
                foreach ($lines as $line) {
                    if (!is_array($line)) {
                        throw self::invalidLine('lines');
                    }
                    $holderId = self::canonicalPositiveId(
                        $line['entitlementInstanceId'] ?? null,
                        'entitlementInstanceId'
                    );
                    $detailId = self::canonicalPositiveId(
                        $line['entitlementSourceDetailId'] ?? null,
                        'entitlementSourceDetailId'
                    );
                    $key = $holderId . ':' . $detailId;
                    if (isset($seen[$key])) {
                        throw self::invalidLine('duplicate_entitlement_line');
                    }
                    $seen[$key] = true;
                    $identities[] = [
                        'role' => 'member_benefit_pool:' . $detailId,
                        'kind' => 'member_benefit_pool',
                        'id' => (string)$detailId,
                        'required' => true,
                    ];
                    $identities[] = [
                        'role' => 'card_holder:' . $holderId,
                        'kind' => 'card_holder',
                        'id' => (string)$holderId,
                        'required' => true,
                    ];
                }
                $identities[] = [
                    'role' => 'cashier_workspace',
                    'kind' => 'cashier_workspace',
                    'id' => $workspaceId,
                    'required' => true,
                ];
                return [
                    'required' => ['member', 'member_benefit_pool', 'card_holder', 'cashier_workspace'],
                    'allowed' => [],
                    'identities' => $identities,
                    'required_read_roles' => array_values(array_unique(array_map(static function (array $identity): string {
                        return (string)$identity['role'];
                    }, $identities))),
                    'required_touched_roles' => ['cashier_workspace'],
                ];
            },
            ['cashier_workspace'],
            ['member', 'member_benefit_pool', 'card_holder', 'cashier_workspace'],
            ['member', 'member_benefit_pool', 'card_holder']
        ));

        self::registerChooseCatalogItemPolicy($dispatcher, $readiness, $saleCatalog);
        self::registerCreateCustomCardConfigurationPolicy($dispatcher, $readiness, $customCards);
        self::registerRemoveLinePolicy($dispatcher);
        self::registerChangeQuantityPolicy($dispatcher, $readiness, $saleCatalog);
        self::registerUpdateServiceSettingsPolicy($dispatcher, $readiness);
        self::registerPrepareCheckoutPolicy($dispatcher, $checkoutPreparation);
        self::registerSubmissionPreparationPolicy(
            $dispatcher,
            $submissionDiscovery
        );
        self::registerPaymentDraftPolicies($dispatcher);
        self::registerBalanceDraftPolicies(
            $dispatcher,
            new CashierV3CheckoutBalanceAuthorityDiscovery($memberBalances)
        );
        self::registerReturnToPaymentEditPolicy($dispatcher);
        self::registerSubmitCheckoutPolicy($dispatcher);

        if ($assembler !== null) {
            $assembler->registerPartitionProvider(new CashierV3CashierPartitionProvider(
                $workspace,
                $saleCatalog,
                null,
                $checkoutRootProjection
            ));
        }

        return $workspace;
    }

    private static function registerPrepareCheckoutPolicy(
        CashierV3ActionDispatcher $dispatcher,
        CashierV3CheckoutPreparationServices $preparation
    ): void {
        if ($dispatcher->policies()->has('prepare-checkout')) {
            throw new \LogicException('C2 cashier module: prepare-checkout context policy duplicate');
        }
        $policy = new CashierV3ContextPolicy(
            'prepare-checkout',
            ['cashier_workspace'],
            ['service_order', 'checkout_request', 'hang_order', 'reservation', 'room'],
            [$dispatcher->policies(), 'resolveCheckoutSourceBranch'],
            ['cashier_workspace'],
            ['service_order', 'checkout_request', 'hang_order', 'reservation', 'room'],
            ['service_order', 'checkout_request', 'hang_order', 'reservation', 'room']
        );
        $policy->configureServerResourceDiscovery(
            [$preparation, 'discover'],
            [
                'checkout_member',
                'checkout_entitlement_pool',
                'checkout_card_holder',
                'checkout_catalog_card_definition',
                'checkout_catalog_product',
                'checkout_catalog_sku',
                'checkout_custom_card_configuration',
            ],
            [
                'member',
                'member_benefit_pool',
                'card_holder',
                'catalog_card_definition',
                'catalog_product',
                'catalog_sku',
                'custom_card_configuration',
            ]
        );
        $dispatcher->policies()->register($policy);
    }

    private static function registerSubmissionPreparationPolicy(
        CashierV3ActionDispatcher $dispatcher,
        CashierV3CheckoutSubmissionResourceDiscoveryComposite $discovery
    ): void {
        if ($dispatcher->policies()->has('prepare-checkout-submission')) {
            throw new \LogicException('C2 cashier module: submission preparation context policy duplicate');
        }
        $policy = new CashierV3ContextPolicy(
            'prepare-checkout-submission',
            ['cashier_workspace', 'checkout_request'],
            ['service_order', 'hang_order', 'reservation', 'room'],
            [$dispatcher->policies(), 'resolveCheckoutFollowUpBranch'],
            ['cashier_workspace', 'checkout_request'],
            ['service_order', 'hang_order', 'reservation', 'room'],
            ['service_order', 'hang_order', 'reservation', 'room']
        );
        $policy->configureServerResourceDiscovery(
            [$discovery, 'discover'],
            [
                'checkout_member',
                'checkout_entitlement_pool',
                'checkout_card_holder',
                'checkout_catalog_card_definition',
                'checkout_catalog_product',
                'checkout_catalog_sku',
                'checkout_custom_card_configuration',
                'member',
                'benefit_pool',
                'card_holder',
                'entitlement_debt_guard',
                'occupation_guard',
                'performance_rule',
                'staff',
                'occupation',
                'inventory_policy',
                'inventory_recipe',
                'inventory_stock',
                'inventory_batch',
                'inventory_shortage_cursor',
                'checkout_member_balance',
            ],
            [
                'member',
                'member_benefit_pool',
                'card_holder',
                'catalog_card_definition',
                'catalog_product',
                'catalog_sku',
                'custom_card_configuration',
                'entitlement_debt_guard',
                'entitlement_occupation_guard',
                'performance_rule',
                'staff_profile',
                'service_order',
                'reservation',
                'inventory_policy',
                'inventory_recipe',
                'inventory_stock',
                'inventory_batch',
                'inventory_shortage_cursor',
                'member_balance',
            ]
        );
        $dispatcher->policies()->register($policy);
    }

    private static function registerPaymentDraftPolicies(CashierV3ActionDispatcher $dispatcher): void
    {
        foreach (['add-payment-method', 'update-payment-line', 'remove-payment-line'] as $action) {
            if ($dispatcher->policies()->has($action)) {
                throw new \LogicException('C2 cashier module: payment draft context policy duplicate');
            }
            $dispatcher->policies()->register(new CashierV3ContextPolicy(
                $action,
                ['cashier_workspace', 'checkout_request'],
                ['service_order', 'hang_order', 'reservation', 'room', 'debt_record'],
                [$dispatcher->policies(), 'resolveCheckoutFollowUpBranch'],
                ['cashier_workspace', 'checkout_request'],
                ['service_order', 'hang_order', 'reservation', 'room', 'debt_record'],
                ['service_order', 'hang_order', 'reservation', 'room', 'debt_record']
            ));
        }
    }

    private static function registerBalanceDraftPolicies(
        CashierV3ActionDispatcher $dispatcher,
        CashierV3CheckoutBalanceAuthorityDiscovery $discovery
    ): void {
        foreach (['apply-balance-payment', 'remove-balance-payment', 'update-balance-payment'] as $action) {
            if ($dispatcher->policies()->has($action)) {
                throw new \LogicException('C2 cashier module: balance draft context policy duplicate');
            }
            $policy = new CashierV3ContextPolicy(
                $action,
                ['cashier_workspace', 'checkout_request'],
                ['service_order', 'hang_order', 'reservation', 'room', 'debt_record'],
                [$dispatcher->policies(), 'resolveCheckoutFollowUpBranch'],
                ['cashier_workspace', 'checkout_request'],
                ['service_order', 'hang_order', 'reservation', 'room', 'debt_record'],
                ['service_order', 'hang_order', 'reservation', 'room', 'debt_record']
            );
            // Removing a balance-payment draft only clears the intention on
            // the checkout request. It neither reads nor mutates the member
            // balance row, so requiring a discovered balance resource would
            // turn the correct empty discovery set into INVALID_COMMAND_CONTEXT.
            if ($action !== 'remove-balance-payment') {
                $policy->configureServerResourceDiscovery(
                    [$discovery, 'discover'],
                    ['checkout_member_balance'],
                    ['member_balance']
                );
            }
            $dispatcher->policies()->register($policy);
        }
    }

    private static function registerSubmitCheckoutPolicy(CashierV3ActionDispatcher $dispatcher): void
    {
        if ($dispatcher->policies()->has('submit-checkout')) {
            throw new \LogicException('C2 cashier module: submit checkout context policy duplicate');
        }
        $dispatcher->policies()->register(new CashierV3ContextPolicy(
            'submit-checkout',
            ['cashier_workspace', 'checkout_request'],
            [],
            [$dispatcher->policies(), 'resolveCheckoutSubmitBranch'],
            ['cashier_workspace', 'checkout_request'],
            ['service_order', 'hang_order', 'reservation', 'room'],
            ['service_order', 'hang_order', 'reservation', 'room']
        ));
    }

    private static function registerReturnToPaymentEditPolicy(CashierV3ActionDispatcher $dispatcher): void
    {
        if ($dispatcher->policies()->has('return-to-payment-edit')) {
            throw new \LogicException('C2 cashier module: payment-edit recovery context policy duplicate');
        }
        $dispatcher->policies()->register(new CashierV3ContextPolicy(
            'return-to-payment-edit',
            ['cashier_workspace', 'checkout_request'],
            ['service_order', 'hang_order', 'reservation', 'room', 'debt_record'],
            [$dispatcher->policies(), 'resolveCheckoutFollowUpBranch'],
            ['cashier_workspace', 'checkout_request'],
            ['service_order', 'hang_order', 'reservation', 'room', 'debt_record'],
            ['service_order', 'hang_order', 'reservation', 'room', 'debt_record']
        ));
    }

    private static function workspaceContextId(array $contexts): string
    {
        foreach ($contexts as $context) {
            if ((string)($context['kind'] ?? '') === 'cashier_workspace') {
                $id = trim((string)($context['id'] ?? ''));
                if ($id !== '') {
                    return $id;
                }
            }
        }
        throw new CashierV3CommandException(
            CashierV3ResultCode::INVALID_COMMAND_CONTEXT,
            '本次操作缺少当前工作台版本，请刷新页面后重试。',
            CashierV3ResultCode::STATUS_FAILED
        );
    }

    private static function canonicalPositiveId($value, string $field): int
    {
        if (is_bool($value) || is_array($value) || $value === null) {
            throw self::invalidLine($field);
        }
        $raw = trim((string)$value);
        if (preg_match('/^[1-9][0-9]*$/', $raw) !== 1 || (string)(int)$raw !== $raw) {
            throw self::invalidLine($field);
        }
        return (int)$raw;
    }

    private static function invalidLine(string $field): CashierV3CommandException
    {
        return new CashierV3CommandException(
            CashierV3ResultCode::ENTITLEMENT_LINE_INVALID,
            '卡内项目明细无效，请重新打开后选择。',
            CashierV3ResultCode::STATUS_FAILED,
            ['field' => $field]
        );
    }

    private static function entitlementAppendIntent(array $payload): string
    {
        $mode = $payload['mutationMode'] ?? null;
        if ($mode !== 'append') {
            throw self::invalidLine('mutationMode');
        }
        $lines = isset($payload['lines']) && is_array($payload['lines']) ? array_values($payload['lines']) : [];
        if (count($lines) !== 1 || !is_array($lines[0])) {
            throw self::invalidLine('append_lines');
        }
        $quantity = $lines[0]['quantity'] ?? null;
        if (is_bool($quantity) || is_array($quantity) || is_object($quantity) || $quantity === null
            || trim((string)$quantity) !== '1') {
            throw self::invalidLine('append_quantity');
        }
        $addIntentId = trim((string)($payload['addIntentId'] ?? ''));
        if (preg_match('/^[A-Za-z0-9_-]{16,64}$/D', $addIntentId) !== 1) {
            throw self::invalidLine('addIntentId');
        }
        return $addIntentId;
    }

    private static function lineKey($value): string
    {
        if (is_bool($value) || is_array($value) || $value === null) {
            throw self::invalidLine('lineId');
        }
        $lineKey = trim((string)$value);
        if ($lineKey === '' || strlen($lineKey) > 64 || strpos($lineKey, "\0") !== false) {
            throw self::invalidLine('lineId');
        }
        return $lineKey;
    }

    private static function quantityDelta($value): int
    {
        if (is_bool($value) || is_array($value) || $value === null) {
            throw self::invalidLine('delta');
        }
        $raw = trim((string)$value);
        if (preg_match('/^-?[1-9][0-9]*$/', $raw) !== 1) {
            throw self::invalidLine('delta');
        }
        $delta = (int)$raw;
        if ((string)$delta !== $raw || abs($delta) > 1000) {
            throw self::invalidLine('delta');
        }
        return $delta;
    }

    private static function registerRemoveLinePolicy(CashierV3ActionDispatcher $dispatcher): void
    {
        if ($dispatcher->policies()->has('remove-cart-line')) {
            throw new \LogicException('C2 cashier module: remove cart line context policy duplicate');
        }
        $dispatcher->policies()->register(new CashierV3ContextPolicy(
            'remove-cart-line',
            ['cashier_workspace'],
            [],
            function (array $payload, array $base): array {
                self::lineKey($payload['lineId'] ?? $payload['line_id'] ?? null);
                return self::workspaceOnlyPolicyResult($base);
            },
            ['cashier_workspace'],
            ['cashier_workspace'],
            []
        ));
    }

    private static function registerChooseCatalogItemPolicy(
        CashierV3ActionDispatcher $dispatcher,
        CashierV3CashierReadinessGuard $readiness,
        CashierV3SaleCatalogServices $saleCatalog
    ): void {
        if ($dispatcher->policies()->has('choose-catalog-item')) {
            throw new \LogicException('C2 cashier module: choose catalog item context policy duplicate');
        }
        $dispatcher->policies()->register(new CashierV3ContextPolicy(
            'choose-catalog-item',
            ['cashier_workspace'],
            [],
            function (array $payload, array $base) use ($readiness, $saleCatalog): array {
                $readiness->assertReady();
                $itemId = CashierV3SaleCatalogServices::itemId($payload['itemId'] ?? null);
                $resolved = self::workspaceOnlyPolicyResult($base);
                $resolved['expand_from_server_resource_discovery'] = true;
                $resolved['server_resource_discoverer'] = static function (array $scope) use (
                    $saleCatalog,
                    $itemId
                ): array {
                    return ['resources' => $saleCatalog->discoverItemResources(
                        $itemId,
                        $scope['operator_scope'],
                        $scope['data_scope']
                    )];
                };
                return $resolved;
            },
            ['cashier_workspace'],
            ['cashier_workspace', 'catalog_card_definition', 'catalog_product', 'catalog_sku'],
            ['catalog_card_definition', 'catalog_product', 'catalog_sku']
        ));
    }

    private static function registerCreateCustomCardConfigurationPolicy(
        CashierV3ActionDispatcher $dispatcher,
        CashierV3CashierReadinessGuard $readiness,
        CashierV3CustomCardConfigurationServices $customCards
    ): void {
        if ($dispatcher->policies()->has('create-custom-card-configuration')) {
            throw new \LogicException('C2 cashier module: custom-card configuration context policy duplicate');
        }
        $dispatcher->policies()->register(new CashierV3ContextPolicy(
            'create-custom-card-configuration',
            ['cashier_workspace'],
            [],
            function (array $payload, array $base) use ($readiness, $customCards): array {
                $readiness->assertReady();
                $resolved = self::workspaceOnlyPolicyResult($base);
                $resolved['expand_from_server_resource_discovery'] = true;
                $resolved['server_resource_discoverer'] = static function (array $scope) use ($customCards, $payload): array {
                    return ['resources' => $customCards->discoverCreateResources(
                        $payload,
                        $scope['operator_scope'],
                        $scope['data_scope']
                    )];
                };
                return $resolved;
            },
            ['cashier_workspace'],
            ['cashier_workspace', 'catalog_card_definition', 'catalog_product', 'catalog_sku'],
            ['catalog_card_definition', 'catalog_product', 'catalog_sku']
        ));
    }

    private static function registerChangeQuantityPolicy(
        CashierV3ActionDispatcher $dispatcher,
        CashierV3CashierReadinessGuard $readiness,
        CashierV3SaleCatalogServices $saleCatalog
    ): void {
        if ($dispatcher->policies()->has('change-cart-line-quantity')) {
            throw new \LogicException('C2 cashier module: change cart line quantity context policy duplicate');
        }
        $dispatcher->policies()->register(new CashierV3ContextPolicy(
            'change-cart-line-quantity',
            ['cashier_workspace'],
            [],
            function (array $payload, array $base) use ($readiness, $saleCatalog): array {
                $readiness->assertReady();
                $lineKey = self::lineKey($payload['lineId'] ?? $payload['line_id'] ?? null);
                $delta = self::quantityDelta($payload['delta'] ?? null);
                $workspaceId = (string)($base['session']['workspace_id'] ?? '');
                $row = Db::name('cashier_v3_workspace_line')
                    ->where('workspace_id', $workspaceId)
                    ->where('line_key', $lineKey)
                    ->find();
                if (!$row) {
                    throw new CashierV3CommandException(
                        CashierV3ResultCode::RESOURCE_NOT_FOUND,
                        '该购物车项目不存在或已经删除。',
                        CashierV3ResultCode::STATUS_FAILED,
                        ['line_id' => $lineKey]
                    );
                }
                $resolved = self::workspaceOnlyPolicyResult($base);
                if ((string)($row['line_role'] ?? '') === 'sale') {
                    $resolved['expand_from_server_resource_discovery'] = true;
                    $resolved['server_resource_discoverer'] = static function (array $scope) use (
                        $saleCatalog,
                        $workspaceId,
                        $lineKey,
                        $delta
                    ): array {
                        $current = Db::name('cashier_v3_workspace_line')
                            ->where('workspace_id', $workspaceId)
                            ->where('line_key', $lineKey)
                            ->find();
                        if (!$current || (string)($current['line_role'] ?? '') !== 'sale') {
                            throw new CashierV3CommandException(
                                CashierV3ResultCode::RESOURCE_NOT_FOUND,
                                '该购物车项目不存在或已经删除。',
                                CashierV3ResultCode::STATUS_FAILED,
                                ['line_id' => $lineKey]
                            );
                        }
                        $nextQuantity = (int)($current['quantity'] ?? 0) + $delta;
                        return ['resources' => $saleCatalog->discoverStoredLineResources(
                            (array)$current,
                            $nextQuantity,
                            $scope['operator_scope'],
                            $scope['data_scope']
                        )];
                    };
                    return $resolved;
                }
                if ((string)($row['line_role'] ?? '') !== 'entitlement_service') {
                    throw self::invalidLine('lineRole');
                }
                $memberId = self::canonicalPositiveId($row['member_id'] ?? null, 'memberId');
                $holderId = self::canonicalPositiveId($row['holder_id'] ?? null, 'entitlementInstanceId');
                $detailId = self::canonicalPositiveId($row['source_detail_id'] ?? null, 'entitlementSourceDetailId');
                $extraIdentities = [
                    ['role' => 'member', 'kind' => 'member', 'id' => (string)$memberId, 'required' => true],
                    ['role' => 'member_benefit_pool:' . $detailId, 'kind' => 'member_benefit_pool', 'id' => (string)$detailId, 'required' => true],
                    ['role' => 'card_holder:' . $holderId, 'kind' => 'card_holder', 'id' => (string)$holderId, 'required' => true],
                ];
                $resolved['required'] = ['cashier_workspace', 'member', 'member_benefit_pool', 'card_holder'];
                $resolved['identities'] = array_merge($extraIdentities, $resolved['identities']);
                $resolved['required_read_roles'] = array_values(array_map(static function (array $identity): string {
                    return (string)$identity['role'];
                }, $resolved['identities']));
                return $resolved;
            },
            ['cashier_workspace'],
            [
                'cashier_workspace', 'member', 'member_benefit_pool', 'card_holder',
                'catalog_card_definition', 'catalog_product', 'catalog_sku',
            ],
            [
                'member', 'member_benefit_pool', 'card_holder',
                'catalog_card_definition', 'catalog_product', 'catalog_sku',
            ]
        ));
    }

    private static function registerUpdateServiceSettingsPolicy(
        CashierV3ActionDispatcher $dispatcher,
        CashierV3CashierReadinessGuard $readiness
    ): void {
        if ($dispatcher->policies()->has('update-cart-line-service-settings')) {
            throw new \LogicException('C2 cashier module: update cart line service settings context policy duplicate');
        }
        $dispatcher->policies()->register(new CashierV3ContextPolicy(
            'update-cart-line-service-settings',
            ['cashier_workspace'],
            [],
            function (array $payload, array $base) use ($readiness): array {
                $readiness->assertReady();
                $lineKey = self::lineKey($payload['lineId'] ?? null);
                $workspaceId = trim((string)($base['session']['workspace_id'] ?? ''));
                if ($workspaceId === '') {
                    throw new CashierV3CommandException(
                        CashierV3ResultCode::CLIENT_SESSION_REQUIRED,
                        '当前收银工作台会话无效，请刷新页面后重试。',
                        CashierV3ResultCode::STATUS_FAILED
                    );
                }
                $row = Db::name('cashier_v3_workspace_line')
                    ->where('workspace_id', $workspaceId)
                    ->where('line_key', $lineKey)
                    ->find();
                if (!$row) {
                    throw new CashierV3CommandException(
                        CashierV3ResultCode::RESOURCE_NOT_FOUND,
                        '该购物车项目不存在或已经删除。',
                        CashierV3ResultCode::STATUS_FAILED,
                        ['line_id' => $lineKey]
                    );
                }

                $resolved = self::workspaceOnlyPolicyResult($base);
                $lineRole = (string)($row['line_role'] ?? '');
                if ($lineRole === 'sale') {
                    if ((int)($row['project_id'] ?? 0) <= 0) {
                        $hasSalespeople = array_key_exists('salespeople', $payload);
                        $hasProjectSettings = array_key_exists('serviceObject', $payload)
                            || array_key_exists('craftsmen', $payload)
                            || array_key_exists('isExperience', $payload);
                        if (!$hasSalespeople || $hasProjectSettings) {
                            throw new CashierV3CommandException(
                                CashierV3ResultCode::ENTITLEMENT_LINE_INVALID,
                                '该商品只支持设置销售人。',
                                CashierV3ResultCode::STATUS_FAILED,
                                ['line_id' => $lineKey, 'reason' => 'sale_non_project_setting_invalid']
                            );
                        }
                    }
                    return $resolved;
                }
                if ($lineRole !== 'entitlement_service') {
                    throw self::invalidLine('lineRole');
                }
                if (array_key_exists('salespeople', $payload)) {
                    throw new CashierV3CommandException(
                        CashierV3ResultCode::ENTITLEMENT_LINE_INVALID,
                        '权益项目只支持设置手艺人。',
                        CashierV3ResultCode::STATUS_FAILED,
                        ['line_id' => $lineKey, 'reason' => 'entitlement_salespeople_forbidden']
                    );
                }

                $memberId = self::canonicalPositiveId($row['member_id'] ?? null, 'memberId');
                $holderId = self::canonicalPositiveId($row['holder_id'] ?? null, 'entitlementInstanceId');
                $detailId = self::canonicalPositiveId(
                    $row['source_detail_id'] ?? null,
                    'entitlementSourceDetailId'
                );
                $entitlementIdentities = [
                    ['role' => 'member', 'kind' => 'member', 'id' => (string)$memberId, 'required' => true],
                    [
                        'role' => 'member_benefit_pool:' . $detailId,
                        'kind' => 'member_benefit_pool',
                        'id' => (string)$detailId,
                        'required' => true,
                    ],
                    [
                        'role' => 'card_holder:' . $holderId,
                        'kind' => 'card_holder',
                        'id' => (string)$holderId,
                        'required' => true,
                    ],
                ];
                $resolved['required'] = [
                    'cashier_workspace',
                    'member',
                    'member_benefit_pool',
                    'card_holder',
                ];
                $resolved['identities'] = array_merge(
                    $entitlementIdentities,
                    $resolved['identities']
                );
                $resolved['required_read_roles'] = array_values(array_map(
                    static function (array $identity): string {
                        return (string)$identity['role'];
                    },
                    $resolved['identities']
                ));
                return $resolved;
            },
            ['cashier_workspace'],
            ['cashier_workspace', 'member', 'member_benefit_pool', 'card_holder'],
            ['member', 'member_benefit_pool', 'card_holder']
        ));
    }

    private static function workspaceOnlyPolicyResult(array $base): array
    {
        $workspaceId = trim((string)($base['session']['workspace_id'] ?? ''));
        if ($workspaceId === '') {
            throw new CashierV3CommandException(
                CashierV3ResultCode::CLIENT_SESSION_REQUIRED,
                '当前收银工作台会话无效，请刷新页面后重试。',
                CashierV3ResultCode::STATUS_FAILED
            );
        }
        return [
            'required' => ['cashier_workspace'],
            'allowed' => [],
            'identities' => [[
                'role' => 'cashier_workspace',
                'kind' => 'cashier_workspace',
                'id' => $workspaceId,
                'required' => true,
            ]],
            'required_read_roles' => ['cashier_workspace'],
            'required_touched_roles' => ['cashier_workspace'],
        ];
    }

}
