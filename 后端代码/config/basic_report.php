<?php

use think\facade\Env;

return [
    // 仅启用的实例把平台基础报表菜单授权解释为全部门店只读范围；其他实例保留既有数据范围。
    'platform_menu_all_stores' => Env::get('basic_report.platform_menu_all_stores', false),
];
