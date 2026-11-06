<?php
declare(strict_types=1);

use think\facade\Db;

/**
 * Run from the authorized instance's bootstrapped CLI. Add only native config
 * and an immutable fulfillment boolean; never recalculate historical orders.
 * MySQL 5.7-compatible and rerunnable without resetting an operator's switch.
 */
return static function (): array {
    $prefix = (string)config('database.connections.mysql.prefix', 'eb_');
    if (!preg_match('/^[a-zA-Z0-9_]+$/D', $prefix)) throw new RuntimeException('Invalid database prefix');
    $added = [];
    foreach (['cashier_v3_checkout_request', 'cashier_v3_sales_order'] as $table) {
        $name = $prefix . $table;
        if (!Db::query("SHOW COLUMNS FROM `" . $name . "` LIKE 'project_auto_complete'")) {
            Db::execute('ALTER TABLE `' . $name . '` ADD COLUMN `project_auto_complete` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT \'本次购买项目自动完成服务，历史默认保持原流程\'');
            $added[] = $table;
        }
    }
    $key = 'cashier_project_auto_complete_switch';
    if (!Db::name('system_config')->where('menu_name', $key)->where('is_store', 0)->count()) {
        $anchor = Db::name('system_config')->where('menu_name', 'reservation_service_voice_mode')->where('is_store', 0)->find()
            ?: Db::name('system_config')->where('menu_name', 'station_open')->where('is_store', 0)->find();
        if (!$anchor) throw new RuntimeException('Native store configuration group missing');
        Db::name('system_config')->insert([
            'is_store' => 0, 'menu_name' => $key, 'type' => 'switch', 'input_type' => '',
            'config_tab_id' => (int)$anchor['config_tab_id'], 'parameter' => '', 'upload_type' => 0,
            'required' => '', 'width' => 0, 'high' => 0, 'value' => '1',
            'info' => '门店端购买项目自动核销',
            'desc' => '开启保持购买项目即核销；关闭后须选择会员，购买项目生成待用权益，实际使用时再核销',
            'sort' => (int)$anchor['sort'] + 1, 'status' => 1,
        ]);
        $added[] = $key;
    }
    return $added;
};
