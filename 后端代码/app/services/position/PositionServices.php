<?php

namespace app\services\position;
use app\dao\position\PositionDao;
use app\model\position\PositionLevel;
use app\model\position\PositionYeji;
use app\model\product\category\StoreProductCategory;
use app\services\BaseServices;
/**
 * Class OtherOrder
 * @package app\controller\admin\v1\order
 */
class PositionServices extends BaseServices
{
    /**
     * ArticleServices constructor.
     * @param PositionDao $dao
     */
    public function __construct(PositionDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 获取列表
     * @param array $where
     * @return array
     */
    public function getList(array $where, int $page = 0, int $limit = 0)
    {
        if (!$limit) {
            [$page, $limit] = $this->getPageValue();
        }
        $list = $this->dao->getList($where, $page, $limit);
        foreach ($list as $nk=>$nv){
            $list[$nk]['levels']=PositionYeji::where("position_id",$nv['id'])->column("position_level_id");
            $list[$nk]['levels']=PositionLevel::whereIn('id',$list[$nk]['levels'])->column("name");
            $list[$nk]['levels']=implode(",",array_filter($list[$nk]['levels']));
            $list[$nk]['cates']=PositionYeji::where("position_id",$nv['id'])->column("cate_ids");
            $list[$nk]['cates']=implode(",",array_filter(array_unique($list[$nk]['cates'])));
            $cates=StoreProductCategory::whereIn("id",$list[$nk]['cates'])->column("cate_name");
            $list[$nk]['cates']=implode(",",$cates);
        }
        $count = $this->dao->count($where);
        return compact('list', 'count');
    }
}
