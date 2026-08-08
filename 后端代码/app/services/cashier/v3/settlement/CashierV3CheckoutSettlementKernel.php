<?php

namespace app\services\cashier\v3\settlement;

/**
 * Isolated checkout-request and settlement-draft domain kernel.
 *
 * Inputs named $authoritativeSnapshot must be assembled by the future server
 * orchestrator from business authorities under the final lock set. This class
 * never accepts client money as authority and never writes payment, sale,
 * balance, debt, entitlement, performance, event or outbox facts.
 */
final class CashierV3CheckoutSettlementKernel
{
    public const CONTRACT_VERSION = 'cashier-v3-checkout-settlement-v1';
    public const AUTHORITY_CONTRACT_VERSION = 'cashier-v3-checkout-authority-snapshot-v1';

    public const OPERATION_SAVE_DRAFT = 'save_draft';
    public const OPERATION_PREPARE_SUBMISSION = 'prepare_submission';
    public const OPERATION_SUBMIT = 'submit';

    public const COMPOSITION_SALE_ONLY = 'sale_only';
    public const COMPOSITION_ENTITLEMENT_ONLY = 'entitlement_only';
    public const COMPOSITION_MIXED = 'mixed';

    public const PAYMENT_UNIONPAY = 'unionpay';
    public const PAYMENT_WECHAT = 'wechat';
    public const PAYMENT_ALIPAY = 'alipay';
    public const PAYMENT_DIANPING_VOUCHER = 'dianping_voucher';
    public const PAYMENT_DOUYIN_VOUCHER = 'douyin_voucher';
    public const PAYMENT_PARTNER_COLLECTION = 'partner_collection';
    public const PAYMENT_OTHER_COLLECTION = 'other_collection';
    public const PAYMENT_OLD_CARD_ENTRY = 'old_card_entry';

    private const PAYMENT_METHODS = [
        self::PAYMENT_UNIONPAY,
        self::PAYMENT_WECHAT,
        self::PAYMENT_ALIPAY,
        self::PAYMENT_DIANPING_VOUCHER,
        self::PAYMENT_DOUYIN_VOUCHER,
        self::PAYMENT_PARTNER_COLLECTION,
        self::PAYMENT_OTHER_COLLECTION,
    ];

    private const SALE_SOURCE_TYPES = ['product', 'card', 'project'];
    // 历史 card_holder 没有完整卡型字段。unknown 是可追溯的遗留来源，
    // 不能在结算层伪造成次卡或时间卡；实际核销内核也会按 unknown 留存事实。
    private const ENTITLEMENT_SOURCE_KINDS = ['count_card', 'time_card', 'custom_card', 'gift', 'unknown'];
    private const MAX_LINES = 200;
    private const MAX_MONEY_CENTS = 100000000000;
    private const MAX_QUANTITY = 1000000;
    private const MAX_TIMESTAMP = 4294967295;

    public static function saveDraft(
        array $command,
        array $authoritativeSnapshot,
        ?array $currentRequest,
        string $serverIdSecret
    ): array {
        return self::build(
            $command,
            $authoritativeSnapshot,
            $currentRequest,
            $serverIdSecret,
            self::OPERATION_SAVE_DRAFT,
            CashierV3CheckoutSettlementStateMachine::EDITING,
            false
        );
    }

    public static function prepareSubmission(
        array $command,
        array $authoritativeSnapshot,
        ?array $currentRequest,
        string $serverIdSecret
    ): array {
        return self::build(
            $command,
            $authoritativeSnapshot,
            $currentRequest,
            $serverIdSecret,
            self::OPERATION_PREPARE_SUBMISSION,
            CashierV3CheckoutSettlementStateMachine::READY_FOR_SUBMIT,
            true
        );
    }

    /**
     * Submission is intentionally unavailable until all atomic writers and
     * the shared event/outbox transaction are integrated.
     */
    public static function submit(array $command, array $currentRequest): array
    {
        throw self::failure('checkout_submit_integration_not_ready', [
            'contractVersion' => self::CONTRACT_VERSION,
            'operation' => self::OPERATION_SUBMIT,
            'statusUnchanged' => true,
            'businessEffectsWritten' => false,
            'businessEffects' => self::emptyBusinessEffects(),
            'integrationRequirements' => self::integrationRequirements(),
        ]);
    }

    /**
     * The future lock-owning orchestrator uses this helper after it has built
     * the complete snapshot. The fingerprint is integrity, not authentication.
     */
    public static function authorityFingerprint(array $snapshot): string
    {
        unset($snapshot['authoritySnapshotFingerprint']);
        return CashierV3CheckoutSettlementCanonicalizer::fingerprint($snapshot);
    }

    public static function paymentMethods(): array
    {
        return self::PAYMENT_METHODS;
    }

    public static function legacyEntryContract(): array
    {
        return [
            'methodCode' => self::PAYMENT_OLD_CARD_ENTRY,
            'separateOperationRequired' => true,
            'combinableWithCheckoutSettlement' => false,
            'cashPerformanceAmountCents' => 0,
            'paymentCollectedFactCount' => 0,
            'implementationStatus' => 'not_integrated',
        ];
    }

    public static function performanceDefinitions(): array
    {
        return [
            'salespersonPerformance' => [
                'grain' => 'one_salesperson_allocation_per_formal_sale_line',
                'basis' => 'final_allocated_cash_performance',
                'implementationStatus' => 'not_integrated',
            ],
            'actualPerformance' => [
                'formula' => 'cashPerformanceCents - externalSalespersonAllocatedCashPerformanceCents',
                'deductedSalespersonTypes' => ['partner', 'outsourced'],
                'employeeTypeSnapshotAtBusinessTimeRequired' => true,
                'historicalEmployeeTypeChangesMustNotRewriteFacts' => true,
                'implementationStatus' => 'not_integrated',
            ],
        ];
    }

    /**
     * Product-manager-approved user wording. Technical success-state,
     * idempotency and reversal rules may add constraints but never rename or
     * rewrite these definitions at another client.
     */
    public static function metricDefinitions(): array
    {
        return [
            '销售额' => '正式成交的商品、卡项、项目金额，包含现金业绩、余额扣款和欠款覆盖的部分。',
            '现金业绩' => '银联、微信、支付宝、大众验券、抖音验券、合作方收款、其他收款。',
            '收款明细' => '每一种记账收款分别保存的金额、方式、业务时间、操作人和来源单据。',
            '欠款' => '应收金额中本次未收、允许以后补交的金额；使用七种记账方式补交产生现金业绩。',
            '储值金额' => '充值成功写入会员账户的本金，赠送金额单独记录；使用七种记账方式充值时产生现金业绩。',
            '余额扣款' => '使用会员已有储值余额支付订单的金额，不属于现金业绩。',
            '销售人业绩' => '销售商品明细按最终分配规则归属给所选销售人的现金业绩。',
            '实际业绩' => '现金业绩减去分配给“合作方”或“外包”销售人的业绩。销售人在员工管理中设置人员类型；普通内部员工分配的销售人业绩不扣。员工类型和分配结果按业务发生时保存，后来改人员类型不反改历史数据。',
            '消耗业绩' => '项目真正完成服务并核销后才产生的项目消耗业绩。',
            '劳动业绩' => '项目完成后，按分配规则归属给实际手艺人的业绩。',
            '实际提成' => '在有效业绩基础上，按员工提成规则计算出的薪酬结果',
        ];
    }

    public static function integrationRequirements(): array
    {
        return [
            'cashier_v3_gateway_transaction',
            'c1_command_receipt_historical_idempotency_replay',
            'server_id_namespace_secret_provider',
            'final_authority_lock_recheck',
            'authoritative_sale_order_writer',
            'seven_method_payment_collection_writer',
            'member_balance_atomic_writer',
            'debt_atomic_writer',
            'c2_entitlement_completion_v3',
            'inventory-entitlement-completion-provider-v1',
            'salesperson_allocation_and_employee_type_snapshot',
            'actual_performance_fact_writer',
            'cashier_v3_business_event_and_outbox',
            'reversal_reconciliation_and_source_fact_checks',
        ];
    }

