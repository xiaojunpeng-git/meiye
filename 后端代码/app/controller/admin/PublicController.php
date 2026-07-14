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

namespace app\controller\admin;


use app\Request;
use app\services\other\CacheServices;
use app\services\system\attachment\SystemAttachmentServices;
use mohe\services\CacheService;
use think\Response;
use think\facade\Db;

class PublicController
{

    /**
     * 下载文件
     * @param string $key
     * @return Response|\think\response\File
     */
    public function download(string $key = '')
    {
        if (!$key) {
            return Response::create()->code(500);
        }
        $fileName = CacheService::get($key);
        if (is_array($fileName) && isset($fileName['path']) && isset($fileName['fileName']) && $fileName['path'] && $fileName['fileName'] && file_exists($fileName['path'])) {
            CacheService::delete($key);
            return download($fileName['path'], $fileName['fileName']);
        }
        return Response::create()->code(500);
    }

    /**
     * 获取erp开关
     * @return mixed
     */
    public function getErpConfig()
    {
        return app('json')->success(['open_erp' => !!sys_config('erp_open')]);
    }

    /**扫码上传
     * @param Request $request
     * @param $upload_type
     * @return \think\Response
     */
    public function scanUpload(Request $request)
    {
        [$file, $upload_type, $cache, $uploadToken, $type, $relation_id, $pid] = $request->postMore([
            ['file', 'file'],
            ['upload_type', 0],
            ['cache', ''],
            ['uploadToken', ''],
            ['type', 0],
            ['relation_id', 0],
            ['pid', 0]
        ], true);
        if (!$cache || !$uploadToken) {
            return app('json')->fail('参数有误，无法上传！');
        }
        $cacheServices = app()->make(CacheServices::class);
        $service = app()->make(SystemAttachmentServices::class);
        if ($cacheServices->getDbCache($cache, '') == '' || $cacheServices->getDbCache($cache, '') != $uploadToken) {
            return app('json')->fail('请重新扫码上传！');
        }
        $service->storeUpload((int)$pid, $file, $relation_id, $type, $upload_type, $uploadToken);
        return app('json')->success('上传成功！');
    }

    /**
     * 服务器信息
     * @return \think\Response
     * @author wuhaotian
     * @email 442384644@qq.com
     * @date 2024/9/24
     */
    public function getSystemInfo()
    {
        $SERVER_SOFTWARE = isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : '无法获取';
        $info['server'] = [
            ['name' => '服务器系统', 'require' => '类UNIX', 'value' => PHP_OS],
            ['name' => 'WEB环境', 'require' => 'Apache/Nginx/IIS', 'value' => $SERVER_SOFTWARE],
        ];
        $gd_info = function_exists('gd_info') ? gd_info() : array();
        $info['environment'] = [
            ['name' => 'PHP版本', 'require' => '7.1-7.4', 'value' => phpversion()],
            ['name' => 'MySql版本', 'require' => '5.7', 'value' => Db::query("SELECT VERSION()")[0]['VERSION()']],
            ['name' => 'MySqli', 'require' => '开启', 'value' => function_exists('mysqli_connect')],
            ['name' => 'Openssl', 'require' => '开启', 'value' => function_exists('openssl_encrypt')],
            ['name' => 'Session', 'require' => '开启', 'value' => function_exists('session_start')],
            ['name' => 'Safe_Mode', 'require' => '开启', 'value' => !ini_get('safe_mode')],
            ['name' => 'GD', 'require' => '开启', 'value' => !empty($gd_info['GD Version'])],
            ['name' => 'Curl', 'require' => '开启', 'value' => function_exists('curl_init')],
            ['name' => 'Bcmath', 'require' => '开启', 'value' => function_exists('bcadd')],
            ['name' => 'Upload', 'require' => '开启', 'value' => (bool)ini_get('file_uploads')],
        ];

        $info['permissions'] = [
            ['name' => 'backup', 'require' => '读写', 'value' => is_readable(root_path('backup')) && is_writable(root_path('backup'))],
            ['name' => 'public', 'require' => '读写', 'value' => is_readable(root_path('public')) && is_writable(root_path('public'))],
            ['name' => 'runtime', 'require' => '读写', 'value' => is_readable(root_path('runtime')) && is_writable(root_path('runtime'))],
            ['name' => '.env', 'require' => '读写', 'value' => is_readable(root_path() . '.env') && is_writable(root_path() . '.env')],
            ['name' => '.version', 'require' => '读写', 'value' => is_readable(root_path() . '.version') && is_writable(root_path() . '.version')],
            ['name' => '.constant', 'require' => '读写', 'value' => is_readable(root_path() . '.constant') && is_writable(root_path() . '.constant')],
        ];
		$info['process'] = [
			['name' => '长链接', 'require' => '开启', 'value' => !!config('swoole.websocket.enable')],
			['name' => '定时任务', 'require' => '开启', 'value' => true],
			['name' => '消息队列', 'require' => '开启', 'value' => !!config('swoole.queue.enable')],
		];
        return app('json')->success($info);
    }
}
