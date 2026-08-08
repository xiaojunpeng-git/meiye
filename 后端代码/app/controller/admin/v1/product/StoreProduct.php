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
namespace app\controller\admin\v1\product;

use app\controller\admin\AuthController;
use app\jobs\BatchHandleJob;
use app\jobs\product\ProductSyncErp;
use app\services\agent\SystemRegionAgentServices;
use app\services\other\CacheServices;
use app\services\other\Import\ImportRecordServices;
use app\services\other\queue\QueueServices;
use app\services\product\category\StoreProductCategoryServices;
use app\services\product\product\StoreProductBatchProcessServices;
use app\services\product\product\StoreProductServices;
use app\services\product\sku\StoreProductAttrServices;
use app\services\product\sku\StoreProductAttrValueServices;
use mohe\services\FileService;
use mohe\services\SpreadsheetExcelService;
use mohe\services\UploadService;
use think\facade\App;

/**
 * Class StoreProduct
 * @package app\controller\admin\v1\product
 */
class StoreProduct extends AuthController
{
    protected $service;

    public function __construct(App $app, StoreProductServices $service)
    {
        parent::__construct($app);
        $this->service = $service;
    }

	/**
	 * 显示资源列表头部
	 * @param StoreProductCategoryServices $storeProductCategoryServices
	 * @param SystemRegionAgentServices $regionAgentServices
	 * @return mixed
	 */
    public function type_header(StoreProductCategoryServices $storeProductCategoryServices, SystemRegionAgentServices $regionAgentServices)
    {
		$where = $this->request->getMore([
			['field_key', ''],//搜索字段类型
			['store_name', ''],//关键词
			['product_type', ''],//商品类型0:普通商品，1：卡密，2：优惠券，3：虚拟商品,4：次卡商品,5:卡项商品6：预约商品
			['cate_id', ''],//分类
			['supplier_id', ''],//供应商
			['type', 1, '', 'status'],//商品状态
			['sales', 'normal'],
			['store_id', ''],
			['store_label_id', ''],
			['brand_id', ''],
			['delivery_type', ''],//商品配送方式:1、快递，2、到店核销，3、门店配送
			['spec_type', ''],//规格 0单 1多
			['is_vip', ''],//是否开启付费会员 0未开启1开启
			['sales_range', ''],//销量区间 [小,大]
			['price_range', ''],//售价区间 [小,大]
			['create_range', ''],//创建时间区间 [开始时间,结束时间]
			['is_brokerage', ''],//参与返佣
			['activity_type', ''],//参与活动
			['stock_range', ''],//库存区间[小,大]
			['collect_range', ''],//收藏区间[小,大]
			['is_inventory', ''],//是否参与库存管理
			['allow_negative_stock', ''],//是否允许负库存
        ]);
		$cateId = $where['cate_id'];
		if ($cateId) {
			$cateId = is_string($cateId) ? [$cateId] : $cateId;
			$cateId = array_merge($cateId, $storeProductCategoryServices->getColumn(['pid' => $cateId], 'id'));
			$cateId = array_unique(array_diff($cateId, [0]));
		}
		$where['cate_id'] = $cateId;
		if ($where['store_id']) {//筛选门店
			$where['type'] = 1;
			$where['relation_id'] = $where['store_id'];
			unset($where['store_id']);
		} else {//无筛选
			if ($this->adminType == 3 && $this->agentId) {//区域代理商登录
				$storeIds = $regionAgentServices->getRegionAgentStoreId((int)$this->agentId);
				if ($storeIds) {
					$where['type'] = 1;
					$where['relation_id'] = $storeIds;
				} else {
					return $this->success(['list' => [
						['type' => 1, 'name' => '销售中', 'count' => 0],
						['type' => 2, 'name' => '仓库中', 'count' => 0],
						['type' => 4, 'name' => '已售罄', 'count' => 0],
						['type' => 5, 'name' => '库存预警', 'count' => 0],
						['type' => 6, 'name' => '回收站', 'count' => 0],
						['type' => 0, 'name' => '待审核', 'count' => 0],
						['type' => -1, 'name' => '审核未通过', 'count' => 0],
						['type' => -2, 'name' => '强制下架', 'count' => 0]
					]]);
				}
			}
		}
        $list = $this->service->getHeader(0, $where);
        return $this->success(compact('list'));
    }

    /**
     * 获取退出未保存的数据
     * @param CacheServices $services
     * @return mixed
     */
    public function getCacheData(CacheServices $services)
    {
        return $this->success(['info' => $services->getDbCache($this->adminId . '_product_data', [])]);
    }

