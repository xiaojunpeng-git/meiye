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
declare (strict_types=1);

namespace app\services\activity\integral;

use app\services\BaseServices;
use app\dao\activity\integral\StoreIntegralDao;
use app\services\diy\DiyServices;
use app\services\order\StoreOrderServices;
use app\services\product\ensure\StoreProductEnsureServices;
use app\services\product\label\StoreProductLabelServices;
use app\services\product\product\StoreDescriptionServices;
use app\services\product\product\StoreProductServices;
use app\services\product\sku\StoreProductAttrResultServices;
use app\services\product\sku\StoreProductAttrServices;
use app\services\product\sku\StoreProductAttrValueServices;
use app\jobs\product\ProductLogJob;
use app\services\store\SystemStoreServices;
use mohe\exceptions\AdminException;
use mohe\services\CacheService;
use think\exception\ValidateException;

/**
 *
 * Class StoreIntegralServices
 * @package app\services\activity\integral
 * @mixin StoreIntegralDao
 */
class StoreIntegralServices extends BaseServices
{
    const THODLCEG = 'ykGUKB';

	/**
	* 商品活动类型
	 */
	const TYPE = 4;

    /**
     * StoreIntegralServices constructor.
     * @param StoreIntegralDao $dao
     */
    public function __construct(StoreIntegralDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 获取指定条件下的条数
     * @param array $where
     */
    public function getCount(array $where)
    {
        $this->dao->count($where);
    }

    /**
     * 积分商品添加
     * @param int $id
     * @param array $data
     */
    public function saveData(int $id, array $data)
    {
        if ($data['freight'] == 2 && !$data['postage']) {
            throw new AdminException('请设置运费金额');
        }
        if ($data['freight'] == 3 && !$data['temp_id']) {
            throw new AdminException('请选择运费模版');
        }
        /** @var StoreProductServices $storeProductServices */
        $storeProductServices = app()->make(StoreProductServices::class);
        $productInfo = $storeProductServices->getOne(['is_del' => 0, 'is_verify' => 1, 'id' => $data['product_id']]);
        if (!$productInfo) {
            throw new AdminException('原商品已移入回收站');
        }
        if ($productInfo['is_vip_product'] || $productInfo['is_presale_product']) {
            throw new AdminException('该商品是预售或svip专享');
        }
        $data['product_type'] = $productInfo['product_type'];
		if (!$data['delivery_type']) {
			$data['delivery_type'] = $productInfo['delivery_type'];
		}
		$data['type'] = $productInfo['type'] ?? 0;
		$data['relation_id'] = $productInfo['relation_id'] ?? 0;
        $custom_form = $productInfo['custom_form'] ?? [];
        $data['custom_form'] = is_array($custom_form) ? json_encode($custom_form) : $custom_form;
		$data['system_form_id'] = $productInfo['system_form_id'] ?? 0;
		$data['system_form_type'] = $productInfo['system_form_type'] ?? 1;
		$store_label_id = $productInfo['store_label_id'] ?? [];
        $data['store_label_id'] = is_array($store_label_id) ? implode(',', $store_label_id) : $store_label_id;
        $ensure_id = $productInfo['ensure_id'] ?? [];
        $data['ensure_id'] = is_array($ensure_id) ? implode(',', $ensure_id) : $ensure_id;
        $specs = $productInfo['specs'] ?? [];
        $data['specs'] = is_array($specs) ? json_encode($specs) : $specs;
		$data['applicable_type'] = $productInfo['applicable_type'];
		$data['applicable_store_id'] = $productInfo['applicable_store_id'];
        $description = $data['description'];
        $detail = $data['attrs'];
		if ($detail) {
			foreach ($detail as $attr) {
				if ($attr['quota'] > $attr['stock']) {
					throw new AdminException('限量超过了商品库存');
				}
			}
		}
        $items = $data['items'];
		$data['image'] = $data['images'][0] ?? '';
        $data['images'] = json_encode($data['images']);
        $integral_data = array_column($detail, 'price', 'integral');
        $integrals = array_keys($integral_data);
        $data['integral'] = min($integrals);
        $data['price'] = $integral_data[$data['integral']] ?? 0;
        $data['quota'] = $data['quota_show'] = array_sum(array_column($detail, 'quota'));
        if ($data['quota'] > $storeProductServices->value(['id' => $data['product_id']], 'stock')) {
            throw new ValidateException('限量不能超过商品库存');
        }
        $data['stock'] = array_sum(array_column($detail, 'stock'));
        unset($data['section_time'], $data['description'], $data['attrs'], $data['items']);
        /** @var StoreDescriptionServices $storeDescriptionServices */
        $storeDescriptionServices = app()->make(StoreDescriptionServices::class);
        /** @var StoreProductAttrServices $storeProductAttrServices */
        $storeProductAttrServices = app()->make(StoreProductAttrServices::class);
        $this->transaction(function () use ($id, $data, $description, $detail, $items, $storeDescriptionServices, $storeProductAttrServices, $storeProductServices) {
            if ($id) {
				//重新编辑销量清空
				$data['sales'] = 0;
                $res = $this->dao->update($id, $data);
                if (!$res) throw new AdminException('修改失败');
            } else {
                if (!$storeProductServices->getOne(['is_del' => 0, 'is_verify' => 1, 'id' => $data['product_id']])) {
                    throw new AdminException('原商品已移入回收站');
                }
                $data['add_time'] = time();
                $res = $this->dao->save($data);
                if (!$res) throw new AdminException('添加失败');
                $id = (int)$res->id;
            }
            $storeDescriptionServices->saveDescription((int)$id, $description, 4);
			$storeProductAttrServices->setItem('store_product_id', $data['product_id']);
            $skuList = $storeProductAttrServices->validateProductAttr($items, $detail, (int)$id, 4);
			$storeProductAttrServices->reset();
            $valueGroup = $storeProductAttrServices->saveProductAttr($skuList, (int)$id, 4);

            $res = true;
            foreach ($valueGroup as $item) {
				if ($item['quota_show']) $res = $res && CacheService::setStock($item['unique'], (int)$item['quota_show'], 4);
            }
            if (!$res) {
                throw new AdminException('占用库存失败');
            }
        });
    }

    /**
     * 批量添加商品
     * @param array $data
     */
    public function saveBatchData(array $data)
    {
        /** @var StoreProductServices $service */
        $service = app()->make(StoreProductServices::class);
        /** @var StoreDescriptionServices $storeDescriptionServices */
        $storeDescriptionServices = app()->make(StoreDescriptionServices::class);
        /** @var StoreProductAttrResultServices $storeProductAttrResultServices */
        $storeProductAttrResultServices = app()->make(StoreProductAttrResultServices::class);
        if (!$data) {
            throw new ValidateException('请先添加产品!');
        }
        $attrs = [];
        foreach ($data['attrs'] as $k => $v) {
            $attrs[$v['product_id']][] = $v;
        }
        foreach ($attrs as $k => $v) {
            $productInfo = $service->getOne(['id' => $k]);
            $productInfo = is_object($productInfo) ? $productInfo->toArray() : [];
            if ($productInfo) {
                $product = [];
                $result = $storeProductAttrResultServices->getResult(['product_id' => $productInfo['id'], 'type' => 0]);
                $product['product_id'] = $productInfo['id'];
                $product['description'] = $storeDescriptionServices->getDescription(['product_id' => $productInfo['id'], 'type' => 0]);
                $product['attrs'] = $v;
                $product['items'] = $result['attr'];
                $product['is_show'] = isset($data['is_show']) ? $data['is_show'] : 0;
                $product['title'] = $productInfo['store_name'];
                $product['unit_name'] = $productInfo['unit_name'];
                $product['image'] = $productInfo['image'];
                $product['images'] = $productInfo['slider_image'];
                $product['num'] = 0;
                $product['is_host'] = 0;
                $product['once_num'] = 0;
                $product['sort'] = 0;
                $this->saveData(0, $product);
            }
        }
        return true;
    }

    /**
     * 积分商品列表
     * @param array $where
     * @return array
     */
    public function systemPage(array $where)
    {
        [$page, $limit] = $this->getPageValue();
        $list = $this->dao->getList($where, $page, $limit);
        $count = $this->dao->count($where);
        return compact('list', 'count');
    }

    /**
     * 获取详情
     * @param int $id
     * @return array|\think\Model|null
     */
    public function getInfo(int $id)
    {
        $info = $this->dao->get($id);
        if (!$info) {
            throw new ValidateException('查看的商品不存在!');
        }
        if ($info->is_del) {
            throw new ValidateException('您查看的积分商品已被删除!');
        }
        $info['price'] = floatval($info['price']);
        /** @var StoreDescriptionServices $storeDescriptionServices */
        $storeDescriptionServices = app()->make(StoreDescriptionServices::class);
        $info['description'] = $storeDescriptionServices->getDescription(['product_id' => $id, 'type' => 4]);
		/** @var StoreProductAttrValueServices $storeProductAttrValueServices */
		$storeProductAttrValueServices = app()->make(StoreProductAttrValueServices::class);
		$info['attrs'] = $storeProductAttrValueServices->attrList($id, $info['product_id'], 4);
        return $info;
    }

	/**
	 * 积分商品详情
	 * @param int $uid
	 * @param int $id
	 * @return array
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function integralDetail(int $uid, int $id)
    {
        $storeInfo = $this->dao->getOne(['id' => $id], '*', ['getTotal']);
        if (!$storeInfo) {
            throw new ValidateException('商品不存在');
        } else {
            $storeInfo = $storeInfo->toArray();
        }
		/** @var DiyServices $diyServices */
		$diyServices = app()->make(DiyServices::class);
		$infoDiy = $diyServices->getProductDetailDiy();
		//diy控制参数
		if (!isset($infoDiy['showService']) || !in_array(3, $infoDiy['showService'])) {
			$storeInfo['specs'] = [];
		}
        $siteUrl = sys_config('site_url');
        $storeInfo['image'] = set_file_url($storeInfo['image'], $siteUrl);
        $storeInfo['image_base'] = set_file_url($storeInfo['image'], $siteUrl);
        $storeInfo['sale_stock'] = 0;
        if ($storeInfo['stock'] > 0) $storeInfo['sale_stock'] = 1;
        /** @var StoreDescriptionServices $storeDescriptionService */
        $storeDescriptionService = app()->make(StoreDescriptionServices::class);
        $storeInfo['description'] = $storeDescriptionService->getDescription(['product_id' => $id, 'type' => 4]);
        $storeInfo['store_label'] = $storeInfo['ensure'] = [];
        if ($storeInfo['store_label_id']) {
            /** @var StoreProductLabelServices $storeProductLabelServices */
            $storeProductLabelServices = app()->make(StoreProductLabelServices::class);
			$storeInfo['store_label'] =  $storeProductLabelServices->getLabelCache($storeInfo['store_label_id'], ['id', 'label_name', 'style_type', 'color', 'bg_color', 'border_color', 'icon']);
        }
        if ($storeInfo['ensure_id'] && isset($infoDiy['showService']) && in_array(2, $infoDiy['showService'])) {
            /** @var StoreProductEnsureServices $storeProductEnsureServices */
            $storeProductEnsureServices = app()->make(StoreProductEnsureServices::class);
            $storeInfo['ensure'] = $storeProductEnsureServices->getColumn([['id', 'in', $storeInfo['ensure_id']]], 'id,name,image,desc');
        }
		$storeInfo['small_image'] = get_thumb_water($storeInfo['image']);
        $data['storeInfo'] = $storeInfo;

        /** @var StoreProductAttrServices $storeProductAttrServices */
        $storeProductAttrServices = app()->make(StoreProductAttrServices::class);
        [$productAttr, $productValue] = $storeProductAttrServices->getProductAttrDetail($id, $uid, 0, 4, $storeInfo['product_id']);
        $data['productAttr'] = $productAttr;
        $data['productValue'] = $productValue;
		$data['site_name'] = sys_config('site_name');
		$data['share_qrcode'] = sys_config('share_qrcode', 0);
		$data['product_poster_title'] = sys_config('product_poster_title', '');
        //浏览记录
        ProductLogJob::dispatch(['visit', ['uid' => $uid, 'id' => $id, 'product_id' => $storeInfo['product_id']], 'integral']);
        return $data;
    }

