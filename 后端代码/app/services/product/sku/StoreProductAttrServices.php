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

namespace app\services\product\sku;


use app\dao\product\sku\StoreProductAttrDao;
use app\services\BaseServices;
use app\services\order\StoreCartServices;
use app\services\product\product\StoreProductServices;
use mohe\exceptions\AdminException;
use mohe\traits\OptionTrait;

/**
 * Class StoreProductAttrService
 * @package app\services\product\sku
 * @mixin StoreProductAttrDao
 */
class StoreProductAttrServices extends BaseServices
{

    use OptionTrait;

    /**
     * StoreProductAttrServices constructor.
     * @param StoreProductAttrDao $dao
     */
    public function __construct(StoreProductAttrDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 生成规格唯一值
     * @param int $id
     * @param string $sku
     * @return false|string
     */
    public function createAttrUnique(int $id, string $sku)
    {
        return substr(md5($id . $sku . uniqid(true)), 12, 8);
    }

    /**
     * 根据根据前端规格数据获取sku
     * @param array $value
     * @param string
     */
    public function getSku(array $value)
    {
        $sku = '';
        if ($value) {
            $detail = [];
            $count = count($value['detail'] ?? []);
            for ($i=1; $i<=$count ; $i++) {
                $detail[] = trim($value['value' . $i]);
            }
            $sku = implode(',', $detail);
        }
        return $sku;
    }

    /**
     * 添加商品属性数据判断
     * @param array $attrList
     * @param array $valueList
     * @param int $productId
     * @param int $type
     * @param int $is_vip
     * @param int $validate
     * @return array
     */
    public function validateProductAttr(array $attrList, array $valueList, int $productId, int $type = 0, int $is_vip = 0, int $validate = 1)
    {
        $attrValueList = [];
        $attrNameList = [];
        foreach ($attrList as $index => $attr) {
            if (!isset($attr['value'])) {
                throw new AdminException('请输入规则名称!');
            }
            $attr['value'] = trim($attr['value']);
            if (!isset($attr['value'])) {
                throw new AdminException('请输入规则名称!!');
            }
            if (!isset($attr['detail']) || !count($attr['detail'])) {
                throw new AdminException('请输入属性名称!');
            }
            foreach ($attr['detail'] as $k => $attrValue) {
				if (is_string($attrValue)) {
					$attrValue = trim($attrValue);
					$attrValueTemp = $attrValue;
					$attrValue = [];
					$attrValue['value'] = $attrValueTemp;
					$attrValue['pic'] = '';
				} else {
					$attrValue['value'] = trim($attrValue['value']);
					$attrValue['pic'] = isset($attr['add_pic']) && $attr['add_pic'] ? trim($attrValue['pic'] ?? '') : '';
				}
                if (empty($attrValue)) {
                    throw new AdminException('请输入正确的属性');
                }
                $attrValueList[] = $attrValue;
                $attr['detail'][$k] = $attrValue;
            }
            $attrNameList[] = $attr['value'];
            $attrList[$index] = $attr;
        }
		$result = ['attr' => $attrList, 'value' => $valueList];
        $attrCount = count($attrList);
        foreach ($valueList as $index => $value) {
            if (!isset($value['detail']) || count($value['detail']) != $attrCount) {
                throw new AdminException('请填写正确的商品信息');
            }
            if (!isset($value['price']) || !is_numeric($value['price']) || floatval($value['price']) != $value['price']) {
                throw new AdminException('请填写正确的商品价格');
            }
			if ($type == 4) {
				if (!isset($value['integral']) || !is_numeric($value['integral']) || floatval($value['integral']) != $value['integral']) {
					throw new AdminException('请填写正确的商品兑换积分');
				}
				if (isset($value['price']) && $value['price'] <= 0 && isset($value['integral']) && $value['integral'] <= 0) {
					throw new AdminException('积分商品兑换积分和价格不能同时为空');
				}
			}
            if (!isset($value['stock']) || !is_numeric($value['stock']) || intval($value['stock']) != $value['stock']) {
                throw new AdminException('请填写正确的商品库存');
            }
            // 销售单位换算数：须为大于 0；院装产品的 1～99 整数由 StoreProductServices 强制校验
            if (isset($value['unit_convert']) && $value['unit_convert'] !== '' && $value['unit_convert'] !== null) {
                if (!is_numeric($value['unit_convert']) || (float)$value['unit_convert'] <= 0) {
                    throw new AdminException('销售单位换算数必须为大于0的数字');
                }
            }
            // 库存精度只认 0（整数）或 2（院装两位）；由商品保存服务按院装开关强制写入
            if (isset($value['decimal_scale']) && $value['decimal_scale'] !== '' && $value['decimal_scale'] !== null) {
                if (!is_numeric($value['decimal_scale']) || !in_array((int)$value['decimal_scale'], [0, 2], true)
                    || (float)$value['decimal_scale'] != (int)$value['decimal_scale']) {
                    throw new AdminException('库存精度只允许0或2');
                }
            }
			//供应商结算价 不用成本价
            if (!($value['settle_price'] ?? 0) && (!isset($value['cost']) || !is_numeric($value['cost']) || floatval($value['cost']) != $value['cost'])) {
                throw new AdminException('请填写正确的商品成本价格');
            }
            if ($validate && (!isset($value['pic']) || empty($value['pic']))) {
                throw new AdminException('请上传商品规格图片');
            }
            if ($is_vip && (!isset($value['vip_price']) || !$value['vip_price'])) {
                throw new AdminException('会员价格不能为0');
            }
            foreach ($value['detail'] as $attrName => $attrValue) {
                //如果attrName 存在空格 则这个规格key 会出现两次
                unset($valueList[$index]['detail'][$attrName]);
                $attrName = trim($attrName);
                $attrValue = trim($attrValue);
                if (!in_array($attrName, $attrNameList, true)) {
                    throw new AdminException($attrName . '规则不存在');
                }
				if (!in_array($attrValue, array_column($attrValueList, 'value'), true)) {
					throw new AdminException($attrName . '属性不存在');
				}
                if (empty($attrName)) {
                    throw new AdminException('请输入正确的属性');
                }
                $valueList[$index]['detail'][$attrName] = $attrValue;
            }
        }
        $attrGroup = [];
        $valueGroup = [];
        foreach ($attrList as $k => $value) {
            $attrGroup[] = [
                'product_id' => $productId,
                'attr_name' => $value['value'],
                'attr_values' => $value['detail'],
                'type' => $type
            ];
        }
        /** @var StoreProductAttrValueServices $storeProductAttrValueServices */
        $storeProductAttrValueServices = app()->make(StoreProductAttrValueServices::class);
        $skuArray = $storeProductAttrValueServices->getSkuArray(['product_id' => $productId, 'type' => $type], 'unique,stock,vip_price,level_price,brokerage_two,brokerage', 'suk');

		$store_product_id = $this->getItem('store_product_id', 0);
		$productSkuArray = [];
		if ($store_product_id) {
			$productSkuArray = $storeProductAttrValueServices->getSkuArray(['product_id' => $store_product_id, 'type' => 0], '*', 'suk');
		}
		$stockData = [];
        foreach ($valueList as $k => $value) {
            $sku = implode(',', array_map(function ($key) use ($value) {
                return $value['detail'][$key];
            }, array_column($attrList, 'value')));
			$unique = $skuArray[$sku]['unique'] ?? $this->createAttrUnique($productId, $sku);
			$stockDataOne = [
				'product_id' => $productId,
				'unique' => $unique,
			];
			$stock = $value['stock'];
			if ($type === 0 && !isset($value['pm'])) {
				// 普通商品资料保存：已有 SKU 保留库内库存，新建 SKU 强制 0；禁止用前端库存覆盖
				if (isset($skuArray[$sku])) {
					$stock = $skuArray[$sku]['stock'];
				} else {
					$stock = 0;
				}
			} elseif (isset($value['pm'])) {
				//pm是 1 是增加 0 是减少（库存管理快捷改库存）
				if ($value['pm'] == 1) {
					$stock = (int)$value['stock'] + (int)$value['inventory'];
				} else {
					$stock = (int)$value['stock'] - (int)$value['inventory'];
					if ($stock < 0) {
						throw new AdminException('出库数量不能大于库存');
					}
				}
				$stockDataOne['pm'] = $value['pm'];
				$stockDataOne['stock'] = abs($value['inventory']);
			} else {
				$stockDataOne['stock'] = abs($value['stock']);
				$stockDataOne['pm'] = 1;
			}
			if ($type !== 0 && isset($stockDataOne['stock']) && $stockDataOne['stock'] > 0) {
				$stockData[] = $stockDataOne;
			}
            $valueGroup[$sku] = [
                'product_id' => $productId,
                'suk' => $sku,
                'price' => $value['price'],
				'price_range_status' => isset($value['price_range_min']) && isset($value['price_range_max']) ? ($value['price'] == $value['price_range_min'] && $value['price'] == $value['price_range_max'] ? 0 : 1) : 0,
				'price_range_min' => $value['price_range_min'] ?? 0,
				'price_range_max' => $value['price_range_max'] ?? 0,
				'integral' => $value['integral'] ?? 0,
				'settle_price' => $productSkuArray[$sku]['settle_price'] ?? $value['settle_price'] ?? 0.00,//供应商结算价
                'cost' => !($value['cost'] ?? 0) && ($value['settle_price'] ?? 0.00) ? ($value['settle_price'] ?? 0.00) : ($value['cost'] ?? 0.00),//供应端：去除成本价
                'ot_price' => $value['ot_price'] ?? 0.00,
                'stock' => $stock ?? 0,
				'old_stock' => $skuArray[$sku]['stock'] ?? 0,
                'unique' => $unique,
                'image' => $value['pic'],
                'bar_code' => $value['bar_code'] ?? '',
                'weight' => $value['weight'] ?? 0,
                'volume' => $value['volume'] ?? 0,
                'stock_unit' => mb_substr((string)($value['stock_unit'] ?? ''), 0, 32),
                'sale_unit' => mb_substr((string)($value['sale_unit'] ?? ''), 0, 32),
                'unit_convert' => (isset($value['unit_convert']) && is_numeric($value['unit_convert']) && (float)$value['unit_convert'] > 0)
                    ? (((float)$value['unit_convert'] == (int)$value['unit_convert']
                        && (int)$value['unit_convert'] >= 1 && (int)$value['unit_convert'] <= 99)
                        ? (int)$value['unit_convert']
                        : round((float)$value['unit_convert'], 4))
                    : 1,
                'decimal_scale' => in_array((int)($value['decimal_scale'] ?? 0), [0, 2], true) ? (int)$value['decimal_scale'] : 0,
                'type' => $type,
                'quota' => $value['quota'] ?? 0,
                'quota_show' => $value['quota'] ?? 0,
                'brokerage' => $value['brokerage'] ?? 0,
                'brokerage_two' => $value['brokerage_two'] ?? 0,
                'vip_price' => $value['vip_price'] ?? 0,
				'level_price' => isset($value['level_price']) && $value['level_price'] ? json_encode($value['level_price']) : '',
                'code' => $value['code'] ?? '',
                'product_type' => $value['product_type'] ?? 0,
				'is_default_select' => $value['is_default_select'] ?? 0,
				'is_show' => $value['is_show'] ?? 1,
				'virtual_list' => $productSkuArray[$sku]['virtual_list'] ?? $value['virtual_list'] ?? [],
				'reservation_time_data' => $productSkuArray[$sku]['reservation_time_data'] ?? $value['reservation_time_data'] ?? [],
				'disk_info' => $productSkuArray[$sku]['disk_info'] ?? $value['disk_info'] ?? '',
				'write_times' => $productSkuArray[$sku]['write_times'] ?? $value['write_times'] ?? 1,
				'write_valid' => $productSkuArray[$sku]['write_valid'] ?? $value['write_valid'] ?? 1,
				'write_days' => $productSkuArray[$sku]['write_days'] ?? $value['write_days'] ?? $value['days'] ?? 0,
				'write_start' => ($productSkuArray[$sku]['section_time'][0] ?? $value['section_time'][0] ?? '') ? strtotime($productSkuArray[$sku]['section_time'][0] ?? $value['section_time'][0] ?? '') : 0,
				'write_end' => ($productSkuArray[$sku]['section_time'][1] ?? $value['section_time'][1] ?? '') ?  strtotime($productSkuArray[$sku]['section_time'][1] ?? $value['section_time'][1] ?? '') : 0,
			];
        }
        if (!count($attrGroup) || !count($valueGroup)) {
            throw new AdminException('请设置至少一个属性!');
        }
        return compact('result', 'attrGroup', 'valueGroup', 'stockData');
    }

    /**
     * 保存商品规格
     * @param array $data
     * @param int $id
     * @param int $type
     * @return bool|mixed|\think\Collection
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function saveProductAttr(array $data, int $id, int $type = 0)
    {
        /** @var StoreProductAttrValueServices $storeProductAttrValueServices */
        $storeProductAttrValueServices = app()->make(StoreProductAttrValueServices::class);

        // 校验→写 attr/result→删/增 SKU 同一事务，保证行锁覆盖到删除（含 ERP 等直调路径）
        return $this->transaction(function () use ($data, $id, $type, $storeProductAttrValueServices) {
            $valueGroup = $data['valueGroup'] ?? [];
            $updateSuks = array_column($valueGroup, 'suk');
            $oldSuks = [];
            $oldAttrValue = $storeProductAttrValueServices->getSkuArray(['product_id' => $id, 'type' => $type], '*', 'suk');
            if ($oldAttrValue) $oldSuks = array_column($oldAttrValue, 'suk');
            $delSuks = array_merge(array_diff($oldSuks, $updateSuks));
            // 普通商品：删除/改名规格前禁止静默丢库存（须在写 attr/result 之前拦截）
            if ((int)$type === 0 && $delSuks) {
                $storeProductAttrValueServices->assertSkusCanBeDeleted($id, $delSuks, $oldAttrValue ?: []);
            }

            $this->setAttr($data['attrGroup'], $id, $type);
            /** @var StoreProductAttrResultServices $storeProductAttrResultServices */
            $storeProductAttrResultServices = app()->make(StoreProductAttrResultServices::class);
            $storeProductAttrResultServices->setResult($data['result'], $id, $type);

            $dataAll = [];
            $res1 = $res2 = $res3 = true;
            foreach ($valueGroup as $item) {
                if ($oldSuks && in_array($item['suk'], $oldSuks) && isset($oldAttrValue[$item['suk']])) {
                    $attrId = $oldAttrValue[$item['suk']]['id'];
                    unset($item['suk'], $item['unique']);
                    // 普通商品编辑：禁止覆盖实物库存
                    if ((int)$type === 0) {
                        unset($item['stock'], $item['defective_stock'], $item['sum_stock'], $item['old_stock']);
                    }
                    $item['virtual_list'] = json_encode($item['virtual_list']);
                    $res1 = $res1 && $storeProductAttrValueServices->update($attrId, $item);
                } else {
                    if ((int)$type === 0) {
                        $item['stock'] = 0;
                        $item['defective_stock'] = $item['defective_stock'] ?? 0;
                        $item['sum_stock'] = $item['sum_stock'] ?? 0;
                    }
                    $dataAll[] = $item;
                }
            }
            if ($delSuks) {
                $res2 = $storeProductAttrValueServices->del($id, $type, $delSuks);
            }
            if ($dataAll) {
                $res3 = $storeProductAttrValueServices->saveAll($dataAll);
            }
            if ($res1 && $res2 && $res3) {
                return $valueGroup;
            }
            throw new AdminException('商品规格信息保存失败');
        });
    }


