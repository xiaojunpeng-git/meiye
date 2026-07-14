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
declare (strict_types=1);

namespace app\services\user\label;

use app\services\BaseServices;
use app\dao\user\label\UserLabelRelationDao;
use mohe\exceptions\AdminException;

/**
 * 用户关联标签
 * Class UserLabelRelationServices
 * @package app\services\user\label
 * @mixin UserLabelRelationDao
 */
class UserLabelRelationServices extends BaseServices
{

    /**
     * UserLabelRelationServices constructor.
     * @param UserLabelRelationDao $dao
     */
    public function __construct(UserLabelRelationDao $dao)
    {
        $this->dao = $dao;
    }

	/**
	 * 获取某个用户标签ids
	 * @param int $uid
	 * @param int $type
	 * @param int $relation_id
	 * @return array
	 */
    public function getUserLabels(int $uid, int $type = 0, int $relation_id = 0)
    {
        return $this->dao->getColumn(['uid' => $uid, 'type' => $type, 'relation_id' => $relation_id], 'label_id', '');
    }

	/**
	 * 用户设置标签
	 * @param $uids
	 * @param array $labels
	 * @param int $type
	 * @param int $relation_id
	 * @param bool $group
	 * @return bool
	 */
    public function setUserLable($uids, array $labels, int $type = 0, int $relation_id = 0, bool $group = false)
    {
        if (!$uids) {
            return true;
        }
        if (!is_array($uids)) {
            $uids = [$uids];
        }
        //增加标签
        $data = [];
        foreach ($uids as $uid) {
            //用户已经存在的标签
            $user_label_ids = $this->dao->getColumn([
                ['uid', '=', $uid],
				['type', '=', $type],
                ['relation_id', '=', $relation_id]
            ], 'label_id');
            //删除未选中标签
            if ($group) {
                $del_labels = array_diff($user_label_ids, $labels);
                $this->dao->delete([
                    ['uid', '=', $uid],
                    ['label_id', 'in', $del_labels],
					['type', '=', $type],
                    ['relation_id', '=', $relation_id]
                ]);
            }
            $add_labels = array_diff($labels, $user_label_ids);
            if ($add_labels) {
                foreach ($add_labels as $label) {
                    $label = (int)$label;
                    if (!$label) continue;
                    $data[] = ['uid' => $uid, 'label_id' => $label, 'type' => $type, 'relation_id' => $relation_id];
                }
            }
        }
        if ($data) {
            if (!$this->dao->saveAll($data))
                throw new AdminException('设置标签失败');
        }

        return true;
    }

	/**
	 * 取消用户标签
	 * @param int $uid
	 * @param array $labels
	 * @param int $type
	 * @param int $relation_id
	 * @return bool
	 */
    public function unUserLabel(int $uid, array $labels = [], int $type = 0, int $relation_id = 0)
    {
		if (!count($labels)) {
			return true;
		}
		$where = [
			['uid', '=', $uid],
			['label_id', 'in', $labels],
			['type', '=', $type],
			['relation_id', '=', $relation_id]
		];
        $this->dao->delete($where);
        return true;
    }

	/**
	 * 清空用户标签
	 * @param int $uid
	 * @param int $type
	 * @param int $relation_id
	 * @return bool
	 */
	public function delUserLabel(int $uid, int $type = 0, int $relation_id = 0)
	{
		$where = [
			['uid', '=', $uid],
			['type', '=', $type],
			['relation_id', '=', $relation_id]
		];
		$this->dao->delete($where);
		return true;
	}

	/**
	 * 获取用户标签
	 * @param array $uids
	 * @param int $type
	 * @param int $relation_id
	 * @return array
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function getUserLabelList(array $uids, int $type = 0, int $relation_id = 0)
    {
        return $this->dao->getLabelList($uids, $type, $relation_id);
    }
}
