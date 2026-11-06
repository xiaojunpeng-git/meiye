<?php
declare(strict_types=1);

use think\facade\Db;

/** 任务多图仅保存附件URL；可重跑，历史任务保留NULL，不回填业务内容。 */
return static function (): array {
    $prefix = (string)config('database.connections.mysql.prefix', 'eb_');
    if (!preg_match('/^[a-zA-Z0-9_]+$/D', $prefix)) throw new RuntimeException('Invalid database prefix');
    $table = $prefix . 'customer_care_task';
    if (Db::query("SHOW COLUMNS FROM `{$table}` LIKE 'task_photos'")) return [];
    Db::execute("ALTER TABLE `{$table}` ADD COLUMN `task_photos` TEXT NULL COMMENT '任务备注图片URL数组，历史任务为空'");
    return ['customer_care_task.task_photos'];
};
