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
declare (strict_types=1);

namespace app\services\store;

use app\Request;
use app\services\BaseServices;
use app\dao\store\SystemStoreStaffDao;
use app\model\store\SystemStoreStaff;
use app\services\system\SystemMenusServices;
use app\services\system\SystemRoleServices;
use mohe\exceptions\AdminException;
use mohe\exceptions\AuthException;
use mohe\services\CacheService;
use mohe\utils\ApiErrorCode;
use mohe\utils\JwtAuth;
use Firebase\JWT\ExpiredException;
use think\exception\ValidateException;
use think\facade\Cache;
use think\facade\Db;


/**
 *
 * Class LoginServices
 * @package app\services\user
 * @mixin SystemStoreStaffDao
 */
class LoginServices extends BaseServices
{
    /**
     * 当前门店权限缓存前缀
     */
    const STORE_RULES_LEVEL = 'store_rules_level_';

    /** 登录失败计数缓存前缀（>2 需滑块验证码） */
    const LOGIN_FAIL_CACHE_PREFIX = 'store_login_captcha_';

    /** 密码已通过、等待选店的短期放行缓存前缀（选店阶段不再复验一次性验证码） */
    const LOGIN_PWD_OK_CACHE_PREFIX = 'store_login_pwd_ok_';

    /** 选店放行有效期（秒） */
    const LOGIN_PWD_OK_TTL = 300;

    /**
     * LoginServices constructor.
     * @param SystemStoreStaffDao $dao
     */
    public function __construct(SystemStoreStaffDao $dao)
    {
        $this->dao = $dao;
    }

    public function loginFailCacheKey(string $account): string
    {
        return self::LOGIN_FAIL_CACHE_PREFIX . $account;
    }

    public function loginPwdOkCacheKey(string $account): string
    {
        return self::LOGIN_PWD_OK_CACHE_PREFIX . $account;
    }

    /**
     * 是否因失败次数需要滑块验证码
     */
    public function isCaptchaRequired(string $account): bool
    {
        $key = $this->loginFailCacheKey($account);
        return Cache::has($key) && (int)Cache::get($key) > 2;
    }

    /**
     * 登录前验证码门禁。
     * - 失败超过 2 次：未选店时必须完成一次性验证码；
     * - 密码已通过并返回门店列表后写入短期放行，选店请求不再复验已使用的验证码。
     */
    public function assertLoginCaptcha(string $account, int $storeId, string $captchaType, string $captchaVerification): void
    {
        $storeId = (int)$storeId;
        $pwdOkKey = $this->loginPwdOkCacheKey($account);
        if ($storeId > 0 && Cache::get($pwdOkKey)) {
            return;
        }

        if (!$this->isCaptchaRequired($account)) {
            return;
        }

        if ($captchaType === '' || $captchaVerification === '') {
            throw new ValidateException('请拖动滑块验证');
        }

        // 仅实调探针：RH_STORE_LOGIN_CAPTCHA_STUB=1 时接受固定口令，生产环境勿设置
        if (getenv('RH_STORE_LOGIN_CAPTCHA_STUB') === '1' && $captchaVerification === 'RH_TEST_CAPTCHA_OK') {
            return;
        }

        aj_captcha_check_two($captchaType, $captchaVerification);
    }

    /**
     * 密码校验已成功后的计数/选店放行处理（含 need_select_store）。
     */
    public function afterPasswordLoginSuccess(string $account, array $res): void
    {
        Cache::delete($this->loginFailCacheKey($account));
        if (!empty($res['need_select_store'])) {
            Cache::set($this->loginPwdOkCacheKey($account), 1, self::LOGIN_PWD_OK_TTL);
            return;
        }
        Cache::delete($this->loginPwdOkCacheKey($account));
    }

