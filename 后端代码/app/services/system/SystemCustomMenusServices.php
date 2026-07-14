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

use app\dao\system\SystemCustomMenusDao;
use app\services\BaseServices;
use app\services\other\CacheServices;

/**
 * 自定义菜单
 * Class SystemCustomMenusServices
 * @package app\services\system
 * @mixin SystemCustomMenusDao
 */
class SystemCustomMenusServices extends BaseServices
{

	/**
	 * 后台菜单快捷入口
	 * @var array
	 */
	public $adminFastMenus = [
		['id' => 1, 'name' => '订单管理', 'image' => '/statics/images/admin/quick1.png', 'icon' => 'icondingdanguanli1', 'url' => '/order/list', 'is_show' => 0],
		['id' => 2, 'name' => '售后管理', 'image' => '/statics/images/admin/quick2.png', 'icon' => 'iconshouhouguanli-01', 'url' => '/order/refund', 'is_show' => 0],
		['id' => 3, 'name' => '商品管理', 'image' => '/statics/images/admin/quick3.png', 'icon' => 'iconshangpinguanli', 'url' => '/product/product_list', 'is_show' => 0],
		['id' => 4, 'name' => '营销中心', 'image' => '/statics/images/admin/quick4.png', 'icon' => 'iconyingxiaozhongxin', 'url' => '/marketing/home', 'is_show' => 0],
		['id' => 5, 'name' => '用户管理', 'image' => '/statics/images/admin/quick5.png', 'icon' => 'iconyonghuguanli1', 'url' => '/user/list', 'is_show' => 1],
		['id' => 6, 'name' => '优惠券', 'image' => '/statics/images/admin/quick6.png', 'icon' => 'iconyouhuiquan5', 'url' => '/marketing/store_coupon_issue/index', 'is_show' => 1],
		['id' => 7, 'name' => '分销管理', 'image' => '/statics/images/admin/quick7.png', 'icon' => 'iconfenxiaoguanli-01', 'url' => '/agent/agent_manage/index', 'is_show' => 1],
		['id' => 8, 'name' => '提现申请', 'image' => '/statics/images/admin/quick8.png', 'icon' => 'icontixianshenqing-01', 'url' => '/finance/user_extract/index', 'is_show' => 0],
		['id' => 11, 'name' => '消息配置', 'image' => '/statics/images/admin/quick11.png', 'icon' => 'iconxiaoxipeizhi', 'url' => '/setting/notification/index', 'is_show' => 1],
		['id' => 12, 'name' => '文章管理', 'image' => '/statics/images/admin/quick12.png', 'icon' => 'iconwenzhangguanli-01', 'url' => '/cms/article/index', 'is_show' => 1],
		['id' => 13, 'name' => '系统设置', 'image' => '/statics/images/admin/quick13.png', 'icon' => 'iconxitongshezhi1', 'url' => '/setting/shop/base', 'is_show' => 1],
	];

    /**
     * 初始化
     * SystemCustomMenusServices constructor.
     * @param SystemCustomMenusDao $dao
     */
    public function __construct(SystemCustomMenusDao $dao)
    {
        $this->dao = $dao;
    }

	/**
	 * 获取平台后台快捷入口菜单
	 * @param int $adminId
	 * @return array
	 */
	public function getAdminFastMenus(int $adminId = 0)
	{
		/** @var CacheServices $cache */
		$cache = app()->make(CacheServices::class);
		$data = $cache->getDbCache('admin_fast_menus_' . $adminId, []);
		$default = $this->dao->getList(['admin_id' => [0, $adminId]], 'id,admin_id,name,image,url,is_show');
		$baseUrl = sys_config('site_url');
		foreach ($default as &$item) {
			$item['image'] = filter_var($item['image'], FILTER_VALIDATE_URL) ? $item['image'] : $baseUrl . $item['image'];
		}
		return ['default_menus' => $default, 'set_menus' => is_array($data) && $data ? $data : []];
	}



}
