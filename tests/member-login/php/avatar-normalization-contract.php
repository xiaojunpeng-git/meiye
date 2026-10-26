<?php
declare(strict_types=1);

// 项目当前依赖仍包含 PHP 新版本弃用提示；本合同只验证本轮头像持久化行为。
error_reporting(E_ALL & ~E_DEPRECATED);

require dirname(__DIR__, 3) . '/后端代码/vendor/autoload.php';

use app\services\wechat\WechatUserServices;

$service = (new ReflectionClass(WechatUserServices::class))->newInstanceWithoutConstructor();
$method = new ReflectionMethod(WechatUserServices::class, 'normalizeWechatAvatarForPersistence');

$cases = [
    'existing_missing_avatar_preserved' => [
        'input' => ['openid' => 'existing-openid', 'headimgurl' => null],
        'preserve_existing' => true,
        'expected' => ['openid' => 'existing-openid'],
    ],
    'existing_blank_avatar_preserved' => [
        'input' => ['openid' => 'existing-openid', 'headimgurl' => '   '],
        'preserve_existing' => true,
        'expected' => ['openid' => 'existing-openid'],
    ],
    'new_missing_avatar_uses_safe_empty_value' => [
        'input' => ['openid' => 'new-openid'],
        'preserve_existing' => false,
        'expected' => ['openid' => 'new-openid', 'headimgurl' => ''],
    ],
    'provided_avatar_is_trimmed_and_saved' => [
        'input' => ['openid' => 'new-openid', 'headimgurl' => ' https://cdn.example/avatar.jpg '],
        'preserve_existing' => false,
        'expected' => ['openid' => 'new-openid', 'headimgurl' => 'https://cdn.example/avatar.jpg'],
    ],
];

$failed = 0;
foreach ($cases as $id => $case) {
    // 直接执行生产私有方法，验证数据库写入前的实际数据边界，而非仅匹配源码文本。
    $actual = $method->invoke($service, $case['input'], $case['preserve_existing']);
    if ($actual === $case['expected']) {
        echo "PASS {$id}\n";
        continue;
    }
    $failed++;
    fwrite(STDERR, "FAIL {$id}: " . json_encode($actual, JSON_UNESCAPED_UNICODE) . "\n");
}

exit($failed === 0 ? 0 : 1);
