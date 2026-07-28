<?php
// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------

namespace app\services\cashier\v3;

/**
 * 后端强制操作范围：当前登录账号 + 后端强制门店 + 该门店所属组织 + 租户。
 *
 * 只能由会话与组织主数据构造，客户端不得提交或覆盖任何一项。
 * 业务主表提供器必须接收本对象，用它判定目标对象是否在可操作范围内。
 */
final class CashierV3OperatorScope
{
    /** @var int 后端强制门店 */
    private $storeId;

    /** @var int 当前收银员／操作人 */
    private $operatorId;

    /** @var string 该门店所属组织；未接入组织时为空串 */
    private $organizationId;

    /** @var string 租户；一库一租户时固定为 CashierV3ScopeResolver::TENANT_SCOPE_ID */
    private $tenantId;

    public function __construct(int $storeId, int $operatorId, string $organizationId, string $tenantId)
    {
        if ($storeId <= 0 || $operatorId <= 0) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::CLIENT_SESSION_REQUIRED,
                '当前登录门店或账号无效，请重新登录后重试。'
            );
        }
        $this->storeId = $storeId;
        $this->operatorId = $operatorId;
        $this->organizationId = trim($organizationId);
        $this->tenantId = trim($tenantId);
    }

    public function storeId(): int
    {
        return $this->storeId;
    }

    public function operatorId(): int
    {
        return $this->operatorId;
    }

    public function organizationId(): string
    {
        return $this->organizationId;
    }

    public function tenantId(): string
    {
        return $this->tenantId;
    }

    /**
     * 门店资源的权威作用域。业务主表提供器判定「该对象真实归属门店」时，
     * 应当用真实归属门店构造 scope，而不是直接用本方法。
     */
    public function storeScope(): CashierV3ResourceScope
    {
        return CashierV3ResourceScope::of(CashierV3ResourceScope::TYPE_STORE, (string)$this->storeId);
    }
}
