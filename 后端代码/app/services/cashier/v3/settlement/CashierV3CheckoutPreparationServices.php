<?php

namespace app\services\cashier\v3\settlement;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\cashier\CashierV3CashierMemberSummaryServices;
use app\services\cashier\v3\cashier\CashierV3CashierWorkspaceServices;
use app\services\cashier\v3\cashier\CashierV3EntitlementProjectionServices;
use app\services\cashier\v3\cashier\CashierV3EntitlementResourceVersionProvider;
use app\services\cashier\v3\cashier\CashierV3SaleCatalogServices;
use app\services\cashier\v3\card\CashierV3CardOperationCheckoutSettlementServices;
use think\facade\Db;

/** Server-only resource discovery and checkout-request preparation. */
final class CashierV3CheckoutPreparationServices
{
    public const DISCOVERY_CONTRACT_VERSION = 'cashier-v3-checkout-preparation-discovery-v1';
    public const PREPARATION_CONTRACT_VERSION = 'cashier-v3-checkout-preparation-v1';

    private const SOURCE_KINDS = ['service_order', 'reservation', 'room'];

    /** @var CashierV3CashierWorkspaceServices */
    private $workspace;

    /** @var CashierV3SaleCatalogServices */
    private $saleCatalog;

    /** @var CashierV3EntitlementProjectionServices */
    private $entitlements;

    /** @var CashierV3CheckoutRequestRepository */
    private $requests;

    /** @var CashierV3CashierMemberSummaryServices */
    private $members;

    /** @var CashierV3CardOperationCheckoutSettlementServices */
    private $cardOperationSettlements;

    /** @var string */
    private $serverIdSecret;

    public function __construct(
        CashierV3CashierWorkspaceServices $workspace,
        CashierV3SaleCatalogServices $saleCatalog,
        CashierV3EntitlementProjectionServices $entitlements,
        CashierV3CheckoutRequestRepository $requests = null,
        CashierV3CashierMemberSummaryServices $members = null,
        string $serverIdSecret = '',
        ?CashierV3CardOperationCheckoutSettlementServices $cardOperationSettlements = null
    ) {
        $this->workspace = $workspace;
        $this->saleCatalog = $saleCatalog;
        $this->entitlements = $entitlements;
        $this->requests = $requests ?: new ThinkPhpCashierV3CheckoutRequestRepository();
        $this->members = $members ?: new CashierV3CashierMemberSummaryServices();
        $this->serverIdSecret = $serverIdSecret;
        $this->cardOperationSettlements = $cardOperationSettlements
            ?: new CashierV3CardOperationCheckoutSettlementServices();
    }

    /** Callable contract consumed by CashierV3CommandGatewayServices. */
    public function discover(array $scope): array
    {
        $operatorScope = $scope['operator_scope'];
        $dataScope = $scope['data_scope'];
        $stateContextId = trim((string)($scope['state_context_id'] ?? ''));
        $workspaceId = self::workspaceId($operatorScope, $stateContextId);
        $pack = $this->workspace->discoverCheckoutDraft(
            $workspaceId,
            $stateContextId,
            $operatorScope
        );

        $resources = [];
        $memberId = (int)($pack['draft']['member_id'] ?? 0);
        foreach ($pack['rows'] as $row) {
            $lineRole = (string)($row['line_role'] ?? '');
            if ($lineRole === 'sale') {
                foreach ($this->saleCatalog->discoverStoredLineResources(
                    (array)$row,
                    (int)($row['quantity'] ?? 0),
                    $operatorScope,
                    $dataScope
                ) as $resource) {
                    $resources[] = $resource;
                }
                continue;
            }
            if ($lineRole !== 'entitlement_service') {
                throw self::incomplete('checkout_discovery_line_role_invalid');
            }
            $holderId = (int)($row['holder_id'] ?? 0);
            $detailId = (int)($row['source_detail_id'] ?? 0);
            $holderVersion = (int)($row['source_version'] ?? 0);
            $detailVersion = (int)($row['detail_version'] ?? 0);
            if ($memberId <= 0 || $holderId <= 0 || $detailId <= 0
                || $holderVersion <= 0 || $detailVersion <= 0) {
                throw self::incomplete('checkout_discovery_entitlement_identity_invalid');
            }
            $resources[] = self::resource(
                'member_benefit_pool',
                $detailId,
                $detailVersion,
                'checkout_entitlement_pool:' . $detailId
            );
            $resources[] = self::resource(
                'card_holder',
                $holderId,
                $holderVersion,
                'checkout_card_holder:' . $holderId
            );
        }
        if ($memberId > 0) {
            $memberVersion = $this->shadowVersion('member', $memberId);
            $resources[] = self::resource('member', $memberId, $memberVersion, 'checkout_member');
        }
        return [
            'contractVersion' => self::DISCOVERY_CONTRACT_VERSION,
            'resources' => $resources,
        ];
    }

