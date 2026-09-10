<?php
require __DIR__.'/fixture-autoload.php';
use app\services\ai\management\AiManagementMenuPolicy as Policy;
$count=0;function menuCheck($ok){global $count;if(!$ok)throw new RuntimeException('menu policy assertion');$count++;}
$tree=[['id'=>9,'pid'=>0,'path'=>'/setting','title'=>'设置','header'=>'setting','children'=>[['id'=>10,'pid'=>9,'path'=>'/setting/shop/base','title'=>'基础设置']]],['id'=>1,'pid'=>0,'path'=>'/report','title'=>'数据']];
$admin=['account'=>'admin','admin_type'=>0];
[$menus,$unique]=Policy::formatted($tree,['existing'],$admin);
menuCheck(count($menus[0]['children'])===3);menuCheck($menus[0]['children'][1]['path']==='/setting/mohe-ai');menuCheck($menus[0]['children'][2]['path']==='/setting/mohe-ai/metrics');menuCheck($unique===['existing','setting-mohe-ai']);
menuCheck($menus[1]===$tree[1]);menuCheck($menus[0]['children'][0]===$tree[0]['children'][0]);
[$again,$againUnique]=Policy::formatted($menus,$unique,$admin);menuCheck($again===$menus&&$againUnique===$unique);
foreach([['account'=>'operator','level'=>0,'real_name'=>'admin'],['account'=>'Admin'],['account'=>'admin '],['account'=>'admin','admin_type'=>3],[]] as $other) {
    [$filtered,$u]=Policy::formatted($menus,$unique,$other);menuCheck($filtered===$tree&&$u===['existing']);
}
$legacy=$tree;$legacy[1]['children']=[['id'=>99,'path'=>'/admin/setting/mohe-ai/'],['id'=>98,'unique_auth'=>'setting-mohe-ai'],['id'=>97,'auth'=>['setting-mohe-ai']]];
[$clean]=Policy::formatted($legacy,['setting-mohe-ai'],['account'=>'other']);menuCheck($clean[1]['children']===[]);
$raw=[['id'=>9,'pid'=>0,'menu_path'=>'/setting','menu_name'=>'设置','sort'=>9,'type'=>1],['id'=>10,'pid'=>9,'menu_path'=>'/setting/shop/base','menu_name'=>'基础设置','type'=>0]];
$result=Policy::raw($raw,$admin);menuCheck(count($result)===4&&$result[2]['pid']===9&&$result[2]['type']===0&&$result[3]['pid']===9&&$result[3]['menu_path']==='/setting/mohe-ai/metrics');
menuCheck(Policy::raw($result,$admin)===$result);menuCheck(Policy::raw($result,['account'=>'other'])===$raw);
menuCheck(Policy::raw($raw,$admin)===$result); // shared input is not mutated by another account's projection
[$missing,$u]=Policy::formatted([],['existing'],$admin);menuCheck($missing===[]&&$u===['existing']);
$base=dirname(__DIR__,2).'/后端代码/';
$login=file_get_contents($base.'app/services/system/admin/SystemAdminServices.php');menuCheck(substr_count($login,'AiManagementMenuPolicy::formatted')===2);
$refresh=file_get_contents($base.'app/controller/admin/v1/system/SystemMenus.php');menuCheck(strpos($refresh,'AiManagementMenuPolicy::formatted')!==false);
$common=file_get_contents($base.'app/controller/admin/Common.php');menuCheck(strpos($common,'AiManagementMenuPolicy::raw')>strpos($common,'return sort_list_tier($data)'));
echo "R6 account-owned menu policy: $count checks PASS (pure projections; no DB)\n";