    public static function inventoryIntegrationContract(): array
    {
        return [
            'providerContractVersion' => 'inventory-entitlement-completion-provider-v1',
            'consumerContractVersion' => 'c2-entitlement-completion-v3',
            'activationStatus' => 'blocked_until_final_gateway_transaction_review',
            'gatewayActivationAllowed' => false,
            'finalBusinessTransactionOwnsProviderCall' => true,
            'dataScope' => ['tenant', 'store'],
            'lockOrder' => [
                'inventory_policy' => 46,
                'inventory_recipe' => 47,
                'inventory_stock' => 50,
                'inventory_batch' => 55,
                'inventory_shortage_cursor' => 56,
            ],
            'policyAndRecipeFields' => [
                'merchantDefaultPolicy',
                'merchantDefaultPolicyVersion',
                'productPolicyOverride',
                'productPolicyVersion',
                'policy',
                'policyVersion',
                'recipeId',
                'recipeVersion',
                'recipeFormulaHash',
            ],
            'consumableFields' => [
                'stockId',
                'quantityUnitsPerService',
                'shortageEstimatedUnitCostCents',
                'shortageCostAllocatedQuantityUnitsBefore',
                'shortageCursorId',
                'shortageCursorVersion',
            ],
            'stockFields' => ['stockVersion', 'stockUnitScale', 'batches'],
            'batchFields' => [
                'batchId',
                'batchVersion',
                'availableQuantityUnits',
                'unitCostCents',
                'costAllocatedQuantityUnitsBefore',
                'allocationOrder',
            ],
            'strictShortageBlocksAll' => true,
            'allowShortageConsumesRealBatchesOnly' => true,
            'negativeOrSyntheticBatchForbidden' => true,
            'shortageCursorLockGate' => 'explicit_resource_plan_locked_v1',
            'shortageCursorStableResourceId' => 'stockId:recipeId:estimatedUnitCostCents',
            'lockedInventoryResourcesMustCoverEveryShortageCursor' => true,
            'providerOwnsNaturalKeyIdempotencyReversalAndCostAdjustment' => true,
        ];
    }

    private static function build(
        array $command,
        array $authoritativeSnapshot,
        ?array $currentRequest,
        string $serverIdSecret,
        string $expectedOperation,
        string $targetStatus,
        bool $requireBalanced
    ): array {
        $intent = self::normalizeCommand($command, $expectedOperation);
        $snapshot = self::normalizeAuthoritySnapshot(
            $authoritativeSnapshot,
            $expectedOperation === self::OPERATION_SAVE_DRAFT
        );
        self::assertIntentMatchesSnapshot($intent, $snapshot);
        $current = self::normalizeCurrentRequest($currentRequest);
        $idFactory = new CashierV3CheckoutSettlementIdFactory($serverIdSecret);

        $composition = self::classifyComposition($snapshot['saleLines'], $snapshot['entitlementLines']);
        $salesAmountCents = self::sumField($snapshot['saleLines'], 'saleAmountCents');
        $entitlementActualAmountCents = self::sumField(
            $snapshot['entitlementLines'],
            'actualEntitlementAmountCents'
        );
        $selectedPaymentAmountCents = self::sumField($snapshot['paymentDetails'], 'amountCents');
        $balanceAmountCents = $snapshot['balanceDeduction']['amountCents'];
        $debtAmountCents = $snapshot['debt']['amountCents'];
        if ($debtAmountCents > $salesAmountCents) {
            throw self::failure('checkout_debt_amount_exceeds_sales_amount', [
                'salesAmountCents' => $salesAmountCents,
                'debtAmountCents' => $debtAmountCents,
            ]);
        }
        // Debt is recorded separately and is not a collected payment. The
        // amount due now is the sale total less the debt; only payment methods
        // and balance deductions are compared against that receivable.
        $settlementAmountCents = self::safeAdd(
            $selectedPaymentAmountCents,
            $balanceAmountCents,
            'settlement_total'
        );
        $receivableAmountCents = $salesAmountCents - $debtAmountCents;
        $balanced = $settlementAmountCents === $receivableAmountCents;

        if ($composition === self::COMPOSITION_ENTITLEMENT_ONLY && $settlementAmountCents !== 0) {
            throw self::failure('entitlement_only_settlement_must_be_zero', [
                'settlementAmountCents' => $settlementAmountCents,
            ]);
        }
        if ($requireBalanced && !$balanced) {
            throw self::failure('checkout_receivable_not_balanced', [
                'receivableAmountCents' => $receivableAmountCents,
                'selectedPaymentAmountCents' => $selectedPaymentAmountCents,
                'balanceDeductionAmountCents' => $balanceAmountCents,
                'debtAmountCents' => $debtAmountCents,
                'differenceCents' => $receivableAmountCents - $settlementAmountCents,
            ]);
        }

        $aggregateFingerprint = CashierV3CheckoutSettlementCanonicalizer::fingerprint([
            'contractVersion' => self::CONTRACT_VERSION,
            'composition' => $composition,
            'authoritySnapshot' => $snapshot,
        ]);
        $operationFingerprint = CashierV3CheckoutSettlementCanonicalizer::fingerprint([
            'contractVersion' => self::CONTRACT_VERSION,
            'operation' => $expectedOperation,
            'targetStatus' => $targetStatus,
            'aggregateFingerprint' => $aggregateFingerprint,
        ]);

        $replayed = false;
        $expectedVersion = null;
        if ($current === null) {
            if ($intent['requestId'] !== '' || $intent['expectedVersion'] !== null) {
                throw self::failure('new_checkout_request_identity_must_be_server_generated');
            }
            $requestId = $idFactory->requestId(
                $snapshot['tenantId'],
                $snapshot['workspaceId'],
                $intent['idempotencyKey']
            );
            $creationIdempotencyKey = $intent['idempotencyKey'];
            $nextVersion = 1;
            $persistenceMode = 'insert';
        } else {
            self::assertCurrentMatchesIntentAndSnapshot($current, $intent, $snapshot);
            $requestId = $current['requestId'];
            $creationIdempotencyKey = $current['creationIdempotencyKey'];
            if ($current['lastIdempotencyKey'] === $intent['idempotencyKey']) {
                if (!hash_equals($current['lastOperationFingerprint'], $operationFingerprint)) {
                    throw self::failure('checkout_idempotency_key_conflict', [
                        'requestId' => $requestId,
                    ]);
                }
                $replayed = true;
                $nextVersion = $current['version'];
                $targetStatus = $current['status'];
                $persistenceMode = 'none';
            } else {
                if ($intent['expectedVersion'] === null
                    || $intent['expectedVersion'] !== $current['version']) {
                    throw self::failure('checkout_request_version_conflict', [
                        'requestId' => $requestId,
                        'expectedVersion' => $intent['expectedVersion'],
                        'currentVersion' => $current['version'],
                    ]);
                }
                CashierV3CheckoutSettlementStateMachine::assertDraftWritable($current['status']);
                CashierV3CheckoutSettlementStateMachine::assertTransition($current['status'], $targetStatus);
                if ($current['version'] >= PHP_INT_MAX) {
                    throw self::failure('checkout_request_version_overflow');
                }
                $expectedVersion = $current['version'];
                $nextVersion = $current['version'] + 1;
                $persistenceMode = 'cas_replace_children';
            }
        }

        $lineDrafts = self::buildLineDrafts($snapshot, $requestId, $nextVersion, $idFactory);
        $paymentDrafts = self::buildPaymentDrafts($snapshot, $requestId, $nextVersion, $idFactory);
        $requestRow = self::buildRequestRow(
            $snapshot,
            $requestId,
            $nextVersion,
            $targetStatus,
            $composition,
            $creationIdempotencyKey,
            $intent['idempotencyKey'],
            $salesAmountCents,
            $receivableAmountCents,
            $selectedPaymentAmountCents,
            $balanceAmountCents,
            $debtAmountCents,
            $entitlementActualAmountCents,
            $aggregateFingerprint,
            $operationFingerprint,
            $expectedOperation
        );

        return [
            'contractVersion' => self::CONTRACT_VERSION,
            'authorityContractVersion' => self::AUTHORITY_CONTRACT_VERSION,
            'operation' => $expectedOperation,
            'requestId' => $requestId,
            'requestVersion' => $nextVersion,
            'requestStatus' => $targetStatus,
            'composition' => $composition,
            'replayed' => $replayed,
            'eventless' => true,
            'authoritySnapshotFingerprint' => $snapshot['authoritySnapshotFingerprint'],
            'aggregateFingerprint' => $aggregateFingerprint,
            'operationFingerprint' => $operationFingerprint,
            'totals' => [
                'salesAmountCents' => $salesAmountCents,
                'receivableAmountCents' => $receivableAmountCents,
                'selectedPaymentAmountCents' => $selectedPaymentAmountCents,
                'balanceDeductionAmountCents' => $balanceAmountCents,
                'debtAmountCents' => $debtAmountCents,
                'settlementAmountCents' => $settlementAmountCents,
                'cashPerformanceAmountCents' => $selectedPaymentAmountCents,
                'entitlementActualAmountCents' => $entitlementActualAmountCents,
                'balanced' => $balanced,
            ],
            'lineDrafts' => $lineDrafts,
            'paymentDrafts' => $paymentDrafts,
            'balanceDeductionDraft' => $snapshot['balanceDeduction'],
            'debtDraft' => $snapshot['debt'],
            'performanceDefinitions' => self::performanceDefinitions(),
            'metricDefinitions' => self::metricDefinitions(),
            'persistencePlan' => [
                'mode' => $persistenceMode,
                'requestTable' => 'eb_cashier_v3_checkout_request',
                'lineTable' => 'eb_cashier_v3_checkout_line_draft',
                'paymentTable' => 'eb_cashier_v3_checkout_payment_draft',
                'request' => $requestRow,
                'lineDrafts' => $lineDrafts,
                'paymentDrafts' => $paymentDrafts,
                'cas' => [
                    'requestId' => $requestId,
                    'expectedVersion' => $expectedVersion,
                    'nextVersion' => $nextVersion,
                ],
                'replaceChildrenOnlyUnderLockedRequest' => true,
                'affectedRequestRowsMustEqual' => $persistenceMode === 'cas_replace_children' ? 1 : null,
            ],
            'businessEffects' => self::emptyBusinessEffects(),
            'submitAvailable' => false,
            'integrationRequirements' => self::integrationRequirements(),
        ];
    }