    /**
     * 1分钟保存一次产品数据
     * @param CacheServices $services
     * @return mixed
     */
    public function saveCacheData(CacheServices $services)
    {
        $data = $this->request->postMore([
            ['product_type', 0],//商品类型
            ['supplier_id', 0],//供应商ID
            ['cate_id', []],
            ['store_name', ''],
            ['store_info', ''],
            ['keyword', ''],
            ['unit_name', '件'],
            ['recommend_image', ''],
            ['slider_image', []],
            ['is_sub', []],//佣金是单独还是默认
            ['sort', 0],
            ['sales', 0],
            ['ficti', 0],
            ['give_integral', 0],
            ['is_show', 0],
            ['is_hot', 0],
            ['is_benefit', 0],
            ['is_best', 0],
            ['is_new', 0],
            ['mer_use', 0],
            ['is_postage', 0],
            ['is_good', 0],
            ['description', ''],
            ['spec_type', 0],
            ['video_open', 0],//是否开启视频
            ['video_link', ''],//视频链接
            ['items', []],
            ['attrs', []],
            ['activity', []],
            ['coupon_ids', []],
            ['label_id', []],
            ['command_word', ''],
            ['tao_words', ''],
            ['type', 0],
            ['delivery_type', []],//物流设置 配送方式:1、平台配送，2、到店自提，3、门店配送
            ['store_delivery_type', []],//物流设置
            ['freight', 1],//运费设置
            ['postage', 0],//邮费
            ['temp_id', 0],//运费模版
            ['recommend_list', []],
            ['brand_id', []],
            ['soure_link', ''],
            ['bar_code', ''],
            ['code', ''],
            ['is_support_refund', 1],//是否支持退款
            ['is_presale_product', 0],//预售商品开关
            ['presale_time', []],//预售时间
            ['presale_day', 0],//预售发货日
            ['is_vip_product', 0],//是否付费会员商品
            ['auto_on_time', 0],//自动上架时间
            ['auto_off_time', 0],//自动下架时间
            ['custom_form', []],//自定义表单
			['system_form_id', 0],//系统表单ID
			['system_form_type', 1],//系统表单类型，1：按照商品填写2：按照订单填写
            ['store_label_id', []],//商品标签
            ['ensure_id', []],//商品保障服务区
            ['specs', []],//商品参数
            ['specs_id', 0],//商品参数ID
			['is_limit', 0],//是否限购
			['limit_type', 0],//限购类型
			['limit_num', 0],//限购数量
			['applicable_type', 1],//适用门店类型
			['applicable_store_id', []],//适用门店IDS
        ]);
        $services->setDbCache($this->adminId . '_product_data', $data, 68400);
        return $this->success();
    }

    /**
     * 删除数据缓存
     * @param CacheServices $services
     * @return mixed
     */
    public function deleteCacheData(CacheServices $services)
    {
        $services->deleteDbCache($this->adminId . '_product_data');
        return $this->success();
    }

	/**
	 * 显示资源列表
	 * @param StoreProductCategoryServices $storeProductCategoryServices
	 * @param SystemRegionAgentServices $regionAgentServices
	 * @return mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function index(StoreProductCategoryServices $storeProductCategoryServices, SystemRegionAgentServices $regionAgentServices)
    {
        $where = $this->request->getMore([
			['field_key', ''],//搜索字段类型
            ['store_name', ''],//关键词
			['product_type', ''],//商品类型0:普通商品，1：卡密，2：优惠券，3：虚拟商品,4：次卡商品,5:卡项商品6：预约商品
            ['card_rule_type', ''],//卡项规则：普通卡、任选种数卡、任选次数卡、时间卡
            ['cate_id', ''],//分类
			['supplier_id', 0],//供应商
            ['type', 1, '', 'status'],//商品状态
            ['sales', 'normal'],
            ['store_id', 0],
			['store_label_id', ''],
			['brand_id', ''],
			['delivery_type', ''],//商品配送方式:1、快递，2、到店核销，3、门店配送
			['spec_type', ''],//规格 0单 1多
			['is_vip', ''],//是否开启付费会员 0未开启1开启
			['sales_range', ''],//销量区间 [小,大]
			['price_range', ''],//售价区间 [小,大]
			['create_range', ''],//创建时间区间 [开始时间,结束时间]
			['is_brokerage', ''],//参与返佣
			['activity_type', ''],//参与活动
			['stock_range', ''],//库存区间[小,大]
			['collect_range', ''],//收藏区间[小,大]
			['is_inventory', ''],//是否参与库存管理
			['allow_negative_stock', ''],//是否允许负库存

        ]);
		if ($where['supplier_id']) {
			$where['relation_id'] = $where['supplier_id'];
			$where['type'] = 2;
		} elseif ($where['store_id']) {
			$where['relation_id'] = $where['store_id'];
			$where['type'] = 1;
		} else {
			if ($this->adminType == 3 && $this->agentId) {//区域代理商登录
				$storeIds = $regionAgentServices->getRegionAgentStoreId((int)$this->agentId);
				if ($storeIds) {
					$where['type'] = 1;
					$where['relation_id'] = $storeIds;
				} else {
					return $this->success(['list' => [], 'count' => 0]);
				}
			} else {
				// 平台商品主数据使用 type=0；默认值 type=1 会错误限定为门店来源。
				$where['type'] = 0;
				$where['pid'] = 0;
			}
		}
		$cateId = $where['cate_id'];
		if ($cateId) {
			$cateId = is_string($cateId) ? [$cateId] : $cateId;
			$cateId = array_merge($cateId, $storeProductCategoryServices->getColumn(['pid' => $cateId], 'id'));
			$cateId = array_unique(array_diff($cateId, [0]));
		}
		$where['cate_id'] = $cateId;
		unset($where['supplier_id'], $where['store_id']);
        $data = $this->service->getList($where);
        return $this->success($data);
    }

	/**
 	*  审核商品表单
	* @param $id
	* @return mixed
	*/
	public function verifyForm($id)
	{
		if (!$id) {
			return $this->fail('缺少参数');
		}
		return $this->success($this->service->verifyForm($id));
	}

