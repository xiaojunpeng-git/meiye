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
namespace app\controller\admin\v1\other\export;

use app\controller\admin\AuthController;
use app\Request;
use app\services\activity\bargain\StoreBargainServices;
use app\services\activity\combination\StoreCombinationServices;
use app\services\activity\combination\StorePinkServices;
use app\services\activity\lottery\LuckLotteryRecordServices;
use app\services\activity\lottery\LuckLotteryServices;
use app\services\activity\seckill\StoreSeckillServices;
use app\services\agent\SystemRegionAgentServices;
use app\services\order\store\BranchOrderServices;
use app\services\order\StoreOrderWriteOffServices;
use app\services\product\inventory\InventoryScopeServices;
use app\services\product\inventory\StoreProductStockCountServices;
use app\services\product\inventory\StoreProductStockDetailServices;
use app\services\product\inventory\StoreProductStockOrderServices;
use app\services\product\inventory\StoreProductStockOutOrderServices;
use app\services\spread\AgentManageServices;
use app\services\order\StoreOrderInvoiceServices;
use app\services\order\StoreOrderServices;
use app\services\other\export\ExportServices;
use app\services\other\ExpressServices;
use app\services\other\queue\QueueAuxiliaryServices;
use app\services\other\queue\QueueServices;
use app\services\product\product\StoreProductServices;
use app\services\store\finance\StoreFinanceFlowServices;
use app\services\other\Import\ImportRecordErrorServices;
use app\services\other\Import\ImportRecordServices;
use app\services\supplier\finance\SupplierFlowingWaterServices;
use app\services\store\SystemStoreServices;
use app\services\system\form\SystemFormDataServices;
use app\services\user\member\MemberCardServices;
use app\services\user\UserBillServices;
use app\services\user\UserBrokerageServices;
use app\services\user\UserMoneyServices;
use app\services\user\UserRechargeServices;
use app\services\user\UserServices;
use app\services\wechat\WechatUserServices;
use app\services\store\SystemStoreStaffServices;
use think\facade\App;


/**
 * 导出excel类
 * Class ExportExcel
 * @package app\controller\admin\v1\export
 */
class ExportExcel extends AuthController
{
    /**
     * @var ExportServices
     */
    protected $service;

    /**
     * ExportExcel constructor.
     * @param App $app
     * @param ExportServices $services
     */
    public function __construct(App $app, ExportServices $services)
    {
        parent::__construct($app);
        $this->service = $services;
    }

    /**
     * 用户列表导出
     * @param UserServices $services
     * @return \think\Response
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author wuhaotian
     */
    public function user(UserServices $services)
    {
        $where = $this->request->getMore([
            ['page', 1],
            ['limit', 1000],
            ['nickname', ''],
            ['status', ''],
            ['pay_count', ''],
            ['is_promoter', ''],
            ['order', ''],
            ['data', ''],
            ['user_type', ''],
            ['country', ''],
            ['province', ''],
            ['city', ''],
            ['user_time_type', ''],
            ['user_time', ''],
            ['sex', ''],
            [['level', 0], 0],
            [['group_id', 'd'], 0],
            ['label_id', 0],
            ['now_money', 'normal'],
            ['field_key', ''],
            ['isMember', ''],
            ['label_ids', ''],
            ['store_id', '', '', 'belong_store_id'],
            ['integral', ''],
            ['now_money_peice', ''],
            ['recharge_sum', ''],
            ['recharge_price', ''],
            ['pay_price', ''],
            ['cash_consume_price', ''],
            ['recent_consume_type', ''],
            ['recent_consume_days', 0],
            ['recent_consume_time', ''],
            ['no_consume_type', ''],
            ['no_consume_days', 0],
            ['no_consume_time', ''],
            ['writeoff_count', ''],
            ['recent_writeoff_type', ''],
            ['recent_writeoff_days', 0],
            ['recent_writeoff_time', ''],
            ['no_writeoff_type', ''],
            ['no_writeoff_days', 0],
            ['no_writeoff_time', ''],
            ['sale_date_time', ''],
            ['ids', '', '', 'uid'],
        ]);
        if ($where['label_id']) {
            $where['label_id'] = stringToIntArray($where['label_id']);
        } elseif ($where['label_ids']) {
            $where['label_id'] = stringToIntArray($where['label_ids']);
            unset($where['label_ids']);
        }
        $where['user_time_type'] = $where['user_time_type'] == 'all' ? '' : $where['user_time_type'];
        if ($where['uid'] !== '') {
            $where['uid'] = stringToIntArray($where['uid']);
        } else {
            unset($where['uid']);
        }
        if ($this->adminType == 3 && $this->agentId) {
            /** @var SystemRegionAgentServices $regionAgentServices */
            $regionAgentServices = app()->make(SystemRegionAgentServices::class);
            $storeIds = $regionAgentServices->getRegionAgentStoreId((int)$this->agentId);
            if ($storeIds) {
                $where['store_id'] = $storeIds;
            } else {
                $where['store_id'] = -2;
            }
        }
        $data = $services->index($where);
        return $this->success($this->service->user($data['list'] ?? []));
    }