    private static function normalizeCommand(array $command, string $expectedOperation): array
    {
        self::assertExactKeys(
            $command,
            [
                'contractVersion',
                'operation',
                'idempotencyKey',
                'workspaceId',
                'stateContextId',
                'permissionSnapshotFingerprint',
            ],
            ['requestId', 'expectedVersion'],
            'command'
        );
        if ($command['contractVersion'] !== self::CONTRACT_VERSION) {
            throw self::failure('checkout_contract_version_mismatch');
        }
        if ($command['operation'] !== $expectedOperation) {
            throw self::failure('checkout_operation_mismatch', [
                'expected' => $expectedOperation,
                'actual' => $command['operation'],
            ]);
        }

        $idempotencyKey = self::idempotencyKey($command['idempotencyKey']);
        $requestId = '';
        if (array_key_exists('requestId', $command)) {
            $requestId = self::opaqueId($command['requestId'], 'CKR', 'command.requestId');
        }
        $expectedVersion = null;
        if (array_key_exists('expectedVersion', $command)) {
            $expectedVersion = self::positiveInt($command['expectedVersion'], 'command.expectedVersion');
        }
        return [
            'contractVersion' => self::CONTRACT_VERSION,
            'operation' => $expectedOperation,
            'idempotencyKey' => $idempotencyKey,
            'workspaceId' => self::token($command['workspaceId'], 64, 'command.workspaceId'),
            'stateContextId' => self::token($command['stateContextId'], 64, 'command.stateContextId'),
            'permissionSnapshotFingerprint' => self::fingerprintToken(
                $command['permissionSnapshotFingerprint'],
                'command.permissionSnapshotFingerprint'
            ),
            'requestId' => $requestId,
            'expectedVersion' => $expectedVersion,
        ];
    }

    private static function normalizeAuthoritySnapshot(
        array $snapshot,
        bool $allowZeroPaymentDrafts
    ): array
    {
        self::assertExactKeys(
            $snapshot,
            [
                'contractVersion',
                'authorityOrigin',
                'authoritySnapshotVersion',
                'authoritySnapshotFingerprint',
                'tenantId',
                'organizationId',
                'organizationPath',
                'organizationName',
                'storeId',
                'storeName',
                'workspaceId',
                'stateContextId',
                'permissionSnapshotFingerprint',
                'memberId',
                'memberName',
                'operatorId',
                'operatorName',
                'businessDate',
                'businessTimezone',
                'occurredAt',
                'recordedAt',
                'orderNote',
                'supplement',
                'sourceDocument',
                'saleLines',
                'entitlementLines',
                'paymentDetails',
                'balanceDeduction',
                'debt',
            ],
            [],
            'authoritySnapshot'
        );
        if ($snapshot['contractVersion'] !== self::AUTHORITY_CONTRACT_VERSION) {
            throw self::failure('authority_contract_version_mismatch');
        }
        if ($snapshot['authorityOrigin'] !== 'server_final_lock_snapshot') {
            throw self::failure('authority_origin_invalid');
        }
        $expectedFingerprint = self::fingerprintToken(
            $snapshot['authoritySnapshotFingerprint'],
            'authoritySnapshot.authoritySnapshotFingerprint',
            true
        );
        $actualFingerprint = self::authorityFingerprint($snapshot);
        if (!hash_equals($expectedFingerprint, $actualFingerprint)) {
            throw self::failure('authority_snapshot_fingerprint_mismatch');
        }

        $occurredAt = self::timestamp($snapshot['occurredAt'], 'authoritySnapshot.occurredAt');
        $recordedAt = self::timestamp($snapshot['recordedAt'], 'authoritySnapshot.recordedAt');
        if ($recordedAt < $occurredAt) {
            throw self::failure('recorded_at_before_occurred_at');
        }
        $timezone = self::text($snapshot['businessTimezone'], 64, 'authoritySnapshot.businessTimezone', false);
        if ($timezone !== 'Asia/Shanghai') {
            throw self::failure('business_timezone_not_supported', ['timezone' => $timezone]);
        }

        $saleLines = self::normalizeSaleLines($snapshot['saleLines']);
        $entitlementLines = self::normalizeEntitlementLines($snapshot['entitlementLines']);
        if (count($saleLines) + count($entitlementLines) > self::MAX_LINES) {
            throw self::failure('checkout_line_limit_exceeded');
        }
        $paymentDetails = self::normalizePayments(
            $snapshot['paymentDetails'],
            $occurredAt,
            $allowZeroPaymentDrafts
        );
        $memberId = self::nonNegativeInt($snapshot['memberId'], 'authoritySnapshot.memberId');
        $memberName = self::text(
            $snapshot['memberName'],
            128,
            'authoritySnapshot.memberName',
            true
        );
        if ($memberId > 0 && $memberName === '') {
            throw self::failure('member_snapshot_incomplete');
        }
        if (count($entitlementLines) > 0 && $memberId <= 0) {
            throw self::failure('entitlement_member_required');
        }
        $balance = self::normalizeBalance($snapshot['balanceDeduction'], $memberId);
        $debt = self::normalizeDebt($snapshot['debt'], $memberId);
        if (self::sumField($saleLines, 'debtAmountCents') !== $debt['amountCents']) {
            throw self::failure('sale_line_debt_total_mismatch');
        }
        $orderNote = self::text($snapshot['orderNote'], 500, 'authoritySnapshot.orderNote', true);
        $supplement = $snapshot['supplement'];
        self::assertExactKeys(
            $supplement,
            ['enabled', 'reason', 'operatorId', 'operatorNameSnapshot', 'operatedAt'],
            [],
            'authoritySnapshot.supplement'
        );
        $supplementEnabled = $supplement['enabled'] === true;
        $supplementReason = self::text(
            $supplement['reason'],
            255,
            'authoritySnapshot.supplement.reason',
            true
        );
        $supplementOperatorId = self::nonNegativeInt(
            $supplement['operatorId'],
            'authoritySnapshot.supplement.operatorId'
        );
        $supplementOperatorName = self::text(
            $supplement['operatorNameSnapshot'],
            128,
            'authoritySnapshot.supplement.operatorNameSnapshot',
            true
        );
        $supplementOperatedAt = self::nonNegativeInt(
            $supplement['operatedAt'],
            'authoritySnapshot.supplement.operatedAt'
        );
        if ($supplementEnabled
            ? ($supplementReason === '' || $supplementOperatorId <= 0
                || $supplementOperatorName === '' || $supplementOperatedAt <= 0)
            : ($supplementReason !== '' || $supplementOperatorId !== 0
                || $supplementOperatorName !== '' || $supplementOperatedAt !== 0)) {
            throw self::failure('supplement_audit_invalid');
        }

        return [
            'contractVersion' => self::AUTHORITY_CONTRACT_VERSION,
            'authorityOrigin' => 'server_final_lock_snapshot',
            'authoritySnapshotVersion' => self::positiveInt(
                $snapshot['authoritySnapshotVersion'],
                'authoritySnapshot.authoritySnapshotVersion'
            ),
            'authoritySnapshotFingerprint' => $expectedFingerprint,
            'tenantId' => self::token($snapshot['tenantId'], 32, 'authoritySnapshot.tenantId'),
            'organizationId' => self::token(
                $snapshot['organizationId'],
                32,
                'authoritySnapshot.organizationId'
            ),
            'organizationPath' => self::organizationPath($snapshot['organizationPath']),
            'organizationName' => self::text(
                $snapshot['organizationName'],
                128,
                'authoritySnapshot.organizationName',
                false
            ),
            'storeId' => self::positiveInt($snapshot['storeId'], 'authoritySnapshot.storeId'),
            'storeName' => self::text($snapshot['storeName'], 128, 'authoritySnapshot.storeName', false),
            'workspaceId' => self::token($snapshot['workspaceId'], 64, 'authoritySnapshot.workspaceId'),
            'stateContextId' => self::token(
                $snapshot['stateContextId'],
                64,
                'authoritySnapshot.stateContextId'
            ),
            'permissionSnapshotFingerprint' => self::fingerprintToken(
                $snapshot['permissionSnapshotFingerprint'],
                'authoritySnapshot.permissionSnapshotFingerprint'
            ),
            'memberId' => $memberId,
            'memberName' => $memberName,
            'operatorId' => self::positiveInt($snapshot['operatorId'], 'authoritySnapshot.operatorId'),
            'operatorName' => self::text(
                $snapshot['operatorName'],
                128,
                'authoritySnapshot.operatorName',
                false
            ),
            'businessDate' => self::businessDate($snapshot['businessDate']),
            'businessTimezone' => $timezone,
            'occurredAt' => $occurredAt,
            'recordedAt' => $recordedAt,
            'orderNote' => $orderNote,
            'supplement' => [
                'enabled' => $supplementEnabled,
                'reason' => $supplementReason,
                'operatorId' => $supplementOperatorId,
                'operatorNameSnapshot' => $supplementOperatorName,
                'operatedAt' => $supplementOperatedAt,
            ],
            'sourceDocument' => self::normalizeSourceDocument($snapshot['sourceDocument']),
            'saleLines' => $saleLines,
            'entitlementLines' => $entitlementLines,
            'paymentDetails' => $paymentDetails,
            'balanceDeduction' => $balance,
            'debt' => $debt,
        ];
    }

