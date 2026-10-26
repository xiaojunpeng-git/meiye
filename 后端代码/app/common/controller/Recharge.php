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

namespace app\common\controller;

/**
 * 退款
 * Trait Recharge
 * @package app\common\controller
 */
trait Recharge
{

    /**
     * 显示资源列表
     *
     * @return \think\Response
     */
    public function index()
    {
        $where = $this->request->getMore([
            ['data', ''],
            ['paid', ''],
            ['nickname', ''],
        ]);
//        $where['store_id'] = 0;
        return $this->success($this->services->getRechargeList($where));
    }

    /**
     * 删除指定资源
     *
     * @param int $id
     * @return \think\Response
     */
    public function delete($id)
    {
        if (!$id) return $this->fail('缺少参数');
        return $this->success($this->services->delRecharge((int)$id) ? '删除成功' : '删除失败');
    }

    /**
     * 获取用户储值数据
     * @return array
     */
    public function user_recharge()
    {
        $where = $this->request->getMore([
            ['data', ''],
            ['paid', ''],
            ['nickname', ''],
        ]);
        return $this->success($this->services->user_recharge($where));
    }

    /**
     * 退款表单
     * @param $id
     * @return mixed
     */
    public function refund_edit($id)
    {
        if (!$id) return $this->fail('数据不存在');
        return $this->success($this->services->refund_edit((int)$id));
    }

    /**
     * 退款操作
     * @param $id
     */
    public function refund_update($id)
    {
        $data = $this->request->postMore([
            ['price', 0],
            ['give_price', 0],
            ['refund_business_date', ''],
            ['request_token', ''],
            ['is_split_order', 0],
            ['cart_ids', []],
            ['merge_refund_id', 0],
            ['refund_num', ''],
            ['cart_num', ''],
        ]);
        if (!$id) {
            return $this->fail('数据不存在');
        }
        $operatorType = 'admin';
        $operatorId = (int)$this->request->adminId();
        $storeScope = 0;
        $sourceType = \app\model\order\StoreOrderTerminalOperation::SOURCE_ADMIN;
        if (!empty($this->request->storeStaffId)) {
            $operatorType = 'store';
            $operatorId = (int)$this->request->storeStaffId;
            $storeScope = (int)($this->request->storeId ?? 0);
            $sourceType = \app\model\order\StoreOrderTerminalOperation::SOURCE_STORE;
        }
        try {
            /** @var \app\services\order\StoreOrderRefundDomainServices $domain */
            $domain = app()->make(\app\services\order\StoreOrderRefundDomainServices::class);
            $result = $domain->refundRecharge((int)$id, array_merge($data, [
                'operator_type' => $operatorType,
                'operator_id' => $operatorId,
                'store_scope' => $storeScope,
                'source_type' => $sourceType,
            ]));
            return $this->success($result['message'] ?? '退款成功', $result);
        } catch (\think\exception\ValidateException $e) {
            return $this->fail($e->getMessage());
        } catch (\Throwable $e) {
            return $this->fail('操作未成功，订单状态未改变，请核对后重试或联系负责人。');
        }
    }

}