    /**
     * 获取商品规格
     * @param array $where
     * @return array
     */
    public function getProductAttr(array $where)
    {
        return $this->dao->getProductAttr($where);
    }

	/**
	 * 获取商品规格详情
	 * @param int $id
	 * @param int $uid
	 * @param int $cartNum //是否查询购物车数量
	 * @param int $type //活动类型 attr_value表
	 * @param int $productId
	 * @param array $productInfo
	 * @param int $discount //限时折扣
	 * @return array
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function getProductAttrDetailCache(int $id, int $uid, int $cartNum = 0, int $type = 0, int $productId = 0, array $productInfo = [], int $discount = -1)
	{
		$attrDetail = $this->dao->cacheTag()->remember('attr_' . $id . '_' . $type . '_' . $productId, function () use ($productId, $id, $type) {
			$attrDetail = $this->dao->getProductAttr(['product_id' => $id, 'type' => $type]);
			if (!$attrDetail && $type > 0 && $productId) {//活动商品未获取到规格信息
				$attrDetail = $this->dao->getProductAttr(['product_id' => $productId, 'type' => 0]);
			}
			return $attrDetail;
		}, 600);

		/** @var StoreProductAttrValueServices $storeProductAttrValueService */
		$storeProductAttrValueService = app()->make(StoreProductAttrValueServices::class);
		$_values = $this->dao->cacheTag()->remember('attr_value_' . $id . '_' . $type, function () use ($storeProductAttrValueService, $id, $type) {
			return $storeProductAttrValueService->getProductAttrValue(['product_id' => $id, 'type' => $type], ['reservationTimeData']);
		}, 600);