	/**
 	* 强制下架表单
	* @param $id
	* @return mixed
	*/
	public function removeForm($id)
	{
		if (!$id) {
			return $this->fail('缺少参数');
		}
		return $this->success($this->service->verifyForm($id, 2));
	}

	/**
     * 审核商品
     * @param string $is_show
     * @param string $id
     * @return mixed
     */
    public function setVerify($id = '')
    {
		if (!$id) {
			return $this->fail('缺少参数');
		}
		$data = $this->request->postMore([
            ['is_verify', 1],
            ['is_show', 1],
            ['auto_on_time', 0],//自动上架时间
            ['refusal', '']
        ]);
		if (in_array($data['is_verify'], [-1, -2]) && !$data['refusal']) {
			return $this->fail('请输入原因');
		}
        $data['auto_on_time'] = $data['auto_on_time'] ? strtotime($data['auto_on_time']) : 0;
        if($data['is_show'] == 2 && $data['auto_on_time'] <= 0) return $this->fail('请输入上架时间');
        if($data['auto_on_time']) {
            $data['is_show'] = 0;
        }
        $this->service->verify((int)$id, $data);
        return $this->success('操作成功');
    }

    /**
     * 修改状态
     * @param string $is_show
     * @param string $id
     * @return mixed
     */
    public function set_show($is_show = '', $id = '')
    {
        $this->service->setShow([$id], $is_show);
        return $this->success($is_show == 1 ? '上架成功' : '下架成功');
    }

    /**
     * 设置批量商品上架
     * @return mixed
     */
    public function product_show()
    {
        [$ids, $all, $where] = $this->request->postMore([
            ['ids', []],
            ['all', 0],
            ['where', []],
        ], true);
        if ($all == 0) {//单页不走队列
            if (empty($ids)) return $this->fail('请选择需要上架的商品');
            $this->service->setShow($ids, 1);
            return $this->success('上架成功');
        } else {//全部
			if ($all == 1 && $ids) {//所有页，存在反选取消
				$where['not_ids'] = is_string($ids) ? stringToIntArray($ids) : $ids;
			}
			$ids = [];
			if (isset($where['type'])) {
				$where['status'] = $where['type'];
				unset($where['type']);
			}
			if (isset($where['supplier_id']) && $where['supplier_id']) {
				$where['relation_id'] = $where['supplier_id'];
				$where['type'] = 2;
			} elseif (isset($where['store_id']) && $where['store_id']) {
				$where['relation_id'] = $where['store_id'];
				$where['type'] = 1;
			} else {
				$where['pid'] = 0;
			}
			$type = 4;//商品上架
			/** @var QueueServices $queueService */
			$queueService = app()->make(QueueServices::class);
			$queueService->setQueueData($where, 'id', $ids, $type);
			//加入队列
			BatchHandleJob::dispatch(['up', $type]);
			return $this->success('后台程序已执商品上架任务!');
		}
    }

    /**
     * 设置批量商品下架
     * @return mixed
     */
    public function product_unshow()
    {
        [$ids, $all, $where] = $this->request->postMore([
            ['ids', []],
            ['all', 0],
            ['where', []],
        ], true);
        if ($all == 0) {//单页不走队列
            if (empty($ids)) return $this->fail('请选择需要下架的商品');
            $this->service->setShow($ids, 0);
            return $this->success('下架成功');
        } else {//全部
			if ($all == 1 && $ids) {//所有页，存在反选取消
				$where['not_ids'] = is_string($ids) ? stringToIntArray($ids) : $ids;
			}
			$ids = [];
			if (isset($where['type'])) {
				$where['status'] = $where['type'];
				unset($where['type']);
			}
			$type = 4;//商品下架
			/** @var QueueServices $queueService */
			$queueService = app()->make(QueueServices::class);
			$queueService->setQueueData($where, 'id', $ids, $type);
			//加入队列
			BatchHandleJob::dispatch(['down', $type]);
			return $this->success('后台程序已执商品下架任务!');
		}
    }