    /**
     * 保存用户资金监控的excel表格
     * @param UserMoneyServices $services
     * @return mixed
     */
    public function userFinance(UserMoneyServices $services)
    {
        $where = $this->request->getMore([
            ['start_time', ''],
            ['end_time', ''],
            ['nickname', ''],
            ['type', ''],
        ]);
        $data = $services->getMoneyList($where, '*', $this->service->limit);
        return $this->success($this->service->userFinance($data['data'] ?? []));
    }

    /**
     * 下载记录
     * @param ImportRecordServices $services
     * @return mixed
     * @throws \ReflectionException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function importUserList(ImportRecordServices $services)
    {
        $where = $this->request->getMore([
            ['data', '', '', 'time'],//时间
            ['status', ''], //状态 0:正在导入 1:已完成 -1:导入失败
            ['keyword', ''],//关键字搜索
            ['type', '', '','import_type'], //类型 user: 用户 goods: 商品
        ]);
        $where['type'] = 0;
        $where['is_del'] = 0;
        $where['relation_id'] = 0;
        return $this->success($services->getImportList($where));
    }

    /**
     * 导入用户错误记录导出
     * @return \think\Response
     * @throws \ReflectionException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function importUserExport(ImportRecordErrorServices $errorServices, ImportRecordServices $recordServices)
    {
        $where = $this->request->getMore([
            ['record_id', '',],
            ['type', '',],
        ]);
        if (!$where['record_id']) {
            return $this->fail('参数错误');
        }
        $type = $where['type'];
        $recordId = (int)$where['record_id'];
        $record = $recordServices->get($recordId);
        if (!$record || (int)($record['is_del'] ?? 0) === 1) {
            return $this->fail('导入记录不存在');
        }
        // 平台端仅可下载平台归属记录
        if ((int)($record['type'] ?? -1) !== 0 || (int)($record['relation_id'] ?? -1) !== 0) {
            return $this->fail('无权下载该导入记录');
        }
        unset($where['type']);
        $data = $errorServices->getErrorList($where);
        $recordServices->bcInc($recordId, 'down_count', 1);
        $importType = (string)($record['import_type'] ?? '');
        if ($type == 'user') {
            return $this->success($this->service->importUser($data['list'] ?? []));
        } else if ($type == 'user_card') {
            return $this->success($this->service->importUserCard($data['list'] ?? []));
        } else if (in_array($importType, ['stock_initial_in', 'stock_in', 'stock_out'], true)
            || in_array((string)$type, ['stock_initial_in', 'stock_in', 'stock_out'], true)) {
            return $this->success($this->service->importStock($data['list'] ?? []));
        } else {
            return $this->success($this->service->importProduct($data['list'] ?? []));
        }
    }


    /**
     * 商品迁移数据导出
     * @param StoreProductServices $services
     * @return \think\Response
     */
    public function adminProductImport(StoreProductServices $services)
    {
        $where = $this->request->getMore([
            ['store_name', ''],
            ['cate_id', ''],
            ['type', '', '', 'status'],
            ['store_id', ''],
            ['supplier_id', ''],
            ['sales', 'normal'],
            ['store_label_id', ''],
            ['brand_id', ''],
            ['ids', ''],
            ['all', 0]
        ]);
        if (!$where['ids'] && $where['all'] == 0) return $this->fail('请选择批处理商品');
        if ($where['supplier_id']) {
            $where['relation_id'] = $where['supplier_id'];
            $where['type'] = 2;
        } else if ($where['store_id']) {
            $where['relation_id'] = $where['store_id'];
            $where['type'] = 1;
        } else {
            $where['pid'] = 0;
        }
        $where['ids'] = stringToIntArray($where['ids']);

        unset($where['supplier_id'], $where['store_id'], $where['all']);

        $data = $services->searchList($where, true, $this->service->limit);
        return $this->success($this->service->adminProductImport($data['list'] ?? []));
    }

