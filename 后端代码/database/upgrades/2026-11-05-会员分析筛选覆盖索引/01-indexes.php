<?php
declare(strict_types=1);
/** 可重跑、仅增删本轮三个读取索引，不修改会员、订单、权益或收款事实。
 * 必须由已获授权的发布步骤显式传入目标数据库；不自动遍历实例。
 */
$root=dirname(__DIR__,3);require $root.'/vendor/autoload.php';
$app=new think\App($root.'/');$app->env->load($root.'/.env');$app->initialize();
use think\facade\Db;
$args=getopt('',['database:','apply','rollback']);
$actual=(string)Db::query('SELECT DATABASE() db')[0]['db'];
if (($args['database']??'')!==$actual || (isset($args['apply'])===isset($args['rollback']))) throw new RuntimeException('必须指定匹配的 --database 及 --apply 或 --rollback');
$definitions=[
 'store_order'=>['idx_member_analysis_card_order',['id','paid','is_del','is_system_del','refund_status','terminal_action','card_upgrade_use_oid','store_id']],
 'user_card_holder'=>['idx_member_analysis_holder',['is_del','write_surplus_times','oid','uid']],
 'store_order_cart_info'=>['idx_member_analysis_project',['oid','cart_type','product_type','is_writeoff','write_surplus_times','write_times','pay_price']],
];
$prefix=(string)Db::connect()->getConfig('prefix');if(!preg_match('/^[A-Za-z0-9_]*$/D',$prefix))throw new RuntimeException('数据表前缀不合法');
foreach($definitions as $table=>[$index,$columns]){
 $physical=$prefix.$table;$existing=[];
 foreach(Db::query('SHOW INDEX FROM `'.$physical.'`') as $row)if($row['Key_name']===$index)$existing[]=$row['Column_name'];
 if($existing && $existing!==$columns)throw new RuntimeException($index.' 已存在但定义不同，停止迁移');
 $changed=false;
 if(isset($args['apply']) && !$existing){Db::execute('ALTER TABLE `'.$physical.'` ADD INDEX `'.$index.'` (`'.implode('`,`',$columns).'`), ALGORITHM=INPLACE, LOCK=NONE');$changed=true;}
 if(isset($args['rollback']) && $existing){Db::execute('ALTER TABLE `'.$physical.'` DROP INDEX `'.$index.'`, ALGORITHM=INPLACE, LOCK=NONE');$changed=true;}
 echo json_encode(['database'=>$actual,'index'=>$index,'mode'=>isset($args['apply'])?'apply':'rollback','changed'=>$changed]),"\n";
}
