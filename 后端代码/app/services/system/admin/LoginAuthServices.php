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

namespace app\services\system\admin;


use app\services\BaseServices;
use mohe\services\CacheService;
use think\exception\ValidateException;

/**
 * 密码、登录次数验证
 * Class AdminAuthServices
 * @package app\services\system\admin
 */
class LoginAuthServices extends BaseServices
{


	/**
	 * 获取登录验证缓存Key
	 * @param string $account
	 * @param string $type
	 * @return string
	 */
	public function getAuthCacheKey(string $account, string $type = 'admin')
	{
		return 'system_' . $type . '_login_error_num_' . $account;
	}

	/**
	 * 验证登录账号是否需要锁定
	 * @param string $account
	 * @param string $type
	 * @return bool
	 */
	public function checkErrorLock(string $account, string $type = 'admin')
	{
		if ($this->getErrorNum($account, $type) >= (int)sys_config('system_login_error_num', 3)) {
			throw new ValidateException('您输入的错误次数较多，已被暂时锁定，请稍后再试');
		}
		return true;
	}

	/**
	 * 获取登录错误次数
	 * @param string $account
	 * @param string $type
	 * @return int
	 */
	public function getErrorNum(string $account, string $type = 'admin')
	{
		/** @var CacheService $cacheServices */
		$cacheServices = app()->make(CacheService::class);
		return (int)$cacheServices->get($this->getAuthCacheKey($account, $type));
	}

	/**
	 * 设置登录错误次数缓存
	 * @param string $account
	 * @param string $type
	 * @return bool
	 */
	public function setErrorNum(string $account, string $type = 'admin')
	{
		$lockTime = (int)sys_config('system_login_lock_time', 5);
		/** @var CacheService $cacheServices */
		$cacheServices = app()->make(CacheService::class);
		$loginErrorNum = $this->getErrorNum($account, $type);
		$cacheServices::set($this->getAuthCacheKey($account, $type), $loginErrorNum + 1, $lockTime);
		return true;
	}

	/**
	 * 清掉登录错误次数
	 * @param string $account
	 * @param string $type
	 * @return int
	 */
	public function resetErrorNum(string $account, string $type = 'admin')
	{
		/** @var CacheService $cacheServices */
		$cacheServices = app()->make(CacheService::class);
		return $cacheServices->del($this->getAuthCacheKey($account, $type));
	}

	/**
	 * 验证输入密码
	 * @param string $password
	 * @return bool
	 */
	public function validatePassword(string $password)
	{
		$type = (int)sys_config('system_password_type', 3);
		$length = (int)sys_config('system_password_length', 3);
		if (!$this->checkPassword($password, $type, $length)) {
			throw new ValidateException($this->getMessage($type, $length));
		}
		return true;
	}

	/**
	 * 验证登录密码
	 * @param string $password
	 * @param int $type
	 * @param int $length
	 * @return false|int
	 */
	function checkPassword(string $password, int $type = 3, int $length = 6)
	{
		switch ($type) {
			case 1://纯数字
				$regex = '/^\d{' . $length . ',}$/';
				break;
			case 2://纯字母
				$regex = '/^[A-Za-z]{' . $length . ',}$/';
				break;
			case 3://数字+纯字母
				$regex = '/^(?=.*[A-Za-z])(?=.*\d)[A-Za-z\d]{' . $length . ',}$/';
				break;
			case 4://数字+纯字母+特殊符号
				$regex = '/^(?=.*[A-Za-z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{' . $length . ',}$/';
				break;
			default:
				$regex = '/^(?=.*[A-Za-z])(?=.*\d)[A-Za-z\d]{' . $length . ',}$/';
				break;
		}
		return preg_match($regex, $password);
	}

	/**
	 * 获取验证密码提示语
	 * @param int $type
	 * @param int $length
	 * @return string
	 */
	public function getMessage(int $type = 3, int $length = 6)
	{
		switch ($type) {
			case 1://纯数字
				$desc = '纯数字';
				break;
			case 2://纯字母
				$desc = '纯字母';
				break;
			case 3://数字+纯字母
				$desc = '数字+纯字母';
				break;
			case 4://数字+纯字母+特殊符号
				$desc = '数字+纯字母+特殊符号';
				break;
			default:
				$desc = '数字+纯字母';
				break;
		}
		return '密码必须由：' . $desc . '组成,最小' . $length . '位';
	}






}