		if ($productId == 0) {
			$productId = $id;
		}

		/** @var StoreProductServices $storeProductService */
		$storeProductService = app()->make(StoreProductServices::class);
		$vip_price = true;

		if (!$storeProductService->vipIsOpen(!!($productInfo['is_vip'] ?? 0))) $vip_price = false;

		$cartNumList = [];
		$activityAttr = [];
		if ($cartNum) {
			/** @var StoreCartServices $storeCartService */
			$storeCartService = app()->make(StoreCartServices::class);
			$unique = array_column($_values, 'unique');
			$cartNumList = $storeCartService->cacheTag('Cart_Nums_' . $uid)->remember(md5(json_encode($unique)),
				function () use ($storeCartService, $unique, $id, $uid) {
					return $storeCartService->getUserCartNums($unique, $id, $uid);
				}
				, 600);
		}

		$values = [];
		$field = $type ? 'stock,price' : 'stock';
		$storeProducts = $this->dao->cacheTag()->remember('attr_sku_' . $productId . '_' . $type, function () use ($storeProductAttrValueService, $productId, $field) {
			return $storeProductAttrValueService->getSkuArray(['product_id' => $productId, 'type' => 0], $field, 'suk');
		});
		foreach ($_values as $value) {
			if ($cartNum) {
				$value['cart_num'] = $cartNumList[$value['unique']] ?? 0;
			}
			if (!$vip_price) $value['vip_price'] = 0;
			$value['product_stock'] = $storeProducts[$value['suk']]['stock'] ?? 0;
			if ($discount != -1) $value['price'] = bcmul((string)$value['price'], (string)bcdiv((string)$discount, '100', 2), 2);
			if ($type) {
				$value['product_price'] = $storeProducts[$value['suk']]['price'] ?? 0;
				$attrs = explode(',', $value['suk']);
				$count = count($attrs);
				for ($i = 0; $i < $count; $i++) {
					$activityAttr[$i][] = $attrs[$i];
				}
			}
			$value['small_image'] = get_thumb_water($value['image']);
			$value['stock'] = $value['is_show'] == 1 ? $value['stock'] : 0;
			$values[$value['suk']] = $value;
		}
		$attrResult = app()->make(StoreProductAttrResultServices::class)->getResult(['product_id' => $id, 'type' => $type]);
		$attrPics = [];
		foreach ($attrResult['attr'] as $resultAttr) {
			foreach ($resultAttr['detail'] as $detail) {
				if (is_string($detail)) {
					$detail = [
						'value' => $detail,
						'pic' => '',
					];
				}
				$attrPics[$resultAttr['value']][$detail['value']] = $detail['pic'] ?? '';
			}
		}
		foreach ($attrDetail as $k => $v) {
			$attr = $v['attr_values'];
			//活动商品只展示参与活动sku
			if ($type && $activityAttr && $a = array_merge(array_intersect($v['attr_values'], $activityAttr[$k]))) {
				$attrDetail[$k]['attr_values'] = $a;
				$attr = $a;
			}
			$is_pic = 0;
			foreach ($attr as $kk => $vv) {
				$attrDetail[$k]['attr_value'][$kk]['attr'] = $vv;
				$attrDetail[$k]['attr_value'][$kk]['check'] = false;
				$attrDetail[$k]['attr_value'][$kk]['pic'] = $attrPics[$v['attr_name']][$vv] ?? 0;
				if ($attrDetail[$k]['attr_value'][$kk]['pic']) {
					$is_pic = 1;
				}
			}
			$attrDetail[$k]['is_pic'] = $is_pic;
		}
		return [$attrDetail, $values];
	}

    /**
     * 获取商品规格详情
     * @param int $id
     * @param int $uid
     * @param int $cartNum //是否查询购物车数量
     * @param int $type //活动类型 attr_value表
     * @param int $productId
     * @param array $productInfo
     * @param int $discount //限时折扣
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getProductAttrDetail(int $id, int $uid, int $cartNum = 0, int $type = 0, int $productId = 0, array $productInfo = [], int $discount = -1)
    {
        $attrDetail = $this->dao->getProductAttr(['product_id' => $id, 'type' => $type]);
		if (!$attrDetail && $type > 0 && $productId) {//活动商品未获取到规格信息
			$attrDetail = $this->dao->getProductAttr(['product_id' => $productId, 'type' => 0]);
		}
        /** @var StoreProductAttrValueServices $storeProductAttrValueService */
        $storeProductAttrValueService = app()->make(StoreProductAttrValueServices::class);
        $_values = $storeProductAttrValueService->getProductAttrValue(['product_id' => $id, 'type' => $type], ['reservationTimeData']);
        if ($productId == 0) $productId = $id;
        /** @var StoreProductServices $storeProductService */
        $storeProductService = app()->make(StoreProductServices::class);
        if (!$productInfo) {
            $productInfo = $storeProductService->get($productId, ['is_vip']);
        }
        $vip_price = true;
        if (!$storeProductService->vipIsOpen(!!$productInfo['is_vip'])) $vip_price = false;

        $cartNumList = [];
        $activityAttr = [];
        if ($cartNum) {
            /** @var StoreCartServices $storeCartService */
            $storeCartService = app()->make(StoreCartServices::class);
            //真实用户
            if ($uid) {
                $cartNumList = $storeCartService->getUserCartNums(array_column($_values, 'unique'), $id, $uid);
            } else {
                //虚拟用户
                $touristUid = $this->getItem('touristUid');
                if ($touristUid) {
                    $cartNumList = $storeCartService->getUserCartNums(array_column($_values, 'unique'), $id, $touristUid, 'tourist_uid');
                }
            }
        }
        $values = [];
        $field = $type ? 'stock,price' : 'stock';
        $storeProducts = $storeProductAttrValueService->getSkuArray(['product_id' => $productId, 'type' => 0], $field, 'suk');
        foreach ($_values as $value) {
            if ($cartNum) {
                $value['cart_num'] = $uid || $touristUid ? ($cartNumList[$value['unique']] ?? 0) : 0;
            }
            if (!$vip_price) $value['vip_price'] = 0;
            $value['product_stock'] = $storeProducts[$value['suk']]['stock'] ?? 0;
            if ($discount != -1) $value['price'] = bcmul((string)$value['price'], (string)bcdiv((string)$discount, '100', 2), 2);
            if ($type) {
                $value['product_price'] = $storeProducts[$value['suk']]['price'] ?? 0;
                $attrs = explode(',', $value['suk']);
                $count = count($attrs);
                for ($i = 0; $i < $count; $i++) {
                    $activityAttr[$i][] = $attrs[$i];
                }
				if ($value['product_stock'] <= 0) {//原商品规格无库存
					$value['stock'] = 0;
				}
            }
			$value['small_image'] = get_thumb_water($value['image']);
			$value['stock'] = $value['is_show'] == 1 ? $value['stock'] : 0;
            $values[$value['suk']] = $value;
        }
		$attrResult = app()->make(StoreProductAttrResultServices::class)->getResult(['product_id' => $id, 'type' => $type]);
		$attrPics = [];
		if ($attrResult && isset($attrResult['attr'])) {
			foreach ($attrResult['attr'] as $resultAttr) {
				foreach ($resultAttr['detail'] as $detail) {
					if (is_string($detail)) {
						$detail = [
							'value' => $detail,
							'pic' => '',
						];
					}
					$attrPics[$resultAttr['value']][$detail['value']] = $detail['pic'] ?? '';
				}
			}
		}
        foreach ($attrDetail as $k => $v) {
            $attr = $v['attr_values'];
            //活动商品只展示参与活动sku
            if ($type && $activityAttr && $a = array_merge(array_intersect($v['attr_values'], $activityAttr[$k]))) {
                $attrDetail[$k]['attr_values'] = $a;
                $attr = $a;
            }
			$is_pic = 0;
			foreach ($attr as $kk => $vv) {
				$attrDetail[$k]['attr_value'][$kk]['attr'] = $vv;
				$attrDetail[$k]['attr_value'][$kk]['check'] = false;
				$attrDetail[$k]['attr_value'][$kk]['pic'] = $attrPics[$v['attr_name']][$vv] ?? '';
				if ($attrDetail[$k]['attr_value'][$kk]['pic']) {
					$is_pic = 1;
				}
			}
			$attrDetail[$k]['is_pic'] = $is_pic;
		}
		return [$attrDetail, $values];
    }

    /**
     * 删除一条数据
     * @param int $id
     * @param int $type
     */
    public function del(int $id, int $type)
    {
        $this->dao->del($id, $type);
    }


    /**
     * 设置规格
     * @param array $data
     * @param int $id
     * @param int $type
     * @return bool
     * @throws \Exception
     */
    public function setAttr(array $data, int $id, int $type)
    {
		foreach ($data as &$item){
			$item['attr_values'] = array_column($item['attr_values'], 'value');
		}
        if ($data) {
            $this->dao->del($id, $type);
            $res = $this->dao->saveAll($data);
            if (!$res) throw new AdminException('规格保存失败');
        }
        return true;
    }
}
