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

namespace app\services\system\timer;

use app\services\BaseServices;
use mohe\exceptions\AdminException;
use app\dao\system\timer\SystemTimerDao;

/**
 * 定时器service
 * Class SystemTimerServices
 * @package app\services\system\timer
 * @mixin SystemTimerDao
 */
class SystemTimerServices extends BaseServices
{
    /**
     * 定时任务名称及标识
     * @var string[]
     */
    protected $taskName = [
        'auto_cancel' => '自动取消订单',
        'auto_take' => '自动确认收货',
        'auto_comment' => '自动好评',
        'auto_clear_integral' => '自动清空用户积分',
        'auto_off_user_svip' => '自动取消用户到期svip',
        'auto_agent' => '自动解绑上下级',
        'auto_clear_poster' => '自动清除昨日海报',
        'auto_live' => '自动更新直播产品状态和直播间状态',
        'auto_pink' => '拼团状态自动更新',
        'auto_show' => '自动上下架商品',
        'auto_channel' => '渠道码定时任务',
        'auto_moment' => '定时创建发送朋友圈任务',
        'auto_group_task' => '定时发送群发任务',
        'rebate_points_orders' => '未支付积分订单退积分',
        'code_not_operating' => '桌码长期未操作取消桌码记录',
        'collate_not_operating' => '拼单长期未操作取消拼单记录',
        'reminder_unverified_remind' => '次卡商品未核销短信提醒',
        'sign_remind_time' => '用户签到提醒',
		'presale_product_status' => '预售商品结束商品状态处理',
		'shop_assistant_enterprise_we_chat_binding' => '店员企业微信绑定处理',
		'untamed_shop_assistant_time' => '临期解绑店员处理',
    ];

