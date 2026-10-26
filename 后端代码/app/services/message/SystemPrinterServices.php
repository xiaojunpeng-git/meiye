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

namespace app\services\message;

use app\dao\message\SystemPrinterDao;
use app\services\activity\table\TableQrcodeServices;
use app\services\BaseServices;
use app\services\pay\PayServices;
use app\services\store\SystemStoreServices;
use think\exception\ValidateException;
use mohe\services\printer\Printer;

/**
 * 打印机
 * Class SystemPrinterServices
 * @package app\services\message
 * @mixin SystemPrinterDao
 */
class SystemPrinterServices extends BaseServices
{

    public $print_content = [
                'scene' => 1,
                'header' => [0],
                'delivery' => [0,1,2,3],
                'buyer_remarks' => 1,
                'goods' =>  [0],
                'freight'=>1,
                'preferential' =>  [1],
                'pay' =>  [0,1],
                'custom' =>  0,
                'order' =>  [0,1,2,3],
                'code' =>  0,
                'code_url' =>  "",
                'show_notice' =>  0,
                'notice_content' =>  "",
            ];
    public $print_table_content = [
                'scene' => 2,
                'header' => [1,2,3,4,0],
                'delivery' => [],
                'buyer_remarks' => 0,
                'goods' =>  [0],
                'freight'=>0,
                'preferential' =>  [1],
                'pay' =>  [],
                'custom' =>  0,
                'order' =>  [3],
                'code' =>  0,
                'code_url' =>  "",
                'show_notice' =>  0,
                'notice_content' =>  "",
             ];