    /**
     * 获取登录前的login等信息
     * @return array
     */
    public function getLoginInfo()
    {
        return [
            'slide' => sys_data('admin_login_slide') ?? [],
            'logo_square' => sys_config('site_logo'),//透明
            'logo_rectangle' => sys_config('site_logo'),//方形
            'login_logo' => sys_config('start_login_logo'),//登录
            'site_name' => sys_config('site_name'),
            'site_url' => sys_config('site_url'),
            'upload_file_size_max' => config('upload.filesize'),//文件上传大小kb
        ];
    }

	/**
	 * 门店登录（支持同账号多门店：先校验密码，多店时返回可选门店；带 store_id 完成登录）
	 * @param string $account
	 * @param string $password
	 * @param string $type
	 * @param int $id 门店 ID；0 表示未选店
	 * @return array
	 */
    public function login($account, $password, $type, $id = 0)
    {
		$key = $this->loginFailCacheKey((string)$account);
		$id = (int)$id;

		$candidates = SystemStoreStaff::where('is_del', 0)
			->where(function ($q) use ($account) {
				$q->where('account', $account)->whereOr('phone', $account);
			})
			->when($id > 0, function ($q) use ($id) {
				$q->where('store_id', $id);
			})
			->select();

		if ($candidates->isEmpty()) {
			Cache::inc($key);
			throw new AdminException('账号或密码错误，请重新输入!');
		}

		$valid = [];
		foreach ($candidates as $row) {
			if (!(int)$row['status']) {
				continue;
			}
			// 空密码仅允许「已指定门店」的平台快捷登录；账号密码登录必须校验
			if ($password === '' || $password === null) {
				if ($id <= 0) {
					continue;
				}
			} elseif (!password_verify((string)$password, (string)$row['pwd'])) {
				continue;
			}
			$valid[] = $row;
		}

		if ($valid === []) {
			Cache::inc($key);
			throw new AdminException('账号或密码错误，请重新输入!');
		}

		// 同手机号多门店：未指定 store_id 时返回门店列表供选择（密码已通过）
		if ($id <= 0 && count($valid) > 1) {
			return $this->buildNeedSelectStoreResult($valid);
		}

		/** @var \app\model\store\SystemStoreStaff $storeStaffInfo */
		$storeStaffInfo = $valid[0];
		return $this->buildLoginResult($storeStaffInfo, (string)$type);
    }

	/**
	 * 已登录店员切换授权门店（同一 uid / employee_id 下的任职行）
	 */
	public function switchStore(int $currentStaffId, int $targetStoreId, string $type = 'store'): array
	{
		$current = $this->dao->get($currentStaffId);
		if (!$current || !(int)$current['id'] || (int)$current['is_del'] === 1) {
			throw new AdminException('当前登录账号不存在!');
		}
		$targetStoreId = (int)$targetStoreId;
		if ($targetStoreId <= 0) {
			throw new AdminException('请选择门店!');
		}
		if ((int)$current['store_id'] === $targetStoreId) {
			return $this->buildLoginResult($current, $type);
		}

		$uid = (int)$current['uid'];
		$employeeId = (int)($current['employee_id'] ?? 0);
		$query = SystemStoreStaff::where('is_del', 0)
			->where('status', 1)
			->where('store_id', $targetStoreId);
		if ($uid > 0) {
			$query->where('uid', $uid);
		} elseif ($employeeId > 0) {
			$query->where('employee_id', $employeeId);
		} else {
			throw new AdminException('当前账号无法切换门店!');
		}
		$target = $query->find();
		if (!$target) {
			throw new AdminException('无权切换到该门店!');
		}
		return $this->buildLoginResult($target, $type);
	}

