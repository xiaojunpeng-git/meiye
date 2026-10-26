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

namespace app\services\system;


use app\services\BaseServices;
use app\dao\system\SystemSignRewardDao;
use mohe\exceptions\AdminException;
use mohe\services\FormBuilder as Form;
use think\facade\Route as Url;


/**
 * Class SystemSignRewardServices
 * @package app\services\system
 * @mixin SystemSignRewardDao
 */
class SystemSignRewardServices extends BaseServices
{

    /**
     * SystemRoleServices constructor.
     * @param SystemSignRewardDao $dao
     */
    public function __construct(SystemSignRewardDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 签到奖励列表
     * @param $type
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getList($type = 0)
    {
        [$page, $limit] = $this->getPageValue();
        $list = $this->dao->selectList(['type' => $type], '*', $page, $limit, 'days asc');
        $count = $this->dao->count(['type' => $type]);
        return compact('list', 'count');
    }

    /**
     * 新增修改签到奖励表单
     * @param $id
     * @param $type
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function rewardsForm($id = 0, $type = 0)
    {
        $info = $this->dao->get($id);
        if ($info) $type = $info['type'];
        $form[] = Form::hidden('type', $type);
        $form[] = Form::number('days', $type == 1 ? '累积签到天数：' : '连续签到天数：', (int)($info['days'] ?? 0))->max(sys_config('sign_mode') == 1 ? 7 : 30);
        $form[] = Form::number('point', '赠送积分：', (int)($info['point'] ?? 0))->max(999)->min(0);
        $form[] = Form::number('exp', '赠送经验：', (int)($info['exp'] ?? 0))->max(999)->min(0);
        return create_form($type == 1 ? '累积签到奖励' : '连续签到奖励', $form, Url::buildUrl('/setting/sign/save_rewards/' . $id), 'POST');
    }

    /**
     * 保存签到奖励
     * @param $id
     * @param $data
     * @return bool
     * @throws \think\db\exception\DbException
     */
    public function saveRewards($id, $data)
    {
        if ($id) {
            $this->dao->update($id, $data);
        } else {
            if ($this->dao->be(['type' => $data['type'], 'days' => $data['days']])) {
                throw new AdminException('签到奖励已存在');
            } else {
                $this->dao->save($data);
            }
        }
        return true;
    }

    /**
     * 获取累积或者连续签到奖励数据
     * @param $type
     * @param $days
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getSignRewards($type, $days)
    {
        $info = $this->dao->get(['type' => $type, 'days' => $days]);
        if ($info) return [true, $info['point'], $info['exp']];
        return [false, 0, 0];
    }

}
