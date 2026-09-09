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
namespace app\controller\admin;

use app\services\community\CommunityCommentServices;
use app\services\community\CommunityServices;
use app\services\order\StoreOrderRefundServices;
use app\services\other\CityAreaServices;
use app\services\system\SystemAuthServices;
use app\services\order\StoreOrderServices;
use app\services\product\product\StoreProductServices;
use app\services\product\product\StoreProductReplyServices;
use app\services\system\SystemRoleServices;
use app\services\system\SystemUserApplyServices;
use app\services\user\UserExtractServices;
use app\services\system\SystemMenusServices;
use app\services\user\UserServices;
use mohe\services\CacheService;
use mohe\services\SystemConfigService;

/**
 * 公共接口基类 主要存放公共接口
 * Class Common
 * @package app\controller\admin
 */
class Common extends AuthController
{
    /**
     * 获取logo
     * @param SystemConfigService $services
     * @return mixed
     */
    public function getLogo(SystemConfigService $services)
    {
        $data = $services->more(['site_logo', 'site_logo_square', 'site_name']);
        return $this->success($data);
    }

    /**
     * @return mixed
     */
    public function check_auth()
    {
        return $this->checkAuthDecrypt();
    }

    /**
     * @return mixed
     */
    public function auth()
    {
        return $this->getAuth();
    }

    /**
     * 查询购买版权
     * @return mixed
     */
    public function mohe_copyright()
    {
        $this->__6j3nfcwmWqrsDx8F0MjZGeQyWvLsqeFXww();
        return $this->success('查询成功');
    }

    /**
     * 保存版权
     * @return mixed
     */
    public function saveCopyright()
    {
        $copyright = $this->request->post('copyright');
        $copyrightImg = $this->request->post('copyright_img');

        $this->__qsG71NREI01vix2OkjH($copyright, $copyrightImg);

        return $this->success('保存成功');
    }

    /**
     * 获取版权
     * @return mixed
     */
    public function getCopyright()
    {
        try {
            $copyright = $this->__z6uxyJQ4xYa5ee1mx5();
        } catch (\Throwable $e) {
            $copyright = ['copyrightContext' => '', 'copyrightImage' => ''];
        }
        $copyright['version'] = get_mohe_version();
        return $this->success($copyright);
    }

    /**
     * 申请授权
     * @return mixed
     */
    public function auth_apply(SystemAuthServices $services)
    {
        $version = get_mohe_version();
        $data = $this->request->postMore([
            ['company_name', ''],
            ['domain_name', ''],
            ['order_id', ''],
            ['phone', ''],
            ['label', strripos($version, 'min') === false ? 3 : 2],
            ['captcha', ''],
        ]);
        if (!$data['company_name']) {
            return $this->fail('请填写公司名称');
        }
        if (!$data['domain_name']) {
            return $this->fail('请填写授权域名');
        }

        if (!$data['phone']) {
            return $this->fail('请填写手机号码');
        }
        if (!$data['order_id']) {
            return $this->fail('请填写订单id');
        }
        $datas = explode('.', $data['domain_name']);
        $n = count($datas);
        $preg = '/[\w].+\.(com|net|org|gov|edu)\.cn$/';
        if (($n > 2) && preg_match($preg, $data['domain_name'])) {
            //双后缀取后3位
            $domain_name = $datas[$n - 3] . '.' . $datas[$n - 2] . '.' . $datas[$n - 1];
        } else {
            //非双后缀取后两位
            $domain_name = $datas[$n - 2] . '.' . $datas[$n - 1];
        }
        $sec = trim(str_replace($domain_name, '', $data['domain_name']), '.');
        if ($sec) {
            if ($sec == 'www') {
                $data['domain_name'] = $domain_name;
            }
        }
        $header = $this->__k0dUcnKjRUs9lfEllqO9J($data['phone']);
        if ($header) {
            $headerData = ['Authori-zation:Bearer ' . $this->__k0dUcnKjRUs9lfEllqO9J($data['phone'])];
        } else {
            $headerData = false;
        }
        $services->authApply($data, $headerData);
        return $this->success("申请授权成功!");

    }

    /**
     * 首页头部统计数据
     * @return mixed
     */
    public function homeStatics()
    {
        /** @var StoreOrderServices $orderServices */
        $orderServices = app()->make(StoreOrderServices::class);
        $info = $orderServices->homeStatics();
        return $this->success(compact('info'));
    }

