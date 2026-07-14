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

namespace app\services\other\Import;


use app\controller\api\v1\store\Store;
use app\dao\order\StoreOrderDao;
use app\dao\other\Import\ImportRecordDao;
use app\dao\product\product\StoreCardRelatedDao;
use app\dao\product\product\StoreProductDao;
use app\dao\store\SystemStoreDao;
use app\jobs\order\OrderPayHandelJob;
use app\jobs\system\SocketPushJob;
use app\jobs\user\UserImportJob;
use app\jobs\user\UserSpreadJob;
use app\model\order\StoreOrderCartInfo;
use app\model\product\product\StoreCardRelated;
use app\model\product\product\StoreProduct;
use app\model\product\sku\StoreProductAttrValue;
use app\model\store\StoreUser;
use app\model\store\SystemStore;
use app\model\user\User;
use app\model\yeji\CashSource;
use app\model\yeji\CashType;
use app\Request;
use app\services\BaseServices;
use app\services\kefu\UserServices;
use app\services\order\cashier\CashierOrderServices;
use app\services\order\StoreCartServices;
use app\services\order\StoreOrderCartInfoServices;
use app\services\order\StoreOrderCreateServices;
use app\services\other\CityAreaServices;
use app\services\pay\PayServices;
use app\services\product\product\StoreProductServices;
use app\services\user\group\UserGroupServices;
use app\services\user\label\UserLabelRelationServices;
use app\services\user\label\UserLabelServices;
use app\services\user\level\SystemUserLevelServices;
use app\services\wechat\WechatUserServices;
use \think\facade\Db;
use think\db\exception\DataNotFoundException;
use think\db\exception\DbException;
use think\db\exception\ModelNotFoundException;
use think\exception\ValidateException;

/**
 * 导入
 * Class ImportRecordServices
 * @package app\services\other\import
 * @mixin ImportRecordDao
 */
class ImportRecordServices extends BaseServices
{

    const MAX_IMPORT_NUM = 10000000;
    const MAX_SINGLE_NUM = 50;

