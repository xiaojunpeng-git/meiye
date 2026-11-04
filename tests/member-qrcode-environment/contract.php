<?php
declare(strict_types=1);

/** 执行正本二维码服务及微信请求封装；替换网络、上传与附件存储，不接触微信或生产数据库。 */
namespace app\services { class BaseServices {} }
namespace app\dao\other { class QrcodeDao {} }
namespace mohe\traits { trait ServicesTrait {} }
namespace think\exception { class ValidateException extends \RuntimeException {} }
namespace think\facade { class Log { public static function warning($message): void { throw new \RuntimeException($message); } } }
namespace GuzzleHttp\Psr7 { class Utils { public static function streamFor($value) { return $value; } } }
namespace app\services\system\attachment {
    class SystemAttachmentServices {
        public $rows = [];
        public function getOne(array $where) { return $this->rows[$where['name']] ?? null; }
        public function delete(array $where): void { unset($this->rows[$where['name']]); }
        public function save(array $row): void { $this->rows[$row['name']] = $row; }
    }
}
namespace mohe\services {
    class UtilService { public static function remoteImage($url): array { return ['status' => true]; } }
    class UploadService {
        private $path;
        private $name;
        public static function init($type): self { return new self(); }
        public function to($path): self { $this->path = $path; return $this; }
        public function validate(): self { return $this; }
        public function stream($body, $name): bool { $this->name = $name; return true; }
        public function getUploadInfo(): array {
            return ['name' => $this->name, 'dir' => '/uploads/'.$this->path.'/'.$this->name,
                'thumb_path' => '', 'size' => 5, 'type' => 'image/jpeg'];
        }
    }
}
namespace mohe\services\wechat {
    class BaseApplication {
        public static function qrcodeService() { return $GLOBALS['qrTransport']; }
        public static function logger(...$args): void {}
    }
}
namespace {
    $environment = 'release';
    $checks = 0;
    $attachments = new \app\services\system\attachment\SystemAttachmentServices();
    $qrTransport = new class {
        public $requests = [];
        public function getUnlimit($scene, $options) {
            $this->requests[] = ['scene' => $scene, 'options' => $options];
            return 'LOCAL_IMAGE';
        }
    };
    function config($key, $default = null) { return $key === 'member_qrcode.env_version' ? $GLOBALS['environment'] : $default; }
    function sys_config($key, $default = null) { return $key === 'site_url' ? 'https://local.invalid' : $default; }
    function curl_file_exist($url): bool { return true; }
    function app() { return new class { public function make($class) { return $GLOBALS['attachments']; } }; }
    function check($condition, $message): void {
        if (!$condition) throw new \RuntimeException('FAIL '.$message);
        ++$GLOBALS['checks']; echo 'PASS '.$message."\n";
    }
    $root = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 2).'/后端代码';
    require $root.'/mohe/services/wechat/MiniProgram.php';
    require $root.'/app/services/other/QrcodeServices.php';
    $service = new \app\services\other\QrcodeServices(new \app\dao\other\QrcodeDao());
    // 旧附件即使存在，也不能被新的环境键命中；图片文件 URL 同时隔离浏览器和 CDN 缓存。
    $attachments->rows['qrcode/routine/combination_4_9806_0.jpg'] = ['att_dir' => '/uploads/old-release.jpg'];
    $name = 'combination_4_9806_0.jpg';
    $release = $service->getRoutineQrcodePath(4, 9806, 3, $name);
    check($release === 'https://local.invalid/uploads/qrcode/routine/release_'.$name, 'release URL has its own cache identity');
    check($qrTransport->requests[0]['scene'] === 'id=4&spid=9806&type=3', 'scene retains exact activity and authenticated promoter');
    check($qrTransport->requests[0]['options'] === ['page' => 'pages/activity/goods_details/index', 'width' => 280,
        'env_version' => 'release', 'check_path' => true], 'release request targets published page');
    check($service->getRoutineQrcodePath(4, 9806, 3, $name) === $release && count($qrTransport->requests) === 1,
        'same environment reuses saved attachment without generating a new code');
    $environment = 'trial';
    $trial = $service->getRoutineQrcodePath(4, 9806, 3, $name);
    check($trial === 'https://local.invalid/uploads/qrcode/routine/trial_'.$name, 'trial never reuses release image');
    check($qrTransport->requests[1]['options']['env_version'] === 'trial'
        && $qrTransport->requests[1]['options']['check_path'] === false, 'trial request explicitly opens experience version');
    check($qrTransport->requests[1]['scene'] === $qrTransport->requests[0]['scene'], 'environment switch preserves attribution parameters');
    check($service->getRoutineQrcodePath(4, 9806, 3, $name) === $trial && count($qrTransport->requests) === 2,
        'trial lookup and save use the same attachment key');
    $environment = 'release';
    check($service->getRoutineQrcodePath(4, 9806, 3, $name) === $release && count($qrTransport->requests) === 2,
        'switch back preserves original release cache');
    $environment = 'trial';
    $service->getRoutineQrcodePath(4, 123, 3, 'combination_4_123_0.jpg');
    check($qrTransport->requests[2]['scene'] === 'id=4&spid=123&type=3', 'different promoters get independent code and attribution');
    $service->getRoutineQrcodePath(7, 9806, 3, 'combination_7_9806_0.jpg');
    check($qrTransport->requests[3]['scene'] === 'id=7&spid=9806&type=3', 'different activities retain their own identity');
    $before = count($attachments->rows);
    $service->getRoutineQrcodePath(4, 9806, 3, $name, false);
    check(count($qrTransport->requests) === 5 && count($attachments->rows) === $before, 'non-cached call generates code without saving an attachment');
    $environment = 'develop';
    $service->getRoutineQrcodePath(4, 9806, 3, $name);
    check($qrTransport->requests[5]['options']['env_version'] === 'develop', 'development environment is explicit and isolated');
    $environment = 'invalid';
    try { $service->getRoutineQrcodePath(4, 9806, 3, $name); throw new \RuntimeException('invalid config accepted'); }
    catch (\think\exception\ValidateException $error) { check(count($qrTransport->requests) === 6, 'invalid environment fails before network or storage'); }
    // 兼容未传第四参数的现有调用，不把默认环境偷偷切为体验版。
    \mohe\services\wechat\MiniProgram::appCodeUnlimit('id=1');
    check($qrTransport->requests[6]['options'] === ['env_version' => 'release', 'check_path' => true], 'legacy helper call defaults to release');
    try { \mohe\services\wechat\MiniProgram::appCodeUnlimit('id=1', '', 0, 'bad'); throw new \RuntimeException('invalid helper config accepted'); }
    catch (\InvalidArgumentException $error) { check(count($qrTransport->requests) === 7, 'helper rejects invalid environment independently'); }
    echo 'QR ENVIRONMENT PASS '.$checks."\n";
}
