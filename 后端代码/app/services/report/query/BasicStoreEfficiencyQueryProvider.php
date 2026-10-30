<?php
namespace app\services\report\query;

/** 单表身份绑定；统一执行器和后端逐表权限负责查询及导出范围。 */
final class BasicStoreEfficiencyQueryProvider extends BasicBusinessQueryProvider
{
    protected const CODE = 'basic_store_efficiency';
}
