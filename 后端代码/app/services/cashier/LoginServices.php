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

namespace app\services\cashier;

use app\Request;
use app\services\BaseServices;
use app\dao\store\SystemStoreStaffDao;
use app\services\system\SystemMenusServices;
use app\services\system\SystemRoleServices;
use app\services\store\SystemStoreServices;
use app\services\store\StoreStaffShiftHandoverServices;
use mohe\exceptions\AdminException;
use mohe\exceptions\AuthException;
use mohe\services\CacheService;
use mohe\traits\ServicesTrait;
use mohe\utils\ApiErrorCode;
use mohe\utils\JwtAuth;
use Firebase\JWT\ExpiredException;
use think\exception\ValidateException;
use think\facade\Cache;


/**
 *
 * Class LoginServices
 * @package app\services\user
 * @mixin SystemStoreStaffDao
 */
class LoginServices extends BaseServices
{

    use ServicesTrait;

    /**
     * 当前门店权限缓存前缀
     */
    const STORE_CASHIER_RULES_LEVEL = 'store_cashier_rules_level_';

    /**
     * LoginServices constructor.
     * @param SystemStoreStaffDao $dao
     */
    public function __construct(SystemStoreStaffDao $dao)
    {
        $this->dao = $dao;
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
            'login_logo' => sys_config('login_logo'),//登录
            'site_name' => sys_config('site_name'),
            'site_url' => sys_config('site_url'),
        ];
    }

    /**
     * H5账号登录
     * @param Request $request
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function login($account, $password, $type)
    {
        $storeStaffInfo = $this->dao->getOne(['account|phone' => $account, 'is_del' => 0]);
        $key = 'cashier_login_captcha_' . $account;
        if (!$storeStaffInfo) {
            Cache::inc($key);
            throw new AdminException('账号或密码错误，请重新输入!');
        }
        if ($password) {//平台还可以登录
            if (!$storeStaffInfo->status) {
                Cache::inc($key);
                throw new AdminException('您已被禁止登录!');
            }
            if (!password_verify($password, $storeStaffInfo->pwd)) {
                Cache::inc($key);
                throw new AdminException('账号或密码错误，请重新输入');
            }
        }
        return $this->getLoginResult((int)$storeStaffInfo['id'], $type, $storeStaffInfo);
    }

    /**
     * 交接班
     * @param $account
     * @return \mohe\basic\BaseModel|\think\Model|\think\Response
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function shiftHandover($account)
    {
        if (!$account) throw new AdminException('参数有误!');
        $storeStaffInfo = $this->dao->getOne(['account|phone' => $account, 'is_del' => 0]);
        if (!$storeStaffInfo) {
            throw new AdminException('账号已删除!');
        }
        /** @var StoreStaffShiftHandoverServices $services */
        $services = app()->make(StoreStaffShiftHandoverServices::class);
        return $services->setShiftHandover($storeStaffInfo['id']);
    }

    /**
     * 企业微信扫码登录
     * @param array $workUserInfo
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function workScanLogin(array $workUserInfo)
    {
        if (0 !== $workUserInfo['errcode']) {
            throw new ValidateException($workUserInfo['errmsg']);
        }
        if (empty($workUserInfo['mobile'])) {
            throw new ValidateException('改成员请先关联手机号在进行登录');
        }
        $storeStaffInfo = $this->dao->getOne(['phone' => $workUserInfo['mobile'], 'is_del' => 0]);
        if (!$storeStaffInfo) {
            throw new AdminException('账号不存在!');
        }
        return $this->getLoginResult((int)$storeStaffInfo['id'], 'cashier', $storeStaffInfo);
    }


    /**
     * 获取登录店员信息
     * @param int $id
     * @param string $type
     * @param array $storeStaffInfo
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getLoginResult(int $id, string $type, $storeStaffInfo = [])
    {
        if (!$storeStaffInfo) {
            $storeStaffInfo = $this->dao->get(['id' => $id, 'is_del' => 0]);
        }
        if (!$storeStaffInfo) {
            throw new AdminException('账号不存在!');
        }
        $v3Features = [];
        $v3Account = '';
        if ($type === 'cashier_v3') {
            /** @var \app\services\cashier\v3\permission\CashierV3FeatureResolver $resolver */
            $resolver = app()->make(\app\services\cashier\v3\permission\CashierV3FeatureResolver::class);
            $profile = is_array($storeStaffInfo) ? $storeStaffInfo : $storeStaffInfo->toArray();
            $v3Account = $this->cashierV3AccountForProfile($profile);
            if ($v3Account !== '') {
                // The staff row is a store assignment, while the employee's
                // internal account is the authoritative login identity.
                $profile['account'] = $v3Account;
            }
            $v3Features = $resolver->resolveGrantedFeatures($profile);
            if (!$v3Features) {
                throw new AdminException('当前账号无门店端权限，请联系管理员配置岗位和门店端入口');
            }
        }
        $token_login = 'is_staff_user_cashier_login_' . $storeStaffInfo->id . '_' . $type . '_' . $storeStaffInfo->pwd;
