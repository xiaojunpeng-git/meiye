<?php

namespace app\services\query;

/**
 * 宿主领域基于服务端可信身份解析某一页面的功能与字段权限。
 */
interface UnifiedQueryPagePermissionResolver
{
    public function pageCode(): string;

    /**
     * @return array{pageAllowed:bool,exportAllowed:bool,permissions:array}
     */
    public function authorize(array $trustedContext, array $pageDefinition): array;
}
