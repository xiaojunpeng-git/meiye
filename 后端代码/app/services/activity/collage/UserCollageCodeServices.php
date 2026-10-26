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

namespace app\services\activity\collage;

use app\jobs\notice\PrintJob;
use app\services\activity\table\TableQrcodeServices;
use app\services\BaseServices;
use app\dao\activity\collage\UserCollageCodeDao;
use app\services\message\SystemPrinterServices;
use app\services\order\StoreOrderCartInfoServices;
use think\exception\ValidateException;
use app\services\other\CategoryServices;
use mohe\services\SystemConfigService;
use mohe\utils\Arr;
use think\facade\Log;

/**
 *
 * Class UserCollageCodeServices
 * @package app\services\activity\collage
 * @mixin UserCollageCodeDao
 */
class UserCollageCodeServices extends BaseServices
{

    /**
     * UserCollageCodeServices constructor.
     * @param UserCollageCodeDao $dao
     */
    public function __construct(UserCollageCodeDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 验证拼单是否开启
     * @return bool
     */
    public function checkCollageStatus()
    {
        //门店是否开启
        if (!sys_config('store_func_status', 1)) {
            return false;
        }
        //桌码是否开启
        if (!sys_config('store_splicing_switch', 1)) {
            return false;
        }
        return true;
    }

    /**
     * 验证桌码功能是否开启
     * @return bool
     */
    public function checkTabldeCodeStatus(int $store_id)
    {
        //门店是否开启
        if (!sys_config('store_func_status', 1)) {
            return false;
        }
        //桌码是否开启
        if (!store_config($store_id, 'store_code_switch', 1)) {
            return false;
        }
        return true;
    }

    /**
     * 拼单、桌码状态
     * @param $id
     * @return array|\think\Model|null
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function collageStatus($id)
    {
        return $this->dao->get($id, ['status']);
    }

    /**
     * 记录发起拼单
     * @param int $uid
     * @param int $store_id
     * @param int $address_id
     * @param int $shipping_type
     * @return \mohe\basic\BaseModel|\think\Model
     */
    public function setUserCollage(int $uid, int $store_id, int $address_id, int $shipping_type)
    {
        if ($this->dao->be(['uid' => $uid, 'type' => 9, 'store_id' => $store_id, 'address_id' => $address_id, 'shipping_type' => $shipping_type, 'status' => [0, 1]])) throw new ValidateException('您已在拼单中，不能再次拼单！');
        $data = [
            'uid' => $uid,
            'type' => 9,
            'store_id' => $store_id,
            'address_id' => $address_id,
            'shipping_type' => $shipping_type,
            'add_time' => time()
        ];
        $res = $this->dao->save($data);
        return $res;
    }

    /**
     * 获取拼单/桌码信息
     * @param array $where
     * @param string|null $field
     * @param array $with
     * @return array|\think\Model|null
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getUserCollage(array $where, ?string $field = '*', array $with = [])
    {
        return $this->dao->getOne($where, $field, $with);
    }

    /**
     * 修改拼单
     * @param int $id
     * @param array $where
     * @return mixed
     */
    public function userUpdate(int $id, array $where)
    {
        return $this->dao->update($id, $where);
    }

    /**
     * 记录桌码
     * @param int $uid
     * @param int $store_id
     * @param int $qrcode_id
     * @param int $number
     * @return bool|\mohe\basic\BaseModel|\think\Model
     */
    public function setUserTableCode(int $uid, int $store_id, int $qrcode_id, int $number)
    {
        //1=>合并结账 2=>单独结账
        $store_checkout_method = store_config($store_id, 'store_checkout_method', 1);
        if ($store_checkout_method == 1) {
            $where = ['store_id' => $store_id, 'qrcode_id' => $qrcode_id, 'checkout_method' => $store_checkout_method, 'type' => 10, 'status' => [0, 1]];
        } else {
            $where = ['uid' => $uid, 'store_id' => $store_id, 'qrcode_id' => $qrcode_id, 'checkout_method' => $store_checkout_method, 'type' => 10, 'status' => [0, 1]];
        }
        $table = $this->dao->getOne($where);
        if ($table) return $table;
        $max = $this->dao->getMaxSerialNumber(['store_id' => $store_id, 'type' => 10]);
        $data = [
            'uid' => $uid,
            'type' => 10,
            'store_id' => $store_id,
            'checkout_method' => $store_checkout_method,
            'qrcode_id' => $qrcode_id,
            'number_diners' => $number,
            'serial_number' => $max ? '00' . ($max + 1) : '001',
            'add_time' => time()
        ];
        return $this->dao->save($data);
    }

    /**
     * 检查是否换桌 code 0 :换桌 1 ：未换桌 2：无记录
     * @param int $uid
     * @param int $store_id
     * @param int $qrcode_id
     * @param int $store_checkout_method
     * @return array|int[]
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function isUserChangingTables(int $uid, int $store_id, int $qrcode_id, int $store_checkout_method)
    {
        $where = ['store_id' => $store_id, 'uid' => $uid, 'checkout_method' => $store_checkout_method, 'type' => 10, 'status' => [0, 1]];
        $whereTo = ['store_id' => $store_id, 'qrcode_id' => $qrcode_id, 'checkout_method' => $store_checkout_method, 'type' => 10, 'status' => [0, 1]];
        $table = $this->dao->getOne($where);
        $table1 = $this->dao->getOne($whereTo);
        if ($table) {
            if ($table['qrcode_id'] != $qrcode_id) {
                return ['code' => 0, 'number' => $table['number_diners'], 'tableId' => $table['id']];
            } else {
                return ['code' => 1, 'number' => $table['number_diners'], 'tableId' => $table['id']];
            }
        } else {
            if ($store_checkout_method == 1) {
                /** @var UserCollagePartakeServices $partakeService */
                $partakeService = app()->make(UserCollagePartakeServices::class);
                $partake_where = ['uid' => $uid, 'store_id' => $store_id, 'status' => 1];
                $partake = $partakeService->getMaxTime($partake_where);
                if (!$partake) {
                    return ['code' => 2, 'number' => $table1 ? $table1['number_diners'] : 0, 'tableId' => $table1 ? $table1['id'] : 0];
                }
                $table2 = $this->dao->get($partake['collate_code_id']);
                if (!$table2) {
                    return ['code' => 2, 'number' => $table1 ? $table1['number_diners'] : 0, 'tableId' => $table1 ? $table1['id'] : 0];
                }
                if (in_array($table2['status'], [0, 1])) {
                    if ($table2['qrcode_id'] != $qrcode_id) {
                        return ['code' => 0, 'number' => $table2['number_diners'], 'tableId' => $table2['id']];
                    } else {
                        return ['code' => 1, 'number' => $table2['number_diners'], 'tableId' => $table2['id']];
                    }
                } else {
                    return ['code' => 2, 'number' => $table1 ? $table1['number_diners'] : 0, 'tableId' => $table1 ? $table1['id'] : 0];
                }
            } else {
                return ['code' => 1, 'number' => 0, 'tableId' => 0];
            }
        }
    }

    /**
     * 更换座位 处理原有商品
     * @param int $tableId
     * @param int $y_tableId
     * @return bool
     */
    public function userChangingTables(int $tableId, int $y_tableId)
    {
        /** @var UserCollagePartakeServices $partakeService */
        $partakeService = app()->make(UserCollagePartakeServices::class);
        $res = $partakeService->update(['collate_code_id' => $y_tableId], ['collate_code_id' => $tableId]);
        $table = $this->dao->get($y_tableId);
        /** @var TableQrcodeServices $qrcodeService */
        $qrcodeService = app()->make(TableQrcodeServices::class);
        $res1 = $qrcodeService->update($table['qrcode_id'], ['is_use' => 0, 'eat_number' => 0, 'order_time' => 0]);
        $res2 = $this->dao->update($tableId, ['number_diners' => $table['number_diners']]);
        $res3 = $this->dao->delete($y_tableId);
        return $res && $res1 && $res2 && $res3;
    }

    /**
     * 桌码订单
     * @param array $where
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getStoreTableCodeList(array $where, array $field = ['*'])
    {
        [$page, $limit] = $this->getPageValue();
        $data = $this->dao->searchTableCodeList($where, $field, $page, $limit, ['qrcode', 'orderId']);
        $count = $this->dao->count($where);
        /** @var UserCollagePartakeServices $partakeService */
        $partakeService = app()->make(UserCollagePartakeServices::class);
        foreach ($data as $key => &$datum) {
            $datum['qrcode']['category'] = [];
            if (isset($datum['qrcode']['cate_id']) && $datum['qrcode']['cate_id']) {
                /** @var CategoryServices $categoryService */
                $categoryService = app()->make(CategoryServices::class);
                $datum['qrcode']['category'] = $categoryService->get((int)$datum['qrcode']['cate_id'], ['name']);
            } else {
                $datum['qrcode']['category'] = ['name' => '已删除'];
                $datum['qrcode']['table_number'] = '';
            }
            $dataPartake = $partakeService->getCashierTablePartakeProduct(['collate_code_id' => $datum['id'], 'status' => 1], '', ['productInfo']);
            $datum['cartList'] = $dataPartake['cart'] ?? [];
            $datum['sum_price'] = $dataPartake['sum_price'] ?? 0;
            $datum['cart_num'] = $dataPartake['cart_num'] ?? 0;
        }
        return compact('data', 'count');
    }

    /**
     * 确认下单
     * @param int $tableId
     * @param int $store_id
     * @return bool
     */
    public function userTablePlaceOrder(int $tableId, int $store_id)
    {
        $this->dao->update($tableId, ['status' => 1]);
        /** @var UserCollagePartakeServices $partakeService */
        $partakeService = app()->make(UserCollagePartakeServices::class);
        $partakeService->update(['collate_code_id' => $tableId], ['is_settle' => 1]);

		PrintJob::dispatch('tableDoJob', [$tableId, $store_id, 1]);
        return true;
    }

    /**
     * 桌码、拼单长期未操作取消桌码、拼单记录
     * @return bool|void
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function tableCodeNotOperating(int $type = 10)
    {
        $where = ['type' => $type, 'status' => [0, 1]];
        $list = $this->dao->searchTableCodeList($where);
        //系统预设取消订单时间段
        $keyValue = ['table_code_not_operating_time', 'collate_not_operating_time'];
        //获取配置
        $systemValue = SystemConfigService::more($keyValue);
        //格式化数据
        $systemValue = Arr::setValeTime($keyValue, is_array($systemValue) ? $systemValue : []);
        $table_code_not_operating_time = $systemValue['table_code_not_operating_time'];
        $collate_not_operating_time = $systemValue['collate_not_operating_time'];
        $not_operating_time = $type == 10 ? $table_code_not_operating_time : $collate_not_operating_time;
        /** @var UserCollagePartakeServices $partakeService */
        $partakeService = app()->make(UserCollagePartakeServices::class);
        $not_operating_time = (int)bcmul((string)$not_operating_time, '3600', 0);
        foreach ($list as $key => $item) {
            if ((strtotime($item['add_time']) + $not_operating_time) < time()) {
                try {
                    $this->transaction(function () use ($item, $partakeService, $type) {
                        //修改记录状态
                        $res = $this->dao->update($item['id'], ['status' => -1]);
                        $res && $partakeService->update(['collate_code_id' => $item['id']], ['status' => 0]);
                        if($type == 10) {
                            /** @var TableQrcodeServices $qrcodeService */
                            $qrcodeService = app()->make(TableQrcodeServices::class);
                            $qrcodeService->update($item['qrcode_id'], ['is_use' => 0, 'eat_number' => 0, 'order_time' => 0]);
                        }
                    });
                } catch (\Throwable $e) {
                    $msg = $type == 10 ? '桌码长期未操作取消桌码记录失败' : '拼单长期未操作取消拼单记录失败';
                    Log::error($msg . ',失败原因:' . $e->getMessage(), $e->getTrace());
                }
            }
        }
        return true;
    }

	/**
	 * 桌码打印订单
	 * @param int $tableId
	 * @param int $store_id
	 * @param int $print_event
	 * @param int $is_print
	 * @return bool
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function tablePrint(int $tableId, int $store_id, int $print_event = -1, int $is_print = 0)
    {
        $table = $this->dao->get($tableId);
        if (!$table || $table['status'] == -1) {
            throw new ValidateException('桌码已取消');
        }
        /** @var UserCollagePartakeServices $partakeService */
        $partakeService = app()->make(UserCollagePartakeServices::class);
        if ($table['oid']) {//下单后
            /** @var StoreOrderCartInfoServices $cartServices */
            $cartServices = app()->make(StoreOrderCartInfoServices::class);
            $product = $cartServices->getCartInfoPrintProduct((int)$table['oid']);
        } else {
            $product = $partakeService->getCartInfoPrintProduct($table->toArray(), $is_print);
        }
        if (!$product) {
            return true;
        }
        $partakeService->update(['collate_code_id' => $tableId, 'is_print' => 0], ['is_print' => 1]);
        try {
            $config = [
                'name' => sys_config('site_name'),
                'tableInfo' => is_object($table) ? $table->toArray() : $table,
                'product' => $product
            ];
            app()->make(SystemPrinterServices::class)->startPrint($config, $print_event, 1,$store_id,2);
            return true;
        } catch (\Exception $e) {
            \think\facade\Log::error('小票打印失败，原因：' . $e->getMessage());
            return false;
        }
    }
}
