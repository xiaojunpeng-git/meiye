<?php
declare(strict_types=1);

use app\services\cashier\v3\CashierV3ServicePositionExclusion;
use think\facade\Db;

/** 安装原生配置项；空名单保留原统计行为，重复执行不覆盖已保存名单。 */
return static function (): array {
    $key = CashierV3ServicePositionExclusion::CONFIG_KEY;
    if (Db::name('system_config')->where('menu_name', $key)->where('is_store', 0)->count()) return [];
    $anchor = Db::name('system_config')->where('menu_name', 'cashier_craftsman_exclude_counts_switch')->where('is_store', 0)->find()
        ?: Db::name('system_config')->where('menu_name', 'station_open')->where('is_store', 0)->find();
    if (!$anchor) throw new RuntimeException('Native store configuration group missing');
    Db::name('system_config')->insert([
        'is_store' => 0, 'menu_name' => $key, 'type' => 'select', 'input_type' => '',
        'config_tab_id' => (int)$anchor['config_tab_id'], 'parameter' => '', 'upload_type' => 0,
        'required' => '', 'width' => 0, 'high' => 0, 'value' => '[]',
        'info' => '门店不算服务人数、服务人次、手工费',
        'desc' => '所选岗位只对保存后完成的服务生效。',
        'sort' => (int)$anchor['sort'] + 1, 'status' => 1,
    ]);
    return [$key];
};
