<?php

namespace app\services\position;
use app\dao\position\PositionYejiDao;
use app\model\position\Position;
use app\model\position\PositionLevel;
use app\model\product\category\StoreProductCategory;
use think\facade\Db;
use app\services\BaseServices;
/**
 * Class OtherOrder
 * @package app\controller\admin\v1\order
 */
class PositionYejiServices extends BaseServices
{
    /**
     * ArticleServices constructor.
     * @param PositionYejiDao $dao
     */
    public function __construct(PositionYejiDao $dao)
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
        $independent=\app\services\payroll\PayrollAlgorithmPolicy::independentPositions();
        if ($independent) {
            if (empty($where['position_id'])) throw new \InvalidArgumentException('请选择明确职位');
            $all=(new \app\services\payroll\PositionSalaryRuleResolver())->resolve((int)$where['position_id'],\app\services\payroll\PositionSalaryRuleResolver::allRules());
            usort($all,static function($a,$b){return (int)$b['id']<=>(int)$a['id'];});
            $count=count($all);$list=array_slice($all,($page-1)*$limit,$limit);
        } else $list = $this->dao->getList($where, $page, $limit);
        $attr=['','现金','手工','扣储值-包含当天充值','扣储值-不包含当天充值'];
        $hasRecharge=['不包含','包含','不包含'];
        $yejiAttr=['','所属门店','个人'];
        foreach ($list as $nk=>$v){
            $list[$nk]['position_label']=Position::where("id",$v['position_id'])->value("name");
            $list[$nk]['position_level_label']=PositionLevel::where("id",$v['position_level_id'])->value("name");
            $cates=StoreProductCategory::whereIn("id",$v['cate_ids'])->column("cate_name");
            $list[$nk]['cates']=implode(",",$cates);
            $list[$nk]['type_label']=$attr[$v['type']] ?? '';
            $list[$nk]['range_type']=$yejiAttr[$v['range_type']] ?? '';
            $list[$nk]['has_recharge']=$hasRecharge[$v['has_recharge']] ?? '';
            $list[$nk]['commission']=$v['commission']."%";
            if ($independent && \app\services\payroll\PayrollAlgorithmPolicy::calculated() && (int)$v['type']===2 && (int)$v['range_type']===2) {
                $list[$nk]['type_label']='个人劳动提成';$list[$nk]['commission']='按项目配置';
            }
        }
        if (!$independent) $count = $this->dao->count($where);
        return compact('list', 'count') + ['independent_positions'=>$independent];
    }
}