    private static function normalizeSaleLines($rawLines): array
    {
        if (!is_array($rawLines) || !self::isList($rawLines)) {
            throw self::failure('sale_lines_shape_invalid');
        }
        $result = [];
        $keys = [];
        foreach ($rawLines as $index => $line) {
            if (!is_array($line)) {
                throw self::failure('sale_line_shape_invalid', ['index' => $index]);
            }
            self::assertExactKeys($line, [
                'authorityKey',
                'saleClassification',
                'sourceType',
                'sourceId',
                'sourceVersion',
                'quantity',
                'originalAmountCents',
                'discountAmountCents',
                'saleAmountCents',
                'debtAmountCents',
                'sourceNameSnapshot',
                'sourceCodeSnapshot',
                'categoryIdSnapshot',
                'categoryNameSnapshot',
                'configuredCostCents',
                'priceChangeReason',
                'priceChangedBy',
                'priceChangedByNameSnapshot',
                'priceChangedAt',
                'craftsmen',
            ], ['catalogSkuId', 'serviceObject', 'isExperience'], 'saleLines[' . $index . ']');
            if ($line['saleClassification'] !== 'formal_sale') {
                throw self::failure('sale_line_not_formal', ['index' => $index]);
            }
            if (!in_array($line['sourceType'], self::SALE_SOURCE_TYPES, true)) {
                throw self::failure('sale_source_type_invalid', ['index' => $index]);
            }
            $authorityKey = self::token($line['authorityKey'], 96, 'saleLine.authorityKey');
            if (isset($keys[$authorityKey])) {
                throw self::failure('sale_authority_key_duplicate', ['authorityKey' => $authorityKey]);
            }
            $keys[$authorityKey] = true;
            $original = self::money($line['originalAmountCents'], 'saleLine.originalAmountCents');
            $discount = self::money($line['discountAmountCents'], 'saleLine.discountAmountCents');
            $sale = self::money($line['saleAmountCents'], 'saleLine.saleAmountCents');
            $lineDebt = self::money($line['debtAmountCents'], 'saleLine.debtAmountCents');
            $quantity = self::boundedQuantity($line['quantity'], 'saleLine.quantity');
            $configuredCost = self::money($line['configuredCostCents'], 'saleLine.configuredCostCents');
            $priceChangeReason = self::text(
                $line['priceChangeReason'],
                255,
                'saleLine.priceChangeReason',
                true
            );
            $priceChangedBy = self::nonNegativeInt($line['priceChangedBy'], 'saleLine.priceChangedBy');
            $priceChangedByName = self::text(
                $line['priceChangedByNameSnapshot'],
                128,
                'saleLine.priceChangedByNameSnapshot',
                true
            );
            $priceChangedAt = self::nonNegativeInt($line['priceChangedAt'], 'saleLine.priceChangedAt');
            if ($discount > $original || $sale !== $original - $discount || $lineDebt > $sale) {
                throw self::failure('sale_line_amount_equation_invalid', ['authorityKey' => $authorityKey]);
            }
            $costOverflow = $configuredCost > 0
                && $quantity > intdiv(PHP_INT_MAX, $configuredCost);
            if ($priceChangedAt === 0
                ? ($priceChangeReason !== '' || $priceChangedBy !== 0 || $priceChangedByName !== '')
                : ($priceChangeReason === '' || $priceChangedBy <= 0 || $priceChangedByName === ''
                    || $costOverflow || $sale < $configuredCost * $quantity)) {
                throw self::failure('sale_line_price_audit_invalid', ['authorityKey' => $authorityKey]);
            }
            $categoryId = self::nonNegativeInt($line['categoryIdSnapshot'], 'saleLine.categoryIdSnapshot');
            $categoryName = self::text(
                $line['categoryNameSnapshot'],
                128,
                'saleLine.categoryNameSnapshot',
                true
            );
            if ($categoryId > 0 && $categoryName === '') {
                throw self::failure('sale_line_category_snapshot_incomplete', ['authorityKey' => $authorityKey]);
            }
            $catalogSkuId = self::nonNegativeInt(
                $line['catalogSkuId'] ?? 0,
                'saleLine.catalogSkuId'
            );
            $serviceObject = self::text(
                $line['serviceObject'] ?? '',
                16,
                'saleLine.serviceObject',
                true
            );
            if (!in_array($serviceObject, ['', 'self', 'friend'], true)) {
                throw self::failure('sale_line_service_object_invalid', ['authorityKey' => $authorityKey]);
            }
            $isExperience = self::nonNegativeInt(
                $line['isExperience'] ?? 0,
                'saleLine.isExperience'
            );
            if ($isExperience > 1) {
                throw self::failure('sale_line_is_experience_invalid', ['authorityKey' => $authorityKey]);
            }
            try {
                $craftsmen = CashierV3CheckoutCraftsmenSnapshot::normalize($line['craftsmen']);
            } catch (\Throwable $exception) {
                throw self::failure('sale_line_craftsmen_snapshot_invalid', [
                    'authorityKey' => $authorityKey,
                ]);
            }
            if ($line['sourceType'] === 'project') {
                if (!in_array($serviceObject, ['self', 'friend'], true)) {
                    throw self::failure('sale_line_project_service_object_invalid', ['authorityKey' => $authorityKey]);
                }
            } elseif ($serviceObject !== '' || $isExperience !== 0 || $craftsmen !== []) {
                throw self::failure('sale_line_non_project_service_tags_invalid', ['authorityKey' => $authorityKey]);
            }
            $normalized = [
                'authorityKey' => $authorityKey,
                'saleClassification' => 'formal_sale',
                'sourceType' => $line['sourceType'],
                'sourceId' => self::positiveInt($line['sourceId'], 'saleLine.sourceId'),
                'catalogSkuId' => $catalogSkuId,
                'sourceVersion' => self::positiveInt($line['sourceVersion'], 'saleLine.sourceVersion'),
                'quantity' => $quantity,
                'originalAmountCents' => $original,
                'discountAmountCents' => $discount,
                'saleAmountCents' => $sale,
                'debtAmountCents' => $lineDebt,
                'sourceNameSnapshot' => self::text(
                    $line['sourceNameSnapshot'],
                    128,
                    'saleLine.sourceNameSnapshot',
                    false
                ),
                'sourceCodeSnapshot' => self::text(
                    $line['sourceCodeSnapshot'],
                    64,
                    'saleLine.sourceCodeSnapshot',
                    true
                ),
                'categoryIdSnapshot' => $categoryId,
                'categoryNameSnapshot' => $categoryName,
                'configuredCostCents' => $configuredCost,
                'priceChangeReason' => $priceChangeReason,
                'priceChangedBy' => $priceChangedBy,
                'priceChangedByNameSnapshot' => $priceChangedByName,
                'priceChangedAt' => $priceChangedAt,
                'serviceObject' => $serviceObject,
                'craftsmen' => $craftsmen,
                'isExperience' => $isExperience,
            ];
            $fingerprintInput = $normalized;
            if ($catalogSkuId <= 0) {
                unset($fingerprintInput['catalogSkuId']);
            }
            $normalized['lineFingerprint'] = CashierV3CheckoutSettlementCanonicalizer::fingerprint($fingerprintInput);
            $result[] = $normalized;
        }
        return $result;
    }