	/**
	 * @param array<int, \app\model\store\SystemStoreStaff|array> $validStaffRows
	 */
	protected function buildNeedSelectStoreResult(array $validStaffRows): array
	{
		$storeIds = [];
		foreach ($validStaffRows as $row) {
			$storeIds[] = (int)(is_object($row) ? $row['store_id'] : ($row['store_id'] ?? 0));
		}
		$storeIds = array_values(array_unique(array_filter($storeIds)));
		$nameMap = [];
		if ($storeIds) {
			$nameMap = Db::name('system_store')->whereIn('id', $storeIds)->column('name', 'id');
			$nameMap = is_array($nameMap) ? $nameMap : [];
		}
		$stores = [];
		foreach ($validStaffRows as $row) {
			$sid = (int)(is_object($row) ? $row['store_id'] : ($row['store_id'] ?? 0));
			if ($sid <= 0) {
				continue;
			}
			$stores[$sid] = [
				'id' => $sid,
				'name' => (string)($nameMap[$sid] ?? ('门店' . $sid)),
				'staff_id' => (int)(is_object($row) ? $row['id'] : ($row['id'] ?? 0)),
			];
		}
		return [
			'need_select_store' => true,
			'stores' => array_values($stores),
			'message' => '该账号绑定多个门店，请选择门店后登录',
		];
	}

	/**
	 * @param \app\model\store\SystemStoreStaff|array $storeStaffInfo
	 */
	protected function buildLoginResult($storeStaffInfo, string $type): array
	{
		if (is_array($storeStaffInfo)) {
			$storeStaffInfo = $this->dao->get((int)$storeStaffInfo['id']);
		}
		if (!$storeStaffInfo) {
			throw new AdminException('账号或密码错误，请重新输入!');
		}
		$storeStaffInfo->last_time = time();
		$storeStaffInfo->last_ip = app('request')->ip();
		$storeStaffInfo->login_count++;
		$storeStaffInfo->save();

		$tokenInfo = $this->createToken((int)$storeStaffInfo->id, $type, (string)$storeStaffInfo->pwd);
		/** @var SystemMenusServices $services */
		$services = app()->make(SystemMenusServices::class);
		[$menus, $uniqueAuth] = $services->getMenusList($storeStaffInfo->roles, (int)$storeStaffInfo['level'], 2);
		/** @var SystemStoreServices $storeServices */
		$storeServices = app()->make(SystemStoreServices::class);
		$store = $storeServices->get((int)$storeStaffInfo['store_id'], ['id', 'image', 'name', 'product_category_status']);
		return [
			'token' => $tokenInfo['token'],
			'expires_time' => $tokenInfo['params']['exp'],
			'need_select_store' => false,
			'menus' => $menus,
			'unique_auth' => $uniqueAuth,
			'user_info' => [
				'id' => $storeStaffInfo->getData('id'),
				'account' => $storeStaffInfo->getData('account'),
				'avatar' => $storeStaffInfo->getData('avatar'),
				'uid' => (int)$storeStaffInfo->getData('uid'),
				'employee_id' => (int)$storeStaffInfo->getData('employee_id'),
				'is_manager' => (int)$storeStaffInfo->getData('is_manager'),
				'is_cashier' => (int)$storeStaffInfo->getData('is_cashier'),
				'verify_status' => (int)$storeStaffInfo->getData('verify_status'),
			],
			'store_id' => $store && isset($store['id']) ? (int)$store['id'] : (int)$storeStaffInfo['store_id'],
			'store_name' => $store && isset($store['name']) ? (string)$store['name'] : '',
			'logo' => $store && isset($store['image']) && $store['image'] ? $store['image'] : sys_config('site_logo'),
			'logo_square' => $store && isset($store['image']) && $store['image'] ? $store['image'] : sys_config('site_logo'),
			'product_category_status' => $store && isset($store['product_category_status']) ? $store['product_category_status'] : 0,
			'version' => get_mohe_version(),
			'newOrderAudioLink' => set_file_url(sys_config('new_order_audio_link', '/statics/audio/newOrderAudioLink.mp3')),
			'prefix' => config('admin.store_prefix'),
		];
	}


    /**
     * 重置密码
     * @param $account
     * @param $password
     */
    public function reset($account, $password)
    {
        $user = $this->dao->getOne(['account|phone' => $account]);
        if (!$user) {
            throw new ValidateException('用户不存在');
        }
        if (!$this->dao->update($user['uid'], ['pwd' => md5((string)$password)], 'uid')) {
            throw new ValidateException('修改密码失败');
        }
        return true;
    }


