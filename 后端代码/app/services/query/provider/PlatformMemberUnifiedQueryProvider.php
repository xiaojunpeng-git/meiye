<?php
namespace app\services\query\provider;

/** 共用会员事实读取/字段/导出；独立页面码隔离同数字的平台与门店账号查询偏好。 */
class PlatformMemberUnifiedQueryProvider extends MemberUnifiedQueryProvider
{
    public const PAGE_CODE = 'platform_member_list';
}