    /**
     * Build and persist one eventless editing checkout request under Gateway's
     * complete lock set. No sale, payment, balance, debt or service fact is
     * written here.
     */
    public function prepareInTx(array $scope): array
    {
        CashierV3TransactionGuard::assertInTransaction('cashierCheckoutPreparation');
        $operatorScope = $scope['operator_scope'];
        $dataScope = $scope['data_scope'];
        $stateContextId = trim((string)($scope['state_context_id'] ?? ''));
        $contexts = array_values((array)($scope['contexts'] ?? []));
        $idempotencyKey = trim((string)($scope['idempotency_key'] ?? ''));
        $payload = is_array($scope['payload'] ?? null) ? $scope['payload'] : [];
        $editingRequestId = trim((string)($payload['checkoutRequestId'] ?? ''));
        $editingRequestVersion = self::editingRequestVersion($payload['checkoutRequestVersion'] ?? null);
        if (($editingRequestId === '') !== ($editingRequestVersion === null)) {
            throw self::incomplete('checkout_edit_identity_incomplete');
        }
        $workspaceId = self::workspaceId($operatorScope, $stateContextId);

        $authority = $this->workspace->checkoutSaleSourceSetInTx(
            $workspaceId,
            $stateContextId,
            $operatorScope,
            function (array $row) use ($contexts, $operatorScope, $dataScope): array {
                return $this->saleCatalog->checkoutSourceLineAfterGatewayLocksInTx(
                    $row,
                    $contexts,
                    $operatorScope,
                    $dataScope
                );
            }
        );
        $publicDraft = (array)($authority['publicDraft'] ?? []);
        $storedDraft = (array)($authority['storedDraft'] ?? []);
        $storedRows = array_values((array)($authority['storedRows'] ?? []));
        $debtAmountCents = $this->saleDebtAmountCents((array)($authority['lines'] ?? []));
        $this->assertEntitlementRowsAfterGatewayLocks(
            $storedRows,
            $contexts,
            $operatorScope
        );

        $snapshot = $this->authoritySnapshot(
            $authority,
            $publicDraft,
            $contexts,
            $operatorScope,
            $dataScope,
            $workspaceId,
            $stateContextId,
            $debtAmountCents
        );
        $command = [
            'contractVersion' => CashierV3CheckoutSettlementKernel::CONTRACT_VERSION,
            'operation' => CashierV3CheckoutSettlementKernel::OPERATION_SAVE_DRAFT,
            'idempotencyKey' => $idempotencyKey,
            'workspaceId' => $workspaceId,
            'stateContextId' => $stateContextId,
            'permissionSnapshotFingerprint' => $dataScope->permissionVersion(),
        ];
        if ($editingRequestId !== '') {
            $command['requestId'] = $editingRequestId;
            $command['expectedVersion'] = $editingRequestVersion;
        }
        $current = $this->requests->lockCurrentForKernelInTx(
            $editingRequestId,
            $idempotencyKey,
            $operatorScope,
            $dataScope
        );
        $kernel = CashierV3CheckoutSettlementKernel::saveDraft(
            $command,
            $snapshot,
            $current,
            $this->serverIdSecret()
        );
        $verifiedSources = $this->verifiedNavigationSources(
            $contexts,
            $operatorScope,
            $dataScope
        );
        $persisted = $this->requests->persistKernelPlanInTx(
            $kernel,
            $verifiedSources,
            $operatorScope,
            $dataScope
        );
        // A resumed hang is only an editable cart draft. It never enters
        // source-document resolution; the internal reference lets final
        // submission lock and remove that draft only after success.
        $this->requests->bindResumedHangOrderInTx(
            (string)$kernel['requestId'],
            (int)$kernel['requestVersion'],
            trim((string)($storedDraft['resumed_hang_order_id'] ?? '')),
            $operatorScope,
            $dataScope
        );
        $cardOperationBinding = $this->cardOperationSettlements->bindPreparedCheckoutInTx(
            $storedRows,
            (string)$kernel['requestId'],
            $operatorScope,
            $dataScope
        );

        return [
            'contractVersion' => self::PREPARATION_CONTRACT_VERSION,
            'preparationRequestId' => $idempotencyKey,
            'checkoutRequestId' => (string)$kernel['requestId'],
            'checkoutRequestVersion' => (int)$kernel['requestVersion'],
            'requestStatus' => (string)$kernel['requestStatus'],
            'composition' => (string)$kernel['composition'],
            'totals' => (array)$kernel['totals'],
            'authoritySnapshotFingerprint' => (string)$kernel['authoritySnapshotFingerprint'],
            'aggregateFingerprint' => (string)$kernel['aggregateFingerprint'],
            'replayed' => !empty($kernel['replayed']) || !empty($persisted['replayed']),
            'eventless' => true,
            'businessEffects' => (array)$kernel['businessEffects'],
            'cardOperationCheckout' => $cardOperationBinding,
        ];
    }

