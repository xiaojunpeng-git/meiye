<?php
declare(strict_types=1);
use think\facade\Db;

/** 显式CLI元数据安装。函数局部变量不得污染调用方测试库标识；从运行中连接核对目标后才写入。 */
function installMemberSurveyMetadata(string $root, string $expectedDatabase, bool $preflight=false):array
{
    if(PHP_SAPI!=='cli'||getenv('R12_SURVEY_INSTALL')!=='1')throw new RuntimeException('explicit survey installation flag required');
    $database=(string)Db::query('SELECT DATABASE() db')[0]['db'];
    if ($database==='' || $database !== $expectedDatabase) throw new RuntimeException('survey target database mismatch');
    $exists=static function(string $table):bool { return count(Db::query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', ['eb_'.$table]))>0; };
    foreach (['cashier_v3_direct_gift_authority','cashier_v3_direct_gift_item','cashier_v3_gift_fact','cashier_v3_business_event','cashier_v3_outbox','organization_store'] as $table) {
        if (!$exists($table)) throw new RuntimeException('010 gift dependency missing: '.$table);
    }
    if (!Db::name('system_menus')->where('unique_auth','admin-user')->where('type',1)->where('is_del',0)->count()) throw new RuntimeException('platform member menu parent missing');
    $legacyTask=false;
    if ($exists('member_survey_task')) {
        $fields=array_column(Db::query('SHOW COLUMNS FROM eb_member_survey_task'),'Field');
        $legacyTask=!in_array('entry_token',$fields,true);
        foreach (['questionnaire_id','send_round','sent_at','questions','filters'] as $field) if (!in_array($field,$fields,true)) throw new RuntimeException('legacy survey schema requires explicit review: '.$field);
        // 已导入的旧任务不能无声恢复提醒或把007奖励当作010奖励；本轮只安装新功能。
        if ($legacyTask && Db::name('member_survey_task')->count()>0) throw new RuntimeException('existing legacy survey tasks require separately approved conversion');
    }
    if ($preflight) {
        return ['passed'=>true,'database'=>$database,'read_only'=>true,'empty_legacy_task_table'=>$legacyTask];
    }
    $pdo=Db::connect()->getPdo();
    $pdo->exec(file_get_contents($root.'/database/upgrades/2026-10-29-会员调查/01-schema.sql'));
    if ($legacyTask) $pdo->exec('ALTER TABLE eb_member_survey_task ADD COLUMN entry_token varchar(64) NULL, ADD UNIQUE KEY uq_survey_entry_token(entry_token)');
    $pdo->exec(file_get_contents($root.'/database/upgrades/2026-10-29-会员调查/02-menu.sql'));
    return ['passed'=>true,'database'=>$database,'business_rows_imported'=>0,'existing_roles_changed'=>0,'instance_menu_cache_refresh_required'=>true];
}

// 被测试导入时仅定义安装函数，不重启应用或替换当前数据库连接。
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    if(PHP_SAPI!=='cli'||getenv('R12_SURVEY_INSTALL')!=='1')throw new RuntimeException('explicit survey installation flag required');
    $root=getenv('BACKEND_ROOT')?:dirname(__DIR__,2).'/后端代码';
    require $root.'/vendor/autoload.php';
    $app=new think\App($root.'/');
    if($env=getenv('LOCAL_TEST_ENV'))$app->env->load($env);
    $app->initialize();
    echo json_encode(installMemberSurveyMetadata($root,(string)getenv('R12_EXPECTED_DATABASE'),getenv('R12_SURVEY_PREFLIGHT')==='1'),JSON_UNESCAPED_UNICODE).PHP_EOL;
}
