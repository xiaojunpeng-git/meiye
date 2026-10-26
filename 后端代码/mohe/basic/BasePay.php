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
namespace mohe\basic;

/**
 * Class BasePay
 * @package mohe\basic
 */
abstract class BasePay extends BaseStorage
{

    /**
     * 支付参数配置
     * @var array
     */
    protected $configPay = [];

    /**
     * 发起支付
     * @param array|null $configPay
     * @return mixed
     */
    abstract public function pay(?array $configPay = []);

    /**
     * 退款
     * @param array|null $configPay
     * @return mixed
     */
    abstract public function refund(?array $configPay = []);

    /**
     * 设置支付参数
     * @param array $configPay
     * @return $this
     */
    public function setConfigPay(array $configPay)
    {
        $this->configPay = $configPay;
        return $this;
    }
}
