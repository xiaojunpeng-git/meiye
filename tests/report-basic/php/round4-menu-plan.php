<?php
require dirname(__DIR__,3).'/后端代码/database/upgrades/2026-10-30-侧栏四表菜单接入/plan.php';
$before=[['id'=>1,'unique_auth'=>'admin-report','pid'=>0,'menu_path'=>'/report','is_del'=>0]];
$p=roundFourSidebarMenuPlan($before);
if(count($p['changes'])!==4||$p['role_rule_changes']!==[])throw new RuntimeException('菜单范围或角色边界不正确');
foreach($p['changes'] as $i=>$row)$before[]=['id'=>10+$i,'unique_auth'=>$row['definition']['auth'],'pid'=>1,'menu_path'=>$row['definition']['path'],'is_del'=>0];
$repeat=roundFourSidebarMenuPlan($before);
if(array_column($repeat['changes'],'action')!==array_fill(0,4,'keep'))throw new RuntimeException('重复执行不幂等');
$before[1]['menu_path']='/wrong';
try{roundFourSidebarMenuPlan($before);throw new RuntimeException('未拒绝冲突');}catch(LogicException $expected){}
echo "round4-menu-plan PASS\n";
