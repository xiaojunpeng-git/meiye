<?php
declare(strict_types=1);

namespace app\services\cashier\v3\settlement;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use think\facade\Db;

/**
 * Rebuilds the debt resource for a repayment from the persisted checkout
 * request. The browser may only submit the workspace and checkout request.
 */
final class CashierV3DebtRepaymentResourceDiscovery
{
    public const CONTRACT_VERSION = 'cashier-v3-debt-repayment-discovery-v1';

    /** @return array{contractVersion:string,resources:array<int,array>} */
    public function discover(array $scope): array
    {
        $payload = is_array($scope['payload'] ?? null) ? $scope['payload'] : [];
        $operator = $scope['operator_scope'] ?? null;
        $dataScope = $scope['data_scope'] ?? null;
        if (!$operator instanceof CashierV3OperatorScope || !$dataScope instanceof CashierV3DataScopeContext) {
            throw self::failure('debt_repayment_discovery_scope_missing');
        }

        $requestId = trim((string)($payload['checkoutRequestId'] ?? $payload['checkout_request_id'] ?? ''));
        if (preg_match('/^CKR-[0-9a-f]{40}$/D', $requestId) !== 1) {
            throw self::invalid('debt_repayment_checkout_request_invalid');
        }

        $request = (array)Db::name('cashier_v3_checkout_request')
            ->where('request_id', $requestId)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('store_id', $operator->storeId())
            ->where('operator_id', $operator->operatorId())
            ->field('request_id,request_version,request_status,source_document_type,source_document_id')
            ->find();
        if (!$request
            || (string)($request['request_status'] ?? '') !== 'editing'
            || (string)($request['source_document_type'] ?? '') !== 'debt_repayment') {
            throw self::invalid('debt_repayment_checkout_source_invalid');
        }
        $debtId = (int)($request['source_document_id'] ?? 0);
        if ($debtId <= 0) {
            throw self::invalid('debt_repayment_checkout_debt_invalid');
        }

        $reference = (array)Db::name('cashier_v3_checkout_source_reference')
            ->where('request_id', $requestId)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('store_id', $operator->storeId())
            ->where('bound_request_version', (int)$request['request_version'])
            ->where('source_kind', 'debt_record')
            ->where('source_id', (string)$debtId)
            ->field('source_kind,source_id,source_version,source_role,source_fingerprint')
            ->find();
        if (!$reference
            || (int)($reference['source_version'] ?? 0) <= 0
            || (string)($reference['source_role'] ?? '') !== 'debt_record') {
            throw self::invalid('debt_repayment_checkout_reference_missing');
        }

        $version = (array)Db::name('cashier_v3_entitlement_resource_version')
            ->where('resource_kind', 'debt_record')
            ->where('resource_id', (string)$debtId)
            ->field('current_version,source_fingerprint')
            ->find();
        $currentVersion = (int)($version['current_version'] ?? 0);
        if ($currentVersion <= 0 || $currentVersion !== (int)$reference['source_version']) {
            throw CashierV3CommandException::versionConflict(
                '该笔欠款已经变化，请刷新后重新补交。',
                ['reason' => 'debt_repayment_source_version_changed']
            );
        }

        return [
            'contractVersion' => self::CONTRACT_VERSION,
            'resources' => [[
                'kind' => 'debt_record',
                'id' => (string)$debtId,
                'expectedVersion' => $currentVersion,
                'roles' => ['debt_record'],
                'accessMode' => 'mutate',
                'providerContractVersion' => 'cashier-v3-entitlement-resource-v1',
                'authorityFingerprint' => hash('sha256', implode('|', [
                    $requestId,
                    (string)$reference['source_fingerprint'],
                    (string)($version['source_fingerprint'] ?? ''),
                    (string)$currentVersion,
                ])),
            ]],
        ];
    }

    private static function invalid(string $reason): CashierV3CommandException
    {
        return new CashierV3CommandException(
            CashierV3ResultCode::INVALID_COMMAND_CONTEXT,
            '欠款补交资料已变化，请刷新后重试。',
            CashierV3ResultCode::STATUS_FAILED,
            ['reason' => $reason]
        );
    }

    private static function failure(string $reason): CashierV3CommandException
    {
        return new CashierV3CommandException(
            CashierV3ResultCode::ACTION_DEPENDENCY_NOT_READY,
            '欠款补交资源暂时不可用，请稍后重试。',
            CashierV3ResultCode::STATUS_FAILED,
            ['reason' => $reason]
        );
    }
}
