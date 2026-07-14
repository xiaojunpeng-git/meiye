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

namespace app;

use mohe\traits\MacroTrait;
use Spatie\Macroable\Macroable;

/**
 * Class Request
 * @package app
 * @method tokenData() 获取token信息
 * @method user(string $key = null) 获取用户信息
 * @method uid() 获取用户uid
 * @method isAdminLogin() 后台登录状态
 * @method adminId() 后台管理员id
 * @method adminInfo() 后台管理信息
 * @method adminType() 管理员类型
 * @method agentId() 区域代理商ID
 * @method kefuId() 客服id
 * @method kefuInfo() 客服信息
 * @method outId() 对外接口用户id
 * @method outInfo() 对外接口用户id
 * @method storeId() 门店ID
 * @method storeStaffId() 门店管理员id
 * @method storeStaffInfo() 门店管理员信息
 * @method cashierId() 门店收银员id
 * @method cashierInfo() 门店收银员信息
 * @method clientInfo() 企业微信客户信息
 * @method userid() 企业微信客户userid
 * @method supplierId() 供应商id
 * @method supplierInfo() 供应商信息
 */
class Request extends \think\Request
{

	public $tokenData; //获取token信息
	public $user; //获取用户信息
	public $uid; //获取用户uid
	public $isLogin;//
	public $isAdminLogin; //后台登录状态
	public $adminId; //后台管理员id
	public $adminInfo; //后台管理信息
	public $adminType; //管理员类型
	public $agentId; //区域代理商ID
	public $kefuId; //客服id
	public $kefuInfo; //客服信息
	public $outId; //对外接口用户id
	public $outInfo; //对外接口用户id
	public $storeId; //门店ID
	public $storeStaffId; //门店管理员id
	public $storeStaffInfo; //门店管理员信息
	public $cashierId; //门店收银员id
	public $cashierInfo; //门店收银员信息
	public $clientInfo; //企业微信客户信息
	public $userid; //企业微信客户userid
	public $supplierId; //供应商id
	public $supplierInfo; //供应商信息

	/**
	 * 登录保存参数
	 * @var array
	 */
	protected $loginParam = [
		'tokenData', 'user', 'uid', 'isLogin',
		'isAdminLogin', 'adminId', 'adminInfo', 'adminType',
		'agentId',
		'kefuId', 'kefuInfo',
		'outId', 'outInfo',
		'storeId', 'storeStaffId', 'storeStaffInfo',
		'cashierId', 'cashierInfo',
		'clientInfo', 'userid',
		'supplierId', 'supplierInfo'
	];

    /**
     * 不过滤变量名
     * @var array
     */
    protected $except = [
		'menu_path', 'api_url', 'unique_auth', 'content',
		'description', 'custom_form', 'product_detail_diy', 'value', 'member', 'product_category_diy',
		'captchaType', 'captchaVerification'
	];



	/**
	 * 获取请求的数据
	 * @param array $params
	 * @param bool $suffix
	 * @param bool $filter
	 * @return array
	 */
	public function more(array $params, bool $suffix = false, bool $filter = true): array
	{
		$p = [];
		$i = 0;
		foreach ($params as $param) {
			if (!is_array($param)) {
				$p[$suffix == true ? $i++ : $param] = $this->param($param);
			} else {
				if (!isset($param[1])) $param[1] = null;
				if (!isset($param[2])) $param[2] = '';
				if (is_array($param[0])) {
					$name = is_array($param[1]) ? $param[0][0] . '/a' : $param[0][0] . '/' . $param[0][1];
					$keyName = $param[0][0];
				} else {
					$name = is_array($param[1]) ? $param[0] . '/a' : $param[0];
					$keyName = $param[0];
				}

				$p[$suffix == true ? $i++ : ($param[3] ?? $keyName)] = $this->param($name, $param[1], $param[2]);
			}
		}

		if ($filter && $p) {
			$p = $this->filterArrayValues($p);
		}

		return $p;
	}

	/**
	 * @param $array
	 * @return array
	 */
	public function filterArrayValues($array)
	{
		$result = [];
		foreach ($array as $key => $value) {
			if (is_array($value)) {
				// 如果值是数组，递归调用 filterArrayValues
				$result[$key] = in_array($key, $this->except) ? $value : $this->filterArrayValues($value);
			} else {
				if (in_array($key, $this->except) || is_int($value) || is_null($value)) {
					$result[$key] = $value;
				} else {
					// 如果值是字符串，过滤特殊字符
					$result[$key] = filter_str($value);
				}

			}
		}
		return $result;
	}


    /**
     * 获取get参数
     * @param array $params
     * @param bool $suffix
     * @param bool $filter
     * @return array
     */
    public function getMore(array $params, bool $suffix = false, bool $filter = true): array
    {
        return $this->more($params, $suffix, $filter);
    }

    /**
     * 获取post参数
     * @param array $params
     * @param bool $suffix
     * @param bool $filter
     * @return array
     */
    public function postMore(array $params, bool $suffix = false, bool $filter = true): array
    {
        return $this->more($params, $suffix, $filter);
    }

    /**
     * 获取用户访问端
     * @return array|string|null
     */
    public function getFromType()
    {
        return $this->header('Form-type', '');
    }

    /**
     * 当前访问端
     * @param string $terminal
     * @return bool
     */
    public function isTerminal(string $terminal)
    {
        return strtolower($this->getFromType()) === $terminal;
    }

    /**
     * 是否是H5端
     * @return bool
     */
    public function isH5()
    {
        return $this->isTerminal('h5');
    }

    /**
     * 是否是微信端
     * @return bool
     */
    public function isWechat()
    {
        return $this->isTerminal('wechat');
    }

    /**
     * 是否是小程序端
     * @return bool
     */
    public function isRoutine()
    {
        return $this->isTerminal('routine');
    }

    /**
     * 是否是app端
     * @return bool
     */
    public function isApp()
    {
        return $this->isTerminal('app');
    }

    /**
     * 是否是app端
     * @return bool
     */
    public function isPc()
    {
        return $this->isTerminal('pc');
    }

    /**
     * 获取ip
     * @return string
     */
    public function ip(): string
    {
        if ($this->server('HTTP_CLIENT_IP', '')) {
            $ip = $this->server('HTTP_CLIENT_IP', '');
        } elseif ($this->server('HTTP_X_REAL_IP', '')) {
            $ip = $this->server('HTTP_X_REAL_IP', '');
        } elseif ($this->server('HTTP_X_FORWARDED_FOR', '')) {
            $ip = $this->server('HTTP_X_FORWARDED_FOR', '');
            $ips = explode(',', $ip);
            $ip = $ips[0];
        } elseif ($this->server('REMOTE_ADDR', '')) {
            $ip = $this->server('REMOTE_ADDR', '');
        } else {
            $ip = '0.0.0.0';
        }
        return $ip;
    }

	public function hasMacro(string $name): bool
	{
		return !!$this->{$name};
	}


	public function __call($method, $parameters)
	{
		if (in_array($method, $this->loginParam)) {
			$result = $this->{$method};
			if (is_callable($result)) {
				return call_user_func_array($result, $parameters);
			} else {
				return $result;
			}
		}
		return null;
	}
}