    /**
     * 批量设置商品配送方式
     * @return mixed
     */
    public function setProductDeliveryType()
    {
        [$ids, $all, $deliveryType] = $this->request->postMore([
            ['ids', []],
            ['all', 0],
            ['delivery_type', []], //配送方式:1、平台配送，2、到店自提，3、门店配送
        ], true);
        if (!$deliveryType) {
            return $this->fail('请选择配送方式');
        }
        if ($all == 0 && empty($ids)) {
            return $this->fail('请选择需要设置的商品');
        }
        if (count($ids) == 1) {
            $product = $this->service->get($ids[0]);
            if ($product && in_array($product['product_type'], [1, 2, 3])) {
                return $this->fail('虚拟、卡密商品不需要设置配送方式');
            }
        }
		if ($all == 1) {
			$update_where = ['is_show' => 1, 'is_del' => 0];
        } else {
			$update_where = [['id', 'in', $ids]];
        }
        if (!$this->service->update($update_where, ['delivery_type' => $deliveryType])) {
            return $this->fail('设置失败');
        }

		$this->service->cacheTag()->clear();

        return $this->success('设置成功');
    }

    /**
     * 批量设置商品标签
     * @return mixed
     */
    public function setProductlabel()
    {
        [$ids, $all, $store_label_id] = $this->request->postMore([
            ['ids', []],
            ['all', 0],
            ['store_label_id', []],
        ], true);
        if (!$store_label_id) {
            return $this->fail('请选择商品标签');
        }
        if ($all == 0 && empty($ids)) {
            return $this->fail('请选择需要设置的商品');
        }
		if ($all == 1) {
			$update_where = ['is_show' => 1, 'is_del' => 0];
        } else {
			$update_where = [['id', 'in', $ids]];
        }
        if (!$this->service->update($update_where, ['store_label_id' => implode(',', $store_label_id)])) {
            return $this->fail('设置失败');
        }

		$this->service->cacheTag()->clear();

        return $this->success('设置成功');
    }

    /**
     * 批量设置商品保障服务
     * @return mixed
     */
    public function setProductEnsure()
    {
        [$ids, $all, $ensure_id] = $this->request->postMore([
            ['ids', []],
            ['all', 0],
            ['store_label_id', []],
        ], true);
        if (!$ensure_id) {
            return $this->fail('请选择商品保障服务');
        }
        if ($all == 0 && empty($ids)) {
            return $this->fail('请选择需要设置的商品');
        }
		if ($all == 1) {
			$update_where = ['is_show' => 1, 'is_del' => 0];
        } else {
			$update_where = [['id', 'in', $ids]];
        }
        if (!$this->service->update($update_where, ['ensure_id' => implode(',', $ensure_id)])) {
            return $this->fail('设置失败');
        }

		$this->service->cacheTag()->clear();

        return $this->success('设置成功');
    }


    /**
     * 批量设置商品参数
     * @return mixed
     */
    public function setProductSpecs()
    {
        [$ids, $all, $specs_id, $specs] = $this->request->postMore([
            ['ids', []],
            ['all', 0],
            ['specs_id', 0],
            ['specs', []],
        ], true);
        if (!$specs_id) {
            return $this->fail('请选择商品参数模版');
        }
        if (!$specs) {
            return $this->fail('请添加商品参数');
        }
        if ($all == 0 && empty($ids)) {
            return $this->fail('请选择需要设置的商品');
        }
		if ($all == 1) {
			$update_where = ['is_show' => 1, 'is_del' => 0];
        } else {
			$update_where = [['id', 'in', $ids]];
        }
        if (!$this->service->update($update_where, ['specs_id' => $specs_id, 'specs' => json_encode($specs)])) {
            return $this->fail('设置失败');
        }

		$this->service->cacheTag()->clear();

        return $this->success('设置成功');
    }

    /**
     * 获取规格模板
     * @return mixed
     */
    public function get_rule()
    {
        return $this->success($this->service->getRule());
    }

