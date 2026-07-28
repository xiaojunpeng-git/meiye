<?php
namespace app\services\cashier\v3;

/**
 * 统一、只读、服务端生成的数据权限上下文。
 *
 * 授权模式互斥，禁止用同一个空数组同时表达「无权限」和「仅本人参与」：
 * - ALL：超管全可见（visibleStoreIds=null）
 * - STORES：指定门店集合
 * - SELF_PARTICIPANT：无门店级扩展，仅本人参与数据；由 domain provider 判定参与关系
 * - NONE：无可见数据
 */
final class CashierV3DataScopeContext
{
    public const MODE_ALL = 'all';
    public const MODE_STORES = 'stores';
    public const MODE_SELF_PARTICIPANT = 'self_participant';
    public const MODE_NONE = 'none';

    private $operatorId;
    private $employeeId;
    private $forcedStoreId;
    private $tenantId;
    private $organizationId;
    /** @var int[]|null null=超管全可见 */
    private $visibleStoreIds;
    private $authorizationMode;
    private $employeeDataScope;
    private $isSuperAdmin;
    private $superAdminBasis;
    private $permissionVersion;
    /** @var string[] */
    private $grantedFeatures;
    private $operatorProfile;

    public function __construct(
        int $operatorId,
        int $employeeId,
        int $forcedStoreId,
        string $tenantId,
        string $organizationId,
        $visibleStoreIds,
        string $authorizationMode,
        array $employeeDataScope,
        bool $isSuperAdmin,
        string $superAdminBasis,
        string $permissionVersion,
        array $grantedFeatures,
        array $operatorProfile
    ) {
        $this->operatorId = $operatorId;
        $this->employeeId = $employeeId;
        $this->forcedStoreId = $forcedStoreId;
        $this->tenantId = $tenantId;
        $this->organizationId = $organizationId;
        $this->visibleStoreIds = $visibleStoreIds;
        $this->authorizationMode = $authorizationMode;
        $this->employeeDataScope = $employeeDataScope;
        $this->isSuperAdmin = $isSuperAdmin;
        $this->superAdminBasis = $superAdminBasis;
        $this->permissionVersion = $permissionVersion;
        $this->grantedFeatures = array_values(array_unique(array_map('strval', $grantedFeatures)));
        $this->operatorProfile = $operatorProfile;
    }

    public function operatorId(): int { return $this->operatorId; }
    public function employeeId(): int { return $this->employeeId; }
    public function forcedStoreId(): int { return $this->forcedStoreId; }
    public function tenantId(): string { return $this->tenantId; }
    public function organizationId(): string { return $this->organizationId; }
    /** @return int[]|null */
    public function visibleStoreIds() { return $this->visibleStoreIds; }
    public function authorizationMode(): string { return $this->authorizationMode; }
    public function isSelfParticipantMode(): bool
    {
        return $this->authorizationMode === self::MODE_SELF_PARTICIPANT;
    }
    public function employeeDataScope(): array { return $this->employeeDataScope; }
    public function isSuperAdmin(): bool { return $this->isSuperAdmin; }
    public function superAdminBasis(): string { return $this->superAdminBasis; }
    public function permissionVersion(): string { return $this->permissionVersion; }
    /** @return string[] */
    public function grantedFeatures(): array { return $this->grantedFeatures; }
    public function operatorProfile(): array { return $this->operatorProfile; }

    public function hasFeature(string $featureCode): bool
    {
        return in_array($featureCode, $this->grantedFeatures, true);
    }

    /**
     * @param int[]|null $clientStoreIds
     * @return int[]|null
     */
    public function narrowVisibleStores($clientStoreIds)
    {
        if ($this->authorizationMode === self::MODE_ALL) {
            if ($clientStoreIds === null) {
                return null;
            }
            return array_values(array_unique(array_map('intval', $clientStoreIds)));
        }
        if ($this->authorizationMode === self::MODE_SELF_PARTICIPANT
            || $this->authorizationMode === self::MODE_NONE) {
            return [];
        }
        if ($clientStoreIds === null) {
            return $this->visibleStoreIds ?: [];
        }
        $client = array_map('intval', $clientStoreIds);
        return array_values(array_intersect($this->visibleStoreIds ?: [], $client));
    }

    /**
     * 门店集合授权判定。SELF_PARTICIPANT 不走门店集合（由 provider 判定参与关系）。
     */
    public function allowsStore(int $storeId): bool
    {
        if ($storeId <= 0) {
            return false;
        }
        if ($this->authorizationMode === self::MODE_ALL) {
            return true;
        }
        if ($this->authorizationMode === self::MODE_SELF_PARTICIPANT) {
            // 本人参与不由门店集合表达；调用方应走 provider 参与判定
            return false;
        }
        if ($this->authorizationMode === self::MODE_NONE) {
            return false;
        }
        return in_array($storeId, $this->visibleStoreIds ?: [], true);
    }

    /**
     * ScopeResolver：门店类对象是否还需要二次门店集合校验。
     * SELF_PARTICIPANT 在 provider 已完成参与判定后跳过。
     */
    public function requiresStoreSetGate(): bool
    {
        return $this->authorizationMode === self::MODE_STORES
            || $this->authorizationMode === self::MODE_NONE
            || $this->authorizationMode === self::MODE_ALL;
    }
}