    private function authoritySnapshot(
        array $authority,
        array $publicDraft,
        array $contexts,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope,
        string $workspaceId,
        string $stateContextId,
        int $debtAmountCents
    ): array {
        $dimensions = $this->dimensions($operatorScope, $dataScope);
        $memberId = (int)($publicDraft['memberId'] ?? 0);
        $memberName = '';
        if ($memberId > 0) {
            $member = $this->members->read($memberId, $operatorScope->storeId());
            $memberName = (string)($member['name'] ?? '');
        }
        $saleLines = [];
        foreach ((array)($authority['lines'] ?? []) as $line) {
            $saleLines[] = $this->saleSnapshotLine((array)$line);
        }
        $salesAmountCents = array_sum(array_column($saleLines, 'saleAmountCents'));
        if ($debtAmountCents > 0
            && ($memberId <= 0
                || $salesAmountCents <= 0
                || $debtAmountCents > $salesAmountCents)) {
            throw self::incomplete('checkout_debt_intent_invalid');
        }
        $entitlementLines = [];
        foreach ((array)($publicDraft['lines'] ?? []) as $line) {
            if ((string)($line['lineRole'] ?? '') === 'entitlement_service') {
                $entitlementLines[] = $this->entitlementSnapshotLine((array)$line);
            }
        }
        $now = time();
        $supplementEnabled = (int)($authority['storedDraft']['supplement_enabled'] ?? 0) === 1;
        $supplementBusinessDate = (string)($authority['storedDraft']['supplement_business_date'] ?? '');
        if ($supplementEnabled
            && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $supplementBusinessDate) !== 1) {
            throw self::incomplete('checkout_supplement_business_date_invalid');
        }
        $snapshot = [
            'contractVersion' => CashierV3CheckoutSettlementKernel::AUTHORITY_CONTRACT_VERSION,
            'authorityOrigin' => 'server_final_lock_snapshot',
            'authoritySnapshotVersion' => $this->contextVersion($contexts, 'cashier_workspace', $workspaceId),
            'authoritySnapshotFingerprint' => '',
            'tenantId' => $dataScope->tenantId(),
            'organizationId' => $operatorScope->organizationId(),
            'organizationPath' => $dimensions['organizationPath'],
            'organizationName' => $dimensions['organizationName'],
            'storeId' => $operatorScope->storeId(),
            'storeName' => $dimensions['storeName'],
            'workspaceId' => $workspaceId,
            'stateContextId' => $stateContextId,
            'permissionSnapshotFingerprint' => $dataScope->permissionVersion(),
            'memberId' => $memberId,
            'memberName' => $memberName,
            'operatorId' => $operatorScope->operatorId(),
            'operatorName' => $dimensions['operatorName'],
            'businessDate' => $supplementEnabled ? $supplementBusinessDate : date('Y-m-d', $now),
            'businessTimezone' => 'Asia/Shanghai',
            'occurredAt' => $now,
            'recordedAt' => $now,
            'orderNote' => (string)($authority['storedDraft']['order_note'] ?? ''),
            'supplement' => [
                'enabled' => $supplementEnabled,
                'reason' => (string)($authority['storedDraft']['supplement_reason'] ?? ''),
                'operatorId' => (int)($authority['storedDraft']['supplement_operator_id'] ?? 0),
                'operatorNameSnapshot' => (string)($authority['storedDraft']['supplement_operator_name_snapshot'] ?? ''),
                'operatedAt' => (int)($authority['storedDraft']['supplement_operated_at'] ?? 0),
            ],
            'sourceDocument' => $this->sourceDocument($contexts, $workspaceId),
            'saleLines' => $saleLines,
            'entitlementLines' => $entitlementLines,
            'paymentDetails' => [],
            'balanceDeduction' => [
                'authorityKey' => '',
                'accountId' => '',
                'accountVersion' => 0,
                'amountCents' => 0,
            ],
            'debt' => [
                'authorityKey' => $debtAmountCents > 0
                    ? CashierV3CheckoutDebtAuthorityServices::authorityKey($operatorScope->storeId())
                    : '',
                'policyVersion' => $debtAmountCents > 0
                    ? CashierV3CheckoutDebtAuthorityServices::POLICY_VERSION
                    : 0,
                'amountCents' => $debtAmountCents,
            ],
        ];
        $snapshot['authoritySnapshotFingerprint'] =
            CashierV3CheckoutSettlementKernel::authorityFingerprint($snapshot);
        return $snapshot;
    }

    private function assertEntitlementRowsAfterGatewayLocks(
        array $storedRows,
        array $contexts,
        CashierV3OperatorScope $operatorScope
    ): void {
        $aggregate = [];
        foreach ($storedRows as $row) {
            if ((string)($row['line_role'] ?? '') !== 'entitlement_service') {
                continue;
            }
            $key = (int)($row['holder_id'] ?? 0) . ':' . (int)($row['source_detail_id'] ?? 0);
            $aggregate[$key] = (int)($aggregate[$key] ?? 0) + (int)($row['quantity'] ?? 0);
        }
        foreach ($storedRows as $row) {
            if ((string)($row['line_role'] ?? '') !== 'entitlement_service') {
                continue;
            }
            $key = (int)($row['holder_id'] ?? 0) . ':' . (int)($row['source_detail_id'] ?? 0);
            $this->entitlements->assertDraftLineQuantityInTx(
                (array)$row,
                (int)($row['quantity'] ?? 0),
                (int)($aggregate[$key] ?? 0),
                $contexts,
                $operatorScope
            );
        }
    }

    private function saleSnapshotLine(array $line): array
    {
        $kindCode = (string)($line['kindCode'] ?? '');
        $sourceType = $kindCode === 'product'
            ? 'product'
            : ($kindCode === 'project' ? 'project' : 'card');
        $original = (int)($line['originalLineAmountCents'] ?? -1);
        $sale = (int)($line['lineAmountCents'] ?? -1);
        if ($original < 0 || $sale < 0) {
            throw self::incomplete('checkout_sale_amount_invalid');
        }
        // 手工改价可以高于目录原价；结算方程以成交价作为本单原价，
        // 不产生负折扣，实际收款仍以草稿中的成交价为准。
        if ($sale > $original) {
            $original = $sale;
        }
        try {
            $craftsmen = $sourceType === 'project'
                ? CashierV3CheckoutCraftsmenSnapshot::normalize($line['craftsmen'] ?? [])
                : [];
        } catch (\Throwable $exception) {
            throw self::incomplete('checkout_sale_craftsmen_snapshot_invalid');
        }
        return [
            'authorityKey' => 'sale:' . (string)($line['lineId'] ?? ''),
            'saleClassification' => 'formal_sale',
            'sourceType' => $sourceType,
            'sourceId' => (int)($line['productId'] ?? 0),
            'catalogSkuId' => (int)($line['skuId'] ?? 0),
            'sourceVersion' => (int)($line['productVersion'] ?? 0),
            'quantity' => (int)($line['quantity'] ?? 0),
            'originalAmountCents' => $original,
            'discountAmountCents' => $original - $sale,
            'couponUserId' => (int)($line['couponUserId'] ?? 0),
            'couponNameSnapshot' => (string)($line['couponNameSnapshot'] ?? ''),
            'couponDiscountCents' => (int)($line['couponDiscountCents'] ?? 0),
            'saleAmountCents' => $sale,
            'debtAmountCents' => $this->saleLineDebtAmountCents($line, $sale),
            'configuredCostCents' => (int)($line['configuredCostCents'] ?? 0),
            'priceChangeReason' => (string)($line['priceChangeReason'] ?? ''),
            'priceChangedBy' => (int)($line['priceChangedBy'] ?? 0),
            'priceChangedByNameSnapshot' => (string)($line['priceChangedByNameSnapshot'] ?? ''),
            'priceChangedAt' => (int)($line['priceChangedAt'] ?? 0),
            'sourceNameSnapshot' => (string)($line['nameSnapshot'] ?? ''),
            'sourceCodeSnapshot' => (string)($line['skuUnique'] ?? ''),
            'categoryIdSnapshot' => (int)($line['categoryIdSnapshot'] ?? 0),
            'categoryNameSnapshot' => (string)($line['categoryNameSnapshot'] ?? ''),
            'serviceObject' => (string)($line['serviceObject'] ?? ''),
            'craftsmen' => $craftsmen,
            'isExperience' => !empty($line['isExperience']) ? 1 : 0,
        ];
    }

    private function saleDebtAmountCents(array $saleLines): int
    {
        $total = 0;
        foreach ($saleLines as $line) {
            $sale = (int)($line['lineAmountCents'] ?? -1);
            $debt = $this->saleLineDebtAmountCents((array)$line, $sale);
            if ($total > PHP_INT_MAX - $debt) {
                throw self::incomplete('checkout_line_debt_total_overflow');
            }
            $total += $debt;
        }
        return $total;
    }

    private function saleLineDebtAmountCents(array $line, int $saleAmountCents): int
    {
        $debt = (int)($line['debtAmountCents'] ?? 0);
        if ($debt < 0 || $saleAmountCents < 0 || $debt > $saleAmountCents) {
            throw self::incomplete('checkout_line_debt_invalid');
        }
        return $debt;
    }

    private function entitlementSnapshotLine(array $line): array
    {
        return [
            'authorityKey' => (string)($line['id'] ?? ''),
            'sourceKind' => (string)($line['entitlementSourceKind'] ?? ''),
            'holderId' => (int)($line['entitlementInstanceId'] ?? 0),
            'entitlementSourceDetailId' => (int)($line['entitlementSourceDetailId'] ?? 0),
            'sourceVersion' => (int)($line['entitlementSourceVersion'] ?? 0),
            'projectId' => (int)($line['projectId'] ?? 0),
            'projectVersion' => (int)($line['projectVersion'] ?? 0),
            'quantity' => (int)($line['quantity'] ?? 0),
            'actualEntitlementAmountCents' => $this->moneyToCents($line['actualAmount'] ?? null),
            'sourceNameSnapshot' => (string)($line['entitlementSourceName'] ?? ''),
            'sourceCodeSnapshot' => (string)($line['fullCardNo'] ?? ''),
            'projectNameSnapshot' => (string)($line['name'] ?? ''),
            'projectCategoryIdSnapshot' => 0,
            'projectCategoryNameSnapshot' => '',
        ];
    }

    private function verifiedNavigationSources(
        array $contexts,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): CashierV3CheckoutVerifiedSourceSet {
        $rows = [];
        foreach ($contexts as $context) {
            $kind = (string)($context['kind'] ?? '');
            if (!in_array($kind, self::SOURCE_KINDS, true)) {
                continue;
            }
            $sourceId = (string)($context['id'] ?? '');
            $rows[] = [
                'tenantId' => $dataScope->tenantId(),
                'storeId' => $operatorScope->storeId(),
                'kind' => $kind,
                'id' => $sourceId,
                'sourceVersion' => $this->contextVersion($contexts, $kind, $sourceId),
                'role' => $kind,
            ];
        }
        return CashierV3CheckoutVerifiedSourceSet::fromServerVerifiedAuthorityRows(
            $dataScope->tenantId(),
            $operatorScope->storeId(),
            $rows
        );
    }

    private function dimensions(
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        $storeName = trim((string)Db::name('system_store')
            ->where('id', $operatorScope->storeId())
            ->value('name'));
        $profile = $dataScope->operatorProfile();
        $operatorName = '';
        foreach (['staff_name', 'real_name', 'name', 'account'] as $field) {
            $operatorName = trim((string)($profile[$field] ?? ''));
            if ($operatorName !== '') {
                break;
            }
        }
        $organizationId = (int)$operatorScope->organizationId();
        $ids = [];
        $organizationName = '';
        $seen = [];
        $currentId = $organizationId;
        while ($currentId > 0 && count($ids) < 64) {
            if (isset($seen[$currentId])) {
                throw self::incomplete('checkout_organization_cycle');
            }
            $seen[$currentId] = true;
            $node = Db::name('organization')
                ->where('id', $currentId)
                ->where('is_del', 0)
                ->field('id,pid,name')
                ->find();
            if (!$node) {
                throw self::incomplete('checkout_organization_missing');
            }
            $name = trim((string)($node['name'] ?? ''));
            if ($name === '') {
                throw self::incomplete('checkout_organization_name_missing');
            }
            if ($organizationName === '') {
                $organizationName = $name;
            }
            $ids[] = (int)$node['id'];
            $currentId = (int)($node['pid'] ?? 0);
        }
        if ($storeName === '' || $operatorName === '' || !$ids || $currentId > 0) {
            throw self::incomplete('checkout_dimension_snapshot_incomplete');
        }
        return [
            'storeName' => $storeName,
            'operatorName' => $operatorName,
            'organizationName' => $organizationName,
            'organizationPath' => '/' . implode('/', array_reverse($ids)) . '/',
        ];
    }

    private function sourceDocument(array $contexts, string $workspaceId): array
    {
        foreach (self::SOURCE_KINDS as $kind) {
            foreach ($contexts as $context) {
                if ((string)($context['kind'] ?? '') === $kind) {
                    $id = (string)($context['id'] ?? '');
                    return ['type' => $kind, 'id' => $id, 'no' => $id];
                }
            }
        }
        return ['type' => 'cashier_workspace', 'id' => $workspaceId, 'no' => $workspaceId];
    }

    private function contextVersion(array $contexts, string $kind, string $id): int
    {
        foreach ($contexts as $context) {
            if ((string)($context['kind'] ?? '') === $kind
                && (string)($context['id'] ?? '') === $id) {
                $version = (int)($context['expected_version'] ?? $context['expectedVersion'] ?? 0);
                if ($version > 0) {
                    return $version;
                }
            }
        }
        throw self::incomplete('checkout_workspace_version_missing');
    }

    private function moneyToCents($amount): int
    {
        if (!is_string($amount)
            || preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/D', $amount) !== 1) {
            throw self::incomplete('checkout_entitlement_actual_amount_invalid');
        }
        $parts = explode('.', $amount, 2);
        $raw = ltrim($parts[0] . str_pad($parts[1] ?? '', 2, '0'), '0');
        $raw = $raw === '' ? '0' : $raw;
        if (strlen($raw) > 12 || (string)(int)$raw !== $raw) {
            throw self::incomplete('checkout_entitlement_actual_amount_overflow');
        }
        return (int)$raw;
    }

    private static function debtIntentAmountCents($value): int
    {
        if (is_string($value) && preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) === 1) {
            $value = (int)$value;
        }
        if (!is_int($value) || $value < 0 || $value > 100000000000) {
            throw self::incomplete('checkout_debt_intent_amount_invalid');
        }
        return $value;
    }

    private static function editingRequestVersion($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1) {
            $value = (int)$value;
        }
        if (!is_int($value) || $value <= 0 || $value > PHP_INT_MAX) {
            throw self::incomplete('checkout_edit_version_invalid');
        }
        return $value;
    }

    private function serverIdSecret(): string
    {
        $secret = $this->serverIdSecret;
        if ($secret === '' && function_exists('config')) {
            $secret = trim((string)config('cashier_v3.checkout_namespace_secret'));
        }
        if (strlen($secret) < 32) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ACTION_DEPENDENCY_NOT_READY,
                '结账请求签名服务尚未配置，请联系管理员。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'checkout_namespace_secret_missing']
            );
        }
        return $secret;
    }

    private function shadowVersion(string $kind, int $resourceId): int
    {
        $row = Db::name(CashierV3EntitlementResourceVersionProvider::VERSION_TABLE)
            ->where('resource_kind', $kind)
            ->where('resource_id', (string)$resourceId)
            ->field('current_version')
            ->find();
        $version = (int)($row['current_version'] ?? 0);
        if ($version <= 0) {
            throw self::incomplete('checkout_discovery_member_version_missing');
        }
        return $version;
    }

    private static function workspaceId(
        CashierV3OperatorScope $operatorScope,
        string $stateContextId
    ): string {
        if ($stateContextId === '') {
            throw self::incomplete('checkout_state_context_missing');
        }
        return sprintf(
            'ws:%d:%d:%s',
            $operatorScope->storeId(),
            $operatorScope->operatorId(),
            $stateContextId
        );
    }

    private static function resource(string $kind, int $id, int $version, string $role): array
    {
        return [
            'kind' => $kind,
            'id' => (string)$id,
            'expectedVersion' => $version,
            'roles' => [$role],
            'accessMode' => 'read',
            'providerContractVersion' => 'cashier-entitlement-projection-resource-v1',
            'authorityFingerprint' => hash('sha256', $kind . '|' . $id . '|' . $version),
        ];
    }

    private static function incomplete(string $reason): CashierV3CommandException
    {
        return new CashierV3CommandException(
            CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
            '结账所需的商品或权益资料不完整，请刷新购物车后重试。',
            CashierV3ResultCode::STATUS_FAILED,
            ['reason' => $reason]
        );
    }
}
