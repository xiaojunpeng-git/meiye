<?php

namespace app\services\cashier\v3\settlement;

use app\services\cashier\v3\CashierV3BusinessDocumentNumberServices;
use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\order\settlement\CashierV3SalesOrderPlanV1;
use app\services\cashier\v3\order\settlement\ThinkPhpCashierV3SalesOrderAuthorityWriter;
use think\facade\Db;

/**
 * Cash purchase of a project is an immediately completed service by product
 * decision.  It therefore writes the same immutable service fact read by the
 * service-record workbench, while leaving products, cards and entitlement
 * write-off facts untouched.
 */
final class CashierV3SaleProjectServiceCompletionServices
{
    private const SERVICE_TABLE = 'cashier_v3_entitlement_service_fact';

    /** @var ThinkPhpCashierV3SalesOrderAuthorityWriter */
    private $salesOrders;

    public function __construct(?ThinkPhpCashierV3SalesOrderAuthorityWriter $salesOrders = null)
    {
        $this->salesOrders = $salesOrders ?: new ThinkPhpCashierV3SalesOrderAuthorityWriter();
    }

    /**
     * @return array{serviceCount:int,replayed:bool,services:array<int,array<string,mixed>>}
     */
    public function completeInTx(
        CashierV3SalesOrderPlanV1 $salesPlan,
        array $salesResult,
        int $recordedAt,
        string $businessEventNo
    ): array {
        CashierV3TransactionGuard::assertInTransaction('saleProjectServiceCompletion.completeInTx');
        $header = $salesPlan->header();
        $orderId = (string)($salesResult['orderId'] ?? $header['order_id'] ?? '');
        $orderNo = (string)($salesResult['orderNo'] ?? $header['order_no'] ?? '');
        if ($orderId === '' || $orderNo === '' || $recordedAt <= 0 || $businessEventNo === '') {
            throw self::failure('sale_project_service_order_missing');
        }
        $sourceDocumentId = $this->salesOrders->internalRecordIdInTx(
            (string)$header['tenant_id'],
            $orderId
        );
        if ($sourceDocumentId <= 0) {
            throw self::failure('sale_project_service_order_record_missing');
        }

        $numbers = new CashierV3BusinessDocumentNumberServices();
        $services = [];
        $replayed = false;
        foreach ($salesPlan->lines() as $line) {
            if ((string)($line['item_type'] ?? '') !== 'project') {
                continue;
            }
            $sourceLineId = (string)($line['order_line_id'] ?? '');
            if ($sourceLineId === '') {
                throw self::failure('sale_project_service_line_missing');
            }
            try {
                $craftsmen = CashierV3CheckoutCraftsmenSnapshot::decode(
                    (string)$line['craftsmen_snapshot_json']
                );
            } catch (\InvalidArgumentException $exception) {
                throw self::failure('sale_project_service_craftsmen_snapshot_invalid');
            }
            $primaryCraftsmanStaffId = 0;
            foreach ($craftsmen as $craftsman) {
                if (($craftsman['isPrimary'] ?? false) === true) {
                    $primaryCraftsmanStaffId = (int)$craftsman['staffId'];
                    break;
                }
            }
            $naturalKey = 'sale_project_service:' . (string)$header['checkout_request_id'] . ':' . $sourceLineId;
            $row = [
                'service_fact_id' => 'SSF-' . substr(hash('sha256', $naturalKey), 0, 40),
                'natural_key' => $naturalKey,
                'business_event_no' => $businessEventNo,
                'command_idempotency_key' => (string)$header['command_idempotency_key'],
                'tenant_id' => (string)$header['tenant_id'],
                'tenant_name_snapshot' => '',
                'organization_id' => (string)$header['organization_id'],
                'organization_name_snapshot' => (string)$header['organization_name_snapshot'],
                'organization_path_snapshot' => (string)$header['organization_path_snapshot'],
                'store_id' => (int)$header['store_id'],
                'store_name_snapshot' => (string)$header['store_name_snapshot'],
                'member_id' => (int)$header['member_id'],
                'member_name_snapshot' => (string)$header['member_name_snapshot'],
                'operator_id' => (int)$header['operator_id'],
                'operator_name_snapshot' => (string)$header['operator_name_snapshot'],
                'business_date' => (string)$header['business_date'],
                'business_timezone' => (string)$header['business_timezone'],
                'occurred_at' => (int)$header['occurred_at'],
                'settled_at' => (int)$header['settled_at'],
                'recorded_at' => $recordedAt,
                'checkout_request_id' => (string)$header['checkout_request_id'],
                'document_id' => $orderId,
                'document_no_snapshot' => $orderNo,
                'source_document_type' => 'sales_order',
                'source_document_id' => $sourceDocumentId,
                'source_line_id' => $sourceLineId,
                'project_id' => (int)$line['item_id'],
                'project_name_snapshot' => (string)$line['item_name_snapshot'],
                'project_category_id_snapshot' => (int)$line['category_id_snapshot'],
                'project_category_name_snapshot' => (string)$line['category_name_snapshot'],
                'quantity' => (int)$line['quantity'],
                'service_object' => (string)$line['service_object'],
                'is_experience' => (int)$line['is_experience'],
                // A paid project is immediately completed service. Preserve the
                // locked checkout line's personnel snapshot, not a later staff
                // lookup, so the service record stays historically traceable.
                'primary_craftsman_staff_id' => $primaryCraftsmanStaffId,
                'craftsmen_snapshot_json' => (string)$line['craftsmen_snapshot_json'],
                'service_status' => 'completed',
            ];
            $row['immutable_fingerprint'] = self::fingerprint($row);
            $existing = Db::name(self::SERVICE_TABLE)
                ->where('tenant_id', $row['tenant_id'])
                ->where('checkout_request_id', $row['checkout_request_id'])
                ->where('source_line_id', $sourceLineId)
                ->lock(true)
                ->find();
            if ($existing) {
                if ((string)($existing['immutable_fingerprint'] ?? '') !== $row['immutable_fingerprint']) {
                    throw self::failure('sale_project_service_replay_conflict');
                }
                $row['service_record_no'] = (string)($existing['service_record_no'] ?? '');
                $replayed = true;
            } else {
                $row['service_record_no'] = $numbers->allocateForSourceInTx(
                    $row['tenant_id'],
                    CashierV3BusinessDocumentNumberServices::SERVICE,
                    'sale_project_service_fact',
                    $row['service_fact_id'],
                    $row['business_date'],
                    $recordedAt
                );
                if ((int)Db::name(self::SERVICE_TABLE)->insert($row) !== 1) {
                    throw self::failure('sale_project_service_insert_failed');
                }
            }
            $services[] = [
                'serviceFactId' => $row['service_fact_id'],
                'serviceRecordNo' => $row['service_record_no'],
                'sourceLineId' => $sourceLineId,
                'projectId' => $row['project_id'],
                'projectNameSnapshot' => $row['project_name_snapshot'],
                'quantity' => $row['quantity'],
                'serviceObject' => $row['service_object'],
                'isExperience' => (bool)$row['is_experience'],
            ];
        }
        return ['serviceCount' => count($services), 'replayed' => $replayed, 'services' => $services];
    }

    private static function fingerprint(array $row): string
    {
        unset($row['service_record_no'], $row['immutable_fingerprint']);
        ksort($row, SORT_STRING);
        return hash('sha256', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function failure(string $reason): CashierV3CommandException
    {
        return new CashierV3CommandException(
            CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
            '项目服务记录保存失败，本次结账已回滚，请重试。',
            CashierV3ResultCode::STATUS_FAILED,
            ['reason' => $reason]
        );
    }
}
