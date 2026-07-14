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

namespace app\services\activity\combination;

use app\services\BaseServices;
use app\dao\activity\combination\StoreCombinationDao;
use app\services\diy\DiyServices;
use app\services\order\StoreOrderServices;
use app\services\other\QrcodeServices;
use app\services\product\ensure\StoreProductEnsureServices;
use app\services\product\label\StoreProductLabelServices;
use app\services\product\product\StoreDescriptionServices;
use app\services\user\UserRelationServices;
use app\services\product\product\StoreProductReplyServices;
use app\services\product\product\StoreProductServices;
use app\services\product\sku\StoreProductAttrServices;
use app\services\product\sku\StoreProductAttrValueServices;
use app\jobs\product\ProductLogJob;
use app\services\user\UserServices;
use mohe\exceptions\AdminException;
use mohe\services\CacheService;
use mohe\services\SystemConfigService;
use think\exception\ValidateException;

/**
 * 拼团商品
 * Class StoreCombinationServices
 * @package app\services\activity\combination
 * @mixin StoreCombinationDao
 */
class StoreCombinationServices extends BaseServices
{
    const THODLCEG = 'ykGUKB';

	/**
	* 商品活动类型
	 */
	const TYPE = 2;

    /**
     * StoreCombinationServices constructor.
     * @param StoreCombinationDao $dao
     */
    public function __construct(StoreCombinationDao $dao)
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
	 * @param array $productIds
	 * @return mixed
	 * @author 等风来
	 * @email 136327134@qq.com
	 * @date 2022/11/28
	 */
	public function getPinkIdsArrayCache(array $productIds)
	{
		return $this->dao->cacheTag()->remember(md5('pink_ids_' . json_encode($productIds)), function () use ($productIds) {
			return $this->dao->getPinkIdsArray($productIds, ['id']);
		});
	}

    /**
     * 获取是否有拼团商品
     * */
    public function validCombination()
    {
        return $this->dao->count([
            'is_del' => 0,
            'is_show' => 1,
            'pinkIngTime' => true
        ]);
    }

    /**
     * 拼团商品添加
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
		if ($data['product_type'] == 4 && !$data['delivery_type']) {
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
        if ($data['stop_time'] < strtotime(date('Y-m-d', time()))) throw new AdminException('结束时间不能小于今天');
        $data['image'] = $data['images'][0] ?? '';
        $data['images'] = json_encode($data['images']);
        $data['price'] = max(array_column($detail, 'price'));
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

        $this->transaction(function () use ($id, $data, $description, $detail, $items, $storeDescriptionServices, $storeProductAttrServices) {
            if ($id) {
				//重新编辑销量清空
				$data['sales'] = 0;
                $res = $this->dao->update($id, $data);
                if (!$res) throw new AdminException('修改失败');
            } else {
                $data['add_time'] = time();
                $res = $this->dao->save($data);
                if (!$res) throw new AdminException('添加失败');
                $id = (int)$res->id;
            }
            $storeDescriptionServices->saveDescription((int)$id, $description, 3);
			$storeProductAttrServices->setItem('store_product_id', $data['product_id']);
            $skuList = $storeProductAttrServices->validateProductAttr($items, $detail, (int)$id, 3);
			$storeProductAttrServices->reset();
            $valueGroup = $storeProductAttrServices->saveProductAttr($skuList, (int)$id, 3);

            $res = true;
            foreach ($valueGroup as $item) {
				if ($item['quota_show']) $res = $res && CacheService::setStock($item['unique'], (int)$item['quota_show'], 3);
            }
            if (!$res) {
                throw new AdminException('占用库存失败');
            }
        });

		$this->dao->cacheTag()->clear();
    }

    /**
     * 拼团列表
     * @param array $where
     * @return array
     */
    public function systemPage(array $where)
    {
        [$page, $limit] = $this->getPageValue();
        $list = $this->dao->getList($where, $page, $limit);
        $count = $this->dao->count($where);
        /** @var StorePinkServices $storePinkServices */
        $storePinkServices = app()->make(StorePinkServices::class);
        $countAll = $storePinkServices->getPinkCount([]);
        $countTeam = $storePinkServices->getPinkCount(['k_id' => 0, 'status' => 2]);
        $countPeople = $storePinkServices->getPinkCount(['k_id' => 0]);
        foreach ($list as &$item) {
            $item['count_people'] = $countPeople[$item['id']] ?? 0;//拼团数量
            $item['count_people_all'] = $countAll[$item['id']] ?? 0;//参与人数
            $item['count_people_pink'] = $countTeam[$item['id']] ?? 0;//成团数量
            $item['stop_status'] = $item['stop_time'] < time() ? 1 : 0;
            if ($item['is_show']) {
                if ($item['start_time'] > time())
                    $item['start_name'] = '未开始';
                else if ($item['stop_time'] < time())
                    $item['start_name'] = '已结束';
                else if ($item['stop_time'] > time() && $item['start_time'] < time()) {
                    $item['start_name'] = '进行中';
                }
            } else $item['start_name'] = '已结束';
        }
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
            throw new ValidateException('您查看的团团商品已被删除!');
        }
        if ($info['start_time'])
            $start_time = date('Y-m-d H:i:s', $info['start_time']);