    /**
     * 获取商品详细信息
     * @param int $id
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function get_product_info($id = 0)
    {
        return $this->success($this->service->getInfo((int)$id));
    }

    /**
     * 保存新建或编辑
     * @param $id
     * @return mixed
     * @throws \Exception
     */
    public function save($id)
    {
        $data = $this->request->postMore([
            ['product_type', 0],//商品类型
            ['supplier_id', 0],//供应商ID
            ['cate_id', []],
            ['store_cate_id', []],
            ['store_name', ''],
            ['store_info', ''],
            ['keyword', ''],
            ['unit_name', '件'],
            ['recommend_image', ''],
            ['slider_image', []],
            ['sort', 0],
            ['card_num', 0],
            ['card_num_type', 0],
            ['card_rule_type', ''],
            ['card_rule_version', 0],
            ['card_choice_limit', 0],
            ['card_shared_times', 0],
//            ['sales', 0],
            ['ficti', 0],
            ['give_integral', 0],
            ['is_show', 0],
            ['is_hot', 0],
            ['is_benefit', 0],
            ['is_best', 0],
            ['is_new', 0],
            ['mer_use', 0],
            ['is_postage', 0],
            ['is_good', 0],
            ['description', ''],
            ['spec_type', 0],
            ['single_spec_name', '规格'],//单规格名称（仅产品类型）
            ['video_open', 0],
            ['video_link', ''],
            ['items', []],
            ['attrs', []],
			['attr', []],
			['related', []],//卡项关联商品
            ['recommend', []],//商品推荐
            ['activity', []],
            ['coupon_ids', []],
            ['label_id', []],
            ['command_word', ''],
            ['tao_words', ''],
            ['type', 0, '', 'is_copy'],
            ['delivery_type', []],//配送方式:1、快递，2、到店核销，3、门店配送
            ['store_delivery_type', []],//门店配送方式:1、快递发货，2、同城配送
            ['freight', 1],//运费设置
            ['postage', 0],//邮费
            ['temp_id', 0],//运费模版
            ['recommend_list', []],
            ['brand_id', []],
            ['soure_link', ''],
            ['bar_code', ''],
            ['code', ''],
            ['is_support_refund', 1],//是否支持退款
            ['is_presale_product', 0],//预售商品开关
            ['presale_time', []],//预售时间
            ['presale_day', 0],//预售发货日
            ['is_vip_product', 0],//是否付费会员商品
            ['auto_on_time', 0],//自动上架时间
            ['auto_off_time', 0],//自动下架时间
            ['custom_form', []],//自定义表单
			['system_form_id', 0],//系统表单ID
			['system_form_type', 1],//系统表单类型，1：按照商品填写2：按照订单填写
            ['store_label_id', []],//商品标签
            ['ensure_id', []],//商品保障服务区
            ['specs', []],//商品参数
            ['specs_id', 0],//商品参数ID
            ['is_limit', 0],//是否限购
            ['limit_type', 0],//限购类型
            ['limit_num', 0],//限购数量
			['applicable_type', 0],//适用门店类型 0：仅平台1：所有2：部分
			['applicable_store_id', []],//适用门店IDS
			['presale_status', 0],//预售结束后状态
			['reservation_type', 1],//预约类型1：到店服务+上门服务，2：到店服务，3：上门服务
			['reservation_timing_type', 1],//预约时机1：购买时预约+售后预约，2：购买时预约，3：售后预约
			['sale_time_type', 1],//销售日期1：每天，2:每周，3：自定义时间
			['sale_time_week', []],//销售日期每周设置
			['sale_time_data', []],//销售日期自定义[开始时间,结束时间]
			['show_reservation_days_type', 1],//显示可预约日期类型1：全部展示，2：自定义展示时间
			['show_reservation_days', 0],//显示多少天内可预约日期（天）
			['is_advance', 0],//是否需要提前预约0：无需提前1：需要
			['advance_time', 0],//提前多少小时预约（小时）
			['is_cancel_reservation', 0],//是否可以取消预约0：不允许1：可以
			['cancel_reservation_time', 0],//服务开始前多少小时允许取消（小时）
			['reservation_time_type', 1],//预约时段类型1：自动划分，2：自定义
			['customize_time_period', 0],//自定义时间段划分数据
			['reservation_times', []],//预约时段自定义[开始时间,结束时间]
			['reservation_time_interval', 0],//预约时段自动类型：时间间隔（分钟）
			['is_show_stock', 0],//是否展示库存
			['project_service_duration', 0],//项目服务时长（分钟）
			['addon_service_duration', 0],//增项服务时长（分钟）
			['card_cover', 1],//卡片封面 1:图片，2:颜色
			['card_cover_image', ''],//卡片封面图片
			['card_cover_color', ''],//卡片封面颜色
			['is_sync_stock', 0],//【已停用同步库存】固定 0：新门店商品/SKU 库存为 0；后端也会强制忽略同步库存
			['is_sync_show', 1],//状态同步到门店1：同平台商品状态0：同步至门店为下架状态
            ['is_brokerage', 0],//是否参与返佣
            ['is_sub', 0],//是否单独返佣
            ['is_vip', 0],//是否开启会员价
            ['level_type', 1],//等级会员价格,1:系统默认,2:自定义
            ['is_inventory', 1],//是否参与库存管理（仅产品类型，服务层按 product_type 强制）
            ['allow_negative_stock', 1],//是否允许良品负库存（仅产品类型）
            ['salon_stock_enabled', 0],//是否可作为院装耗材（仅产品类型且参与库存）
            ['create_request_key', ''],//新建/复制请求幂等令牌
        ]);
		if ($data['applicable_type'] == 1) {
			$data['applicable_store_id'] = [];
		} elseif ($data['applicable_type'] == 2) {
			if (!$data['applicable_store_id']) {
				return $this->fail('请选择要适用门店');
			}
		}
        $result = $this->service->saveData((int)$id, $data, 0, 0, (int)$this->adminId);
        if (is_array($result) && !empty($result['duplicated'])) {
            return $this->success('商品已创建，请勿重复提交', ['product_id' => (int)$result['product_id'], 'duplicated' => 1]);
        }
        return $this->success($id ? '保存商品信息成功' : '添加商品成功!', is_array($result) ? ['product_id' => (int)$result['product_id']] : []);
    }