    /**
     * 修改销量和库存
     * @param $num
     * @param $integralId
     * @return bool
     */
    public function decIntegralStock(int $num, int $integralId, string $unique, int $store_id = 0, bool $decProductStock = false)
    {
		if (!$integralId) return true;
		$product_id = $this->dao->value(['id' => $integralId], 'product_id');
		$res = false;
		if ($product_id) {
			if ($unique) {
				/** @var StoreProductAttrValueServices $skuValueServices */
				$skuValueServices = app()->make(StoreProductAttrValueServices::class);
				//减去积分商品的sku库存增加销量（活动额度）
				$res = false !== $skuValueServices->decProductAttrStock($integralId, $unique, $num,4);
				if ($decProductStock) {
					$suk = $skuValueServices->value(['unique' => $unique, 'product_id' => $integralId, 'type' => 4], 'suk');
					$productUnique = $skuValueServices->value(['suk' => $suk, 'product_id' => $product_id, 'type' => 0], 'unique');
					/** @var StoreProductServices $services */
					$services = app()->make(StoreProductServices::class);
					$res = $res && $services->decProductStock($num, $product_id, (string)$productUnique, $store_id);
				}
			}
			//减去积分商品库存
			$res = $res && false !== $this->dao->decStockIncSales(['id' => $integralId, 'type' => 4], $num);
		}
		return $res;
    }