    /**
     * 获取Admin授权信息
     * @param string $token
     * @return array
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function parseToken(string $token): array
    {
        /** @var CacheService $cacheService */
        $cacheService = app()->make(CacheService::class);

        if (!$token || $token === 'undefined') {
            throw new AuthException(ApiErrorCode::ERR_LOGIN);
        }
        //检测token是否过期
        $md5Token = md5($token);
        if (!$cacheService->hasToken($md5Token) || !($cacheToken = $cacheService->getTokenBucket($md5Token))) {
            throw new AuthException(ApiErrorCode::ERR_LOGIN);
        }
        //是否超出有效次数
        if (isset($cacheToken['invalidNum']) && $cacheToken['invalidNum'] >= 3) {
            if (!request()->isCli()) {
                $cacheService->clearToken($md5Token);
            }
            throw new AuthException(ApiErrorCode::ERR_LOGIN_INVALID);
        }

        /** @var JwtAuth $jwtAuth */
        $jwtAuth = app()->make(JwtAuth::class);
        //设置解析token
        [$id, $type, $auth] = $jwtAuth->parseToken($token);
        //验证token
        try {
            $jwtAuth->verifyToken();
            $cacheService->setTokenBucket($md5Token, $cacheToken, $cacheToken['exp']);
        } catch (ExpiredException $e) {
            $cacheToken['invalidNum'] = isset($cacheToken['invalidNum']) ? $cacheToken['invalidNum']++ : 1;
            $cacheService->setTokenBucket($md5Token, $cacheToken, $cacheToken['exp']);
        } catch (\Throwable $e) {
            if (!request()->isCli()) {
                $cacheService->clearToken($md5Token);
            }
            throw new AuthException(ApiErrorCode::ERR_LOGIN_INVALID);
        }
        //获取管理员信息
        $storeStaffInfo = $this->dao->get($id);
        if (!$storeStaffInfo || !$storeStaffInfo->id || $storeStaffInfo->is_del) {
            if (!request()->isCli()) {
                $cacheService->clearToken($md5Token);
            }
            throw new AuthException(ApiErrorCode::ERR_LOGIN_STATUS);
        }
        if ($auth !== md5($storeStaffInfo->pwd)) {
            throw new AuthException(ApiErrorCode::ERR_LOGIN_INVALID);
        }

        $storeStaffInfo->type = $type;
        return $storeStaffInfo->hidden(['pwd', 'is_del', 'status'])->toArray();
    }

    /**
     * 后台验证权限
     * @param Request $request
     */
    public function verifiAuth(Request $request)
    {
        $rule = str_replace('storeapi/', '', trim(strtolower($request->rule()->getRule())));
        if (in_array($rule, ['store/logout', 'menuslist'])) {
            return true;
        }
		$method = trim(strtolower($request->method()));
        /** @var SystemRoleServices $roleServices */
        $roleServices = app()->make(SystemRoleServices::class);
        $auth = $roleServices->getAllRoles(2, 2, self::STORE_RULES_LEVEL);
        //验证访问接口是否存在
		if ($auth && !in_array($method . '@@' . $rule, array_map(function ($item) {
				return trim(strtolower($item['methods'])). '@@'. trim(strtolower(str_replace(' ', '', $item['api_url'])));
			}, $auth))) {
			return true;
		}
        $auth = $roleServices->getRolesByAuth($request->storeStaffInfo()['roles'], 2, 2, self::STORE_RULES_LEVEL);

        //验证访问接口是否有权限
        if ($auth && empty(array_filter($auth, function ($item) use ($rule, $method) {
            if (trim(strtolower($item['api_url'])) === $rule && $method === trim(strtolower($item['methods'])))
                return true;
        }))) {
            throw new AuthException(ApiErrorCode::ERR_AUTH);
        }
    }


}
