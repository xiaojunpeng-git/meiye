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
namespace app\controller\supplier\product;


use app\jobs\BatchHandleJob;
use app\services\other\Import\ImportRecordServices;
use app\services\other\queue\QueueServices;
use app\services\product\branch\StoreBranchProductAttrValueServices;
use app\services\product\branch\StoreBranchProductServices;
use app\services\product\category\StoreProductCategoryServices;
use app\services\product\product\StoreProductBatchProcessServices;
use app\services\product\product\StoreProductServices;
use app\services\product\sku\StoreProductAttrServices;
use app\services\product\sku\StoreProductAttrValueServices;
use app\services\user\label\UserLabelCateServices;
use app\services\user\label\UserLabelServices;
use mohe\services\FileService;
use mohe\services\UploadService;
use think\facade\App;
use app\controller\supplier\AuthController;

/**
 * Class StoreProduct
 * @package app\controller\supplier\product
 */
class StoreProduct extends AuthController
{
    protected $services;
	protected $branchServices;

	/**
	* @param App $app
	* @param StoreProductServices $service
	* @param StoreBranchProductServices $branchServices
	 */
    public function __construct(App $app, StoreProductServices $service, StoreBranchProductServices $branchServices)
    {
        parent::__construct($app);
        $this->services = $service;
		$this->branchServices = $branchServices;
    }

    /**
     * 显示资源列表头部
     * @return mixed
     */
    public function type_header(StoreProductCategoryServices $storeProductCategoryServices)
    {
		$where = $this->request->getMore([
			['store_name', ''],
			['cate_id', ''],
			['type', 1, '', 'status'],
			['show_type', ''],
			['sales', 'normal'],
			['pid', ''],
			['data', '', '', 'time'],
			['store_label_id', ''],
			['brand_id', '']
		]);
		$cateId = $where['cate_id'];
		if ($cateId) {
			$cateId = is_string($cateId) ? [$cateId] : $cateId;
			$cateId = array_merge($cateId, $storeProductCategoryServices->getColumn(['pid' => $cateId], 'id'));
			$cateId = array_unique(array_diff($cateId, [0]));
		}
		$where['cate_id'] = $cateId;
		$where['supplier_id'] = $this->supplierId;
        $list = $this->services->getHeader(0, $where);
        return app('json')->success(compact('list'));
    }

    /**
     * 显示资源列表
     * @return mixed
     */
    public function index(StoreProductCategoryServices $storeProductCategoryServices)
    {
        $where = $this->request->getMore([
            ['store_name', ''],
            ['cate_id', ''],
            ['type', 1, '', 'status'],
			['show_type', ''],
            ['sales', 'normal'],
            ['pid', ''],
			['data', '', '', 'time'],
			['store_label_id', ''],
			['brand_id', '']
        ]);
		$cateId = $where['cate_id'];
		if ($cateId) {
			$cateId = is_string($cateId) ? [$cateId] : $cateId;
			$cateId = array_merge($cateId, $storeProductCategoryServices->getColumn(['pid' => $cateId], 'id'));
			$cateId = array_unique(array_diff($cateId, [0]));
		}
		$where['cate_id'] = $cateId;
        $where['relation_id'] = $this->supplierId;
		$where['type'] = 2;
        $data = $this->services->getList($where);
        return app('json')->success($data);
    }

	/**
     * 获取选择的商品列表
     * @return mixed
     */
    public function search_list()
    {
        $where = $this->request->getMore([
            ['cate_id', ''],
            ['store_name', ''],
            ['type', 1, '', 'status'],
            ['is_live', 0],
            ['is_new', ''],
            ['is_vip_product', ''],
            ['is_presale_product', ''],
			['data', '', '', 'time'],
			['store_label_id', ''],
			['brand_id', ''],
			['product_type', ''],//商品类型0:普通商品，1：卡密，2：优惠券，3：虚拟商品,4：次卡商品,5:卡项商品6：预约商品
			['choose_type', ''],//选择商品列表使用场景1：秒杀、2:砍价、3:拼团、4:积分、5:套餐、7:新人礼、8:抽奖、 90:卡项关联商品、91：添加门店同步商品、92优惠活动参与商品、93优惠活动赠送商品
        ]);
        $where['is_show'] = 1;
        $where['is_del'] = 0;
		$where['type'] = 2;
		$where['relation_id'] = $this->supplierId;
        /** @var StoreProductCategoryServices $storeCategoryServices */
        $storeCategoryServices = app()->make(StoreProductCategoryServices::class);
        if ($where['cate_id'] !== '') {
            if ($storeCategoryServices->value(['id' => $where['cate_id']], 'pid')) {
                $where['sid'] = $where['cate_id'];
            } else {
                $where['cid'] = $where['cate_id'];
            }
        }
        unset($where['cate_id']);
        $list = $this->services->searchList($where);
        return $this->success($list);
    }

