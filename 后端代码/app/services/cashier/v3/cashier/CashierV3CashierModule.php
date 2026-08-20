<?php

namespace app\services\cashier\v3\cashier;

use app\services\cashier\v3\CashierV3ActionDispatcher;
use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\card\CashierV3CardOperationAuthorityServices;
use app\services\cashier\v3\checkout\CashierV3DirectSnapshotEntitlementSettlementServices;
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
use app\services\cashier\v3\projection\CashierV3RootDomainAssembler;
use app\services\cashier\v3\registry\CashierV3ContextPolicy;
use app\services\cashier\v3\service\CashierV3ServiceOrderOccupationAuthorityProvider;
use app\services\cashier\v3\service\ThinkPhpCashierV3EntitlementCompletionOccupationWriter;
use app\services\cashier\v3\service\ThinkPhpCashierV3ServiceOrderRepository;
use app\services\cashier\v3\settlement\CashierV3CheckoutPreparationServices;
use app\services\cashier\v3\settlement\CashierV3CheckoutBalanceAuthorityDiscovery;
use app\services\cashier\v3\settlement\CashierV3CheckoutProjectionServices;
use app\services\cashier\v3\settlement\CashierV3CheckoutResultQueryServices;
use app\services\cashier\v3\settlement\CashierV3DebtRepaymentServices;
use app\services\cashier\v3\settlement\CashierV3DebtRepaymentResourceDiscovery;
use app\services\cashier\v3\settlement\CashierV3RechargeDebtRepaymentServices;
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
        $moreActions = new CashierV3CashierMoreActionServices($workspace);
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
        $directSnapshotEntitlements = new CashierV3DirectSnapshotEntitlementSettlementServices(
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
            [$directSnapshotEntitlements, 'discover'],
            [new CashierV3CheckoutBalanceAuthorityDiscovery($memberBalances), 'discover']
        );
        $submissionPreparation = new CashierV3CheckoutSubmissionPreparationServices(
            $checkoutRequests,
            null,
            $resourcePlans
        );
        $checkoutExecutionPort = new ThinkPhpCashierV3CheckoutSubmissionExecutionPort(
            $workspace,
            $directSnapshotEntitlements,
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
        $debtRepayments = new CashierV3DebtRepaymentServices($checkoutRequests);
        $memberDebtProjection = new CashierV3MemberDebtProjectionServices();

        $handlers = $dispatcher->handlers();
        if ($handlers->hasProjection('open-member-debt-repayment')) {
            throw new \LogicException('C2 cashier module: member debt projection duplicate handler');
        }
        $handlers->registerProjection('open-member-debt-repayment', function (array $scope) use ($memberDebtProjection, $provider, $versionServices): array {
            $payload = is_array($scope['payload'] ?? null) ? $scope['payload'] : [];
            $memberId = (int)($payload['memberId'] ?? $payload['member_id'] ?? 0);
            return Db::transaction(function () use ($memberDebtProjection, $provider, $versionServices, $scope, $memberId): array {
                $snapshot = $memberDebtProjection->read(
                    $memberId,
                    $scope['operator_scope'],
                    $scope['data_scope']
                );
                // 补交命令同时依赖工作台、会员和欠款记录。欠款页必须公开同一
                // 时点的全部版本，不能让用户打开明细后仍携带旧 workspace 版本。
                $workspaceId = \app\services\cashier\v3\CashierV3CheckoutWorkspaceIdentity::id(
                    $scope['operator_scope']->storeId(),
                    (string)$scope['state_context_id']
                );
                $workspaceVersion = $versionServices->ensureRegistered(
                    \app\services\cashier\v3\CashierV3ResourceScope::of(
                        \app\services\cashier\v3\CashierV3ResourceScope::TYPE_STORE,
                        (string)$scope['operator_scope']->storeId()
                    ),
                    'cashier_workspace',
                    $workspaceId,
                    $scope['data_scope']
                );
                $memberVersion = $provider->synchronizeProjectionVersion(
                    'member',
                    (string)$memberId,
                    $scope['operator_scope'],
                    $scope['data_scope']
                );
                $versions = [
                    [
                        'kind' => 'cashier_workspace',
                        'id' => $workspaceId,
                        'version' => $workspaceVersion,
                    ],
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
                    $revision = $debtId !== ''
                        ? $provider->synchronizeProjectionVersion(
                            'debt_record',
                            $debtId,
                            $scope['operator_scope'],
                            $scope['data_scope']
                        )
                        : 0;
                    if ($debtId !== '' && $revision > 0) {
                        $versions[] = [
                            'kind' => 'debt_record',
                            'id' => $debtId,
                            'version' => $revision,
                        ];
                    }
                }
                foreach ((array)($snapshot['records'] ?? []) as $index => $record) {
                    $debtId = (string)($record['debtId'] ?? $record['id'] ?? '');
                    foreach ($versions as $version) {
                        if ((string)($version['kind'] ?? '') === 'debt_record'
                            && (string)($version['id'] ?? '') === $debtId) {
                            $snapshot['records'][$index]['recordVersion'] = (int)$version['version'];
                            $snapshot['records'][$index]['revision'] = (int)$version['version'];
                            break;
                        }
                    }
                }
                return [
                    'data' => ['debtSnapshot' => $snapshot],
                    'versions' => $versions,
                    'message' => '会员欠款已重新读取。',
                ];
            });
        });
        if ($handlers->hasCommand('prepare-debt-repayment') || $handlers->hasCommand('submit-debt-repayment')) {
            throw new \LogicException('C2 cashier module: debt repayment command handler duplicate');
        }
        $handlers->registerCommand('prepare-debt-repayment', function (array $scope) use ($debtRepayments): array {
            $prepared = $debtRepayments->prepareInTx($scope);
            return [
                'data' => ['debtRepaymentPreparation' => $prepared],
                'business_no' => (string)$prepared['checkoutRequestId'],
                'touched' => ['cashier_workspace'],
                'return_root_state' => true,
                'message' => '欠款补交收款已准备完成。',
            ];
        });
        if ($handlers->hasCommand('prepare-recharge-debt-repayment')) {
            throw new \LogicException('C5 cashier module: recharge debt repayment prepare handler duplicate');
        }
        $rechargeDebtRepayments = new CashierV3RechargeDebtRepaymentServices();
        $handlers->registerCommand('prepare-recharge-debt-repayment', function (array $scope) use ($rechargeDebtRepayments): array {
            $prepared = $rechargeDebtRepayments->prepareCheckoutInTx($scope);
            return [
                'data' => ['debtRepaymentPreparation' => $prepared],
                'business_no' => (string)$prepared['checkoutRequestId'],
                'touched' => ['cashier_workspace'],
                'return_root_state' => true,
                'message' => '充值欠款补交收款已准备完成。',
            ];
        });
        $handlers->registerCommand('submit-debt-repayment', function (array $scope) use ($debtRepayments): array {
            return $debtRepayments->submitInTx($scope);
        });
        if ($handlers->hasCommand('submit-recharge-debt-repayment')) {
            throw new \LogicException('C5 cashier module: recharge debt repayment submit handler duplicate');
        }
        $handlers->registerCommand('submit-recharge-debt-repayment', function (array $scope) use ($debtRepayments): array {
            return $debtRepayments->submitRechargeDebtCheckoutInTx($scope);
        });
        if ($handlers->hasProjection('query-debt-repayment-result')) {
            throw new \LogicException('C2 cashier module: debt repayment query handler duplicate');
        }
        $handlers->registerProjection('query-debt-repayment-result', function (array $scope) use ($debtRepayments): array {
            return $debtRepayments->query(
                is_array($scope['payload'] ?? null) ? $scope['payload'] : [],
                $scope['operator_scope'],
                $scope['data_scope']
            );
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
            $line = $saleCatalog->selectDraftSaleLineAfterGatewayLocksInTx(
                $payload['itemId'] ?? null,
                (string)($scope['idempotency_key'] ?? ''),
                $scope['operator_scope'],
                $scope['data_scope']
            );
            $workspace->assertCardSaleMemberInTx($lockedDraft, $line);
            $draft = $workspace->appendSaleLineInTx(
                $workspaceId,
                (string)($scope['state_context_id'] ?? ''),
                $scope['operator_scope'],
                $line,
                $lockedDraft
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
            $lockedDraft = $workspace->lockForSaleMutationInTx(
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
                $line,
                $lockedDraft
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
        $handlers->registerCommand('remove-cart-line', function (array $scope) use ($workspace): array {
            $payload = is_array($scope['payload'] ?? null) ? $scope['payload'] : [];
            $draft = $workspace->removeLineInTx(
                self::workspaceContextId((array)($scope['contexts'] ?? [])),
                (string)($scope['state_context_id'] ?? ''),
                $scope['operator_scope'],
                self::lineKey($payload['lineId'] ?? $payload['line_id'] ?? null)
            );
            return [
                'data' => ['cashierDraft' => $draft],
                'touched' => ['cashier_workspace'],
                'message' => '购物车项目已删除。',
            ];
        });

        if ($handlers->hasCommand('clear-cart-lines')) {
            throw new \LogicException('C2 cashier module: clear cart lines duplicate handler');
        }
        $handlers->registerCommand('clear-cart-lines', function (array $scope) use ($workspace): array {
            $draft = $workspace->clearLinesInTx(
                self::workspaceContextId((array)($scope['contexts'] ?? [])),
                (string)($scope['state_context_id'] ?? ''),
                $scope['operator_scope']
            );
            return [
                'data' => ['cashierDraft' => $draft],
                'touched' => ['cashier_workspace'],
                'message' => '购物车已清空。',
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
                // Quantity is a draft edit.  Entitlement availability and
                // inventory are checked once by checkout preparation only.
                static function (): void {},
                static function (): void {}
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

        if ($handlers->hasCommand('apply-cashier-salespeople-to-all-sale-lines')) {
            throw new \LogicException('C2 cashier module: apply salespeople to all sale lines duplicate handler');
        }
        $handlers->registerCommand('apply-cashier-salespeople-to-all-sale-lines', function (array $scope) use ($workspace): array {
            $payload = is_array($scope['payload'] ?? null) ? $scope['payload'] : [];
            $draft = $workspace->applySalespeopleToAllSaleLinesInTx(
                self::workspaceContextId((array)($scope['contexts'] ?? [])),
                (string)($scope['state_context_id'] ?? ''),
                $scope['operator_scope'],
                is_array($payload['salespeople'] ?? null) ? $payload['salespeople'] : []
            );
            return [
                'data' => ['cashierDraft' => $draft],
                'touched' => ['cashier_workspace'],
                'message' => '销售人已应用到全部本次购买商品。',
            ];
        });

        if ($handlers->hasCommand('apply-cashier-craftsmen-to-all-service-lines')) {
            throw new \LogicException('C2 cashier module: apply craftsmen to all service lines duplicate handler');
        }
        $handlers->registerCommand('apply-cashier-craftsmen-to-all-service-lines', function (array $scope) use ($workspace): array {
            $payload = is_array($scope['payload'] ?? null) ? $scope['payload'] : [];
            $draft = $workspace->applyCraftsmenToAllServiceLinesInTx(
                self::workspaceContextId((array)($scope['contexts'] ?? [])),
                (string)($scope['state_context_id'] ?? ''),
                $scope['operator_scope'],
                is_array($payload['craftsmen'] ?? null) ? $payload['craftsmen'] : []
            );
            return [
                'data' => ['cashierDraft' => $draft],
                'touched' => ['cashier_workspace'],
                'message' => '手艺人已应用到全部服务项目。',
            ];
        });

        if ($handlers->hasCommand('apply-cashier-personnel-to-all-lines')) {
            throw new \LogicException('C2 cashier module: apply personnel to all lines duplicate handler');
        }
        $handlers->registerCommand('apply-cashier-personnel-to-all-lines', function (array $scope) use ($workspace): array {
            $payload = is_array($scope['payload'] ?? null) ? $scope['payload'] : [];
            $craftsmen = is_array($payload['craftsmen'] ?? null) ? $payload['craftsmen'] : [];
            $salespeople = is_array($payload['salespeople'] ?? null) ? $payload['salespeople'] : [];
            if (!$craftsmen && !$salespeople) {
                throw CashierV3CommandException::invalidContext('请先选择要应用到购物车的人员。');
            }
            $workspaceId = self::workspaceContextId((array)($scope['contexts'] ?? []));
            $stateContextId = (string)($scope['state_context_id'] ?? '');
            $draft = null;
            if ($craftsmen) {
                try {
                    $draft = $workspace->applyCraftsmenToAllServiceLinesInTx(
                        $workspaceId,
                        $stateContextId,
                        $scope['operator_scope'],
                        $craftsmen
                    );
                } catch (CashierV3CommandException $exception) {
                    // The combined personnel action is allowed to carry both
                    // roles. A sale-only cart may have no service rows even
                    // though the overlay still has craftsmen candidates; in
                    // that case skip only the inapplicable role and continue
                    // applying salespeople below.
                    if (($exception->getDetail()['reason'] ?? '') !== 'service_lines_missing_for_apply_all') {
                        throw $exception;
                    }
                    $draft = $workspace->readDraft($workspaceId, $stateContextId, $scope['operator_scope'], true);
                }
            }
            if ($salespeople) {
                try {
                    $draft = $workspace->applySalespeopleToAllSaleLinesInTx(
                        $workspaceId,
                        $stateContextId,
                        $scope['operator_scope'],
                        $salespeople
                    );
                } catch (CashierV3CommandException $exception) {
                    // A pure entitlement cart can still carry the combined
                    // personnel payload from the overlay. Salespeople are
                    // inapplicable there; skip only that role while keeping
                    // all validation failures for real sale lines intact.
                    if (($exception->getDetail()['reason'] ?? '') !== 'sale_lines_missing_for_apply_all') {
                        throw $exception;
                    }
                    $draft = $workspace->readDraft($workspaceId, $stateContextId, $scope['operator_scope'], true);
                }
            }
            if ($draft === null) {
                $draft = $workspace->readDraft($workspaceId, $stateContextId, $scope['operator_scope'], true);
            }
            return [
                'data' => ['cashierDraft' => $draft],
                'touched' => ['cashier_workspace'],
                'message' => '手艺人和销售人已应用到购物车全部适用项目。',
            ];
        });

        if ($handlers->hasCommand('update-cashier-line-debt')) {
            throw new \LogicException('C2 cashier module: update line debt duplicate handler');
        }
        $handlers->registerCommand('update-cashier-line-debt', function (array $scope) use ($workspace): array {
            $payload = is_array($scope['payload'] ?? null) ? $scope['payload'] : [];
            $amount = $payload['debtAmountCents'] ?? null;
            if (!is_int($amount) && !(is_string($amount) && preg_match('/^(?:0|[1-9][0-9]*)$/D', $amount))) {
                throw CashierV3CommandException::invalidContext('欠款金额无效。');
            }
            $draft = $workspace->updateLineDebtInTx(
                self::workspaceContextId((array)($scope['contexts'] ?? [])),
                (string)($scope['state_context_id'] ?? ''),
                $scope['operator_scope'],
                self::lineKey($payload['lineId'] ?? null),
                (int)$amount
            );
            return [
                'data' => ['cashierDraft' => $draft],
                'touched' => ['cashier_workspace'],
                'message' => '该条商品欠款已更新。',
            ];
        });

        if ($handlers->hasProjection('open-line-coupon')) {
            throw new \LogicException('C2 cashier module: open line coupon duplicate handler');
        }
        $handlers->registerProjection('open-line-coupon', function (array $scope) use ($workspace): array {
            $payload = is_array($scope['payload'] ?? null) ? $scope['payload'] : [];
            $selector = $workspace->couponSelector(
                // 投影请求没有写命令 contexts；工作台身份必须由服务端已
                // 解析的门店、操作人和 state context 派生，不能错误要求
                // 浏览器伪造一个带版本的 command context。
                self::workspaceIdForProjection($scope),
                (string)($scope['state_context_id'] ?? ''),
                $scope['operator_scope'],
                self::lineKey($payload['lineId'] ?? $payload['line_id'] ?? null)
            );
            return [
                'data' => ['couponSelector' => $selector],
                'message' => '可用优惠券已重新读取。',
            ];
        });

        if ($handlers->hasProjection('open-local-line-coupon')) {
            throw new \LogicException('C2 cashier module: open local line coupon duplicate handler');
        }
        $handlers->registerProjection('open-local-line-coupon', function (array $scope) use ($workspace): array {
            $payload = is_array($scope['payload'] ?? null) ? $scope['payload'] : [];
            $selector = $workspace->localCouponSelector(
                self::workspaceIdForProjection($scope),
                (string)($scope['state_context_id'] ?? ''),
                $scope['operator_scope'],
                self::lineKey($payload['lineId'] ?? $payload['line_id'] ?? null),
                (int)($payload['lineAmountCents'] ?? $payload['line_amount_cents'] ?? -1),
                (int)($payload['couponThresholdCents'] ?? $payload['coupon_threshold_cents'] ?? -1),
                is_array($payload['reservedCouponIds'] ?? null) ? $payload['reservedCouponIds'] : []
            );
            return [
                'data' => ['couponSelector' => $selector],
                'message' => '可用优惠券已读取。',
            ];
        });

        foreach (['apply-line-coupon', 'remove-line-coupon'] as $action) {
            if ($handlers->hasCommand($action)) {
                throw new \LogicException('C2 cashier module: line coupon command duplicate handler');
            }
            $handlers->registerCommand($action, function (array $scope) use ($workspace, $action): array {
                $payload = is_array($scope['payload'] ?? null) ? $scope['payload'] : [];
                $lineKey = self::lineKey($payload['lineId'] ?? $payload['line_id'] ?? null);
                $couponId = 0;
                if ($action === 'apply-line-coupon') {
                    $rawCouponId = trim((string)($payload['couponId'] ?? ''));
                    if (preg_match('/^[1-9][0-9]*$/D', $rawCouponId) !== 1
                        || (string)(int)$rawCouponId !== $rawCouponId) {
                        throw CashierV3CommandException::invalidContext('优惠券标识无效，请重新选择。');
                    }
                    $couponId = (int)$rawCouponId;
                }
                $draft = $action === 'apply-line-coupon'
                    ? $workspace->applyLineCouponInTx(
                        self::workspaceContextId((array)($scope['contexts'] ?? [])),
                        (string)($scope['state_context_id'] ?? ''),
                        $scope['operator_scope'],
                        $lineKey,
                        $couponId
                    )
                    : $workspace->removeLineCouponInTx(
                        self::workspaceContextId((array)($scope['contexts'] ?? [])),
                        (string)($scope['state_context_id'] ?? ''),
                        $scope['operator_scope'],
                        $lineKey
                    );
                return [
                    'data' => ['cashierDraft' => $draft],
                    'touched' => ['cashier_workspace'],
                    'message' => $action === 'apply-line-coupon' ? '优惠券已使用。' : '优惠券已移除。',
                ];
            });
        }

        foreach ([
            'update-cashier-order-note',
            'update-cashier-line-price',
            'update-cashier-supplement',
            'change-supplement-date',
            'exit-supplement',
        ] as $action) {
            if ($handlers->hasCommand($action)) {
                throw new \LogicException('C2 cashier module: more-action command handler duplicate');
            }
            $handlers->registerCommand($action, function (array $scope) use ($moreActions, $action): array {
                $result = $moreActions->mutateInTx($action, $scope);
                return [
                    'data' => ['cashierDraft' => $result['cashierDraft']],
                    'touched' => ['cashier_workspace'],
                    'message' => (string)$result['message'],
                ];
            });
        }

        if ($handlers->hasCommand('submit-checkout')) {
            throw new \LogicException('C2 cashier module: submit checkout handler duplicate');
        }
        $handlers->registerCommand('submit-checkout', function (array $scope) use ($checkoutSubmission, $checkoutPreparation, $submissionPreparation): array {
            $payload = is_array($scope['payload'] ?? null) ? $scope['payload'] : [];
            if (is_array($payload['checkoutSnapshot'] ?? null)) {
                // Card upgrades are browser-owned until this final command.
                // Materialize their pending operation inside the same
                // transaction so the checkout snapshot remains the only
                // client-to-server write before settlement.
                $snapshot = $payload['checkoutSnapshot'];
                $directOperationAuthority = new CashierV3CardOperationAuthorityServices();
                $operationResults = [];
                foreach (array_keys((array)($snapshot['lines'] ?? [])) as $lineIndex) {
                    $line = is_array($snapshot['lines'][$lineIndex] ?? null)
                        ? $snapshot['lines'][$lineIndex]
                        : [];
                    $operationPayload = is_array($line['localCardOperation'] ?? null)
                        ? $line['localCardOperation']
                        : [];
                    $operationType = trim((string)($operationPayload['operationType'] ?? ''));
                    if ($operationType === '') {
                        continue;
                    }
                    $lineRole = trim((string)($line['lineRole'] ?? ''));
                    $upgradeBinding = is_array($line['cardOperationUpgrade'] ?? null)
                        ? $line['cardOperationUpgrade']
                        : [];
                    $isUpgradeOperation = in_array($operationType, ['card_upgrade', 'project_upgrade'], true);
                    // Browser snapshots can survive a hot reload. A stale local
                    // operation marker on a normal sale line is not business
                    // intent and must never turn an ordinary card purchase into
                    // an upgrade credit. Only the explicitly paired upgrade row
                    // may materialize an upgrade operation here.
                    if ($lineRole === 'sale' && (!$isUpgradeOperation
                        || (string)($upgradeBinding['operationType'] ?? '') !== $operationType)) {
                        unset($snapshot['lines'][$lineIndex]['localCardOperation']);
                        unset($snapshot['lines'][$lineIndex]['cardOperationUpgrade']);
                        continue;
                    }
                    if ($lineRole !== 'sale' && $lineRole !== 'card_operation') {
                        unset($snapshot['lines'][$lineIndex]['localCardOperation']);
                        continue;
                    }
                    if ($operationType === 'project_replacement') {
                        $targetProjectSnapshot = is_array($line['targetProjectSnapshot'] ?? null)
                            ? $line['targetProjectSnapshot']
                            : [];
                        $targetName = trim((string)($targetProjectSnapshot['name'] ?? ''));
                        $targetQuantity = (int)($targetProjectSnapshot['targetQuantity'] ?? 0);
                        if ($targetName === '' || $targetQuantity <= 0
                            || $targetQuantity !== (int)($operationPayload['targetQuantity'] ?? 0)) {
                            throw new CashierV3CommandException(
                                CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                                '项目替换目标快照不完整，本次结账已回滚，请重试。',
                                CashierV3ResultCode::STATUS_FAILED
                            );
                        }
                        // The line-level target snapshot is the sole business
                        // input for the new right. Do not re-read a projected
                        // target name or count from the selector/catalogue.
                        $operationPayload['targetSnapshot'] = [
                            'catalogId' => (int)($targetProjectSnapshot['catalogId'] ?? 0),
                            'name' => $targetName,
                            'targetQuantity' => $targetQuantity,
                        ];
                        $operationPayload['targetQuantity'] = $targetQuantity;
                    }
                    $operationScope = $scope;
                    $operationScope['action'] = 'submit-card-operation';
                    if ($isUpgradeOperation && $upgradeBinding !== []) {
                        // The checkout line is the frozen monetary snapshot.
                        // Lock live identities, but do not re-price this checkout.
                        $operationPayload['snapshotSettlement'] = [
                            'targetPriceCents' => (int)($upgradeBinding['targetPriceCents'] ?? -1),
                            'sourceRemainingValueCents' => (int)($upgradeBinding['sourceRemainingValueCents'] ?? -1),
                            'settlementDeltaCents' => (int)($upgradeBinding['settlementDeltaCents'] ?? -1),
                        ];
                    }
                    $operationScope['payload'] = $operationPayload;
                    // A local operation selection can retain catalog/card
                    // versions from the moment its dialog was opened. They
                    // are not checkout authority. Keep only the current
                    // final-command contexts; the card operation locks and
                    // reads its current source rights inside this transaction.
                    $operationScope['contexts'] = (array)($scope['contexts'] ?? []);
                    $operationScope['idempotency_key'] = (string)($operationPayload['idempotencyKey'] ?? '');
                    $operationScope['direct_snapshot_operation'] = true;
                    $operationScope['snapshot_occurred_at'] = (int)($snapshot['occurredAt'] ?? 0);
                    $operationScope['snapshot_business_date'] = (string)($snapshot['businessDate'] ?? '');
                    $operationResult = $directOperationAuthority->submitInTx($operationScope);
                    $operation = is_array($operationResult['operation'] ?? null)
                        ? $operationResult['operation']
                        : [];
                    $operationId = trim((string)($operation['operationId'] ?? $operation['operation_id'] ?? ''));
                    if ($operationId === '') {
                        throw new CashierV3CommandException(
                            CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                            '升级操作资料不完整，本次结账已回滚，请重试。',
                            CashierV3ResultCode::STATUS_FAILED
                        );
                    }
                    $operationResults[] = $operation;
                    if (!$isUpgradeOperation) {
                        unset($snapshot['lines'][$lineIndex]);
                        continue;
                    }
                    $upgradeLine = is_array($operationResult['upgradeSaleLine'] ?? null)
                        ? $operationResult['upgradeSaleLine']
                        : [];
                    $upgradeSnapshot = is_array($upgradeLine['authoritySnapshot'] ?? null)
                        ? $upgradeLine['authoritySnapshot']
                        : (is_array($upgradeLine['authority_snapshot'] ?? null)
                            ? $upgradeLine['authority_snapshot']
                            : []);
                    $upgradeBinding = is_array($upgradeSnapshot['cardOperationUpgrade'] ?? null)
                        ? $upgradeSnapshot['cardOperationUpgrade']
                        : [];
                    if ($upgradeBinding === []) {
                        throw new CashierV3CommandException(
                            CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                            '升级结账资料不完整，本次结账已回滚，请重试。',
                            CashierV3ResultCode::STATUS_FAILED
                        );
                    }
                    // The browser line is the user-facing intent, while the
                    // operation plan is the final locked monetary authority.
                    // Reconcile both in this same final transaction so the
                    // checkout snapshot has one amount equation everywhere:
                    // delta = max(0, target - source value). Financial credit
                    // is capped to target later, so an excess source value
                    // never becomes a negative payment or a refund.
                    $targetPriceCents = (int)($upgradeBinding['targetPriceCents'] ?? -1);
                    $creditCents = (int)($upgradeBinding['sourceRemainingValueCents'] ?? -1);
                    $deltaCents = (int)($upgradeBinding['settlementDeltaCents'] ?? -1);
                    $couponDiscountCents = max(0, (int)(
                        $snapshot['lines'][$lineIndex]['couponDiscountCents']
                        ?? $snapshot['lines'][$lineIndex]['coupon_discount_cents']
                        ?? 0
                    ));
                    if ($targetPriceCents < 0 || $creditCents < 0 || $deltaCents < 0
                        || max(0, $targetPriceCents - $creditCents) !== $deltaCents
                        || $couponDiscountCents > $deltaCents) {
                        throw new CashierV3CommandException(
                            CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                            '升级结账金额不完整，本次结账已回滚，请重试。',
                            CashierV3ResultCode::STATUS_FAILED
                        );
                    }
                    $payableCents = $deltaCents - $couponDiscountCents;
                    $snapshot['lines'][$lineIndex]['lineAmountCents'] = $payableCents;
                    $snapshot['lines'][$lineIndex]['originalLineAmountCents'] = $targetPriceCents;
                    $snapshot['lines'][$lineIndex]['amount'] = $payableCents / 100;
                    $snapshot['lines'][$lineIndex]['finalAmount'] = $payableCents / 100;
                    $snapshot['lines'][$lineIndex]['originalAmount'] = $targetPriceCents / 100;
                    $snapshot['lines'][$lineIndex]['cardOperationUpgrade'] = $upgradeBinding;
                }
                $snapshot['lines'] = array_values((array)$snapshot['lines']);
                if ($snapshot['lines'] === []) {
                    $lastOperation = $operationResults === [] ? [] : $operationResults[count($operationResults) - 1];
                    $operationId = trim((string)($lastOperation['operationId'] ?? ''));
                    $operationNo = trim((string)($lastOperation['operationNo'] ?? $lastOperation['operation_no'] ?? ''));
                    if ($operationId === '' || $operationNo === '') {
                        throw new CashierV3CommandException(
                            CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                            '卡操作结果不完整，本次操作已回滚，请重试。',
                            CashierV3ResultCode::STATUS_FAILED
                        );
                    }
                    $eventRecorder = $scope['event_recorder'] ?? null;
                    $eventExecution = $scope['event_execution'] ?? null;
                    if (!$eventRecorder || !$eventExecution) {
                        throw new CashierV3CommandException(
                            CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                            '卡操作结账记录不完整，本次操作已回滚，请重试。',
                            CashierV3ResultCode::STATUS_FAILED
                        );
                    }
                    $occurredAt = (int)($snapshot['occurredAt'] ?? 0);
                    $eventRecorder->recordInTx(
                        $eventExecution,
                        (array)($scope['event_contract'] ?? []),
                        [
                            'event_type' => 'checkout.completed',
                            'aggregate_type' => 'card_operation',
                            'aggregate_id' => $operationId,
                            'aggregate_version' => 1,
                            'source_type' => 'submit-checkout',
                            'source_id' => $operationId,
                            'member_id' => (int)($lastOperation['memberIdAfter'] ?? 0),
                            'business_date' => (string)($snapshot['businessDate'] ?? ''),
                            'occurred_at' => $occurredAt,
                            'settled_at' => $occurredAt,
                            'recorded_at' => max(time(), $occurredAt),
                            'aggregate_name_snapshot' => $operationNo,
                            'payload' => [
                                'composition' => 'card_operation_only',
                                'operationId' => $operationId,
                                'operationNo' => $operationNo,
                            ],
                        ]
                    );
                    return [
                        'data' => ['checkoutSubmission' => [
                            'requestStatus' => CashierV3ResultCode::STATUS_SUCCESS,
                            'composition' => 'card_operation_only',
                            'completionReferenceId' => $operationNo,
                            'cardOperations' => $operationResults,
                            'cashierDraft' => [],
                        ]],
                        'business_no' => $operationNo,
                        // A card-operation-only browser snapshot has no
                        // persisted workbench mutation.  Its operation row is
                        // created and settled inside this final transaction.
                        'touched' => [],
                        'return_root_state' => true,
                        'message' => '卡操作已完成。',
                    ];
                }
                $payload['checkoutSnapshot'] = $snapshot;
                $scope['payload'] = $payload;
                $prepared = $checkoutPreparation->prepareInTx($scope);
                $submitScope = $scope;
                $submissionPreparationScope = $scope;
                $submissionPreparationScope['action'] = 'finalize-checkout-snapshot';
                $submissionPreparationScope['direct_snapshot_submission'] = true;
                $submissionPreparationScope['idempotency_key'] = preg_replace(
                    '/^CHECKOUT-/D',
                    'CHECKOUT_PREPARE-',
                    (string)($scope['idempotency_key'] ?? '')
                );
                $submissionPreparationScope['payload'] = [
                    'checkoutRequestId' => (string)$prepared['checkoutRequestId'],
                    'checkoutRequestVersion' => (int)$prepared['checkoutRequestVersion'],
                    'preparationRequestId' => (string)$submissionPreparationScope['idempotency_key'],
                ];
                $promoted = $submissionPreparation->prepareInTx($submissionPreparationScope);
                $submitPayload = [
                    'checkoutRequestId' => (string)$prepared['checkoutRequestId'],
                    'checkoutRequestVersion' => (int)$promoted['checkoutRequestVersion'],
                    'preparationRequestId' => (string)$submissionPreparationScope['idempotency_key'],
                ];
                // The submission preparation creates the internal CHECKOUT_PREPARE
                // identity used by the final executor. Keep the browser snapshot
                // as the business authority while binding this transport identity
                // to the promoted in-transaction preparation.
                $submitPayload['preparationRequestId'] = (string)$submissionPreparationScope['idempotency_key'];
                $submitScope['payload'] = $submitPayload;
                $submitScope['direct_snapshot_submission'] = true;
                $discoveredResources = (array)(
                    is_array($scope['server_resource_discovery'] ?? null)
                        ? ($scope['server_resource_discovery']['resources'] ?? [])
                        : []
                );
                $submitScope['checkout_resource_plan'] = [
                    'requestId' => (string)$prepared['checkoutRequestId'],
                    'boundRequestVersion' => (int)$promoted['checkoutRequestVersion'],
                    'tenantId' => $scope['data_scope']->tenantId(),
                    'storeId' => $scope['data_scope']->forcedStoreId(),
                    'resourcePlanFingerprint' => (string)($promoted['resourcePlanFingerprint'] ?? ''),
                    'resources' => array_values(array_map(static function (array $resource): array {
                        return [
                            'kind' => (string)($resource['kind'] ?? ''),
                            'id' => (string)($resource['id'] ?? ''),
                            'expectedVersion' => (int)($resource['expectedVersion'] ?? 0),
                            'roles' => array_values((array)($resource['roles'] ?? [])),
                        ];
                    }, $discoveredResources)),
                ];
                $submitted = $checkoutSubmission->submitInTx($submitScope);
            } else {
                throw new CashierV3CommandException(
                    CashierV3ResultCode::INVALID_COMMAND_CONTEXT,
                    '结账必须使用最终前端快照，旧结账草稿路径已删除。',
                    CashierV3ResultCode::STATUS_FAILED
                );
            }
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
            // A direct snapshot command has no browser-provided workspace or
            // checkout-request context. The request aggregate is created and
            // consumed inside this transaction, so its result must not report
            // a client projection version as changed.
            $isBrowserSnapshot = is_array($payload['checkoutSnapshot'] ?? null)
                || !empty($scope['direct_snapshot_submission']);
            $touched = ['cashier_workspace', 'checkout_request'];
            if ($isBrowserSnapshot) {
                $touched = [];
            }
            foreach ((array)($scope['contexts'] ?? []) as $context) {
                if (in_array('checkout_member_balance', (array)($context['roles'] ?? []), true)) {
                    $touched[] = 'checkout_member_balance';
                    break;
                }
            }
            return [
                'data' => [
                    'checkoutSubmission' => $submitted,
                    'cashierDraft' => $submitted['cashierDraft'],
                ],
                'business_no' => $businessNo,
                'touched' => $touched,
                // 结账成功后必须重建权威根投影，让客户端看到 succeeded
                // 终态及清空后的工作台；否则只拿到命令成功会被误判为结果未知。
                'return_root_state' => true,
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
        self::registerClearCartLinesPolicy($dispatcher);
        self::registerChangeQuantityPolicy($dispatcher, $readiness, $saleCatalog);
        self::registerUpdateServiceSettingsPolicy($dispatcher, $readiness);
        self::registerMoreActionPolicies($dispatcher, $readiness);
        self::registerLineCouponPolicies($dispatcher, $readiness);
        self::registerDebtRepaymentPolicies($dispatcher);
        self::registerSubmitCheckoutPolicy($dispatcher, $submissionDiscovery);

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

    private static function registerDebtRepaymentPolicies(CashierV3ActionDispatcher $dispatcher): void
    {
        foreach (['prepare-debt-repayment', 'prepare-recharge-debt-repayment', 'submit-debt-repayment', 'submit-recharge-debt-repayment'] as $action) {
            if ($dispatcher->policies()->has($action)) {
                throw new \LogicException('C2 cashier module: debt repayment context policy duplicate');
            }
        }

        $dispatcher->policies()->register(new CashierV3ContextPolicy(
            'prepare-debt-repayment',
            ['cashier_workspace', 'debt_record'],
            [],
            [$dispatcher->policies(), 'resolveCheckoutSourceBranch'],
            ['cashier_workspace'],
            ['debt_record'],
            ['debt_record']
        ));

        $dispatcher->policies()->register(new CashierV3ContextPolicy(
            'prepare-recharge-debt-repayment',
            ['cashier_workspace', 'member', 'member_balance'],
            [],
            static function (array $payload, array $base): array {
                $workspaceId = trim((string)($base['session']['workspace_id'] ?? ''));
                $memberId = trim((string)($payload['memberId'] ?? ''));
                if ($workspaceId === '' || preg_match('/^[1-9][0-9]*$/D', $memberId) !== 1) {
                    throw CashierV3CommandException::invalidContext('充值欠款补交资料已变化，请刷新后重试。');
                }
                return [
                    'required' => ['cashier_workspace', 'member', 'member_balance'],
                    'identities' => [
                        ['role' => 'cashier_workspace', 'kind' => 'cashier_workspace', 'id' => $workspaceId, 'required' => true],
                        ['role' => 'member', 'kind' => 'member', 'id' => $memberId, 'required' => true],
                        ['role' => 'member_balance', 'kind' => 'member_balance', 'id' => $memberId, 'required' => true],
                    ],
                    'required_read_roles' => ['cashier_workspace', 'member', 'member_balance'],
                    'required_touched_roles' => ['cashier_workspace'],
                ];
            },
            ['cashier_workspace'],
            ['cashier_workspace', 'member', 'member_balance'],
            ['cashier_workspace', 'member', 'member_balance']
        ));

        $submitDebtRepaymentPolicy = new CashierV3ContextPolicy(
            'submit-debt-repayment',
            ['cashier_workspace', 'checkout_request'],
            ['debt_record'],
            [$dispatcher->policies(), 'resolveCheckoutFollowUpBranch'],
            ['cashier_workspace', 'checkout_request', 'debt_record'],
            ['debt_record'],
            ['debt_record']
        );
        $submitDebtRepaymentPolicy->configureServerResourceDiscovery(
            [new CashierV3DebtRepaymentResourceDiscovery(), 'discover'],
            ['debt_record'],
            ['debt_record']
        );
        $dispatcher->policies()->register($submitDebtRepaymentPolicy);

        $dispatcher->policies()->register(new CashierV3ContextPolicy(
            'submit-recharge-debt-repayment',
            ['cashier_workspace', 'member', 'member_balance'],
            ['checkout_request'],
            static function (array $payload, array $base): array {
                $workspaceId = trim((string)($base['session']['workspace_id'] ?? ''));
                $memberId = trim((string)($payload['memberId'] ?? ''));
                $requestId = trim((string)($payload['checkoutRequestId'] ?? ''));
                if ($workspaceId === '' || preg_match('/^[1-9][0-9]*$/D', $memberId) !== 1 || $requestId === '') {
                    throw CashierV3CommandException::invalidContext('充值欠款补交资料已变化，请刷新后重试。');
                }
                return [
                    'required' => ['cashier_workspace', 'member', 'member_balance', 'checkout_request'],
                    'identities' => [
                        ['role' => 'cashier_workspace', 'kind' => 'cashier_workspace', 'id' => $workspaceId, 'required' => true],
                        ['role' => 'member', 'kind' => 'member', 'id' => $memberId, 'required' => true],
                        ['role' => 'member_balance', 'kind' => 'member_balance', 'id' => $memberId, 'required' => true],
                        ['role' => 'checkout_request', 'kind' => 'checkout_request', 'id' => $requestId, 'required' => true],
                    ],
                    'required_read_roles' => ['cashier_workspace', 'member', 'member_balance', 'checkout_request'],
                    'required_touched_roles' => ['cashier_workspace', 'checkout_request', 'member_balance'],
                ];
            },
            ['cashier_workspace', 'checkout_request', 'member_balance'],
            ['cashier_workspace', 'member', 'member_balance', 'checkout_request'],
            ['cashier_workspace', 'member', 'member_balance', 'checkout_request']
        ));
    }

    private static function registerSubmitCheckoutPolicy(
        CashierV3ActionDispatcher $dispatcher,
        CashierV3CheckoutSubmissionResourceDiscoveryComposite $submissionDiscovery
    ): void
    {
        if ($dispatcher->policies()->has('submit-checkout')) {
            throw new \LogicException('C2 cashier module: submit checkout context policy duplicate');
        }
        $policy = new CashierV3ContextPolicy(
            'submit-checkout',
            [],
            [],
            [$dispatcher->policies(), 'resolveCheckoutSubmitBranch'],
            [],
            ['service_order', 'hang_order', 'reservation', 'room'],
            ['service_order', 'hang_order', 'reservation', 'room'],
            true
        );
        $policy->configureServerResourceDiscovery(
            [$submissionDiscovery, 'discover'],
            [
                'checkout_member', 'checkout_entitlement_pool', 'checkout_card_holder',
                'checkout_catalog_card_definition', 'checkout_catalog_product',
                'checkout_catalog_sku', 'checkout_custom_card_configuration',
                'member', 'benefit_pool', 'card_holder', 'entitlement_debt_guard',
                'occupation_guard', 'performance_rule', 'staff', 'occupation',
                'inventory_policy', 'inventory_recipe', 'inventory_stock',
                'inventory_batch', 'inventory_shortage_cursor', 'checkout_member_balance',
            ],
            [
                'member', 'member_benefit_pool', 'card_holder',
                'catalog_card_definition', 'catalog_product', 'catalog_sku',
                'custom_card_configuration', 'entitlement_debt_guard',
                'entitlement_occupation_guard', 'performance_rule', 'staff_profile',
                'service_order', 'inventory_policy', 'inventory_recipe',
                'inventory_stock', 'inventory_batch', 'inventory_shortage_cursor',
                'member_balance',
            ]
        );
        $dispatcher->policies()->register($policy);
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

    /**
     * Projection 不能携带写命令 contexts；以服务端已认证的会话派生当前
     * 工作台 ID，随后仍由 workspace binding 校验门店、操作人和状态会话。
     */
    private static function workspaceIdForProjection(array $scope): string
    {
        $operatorScope = $scope['operator_scope'] ?? null;
        $stateContextId = trim((string)($scope['state_context_id'] ?? ''));
        if (!$operatorScope instanceof CashierV3OperatorScope || $stateContextId === '') {
            throw new CashierV3CommandException(
                CashierV3ResultCode::CLIENT_SESSION_REQUIRED,
                '当前收银工作台会话无效，请刷新页面后重试。',
                CashierV3ResultCode::STATUS_FAILED
            );
        }
        return \app\services\cashier\v3\CashierV3CheckoutWorkspaceIdentity::id(
            $operatorScope->storeId(),
            $stateContextId
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

    private static function registerClearCartLinesPolicy(CashierV3ActionDispatcher $dispatcher): void
    {
        if ($dispatcher->policies()->has('clear-cart-lines')) {
            throw new \LogicException('C2 cashier module: clear cart lines context policy duplicate');
        }
        $dispatcher->policies()->register(new CashierV3ContextPolicy(
            'clear-cart-lines',
            ['cashier_workspace'],
            [],
            static function (array $payload, array $base): array {
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
                // 目录点击只创建草稿行；商品可售、库存和卡项有效性统一在结账事务中校验。
                $resolved = self::workspaceOnlyPolicyResult($base);
                $resolved['expand_from_server_resource_discovery'] = true;
                $resolved['server_resource_discoverer'] = static function (array $scope) use ($saleCatalog, $payload): array {
                    return [
                        'resources' => $saleCatalog->discoverItemResources(
                            $payload['itemId'] ?? null,
                            $scope['operator_scope'],
                            $scope['data_scope']
                        ),
                    ];
                };
                return $resolved;
            },
            ['cashier_workspace'],
            ['cashier_workspace'],
            []
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
                    $resolved['server_resource_discoverer'] = static function (array $scope) use ($saleCatalog, $row, $delta): array {
                        $quantity = (int)($row['quantity'] ?? 0) + $delta;
                        return [
                            'resources' => $quantity > 0
                                ? $saleCatalog->discoverStoredLineResources(
                                    (array)$row,
                                    $quantity,
                                    $scope['operator_scope'],
                                    $scope['data_scope']
                                )
                                : [],
                        ];
                    };
                }
                return $resolved;
            },
            ['cashier_workspace'],
            ['cashier_workspace'],
            []
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
                            || array_key_exists('isExperience', $payload)
                            || array_key_exists('friendCountsAsCustomer', $payload)
                            || array_key_exists('laborManualFee', $payload);
                        $hasAttributions = array_key_exists('guideSelections', $payload)
                            || array_key_exists('salesManagerSelections', $payload);
                        $hasInventoryRule = array_key_exists('isPresale', $payload)
                            || array_key_exists('inventoryOutboundRequired', $payload);
                        if ($hasProjectSettings || (!$hasSalespeople && !$hasAttributions && !$hasInventoryRule)) {
                            throw new CashierV3CommandException(
                                CashierV3ResultCode::ENTITLEMENT_LINE_INVALID,
                                '该商品不支持设置服务对象、手艺人或体验标记。',
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

                // 编辑权益服务行只修改当前工作台草稿。会员、卡和权益次数
                // 都在最终结账事务内锁定并校验，不能提前作为本地草稿编辑的
                // contexts，否则浏览器仅携带工作台版本时会被错误拒绝。
                return $resolved;
            },
            ['cashier_workspace'],
            ['cashier_workspace'],
            []
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

    private static function registerMoreActionPolicies(
        CashierV3ActionDispatcher $dispatcher,
        CashierV3CashierReadinessGuard $readiness
    ): void {
        foreach ([
            'apply-cashier-salespeople-to-all-sale-lines',
            'apply-cashier-craftsmen-to-all-service-lines',
            'apply-cashier-personnel-to-all-lines',
            'update-cashier-line-debt',
            'update-cashier-order-note',
            'update-cashier-line-price',
            'update-cashier-supplement',
            'change-supplement-date',
            'exit-supplement',
        ] as $action) {
            if ($dispatcher->policies()->has($action)) {
                throw new \LogicException('C2 cashier module: more-action context policy duplicate');
            }
            $dispatcher->policies()->register(new CashierV3ContextPolicy(
                $action,
                ['cashier_workspace'],
                [],
                function (array $payload, array $base) use ($readiness): array {
                    $readiness->assertReady();
                    return self::workspaceOnlyPolicyResult($base);
                },
                ['cashier_workspace'],
                ['cashier_workspace'],
                []
            ));
        }
    }

    private static function registerLineCouponPolicies(
        CashierV3ActionDispatcher $dispatcher,
        CashierV3CashierReadinessGuard $readiness
    ): void {
        foreach (['apply-line-coupon', 'remove-line-coupon'] as $action) {
            if ($dispatcher->policies()->has($action)) {
                throw new \LogicException('C2 cashier module: line coupon context policy duplicate');
            }
            $dispatcher->policies()->register(new CashierV3ContextPolicy(
                $action,
                ['cashier_workspace'],
                [],
                function (array $payload, array $base) use ($readiness): array {
                    $readiness->assertReady();
                    return self::workspaceOnlyPolicyResult($base);
                },
                ['cashier_workspace'],
                ['cashier_workspace'],
                []
            ));
        }
    }

}