    /**
     * 商品放入回收站｜从回收站恢复
     * @param int $id
     * @return \think\Response
     */
    public function delete(StoreProductAttrServices $attrServices, $id)
    {
        //删除商品检测是否有参与活动
        $this->service->checkActivity($id);
        $res = $this->service->del((int)$id);
        event('product.delete', [$id]);

		$this->service->cacheTag()->clear();
		$attrServices->cacheTag()->clear();

        return $this->success($res);
    }

	/**
	 * 删除回收站商品
	 * @param $id
	 * @return mixed
	 */
	public function delProduct($id)
	{
		$res = $this->service->delProduct((int)$id);
		event('product.delete', [$id]);
		return $this->success('删除成功');
	}

    /**
     * 生成规格列表
     * @param int $id
     * @param int $type
     * @return mixed
     */
    public function is_format_attr($id = 0, $type = 0)
    {
        $data = $this->request->postMore([
            ['attrs', []],
            ['items', []],
            ['product_type', 0]
        ]);
        if ($id > 0 && $type == 1) $this->service->checkActivity($id);
		$plat_type = 0;
		$relation_id = 0;
		if ((int)$id) {//编辑 需要查看商品来源
			$productInfo = $this->service->get((int)$id, ['id', 'type', 'relation_id']);
			if (!$productInfo) {
				return $this->fail('商品不存在');
			}
			$plat_type = (int)$productInfo['type'];
			$relation_id = (int)$productInfo['relation_id'];
		}
		$info = $this->service->getAttr($data, $id, $type, 0, $relation_id);
        return $this->success(compact('info'));
    }

    /**
     * 获取选择的商品列表
     * @return mixed
     */
    public function search_list()
    {
        $where = $this->request->getMore([
			['ids', ''],
            ['cate_id', ''],
            ['store_name', ''],
            ['type', '', '', 'status'],
            ['is_live', 0],
            ['is_new', ''],
            ['is_vip_product', ''],
            ['is_presale_product', ''],
            ['store_label_id', ''],
			['is_supplier', -1],//供应商商品0:不是1:是
			['is_integral', 0],//活动不要次卡商品
			['is_community', 0],//社区关联商品 过滤下架等
			['is_card', 0],//是否卡项获取关联商品
            ['product_type', ''],//商品类型0:普通商品，1：卡密，2：优惠券，3：虚拟商品,4：次卡商品,5:卡项商品6：预约商品
			['choose_type', ''],//选择商品列表使用场景1：秒杀、2:砍价、3:拼团、4:积分、5:套餐、7:新人礼、8:抽奖、 90:卡项关联商品、91：添加门店同步商品、92优惠活动参与商品、93优惠活动赠送商品、94:库存选品
        ]);
//        $where['is_show'] = 1;
        $where['is_del'] = 0;
		$where['is_verify'] = 1;
        /** @var StoreProductCategoryServices $storeCategoryServices */
        $storeCategoryServices = app()->make(StoreProductCategoryServices::class);
        if ($where['cate_id'] !== '') {
            if ($storeCategoryServices->value(['id' => $where['cate_id']], 'pid')) {
                $where['sid'] = $where['cate_id'];
            } else {
                $where['cid'] = $where['cate_id'];
            }
        }
        if($where['is_community']) {
            $where['is_show'] = 1;
        }
        // 库存相关选品：强制商品类型，忽略客户端篡改
        $chooseType = (int)($where['choose_type'] ?? 0);
        if (in_array($chooseType, [94, 95], true)) {
            // 94=库存选品 / 95=院装耗材：仅产品
            $where['product_type'] = 0;
        } elseif ($chooseType === 96 || $chooseType === 97) {
            // 96=院装配方项目，97=平台卡项选项目：仅预约/项目
            $where['product_type'] = 6;
        }
        // 94=库存选品：仅平台商品，排除供应商；取消 type=[0,2]；忽略客户端 status 以免只查上架
        if ($chooseType === 94) {
            unset($where['status'], $where['is_show']);
            $where['type'] = 0;
            $where['relation_id'] = 0;
        } elseif ($chooseType === 97) {
            // 平台卡项只配置已上架的平台项目主数据；门店项目 ID 在同步后由原映射链路解析。
            $where['type'] = 0;
            $where['relation_id'] = 0;
            $where['is_show'] = 1;
            $where['is_card'] = 0;
        } else {
            $where['type'] = [0, 2];
            if ($where['is_supplier'] == 1) {
                $where['type'] = [2];
            } elseif ($where['is_supplier'] == 0) {
                $where['type'] = [0];
            }
        }
		unset($where['cate_id'], $where['is_supplier']);
        $list = $this->service->searchList($where, true);
        return $this->success($list);
    }