    private static function normalizeEntitlementLines($rawLines): array
    {
        if (!is_array($rawLines) || !self::isList($rawLines)) {
            throw self::failure('entitlement_lines_shape_invalid');
        }
        $result = [];
        $keys = [];
        foreach ($rawLines as $index => $line) {
            if (!is_array($line)) {
                throw self::failure('entitlement_line_shape_invalid', ['index' => $index]);
            }
            self::assertExactKeys($line, [
                'authorityKey',
                'sourceKind',
                'holderId',
                'entitlementSourceDetailId',
                'sourceVersion',
                'projectId',
                'projectVersion',
                'quantity',
                'actualEntitlementAmountCents',
                'sourceNameSnapshot',
                'sourceCodeSnapshot',
                'projectNameSnapshot',
                'projectCategoryIdSnapshot',
                'projectCategoryNameSnapshot',
            ], [], 'entitlementLines[' . $index . ']');
            if (!in_array($line['sourceKind'], self::ENTITLEMENT_SOURCE_KINDS, true)) {
                throw self::failure('entitlement_source_kind_invalid', ['index' => $index]);
            }
            $authorityKey = self::token($line['authorityKey'], 96, 'entitlementLine.authorityKey');
            if (isset($keys[$authorityKey])) {
                throw self::failure('entitlement_authority_key_duplicate', ['authorityKey' => $authorityKey]);
            }
            $keys[$authorityKey] = true;
            $categoryId = self::nonNegativeInt(
                $line['projectCategoryIdSnapshot'],
                'entitlementLine.projectCategoryIdSnapshot'
            );
            $categoryName = self::text(
                $line['projectCategoryNameSnapshot'],
                128,
                'entitlementLine.projectCategoryNameSnapshot',
                true
            );
            if ($categoryId > 0 && $categoryName === '') {
                throw self::failure('entitlement_category_snapshot_incomplete', [
                    'authorityKey' => $authorityKey,
                ]);
            }
            $normalized = [
                'authorityKey' => $authorityKey,
                'sourceKind' => $line['sourceKind'],
                'holderId' => self::positiveInt($line['holderId'], 'entitlementLine.holderId'),
                'entitlementSourceDetailId' => self::positiveInt(
                    $line['entitlementSourceDetailId'],
                    'entitlementLine.entitlementSourceDetailId'
                ),
                'sourceVersion' => self::positiveInt(
                    $line['sourceVersion'],
                    'entitlementLine.sourceVersion'
                ),
                'projectId' => self::positiveInt($line['projectId'], 'entitlementLine.projectId'),
                'projectVersion' => self::positiveInt(
                    $line['projectVersion'],
                    'entitlementLine.projectVersion'
                ),
                'quantity' => self::boundedQuantity($line['quantity'], 'entitlementLine.quantity'),
                'actualEntitlementAmountCents' => self::money(
                    $line['actualEntitlementAmountCents'],
                    'entitlementLine.actualEntitlementAmountCents'
                ),
                'sourceNameSnapshot' => self::text(
                    $line['sourceNameSnapshot'],
                    128,
                    'entitlementLine.sourceNameSnapshot',
                    false
                ),
                'sourceCodeSnapshot' => self::text(
                    $line['sourceCodeSnapshot'],
                    64,
                    'entitlementLine.sourceCodeSnapshot',
                    true
                ),
                'projectNameSnapshot' => self::text(
                    $line['projectNameSnapshot'],
                    128,
                    'entitlementLine.projectNameSnapshot',
                    false
                ),
                'projectCategoryIdSnapshot' => $categoryId,
                'projectCategoryNameSnapshot' => $categoryName,
            ];
            $normalized['lineFingerprint'] = CashierV3CheckoutSettlementCanonicalizer::fingerprint($normalized);
            $result[] = $normalized;
        }
        return $result;
    }

    private static function normalizePayments(
        $rawPayments,
        int $occurredAt,
        bool $allowZeroPaymentDrafts
    ): array
    {
        if (!is_array($rawPayments) || !self::isList($rawPayments)) {
            throw self::failure('payment_details_shape_invalid');
        }
        if (count($rawPayments) > self::MAX_LINES) {
            throw self::failure('payment_method_count_exceeded');
        }
        $result = [];
        $keys = [];
        foreach ($rawPayments as $index => $payment) {
            if (!is_array($payment)) {
                throw self::failure('payment_detail_shape_invalid', ['index' => $index]);
            }
            self::assertExactKeys($payment, [
                'paymentAuthorityKey',
                'method',
                'amountCents',
                'businessTime',
            ], [
                'externalTransactionNo',
                'remark',
            ], 'paymentDetails[' . $index . ']');
            if ($payment['method'] === self::PAYMENT_OLD_CARD_ENTRY) {
                throw self::failure('old_card_entry_separate_flow_required', self::legacyEntryContract());
            }
            if (!in_array($payment['method'], self::PAYMENT_METHODS, true)) {
                throw self::failure('payment_method_invalid', [
                    'index' => $index,
                    'method' => $payment['method'],
                ]);
            }
            $authorityKey = self::token(
                $payment['paymentAuthorityKey'],
                96,
                'paymentDetail.paymentAuthorityKey'
            );
            if (isset($keys[$authorityKey])) {
                throw self::failure('payment_authority_key_duplicate', ['authorityKey' => $authorityKey]);
            }
            $keys[$authorityKey] = true;
            $amount = self::money($payment['amountCents'], 'paymentDetail.amountCents');
            if ($amount < 0 || (!$allowZeroPaymentDrafts && $amount === 0)) {
                throw self::failure('payment_amount_must_be_positive', ['method' => $payment['method']]);
            }
            $businessTime = self::timestamp($payment['businessTime'], 'paymentDetail.businessTime');
            if ($businessTime !== $occurredAt) {
                throw self::failure('payment_business_time_must_match_authority_operation_time', [
                    'method' => $payment['method'],
                ]);
            }
            $normalized = [
                'paymentAuthorityKey' => $authorityKey,
                'method' => $payment['method'],
                'amountCents' => $amount,
                'businessTime' => $businessTime,
                // These are cashier-entered trace notes only. They never prove
                // that an external channel charged the customer.
                'externalTransactionNo' => self::text(
                    $payment['externalTransactionNo'] ?? '',
                    64,
                    'paymentDetail.externalTransactionNo',
                    true
                ),
                'remark' => self::text(
                    $payment['remark'] ?? '',
                    255,
                    'paymentDetail.remark',
                    true
                ),
            ];
            $normalized['paymentFingerprint'] = CashierV3CheckoutSettlementCanonicalizer::fingerprint($normalized);
            $result[] = $normalized;
        }
        return $result;
    }

