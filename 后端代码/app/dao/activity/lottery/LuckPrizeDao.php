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

namespace app\dao\activity\lottery;

use app\dao\BaseDao;
use app\model\activity\lottery\LuckPrize;

/**
 * 抽奖奖品
 * Class LuckPrizeDao
 * @package app\dao\activity\lottery
 */
class LuckPrizeDao extends BaseDao
{

    /**
     * 设置模型
     * @return string
     */
    protected function setModel(): string
    {
        return LuckPrize::class;
    }

    /**
     * 获取某个活动所有奖品
     * @param int $lottery_id
     * @param string $field
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getPrizeList(int $lottery_id, string $field = '*')
    {
        $where = ['is_del' => 0, 'status' => 1];
        return $this->search($where + ['lottery_id' => $lottery_id])->field($field)->order('sort desc,id desc')->select()->toArray();
    }
}