    /**
     * 获取分类cascader格式数据
     * @param $type
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function cascader_list(StoreProductCategoryServices $services)
    {
        return app('json')->success($services->cascaderList());
    }

    /**
     * 获取商品详细信息
     * @param int $id
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function read($id = 0)
    {
        return app('json')->success($this->services->getInfo((int)$id));
    }

	/**
	 * 保存新建或编辑
	 * @param $id
	 * @return mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function save($id)
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
//            ['sales', 0],
            ['ficti', 100],
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
            ['video_open', 0],
            ['video_link', ''],
            ['items', []],
            ['attrs', []],
			['attr', []],
            ['recommend', []],//商品推荐
            ['activity', []],
            ['coupon_ids', []],
            ['label_id', []],
            ['command_word', ''],
            ['tao_words', ''],
            ['type', 0, '', 'is_copy'],
            ['delivery_type', []],//物流设置
            ['freight', 1],//运费设置
            ['postage', 0],//邮费
            ['temp_id', 0],//运费模版
            ['recommend_list', []],
            ['brand_id', []],
            ['soure_link', ''],
            ['bar_code', ''],
            ['code', ''],
//            ['is_support_refund', 1],//是否支持退款
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
            ['limit_num', 0]//限购数量
        ]);
		$data['is_verify'] = 0;
		//供应商商品不支持门店
		$data['delivery_type'] = [1];
		$data['applicable_type'] = 0;
		if ($id) {//编辑 兼容供应商商品适用门店
			$productInfo = $this->services->get($id);
			if (!$productInfo) {
				return $this->fail('商品不存在或已删除');
			}
			$data['applicable_type'] = $productInfo['applicable_type'];
			$data['applicable_store_id'] =  $productInfo['applicable_store_id'] ? (is_string($productInfo['applicable_store_id']) ? explode(',',  $productInfo['applicable_store_id']) : $productInfo['applicable_store_id']) : [];
		}
        $this->services->saveData((int)$id, $data, 2, (int)$this->supplierId, (int)$this->supplierId);

        return $this->success($id ? '保存商品信息成功' : '添加商品成功!');
    }

    /**
     * 保存编辑
     * @param int $id
     * @param StoreBranchProductServices $services
     * @return mixed
     */
    public function update($id = 0, StoreBranchProductAttrValueServices $services)
    {
        // 【库存铁律】供应商端旧规格库存编辑已停用（与门店一致）
        return app('json')->fail('已停用：不可在此编辑规格库存，请到「库存管理」操作');
        // $data = $this->request->postMore([...]);
        // $services->updataAll(...);
    }


    /**
     * 获取关联用户标签列表
     * @param UserLabelServices $service
     * @return mixed
     */
    public function getUserLabel(UserLabelCateServices $userLabelCateServices, UserLabelServices $service)
    {
        $cate = $userLabelCateServices->getLabelCateAll(2, (int)$this->supplierId);
        $data = [];
        $label = [];
        if ($cate) {
            foreach ($cate as $value) {
                $data[] = [
                    'id' => $value['id'] ?? 0,
                    'value' => $value['id'] ?? 0,
                    'label_cate' => 0,
                    'label_name' => $value['name'] ?? '',
                    'label' => $value['name'] ?? '',
                    'store_id' => $value['store_id'] ?? 0,
                    'type' => $value['type'] ?? 1,
                ];
            }
            $label = $service->getColumn(['type' => 2, 'relation_id' => $this->supplierId], '*');
            if ($label) {
                foreach ($label as &$item) {
                    $item['label'] = $item['label_name'];
                    $item['value'] = $item['id'];
                }
            }
        }
        return app('json')->success($service->get_tree_children($data, $label));
    }

    /**
     * 修改状态
     * @param string $is_show
     * @param string $id
     * @return mixed
     */
    public function set_show($is_show = '', $id = '', StoreBranchProductServices $services)
    {
        if (!$id) return $this->fail('缺少商品ID');
        $services->setShow($this->supplierId, $id, $is_show);
        return $this->success($is_show == 1 ? '上架成功' : '下架成功');
    }

