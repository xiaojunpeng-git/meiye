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
namespace app\controller\store\product;


use app\jobs\BatchHandleJob;
use app\jobs\product\ProductRelationJob;
use app\jobs\store\SynchStocksJob;
use app\services\other\Import\ImportRecordServices;
use app\services\other\queue\QueueServices;
use app\services\product\branch\StoreBranchProductAttrValueServices;
use app\services\product\branch\StoreBranchProductServices;
use app\services\product\category\StoreProductCategoryServices;
use app\services\product\product\StoreProductBatchProcessServices;
use app\services\product\product\StoreProductServices;
use app\services\product\sku\StoreProductAttrServices;
use app\services\product\sku\StoreProductAttrValueServices;
use app\services\store\SystemStoreServices;
use app\services\user\label\UserLabelCateServices;
use app\services\user\label\UserLabelServices;
use mohe\services\FileService;
use mohe\services\UploadService;
use think\facade\App;
use app\controller\store\AuthController;

/**
 * Class StoreProduct
 * @package app\controller\store\product
 */
class StoreProduct extends AuthController
{
    /**
     * @var StoreProductServices
     */
    protected $services;

    /**
     * @var StoreBranchProductServices
     */
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
            ['store_cate_id', ''],
            ['type', 1, '', 'status'],
            ['show_type', ''],
            ['sales', ''],
            ['pid', ''],
            ['data', '', '', 'time'],
            ['store_label_id', ''],
            ['brand_id', ''],
            ['product_type', ''],//商品类型0:普通商品，1：卡密，2：优惠券，3：虚拟商品,4：次卡商品,5:卡项商品6：预约商品
        ]);
        $cateId = $where['cate_id'];
        if ($cateId) {
            $cateId = is_string($cateId) ? [$cateId] : $cateId;
            $cateId = array_merge($cateId, $storeProductCategoryServices->getColumn(['pid' => $cateId], 'id'));
            $cateId = array_unique(array_diff($cateId, [0]));
        }
        $where['cate_id'] = $cateId;
        $storeCateId = $where['store_cate_id'];
        if ($storeCateId) {
            $storeCateId = is_string($storeCateId) ? [$storeCateId] : $storeCateId;
            $storeCateId = array_merge($storeCateId, $storeProductCategoryServices->getColumn(['pid' => $storeCateId], 'id'));
            $storeCateId = array_unique(array_diff($storeCateId, [0]));
        }
        $where['store_cate_id'] = $storeCateId;
        $list = $this->services->getHeader((int)$this->storeId, $where);
        return app('json')->success(compact('list'));
    }

    /**
     * 显示资源列表
     * @param StoreProductCategoryServices $storeProductCategoryServices
     * @param SystemStoreServices $storeServices
     * @return \think\Response
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function index(StoreProductCategoryServices $storeProductCategoryServices, SystemStoreServices $storeServices)
    {
        $where = $this->request->getMore([
            ['store_name', ''],
            ['cate_id', ''],
            ['store_cate_id', ''],
            ['type', 1, '', 'status'],
            ['show_type', ''],
            ['sales', 'normal'],
            ['pid', ''],
            ['data', '', '', 'time'],
            ['store_label_id', ''],
            ['brand_id', ''],
            ['product_type', ''],//商品类型0:普通商品，1：卡密，2：优惠券，3：虚拟商品,4：次卡商品,5:卡项商品6：预约商品
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
        $storeCateId = $where['store_cate_id'];
        if ($storeCateId) {
            $storeCateId = is_string($storeCateId) ? [$storeCateId] : $storeCateId;
            $storeCateId = array_merge($storeCateId, $storeProductCategoryServices->getColumn(['pid' => $storeCateId], 'id'));
            $storeCateId = array_unique(array_diff($storeCateId, [0]));
        }
        $where['store_cate_id'] = $storeCateId;
        $where['relation_id'] = $this->storeId;
        $where['type'] = 1;
        $data = $this->services->getList($where);
        $storeInfo = $storeServices->get($this->storeId, ['id', 'product_change_price_status']);
        $data['product_change_price_status'] = $storeInfo['product_change_price_status'] ?? 0;
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
            ['store_cate_id', ''],
            ['store_name', ''],
            ['type', 1, '', 'status'],
            ['is_live', 0],
            ['is_new', ''],
            ['is_vip_product', ''],
            ['is_presale_product', ''],
            ['data', '', '', 'time'],
            ['store_label_id', ''],
            ['brand_id', ''],
            ['is_card', 0],//是否卡项获取关联商品
            ['product_type', ''],//商品类型0:普通商品，1：卡密，2：优惠券，3：虚拟商品,4：次卡商品,5:卡项商品6：预约商品
            ['choose_type', ''],//选择商品列表使用场景1：秒杀、2:砍价、3:拼团、4:积分、5:套餐、7:新人礼、8:抽奖、 90:卡项关联商品、91：添加门店同步商品、92优惠活动参与商品、93优惠活动赠送商品
        ]);
        $where['is_show'] = 1;
        $where['is_del'] = 0;
        $where['type'] = 1;
        $where['relation_id'] = $this->storeId;
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
        if (!$id || !is_numeric($id)) {
            return app('json')->fail('参数错误');
        }
        return app('json')->success($this->services->getInfo((int)$id));
    }

    /**
     * 保存新建或编辑
     * @param SystemStoreServices $storeServices
     * @param $id
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function save(SystemStoreServices $storeServices, $id)
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
            ['related', []],//卡项关联商品
            ['recommend', []],//商品推荐
            ['activity', []],
            ['coupon_ids', []],
            ['label_id', []],
            ['command_word', ''],
            ['tao_words', ''],
            ['type', 0, '', 'is_copy'],
            ['delivery_type', []],//物流设置 配送方式:1、快递发货，2、到店自提，3、同城配送
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
            ['show_type', 0],//商品展示
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
        ]);
        //门店商品编辑 需要再次审核
        $storeId = (int)$this->storeId;
        $storeInfo = [];
        if ($storeId) {
            $storeInfo = $storeServices->getStoreInfo($storeId);
        }
        if (!$storeInfo) {
            return $this->fail('门店不存在或者已下架');
        }
        if (!$id && !$storeInfo['product_status']) {//门店编辑已有商品不验证
            return $this->fail('暂不支持添加商品，请联系平台管理员');
        }
        //有自建商品分类权限门店
        if ($storeInfo['product_category_status'] && isset($data['store_cate_id']) && count($data['store_cate_id']) < 1) {
            return $this->fail('请选择门店商品分类');
        }
        $data['is_verify'] = 0;
        //门店开启免审
        if (isset($storeInfo['product_verify_status']) && $storeInfo['product_verify_status']) {
            $data['is_verify'] = 1;
        }
        if (!in_array($data['product_type'], [4, 5, 6])) {
            $delivery_type = $data['delivery_type'];//配送方式:1、快递发货，2、到店自提，3、同城配送
            $data['store_delivery_type'] = [];
            unset($data['delivery_type']);
            if ($data['product_type'] == 0 && (in_array(1, $delivery_type) || in_array(3, $delivery_type))) {
                $data['delivery_type'][] = 3;
                if (in_array(1, $delivery_type)) {
                    $data['store_delivery_type'][] = 1;
                }
                if (in_array(3, $delivery_type)) {
                    $data['store_delivery_type'][] = 2;
                }
            }
            if (in_array(2, $delivery_type)) $data['delivery_type'][] = 2;
            //门店商品不支持平台配送
            $data['delivery_type'] = array_diff($data['delivery_type'], [1]);
        } else {
            $data['delivery_type'] = [2];
            $data['store_delivery_type'] = [];
        }


        $this->services->saveData((int)$id, $data, 1, (int)$this->storeId, (int)$this->storeStaffId);

        return $this->success($id ? '保存商品信息成功' : '添加商品成功!');
    }

    /**
     * 修改门店分类
     * @param $id
     * @return mixed
     */
    public function updateStoreCate($id = 0)
    {
        $data = $this->request->postMore([
            ['store_cate_id', []],
        ]);
        if (!$id) return $this->fail('参数有误!');
        if (isset($data['store_cate_id']) && count($data['store_cate_id']) < 1) return $this->fail('请选择门店商品分类!');
        $cateId = $this->services->value(['id' => $id], 'cate_id');
        $cateId = explode(',', $cateId);
        $cate_id = array_merge($cateId, $data['store_cate_id']);
        $data['store_cate_id'] = implode(',', $data['store_cate_id']);
        $res = $this->services->update($id, $data);
        if ($res) {
            //商品分类关联
            if ($cate_id) ProductRelationJob::dispatch([$id, $cate_id, 1, (int)($data['is_show'] ?? 0)]);
        }
        return $this->success($res ? '修改成功!' : '修改失败!');
    }

    /**
     * 保存编辑
     * @param int $id
     * @param StoreBranchProductServices $services
     * @return mixed
     */
    public function update($id = 0, StoreBranchProductAttrValueServices $services)
    {
        // 【库存铁律】旧门店规格编辑（删光重建 SKU 并写库存）已停用
        return app('json')->fail('已停用：不可在此编辑规格库存；资料请走商品编辑，库存请到「库存管理」操作');
        // $data = $this->request->postMore([
        //     ['attrs', []],
        //     ['label_id', []],
        //     ['is_show', 1]
        // ]);
        // $storeId = $this->storeId;
        // $services->updataAll((int)$id, (array)$data, (int)$storeId);
        // return app('json')->success('保存商品信息成功');
    }

    /**
     * 门店同步库存（已停用）
     * 【库存铁律】禁止从平台覆盖门店库存；库存仅能通过库存管理与销售出入库变动
     * @return mixed
     */
    public function synchStocks()
    {
        return app('json')->fail('已停用：不可同步平台库存覆盖门店，请到「库存管理」入库/出库/盘点调整');
        // [$ids] = $this->request->postMore([
        //     ['ids', []]
        // ], true);
        // if (!count($ids)) return $this->fail('请选择商品');
        // $storeId = $this->storeId;
        // $idsArr = array_chunk($ids, 5);
        // foreach ($idsArr as $syncIds) {
        //     SynchStocksJob::dispatch([$syncIds, $storeId]);
        // }
        // return app('json')->success('库存同步已加入队列执行，请稍后查看');
    }

    /**
     * 获取关联用户标签列表
     * @param UserLabelServices $service
     * @return mixed
     */
    public function getUserLabel(UserLabelCateServices $userLabelCateServices, UserLabelServices $service)
    {
        $cate = $userLabelCateServices->getLabelCateAll(1, (int)$this->storeId);
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
                    'relation_id' => $value['store_id'] ?? 0,
                    'type' => $value['type'] ?? 1,
                ];
            }
            $label = $service->getColumn(['type' => 1, 'relation_id' => $this->storeId], '*');
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
        if (!$id || !is_numeric($id)) {
            return $this->fail('参数错误');
        }
        if (!in_array($is_show, [0, 1])) {
            return $this->fail('参数错误');
        }
        $services->setShow($this->storeId, $id, $is_show);
        return $this->success($is_show == 1 ? '上架成功' : '下架成功');
    }

    /**
     * 获取规格模板
     * @return mixed
     */
    public function get_rule()
    {
        return $this->success($this->services->getRule(1, (int)$this->storeId));
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
        if (!$id || !is_numeric($id)) {
            return $this->fail('参数错误');
        }
        return $this->success($this->services->getInfo((int)$id));
    }


    /**
     * 获取运费模板列表
     * @return mixed
     */
    public function get_template()
    {
        return $this->success($this->services->getTemp(1, (int)$this->storeId));
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
        $productInfo = $this->services->getCacheProductInfo((int)$id);
        if (!$productInfo) {
            return $this->fail('商品不存在或已删除');
        }
        $with = [];
        if ($productInfo['product_type'] == 6) {//预约商品
            $with = ['reservationTimeData'];
        }
        return $this->success($services->getStoreProductAttr((int)$id, 0, $with));
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
        $info = $this->services->getAttr($data, $id, $type, 1);
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
        // 【库存铁律】商品页快捷改库存已停用；出入库单据内部仍可调用 Service
        return $this->fail('已停用：不可在商品页快捷改库存，请到「库存管理」入库/出库/盘点操作');
        // if (!$id) {
        //     return $this->fail('缺少商品ID');
        // }
        // ...
    }

    /**
     * 快速修改商品规格售价
     * @param StoreProductAttrValueServices $services
     * @param $id
     * @return mixed
     */
    public function saveProductAttrsPrice(StoreProductAttrValueServices $services, $id)
    {
        if (!$id) {
            return $this->fail('缺少商品ID');
        }
        [$attrs] = $this->request->getMore([
            ['attrs', []]
        ], true);
        if (!$attrs) {
            return $this->fail('请重新修改规格库存');
        }
        $productInfo = $this->services->getCacheProductInfo((int)$id);
        if (!$productInfo) {
            return $this->fail('商品不存在或已删除');
        }
        //判断规格的属性值是否存在
        $requiredKeys = ['unique', 'price'];
        foreach ($attrs as $attr) {
            $missingKeys = array_diff($requiredKeys, array_keys($attr));
            if (!empty($missingKeys)) {
                return app('json')->fail('请重新修改规格库存');
            }
        }
        return $this->success(['price' => $services->updateAttrs((int)$id, $attrs, 'price')]);
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
            $where['type'] = 1;
            $where['relation_id'] = $this->storeId;
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
            $all_ids = $this->services->getColumn(['is_show' => 1, 'is_del' => 0, 'type' => 1, 'relation_id' => $this->storeId], 'id');
//			$this->services->checkActivity($all_ids);
            $ids = [];
            if (isset($where['type'])) {
                $where['status'] = $where['type'];
                unset($where['type']);
            }
            $where['type'] = 1;
            $where['relation_id'] = $this->storeId;
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
        if (!$data && !in_array($type, [11, 12])) {
            return $this->fail('请选择批处理数据');
        }
        if (isset($where['type'])) {
            $where['status'] = $where['type'];
            unset($where['type']);
        }
        if ($all == 1 && $ids) {//所有页，存在反选取消
            $where['not_ids'] = is_string($ids) ? stringToIntArray($ids) : $ids;
        }
        if ($all == 1 && $type == 12) {
            $where['is_del'] = 1;
            $where['is_show'] = 0;
        }
        $where['type'] = 1;
        $where['relation_id'] = $this->storeId;
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

        $cardData = app()->make(FileService::class)->readImportExcel($file, 2, 'store_goods');

        $cardCount = count($cardData);

        if ($cardCount == 0) {
            return app('json')->fail('导入数据不能为空');
        }

        if ($cardCount > ImportRecordServices::MAX_IMPORT_NUM) {
            return app('json')->fail('导入数据不能超过 ' . ImportRecordServices::MAX_IMPORT_NUM);
        }
        $dat['admin_id'] = $this->storeStaffId;
        $dat['admin_name'] = $this->storeStaffInfo['staff_name'];
        $dat['real_name'] = $data['real_name'];
        $res = $recordServices->batchUserImport($cardData, $dat, 'goods', 1, $this->storeId);

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

        return app('json')->success(compact('id', 'msg', 'failCount', 'jump', 'allCount', 'type'));
    }

    /**
     * 获取数据
     * @param $id
     * @return \think\Response
     */
    public function productData($id = 0)
    {
        if (!$id) {
            return app('json')->fail('参数有误');
        }
        $data = $this->services->get($id, ['show_type', 'delivery_type', 'store_delivery_type']);
        $data = $data->toArray();
        return app('json')->success($data);
    }

    /**
     * 修改数据
     * @param $id
     * @return \think\Response
     */
    public function updataData($id = 0)
    {
        $data = $this->request->postMore([
            ['delivery_type', []],//物流设置 配送方式:1、快递发货，2、到店自提，3、同城配送
            ['product_type', 0],//商品类型
            ['show_type', 0],//商品展示
            ['field', ''],
        ]);
        if (!$id) {
            return app('json')->fail('参数有误');
        }
        if ($data['field'] == 'delivery_type') {
            if (!in_array($data['product_type'], [4, 5, 6])) {
                $delivery_type = $data['delivery_type'];//配送方式:1、快递发货，2、到店自提，3、同城配送
                $data['store_delivery_type'] = [];
                unset($data['delivery_type']);
                if ($data['product_type'] == 0 && (in_array(1, $delivery_type) || in_array(3, $delivery_type))) {
                    $data['delivery_type'][] = 3;
                    if (in_array(1, $delivery_type)) {
                        $data['store_delivery_type'][] = 1;
                    }
                    if (in_array(3, $delivery_type)) {
                        $data['store_delivery_type'][] = 2;
                    }
                }
                if (in_array(2, $delivery_type)) $data['delivery_type'][] = 2;
                //门店商品不支持平台配送
                $data['delivery_type'] = array_diff($data['delivery_type'], [1]);
            } else {
                $data['delivery_type'] = [2];
                $data['store_delivery_type'] = [];
            }
        } else {
            unset($data['delivery_type']);
        }
        unset($data['product_type'], $data['field']);
        $res = $this->services->update($id, $data);
        if ($res) {
            return app('json')->success('修改成功');
        } else {
            return app('json')->fail('修改失败');
        }
    }
}
