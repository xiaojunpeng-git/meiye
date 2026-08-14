<?php

namespace app\services\cashier\v3\settlement;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\CashierV3CheckoutWorkspaceIdentity;
use app\services\cashier\v3\report\CashierV3GuideRoundFactServices;

/** Read-only guide-round date check run before the payment step opens. */
final class CashierV3CheckoutGuideRoundPreflightServices
{
    /** @var CashierV3CheckoutRequestRepository */
    private $requests;

    public function __construct(CashierV3CheckoutRequestRepository $requests = null)
    {
        $this->requests = $requests ?: new ThinkPhpCashierV3CheckoutRequestRepository();
    }

    public function validateInTx(
        string $stateContextId,
        string $checkoutRequestId,
        int $checkoutRequestVersion,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        CashierV3TransactionGuard::assertInTransaction('checkoutGuideRoundPreflight');
        $stateContextId = trim($stateContextId);
        $workspaceId = CashierV3CheckoutWorkspaceIdentity::id($operatorScope->storeId(), $stateContextId);
        $aggregate = $this->requests->lockAggregateForEditInTx(
            trim($checkoutRequestId),
            $checkoutRequestVersion,
            $workspaceId,
            $stateContextId,
            $operatorScope,
            $dataScope
        );
        $request = (array)($aggregate['request'] ?? []);
        $availability = (new CashierV3GuideRoundFactServices())->assertAvailableInTx([
            'tenant_id' => $dataScope->tenantId(),
            'organization_id' => $operatorScope->organizationId(),
            'store_id' => $operatorScope->storeId(),
            'member_id' => (int)($request['member_id'] ?? 0),
            'business_date' => (string)($request['business_date'] ?? ''),
            'operator_id' => $operatorScope->operatorId(),
        ], self::guideSelectionsByCheckoutLine((array)($aggregate['lines'] ?? [])), $operatorScope, $dataScope);

        return [
            'checkoutRequestId' => (string)$request['request_id'],
            'checkoutRequestVersion' => (int)$request['request_version'],
            'guideRoundNo' => (int)$availability['round_no'],
            'guideCount' => (int)$availability['guide_count'],
            'eventless' => true,
            'message' => '导购轮次校验通过。',
        ];
    }

    private static function guideSelectionsByCheckoutLine(array $lines): array
    {
        $result = [];
        foreach ($lines as $line) {
            if (!is_array($line)) continue;
            $lineId = trim((string)($line['line_id'] ?? $line['line_key'] ?? $line['checkout_line_id'] ?? $line['id'] ?? ''));
            if ($lineId === '') continue;
            $raw = $line['guide_selections_json'] ?? $line['guideSelections'] ?? $line['guide_selections'] ?? [];
            if (is_string($raw)) {
                $raw = json_decode($raw, true);
                if (!is_array($raw)) throw self::failure('guide_selection_invalid');
            }
            if ($raw === null || $raw === []) continue;
            if (!is_array($raw)) throw self::failure('guide_selection_invalid');
            $result[$lineId] = array_values($raw);
        }
        return $result;
    }

    private static function failure(string $reason): CashierV3CommandException
    {
        return new CashierV3CommandException(
            CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
            '导购轮次资料无效，请返回购物车重新选择后再结账。',
            CashierV3ResultCode::STATUS_FAILED,
            ['reason' => $reason]
        );
    }
}
