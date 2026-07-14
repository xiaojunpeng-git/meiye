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

namespace app\services\supplier\finance;


use app\dao\supplier\finance\SupplierTransactionsDao;
use app\dao\store\StoreUserDao;
use app\services\BaseServices;


/**
 * 门店流水
 * Class StoreExtractServices
 * @package app\services\store\finance
 * @mixin SupplierTransactionsDao
 */
class SupplierTransactionsServices extends BaseServices
{
    /**
     * 支付类型
     * @var string[]
     */
    public $pay_type = ['weixin' => '微信支付', 'yue' => '余额支付', 'offline' => '线下支付', 'alipay' => '支付宝支付', 'cash' => '现金支付', 'automatic' => '自动转账', 'store' => '微信支付'];

    /**
     * 交易类型
     * @var string[]
     */
    public $type = [
        1 => '支付订单',
        2 => '支付订单',
        3 => '订单手续费',
        4 => '退款订单',
        5 => '储值返点',
        6 => '付费会员返点',
        7 => '储值订单',
        8 => '付费订单',
        9 => '收银订单',
        10 => '核销订单',
        11 => '分配订单',
        12 => '配送订单',
        13 => '同城配送订单',
    ];

    /**
     * 构造方法
     * StoreUser constructor.
     * @param StoreUserDao $dao
     */
    public function __construct(SupplierTransactionsDao $dao)
    {
        $this->dao = $dao;
    }
    /**
     * 写入数据
     * @param $order
     * @param $number
     * @param $pm
     * @param $type
     * @param $trade_type
     * @param array $append
     * @throws \Exception
     */
    public function savaData($order, $pm, $type, array $append = [])
    {
        $order_id = $this->getUniqueId('ls');
        $data = [
            'supplier_id' => $order['supplier_id'] ?? 0,
            'uid' => $order['uid'] ?? 0,
            'order_id' => $order_id,
            'link_id' => $order['order_id'] ?? '',
            'pay_type' => $order['pay_type'] ?? '',
            'trade_time' => $order['pay_time'] ?? $order['add_time'] ?? '',
            'pm' => $pm,
            'type' => $type,
            'add_time' => time()
        ];
        $data = array_merge($data, $append);
        $this->dao->save($data);
    }
}