    /**
     * SystemPrinterServices constructor.
     * @param SystemPrinterDao $dao
     */
    public function __construct(SystemPrinterDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 获取有效的打印机
     * @param int $type
     * @param int $relation_id
     * @param string $field
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getValidPrinter(int $type = 0, int $relation_id = 0, int $print_event = 2, string $field = '*')
    {
        return $this->dao->getList(['type' => $type, 'relation_id' => $relation_id, 'status' => 1, 'print_event' => $print_event], $field);
    }

    /**
     * 获取打印机列表
     * @param array $where
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function index(array $where = [])
    {
        [$page, $limit] = $this->getPageValue();
        $list = $this->dao->getList($where, '*', $page, $limit);
        $count = $this->dao->count($where);
        if ($list) {
            foreach ($list as &$item) {
                $item['add_time'] = $item['add_time'] ? date('Y-m-d H:i:s', $item['add_time']) : '';
            }
        }
        return compact('count', 'list');
    }

    /**
     * 获取单个打印机信息
     * @param int $id
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getInfo(int $id)
    {
        $info = $this->dao->get($id);
        if (!$info) {
            throw new ValidateException('打印机信息不存在或已删除');
        }
        return $info->toArray();
    }

    /**
     * 获取小票样式配置
     * @param $id
     * @return array|mixed
     */
    public function getPrintContent($id, $scene)
    {
        switch ($scene) {
            case 1:
                $field = 'print_content';
                break;
            case 2:
                $field = 'print_table_content';
                break;
            default:
                $field = 'print_content';
        }
        $print_content = $this->dao->value(['id' => $id], $field);
        return $print_content ? json_decode($print_content, true) : [];
    }

    /**
     * 设置小票样式配置
     * @param $id
     * @param $data
     * @return bool
     */
    public function savePrintContent($id, $data, $scene)
    {
        $print_content = json_encode($data);
        switch ($scene) {
            case 1:
                $content = ['print_content' => $print_content];
                break;
            case 2:
                $content = ['print_table_content' => $print_content];
                break;
            default:
                $content = ['print_content' => $print_content];
        }
        $this->dao->update($id, $content);
        return true;
    }

    /**
     * @param $config
     * @param $print_event 1：下单后2：支付后
     * @param $type 0:平台1:门店2:供应商
     * @param $relation_id
     * @param $scene 1  普通  2 桌码
     * @return void
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function startPrint(array $config, $print_event = -1, $type = 0, $relation_id = 0, $scene = 1)
    {
        $where = [];
        if($print_event != -1) $where['print_event'] = $print_event;
        $list = $this->dao->getList($where + ['status' => 1, 'type' => $type, 'relation_id' => $relation_id]);
		$res = true;
		$err = [];
        foreach ($list as $item) {
			if (!$item['print_content']) continue;
            if ($item['plat_type'] == 1) { //易联云
                $name = 'yi_lian_yun';
                $configData = [
                    'partner' => $item['yly_user_id'],
                    'clientId' => $item['yly_app_id'],
                    'apiKey' => $item['yly_app_secret'],
                    'terminal' => $item['yly_sn'],
                    'print_num' => $item['print_num']
                ];
                switch ($scene) {
                    case 1:
                        $content = $this->ylyContent(json_decode($item['print_content'], true), $config, $item['print_num'], $print_event);
                        break;
                    case 2:
                        $content = $this->ylyTableContent(json_decode($item['print_table_content'], true), $config, $item['print_num'], $print_event);
                        break;
                    default:
                        $content = $this->ylyContent(json_decode($item['print_content'], true), $config, $item['print_num'], $print_event);
                }
            } else { //飞鹅云
                $name = 'fei_e_yun';
                $configData = [
                    'feyUser' => $item['fey_user'],
                    'feyUkey' => $item['fey_ukey'],
                    'feySn' => $item['fey_sn'],
                    'print_num' => $item['print_num']
                ];
                switch ($scene) {
                    case 1:
                        $content = $this->feyContent(json_decode($item['print_content'], true), $config, $print_event);
                        break;
                    case 2:
                        $content = $this->feyTableContent(json_decode($item['print_table_content'], true), $config, $print_event);
                        break;
                    default:
                        $content = $this->feyContent(json_decode($item['print_content'], true), $config, $print_event);
                }
            }
            $printer = new Printer($name, $configData);
            $res = $res && $printer->setPrinterContent($content)->startPrinter();
			if (!$res) {
				$err[] = $printer->getError();
			}
        }
		if ($err) {
			throw new ValidateException('小票打印失败，原因：'.json_encode($err));
		}
    }

    /**
     * 设置打印内容
     * @param $printContent
     * @param $config
     * @param $print_num
     * @param $print_event
     * @return string
     */
    public function ylyContent($printContent, $config, $print_num, $print_event)
    {
        $orderInfo = $config['orderInfo'];
        $product = $config['product'];
        $name = $config['name'];
        $goodsStr = '<table><tr><td>名称</td><td>单价</td><td>数量</td><td>金额</td></tr>';
        foreach ($product as $item) {
            $goodsStr .= '<tr><td><FH2><FW2>----------------</FW2></FH2></td></tr>';
            $goodsStr .= '<tr>';
            $price = $item['sum_price'];
            $num = $item['cart_num'];
            $prices = bcmul((string)$item['cart_num'], (string)$item['sum_price'], 2);
            $goodsStr .= "<td>{$item['productInfo']['store_name']} | {$item['productInfo']['attrInfo']['suk']}</td><td>{$price}</td><td>{$num}</td><td>{$prices}</td>";
            $goodsStr .= '</tr>';
            if (in_array(1, $printContent['goods'])) {
                $goodsStr .= '<tr>';
                $goodsStr .= "<td>规格编码：{$item['productInfo']['attrInfo']['code']}</td>";
                $goodsStr .= '</tr>';
            }
            unset($price, $num, $prices);
        }
        $goodsStr .= '</table>';
        $total_price = bcadd($orderInfo['total_price'], $orderInfo['pay_postage'], 2);
        $addTime = date('Y-m-d H:i:s', $orderInfo['add_time']);
        $payTime = isset($orderInfo['pay_time']) && $orderInfo['pay_time'] ? date('Y-m-d H:i:s', $orderInfo['pay_time']) : '';
        $printTime = date('Y-m-d H:i:s', time());

        $content = '';
        $content .= '<MN>' . $print_num . '</MN>';
        if ($printContent['header']) {
            if (in_array(0, $printContent['header'])) {
                $content .= '<FS2><center>' . $name . '</center></FS2>';
            }
            if (in_array(1, $printContent['header']) && $orderInfo['store_id']) {
                /** @var SystemStoreServices $storeServices */
                $storeServices = app()->make(SystemStoreServices::class);
                $store_name = $storeServices->value(['id' => $orderInfo['store_id']], 'name');
                $content .= '<FS2><center>' . $store_name . '</center></FS2>';
            }
            $content .= '<FH2><FW2>----------------</FW2></FH2>';
        }
        if ($printContent['delivery']) {
            if (in_array(0, $printContent['delivery'])) {
				switch ($orderInfo['shipping_type']) {
					case 1:
					case 3:
						if (isset($orderInfo['store_id']) && $orderInfo['store_id'])  $content .= "配送方式：门店配送 \r";
						else $content .= "配送方式：商家配送 \r";
						break;
					case 2:
						$content .= "配送方式：门店自提 \r";
						break;
					case 4://收银台
						$content .= "配送方式：门店自提 \r";
						break;
				}
            }
            if (in_array(1, $printContent['delivery'])) {
                $content .= '客户姓名: ' . $orderInfo['real_name'] . " \r";
            }
            if (in_array(2, $printContent['delivery'])) {
                $content .= '客户电话: ' . $orderInfo['user_phone'] . " \r";
            }
            if (in_array(3, $printContent['delivery'])) {
				$content .= '收货地址: ' . $orderInfo['user_address'] . " \r";
            }
            $content .= '<FH2><FW2>----------------</FW2></FH2>';
        }
        if ($printContent['buyer_remarks']) {
            $content .= '买家备注: ' . $orderInfo['mark'] . " \r";
            $content .= '<FH2><FW2>----------------</FW2></FH2>';
        }
        if (in_array(0, $printContent['goods'])) {
            $content .= '*************商品***************';
            $content .= "      \r";
            $content .= $goodsStr;
            $content .= "********************************\r";
            $content .= '<FH2><FW2>----------------</FW2></FH2>';
        }
        if ($printContent['freight']) {
            $content .= '<RA>邮费：' . $orderInfo['pay_postage'] . '元</RA>';
            $content .= '<RA>合计：' . $total_price . '元</RA>';
            $content .= '<FH2><FW2>----------------</FW2></FH2>';
        }
        if ($printContent['preferential']) {
            $discount_price = bcsub(bcadd($orderInfo['total_price'], $orderInfo['pay_postage'], 2), bcadd($orderInfo['deduction_price'], $orderInfo['pay_price'], 2), 2);
            $content .= '<RA>优惠：-' . $discount_price . '元</RA>';
            $content .= '<RA>抵扣：-' . $orderInfo['deduction_price'] . '元</RA>';
            $content .= '<FH2><FW2>----------------</FW2></FH2>';
        }
        if (in_array(0, $printContent['pay'])) {
				$systemPayType = PayServices::PAY_TYPE;
				$content .= '<RA>支付方式：'. ($systemPayType[$orderInfo['pay_type']] ?? '其他支付') . '</RA>';
        }
        if (in_array(1, $printContent['pay'])) {
            $content .= '<RA>实际支付：' . $orderInfo['pay_price'] . '元</RA>';
        }
        if (count($printContent['pay'])) {
            $content .= '<FH2><FW2>----------------</FW2></FH2>';
        }
        if (in_array(0, $printContent['order'])) {
            $content .= '订单编号：' . $orderInfo['order_id'] . "\r";
        }
        if (in_array(1, $printContent['order'])) {
            $content .= '下单时间：' . $addTime . "\r";
        }
        if (in_array(2, $printContent['order']) && $payTime) {
            $content .= '支付时间：' . $payTime . "\r";
        }
        if (in_array(3, $printContent['order'])) {
            $content .= '打印时间：' . $printTime . "\r";
        }
        $content .= '<FH2><FW2>----------------</FW2></FH2>';
        if ($printContent['code'] && $printContent['code_url']) {
            $content .= '<QR>' . sys_config('site_url') . $printContent['code_url'] . '</QR>';
            $content .= "      \r";
        }
        if ($printContent['show_notice']) {
            $content .= '<center>' . $printContent['notice_content'] . '</center>';
            $content .= "      \r";
        }
        return $content;
    }

    /**
     * 设置桌码打印内容
     * @param $printContent
     * @param $config
     * @param $print_num
     * @param $print_event
     * @return string
     */
    public function ylyTableContent($printContent, $config, $print_num, $print_event)
    {
        $product = $config['product'];
        $tableInfo = $config['tableInfo'];
        $name = $config['name'];
        $goodsStr = '<table><tr><td>名称</td><td>单价</td><td>数量</td><td>金额</td></tr>';
        foreach ($product as $item) {
            $goodsStr .= '<tr><td><FH2><FW2>----------------</FW2></FH2></td></tr>';
            $goodsStr .= '<tr>';
            $price = $item['sum_price'];
            $num = $item['cart_num'];
            $prices = bcmul((string)$item['cart_num'], (string)$item['sum_price'], 2);
            $goodsStr .= "<td>{$item['productInfo']['store_name']} | {$item['productInfo']['attrInfo']['suk']}</td><td>{$price}</td><td>{$num}</td><td>{$prices}</td>";
            $goodsStr .= '</tr>';
            if (in_array(1, $printContent['goods'])) {
                $goodsStr .= '<tr>';
                $goodsStr .= "<td>规格编码：{$item['productInfo']['attrInfo']['code']}</td>";
                $goodsStr .= '</tr>';
            }
            unset($price, $num, $prices);
        }
        $goodsStr .= '</table>';
        $printTime = date('Y-m-d H:i:s', time());

        $content = '';
        $content .= '<MN>' . $print_num . '</MN>';
        if ($printContent['header']) {
            if (in_array(0, $printContent['header'])) {
                $content .= '<FS2><center>' . $name . '</center></FS2>';
            }
            if (in_array(1, $printContent['header'])) {
                /** @var SystemStoreServices $storeServices */
                $storeServices = app()->make(SystemStoreServices::class);
                $store_name = $storeServices->value(['id' => $tableInfo['store_id']], 'name');
                $content .= '<FS2><center>' . $store_name . '</center></FS2>';
            }
            /** @var TableQrcodeServices $qrcodeService */
            $qrcodeService = app()->make(TableQrcodeServices::class);
            $Info = $qrcodeService->getQrcodeyInfo((int)$tableInfo['qrcode_id'], ['category']);
            if (in_array(2, $printContent['header'])) {
                $content .= '<RA>桌码流水：' . $tableInfo['serial_number'] . '</RA>';
            }
            if (in_array(3, $printContent['header'])) {
                $content .= '<RA>桌码分类：' . $Info['category']['name'] . '</RA>';
            }
            if (in_array(4, $printContent['header'])) {
                $content .= '<RA>桌码编号：' . $Info['table_number'] . '</RA>';
            }
            $content .= '<FH2><FW2>----------------</FW2></FH2>';
        }
        if (in_array(0, $printContent['goods'])) {
            $content .= '*************商品***************';
            $content .= "      \r";
            $content .= $goodsStr;
            $content .= "********************************\r";
            $content .= '<FH2><FW2>----------------</FW2></FH2>';
        }

        if (in_array(3, $printContent['order'])) {
            $content .= '打印时间：' . $printTime . "\r";
        }
        $content .= '<FH2><FW2>----------------</FW2></FH2>';
        if ($printContent['code'] && $printContent['code_url']) {
            $content .= '<QR>' . sys_config('site_url') . $printContent['code_url'] . '</QR>';
            $content .= "      \r";
        }
        if ($printContent['show_notice']) {
            $content .= '<center>' . $printContent['notice_content'] . '</center>';
            $content .= "      \r";
        }
        return $content;
    }


    /**
     *
     * @param $printContent
     * @param $orderInfo
     * @param $product
     * @param $print_event
     * @return string
     */
    public function feyContent($printContent, $config, $print_event)
    {
        $orderInfo = $config['orderInfo'];
        $product = $config['product'];
        $name = $config['name'];
        $printTime = date('Y-m-d H:i:s', time());
        $addTime = date('Y-m-d H:i:s', $orderInfo['add_time']);
        $payTime = isset($orderInfo['pay_time']) && $orderInfo['pay_time'] ? date('Y-m-d H:i:s', $orderInfo['pay_time']) : '';
        $content = '';
        if ($printContent['header']) {
            if (in_array(0, $printContent['header'])) {
                $content .= '<CB>' . $name . '</CB><BR>';
            }
            if (in_array(1, $printContent['header']) && $orderInfo['store_id']) {
                /** @var SystemStoreServices $storeServices */
                $storeServices = app()->make(SystemStoreServices::class);
                $store_name = $storeServices->value(['id' => $orderInfo['store_id']], 'name');
                $content .= '<CB>' . $store_name . '</CB><BR>';
            }
            $content .= '--------------------------------<BR>';
        }
        if ($printContent['delivery']) {
            if (in_array(0, $printContent['delivery'])) {
				switch ($orderInfo['shipping_type']) {
					case 1:
					case 3:
						if (isset($orderInfo['store_id']) && $orderInfo['store_id'])  $content .= "配送方式：门店配送 <BR>";
						else $content .= "配送方式：商家配送 <BR>";
						break;
					case 2:
						$content .= "配送方式：门店自提 <BR>";
						break;
					case 4://收银台
						$content .= "配送方式：门店自提 <BR>";
						break;
				}
            }
            if (in_array(1, $printContent['delivery'])) {
                $content .= '客户姓名: ' . $orderInfo['real_name'] . '<BR>';
            }
            if (in_array(2, $printContent['delivery'])) {
                $content .= '客户电话: ' . $orderInfo['user_phone'] . '<BR>';
            }
            if (in_array(3, $printContent['delivery'])) {
				$content .= '收货地址：' . $orderInfo['user_address'] . '<BR>';
            }
            $content .= '--------------------------------<BR>';
        }
        if ($printContent['buyer_remarks']) {
            $content .= '买家备注：' . $orderInfo['mark'] . '<BR>';
            $content .= '--------------------------------<BR>';
        }
        if (in_array(0, $printContent['goods'])) {
            $content .= '<BR>';
            $content .= '**************商品**************<BR>';
            $content .= '<BR>';
            $content .= '名称           单价  数量 金额<BR>';
            foreach ($product as $item) {
                $content .= '--------------------------------<BR>';
                $name = $item['productInfo']['store_name'] . " | " . $item['productInfo']['attrInfo']['suk'];
                $price = (string)$item['sum_price'];
                $num = (string)$item['cart_num'];
                $prices = bcmul((string)$item['cart_num'], (string)$item['sum_price'], 2);
                $kw3 = '';
                $kw1 = '';
                $kw2 = '';
                $kw4 = '';
                $str = $name;
                $blankNum = 14;//名称控制为14个字节
                $lan = mb_strlen($str, 'utf-8');
                $m = 0;
                $j = 1;
                $blankNum++;
                $result = array();
                if (strlen($price) < 6) {
                    $k1 = 6 - strlen($price);
                    for ($q = 0; $q < $k1; $q++) {
                        $kw1 .= ' ';
                    }
                    $price = $price . $kw1;
                }
                if (strlen($num) < 3) {
                    $k2 = 3 - strlen($num);
                    for ($q = 0; $q < $k2; $q++) {
                        $kw2 .= ' ';
                    }
                    $num = $num . $kw2;
                }
                if (strlen($prices) < 6) {
                    $k3 = 6 - strlen($prices);
                    for ($q = 0; $q < $k3; $q++) {
                        $kw4 .= ' ';
                    }
                    $prices = $prices . $kw4;
                }
                for ($i = 0; $i < $lan; $i++) {
                    $new = mb_substr($str, $m, $j, 'utf-8');
                    $j++;
                    if (mb_strwidth($new, 'utf-8') < $blankNum) {
                        if ($m + $j > $lan) {
                            $m = $m + $j;
                            $tail = $new;
                            $lenght = iconv("UTF-8", "GBK//IGNORE", $new);
                            $k = 14 - strlen($lenght);
                            for ($q = 0; $q < $k; $q++) {
                                $kw3 .= ' ';
                            }
                            if ($m == $j) {
                                $tail .= $kw3 . ' ' . $price . ' ' . $num . ' ' . $prices;
                            } else {
                                $tail .= $kw3 . '<BR>';
                            }
                            break;
                        } else {
                            $next_new = mb_substr($str, $m, $j, 'utf-8');
                            if (mb_strwidth($next_new, 'utf-8') < $blankNum) {
                                continue;
                            } else {
                                $m = $i + 1;
                                $result[] = $new;
                                $j = 1;
                            }
                        }
                    }
                }
                $head = '';
                foreach ($result as $key => $value) {
                    if ($key < 1) {
                        $v_lenght = iconv("UTF-8", "GBK//IGNORE", $value);
                        $v_lenght = strlen($v_lenght);
                        if ($v_lenght == 13) $value = $value . " ";
                        $head .= $value . ' ' . $price . ' ' . $num . ' ' . $prices;
                    } else {
                        $head .= $value . '<BR>';
                    }
                }
                $content .= $head . $tail;
                if (in_array(1, $printContent['goods'])) {
                    $content .= '规格编码：' . $item['productInfo']['attrInfo']['code'] . '<BR>';
                }
                unset($price);
            }
            $content .= '<BR>';
            $content .= '********************************<BR>';
        }
        if ($printContent['freight']) {
            $content .= '<BR>';
            $content .= '--------------------------------<BR>';
            $content .= '<RIGHT>邮费：' . $orderInfo['pay_postage'] . '元</RIGHT><BR>';
            $total_price = bcadd($orderInfo['total_price'], $orderInfo['pay_postage'], 2);
            $content .= '<RIGHT>合计：' . $total_price . '元</RIGHT>';
            $content .= '--------------------------------<BR>';
        }
        if ($printContent['preferential']) {
            $discount_price = bcsub(bcadd($orderInfo['total_price'], $orderInfo['pay_postage'], 2), bcadd($orderInfo['deduction_price'], $orderInfo['pay_price'], 2), 2);
            $content .= '<RIGHT>优惠：-' . $discount_price . '元</RIGHT><BR>';
            $content .= '<RIGHT>抵扣：-' . $orderInfo['deduction_price'] . '元</RIGHT>';
            $content .= '--------------------------------<BR>';
        }
        if (in_array(0, $printContent['pay'])) {
			$systemPayType = PayServices::PAY_TYPE;
			$content .= '<RIGHT>支付方式：'. ($systemPayType[$orderInfo['pay_type']] ?? '其他支付') . '</RIGHT><BR>';
        }
        if (in_array(1, $printContent['pay'])) {
            $content .= '<RIGHT>实际支付：' . $orderInfo['pay_price'] . '元</RIGHT>';
        }
        if (count($printContent['pay'])) {
            $content .= '--------------------------------<BR>';
        }
        if (in_array(0, $printContent['order'])) {
            $content .= '订单编号：' . $orderInfo['order_id'] . '<BR>';
        }
        if (in_array(1, $printContent['order'])) {
            $content .= '下单时间: ' . $addTime . '<BR>';
        }
        if (in_array(2, $printContent['order']) && $payTime) {
            $content .= '付款时间: ' . $payTime . '<BR>';
        }
        if (in_array(3, $printContent['order'])) {
            $content .= '打印时间: ' . $printTime . '<BR>';
        }
        $content .= '--------------------------------<BR>';
        $content .= '<BR>';
        if ($printContent['code'] && $printContent['code_url']) {
            $content .= '<QR>' . sys_config('site_url') . $printContent['code_url'] . '</QR>';
        }
        if ($printContent['show_notice']) {
            $content .= '<C>' . $printContent['notice_content'] . '</C>';
        }
        return $content;
    }

    /**
     * 设置桌码打印内容
     * @param $printContent
     * @param $orderInfo
     * @param $product
     * @param $print_event
     * @return string
     */
    public function feyTableContent($printContent, $config, $print_event)
    {
        $product = $config['product'];
        $tableInfo = $config['tableInfo'];
        $name = $config['name'];
        $printTime = date('Y-m-d H:i:s', time());
        $content = '';
        if ($printContent['header']) {
            if (in_array(0, $printContent['header'])) {
                $content .= '<CB>' . $name . '</CB><BR>';
            }
            if (in_array(1, $printContent['header'])) {
                /** @var SystemStoreServices $storeServices */
                $storeServices = app()->make(SystemStoreServices::class);
                $store_name = $storeServices->value(['id' => $tableInfo['store_id']], 'name');
                $content .= '<CB>' . $store_name . '</CB><BR>';
            }
            /** @var TableQrcodeServices $qrcodeService */
            $qrcodeService = app()->make(TableQrcodeServices::class);
            $Info = $qrcodeService->getQrcodeyInfo((int)$tableInfo['qrcode_id'], ['category']);
            if (in_array(2, $printContent['header'])) {
                $content .= '桌码流水：' . $tableInfo['serial_number'] . '<BR>';
            }
            if (in_array(3, $printContent['header'])) {
                $content .= '桌码分类：' . $Info['category']['name'] . '<BR>';
            }
            if (in_array(4, $printContent['header'])) {
                $content .= '桌码编号：' . $Info['table_number'] . '<BR>';
            }

            $content .= '--------------------------------<BR>';
        }
        if (in_array(0, $printContent['goods'])) {
            $content .= '<BR>';
            $content .= '**************商品**************<BR>';
            $content .= '<BR>';
            $content .= '名称           单价  数量 金额<BR>';
            foreach ($product as $item) {
                $content .= '--------------------------------<BR>';
                $name = $item['productInfo']['store_name'] . " | " . $item['productInfo']['attrInfo']['suk'];
                $price = (string)$item['sum_price'];
                $num = (string)$item['cart_num'];
                $prices = (string)bcmul((string)$item['cart_num'], (string)$item['sum_price'], 2);
                $kw3 = '';
                $kw1 = '';
                $kw2 = '';
                $kw4 = '';
                $str = $name;
                $blankNum = 14;//名称控制为14个字节
                $lan = mb_strlen($str, 'utf-8');
                $m = 0;
                $j = 1;
                $blankNum++;
                $result = array();
                if (strlen($price) < 6) {
                    $k1 = 6 - strlen($price);
                    for ($q = 0; $q < $k1; $q++) {
                        $kw1 .= ' ';
                    }
                    $price = $price . $kw1;
                }
                if (strlen($num) < 3) {
                    $k2 = 3 - strlen($num);
                    for ($q = 0; $q < $k2; $q++) {
                        $kw2 .= ' ';
                    }
                    $num = $num . $kw2;
                }
                if (strlen($prices) < 6) {
                    $k3 = 6 - strlen($prices);
                    for ($q = 0; $q < $k3; $q++) {
                        $kw4 .= ' ';
                    }
                    $prices = $prices . $kw4;
                }
                for ($i = 0; $i < $lan; $i++) {
                    $new = mb_substr($str, $m, $j, 'utf-8');
                    $j++;
                    if (mb_strwidth($new, 'utf-8') < $blankNum) {
                        if ($m + $j > $lan) {
                            $m = $m + $j;
                            $tail = $new;
                            $lenght = iconv("UTF-8", "GBK//IGNORE", $new);
                            $k = 14 - strlen($lenght);
                            for ($q = 0; $q < $k; $q++) {
                                $kw3 .= ' ';
                            }
                            if ($m == $j) {
                                $tail .= $kw3 . ' ' . $price . ' ' . $num . ' ' . $prices;
                            } else {
                                $tail .= $kw3 . '<BR>';
                            }
                            break;
                        } else {
                            $next_new = mb_substr($str, $m, $j, 'utf-8');
                            if (mb_strwidth($next_new, 'utf-8') < $blankNum) {
                                continue;
                            } else {
                                $m = $i + 1;
                                $result[] = $new;
                                $j = 1;
                            }
                        }
                    }
                }
                $head = '';
                foreach ($result as $key => $value) {
                    if ($key < 1) {
                        $v_lenght = iconv("UTF-8", "GBK//IGNORE", $value);
                        $v_lenght = strlen($v_lenght);
                        if ($v_lenght == 13) $value = $value . " ";
                        $head .= $value . ' ' . $price . ' ' . $num . ' ' . $prices;
                    } else {
                        $head .= $value . '<BR>';
                    }
                }
                $content .= $head . $tail;
                if (in_array(1, $printContent['goods'])) {
                    $content .= '规格编码：' . $item['productInfo']['attrInfo']['code'] . '<BR>';
                }
                unset($price);
            }
            $content .= '<BR>';
            $content .= '********************************<BR>';
        }

        if (in_array(3, $printContent['order'])) {
            $content .= '打印时间: ' . $printTime . '<BR>';
        }
        $content .= '--------------------------------<BR>';
        $content .= '<BR>';
        if ($printContent['code'] && $printContent['code_url']) {
            $content .= '<QR>' . sys_config('site_url') . $printContent['code_url'] . '</QR>';
        }
        if ($printContent['show_notice']) {
            $content .= '<C>' . $printContent['notice_content'] . '</C>';
        }
        return $content;
    }
}
