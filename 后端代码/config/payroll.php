<?php
/** 职位模型与工资启用日期均为实例配置，不按客户名称、域名或数据库分支。 */
return [
    'position_model' => env('payroll.position_model', 'legacy'),
    // 空值保留既有实时工资；指定月首后，之前月份仅查询已保存工资。
    'realtime_start_date' => env('payroll.realtime_start_date', ''),
];
