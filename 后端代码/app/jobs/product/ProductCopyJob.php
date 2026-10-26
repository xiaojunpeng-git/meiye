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

namespace app\jobs\product;


use app\services\product\product\CopyTaobaoServices;
use app\services\product\product\StoreDescriptionServices;
use app\services\product\product\StoreProductServices;
use app\services\product\sku\StoreProductAttrValueServices;
use mohe\basic\BaseJobs;
use mohe\services\CacheService;
use mohe\traits\QueueTrait;
use think\facade\Log;

/**
 * 复制商品
 * Class ProductCopyJob
 * @package app\jobs
 */
class ProductCopyJob extends BaseJobs
{
    use QueueTrait;

    /**
     * 下载商品详情图片
     * @param $id
     * @param $description
     * @param $image
     * @param $count
     * @return bool
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function copyDescriptionImage($id, $description, $image, $count)
    {
        try {
            /** @var CopyTaobaoServices $copyTaobao */
            $copyTaobao = app()->make(CopyTaobaoServices::class);
            /** @var StoreDescriptionServices $storeDescriptionServices */
            $storeDescriptionServices = app()->make(StoreDescriptionServices::class);
            if (is_int(strpos($image, 'http'))) {
                $d_image = $image;
            } else {
                $d_image = 'http://' . ltrim($image, '\//');
            }
            $description_cache = CacheService::getTokenBucket('desc_images_' . $id);
            if ($description_cache === null) {
                $description_cache = $description;
                CacheService::setTokenBucket('desc_images_count' . $id, 0);
            }
            $res = $copyTaobao->downloadCopyImage($d_image);
            $description_cache = str_replace($image, $res, $description_cache);
            $desc_count = CacheService::getTokenBucket('desc_images_count' . $id) + 1;
            if ($desc_count == $count) {
                CacheService::clearToken('desc_images_' . $id);
                CacheService::clearToken('desc_images_count' . $id);
                $storeDescriptionServices->saveDescription((int)$id, $description_cache);
            } else {
                CacheService::setTokenBucket('desc_images_' . $id, $description_cache);
                CacheService::setTokenBucket('desc_images_count' . $id, $desc_count);
            }
        } catch (\Throwable $e) {
            Log::error('下载商品详情图片失败，失败原因:' . $e->getMessage());
        }
        return true;
    }

    /**
     * 下载商品轮播图片
     * @param $id
     * @param $image
     * @param $count
     * @return bool
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function copySliderImage($id, $image, $count)
    {
        try {
            /** @var CopyTaobaoServices $copyTaobao */
            $copyTaobao = app()->make(CopyTaobaoServices::class);
            /** @var StoreProductServices $storeProductServices */
            $storeProductServices = app()->make(StoreProductServices::class);
            //下载图片
            $res = $copyTaobao->downloadCopyImage($image);
            //获取缓存中的轮播图
            $slider_images = CacheService::getTokenBucket('slider_images_' . $id);
            //缓存为null则赋值[]
            if ($slider_images === null) $slider_images = [];
            //将下载的图片插入数组
            array_push($slider_images, $res);
            //如果$slider_images中图片数量和传入的$count相等，说明已经下载完成，写入商品表，如果不等则继续插入缓存
            if (count($slider_images) == $count) {
                CacheService::clearToken('slider_images_' . $id);
                $image = $slider_images[0];
                $slider_images = $slider_images ? json_encode($slider_images) : '';
				$storeProductServices->update($id, ['slider_image' => $slider_images, 'image' => $image]);
            } else {
                CacheService::setTokenBucket('slider_images_' . $id, $slider_images);
            }
        } catch (\Throwable $e) {
            Log::error('下载商品轮播图片失败，失败原因:' . $e->getMessage());
        }
        return true;
    }

	/**
	 * 采集商品规格，分批队列
	 * @param $id
	 * @param $type
	 * @return bool
	 */
	public function copyAttrImage($id, $type = 0)
	{
		if (!$id) {
			return true;
		}
		try {
			$productAttrValue = app()->make(StoreProductAttrValueServices::class);
			$attrValueList = $productAttrValue->getColumn(['product_id' => $id, 'type' => $type], 'image', 'id');
			foreach ($attrValueList as $value_id => $value_image) {
				ProductCopyJob::dispatch('copyAttrImageDo', [$value_id, $value_image]);
			}
		} catch (\Throwable $e) {
			Log::error('下载商品轮播图片失败，失败原因:' . $e->getMessage());
		}
		return true;
	}

	/**
	 * 采集规格图片
	 * @param $attrId
	 * @param $value_image
	 * @return bool
	 */
	public function copyAttrImageDo($attrId, $value_image)
	{
		if (!$attrId) {
			return true;
		}
		try {
			/** @var CopyTaobaoServices $copyTaobao */
			$copyTaobao = app()->make(CopyTaobaoServices::class);
			/** @var StoreProductAttrValueServices $storeProductAttrValueServices */
			$storeProductAttrValueServices = app()->make(StoreProductAttrValueServices::class);
			//下载图片
			$res = $copyTaobao->downloadCopyImage($value_image);
			$storeProductAttrValueServices->update($attrId, ['image' => $res]);
		} catch (\Throwable $e) {
			Log::error('下载商品规格图片失败，失败原因:' . $e->getMessage() . '_' . $e->getFile() . '_' . $e->getLine());
		}
		return true;
	}

}
