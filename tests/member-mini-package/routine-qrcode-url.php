<?php
declare(strict_types=1);
/** Pure response-address contract: no database, storage writes or WeChat/network calls. */
namespace app\services\other {
    // Isolate the cached public response path from framework configuration and storage/network infrastructure.
    function sys_config($key, $default = null) { return $GLOBALS['routine_qr_test_origin']; }
    function app() { return $GLOBALS['routine_qr_test_app']; }
    function curl_file_exist($url) { return true; }
}
namespace {
require dirname(__DIR__, 2) . '/后端代码/vendor/autoload.php';

$class = new ReflectionClass(app\services\other\QrcodeServices::class);
$service = $class->newInstanceWithoutConstructor();
$method = $class->getMethod('routineImageUrl');
$method->setAccessible(true);
$cases = 0;
foreach (['https://008.cc3798.com/', 'https://010.cc3798.com/', 'https://rh.cc3798.com/', 'http://127.0.0.1:8080/'] as $origin) {
    foreach (['/uploads/qrcode/routine/group.jpg', '//uploads/qrcode/routine/group.jpg'] as $url) {
        $expected = rtrim($origin, '/') . '/uploads/qrcode/routine/group.jpg';
        if ($method->invoke($service, $url, $origin) !== $expected) throw new RuntimeException('Local QR URL must use its own customer origin');
        ++$cases;
    }
    foreach (['https://cdn.example/qr.jpg', 'http://cdn.example/qr.jpg', '//cdn.example/qr.jpg'] as $url) {
        if ($method->invoke($service, $url, $origin) !== $url) throw new RuntimeException('Remote QR URL must not be prefixed again');
        ++$cases;
    }
}
foreach (['', 'javascript:bad', 'https://host/admin'] as $origin) {
    if ($method->invoke($service, '/uploads/qrcode/qr.jpg', $origin) !== '') throw new RuntimeException('Invalid local origin must fail closed');
    ++$cases;
}
$attachment = new class extends app\services\system\attachment\SystemAttachmentServices {
    public $row;
    public function __construct() {}
    public function getOne(array $where, ?string $field = '*', array $with = []) { return $this->row; }
};
$GLOBALS['routine_qr_test_origin'] = 'https://010.cc3798.com/';
$GLOBALS['routine_qr_test_app'] = new class($attachment) {
    private $attachment;
    public function __construct($attachment) { $this->attachment = $attachment; }
    public function make($class) {
        if ($class !== app\services\system\attachment\SystemAttachmentServices::class) throw new RuntimeException('Unexpected infrastructure access');
        return $this->attachment;
    }
};
foreach ([0, 1, 2] as $type) {
    $url = $type === 2 ? 'https://cdn.example/cached.jpg' : '/uploads/qrcode/routine/cached.jpg';
    $attachment->row = ['att_dir' => $url, 'image_type' => $type];
    $expected = $type === 2 ? $url : 'https://010.cc3798.com/uploads/qrcode/routine/cached.jpg';
    if ($service->getRoutineQrcodePath(26, 99, 31, 'cached.jpg') !== $expected) throw new RuntimeException('Cached public response must normalize local types 0 and 1 while preserving CDN');
    ++$cases;
}
echo 'ROUTINE_QRCODE_URL_PASS ', $cases, ' cases', PHP_EOL;
}
