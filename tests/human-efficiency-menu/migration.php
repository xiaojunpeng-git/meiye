<?php
/** 本地事务回归：真实原生菜单和角色表，结束回滚；不触碰业务数据。 */
require '/var/www/html/vendor/autoload.php';(new think\App('/var/www/html/'))->initialize();
use think\facade\Db;
if(config('database.connections.mysql.hostname')!=='mysql'||config('database.connections.mysql.database')!=='ruihao')throw new RuntimeException('Local only');
function check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
$apply=require '/var/www/html/database/upgrades/20261106_human_efficiency_native_menu.php';
Db::startTrans();
try{
 // 仅在回滚事务中清除本入口，以覆盖首次安装及已安装实例两种路径。
 Db::name('system_menus')->where('menu_path','/admin/report/human-efficiency')->delete();
 $before=Db::name('system_role')->column('rules','id');$first=$apply();$second=$apply();
 check($first['created']&&!$second['created']&&$first['menu_ids']===$second['menu_ids'],'重复执行不重复创建');
 $id=$first['menu_ids'][0];$row=Db::name('system_menus')->where('id',$id)->find();
 $anchor=Db::name('system_menus')->where('menu_path','/admin/report/employee-dashboard')->where('is_del',0)->find();
 check($row['pid']===$anchor['pid']&&$row['sort']===$anchor['sort']-1,'同父级且紧随员工看板排序');
 check($row['unique_auth']===$anchor['unique_auth'],'保留原权限标识');
 foreach(Db::name('system_role')->select()->toArray() as $role){$old=explode(',',$before[$role['id']]);$now=explode(',',$role['rules']);if(array_values(array_diff($now,$old))!==(in_array((string)$anchor['id'],$old,true)?[(string)$id]:[]))throw new RuntimeException('角色授权异常 '.$role['id']);}
 check(true,'全部角色仅继承原入口导航授权');
 Db::name('system_menus')->where('id',$id)->update(['is_show'=>0,'sort'=>99]);$apply();
 check((int)Db::name('system_menus')->where('id',$id)->value('is_show')===0&&(int)Db::name('system_menus')->where('id',$id)->value('sort')===99,'重复迁移不覆盖隐藏与排序配置');
 Db::name('system_menus')->where('id',$id)->update(['is_del'=>1]);$apply();
 check((int)Db::name('system_menus')->where('id',$id)->value('is_del')===1,'已删除入口不被自动复活');
}finally{Db::rollback();}
