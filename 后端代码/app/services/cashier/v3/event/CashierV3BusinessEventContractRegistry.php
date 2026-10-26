<?php

namespace app\services\cashier\v3\event;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3ResultCode;

/**
 * Immutable validation for the event contract attached to an action.
 * A command must explicitly declare either required event types or an
 * eventless reason; an omitted contract is never treated as eventless.
 */
final class CashierV3BusinessEventContractRegistry
{
    public static function normalize(array $definition, string $action = ''): array
    {
        if (!array_key_exists('event_contract', $definition)
            || !is_array($definition['event_contract'])) {
            throw self::invalid($action, 'contract_missing');
        }
        $raw = $definition['event_contract'];
        $required = self::strings($raw['required_event_types'] ?? null);
        $allowed = self::strings($raw['allowed_event_types'] ?? null);
        $eventlessReason = trim((string)($raw['eventless_reason'] ?? ''));
        $activationBlocked = $raw['activation_blocked_until_event_contract'] ?? false;
        if (!is_bool($activationBlocked)) {
            throw self::invalid($action, 'activation_blocked_flag_invalid');
        }
        if ($required && !$allowed) {
            $allowed = $required;
        }
        foreach ($required as $type) {
            if (!in_array($type, $allowed, true)) {
                throw self::invalid($action, 'required_type_not_allowed');
            }
        }
        if (!$required && $eventlessReason === '') {
            throw self::invalid($action, 'eventless_reason_missing');
        }
        if ($required && $eventlessReason !== '') {
            throw self::invalid($action, 'eventless_reason_with_required_type');
        }
        if ($required && $activationBlocked) {
            throw self::invalid($action, 'required_event_contract_cannot_be_activation_blocked');
        }
        $eventRules = self::eventRules($raw, $required, $allowed, $action);
        $consumers = [];
        foreach ((array)($raw['consumers'] ?? []) as $eventType => $codes) {
            $eventType = trim((string)$eventType);
            if ($eventType === '' || !is_array($codes)) {
                throw self::invalid($action, 'consumer_contract_invalid');
            }
            $normalizedCodes = self::strings($codes);
            if (!in_array($eventType, $allowed, true) || $normalizedCodes !== array_values($codes)) {
                throw self::invalid($action, 'consumer_type_or_code_invalid');
            }
            $consumers[$eventType] = $normalizedCodes;
        }
        return [
            'required_event_types' => $required,
            'allowed_event_types' => $allowed,
            'event_rules' => $eventRules,
            'eventless_reason' => $eventlessReason,
            'activation_blocked_until_event_contract' => $activationBlocked,
            'consumers' => $consumers,
        ];
    }

    public static function forAction(string $action, array $definition): array
    {
        return self::normalize($definition, $action);
    }

    /** @return string[] */
    public static function activationProblems(array $normalizedContract, bool $handlerRegistered): array
    {
        if ($handlerRegistered
            && !empty($normalizedContract['activation_blocked_until_event_contract'])) {
            return ['production_event_contract'];
        }
        return [];
    }

    private static function strings($value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            $item = trim((string)$item);
            if ($item === '' || !preg_match('/^[a-z][a-z0-9_.-]{1,63}$/', $item)) {
                throw self::invalid('', 'event_type_or_consumer_invalid');
            }
            $out[] = $item;
        }
        return array_values(array_unique($out));
    }

    /**
     * @return array<string,array{min_count:int,max_count:int,aggregate_type:string,source_type:string,aggregate_version:int|null}>
     */
    private static function eventRules(array $raw, array $required, array $allowed, string $action): array
    {
        $hasRules = array_key_exists('event_rules', $raw);
        $rawRules = $raw['event_rules'] ?? [];
        if (!is_array($rawRules)) {
            throw self::invalid($action, 'event_rules_not_array');
        }
        if (!$hasRules) {
            // Transitional compatibility for isolated direct tests. Product
            // manifest contracts use explicit rules for every allowed type.
            $rawRules = [];
            foreach ($allowed as $type) {
                $rawRules[$type] = [
                    'min_count' => in_array($type, $required, true) ? 1 : 0,
                    'max_count' => PHP_INT_MAX,
                    'aggregate_type' => '',
                    'source_type' => '',
                    'aggregate_version' => null,
                ];
            }
        }

        $rules = [];
        foreach ($rawRules as $eventType => $rule) {
            $eventType = trim((string)$eventType);
            if (!in_array($eventType, $allowed, true) || !is_array($rule)) {
                throw self::invalid($action, 'event_rule_type_or_shape_invalid');
            }
            $min = self::nonNegativeInt($rule['min_count'] ?? null, $action, 'event_rule_min_invalid');
            $max = self::nonNegativeInt($rule['max_count'] ?? null, $action, 'event_rule_max_invalid');
            if ($max < $min) {
                throw self::invalid($action, 'event_rule_cardinality_invalid');
            }
            $aggregateType = trim((string)($rule['aggregate_type'] ?? ''));
            $sourceType = trim((string)($rule['source_type'] ?? ''));
            if ($aggregateType !== '' && !preg_match('/^[a-z][a-z0-9_.-]{1,31}$/', $aggregateType)) {
                throw self::invalid($action, 'event_rule_aggregate_type_invalid');
            }
            if ($sourceType !== '' && !preg_match('/^[a-z][a-z0-9_.-]{1,63}$/', $sourceType)) {
                throw self::invalid($action, 'event_rule_source_type_invalid');
            }
            $aggregateVersion = null;
            if (array_key_exists('aggregate_version', $rule) && $rule['aggregate_version'] !== null) {
                $aggregateVersion = self::positiveInt($rule['aggregate_version'], $action, 'event_rule_aggregate_version_invalid');
            }
            $rules[$eventType] = [
                'min_count' => $min,
                'max_count' => $max,
                'aggregate_type' => $aggregateType,
                'source_type' => $sourceType,
                'aggregate_version' => $aggregateVersion,
            ];
        }

        $ruleTypes = array_keys($rules);
        sort($ruleTypes, SORT_STRING);
        $allowedTypes = $allowed;
        sort($allowedTypes, SORT_STRING);
        if ($ruleTypes !== $allowedTypes) {
            throw self::invalid($action, 'event_rules_must_cover_allowed_types');
        }
        foreach ($rules as $eventType => $rule) {
            $mustExist = in_array($eventType, $required, true);
            if (($rule['min_count'] > 0) !== $mustExist) {
                throw self::invalid($action, 'required_types_and_min_count_mismatch');
            }
        }
        return $rules;
    }

    private static function nonNegativeInt($value, string $action, string $reason): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^(0|[1-9]\d*)$/', $value)) {
            $normalized = (int)$value;
            if ((string)$normalized === $value) {
                return $normalized;
            }
        }
        throw self::invalid($action, $reason);
    }

    private static function positiveInt($value, string $action, string $reason): int
    {
        $normalized = self::nonNegativeInt($value, $action, $reason);
        if ($normalized <= 0) {
            throw self::invalid($action, $reason);
        }
        return $normalized;
    }

    private static function invalid(string $action, string $reason): CashierV3CommandException
    {
        return new CashierV3CommandException(
            CashierV3ResultCode::EVENT_CONTRACT_INVALID,
            '业务事件合同无效，当前操作已停止。',
            CashierV3ResultCode::STATUS_FAILED,
            ['action' => $action, 'reason' => $reason]
        );
    }
}
