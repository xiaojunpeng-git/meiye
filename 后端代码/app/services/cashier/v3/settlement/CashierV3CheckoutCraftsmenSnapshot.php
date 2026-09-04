<?php

namespace app\services\cashier\v3\settlement;

use app\services\cashier\v3\CashierV3PersonnelIdentity;

/**
 * Canonical immutable craftsmen snapshot shared by checkout and sales orders.
 *
 * The snapshot originates from the server-locked cashier workspace line. It is
 * deliberately not rebuilt from a current member, product, or employee query
 * when an order is read later.
 */
final class CashierV3CheckoutCraftsmenSnapshot
{
    private const MAX_CRAFTSMEN = 20;

    /** @return array<int,array{id:int,staffId:int,employeeId:int,storeId:int,name:string,isPrimary:bool,sequence:int,laborWeight:int,isPointCustomer:bool,personnelSource?:string}> */
    public static function normalize($rows): array
    {
        if (!is_array($rows) || !self::isList($rows) || count($rows) > self::MAX_CRAFTSMEN) {
            throw new \InvalidArgumentException('craftsmen_snapshot_shape_invalid');
        }

        $normalized = [];
        $staffIds = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException('craftsmen_snapshot_item_invalid');
            }
            $baseKeys = [
                'id', 'staffId', 'employeeId', 'storeId', 'name', 'isPrimary',
                'sequence', 'laborWeight', 'isPointCustomer',
            ];
            $hasPerformanceFields = array_key_exists('craftsmanPerformanceType', $row)
                || array_key_exists('laborFeeCents', $row);
            $hasPerformanceAmount = array_key_exists('performanceAmountCents', $row)
                || array_key_exists('performanceAmountManual', $row);
            $hasProjectCount = array_key_exists('projectCountHalfUnits', $row);
            $hasPersonnelSource = array_key_exists('personnelSource', $row);
            $hasPosition = array_key_exists('positionId', $row)
                || array_key_exists('positionName', $row)
                || array_key_exists('performanceIndependent', $row)
                || array_key_exists('allocationGroupKey', $row);
            $optionalKeys = $hasPerformanceFields ? ['craftsmanPerformanceType', 'laborFeeCents'] : [];
            if ($hasPerformanceAmount) $optionalKeys = array_merge($optionalKeys, ['performanceAmountCents', 'performanceAmountManual']);
            if ($hasProjectCount) $optionalKeys[] = 'projectCountHalfUnits';
            if ($hasPersonnelSource) $optionalKeys[] = 'personnelSource';
            foreach (['positionId', 'positionName', 'performanceIndependent', 'allocationGroupKey'] as $positionKey) {
                if (array_key_exists($positionKey, $row)) $optionalKeys[] = $positionKey;
            }
            self::assertExactKeys($row, array_merge($baseKeys, $optionalKeys));
            $staffId = self::positiveInt($row['staffId']);
            if (self::positiveInt($row['id']) !== $staffId || isset($staffIds[$staffId])) {
                throw new \InvalidArgumentException('craftsmen_snapshot_staff_invalid');
            }
            $staffIds[$staffId] = true;
            $employeeId = self::positiveInt($row['employeeId']);
            $source = array_key_exists('personnelSource', $row)
                ? (string)$row['personnelSource']
                : (CashierV3PersonnelIdentity::isOrganizationStaffId($staffId) ? 'other' : 'store');
            if (!in_array($source, ['store', 'other'], true)) {
                throw new \InvalidArgumentException('craftsmen_snapshot_personnel_source_invalid');
            }
            // Virtual organization staff IDs are resource keys, never employee
            // identities. Normalize them before persisting the immutable order
            // snapshot so reports and facts aggregate by the real employee.
            if ($source === 'other' && CashierV3PersonnelIdentity::isOrganizationStaffId($staffId)) {
                $employeeId = CashierV3PersonnelIdentity::employeeIdFromStaffId($staffId);
            }
            $storeId = self::positiveInt($row['storeId']);
            $name = trim((string)$row['name']);
            if ($name === '' || self::textLength($name) > 128) {
                throw new \InvalidArgumentException('craftsmen_snapshot_name_invalid');
            }
            if (!is_bool($row['isPrimary'])
                || !is_bool($row['isPointCustomer'])
                || !is_int($row['sequence'])
                || $row['sequence'] !== $index + 1
                || $row['isPrimary'] !== ($index === 0)) {
                throw new \InvalidArgumentException('craftsmen_snapshot_sequence_invalid');
            }
            $laborWeight = self::nonNegativeInt($row['laborWeight']);
            if ($laborWeight > 100) {
                throw new \InvalidArgumentException('craftsmen_snapshot_weight_invalid');
            }
            $performanceType = $hasPerformanceFields ? (string)$row['craftsmanPerformanceType'] : 'commission_labor';
            if (!in_array($performanceType, ['commission', 'labor', 'commission_labor'], true)) {
                throw new \InvalidArgumentException('craftsmen_snapshot_performance_type_invalid');
            }
            $laborFeeCents = $hasPerformanceFields ? self::nonNegativeInt($row['laborFeeCents']) : 0;
            if ($performanceType === 'commission' && $laborFeeCents !== 0) {
                throw new \InvalidArgumentException('craftsmen_snapshot_labor_fee_invalid');
            }
            $normalized[] = [
                'id' => $staffId,
                'staffId' => $staffId,
                'employeeId' => $employeeId,
                'storeId' => $storeId,
                'name' => $name,
                'isPrimary' => $row['isPrimary'],
                'sequence' => $row['sequence'],
                'laborWeight' => $laborWeight,
                'isPointCustomer' => $row['isPointCustomer'],
            ];
            if ($hasPerformanceFields) {
                $normalized[count($normalized) - 1]['craftsmanPerformanceType'] = $performanceType;
                $normalized[count($normalized) - 1]['laborFeeCents'] = $laborFeeCents;
            }
            if ($hasPerformanceAmount) {
                if (!array_key_exists('performanceAmountCents', $row)
                    || !array_key_exists('performanceAmountManual', $row)
                    || !is_bool($row['performanceAmountManual'])) {
                    throw new \InvalidArgumentException('craftsmen_snapshot_performance_amount_invalid');
                }
                $performanceAmountCents = self::nonNegativeInt($row['performanceAmountCents']);
                $performanceAmountManual = $row['performanceAmountManual'];
                if ($performanceType === 'labor' && ($performanceAmountCents !== 0 || $performanceAmountManual)) {
                    throw new \InvalidArgumentException('craftsmen_snapshot_labor_performance_amount_invalid');
                }
                $normalized[count($normalized) - 1]['performanceAmountCents'] = $performanceAmountCents;
                $normalized[count($normalized) - 1]['performanceAmountManual'] = $performanceAmountManual;
            }
            if ($hasProjectCount) {
                $normalized[count($normalized) - 1]['projectCountHalfUnits'] = self::nonNegativeInt(
                    $row['projectCountHalfUnits'],
                    'craftsmen_snapshot_project_count_invalid'
                );
            }
            if ($hasPersonnelSource || $source === 'other') {
                $normalized[count($normalized) - 1]['personnelSource'] = $source;
            }
            if ($hasPosition) {
                $positionId = self::nonNegativeInt($row['positionId'] ?? 0, 'craftsmen_snapshot_position_invalid');
                $explicitGroupKey = trim((string)($row['allocationGroupKey'] ?? ''));
                $performanceIndependent = !empty($row['performanceIndependent'])
                    || strncmp($explicitGroupKey, 'independent:', 12) === 0;
                $groupKey = $performanceIndependent
                    ? ($explicitGroupKey !== '' && strncmp($explicitGroupKey, 'independent:', 12) === 0
                        ? $explicitGroupKey
                        : 'independent:' . ($positionId > 0 ? $positionId : (int)($row['staffId'] ?? $row['id'] ?? 0)))
                    : 'normal';
                if ($positionId > 0) {
                    $normalized[count($normalized) - 1]['positionId'] = $positionId;
                    $normalized[count($normalized) - 1]['positionName'] = trim((string)($row['positionName'] ?? ''));
                }
                if ($performanceIndependent) {
                    $normalized[count($normalized) - 1]['performanceIndependent'] = true;
                    $normalized[count($normalized) - 1]['allocationGroupKey'] = $groupKey;
                }
            }
        }
        return $normalized;
    }

    /** @return array<int,array{id:int,staffId:int,employeeId:int,storeId:int,name:string,isPrimary:bool,sequence:int,laborWeight:int,isPointCustomer:bool}> */
    public static function decode($json): array
    {
        if (self::isLegacyEmpty($json)) {
            // Pre-snapshot historical rows are intentionally displayed without
            // invented personnel. MySQL 5.6 cannot define a TEXT default.
            return [];
        }
        if (!is_string($json)) {
            throw new \InvalidArgumentException('craftsmen_snapshot_json_invalid');
        }
        $decoded = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('craftsmen_snapshot_json_invalid');
        }
        return self::normalize($decoded);
    }

    public static function isLegacyEmpty($json): bool
    {
        return $json === null || $json === '';
    }

    public static function encode(array $rows): string
    {
        return CashierV3CheckoutSettlementCanonicalizer::encode(self::normalize($rows));
    }

    private static function positiveInt($value): int
    {
        if (!is_int($value) || $value <= 0) {
            throw new \InvalidArgumentException('craftsmen_snapshot_positive_integer_invalid');
        }
        return $value;
    }

    private static function nonNegativeInt($value): int
    {
        if (!is_int($value) || $value < 0) {
            throw new \InvalidArgumentException('craftsmen_snapshot_non_negative_integer_invalid');
        }
        return $value;
    }

    private static function assertExactKeys(array $row, array $expected): void
    {
        $actual = array_keys($row);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new \InvalidArgumentException('craftsmen_snapshot_item_shape_invalid');
        }
    }

    private static function isList(array $value): bool
    {
        return array_keys($value) === ($value === [] ? [] : range(0, count($value) - 1));
    }

    private static function textLength(string $value): int
    {
        return function_exists('mb_strlen') ? (int)mb_strlen($value) : strlen($value);
    }
}