    /**
     * 用户佣金
     * @param UserBrokerageServices $services
     * @return mixed
     */
    public function userCommission(UserBrokerageServices $services)
    {
        $where = $this->request->getMore([
            ['page', 1],
            ['limit', 20],
            ['nickname', ''],
            ['price_max', ''],
            ['price_min', ''],
            ['excel', '1'],
            ['date', '', '', 'time']
        ]);
        $data = $services->getCommissionList($where, $this->service->limit);
        return $this->success($this->service->userCommission($data['list'] ?? []));
    }

    /**
     * 用户积分
     * @param UserBillServices $services
     * @return mixed
     */
    public function userPoint(UserBillServices $services)
    {
        $where = $this->request->getMore([
            ['start_time', ''],
            ['end_time', ''],
            ['nickname', ''],
            ['excel', '1'],
        ]);
        $data = $services->getPointList($where, '*', $this->service->limit);
        return $this->success($this->service->userPoint($data['list'] ?? []));
    }

    /**
     * 用户储值
     * @param UserRechargeServices $services
     * @return mixed
     */
    public function userRecharge(UserRechargeServices $services)
    {
        $where = $this->request->getMore([
            ['data', ''],
            ['paid', ''],
            ['page', 1],
            ['limit', 20],
            ['nickname', ''],
            ['excel', '1'],
        ]);
        $data = $services->getRechargeList($where, '*', $this->service->limit);
        return $this->success($this->service->userRecharge($data['list'] ?? []));
    }

    /**
     * 分销管理 用户推广
     * @param AgentManageServices $services
     * @return mixed
     */
    public function userAgent(AgentManageServices $services)
    {
        $where = $this->request->getMore([
            ['nickname', ''],
            ['data', ''],
            ['excel', '1'],
        ]);
        $data = $services->agentSystemPage($where, $this->service->limit);
        return $this->success($this->service->userAgent($data['list']));
    }

    /**
     * 微信用户导出
     * @param WechatUserServices $services
     * @return mixed
     */
    public function wechatUser(WechatUserServices $services)
    {
        $where = $this->request->getMore([
            ['page', 1],
            ['limit', 20],
            ['nickname', ''],
            ['data', ''],
            ['tagid_list', ''],
            ['groupid', '-1'],
            ['sex', ''],
            ['export', '1'],
            ['subscribe', '']
        ]);
        $tagidList = explode(',', $where['tagid_list']);
        foreach ($tagidList as $k => $v) {
            if (!$v) {
                unset($tagidList[$k]);
            }
        }
        $tagidList = array_unique($tagidList);
        $where['tagid_list'] = implode(',', $tagidList);
        $data = $services->exportData($where);
        return $this->success($this->service->wechatUser($data));
    }

    /**
     * 商铺砍价活动导出
     * @param StoreBargainServices $services
     * @return mixed
     */
    public function storeBargain(StoreBargainServices $services)
    {
        $where = $this->request->getMore([
            ['start_status', ''],
            ['status', ''],
            ['store_name', ''],
            ['page', 0]
        ]);
        $where['is_del'] = 0;
        $page = $where['page'];
        unset($where['page']);
        $data = $services->getList($where, $page, $this->service->limit);
        return $this->success($this->service->storeBargain($data));
    }

    /**
     * 商铺拼团导出
     * @param StoreCombinationServices $services
     * @return mixed
     */
    public function storeCombination(StoreCombinationServices $services)
    {
        $where = $this->request->getMore([
            ['start_status', ''],
            ['is_show', ''],
            ['store_name', ''],
            ['page', 0]
        ]);
        $where['is_del'] = 0;
        $page = $where['page'];
        unset($where['page']);
        $data = $services->getList($where, $page, $this->service->limit);
        /** @var StorePinkServices $storePinkServices */
        $storePinkServices = app()->make(StorePinkServices::class);
        $countAll = $storePinkServices->getPinkCount([]);
        $countTeam = $storePinkServices->getPinkCount(['k_id' => 0, 'status' => 2]);
        $countPeople = $storePinkServices->getPinkCount(['k_id' => 0]);
        foreach ($data as &$item) {
            $item['count_people'] = $countPeople[$item['id']] ?? 0;//拼团数量
            $item['count_people_all'] = $countAll[$item['id']] ?? 0;//参与人数
            $item['count_people_pink'] = $countTeam[$item['id']] ?? 0;//成团数量
        }
        return $this->success($this->service->storeCombination($data));
    }

