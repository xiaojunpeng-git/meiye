<?php
/** Local metadata regression: preserve unrelated grants and never re-grant a cancelled button on rerun. */
$backend = getenv('R4_BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require $backend . '/vendor/autoload.php';
$app = new \think\App($backend . '/'); $app->env->load($backend . '/.env'); $app->initialize();
use think\facade\Db;
use app\services\organization\StorePermissionCatalogServices;
$c = Db::connect()->getConfig();
if (getenv('R4_LOCAL_ONLY') !== '1' || ($c['hostname'] ?? '') !== 'mysql' || ($c['database'] ?? '') !== 'ruihao') throw new RuntimeException('local database required');
$service = new StorePermissionCatalogServices();
$original = Db::name($service::TABLE)->where('id', 1)->find();
$before = Db::name('job_position_channel_rule')->order('id')->select()->toArray();
Db::startTrans();
try {
    // Force pre-upgrade state inside the rollback-only test, including after a local install.
    Db::name('store_v3_permission_audit')->where('action', 'R4_CARD_BUTTON_VISIBILITY')->delete();
    $rows = array_values(array_filter(json_decode($original['rules_json'], true), static function ($r) { return !in_array((int)$r['id'], [301052,301053], true); }));
    Db::name($service::TABLE)->where('id', 1)->update(['rules_json'=>json_encode($rows)]);
    $service->initializeCardButtonVisibilityPermissions();
    $installed = Db::name($service::TABLE)->where('id', 1)->find();
    $after = Db::name('job_position_channel_rule')->order('id')->select()->toArray();
    $cancelId = 0;
    foreach ($before as $i => $row) {
        $ids = array_map('intval', explode(',', $row['rules']));
        $eligible = $row['channel'] === 'store_v3' && in_array('cashier.v3.cashier', $service::explicitFeatures($ids), true);
        if (!$eligible && $after[$i] !== $row) throw new RuntimeException('unrelated grant changed');
        if ($eligible) {
            $next = array_map('intval', explode(',', $after[$i]['rules']));
            if (!in_array(301052,$next,true) || !in_array(301053,$next,true) || array_diff($ids,$next)) throw new RuntimeException('cashier visibility not preserved');
            $cancelId = (int)$row['id'];
        }
    }
    if (!$cancelId) throw new RuntimeException('no cashier sample');
    Db::name('job_position_channel_rule')->where('id',$cancelId)->update(['rules'=>'301001']);
    if ($service->initializeCardButtonVisibilityPermissions() !== 0 || Db::name('job_position_channel_rule')->where('id',$cancelId)->value('rules') !== '301001') throw new RuntimeException('rerun restored cancelled buttons');
    $service->snapshot();
    $preserved = array_values(array_filter(json_decode($installed['rules_json'],true),static function ($r) {return !in_array((int)$r['id'],[301052,301053],true);}));
    if ($preserved !== $rows) throw new RuntimeException('existing catalog changed');
} finally { Db::rollback(); }
if (Db::name($service::TABLE)->where('id',1)->find() !== $original || Db::name('job_position_channel_rule')->order('id')->select()->toArray() !== $before) throw new RuntimeException('test rollback failed');
echo "R4_CARD_BUTTON_VISIBILITY=PASS\n";