	/**
	 * 获取规格模板
	 * @return mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function get_rule()
    {
        return $this->success($this->services->getRule(2, (int)$this->supplierId));
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
        return $this->success($this->services->getInfo((int)$id));
    }


	/**
     * 获取运费模板列表
     * @return mixed
     */
    public function get_template()
    {
        return $this->success($this->services->getTemp(2, (int)$this->supplierId));
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
     * 获取商品所有规格数据
     * @param StoreBranchProductAttrValueServices $services
     * @param $id
     * @return mixed
     */
    public function getAttrs(StoreBranchProductAttrValueServices $services, $id)
    {
        if (!$id) {
            return $this->fail('缺少商品ID');
        }
        return $this->success($services->getStoreProductAttr((int)$id));
    }

	/**
	 * 商品放入回收站｜从回收站恢复
	 * @param StoreProductAttrServices $attrServices
	 * @param $id
	 * @return mixed
	 */
    public function delete(StoreProductAttrServices $attrServices, $id)
    {
        //删除商品检测是否有参与活动
        $this->services->checkActivity($id);
        $res = $this->services->del((int)$id);
        event('product.delete', [$id]);

		$this->services->cacheTag()->clear();
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
		$res = $this->services->delProduct((int)$id);
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
        if ($id > 0 && $type == 1) $this->services->checkActivity($id);
        $info = $this->services->getAttr($data, $id, $type, 2);
        return $this->success(compact('info'));
    }

	/**
     * 快速修改商品规格库存
     * @param StoreProductAttrValueServices $services
     * @param $id
     * @return mixed
     */
    public function saveProductAttrsStock(StoreProductAttrValueServices $services, $id)
    {
        // 【库存铁律】供应商端快捷改库存已停用
        return $this->fail('已停用：不可在此快捷改库存，请到「库存管理」操作');
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
			$this->services->setShow($ids, 1);
			return $this->success('上架成功');
		}
		if ($all == 1) {
			$ids = [];
			if (isset($where['type'])) {
				$where['status'] = $where['type'];
				unset($where['type']);
			}
			$where['type'] = 2;
			$where['relation_id'] = $this->supplierId;
		}
		$type = 4;//商品上架
		/** @var QueueServices $queueService */
		$queueService = app()->make(QueueServices::class);
		$queueService->setQueueData($where, 'id', $ids, $type);
		//加入队列
		BatchHandleJob::dispatch(['up', $type]);
		return $this->success('后台程序已执商品上架任务!');
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
			$this->services->setShow($ids, 0);
			return $this->success('下架成功');
		}
		if ($all == 1) {
			$all_ids = $this->services->getColumn(['is_show' => 1, 'is_del' => 0, 'type' => 2, 'relation_id' => $this->supplierId], 'id');
//			$this->services->checkActivity($all_ids);
			$ids = [];
			if (isset($where['type'])) {
				$where['status'] = $where['type'];
				unset($where['type']);
			}
			$where['type'] = 2;
			$where['relation_id'] = $this->supplierId;
		}
		$type = 4;//商品下架
		/** @var QueueServices $queueService */
		$queueService = app()->make(QueueServices::class);
		$queueService->setQueueData($where, 'id', $ids, $type);
		//加入队列
		BatchHandleJob::dispatch(['down', $type]);
		return $this->success('后台程序已执商品下架任务!');
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
		$where['type'] = 2;
		$where['relation_id'] = $this->supplierId;
		//批量操作
		$batchProcessServices->batchProcess((int)$type, $ids, $data, !!$all, $where);
		return app('json')->success('已加入消息队列,请稍后查看');
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

        $cardData = app()->make(FileService::class)->readImportExcel($file, 2, 'supplier_goods');

        $cardCount = count($cardData);

        if ($cardCount == 0) {
            return app('json')->fail('导入数据不能为空');
        }

        if ($cardCount > ImportRecordServices::MAX_IMPORT_NUM) {
            return app('json')->fail('导入数据不能超过 ' . ImportRecordServices::MAX_IMPORT_NUM);
        }
        $dat['admin_id'] = $this->supplierId;
        $dat['admin_name'] = $this->supplierInfo['supplier_name'];
        $dat['real_name'] = $data['real_name'];
        $res = $recordServices->batchUserImport($cardData, $dat,'goods',2,$this->supplierId);

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
