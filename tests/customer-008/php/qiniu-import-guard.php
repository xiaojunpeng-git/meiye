<?php
/** Synthetic checks for the fixed 008 Qiniu source guard; no live keys enter tests. */

declare(strict_types=1);

require dirname(__DIR__, 3) . '/scripts/import/customer_008_morestore_qiniu_apply.php';

$config = [
    'upload_type' => '2',
    'qiniu_secretKey' => json_encode('TEST_SECRET_ONLY'),
    'qiniu_accessKey' => json_encode('TEST_ACCESS_ONLY'),
];
$storage = [
    'status' => '1', 'type' => '2', 'is_delete' => '0',
    'name' => 'meiyue01', 'domain' => 'https://myqiniu.cc3798.com',
    'access_key' => 'TEST_ACCESS_ONLY', 'region' => 'z0',
    'acl' => 'public-read-write', 'cname' => '', 'is_ssl' => '1',
];
$digest = qiniuSourceDigest($config, $storage);
qiniuValidateSource($config, $storage, $digest);

$checks = [
    ['field' => 'config', 'data' => array_merge($config, ['upload_type' => '1'])],
    ['field' => 'storage', 'data' => array_merge($storage, ['access_key' => 'OTHER'])],
    ['field' => 'storage', 'data' => array_merge($storage, ['name' => 'other-bucket'])],
];
foreach ($checks as $check) {
    $failed = false;
    try {
        qiniuValidateSource(
            $check['field'] === 'config' ? $check['data'] : $config,
            $check['field'] === 'storage' ? $check['data'] : $storage,
            $digest
        );
    } catch (RuntimeException $error) {
        $failed = true;
    }
    if (!$failed) {
        throw new RuntimeException('Changed Qiniu source was not rejected');
    }
}

$reversedConfig = array_reverse($config, true);
$reversedStorage = array_reverse($storage, true);
if (qiniuSourceDigest($reversedConfig, $reversedStorage) !== $digest) {
    throw new RuntimeException('Qiniu guard hash depends on database row ordering');
}
echo "QINIU_IMPORT_GUARD_PASS\n";
