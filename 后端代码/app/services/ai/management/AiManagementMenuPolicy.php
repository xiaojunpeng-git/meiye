<?php
namespace app\services\ai\management;

/** Per-authenticated-account menu projection; never write it into shared role caches. */
final class AiManagementMenuPolicy
{
    const AUTH='setting-mohe-ai';
    const PATH='/setting/mohe-ai';
    const ID='mohe-ai-management';
    public static function formatted(array $menus,array $unique,array $admin): array
    {
        $menus=self::clean($menus);$unique=array_values(array_filter($unique,static function($v){return $v!==self::AUTH;}));
        if(!self::allowed($admin)) return [$menus,$unique];
        $found=false;
        foreach($menus as &$root) {
            if(self::settings($root)) {
                $root['children'][]=['id'=>self::ID,'pid'=>$root['id'],'path'=>self::PATH,'title'=>'魔核 AI','icon'=>'ios-settings-outline',
                    'header'=>$root['header']??'setting','is_header'=>0,'unique_auth'=>self::AUTH,'auth'=>[self::AUTH]];
                $found=true;break;
            }
        }unset($root);
        if($found)$unique[]=self::AUTH;
        return [$menus,array_values(array_unique($unique))];
    }
    public static function raw(array $menus,array $admin): array
    {
        $menus=self::clean($menus);if(!self::allowed($admin))return $menus;
        foreach($menus as $root)if(self::settings($root)) {
            $menus[]=['id'=>self::ID,'pid'=>$root['id'],'menu_name'=>'魔核 AI','menu_path'=>self::PATH,'unique_auth'=>self::AUTH,'sort'=>0,'type'=>0];break;
        }
        return $menus;
    }
    private static function allowed(array $admin): bool {return ($admin['account']??null)==='admin'&&(int)($admin['admin_type']??0)!==3;}
    private static function settings(array $node): bool
    {
        $path=rtrim((string)($node['path']??$node['menu_path']??''),'/');
        return (string)($node['pid']??'0')==='0' && (in_array($path,['/setting','/admin/setting'],true)
            || (($node['title']??$node['menu_name']??null)==='设置'));
    }
    private static function clean(array $nodes): array
    {
        $result=[];
        foreach($nodes as $node) {
            if(!is_array($node))continue;
            $path=rtrim((string)($node['path']??$node['menu_path']??''),'/');
            if(($node['unique_auth']??null)===self::AUTH||in_array(self::AUTH,(array)($node['auth']??[]),true)||in_array($path,[self::PATH,'/admin'.self::PATH],true))continue;
            if(isset($node['children'])&&is_array($node['children']))$node['children']=self::clean($node['children']);
            $result[]=$node;
        }
        return $result;
    }
}
