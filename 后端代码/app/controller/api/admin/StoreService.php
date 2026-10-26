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
namespace app\controller\api\admin;


use app\Request;
use app\services\message\service\StoreServiceServices;
use app\services\order\OtherOrderServices;
use app\services\order\store\BranchOrderServices;
use app\services\other\QrcodeServices;
use app\services\store\SystemStoreServices;
use app\services\store\SystemStoreStaffServices;
use app\services\system\SystemMenusServices;
use app\services\system\SystemRoleServices;
use app\services\user\UserCardServices;
use app\services\user\UserRechargeServices;
use app\services\user\UserSpreadServices;
use app\services\wechat\WechatCardServices;
use mohe\services\CacheService;
use mohe\services\DownloadImageService;
use mohe\services\wechat\OfficialAccount;
use think\db\exception\DbException;
use think\Response;


/**
 * 客服
 * Class StoreService
 * @package app\controller\api\admin
 */
class StoreService
{
    /**
     * @var StoreServiceServices
     */
    protected $services;

    /**
     * @var int
     */
    protected $uid;

    /**
     * 客服信息
     * @var array
     */
    protected $serviceInfo;

    /**
     * 客服ID
     * @var int|mixed
     */
    protected $service_id;

	/**
	 * 构造方法
	 * @param StoreServiceServices $services
	 * @param Request $request
	 */
    public function __construct(StoreServiceServices $services, Request $request)
    {
        $this->services = $services;
        $this->uid = (int)$request->uid();
        $this->serviceInfo = $services->getServiceInfoByUid($this->uid);
        $this->service_id = (int)($this->serviceInfo['id'] ?? 0);
    }

	/**
	 * 获取
	 * @param SystemMenusServices $menusServices
	 * @param SystemRoleServices $roleServices
	 * @return Response
	 */
    public function info(SystemMenusServices $menusServices, SystemRoleServices $roleServices)
    {
		$serviceInfo = $this->serviceInfo;
		$mallMenus = [];
		if ($serviceInfo && $serviceInfo['customer']) {//客服存在 && 有移动端管理权限
			if ($serviceInfo['roles']) {//绑定角色
				$roleIds = is_string($serviceInfo['roles']) ? explode(',', $serviceInfo['roles']) : $serviceInfo['roles'];
				$menusIds = $roleServices->getRoleIds($roleIds, 'mall_rules');
				if ($menusIds) {//有绑定移动端权限
					$mallMenusAll = $menusServices->getMallMenus();
					foreach ($mallMenusAll as $item) {
						if (in_array($item['id'], $menusIds)) $mallMenus[] = $item;
					}
				}
			}
		}
		$serviceInfo['mall_menus'] = $mallMenus;
		$serviceInfo['mall_unique_auth'] = $mallMenus ? array_column($mallMenus, 'unique_auth') : [];

        return app('json')->success($serviceInfo);
    }



}