    /**
     * 获取某个商品规格
     * @return mixed
     */
    public function get_attrs()
    {
        [$id, $type] = $this->request->getMore([
            [['id', 'd'], 0],
            [['type', 'd'], 0],
        ], true);
        $info = $this->service->getProductRules($id, $type);
        return $this->success(compact('info'));
    }

    /**
     * 获取运费模板列表
     * @return mixed
     */
    public function get_template()
    {
		[$id] = $this->request->getMore([
			[['id', 'd'], 0],
		], true);
		$type = $relation_id = 0;
		if ((int)$id) {
			$info = $this->service->get((int)$id, ['id', 'type', 'relation_id']);
			if ($info) {
				$type = (int)$info['type'];
				$relation_id = (int)$info['relation_id'];
			}
		}
        return $this->success($this->service->getTemp($type, $relation_id));
    }

    /**
     * 获取视频上传token
     * @return mixed
     * @throws \Exception
     */
    public function getTempKeys()
    {
        $upload = UploadService::init();
		$type = (int)sys_config('upload_type', 1);
		$key = $this->request->get('key', '');
		$path = $this->request->get('path', '');
		$contentType = $this->request->get('contentType', '');
		if ($type === 5) {
			if (!$key || !$contentType) {
				return app('json')->fail('缺少参数');
			}
			$re = $upload->getTempKeys($key, $path, $contentType);
		} else {
			$re = $upload->getTempKeys();
		}
        return $re ? $this->success($re) : $this->fail($upload->getError());
    }

    /**
     * 检测商品是否开活动
     * @param $id
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function check_activity($id)
    {
        $this->service->checkActivity($id);
        return $this->success('检测成功');
    }

    /**
     * 获取商品所有规格数据
     * @param StoreProductAttrValueServices $services
     * @param $id
     * @return mixed
     */
    public function getAttrs(StoreProductAttrValueServices $services, $id)
    {
        if (!$id) {
            return $this->fail('缺少商品ID');
        }
		$productInfo = $this->service->getCacheProductInfo((int)$id);
		if (!$productInfo) {
			return $this->fail('商品不存在或已删除');
		}
		$with = [];
		if ($productInfo['product_type'] == 6) {//预约商品
			$with = ['reservationTimeData'];
		}
        return $this->success($services->getProductAttrValue(['product_id' => $id, 'type' => 0], $with));
    }

    /**
     * 快速修改商品规格库存
     * @param StoreProductAttrValueServices $services
     * @param $id
     * @return mixed
     */
    public function saveProductAttrsStock(StoreProductAttrValueServices $services, $id)
    {
        // 【库存铁律】商品页快捷改库存已停用；出入库单据内部仍可调用 Service
        return $this->fail('已停用：不可在商品页快捷改库存，请到「库存管理」入库/出库/盘点操作');
        // if (!$id) {
        //     return $this->fail('缺少商品ID');
        // }
        // ...
    }

    /**
     * 导入卡密
     * @return mixed
     * @throws \PhpOffice\PhpSpreadsheet\Reader\Exception
     */
    public function import_card()
    {
        $data = $this->request->getMore([
            ['file', ""]
        ]);
        if (!$data['file']) return app('json')->fail('请上传文件');
        $file = public_path() . substr($data['file'], 1);
        /** @var FileService $readExcelService */
        $readExcelService = app()->make(FileService::class);
        $cardData = $readExcelService->readProductCardExcel($file, 2);
        return app('json')->success($cardData);
    }