    /**
     * 商铺秒杀导出
     * @param StoreSeckillServices $services
     * @return mixed
     */
    public function storeSeckill(StoreSeckillServices $services)
    {
        $where = $this->request->getMore([
            ['start_status', ''],
            ['status', ''],
            ['store_name', ''],
            ['page', 0]
        ]);
        $where['is_del'] = 0;
        $page = $where['page'];
        unset($where['page']);
        $data = $services->getList($where, $page, $this->service->limit);
        return $this->success($this->service->storeSeckill($data));
    }

    /**
     * 导出商品卡号、卡密模版
     * @return mixed
     */
    public function storeProductCardTemplate()
    {
        return $this->success($this->service->storeProductCardTemplate());
    }

    /**
     * 商铺产品导出
     * @param StoreProductServices $services
     * @return mixed
     */
    public function storeProduct(StoreProductServices $services)
    {
        $where = $this->request->getMore([
            ['field_key', ''],//搜索字段类型
            ['store_name', ''],//关键词
            ['product_type', ''],//商品类型0:普通商品，1：卡密，2：优惠券，3：虚拟商品,4：次卡商品,5:卡项商品6：预约商品
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
            ['ids', ''],
            ['all', 0]
        ]);
        if (!$where['ids'] && $where['all'] == 0) return $this->fail('请选择导出商品');
        if ($where['supplier_id']) {
            $where['relation_id'] = $where['supplier_id'];
            $where['type'] = 2;
        } elseif ($where['store_id']) {
            $where['relation_id'] = $where['store_id'];
            $where['type'] = 1;
        } else {
            $where['pid'] = 0;
        }
        if ($where['ids']) {
            $where['ids'] = is_string($where['ids']) ? stringToIntArray($where['ids']) : $where['ids'];
        }
        if ($where['all'] == 1 && $where['ids']) {//所有页，存在反选取消
            $where['not_ids'] = $where['ids'];
            unset($where['ids']);
        }
        unset($where['supplier_id'], $where['store_id'], $where['all']);

        $data = $services->searchList($where, true, $this->service->limit);
        return $this->success($this->service->storeProduct($data['list'] ?? []));
    }

