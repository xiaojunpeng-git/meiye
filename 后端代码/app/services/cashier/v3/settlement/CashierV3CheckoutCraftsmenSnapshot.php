<?php

namespace app\services\cashier\v3\settlement;

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

    /** @return array<int,array{id:int,staffId:int,employeeId:int,storeId:int,name:string,isPrimary:bool,sequence:int,laborWeight:int,isPointCustomer:bool}> */
    public static function normalize($rows): array
    {
        if (!is_array($rows) || !self::isList($rows) || count($rows) > self::MAX_CRAFTSMEN) {
            throw new \InvalidArgumentException('craftsmen_snapshot_shape_invalid');
        }

        $normalized = [];
        $staffIds = [];
        $weightTotal = 0;
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException('craftsmen_snapshot_item_invalid');
            }
            self::assertExactKeys($row, [
                'id', 'staffId', 'employeeId', 'storeId', 'name', 'isPrimary',
                'sequence', 'laborWeight', 'isPointCustomer',
            ]);
            $staffId = self::positiveInt($row['staffId']);
            if (self::positiveInt($row['id']) !== $staffId || isset($staffIds[$staffId])) {
                throw new \InvalidArgumentException('craftsmen_snapshot_staff_invalid');
            }
            $staffIds[$staffId] = true;
            $employeeId = self::positiveInt($row['employeeId']);
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
            $laborWeight = self::positiveInt($row['laborWeight']);
            if ($laborWeight > 100) {
                throw new \InvalidArgumentException('craftsmen_snapshot_weight_invalid');
            }
            $weightTotal += $laborWeight;
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
        }
        if ($normalized !== [] && $weightTotal !== 100) {
            throw new \InvalidArgumentException('craftsmen_snapshot_weight_sum_invalid');
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
