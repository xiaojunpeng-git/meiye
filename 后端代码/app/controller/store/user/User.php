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
namespace app\controller\store\user;


use app\services\user\UserCardHolderServices;
use think\Db;
use think\facade\App;
use app\controller\store\AuthController;
use app\services\store\StoreUserServices;
use app\services\user\UserServices;
use app\services\other\queue\QueueServices;
use app\services\user\group\UserGroupServices;
use app\services\user\label\UserLabelCateServices;
use app\services\user\label\UserLabelServices;
use app\services\user\level\SystemUserLevelServices;
use app\services\wechat\WechatCardServices;
use app\jobs\BatchHandleJob;

/**
 * 门店用户
 * Class User
 * @package app\controller\store\user
 */
class User extends AuthController
{
    /**
     * DeliveryService constructor.
     * @param App $app
     * @param StoreUserServices $services
     */
    public function __construct(App $app, StoreUserServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    /**
     * 搜索用户
     * @param UserServices $services
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function search(UserServices $services)
    {
        $data = $this->request->getMore([
            ['keyword', ''],
            ['field_key', ''],
        ]);
        if ($data['field_key'] == 'all') {
            $data['field_key'] = '';
        }
        if ($data['field_key'] && in_array($data['field_key'], ['uid', 'phone', 'bar_code'])) {
            if ($data['field_key'] === 'phone') {
                $where['phone_fuzzy'] = trim($data['keyword']);
            } else {
                $where[$data['field_key']] = trim($data['keyword']);
            }
        } else {
            $where['store_like'] = trim($data['keyword']);
        }
		$result = $services->getUserList($where, 'uid,nickname,avatar,phone,now_money,user_type');
        $list = $result['list'] ?? [];
        $count = $result['count'] ?? 0;
		if ($list) {
			foreach ($list as &$item) {
				//用户类型
				$item['user_type'] = $services->userTypeName[$item['user_type']] ?? '其他';
			}
		}
        return app('json')->success(compact('list', 'count'));
    }

    /**
     * 显示资源列表
     *
     * @return \think\Response
     */
    public function index()
    {
        $where = $this->request->getMore([
            ['page', 1],
            ['limit', 20],
            ['nickname', ''],
            ['keyword', ''],
            ['status', ''],
            ['pay_count', ''],
            ['is_promoter', ''],
            ['order', ''],
            ['data', ''],
            ['user_type', ''],
            ['country', ''],
            ['province', ''],
            ['city', ''],
            ['user_time_type', ''],
            ['user_time', ''],
            ['sex', ''],
            [['level', 0], 0],
            [['group_id', 'd'], 0],
            [['label_id', 'd'], 0],
            ['now_money', 'normal'],
            ['field_key', ''],
            ['isMember', ''],
            ['cash_consume_price', ''],
            ['recent_consume_type', ''],
            ['recent_consume_days', 0],
            ['recent_consume_time', ''],
            ['no_consume_type', ''],
            ['no_consume_days', 0],
            ['no_consume_time', ''],
            ['writeoff_count', ''],
            ['recent_writeoff_type', ''],
            ['recent_writeoff_days', 0],
            ['recent_writeoff_time', ''],
            ['no_writeoff_type', ''],
            ['no_writeoff_days', 0],
            ['no_writeoff_time', ''],
            ['integral', ''],
            ['now_money_peice', ''],
            ['sale_date_time', ''],
        ]);
        if (empty($where['nickname']) && !empty($where['keyword'])) {
            $where['nickname'] = $where['keyword'];
        }
        unset($where['keyword']);
        if (!empty($where['label_id'])) {
            $where['label_id'] = stringToIntArray($where['label_id']);
        }
        return app('json')->success($this->services->index($where, $this->storeId));
    }

    /**
     * 显示创建资源表单页.
     *
     * @return \think\Response
     */
    public function create()
    {
        return app('json')->success($this->services->saveForm());
    }

    /**
     * 保存新建的资源
     *
     * @param \think\Request $request
     * @return \think\Response
     */
    public function save()
    {
        $data = $this->request->postMore([
            ['is_promoter', 0],
            ['real_name', ''],
            ['card_id', ''],
            ['birthday', ''],
            ['mark', ''],
            ['status', 0],
            ['level', 0],
            ['phone', 0],
            ['addres', ''],
            ['label_id', []],
            ['group_id', 0],
            ['pwd', ''],
            ['true_pwd', ''],
            ['spread_open', 1]
        ]);
        if ($data['phone']) {
            if (!check_phone($data['phone'])) {
                return app('json')->fail('手机号码格式不正确');
            }
            if ($this->services->count(['phone' => $data['phone']])) {
                return app('json')->fail('手机号已经存在不能添加相同的手机号用户');
            }
            $data['nickname'] = substr_replace($data['phone'], '****', 3, 4);
        }
        if ($data['card_id']) {
			try {
				if (!check_card($data['card_id'])) return app('json')->fail('请输入正确的身份证');
 			} catch (\Throwable $e) {
//				return app('json')->fail('请输入正确的身份证');
 			}
        }
        if ($data['pwd']) {
            if (!$data['true_pwd']) {
                return app('json')->fail('请输入确认密码');
            }
            if ($data['pwd'] != $data['true_pwd']) {
                return app('json')->fail('两次输入的密码不一致');
            }
            $data['pwd'] = md5($data['pwd']);
        } else {
            unset($data['pwd']);
        }
        unset($data['true_pwd']);
        $data['avatar'] = sys_config('h5_avatar');
        $data['adminId'] = $this->adminId;
        $data['user_type'] = 'h5';
        $lables = $data['label_id'];
        unset($data['label_id']);
        foreach ($lables as $k => $v) {
            if (!$v) {
                unset($lables[$k]);
            }
        }
        $data['birthday'] = empty($data['birthday']) ? 0 : strtotime($data['birthday']);
        $data['add_time'] = time();
        $this->services->transaction(function () use ($data, $lables) {
            $res = true;
            $userInfo = $this->services->save($data);
            if ($lables) {
                $res = $this->services->saveSetLabel([$userInfo->uid], $lables);
            }
            if ($data['level']) {
                $res = $this->services->saveGiveLevel((int)$userInfo->uid, (int)$data['level']);
            }
            event('user.register', [$this->services->get((int)$userInfo->uid), true, 0]);
        });
        return app('json')->success('添加成功');
    }

    /**
     * 显示指定的资源
     *
     * @param int $id
     * @param UserServices $services
     * @return \think\Response
     */
    public function read($id, UserServices $services)
    {
        return app('json')->success($services->read((int)$id));
    }

    /**
     * 设置用户标签表单
     * @param UserServices $services
     * @return mixed
     */
    public function set_label(UserServices $services)
    {
        [$uids, $all, $where] = $this->request->postMore([
            ['uids', []],
            ['all', 0],
            ['where', ""],
        ], true);
        return app('json')->success($services->setLabel($uids, $all, $where, 1, (int)$this->storeId));
    }

    /**
     * 批量设置用户标签
     * @return mixed
     */
    public function save_set_label()
    {
        [$lables, $uids, $all, $where] = $this->request->postMore([
            ['label_id', []],
            ['uids', ''],
            ['all', 0],
            ['where', ""],
        ], true);
        if (!$uids && $all == 0) return app('json')->fail('缺少参数');
        if (!$lables || (count($lables) == 1 && !$lables[0])) return app('json')->fail('请选择标签');
        if ($all == 0) {
            $uids = explode(',', $uids);
            $where = [];
        }
        if ($all == 1) {
            $uids = [];
            $where = $where ? json_decode($where, true) : [];
        }
        /** @var UserLabelServices $userLabelServices */
        $userLabelServices = app()->make(UserLabelServices::class);
        $count = $userLabelServices->getCount([['id', 'IN', $lables]]);
        if ($count != count($lables)) {
            return app('json')->fail('有用户标签不存在或被删除');
        }
        $type = 3;//批量设置用户标签
        /** @var QueueServices $queueService */
        $queueService = app()->make(QueueServices::class);
        $queueService->setQueueData($where, 'uid', $uids, $type, $lables);
        //加入队列
        BatchHandleJob::dispatch([$lables, $type, ['store_id' => $this->storeId]]);
        return app('json')->success('后台程序已执行批量设置用户标签任务!');
    }

    /**
     * 编辑其他
     * @param $id
     * @return mixed
     * @throws \FormBuilder\Exception\FormBuilderException
     */
    public function edit_other($id)
    {
        if (!$id) return app('json')->fail('数据不存在');
        return app('json')->success($this->services->editOther((int)$id));
    }

    /**
     * 执行编辑其他
     * @param int $id
     * @return mixed
     */
    public function update_other($id)
    {
        $data = $this->request->postMore([
            ['money_status', 0],
            ['money', 0],
            ['integration_status', 0],
            ['integration', 0],
        ]);
        if (!$id) return app('json')->fail('数据不存在');
        $data['adminId'] = $this->adminId;
        $data['money'] = (string)$data['money'];
        $data['integration'] = (string)$data['integration'];
        $data['is_other'] = true;
        return app('json')->success($this->services->updateInfo($id, $data) ? '修改成功' : '修改失败');
    }

    /**
     * 编辑会员信息
     * @param $id
     * @return mixed|\think\response\Json|void
     */
    public function edit($id)
    {
        if (!$id) return app('json')->fail('数据不存在');
        return app('json')->success($this->services->edit($id));
    }

    public function update($id)
    {
        $data = $this->request->postMore([
            ['money_status', 0],
            ['is_promoter', 0],
            ['real_name', ''],
            ['card_id', ''],
            ['birthday', ''],
            ['mark', ''],
            ['money', 0],
            ['integration_status', 0],
            ['integration', 0],
            ['status', 0],
            ['level', 0],
            ['phone', 0],
            ['addres', ''],
            ['label_id', ''],
            ['group_id', 0],
            ['pwd', ''],
            ['true_pwd'],
            ['spread_open', 1]
        ]);
        if ($data['phone']) {
            if (!check_phone($data['phone'])) return app('json')->fail('手机号码格式不正确');
        }
        if ($data['card_id']) {
			try {
				if (!check_card($data['card_id'])) return app('json')->fail('请输入正确的身份证');
 			} catch (\Throwable $e) {
//				return app('json')->fail('请输入正确的身份证');
 			}
        }
        if ($data['pwd']) {
            if (!$data['true_pwd']) {
                return app('json')->fail('请输入确认密码');
            }
            if ($data['pwd'] != $data['true_pwd']) {
                return app('json')->fail('两次输入的密码不一致');
            }
            $data['pwd'] = md5($data['pwd']);
        } else {
            unset($data['pwd']);
        }
        unset($data['true_pwd']);
        if (!$id) return app('json')->fail('数据不存在');
        $data['adminId'] = $this->adminId;
        $data['money'] = (string)$data['money'];
        $data['integration'] = (string)$data['integration'];
        return app('json')->success($this->services->updateInfo($id, $data) ? '修改成功' : '修改失败');
    }

    /**
     * 获取单个用户信息
     * @param $id 用户id
     * @param UserServices $services
     * @return mixed
     */
    public function oneUserInfo($id, UserServices $services)
    {
        $data = $this->request->getMore([
            ['type', ''],
        ]);
        $id = (int)$id;
        if ($data['type'] == '') return app('json')->fail('缺少参数');
        return app('json')->success($services->oneUserInfo($id, $data['type'], $this->storeId));
    }

    /**
     * 卡项信息
     * @param $id
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function cardHolder($id)
    {
        if (!$id) {
            return app('json')->fail('缺少参数');
        }
        /** @var UserCardHolderServices $cardHolderServices */
        $cardHolderServices = app()->make(UserCardHolderServices::class);
        $cardHolder = $cardHolderServices->oneCardHolder((int)$id);
        if(!$cardHolder) return app('json')->fail('数据有误');
        return app('json')->success($cardHolder);
    }

