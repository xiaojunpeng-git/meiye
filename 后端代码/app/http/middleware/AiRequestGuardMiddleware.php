<?php
namespace app\http\middleware;

use app\Request;
use mohe\interfaces\MiddlewareInterface;

/** AI-only wire limit and error boundary: no generic body logger or exception renderer. */
final class AiRequestGuardMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, \Closure $next)
    {
        $configured=(array)config('cookie.header',[]);
        $headers=['Access-Control-Allow-Headers'=>($configured['Access-Control-Allow-Headers']??'Content-Type,Authori-zation,Authorization')
            .',X-Mohe-Ai-Client-Session-Id,X-Mohe-Ai-Run-Delivery-Token,X-Mohe-Ai-Generation,X-Mohe-Ai-Window-Token','Cache-Control'=>'no-store'];
        try {
            $length=(string)$request->header('content-length','');
            if ($length!=='' && (!ctype_digit($length) || strlen($length)>9 || (int)$length>262144)) { throw new \RuntimeException('AI_PAYLOAD_TOO_LARGE'); }
            // Raw bytes are checked before controller JSON parsing, including chunked requests.
            if (!in_array(strtoupper((string)$request->method()),['GET','HEAD','OPTIONS'],true) && strlen($request->getContent())>262144) { throw new \RuntimeException('AI_PAYLOAD_TOO_LARGE'); }
            $path=ltrim((string)$request->pathinfo(),'/');
            $chain=[InstallMiddleware::class,AllowOriginMiddleware::class];
            if (strpos($path,'adminapi/ai/')===0) {
                $chain[]=\app\http\middleware\admin\AdminAuthTokenMiddleware::class;
            } elseif (strpos($path,'cashierapi/v3/ai/')===0) {
                $chain[]=StationOpenMiddleware::class;
                $chain[]=\app\http\middleware\cashier\AuthTokenMiddleware::class;
                $chain[]=\app\http\middleware\cashier\ForceStoreSessionMiddleware::class;
            } elseif (strpos($path,'api/mobile/merchant/ai/')===0) {
                $chain[]=StationOpenMiddleware::class;
                $chain[]=\app\http\middleware\mobile\MobileTransportEnvelopeMiddleware::class;
                $chain[]=\app\http\middleware\mobile\MobileMerchantSessionMiddleware::class;
            } else { throw new \RuntimeException('AI_ROUTE_INVALID'); }
            // Direct chaining keeps authentication errors inside this boundary. Framework
            // middleware pipes individually catch/report exceptions before outer guards see them.
            $pipeline=array_reduce(array_reverse($chain),function ($nextHandler,$class) {
                return function ($req) use ($nextHandler,$class) { return app()->make($class)->handle($req,$nextHandler); };
            },$next);
            return $pipeline($request)->header($headers);
        } catch (\Throwable $exception) {
            $mobile=strpos((string)$request->pathinfo(),'api/mobile/merchant/')===0;
            return ($mobile
                ? json(['contractVersion'=>'mobile-merchant-v1','errorCode'=>'AI_REQUEST_FAILED','message'=>'请求未完成，请检查登录状态后重试。'],400)
                : json(['status'=>400,'msg'=>'请求未完成，请检查登录状态后重试。','data'=>[]],400))->header($headers);
        }
    }
}
