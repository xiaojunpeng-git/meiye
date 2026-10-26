<?php

declare(strict_types=1);

/**
 * 现网门店登录 + MerchantAccess 实调探针（仅本地 mohe-app / 测试库，禁止连 RH）。
 *
 * 环境变量：
 *   RH_LOGIN_TEST_DB       默认 rh_mig_staff_login_test
 *   RH_LOGIN_TEST_PHONE    多门店店长手机号
 *   RH_LOGIN_TEST_PASSWORD 明文密码（迁移默认手机号后 6 位）
 */

$failures = [];

function probe(bool $ok, string $name, array &$failures, string $detail = ''): void
{
    if ($ok) {
        echo "PASS: {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    } else {
        $failures[] = $name . ($detail !== '' ? " ({$detail})" : '');
        echo "FAIL: {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

echo "=== rh_staff_login_service_probe.php ===\n";

$dbName = getenv('RH_LOGIN_TEST_DB') ?: 'rh_mig_staff_login_test';
$phone = getenv('RH_LOGIN_TEST_PHONE') ?: '13800001111';
$password = getenv('RH_LOGIN_TEST_PASSWORD') ?: (strlen($phone) >= 6 ? substr($phone, -6) : $phone);

require dirname(__DIR__) . '/vendor/autoload.php';

$app = new think\App();
$app->initialize();

$dbCfg = $app->config->get('database');
$dbCfg['connections']['mysql']['hostname'] = 'mysql';
$dbCfg['connections']['mysql']['hostport'] = '3306';
$dbCfg['connections']['mysql']['database'] = $dbName;
$dbCfg['connections']['mysql']['username'] = 'root';
$dbCfg['connections']['mysql']['password'] = 'localdev123';
$dbCfg['connections']['mysql']['charset'] = 'utf8mb4';
$dbCfg['connections']['mysql']['prefix'] = 'eb_';
$app->config->set(['database' => $dbCfg]);

// 登录结果依赖的最小辅助表（测试库专用）
$pdo = new PDO(
    'mysql:host=mysql;port=3306;dbname=' . $dbName . ';charset=utf8mb4',
    'root',
    'localdev123',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS eb_system_config (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      menu_name VARCHAR(255) NOT NULL DEFAULT "",
      value TEXT,
      KEY idx_menu_name (menu_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);
$pdo->exec('DROP TABLE IF EXISTS eb_system_menus');
$pdo->exec(
    'CREATE TABLE eb_system_menus (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      pid INT NOT NULL DEFAULT 0,
      type TINYINT NOT NULL DEFAULT 1,
      icon VARCHAR(64) NOT NULL DEFAULT "",
      menu_name VARCHAR(64) NOT NULL DEFAULT "",
      module VARCHAR(64) NOT NULL DEFAULT "",
      controller VARCHAR(64) NOT NULL DEFAULT "",
      action VARCHAR(64) NOT NULL DEFAULT "",
      api_url VARCHAR(255) NOT NULL DEFAULT "",
      methods VARCHAR(32) NOT NULL DEFAULT "",
      params TEXT NULL,
      sort INT NOT NULL DEFAULT 0,
      is_show TINYINT NOT NULL DEFAULT 1,
      is_show_path TINYINT NOT NULL DEFAULT 0,
      access TINYINT NOT NULL DEFAULT 0,
      menu_path VARCHAR(255) NOT NULL DEFAULT "",
      path VARCHAR(255) NOT NULL DEFAULT "",
      auth_type TINYINT NOT NULL DEFAULT 1,
      header VARCHAR(64) NOT NULL DEFAULT "",
      is_header TINYINT NOT NULL DEFAULT 0,
      unique_auth VARCHAR(128) NOT NULL DEFAULT "",
      is_del TINYINT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);
foreach ([
    'ALTER TABLE eb_system_role ADD COLUMN status TINYINT NOT NULL DEFAULT 1',
    'ALTER TABLE eb_system_role ADD COLUMN rules TEXT NULL',
    'ALTER TABLE eb_system_store ADD COLUMN image VARCHAR(255) NOT NULL DEFAULT ""',
    'ALTER TABLE eb_system_store ADD COLUMN product_category_status TINYINT NOT NULL DEFAULT 0',
    'ALTER TABLE eb_system_store ADD COLUMN is_del TINYINT NOT NULL DEFAULT 0',
] as $alterSql) {
    try {
        $pdo->exec($alterSql);
    } catch (Throwable $e) {
        // column may already exist
    }
}

// 避免 sysConfig / 客服等缺表打断身份解析
foreach ([
    'CREATE TABLE IF NOT EXISTS eb_store_service (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      uid INT UNSIGNED NOT NULL DEFAULT 0,
      status TINYINT NOT NULL DEFAULT 0,
      account_status TINYINT NOT NULL DEFAULT 0,
      customer TINYINT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
    'CREATE TABLE IF NOT EXISTS eb_delivery_service (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      uid INT UNSIGNED NOT NULL DEFAULT 0,
      status TINYINT NOT NULL DEFAULT 0,
      is_del TINYINT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
    'CREATE TABLE IF NOT EXISTS eb_system_region_agent (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      uid INT UNSIGNED NOT NULL DEFAULT 0,
      status TINYINT NOT NULL DEFAULT 0,
      is_del TINYINT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
] as $ddl) {
    $pdo->exec($ddl);
}

/** @var \app\services\store\LoginServices $loginServices */
$loginServices = app()->make(\app\services\store\LoginServices::class);

$needSelect = null;
try {
    $needSelect = $loginServices->login($phone, $password, 'store', 0);
} catch (Throwable $e) {
    probe(false, 'store_login_password_multi_store', $failures, $e->getMessage());
}

if (is_array($needSelect)) {
    probe(
        !empty($needSelect['need_select_store']) && count($needSelect['stores'] ?? []) >= 2,
        'store_login_need_select_store',
        $failures,
        'stores=' . count($needSelect['stores'] ?? [])
    );
}

$login101 = null;
try {
    $login101 = $loginServices->login($phone, $password, 'store', 101);
} catch (Throwable $e) {
    probe(false, 'store_login_with_store_id_101', $failures, $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
}

if (is_array($login101)) {
    probe(
        empty($login101['need_select_store']) && !empty($login101['token']) && (int)($login101['store_id'] ?? 0) === 101,
        'store_login_token_store_101',
        $failures,
        'store_id=' . ($login101['store_id'] ?? '')
    );
    probe((int)($login101['user_info']['is_manager'] ?? 0) === 1, 'login_user_info_is_manager', $failures);
    probe((int)($login101['user_info']['uid'] ?? 0) > 0, 'login_user_info_uid', $failures);
}

$staffId101 = (int)($login101['user_info']['id'] ?? 0);
$uid = (int)($login101['user_info']['uid'] ?? 0);

$switched = null;
if ($staffId101 > 0) {
    try {
        $switched = $loginServices->switchStore($staffId101, 102, 'store');
    } catch (Throwable $e) {
        probe(false, 'store_switch_store_102', $failures, $e->getMessage());
    }
}
if (is_array($switched)) {
    probe(
        !empty($switched['token']) && (int)($switched['store_id'] ?? 0) === 102,
        'store_switch_store_token_102',
        $failures,
        'store_id=' . ($switched['store_id'] ?? '')
    );
}

/** @var \app\services\merchant\MerchantAccessServices $accessServices */
$accessServices = app()->make(\app\services\merchant\MerchantAccessServices::class);
$access = null;
if ($uid > 0) {
    try {
        $access = $accessServices->resolveAccess($uid, ['active_store_id' => 101]);
    } catch (Throwable $e) {
        probe(false, 'merchant_access_resolve', $failures, $e->getMessage());
    }
}
if (is_array($access)) {
    $roles = $access['roles'] ?? [];
    probe(in_array('store_manager', $roles, true), 'merchant_roles_contain_store_manager', $failures, json_encode($roles, JSON_UNESCAPED_UNICODE));
    probe(($access['active_role'] ?? '') === 'store_manager', 'merchant_active_role_store_manager', $failures, (string)($access['active_role'] ?? ''));
    probe(!empty($access['identity']['is_manager']), 'merchant_identity_is_manager', $failures);
    probe(!empty($access['can_enter_merchant']), 'merchant_can_enter', $failures);
}

// —— 失败超过3次 → 完成验证码 → 选择门店 → 成功登录（控制器同口径门禁）——
putenv('RH_STORE_LOGIN_CAPTCHA_STUB=1');
$_ENV['RH_STORE_LOGIN_CAPTCHA_STUB'] = '1';
$failKey = $loginServices->loginFailCacheKey($phone);
$pwdOkKey = $loginServices->loginPwdOkCacheKey($phone);
\think\facade\Cache::delete($failKey);
\think\facade\Cache::delete($pwdOkKey);

for ($i = 0; $i < 4; $i++) {
    try {
        $loginServices->login($phone, 'wrong-password-xxxx', 'store', 0);
    } catch (Throwable $e) {
        // expected
    }
}
probe($loginServices->isCaptchaRequired($phone), 'captcha_required_after_fail_gt_2', $failures, 'count=' . (string)\think\facade\Cache::get($failKey));

$needCaptchaBlocked = false;
try {
    $loginServices->assertLoginCaptcha($phone, 0, '', '');
} catch (Throwable $e) {
    $needCaptchaBlocked = (strpos($e->getMessage(), '请拖动滑块验证') !== false);
}
probe($needCaptchaBlocked, 'captcha_blocks_login_without_code', $failures);

try {
    $loginServices->assertLoginCaptcha($phone, 0, 'clickWord', 'RH_TEST_CAPTCHA_OK');
    $afterCaptcha = $loginServices->login($phone, $password, 'store', 0);
    $loginServices->afterPasswordLoginSuccess($phone, is_array($afterCaptcha) ? $afterCaptcha : []);
    probe(
        !empty($afterCaptcha['need_select_store']),
        'captcha_then_need_select_store',
        $failures
    );
    probe(
        !$loginServices->isCaptchaRequired($phone),
        'fail_count_cleared_after_need_select',
        $failures
    );
    probe(
        (bool)\think\facade\Cache::get($pwdOkKey),
        'pwd_ok_ticket_set_for_store_select',
        $failures
    );

    // 选店：不得再要求/复验一次性验证码（无 captcha 参数）
    $loginServices->assertLoginCaptcha($phone, 101, '', '');
    $finalLogin = $loginServices->login($phone, $password, 'store', 101);
    $loginServices->afterPasswordLoginSuccess($phone, is_array($finalLogin) ? $finalLogin : []);
    probe(
        !empty($finalLogin['token']) && (int)($finalLogin['store_id'] ?? 0) === 101,
        'select_store_login_success_without_recaptcha',
        $failures,
        'store_id=' . ($finalLogin['store_id'] ?? '')
    );
    probe(
        !\think\facade\Cache::get($pwdOkKey),
        'pwd_ok_ticket_cleared_after_full_login',
        $failures
    );
} catch (Throwable $e) {
    probe(false, 'captcha_fail_then_select_store_flow', $failures, $e->getMessage());
}

echo "\n=== probe summary: " . (count($failures) === 0 ? 'all pass' : count($failures) . ' fail') . " ===\n";
if ($failures !== []) {
    foreach ($failures as $f) {
        echo "  FAIL: {$f}\n";
    }
    exit(1);
}
exit(0);
