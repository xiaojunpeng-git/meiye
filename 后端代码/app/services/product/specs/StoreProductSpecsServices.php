<?php
// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2021 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------
namespace app\services\product\specs;


use app\dao\product\specs\StoreProductSpecsDao;
use app\services\BaseServices;
use think\exception\ValidateException;


/**
 * 商品参数
 * Class StoreProductSpecsServices
 * @package app\services\product\ensure
 * @mixin StoreProductSpecsDao
 */
class StoreProductSpecsServices extends BaseServices
{

    /**
     * 商品参数字段
     * @var array
     */
    protected $specs = [
        'id' => 0,
        'temp_id' => 0,
        'name' => '',
        'value' => '',
        'sort' => 0
    ];

    /**
     * StoreProductSpecsServices constructor.
     * @param StoreProductSpecsDao $dao
     */
    public function __construct(StoreProductSpecsDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 获取参数模版列表（带参数）
     * @param array $where
     * @return array
     */
    public function getSpecsTemplateList(array $where)
    {
        [$page, $limit] = $this->getPageValue();
        $list = $this->dao->getList($where, '*', $page, $limit);
        $count = $this->dao->count($where);
        return compact('list', 'count');
    }

    /**
     * 检测
     * @param array $data
     * @return array
     */
    public function checkSpecsData(array $data)
    {
        $data = array_merge($this->specs, array_intersect_key($data, $this->specs));
        if (!isset($data['name']) || !$data['name']) {
            throw new ValidateException('请填写参数名称');
        }
        if (!isset($data['value']) || !$data['value']) {
            throw new ValidateException('请填写参数值');
        }
        return $data;
    }

	/**
	 * 修改参数模版（商品参数）
	 * @param int $id
	 * @param array $specsArr
	 * @param int $type
	 * @param int $relation_id
	 * @return bool
	 */
    public function updateData(int $id, array $specsArr, int $type = 0, int $relation_id = 0)
    {
        $this->dao->delete(['temp_id' => $id]);
        $insert = [];
        $time = time();
        foreach ($specsArr as $specs) {
            $specs = $this->checkSpecsData($specs);
			$specs['type'] = $type;
			$specs['relation_id'] = $relation_id;
            $specs['temp_id'] = $id;
            if (isset($specs['id'])) {
                unset($specs['id']);
            }
            $specs['add_time'] = $time;
            $insert[] = $specs;

        }
        if ($insert) {
            if (!$this->dao->saveAll($insert)) {
                throw new ValidateException('新增商品参数失败');
            }
        }
        return true;
    }

	/**
	 * 保存参数模版（商品参数）
	 * @param int $id
	 * @param array $specsArr
	 * @param int $type
	 * @param int $relation_id
	 * @return bool
	 */
    public function saveData(int $id, array $specsArr, int $type = 0, int $relation_id = 0)
    {
        if (!$specsArr) return true;
        $dataAll = [];
        $time = time();
        foreach ($specsArr as $specs) {
            $specs = $this->checkSpecsData($specs);
			$specs['type'] = $type;
			$specs['relation_id'] = $relation_id;
            $specs['temp_id'] = $id;
            $specs['add_time'] = $time;
            $dataAll[] = $specs;
        }
        if ($dataAll) {
            $this->dao->saveAll($dataAll);
        }
        return true;
    }


}