	/**
	 * 加库存减销量
	 * @param int $num
	 * @param int $integralId
	 * @param string $unique
	 * @param int $store_id
	 * @return bool
	 */
	public function incIntegralStock(int $num, int $integralId, string $unique, int $store_id = 0, bool $incProductStock = false)
	{
		if (!$integralId) return true;
		$product_id = $this->dao->value(['id' => $integralId], 'product_id');
		$res = false;
		if ($product_id) {
			if ($unique) {
				/** @var StoreProductAttrValueServices $skuValueServices */
				$skuValueServices = app()->make(StoreProductAttrValueServices::class);
				//增加积分商品的sku库存,减去销量
				$res = false !== $skuValueServices->incProductAttrStock($integralId, $unique, $num, 4);

				//积分商品sku
				$suk = $skuValueServices->value(['unique' => $unique, 'product_id' => $integralId, 'type' => 4], 'suk');
				//平台商品sku unique
				$productUnique = $skuValueServices->value(['suk' => $suk, 'product_id' => $product_id, 'type' => 0], 'unique');
				/** @var StoreProductServices $services */
				$services = app()->make(StoreProductServices::class);
				//增加普通商品库存
				if ($incProductStock) { $res = $res && $services->incProductStock($num, $product_id, (string)$productUnique, $store_id); }
			}
			//增加积分库存
			$res = $res && false !== $this->dao->incStockDecSales(['id' => $integralId, 'type' => 4], $num);
		}
		return $res;
	}


