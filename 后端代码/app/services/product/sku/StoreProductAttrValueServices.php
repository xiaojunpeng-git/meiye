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


use app\dao\product\sku\StoreProductAttrValueDao;
use app\jobs\product\ProductStockTips;
use app\services\activity\bargain\StoreBargainServices;
use app\services\activity\combination\StoreCombinationServices;
use app\services\activity\discounts\StoreDiscountsServices;
use app\services\activity\integral\StoreIntegralServices;
use app\services\activity\seckill\StoreSeckillServices;
use app\services\BaseServices;
use app\services\product\branch\StoreBranchProductAttrValueServices;
use app\services\product\inventory\StoreProductStockDetailServices;
use app\services\product\product\StoreCardRelatedServices;
use app\services\product\product\StoreProductServices;
use app\services\store\SystemStoreServices;
use mohe\exceptions\AdminException;
use mohe\services\CacheService;
use mohe\traits\ServicesTrait;
use think\exception\ValidateException;
use think\facade\Db;

/**
 * Class StoreProductAttrValueService
 * @package app\services\product\sku
 * @mixin StoreProductAttrValueDao
 */
class StoreProductAttrValueServices extends BaseServices
{

    use ServicesTrait;

    /**
     * StoreProductAttrValueServices constructor.
     * @param StoreProductAttrValueDao $dao
     */
    public function __construct(StoreProductAttrValueDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 获取单规格规格
     * @param array $where
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getOne(array $where)
    {
        return $this->dao->getOne($where);
    }

	/**
	 * 获取商品规格信息
	 * @param int $id
	 * @param int $pid
	 * @param int $type
	 * @return array
	 */
	public function attrList(int $id, int $pid, int $type = 0)
	{
		/** @var StoreProductAttrResultServices $storeProductAttrResultServices */
		$storeProductAttrResultServices = app()->make(StoreProductAttrResultServices::class);
		$result = $storeProductAttrResultServices->value(['product_id' => $id, 'type' => $type], 'result');
		$items = json_decode($result, true)['attr'];
		$productAttr = $this->getAttr($items, $pid, 0);
		$activityAttr = $this->getAttr($items, $id, $type);
		foreach ($productAttr as $pk => &$pv) {
			if ($type == 3){
				$sv['ot_price'] = isset($pv['_checked']) && $pv['_checked'] ? $pv['ot_price'] : $pv['price'];
			}
			foreach ($activityAttr as &$sv) {
				if ($pv['detail'] == $sv['detail']) {
					if ($type == 3){
						$sv['ot_price'] = isset($sv['_checked']) && $sv['_checked'] ? $sv['ot_price'] : $sv['price'];
					}
					$sv['stock'] = $pv['stock'];
					$productAttr[$pk] = $sv;
				}
			}
			$productAttr[$pk]['detail'] = json_decode($productAttr[$pk]['detail']);
		}
		$attrs['items'] = $items;
		$attrs['value'] = $productAttr;
		foreach ($items as $key => $item) {
			$header[] = ['title' => $item['value'], 'key' => 'value' . ($key + 1), 'align' => 'center', 'minWidth' => 80];
		}
		$header[] = ['title' => '图片', 'slot' => 'pic', 'align' => 'center', 'minWidth' => 120];
		$header[] = ['title' => '成本价', 'key' => 'cost', 'align' => 'center', 'minWidth' => 80];
		if ($type == 3) {
			$header[] = ['title' => '日常售价', 'key' => 'ot_price', 'align' => 'center', 'minWidth' => 80];
		} elseif ($type == 4) {
			$header[] = ['title' => '金额', 'key' => 'price', 'type' => 1, 'align' => 'center', 'minWidth' => 80];
		} else {
			$header[] = ['title' => '划线价', 'key' => 'ot_price', 'align' => 'center', 'minWidth' => 80];
		}
		if ($type == 1) {
			$header[] = ['title' => '秒杀价', 'key' => 'price', 'type' => 1, 'align' => 'center', 'minWidth' => 80];
		} elseif ($type == 2) {
			$header[] = ['title' => '砍价起始金额', 'slot' => 'price', 'align' => 'center', 'minWidth' => 80];
			$header[] = ['title' => '砍价最低价', 'slot' => 'min_price', 'align' => 'center', 'minWidth' => 80];
		} elseif ($type == 3) {
			$header[] = ['title' => '拼团价', 'key' => 'price', 'type' => 1, 'align' => 'center', 'minWidth' => 80];
		} elseif ($type == 4) {
			$header[] = ['title' => '兑换积分', 'key' => 'integral', 'type' => 1, 'align' => 'center', 'minWidth' => 80];
		}
		$header[] = ['title' => '库存', 'key' => 'stock', 'align' => 'center', 'minWidth' => 80];
		if (in_array($type, [1, 3, 4])) {
			$header[] = ['title' => $type == 4 ? '兑换次数' : '限量', 'key' => 'quota', 'type' => 1, 'align' => 'center', 'minWidth' => 80];
		} else {
			$header[] = ['title' => '限量', 'slot' => 'quota', 'align' => 'center', 'minWidth' => 80];
		}
		$header[] = ['title' => '重量(KG)', 'key' => 'weight', 'align' => 'center', 'minWidth' => 80];
		$header[] = ['title' => '体积(m³)', 'key' => 'volume', 'align' => 'center', 'minWidth' => 80];
		$header[] = ['title' => '商品条形码', 'key' => 'bar_code', 'align' => 'center', 'minWidth' => 80];
		$header[] = ['title' => '商品编号', 'key' => 'code', 'align' => 'center', 'minWidth' => 80];
		$attrs['header'] = $header;
		return $attrs;
	}

	/**
	 * 获取规格
	 * @param $attr
	 * @param $id
	 * @param $type
	 * @return array
	 */
	public function getattr($attr, $id, $type)
	{
		foreach ($attr as  $k => $v){
			foreach ($v['detail'] as $key => $item){
				if(isset($item['value'])){
					$attr[$k]['detail'][$key] = $item['value'];
				}
			}
		}
		$value = attr_format($attr)[1];
		$valueNew = [];
		$count = 0;
		if ($type == 2) {
			/** @var StoreBargainServices $storeBargainServices */
			$storeBargainServices = app()->make(StoreBargainServices::class);
			$min_price = $storeBargainServices->value(['id' => $id], 'min_price');
		} else {
			$min_price = 0;
		}
		$sukValueArr = $this->getSkuArray(['product_id' => $id, 'type' => $type], 'bar_code,code,cost,price,ot_price,stock,image as pic,weight,volume,brokerage,brokerage_two,quota,quota_show,settle_price,integral', 'suk');
		foreach ($value as $key => $item) {
			$detail = $item['detail'];
//            sort($item['detail'], SORT_STRING);
			$suk = implode(',', $item['detail']);
			$sukValue = $sukValueArr[$suk] ?? [];
			if ($sukValue) {
				foreach (array_values($detail) as $k => $v) {
					$valueNew[$count]['value' . ($k + 1)] = $v;
				}
				$valueNew[$count]['detail'] = json_encode($detail);
				$valueNew[$count]['pic'] = $sukValue['pic'] ?? '';
				$valueNew[$count]['price'] = $sukValue['price'] ? floatval($sukValue['price']) : 0;
				$valueNew[$count]['integral'] = $sukValue['integral'] ?: 0;
				$valueNew[$count]['settle_price'] = $sukValue['cost'] ? floatval($sukValue['settle_price']) : 0;
				$valueNew[$count]['cost'] = $sukValue['cost'] ? floatval($sukValue['cost']) : 0;
				$valueNew[$count]['ot_price'] = isset($sukValue['ot_price']) ? floatval($sukValue['ot_price']) : 0;
				$valueNew[$count]['stock'] = $sukValue['stock'] ? intval($sukValue['stock']) : 0;
				$valueNew[$count]['quota'] = isset($sukValue['quota_show']) && $sukValue['quota_show'] ? intval($sukValue['quota_show']) : 0;
				$valueNew[$count]['code'] = $sukValue['code'] ?? '';
				$valueNew[$count]['bar_code'] = $sukValue['bar_code'] ?? '';
				$valueNew[$count]['weight'] = $sukValue['weight'] ? floatval($sukValue['weight']) : 0;
				$valueNew[$count]['volume'] = $sukValue['volume'] ? floatval($sukValue['volume']) : 0;
				$valueNew[$count]['brokerage'] = $sukValue['brokerage'] ? floatval($sukValue['brokerage']) : 0;
				$valueNew[$count]['brokerage_two'] = $sukValue['brokerage_two'] ? floatval($sukValue['brokerage_two']) : 0;
				switch ($type) {
					case 1://秒杀
						$valueNew[$count]['_checked'] = true;
						break;
					case 2://砍价
						$valueNew[$count]['min_price'] = $min_price ? floatval($min_price) : 0;
						$valueNew[$count]['opt'] = true;
						break;
					case 3://拼团
						$valueNew[$count]['_checked'] = true;
						break;
					case 4://积分
						$valueNew[$count]['integral'] = isset($sukValue['integral']) ? floatval($sukValue['integral']) : 0;
						$valueNew[$count]['_checked'] = true;
						break;
					default:
						$valueNew[$count]['_checked'] = false;
						$valueNew[$count]['opt'] = false;
						break;
				}
				$count++;
			}
		}
		return $valueNew;
	}

    /**
     * 根据活动商品unique查看原商品unique
     * @param string $unique
     * @param int $activity_id
     * @param int $type
     * @param array|string[] $field
     * @return array|mixed|string|\think\Model|null
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getUniqueByActivityUnique(string $unique, int $activity_id, int $type = 1, array $field = ['unique'])
    {
        if ($type == 0) return $unique;
        $attrValue = $this->dao->get(['unique' => $unique, 'product_id' => $activity_id, 'type' => $type], ['id', 'suk', 'product_id']);
        if (!$attrValue) {
            return '';
        }
        switch ($type) {
            case 1://秒杀
                /** @var StoreSeckillServices $activityServices */
                $activityServices = app()->make(StoreSeckillServices::class);
                break;
            case 2://砍价
                /** @var StoreBargainServices $activityServices */
                $activityServices = app()->make(StoreBargainServices::class);
                break;
            case 3://拼团
                /** @var StoreCombinationServices $activityServices */
                $activityServices = app()->make(StoreCombinationServices::class);
                break;
            case 4://积分
                /** @var StoreIntegralServices $activityServices */
                $activityServices = app()->make(StoreIntegralServices::class);
                break;
            case 5://套餐
                /** @var StoreDiscountsServices $activityServices */
                $activityServices = app()->make(StoreDiscountsServices::class);
                break;
            default:
                /** @var StoreProductServices $activityServices */
                $activityServices = app()->make(StoreProductServices::class);
                break;

        }
        $product_id = $activityServices->value(['id' => $activity_id], 'product_id');
        if (!$product_id) {
            return '';
        }
        if (count($field) == 1) {
            return $this->dao->value(['suk' => $attrValue['suk'], 'product_id' => $product_id, 'type' => 0], $field[0] ?? 'unique');
        } else {
            return $this->dao->get(['suk' => $attrValue['suk'], 'product_id' => $product_id, 'type' => 0], $field);
        }

    }

    /**
     * 删除一条数据
     * @param int $id
     * @param int $type
     * @param array $suk
     * @return bool
     */
    public function del(int $id, int $type, array $suk = [])
    {
        return $this->dao->del($id, $type, $suk);
    }

    /**
     * 普通商品删除/改名规格前校验：有库存或未完成盘点则禁止删除（不得静默丢库存）
     * @param int $productId
     * @param array $delSuks 待删除的 suk 列表
     * @param array $oldAttrValue 以 suk 为键的旧规格行
     */
    public function assertSkusCanBeDeleted(int $productId, array $delSuks, array $oldAttrValue): void
    {
        if ($productId <= 0 || !$delSuks) {
            return;
        }
        foreach ($delSuks as $suk) {
            $row = $oldAttrValue[$suk] ?? null;
            if (!$row) {
                continue;
            }
            $skuId = (int)($row['id'] ?? 0);
            $unique = (string)($row['unique'] ?? '');
            if ($skuId > 0) {
                $locked = \think\facade\Db::name('store_product_attr_value')
                    ->where('id', $skuId)
                    ->lock(true)
                    ->find();
                if ($locked) {
                    $row = $locked;
                    $unique = (string)($locked['unique'] ?? $unique);
                }
            }
            $stock = (string)($row['stock'] ?? '0');
            $defective = (string)($row['defective_stock'] ?? '0');
            if (bccomp($stock, '0', 4) !== 0 || bccomp($defective, '0', 4) !== 0) {
                throw new AdminException(sprintf(
                    '商品ID %d 规格「%s」仍有库存（良品 %s / 残次品 %s），请先清零后再删除、改名或同步；改规格名等于删除旧规格并新建',
                    $productId,
                    (string)$suk,
                    $stock,
                    $defective
                ));
            }
            if ($unique === '') {
                continue;
            }
            // 未完成盘点单（status=0）明细挂有该 SKU
            $pendingCountId = \think\facade\Db::name('store_product_stock_detail')
                ->alias('d')
                ->join('store_product_stock_count c', 'c.id = d.order_id')
                ->where('d.stock_type', 3)
                ->where('d.product_id', $productId)
                ->where('d.unique', $unique)
                ->where('c.status', 0)
                ->value('c.id');
            if ($pendingCountId) {
                throw new AdminException(sprintf(
                    '商品ID %d 规格「%s」仍在未完成的盘点单（ID %s）中，请先完成或撤销盘点后再删除、改名或同步',
                    $productId,
                    (string)$suk,
                    (string)$pendingCountId
                ));
            }
            // 未完成请货（草稿/已申请/部分调拨）
            $reqSn = \think\facade\Db::name('store_stock_request_detail')
                ->alias('d')
                ->join('store_stock_request r', 'r.id = d.request_id')
                ->where(function ($q) use ($productId, $unique) {
                    $q->where(function ($q2) use ($productId, $unique) {
                        $q2->where('d.request_product_id', $productId)->where('d.request_unique', $unique);
                    })->whereOr(function ($q2) use ($productId, $unique) {
                        $q2->where('d.supply_product_id', $productId)->where('d.supply_unique', $unique);
                    });
                })
                ->whereIn('r.status', [0, 1, 2])
                ->order('r.id', 'desc')
                ->value('r.order_sn');
            if ($reqSn) {
                throw new AdminException(sprintf(
                    '商品ID %d 规格「%s」仍在未完成的请货单（%s）中，请先完成、取消或驳回后再删除、改名或同步',
                    $productId,
                    (string)$suk,
                    (string)$reqSn
                ));
            }
            // 调拨草稿，或已确认但仍可冲销（reversed_qty < qty）
            $tfSn = \think\facade\Db::name('store_stock_transfer_detail')
                ->alias('d')
                ->join('store_stock_transfer t', 't.id = d.transfer_id')
                ->where(function ($q) use ($productId, $unique) {
                    $q->where(function ($q2) use ($productId, $unique) {
                        $q2->where('d.from_product_id', $productId)->where('d.from_unique', $unique);
                    })->whereOr(function ($q2) use ($productId, $unique) {
                        $q2->where('d.to_product_id', $productId)->where('d.to_unique', $unique);
                    });
                })
                ->where(function ($q) {
                    $q->where('t.status', 0)
                        ->whereOr(function ($q2) {
                            $q2->where('t.status', 1)->whereRaw('d.reversed_qty < d.qty');
                        });
                })
                ->order('t.id', 'desc')
                ->value('t.order_sn');
            if ($tfSn) {
                throw new AdminException(sprintf(
                    '商品ID %d 规格「%s」仍在调拨单（%s）中（草稿或仍可冲销），请先处理后再删除、改名或同步',
                    $productId,
                    (string)$suk,
                    (string)$tfSn
                ));
            }
        }
    }

    /**
     * 永久删除/批量删除前：校验商品下全部 type=0 SKU 可否删除
     * @param int $productId
     */
    public function assertAllType0SkusCanBeDeleted(int $productId): void
    {
        if ($productId <= 0) {
            return;
        }
        $oldAttrValue = $this->getSkuArray(['product_id' => $productId, 'type' => 0], '*', 'suk');
        if (!$oldAttrValue) {
            return;
        }
        $this->assertSkusCanBeDeleted($productId, array_keys($oldAttrValue), $oldAttrValue);
    }

    /**
     * @param array $productIds
     */
    public function assertProductsSkusCanBeDeleted(array $productIds): void
    {
        foreach ($productIds as $productId) {
            $this->assertAllType0SkusCanBeDeleted((int)$productId);
        }
    }

    /**
     * 批量保存
     * @param array $data
     */
    public function saveAll(array $data)
    {
        $res = $this->dao->saveAll($data);
        if (!$res) throw new AdminException('规格保存失败');
        return $res;
    }

    /**
     * 获取sku
     * @param array $where
     * @param string $field
     * @param string $key
     * @return array
     */
    public function getSkuArray(array $where, string $field = 'unique,bar_code,cost,price,ot_price,stock,image as pic,weight,volume,brokerage,brokerage_two,quota,product_id,code,level_price', string $key = 'suk')
    {
        return $this->dao->getColumn($where, $field, $key);
    }

    /**
     * 交易排行榜
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function purchaseRanking()
    {
        $dlist = $this->dao->attrValue();
        /** @var StoreProductServices $proServices */
        $proServices = app()->make(StoreProductServices::class);
        $slist = $proServices->getProductLimit(['is_del' => 0], $limit = 20, 'id as product_id,store_name,sales * price as val');
        $data = array_merge($dlist, $slist);
        $last_names = array_column($data, 'val');
        array_multisort($last_names, SORT_DESC, $data);
        $list = array_splice($data, 0, 20);
        return $list;
    }

    /**
     * 获取商品的属性数量
     * @param $product_id
     * @param $unique
     * @param $type
     * @return int
     */
    public function getAttrvalueCount($product_id, $unique, $type)
    {
        return $this->dao->count(['product_id' => $product_id, 'unique' => $unique, 'type' => $type]);
    }

    /**
     * 获取唯一值下的库存
     * @param string $unique
     * @return int
     */
    public function uniqueByStock(string $unique)
    {
        if (!$unique) return 0;
        return $this->dao->uniqueByStock($unique);
    }

	/**
	 * 减销量,加库存
	 * @param int $productId
	 * @param string $unique
	 * @param int $num
	 * @param int $type
	 * @return bool|mixed
	 */
    public function decProductAttrStock(int $productId, string $unique, int $num, int $type = 0)
    {
        // 【库存铁律】type=0 实物库存禁止走旧增减接口；仅活动规格额度（type>0）可用
        if ((int)$type === 0) {
            throw new ValidateException('已停用：实物库存请通过「库存管理」或销售出库/退货统一服务处理');
        }
        $res = $this->dao->decStockIncSales([
            'product_id' => $productId,
            'unique' => $unique,
            'type' => $type
        ], $num);
        if ($res) {
            $this->workSendStock($productId, $unique, $type);
        }
        return $res;
    }

    /**
     * 减少销量增加库存
     * @param $productId
     * @param $unique
     * @param $num
     * @return bool
     */
    public function incProductAttrStock(int $productId, string $unique, int $num, int $type = 0)
    {
        // 【库存铁律】type=0 实物库存禁止走旧增减接口
        if ((int)$type === 0) {
            throw new ValidateException('已停用：实物库存请通过「库存管理」或销售出库/退货统一服务处理');
        }
        return $this->dao->incStockDecSales(['unique' => $unique, 'product_id' => $productId, 'type' => $type], $num);
    }

    /**
     * 库存预警消息提醒
     * @param int $productId
     * @param string $unique
     * @param int $type
     */
    public function workSendStock(int $productId, string $unique, int $type)
    {
        ProductStockTips::dispatchDo('sendTips', [$productId, $unique, $type]);
    }

    /**
     * 获取秒杀库存
     * @param int $productId
     * @param string $unique
     * @param bool $isNew
     * @return array|mixed|\think\Model|null
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getSeckillAttrStock(int $productId, string $unique, bool $isNew = false)
    {
        $key = md5('seclkill_attr_stock_' . $productId . '_' . $unique);
        $stock = CacheService::redisHandler()->get($key);
        if (!$stock || $isNew) {
            $stock = $this->dao->getOne(['product_id' => $productId, 'unique' => $unique, 'type' => 1], 'suk,quota');
            if ($stock) {
                CacheService::redisHandler()->set($key, $stock, 60);
            }
        }
        return $stock;
    }

    /**
     * @param $product_id
     * @param string $suk
     * @param string $unique
     * @param bool $is_new
     * @return int|mixed
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function getProductAttrStock(int $productId, string $suk = '', string $unique = '', $isNew = false)
    {
        if (!$suk && !$unique) return 0;
        $key = md5('product_attr_stock_' . $productId . '_' . $suk . '_' . $unique);
        $stock = CacheService::redisHandler()->get($key);
        if (!$stock || $isNew) {
            $where = ['product_id' => $productId, 'type' => 0];
            if ($suk) {
                $where['suk'] = $suk;
            }
            if ($unique) {
                $where['unique'] = $unique;
            }
            $stock = $this->dao->value($where, 'stock');
            CacheService::redisHandler()->set($key, $stock, 60);
        }
        return $stock;
    }

    /**
     * 根据商品id获取对应规格库存
     * @param int $productId
     * @param int $type
     * @return float
     */
    public function pidBuStock(int $productId, int $type = 0)
    {
        return $this->dao->pidBuStock($productId, $type);
    }

    /**
     * 更新sum_stock
     * @param array $uniques
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function updateSumStock(array $uniques)
    {
        /** @var StoreBranchProductAttrValueServices $storeValueService */
        $storeValueService = app()->make(StoreBranchProductAttrValueServices::class);
        $stockSumData = $storeValueService->getProductAttrValueStockSum($uniques ?? []);
        $this->dao->getList(['unique' => $uniques])->map(function ($item) use ($stockSumData) {
            if (isset($stockSumData[$item->unique])) {
                $data['sum_stock'] = $item->stock + $stockSumData[$item->unique];
            } else {
                $data['sum_stock'] = $item->stock;
            }
            $this->dao->update(['product_id' => $item['product_id'], 'unique' => $item['unique'], 'type' => $item['type']], $data);
        });
    }

    /**
     * 批量快速修改商品规格库存
     * @param int $id
     * @param array $data
     * @return int|string
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function saveProductAttrsStock(int $id, array $data, int $type = 0, int $relation_id = 0, int $adminId = 0, bool $isStockOrder = true)
    {
        /** @var StoreProductServices $productServices */
        $productServices = app()->make(StoreProductServices::class);
        // 全局实物库存锁顺序（与 changeSkuStock 一致）：先按 id 升序锁 SKU，再更新商品表（不先锁商品）
        $uniques = [];
        foreach ($data as $attr) {
            $uq = (string)($attr['unique'] ?? '');
            if ($uq !== '') {
                $uniques[$uq] = $uq;
            }
        }
        $lockedRows = $this->dao->lockAttrValuesByUniques(array_values($uniques), $id);
        $attrs = [];
        foreach ($lockedRows as $row) {
            $attrs[(string)$row['unique']] = $row;
        }
        $product = $productServices->get($id);
        if (!$product) {
            throw new ValidateException('商品不存在');
        }
        $product = is_object($product) ? $product->toArray() : $product;
		/** @var StoreProductReservationTimeServices $productReservationTimeServices */
		$productReservationTimeServices = app()->make(StoreProductReservationTimeServices::class);
		$reservationTimes = $productReservationTimeServices->getColumn(['product_id' => $id], '*', 'id');
		$stockDataAll = [];
        $time = time();
        $deltaGoodTotal = '0';
        $deltaDefTotal = '0';
        foreach ($data as $attr) {
            if (!isset($attrs[$attr['unique']])) continue;
			$update = []; // 每行重置，避免上一 SKU 字段残留
			if (isset($attr['reservation_time_data'])) {//预约商品
				$skuStock = 0;//sku库存
				$oldGood = (string)($attrs[$attr['unique']]['stock'] ?? 0);
				foreach ($attr['reservation_time_data'] as $times) {
					if (!isset($reservationTimes[$times['id']]))  continue;
					$skuStock = bcadd((string)$skuStock, (string)$times['stock'], 0);
					$timeUpdate = [
						'stock' => $times['stock'],
						'service_price' => $times['service_price'] ?? '0.00',
					];
					$productReservationTimeServices->update(['id' => $times['id']], $timeUpdate);
				}
				$this->dao->update(['id' => $attrs[$attr['unique']]['id']], ['stock' => $skuStock]);
				$deltaGoodTotal = bcadd($deltaGoodTotal, bcsub((string)$skuStock, $oldGood, 4), 4);
				$attrs[$attr['unique']]['stock'] = $skuStock;
			} else {
				// 必须用锁后的最新余额计算，禁止使用事务外快照
				$cur = $attrs[$attr['unique']];
				$lineGood = '0';
				$lineDef = '0';
				if ($attr['pm']) {
					$lineGood = (string)$attr['stock'];
					$update['stock'] = bcadd((string)$cur['stock'], $lineGood, 4);
					if (isset($attr['defective_stock'])) {//有残次品库存
						$lineDef = (string)$attr['defective_stock'];
						$update['defective_stock'] = bcadd((string)$cur['defective_stock'], $lineDef, 4);
					}
					$update['sum_stock'] = bcadd((string)$cur['sum_stock'], $lineGood, 4);
				} else {
					$lineGood = bcsub('0', (string)$attr['stock'], 4);
					$update['stock'] = bcadd((string)$cur['stock'], $lineGood, 4);
					if (isset($attr['defective_stock'])) {//有残次品库存
						$lineDef = bcsub('0', (string)$attr['defective_stock'], 4);
						$update['defective_stock'] = bcadd((string)$cur['defective_stock'], $lineDef, 4);
					}
					$update['sum_stock'] = bcadd((string)$cur['sum_stock'], $lineGood, 4);
				}
				// 库存改造：禁止 max(0)/int 静默截断；残次品不允许为负
				if (isset($update['defective_stock']) && bccomp((string)$update['defective_stock'], '0', 4) < 0) {
					throw new ValidateException('残次品库存不足');
				}
				$allowNegative = (int)($product['allow_negative_stock'] ?? 1) === 1;
				if (!$allowNegative && bccomp((string)$update['stock'], '0', 4) < 0) {
					throw new ValidateException('库存不足');
				}
				$this->dao->update(['id' => $attrs[$attr['unique']]['id']], $update);
				$deltaGoodTotal = bcadd($deltaGoodTotal, $lineGood, 4);
				$deltaDefTotal = bcadd($deltaDefTotal, $lineDef, 4);
				// 同一商品多行改同一 SKU 时，后续行基于更新后余额
				$attrs[$attr['unique']]['stock'] = $update['stock'];
				if (isset($update['defective_stock'])) {
					$attrs[$attr['unique']]['defective_stock'] = $update['defective_stock'];
				}
				$attrs[$attr['unique']]['sum_stock'] = $update['sum_stock'];
			}
			//同步出入库单
			if ($isStockOrder) {
				$stockDataAll[] = [
					'product_id' => $id,
					'unique' => $attr['unique'],
					'cost_price' => $attrs[$attr['unique']]['cost'] ?? 0,
					'stock' => $attr['stock'],
					'pm' => $attr['pm'] ? 1 : 0,
					'add_time' => $time,
				];
			}
        }
        // 商品主表按本次变化量原子增减，禁止 SUM 快照后绝对值覆盖（防并发丢汇总）
        $this->applyProductStockDeltaAtomic($id, $deltaGoodTotal, $deltaDefTotal);
        $stock = Db::name('store_product')->where('id', $id)->value('stock');
        //检测库存警戒和检测是否售罄
        ProductStockTips::dispatch([$id, 0]);
        //同步出入库单
        if ($isStockOrder && $stockDataAll) {
            /** @var StoreProductStockDetailServices $storeProductStockDetailServices */
            $storeProductStockDetailServices = app()->make(StoreProductStockDetailServices::class);
			$storeProductStockDetailServices->handelProductStock($id, $stockDataAll, $type, $relation_id, $adminId);
        }

        //清除缓存
        $productServices->cacheTag()->clear();
        /** @var StoreProductAttrServices $attrService */
        $attrService = app()->make(StoreProductAttrServices::class);
        $attrService->cacheTag()->clear();

        return $stock;
    }

    /**
     * 商品主表库存原子增减（与支付 changeSkuStock 同一策略）
     */
    protected function applyProductStockDeltaAtomic(int $productId, string $deltaGood, string $deltaDefective): void
    {
        if ($productId <= 0) {
            return;
        }
        if (!preg_match('/^-?\d+(\.\d{1,4})?$/', $deltaGood)) {
            $deltaGood = bcadd($deltaGood, '0', 4);
        }
        if (!preg_match('/^-?\d+(\.\d{1,4})?$/', $deltaDefective)) {
            $deltaDefective = bcadd($deltaDefective, '0', 4);
        }
        if (bccomp($deltaGood, '0', 4) === 0 && bccomp($deltaDefective, '0', 4) === 0) {
            return;
        }
        $deltaGood = bcadd($deltaGood, '0', 4);
        $deltaDefective = bcadd($deltaDefective, '0', 4);
        if (!preg_match('/^-?\d+(\.\d{1,4})?$/', $deltaGood) || !preg_match('/^-?\d+(\.\d{1,4})?$/', $deltaDefective)) {
            throw new ValidateException('库存数量格式错误');
        }
        Db::name('store_product')->where('id', $productId)->update([
            'stock' => Db::raw('`stock`+(' . $deltaGood . ')'),
            'defective_stock' => Db::raw('`defective_stock`+(' . $deltaDefective . ')'),
            // MySQL 同句 UPDATE 中 stock 已先完成增减，is_sold 只按最终 stock 判断一次，禁止再 +delta
            'is_sold' => Db::raw('IF(`stock`>0,0,1)'),
        ]);
    }

    /**
     * 查询库存预警产品ids
     * @param array $where
     * @return array
     */
    public function getGroupId(array $where)
    {
        $res1 = [];
        $res2 = $this->dao->getGroupData('product_id', 'product_id', $where);
        foreach ($res2 as $id) {
            $res1[] = $id['product_id'];
        }
        return $res1;
    }

	/**
	 * 批量修改库存\价格
	 * @param int $id
	 * @param array $data
	 * @param string $type
	 * @param int $is_verify
	 * @return int|mixed|string
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function updateAttrs(int $id, array $data, string $type = '', int $is_verify = 1, int $relation_type = 0, int $relation_id = 0, int $adminId = 0)
	{
		/** @var StoreProductServices $productServices */
		$productServices = app()->make(StoreProductServices::class);
		$product = $productServices->get($id);
		if (!$product) {
			throw new ValidateException('商品不存在');
		}
		// 【库存铁律】库存只能由库存管理（入/出/盘）与销售出库/退货改动；规格页禁止改库存
		// 原 type=stock / 同时改价库存 逻辑已停用
		if ($type !== 'price') {
			throw new ValidateException('已停用：不可在此修改库存，请到「库存管理」入库/出库/盘点操作');
		}
		//平台同步到门店商品 验证门店是否有自主定价权限
		$isSyncStoreProduct = $product['type'] == 1 && $product['relation_id'] && $product['pid'] > 0;
		if ($isSyncStoreProduct) {
			/** @var SystemStoreServices $systemStoreServices */
			$systemStoreServices = app()->make(SystemStoreServices::class);
			$storeInfo = $systemStoreServices->get((int)$product['relation_id'], ['id', 'product_change_price_status']);
			if (!$storeInfo || !$storeInfo['product_change_price_status']) {
				throw new ValidateException('您暂无自主改价权限，请联系平台管理员');
			}
		}

		$attrs = $this->dao->getProductAttrValue(['product_id' => $id, 'type' => 0]);
		$data = array_combine(array_column($data, 'unique'), $data);
		$product_price_arr = $product_ot_price_arr = $product_cost_arr = [];
		foreach ($attrs as $item) {
			$attr = $data[$item['unique']] ?? [];
			if ($attr) {
				if ($isSyncStoreProduct) {//同步到门店商品改价 验证改价区间
					$price = (float)$attr['price'];
					$min = (float)$item['price_range_min'];
					$max = (float)$item['price_range_max'];
					$oldPrice = (float)$item['price'];
					if ($oldPrice == $min && $oldPrice == $max) {
						if ($oldPrice != $price) throw new ValidateException($item['suk'] . ': 不允许改价');
					} elseif ($min && $max) {//限制区间
						if ($price < $min || $price > $max) {
							throw new ValidateException($item['suk'] . ': 不在改价区间范围');
						}
					} elseif (!$min && $max) {//限制最大值
						if ($price > $max) {
							throw new ValidateException($item['suk'] . ': 不在改价区间范围');
						}
					} elseif ($min && !$max) {//限制最小值
						if ($price < $min) {
							throw new ValidateException($item['suk'] . ': 不在改价区间范围');
						}
					}
				}
				$updateData = ['price' => $attr['price'], 'cost' => $attr['cost'] ?? $item['cost'], 'ot_price' => $attr['ot_price'] ?? $item['ot_price']];
				$this->dao->update($item['id'], $updateData);
			}
			// 更新商品价格（不改库存汇总）
			$product_price_arr[] = $attr['price'] ?? $item['price'] ?? 0;
			$product_ot_price_arr[] = $attr['ot_price'] ?? $item['ot_price'] ?? 0;
			$product_cost_arr[] = $attr['cost'] ?? $item['cost'] ?? 0;
		}
		$product_price = array_diff($product_price_arr, [0]) ? min(array_diff($product_price_arr, [0])) : 0;
		$product_ot_price = array_diff($product_ot_price_arr, [0]) ? min(array_diff($product_ot_price_arr, [0])) : 0;
		$product_cost = array_diff($product_cost_arr, [0]) ? min(array_diff($product_cost_arr, [0])) : 0;

		$productServices->update($id, [
			'price' => $product_price,
			'ot_price' => $product_ot_price,
			'cost' => $product_cost,
			'is_verify' => $is_verify
		]);
		// 清除缓存
		$productServices->cacheTag()->clear();
		/** @var StoreProductAttrServices $attrService */
		$attrService = app()->make(StoreProductAttrServices::class);
		$attrService->cacheTag()->clear();

		return $product_price;
	}

	/**
	 * 根据商品获取sku列表
	 * @param array $where
	 * @param int $type
	 * @param int $relation_id
	 * @return array
	 */
	public function getAttrValueList(array $where = [], int $type = 0, int $relation_id = 0)
	{
		$where['type'] = $type;
		$where['relation_id'] = $relation_id;
		[$page, $limit] = $this->getPageValue();
		$query = $this->dao->joinAttrSearch($where);
		$count = $query->count();
		$list = $query->field('a.*,p.store_name')->page($page, $limit)->order('p.sort desc,p.id desc')->select()->toArray();
		return compact('list', 'count');
	}

}
