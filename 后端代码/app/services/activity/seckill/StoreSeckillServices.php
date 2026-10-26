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

namespace app\services\activity\seckill;



use app\services\activity\StoreActivityServices;
use app\services\BaseServices;
use app\dao\activity\seckill\StoreSeckillDao;
use app\services\diy\DiyServices;
use app\services\order\StoreOrderServices;
use app\services\other\QrcodeServices;
use app\services\product\branch\StoreBranchProductServices;
use app\services\product\ensure\StoreProductEnsureServices;
use app\services\product\label\StoreProductLabelServices;
use app\services\product\product\StoreDescriptionServices;
use app\services\store\SystemStoreServices;
use app\services\user\UserRelationServices;
use app\services\product\product\StoreProductReplyServices;
use app\services\product\product\StoreProductServices;
use app\services\product\sku\StoreProductAttrServices;
use app\services\product\sku\StoreProductAttrValueServices;
use app\jobs\product\ProductLogJob;
use mohe\exceptions\AdminException;
use mohe\services\CacheService;
use mohe\services\SystemConfigService;
use mohe\traits\OptionTrait;
use think\exception\ValidateException;

/**
 * Class StoreSeckillServices
 * @package app\services\activity\seckill
 * @method getSeckillIdsArray(array $ids, array $field)
 * @mixin StoreSeckillDao
 */
class StoreSeckillServices extends BaseServices
{

	use OptionTrait;

    const OPOXMWTJ = 'k8kkOJ';

	/**
	* 商品活动类型
	 */
	const TYPE = 1;

    /**
     * StoreSeckillServices constructor.
     * @param StoreSeckillDao $dao
     */
    public function __construct(StoreSeckillDao $dao)
    {
        $this->dao = $dao;
    }

	/**
	 * @param array $productIds
	 * @return array
	 * @author 等风来
	 * @email 136327134@qq.com
	 * @date 2022/11/14
	 */
	public function getSeckillIdsArrayCache(array $productIds)
	{
		$list = $this->dao->cacheList();
		if (!$list) {
			return $this->dao->getSeckillIdsArray($productIds, ['id', 'time_id', 'product_id']);
		} else {
			$seckill = [];
			$time = time();
			foreach ($list as $item) {
				if ($item['is_del'] == 0 && $item['status'] == 1 && $item['start_time'] <= $time && $item['stop_time'] >= ($time - 86400) && in_array($item['product_id'], $productIds)) {
					$seckill[] = [
						'id' => $item['id'],
						'time_id' => $item['time_id'],
						'product_id' => $item['product_id'],
					];
				}
			}
			return $seckill;
		}
	}

    public function getCount(array $where)
    {
        $this->dao->count($where);
    }