    /**
     * 获取一条积分商品
     * @param $id
     * @return mixed
     */
    public function getIntegralOne($id)
    {
        return $this->dao->validProduct($id, '*');
    }

	/**
	 * 验证积分商品下单库存限量
	 * @param int $uid
	 * @param int $integralId
	 * @param int $num
	 * @param string $unique
	 * @return array
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function checkoutProductStock(int $uid, int $integralId, int $num = 1, string $unique = '')
    {
        /** @var StoreProductAttrValueServices $attrValueServices */
        $attrValueServices = app()->make(StoreProductAttrValueServices::class);
        if ($unique == '') {
            $unique = $attrValueServices->value(['product_id' => $integralId, 'type' => 4], 'unique');
        }
		$StoreIntegralInfo = $this->dao->validProduct($integralId, '*,title as store_name');
        if (!$StoreIntegralInfo) {
            throw new ValidateException('该商品已下架或删除');
        }
        /** @var StoreOrderServices $orderServices */
        $orderServices = app()->make(StoreOrderServices::class);
        $userBuyCount = $orderServices->getBuyCount($uid, 4, $integralId);
        if ($StoreIntegralInfo['once_num'] < $num && $StoreIntegralInfo['once_num'] != -1) {
            throw new ValidateException('每个订单限购' . $StoreIntegralInfo['once_num'] . '件');
        }
        if ($StoreIntegralInfo['num'] < ($userBuyCount + $num) && $StoreIntegralInfo['num'] != -1) {
            throw new ValidateException('每人总共限购' . $StoreIntegralInfo['num'] . '件');
        }
		$attrInfo = $attrValueServices->getOne(['product_id' => $integralId, 'unique' => $unique, 'type' => 4]);
        if ($num > $attrInfo['quota']) {
            throw new ValidateException('该商品库存不足' . $num);
        }
        $product_stock = $attrValueServices->value(['product_id' => $StoreIntegralInfo['product_id'], 'suk' => $attrInfo['suk'], 'type' => 0], 'stock');
        if ($product_stock < $num) {
            throw new ValidateException('该商品库存不足' . $num);
        }
        if (!CacheService::checkStock($unique, $num, 4)) {
            throw new ValidateException('该商品库存不足' . $num);
        }
        return [$attrInfo, $unique, $StoreIntegralInfo];
    }

	/**
	 * 获取积分商品列表
	 * @param array $where
	 * @param int $uid
	 * @return array
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function getIntegralList(array $where, int $uid = 0)
    {
		/** @var SystemStoreServices $storeServices */
		$storeServices = app()->make(SystemStoreServices::class);
		$entryRules = $storeServices->getStoreIdByEntryRules($uid);
		$shop_operation_type = $entryRules['shop_operation_type'] ?? 1;
		[$page, $limit] = $this->getPageValue();
		if ($shop_operation_type == 3) {//单店模式不返回
			$storeId = $entryRules['store_id'] ?? 0;
			if ($storeId) {
				$where['applicable_store_id'] = $storeId;
			} else {
				return [];
			}
		}
        $list = $this->dao->getList($where, $page, $limit, 'id,product_id,image,title,integral,price,sales,stock');
		if ($list) {
			foreach ($list as &$item) {
				/** @var StoreProductServices $storeProductServices */
				$storeProductServices = app()->make(StoreProductServices::class);
				$item['brand_name'] = $storeProductServices->productIdByBrandName((int)$item['product_id']);
			}
		}
        return $list;
    }

    /**
     * 获取全部积分商品
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getAllIntegralList(array $where)
    {
        $list = $this->dao->getList($where, 0, 0, 'id,image,title,integral,price,sales');
        return $list;
    }
}
