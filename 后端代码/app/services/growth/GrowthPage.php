<?php
declare(strict_types=1);
namespace app\services\growth;

/** 普通管理列表固定20条；计数、越界页修正和稳定排序始终在后端查询执行。 */
final class GrowthPage
{
    /** 同一查询返回完整总数与稳定页序，越界页回落且空结果至少一页。 */
    public static function take($query,$requested=1,string $id='id'): array
    {
        $total=(int)(clone $query)->count();$pages=max(1,(int)ceil($total/20));$page=min($pages,max(1,(int)$requested));$rows=$query->order($id,'desc')->page($page,20)->select()->toArray();return [$rows,['total'=>$total,'page'=>$page,'page_size'=>20,'total_pages'=>$pages]];
    }
}