    /**
     * 导入erp商品
     * @return mixed
     * @throws \PhpOffice\PhpSpreadsheet\Exception
     * @throws \PhpOffice\PhpSpreadsheet\Reader\Exception
     */
    public function import_erp_product()
    {
        [$path] = $this->request->postMore([
            ['path', ""]
        ], true);
        if (!$path) {
            return $this->fail('请上传表格');
        }
        $path = public_path() . substr($path, 1);

        $data = SpreadsheetExcelService::getExcelData($path, ['spu' => 'A']);
        $spus = [];
        foreach ($data as $item) {
            $spus[] = $item['spu'];
        }
        $spus = array_unique(array_filter($spus));
        foreach ($spus as $spu) {
            ProductSyncErp::dispatchDo('productFromErp', [$spu]);
        }
        unlink($path);
        return $this->success('已加入消息队列进行同步');
    }

	/**
	 * 商品批量操作
	 * @param StoreProductBatchProcessServices $batchProcessServices
	 * @return mixed
	 */
	public function batchProcess(StoreProductBatchProcessServices $batchProcessServices)
	{
		[$type, $ids, $all, $where, $data] = $this->request->postMore([
			['type', 1],
			['ids', ''],
			['all', 0],
			['where', []],
			['data', []]
		], true);
		if (!$ids && $all == 0) return $this->fail('请选择批处理商品');
		if (!$data && !in_array($type,[11,12])) {
			return $this->fail('请选择批处理数据');
		}
		if (isset($where['type'])) {
			$where['status'] = $where['type'];
			unset($where['type']);
		}
		if ($all == 1 && $ids) {//所有页，存在反选取消
			$where['not_ids'] = is_string($ids) ? stringToIntArray($ids) : $ids;
		}
        if($all == 1 && $type == 12) {
            $where['is_del'] = 1;
            $where['is_show'] = 0;
        }
		if (isset($where['supplier_id']) && $where['supplier_id']) {
			$where['relation_id'] = $where['supplier_id'];
			$where['type'] = 2;
		} elseif (isset($where['store_id']) && $where['store_id']) {
			$where['relation_id'] = $where['store_id'];
			$where['type'] = 1;
		} else {
            $where['type'] = 0;
			$where['pid'] = 0;
			$where['relation_id'] = 0;
		}
		//批量操作
		$batchProcessServices->batchProcess((int)$type, $ids, $data, !!$all, $where);
		return app('json')->success('已加入消息队列,请稍后查看');
	}

	/**
	 * 获取分佣/会员价
	 * @param $id
	 * @param $type
	 * @return \think\Response
	 */
	public function otherInfo($id, $type)
	{
		return $this->success($this->service->otherInfo($id, $type));
	}

	/**
	 * 保存分佣/会员价
	 * @param $id
	 * @param $type
	 * @return \think\Response
	 */
	public function otherUpdate($id, $type)
	{
		$data = $this->request->postMore([
			['is_brokerage', 0],//是否参与返佣
			['is_sub', 0],//是否单独返佣
			['is_vip', 0],//是否开启会员价
			['level_type', 0],//等级会员价格,1:系统默认,2:自定义
			['attr_value', []]//sku
		]);
		if(!$id) return app('json')->fail('参数错误');
		$this->service->otherUpdate($id, $type, $data);
		return $this->success('保存成功');
	}

    /**
     * 导入商品
     * @return \think\Response
     */
    public function productImport()
    {
        $data = $this->request->getMore([
            ['file', ""],
            ['real_name', '']
        ]);
        /** @var ImportRecordServices $recordServices */
        $recordServices = app()->make(ImportRecordServices::class);
        if (!$data['file']) return app('json')->fail('请上传文件');
        $file = public_path() . substr($data['file'], 1);

        $cardData = app()->make(FileService::class)->readImportExcel($file, 2, 'goods');

        $cardCount = count($cardData);

        if ($cardCount == 0) {
            return app('json')->fail('导入数据不能为空');
        }

        if ($cardCount > ImportRecordServices::MAX_IMPORT_NUM) {
            return app('json')->fail('导入数据不能超过 ' . ImportRecordServices::MAX_IMPORT_NUM);
        }
        $dat['admin_id'] = $this->adminId;
        $dat['admin_name'] = $this->adminInfo['real_name'];
        $dat['real_name'] = $data['real_name'];
        $res = $recordServices->batchUserImport($cardData, $dat,'goods');

        $failCount = $res['errorCount'] ?? 0;
        $jump = $res['jump'] ?? 0;
        $allCount = $cardCount;
        $id = $res['id'] ?? 0;

        if ($cardCount > ImportRecordServices::MAX_SINGLE_NUM) {
            $msg = '执行队列成功,稍后导入记录查看';
            $type = 2;
        } else {
            $msg = '导入成功';
            $type = 1;
        }

        return app('json')->success(compact('id', 'msg', 'failCount','jump', 'allCount', 'type'));
    }
}