    /**
     * 订单列表导出
     * @param StoreOrderServices $services
     * @param SystemRegionAgentServices $regionAgentServices
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function storeOrder(StoreOrderServices $services, SystemRegionAgentServices $regionAgentServices)
    {
        $where_tmp = $this->request->getMore([
            ['status', ''],
            ['real_name', ''],
            ['is_del', ''],
            ['data', '', '', 'time'],
            ['pay_time', ''],
            ['take_time', ''], //收货时间
            ['deliveryType', ''], //1 快递、2 商家配送、3 UU、4 达达、5 无需配送、6到店核销
            ['interval_price_min', ''],
            ['interval_price_max', ''],
            ['type', ''],
            ['export_type', ''],
            ['pay_type', ''],
            ['plat_type', -1],
            ['order', ''],
            ['field_key', ''],
            ['supplier_id', ''],
            ['ids', ''],
            ['product_type', ''],
            ['order_type', ''],
            ['store_id', -1],
            ['link_type', ''],
            ['yeji_staff', ''],
            ['yeji_shouyi', ''],
            ['cash_choose',''],
            ['source',''],
            ['time', ''],
            ['date_range', ''],
            ['staff_id', ''],
            ['real_name', ''],
            ['search_order_id', ''],
            ['search_verify_code', ''],
            ['search_product', ''],
            ['search_user', ''],
            ['service_object', ''],
            ['is_kuadian','']
        ]);
        //门店订单参数为time
        $time = $this->request->param('time', '');
        if ($time && !$where_tmp['time']) {
            $where_tmp['time'] = $time;
        }
        $type = $where_tmp['export_type'];
        unset($where_tmp['export_type']);
        $exportByIds = !empty($where_tmp['ids']);
        $exportIds = [];
        if ($exportByIds) {
            $exportIds = array_values(array_filter(array_map('intval', explode(',', (string)$where_tmp['ids']))));
            $exportByIds = !empty($exportIds);
        }
        $where = $with = [];
        if ($exportByIds) {
            $where['id'] = $exportIds;
        } else {
            unset($where_tmp['ids']);
        }
        if (!$exportByIds) {
            $where = array_merge($where, $where_tmp);
        }
        $where['product_type'] = $where_tmp['product_type'] ?? '';
        $where['order_type'] = $where_tmp['order_type'] ?? '';
        $where['status'] = trim((string)($where['status'] ?? $where_tmp['status'] ?? ''));
        if ($type) {
            $where['status'] = 1;
            $where['paid'] = 1;
            $where['is_del'] = 0;
            $where['shipping_type'] = 1;
            $where['pid'] = 0;
            $with = ['pink', 'refund' => function ($query) {
                $query->whereIn('refund_type', [0, 1, 2, 4, 5])->where('is_cancel', 0)->where('is_del', 0)->field('id,store_order_id');
            }];
        }
        $where['is_system_del'] = 0;
        $where['plat_type'] = $where_tmp['plat_type'] ?? -1;
        $where['store_id'] = $where_tmp['store_id'] ?? -1;
        $where['supplier_id'] = $where_tmp['supplier_id'] ?? '';
        if (!$where_tmp['store_id']) {//无筛选
            if ($this->adminType == 3 && $this->agentId) {//区域代理商登录
                $storeIds = $regionAgentServices->getRegionAgentStoreId((int)$this->agentId);
                if ($storeIds) {
                    $where['store_id'] = $storeIds;
                } else {
                    return $this->success($this->service->storeOrder([], $type));
                }
            }
        }
        // 勾选导出按 id，不再套 pid；按筛选导出时与门店订单列表一致
        if (!$exportByIds && !$type) {
            $where['pid'] = -2;
            $hasSearch = StoreOrderServices::hasOrderListSearch($where_tmp);
            if (!$hasSearch && !in_array($where['status'], [-1, -2, -3])) {
                $where['pid'] = -3;
            }
        }
        $where['not_recharge'] = 1;
        $where['not_auto'] = 1;
        if (trim((string)($where_tmp['search_verify_code'] ?? '')) !== '') {
            unset($where['not_auto']);
        }
        if (!$exportByIds) {
            $where['service_object'] = $where_tmp['service_object'] ?? '';
        }
        $where['status'] = trim((string)$where['status']);
        $where['type'] = trim((string)($where['type'] ?? $where_tmp['type'] ?? ''));
        $data = $services->getExportList($where, $with, $this->service->limit);
        return $this->success($this->service->storeOrder($data, $type));
    }

    public function writeoff(StoreOrderWriteOffServices $services){
        $where = $this->request->postMore([
            ['order_id', '', '', 'keyword'], //订单编号
            ['ordering_store_id', ''], //下单门店
            ['write_off_store_id', ''], //核销门店
            ['service_type', ''], //核销身份
            ['staff', ''], //核销店员
            ['user_key', ''], //客户资料
            ['product_name', ''], //商品名称
            ['yeji_staff', ''], //手艺人
            ['data', '', '', 'time'], //核销时间
        ]);
        $data=$services->getAllWriteOffRecords($where,$this->service->limit);
        return $this->success($this->service->writeoff($data, 1));
    }
    /**
     * 获取门店
     * @return mixed
     */
    public function storeMerchant(SystemStoreServices $services)
    {
        $where = $this->request->getMore([
            [['keywords', 's'], ''],
            [['type', 'd'], 0],
        ]);
        $data = $services->getStoreList($where, ['*']);
        return $this->success($this->service->storeMerchant($data));
    }

