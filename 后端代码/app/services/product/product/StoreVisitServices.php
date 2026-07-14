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

namespace app\services\product\product;


use app\dao\product\product\StoreVisitDao;
use app\services\BaseServices;

/**
 * Class StoreVisitService
 * @package app\services\product\product
 * @mixin StoreVisitDao
 */
class StoreVisitServices extends BaseServices
{
    public function __construct(StoreVisitDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     *  设置浏览信息
     * @param $uid
     * @param array $productIds
     * @param int $cate
     * @param string $type
     * @param string $content
     * @param int $min
     */
    public function setView($uid, $productIds = [], $product_type = 'product', $cate = 0, $type = '', $content = '', $min = 20)
    {
        if (!$productIds) {
            return true;
        }
        if (!is_array($productIds)) {
            $productIds = [$productIds];
        }
        $views = $this->dao->getColumn(['uid' => $uid, 'product_id' => $productIds, 'product_type' => $product_type], 'count,add_time,id', 'product_id');
        $cate = explode(',', $cate)[0];
        $dataAll = [];
        $time = time();
		/** @var StoreProductServices $productServices */
		$productServices = app()->make(StoreProductServices::class);
        foreach ($productIds as $key => $product_id) {
            if (isset($views[$product_id]) && $type != 'search') {
                $view = $views[$product_id] ?? [];
                if ($view && ($view['add_time'] + $min) < $time) {
                    $this->dao->update($view['id'], ['count' => $view['count'] + 1, 'add_time' => time()]);
                }
            } else {
				$product = $productServices->getCacheProductInfo((int)$product_id);
				$log_data['store_id'] =
                $data = [
                    'add_time' => $time,
                    'count' => 1,
                    'product_id' => $product_id,
                    'product_type' => $product_type,
                    'cate_id' => $cate,
                    'type' => $type,
                    'uid' => $uid,
                    'content' => $content,
					'store_id' => (($product['type'] ?? 0) == 1) ? $product['relation_id'] : 0
                ];
                $dataAll[] = $data;
            }
        }
        if ($dataAll) {
            if (!$this->dao->saveAll($dataAll)) {
                throw new ValidateException('添加失败');
            }
        }
        return true;
    }
}
