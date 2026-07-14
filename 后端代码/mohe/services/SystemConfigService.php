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

use app\services\system\config\SystemConfigServices;
use mohe\utils\Arr;
use think\facade\Config;
use think\facade\Db;

/** 获取系统配置服务类
 * Class SystemConfigService
 * @package service
 */
class SystemConfigService
{
    /**
     * 缓存前缀字符
     */
    const CACHE_SYSTEM = 'system_config';
    /**
     * 过期时间
     */
    const EXPIRE_TIME = 30 * 24 * 3600;

    /**
     * @var int
     */
    protected static $relationId = 0;

	/**
	 * @var int
	 */
	protected static $type = 0;

	/**
	 * @var int
	 */
	protected static $printId = 0;

    /**
     * 获取配置缓存前缀
     * @return string
     */
    public static function getTag()
    {
        return Config::get('cache.stores.redis.tag_prefix') . 'cahce_' . self::CACHE_SYSTEM;
    }

    /**
     * @param int $storeId
     */
    public static function setStore(int $storeId, int $printId = 0)
    {
        self::$relationId = $storeId;
		self::$type = 1;
		self::$printId = $printId;
    }

	/**
	 * @param int $supplier
	 */
	public static function setSupplier(int $supplier, int $printId = 0)
	{
		self::$relationId = $supplier;
		self::$type = 2;
		self::$printId = $printId;
	}

    /**
     * 获取单个配置效率更高
     * @param $key
     * @param string $default
     * @param bool $isCaChe 是否获取缓存配置
     * @return bool|mixed|string
     */
    public static function get(string $key, $default = '', bool $isCaChe = false)
    {
        $cacheName = self::CACHE_SYSTEM . ':' . $key . (self::$type ? '_' . self::$type : '') . (self::$relationId ? '_' . self::$relationId : '') . (self::$printId ? '_' . self::$printId : '');
		$type = self::$type;
		$relationId = self::$relationId;
		$printId = self::$printId;
		$callable = function () use ($key, $type, $relationId, $printId) {
            event('get.config');
            /** @var SystemConfigServices $service */
            $service = app()->make(SystemConfigServices::class);
            return $service->getConfigValue($key, $type, $relationId, $printId);
        };

        try {
            if ($isCaChe) {
                return $callable();
            }
            $value = CacheService::redisHandler(self::getTag())->remember($cacheName, $callable, self::EXPIRE_TIME);
			self::$relationId = 0;
			self::$type = 0;
            return $value;
        } catch (\Throwable $e) {
            return $default;
        }
    }

    /**
     * 获取多个配置
     * @param array $keys 示例 [['appid','1'],'appkey']
     * @param bool $isCaChe 是否获取缓存配置
     * @return array
     */
    public static function more(array $keys, bool $isCaChe = false)
    {
        $cacheName = self::CACHE_SYSTEM . ':' . md5(implode(',', $keys) . (self::$type ? '_' . self::$type : '') . (self::$relationId ? '_' . self::$relationId : ''). (self::$printId ? '_' . self::$printId : ''));
		$type = self::$type;
		$relationId = self::$relationId;
		$printId = self::$printId;
        $callable = function () use ($keys, $type, $relationId, $printId) {
            /** @var SystemConfigServices $service */
            $service = app()->make(SystemConfigServices::class);
            return Arr::getDefaultValue($keys, $service->getConfigAll($keys, $type, $relationId, $printId));
        };
        try {
            if ($isCaChe)
                return $callable();

            $value = CacheService::redisHandler(self::getTag())->remember($cacheName, $callable, self::EXPIRE_TIME);
            self::$relationId = 0;
			self::$type = 0;
            return $value;
        } catch (\Throwable $e) {
            return Arr::getDefaultValue($keys);
        }
    }

    /**
     * 清空配置缓存
     * @return bool|void
     */
    public static function clear()
    {
        try {
            return CacheService::redisHandler(self::getTag())->clear();
        } catch (\Throwable $e) {
            \think\facade\Log::error('清空配置缓存失败：原因：' . $e->getMessage());
            return false;
        }
    }

}