    /**
     * 秒杀是否存在
     * @param int $id
     * @param string $field
     * @return array|int|\think\Model
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getSeckillCount(int $id = 0, string $field = 'time_id')
    {
        $where = [];
        $where[] = ['is_del', '=', 0];
        $where[] = ['status', '=', 1];$config = [];
		$currentHour = date('Hi');
		/** @var StoreSeckillTimeServices $storeSeckillTimeServices */
		$storeSeckillTimeServices = app()->make(StoreSeckillTimeServices::class);
        if ($id) {
            $time = time();
            $where[] = ['id', '=', $id];
            $where[] = ['start_time', '<=', $time];
            $where[] = ['stop_time', '>=', $time - 86400];
            $seckill_one = $this->dao->getOne($where, $field);
            if (!$seckill_one) {
                throw new ValidateException('活动已结束');
            }
			$time_id = $seckill_one['time_id'] ?? [];
			if ($time_id) {
				$time_id = is_string($time_id) ? explode(',', $time_id) : $time_id;
				$timeList = $storeSeckillTimeServices->getList(['id' => $time_id, 'status' => 1]);
				foreach ($timeList as $value) {
					$value['start_time'] = $start = str_replace(':', '', $value['start_time']);
					$value['end_time'] = $end = str_replace(':', '', $value['end_time']);
					if ($currentHour >= $start && $currentHour < $end) {
						$config = $value;
						break;
					}
				}
			}
            if (!$config) {
                throw new ValidateException('活动已结束');
            }
			//获取秒杀商品状态
			if ($config['start_time'] <= $currentHour && $config['end_time'] > $currentHour) {
				return $seckill_one;
			} else if ($config['start_time'] > $currentHour) {
				throw new ValidateException('活动未开始');
			} else {
				throw new ValidateException('活动已结束');
			}
        } else {
			$timeList = $storeSeckillTimeServices->getList(['status' => 1]);
			foreach ($timeList as $value) {
				$start = str_replace(':', '', $value['start_time']);
				$end = str_replace(':', '', $value['end_time']);
				if ($currentHour >= $start && $currentHour < $end) {
					$config = $value;
					break;
				}
			}
            if (!$config) return 0;

			//获取秒杀商品状态
			$start = substr_replace($config['start_time'], ':', 2, 0);
			$end = substr_replace($config['end_time'], ':', 2, 0);

            $startTime = strtotime(date('Y-m-d') . ' '. $start);
            $stopTime = strtotime(date('Y-m-d') . ' '. $end);

            $where[] = ['start_time', '<', $startTime];
            $where[] = ['stop_time', '>', $stopTime];
            return $this->dao->getCount($where);
        }
    }


    /**
     * 保存数据
     * @param int $id
     * @param array $data
     */
    public function saveData(int $id, array $data)
    {
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
        if (in_array($data['product_type'], [1, 2, 3, 4])) {
            $data['freight'] = 1;
            $data['temp_id'] = 0;
            $data['postage'] = 0;
        } else {
            if ($data['freight'] == 1) {
                $data['temp_id'] = 0;
                $data['postage'] = 0;
            } elseif ($data['freight'] == 2) {
                $data['temp_id'] = 0;
            } elseif ($data['freight'] == 3) {
                $data['postage'] = 0;
            }
            if ($data['freight'] == 2 && !$data['postage']) {
                throw new AdminException('请设置运费金额');
            }
            if ($data['freight'] == 3 && !$data['temp_id']) {
                throw new AdminException('请选择运费模版');
            }
        }
        $description = $data['description'];
        $detail = $data['attrs'];
        $items = $data['items'];
        $data['start_time'] = strtotime($data['section_time'][0]);
        $data['stop_time'] = strtotime($data['section_time'][1]);
        $data['image'] = $data['images'][0] ?? '';
        $data['images'] = json_encode($data['images']);
        $data['price'] = min(array_column($detail, 'price'));
        $data['ot_price'] = max(array_column($detail, 'ot_price'));
        $data['quota'] = $data['quota_show'] = array_sum(array_column($detail, 'quota'));
        if ($data['quota'] > $productInfo['stock']) {
            throw new ValidateException('限量不能超过商品库存');
        }
        $data['stock'] = array_sum(array_column($detail, 'stock'));
        unset($data['section_time'], $data['description'], $data['attrs'], $data['items']);
        /** @var StoreDescriptionServices $storeDescriptionServices */
        $storeDescriptionServices = app()->make(StoreDescriptionServices::class);
        /** @var StoreProductAttrServices $storeProductAttrServices */
        $storeProductAttrServices = app()->make(StoreProductAttrServices::class);
        $id = $this->transaction(function () use ($id, $data, $description, $detail, $items, $storeDescriptionServices, $storeProductAttrServices) {
            if ($id) {
                $res = $this->dao->update($id, $data);
                if (!$res) throw new AdminException('修改失败');
            } else {
                $data['add_time'] = time();
                $res = $this->dao->save($data);
                if (!$res) throw new AdminException('添加失败');
                $id = (int)$res->id;
            }
            $storeDescriptionServices->saveDescription((int)$id, $description, 1);
			$storeProductAttrServices->setItem('store_product_id', $data['product_id']);
            $skuList = $storeProductAttrServices->validateProductAttr($items, $detail, (int)$id, 1);
			$storeProductAttrServices->reset();
            $valueGroup = $storeProductAttrServices->saveProductAttr($skuList, (int)$id, 1);

            $res = true;
            foreach ($valueGroup as $item) {
                if ($item['quota_show']) $res = $res && CacheService::setStock($item['unique'], (int)$item['quota_show']);
            }
            if (!$res) {
                throw new AdminException('占用库存失败');
            }
			return $id;
        });
		$this->dao->cacheTag()->clear();
		//保存
		$seckill = $this->dao->get($id, ['*'], ['descriptions', 'activity' => function ($query) {
			$query->field('id,start_day,end_day,time_id');
		}]);
		$this->dao->cacheUpdate($seckill->toArray());
		CacheService::redisHandler('product_attr')->clear();
    }

    /**
     * 获取列表
     * @param array $where
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function systemPage(array $where)
    {
        [$page, $limit] = $this->getPageValue();
        $list = $this->dao->getList($where, $page, $limit, ['activityName']);
        $count = $this->dao->count($where);
        foreach ($list as &$item) {
            $item['store_name'] = $item['title'];
            if ($item['status']) {
                if ($item['start_time'] > time())
                    $item['start_name'] = '未开始';
                else if (bcadd($item['stop_time'], '86400') < time())
                    $item['start_name'] = '已结束';
                else if (bcadd($item['stop_time'], '86400') > time() && $item['start_time'] < time()) {
                    $item['start_name'] = '进行中';
                }
            } else $item['start_name'] = '已结束';
            $end_time = $item['stop_time'] ? (date('Y-m-d', (int)$item['stop_time']) . ' 23:59:59') : '';
            $item['_stop_time'] = $end_time;
            $item['stop_status'] = $item['stop_time'] + 86400 < time() ? 1 : 0;
        }
        return compact('list', 'count');
    }

    /**
     * 获取秒杀详情
     * @param int $id
     * @return array|\think\Model|null
     */
    public function getInfo(int $id)
    {
        $info = $this->dao->get($id);
        if ($info) {
            if ($info['start_time'])
                $start_time = date('Y-m-d', (int)$info['start_time']);

            if ($info['stop_time'])
                $stop_time = date('Y-m-d', (int)$info['stop_time']);
            if (isset($start_time) && isset($stop_time))
                $info['section_time'] = [$start_time, $stop_time];
            else
                $info['section_time'] = [];
            unset($info['start_time'], $info['stop_time']);
            $info['give_integral'] = intval($info['give_integral']);
            $info['price'] = floatval($info['price']);
            $info['ot_price'] = floatval($info['ot_price']);
            $info['postage'] = floatval($info['postage']);
            $info['cost'] = floatval($info['cost']);
            $info['weight'] = floatval($info['weight']);
            $info['volume'] = floatval($info['volume']);
            if (!$info['delivery_type']) {
                $info['delivery_type'] = [1];
            }
            if ($info['postage']) {
                $info['freight'] = 2;
            } elseif ($info['temp_id']) {
                $info['freight'] = 3;
            } else {
                $info['freight'] = 1;
            }
            /** @var StoreDescriptionServices $storeDescriptionServices */
            $storeDescriptionServices = app()->make(StoreDescriptionServices::class);
            $info['description'] = $storeDescriptionServices->getDescription(['product_id' => $id, 'type' => 1]);
			/** @var StoreProductAttrValueServices $storeProductAttrValueServices */
			$storeProductAttrValueServices = app()->make(StoreProductAttrValueServices::class);
			$info['attrs'] = $storeProductAttrValueServices->attrList($id, $info['product_id'], 1);
			//适用门店
			$info['stores'] = [];
			if (isset($info['applicable_type']) && ($info['applicable_type'] == 1 || ($info['applicable_type'] == 2 && isset($info['applicable_store_id']) && $info['applicable_store_id']))) {//查询门店信息
				$where = ['is_del' => 0];
				if ($info['applicable_type'] == 2) {
					$store_ids = is_array($info['applicable_store_id']) ? $info['applicable_store_id'] : explode(',', $info['applicable_store_id']);
					$where['id'] = $store_ids;
				}
				$field = ['id', 'cate_id', 'name', 'phone', 'address', 'detailed_address', 'image', 'is_show', 'day_time', 'day_start', 'day_end'];
				/** @var SystemStoreServices $storeServices */
				$storeServices = app()->make(SystemStoreServices::class);
				$storeData = $storeServices->getStoreList($where, $field, '', '', 0, ['categoryName']);
				$info['stores'] = $storeData['list'] ?? [];
			}
        }
        return $info;
    }

	/**
	 * 获取某个时间段的秒杀列表
	 * @param int $time
	 * @param array $where
	 * @param bool $isStore
	 * @return array
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function getListByTime(int $time, array $where = [], bool $isStore = false)
    {
        [$page, $limit] = $this->getPageValue();
		/** @var StoreActivityServices $storeActivityServices */
		$storeActivityServices = app()->make(StoreActivityServices::class);
		$activityList = $storeActivityServices->getList(['time_id' => $time, 'type' => 1, 'status' => 1, 'is_del' => 0, 'activityTime' => true], 'id,image');
		$seckillInfo = [];
		if ($activityList) {
			$activityIds = array_column($activityList, 'id');
			$field = 'id,product_id,activity_id,title,image,price,ot_price,quota,quota_show,freight,stock,store_label_id';
			$seckillInfo = $this->dao->getListByTime($activityIds, $where, $field, $page, $limit, $isStore);
			if (count($seckillInfo)) {
				$activityList = array_combine($activityIds, $activityList);
				/** @var StoreProductLabelServices $storeProductLabelServices */
				$storeProductLabelServices = app()->make(StoreProductLabelServices::class);
				foreach ($seckillInfo as $key => &$item) {
					if ($item['quota'] > 0) {
						$percent =  (float)sprintf("%.1f", (($item['quota_show'] - $item['quota']) / $item['quota_show'] * 100));
						$item['percent'] = $percent;
						$item['stock'] = $item['quota'];
					} else {
						$item['percent'] = 100;
						$item['stock'] = 0;
					}
					$item['price'] = floatval($item['price']);
					$item['ot_price'] = floatval($item['ot_price']);
					$item['discount_num'] = $item['ot_price'] ? (float)bcmul(bcdiv((string)$item['price'], (string)$item['ot_price'], 4), '10', 1) : 10;
					$item['activity_image'] = $activityList[$item['activity_id']]['image'] ?? '';
					$item['store_label'] = [];
					if ($item['store_label_id']) {
						$item['store_label'] = $storeProductLabelServices->getLabelCache($item['store_label_id'], ['id', 'label_name', 'style_type', 'color', 'bg_color', 'border_color', 'icon']);
					}
                    /** @var StoreProductServices $storeProductServices */
                    $storeProductServices = app()->make(StoreProductServices::class);
                    $item['brand_name'] = $storeProductServices->productIdByBrandName((int)$item['product_id']);
				}
			}
		}

        return $seckillInfo;
    }

	/**
	 * 获取某个时间段的秒杀商品数量
	 * @param int $time
	 * @param array $ids
	 * @param array $not_ids
	 * @param bool $isStore
	 * @return int
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function getCountByTime(int $time, array $ids = [], array $not_ids = [], bool $isStore = false)
	{
		/** @var StoreActivityServices $storeActivityServices */
		$storeActivityServices = app()->make(StoreActivityServices::class);
		$activityList = $storeActivityServices->getList(['time_id' => $time, 'type' => 1, 'status' => 1, 'is_del' => 0, 'activityTime' => true], 'id');
		$count = 0;
		if ($activityList) {
			$activityIds = array_column($activityList, 'id');
			$count = $this->dao->getTimeCount($activityIds, $ids, $not_ids, $isStore);
		}
		return $count;
	}

	/**
	 * 获取秒杀详情
	 * @param int $uid
	 * @param int $id
	 * @return array
	 * @throws \Throwable
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function seckillDetail(int $uid, int $id)
    {
		//读取秒杀商品缓存信息
		$storeInfo = $this->dao->cacheRemember($id, function () use ($id) {
			$storeInfo = $this->dao->getOne(['id' => $id], '*', ['descriptions', 'getTotal']);
			if (!$storeInfo) {
				throw new ValidateException('商品不存在');
			} else {
				$storeInfo = $storeInfo->toArray();
			}
			return $storeInfo;
		});
		$storeInfo['activity'] = [];
		if ($storeInfo['activity_id']) {
			$activityServices = app()->make(StoreActivityServices::class);
			$storeInfo['activity'] = $activityServices->getInfo((int)$storeInfo['activity_id'], ['id', 'start_day', 'end_day', 'time_id']);
		}
		/** @var DiyServices $diyServices */
		$diyServices = app()->make(DiyServices::class);
		$infoDiy = $diyServices->getProductDetailDiy();
		//diy控制参数
		if (!isset($infoDiy['showService']) || !in_array(3, $infoDiy['showService'])) {
			$storeInfo['specs'] = [];
		}
        $configData = SystemConfigService::more(['site_url', 'routine_contact_type', 'site_name', 'share_qrcode', 'store_self_mention', 'store_func_status', 'product_poster_title']);
        $siteUrl = $configData['site_url'] ?? '';
        $storeInfo['image'] = set_file_url($storeInfo['image'], $siteUrl);
        $storeInfo['image_base'] = set_file_url($storeInfo['image'], $siteUrl);

        //品牌名称
        /** @var StoreProductServices $storeProductServices */
        $storeProductServices = app()->make(StoreProductServices::class);
		$productInfo = $storeProductServices->getCacheProductInfo((int)$storeInfo['product_id']);
        $storeInfo['brand_name'] = $storeProductServices->productIdByBrandName($storeInfo['product_id'], $productInfo);
		$delivery_type = $storeInfo['delivery_type'] ?? $productInfo['delivery_type'];
        $storeInfo['delivery_type'] = is_string($delivery_type) ? explode(',', $delivery_type) : $delivery_type;
        $storeInfo['product_price'] = $productInfo['price'];
         /**
         * 判断配送方式
         */
        $storeInfo['delivery_type'] = $storeProductServices->getDeliveryType($uid, (int)$storeInfo['id'], (int)$storeInfo['type'], (int)$storeInfo['relation_id'], $storeInfo['delivery_type'], 1);
		$storeInfo['total'] = ($productInfo['sales'] ?? 0) + ($productInfo['ficti'] ?? 0);
        $storeInfo['store_label'] = $storeInfo['ensure'] = [];
		if ($storeInfo['store_label_id']) {
			/** @var StoreProductLabelServices $storeProductLabelServices */
			$storeProductLabelServices = app()->make(StoreProductLabelServices::class);
			$storeInfo['store_label'] = $storeProductLabelServices->getLabelCache($storeInfo['store_label_id'], ['id', 'label_name', 'style_type', 'color', 'bg_color', 'border_color', 'icon']);
		}
        if ($storeInfo['ensure_id'] && isset($infoDiy['showService']) && in_array(2, $infoDiy['showService'])) {
            /** @var StoreProductEnsureServices $storeProductEnsureServices */
            $storeProductEnsureServices = app()->make(StoreProductEnsureServices::class);
            $storeInfo['ensure'] = $storeProductEnsureServices->getEnsurCache($storeInfo['ensure_id'], ['id', 'name', 'image', 'desc']);
        }
		$time = $this->getItem('time', '');
		$status = $this->getItem('status', '');
		$time_id = (int)$this->getItem('time_id', '');

        /** @var QrcodeServices $qrcodeService */
        $qrcodeService = app()->make(QrcodeServices::class);
        if (($configData['share_qrcode'] ?? 0) && request()->isWechat()) {
            $storeInfo['code_base'] = $qrcodeService->getTemporaryQrcode('seckill-' . $id . '-'. $uid . '-'  . $time . '-' . $status, $uid)->url;
        } else {
            $storeInfo['code_base'] = $qrcodeService->getWechatQrcodePath($id . '_product_seckill_detail_wap.jpg', '/pages/activity/goods_seckill_details/index?id=' . $id . '&spid=' . $uid . '&time=' . $time . '&status=' . $status);
        }

        /** @var StoreOrderServices $storeOrderServices */
        $storeOrderServices = app()->make(StoreOrderServices::class);
        $data['buy_num'] = $storeOrderServices->getBuyCount($uid, 1, $id);

        /** @var UserRelationServices $userRelationServices */
        $userRelationServices = app()->make(UserRelationServices::class);
        $storeInfo['userCollect'] = $userRelationServices->isProductRelationCache(['uid' => $uid, 'relation_id' => $storeInfo['product_id'], 'type' => 'collect', 'category' => UserRelationServices::CATEGORY_PRODUCT]);
        $storeInfo['userLike'] = 0;

        $storeInfo['uid'] = $uid;
        if ($storeInfo['quota'] > 0) {
            $percent = (float)sprintf("%.1f", ($storeInfo['quota_show'] - $storeInfo['quota']) / $storeInfo['quota_show'] * 100);
            $storeInfo['percent'] = $percent;
            $storeInfo['stock'] = $storeInfo['quota'];
        } else {
            $storeInfo['percent'] = 100;
            $storeInfo['stock'] = 0;
        }
		$storeInfo['last_time'] = 0;
		$time_id = $time_id ?: ($storeInfo['activity']['time_id'] ?? []);
		$storeInfo['status'] = (int)$storeInfo['status'];
		if ($time_id && $storeInfo['status'] == 1) {
			$time_id = is_string($time_id) ? explode(',', $time_id) : $time_id;
			/** @var StoreSeckillTimeServices $storeSeckillTimeServices */
			$storeSeckillTimeServices = app()->make(StoreSeckillTimeServices::class);
			$timeList = $storeSeckillTimeServices->getList(['id' => $time_id, 'status' => 1]);
			$config = [];
			$today = date('Y-m-d');
			$currentHour = date('Hi');
			if (count($timeList) <= 1) {
				$config = $timeList[0] ?? [];
			} else {
				foreach ($timeList as $value) {
					$start = str_replace(':', '', $value['start_time']);
					$end = str_replace(':', '', $value['end_time']);
					if ($currentHour >= $start && $currentHour < $end) {
						$config = $value;
						break;
					}
				}
			}
			//获取秒杀商品状态
			if ($config) {//正在进行中的
				$start = str_replace(':', '', $config['start_time']);
				$end = str_replace(':', '', $config['end_time']);
				if ($start <= $currentHour && $end > $currentHour) {
					$storeInfo['status'] = 1;
					$storeInfo['last_time'] = strtotime($today. ' '. $config['end_time']);
				} else if ($start > $currentHour) {
					$storeInfo['status'] = 2;
				} else {
					$storeInfo['status'] = 0;
				}
			} else {
				$storeInfo['status'] = 0;
			}
		}

        //商品详情
        $storeInfo['small_image'] = get_thumb_water($storeInfo['image']);
        $data['storeInfo'] = $storeInfo;

		$data['reply'] = [];
		$data['replyChance'] = $data['replyCount'] = 0;
		if (isset($infoDiy['showReply']) && $infoDiy['showReply']) {
			/** @var StoreProductReplyServices $storeProductReplyService */
			$storeProductReplyService = app()->make(StoreProductReplyServices::class);
			$reply = $storeProductReplyService->getRecProductReplyCache((int)$storeInfo['product_id'], (int)($infoDiy['replyNum'] ?? 1));
			$data['reply'] = $reply ? get_thumb_water($reply, 'small', ['pics']) : [];
			[$replyCount, $goodReply, $replyChance] = $storeProductReplyService->getProductReplyData((int)$storeInfo['product_id']);
			$data['replyChance'] = $replyChance;
			$data['replyCount'] = $replyCount;
		}

        /** @var StoreProductAttrServices $storeProductAttrServices */
        $storeProductAttrServices = app()->make(StoreProductAttrServices::class);
        [$productAttr, $productValue] = $storeProductAttrServices->getProductAttrDetailCache($id, $uid, 0, 1, $storeInfo['product_id'], $productInfo);
        $data['productAttr'] = $productAttr;
        $data['productValue'] = $productValue;
        $data['routine_contact_type'] = $configData['routine_contact_type'] ?? 0;
		$data['store_func_status'] = (int)($configData['store_func_status'] ?? 1);//门店是否开启
		$data['store_self_mention'] = $data['store_func_status'] ? (int)($configData['store_self_mention'] ?? 0) : 0;//门店核销是否开启
        $data['site_name'] = $configData['site_name'] ?? '';
        $data['share_qrcode'] = $configData['share_qrcode'] ?? 0;
		$data['product_poster_title'] = $configData['product_poster_title'] ?? '';
        //浏览记录
        ProductLogJob::dispatch(['visit', ['uid' => $uid, 'id' => $id, 'product_id' => $storeInfo['product_id']], 'seckill']);
        return $data;
    }

    /**
     * 修改秒杀库存
     * @param int $num
     * @param int $seckillId
     * @param string $unique
     * @param int $store_id
     * @return bool
     */
    public function decSeckillStock(int $num, int $seckillId, string $unique = '', int $store_id = 0, bool $decProductStock = false)
    {
		if (!$seckillId) return true;
        $product_id = $this->dao->value(['id' => $seckillId], 'product_id');
		$res = true;
		if ($product_id) {
			if ($unique) {
				/** @var StoreProductAttrValueServices $skuValueServices */
				$skuValueServices = app()->make(StoreProductAttrValueServices::class);
				//减去秒杀商品的sku库存增加销量（活动额度）
				$res = $res &&  $skuValueServices->decProductAttrStock($seckillId, $unique, $num, 1);

				if ($decProductStock) {
					//减去当前普通商品sku的库存增加销量
					$suk = $skuValueServices->value(['unique' => $unique, 'product_id' => $seckillId, 'type' => 1], 'suk');
					$productUnique = $skuValueServices->value(['suk' => $suk, 'product_id' => $product_id, 'type' => 0], 'unique');
					/** @var StoreProductServices $services */
					$services = app()->make(StoreProductServices::class);
					$res = $res && $services->decProductStock($num, (int)$product_id, (string)$productUnique, $store_id);
				}
			}
			//减去秒杀库存
			$res = $res && $this->dao->decStockIncSales(['id' => $seckillId, 'type' => 1], $num);
		}
		//更新单个缓存
		$info = $this->dao->getOne(['id' => $seckillId], '*', ['descriptions']);
		if ($info) {
			$info = $info->toArray();
			$this->dao->cacheUpdate($info);
		}
        return $res;
    }

    /**
     * 加库存减销量
     * @param int $num
     * @param int $seckillId
     * @param string $unique
     * @param int $store_id
     * @return bool
     */
    public function incSeckillStock(int $num, int $seckillId, string $unique = '', int $store_id = 0, bool $incProductStock = false)
    {
		if (!$seckillId) return true;
        $product_id = $this->dao->value(['id' => $seckillId], 'product_id');
		$res = true;
		if ($product_id) {
			if ($unique) {
				/** @var StoreProductAttrValueServices $skuValueServices */
				$skuValueServices = app()->make(StoreProductAttrValueServices::class);
				//增加秒杀商品的sku库存减少销量（活动额度）
				$res = $res && $skuValueServices->incProductAttrStock($seckillId, $unique, $num, 1);

				if ($incProductStock) {
					$suk = $skuValueServices->value(['unique' => $unique, 'product_id' => $seckillId, 'type' => 1], 'suk');
					$productUnique = $skuValueServices->value(['suk' => $suk, 'product_id' => $product_id, 'type' => 0], 'unique');
					/** @var StoreProductServices $services */
					$services = app()->make(StoreProductServices::class);
					$res = $res && $services->incProductStock($num, (int)$product_id, (string)$productUnique, $store_id);
				}
			}
			//增加秒杀库存减去销量
			$res = $res &&  $this->dao->incStockDecSales(['id' => $seckillId, 'type' => 1], $num);
		}
		//更新单个缓存
		$info = $this->dao->getOne(['id' => $seckillId], '*', ['descriptions']);
		if ($info) {
			$info = $info->toArray();
			$this->dao->cacheUpdate($info);
		}
        return $res;
    }

    /**
     * 下单｜加入购物车验证秒杀商品库存
     * @param int $uid
     * @param int $seckillId
     * @param int $cartNum
     * @param string $unique
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function checkSeckillStock(int $uid, int $seckillId, int $cartNum = 1, int $store_id = 0, string $unique = '')
    {
        /** @var StoreProductAttrValueServices $attrValueServices */
        $attrValueServices = app()->make(StoreProductAttrValueServices::class);
        if ($unique == '') {
            $unique = $attrValueServices->value(['product_id' => $seckillId, 'type' => 1], 'unique');
        }
        //检查商品活动状态
        $storeSeckillinfo = $this->getSeckillCount($seckillId, '*,title as store_name');
		if (!$storeSeckillinfo) {
			throw new ValidateException('该活动已下架');
		}
        if ($storeSeckillinfo['once_num'] < $cartNum) {
            throw new ValidateException('每个订单限购' . $storeSeckillinfo['once_num'] . '件');
        }
		/** @var StoreOrderServices $orderServices */
        $orderServices = app()->make(StoreOrderServices::class);
        $userBuyCount = $orderServices->getBuyCount($uid, 1, $seckillId);
        if ($storeSeckillinfo['num'] < ($userBuyCount + $cartNum)) {
            throw new ValidateException('每人总共限购' . $storeSeckillinfo['num'] . '件');
        }
        if ($storeSeckillinfo['num'] < $cartNum) {
            throw new ValidateException('每人限购' . $storeSeckillinfo['num'] . '件');
        }
        $attrInfo = $attrValueServices->getOne(['product_id' => $seckillId, 'unique' => $unique, 'type' => 1]);
        if (!$attrInfo || $attrInfo['product_id'] != $seckillId) {
            throw new ValidateException('请选择有效的商品属性');
        }
        if ($cartNum > $attrInfo['quota']) {
            throw new ValidateException('该商品库存不足' . $cartNum);
        }

		if ($store_id) {
			/** @var StoreBranchProductServices $branchProductServices */
			$branchProductServices = app()->make(StoreBranchProductServices::class);
			$branchProductInfo =  $branchProductServices->isValidStoreProduct((int)$storeSeckillinfo['product_id'], $store_id);
			if (!$branchProductInfo) {
				throw new ValidateException('门店该商品已下架');
			}
			$suk = $attrValueServices->value(['unique' => $unique, 'product_id' => $seckillId, 'type' => 1], 'suk');
			$brandAttrInfo = $attrValueServices->getOne(['product_id' => $branchProductInfo['id'], 'suk' => $suk, 'type' => 0]);
			if (!$brandAttrInfo) {
				throw new ValidateException('请选择有效的商品属性');
			}
			if ($cartNum > $brandAttrInfo['stock']) {
				throw new ValidateException('该商品库存不足' . $cartNum);
			}
		}
        return [$attrInfo, $unique, $storeSeckillinfo];
    }

    /**
     * 秒杀统计
     * @return array
     */
    public function seckillStatistics($id)
    {
        /** @var StoreOrderServices $orderServices */
        $orderServices = app()->make(StoreOrderServices::class);
        $pay_count = $orderServices->getDistinctCount(['type' => 1, 'activity_id' => $id, 'paid' => 1, 'pid' => [0, -1]], 'uid');
        $order_count = $orderServices->getDistinctCount(['type' => 1, 'activity_id' => $id, 'pid' => [0, -1]], 'uid');
        $all_price = $orderServices->sum(['type' => 1, 'activity_id' => $id, 'paid' => 1,'refund_type' => [0, 3], 'pid' => [0, -1]], 'pay_price');
        $seckillInfo = $this->dao->get($id);
        $pay_rate = $seckillInfo['quota'] . '/' . $seckillInfo['quota_show'];
        return compact('pay_count', 'order_count', 'all_price', 'pay_rate');
    }

    /**
     * 秒杀参与人统计
     * @param $id
     * @param string $keyword
     * @return array
     */
    public function seckillPeople($id, $keyword = '')
    {
        /** @var StoreOrderServices $orderServices */
        $orderServices = app()->make(StoreOrderServices::class);
        [$page, $limit] = $this->getPageValue();
        $list = $orderServices->seckillPeople($id, $keyword, $page, $limit);
        $count = $orderServices->getDistinctCount([['paid', '=', 1],['type', '=', 1],['activity_id', '=', $id], ['pid', 'in', [0, -1]], ['real_name|uid|user_phone', 'like', '%' . $keyword . '%']], 'uid', false);
        foreach ($list as &$item) {
            $item['add_time'] = date('Y-m-d H:i:s', $item['add_time']);
        }
        return compact('list', 'count');
    }

    /**
     * 秒杀订单统计
     * @param $id
     * @param array $where
     * @return array
     */
    public function seckillOrder(int $id, array $where = [])
    {
        /** @var StoreOrderServices $orderServices */
        $orderServices = app()->make(StoreOrderServices::class);
        [$page, $limit] = $this->getPageValue();
		$list = $orderServices->activityStatisticsOrder($id, 1, $where, $page, $limit);
		$where['type'] = 1;
        $where['activity_id'] = $id;
        $count = $orderServices->count($where);
        foreach ($list as &$item) {
			if ($item['is_del'] || $item['is_system_del']) {
				$item['status'] = '已删除';
			} else if ($item['paid'] == 0 && $item['status'] == 0) {
				$item['status'] = '待付款';
			} else if ($item['paid'] == 1 && $item['status'] == 4 && in_array($item['shipping_type'], [1, 3]) && $item['refund_status'] == 0) {
				$item['status'] = '部分发货';
			} else if ($item['paid'] == 1 && $item['refund_status'] == 2) {
				$item['status'] = '已退款';
			} else if ($item['paid'] == 1 && $item['status'] == 5 && $item['refund_status'] == 0) {
				$item['status'] = $item['shipping_type'] == 2 ? '部分核销' : '部分收货';
				$item['_status'] = 12;//已支付 部分核销
			} else if ($item['paid'] == 1 && $item['refund_status'] == 1) {
				$item['status'] = '申请退款';
			} else if ($item['paid'] == 1 && $item['refund_status'] == 4) {
				$item['status'] = '退款中';
			} else if ($item['paid'] == 1 && $item['status'] == 0 && in_array($item['shipping_type'], [1, 3]) && $item['refund_status'] == 0) {
				$item['status'] = '未发货';
				$item['_status'] = 2;//已支付 未发货
			} else if ($item['paid'] == 1 && in_array($item['status'], [0, 1]) && $item['shipping_type'] == 2 && $item['refund_status'] == 0) {
				$item['status'] = '未核销';
			} else if ($item['paid'] == 1 && in_array($item['status'], [1, 5]) && in_array($item['shipping_type'], [1, 3]) && $item['refund_status'] == 0) {
				$item['status'] = '待收货';
			} else if ($item['paid'] == 1 && $item['status'] == 2 && $item['refund_status'] == 0) {
				$item['status'] = '待评价';
			} else if ($item['paid'] == 1 && $item['status'] == 3 && $item['refund_status'] == 0) {
				$item['status'] = '已完成';
			} else if ($item['paid'] == 1 && $item['refund_status'] == 3) {
				$item['status'] = '部分退款';
			} else {
				$item['status'] = '未知';
			}
            $item['add_time'] = date('Y-m-d H:i:s', $item['add_time']);
            $item['pay_time'] = $item['pay_time'] ? date('Y-m-d H:i:s', $item['pay_time']) : '';
        }
        return compact('list', 'count');
    }
}
