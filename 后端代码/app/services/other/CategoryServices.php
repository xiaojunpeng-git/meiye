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

namespace app\services\other;


use app\dao\other\CategoryDao;
use app\services\BaseServices;
use mohe\traits\ServicesTrait;

/**
 * Class CategoryServices
 * @package app\services\other
 * @mixin CategoryDao
 */
class CategoryServices extends BaseServices
{

    use ServicesTrait;
	//0=标签分类，1=快捷短语分类,2=商品标签分类，3=商品参数模版,4=企业渠道码，5=门店分类，6=桌码分类,7=积分分类

	//门店分类
	public const  STORE_CATEGORY_GROUP = 5;
	//门店桌码分类
	public const  TABLE_CODE_GROUP = 6;
	//积分分类
	public const  INTEGRAL_CATEGORY_GROUP = 7;


    protected $cacheName = 'mohe_cate';

    /**
     * CategoryServices constructor.
     * @param CategoryDao $dao
     */
    public function __construct(CategoryDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 获取分类列表
     * @param array $where
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getCateList(array $where = [], array $field = ['*'], array $with = [])
    {
        [$page, $limit] = $this->getPageValue();
        $data = $this->dao->getCateList($where, $page, $limit, $field, $with);
        $count = $this->dao->count($where);
        return compact('data', 'count');
    }

    /**桌码管理
     * @param array $where
     * @param array $field
     * @param array $with
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getTableCodeCateList(array $where = [], array $field = ['*'], array $with = [])
    {
        $data = $this->dao->getCateList($where, 0, 0, $field, $with);
        return $data;
    }

}
