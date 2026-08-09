<?php

namespace app\services\customer\care\query;

/**
 * Server-created customer-care scope. Never construct this object from page payloads.
 */
final class CustomerCareQueryScope
{
    /** @var array */
    private $context;

    private function __construct(array $context)
    {
        $this->context = $context;
    }

    public static function fromTrustedContext(array $input): self
    {
        $allowed = $input['allowedBusinessStoreIds'] ?? null;
        if (!is_array($allowed) || self::isAssociative($allowed) || $allowed === []) {
            throw self::invalid('allowedBusinessStoreIds', '后端强制门店范围不能为空。');
        }
        $stores = [];
        foreach ($allowed as $value) {
            $id = self::positiveInt($value, 'allowedBusinessStoreIds');
            $stores[$id] = $id;
        }
        ksort($stores, SORT_NUMERIC);

        $operationStoreId = self::positiveInt($input['operationStoreId'] ?? null, 'operationStoreId');
        if (!isset($stores[$operationStoreId])) {
            throw self::invalid('operationStoreId', '当前操作门店不在后端强制门店范围内。');
        }

        $timezone = self::text($input['businessTimezone'] ?? 'Asia/Shanghai', 'businessTimezone', 32);
        try {
            new \DateTimeZone($timezone);
        } catch (\Throwable $throwable) {
            throw self::invalid('businessTimezone', '业务时区无效。');
        }

        $permissions = [];
        foreach ([
            'canViewAllTasks', 'canCreateTask', 'canCreateRecord',
            'canReassign', 'canViewStatistics',
        ] as $permission) {
            if (!array_key_exists($permission, $input) || !is_bool($input[$permission])) {
                throw self::invalid($permission, '客情权限必须由后端明确注入。');
            }
            $permissions[$permission] = $input[$permission];
        }

        return new self(array_merge($permissions, [
            'tenantId' => self::identifier($input['tenantId'] ?? null, 'tenantId', 32),
            'staffId' => self::positiveInt($input['staffId'] ?? null, 'staffId'),
            'employeeId' => self::positiveInt($input['employeeId'] ?? null, 'employeeId'),
            'staffName' => self::text($input['staffName'] ?? null, 'staffName', 64),
            'operationStoreId' => $operationStoreId,
            'operationStoreName' => self::text(
                $input['operationStoreName'] ?? null,
                'operationStoreName',
                100
            ),
            'operationOrganizationId' => self::identifier(
                $input['operationOrganizationId'] ?? null,
                'operationOrganizationId',
                32
            ),
            'operationOrganizationPath' => self::organizationPath(
                $input['operationOrganizationPath'] ?? null
            ),
            'operationOrganizationName' => self::text(
                $input['operationOrganizationName'] ?? null,
                'operationOrganizationName',
                100
            ),
            'allowedBusinessStoreIds' => array_values($stores),
            'businessTimezone' => $timezone,
        ]));
    }

    public function tenantId(): string { return $this->context['tenantId']; }
    public function staffId(): int { return $this->context['staffId']; }
    public function employeeId(): int { return $this->context['employeeId']; }
    public function staffName(): string { return $this->context['staffName']; }
    public function operationStoreId(): int { return $this->context['operationStoreId']; }
    public function operationStoreName(): string { return $this->context['operationStoreName']; }
    public function operationOrganizationId(): string { return $this->context['operationOrganizationId']; }
    public function operationOrganizationPath(): string { return $this->context['operationOrganizationPath']; }
    public function operationOrganizationName(): string { return $this->context['operationOrganizationName']; }
    public function allowedBusinessStoreIds(): array { return $this->context['allowedBusinessStoreIds']; }
    public function businessTimezone(): string { return $this->context['businessTimezone']; }
    public function canViewAllTasks(): bool { return $this->context['canViewAllTasks']; }
    public function canCreateTask(): bool { return $this->context['canCreateTask']; }
    public function canCreateRecord(): bool { return $this->context['canCreateRecord']; }
    public function canReassign(): bool { return $this->context['canReassign']; }
    public function canViewStatistics(): bool { return $this->context['canViewStatistics']; }

    public function commandActor(): array
    {
        return [
            'tenantId' => $this->tenantId(),
            'staffId' => $this->staffId(),
            'employeeId' => $this->employeeId(),
            'staffName' => $this->staffName(),
            'operationStoreId' => $this->operationStoreId(),
            'operationStoreName' => $this->operationStoreName(),
            'operationOrganizationId' => $this->operationOrganizationId(),
            'operationOrganizationPath' => $this->operationOrganizationPath(),
            'operationOrganizationName' => $this->operationOrganizationName(),
            'allowedBusinessStoreIds' => $this->allowedBusinessStoreIds(),
            'canReassign' => $this->canReassign(),
        ];
    }

    public function permissionsDto(): array
    {
        return [
            'canViewAllTasks' => $this->canViewAllTasks(),
            'canCreateTask' => $this->canCreateTask(),
            'canCreateRecord' => $this->canCreateRecord(),
            'canReassign' => $this->canReassign(),
            'canViewStatistics' => $this->canViewStatistics(),
            // Rules and automatic exceptions are outside Phase B and stay fail-closed.
            'canManageRules' => false,
            'canHandleExceptions' => false,
        ];
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            'tenantId' => $this->tenantId(),
            'staffId' => $this->staffId(),
            'stores' => $this->allowedBusinessStoreIds(),
            'canViewAllTasks' => $this->canViewAllTasks(),
            'canViewStatistics' => $this->canViewStatistics(),
        ], JSON_UNESCAPED_SLASHES));
    }

    private static function positiveInt($value, string $field): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value)) {
            $number = (int)$value;
            if ($number > 0 && (string)$number === $value) {
                return $number;
            }
        }
        throw self::invalid($field, '客情正整数参数无效。');
    }

    private static function identifier($value, string $field, int $maxLength): string
    {
        $text = is_string($value) || is_int($value) ? trim((string)$value) : '';
        if ($text === '' || strlen($text) > $maxLength
            || !preg_match('/^[A-Za-z0-9][A-Za-z0-9:._-]*$/D', $text)) {
            throw self::invalid($field, '客情标识字段无效。');
        }
        return $text;
    }

    private static function organizationPath($value): string
    {
        $path = is_string($value) ? trim($value) : '';
        if ($path === '' || strlen($path) > 191
            || !preg_match('/^[A-Za-z0-9_.\/:\-]+$/D', $path)) {
            throw self::invalid('operationOrganizationPath', '组织路径无效。');
        }
        return $path;
    }

    private static function text($value, string $field, int $maxLength): string
    {
        $text = is_string($value) ? trim($value) : '';
        $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
        if ($text === '' || $length > $maxLength || strpos($text, "\0") !== false) {
            throw self::invalid($field, '客情文本字段无效。');
        }
        return $text;
    }

    private static function isAssociative(array $value): bool
    {
        return array_keys($value) !== ($value ? range(0, count($value) - 1) : []);
    }

    private static function invalid(string $field, string $message): CustomerCareProjectionException
    {
        return new CustomerCareProjectionException(
            CustomerCareProjectionErrorCode::INVALID_QUERY,
            $message,
            ['field' => $field]
        );
    }
}
