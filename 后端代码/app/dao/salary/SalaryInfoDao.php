<?php
// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2020 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------
namespace app\dao\salary;

use app\dao\BaseDao;
use app\model\salary\SalaryInfo;
use app\model\store\SystemStoreStaff;

/**
 * 文章dao
 * Class ArticleDao
 * @package app\dao\article
 */
class SalaryInfoDao extends BaseDao
{
    /**
     * 设置模型
     * @return string
     */
    protected function setModel(): string
    {
        return SalaryInfo::class;
    }

    public function search(array $where = [])
    {
        $hasDel=$where['has_del'] ?? 0;
        $staffId=SystemStoreStaff::where("salary_status",1);
        if(empty($hasDel || $hasDel == 0)){
             $staffId=$staffId->where("is_del",0);
        }
        $staffId=$staffId->column("id");
        return parent::search($where)->order("position_id asc")->whereIn("staff_id",$staffId)->when(isset($where['store_id']) && !empty($where['store_id']), function ($query) use ($where) {
            $query->where('store_id',$where['store_id']);
        })->when(isset($where['date']) && !empty($where['date']), function ($query) use ($where) {
            $query->where('date',strtotime($where['date']));
        });
    }

    public function getList(array $where, int $page = 0, int $limit = 0, string $order = '', array $with = [])
    {
        return $this->search($where)->order(($order ? $order . ' ,' : '') . 'id desc')
            ->when(count($with), function ($query) use ($with) {
                $query->with($with);
            })
            ->when($page != 0 && $limit != 0, function ($query) use ($page, $limit) {
                $query->page($page, $limit);
            })->select()->toArray();
    }

    public function moneyCount($where){
          $list=$this->search($where)->select();
          return $list;
    }
}
