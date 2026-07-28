<?php
namespace app\services\cashier\v3;

/**
 * 资源版本的权威作用域。
 *
 * 作用域类型：
 * - tenant：集团内共享（会员、余额、卡权益）
 * - organization：组织范围
 * - store：门店范围
 * - account：登录账号范围（查询方案等，不随办理门店变化）
 */
final class CashierV3ResourceScope
{
    public const TYPE_TENANT = 'tenant';
    public const TYPE_ORGANIZATION = 'organization';
    public const TYPE_STORE = 'store';
    public const TYPE_ACCOUNT = 'account';

    private const TYPES = [self::TYPE_TENANT, self::TYPE_ORGANIZATION, self::TYPE_STORE, self::TYPE_ACCOUNT];

    private $type;
    private $id;

    private function __construct(string $type, string $id)
    {
        $this->type = $type;
        $this->id = $id;
    }

    public static function of(string $type, string $id): self
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::RESOURCE_SCOPE_UNRESOLVED,
                '系统未能确定该对象的归属范围，请刷新当前工作台后重试。',
                CashierV3ResultCode::STATUS_FAILED,
                ['scope_type' => $type]
            );
        }
        $id = trim($id);
        if ($id === '' || strlen($id) > 32 || !preg_match('/^[A-Za-z0-9_.:-]+$/', $id)) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::RESOURCE_SCOPE_UNRESOLVED,
                '系统未能确定该对象的归属范围，请刷新当前工作台后重试。',
                CashierV3ResultCode::STATUS_FAILED,
                ['scope_type' => $type, 'scope_id' => $id]
            );
        }
        return new self($type, $id);
    }

    public function type(): string { return $this->type; }
    public function id(): string { return $this->id; }

    public function equals(CashierV3ResourceScope $other): bool
    {
        return $this->type === $other->type && $this->id === $other->id;
    }

    public function signature(): string
    {
        return $this->type . '/' . $this->id;
    }

    public static function allTypes(): array
    {
        return self::TYPES;
    }
}