    /**
     * 获取卡卷颜色
     * @param WechatCardServices $services
     * @return \think\Response
     */
    public function getWechatCard(WechatCardServices $services)
    {
        return app('json')->success($services->getInfo());
    }

    /**
     * 用户分组列表
     * @param UserGroupServices $services
     * @return \think\Response
     */
    public function userGroup(UserGroupServices $services)
    {
        return app('json')->success($services->getGroupList('*', true));
    }

    /**
     * 用户等级列表
     * @param SystemUserLevelServices $services
     * @return \think\Response
     */
    public function userLevel(SystemUserLevelServices $services)
    {
        $where = $this->request->getMore([
            ['page', 0],
            ['limit', 10],
            ['title', ''],
            ['is_show', 1],
        ]);
        return app('json')->success($services->getLevelList($where));
    }

    /**
     * 用户标签树（筛选）
     * @param UserLabelCateServices $userLabelCateServices
     * @param UserLabelServices $userLabelServices
     * @return \think\Response
     */
    public function userLabelTree(UserLabelCateServices $userLabelCateServices, UserLabelServices $userLabelServices)
    {
        $cate = $userLabelCateServices->getLabelCateAll(1, (int)$this->storeId);
        $data = [];
        $label = [];
        if ($cate) {
            foreach ($cate as $value) {
                $data[] = [
                    'id' => $value['id'] ?? 0,
                    'value' => $value['id'] ?? 0,
                    'label_cate' => 0,
                    'label_name' => $value['name'] ?? '',
                    'label' => $value['name'] ?? '',
                    'store_id' => $value['store_id'] ?? 0,
                    'type' => $value['type'] ?? 1,
                ];
            }
            $label = $userLabelServices->getList(['type' => 1, 'relation_id' => $this->storeId]);
            $label = $label['list'] ?? [];
            if ($label) {
                foreach ($label as &$item) {
                    $item['label'] = $item['label_name'];
                    $item['value'] = $item['id'];
                }
            }
        }
        return app('json')->success($userLabelServices->get_tree_children($data, $label));
    }
}