    private static function normalizeBalance($rawBalance, int $memberId): array
    {
        if (!is_array($rawBalance)) {
            throw self::failure('balance_deduction_shape_invalid');
        }
        self::assertExactKeys($rawBalance, [
            'authorityKey', 'accountId', 'accountVersion', 'amountCents',
        ], [], 'balanceDeduction');
        $amount = self::money($rawBalance['amountCents'], 'balanceDeduction.amountCents');
        if ($amount === 0) {
            if ($rawBalance['authorityKey'] !== ''
                || $rawBalance['accountId'] !== ''
                || $rawBalance['accountVersion'] !== 0) {
                throw self::failure('zero_balance_deduction_authority_must_be_empty');
            }
            return ['authorityKey' => '', 'accountId' => '', 'accountVersion' => 0, 'amountCents' => 0];
        }
        if ($memberId <= 0) {
            throw self::failure('balance_deduction_member_required');
        }
        return [
            'authorityKey' => self::token($rawBalance['authorityKey'], 96, 'balanceDeduction.authorityKey'),
            'accountId' => self::token($rawBalance['accountId'], 64, 'balanceDeduction.accountId'),
            'accountVersion' => self::positiveInt(
                $rawBalance['accountVersion'],
                'balanceDeduction.accountVersion'
            ),
            'amountCents' => $amount,
        ];
    }

    private static function normalizeDebt($rawDebt, int $memberId): array
    {
        if (!is_array($rawDebt)) {
            throw self::failure('debt_shape_invalid');
        }
        self::assertExactKeys($rawDebt, [
            'authorityKey', 'policyVersion', 'amountCents',
        ], [], 'debt');
        $amount = self::money($rawDebt['amountCents'], 'debt.amountCents');
        if ($amount === 0) {
            if ($rawDebt['authorityKey'] !== '' || $rawDebt['policyVersion'] !== 0) {
                throw self::failure('zero_debt_authority_must_be_empty');
            }
            return ['authorityKey' => '', 'policyVersion' => 0, 'amountCents' => 0];
        }
        if ($memberId <= 0) {
            throw self::failure('debt_member_required');
        }
        return [
            'authorityKey' => self::token($rawDebt['authorityKey'], 96, 'debt.authorityKey'),
            'policyVersion' => self::positiveInt($rawDebt['policyVersion'], 'debt.policyVersion'),
            'amountCents' => $amount,
        ];
    }

    private static function normalizeSourceDocument($rawSource): array
    {
        if (!is_array($rawSource)) {
            throw self::failure('source_document_shape_invalid');
        }
        self::assertExactKeys($rawSource, ['type', 'id', 'no'], [], 'sourceDocument');
        return [
            'type' => self::token($rawSource['type'], 32, 'sourceDocument.type'),
            'id' => self::token($rawSource['id'], 64, 'sourceDocument.id'),
            'no' => self::text($rawSource['no'], 64, 'sourceDocument.no', false),
        ];
    }

    private static function normalizeCurrentRequest(?array $current): ?array
    {
        if ($current === null) {
            return null;
        }
        self::assertExactKeys($current, [
            'requestId',
            'tenantId',
            'workspaceId',
            'version',
            'status',
            'creationIdempotencyKey',
            'lastIdempotencyKey',
            'lastOperationFingerprint',
        ], [], 'currentRequest');
        $status = self::text($current['status'], 32, 'currentRequest.status', false);
        CashierV3CheckoutSettlementStateMachine::assertKnown($status);
        return [
            'requestId' => self::opaqueId($current['requestId'], 'CKR', 'currentRequest.requestId'),
            'tenantId' => self::token($current['tenantId'], 32, 'currentRequest.tenantId'),
            'workspaceId' => self::token($current['workspaceId'], 64, 'currentRequest.workspaceId'),
            'version' => self::positiveInt($current['version'], 'currentRequest.version'),
            'status' => $status,
            'creationIdempotencyKey' => self::idempotencyKey($current['creationIdempotencyKey']),
            'lastIdempotencyKey' => self::idempotencyKey($current['lastIdempotencyKey']),
            'lastOperationFingerprint' => self::fingerprintToken(
                $current['lastOperationFingerprint'],
                'currentRequest.lastOperationFingerprint',
                true
            ),
        ];
    }

    private static function assertIntentMatchesSnapshot(array $intent, array $snapshot): void
    {
        $checks = [
            'workspaceId' => 'workspace_id_mismatch',
            'stateContextId' => 'state_context_id_mismatch',
            'permissionSnapshotFingerprint' => 'permission_snapshot_fingerprint_mismatch',
        ];
        foreach ($checks as $key => $reason) {
            if (!hash_equals($intent[$key], $snapshot[$key])) {
                throw self::failure($reason);
            }
        }
    }

    private static function assertCurrentMatchesIntentAndSnapshot(
        array $current,
        array $intent,
        array $snapshot
    ): void {
        $creationReplayWithoutRequestId = $intent['requestId'] === ''
            && hash_equals($current['creationIdempotencyKey'], $intent['idempotencyKey']);
        if (!$creationReplayWithoutRequestId
            && ($intent['requestId'] === ''
                || !hash_equals($current['requestId'], $intent['requestId']))) {
            throw self::failure('checkout_request_id_mismatch');
        }
        if (!hash_equals($current['tenantId'], $snapshot['tenantId'])) {
            throw self::failure('checkout_request_tenant_mismatch');
        }
        if (!hash_equals($current['workspaceId'], $snapshot['workspaceId'])) {
            throw self::failure('checkout_request_workspace_mismatch');
        }
    }

    private static function classifyComposition(array $saleLines, array $entitlementLines): string
    {
        $hasSales = count($saleLines) > 0;
        $hasEntitlements = count($entitlementLines) > 0;
        if ($hasSales && $hasEntitlements) {
            return self::COMPOSITION_MIXED;
        }
        if ($hasSales) {
            return self::COMPOSITION_SALE_ONLY;
        }
        if ($hasEntitlements) {
            return self::COMPOSITION_ENTITLEMENT_ONLY;
        }
        throw self::failure('checkout_composition_empty');
    }