    /**
     * 订单图表
     */
    public function orderChart()
    {
        $cycle = $this->request->param('cycle') ?: 'thirtyday';//默认30天
        /** @var StoreOrderServices $orderServices */
        $orderServices = app()->make(StoreOrderServices::class);
        $chartdata = $orderServices->orderCharts($cycle);
        return $this->success($chartdata);
    }

    /**
     * 用户图表
     */
    public function userChart()
    {
        /** @var UserServices $uServices */
        $uServices = app()->make(UserServices::class);
        $chartdata = $uServices->userChart();
        return $this->success($chartdata);
    }

    /**
     * 交易额排行
     * @return mixed
     */
    public function purchaseRanking()
    {
//        /** @var StoreProductAttrValueServices $valueServices */
//        $valueServices = app()->make(StoreProductAttrValueServices::class);
//        $list = $valueServices->purchaseRanking();
        $list = [];
        return $this->success(compact('list'));
    }

    /**
     * 待办事统计
     * @return mixed
     */
    public function jnotice()
    {
		$data = [];
        /** @var StoreOrderServices $orderServices */
        $orderServices = app()->make(StoreOrderServices::class);
		//订单待发货
        $data['unshippedOrder'] = $orderServices->count(['plat_type' => 0, 'pid' => 0, 'status' => 1]);
        /** @var StoreProductServices $storeServices */
		$StoreProductServices = app()->make(StoreProductServices::class);
        /** @var StoreProductReplyServices $replyServices */
        $replyServices = app()->make(StoreProductReplyServices::class);
		//评论待回复
        $data['unReplyComment'] = $replyServices->replyCount();
        /** @var UserExtractServices $extractServices */
        $extractServices = app()->make(UserExtractServices::class);
		//提现待审核
        $data['unVerifyExtract'] = $extractServices->userExtractCount();//提现
        $newOrderId = $orderServices->newOrderId(1);
        /** @var StoreOrderRefundServices $refundServices */
        $refundServices = app()->make(StoreOrderRefundServices::class);
		//售后订单待处理
		$data['unVerifyRefundOrder'] = $refundServices->count(['plat_type' => 0, 'is_cancel' => 0, 'refund_type' => [0, 1, 2, 4, 5], 'store_id' => 0]);
        if (count($newOrderId)) $orderServices->update([['order_id', 'IN', $newOrderId]], ['is_remind' => 1]);
		//待审核商品
		$data['unVerifyProduct'] = $StoreProductServices->getCount(['status' => 0, 'pid' => 0]);
		//已经售馨商品
		$data['outofStockProduct'] = $StoreProductServices->getCount(['type' => 0, 'status' => 4, 'pid' => 0]);
		//警戒库存商品
		$store_stock = sys_config('store_stock', 0);
		$data['policeForceProduct'] = $StoreProductServices->getCount(['type' => 0, 'status' => 5, 'pid' => 0, 'store_stock' => $store_stock > 0 ? $store_stock : 2]);
		//供应商申请
		/** @var SystemUserApplyServices $userApplyServices */
		$userApplyServices = app()->make(SystemUserApplyServices::class);
		$data['unVerifySupplier'] = $userApplyServices->count(['type' => 2, 'status' => 0, 'is_del' => 0]);
		//门店申请
		$data['unVerifyStore'] = $userApplyServices->count(['type' => 1, 'status' => 0, 'is_del' => 0]);
		//社区内容
		/** @var CommunityServices $communityServices */
		$communityServices = app()->make(CommunityServices::class);
		$data['unVerifyCommunity'] = $communityServices->count(['is_verify' => 0, 'is_del' => 0]);
		//社区评论
		/** @var CommunityCommentServices $communityCommentServices */
		$communityCommentServices = app()->make(CommunityCommentServices::class);
		$data['unVerifyCommunityComment'] = $communityCommentServices->count(['is_verify' => 0, 'is_del' => 0]);
		/** @var \app\services\product\inventory\StoreStockRequestServices $stockRequestServices */
		$stockRequestServices = app()->make(\app\services\product\inventory\StoreStockRequestServices::class);
		$data['unHandleStockRequest'] = $stockRequestServices->pendingSupplyCount(0);
		return $this->success($data);
    }

