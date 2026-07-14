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
namespace app\controller\supplier\finance;


use app\services\supplier\finance\SupplierFlowingWaterServices;
use think\facade\App;
use think\facade\Config;
use app\controller\supplier\AuthController;
use app\services\supplier\finance\SupplierExtractServices;


/**
 * 供应商提现
 * Class SupplierExtract
 * @package app\controller\supplier\finance
 */
class SupplierExtract extends AuthController
{
    /**
     * StoreExtract constructor.
     * @param App $app
     * @param SupplierExtractServices $services
     */
    public function __construct(App $app, SupplierExtractServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }


    /**
     * 显示资源列表
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function index()
    {
        $where = $this->request->getMore([
            ['status', ''],
            ['pay_status', ''],
            ['extract_type', ''],
            ['nireid', '', '', 'like'],
            ['data', '', '', 'time'],
        ]);
        if (isset($where['extract_type']) && $where['extract_type'] == 'wx') {
            $where['extract_type'] = 'weixin';
        }
        $where['supplier_id'] = $this->supplierId;
        $whereData = [
            'supplier_id' => $this->supplierId,
            'is_del' => 0,
        ];
        return app('json')->success($this->services->index($where,$whereData));
    }

    /**
     * 增加备注
     * @param $id
     * @return mixed
     */
    public function mark($id)
    {
        [$mark] = $this->request->getMore([
            ['mark', '']
        ], true);
        if (!$id || !$mark) {
            return app('json')->fail('缺少参数');
        }
        $extract = $this->services->get((int)$id);
        if (!$extract) {
            return app('json')->fail('转账记录不存在');
        }
        if (!$this->services->update($id, ['supplier_mark' => $mark])) {
            return app('json')->fail('备注失败');
        }
        return app('json')->success('备注成功');
    }

    /**
     * 提现申请
     * @param Request $request
     * @return mixed
     */
    public function cash()
    {
        $extractInfo = $this->request->postMore([
            ['extract_type', ''],
            ['money', 0],
            ['mark', '']
        ]);
        $extractType = Config::get('pay.extractType', []);
        //最低提现
        $supplier_extract_min_price = sys_config('supplier_extract_min_price') ?? 0;
        //最高提现
        $supplier_extract_max_price = sys_config('supplier_extract_max_price') ?? 0;
        if ($extractInfo['money'] < $supplier_extract_min_price)
            return app('json')->fail('最低提现' . $supplier_extract_min_price . '元');
        if ($extractInfo['money'] > $supplier_extract_max_price)
            return app('json')->fail('最高提现' . $supplier_extract_max_price . '元');
        //可提现金额
        /** @var SupplierFlowingWaterServices $financeFlowServices */
        $financeFlowServices = app()->make(SupplierFlowingWaterServices::class);
        $whereData = [
            'supplier_id' => $this->supplierId,
            'is_del' => 0,
            'is_take' => 1,
        ];
        $price_not = $financeFlowServices->getSumFinance(['supplier_id' => $this->supplierId], $whereData);
        if ($extractInfo['money'] > $price_not) {
			return app('json')->fail($price_not > 0 ? '可提现金额为' . $price_not . '元': '暂无可提现金额');
        }
        if (!in_array($extractInfo['extract_type'], $extractType))
            return app('json')->fail('转账方式不存在');
        if (!preg_match('/^[0-9]+(.[0-9]{1,2})?$/', (float)$extractInfo['money'])) return app('json')->fail('转账金额输入有误');
        if ($this->services->cash((int)$this->supplierId, $extractInfo))
            return app('json')->successful('申请转账成功!');
        else
            return app('json')->fail('转账失败');
    }
}