        if ($info['stop_time'])
            $stop_time = date('Y-m-d H:i:s', $info['stop_time']);
        if (isset($start_time) && isset($stop_time))
            $info['section_time'] = [$start_time, $stop_time];
        else
            $info['section_time'] = [];
        unset($info['start_time'], $info['stop_time']);
        $info['price'] = floatval($info['price']);
        $info['postage'] = floatval($info['postage']);
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
        $info['description'] = $storeDescriptionServices->getDescription(['product_id' => $id, 'type' => 3]);
		/** @var StoreProductAttrValueServices $storeProductAttrValueServices */
		$storeProductAttrValueServices = app()->make(StoreProductAttrValueServices::class);
		$info['attrs'] = $storeProductAttrValueServices->attrList($id, $info['product_id'], 3);
        return $info;
    }

    /**
     * 根据id获取拼团数据列表
     * @param array $ids
     * @param string $field
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getCombinationList(array $where = [])
    {
        [$page, $limit] = $this->getPageValue();
		$field = 'id,title,image,price,product_id,people,quota,quota_show,stock';
		$list = $this->dao->combinationList(array_merge($where, ['is_del' => 0, 'is_show' => 1, 'pinkIngTime' => true, 'storeProductId' => true]), $field, $page, $limit);
        foreach ($list as &$item) {
            $item['image'] = set_file_url($item['image']);
            $item['price'] = floatval($item['price']);
            $item['product_price'] = floatval($item['product_price']);
            $item['pink_count'] = max((int)bcsub((string)$item['quota_show'], (string)$item['quota'], 0), 0);
            /** @var StoreProductServices $storeProductServices */
            $storeProductServices = app()->make(StoreProductServices::class);
            $item['brand_name'] = $storeProductServices->productIdByBrandName((int)$item['product_id']);
        }
        return $list;
    }

	/**
	 * 拼团商品详情
	 * @param int $uid
	 * @param int $id
	 * @return array
	 * @throws \Throwable
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function combinationDetail(int $uid, int $id)
    {
		$storeInfo = $this->dao->cacheTag()->remember('' . $id, function () use ($id) {
			$storeInfo = $this->dao->getOne(['id' => $id], '*', ['descriptions', 'getTotal']);
			if (!$storeInfo) {
				throw new ValidateException('商品不存在');
			} else {
				$storeInfo = $storeInfo->toArray();
			}
			return $storeInfo;
		}, 600);
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
        $storeInfo['sale_stock'] = 0;
        if ($storeInfo['stock'] > 0) $storeInfo['sale_stock'] = 1;

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
        $storeInfo['delivery_type'] = $storeProductServices->getDeliveryType($uid, (int)$storeInfo['id'], (int)$storeInfo['type'], (int)$storeInfo['relation_id'], $storeInfo['delivery_type'], 3);
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

        /** @var UserRelationServices $userRelationServices */
        $userRelationServices = app()->make(UserRelationServices::class);
        $storeInfo['userCollect'] = $userRelationServices->isProductRelationCache(['uid' => $uid, 'relation_id' => $storeInfo['product_id'], 'type' => 'collect', 'category' => UserRelationServices::CATEGORY_PRODUCT]);
        $storeInfo['userLike'] = 0;

        /** @var QrcodeServices $qrcodeService */
        $qrcodeService = app()->make(QrcodeServices::class);
        if (($configData['share_qrcode'] ?? 0) && request()->isWechat()) {
            $storeInfo['code_base'] = $qrcodeService->getTemporaryQrcode('combination-' . $id . '-' . $uid, $uid)->url;
        } else {
            $storeInfo['code_base'] = $qrcodeService->getWechatQrcodePath($id . '_product_combination_detail_wap.jpg', '/pages/activity/goods_details/index?type=3id=' . $id . '&spid=' . $uid);
        }
		$storeInfo['small_image'] = get_thumb_water($storeInfo['image']);
        $data['storeInfo'] = $storeInfo;

        /** @var StorePinkServices $pinkService */
        $pinkService = app()->make(StorePinkServices::class);
        [$pink, $pindAll] = $pinkService->getPinkList($id, true, 1);//拼团进行中列表
        $data['pink_ok_list'] = $pinkService->getPinkOkList($uid);
        $data['pink_ok_sum'] = $pinkService->getPinkOkSumTotalNum();
        $data['pink'] = $pink;
        $data['pindAll'] = $pindAll;

        /** @var StoreOrderServices $storeOrderServices */
        $storeOrderServices = app()->make(StoreOrderServices::class);
        $data['buy_num'] = $storeOrderServices->getBuyCount($uid, 3, $id);

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
        [$productAttr, $productValue] = $storeProductAttrServices->getProductAttrDetailCache($id, $uid, 0, 3, $storeInfo['product_id'], $productInfo);
        $data['productAttr'] = $productAttr;
        $data['productValue'] = $productValue;
        $data['routine_contact_type'] = sys_config('routine_contact_type', 0);
		$data['store_func_status'] = (int)($configData['store_func_status'] ?? 1);//门店是否开启
		$data['store_self_mention'] = $data['store_func_status'] ? (int)($configData['store_self_mention'] ?? 0) : 0;//门店核销是否开启
        $data['site_name'] = sys_config('site_name');
        $data['share_qrcode'] = sys_config('share_qrcode', 0);
		$data['product_poster_title'] = $configData['product_poster_title'] ?? '';
        //浏览记录
        ProductLogJob::dispatch(['visit', ['uid' => $uid, 'id' => $id, 'product_id' => $storeInfo['product_id']], 'combination']);
        return $data;
    }

    /**
     * 修改销量和库存
     * @param int $num
     * @param int $CombinationId
     * @param string $unique
     * @param int $store_id
     * @return bool
     */
    public function decCombinationStock(int $num, int $CombinationId, string $unique, int $store_id = 0, bool $decProductStock = false)
    {
		if (!$CombinationId) return true;
        $product_id = $this->dao->value(['id' => $CombinationId], 'product_id');
		$res = true;
		if ($product_id) {
			if ($unique) {
				/** @var StoreProductAttrValueServices $skuValueServices */
				$skuValueServices = app()->make(StoreProductAttrValueServices::class);
				//减去拼团商品的sku库存增加销量（活动额度）
				$res = $res && $skuValueServices->decProductAttrStock($CombinationId, $unique, $num, 3);

				if ($decProductStock) {
					$sku = $skuValueServices->value(['product_id' => $CombinationId, 'unique' => $unique, 'type' => 3], 'suk');
					$productUnique = $skuValueServices->value(['suk' => $sku, 'product_id' => $product_id, 'type' => 0], 'unique');
					/** @var StoreProductServices $services */
					$services = app()->make(StoreProductServices::class);
					$res = $res && $services->decProductStock($num, (int)$product_id, (string)$productUnique, $store_id);
				}
			}
			//减去拼团商品库存
			$res = $res && $this->dao->decStockIncSales(['id' => $CombinationId, 'type' => 3], $num);
		}
        return $res;
    }

    /**
     * 加库存减销量
     * @param int $num
     * @param int $CombinationId
     * @param string $unique
     * @param int $store_id
     * @return bool
     */
    public function incCombinationStock(int $num, int $CombinationId, string $unique, int $store_id = 0, bool $incProductStock = false)
    {
		if (!$CombinationId) return true;
        $product_id = $this->dao->value(['id' => $CombinationId], 'product_id');
        $res = false;
		if ($product_id) {
			if ($unique) {
				/** @var StoreProductAttrValueServices $skuValueServices */
				$skuValueServices = app()->make(StoreProductAttrValueServices::class);
				//增加拼团商品的sku库存,减去销量
				$res = false !== $skuValueServices->incProductAttrStock($CombinationId, $unique, $num, 3);

				//拼团商品sku
				$suk = $skuValueServices->value(['unique' => $unique, 'product_id' => $CombinationId, 'type' => 3], 'suk');
				//平台商品sku unique
				$productUnique = $skuValueServices->value(['suk' => $suk, 'product_id' => $product_id, 'type' => 0], 'unique');

				/** @var StoreProductServices $services */
				$services = app()->make(StoreProductServices::class);
				//增加当前普通商品sku的库存,减去销量
				if ($incProductStock) { $res = $res && $services->incProductStock($num, (int)$product_id, (string)$productUnique, $store_id); }
			}
			//增加拼团库存
			$res = $res && $this->dao->incStockDecSales(['id' => $CombinationId, 'type' => 3], $num);
		}
        return $res;
    }

	/**
	 * 获取拼团详情
	 * @param int $uid
	 * @param int $id
	 * @param array $user
	 * @return array
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function getPinkInfo(int $uid, int $id, array $user = [])
    {
        if (!$id || !$uid) throw new ValidateException('参数错误');

		$is_ok = 0;//判断拼团是否完成
		$userBool = 0;//判断当前用户是否在团内  0未在 1在
		$pinkBool = 0;//判断拼团是否成功  0未在 1在
		if (!$user) {
			$userServices = app()->make(UserServices::class);
			$user = $userServices->getUserCacheInfo($uid);
			if (!$user) {
				throw new ValidateException('参数错误');
			}
			$user = $user->toArray();
		}
		/** @var StorePinkServices $pinkService */
		$pinkService = app()->make(StorePinkServices::class);
        $pink = $pinkService->getPinkUserOne($id);
        if (!$pink) throw new ValidateException('参数错误');
        $pink = $pink->toArray();
        if (isset($pink['is_refund']) && $pink['is_refund']) {
            if ($pink['is_refund'] != $pink['id']) {
                $id = $pink['is_refund'];
                return $this->getPinkInfo($uid, $id, $user);
            } else {
                throw new ValidateException('订单已退款');
            }
        }
        [$pinkAll, $pinkT, $count, $idAll, $uidAll] = $pinkService->getPinkMemberAndPinkK($pink);
        if ($pinkT['status'] == 2) {
            $pinkBool = 1;
            $is_ok = 1;
        } else if ($pinkT['status'] == 3) {
            $pinkBool = -1;
            $is_ok = 0;
        } else {
            if ($count < 1) {//组团完成
                $is_ok = 1;
                $pinkBool = $pinkService->pinkComplete($uidAll, $idAll, $user['uid'], $pinkT);
            } else {
                $pinkBool = $pinkService->pinkFail($pinkAll, $pinkT, $pinkBool);
            }
			//更新pinkT 数据 可能成功或失败
			$pinkT = $pinkService->getPinkUserOne($pinkT['id']);
        }
        if (!empty($pinkAll)) {
            foreach ($pinkAll as $v) {
                if ($v['uid'] == $user['uid']) $userBool = 1;
            }
        }
        if ($pinkT['uid'] == $user['uid']) $userBool = 1;
        $combinationOne = $this->dao->combinationOne($pink['cid']);
        if (!$combinationOne) {
            throw new ValidateException('拼团不存在或已下架,请手动申请退款!');
        }
		$combinationOne = $combinationOne->hidden(['mer_id', 'images', 'attr', 'info', 'sort', 'sales', 'stock', 'add_time', 'is_host', 'is_show', 'is_del', 'combination', 'mer_use', 'is_postage', 'postage', 'start_time', 'stop_time', 'cost', 'browse'])->toArray();
		$combinationOne['pink_count'] = max((int)bcsub((string)$combinationOne['quota_show'], (string)$combinationOne['quota'], 0), 0);

        $data['userInfo']['uid'] = $user['uid'];
        $data['userInfo']['nickname'] = $user['nickname'];
        $data['userInfo']['avatar'] = $user['avatar'];
        $data['is_ok'] = $is_ok;
        $data['userBool'] = $userBool;
        $data['pinkBool'] = $pinkBool;
		$delivery_type = $combinationOne['delivery_type'] ?? [];
        $combinationOne['delivery_type'] = is_string($delivery_type) ? explode(',', $delivery_type) : $delivery_type;
        $data['store_combination'] = $combinationOne;
		$data['pinkT'] = $pinkT;
        $data['pinkAll'] = $pinkAll;
        $data['count'] = max($count, 0);
		$data['store_combination_host'] = $this->dao->getCombinationHost();
		foreach ($data['store_combination_host'] as &$item) {
			$item['pink_count'] = max((int)bcsub((string)$item['quota_show'], (string)$item['quota'], 0), 0);
		}
		$data['current_pink_order'] = $pinkService->getCurrentPink($id, $user['uid']);

        /** @var StoreProductAttrServices $storeProductAttrServices */
        $storeProductAttrServices = app()->make(StoreProductAttrServices::class);
        /** @var StoreProductAttrValueServices $storeProductAttrValueServices */
        $storeProductAttrValueServices = app()->make(StoreProductAttrValueServices::class);

        [$productAttr, $productValue] = $storeProductAttrServices->getProductAttrDetail($combinationOne['id'], $user['uid'], 0, 3, $combinationOne['product_id']);
        foreach ($productValue as $k => $v) {
            $productValue[$k]['product_stock'] = $storeProductAttrValueServices->value(['product_id' => $combinationOne['product_id'], 'suk' => $v['suk'], 'type' => 0], 'stock');
        }
        $data['store_combination']['productAttr'] = $productAttr;
        $data['store_combination']['productValue'] = $productValue;
		$data['store_func_status'] = (int)(sys_config('store_func_status', 1));
		$data['store_self_mention'] = $data['store_func_status'] ? (int)(sys_config('store_self_mention', 0)) : 0;//门店核销是否开启
        return $data;
    }

    /**
     * 验证拼团下单库存限量
     * @param int $uid
     * @param int $combinationId
     * @param int $cartNum
     * @param string $unique
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function checkCombinationStock(int $uid, int $combinationId, int $cartNum = 1, string $unique = '')
    {
        /** @var StoreProductAttrValueServices $attrValueServices */
        $attrValueServices = app()->make(StoreProductAttrValueServices::class);
        if ($unique == '') {
            $unique = $attrValueServices->value(['product_id' => $combinationId, 'type' => 3], 'unique');
        }
        $attrInfo = $attrValueServices->getOne(['product_id' => $combinationId, 'unique' => $unique, 'type' => 3]);
        if (!$attrInfo || $attrInfo['product_id'] != $combinationId) {
            throw new ValidateException('请选择有效的商品属性');
        }
        $StoreCombinationInfo = $productInfo = $this->dao->validCombinationOne($combinationId, '*,title as store_name');
        if (!$StoreCombinationInfo) {
            throw new ValidateException('该商品已下架或删除');
        }
        /** @var StoreOrderServices $orderServices */
        $orderServices = app()->make(StoreOrderServices::class);
        $userBuyCount = $orderServices->getBuyCount($uid, 3, $combinationId);
        if ($StoreCombinationInfo['once_num'] < $cartNum) {
            throw new ValidateException('每个订单限购' . $StoreCombinationInfo['once_num'] . '件');
        }
        if ($StoreCombinationInfo['num'] < ($userBuyCount + $cartNum)) {
            throw new ValidateException('每人总共限购' . $StoreCombinationInfo['num'] . '件');
        }

        if ($cartNum > $attrInfo['quota']) {
            throw new ValidateException('该商品库存不足' . $cartNum);
        }
        return [$attrInfo, $unique, $productInfo];
    }

    /**
     * 拼团统计
     * @param $id
     * @return array
     */
    public function combinationStatistics(int $id)
    {
        /** @var StorePinkServices $pinkServices */
        $pinkServices = app()->make(StorePinkServices::class);
        /** @var StoreOrderServices $orderServices */
        $orderServices = app()->make(StoreOrderServices::class);
        $people_count = $pinkServices->getDistinctCount([['cid', '=', $id]], 'uid', false);
        $spread_count = $pinkServices->getDistinctCount([['cid', '=', $id], ['k_id', '>', 0]], 'uid', false);
        $start_count = $pinkServices->count(['cid' => $id, 'k_id' => 0]);
        $success_count = $pinkServices->count(['cid' => $id, 'k_id' => 0, 'status' => 2]);
        $pay_price = $orderServices->sum(['type' => 3, 'activity_id' => $id, 'paid' => 1, 'pid' => [0, -1]], 'pay_price', true);
        $pay_count = $orderServices->getDistinctCount(['type' => 3, 'activity_id' => $id, 'paid' => 1, 'pid' => [0, -1]], 'uid');
        return compact('people_count', 'spread_count', 'start_count', 'success_count', 'pay_price', 'pay_count');
    }

    /**
     * 拼团订单
     * @param $id
     * @param array $where
     * @return array
     */
    public function combinationStatisticsOrder(int $id, array $where = [])
    {
        /** @var StoreOrderServices $orderServices */
        $orderServices = app()->make(StoreOrderServices::class);
        [$page, $limit] = $this->getPageValue();
		$list = $orderServices->activityStatisticsOrder($id, 3, $where, $page, $limit);
		$where['type'] = 3;
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