    /**
     * 会员卡导出
     * @param int $id
     * @param MemberCardServices $services
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function memberCard($id, MemberCardServices $services)
    {
        $data = $services->getExportData(['batch_card_id' => $id], $this->service->limit);
        return $this->success($this->service->memberCard($data));
    }

    /**
     * 导出批量任务发货的记录
     * @param int $id
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function batchOrderDelivery($id, $queueType, $cacheType)
    {
        /** @var QueueAuxiliaryServices $auxiliaryService */
        $auxiliaryService = app()->make(QueueAuxiliaryServices::class);
        /** @var QueueServices $queueService */
        $queueService = app()->make(QueueServices::class);
        $queueInfo = $queueService->getQueueOne(['id' => $id]);
        if (!$queueInfo) $this->fail("数据不存在");
        $queueValue = json_decode($queueInfo['queue_in_value'], true);
        if (!$queueValue || !isset($queueValue['cacheType'])) $this->fail("数据参数缺失");
        $data = $auxiliaryService->getExportData(['binding_id' => $id, 'type' => $cacheType], $this->service->limit);
        return $this->success($this->service->batchOrderDelivery($data, $queueType));
    }

    /**
     * 物流公司表导出
     * @return mixed
     */
    public function expressList()
    {
        /** @var ExpressServices $expressService */
        $expressService = app()->make(ExpressServices::class);
        $data = $expressService->apiExpressList();
        return $this->success($this->service->expressList($data));
    }


    /**
     * 导出积分兑换订单
     * @param StoreOrderServices $services
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function storeIntegralOrder(StoreOrderServices $services)
    {
        $where_tmp = $this->request->getMore([
            ['status', ''],
            ['real_name', ''],
            ['is_del', ''],
            ['data', '', '', 'time'],
            ['type', ''],
            ['export_type', ''],
            ['pay_type', ''],
            ['plat_type', -1],
            ['order', ''],
            ['field_key', ''],
            ['store_id', ''],
            ['supplier_id', ''],
            ['ids', '']
        ]);
        $type = $where_tmp['export_type'];
        unset($where_tmp['export_type']);
        $where = $with = [];
        if ($where_tmp['ids']) {
            $where['id'] = explode(',', $where_tmp['ids']);
        }
        $where['status'] = $where_tmp['status'];
        if ($type) {
            $where['status'] = 1;
            $where['paid'] = 1;
            $where['is_del'] = 0;
            $where['shipping_type'] = 1;
            $where['pid'] = 0;
            $with = ['pink', 'refund' => function ($query) {
                $query->whereIn('refund_type', [0, 1, 2, 4, 5])->where('is_cancel', 0)->where('is_del', 0)->field('id,store_order_id');
            }];
        }
        if (!$where_tmp['ids'] && !$type) {
            unset($where_tmp['ids']);
            $where = $where_tmp;
        }
        //积分订单
        $where['type'] = 4;
        $where['is_system_del'] = 0;
        $where['plat_type'] = $where_tmp['plat_type'];
        $where['store_id'] = $where_tmp['store_id'];
        $where['supplier_id'] = $where_tmp['supplier_id'];
        if ($where['store_id'] || $where['supplier_id'] || $where['plat_type'] != '' && in_array($where['plat_type'], [0, 1, 2])) {
            $where['pid'] = 0;
        } elseif (!in_array($where['status'], [-1, -2, -3])) {
            $where['pid'] = [0, -1];
        }
        $data = $services->getExportList($where, $with, $this->service->limit);
        return $this->success($this->service->storeOrder($data, $type));
    }

    /**
     * 门店账单下载
     * @param StoreFinanceFlowServices $services
     * @return mixed
     */
    public function financeRecord(StoreFinanceFlowServices $services)
    {
        $where = $this->request->getMore([
            ['timeType', 'day'],
            ['day', ''],
            ['store_id', 0]
        ]);
        $where['trade_type'] = 1;
        $where['no_type'] = [1, 15];
        $where['is_del'] = 0;
        $data = $services->getList($where);
        return $this->success($this->service->financeRecord($data['list'] ?? []));
    }

    /**
     * 门店流水导出
     * @param StoreFinanceFlowServices $services
     * @return mixed
     */
    public function flowExport(StoreFinanceFlowServices $services)
    {
        $where = $this->request->getMore([
            ['store_id', 0],
            ['keyword', ''],
            ['data', '', '', 'time'],
        ]);
        $where['is_del'] = 0;
        $where['trade_type'] = 1;
        $where['no_type'] = 2;
        $data = $services->getList($where);
        return $this->success($this->service->financeRecord($data['list'] ?? [], '门店流水导出'));
    }

    /**
     * 供应商资金流水导出
     * @param SupplierFlowingWaterServices $services
     * @return mixed
     */
    public function waterExport(SupplierFlowingWaterServices $services)
    {
        $where = $this->request->getMore([
            ['supplier_id', 0],
            ['keyword', ''],
            ['data', '', '', 'time'],
        ]);
        $where['is_del'] = 0;
        $data = $services->getList($where);
        return $this->success($this->service->supplierFinanceRecord($data['list'] ?? [], '供应商资金流水导出'));
    }

    /**
     * 供应商账单下载
     * @param SupplierFlowingWaterServices $services
     * @return mixed
     */
    public function waterRecord(SupplierFlowingWaterServices $services)
    {
        [$ids] = $this->request->getMore([
            ['ids', ''],
            ['supplier_id', 0]
        ], true);
        $where['id'] = $ids ? explode(',', $ids) : [];
        $where['is_del'] = 0;
        $data = $services->getList($where);
        return $this->success($this->service->supplierFinanceRecord($data['list'] ?? []));
    }

    /**
     * 发票导出
     * @param StoreOrderInvoiceServices $services
     * @return mixed
     */
    public function invoiceExport(StoreOrderInvoiceServices $services)
    {
        $where = $this->request->getMore([
            ['status', 0],
            ['real_name', ''],
            ['header_type', ''],
            ['type', ''],
            ['data', '', '', 'time'],
            ['field_key', ''],
        ]);
        $data = $services->getList($where);
        return $this->success($this->service->invoiceRecord($data['list'] ?? []));
    }

    /**
     * 系统表单收集数据导出
     * @param SystemFormDataServices $systemFormDataServices
     * @param $id
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException'
     */
    public function systemFormDataExport(SystemFormDataServices $systemFormDataServices, $id)
    {
        $where = $this->request->postMore([
            ['data', '', '', 'time'],
            ['store_name', ''],//商品信息搜索
            ['user_name', ''],//用户信息筛选
        ]);
        $data = $systemFormDataServices->getFormDataList((int)$id, $where);
        return $this->success($this->service->systemFormData($data['list'] ?? []));
    }

    /**
     * 店员列表导出
     * @param SystemStoreStaffServices $services
     * @return mixed
     */
    public function staffListExport(SystemStoreStaffServices $staffServices)
    {
        $where = $this->request->getMore([
            ['store_id', 0],
            ['keyword', ''],
        ]);
        $where['status'] = 1;
        $where['is_del'] = 0;
        $data = $staffServices->getStoreStaffListData($where, ['store']);
        return $this->success($this->service->staffList($data['list'] ?? [], '店员列表导出'));
    }

    /**
     * 抽奖记录导出
     * @param LuckLotteryRecordServices $services
     * @return mixed
     */
    public function luckLotteryRecordExport(LuckLotteryRecordServices $services)
    {
        $where = $this->request->postMore([
            ['keyword', ''],
            ['factor', ''],
            ['type', ''],
            ['is_receive', ''],
            ['lottery_id', 0],
            ['is_deliver', ''],
            ['data', '', '', 'time'],
        ]);
        $data = $services->getList($where);
        return $this->success($this->service->luckLotteryRecordList($data['list'] ?? [], '抽奖记录导出'));
    }

    /**
     * 入库单导出
     * @param StoreProductStockOrderServices $services
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function productStockInOrder(StoreProductStockOrderServices $services)
    {
        $where = $this->request->getMore([
            ['order_type', ''],//入库类型
            ['keyword', ''],//关键字搜索
            ['stock_time', ''],//入库日期
            ['add_time', '', '', 'time'],//创建时间
            ['ids', ''],//选择ID
            ['all', ''],//是否全选
            ['scope', 'hq'],
            ['store_id', ''],
        ]);
        if (!$where['ids'] && $where['all'] == 0) return $this->fail('请选择要导出入库单');
        $where['ids'] = stringToIntArray($where['ids']);
        $where['stock_type'] = 1;
        $scope = app()->make(InventoryScopeServices::class)->resolveFromRequest($where);
        unset($where['scope'], $where['store_id']);
        $data = $services->getStockOrderList(
            $where,
            (int)$scope['type'],
            $scope['relation_id'],
            [],
            (bool)$scope['all_stores']
        );
        return $this->success($this->service->productStockOrder(1, $data['list'] ?? []));
    }

    /**
     * 出库单导出
     * @param StoreProductStockOrderServices $services
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function productStockOutOrder(StoreProductStockOrderServices $services)
    {
        $where = $this->request->getMore([
            ['order_type', ''],//出库类型
            ['keyword', ''],//关键字搜索
            ['stock_time', ''],//出库日期
            ['add_time', '', '', 'time'],//创建时间
            ['ids', ''],//选择ID
            ['all', ''],//是否全选
            ['scope', 'hq'],
            ['store_id', ''],
        ]);
        if (!$where['ids'] && $where['all'] == 0) return $this->fail('请选择要导出出库单');
        $where['ids'] = stringToIntArray($where['ids']);
        $where['stock_type'] = 2;
        $scope = app()->make(InventoryScopeServices::class)->resolveFromRequest($where);
        unset($where['scope'], $where['store_id']);
        $data = $services->getStockOrderList(
            $where,
            (int)$scope['type'],
            $scope['relation_id'],
            [],
            (bool)$scope['all_stores']
        );
        return $this->success($this->service->productStockOrder(2, $data['list'] ?? []));
    }

    /**
     * 库存盘点导出
     * @param StoreProductStockCountServices $services
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function productStockCount(StoreProductStockCountServices $services)
    {
        $where = $this->request->getMore([
            ['keyword', ''],//关键字搜索
            ['status', ''],//0
            ['add_time', '', '', 'time'],//创建时间
            ['ids', ''],//选择ID
            ['all', ''],//是否全选
            ['scope', 'hq'],
            ['store_id', ''],
        ]);
        if (!$where['ids'] && $where['all'] == 0) return $this->fail('请选择要导出库存盘点');
        $where['ids'] = stringToIntArray($where['ids']);
        $scope = app()->make(InventoryScopeServices::class)->resolveFromRequest($where);
        unset($where['scope'], $where['store_id']);
        $data = $services->getStockCountList(
            $where,
            (int)$scope['type'],
            $scope['relation_id'],
            (bool)$scope['all_stores']
        );
        return $this->success($this->service->productStockCount($data['list'] ?? []));
    }

    /**
     * 库存明细导出
     * @param StoreProductStockOrderServices $services
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function productStockDetail(StoreProductStockOrderServices $services)
    {
        $where = $this->request->getMore([
            ['order_type', ''],//入库类型
            ['keyword', ''],//单据编号关键字搜索
            ['stock_time', ''],//业务时间
            ['add_time', '', '', 'time'],//创建时间
            ['unique', ''],//商品sku
            ['scope', 'hq'],
            ['store_id', ''],
        ]);
        $scope = app()->make(InventoryScopeServices::class)->resolveFromRequest($where);
        unset($where['scope'], $where['store_id']);
        $data = $services->getStockOrderList(
            $where,
            (int)$scope['type'],
            $scope['relation_id'],
            [],
            (bool)$scope['all_stores']
        );
        return $this->success($this->service->productStockDetail($data['list'] ?? []));
    }

    /**
     * 出入库统计导出
     * @param StoreProductStockDetailServices $services
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function productStockOrderStatistics(StoreProductStockDetailServices $services)
    {
        $where = $this->request->getMore([
            ['keyword', ''],
            ['stock_type', ''],//1入库2出库
            ['stock_time', '', '', 'add_time'],//时间
            ['scope', 'hq'],
            ['store_id', ''],
        ]);
        $stockType = (int)$where['stock_type'];
        unset($where['stock_type']);
        $scope = app()->make(InventoryScopeServices::class)->resolveFromRequest($where);
        unset($where['scope'], $where['store_id']);
        $data = $services->getStockStatisticsList(
            $stockType,
            $where,
            (int)$scope['type'],
            $scope['relation_id'],
            (bool)$scope['all_stores']
        );
        return $this->success($this->service->productStockOrderStatistics($stockType, $data['list'] ?? []));
    }

    /**
     * 配送数据统计导出
     * @param StoreOrderServices $services
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function statistics(StoreOrderServices $services, BranchOrderServices $orderServices)
    {
        $where = $this->request->getMore([
            ['delivery_uid', 0],
            ['real_name', ''],
            ['status', ''],// 0:待付款1:待配送 2:配送中 3:待评价 4:已完成 -2:已退款
            ['data', '', '', 'time'],
        ]);
        $where['store_id'] = 0;
        $where['time'] = $orderServices->timeHandle($where['time']);
        $data = $services->getDeliveryStatistics($where);
        return app('json')->success($this->service->deliveryStatistics($data['list'] ?? []));
    }
}
