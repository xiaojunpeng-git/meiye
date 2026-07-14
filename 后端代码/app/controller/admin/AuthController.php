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


use app\Request;
use mohe\basic\BaseController;

/**
 * 基类 所有控制器继承的类
 * Class AuthController
 * @package app\controller\admin
 * @property Request $request
 * @property $services
 * @method success($message = '',array $data = [])
 * @method fail($message = '',array $data = [])
 */
class AuthController extends BaseController
{
    /**
     * 当前登录管理员信息
     * @var
     */
    protected $adminInfo;

    /**
     * 当前登录管理员ID
     * @var
     */
    protected $adminId;

	/**
	 * 当前登录管理员代理商ID
	 * @var
	 */
	protected $agentId;

	/**
	 * 当前登录管理员类型
	 * @var
	 */
	protected $adminType;

    /**
     * 当前管理员权限
     * @var array
     */
    protected $auth = [];

    /**
     * 初始化
     */
    protected function initialize()
    {
        $this->adminId = $this->request->hasMacro('adminId') ? intval($this->request->adminId()) : 0;
        $this->adminInfo = $this->request->hasMacro('adminInfo') ? $this->validateAdminInfo($this->request->adminInfo()) : [];
		$this->adminType = $this->adminInfo['admin_type'] ?? 0;
		$this->agentId = $this->adminType == 3 ? ($this->adminInfo['relation_id'] ?? 0) : 0;
        $this->auth = is_array($this->adminInfo['rule'] ?? null) ? $this->adminInfo['rule'] : [];
    }

    /**
     * 验证当前登录管理员信息
     * @param array $adminInfo
     * @return array
     */
    protected function validateAdminInfo(array $adminInfo): array
    {
        if (empty($adminInfo) || !isset($adminInfo['id']) || $adminInfo['id'] != $this->adminId) {
            return [];
        }
        return $adminInfo;
    }
}
