<?php
/** R37 本地配置读回与表单/枚举验证。仅查询配置，不改金额、订单或人员事实。 */
declare(strict_types=1);
chdir(getenv('MOHE_BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码');
require 'vendor/autoload.php';
(new think\App())->initialize();
$validate = new app\validate\admin\setting\SystemConfigValidate();
foreach ([0 => true, 1 => true, 2 => false, -1 => false, 'invalid' => false] as $input => $expected) {
    if ($validate->check(['cashier_labor_fee_mode' => $input]) !== $expected) throw new RuntimeException('枚举边界错误');
}
$form = app()->make(app\services\system\config\SystemConfigServices::class)->shopBaseFormBuild();
$find = function ($nodes) use (&$find) {
    if (!is_array($nodes)) return null;
    if (($nodes['field'] ?? null) === 'cashier_labor_fee_mode') return $nodes;
    foreach ($nodes as $node) { $found = $find($node); if ($found !== null) return $found; }
    return null;
};
$radio = $find($form);
if (!$radio || count($radio['options'] ?? []) !== 2) throw new RuntimeException('缺少两个选项');
$row = think\facade\Db::name('system_config')->where('menu_name', 'cashier_labor_fee_mode')->field('id,value')->find();
if (!$row || (int)$radio['value'] !== (int)$row['value'] || (int)sys_config('cashier_labor_fee_mode', 0) !== (int)$row['value']) {
    throw new RuntimeException('表单、数据库、有效配置不一致');
}
echo json_encode(['result' => 'PASS', 'config' => $row, 'formValue' => $radio['value'], 'options' => $radio['options'], 'enumValidation' => 'PASS'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