    private static function buildLineDrafts(
        array $snapshot,
        string $requestId,
        int $draftVersion,
        CashierV3CheckoutSettlementIdFactory $idFactory
    ): array {
        $drafts = [];
        $sortNo = 0;
        foreach ($snapshot['saleLines'] as $line) {
            $drafts[] = [
                'lineId' => $idFactory->lineId($requestId, 'sale', $line['authorityKey']),
                'requestId' => $requestId,
                'draftVersion' => $draftVersion,
                'draftStatus' => 'draft',
                'tenantId' => $snapshot['tenantId'],
                'storeId' => $snapshot['storeId'],
                'memberId' => $snapshot['memberId'],
                'lineRole' => 'sale',
                'authorityKey' => $line['authorityKey'],
                'sourceKind' => $line['sourceType'],
                'sourceType' => $line['sourceType'],
                'sourceId' => $line['sourceId'],
                'catalogSkuId' => $line['catalogSkuId'],
                'entitlementSourceDetailId' => 0,
                'sourceVersion' => $line['sourceVersion'],
                'projectId' => $line['sourceType'] === 'project' ? $line['sourceId'] : 0,
                'projectVersion' => $line['sourceType'] === 'project' ? $line['sourceVersion'] : 0,
                'serviceObject' => $line['serviceObject'],
                'isExperience' => $line['isExperience'],
                'quantity' => $line['quantity'],
                'originalAmountCents' => $line['originalAmountCents'],
                'discountAmountCents' => $line['discountAmountCents'],
                'saleAmountCents' => $line['saleAmountCents'],
                'debtAmountCents' => $line['debtAmountCents'],
                'entitlementActualAmountCents' => 0,
                'sourceNameSnapshot' => $line['sourceNameSnapshot'],
                'sourceCodeSnapshot' => $line['sourceCodeSnapshot'],
                'projectNameSnapshot' => $line['sourceType'] === 'project'
                    ? $line['sourceNameSnapshot']
                    : '',
                'categoryIdSnapshot' => $line['categoryIdSnapshot'],
                'categoryNameSnapshot' => $line['categoryNameSnapshot'],
                'configuredCostCents' => $line['configuredCostCents'],
                'priceChangeReason' => $line['priceChangeReason'],
                'priceChangedBy' => $line['priceChangedBy'],
                'priceChangedByNameSnapshot' => $line['priceChangedByNameSnapshot'],
                'priceChangedAt' => $line['priceChangedAt'],
                'craftsmenSnapshotJson' => CashierV3CheckoutCraftsmenSnapshot::encode(
                    $line['craftsmen']
                ),
                'lineFingerprint' => $line['lineFingerprint'],
                'sortNo' => ++$sortNo,
            ];
        }
        foreach ($snapshot['entitlementLines'] as $line) {
            $drafts[] = [
                'lineId' => $idFactory->lineId(
                    $requestId,
                    'entitlement_service',
                    $line['authorityKey']
                ),
                'requestId' => $requestId,
                'draftVersion' => $draftVersion,
                'draftStatus' => 'draft',
                'tenantId' => $snapshot['tenantId'],
                'storeId' => $snapshot['storeId'],
                'memberId' => $snapshot['memberId'],
                'lineRole' => 'entitlement_service',
                'authorityKey' => $line['authorityKey'],
                'sourceKind' => $line['sourceKind'],
                'sourceType' => 'entitlement_project',
                'sourceId' => $line['holderId'],
                // 持久化行契约要求所有行明确携带 SKU 槽位；权益项目没有
                // 销售 SKU，固定写 0，避免用缺字段表示“无 SKU”。
                'catalogSkuId' => 0,
                'entitlementSourceDetailId' => $line['entitlementSourceDetailId'],
                'sourceVersion' => $line['sourceVersion'],
                'projectId' => $line['projectId'],
                'projectVersion' => $line['projectVersion'],
                // These columns belong to the shared line-draft schema. Entitlement
                // service tags are frozen by the entitlement completion authority,
                // not by the sales-order checkout line.
                'serviceObject' => '',
                'isExperience' => 0,
                'quantity' => $line['quantity'],
                'originalAmountCents' => 0,
                'discountAmountCents' => 0,
                'saleAmountCents' => 0,
                'debtAmountCents' => 0,
                'entitlementActualAmountCents' => $line['actualEntitlementAmountCents'],
                'sourceNameSnapshot' => $line['sourceNameSnapshot'],
                'sourceCodeSnapshot' => $line['sourceCodeSnapshot'],
                'projectNameSnapshot' => $line['projectNameSnapshot'],
                'categoryIdSnapshot' => $line['projectCategoryIdSnapshot'],
                'categoryNameSnapshot' => $line['projectCategoryNameSnapshot'],
                'configuredCostCents' => 0,
                'priceChangeReason' => '',
                'priceChangedBy' => 0,
                'priceChangedByNameSnapshot' => '',
                'priceChangedAt' => 0,
                'craftsmenSnapshotJson' => '[]',
                'lineFingerprint' => $line['lineFingerprint'],
                'sortNo' => ++$sortNo,
            ];
        }
        return $drafts;
    }

    private static function buildPaymentDrafts(
        array $snapshot,
        string $requestId,
        int $draftVersion,
        CashierV3CheckoutSettlementIdFactory $idFactory
    ): array {
        $drafts = [];
        foreach ($snapshot['paymentDetails'] as $index => $payment) {
            $drafts[] = [
                'paymentDraftId' => $idFactory->paymentDraftId(
                    $requestId,
                    $payment['paymentAuthorityKey']
                ),
                'requestId' => $requestId,
                'draftVersion' => $draftVersion,
                'tenantId' => $snapshot['tenantId'],
                'storeId' => $snapshot['storeId'],
                'memberId' => $snapshot['memberId'],
                'operatorId' => $snapshot['operatorId'],
                'paymentAuthorityKey' => $payment['paymentAuthorityKey'],
                'paymentMethod' => $payment['method'],
                'amountCents' => $payment['amountCents'],
                'externalTransactionNo' => $payment['externalTransactionNo'],
                'remark' => $payment['remark'],
                'businessDate' => $snapshot['businessDate'],
                'businessTimezone' => $snapshot['businessTimezone'],
                'operationOccurredAt' => $payment['businessTime'],
                'recordedAt' => $snapshot['recordedAt'],
                'settledAt' => null,
                'operatorNameSnapshot' => $snapshot['operatorName'],
                'sourceDocumentType' => $snapshot['sourceDocument']['type'],
                'sourceDocumentId' => $snapshot['sourceDocument']['id'],
                'sourceDocumentNo' => $snapshot['sourceDocument']['no'],
                'paymentFingerprint' => $payment['paymentFingerprint'],
                'draftStatus' => 'draft',
                'sortNo' => $index + 1,
            ];
        }
        return $drafts;
    }

    private static function buildRequestRow(
        array $snapshot,
        string $requestId,
        int $version,
        string $status,
        string $composition,
        string $creationIdempotencyKey,
        string $lastIdempotencyKey,
        int $salesAmountCents,
        int $receivableAmountCents,
        int $selectedPaymentAmountCents,
        int $balanceAmountCents,
        int $debtAmountCents,
        int $entitlementActualAmountCents,
        string $aggregateFingerprint,
        string $operationFingerprint,
        string $operation
    ): array {
        return [
            'requestId' => $requestId,
            'tenantId' => $snapshot['tenantId'],
            'organizationId' => $snapshot['organizationId'],
            'organizationPath' => $snapshot['organizationPath'],
            'organizationNameSnapshot' => $snapshot['organizationName'],
            'workspaceId' => $snapshot['workspaceId'],
            'stateContextId' => $snapshot['stateContextId'],
            'storeId' => $snapshot['storeId'],
            'storeNameSnapshot' => $snapshot['storeName'],
            'memberId' => $snapshot['memberId'],
            'memberNameSnapshot' => $snapshot['memberName'],
            'operatorId' => $snapshot['operatorId'],
            'operatorNameSnapshot' => $snapshot['operatorName'],
            'requestVersion' => $version,
            'requestStatus' => $status,
            'composition' => $composition,
            'businessDate' => $snapshot['businessDate'],
            'businessTimezone' => $snapshot['businessTimezone'],
            'operationOccurredAt' => $snapshot['occurredAt'],
            'recordedAt' => $snapshot['recordedAt'],
            'orderNote' => $snapshot['orderNote'],
            'supplementEnabled' => $snapshot['supplement']['enabled'] ? 1 : 0,
            'supplementReason' => $snapshot['supplement']['reason'],
            'supplementOperatorId' => $snapshot['supplement']['operatorId'],
            'supplementOperatorNameSnapshot' => $snapshot['supplement']['operatorNameSnapshot'],
            'supplementOperatedAt' => $snapshot['supplement']['operatedAt'],
            'sourceDocumentType' => $snapshot['sourceDocument']['type'],
            'sourceDocumentId' => $snapshot['sourceDocument']['id'],
            'sourceDocumentNo' => $snapshot['sourceDocument']['no'],
            'salesAmountCents' => $salesAmountCents,
            'receivableAmountCents' => $receivableAmountCents,
            'selectedPaymentAmountCents' => $selectedPaymentAmountCents,
            'balanceDeductionAmountCents' => $balanceAmountCents,
            'balanceAuthorityKey' => $snapshot['balanceDeduction']['authorityKey'],
            'balanceAccountId' => $snapshot['balanceDeduction']['accountId'],
            'balanceAccountVersion' => $snapshot['balanceDeduction']['accountVersion'],
            'debtAmountCents' => $debtAmountCents,
            'debtAuthorityKey' => $snapshot['debt']['authorityKey'],
            'debtPolicyVersion' => $snapshot['debt']['policyVersion'],
            'cashPerformanceAmountCents' => $selectedPaymentAmountCents,
            'entitlementActualAmountCents' => $entitlementActualAmountCents,
            'authoritySnapshotVersion' => $snapshot['authoritySnapshotVersion'],
            'authorityFingerprint' => $snapshot['authoritySnapshotFingerprint'],
            'aggregateFingerprint' => $aggregateFingerprint,
            'creationIdempotencyKey' => $creationIdempotencyKey,
            'lastIdempotencyKey' => $lastIdempotencyKey,
            'lastOperationFingerprint' => $operationFingerprint,
            'lastOperation' => $operation,
        ];
    }

