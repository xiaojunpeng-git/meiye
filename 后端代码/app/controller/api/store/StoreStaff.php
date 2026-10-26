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
namespace app\controller\api\store;


use app\model\store\SystemStore;
use app\model\yeji\CashType;
use app\Request;
use app\services\order\agent\AgentOrderServices;
use app\services\order\OtherOrderServices;
use app\services\order\store\BranchOrderServices;
use app\services\other\QrcodeServices;
use app\services\report\ReportServices;
use app\services\store\SystemStoreServices;
use app\services\store\SystemStoreStaffServices;
use app\services\system\SystemMenusServices;
use app\services\system\SystemRoleServices;
use app\services\user\UserCardServices;
use app\services\user\UserRechargeServices;
use app\services\user\UserSpreadServices;
use app\services\wechat\WechatCardServices;
use app\services\yeji\SatffYejiServices;
use mohe\services\CacheService;
use mohe\services\DownloadImageService;
use mohe\services\wechat\OfficialAccount;
use think\db\exception\DbException;
use think\Response;


/**
 * 店员
 * Class StoreStaff
 * @package app\controller\api\store
 */
class StoreStaff
{
    /**
     * @var SystemStoreStaffServices
     */
    protected $services;

    /**
     * @var int
     */
    protected $uid;
    /**
     * 门店店员信息
     * @var array
     */
    protected $staffInfo;
    /**
     * 门店id
     * @var int|mixed
     */
    protected $store_id;

    /**
     * 门店店员ID
     * @var int|mixed
     */
    protected $staff_id;

    /**
     * 构造方法
     * StoreStaff constructor.
     * @param SystemStoreStaffServices $services
     */
    public function __construct(SystemStoreStaffServices $services, Request $request)
    {
        $this->services = $services;
        $this->uid = (int)$request->uid();
        $this->staffInfo = $services->getStaffInfoByUid($this->uid);
        if(!empty($this->staffInfo)){
            $this->staffInfo=$this->staffInfo->toArray();
        }else{
            $this->staffInfo=[];
        }
        $this->store_id = (int)($this->staffInfo['store_id'] ?? 0);
        $this->staff_id = (int)($this->staffInfo['id'] ?? 0);
    }

    //员工业绩表 类型1销售业绩 2耗卡业绩
    public function yejiRanking(Request $request,SatffYejiServices $service){
        $where = $request->getMore([
            ['data', '', '', 'created_time'],
            ['sum_type',1],
            ['store_id',''],
        ]);
        if(empty($where['store_id'])){
            $where['store_id']=$this->store_id;
        }
        $result=$service->yejiRanking($where);
        return app('json')->success($result);
    }

    //获取点客
    public function dianke(Request $request,SatffYejiServices $service){
        $where = $request->getMore([
            ['data', '', '', 'created_time'],
            ['store_id',''],
        ]);
        if(empty($where['store_id'])){
            $where['store_id']=$this->store_id;
        }
        $where['sum_type']=2;
        $result=$service->dianke($where);
        return app('json')->success($result);
    }

    //员工提成明细
    public function detailYeji(Request $request,SatffYejiServices $service){
        $where = $request->getMore([
            ['data', '', '', 'created_time'],
            ['staff_id',''],
            ['sum_type',1]
        ]);
        if(empty($where['staff_id'])){
            $where['staff_id']=$this->staff_id;
        }
        $result=$service->detailYeji($where);
        return app('json')->success($result);
    }

    //业绩信息
    public function yejiInfo(Request $request,SatffYejiServices $service)
    {
        $where = $request->getMore([
            ['data', '', '', 'created_time'],
            ['staff_id',''],
            ['sum_type',1]
        ]);
        if(empty($where['staff_id'])){
            $where['staff_id']=$this->staff_id;
        }
        $result = $service->staffInfo($where);
        return app('json')->success($result);
    }

