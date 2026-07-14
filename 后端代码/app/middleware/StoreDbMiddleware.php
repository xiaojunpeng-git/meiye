<?php
declare (strict_types = 1);
namespace app\middleware;
use think\facade\Db;

class StoreDbMiddleware
{
    public function handle($request, \Closure $next)
    {
        // ========== 1. 获取当前请求的【真实访问域名】 ==========
        // Swoole模式下，TP6的request对象可以直接获取真实域名，兼容反向代理！
        $host = $request->host(true);
        // 去除端口号 (如：m1.007.cc3798.com:8080 → m1.007.cc3798.com)
        $host = explode(':', $host)[0];
        // 统一转小写，防止大小写匹配失败
        $host = strtolower(trim($host));
        // 可选：自动去除www前缀，www.m1.xxx.com 和 m1.xxx.com 指向同一个库
        if (strpos($host, 'www.') === 0) {
            $host = substr($host, 4);
        }
//        $file="/www/wwwroot/007.cc3798.com/log.txt";
//        $content=file_get_contents($file);
//        file_put_contents($file,$content."域名文件".$host);
//        // ========== 2. 加载域名-数据库映射配置 ==========
//        $storeDbConfig = config('store_db');
//        // 匹配数据库配置：有域名用对应配置，无则用默认主库
//        $dbConfig = isset($storeDbConfig[$host]) ? $storeDbConfig[$host] : $storeDbConfig['default'];
//
//        // ========== 3. TP6原生方法：动态切换数据库配置【核心关键】 ==========
//        // 这行代码执行后，本次请求的所有数据库操作，全部使用匹配后的数据库！
//        // Swoole模式下，该配置只对【当前请求生效】，不影响其他请求，完美隔离！
//        Db::setConfig($dbConfig);
//
//        // ========== 4. 继续执行请求逻辑 ==========
        $response = $next($request);
        return $response;
    }
}