    private static function sumField(array $rows, string $field): int
    {
        $total = 0;
        foreach ($rows as $row) {
            $total = self::safeAdd($total, $row[$field], $field);
        }
        return $total;
    }

    private static function emptyBusinessEffects(): array
    {
        return [
            'checkoutSucceeded' => false,
            'saleFacts' => 0,
            'paymentCollectedFacts' => 0,
            'balanceMutations' => 0,
            'debtMutations' => 0,
            'entitlementMutations' => 0,
            'performanceFacts' => 0,
            'businessEvents' => 0,
            'outboxRows' => 0,
        ];
    }

    private static function safeAdd(int $left, int $right, string $field): int
    {
        if ($left < 0 || $right < 0 || $left > self::MAX_MONEY_CENTS - $right) {
            throw self::failure('money_total_overflow', ['field' => $field]);
        }
        return $left + $right;
    }

    private static function money($value, string $path): int
    {
        if (!is_int($value) || $value < 0 || $value > self::MAX_MONEY_CENTS) {
            throw self::failure('money_cents_invalid', ['path' => $path]);
        }
        // V3 persists money as cents, while the approved cashier rule is
        // whole RMB for every newly written monetary fact. Never round a
        // client value here: a fractional request must fail before settlement.
        if ($value % 100 !== 0) {
            throw self::failure('money_whole_yuan_required', ['path' => $path]);
        }
        return $value;
    }

    private static function boundedQuantity($value, string $path): int
    {
        $quantity = self::positiveInt($value, $path);
        if ($quantity > self::MAX_QUANTITY) {
            throw self::failure('quantity_limit_exceeded', ['path' => $path]);
        }
        return $quantity;
    }

    private static function positiveInt($value, string $path): int
    {
        if (!is_int($value) || $value <= 0) {
            throw self::failure('positive_integer_required', ['path' => $path]);
        }
        return $value;
    }

    private static function nonNegativeInt($value, string $path): int
    {
        if (!is_int($value) || $value < 0) {
            throw self::failure('non_negative_integer_required', ['path' => $path]);
        }
        return $value;
    }

    private static function timestamp($value, string $path): int
    {
        $timestamp = self::positiveInt($value, $path);
        if ($timestamp > self::MAX_TIMESTAMP) {
            throw self::failure('timestamp_out_of_range', ['path' => $path]);
        }
        return $timestamp;
    }

    private static function businessDate($value): string
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            throw self::failure('business_date_invalid');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$date
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value) {
            throw self::failure('business_date_invalid');
        }
        return $value;
    }

    private static function organizationPath($value): string
    {
        if (!is_string($value)
            || strlen($value) > 191
            || preg_match('#^/[1-9][0-9]*(?:/[1-9][0-9]*)*/$#D', $value) !== 1) {
            throw self::failure('organization_path_invalid');
        }
        return $value;
    }

    private static function idempotencyKey($value): string
    {
        if (!is_string($value) || strlen($value) > 128) {
            throw self::failure('checkout_idempotency_key_invalid');
        }
        $matches = [];
        $uuid = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}';
        if (preg_match('/^(CHECKOUT|CHECKOUT_PREPARE)-(' . $uuid . ')$/D', trim($value), $matches) !== 1) {
            throw self::failure('checkout_idempotency_key_invalid');
        }
        return $matches[1] . '-' . strtolower($matches[2]);
    }

    private static function opaqueId($value, string $prefix, string $path): string
    {
        if (!is_string($value)
            || preg_match('/^' . preg_quote($prefix, '/') . '-[0-9a-f]{40}$/D', $value) !== 1) {
            throw self::failure('opaque_id_invalid', ['path' => $path]);
        }
        return $value;
    }

    private static function fingerprintToken($value, string $path, bool $shaOnly = false): string
    {
        if (!is_string($value)) {
            throw self::failure('fingerprint_invalid', ['path' => $path]);
        }
        if ($shaOnly) {
            if (preg_match('/^[0-9a-f]{64}$/D', $value) !== 1) {
                throw self::failure('fingerprint_invalid', ['path' => $path]);
            }
            return $value;
        }
        $trimmed = trim($value);
        if (strlen($trimmed) < 16
            || strlen($trimmed) > 128
            || preg_match('/^[A-Za-z0-9:._-]+$/D', $trimmed) !== 1) {
            throw self::failure('fingerprint_invalid', ['path' => $path]);
        }
        return $trimmed;
    }

    private static function token($value, int $maxBytes, string $path): string
    {
        if (!is_string($value)) {
            throw self::failure('token_invalid', ['path' => $path]);
        }
        $trimmed = trim($value);
        if ($trimmed === ''
            || strlen($trimmed) > $maxBytes
            || preg_match('/^[A-Za-z0-9:._-]+$/D', $trimmed) !== 1) {
            throw self::failure('token_invalid', ['path' => $path]);
        }
        return $trimmed;
    }

    private static function text($value, int $maxBytes, string $path, bool $allowEmpty): string
    {
        if (!is_string($value) || strlen($value) > $maxBytes) {
            throw self::failure('text_invalid', ['path' => $path]);
        }
        if (!$allowEmpty && trim($value) === '') {
            throw self::failure('text_invalid', ['path' => $path]);
        }
        if (json_encode($value, JSON_UNESCAPED_UNICODE) === false) {
            throw self::failure('text_utf8_invalid', ['path' => $path]);
        }
        return $value;
    }

    private static function assertExactKeys(
        array $value,
        array $required,
        array $optional,
        string $path
    ): void {
        $allowed = array_merge($required, $optional);
        $missing = array_values(array_diff($required, array_keys($value)));
        $unknown = array_values(array_diff(array_keys($value), $allowed));
        sort($missing, SORT_STRING);
        sort($unknown, SORT_STRING);
        if ($missing || $unknown) {
            throw self::failure('object_shape_invalid', [
                'path' => $path,
                'missing' => $missing,
                'unknown' => $unknown,
            ]);
        }
    }

    private static function isList(array $value): bool
    {
        $expected = 0;
        foreach ($value as $key => $_item) {
            if ($key !== $expected) {
                return false;
            }
            $expected++;
        }
        return true;
    }

    private static function failure(string $reason, array $detail = []): CashierV3CheckoutSettlementContractException
    {
        return new CashierV3CheckoutSettlementContractException($reason, $detail);
    }
}
