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

namespace app\dao\diy;

use app\dao\BaseDao;
use app\model\diy\Diy;

/**
 *
 * Class DiyDao
 * @package app\dao\diy
 */
class DiyDao extends BaseDao
{

    /**
     * 设置模型
     * @return string
     */
    protected function setModel(): string
    {
        return Diy::class;
    }

    /**
     * 获取DIY列表
     * @param array $where
     * @param int $page
     * @param int $limit
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getDiyList(array $where, int $page, int $limit, array $field = ['*'])
    {
        return $this->search($where)->field($field)->page($page, $limit)->order('status desc,id desc')->select()->toArray();
    }


}