    /**
     * SystemTimerServices constructor.
     * @param SystemTimerDao $dao
     */
    public function __construct(SystemTimerDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 获取定时任务名称
     * @return array|string[]
     */
    public function getTasKName()
    {
        return $this->taskName;
    }

    /**
     * 获取定时器列表
     * @param array $where
     * @return array
     */
    public function getTimerList(array $where)
    {
        [$page, $limit] = $this->getPageValue();
        $list = $this->dao->getList($where, $page, $limit);
        foreach ($list as $key => &$item) {
            $last_execution_time = $this->dao->cacheHander()->get($item['mark']);
            $item['last_execution_time'] = $last_execution_time > 0 ? date('Y-m-d H:i:s', $last_execution_time) : '';
            switch ($item['type']) {
                case 1:
                    $item['execution_cycle'] = '每隔' . $item['cycle'] . '分钟执行';
                    break;
                case 2:
                    $arr = explode('/', $item['cycle']);
                    $item['execution_cycle'] = '每' . $arr[0] . '小时, 第' . $arr[1] . '分钟 执行';
                    break;
                case 3:
                    $item['execution_cycle'] = '每小时, 第' . $item['cycle'] . '分钟执行';
                    break;
                case 4:
                    $arr = explode('/', $item['cycle']);
                    $item['execution_cycle'] = '每天, ' . $arr[0] . '点' . $arr[1] . '分 执行';
                    break;
                case 5:
                    $arr = explode('/', $item['cycle']);
                    $item['execution_cycle'] = '每隔' . $arr[0] . '天, ' . $arr[1] . '点' . $arr[2] . '分 执行';
                    break;
                case 6:
                    $arr = explode('/', $item['cycle']);
                    $item['execution_cycle'] = '每周' . $arr[0] . ', ' . $arr[1] . '点' . $arr[2] . '分执行';
                    break;
                case 7:
                    $arr = explode('/', $item['cycle']);
                    $item['execution_cycle'] = '每月' . $arr[0] . '日 ' . $arr[1] . '点' . $arr[2] . '分执行';
                    break;
                case 8:
                    $arr = explode('/', $item['cycle']);
                    $item['execution_cycle'] = '每年' . $arr[0] . '月' . $arr[1] . '日 ' . $arr[2] . '点' . $arr[3] . '分执行';
                    break;
                default:
                    $item['execution_cycle'] = '周期有误！';
                    break;
            }
        }
        $count = $this->dao->count($where);
        return compact('list', 'count');
    }

    /**设置或更新定时任务缓存
     * @return void
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function setAllTimerCache()
    {
        $where['is_del'] = 0;
        $list = $this->dao->getList($where, 0, 0);
        foreach ($list as $key => &$value) {
            if (!$value['update_execution_time']) {
                $update_time = time();
                $value['update_execution_time'] = $update_time;
                $this->dao->update($value['id'], ['update_execution_time' => $update_time]);
            }
        }
        $this->dao->cacheCreate($list);
    }

    /**
     * 设置定时器状态
     * @param $id
     * @param $is_show
     */
    public function setShow(int $id, int $is_show)
    {
        $this->dao->update($id, ['is_open' => $is_show]);
    }

    /**
     * 删除定时器
     * @param string $id
     */
    public function del(int $id)
    {
        if (!$id) throw new AdminException('请选择要删除的定时任务');
        $this->dao->delete((int)$id);
    }

    /**
     * 新增数据
     * @param $data
     */
    public function createData($data)
    {
        if ($this->dao->be(['mark' => $data['mark'], 'name' => $data['name']])) {
            throw new AdminException('该定时任务已经存在');
        }
        $data['add_time'] = time();
        $data['update_execution_time'] = time();
        $res = $this->dao->save($data);
        if (!$res) throw new AdminException('添加失败');
        return $res;
    }

    /**
     * 保存修改数据
     * @param $id
     * @param $data
     */
    public function editData($id, $data)
    {
        if (!$this->dao->be(['id' => $id])) {
            throw new AdminException('该定时任务不存在');
        }
        $data['update_execution_time'] = time();
        $res = $this->dao->update($id, $data);
        if (!$res) throw new AdminException('修改失败');
        return $res;
    }

    /**更新缓存
     * @param $id
     * @return bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function updateOneTimerCache($id)
    {
        if (!$id) throw new AdminException('参数错误');
        $timer = $this->dao->get($id);
        $this->dao->cacheUpdate($timer->toArray());
        return true;
    }

    /**删除缓存
     * @param $id
     * @return bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function delOneTimerCache($id)
    {
        if (!$id) throw new AdminException('参数错误');
        $this->dao->cacheDelById($id);
        return true;
    }

    /**获取单条定时器数据
     * @param $id
     * @return array
     */
    public function getOneTimer($id)
    {
        if (!$id) throw new AdminException('请选择要删除的定时任务');
        $timer = $this->dao->get($id);
        if (!$timer) throw new AdminException('该定时任务不存在');
        return $timer->toArray();
    }

    /**获取下次执行时间
     * @param $type
     * @param $cycle
     * @param $time
     * @param $start_time
     * @return array
     */
    public function getTimerCycleTime($type, $cycle, $time, $start_time = 0)
    {
        if (!$time) $time = time();
        switch ($type) {
            case 1: // N分钟
                $cycle_time = 60 * $cycle;
                $i = $time - $start_time;
                $more = $i % $cycle_time;
                if ($more == 0) {
                    $cycle_time = $time;
                } else {
                    $cycle_time = $time - $more + $cycle_time;
                }
                break;
            case 2: //N小时
                $arr = explode('/', $cycle);
                $todaystart = strtotime(date('Y-m-d H' . ':00:00', $time));
                $start_time = strtotime(date('Y-m-d H' . ':00:00', $start_time));
                if ($arr[0] <= 0) {
                    $cycle_time = 60 * $arr[1];
                    $i = $time - $start_time;
                    $more = $i % $cycle_time;
                    if ($more == 0) {
                        $cycle_time = $time;
                    } else {
                        $cycle_time = $time - $more + $cycle_time;
                    }
                } else {
                    $h = ($todaystart - $start_time) / 3600;
                    $h = floor($h);
                    $more = $h % $arr[0];
                    if ($more == 0) {
                        $cycle_time = 60 * $arr[1];
                        $cycle_time1 = $todaystart + $cycle_time;
                        if ($cycle_time1 < $time) {
                            $cycle_time = 60 * 60 * $arr[0] + 60 * $arr[1];
                        }
                    } else {
                        $sh = $arr[0] - $more;
                        $cycle_time = 60 * 60 * $sh + 60 * $arr[1];
                    }
                    $cycle_time = $todaystart + $cycle_time;
                }
                break;
            case 3: //每小时
                $cycle_time = strtotime(date('Y-m-d ' . 'H:' . $cycle . ':00', $time));
                break;
            case 4: //每天
                $arr = explode('/', $cycle);
                $cycle_time = strtotime(date('Y-m-d ' . $arr[0] . ':' . $arr[1] . ':00', $time));
                break;
            case 5: //N天
                $arr = explode('/', $cycle);
                $todaystart = strtotime(date('Y-m-d ' . '00:00:00', $time));
                $start_time = strtotime(date('Y-m-d ' . '00:00:00', $start_time));
                $d = ($todaystart - $start_time) / 86400;
                $d = floor($d);
                $more = $d % $arr[0];
                if ($more == 0) {
                    $cycle_time = 60 * 60 * $arr[1] + 60 * $arr[2];
                    $cycle_time1 = $todaystart + $cycle_time;
                    if ($cycle_time1 < $time) {
                        $cycle_time = 60 * 60 * 24 * $more + 60 * 60 * $arr[1] + 60 * $arr[2];
                    }
                } else {
                    $sd = $arr[0] - $more;
                    $cycle_time = 60 * 60 * 24 * $sd + 60 * 60 * $arr[1] + 60 * $arr[2];
                }
                $cycle_time = $todaystart + $cycle_time;
                break;
            case 6: //每星期
                $arr = explode('/', $cycle);
                $todaystart = strtotime(date('Y-m-d ' . '00:00:00', $time));
                $w = date("w");
                if ($w > $arr[0]) {
                    $d = 7 - $w + $arr[0];
                    $cycle_time = $todaystart + 60 * 60 * 24 * $d + 60 * 60 * $arr[1] + 60 * $arr[2];
                } else if ($w == $arr[0]) {
                    $to_time = 60 * 60 * $arr[1] + 60 * $arr[2];
                    $to_time = $todaystart + $to_time;
                    if ($time > $to_time) {
                        $d = 7 - $w + $arr[0];
                        $cycle_time = $todaystart + 60 * 60 * 24 * $d + 60 * 60 * $arr[1] + 60 * $arr[2];
                    } else {
                        $cycle_time = $to_time;
                    }
                } else {
                    $d = $arr[0] - $w;
                    $cycle_time = $todaystart + 60 * 60 * 24 * $d + 60 * 60 * $arr[1] + 60 * $arr[2];
                }
                break;
            case 7: //每月
                $arr = explode('/', $cycle);
                $current_d = date("d");
                $firstDate = date('Y-m-01', $time);
                $max_d = date('d', strtotime("$firstDate + 1 month -1 day"));
                $todaystart = strtotime(date('Y-m-d ' . '00:00:00', $time));
                if ($current_d > $arr[0]) {
                    $d = $max_d - $current_d + $arr[0];
                    $cycle_time = $todaystart + 60 * 60 * 24 * $d + 60 * 60 * $arr[1] + 60 * $arr[2];
                } elseif ($current_d == $arr[0]) {
                    $to_time = 60 * 60 * $arr[1] + 60 * $arr[2];
                    $to_time = $todaystart + $to_time;
                    if ($time > $to_time) {
                        $d = $max_d - $current_d + $arr[0];
                        $cycle_time = $todaystart + 60 * 60 * 24 * $d + 60 * 60 * $arr[1] + 60 * $arr[2];
                    } else {
                        $cycle_time = $to_time;
                    }
                } else {
                    $d = $arr[0] - $current_d;
                    $cycle_time = $todaystart + 60 * 60 * 24 * $d + 60 * 60 * $arr[1] + 60 * $arr[2];
                }
                break;
            case 8: //每年
                $arr = explode('/', $cycle);
                $cycle_time = strtotime(date('Y-' . $arr[0] . '-' . $arr[1] . ' ' . $arr[2] . ':' . $arr[3] . ':00', $time));
                break;
            default:
                $cycle_time = 0;
                break;
        }
        return ['cycle_time' => $cycle_time];
    }
}
