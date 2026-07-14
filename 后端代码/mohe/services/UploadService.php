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

namespace mohe\services;

use app\services\system\config\SystemStorageServices;
use mohe\services\upload\Upload;

/**
 * Class UploadService
 * @package mohe\services
 */
class UploadService
{

    protected static $upload = [];

    /**
     * @param null $type
     * @return Upload|mixed
     */
    public static function init($type = null, $is_cache = false)
    {
        if (is_null($type)) {
            $type = (int)sys_config('upload_type', 1);
        }
        if ($is_cache && isset(self::$upload['upload_' . $type]) && self::$upload['upload_' . $type]) {
            return self::$upload['upload_' . $type];
        }
        $type = (int)$type;
        $config = [];
        switch ($type) {
            case 2://七牛
                $config = [
                    'accessKey' => sys_config('qiniu_accessKey'),
                    'secretKey' => sys_config('qiniu_secretKey'),
                ];
                break;
            case 3:// oss 阿里云
                $config = [
                    'accessKey' => sys_config('accessKey'),
                    'secretKey' => sys_config('secretKey'),
                ];
                break;
            case 4:// cos 腾讯云
                $config = [
                    'accessKey' => sys_config('tengxun_accessKey'),
                    'secretKey' => sys_config('tengxun_secretKey'),
                    'appid' => sys_config('tengxun_appid'),
                ];
				break;
			case 5://京东云
				$config = [
					'accessKey' => sys_config('jd_accessKey'),
					'secretKey' => sys_config('jd_secretKey'),
					'storageRegion' => sys_config('jd_storage_region'),
				];
				break;
			case 6://华为云
				$config = [
					'accessKey' => sys_config('hw_accessKey'),
					'secretKey' => sys_config('hw_secretKey'),
				];
				break;
			case 7://天翼云
				$config = [
					'accessKey' => sys_config('ty_accessKey'),
					'secretKey' => sys_config('ty_secretKey'),
				];
                break;
        }
        $thumb = SystemConfigService::more(['thumb_big_height', 'thumb_big_width', 'thumb_mid_height', 'thumb_mid_width', 'thumb_small_height', 'thumb_small_width',]);
        $water = SystemConfigService::more([
            'image_watermark_status',
            'watermark_type',
            'watermark_image',
            'watermark_opacity',
            'watermark_position',
            'watermark_rotate',
            'watermark_text',
            'watermark_text_angle',
            'watermark_text_color',
            'watermark_text_size',
            'watermark_x',
            'watermark_y']);
        $config = array_merge($config, ['thumb' => $thumb], ['water' => $water]);

        //除了本地存储其他都去获取配置信息
        if (1 !== $type) {
            /** @var SystemStorageServices $make */
            $make = app()->make(SystemStorageServices::class);
            $res = $make->getConfig($type);
            $config['uploadUrl'] = $res['domain'];
            $config['storageName'] = $res['name'];
            if ($type != 5) $config['storageRegion'] = $res['region'];
        }

        return self::$upload['upload_' . $type] = new Upload($type, $config);
    }

    /**
     * 生辰缩略图水印实例化
     * @param string $filePath
     * @param bool $is_remote_down
     * @return Upload
     */
    public static function getOssInit(string $filePath, bool $is_remote_down = false)
    {
        //本地
        $uploadUrl = sys_config('site_url');
        if ($uploadUrl && strpos($filePath, $uploadUrl) !== false) {
            $filePath = explode($uploadUrl, $filePath)[1] ?? '';
            return self::init(1)->setFilepath($filePath);
        }
		$fileArr = parse_url($filePath);
		$fileHost = ($fileArr['scheme'] ?? '') . '://' . ($fileArr['host'] ?? '');
		/** @var SystemStorageServices $storageServices */
		$storageServices = app()->make(SystemStorageServices::class);
		$storageArr = $storageServices->cacheTag()->remember('storage_list', function () use ($storageServices) {
			return $storageServices->getStorageList([], ['domain','type']);
		});
		foreach ($storageArr as $item) {
			if ($fileHost == $item['domain']) {
				return self::init($item['type'])->setFilepath($filePath);
			}
		}
        //远程图片 下载到本地处理
        if ($is_remote_down) {
            try {
                /** @var DownloadImageService $down */
                $down = app()->make(DownloadImageService::class);
                $data = $down->path('thumb_water')->downloadImage($filePath);
                $filePath = $data['path'] ?? '';
            } catch (\Throwable $e) {
                //下载失败 传入原地址
            }
        }
        return self::init(1)->setFilepath($filePath);
    }
}
