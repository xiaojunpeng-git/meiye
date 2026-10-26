<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$read = static function (string $relative) use ($root): string {
    $content = file_get_contents($root . '/' . $relative);
    if (!is_string($content)) {
        throw new RuntimeException('missing_source:' . $relative);
    }
    return $content;
};
$failed = 0;
$assert = static function (string $id, bool $condition) use (&$failed): void {
    if ($condition) {
        echo "PASS {$id}\n";
        return;
    }
    $failed++;
    fwrite(STDERR, "FAIL {$id}\n");
};

$wechatUsers = $read('后端代码/app/services/wechat/WechatUserServices.php');
$exceptions = $read('后端代码/app/ExceptionHandle.php');
$publicController = $read('后端代码/app/controller/api/v1/PublicController.php');
$loginPage = $read('前端代码/uniapp/pages/users/wechat_login/index.vue');
$routineServices = $read('后端代码/app/services/wechat/RoutineServices.php');
$authMiddleware = $read('后端代码/app/http/middleware/api/AuthTokenMiddleware.php');

$assert('MEMBER-LOGIN-AVATAR-01',
    str_contains($wechatUsers, 'normalizeWechatAvatarForPersistence($wechatInfo, true)')
    && str_contains($wechatUsers, 'normalizeWechatAvatarForPersistence($wechatInfo, false)')
    && str_contains($wechatUsers, "unset(\$wechatInfo['headimgurl'])")
    && str_contains($wechatUsers, "\$wechatInfo['headimgurl'] = ''")
);
$assert('MEMBER-LOGIN-ERROR-02',
    str_contains($exceptions, "'系统繁忙，请稍后重试'")
    && str_contains($exceptions, "Env::get('app_debug', false) ? \$e->getMessage()")
);
$assert('MEMBER-LOGIN-BRAND-03',
    str_contains($publicController, "sys_config('routine_name', '')")
    && str_contains($publicController, "\$copyright['member_site_name']")
    && str_contains($loginPage, '会员登录')
    && str_contains($loginPage, '{{memberSiteName}}')
    && !str_contains($loginPage, '商城登录')
    && !str_contains($loginPage, '{{configData.site_name}}')
);
$assert('MEMBER-PHONE-BACKEND-04',
    str_contains($routineServices, "\$bindPhone = !\$user || trim((string)(\$user['phone'] ?? '')) === ''")
    && str_contains($routineServices, "\$userType === 'routine'")
    && str_contains($routineServices, "throw new ValidateException('请先授权手机号')")
    && str_contains($routineServices, "return ['auth_login' => 1, 'key' => \$userInfoKey]")
);
$assert('MEMBER-PHONE-OLD-TOKEN-05',
    str_contains($authMiddleware, "(\$authInfo['tokenData']['type'] ?? '') === 'routine'")
    && str_contains($authMiddleware, "trim((string)(\$authInfo['user']['phone'] ?? '')) === ''")
    && str_contains($authMiddleware, "app('json')->make(410000, '请先授权手机号')")
);

exit($failed === 0 ? 0 : 1);