    //店长顶部数据
    public function homeStatics(Request $request, AgentOrderServices $agentOrderServices)
    {
        $where = $request->getMore([
            ['data', '', '', 'time'],
            ['store_id',''],
        ]);
        if(empty($where['store_id'])){
            $where['store_id']=$this->store_id;
        }
        $result = $agentOrderServices->homeStatics($where);
        $res['store_name']=SystemStore::where("id",$where['store_id'])->value("name");
        $res['head']=$result;
        return app('json')->success($res);
    }
    /**
     * 订单列表
     * @param Request $request
     * @return mixed
     */
    public function orderData(Request $request, BranchOrderServices $orderServices)
    {
        $where = $request->getMore([
            ['data', '', '', 'time'],
            ['store_id','']
        ]);
        if(empty($where['store_id'])){
            $where['store_id']=$this->store_id;
        }
        $where['time'] = $orderServices->timeHandle($where['time']);
        //旧卡录入跟余额的不要
        $result=[];
        $data=CashType::where("id","<>",9)->select();
        foreach ($data as $nk=>$nv){
            $where['cash_choose']=$nv['id'];
            $one['name']=$nv['name'];
            $one['total_money']=$orderServices->reportOrder($where);
            $result[]=$one;
        }
        return app('json')->success($result);
    }
    /**
     * 获取店员信息
     * @return mixed
     */
    public function info(SystemStoreServices $storeServices, SystemMenusServices $menusServices, SystemRoleServices $roleServices)
    {
		$staffInfo = $this->staffInfo;
		$staffInfo['store_info'] = $storeServices->getOne(['id' => $this->store_id], 'product_category_status,name');
		$data = $storeServices->getStoreQrcode((int)$this->store_id, (int)$this->staff_id);
		$staffInfo = array_merge($staffInfo, $data);
		$mallMenus = [];
		if ($staffInfo['roles']) {//绑定角色
			$roleIds = is_string($staffInfo['roles']) ? explode(',', $staffInfo['roles']) : $staffInfo['roles'];
			$menusIds = $roleServices->getRoleIds($roleIds, 'mall_rules');
			if ($menusIds) {//有绑定移动端权限
				$mallMenusAll = $menusServices->getMallMenus(1);
				foreach ($mallMenusAll as $item) {
					if (in_array($item['id'], $menusIds)) $mallMenus[] = $item;
				}
			}
		}
		$staffInfo['mall_menus'] = $mallMenus;
		$uniqueAuths = $mallMenus ? array_column($mallMenus, 'unique_auth') : [];
		$reservationAuths = [
			'mall-admin-reservation',
			'mall-admin-reservation-list',
			'mall-admin-reservation-detail',
			'mall-admin-reservation-start',
			'mall-admin-reservation-end',
		];
		// 店长（含历史管家）默认拥有预约管理移动端权限
		if (\app\services\store\SystemStoreStaffServices::staffIsManager($staffInfo)) {
			$uniqueAuths = array_values(array_unique(array_merge($uniqueAuths, $reservationAuths)));
		} else {
			$uniqueAuths = array_values(array_diff($uniqueAuths, $reservationAuths));
		}
		$staffInfo['mall_unique_auth'] = $uniqueAuths;
		$staffInfo = \app\services\store\SystemStoreStaffServices::presentStaffManagerFlags($staffInfo);
        return app('json')->success($staffInfo);
    }

    /**
     * 工作台数据
     * @param Request $request
     * @return Response
     * @throws DbException
     */
    public function stagingData(Request $request)
    {
        [$is_manager, $time] = $request->getMore([
            ['is_manager', 0],
            ['data', '', '', 'time'],
        ], true);
        if (!$is_manager || !$this->staffInfo['is_manager']) {
            $is_manager = 0;
        }
        $store_id = $this->store_id;
        $staff_id = $is_manager ? 0 : $this->staff_id;
        return app('json')->successful($this->services->getStagingData($store_id, $staff_id));
    }


    /**
     * 店员｜门店数据统计
     * @param Request $request
     * @return mixed
     */
    public function statistics(Request $request)
    {
        [$is_manager, $time] = $request->getMore([
            ['is_manager', 0],
            ['data', '', '', 'time'],
        ], true);
        if (!$is_manager || !$this->staffInfo['is_manager']) {
            $is_manager = 0;
        }
        $store_id = $this->store_id;
        $staff_id = $is_manager ? 0 : $this->staff_id;
        $data = $this->services->getStoreData($this->uid, $store_id, $staff_id, $time);
        return app('json')->successful($data);
    }

