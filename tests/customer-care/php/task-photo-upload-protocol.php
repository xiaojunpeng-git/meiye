<?php
declare(strict_types=1);

// 精确验证上传媒体类型例外，不伪造已登录业务操作或放宽其他移动接口。
$backend = getenv('CUSTOMER_CARE_BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
foreach (['MobileApiException','MobileApiResponse','MobileRequestMetadata'] as $name) {
    require_once $backend . '/app/services/mobile/protocol/' . $name . '.php';
}

use app\services\mobile\protocol\MobileApiException;
use app\services\mobile\protocol\MobileRequestMetadata;

final class CarePhotoProtocolRequest
{
    public array $headers;
    public string $path = 'api/mobile/merchant/customer-care/service-photo';
    public string $verb = 'POST';
    public array $body = [];
    public function __construct() {
        $this->headers = [
            'Content-Type'=>'multipart/form-data; boundary=care-photo-boundary',
            'X-Mobile-Contract-Version'=>'mobile-merchant-v1',
            'X-Mobile-Client-Session-Id'=>'care-photo-session', 'X-Mobile-Platform'=>'WEB',
            'X-Mobile-Request-Id'=>'care-photo-request',
            'Authorization'=>'Bearer ' . str_repeat('a',32),
            'X-Mobile-Active-Context-Id'=>'care-active-context',
            'X-Mobile-State-Context-Id'=>'care-state-context',
        ];
    }
    public function header($name,$default='') { return $this->headers[$name] ?? $default; }
    public function method() { return $this->verb; }
    public function pathinfo() { return $this->path; }
    public function get($key,$default=null) { return $default; }
    public function post($key,$default=null) { return $this->body[$key] ?? $default; }
}
$passed = 0;
$check = static function (bool $ok,string $label) use (&$passed): void {
    if (!$ok) throw new RuntimeException('FAIL '.$label); $passed++; echo 'PASS '.$label."\n";
};
$request = new CarePhotoProtocolRequest();
$check(MobileRequestMetadata::merchant($request)['activeContextId'] === 'care-active-context', '客情照片POST允许真实multipart媒体类型');
$request->path = 'api/mobile/merchant/growth/asset/upload';
$check(MobileRequestMetadata::merchant($request)['contractVersion'] === 'mobile-merchant-v1', '原成长证据上传保持可用');
$reject = static function ($request,string $code,string $label) use ($check): void {
    try { MobileRequestMetadata::merchant($request); throw new RuntimeException('accepted '.$label); }
    catch (MobileApiException $error) { $check($error->mobileCode() === $code,$label); }
};
foreach (['api/mobile/merchant/customer-care/actions/create-care-task','api/mobile/merchant/customer-care/workbench','api/mobile/merchant/customer-care/service-photo/extra','api/mobile/merchant/customer-care/service-photo-fake'] as $path) {
    $r = new CarePhotoProtocolRequest(); $r->path = $path; $reject($r,'UNSUPPORTED_MEDIA_TYPE','非精确上传路由仍拒绝multipart');
}
$r = new CarePhotoProtocolRequest(); $r->verb = 'PUT'; $reject($r,'UNSUPPORTED_MEDIA_TYPE','上传例外不扩展到其他方法');
$r = new CarePhotoProtocolRequest(); unset($r->headers['Authorization']); $reject($r,'AUTH_REQUIRED','上传仍要求Bearer身份');
$r = new CarePhotoProtocolRequest(); $r->headers['Cookie']='session=fake'; $reject($r,'CREDENTIAL_FORBIDDEN','上传仍禁止Cookie');
$r = new CarePhotoProtocolRequest(); $r->body['token']='fake'; $reject($r,'CREDENTIAL_FORBIDDEN','上传仍禁止表单携带token');
$r = new CarePhotoProtocolRequest(); unset($r->headers['X-Mobile-Active-Context-Id']); $reject($r,'CONTRACT_HEADER_INVALID','上传仍校验上下文');
$r = new CarePhotoProtocolRequest(); $r->path='api/mobile/merchant/customer-care/workbench'; $r->headers['Content-Type']='application/json';
$check(MobileRequestMetadata::merchant($r)['requestId'] === 'care-photo-request','原JSON业务请求保持可用');
echo 'TASK_PHOTO_UPLOAD_PROTOCOL=PASS checks='.$passed."\n";