    /**
     * 消息返回格式
     * @param array $data
     * @return array
     */
    public function noticeData(array $data): array
    {
        // 消息图标
        $iconColor = [
            // 邮件 消息
            'mail' => [
                'icon' => 'md-mail',
                'color' => '#3391e5'
            ],
            // 普通 消息
            'bulb' => [
                'icon' => 'md-bulb',
                'color' => '#87d068'
            ],
            // 警告 消息
            'information' => [
                'icon' => 'md-information',
                'color' => '#fe5c57'
            ],
            // 关注 消息
            'star' => [
                'icon' => 'md-star',
                'color' => '#ff9900'
            ],
            // 申请 消息
            'people' => [
                'icon' => 'md-people',
                'color' => '#f06292'
            ],
        ];
        // 消息类型
        $type = array_keys($iconColor);
        // 默认数据格式
        $default = [
            'icon' => 'md-bulb',
            'iconColor' => '#87d068',
            'title' => '',
            'url' => '',
            'type' => 'bulb',
            'read' => 0,
            'time' => 0
        ];
        $value = [];
        foreach ($data as $item) {
            $val = array_merge($default, $item);
            if (isset($item['type']) && in_array($item['type'], $type)) {
                $val['type'] = $item['type'];
                $val['iconColor'] = $iconColor[$item['type']]['color'] ?? '';
                $val['icon'] = $iconColor[$item['type']]['icon'] ?? '';
            }
            $value[] = $val;
        }
        return $value;
    }

    /**
     * 格式化菜单
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function menusList(SystemMenusServices $menusServices, SystemRoleServices $roleServices)
    {
		$adminType = $this->adminType;
		$where = [];
		if ($this->adminInfo['level'] || $this->adminType == 3) {//子管理员 || 区域代理商
			$roles = $this->adminInfo['roles'];

			$roles = is_string($roles) ? explode(',', $roles) : $roles;
			$ids = $roleServices->getRoleIds($roles);
			$where = ['rule' => $ids];
		}
		// 菜单树受角色规则裁剪，缓存键必须包含规则集合；否则同一管理员类型
		// 的不同角色会命中彼此的菜单缓存，造成入口越权或缺失。
		$ruleIds = $where['rule'] ?? [];
		$ruleIds = is_array($ruleIds) ? $ruleIds : [$ruleIds];
		sort($ruleIds, SORT_NUMERIC);
		$cahcheKey = md5('admin_common_menu_list_v2_' . $adminType . '_' . implode(',', $ruleIds));
		$list = CacheService::redisHandler('system_menus')->remember($cahcheKey, function () use ($where, $menusServices) {

			$menus = $menusServices->getSearchList(1, $where);
			$counts = $menusServices->getColumn([
				['is_show', '=', 1],
				['auth_type', '=', 1],
				['is_del', '=', 0],
				['is_show_path', '=', 0],
			], 'pid');
			$data = [];
			foreach ($menus as $key => $item) {
				$pid = $item->getData('pid');
				$data[$key] = json_decode($item, true);
				$data[$key]['pid'] = $pid;
				if (in_array($item->id, $counts)) {
					$data[$key]['type'] = 1;
				} else {
					$data[$key]['type'] = 0;
				}
				$data[$key]['menu_path'] = preg_replace('/^\/admin/', '', $item['menu_path']);
			}
			return sort_list_tier($data);
		});
        // Apply account ownership after the shared role cache, never inside it.
        $list = \app\services\ai\management\AiManagementMenuPolicy::raw($list, is_array($this->adminInfo) ? $this->adminInfo : $this->adminInfo->toArray());
        return app('json')->success($list);
    }

    /**
     * @param CityAreaServices $services
     * @return mixed
     */
    public function city(CityAreaServices $services)
    {
		[$pid, $level] = $this->request->postMore([
			['pid', 0],
			['level', 3],
		], true);
        return $this->success($services->getCityTreeList((int)$pid, (int)$level));
    }

	/**
	 * 解析（获取导入地图城市地址）
	 * @param CityAreaServices $services
	 * @return \think\Response
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function resolveCityList(CityAreaServices $services)
	{
		$address = $this->request->param('address', '');
		if (!$address)
			return app('json')->fail('地址不存在');
		if (strpos($address, '/') === false) {
			$address = implode('/', array_values($services->addressHandle($address)));
		}
		$city = $services->searchCity(compact('address'));
		if (!$city) return app('json')->fail('地址暂未录入，请联系管理员');
		$where = [['id', 'in', array_merge([$city['id']], explode('/', trim($city->path, '/')))]];
		return app('json')->success($services->getCityList($where, 'id as value,id,name as label,parent_id as pid', ['children']));
	}
}