//        if (Cache::has($token_login)) {
//            throw new AdminException('当前登录账号已登录，无法再次登录！');
//        }
        $storeStaffInfo->last_time = time();
        $storeStaffInfo->last_ip = app('request')->ip();
        $storeStaffInfo->login_count++;
        $storeStaffInfo->save();

        $tokenInfo = $this->createToken($storeStaffInfo->id, $type, $storeStaffInfo->pwd);
        Cache::set($token_login, $tokenInfo['token']);
        if ($type === 'cashier_v3') {
            /** @var SystemStoreServices $storeServices */
            $storeServices = app()->make(SystemStoreServices::class);
            $store = $storeServices->get((int)$storeStaffInfo['store_id'], ['id', 'image', 'name', 'product_category_status']);
            return [
                'token' => $tokenInfo['token'],
                'expires_time' => $tokenInfo['params']['exp'],
                'features' => $v3Features,
                'user_info' => [
                    'id' => $storeStaffInfo->getData('id'),
                    'employee_id' => (int)($storeStaffInfo->getData('employee_id') ?? 0),
                    'account' => $v3Account !== '' ? $v3Account : $storeStaffInfo->getData('account'),
                    'avatar' => $storeStaffInfo->getData('avatar'),
                    'shift_start_time' => time(),
                ],
                'store_id' => $store && isset($store['id']) ? (int)$store['id'] : 0,
                'store_name' => $store && isset($store['name']) ? (string)$store['name'] : '',
                'version' => get_mohe_version(),
                'prefix' => config('admin.cashier_prefix'),
            ];
        }
        /** @var SystemMenusServices $services */
        $services = app()->make(SystemMenusServices::class);
        [$menus, $uniqueAuth] = $services->getMenusList($storeStaffInfo->roles, (int)($storeStaffInfo['level'] ?? 0), 3);
        if (!$menus) {//无收银台菜单权限
            throw new AdminException('当前登录账号角色无收银台相关菜单权限，请联系管理员');
        }
        /** @var SystemStoreServices $storeServices */
        $storeServices = app()->make(SystemStoreServices::class);
        $store = $storeServices->get((int)$storeStaffInfo['store_id'], ['id', 'image', 'name', 'product_category_status']);
        return [
            'token' => $tokenInfo['token'],
            'expires_time' => $tokenInfo['params']['exp'],
            'menus' => $menus,
            'unique_auth' => $uniqueAuth,
            'user_info' => [
                'id' => $storeStaffInfo->getData('id'),
                'account' => $storeStaffInfo->getData('account'),
                'avatar' => $storeStaffInfo->getData('avatar'),
                'shift_start_time' => time(),
            ],
            'store_id' => $store && isset($store['id']) && $store['id'] ? $store['id'] : 0,
            'logo' => $store && isset($store['image']) && $store['image'] ? $store['image'] : sys_config('site_logo'),
            'store_name' => $store && isset($store['name']) && $store['name'] ? $store['name'] : sys_config('site_name'),
            'logo_square' => $store && isset($store['image']) && $store['image'] ? $store['image'] : sys_config('site_logo'),
            'product_category_status' => $store && isset($store['product_category_status']) ? $store['product_category_status'] : 0,
            'version' => get_mohe_version(),
            'newOrderAudioLink' => set_file_url(sys_config('new_order_audio_link', '/statics/audio/newOrderAudioLink.mp3')),
            'prefix' => config('admin.cashier_prefix')
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
            throw new ValidateException('登录已失效');
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
        if ((string)$type === 'cashier_v3_delegated') {
            return $this->parseCashierV3DelegatedToken($cacheService, (int)$id, (string)$auth, $md5Token);
        }
        //获取管理员信息
        $storeStaffInfo = $this->dao->get($id);
        if (!$storeStaffInfo || !$storeStaffInfo->id || $storeStaffInfo->is_del) {
            if (!request()->isCli()) {
                $cacheService->clearToken($md5Token);
            }
            throw new AuthException(ApiErrorCode::ERR_LOGIN_STATUS);
        }
        // 停职/禁用：现有 token 必须即时失效（不得仅阻止下一次登录）
        if (!(int)$storeStaffInfo->status) {
            if (!request()->isCli()) {
                $cacheService->clearToken($md5Token);
            }
            throw new AuthException(ApiErrorCode::ERR_LOGIN_STATUS);
        }
        $employeeId = (int)($storeStaffInfo['employee_id'] ?? 0);
        if ($employeeId > 0) {
            $emp = \think\facade\Db::name('employee')->where('id', $employeeId)->field('id,status,is_del')->find();
            if (!$emp || (int)$emp['is_del'] === 1 || (int)$emp['status'] !== 1) {
                if (!request()->isCli()) {
                    $cacheService->clearToken($md5Token);
                }
                throw new AuthException(ApiErrorCode::ERR_LOGIN_STATUS);
            }
        }

        if ($auth !== md5($storeStaffInfo['pwd'])) {
            throw new AuthException(ApiErrorCode::ERR_LOGIN_INVALID);
        }

        $storeStaffInfo->type = $type;
        $profile = $storeStaffInfo->hidden(['pwd', 'is_del', 'status'])->toArray();
        if ($type === 'cashier_v3') {
            $account = $this->cashierV3AccountForProfile($profile);
            if ($account !== '') {
                $profile['account'] = $account;
            }
        }
        return $profile;
    }

    /**
     * 解析无直接门店任职的收银 V3 数据权限会话。
     * 会话主键不是 system_store_staff，避免为了登录而伪造或恢复门店任职。
     */
    protected function parseCashierV3DelegatedToken(CacheService $cacheService, int $sessionId, string $auth, string $md5Token): array
    {
        $session = \think\facade\Db::name('cashier_v3_store_session')->where('id', $sessionId)
            ->where('is_del', 0)->where('status', 1)->find();
        if (!$session || (int)($session['expire_time'] ?? 0) < time()) {
            $cacheService->clearToken($md5Token);
            throw new AuthException(ApiErrorCode::ERR_LOGIN_STATUS);
        }
        if (!hash_equals((string)($session['token_secret_hash'] ?? ''), $auth === '' ? '' : $auth)) {
            $cacheService->clearToken($md5Token);
            throw new AuthException(ApiErrorCode::ERR_LOGIN_INVALID);
        }
        $employeeId = (int)($session['employee_id'] ?? 0);
        $storeId = (int)($session['store_id'] ?? 0);
        $employee = \think\facade\Db::name('employee')->where('id', $employeeId)
            ->where('status', 1)->where('is_del', 0)->field('id,auth_version')->find();
        if (!$employee || (int)($employee['auth_version'] ?? 1) !== (int)($session['auth_version'] ?? 1)) {
            $cacheService->clearToken($md5Token);
            throw new AuthException(ApiErrorCode::ERR_LOGIN_STATUS);
        }
        $store = \think\facade\Db::name('system_store')->alias('store')
            ->leftJoin('organization_store org_store', 'org_store.store_id = store.id')
            ->leftJoin('organization org', 'org.id = org_store.org_id')
            ->where('store.id', $storeId)->where('store.is_del', 0)->where('store.is_show', 1)
            ->where(function ($query) {
                $query->whereNull('org.id')->whereOr(function ($or) { $or->where('org.is_del', 0); });
            })->field('store.id,store.name,store.image,store.product_category_status')->find();
        if (!$store) {
            $cacheService->clearToken($md5Token);
            throw new AuthException(ApiErrorCode::ERR_LOGIN_STATUS);
        }
        $scope = app()->make(\app\services\organization\EmployeeDataScopeServices::class);
        $allowed = $scope->resolveEffectiveStoreIds($employeeId, 0, ['admin_type' => 3]);
        if ($allowed === [] || ($allowed !== null && !in_array($storeId, array_map('intval', (array)$allowed), true))) {
            $cacheService->clearToken($md5Token);
            throw new AuthException(ApiErrorCode::ERR_LOGIN_STATUS);
        }
        $profile = [
            'id' => $sessionId,
            'employee_id' => $employeeId,
            'store_id' => $storeId,
            'staff_name' => (string)($session['staff_name_snapshot'] ?? ''),
            'account' => (string)($session['account_snapshot'] ?? ''),
            'roles' => [],
            'level' => 1,
            '_cashier_v3_delegated' => 1,
            'type' => 'cashier_v3_delegated',
        ];
        $features = app()->make(\app\services\cashier\v3\permission\CashierV3FeatureResolver::class)
            ->resolveGrantedFeatures($profile);
        if (!$features) {
            $cacheService->clearToken($md5Token);
            throw new AuthException(ApiErrorCode::ERR_LOGIN_STATUS);
        }
        return $profile + [
            'features' => $features,
            'store_name' => (string)($store['name'] ?? ''),
            'logo' => (string)($store['image'] ?? ''),
            'product_category_status' => (int)($store['product_category_status'] ?? 0),
        ];
    }

    /**
     * 门店任职记录可保留历史手机号或为空；V3 会话展示必须使用员工统一账号。
     * 此处只补会话展示身份，不参与门店资格、岗位或数据范围判定。
     */
    protected function cashierV3AccountForProfile(array $profile): string
    {
        $employeeId = (int)($profile['employee_id'] ?? 0);
        if ($employeeId > 0) {
            $account = trim((string)\think\facade\Db::name('employee_internal_account')
                ->where('employee_id', $employeeId)
                ->where('status', 1)
                ->where('is_del', 0)
                ->value('account'));
            if ($account !== '') {
                return $account;
            }
        }
        return trim((string)($profile['account'] ?? ''));
    }

    /**
     * 后台验证权限
     * @param Request $request
     */
    public function verifiAuth(Request $request)
    {
        $rule = str_replace('cashierapi/', '', trim(strtolower($request->rule()->getRule())));
        if (in_array($rule, ['cashier/logout', 'menuslist'])) {
            return true;
        }
        $method = trim(strtolower($request->method()));
        /** @var SystemRoleServices $roleServices */
        $roleServices = app()->make(SystemRoleServices::class);
        $auth = $roleServices->getAllRoles(2, 3, self::STORE_CASHIER_RULES_LEVEL);
        //验证访问接口是否存在
        if ($auth && !in_array($method . '@@' . $rule, array_map(function ($item) {
                return trim(strtolower($item['methods'])) . '@@' . trim(strtolower(str_replace(' ', '', $item['api_url'])));
            }, $auth))) {
            return true;
        }
        $auth = $roleServices->getRolesByAuth($request->cashierInfo()['roles'], 2, 3, self::STORE_CASHIER_RULES_LEVEL);
        //验证访问接口是否有权限
        if ($auth && empty(array_filter($auth, function ($item) use ($rule, $method) {
                if (trim(strtolower($item['api_url'])) === $rule && $method === trim(strtolower($item['methods'])))
                    return true;
            }))) {
            throw new AuthException(ApiErrorCode::ERR_AUTH);
        }
    }


}
