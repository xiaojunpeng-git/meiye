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

namespace app\services\product\product;


use app\dao\product\product\StoreProductWordsDao;
use app\services\BaseServices;
use mohe\exceptions\AdminException;



/**
 * 热词
 * Class StoreProductWordsServices
 * @package app\services\product\product
 * @mixin StoreProductWordsDao
 */
class StoreProductWordsServices extends BaseServices
{

	/**
	 * @param StoreProductWordsDao $dao
	 */
	public function __construct(StoreProductWordsDao $dao)
	{
		$this->dao = $dao;
	}

    /**
	 * 获取所有商品单位列表
	 * @param int $type
	 * @param int $relation_id
	 * @return array
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function getAllWordsList(int $type = 0, int $relation_id = 0)
    {
		$where = [];
//		$where['type'] = $type;
//		$where['relation_id'] = $relation_id;
		$where['is_show'] = 1;
		$where['is_del'] = 0;
        return $this->dao->getList($where, 'id,name');
    }

    /**
     * 获取商品单位
     * @param array $where
     * @param string $field
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getWordsList(array $where, string $field = '*')
    {
        [$page, $limit] = $this->getPageValue();
        $list = $this->dao->getList($where, $field, $page, $limit);
		if ($list) {
			foreach ($list as &$item) {
				$item['add_time'] = date('Y-m-d H:i:s', time());
			}
		}
        $count = $this->dao->count($where);
        return compact('list', 'count');
    }

	/**
 	* 保存数据
	* @param int $id
	* @param array $data
	* @return bool
	* @throws \think\db\exception\DataNotFoundException
	* @throws \think\db\exception\DbException
	* @throws \think\db\exception\ModelNotFoundException
	*/
	public function saveData(int $id, array $data)
	{
		$words = $this->dao->getOne(['name' => $data['name'], 'is_del' => 0, 'type' => 0]);
		if ($id) {
			if ($words && $words['id'] != $id) {
				throw new AdminException('热词名称已经存在');
			}
			if ($this->dao->update($id, $data)) {
				$data['id'] = $id;
				$this->cacheUpdate($data);
				return true;
			} else {
				return false;
			}
		} else {
			if ($words) {
				throw new AdminException('热词已经存在，请勿重复添加');
			}
			$data['add_time'] = time();
			if ($res = $this->dao->save($data)) {
				$id = $res->id;
				$data['id'] = $id;
				$this->cacheUpdate($data);
				return true;
			} else {
				return false;
			}
		}
	}

	 /**
     * 设置状态
     * @param $id
     * @param $is_show
     */
    public function setShow(int $id, int $is_show)
    {
        $res = $this->dao->update($id, ['is_show' => $is_show]);
        if (!$res) {
            throw new AdminException('设置失败');
        }

        //设置缓存
        if (!$is_show) {
            $this->cacheDelById($id);
            return;
        }
        $branInfo = $this->dao->cacheInfoById($id);
        if ($branInfo) {
            $branInfo['is_show'] = 1;
        } else {
            $branInfo = $this->dao->get($id);
            if (!$branInfo) {
                return;
            }
            $branInfo = $branInfo->toArray();
        }
        $this->dao->cacheUpdate($branInfo);

    }

	/**
     * 删除数据
     * @param int $id
     */
    public function del(int $id)
    {
        $res = $this->dao->delete($id);
        if (!$res) throw new AdminException('删除失败');

        //更新缓存
        $this->cacheDelById($id);
    }



}
