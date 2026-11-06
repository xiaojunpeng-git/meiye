<?php
namespace app\controller\admin\v1\system;
/** 商家端数据规则沿用平台权限规则管理资格；不向门店会话开放配置写入。 */
final class MerchantPermissions extends StorePermissions
{
    protected function catalog() { return new \app\services\mobile\warehouse\MerchantDataPermissionServices(); }
    /** 新目录接受自身独立管理授权，也保留原权限规则管理员的维护能力。 */
    protected function managementAuthCodes(): array { return ['setting-system-menus','setting-store-permissions','setting-merchant-permissions']; }
}