    public function __construct(ImportRecordDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 列表
     * @param $where
     * @return array
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @throws \ReflectionException
     */
    public function getImportList(array $where)
    {
        [$page, $limit] = $this->getPageValue();
        $list = $this->dao->getList($where, $page, $limit);
        $count = $this->dao->count($where);
        foreach ($list as &$item) {
            $item['add_time'] = date('Y-m-d H:i:s', $item['add_time']);
        }
        return compact('count', 'list');
    }

    /**
     * 删除
     * @param int $id
     * @return true
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public function deleteImport(int $id)
    {
        $info = $this->dao->get($id);
        if (!$info) {
            throw new ValidateException('删除失败');
        }
        $info->is_del = 1;
        $info->save();
        app()->make(ImportRecordErrorServices::class)->delete(['record_id' => $id]);
        return true;
    }

    /**
     * 批量
     * @param $data
     */
    public function batchUserImport($data, $dat, $import_type = 'user', $type = 0, $relation_id = 0)
    {
        $name = $import_type == 'user' ? '导入用户数据' : '导入商品数据';
        if($import_type == 'user'){
             $name='导入用户数据';
        }
        if($import_type == 'goods'){
            $name='导入商品数据';
        }
        if($import_type == 'user_card'){
            $name='导入用户卡项';
        }
        if($import_type == 'user_recharge'){
            $name='导入用户充值';
        }
        $real_name = $dat['real_name'];
        $res = $this->dao->save([
            'name' => $real_name ? substr($real_name, 0, strpos($real_name, '.')) : $name,
            'import_type' => $import_type,
            'type' => $type,
            'relation_id' => $relation_id,
            'total_count' => count($data),
            'admin_id' => $dat['admin_id'],
            'admin_name' => $dat['admin_name'],
            'add_time' => time()
        ]);
        $id = $res->id;
        $errorCount = 0;
        $jump = 0;
        /** @var StoreProductServices $productServices */
        $productServices = app()->make(StoreProductServices::class);
        if (count($data) > self::MAX_SINGLE_NUM) {

            $chunkedArray = array_chunk($data, self::MAX_SINGLE_NUM);
            foreach ($chunkedArray as $key => $chunk) {
                $end = !array_key_last($chunkedArray) == $key;
                if ($import_type == 'user') {
                    UserImportJob::dispatch([$id, $chunk, $end]);
                }else if($import_type == 'user_card'){
                    //导入用户剩余卡项
                    UserImportJob::dispatchDo('userCardSync',[$id, $chunk, $end]);
                } else if($import_type == 'user_recharge'){
                    //导入用户剩余卡项
                    UserImportJob::dispatchDo('userRechargeSync',[$id, $chunk, $end]);
                }else {
                    UserImportJob::dispatchDo('productImportSync', [$id, $chunk, $end, $type, $relation_id]);
                }
            }
        } else {
            if ($import_type == 'user') {
                $errorCount = $this->userSingleImport($data, $id, true);
            }else if($import_type == 'user_card'){
                 //导入用户剩余卡项
                $errorCount = $this->userCardSingleImport($data, $id, true);
            } else if($import_type == 'user_recharge'){
                //导入用户剩余卡项
                $this->userRechargeSingleImport($data, $id, true);
            } else {
                $res = $productServices->productImport($data, $id, true, $type, $relation_id);
                $errorCount = $res['error_sum'];
                $jump = $res['jump'];
            }
        }
        return compact('errorCount', 'jump','id');
    }

    /**
     * 0:openid    1:unioId 2:uid 3:手机号 4:用户昵称 5:客户姓名 6:性别 7:生日 8:用户等级 9:经验值 10:付费会员有效期
     * 11:客户积分 12:客户余额 13:客户标签 14:用户分组 15:用户来源 16:省 17:市 18:区 19:地址 20:所属门店 21本金 22赠金
     * 单次
     */
    public function userSingleImport($data, $id, $end = false)
    {
        $userLabel = app()->make(UserLabelServices::class);

        $labelCateId = $userLabel->value(['type' => 0, 'label_cate' => 0, 'label_name' => '外部导入'], 'id');
        if (!$labelCateId) {
            $labelCateId = $userLabel->save(0, ['label_name' => '外部导入', 'type' => 0, 'label_cate' => 0]);
        }

        // 数据处理
        list($errorData, $handleData, $labelData, $groupData) = $this->checkUser($data);

        // 唯一化并过滤标签和分组数据
        $_labelValues = array_unique(array_filter($labelData));
        $_groupValues = array_unique(array_filter($groupData));

        $res = $this->transaction(function () use ($labelCateId, $_labelValues, $_groupValues, $errorData, $id, $handleData, $end) {
            // 获取标签和分组数据
            $labelValues = $this->getOrCreateLabels($_labelValues, $labelCateId);
            $groupValues = $this->getOrCreateGroups($_groupValues);

            // 错误数据
            $saveErrorData = $this->prepareErrorData($errorData, $id);

            // 处理用户数据
            list($labelRelationData, $wechatUserData) = $this->processHandleData($handleData, $labelValues, $groupValues);
            // 批量插入其他数据
            $this->bulkSave($labelRelationData, $wechatUserData, $saveErrorData, $id);
            $error_sum = count($saveErrorData);
            //是否最后一批数据
            if ($end) {
                $this->dao->update($id, ['status' => $error_sum > 0 ? -1 : 1,'fail_count'=>$error_sum]);
            } else {
                $this->dao->update($id, ['status' => -1,'fail_count'=>$error_sum]);
            }
            return true;
        });
        if (!$res) {
            $this->dao->update($id, ['status' => -1]);
            throw new ValidateException('导入失败');
        }
        return count($errorData);
    }

    public function userCardSingleImport($handleData, $id, $end = false)
    {
        $res = $this->transaction(function () use ($id, $handleData, $end) {
            // 错误数据
            // 处理用户数据
            $saveErrorData = $this->processHandleCard($handleData,$id);
            // 批量插入其他数据
            $this->bulkSave([], [], $saveErrorData, $id);
            $error_sum = count($saveErrorData);
            //是否最后一批数据
            if ($end) {
                $this->dao->update($id, ['status' => $error_sum > 0 ? -1 : 1,'fail_count'=>$error_sum]);
            } else {
                $this->dao->update($id, ['status' => -1,'fail_count'=>$error_sum]);
            }
            return true;
        });
        if (!$res) {
            $this->dao->update($id, ['status' => -1]);
            throw new ValidateException('导入失败');
        }
        return count([]);
    }

    public function userRechargeSingleImport($handleData, $id, $end = false)
    {
        $res = $this->transaction(function () use ($id, $handleData, $end) {
            // 错误数据
            // 处理用户数据
            $saveErrorData = $this->processRecharge($handleData);
            // 批量插入其他数据
            $this->bulkSave([], [], $saveErrorData, $id);
            $error_sum = count($saveErrorData);
            //是否最后一批数据
            if ($end) {
                $this->dao->update($id, ['status' => $error_sum > 0 ? -1 : 1,'fail_count'=>$error_sum]);
            } else {
                $this->dao->update($id, ['status' => -1,'fail_count'=>$error_sum]);
            }
            return true;
        });
        if (!$res) {
            $this->dao->update($id, ['status' => -1]);
            throw new ValidateException('导入失败');
        }
        return count([]);
    }
    /**
     * 获取或创建标签
     * @param $_labelValues
     * @param $labelCateId
     * @return array
     * @throws \ReflectionException
     */

    private function getOrCreateLabels($_labelValues, $labelCateId)
    {
        $userLabel = app()->make(UserLabelServices::class);

        $labelValues = $userLabel->search([])->whereIn('label_name', $_labelValues)->column('label_name', 'id');

        $diffLabels = array_diff($_labelValues, $labelValues);

        if ($diffLabels) {
            foreach ($diffLabels as $diffLabel) {
                $_id = $userLabel->search([])->insert([
                    'label_cate' => $labelCateId,
                    'label_name' => $diffLabel,
                ], true);
                $labelValues[$_id] = $diffLabel;
            }
        }

        return array_combine(
            array_map('md5', $labelValues),
            array_keys($labelValues)
        );
    }

    /**
     * 获取或创建分组
     * @param $_groupValues
     * @return array
     * @throws \ReflectionException
     */
    private function getOrCreateGroups($_groupValues)
    {
        $userGroupServices = app()->make(UserGroupServices::class);
        $groupValues = $userGroupServices->search([])->whereIn('group_name', $_groupValues)->column('group_name', 'id');
        $diffGroups = array_diff($_groupValues, $groupValues);

        if ($diffGroups) {
            foreach ($diffGroups as $diffGroup) {
                $_id = $userGroupServices->search([])->insert(['group_name' => $diffGroup], true);
                $groupValues[$_id] = $diffGroup;
            }
        }
        return array_combine(
            array_map('md5', $groupValues),
            array_keys($groupValues)
        );
    }

    /**
     * 错误记录
     * @param $errorData
     * @param $id
     * @return array
     */
    private function prepareErrorData($errorData, $id)
    {
        $saveErrorData = [];
        foreach ($errorData as $error) {
            $fail_msg = $error['fail_msg'] ?? '';
            unset($error['fail_msg']);
            $saveErrorData[] = [
                'record_id' => $id,
                'original_data' => json_encode($error),
                'fail_msg' => $fail_msg,
            ];
        }
        return $saveErrorData;
    }

    public function processRecharge($handleData){
        $errorData=[];
        $time=time();
        foreach ($handleData as $handle) {
            //添加用户
            $save = [];
            $save['group'] = 0;
            $save['account'] = '';
            $save['phone'] = '';
            $save['nickname'] = $handle['nickname'];
            $save['real_name'] = $handle['nickname'];
            $save['user_type'] = 'h5';
            $save['login_type'] = 'h5';
            $save['pwd'] = md5('123456');
            $save['avatar'] = sys_config('h5_avatar');
            $random_time = date('H:i:s', mt_rand(0, strtotime('23:59:59')));
            $save['add_time'] = strtotime("-1 day",strtotime($handle['date']." ".$random_time));
            $save['belong_store_id'] = 1;
            $userServices = app()->make(UserServices::class);
            $uid = $userServices->save($save)->uid;
            $find = StoreUser::where('uid', $uid)->find();
            if (empty($find)) {
                $storeUser = [
                    'store_id' => $save['belong_store_id'],
                    'uid' => $uid,
                    'status' => 1,
                    'add_time' => $time,
                ];
                Db::name("store_user")->insert($storeUser);
            }
            $random_time = date('H:i:s', mt_rand(0, strtotime('23:59:59')));
            $addTime=strtotime($handle['date']." ".$random_time);
            $source=CashSource::orderRand()->value("id");
            $price=0;
            $cash_choose=0;
            if(!empty($handle['cash'])){
                $price=$handle['cash'];
                $cash_choose=7;
            }
            if(!empty($handle['bank'])){
                $price=$handle['bank'];
                $cash_choose=5;
            }
            $orderInfo=[
                'uid' => $uid,
                'pid'=>-2,
                'order_id' => $this->getUniqueId(),
                'add_time'=>$addTime,
                'pay_time'=>$addTime,
                'total_price'=>$price,
                'pay_price'=>$price,
                'unique'=>generateUnique32Str(),
                'order_type'=>1,
                'send_all' => '',
                'link_id'=>0,
                'paid'=>1,
                'status'=>2,
                'pay_type'=>'cash',
                'store_id'=>1,
                'source'=>$source,
                'staff_id'=>0,
                'cash_choose'=>$cash_choose,
                'remark_info'=>''
            ];
            $orderServices = app()->make(StoreOrderCreateServices::class);
            $orderServices->save($orderInfo);
        }
        return [];
    }
    /**
     * 处理用户卡项数据add_time
     */
    public function processHandleCard($handleData,$id){
        $errorData=[];
        $time=time();
        foreach ($handleData as $handle) {
            //生成cradId
            $storeId=SystemStore::where("name",$handle['shop'])->value("id");
            if(empty($storeId)){
                $handle['fail_msg'] = $handle['shop'].'门店不存在';
                $errorData[] = $handle;
                continue;
            }
            $productId=StoreProduct::where("relation_id",$storeId)
                ->where("product_type",6)
                ->where('store_name',$handle['card'])
                ->where("is_del",0)
                ->where("is_show",1)
                ->value("id");
            if(empty($productId)){
                $handle['fail_msg'] = $handle['card'].'权益不存在';
                $errorData[] = $handle;
                continue;
            }
            $handle['phone']=trim($handle['phone']);
            $uid=User::where("account",$handle['phone'])->value("uid");
            if(empty($uid)) {
                //添加用户
                $save = [];
                $save['group'] = 0;
                $save['account'] = $handle['phone'];
                $save['phone'] = $handle['phone'];
                $save['nickname'] = $handle['nickname'];
                $save['real_name'] = $handle['nickname'];
                $save['user_type'] = 'import';
                $save['login_type'] = 'import';
                $save['pwd'] = md5('123456');
                $save['avatar'] = sys_config('h5_avatar');
                $save['add_time'] = time();
                $shopName = $handle['shop'] ?? '';
                $store = SystemStore::where("name", $shopName)->find();
                if (!empty($store)) {
                    $save['belong_store_id'] = $store['id'];
                } else {
                    $saveStore=[];
                    $saveStore['type'] = 1;
                    $saveStore['cate_id'] = 2;
                    $saveStore['cate_com'] = 2;
                    $saveStore['name'] = $shopName;
                    $storeDao = app()->make(SystemStoreDao::class);
                    $save['belong_store_id'] = $storeDao->insertGetId($saveStore);
                }
                $userServices = app()->make(UserServices::class);
                $uid = $userServices->save($save)->uid;
                $find = StoreUser::where('uid', $uid)->find();
                if (empty($find)) {
                    $storeUser = [
                        'store_id' => $save['belong_store_id'],
                        'uid' => $uid,
                        'status' => 1,
                        'add_time' => $time,
                    ];
                    Db::name("store_user")->insert($storeUser);
                }
            }
            $number=$handle['total_number'];
            $mainId=7895;
            $relate=StoreCardRelated::where("card_product_id",$mainId)->where("product_id",$productId)->find();
            if(empty($relate)){
                $ids=StoreProduct::where("pid",$mainId)->column("id");
                $ids[]=$mainId;
                foreach ($ids as $k=>$v) {
                    $info=StoreProduct::where("id",$v)->find();
                    $theId=StoreProduct::where("relation_id",$info['relation_id'])
                        ->where('store_name',$handle['card'])
                        ->where("product_type",6)
                        ->value("id");
                    if(empty($theId)){
                        continue;
                    }
                    $find=StoreCardRelated::where("card_product_id",$v)->where("product_id",$theId)->find();
                    if(!empty($find)){
                        continue;
                    }
                    $save = [];
                    $save['card_product_id'] = $v;
                    $save['product_id'] = $theId;
                    $save['product_type'] =$info['product_type'];
                    $save['product_attr_unique'] = StoreProductAttrValue::where("product_id",$theId)->value("unique");
                    $save['cost'] = $info['cost'];
                    $save['price'] = $info['price'];
                    $save['write_times'] = $number;
                    $save['status'] = 1;
                    $dao=app()->make(StoreCardRelatedDao::class);
                    $dao->save($save);
                }
            }
            $storeMainId=StoreProduct::where("pid",$mainId)->where("relation_id",$storeId)->value("id");
            $setCard=[
                'cart_type'=>0,
                'productId'=>$storeMainId,
                'cartNum'=>1,
                'uniqueId'=>StoreProductAttrValue::where("product_id",$storeMainId)->value("unique"),//attr
                'price'=>0,
                'staff_id'=>'',
                'new'=>0,
                'tourist_uid'=>'',
                'secKillId'=>0,
                'reservation_time'=>'',
                'reservation_time_id'=>0,
                'real_name'=>'',
                'phone'=>'',
                'service_staff_id'=>0,
            ];
            $cartId=$this->addCart($uid,$storeId,$setCard);
            $cashService=app()->make(CashierOrderServices::class);
            $userService = app()->make(\app\services\user\UserServices::class);
            $userInfo = $userService->getUserInfo($uid);
            $userInfo = $userInfo->toArray();
            $cartIds=[$cartId];
            $coupon=false;
            $integral=false;
            $coupon_id=0;
            $staffId=0;
            $new=0;
            $changePrice=0;
            $isPrice=0;
            $payType="cash";
            $remarks='';
            $changeCartInfo=[];
            $seckillId=0;
            $collate_code_id=0;
            $cashChoose=0;
            $selectedProduct=[$productId];
            $remarkInfo=[];
            $combinationInfo=[];
            //下单
            $computeData = $cashService->computeOrder($uid, $storeId,[$cartId], !!$integral, !!$coupon, $userInfo, (int)$coupon_id, !!$new);
            $orderInfo=$cashService->createOrder((int)$uid, $userInfo, $computeData, $storeId, (int)$staffId, $cartIds, $payType, !!$integral, !!$coupon, $remarks, $changePrice, $changeCartInfo, !!$isPrice, $coupon_id, $seckillId, $collate_code_id,0,[],2,0,'',0,$selectedProduct,$cashChoose,$remarkInfo,$combinationInfo);
            $updata = ['paid' => 1, 'pay_type' =>PayServices::CASH_PAY, 'pay_time' => time(),'type'=>11];
            $oid=$orderInfo['id'];
            $dao=app()->make(StoreOrderDao::class);
            $dao->update($oid, $updata);
            $cartServices = app()->make(StoreOrderCartInfoServices::class);
            $cartServices->setCartCartInfo($oid, (int)$uid, $storeMainId,$selectedProduct);
            //修改cardInfo
            $saveCartInfo=[];
            $saveCartInfo['write_times']=$handle['total_number'];   //总次数
            $saveCartInfo['write_surplus_times']=$handle['number'];  //剩余次数
            $saveCartInfo['write_start']=$this->getTime($handle['begin_time']);
            $saveCartInfo['write_end']=$this->getTime($handle['end_time']);
            StoreOrderCartInfo::where("oid",$oid)->update($saveCartInfo);
            //保存用户卡项
            $userCard=[];
            $userCard['uid']=$uid;
            $userCard['oid']=$oid;
            $userCard['card_name']=$handle['card'];
            $userCard['store_id']=$storeId;
            $userCard['product_id']=$storeMainId;
            $userCard['product_type']=5;
            $userCard['verify_code']=$orderInfo['verify_code'];
            $userCard['write_valid']=3;
            $userCard['write_days']=0;
            $userCard['write_start']=$saveCartInfo['write_start'];
            $userCard['write_end']=$saveCartInfo['write_end'];
            $userCard['write_times']=$saveCartInfo['write_times'];
            $userCard['write_surplus_times']=$saveCartInfo['write_surplus_times'];
             $userCard['add_time']=time();
             if($userCard['write_end'] == 0){
                 $userCard['write_valid']=1;
             }
             if($userCard['write_end'] > 0 && $userCard['write_start'] == 0){
                 $userCard['write_start']=time();
             }
             Db::name("user_card_holder")->insert($userCard);
        }
        $saveErrorData = $this->prepareErrorData($errorData, $id);
        return $saveErrorData;
    }
    public function getTime($time){
         if($time == '永久有效'){
               return 0;
         }else{
              return  strtotime($time);
         }
    }
    private function addCart($uid,$storeId,$where)
    {
        $services=app()->make(StoreCartServices::class);
        if ($where['cart_type'] == 3) {//无码商品 随机属性唯一值
            $where['uniqueId'] = substr(md5($uid . time() . uniqid(true)), 12, 8);
        }
        $new = !!$where['new'];
        if (!$where['cart_type'] && !$where['productId']) {
            return false;
        }
        //真实用户存在，虚拟用户uid为空
        if ($uid) {
            $where['tourist_uid'] = '';
            /** @var \app\services\user\UserServices $userservice */
            $userServices = app()->make(\app\services\user\UserServices::class);
            $userInfo = $userServices->getUserInfo($uid);
            if (!$userInfo) {
                return false;
            }
        }
        if (!$uid && !$where['tourist_uid']) {
            return false;
        }
        $services->setItem('store_id', $storeId)->setItem('tourist_uid', $where['tourist_uid'])->setItem('staff_id', $where['staff_id']);
        //预约相关参数
        $services->setItem('reservation_type', 2)
            ->setItem('reservation_time', $where['reservation_time'] ?? '')->setItem('reservation_time_id', $where['reservation_time_id'] ?? 0)
            ->setItem('real_name', $where['real_name'] ?? '')
            ->setItem('phone', $where['phone'] ?? '')
            ->setItem('service_staff_id', $where['service_staff_id'] ?? 0)
            ->setItem('is_check_reservation_time', 0);
        //无码商品
        $services->setItem('cart_type', $where['cart_type'])->setItem('price', $where['price']);
        $activityId = $type = 0;
        if ($where['secKillId']) {
            $type = 1;
            $activityId = $where['secKillId'];
        }
        [$cartId, $cartNum] = $services->setCart($uid, (int)$where['productId'], (int)$where['cartNum'], $where['uniqueId'], $type, $new, (int)$activityId);
        $services->reset();
        return $cartId;
    }
    /**
     * 处理用户数据
     * @param $handleData
     * @param $labelValues
     * @param $groupValues
     * @return array[]
     */
    private function processHandleData($handleData, $labelValues, $groupValues)
    {
        $userServices = app()->make(UserServices::class);

        $labelRelationData = [];
        $wechatUserData = [];
         $time=time();
        foreach ($handleData as $handle) {
            $uid = $handle['uid'];
            $openid = $handle['openid'];
            $unionid = $handle['unionid'];
            $label = $handle['label'];

            unset($handle['label'], $handle['uid'], $handle['openid'], $handle['unionid']);

            if ($uid) {
                $handle['uid'] = $uid;
            }
            $handle['group'] = $handle['group'] ? $groupValues[md5($handle['group'])] ?? 0 : 0;
            $handle['account'] = $handle['phone'] ?: 'wx' . rand(1, 9999) . time();
            $handle['pwd'] = md5('123456');
            $handle['avatar'] = sys_config('h5_avatar');
            $handle['user_type'] = $handle['login_type'];
            $handle['add_time'] = time();
            $shopName=$handle['shop'] ?? '';
            $saveStore['type']=1;
            $saveStore['cate_id']=2;
            $saveStore['cate_com']=2;
            $saveStore['name']=$shopName;
            $store=SystemStore::where("name",$shopName)->find();
            if(!empty($store)){
                $handle['belong_store_id']=$store['id'];
            }else{
                $storeDao = app()->make(SystemStoreDao::class);
                $handle['belong_store_id']=$storeDao->insertGetId($saveStore);
            }
            $user=User::where("account",$handle['account'])->find();
            if(empty($user)) {
                $uid = $userServices->save($handle)->uid;
                //插入门店用户
                if(!empty($handle['belong_store_id'])){
                    $find=StoreUser::where('uid',$uid)->find();
                    if(empty($find)) {
                        $storeUser=[
                           'store_id' => $handle['belong_store_id'],
                           'uid' => $uid,
                          'status' => 1,
                           'add_time' =>$time,
                       ];
                       Db::name("store_user")->insert($storeUser);
                    }
                }
            }else{
                $uid=$user['uid'];
                $save['now_money']=bcadd($handle['now_money'],$user['now_money']);
                $save['ben_money']=bcadd($handle['ben_money'],$user['ben_money']);
                $save['give_money']=bcadd($handle['give_money'],$user['give_money']);
                User::where("uid",$user['uid'])->update($save);
                if(!empty($handle['belong_store_id'])){
                    $find=StoreUser::where('uid',$user['uid'])->find();
                    if(empty($find)) {
                        $storeUser=[
                            'store_id' => $handle['belong_store_id'],
                            'uid' => $user['uid'],
                            'status' => 1,
                            'add_time' =>$time,
                        ];
                        Db::name("store_user")->insert($storeUser);
                    }
                }
            }
            // 处理标签关系
            if ($label) {
                foreach ($label as $l) {
                    $labelRelationData[] = [
                        'uid' => $uid,
                        'label_id' => $labelValues[md5($l)] ?? 0,
                    ];
                }
            }

            // 处理微信用户
            if (($openid || $unionid) && in_array($handle['login_type'], ['routine', 'app', 'wechat'])) {
                $wechatUserData[] = [
                    'uid' => $uid,
                    'openid' => $openid,
                    'unionid' => $unionid,
                    'nickname' => $handle['nickname'],
                    'sex' => $handle['sex'],
                    'user_type' => $handle['login_type']
                ];
            }
//            event('user.register', [$userServices->get($uid), true, 0]);
        }

        return [$labelRelationData, $wechatUserData];
    }

    /**
     * 批量保存数据
     * @param $labelRelationData
     * @param $wechatUserData
     * @param $saveErrorData
     * @param $id
     * @return void
     * @throws DbException
     * @throws \ReflectionException
     */
    private function bulkSave($labelRelationData, $wechatUserData, $saveErrorData, $id)
    {
        if (count($labelRelationData) > 0) {
            app()->make(UserLabelRelationServices::class)->saveAll($labelRelationData);
        }

        if (count($wechatUserData) > 0) {
            app()->make(WechatUserServices::class)->saveAll($wechatUserData);
        }

        if (count($saveErrorData) > 0) {
            app()->make(ImportRecordErrorServices::class)->saveAll($saveErrorData);
            $this->dao->incUpdate(['id'=>$id],'fail_count', count($saveErrorData));
        }
    }


    /**
     * 检查用户数据并处理
     * @param $data
     * @return array
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @throws \ReflectionException
     */
    public function checkUser($data)
    {
        $userServices = app()->make(UserServices::class);
        $wechatUserServices = app()->make(WechatUserServices::class);
        $cityAreaServices = app()->make(CityAreaServices::class);

        // 初始化错误数据、处理后的数据、标签数据和分组数据数组
        $errorData = [];
        $handleData = [];
        $labelData = [];
        $groupData = [];

        // 获取微信用户的openid和unionid信息
        $openIds = $wechatUserServices->search([])->whereIn('openid', array_filter(array_column($data, 'openid')))->column('uid', 'openid');
        $unionIds = $wechatUserServices->search([])->whereIn('unionid', array_filter(array_column($data, 'unionid')))->column('uid', 'unionid');
        // 获取用户的uid和手机号信息
        $userIds = $userServices->search([])->withTrashed()->whereIn('uid', array_filter(array_column($data, 'uid')))->column('uid', 'uid');
        $phoneIds = $userServices->search([])->whereIn('phone', array_filter(array_column($data, 'phone')))->column('uid', 'phone');
        //等级
        $levels = app()->make(SystemUserLevelServices::class)->getColumn(['is_del' => 0, 'is_show' => 1], 'exp_num,grade', 'id');
        // 遍历输入的数据数组
        foreach ($data as $handle) {
            // 检查是否所有关键用户信息都未提供如果未提供，则标记为错误
            if (!$handle['unionid'] && !$handle['uid'] && !$handle['phone'] && !$handle['openid']) {
                $handle['fail_msg'] = 'openid, unionid, uid, 手机号请填写至少一个用户信息';
                $errorData[] = $handle;
                continue;
            }

            if ($handle['openid'] && isset($openIds[$handle['openid']])) {
                $handle['fail_msg'] = 'openid: ' . $handle['openid'] . ' 已存在';
                $errorData[] = $handle;
                continue;
            }

            if ($handle['unionid'] && isset($unionIds[$handle['unionid']])) {
                $handle['fail_msg'] = 'unionid: ' . $handle['unionid'] . ' 已存在';
                $errorData[] = $handle;
                continue;
            }

            if ($handle['uid'] && isset($userIds[$handle['uid']])) {
                $handle['fail_msg'] = 'uid: ' . $handle['uid'] . ' 已存在';
                $errorData[] = $handle;
                continue;
            }

            if ($handle['phone']) {
                if (!preg_match('/^1[3-9]\d{9}$/', $handle['phone'])) {
                    $handle['fail_msg'] = '手机号格式错误';
                    $errorData[] = $handle;
                    continue;
                }
                if (isset($phoneIds[$handle['phone']])) {
//                    $handle['fail_msg'] = '手机号: ' . $handle['phone'] . ' 已存在';
//                    $errorData[] = $handle;
//                    continue;
                }
            }

            //真实姓名
            if ($handle['real_name'] && mb_strlen($handle['real_name'], 'UTF-8') > 25) {
                $handle['fail_msg'] = '真实姓名不能超过25个字符';
                $errorData[] = $handle;
                continue;
            }
            // 根据性别名称转换为对应的数字代码
            switch ($handle['sex']) {
                case '男':
                    $handle['sex'] = 1;
                    break;
                case '女':
                    $handle['sex'] = 2;
                    break;
                default:
                    $handle['sex'] = 0;
            }


            if ($handle['birthday']) {
//                $handle['birthday'] = $this->excelDate($handle['birthday']);
                $handle['birthday'] = strtotime($handle['birthday']);
            }
            if ($handle['overdue_time']) {
                $handle['overdue_time'] = $this->excelDate($handle['overdue_time']);
                $handle['overdue_time'] = strtotime($handle['overdue_time']);
                $handle['is_money_level'] = 1;
            }

            if ($handle['label']) {
                $handle['label'] = array_unique(array_filter(explode(',', $handle['label'])));
                $labelData = array_merge($labelData, $handle['label']);
            }

            if ($handle['group']) {
                $groupData[] = $handle['group'];
            }

            //经验
            if ($handle['level'] || $handle['exp']) {
                $level = in_array($handle['level'], array_column($levels, 'grade')) ? $handle['level'] : 0;
                $level_num = 0;
                foreach ($levels as $var) {
                    if ($handle['exp'] >= $var['exp_num']) {
                        $level_num = $var['grade'];
                    }
                }

                foreach ($levels as $key => $var) {
                    if ($level > $level_num && $var['grade'] == $level) {
                        $handle['exp'] = $var['exp_num'];
                        $handle['level'] = $key;
                        break;
                    }
                    if ($level <= $level_num && $var['grade'] == $level_num) {
                        $handle['level'] = $key;
                        break;
                    } else {
                        $handle['level'] = $handle['exp'] = 0;
                    }
                }
            }

            if ($handle['province'] && $handle['city'] && $handle['area']) {
                $handle['provincials'] = "{$handle['province']}/{$handle['city']}/{$handle['area']}";
                $province = $cityAreaServices->getCityId($handle['province'], $handle['city'], $handle['area']);
                $handle['province'] = $province['province'];
                $handle['city'] = $province['city'];
                $handle['area'] = $province['district'];
                $handle['street'] = $province['street'];
            } else {
                $handle['province'] = 0;
                $handle['city'] = 0;
                $handle['area'] = 0;
                $handle['street'] = 0;
            }

            switch ($handle['login_type']) {
                case '微信小程序':
                    $handle['login_type'] = 'routine';
                    break;
                case '公众号':
                    $handle['login_type'] = 'wechat';
                    break;
                case 'H5':
                    $handle['login_type'] = 'h5';
                    break;
                case 'PC':
                    $handle['login_type'] = 'pc';
                    break;
                case 'APP':
                    $handle['login_type'] = 'app';
                    break;
                default:
                    $handle['login_type'] = 'import';
            }
            $handleData[] = $handle;
        }
        return [$errorData, $handleData, $labelData, $groupData];
    }

    public function excelDate($date)
    {
        //Excel 1900日期系统起始日期
        $excelStartDate = strtotime('1900-01-01');
        // 因为 Excel 错误地包含了 1900-02-29，日期序列号应该减去 1
        $date = $date - 2;

        $unixTimestamp = strtotime("+$date days", $excelStartDate);
        return date('Y-m-d', $unixTimestamp);
    }
}
