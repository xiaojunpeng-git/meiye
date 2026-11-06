<?php
declare(strict_types=1);
use think\facade\Db;

/**
 * 人效入口落入原生菜单配置。只为缺失入口的实例补一次菜单和等价导航授权；
 * 已存在、隐藏或软删除的配置保持管理员选择，不用前端补回。执行须在目标实例授权后进行。
 */
return static function (): array {
    return Db::transaction(static function (): array {
        $existing = Db::name('system_menus')->where('menu_path', '/admin/report/human-efficiency')->lock(true)->select()->toArray();
        if ($existing) return ['created'=>false, 'menu_ids'=>array_column($existing,'id'), 'roles'=>[]];
        $anchors = Db::name('system_menus')->where('menu_path','/admin/report/employee-dashboard')->where('is_del',0)->lock(true)->select()->toArray();
        if (count($anchors)!==1) throw new RuntimeException('请先配置唯一的员工看板菜单，再配置人效看板');
        $anchor=$anchors[0];
        // 权限标识沿用原入口：仅增加可独立显隐、排序、授权的导航，不扩大报表数据范围。
        $row=$anchor;unset($row['id']);
        $row['menu_name']='人效看板';$row['menu_path']='/admin/report/human-efficiency';
        $row['sort']=(int)$anchor['sort']-1;
        $id=(int)Db::name('system_menus')->insertGetId($row);$changed=[];
        foreach(Db::name('system_role')->lock(true)->select()->toArray() as $role){
            $ids=array_values(array_filter(explode(',',(string)$role['rules']),static fn($v)=>ctype_digit($v)));
            // 仅继承确实拥有原菜单ID的角色；管理员既有超级权限由系统原生处理。
            if(!in_array((string)$anchor['id'],$ids,true))continue;
            $ids[]=(string)$id;
            Db::name('system_role')->where('id',$role['id'])->update(['rules'=>implode(',',array_unique($ids))]);
            $changed[]=(int)$role['id'];
        }
        return ['created'=>true,'menu_ids'=>[$id],'roles'=>$changed];
    });
};