    /**
     * 统计图表
     * @param Request $request
     * @param $type
     * @return Response
     * @throws DbException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function timeChart(Request $request, $type = 1)
    {
        [$is_manager, $time] = $request->getMore([
            ['is_manager', 0],
            ['time', 'today'],
        ], true);
        [$start, $end, $timeType, $timeKey] = $this->services->timeHandle($time, true);
        if (!$is_manager || !$this->staffInfo['is_manager']) {
            $is_manager = 0;
        }
        $staff_id = (int)($is_manager ? 0 : $this->staff_id);
        $store_id = (int)$this->store_id;
        switch ($type) {
            case 1://销售额
            case 2://配送订单金额
            case 3://配送订单数量
            case 4://退款订单金额
            case 5://收银订单金额
            case 6://核销订单金额
                /** @var BranchOrderServices $order */
                $order = app()->make(BranchOrderServices::class);
                $list = $order->time($store_id, $staff_id, $type, [$start, $end], $timeType);
                break;
            case 7://付费会员
                /** @var OtherOrderServices $otherOrder */
                $otherOrder = app()->make(OtherOrderServices::class);
                $list = $otherOrder->time($store_id, $staff_id, [$start, $end], $timeType);
                break;
            case 8://储值
                /** @var UserRechargeServices $userRecharge */
                $userRecharge = app()->make(UserRechargeServices::class);
                $list = $userRecharge->time($store_id, $staff_id, [$start, $end], $timeType);
                break;
            case 9://推广用户
                /** @var UserSpreadServices $userSpread */
                $userSpread = app()->make(UserSpreadServices::class);
                $list = $userSpread->time($this->store_id, $staff_id, [$start, $end], $timeType);
                break;
            case 10://激活会员卡
                /** @var UserCardServices $userCard */
                $userCard = app()->make(UserCardServices::class);
                $list = $userCard->time($this->store_id, $staff_id, [$start, $end], $timeType);
                break;
            default:
                return app('json')->fail('没有此类型');
        }
        $result = [];
        if ($list) {
            $list = array_combine(array_column($list, 'day'), $list);
        }
        foreach ($timeKey as $value) {
            $key = in_array($type, [3, 9, 10]) ? 'count' : 'price';
            $result[] = ['time' => $time == 'month' ? date('d', strtotime($value)) : $value, 'number' => $list[$value][$key] ?? 0, 'price' => in_array($type, [3, 9, 10]) ? 0 : $list[$value]['price'] ?? 0, 'num' => $list[$value]['count'] ?? 0];
        }
        return app('json')->successful($result);
    }


    /**
     * 店员｜门店统计详情页列表
     * @param Request $request
     * @param $type
     * @return Response
     * @throws DbException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function data(Request $request, $type)
    {
        [$is_manager, $time] = $request->getMore([
            ['is_manager', 0],
            ['time', 'today'],
        ], true);
        [$start, $end, $timeType, $timeKey] = $time = $this->services->timeHandle($time, true);
        if (!$is_manager || !$this->staffInfo['is_manager']) {
            $is_manager = 0;
        }
        $staff_id = (int)($is_manager ? 0 : $this->staff_id);
        $store_id = (int)$this->store_id;
        $timeType = 'day';
        switch ($type) {
            case 1://销售额
            case 2://配送订单金额
            case 3://配送订单数量
            case 4://退款订单金额
            case 5://收银订单金额
            case 6://核销订单金额
                /** @var BranchOrderServices $order */
                $order = app()->make(BranchOrderServices::class);
                $list = $order->time($store_id, $staff_id, $type, [$start, $end], $timeType);
                break;
            case 7://付费会员
                /** @var OtherOrderServices $otherOrder */
                $otherOrder = app()->make(OtherOrderServices::class);
                $list = $otherOrder->time($store_id, $staff_id, [$start, $end], $timeType);
                break;
            case 8://储值
                /** @var UserRechargeServices $userRecharge */
                $userRecharge = app()->make(UserRechargeServices::class);
                $list = $userRecharge->time($this->store_id, $staff_id, [$start, $end], $timeType);
                break;
            case 9://推广用户
                /** @var UserSpreadServices $userSpread */
                $userSpread = app()->make(UserSpreadServices::class);
                $list = $userSpread->time($this->store_id, $staff_id, [$start, $end], $timeType);
                break;
            case 10://激活会员卡
                /** @var UserCardServices $userCard */
                $userCard = app()->make(UserCardServices::class);
                $list = $userCard->time($this->store_id, $staff_id, [$start, $end], $timeType);
                break;
            default:
                return app('json')->fail('没有此类型');
        }
        return app('json')->success($list);
    }


    /**
     * 店员推广员
     * @param Request $request
     * @param $id
     * @return mixed
     */
    public function code(Request $request, WechatCardServices $cardServices)
    {
        $wechatCard = $cardServices->get(['card_type' => 'member_card', 'status' => 1, 'is_del' => 0]);
        $uid = (int)$request->uid();
		$site_url = sys_config('site_url');
        if ($wechatCard && !$request->isApp()) {
            $key = $wechatCard['card_id'] . '_cart_code_' . $uid;
            $data = CacheService::get($key);
            if (!$data) {
                $result = OfficialAccount::getCardQRCode($wechatCard['card_id'], $uid);
				$url = $result['show_qrcode_url'] ?? '';
				if ($url && strpos($url, 'weixin.qq.com')) {//远程图片
					/** @var DownloadImageService $download */
					$download = app()->make(DownloadImageService::class);
					$downloadUrl = $download->path('qrcode')->downloadImage($url, $key. '.png')['path'] ?? '';
					if ($downloadUrl) $url = $site_url . $downloadUrl;
				}
                $data = [
                    'url' => $url,
                    'show_qrcode_url' => $url
                ];
                CacheService::set($key, $data, 1500);
            }
        } else {
            $valueData = 'spread=' . $uid . '&spid=' . $uid;
            $url = $site_url ? $site_url . '?' . $valueData : '';
            if (request()->isRoutine()) {
				$name = $uid . '_store_share_routine.jpg';
				/** @var QrcodeServices $QrcodeService */
				$QrcodeService = app()->make(QrcodeServices::class);
				//生成小程序地址
				$url = $QrcodeService->getRoutineQrcodePath(0, $uid, -1, $name);
            }
            $data = [
                'url' => $url,
                'show_qrcode_url' => $url
            ];
        }
        return app('json')->success($data);
    }


    //工资信息
    public function salary(Request $request,ReportServices $services){
        $date=$request->get("date");
        $staff_id=$request->get("staff_id");
        if(empty($date)){
            $date=date("Y-m");
        }
        if(empty($staff_id)){
            $staff_id=$this->staff_id;
        }
        $date=strtotime($date);
        $info=$services->salaryOne($staff_id,$date);
        return app('json')->success($info);
    }

    /**
     * 老师中心信息
     */
    public function teacherCenter()
    {
        if (!$this->staffInfo) {
            return app('json')->fail('非门店员工');
        }
        $data = $this->services->getTeacherCenterInfo($this->staffInfo);
        /** @var \app\services\order\StoreReservationOrderServices $reservationServices */
        $reservationServices = app()->make(\app\services\order\StoreReservationOrderServices::class);
        $where = ['store_id' => $this->store_id, 'service_staff_id' => $this->staff_id];
        $data['order_stats'] = $reservationServices->getTeacherOrderStatistics($where);
        return app('json')->success($data);
    }

    /**
     * 更新老师中心资料
     */
    public function updateTeacherCenter(Request $request)
    {
        if (!$this->staffInfo) {
            return app('json')->fail('非门店员工');
        }
        $data = $request->postMore([
            [['is_reservable', 'd'], -1],
            ['off_work_time', ''],
            ['staff_intro', ''],
        ]);
        $payload = [];
        if ((int)$data['is_reservable'] >= 0) {
            $payload['is_reservable'] = (int)$data['is_reservable'];
        }
        if ($data['off_work_time'] !== '') {
            $payload['off_work_time'] = $data['off_work_time'];
        }
        if ($data['staff_intro'] !== '') {
            $payload['staff_intro'] = $data['staff_intro'];
        }
        $this->services->updateTeacherProfile($this->staff_id, $this->store_id, $payload);
        return app('json')->success('保存成功');
    }

    /**
     * 老师休息列表
     */
    public function teacherRestList(Request $request)
    {
        if (!$this->staffInfo) {
            return app('json')->fail('非门店员工');
        }
        [$page, $limit] = $request->getMore([
            [['page', 'd'], 1],
            [['limit', 'd'], 20],
        ], true);
        $result = $this->services->getTeacherRestList($this->store_id, $this->staff_id, (int)$page, (int)$limit);
        return app('json')->success($result);
    }

    /**
     * 老师休息可选班次
     */
    public function teacherRestShiftOptions(Request $request)
    {
        if (!$this->staffInfo) {
            return app('json')->fail('非门店员工');
        }
        $list = $this->services->getTeacherRestShiftOptions($this->store_id);
        return app('json')->success(['list' => $list]);
    }

    /**
     * 添加老师休息
     */
    public function teacherRestSave(Request $request)
    {
        if (!$this->staffInfo) {
            return app('json')->fail('非门店员工');
        }
        $data = $request->postMore([
            ['date', ''],
            ['schedule_date', ''],
            [['type', 'd'], 0],
            [['shift_id', 'd'], 0],
            [['is_full_day', 'd'], 0],
            ['remark', ''],
        ]);
        $this->services->saveTeacherRest($this->store_id, $this->staff_id, $data);
        return app('json')->success('添加成功');
    }

    /**
     * 删除老师休息
     */
    public function teacherRestDelete($id)
    {
        if (!$this->staffInfo) {
            return app('json')->fail('非门店员工');
        }
        $this->services->deleteTeacherRest($this->store_id, $this->staff_id, (int)$id);
        return app('json')->success('删除成功');
    }
}
