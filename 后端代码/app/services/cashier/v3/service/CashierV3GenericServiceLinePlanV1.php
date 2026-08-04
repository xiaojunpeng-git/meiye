<?php

namespace app\services\cashier\v3\service;

/**
 * Server-only persistence plan for a service project originating from a sale.
 * It cannot create or mutate entitlement occupation.
 */
final class CashierV3GenericServiceLinePlanV1
{
    public const CONTRACT_VERSION = 'cashier-v3-generic-service-line-v1';

    /** @var array */
    private $row;

    private function __construct(array $row)
    {
        $this->row = $row;
    }

    public static function fromStartService(array $input): self
    {
        self::assertExactKeys($input, [
            'tenantId', 'serviceOrderId', 'lineKey', 'sourceId', 'sourceVersion',
            'hangLineId', 'projectId', 'projectNameSnapshot', 'serviceQuantity',
            'serviceTarget', 'isExperience', 'artisanStaffId', 'artisanEmployeeId',
            'artisanNameSnapshot', 'authorityFingerprint', 'createdAt',
        ]);
        $target = (string)$input['serviceTarget'];
        if (!in_array($target, ['SELF', 'FRIEND'], true)) {
            throw self::failure('generic_service_line_target_invalid');
        }
        if (!is_int($input['isExperience']) || !in_array($input['isExperience'], [0, 1], true)) {
            throw self::failure('generic_service_line_experience_invalid');
        }
        $fingerprint = is_string($input['authorityFingerprint'])
            ? strtolower(trim($input['authorityFingerprint']))
            : '';
        if (preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) {
            throw self::failure('generic_service_line_authority_fingerprint_invalid');
        }
        $createdAt = self::positiveInt($input['createdAt'], 'generic_service_line_created_at_invalid');
        $row = [
            'tenant_id' => self::token($input['tenantId'], 32, 'generic_service_line_tenant_invalid'),
            'service_order_id' => self::positiveInt($input['serviceOrderId'], 'generic_service_line_order_invalid'),
            'line_key' => self::token($input['lineKey'], 64, 'generic_service_line_key_invalid'),
            'source_type' => ThinkPhpCashierV3ServiceOrderRepository::LINE_SOURCE_SALE_PROJECT,
            'source_id' => self::positiveInt($input['sourceId'], 'generic_service_line_source_invalid'),
            'source_version_snapshot' => self::positiveInt($input['sourceVersion'], 'generic_service_line_source_version_invalid'),
            'hang_line_id' => self::positiveInt($input['hangLineId'], 'generic_service_line_hang_line_invalid'),
            'service_quantity' => self::positiveInt($input['serviceQuantity'], 'generic_service_line_quantity_invalid'),
            'authority_fingerprint' => $fingerprint,
            'entitlement_source_detail_id' => 0,
            'entitlement_instance_id' => 0,
            'project_id' => self::positiveInt($input['projectId'], 'generic_service_line_project_invalid'),
            'project_name_snapshot' => self::text($input['projectNameSnapshot'], 128, 'generic_service_line_project_name_invalid'),
            'occupied_times' => 0,
            'service_target' => $target,
            'is_experience' => $input['isExperience'],
            'artisan_staff_id' => self::nonNegativeInt($input['artisanStaffId'], 'generic_service_line_artisan_staff_invalid'),
            'artisan_employee_id' => self::nonNegativeInt($input['artisanEmployeeId'], 'generic_service_line_artisan_employee_invalid'),
            'artisan_name_snapshot' => self::optionalText($input['artisanNameSnapshot'], 64, 'generic_service_line_artisan_name_invalid'),
            'status' => CashierV3ServiceOrderState::LINE_ACTIVE,
            'version' => 1,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];
        if (($row['artisan_staff_id'] === 0) !== ($row['artisan_employee_id'] === 0)
            || ($row['artisan_employee_id'] === 0) !== ($row['artisan_name_snapshot'] === '')) {
            throw self::failure('generic_service_line_artisan_snapshot_invalid');
        }
        return new self($row);
    }

    public function row(): array
    {
        return $this->row;
    }

    private static function assertExactKeys(array $input, array $expected): void
    {
        $actual = array_keys($input);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw self::failure('generic_service_line_shape_invalid');
        }
    }

    private static function positiveInt($value, string $reason): int
    {
        if (!is_int($value) || $value <= 0 || $value >= PHP_INT_MAX) {
            throw self::failure($reason);
        }
        return $value;
    }

    private static function nonNegativeInt($value, string $reason): int
    {
        if (!is_int($value) || $value < 0 || $value >= PHP_INT_MAX) {
            throw self::failure($reason);
        }
        return $value;
    }

    private static function token($value, int $max, string $reason): string
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '' || strlen($value) > $max || preg_match('/^[A-Za-z0-9:._-]+$/D', $value) !== 1) {
            throw self::failure($reason);
        }
        return $value;
    }

    private static function text($value, int $max, string $reason): string
    {
        $value = is_string($value) ? trim($value) : '';
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        if ($value === '' || $length > $max) {
            throw self::failure($reason);
        }
        return $value;
    }

    private static function optionalText($value, int $max, string $reason): string
    {
        if (!is_string($value)) {
            throw self::failure($reason);
        }
        $value = trim($value);
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        if ($length > $max) {
            throw self::failure($reason);
        }
        return $value;
    }

    private static function failure(string $reason): CashierV3ServiceOrderAuthorityException
    {
        return new CashierV3ServiceOrderAuthorityException($reason);
    }
}
