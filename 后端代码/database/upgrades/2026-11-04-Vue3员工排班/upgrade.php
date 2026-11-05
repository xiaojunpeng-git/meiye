<?php
declare(strict_types=1);
/** 幂等本地/逐实例升级：保留旧排班；只增加时间快照、事务命令审计，不回填业务事实。 */
return static function (): void {
    $db = \think\facade\Db::connect();
    $prefix = (string)config('database.connections.mysql.prefix', 'eb_');
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $prefix)) throw new \RuntimeException('表前缀无效');
    $columns = array_column($db->query('SHOW COLUMNS FROM `' . $prefix . 'store_staff_schedule`'), 'Field');
    foreach (['shift_name_snapshot' => "varchar(64) NOT NULL DEFAULT ''", 'breaks_snapshot' => 'text NULL', 'roster_managed' => 'tinyint unsigned NOT NULL DEFAULT 0'] as $column => $definition) {
        if (!in_array($column, $columns, true)) $db->execute('ALTER TABLE `' . $prefix . 'store_staff_schedule` ADD COLUMN `' . $column . '` ' . $definition);
    }
    $indexes = array_column($db->query('SHOW INDEX FROM `' . $prefix . 'store_staff_schedule`'), 'Key_name');
    if (!in_array('idx_roster_staff_date', $indexes, true)) $db->execute('ALTER TABLE `' . $prefix . 'store_staff_schedule` ADD INDEX idx_roster_staff_date(staff_id,schedule_date,store_id)');
    $reservationIndexes = array_column($db->query('SHOW INDEX FROM `' . $prefix . 'cashier_v3_reservation`'), 'Key_name');
    if (!in_array('idx_roster_reservation_range', $reservationIndexes, true)) $db->execute('ALTER TABLE `' . $prefix . 'cashier_v3_reservation` ADD INDEX idx_roster_reservation_range(tenant_id,lifecycle_generation,status,appointment_end_at,appointment_start_at)');
    $db->execute('CREATE TABLE IF NOT EXISTS `' . $prefix . 'staff_roster_command` (
        id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
        store_id int unsigned NOT NULL, actor_type varchar(16) NOT NULL, actor_id int unsigned NOT NULL,
        request_token varchar(64) NOT NULL, request_hash char(64) NOT NULL,
        action varchar(32) NOT NULL, before_json mediumtext NOT NULL, after_json mediumtext NOT NULL,
        result_json mediumtext NOT NULL, created_at int unsigned NOT NULL,
        UNIQUE KEY uq_request (actor_type,actor_id,store_id,request_token), KEY ix_store_time(store_id,created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT="员工排班变更审计及幂等回执"');
    $db->execute('CREATE TABLE IF NOT EXISTS `' . $prefix . 'staff_roster_setting_revision` (id tinyint unsigned NOT NULL PRIMARY KEY, version bigint unsigned NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    if (!\think\facade\Db::name('staff_roster_setting_revision')->where('id',1)->find()) \think\facade\Db::name('staff_roster_setting_revision')->insert(['id'=>1,'version'=>1]);
    // 保留已有参数取值，只更新名称说明；新实例缺省关闭，不自动启用业务。
    $config = \think\facade\Db::name('system_config')->where('menu_name','reservation_schedule_manage')->where('is_store',0)->find();
    if ($config) \think\facade\Db::name('system_config')->where('id',$config['id'])->update(['info'=>'启用排班','desc'=>'关闭沿用原预约规则；开启后按员工上班和休息预约，未排班仍沿用原规则']);
    else {
        $template = \think\facade\Db::name('system_config')->where('menu_name','station_open')->where('is_store',0)->find();
        if (!$template) throw new \RuntimeException('系统配置尚未初始化');
        unset($template['id']);
        \think\facade\Db::name('system_config')->insert(array_replace($template,['menu_name'=>'reservation_schedule_manage','value'=>'0','info'=>'启用排班','desc'=>'关闭沿用原预约规则；开启后按员工上班和休息预约，未排班仍沿用原规则','type'=>'switch']));
    }
    // 只补本次一个导航节点；不改变岗位授权，也不调用会补入其他在途节点的全目录安装器。
    \think\facade\Db::transaction(static function (): void {
        $catalog = \think\facade\Db::name('store_v3_permission_catalog')->where('id', 1)->lock(true)->find();
        if (!$catalog) throw new \RuntimeException('请先初始化门店端权限目录');
        $rows = json_decode((string)$catalog['rules_json'], true);
        if (!in_array(-34001, array_column($rows, 'id'), true)) {
            $rows[] = \app\services\organization\StorePermissionCatalogServices::navigationItems()[-34001];
            $rows = (new \app\services\organization\StorePermissionCatalogServices())->validateRows($rows);
            $json = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            \think\facade\Db::name('store_v3_permission_catalog')->where('id', 1)->update(['rules_json' => $json, 'revision' => (int)$catalog['revision'] + 1, 'updated_at' => time()]);
            \think\facade\Db::name('store_v3_permission_audit')->insert(['operator_id' => 0, 'action' => 'ADD_STAFF_ROSTER_NAV', 'before_json' => $catalog['rules_json'], 'after_json' => $json, 'created_at' => time()]);
        }
    });
    // 挂在既有人员职位入口下，不批量扩大角色授权；已有人员管理资格可见。
    $parent = \think\facade\Db::name('system_menus')->where('unique_auth', 'admin-staff')->where('is_del', 0)->find();
    if ($parent && !\think\facade\Db::name('system_menus')->where('unique_auth', 'admin-staff-roster')->where('is_del', 0)->find()) {
        \think\facade\Db::name('system_menus')->insert([
            'pid' => $parent['id'], 'type' => 1, 'icon' => '', 'menu_name' => '员工排班', 'module' => 'admin',
            'controller' => '', 'action' => '', 'api_url' => '', 'methods' => '', 'params' => '[]', 'sort' => 0,
            'is_show' => 1, 'is_show_path' => 0, 'access' => 1, 'menu_path' => '/admin/store/staff-roster',
            'path' => ($parent['path'] ?? '') . '/' . $parent['id'], 'auth_type' => 1, 'header' => 'staff',
            'is_header' => 0, 'unique_auth' => 'admin-staff-roster', 'is_del' => 0,
        ]);
    }
};
