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

namespace app\services\other\export;

use app\services\activity\lottery\LuckLotteryRecordServices;
use app\services\activity\lottery\LuckLotteryServices;
use app\services\BaseServices;
use app\jobs\system\ExportExcelJob;
use app\services\order\StoreOrderServices;
use app\services\pay\PayServices;
use app\services\product\category\StoreProductCategoryServices;
use app\services\product\inventory\StoreProductStockCountServices;
use app\services\product\inventory\StoreProductStockDetailServices;
use app\services\product\inventory\StoreProductStockOrderServices;
use app\services\product\product\StoreDescriptionServices;
use app\services\product\sku\StoreProductAttrResultServices;
use app\services\product\sku\StoreProductAttrValueServices;
use app\services\system\form\SystemFormServices;
use mohe\services\SpreadsheetExcelService;

/**
 * 导出
 * Class ExportServices
 * @package app\services\other\export
 */
class ExportServices extends BaseServices
{
    /**
     * 不分页拆分处理最大条数
     * @var int
     */
    public $maxLimit = 1000;
    /**
     * 分页导出每页条数
     * @var int
     */
    public $limit = 1000;

    /**
     * 订单导出：规格占位「默认」等不输出
     */
    protected function normalizeOrderExportAttrSuk($suk): string
    {
        $s = trim((string)$suk, " \t\n\r\0\x0B\xe3\x80\x80");
        if ($s === '' || $s === '默认' || $s === '默认规格') {
            return '';
        }
        if ($s !== '' && preg_match('/^\s+$/u', $s)) {
            return '';
        }
        return $s;
    }

    /**
     * 订单导出：行级服务对象（与列表/核销逻辑一致）
     */
    protected function orderExportLineServiceObject(array $orderRow, array $cartRow): string
    {
        $pt = (int)($cartRow['productInfo']['product_type'] ?? $cartRow['product_type'] ?? 0);
        $lineSvc = trim((string)($cartRow['service_object'] ?? ''));
        if ($pt === 6 && ($lineSvc === '朋友' || $lineSvc === '本人')) {
            return $lineSvc;
        }
        $orderSvc = trim((string)($orderRow['service_object'] ?? ''));
        return $orderSvc === '朋友' ? '朋友' : '本人';
    }

    /**
     * 核销订单导出：商品名称与核销数量分列（数量不进商品名）
     */
    protected function orderExportWriteoffGoodsFields(array $orderRow): array
    {
        $productName = trim((string)($orderRow['link_product_name'] ?? ''));
        $writeoffNum = trim((string)($orderRow['link_writeoff_num'] ?? ''));
        $linkName = trim((string)($orderRow['link_name'] ?? ''));
        if ($productName === '' && $linkName !== '') {
            if (preg_match('/^(.*),核销数量:(\d+)$/u', $linkName, $m)) {
                $productName = trim($m[1]);
                if ($writeoffNum === '') {
                    $writeoffNum = $m[2];
                }
            } else {
                $productName = $linkName;
            }
        }
        $productName = preg_replace('/,核销数量:\d+$/u', '', $productName);
        $productName = preg_replace('/^服务对象[：:](本人|朋友)\s*/u', '', $productName);
        $productName = preg_replace('/^\[(本人|朋友)\]/u', '', $productName);
        $svc = trim((string)($orderRow['service_object'] ?? '')) === '朋友' ? '朋友' : '本人';
        if ($productName !== '') {
            $productName = '[' . $svc . ']' . $productName;
        }
        return [
            'title' => $productName,
            'num' => $writeoffNum,
            'service_object' => $svc,
        ];
    }

    /**
     * 门店/总后台订单导出：一单多商品拆多行，非商品列用 null 供 Excel 合并
     */
    protected function expandStoreOrderExportRows(array $baseRow, array $productLines): array
    {
        $lineKeys = ['goods_title', 'goods_num', 'goods_unit_price', 'service_object'];
        foreach ($lineKeys as $k) {
            if (!array_key_exists($k, $baseRow)) {
                $baseRow[$k] = '';
            }
        }
        if (count($productLines) <= 1) {
            if (count($productLines) === 1) {
                foreach ($lineKeys as $k) {
                    $baseRow[$k] = (string)($productLines[0][$k] ?? '');
                }
            }
            return [$baseRow];
        }
        $rows = [];
        foreach ($productLines as $i => $line) {
            if ($i === 0) {
                $row = $baseRow;
                foreach ($lineKeys as $k) {
                    $row[$k] = (string)($line[$k] ?? '');
                }
            } else {
                $row = array_fill_keys(array_keys($baseRow), null);
                foreach ($lineKeys as $k) {
                    $row[$k] = (string)($line[$k] ?? '');
                }
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * 真实请求导出
     * @param $header excel表头
     * @param $title 标题
     * @param array $export 填充数据
     * @param string $filename 保存文件名称
     * @param string $suffix 保存文件后缀
     * @param bool $is_save true|false 是否保存到本地
     * @return mixed
     */
    public function export(array $header, array $title_arr, array $export = [], string $filename = '', string $suffix = 'xlsx', bool $is_save = true)
    {
        $path = [];
        $exportNum = count($export);
        $limit = $this->maxLimit;
        if ($exportNum < $limit) {
            $title = isset($title_arr[0]) && !empty($title_arr[0]) ? $title_arr[0] : '导出数据';
            $name = isset($title_arr[1]) && !empty($title_arr[1]) ? $title_arr[1] : '导出数据';
            $info = isset($title_arr[2]) && !empty($title_arr[2]) ? $title_arr[2] : date('Y-m-d H:i:s', time());
            $filePath = SpreadsheetExcelService::instance()->setExcelHeader($header)
                ->setExcelTile($title, $name, $info)
                ->setExcelContent($export)
                ->excelSave($filename, $suffix, $is_save);
            $path[] = sys_config('site_url') . $filePath;
        } else {
            $data = [];
            $i = $j = 0;
            $basePath = sys_config('site_url') . '/phpExcel/';
            foreach ($export as $item) {
                $data[] = $item;
                $i++;
                if ($limit <= 1 || $i == $exportNum) {
                    if ($j > 0) {
                        $filename .= '_' . $j;
                        $header = [];
                        $title_arr = [];
                    }
                    //加入队列
                    ExportExcelJob::dispatch([$data, $filename, $header, $title_arr, $suffix, $is_save]);
                    $path[] = $basePath . $filename . '.' . $suffix;
                    $data = [];
                    $limit = $this->limit + 1;
                    $j++;
                }
                $limit--;
            }
        }
        return $path;
    }

    /**
     * 用户资金导出
     * @param array $data
     * @param int $type 1:直接返回数据前端生成excel 2：后台生成excel
     * @return array|mixed
     */
    public function userFinance($data = [], $type = 1)
    {
        $header = ['会员ID', '昵称', '金额', '类型', '备注', '创建时间'];
        $title = ['资金监控', '资金监控', date('Y-m-d H:i:s', time())];
        $filename = '资金监控_' . date('YmdHis', time());
        $export = [];
        $filekey = [];
        if (!empty($data)) {
            $i = 0;
            foreach ($data as $value) {
                $one_data = [
                    'uid' => $value['uid'],
                    'nickname' => $value['nickname'],
                    'pm' => $value['pm'] == 0 ? '-' . $value['number'] : $value['number'],
                    'title' => $value['title'],
                    'mark' => $value['mark'],
                    'add_time' => $value['add_time'],
                ];
                if ($type == 1) {
                    $export[] = $one_data;
                    if ($i == 0) {
                        $filekey = array_keys($one_data);
                    }
                } else {
                    $export[] = array_values($one_data);
                }
                $i++;
            }
        }
        if ($type == 1) {
            return compact('header', 'filekey', 'export', 'filename');
        } else {
            return $this->export($header, $title, $export, $filename);
        }
    }

    /**
     * 用户佣金导出
     * @param $data 导出数据
     */
    public function userCommission($data = [], $type = 1)
    {
        $header = ['昵称/姓名', '总佣金金额', '账户余额', '账户佣金', '提现到账佣金', '时间'];
        $title = ['佣金记录', '佣金记录' . time(), ' 生成时间：' . date('Y-m-d H:i:s', time())];
        $filename = '佣金记录_' . date('YmdHis', time());
        $export = [];
        $filekey = [];
        if (!empty($data)) {
            $i = 0;
            foreach ($data as $value) {
                $one_data = [
                    'nickname' => $value['nickname'],
                    'sum_number' => $value['sum_number'],
                    'now_money' => $value['now_money'],
                    'brokerage_price' => $value['brokerage_price'],
                    'extract_price' => $value['extract_price'],
                    'time' => $value['time']
                ];
                if ($type == 1) {
                    $export[] = $one_data;
                    if ($i == 0) {
                        $filekey = array_keys($one_data);
                    }
                } else {
                    $export[] = array_values($one_data);
                }
                $i++;
            }
        }
        if ($type == 1) {
            return compact('header', 'filekey', 'export', 'filename');
        } else {
            return $this->export($header, $title, $export, $filename);
        }
    }

    /**
     * 用户积分导出
     * @param $data 导出数据
     */
    public function userPoint($data = [], $type = 1)
    {
        $header = ['编号', '标题', '变动后积分', '积分变动', '备注', '用户微信昵称', '添加时间'];
        $title = ['积分日志', '积分日志' . time(), '生成时间：' . date('Y-m-d H:i:s', time())];
        $filename = '积分日志_' . date('YmdHis', time());
        $export = [];
        $filekey = [];
        if (!empty($data)) {
            $i = 0;
            foreach ($data as $key => $item) {
                $one_data = [
                    'id' => $item['id'],
                    'title' => $item['title'],
                    'balance' => $item['balance'],
                    'number' => $item['number'],
                    'mark' => $item['mark'],
                    'nickname' => $item['nickname'],
                    'add_time' => $item['add_time'],
                ];
                if ($type == 1) {
                    $export[] = $one_data;
                    if ($i == 0) {
                        $filekey = array_keys($one_data);
                    }
                } else {
                    $export[] = array_values($one_data);
                }
                $i++;
            }
        }
        if ($type == 1) {
            return compact('header', 'filekey', 'export', 'filename');
        } else {
            return $this->export($header, $title, $export, $filename);
        }
    }

    /**
     * 用户储值导出
     * @param $data 导出数据
     */
    public function userRecharge($data = [], $type = 1)
    {
        $header = ['UID', '昵称/姓名', '手机号', '储值金额', '赠送金额', '是否支付', '储值类型', '支付时间', '是否退款', '添加时间'];
        $title = ['储值记录', '储值记录' . time(), ' 生成时间：' . date('Y-m-d H:i:s', time())];
        $filename = '储值记录_' . date('YmdHis', time());
        $export = [];
        $filekey = [];
        if (!empty($data)) {
            $i = 0;
            foreach ($data as $item) {
                switch ($item['recharge_type']) {
                    case 'routine':
                        $item['_recharge_type'] = '小程序储值';
                        break;
                    case 'weixin':
                        $item['_recharge_type'] = '公众号储值';
                        break;
                    case 'balance':
                        $item['_recharge_type'] = '佣金转入';
                        break;
                    case 'store':
                        $item['_recharge_type'] = '门店余额储值';
                        break;
                    default:
                        $item['_recharge_type'] = '其他储值';
                        break;
                }
                $item['_pay_time'] = $item['pay_time'] ? date('Y-m-d H:i:s', $item['pay_time']) : '暂无';
                $item['_add_time'] = $item['add_time'] ? date('Y-m-d H:i:s', $item['add_time']) : '暂无';
                $item['paid_type'] = $item['paid'] ? '已支付' : '待付款';

                $one_data = [
                    'uid' => $item['uid'],
                    'nickname' => $item['nickname'],
                    'phone' => $item['phone'],
                    'price' => $item['price'],
                    'give_price' => $item['give_price'],
                    'paid_type' => $item['paid_type'],
                    '_recharge_type' => $item['_recharge_type'],
                    '_pay_time' => $item['_pay_time'],
                    'paid' => $item['paid'] == 1 && $item['refund_price'] == $item['price'] ? '已退款' : '未退款',
                    '_add_time' => $item['_add_time']
                ];
                if ($type == 1) {
                    $export[] = $one_data;
                    if ($i == 0) {
                        $filekey = array_keys($one_data);
                    }
                } else {
                    $export[] = array_values($one_data);
                }
                $i++;
            }
        }
        if ($type == 1) {
            return compact('header', 'filekey', 'export', 'filename');
        } else {
            return $this->export($header, $title, $export, $filename);
        }
    }

    /**
     * 用户推广导出
     * @param $data 导出数据
     */
    public function userAgent($data = [], $type = 1)
    {
        $header = ['用户编号', '昵称', '电话号码', '推广用户数量', '订单数量', '推广订单金额', '佣金金额', '已提现金额', '提现次数', '未提现金额', '上级推广人'];
        $title = ['推广用户', '推广用户导出' . time(), ' 生成时间：' . date('Y-m-d H:i:s', time())];
        $filename = '推广用户_' . date('YmdHis', time());
        $export = [];
        $filekey = [];
        if (!empty($data)) {
            $i = 0;
            foreach ($data as $index => $item) {
                $one_data = [
                    'uid' => $item['uid'],
                    'nickname' => $item['nickname'],
                    'phone' => $item['phone'],
                    'spread_count' => $item['spread_count'],
                    'order_count' => $item['order_count'],
                    'order_price' => $item['order_price'],
                    'brokerage_money' => $item['brokerage_money'],
                    'extract_count_price' => $item['extract_count_price'],
                    'extract_count_num' => $item['extract_count_num'],
                    'brokerage_price' => $item['brokerage_price'],
                    'spread_name' => $item['spread_name'],
                ];
                if ($type == 1) {
                    $export[] = $one_data;
                    if ($i == 0) {
                        $filekey = array_keys($one_data);
                    }
                } else {
                    $export[] = array_values($one_data);
                }
                $i++;
            }
        }
        if ($type == 1) {
            return compact('header', 'filekey', 'export', 'filename');
        } else {
            return $this->export($header, $title, $export, $filename);
        }
    }

    /**
     * 微信用户导出
     * @param $data 导出数据
     */
    public function wechatUser($data = [], $type = 1)
    {
        $header = ['名称', '性别', '地区', '是否关注公众号'];
        $title = ['微信用户导出', '微信用户导出' . time(), ' 生成时间：' . date('Y-m-d H:i:s', time())];
        $filename = '微信用户导出_' . date('YmdHis', time());
        $export = [];
        $filekey = [];
        if (!empty($data)) {
            $i = 0;
            foreach ($data as $index => $item) {
                $one_data = [
                    'nickname' => $item['nickname'],
                    'sex' => $item['sex'],
                    'address' => $item['country'] . $item['province'] . $item['city'],
                    'subscribe' => $item['subscribe'] == 1 ? '关注' : '未关注',
                ];
                if ($type == 1) {
                    $export[] = $one_data;
                    if ($i == 0) {
                        $filekey = array_keys($one_data);
                    }
                } else {
                    $export[] = array_values($one_data);
                }
                $i++;
            }
        }
        if ($type == 1) {
            return compact('header', 'filekey', 'export', 'filename');
        } else {
            return $this->export($header, $title, $export, $filename);
        }
    }

    /**
     * 订单资金导出
     * @param array $data
     * @param int $type
     * @return array|mixed
     */
    public function orderFinance($data = [], $type = 1)
    {
        $header = ['时间', '营业额(元)', '支出(元)', '成本', '优惠', '积分抵扣', '盈利(元)'];
        $title = ['财务统计', '财务统计', date('Y-m-d H:i:s', time())];
        $filename = '财务统计_' . date('YmdHis', time());
        $export = [];
        $filekey = [];
        if (!empty($data)) {
            $i = 0;
            foreach ($data as $info) {
                $time = $info['pay_time'];
                $price = $info['total_price'] + $info['pay_postage'];
                $zhichu = $info['coupon_price'] + $info['deduction_price'] + $info['cost'];
                $profit = ($info['total_price'] + $info['pay_postage']) - ($info['coupon_price'] + $info['deduction_price'] + $info['cost']);
                $deduction = $info['deduction_price'];//积分抵扣
                $coupon = $info['coupon_price'];//优惠
                $cost = $info['cost'];//成本
                $one_data = compact('time', 'price', 'zhichu', 'cost', 'coupon', 'deduction', 'profit');
                if ($type == 1) {
                    $export[] = $one_data;
                    if ($i == 0) {
                        $filekey = array_keys($one_data);
                    }
                } else {
                    $export[] = array_values($one_data);
                }
                $i++;
            }
        }
        if ($type == 1) {
            return compact('header', 'filekey', 'export', 'filename');
        } else {
            return $this->export($header, $title, $export, $filename);
        }
    }

    /**
     * 砍价活动导出
     * @param array $data
     * @param int $type
     * @return array|mixed
     */
    public function storeBargain($data = [], $type = 1)
    {
        $header = ['砍价活动名称', '砍价活动简介', '砍价金额', '砍价最低价',
            '用户每次砍价的次数', '砍价状态', '砍价开启时间', '砍价结束时间', '销量', '库存', '返多少积分', '添加时间'];
        $title = ['砍价商品导出', '商品信息' . time(), ' 生成时间：' . date('Y-m-d H:i:s', time())];
        $filename = '砍价商品导出_' . date('YmdHis', time());
        $export = [];
        $filekey = [];
        if (!empty($data)) {
            $i = 0;
            foreach ($data as $index => $item) {
                $one_data = [
                    'title' => $item['title'] ?? '',
                    'info' => $item['info'] ?? '',
                    'price' => '￥' . ($item['price'] ?? 0),
                    'bargain_max_price' => '￥' . ($item['min_price'] ?? 0),
                    'bargain_num' => $item['bargain_num'] ?? 0,
                    'status' => $item['status'] ? '开启' : '关闭',
                    'start_time' => empty($item['start_time']) ? '' : date('Y-m-d H:i:s', (int)$item['start_time']),
                    'stop_time' => empty($item['stop_time']) ? '' : date('Y-m-d H:i:s', (int)$item['stop_time']),
                    'sales' => $item['sales'] ?? 0,
                    'stock' => $item['stock'] ?? 0,
                    'give_integral' => $item['give_integral'] ?? 0,
                    'add_time' => empty($item['add_time']) ? '' : $item['add_time'],
                ];
                if ($type == 1) {
                    $export[] = $one_data;
                    if ($i == 0) {
                        $filekey = array_keys($one_data);
                    }
                } else {
                    $export[] = array_values($one_data);
                }
                $i++;
            }
        }
        if ($type == 1) {
            return compact('header', 'filekey', 'export', 'filename');
        } else {
            return $this->export($header, $title, $export, $filename);
        }
    }

    /**
     * 拼团导出
     * @param array $data
     * @param int $type
     * @return array|mixed
     */
    public function storeCombination($data = [], $type = 1)
    {
        $header = ['编号', '拼团名称', '划线价', '拼团价', '库存', '拼团人数', '参与人数', '成团数量', '销量', '商品状态', '结束时间'];
        $title = ['拼团商品导出', '商品信息' . time(), ' 生成时间：' . date('Y-m-d H:i:s', time())];
        $filename = '拼团商品导出_' . date('YmdHis', time());
        $export = [];
        $filekey = [];
        if (!empty($data)) {
            $i = 0;
            foreach ($data as $item) {
                $one_data = [
                    'id' => $item['id'],
                    'title' => $item['title'],
                    'ot_price' => $item['ot_price'],
                    'price' => $item['price'],
                    'stock' => $item['stock'],
                    'people' => $item['count_people'],
                    'count_people_all' => $item['count_people_all'],
                    'count_people_pink' => $item['count_people_pink'],
                    'sales' => $item['sales'] ?? 0,
                    'is_show' => $item['is_show'] ? '开启' : '关闭',
                    'stop_time' => empty($item['stop_time']) ? '' : date('Y/m/d H:i:s', (int)$item['stop_time'])
                ];
                if ($type == 1) {
                    $export[] = $one_data;
                    if ($i == 0) {
                        $filekey = array_keys($one_data);
                    }
                } else {
                    $export[] = array_values($one_data);
                }
                $i++;
            }
        }
        if ($type == 1) {
            return compact('header', 'filekey', 'export', 'filename');
        } else {
            return $this->export($header, $title, $export, $filename);
        }
    }

    /**
     * 秒杀活动导出
     * @param array $data
     * @param int $type
     * @return array|mixed
     */
    public function storeSeckill($data = [], $type = 1)
    {
        $header = ['编号', '活动标题', '活动简介', '划线价', '秒杀价', '限量', '限量剩余', '秒杀状态', '结束时间', '状态'];
        $title = ['秒杀商品导出', ' ', ' 生成时间：' . date('Y-m-d H:i:s', time())];
        $filename = '秒杀商品导出_' . date('YmdHis', time());
        $export = [];
        $filekey = [];
        if (!empty($data)) {
            $i = 0;
            foreach ($data as $item) {
                if ($item['status']) {
                    if ($item['start_time'] > time())
                        $item['start_name'] = '活动未开始';
                    else if ($item['stop_time'] < time())
                        $item['start_name'] = '活动已结束';
                    else if ($item['stop_time'] > time() && $item['start_time'] < time())
                        $item['start_name'] = '正在进行中';
                } else {
                    $item['start_name'] = '活动已结束';
                }
                $one_data = [
                    'id' => $item['id'],
                    'title' => $item['title'],
                    'info' => $item['info'],
                    'ot_price' => $item['ot_price'],
                    'price' => $item['price'],
                    'quota_show' => $item['quota_show'],
                    'quota' => $item['quota'],
                    'start_name' => $item['start_name'],
                    'stop_time' => $item['stop_time'] ? date('Y-m-d H:i:s', $item['stop_time']) : '/',
                    'status' => $item['status'] ? '开启' : '关闭',
                ];
                if ($type == 1) {
                    $export[] = $one_data;
                    if ($i == 0) {
                        $filekey = array_keys($one_data);
                    }
                } else {
                    $export[] = array_values($one_data);
                }
                $i++;
            }
        }
        if ($type == 1) {
            return compact('header', 'filekey', 'export', 'filename');
        } else {
            return $this->export($header, $title, $export, $filename);
        }
    }

    /**
     * 导出商品卡号、卡密模版
     * @param int $type
     * @return array|mixed
     */
    public function storeProductCardTemplate($type = 1)
    {
        $header = ['卡号', '卡密'];
        $title = ['商品卡密模版', '商品密' . time(), ' 生成时间：' . date('Y-m-d H:i:s', time())];
        $filename = '商品卡密模版_' . date('YmdHis', time());

        if ($type == 1) {
            $export = [];
            $filekey = ['card_no', 'card_pwd'];
            return compact('header', 'filekey', 'export', 'filename');
        } else {
            return $this->export($header, $title, [], $filename);
        }
    }

    /**
     * 核销记录导出
     * @param array $data
     * @param int $type
     * @return array|mixed
     */
    public function writeoff($data = [], $type = 1)
    {
        $header = ['ID', '订单号', '客户名称', '手机号', '商品名称', '手艺人', '核销数量', '下单门店', '核销门店', '核销人员', '核销金额', '核销时间'];
        $title = ['核销记录导出', '核销记录导出' . time(), ' 生成时间：' . date('Y-m-d H:i:s', time())];
        $filename = '核销记录导出_' . date('YmdHis', time());
        $export = [];
        $filekey = [];
        if (!empty($data)) {
            $i = 0;
            foreach ($data['list'] as $nk=>$value) {
                $one_data = [
                    'id' => $value['id'],
                    'order_id' => $value['order_id'],
                    'real_name' => $value['real_name'],
                    'phone' => $value['phone'],
                    'product_name' => $value['product_name'],
                    'yeji_staff' => $value['yeji_staff'] ?? '',
                    'writeoff_num' => $value['writeoff_num'] ?? 1,
                    'ordering_store' => $value['ordering_store'],
                    'write_off_store' => $value['write_off_store'],
                    'staff_name' => $value['staff_name'],
                    'writeoff_price' => $value['writeoff_price'],
                    'add_time' => $value['add_time']
                ];
                if ($type == 1) {
                    $export[] = $one_data;
                    if ($i == 0) {
                        $filekey = array_keys($one_data);
                    }
                } else {
                    $export[] = array_values($one_data);
                }
                $i++;
            }
        }
        if ($type == 1) {
            return compact('header', 'filekey', 'export', 'filename');
        } else {
            return $this->export($header, $title, $export, $filename);
        }
    }
    /**
     * 商品导出
     * @param array $data
     * @param int $type
     * @return array|mixed
     */
    public function storeProduct($data = [], $type = 1)
    {
        $header = ['商品编号', '商品名称', '商品类型', '商品来源', '商品品牌', '商品单位', '规格类型', '规格名称', '售价', '划线价', '成本价', '调价区间（最小值）', '调价区间（最大值）',
            '库存', '重量', '体积', '商品编码', '条形码', '销量', '浏览量', '收藏数', '购买送积分', '虚拟销量', '仅会员可见', '创建时间'];
        $title = ['商品导出', '商品信息' . time(), ' 生成时间：' . date('Y-m-d H:i:s', time())];
        $filename = '商品导出_' . date('YmdHis', time());
        $export = [];
        $filekey = [];
        $virtualType = [0 => '普通商品', 1 => '卡密/网盘', 2 => '优惠券', 3 => '虚拟商品', 4 => '次卡商品', 5 => '卡项商品', 6 => '预约商品'];
        if (!empty($data)) {
            $i = 0;
            $productIds = array_column($data, 'id');
            $productList = array_column($data, null, 'id');
            $attrResultArr = app()->make(StoreProductAttrResultServices::class)->getColumn([['product_id', 'in', $productIds], ['type', '=', 0]], 'result', 'product_id');
            foreach ($attrResultArr as $product_id => $attrResult) {
                $attrResult = json_decode($attrResult, true);
                $productInfo = $productList[$product_id];
                foreach ($attrResult['value'] as &$value) {
                    $one_data = [
                        'id' => intval($product_id),
                        'store_name' => $productInfo['store_name'] ?? '',
                        'product_type' => $virtualType[$productInfo['product_type']],
                        'plate_name' => $productInfo['plate_name'] ?? '',
                        'brand_name' => $productInfo['brand_name'] ?? '',
                        'unit_name' => $productInfo['unit_name'] ?? '',
                        'spec_type' => $productInfo['spec_type'] == 1 ? '多规格' : '单规格',
                        'sku_name' => implode(',', $value['detail']),
                        'price' => '￥' . ($productInfo['price'] ?? 0),
                        'ot_price' => '￥' . ($productInfo['ot_price'] ?? 0),
                        'cost' => '￥' . ($productInfo['cost'] ?? 0),
                        'price_range_min' => '￥' . ($productInfo['price_range_min'] ?? 0),
                        'price_range_max' => '￥' . ($productInfo['price_range_max'] ?? 0),
                        'stock' => $productInfo['stock'] ?? 0,
                        'volume' => intval($value['volume'] ?? 0),
                        'weight' => intval($value['weight'] ?? 0),
                        'code' => $value['code'] ?? '',
                        'bar_code' => $value['bar_code'] ?? '',
                        'sales' => intval($productInfo['sales']),
                        'browse' => intval($productInfo['browse']),
                        'collect' => intval($productInfo['collect']),
                        'give_integral' => $productInfo['give_integral'],
                        'ficti' => intval($productInfo['ficti']),
                        'is_vip_product' => intval($productInfo['is_vip_product']) == 1 ? '是' : '否',
                        'add_time' => date('Y-m-d H:i:s', $productInfo['add_time']),
                    ];
                    if ($type == 1) {
                        $export[] = $one_data;
                        if ($i == 0) {
                            $filekey = array_keys($one_data);
                        }
                    } else {
                        $export[] = array_values($one_data);
                    }
                    $i++;
                }
            }
        }
        if ($type == 1) {
            return compact('header', 'filekey', 'export', 'filename');
        } else {
            return $this->export($header, $title, $export, $filename);
        }
    }

    /**
     * 商铺订单导出
     * @param $data
     * @param $type
     * @param $export_type
     * @param $isSupplier 是否是供应商导出
     * @return array|mixed
     */
    public function storeOrder($data = [], $type = "", $export_type = 1, $isSupplier = false)
    {
        if (!$type) {
            if ($isSupplier) {//供应商
                $header = ['订单ID', '订单编号', '订单类型', '订单状态', '下单时间', '订单归属', '商品名称', '数量', '单价', '服务对象', '商品总数',
                    '支付方式', '支付状态', '支付时间', '交易单号', '商品结算价合计', '邮费', '实际支付邮费',
                    '订单配送方式', '送货人姓名/手机号', '收货人/提货人', '收货人手机号/提货人手机号', '收货人省份', '收货人城市', '收货人地区', '收货人/提货人详细地址',
                    '买家昵称', '买家手机号', '买家是否会员', '买家备注', '自定义表单'];
            } else {
                $header = ['下单时间', '用户信息', '商品名称', '数量', '单价', '服务对象', '付款金额', '赠送金额', '付款方式', '手艺人', '销售人', '消费门店', '订单状态', '订单类型', '来源', '是否跟单', '订单ID', '订单号',
                    '撤销原因', '关联ID', '关联订单ID', '下单门店'];
            }
            $title = ['订单导出', '订单信息' . time(), ' 生成时间：' . date('Y-m-d H:i:s', time())];
            $filename = '订单导出_' . date('YmdHis', time());
        } else {
            if ($isSupplier) {
                $header = ['订单ID', '订单编号', '物流公司', '物流编码', '物流单号', '发货地址', '收货人姓名', '收货人电话', '订单总结算价', '商品数量*商品结算价', '商品ID', '商品名称', '商品规格', '商家备注', '订单成交时间'];
            } else {
                $header = ['订单ID', '订单编号', '物流公司', '物流编码', '物流单号', '发货地址', '收货人姓名', '收货人电话', '订单实付金额', '商品数量*售价', '商品ID', '商品名称', '商品规格', '商家备注', '订单成交时间'];
            }
            $title = ['发货单导出', '订单信息' . time(), ' 生成时间：' . date('Y-m-d H:i:s', time())];
            $filename = '发货单导出_' . date('YmdHis', time());
        }
        $export = [];
        $filekey = [];
        if (!empty($data)) {
            /** @var SystemFormServices $systemFormServices */
            $systemFormServices = app()->make(SystemFormServices::class);
            $handleReservationInfo = function ($reservationInfo) {
                if (!$reservationInfo) {
                    return [];
                }
                $result = [];
                foreach ($reservationInfo as $info) {
                    if (!isset($info['title']) || !isset($info['value'])) continue;
                    $result[] = $info['title'] . '：' . (is_array($info['value']) ? implode(',', $info['value']) : $info['value']);
                }
                return $result;
            };
            $i = 0;
            foreach ($data as $item) {
                $one_data = [];
                if (!$type) {
                    if ($item['paid'] == 0 && $item['status'] == 0) {
                        $item['status_name'] = '待付款';
                    } else if ($item['paid'] == 1 && $item['status'] == 4 && in_array($item['shipping_type'], [1, 3]) && $item['refund_status'] == 0) {
                        $item['status_name'] = '部分发货';
                    } else if ($item['paid'] == 1 && $item['status'] == 5 && $item['shipping_type'] == 2 && $item['refund_status'] == 0) {
                        $item['status_name'] = '部分核销';
                    } else if ($item['paid'] == 1 && $item['refund_status'] == 1) {
                        $item['status_name'] = '申请退款';
                    } else if ($item['paid'] == 1 && $item['refund_status'] == 2) {
                        $item['status_name'] = '已退款';
                    } else if ($item['paid'] == 1 && $item['refund_status'] == 4) {
                        $item['status_name'] = '退款中';
                    } else if ($item['paid'] == 1 && $item['status'] == 0 && in_array($item['shipping_type'], [1, 3]) && $item['refund_status'] == 0) {
                        $item['status_name'] = '未发货';
                    } else if ($item['paid'] == 1 && in_array($item['status'], [0, 1]) && $item['shipping_type'] == 2 && $item['refund_status'] == 0) {
                        $item['status_name'] = '未核销';
                    } else if ($item['paid'] == 1 && in_array($item['status'], [1, 5]) && in_array($item['shipping_type'], [1, 3]) && $item['refund_status'] == 0) {
                        $item['status_name'] = '待收货';
                    } else if ($item['paid'] == 1 && $item['status'] == 2 && $item['refund_status'] == 0) {
                        $item['status_name'] = '待评价';
                    } else if ($item['paid'] == 1 && $item['status'] == 3 && $item['refund_status'] == 0) {
                        $item['status_name'] = '已完成';
                    } else if ($item['paid'] == 1 && $item['refund_status'] == 3) {
                        $item['status_name'] = '部分退款';
                    }
                    $productLines = [];
                    $vip_sum_price = 0;
                    foreach ($item['_info'] as $k => $v) {
                        if (!isset($v['productInfo']) || !is_array($v['productInfo'])) {
                            $v['productInfo'] = [];
                        }
                        $name = (string)($v['productInfo']['store_name'] ?? $v['productInfo']['title'] ?? '');
                        $name = preg_replace('/^\[(本人|朋友)\]/u', '', $name);
                        $suk = '';
                        if (isset($v['productInfo']['attrInfo']['suk'])) {
                            $suk = $this->normalizeOrderExportAttrSuk($v['productInfo']['attrInfo']['suk']);
                        }
                        if ($suk !== '') {
                            $name = $name === '' ? $suk : ($name . '(' . $suk . ')');
                        }
                        $cartNum = (string)($v['cart_num'] ?? '');
                        $unitPrice = (string)($v['truePrice'] ?? '0');
                        $svc = $this->orderExportLineServiceObject($item, $v);
                        if ($name !== '' || $cartNum !== '') {
                            $productLines[] = [
                                'goods_title' => $name,
                                'goods_num' => $cartNum,
                                'goods_unit_price' => $unitPrice,
                                'service_object' => $svc,
                            ];
                        }
                        $vip_sum_price = bcadd((string)$vip_sum_price, bcmul($v['vip_truePrice'], $v['cart_num'] ? $v['cart_num'] : 1, 4), 2);
                    }

                    $names=$item['user_nickname']."/".$item['uid'];
                    if(empty($item['uid'])){
                        $names="游客/".$item['uid'];
                    }
                    if(!empty($item['delete_time'])){
                        $names=$names."(已注销)";
                    }
                    $one_data=[
                           'add_time'=>date("Y-m-d H:i:s",$item['add_time']),
                           'nickname'=>$names,
                           'goods_title' => '',
                           'goods_num' => '',
                           'goods_unit_price' => '',
                           'service_object' => '',
                           'pay_price'=>$item['pay_price'],
                           'send_price'=>$item['send_price'],
                           'pay_type_name' => $item['pay_type_name'],
                           'yeji_craft_staff' => $item['yeji_craft_staff'] ?? '',
                           'yeji_sales_staff' => $item['yeji_sales_staff'] ?? '',
                           'store_name' => $item['store_name'],
                           'statusName' => $item['status_name'],
                           'order_type_label' => $item['order_type_label'],
                           'source_name' => $item['source_name'],
                           'is_gendan_name' => $item['is_gendan_name'] ?? (!empty($item['is_gendan']) ? '是' : '否'),
                           'id' => $item['id'],
                           'order_id' => $item['order_id'],
                           'back_reason' => $item['back_reason'],
                           'link_id' => $item['link_id'],
                           'link_order' => $item['link_order'],
                           'kua_store'=>$item['kua_store_name'] ?? ''
                    ];
                    if($item['order_type'] == 1){
                        $productLines = [[
                            'goods_title' => '充值订单',
                            'goods_num' => '',
                            'goods_unit_price' => (string)($item['pay_price'] ?? ''),
                            'service_object' => '本人',
                        ]];
                    } elseif ($item['order_type'] == 2) {
                        $woGoods = $this->orderExportWriteoffGoodsFields($item);
                        $productLines = [[
                            'goods_title' => $woGoods['title'],
                            'goods_num' => $woGoods['num'],
                            'goods_unit_price' => '',
                            'service_object' => $woGoods['service_object'],
                        ]];
                    }
//                    $one_data = [
//                        'id' => $item['id'],
//                        'order_id' => $item['order_id'],
//                        'type_name' => $item['order_type_label'] ?? '',
//                        'source_name' => $item['source_name'] ?? '',
//                        'status_name' => $item['status_name'] ?? '未知状态',
//                        'add_time' => empty($item['add_time']) ? 0 : date('Y-m-d H:i:s', (int)$item['add_time']),
//                        'plate_name' => $item['plate_name'] ?? '',
//                        'goods_name' => $goodsName ? implode("\n", $goodsName) : '',
//                        'total_num' => $item['total_num'],
//                        'pay_type_name' => $item['pay_type_name'] ?? '',
//                        'pay_status' => $item['paid'] ? '已支付' : '未支付',
//                        'pay_time' => $item['pay_time'] > 0 ? date('Y/m-d H:i', (int)$item['pay_time']) : '暂无',
//                        'trade_no' => $item['trade_no'],
//                    ];
//                    if ($isSupplier) {//需要结算价
//                        $one_data['settle_price'] = $item['settle_price'];
//                    } else {
//                        $one_data['total_price'] = $item['total_price'];
//                        $one_data['pay_price'] = $item['pay_price'];
//                    }
//                    $one_data['total_postage'] = $item['total_postage'];
//                    $one_data['pay_postage'] = $item['pay_postage'];
//                    if (!$isSupplier) {
//                        $one_data['vip_sum_price'] = $vip_sum_price;
//                        $one_data['coupon_price'] = $item['coupon_price'];
//                        $one_data['deduction_price'] = $item['deduction_price'] ?? 0;
//                        $one_data['promotions_price'] = $item['promotions_price'];
//                        $one_data['first_order_price'] = $item['first_order_price'];
//                        $one_data['change_price'] = $item['change_price'];
//                    }
//                    $one_data['delivery_type_name'] = $item['delivery_type_name'] ?? '';
//                    $one_data['delivery_name'] = $item['delivery_name'];
//                    $one_data['real_name'] = $item['real_name'];
//                    $one_data['user_phone'] = $item['user_phone'];
//                    $one_data['user_address_province'] = $item['user_address_province'] ?? '';
//                    $one_data['user_address_city'] = $item['user_address_city'] ?? '';
//                    $one_data['user_address_district'] = $item['user_address_district'] ?? '';
//                    $one_data['user_address_detail'] = $item['user_address_detail'] ?? '';
//                    $one_data['user_nickname'] = $item['user_nickname'] ?? '';
//                    $one_data['user_real_phone'] = $item['user_real_phone'];
//                    $one_data['user_level'] = $item['user_level'] ? '是' : '否';
//                    $one_data['mark'] = $item['mark'];
//                    $one_data['custom_form_info'] = implode("\r\n", $handleReservationInfo($systemFormServices->handleForm($item['custom_form'], 2)));
                    foreach ($this->expandStoreOrderExportRows($one_data, $productLines) as $row) {
                        if ($export_type == 1) {
                            $export[] = $row;
                            if (!$filekey) {
                                $filekey = array_keys($row);
                            }
                        } else {
                            $export[] = array_values($row);
                        }
                    }
                } else {
                    if (isset($item['pinkStatus']) && $item['pinkStatus'] != 2) {
                        continue;
                    }
                    if (isset($item['refund']) && $item['refund']) {
                        continue;
                    }
                    $goodsName = [];
                    $g = 0;
                    foreach ($item['_info'] as $k => $v) {
                        if ($isSupplier) {
                            $goodsName['cart_num'][$g] = $v['cart_num'] . ' * ' . ($v['productInfo']['attrInfo']['settle_price'] ?? $v['productInfo']['price'] ?? 0.00);
                        } else {
                            $goodsName['cart_num'][$g] = $v['cart_num'] . ' * ' . ($v['productInfo']['attrInfo']['price'] ?? $v['productInfo']['price'] ?? 0.00);
                        }
                        $goodsName['product_id'][$g] = $v['product_id'];
                        $suk = $barCode = $code = '';
                        if (!empty($v['productInfo']['attrInfo']['bar_code'])) {
                            $barCode = $v['productInfo']['attrInfo']['bar_code'];
                        }
                        if (!empty($v['productInfo']['attrInfo']['code'])) {
                            $code = $v['productInfo']['attrInfo']['code'];
                        }
                        if (isset($v['productInfo']['attrInfo'])) {
                            if (isset($v['productInfo']['attrInfo']['suk'])) {
                                $sukNorm = $this->normalizeOrderExportAttrSuk($v['productInfo']['attrInfo']['suk']);
                                $parts = [];
                                if ($sukNorm !== '') {
                                    $parts[] = $sukNorm;
                                }
                                if ($barCode !== '') {
                                    $parts[] = '条码:' . $barCode;
                                }
                                if ($code !== '') {
                                    $parts[] = '编码:' . $code;
                                }
                                if ($parts) {
                                    $suk = '(' . implode('|', $parts) . ')';
                                }
                            }
                        }
                        $name = [];
                        if (isset($v['productInfo']['store_name'])) {
                            $name[] = implode(' ',
                                [
                                    $v['productInfo']['store_name'],
                                    $suk,
                                    "[" . $v['cart_num'] . " * " . ($isSupplier ? ($v['productInfo']['attrInfo']['settle_price'] ?? 0) : $v['truePrice']) . " ]",
                                ]);
                        }
                        $goodsName['goods_name'][$g] = implode(' ', $name);
                        $goodsName['attr'][$g] = $this->normalizeOrderExportAttrSuk($v['productInfo']['attrInfo']['suk'] ?? '');
                        $g++;
                    }
                    $one_data = [
                        'id' => $item['id'],
                        'order_id' => $item['order_id'],
                        'a' => "",
                        'b' => "",
                        'c' => "",
                        'user_address' => $item['user_address'],
                        'real_name' => $item['real_name'],
                        'user_phone' => $item['user_phone'],
                        'pay_price' => $isSupplier ? $item['settle_price'] : $item['pay_price'],
                        'cart_num' => isset($goodsName['cart_num']) ? implode("\n", $goodsName['cart_num']) : '',
                        'product_id' => isset($goodsName['product_id']) ? implode("\n", $goodsName['product_id']) : '',
                        'goods_name' => isset($goodsName['goods_name']) ? implode("\n", $goodsName['goods_name']) : '',
                        'attr' => isset($goodsName['attr']) ? implode("\n", $goodsName['attr']) : '',
                        'remark' => $item['remark'],
                        'pay_time' => $item['pay_time'] ? date('Y-m-d H:i:s', (int)$item['pay_time']) : '暂无'
                    ];
                    if ($export_type == 1) {
                        $export[] = $one_data;
                        if ($i == 0) {
                            $filekey = array_keys($one_data);
                        }
                    } else {
                        $export[] = array_values($one_data);
                    }
                }
                $i++;
            }
        }
        if ($export_type == 1) {
            return compact('header', 'filekey', 'export', 'filename');
        } else {
            return $this->export($header, $title, $export, $filename);
        }
    }


    /**
     * 门店导出
     * @param array $data
     * @param int $type
     * @return array|mixed
     */
    public function storeMerchant($data = [], $type = 1)
    {
        $header = ['门店名称', '联系电话', '地址', '营业时间', '状态'];
        $title = ['门店导出', '门店信息' . time(), ' 生成时间：' . date('Y-m-d H:i:s', time())];
        $filename = '门店导出_' . date('YmdHis', time());
        $export = [];
        $filekey = [];
        if (!empty($data)) {
            $i = 0;
            foreach ($data as $index => $item) {
                $one_data = [
                    'name' => $item['name'],
                    'phone' => $item['phone'],
                    'address' => $item['address'] . '' . $item['detailed_address'],
                    'day_time' => $item['day_time'],
                    'is_show' => $item['is_show'] ? '开启' : '关闭'
                ];
                if ($type == 1) {
                    $export[] = $one_data;
                    if ($i == 0) {
                        $filekey = array_keys($one_data);
                    }
                } else {
                    $export[] = array_values($one_data);
                }
                $i++;
            }
        }
        if ($type == 1) {
            return compact('header', 'filekey', 'export', 'filename');
        } else {
            return $this->export($header, $title, $export, $filename);
        }
    }

    /**
     * 会员卡导出
     * @param array $data
     * @param int $type
     * @return array|mixed
     */
    public function memberCard($data = [], $type = 1)
    {
        $header = ['会员卡号', '密码', '领取人', '领取人手机号', '领取时间', '是否使用'];
        $title = ['会员卡导出', '会员卡导出' . time(), ' 生成时间：' . date('Y-m-d H:i:s', time())];
        $filename = $data['title'] ? ("卡密会员_" . trim(str_replace(["\r\n", "\r", "\\", "\n", "/", "<", ">", "=", " "], '', $data['title']))) : "";
        $export = [];
        $filekey = [];
        if (!empty($data)) {
            $i = 0;
            foreach ($data['data'] as $index => $item) {
                $one_data = [
                    'card_number' => $item['card_number'],
                    'card_password' => $item['card_password'],
                    'user_name' => $item['user_name'],
                    'user_phone' => $item['user_phone'],
                    'use_time' => $item['use_time'],
                    'use_uid' => $item['use_uid'] ? '已领取' : '未领取'
                ];
                if ($type == 1) {
                    $export[] = $one_data;
                    if ($i == 0) {
                        $filekey = array_keys($one_data);
                    }
                } else {
                    $export[] = array_values($one_data);
                }
                $i++;
            }
        }
        if ($type == 1) {
            return compact('header', 'filekey', 'export', 'filename');
        } else {
            return $this->export($header, $title, $export, $filename);
        }
    }

    /**
     * 批量任务发货记录导出
     * @param array $data
     * @param $queueType
     * @param int $type
     * @return array|mixed
     */
    public function batchOrderDelivery($data = [], $queueType, $type = 1)
    {
        if (in_array($queueType, [7, 8])) {
            $header = ['订单ID', '物流公司', '物流单号', '处理状态', '异常原因'];
        }
        if ($queueType == 9) {
            $header = ['订单ID', '配送员姓名', '配送员电话', '处理状态', '异常原因'];
        }
        if ($queueType == 10) {
            $header = ['订单ID', '虚拟发货内容', '处理状态', '异常原因'];
        }
        $title = ['发货记录导出', '发货记录导出' . time(), ' 生成时间：' . date('Y-m-d H:i:s', time())];
        $filename = '批量任务发货记录_' . date('YmdHis', time());
        $export = [];
        $filekey = [];
        if (!empty($data)) {
            $i = 0;
            foreach ($data as $index => $item) {
                if (!$item) {
                    continue;
                }
                if (in_array($queueType, [7, 8, 9])) {
                    $one_data = [
                        'order_id' => $item['order_id'] ?? '',
                        'delivery_name' => $item['delivery_name'] ?? '',
                        'delivery_id' => $item['delivery_id'] ?? '',
                        'status_cn' => $item['status_cn'] ?? '',
                        'error' => $item['error'] ?? '',
                    ];
                } else {
                    $one_data = [
                        'order_id' => $item['order_id'] ?? '',
                        'fictitious_content' => $item['fictitious_content'] ?? '',
                        'status_cn' => $item['status_cn'] ?? '',
                        'error' => $item['error'] ?? '',
                    ];
                }
                if ($type == 1) {
                    $export[] = $one_data;
                    if ($i == 0) {
                        $filekey = array_keys($one_data);
                    }
                } else {
                    $export[] = array_values($one_data);
                }
                $i++;
            }
        }
        if ($type == 1) {
            return compact('header', 'filekey', 'export', 'filename');
        } else {
            return $this->export($header, $title, $export, $filename);
        }
    }

    /**
     * 物流公司对照表
     * @param array $data
     * @param int $type
     * @return array|mixed
     */
    public function expressList($data = [], $type = 1)
    {
        $header = ['物流公司名称', '物流公司编码'];
        $title = ['物流公司对照表导出', '物流公司对照表导出' . time(), ' 生成时间：' . date('Y-m-d H:i:s', time())];
        $filename = '物流公司对照表_' . date('YmdHis', time());
        $export = [];
        $filekey = [];
        if (!empty($data)) {
            $i = 0;
            foreach ($data as $index => $item) {
                $one_data = [
                    'name' => $item['name'],
                    'code' => $item['code'],
                ];
                if ($type == 1) {
                    $export[] = $one_data;
                    if ($i == 0) {
                        $filekey = array_keys($one_data);
                    }
                } else {
                    $export[] = array_values($one_data);
                }
                $i++;
            }
        }
        if ($type == 1) {
            return compact('header', 'filekey', 'export', 'filename');
        } else {
            return $this->export($header, $title, $export, $filename);
        }
    }

    /**
     * 交易统计
     * @param array $data
     * @param string $tradeTitle
     * @param int $type
     * @return array|mixed
     */
    public function tradeData($data = [], $tradeTitle = "交易统计", $type = 1)
    {
        $header = ['时间'];
        $title = [$tradeTitle, $tradeTitle, ' 生成时间：' . date('Y-m-d H:i:s', time())];
        $filename = $tradeTitle . '_' . date('YmdHis', time());
        $export = [];
        $filekey = [];
        if (!empty($data)) {
            $headerArray = array_column($data['series'], 'name');
            $header = array_merge($header, $headerArray);
            $export = [];
            foreach ($data['series'] as $index => $item) {
                foreach ($data['x'] as $k => $v) {
                    $export[$v]['time'] = $v;
                    $export[$v][] = $item['value'][$k];
                }
            }
        }
        if ($type == 1) {
            return compact('header', 'filekey', 'export', 'filename');
        } else {
            return $this->export($header, $title, $export, $filename);
        }
    }


    /**
     * 商品统计
     * @param array $data
     * @param int $type
     * @return array|mixed
     */
    public function productTrade($data = [], $type = 1)
    {
        $header = ['日期/时间', '商品浏览量', '商品访客数', '加购件数', '下单件数', '支付件数', '支付金额', '成本金额', '退款金额', '退款件数', '访客-支付转化率'];
        $title = ['商品统计', '商品统计' . time(), ' 生成时间：' . date('Y-m-d H:i:s', time())];
        $filename = '商品统计_' . date('YmdHis', time());
        $export = [];
        $filekey = [];
        if (!empty($data)) {
            $i = 0;
            foreach ($data as $value) {
                $one_data = [
                    'time' => $value['time'],
                    'browse' => $value['browse'],
                    'user' => $value['user'],
                    'cart' => $value['cart'],
                    'order' => $value['order'],
                    'payNum' => $value['payNum'],
                    'pay' => $value['pay'],
                    'cost' => $value['cost'],
                    'refund' => $value['refund'],
                    'refundNum' => $value['refundNum'],
                    'changes' => $value['changes'] . '%'
                ];
                if ($type == 1) {
                    $export[] = $one_data;
                    if ($i == 0) {
                        $filekey = array_keys($one_data);
                    }
                } else {
                    $export[] = array_values($one_data);
                }
                $i++;
            }
        }
        if ($type == 1) {
            return compact('header', 'filekey', 'export', 'filename');
        } else {
            return $this->export($header, $title, $export, $filename);
        }
    }

    /**
     * 用户统计
     * @param array $data
     * @param int $type
     * @return array|mixed
     */
    public function userTrade($data = [], $type = 1)
    {
        $header = ['日期/时间', '访客数', '浏览量', '新增用户数', '成交用户数', '访客-支付转化率', '付费会员数', '储值用户数', '客单价'];
        $title = ['用户统计', '用户统计' . time(), ' 生成时间：' . date('Y-m-d H:i:s', time())];
        $filename = '用户统计_' . date('YmdHis', time());
        $export = [];
        $filekey = [];
        if (!empty($data)) {
            $i = 0;
            foreach ($data as $value) {
                $one_data = [
                    'time' => $value['time'],
                    'user' => $value['user'],
                    'browse' => $value['browse'],
                    'new' => $value['new'],
                    'paid' => $value['paid'],
                    'changes' => $value['changes'] . '%',
                    'vip' => $value['vip'],
                    'recharge' => $value['recharge'],
                    'payPrice' => $value['payPrice'],
                ];
                if ($type == 1) {
                    $export[] = $one_data;
                    if ($i == 0) {
                        $filekey = array_keys($one_data);
                    }
                } else {
                    $export[] = array_values($one_data);
                }
                $i++;
            }
        }
        if ($type == 1) {
            return compact('header', 'filekey', 'export', 'filename');
        } else {
            return $this->export($header, $title, $export, $filename);
        }
    }


    /**
     * 导出积分兑换订单
     * @param array $data
     * @param int $type
     * @return array|mixed
     */
    public function storeIntegralOrder($data = [], $type = 1)
    {
        $header = ['订单号', '电话', '收货人姓名', '收货人电话', '收货地址', '商品信息', '订单状态', '下单时间', '用户备注'];
        $title = ['积分兑换订单导出', '订单信息' . time(), ' 生成时间：' . date('Y-m-d H:i:s', time())];
        $filename = '积分兑换订单导出_' . date('YmdHis', time());
        $export = [];
        $filekey = [];
        if (!empty($data)) {
            $i = 0;
            foreach ($data as $item) {
                $one_data = [
                    'order_id' => $item['order_id'],
                    'phone' => $item['user_phone'],
                    'real_name' => $item['real_name'],
                    'user_phone' => $item['user_phone'],
                    'user_address' => $item['user_address'],
                    'goods_name' => $item['store_name'],
                    'status_name' => $item['status_name'] ?? '未知状态',
                    'add_time' => $item['add_time'],
                    'mark' => $item['mark']
                ];
                if ($type == 1) {
                    $export[] = $one_data;
                    if ($i == 0) {
                        $filekey = array_keys($one_data);
                    }
                } else {
                    $export[] = array_values($one_data);
                }
                $i++;
            }
        }
        if ($type == 1) {
            return compact('header', 'filekey', 'export', 'filename');
        } else {
            return $this->export($header, $title, $export, $filename);
        }
    }

    /**
     * 门店账单导出
     * @param array $data
     * @param int $type
     * @return array|mixed
     */
    public function financeRecord($data = [], $name = '账单导出', $type = 1)
    {
        $header = ['交易单号', '关联订单', '商品信息', '交易时间', '交易金额', '支出收入', '交易人', '关联店员', '交易类型', '支付方式'];
        $title = [$name, $name . time(), '生成时间：' . date('Y-m-d H:i:s', time())];
        $filename = $name . date('YmdHis', time());
        $export = [];
        $filekey = [];
        if (!empty($data)) {
            $i = 0;
            foreach ($data as $key => $item) {
                $goodsName = [];
                foreach ($item['_info'] as $k => $v) {
                    $suk = '';
                    if (isset($v['productInfo']['attrInfo'])) {
                        if (isset($v['productInfo']['attrInfo']['suk'])) {
                            $suk = '(' . $v['productInfo']['attrInfo']['suk'] . ')';
                        }
                    }
                    if (isset($v['productInfo']['store_name'])) {
                        $goodsName[] = implode(' ',
                            [
                                $v['productInfo']['store_name'],
                                $suk,
                                $v['cart_num']
                            ]);
                    }
                }
                $one_data = [
                    'order_id' => $item['order_id'],
                    'link_id' => $item['link_id'],
                    'goods_name' => $goodsName ? implode("\n", $goodsName) : '',
                    'trade_time' => $item['trade_time'],
                    'number' => $item['number'],
                    'pm' => $item['pm'] == 1 ? '收入' : '支出',
                    'user_nickname' => $item['user_nickname'],
                    'staff_name' => $item['staff_name'] ?? '',
                    'type_name' => $item['type_name'],
                    'pay_type_name' => $item['pay_type_name'],
                ];
                if ($type == 1) {
                    $export[] = $one_data;
                    if ($i == 0) {
                        $filekey = array_keys($one_data);
                    }
                } else {
                    $export[] = array_values($one_data);
                }
                $i++;
            }
        }
        if ($type == 1) {
            return compact('header', 'filekey', 'export', 'filename');
        } else {
            return $this->export($header, $title, $export, $filename);
        }
    }

    /**
     * 供应商账单导出
     * @param array $data
     * @param int $type
     * @return array|mixed
     */
    public function supplierFinanceRecord($data = [], $name = '账单导出', $type = 1)
    {
        $header = ['交易单号', '关联订单', '商品信息', '交易时间', '交易金额', '支出收入', '交易人', '交易类型', '支付方式'];
        $title = [$name, $name . time(), '生成时间：' . date('Y-m-d H:i:s', time())];
        $filename = $name . date('YmdHis', time());
        $export = [];
        $filekey = [];
        if (!empty($data)) {
            $i = 0;
            foreach ($data as $key => $item) {
                $goodsName = [];
                foreach ($item['_info'] as $k => $v) {
                    $suk = '';
                    if (isset($v['productInfo']['attrInfo'])) {
                        if (isset($v['productInfo']['attrInfo']['suk'])) {
                            $suk = '(' . $v['productInfo']['attrInfo']['suk'] . ')';
                        }
                    }
                    if (isset($v['productInfo']['store_name'])) {
                        $goodsName[] = implode(' ',
                            [
                                $v['productInfo']['store_name'],
                                $suk,
                                $v['cart_num']
                            ]);
                    }
                }
                $one_data = [
                    'order_id' => $item['order_id'],
                    'link_id' => $item['link_id'],
                    'goods_name' => $goodsName ? implode("\n", $goodsName) : '',
                    'trade_time' => $item['trade_time'],
                    'number' => $item['number'],
                    'pm' => $item['pm'] == 1 ? '收入' : '支出',
                    'user_nickname' => $item['user_nickname'],
                    'type_name' => $item['type_name'],
                    'pay_type_name' => $item['pay_type_name'],
                ];
                if ($type == 1) {
                    $export[] = $one_data;
                    if ($i == 0) {
                        $filekey = array_keys($one_data);
                    }
                } else {
                    $export[] = array_values($one_data);
                }
                $i++;
            }
        }
        if ($type == 1) {
            return compact('header', 'filekey', 'export', 'filename');
        } else {
            return $this->export($header, $title, $export, $filename);
        }
    }

    /**
     * @param array $data
     * @param int $type
     * @return array|mixed
     */
    public function vipOrder(array $data, int $type = 1)
    {
        $header = ['订单号', '用户名', '手机号', '会员类型', '有效期限', '支付金额', '支付方式', '购买时间', '到期时间'];
        $title = ['会员订单', '会员订单' . time(), '生成时间：' . date('Y-m-d H:i:s', time())];
        $filename = '会员订单' . date('YmdHis', time());
        $export = [];
        $filekey = [];
        if (!empty($data)) {
            $i = 0;
            foreach ($data as $key => $item) {
                $one_data = [
                    'order_id' => $item['order_id'],
                    'nickname' => $item['user']['nickname'] ?? '',
                    'phone' => $item['user']['phone'] ?? '',
                    'member_type' => $item['member_type'],
                    'vip_day' => $item['vip_day'],
                    'pay_price' => $item['pay_price'],
                    'pay_type' => $item['pay_type'],
                    'pay_time' => $item['pay_time'],
                    'overdue_time' => $item['overdue_time']
                ];
                if ($type == 1) {
                    $export[] = $one_data;
                    if ($i == 0) {
                        $filekey = array_keys($one_data);
                    }
                } else {
                    $export[] = array_values($one_data);
                }
                $i++;
            }
        }
        if ($type == 1) {
            return compact('header', 'filekey', 'export', 'filename');
        } else {
            return $this->export($header, $title, $export, $filename);
        }
    }

    /**
     * 发票导出
     * @param array $data
     * @param int $type
     * @return array|mixed
     */
    public function invoiceRecord(array $data, int $type = 1)
    {
        $header = ['订单号', '订单金额', '发票类型', '发票抬头类型', '发票抬头名称', '下单时间', '开票状态', '订单状态'];
        $title = ['发票导出', '发票导出' . time(), '生成时间：' . date('Y-m-d H:i:s', time())];
        $filename = '发票导出' . date('YmdHis', time());
        $export = [];
        $filekey = [];
        if (!empty($data)) {
            $i = 0;
            foreach ($data as $key => $item) {
                $one_data = [
                    'order_id' => $item['order_id'],
                    'pay_price' => $item['pay_price'],
                    'type' => $item['type'] == 1 ? '电子普通发票' : '纸质专用发票',
                    'header_type' => $item['header_type'] == 1 ? '个人' : '企业',
                    'name' => $item['name'],
                    'add_time' => $item['add_time'],
                    'is_invoice' => $item['is_invoice'] == 1 ? '已开票' : '未开票'
                ];
                if ($item['refund_status'] > 0) {
                    if ($item['refund_status'] == 1) {
                        $one_data['status'] = '退款中';
                    } else {
                        $one_data['status'] = '已退款';
                    }
                } else {
                    if ($item['status'] == 0) {
                        $one_data['status'] = '未发货';
                    } elseif ($item['status'] == 1) {
                        $one_data['status'] = '待收货';
                    } elseif ($item['status'] == 2) {
                        $one_data['status'] = '待评价';
                    } elseif ($item['status'] == 3) {
                        $one_data['status'] = '已完成';
                    }
                }
                if ($type == 1) {
                    $export[] = $one_data;
                    if ($i == 0) {
                        $filekey = array_keys($one_data);
                    }
                } else {
                    $export[] = array_values($one_data);
                }
                $i++;
            }
        }
        if ($type == 1) {
            return compact('header', 'filekey', 'export', 'filename');
        } else {
            return $this->export($header, $title, $export, $filename);
        }
    }

    /**
     * 系统表单收集数据导出
     * @param array $data
     * @param int $type
     * @return array|mixed
     */
    public function systemFormData(array $data, int $type = 1)
    {
        $header = ['模版名称', '用户UID', '用户昵称', '商品ID', '商品名称', '手机号', '订单编号', '模版内容', '创建时间'];
        $title = ['系统表单收集数据导出', '表单收集数据导出' . time(), '生成时间：' . date('Y-m-d H:i:s', time())];
        $filename = '系统表单收集数据导出' . date('YmdHis', time());
        $export = [];
        $filekey = [];
        if (!empty($data)) {
            $i = 0;
            foreach ($data as $key => $item) {
                $one_data = [
                    'system_form_name' => $item['system_form_name'] ?? '',
                    'uid' => $item['uid'] ?? 0,
                    'nickname' => $item['nickname'] ?? '',
                    'product_id' => $item['product_id'] ?? 0,
                    'product_name' => $item['product_name'] ?? '',
                    'phone' => $item['phone'] ?? '',
                    'order_id' => $item['order_id'] ?? '',
                    'form_data' => is_string($item['value']) ? json_decode($item['value']) : $item['value'],
                    'add_time' => $item['add_time'],
                ];
                if ($type == 1) {
                    $export[] = $one_data;
                    if ($i == 0) {
                        $filekey = array_keys($one_data);
                    }
                } else {
                    $export[] = array_values($one_data);
                }
                $i++;
            }
        }
        if ($type == 1) {
            return compact('header', 'filekey', 'export', 'filename');
        } else {
            return $this->export($header, $title, $export, $filename);
        }
    }

    /**
     * 店员列表导出
     * @param $data
     * @param $name
     * @param $type
     * @return array|mixed
     */
    public function staffList($data = [], $name = '账单导出', $type = 1)
    {
        $header = ['ID', '店员名称', '手机号', '所属门店', '专属客户数量', '订单数量', '订单金额', '业绩'];
        $title = [$name, $name . time(), '生成时间：' . date('Y-m-d H:i:s', time())];
        $filename = $name . date('YmdHis', time());
        $export = [];
        $filekey = [];
        if (!empty($data)) {
            $i = 0;
            foreach ($data as $key => $item) {
                $one_data = [
                    'id' => $item['id'],
                    'staff_name' => $item['staff_name'],
                    'phone' => $item['phone'],
                    'name' => $item['name'] ?? '',
                    'customer_num' => $item['customer_num'],
                    'order_num' => $item['order_num'],
                    'order_price' => $item['order_price'],
                    'performance_price' => $item['performance_price'],
                ];
                if ($type == 1) {
                    $export[] = $one_data;
                    if ($i == 0) {
                        $filekey = array_keys($one_data);
                    }
                } else {
                    $export[] = array_values($one_data);
                }
                $i++;
            }
        }
        if ($type == 1) {
            return compact('header', 'filekey', 'export', 'filename');
        } else {
            return $this->export($header, $title, $export, $filename);
        }
    }

    /**
     * 店员交易统计导出
     * @param $data
     * @param $name
     * @param $type
     * @return array|mixed
     */
    public function statistics(array $data = [], string $name = '账单导出', int $type = 1)
    {
        $header = ['订单号', '用户信息', '实际支付', '订单类型', '支付方式', '店员名称', '下单时间'];
        $title = [$name, $name . time(), '生成时间：' . date('Y-m-d H:i:s', time())];
        $filename = $name . date('YmdHis', time());
        $export = [];
        $filekey = [];
        if (!empty($data)) {
            $i = 0;
            foreach ($data as $key => $item) {
                $one_data = [
                    'link_id' => $item['link_id'],
                    'user_nickname' => $item['user_nickname'],
                    'pay_price' => $item['pay_price'],
                    'type_name' => $item['type_name'],
                    'pay_type_name' => $item['pay_type_name'],
                    'staff_name' => $item['staff_name'],
                    'add_time' => $item['add_time'],
                ];
                if ($type == 1) {
                    $export[] = $one_data;
                    if ($i == 0) {
                        $filekey = array_keys($one_data);
                    }
                } else {
                    $export[] = array_values($one_data);
                }
                $i++;
            }
        }
        if ($type == 1) {
            return compact('header', 'filekey', 'export', 'filename');
        } else {
            return $this->export($header, $title, $export, $filename);
        }
    }

    /**
     * 导出预约单
     * @param array $data
     * @param string $name
     * @param int $type
     * @return array|mixed
     */
    public function reservationOrder(array $data = [], string $name = '预约单导出', int $type = 1)
    {
        $header = ['预约单号', '预约模式', '预约商品信息', '预约规格', '数量', '预约人电话', '预约人昵称', '预约人地址', '预约日期', '预约时间', '预约补充信息', '服务人员', '服务人员电话', '预约状态', '商家备注', '预约确定时间'];
        $title = [$name, $name . time(), '生成时间：' . date('Y-m-d H:i:s', time())];
        $filename = $name . date('YmdHis', time());
        $export = [];
        $filekey = [];
        if (!empty($data)) {
            $i = 0;
            /** @var SystemFormServices $systemFormServices */
            $systemFormServices = app()->make(SystemFormServices::class);
            $handleReservationInfo = function ($reservationInfo) {
                if (!$reservationInfo) {
                    return [];
                }
                $result = [];
                foreach ($reservationInfo as $info) {
                    if (!isset($info['title']) || !isset($info['value'])) continue;
                    $result[] = $info['title'] . '：' . (is_array($info['value']) ? implode(',', $info['value']) : $info['value']);
                }
                return $result;
            };
            foreach ($data as $key => $item) {
                $one_data = [
                    'order_id' => $item['order_id'],
                    'reservation_type_name' => $item['reservation_type'] == 2 ? '到店服务' : '上门服务',
                    'store_name' => $item['cart_info']['productInfo']['store_name'] ?? '',
                    'sku' => $item['sku'],
                    'cart_num' => 1,
                    'reservation_phone' => $item['reservation_phone'] ?? '',
                    'reservation_name' => $item['reservation_name'] ?? '',
                    'reservation_address' => $item['reservation_address'] ?? '',
                    'reservation_time' => $item['reservation_time'],
                    'reservation_show_time' => $item['reservation_start'] . '-' . $item['reservation_end'],
                    'reservation_info' => implode("\r\n", $handleReservationInfo($systemFormServices->handleForm($item['reservation_info']))),
                    'service_staff_name' => $item['service_staff_name'] ?? '',
                    'service_staff_phone' => $item['service_staff_phone'] ?? '',
                    'status_name' => $item['status_name'],
                    'remark' => $item['remark'],
                    'reservation_create_time' => $item['reservation_create_time'],
                ];
                if ($type == 1) {
                    $export[] = $one_data;
                    if ($i == 0) {
                        $filekey = array_keys($one_data);
                    }
                } else {
                    $export[] = array_values($one_data);
                }
                $i++;
            }
        }
        if ($type == 1) {
            return compact('header', 'filekey', 'export', 'filename');
        } else {
            return $this->export($header, $title, $export, $filename);
        }
    }

    /**
     * 导入用户错误记录导出
     * @param array $data
     * @param int $type
     * @return array|mixed
     */
    public function importUserCard(array $data, int $type = 1)
    {
        $header = ['错误信息'];
        $title = ['导入错误信息', '导入用户错误信息导出' . time(), '生成时间：' . date('Y-m-d H:i:s', time())];
        $filename = '导入用户卡项权益错误信息导出' . date('YmdHis', time());
        $export = [];
        $filekey = [];
        if (!empty($data)) {
            $i = 0;
            foreach ($data as $key => $item) {
                $one_data = [
                    'fail_msg' => $item['fail_msg'] ?? '',
                ];
                if ($type == 1) {
                    $export[] = $one_data;
                    if ($i == 0) {
                        $filekey = array_keys($one_data);
                    }
                } else {
                    $export[] = array_values($one_data);
                }
                $i++;
            }
        }
        if ($type == 1) {
            return compact('header', 'filekey', 'export', 'filename');
        } else {
            return $this->export($header, $title, $export, $filename);
        }
    }
    /**
     * 导入用户错误记录导出
     * @param array $data
     * @param int $type
     * @return array|mixed
     */
    public function importUser(array $data, int $type = 1)
    {
        $header = ['openid', 'unioId', 'uid', '手机号', '用户昵称', '客户姓名', '性别', '生日', '用户等级', '经验值', '付费会员有效期',
            '客户积分', '客户余额', '客户标签', '用户分组', '用户来源', '省', '市', '区', '地址', '错误信息'
        ];
        $title = ['导入错误信息', '导入用户错误信息导出' . time(), '生成时间：' . date('Y-m-d H:i:s', time())];
        $filename = '导入用户错误信息导出' . date('YmdHis', time());
        $export = [];
        $filekey = [];
        if (!empty($data)) {
            $i = 0;
            foreach ($data as $key => $item) {
                $original_data = $item['original_data'];
                $one_data = [
                    'openid' => $original_data['openid'] ?? '',
                    'unionid' => $original_data['unionid'] ?? '',
                    'uid' => $original_data['uid'] ?? '',
                    'phone' => $original_data['phone'] ?? '',
                    'nickname' => $original_data['nickname'] ?? '',
                    'real_name' => $original_data['real_name'] ?? '',
                    'sex' => $original_data['sex'] ?? '',
                    'birthday' => $original_data['birthday'] ?? '',
                    'level' => $original_data['level'] ?? '',
                    'exp' => $original_data['exp'] ?? '',
                    'overdue_time' => $original_data['overdue_time'] ?? '',
                    'integral' => $original_data['integral'] ?? '',
                    'now_money' => $original_data['now_money'] ?? '',
                    'label' => $original_data['label'] ?? '',
                    'group' => $original_data['group'] ?? '',
                    'login_type' => $original_data['login_type'] ?? '',
                    'province' => $original_data['province'] ?? '',
                    'city' => $original_data['city'] ?? '',
                    'area' => $original_data['area'] ?? '',
                    'address' => $original_data['address'] ?? '',
                    'fail_msg' => $item['fail_msg'] ?? '',
                ];
                if ($type == 1) {
                    $export[] = $one_data;
                    if ($i == 0) {
                        $filekey = array_keys($one_data);
                    }
                } else {
                    $export[] = array_values($one_data);
                }
                $i++;
            }
        }
        if ($type == 1) {
            return compact('header', 'filekey', 'export', 'filename');
        } else {
            return $this->export($header, $title, $export, $filename);
        }
    }

    public function importProduct(array $data, int $type = 1)
    {
        $header = [
            '商品编号', '商品名称', '商品类型', '商品分类(一级)', '商品分类(二级)', '商品分类(三级)', '商品单位',
            '商品图片', '商品视频', '商品详情',
            '已售数量', '规格类型', '规格类型值', '规格名称', '规格值组合', '规格图片', '售价', '划线价', '成本价', '库存', '重量', '体积', '商品编码', '条形码',
            '商品简介', '商品关键字', '商品口令',
            '购买送积分', '错误信息'
        ];
        $title = ['导入错误信息', '导入商品错误信息导出' . time(), '生成时间：' . date('Y-m-d H:i:s', time())];
        $filename = '导入商品错误信息导出' . date('YmdHis', time());
        $export = [];
        $filekey = [];
        if (!empty($data)) {
            $i = 0;
            $virtualType = [0 => '普通商品', 1 => '卡密/网盘', 2 => '优惠券', 3 => '虚拟商品', 4 => '次卡商品', 5 => '卡项商品', 6 => '预约商品'];
            foreach ($data as $key => $item) {
                $original_data = $item['original_data'];
                $one_data = [
                    'id' => $original_data['id'],
                    'store_name' => $original_data['store_name'],
                    'product_type' => $original_data['product_type'],
                    'cate_name_one' => $original_data['cate_name_one'],
                    'cate_name_two' => $original_data['cate_name_two'],
                    'cate_name_three' => $original_data['cate_name_three'],
                    'unit_name' => $original_data['unit_name'],
                    'slider_image' => $original_data['slider_image'],
                    'video_link' => $original_data['video_link'],
                    'description' => htmlspecialchars_decode($original_data['description']),
                    'ficti' => intval($original_data['ficti']),
                    'spec_type' => $original_data['spec_type'],
                    'sku_type_value' => $original_data['sku_value'],
                    'sku_name' => $original_data['sku_name'],
                    'sku_value' => $original_data['sku_value'],
                    'pic' => $original_data['pic'],

                    'price' => floatval($original_data['price']),
                    'ot_price' => floatval($original_data['ot_price']),
                    'cost' => floatval($original_data['cost']),
                    'stock' => intval($original_data['stock']),
                    'volume' => intval($original_data['volume'] ?? 0),
                    'weight' => intval($original_data['weight'] ?? 0),
                    'code' => $original_data['code'] ?? '',
                    'bar_code' => $original_data['bar_code'] ?? '',
                    'store_info' => $original_data['store_info'],
                    'keyword' => $original_data['keyword'],
                    'command_word' => $original_data['command_word'],
                    'give_integral' => $original_data['give_integral'],
//                    'delivery_type' => $original_data['delivery_type'],
//                    'applicable_type' => $original_data['applicable_type'],
//                    'applicable_store_id' => $original_data['applicable_store_id'],
                    'fail_msg' => $item['fail_msg'] ?? '',
                ];
                if ($type == 1) {
                    $export[] = $one_data;
                    if ($i == 0) {
                        $filekey = array_keys($one_data);
                    }
                } else {
                    $export[] = array_values($one_data);
                }
                $i++;
            }
        }
        if ($type == 1) {
            return compact('header', 'filekey', 'export', 'filename');
        } else {
            return $this->export($header, $title, $export, $filename);
        }
    }

    /**
     * @param array $productList
     * @param int $type
     * @return array|mixed
     */
    public function adminProductImport(array $productList, int $type = 1)
    {
        $header = [
            '商品编号', '商品名称', '商品类型', '商品分类(一级)', '商品分类(二级)', '商品分类(三级)', '商品单位',
            '商品图片', '商品视频', '商品详情', '已售数量', '规格类型', '规格类型值', '规格名称', '规格值组合', '规格图片',
            '售价', '调价区间（最小值）', '调价区间（最大值）', '划线价', '成本价', '库存', '重量', '体积', '商品编码', '条形码',
            '商品简介', '商品关键字', '商品口令', '购买送积分'
        ];
        $title = ['商品迁移', '商品迁移导出' . time(), '生成时间：' . date('Y-m-d H:i:s', time())];
        $filename = '商品迁移导出' . date('YmdHis', time());
        $export = [];
        $filekey = [];
        /** @var StoreProductCategoryServices $categoryService */
        $categoryService = app()->make(StoreProductCategoryServices::class);
        /** @var StoreProductCategoryServices $categoryService */
        $attrValueService = app()->make(StoreProductAttrValueServices::class);
        if (!empty($productList)) {
            $virtualType = [0 => '普通商品', 1 => '卡密/网盘', 2 => '优惠券', 3 => '虚拟商品', 4 => '次卡商品', 5 => '卡项商品', 6 => '预约商品'];
            $i = 0;
            $productIds = array_column($productList, 'id');
            $productList = array_column($productList, null, 'id');
            $attrResultArr = app()->make(StoreProductAttrResultServices::class)->getColumn([['product_id', 'in', $productIds], ['type', '=', 0]], 'result', 'product_id');
            $descriptionArr = app()->make(StoreDescriptionServices::class)->getColumn([['product_id', 'in', $productIds], ['type', '=', 0]], 'description', 'product_id');
            foreach ($attrResultArr as $product_id => $attrResult) {
                $attrResult = json_decode($attrResult, true);
                $productInfo = $productList[$product_id];
                $cate_one = $cate_two = $cate_three = '';
                if (isset($productInfo['cate_id'])) {
                    $cate_name = $categoryService->getCateParentAndChildName($productInfo['cate_id']);
                    foreach ($cate_name as $item => $value) {
                        switch ($value['level']) {
                            case 0:
                                if ($cate_one) {
                                    $cate_one = $cate_one . '/' . $value['two'];
                                } else {
                                    $cate_one = $value['two'];
                                }
                                break;
                            case 1:
                                if ($cate_two) {
                                    $cate_two = $cate_two . '/' . $value['two'];
                                } else {
                                    $cate_two = $value['two'];
                                }
                                break;
                            case 2:
                                if ($cate_three) {
                                    $cate_three = $cate_three . '/' . $value['two'];
                                } else {
                                    $cate_three = $value['two'];
                                }
                                break;
                        }
                    }
                }
                $attrValue = $productInfo['attrValue'];
                foreach ($attrResult['value'] as $k=>&$value) {
                    if (!isset($value['attr_arr'])) {
                        $attrResult['value'][$k]['attr_arr'] = array_values($value['detail']);
                        foreach ($value['detail'] as $detailKey => $detailValue) {
                            $attrResult['value'][$k][$detailKey] = $detailValue;
                        }
                    }
                    foreach ($attrValue as $attrKey => $attrItem) {
                        if (implode(',', $attrResult['value'][$k]['attr_arr']) == $attrKey) {
                            $attrResult['value'][$k]['unique'] = $attrItem['unique'] ?? '';
                            $attrResult['value'][$k]['price'] = $attrItem['price'] ?? 0;
                            $attrResult['value'][$k]['price_range_min'] = $attrItem['price_range_min'] ?? 0;
                            $attrResult['value'][$k]['price_range_max'] = $attrItem['price_range_max'] ?? 0;
                            $attrResult['value'][$k]['stock'] = $attrItem['stock'] ?? 0;
                            $attrResult['value'][$k]['cost'] = $attrItem['cost'] ?? 0;
                            $attrResult['value'][$k]['ot_price'] = $attrItem['ot_price'] ?? 0;
                        }
                    }
                    $skuArr = array_combine(array_column($attrResult['attr'], 'value'), $value['detail']);
                    $attrArr = [];
                    foreach ($attrResult['attr'] as $attrArray) {
                        // 将每个子数组的 'value' 和 'detail' 组合成字符串
                        if (isset($attrArray['detail'][0]['value'])) {
                            $attrArray['detail'] = array_column($attrArray['detail'], 'value');
                        }
                        $detailString = implode(',', $attrArray['detail']); // 将 detail 数组转换为逗号分隔的字符串
                        $attrArr[] = $attrArray['value'] . '=' . $detailString;
                    }
                    $attrString = implode(';', $attrArr);
                    $one_data = [
                        'id' => intval($product_id),
                        'store_name' => $productInfo['store_name'],
                        'product_type' => $virtualType[$productInfo['product_type']],
//                        'product_brand' => $productInfo['brand_name'],
                        'cate_name_one' => $cate_one,
                        'cate_name_two' => $cate_two,
                        'cate_name_three' => $cate_three,
                        'unit_name' => $productInfo['unit_name'],
                        'slider_image' => implode(';', $productInfo['slider_image']),
                        'video_link' => $productInfo['video_link'],
                        'description' => htmlspecialchars_decode(isset($descriptionArr[$productInfo['id']]) ?? $descriptionArr[$productInfo['id']]),
                        'ficti' => intval($productInfo['ficti']),
                        'spec_type' => intval($productInfo['spec_type']) == 1 ? '多规格' : '单规格',

                        'sku_type_value' => $attrString,
                        'sku_name' => implode(',', $value['detail']),
                        'sku_value' => implode(';', array_map(function ($key, $value) {
                            return "$key=$value";
                        }, array_keys($skuArr), $skuArr)),
                        'pic' => $value['pic'],

                        'price' => isset($value['price']) ? floatval($value['price']) : 0,
                        'price_range_min' => isset($value['price_range_min']) ? floatval($value['price_range_min']) : 0,
                        'price_range_max' => isset($value['price_range_max']) ? floatval($value['price_range_max']) : 0,
                        'ot_price' => isset($value['ot_price']) ? floatval($value['ot_price']) : 0,
                        'cost' => isset($value['cost']) ? floatval($value['cost']) : 0,
                        'stock' => isset($value['stock']) ? intval($value['stock']) : 0,
                        'weight' => isset($value['weight']) ? intval($value['weight'] ?? 0) : 0,
                        'volume' => isset($value['volume']) ? intval($value['volume'] ?? 0) : 0,

                        'code' => $value['code'] ?? '',
                        'bar_code' => $value['bar_code'] ?? '',
                        'store_info' => $productInfo['store_info'],
                        'keyword' => $productInfo['keyword'],
                        'command_word' => $productInfo['command_word'],
                        'give_integral' => $productInfo['give_integral'],
                    ];
                    if ($type == 1) {
                        $export[] = $one_data;
                        if ($i == 0) {
                            $filekey = array_keys($one_data);
                        }
                    } else {
                        $export[] = array_values($one_data);
                    }
                    $i++;
                }
            }
        }
        if ($type == 1) {
            return compact('header', 'filekey', 'export', 'filename');
        } else {
            return $this->export($header, $title, $export, $filename);
        }
    }

    /**
     * 门店商品导出
     * @param array $productList
     * @param int $type
     * @return array|mixed
     */
    public function storeProductImport(array $productList, int $type = 1)
    {
        $header = [
            '商品编号', '商品名称', '商品类型', '商品分类(一级)', '商品分类(二级)', '商品分类(三级)', '商品单位',
            '商品图片', '商品视频', '商品详情', '已售数量', '规格类型', '规格类型值', '规格名称', '规格值组合', '规格图片',
            '售价', '划线价', '成本价', '库存', '重量', '体积', '商品编码', '条形码',
            '商品简介', '商品关键字', '商品口令'
        ];
        $title = ['商品迁移', '商品迁移导出' . time(), '生成时间：' . date('Y-m-d H:i:s', time())];
        $filename = '商品迁移导出' . date('YmdHis', time());
        $export = [];
        $filekey = [];
        /** @var StoreProductCategoryServices $categoryService */
        $categoryService = app()->make(StoreProductCategoryServices::class);
        /** @var StoreProductCategoryServices $categoryService */
        $attrValueService = app()->make(StoreProductAttrValueServices::class);
        if (!empty($productList)) {
            $virtualType = [0 => '普通商品', 1 => '卡密/网盘', 2 => '优惠券', 3 => '虚拟商品', 4 => '次卡商品', 5 => '卡项商品', 6 => '预约商品'];
            $i = 0;
            $productIds = array_column($productList, 'id');
            $productList = array_column($productList, null, 'id');
            $attrResultArr = app()->make(StoreProductAttrResultServices::class)->getColumn([['product_id', 'in', $productIds], ['type', '=', 0]], 'result', 'product_id');
            $descriptionArr = app()->make(StoreDescriptionServices::class)->getColumn([['product_id', 'in', $productIds], ['type', '=', 0]], 'description', 'product_id');
            foreach ($attrResultArr as $product_id => $attrResult) {
                $attrResult = json_decode($attrResult, true);
                $productInfo = $productList[$product_id];
                $cate_one = $cate_two = $cate_three = '';
                if (isset($productInfo['cate_id'])) {
                    $cate_name = $categoryService->getCateParentAndChildName($productInfo['cate_id']);
                    foreach ($cate_name as $item => $value) {
                        switch ($value['level']) {
                            case 0:
                                if ($cate_one) {
                                    $cate_one = $cate_one . '/' . $value['two'];
                                } else {
                                    $cate_one = $value['two'];
                                }
                                break;
                            case 1:
                                if ($cate_two) {
                                    $cate_two = $cate_two . '/' . $value['two'];
                                } else {
                                    $cate_two = $value['two'];
                                }
                                break;
                            case 2:
                                if ($cate_three) {
                                    $cate_three = $cate_three . '/' . $value['two'];
                                } else {
                                    $cate_three = $value['two'];
                                }
                                break;
                        }
                    }
                }

                $attrValue = $productInfo['attrValue'];
                foreach ($attrResult['value'] as $k=>&$value) {
                    if (!isset($value['attr_arr'])) {
                        $attrResult['value'][$k]['attr_arr'] = array_values($value['detail']);
                        foreach ($value['detail'] as $detailKey => $detailValue) {
                            $attrResult['value'][$k][$detailKey] = $detailValue;
                        }
                    }
                    foreach ($attrValue as $attrKey => $attrItem) {
                        if (implode(',', $attrResult['value'][$k]['attr_arr']) == $attrKey) {
                            $attrResult['value'][$k]['unique'] = $attrItem['unique'] ?? '';
                            $attrResult['value'][$k]['price'] = $attrItem['price'] ?? 0;
                            $attrResult['value'][$k]['price_range_min'] = $attrItem['price_range_min'] ?? 0;
                            $attrResult['value'][$k]['price_range_max'] = $attrItem['price_range_max'] ?? 0;
                            $attrResult['value'][$k]['stock'] = $attrItem['stock'] ?? 0;
                            $attrResult['value'][$k]['cost'] = $attrItem['cost'] ?? 0;
                            $attrResult['value'][$k]['ot_price'] = $attrItem['ot_price'] ?? 0;
                        }
                    }

                    $skuArr = array_combine(array_column($attrResult['attr'], 'value'), $value['detail']);
                    $attrArr = [];
                    foreach ($attrResult['attr'] as $attrArray) {
                        // 将每个子数组的 'value' 和 'detail' 组合成字符串
                        if (isset($attrArray['detail'][0]['value'])) {
                            $attrArray['detail'] = array_column($attrArray['detail'], 'value');
                        }
                        $detailString = implode(',', $attrArray['detail']); // 将 detail 数组转换为逗号分隔的字符串
                        $attrArr[] = $attrArray['value'] . '=' . $detailString;
                    }
                    $attrString = implode(';', $attrArr);
                    $one_data = [
                        'id' => intval($product_id),
                        'store_name' => $productInfo['store_name'],
                        'product_type' => $virtualType[$productInfo['product_type']],
                        'cate_name_one' => $cate_one,
                        'cate_name_two' => $cate_two,
                        'cate_name_three' => $cate_three,
                        'unit_name' => $productInfo['unit_name'],
                        'slider_image' => implode(';', $productInfo['slider_image']),
                        'video_link' => $productInfo['video_link'],
                        'description' => htmlspecialchars_decode(isset($descriptionArr[$productInfo['id']]) ?? $descriptionArr[$productInfo['id']]),
                        'ficti' => intval($productInfo['ficti']),
                        'spec_type' => intval($productInfo['spec_type']) == 1 ? '多规格' : '单规格',

                        'sku_type_value' => $attrString,
                        'sku_name' => implode(',', $value['detail']),
                        'sku_value' => implode(';', array_map(function ($key, $value) {
                            return "$key=$value";
                        }, array_keys($skuArr), $skuArr)),
                        'pic' => $value['pic'],

                        'price' => isset($value['price']) ? floatval($value['price']) : 0,
                        'ot_price' => isset($value['ot_price']) ? floatval($value['ot_price']) : 0,
                        'cost' => isset($value['cost']) ? floatval($value['cost']) : 0,
                        'stock' => isset($value['stock']) ? intval($value['stock']) : 0,
                        'weight' => isset($value['weight']) ? intval($value['weight'] ?? 0) : 0,
                        'volume' => isset($value['volume']) ? intval($value['volume'] ?? 0) : 0,

                        'code' => $value['code'] ?? '',
                        'bar_code' => $value['bar_code'] ?? '',
                        'store_info' => $productInfo['store_info'],
                        'keyword' => $productInfo['keyword'],
                        'command_word' => $productInfo['command_word'],
                    ];
                    if ($type == 1) {
                        $export[] = $one_data;
                        if ($i == 0) {
                            $filekey = array_keys($one_data);
                        }
                    } else {
                        $export[] = array_values($one_data);
                    }
                    $i++;
                }
            }
        }
        if ($type == 1) {
            return compact('header', 'filekey', 'export', 'filename');
        } else {
            return $this->export($header, $title, $export, $filename);
        }
    }

    /**
     * @param array $productList
     * @param int $type
     * @return array|mixed
     */
    public function supplierProductImport(array $productList, int $type = 1)
    {
        $header = [
            '商品编号', '商品名称', '商品类型', '商品分类(一级)', '商品分类(二级)', '商品分类(三级)', '商品单位',
            '商品图片', '商品视频', '商品详情', '已售数量', '规格类型', '规格类型值',
            '规格名称', '规格值组合', '规格图片', '结算价', '库存', '重量',
            '体积', '商品编码', '条形码', '商品简介', '商品关键字', '商品口令'
        ];
        $title = ['商品迁移', '商品迁移导出' . time(), '生成时间：' . date('Y-m-d H:i:s', time())];
        $filename = '商品迁移导出' . date('YmdHis', time());
        $export = [];
        $filekey = [];
        /** @var StoreProductCategoryServices $categoryService */
        $categoryService = app()->make(StoreProductCategoryServices::class);
        /** @var StoreProductCategoryServices $categoryService */
        $attrValueService = app()->make(StoreProductAttrValueServices::class);
        if (!empty($productList)) {
            $virtualType = [0 => '普通商品', 1 => '卡密/网盘', 2 => '优惠券', 3 => '虚拟商品', 4 => '次卡商品', 5 => '卡项商品', 6 => '预约商品'];
            $i = 0;
            $productIds = array_column($productList, 'id');
            $productList = array_column($productList, null, 'id');
            $attrResultArr = app()->make(StoreProductAttrResultServices::class)->getColumn([['product_id', 'in', $productIds], ['type', '=', 0]], 'result', 'product_id');
            $descriptionArr = app()->make(StoreDescriptionServices::class)->getColumn([['product_id', 'in', $productIds], ['type', '=', 0]], 'description', 'product_id');
            foreach ($attrResultArr as $product_id => $attrResult) {
                $attrResult = json_decode($attrResult, true);
                $productInfo = $productList[$product_id];
                $cate_one = $cate_two = $cate_three = '';
                if (isset($productInfo['cate_id'])) {
                    $cate_name = $categoryService->getCateParentAndChildName($productInfo['cate_id']);
                    foreach ($cate_name as $item => $value) {
                        switch ($value['level']) {
                            case 0:
                                if ($cate_one) {
                                    $cate_one = $cate_one . '/' . $value['two'];
                                } else {
                                    $cate_one = $value['two'];
                                }
                                break;
                            case 1:
                                if ($cate_two) {
                                    $cate_two = $cate_two . '/' . $value['two'];
                                } else {
                                    $cate_two = $value['two'];
                                }
                                break;
                            case 2:
                                if ($cate_three) {
                                    $cate_three = $cate_three . '/' . $value['two'];
                                } else {
                                    $cate_three = $value['two'];
                                }
                                break;
                        }
                    }
                }
                $attrValue = $productInfo['attrValue'];
                foreach ($attrResult['value'] as $k=>&$value) {
                    if (!isset($value['attr_arr'])) {
                        $attrResult['value'][$k]['attr_arr'] = array_values($value['detail']);
                        foreach ($value['detail'] as $detailKey => $detailValue) {
                            $attrResult['value'][$k][$detailKey] = $detailValue;
                        }
                    }
                    foreach ($attrValue as $attrKey => $attrItem) {
                        if (implode(',', $attrResult['value'][$k]['attr_arr']) == $attrKey) {
                            $attrResult['value'][$k]['unique'] = $attrItem['unique'] ?? '';
                            $attrResult['value'][$k]['price'] = $attrItem['price'] ?? 0;
                            $attrResult['value'][$k]['price_range_min'] = $attrItem['price_range_min'] ?? 0;
                            $attrResult['value'][$k]['price_range_max'] = $attrItem['price_range_max'] ?? 0;
                            $attrResult['value'][$k]['stock'] = $attrItem['stock'] ?? 0;
                            $attrResult['value'][$k]['cost'] = $attrItem['cost'] ?? 0;
                            $attrResult['value'][$k]['ot_price'] = $attrItem['ot_price'] ?? 0;
                        }
                    }
                    $skuArr = array_combine(array_column($attrResult['attr'], 'value'), $value['detail']);
                    $attrArr = [];
                    foreach ($attrResult['attr'] as $attrArray) {
                        // 将每个子数组的 'value' 和 'detail' 组合成字符串
                        if (isset($attrArray['detail'][0]['value'])) {
                            $attrArray['detail'] = array_column($attrArray['detail'], 'value');
                        }
                        $detailString = implode(',', $attrArray['detail']); // 将 detail 数组转换为逗号分隔的字符串
                        $attrArr[] = $attrArray['value'] . '=' . $detailString;
                    }
                    $attrString = implode(';', $attrArr);
                    $one_data = [
                        'id' => intval($product_id),
                        'store_name' => $productInfo['store_name'],
                        'product_type' => $virtualType[$productInfo['product_type']],
                        'cate_name_one' => $cate_one,
                        'cate_name_two' => $cate_two,
                        'cate_name_three' => $cate_three,
                        'unit_name' => $productInfo['unit_name'],
                        'slider_image' => implode(';', $productInfo['slider_image']),
                        'video_link' => $productInfo['video_link'],
                        'description' => htmlspecialchars_decode(isset($descriptionArr[$productInfo['id']]) ?? $descriptionArr[$productInfo['id']]),
                        'ficti' => intval($productInfo['ficti']),
                        'spec_type' => intval($productInfo['spec_type']) == 1 ? '多规格' : '单规格',

                        'sku_type_value' => $attrString,
                        'sku_name' => implode(',', $value['detail']),
                        'sku_value' => implode(';', array_map(function ($key, $value) {
                            return "$key=$value";
                        }, array_keys($skuArr), $skuArr)),
                        'pic' => $value['pic'],

                        'settle_price' => isset($value['settle_price']) ? floatval($value['settle_price']) : 0,
                        'stock' => isset($value['stock']) ? intval($value['stock']) : 0,
                        'weight' => isset($value['weight']) ? intval($value['weight'] ?? 0) : 0,
                        'volume' => isset($value['volume']) ? intval($value['volume'] ?? 0) : 0,
                        'code' => $value['code'] ?? '',
                        'bar_code' => $value['bar_code'] ?? '',
                        'store_info' => $productInfo['store_info'],
                        'keyword' => $productInfo['keyword'],
                        'command_word' => $productInfo['command_word'],
                    ];
                    if ($type == 1) {
                        $export[] = $one_data;
                        if ($i == 0) {
                            $filekey = array_keys($one_data);
                        }
                    } else {
                        $export[] = array_values($one_data);
                    }
                    $i++;
                }
            }
        }
        if ($type == 1) {
            return compact('header', 'filekey', 'export', 'filename');
        } else {
            return $this->export($header, $title, $export, $filename);
        }
    }

    /**
     * 用户导出
     * @param array $data
     * @param int $type
     * @return array|mixed
     * @author wuhaotian
     */
    public function user($data = [], $type = 1)
    {
        $header = ['openid', 'unionId', 'uid', '手机号', '用户昵称', '客户姓名', '性别', '生日', '用户等级', '经验值', '付费会员有效期', '客户积分', '客户余额', '消费现金金额', '消费次数', '到店次数', '客户标签', '用户分组', '用户来源', '地址'];
        $title = ['用户列表', '用户列表', date('Y-m-d H:i:s', time())];
        $filename = '用户列表_' . date('YmdHis', time());
        $export = [];
        $filekey = [];
        //获取uid的集合
        $uids = array_column($data, 'uid');
        //查询订单金额
//        $orderPrice = app()->make(StoreOrderServices::class)->getUserOrderSum($uids, 'pay_price');
        if (!empty($data)) {
            $i = 0;
            foreach ($data as $value) {
                if ($value['spread_open'] == 1) {
                    $spread_type = '分销员';
                } else {
                    $spread_type = '无';
                }
                $one_data = [
                    'openid' => '',
                    'unionId' => '',
                    'uid' => $value['uid'],
                    'phone' => $value['phone'],
                    'nickname' => $value['nickname'],
                    'real_name' => $value['real_name'],
                    'sex' => $value['sex'],
                    'birthday' => $value['birthday'],
                    'level' => $value['level'],
                    'exp' => $value['exp'],
                    'overdue_time' => $value['overdue_time'],
                    'integral' => $value['integral'],
                    'now_money' => $value['now_money'],
                    'cash_consume_amount' => $value['cash_consume_amount'] ?? '0.00',
                    'cash_consume_count' => $value['cash_consume_count'] ?? 0,
                    'writeoff_count' => $value['writeoff_count'] ?? 0,
                    'labels' => $value['labels'],
                    'group_id' => $value['group_id'],
                    'user_type' => $value['user_type'],
                    'addres' => $value['addres'],
                ];
                if ($type == 1) {
                    $export[] = $one_data;
                    if ($i == 0) {
                        $filekey = array_keys($one_data);
                    }
                } else {
                    $export[] = array_values($one_data);
                }
                $i++;
            }
        }
        if ($type == 1) {
            return compact('header', 'filekey', 'export', 'filename');
        } else {
            return $this->export($header, $title, $export, $filename);
        }
    }

    /**
     * 门店用户导出
     * @param array $data
     * @param int $type
     * @return array|mixed
     */
    public function storeUser($data = [], $type = 1)
    {
        $header = ['uid', '手机号', '用户昵称', '用户等级', '用户类型', '余额', '关联店员', '本金', '赠金', '消费现金金额', '消费次数', '到店次数', '客户标签'];
        $title = ['用户列表', '用户列表', date('Y-m-d H:i:s', time())];
        $filename = '用户列表_' . date('YmdHis', time());
        $export = [];
        $filekey = [];
        if (!empty($data)) {
            $i = 0;
            foreach ($data as $value) {
                $one_data = [
                    'uid' => $value['uid'],
                    'phone' => $value['phone'],
                    'nickname' => $value['nickname'],
                    'level' => $value['level'],
                    'user_type' => $value['user_type'],
                    'now_money' => $value['now_money'],
                    'staff_name' => $value['staff_name'] ?? '',
                    'ben_money' => $value['ben_money'] ?? '0.00',
                    'give_money' => $value['give_money'] ?? '0.00',
                    'cash_consume_amount' => $value['cash_consume_amount'] ?? '0.00',
                    'cash_consume_count' => $value['cash_consume_count'] ?? 0,
                    'writeoff_count' => $value['writeoff_count'] ?? 0,
                    'labels' => $value['labels'] ?? '',
                ];
                if ($type == 1) {
                    $export[] = $one_data;
                    if ($i == 0) {
                        $filekey = array_keys($one_data);
                    }
                } else {
                    $export[] = array_values($one_data);
                }
                $i++;
            }
        }
        if ($type == 1) {
            return compact('header', 'filekey', 'export', 'filename');
        } else {
            return $this->export($header, $title, $export, $filename);
        }
    }

    /**
     * 中奖记录导出
     * @param $data
     * @param $name
     * @param $type
     * @return array|mixed
     */
    public function luckLotteryRecordList($data = [], $name = '账单导出', $type = 1)
    {
        $header = ['ID', '用户信息', '活动名称', '活动类型', '奖品名称', '奖品详情', '领取状态', '收货信息', '备注'];
        $title = [$name, $name . time(), '生成时间：' . date('Y-m-d H:i:s', time())];
        $filename = $name . date('YmdHis', time());
        $export = [];
        $filekey = [];
        /** @var LuckLotteryServices $lotteryService */
        $lotteryService = app()->make(LuckLotteryServices::class);
        /** @var LuckLotteryRecordServices $lotteryRecordService */
        $lotteryRecordService = app()->make(LuckLotteryRecordServices::class);
        $lottery_factor = $lotteryService->lottery_factor;
        $prizeType = $lotteryRecordService->prize_type;
        if (!empty($data)) {
            $i = 0;
            foreach ($data as $key => $item) {
                if (in_array($item['prize']['type'], [5, 6])) {
                    $prize = $prizeType[$item['prize']['type']] . ':' . $item['prize']['prize_name'];
                } elseif (in_array($item['prize']['type'], [2, 3, 4, 7, 8, 9])) {
                    $prize = $prizeType[$item['prize']['type']] . ':' . $item['prize']['num'];
                } else {
                    $prize = '未中奖';
                }
                $one_data = [
                    'id' => $item['id'],
                    'nickname' => $item['user']['nickname'] . '(ID:' . $item['uid'] . ')',
                    'lottery_name' => $item['lottery']['name'],
                    'factor' => $lottery_factor[$item['lottery']['factor']],
                    'prize_name' => $item['prize']['name'],
                    'prize' => $prize,
                    'is_receive' => $item['is_receive'] ? '已领取' : '未领取',
                    'address' => $item['receive_info'],
                    'mark' => $item['deliver_info']['mark'] ?? '',
                ];
                if ($type == 1) {
                    $export[] = $one_data;
                    if ($i == 0) {
                        $filekey = array_keys($one_data);
                    }
                } else {
                    $export[] = array_values($one_data);
                }
                $i++;
            }
        }
        if ($type == 1) {
            return compact('header', 'filekey', 'export', 'filename');
        } else {
            return $this->export($header, $title, $export, $filename);
        }
    }

    /**
     * 商品出入库单导出
     * @param $data
     * @param $type
     * @return array|mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function productStockOrder(int $stockType = 0, $data = [], $type = 1)
    {
        switch ($stockType) {
            case 1://入库
                $header = ['ID', '入库单号', '入库类型', '入库日期', '操作员', '创建时间', '备注',
                    '商品ID', '商品名称', '商品规格', '商品编码', '商品条码', '良品入库数量', '残次品入库数量', '关联单号'];
                $title = ['商品入库单', '商品入库单导出' . time(), '生成时间：' . date('Y-m-d H:i:s', time())];
                $filename = '商品入库单导出_' . date('YmdHis', time());
                break;
            case 2://出库
                $header = ['ID', '出库单号', '出库类型', '出库日期', '操作员', '创建时间', '备注',
                    '商品ID', '商品名称', '商品规格', '商品编码', '商品条码', '良品出库数量', '残次品出库数量', '最终库存', '关联单号'];
                $title = ['商品出库单', '商品出库单导出' . time(), '生成时间：' . date('Y-m-d H:i:s', time())];
                $filename = '商品出库单导出_' . date('YmdHis', time());
                break;
            default://出入库
                break;
        }
        $export = [];
        $filekey = [];
        if (!empty($data)) {
            $i = 0;
            /** @var StoreProductStockOrderServices $stockInOrderServices */
            $stockInOrderServices = app()->make(StoreProductStockOrderServices::class);
            /** @var StoreProductStockDetailServices $stockDetailServices */
            $stockDetailServices = app()->make(StoreProductStockDetailServices::class);
            $inOrderType = $stockInOrderServices->inOrderType;
            $outOrderType = $stockInOrderServices->outOrderType;
            foreach ($data as $key => $item) {
                if ($item['stock_type'] == 1) {//入库
                    $orderType = $inOrderType;
                } else {
                    $orderType = $outOrderType;
                }
                $one_data = [
                    'id' => $item['id'],
                    'order_id' => $item['order_id'],
                    'order_type' => $orderType[$item['order_type']] ?? '',
                    'stock_time' => $item['stock_time'],
                    'admin_name' => $item['admin_name'] ?? '',
                    'add_time' => $item['add_time'],
                    'remark' => $item['remark'],
                ];
                //入库单详情
                $stockDetailList = $stockDetailServices->getList(['stock_type' => $stockType, 'order_id' => $item['id']]);
                if ($stockDetailList) {
                    foreach ($stockDetailList as $detail) {
                        $one_data['product_id'] = $detail['product_id'];
                        $one_data['product_name'] = $detail['product_name'];
                        $one_data['sku'] = $detail['sku'];
                        $one_data['code'] = $detail['code'];
                        $one_data['bar_code'] = $detail['bar_code'];
                        $one_data['stock'] = $detail['stock'];
                        $one_data['defective_stock'] = $detail['defective_stock'];
                        if ($stockType == 2) $one_data['balance_stock'] = $detail['balance_stock'];
                        $one_data['order_sn'] = $item['order_sn'] ?? '';
                        if ($type == 1) {
                            $export[] = $one_data;
                            if ($i == 0) {
                                $filekey = array_keys($one_data);
                            }
                        } else {
                            $export[] = array_values($one_data);
                        }
                        $i++;
                    }
                }
            }
        }
        if ($type == 1) {
            return compact('header', 'filekey', 'export', 'filename');
        } else {
            return $this->export($header, $title, $export, $filename);
        }
    }

    /**
     * 商品库存盘点导出
     * @param $data
     * @param $type
     * @return array|mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function productStockCount($data = [], $type = 1)
    {
        $header = ['ID', '盘点单号', '盘点状态', '操作员', '创建时间', '完成时间', '备注',
            '商品ID', '商品名称', '商品规格', '商品编码', '商品条码', '良品库存', '良品盘点数量', '良品盈亏数量', '残次品库存', '残次品盘点数量', '残次品盈亏数量'];
        $title = ['商品库存盘点', '商品库存盘点导出' . time(), '生成时间：' . date('Y-m-d H:i:s', time())];
        $filename = '商品库存盘点导出_' . date('YmdHis', time());
        $export = [];
        $filekey = [];
        if (!empty($data)) {
            $i = 0;
            /** @var StoreProductStockCountServices $stockCountServices */
            $stockCountServices = app()->make(StoreProductStockCountServices::class);
            /** @var StoreProductStockDetailServices $stockDetailServices */
            $stockDetailServices = app()->make(StoreProductStockDetailServices::class);
            $statusType = $stockCountServices->statusType;
            foreach ($data as $key => $item) {
                $one_data = [
                    'id' => $item['id'],
                    'order_id' => $item['order_id'],
                    'status' => $statusType[$item['status']] ?? '',
                    'admin_name' => $item['admin_name'] ?? '',
                    'add_time' => $item['add_time'],
                    'update_time' => $item['update_time'],
                    'remark' => $item['remark'],
                ];
                $stockDetailList = $stockDetailServices->getList(['stock_type' => 3, 'order_id' => $item['id']]);
                if ($stockDetailList) {
                    foreach ($stockDetailList as $detail) {
                        $one_data['product_id'] = $detail['product_id'];
                        $one_data['product_name'] = $detail['product_name'];
                        $one_data['sku'] = $detail['sku'];
                        $one_data['code'] = $detail['code'];
                        $one_data['bar_code'] = $detail['bar_code'];
                        $one_data['stock'] = $detail['stock'];
                        $one_data['count_stock'] = $detail['count_stock'];
                        $one_data['change_stock'] = $detail['count_stock'] > -1 ? bcsub((string)$detail['count_stock'], (string)$detail['stock'], 0) : 0;
                        $one_data['defective_stock'] = $detail['defective_stock'];
                        $one_data['count_defective_stock'] = $detail['count_defective_stock'];
                        $one_data['change_defective_stock'] = $detail['count_defective_stock'] > -1 ? bcsub((string)$detail['count_defective_stock'], (string)$detail['defective_stock'], 0) : 0;
                        if ($type == 1) {
                            $export[] = $one_data;
                            if ($i == 0) {
                                $filekey = array_keys($one_data);
                            }
                        } else {
                            $export[] = array_values($one_data);
                        }
                        $i++;
                    }
                }
            }
        }
        if ($type == 1) {
            return compact('header', 'filekey', 'export', 'filename');
        } else {
            return $this->export($header, $title, $export, $filename);
        }
    }

    /**
     * 库存明细导出
     * @param $data
     * @param $type
     * @return array|mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function productStockDetail($data = [], $type = 1)
    {
        $header = ['商品ID', '商品名称', '商品规格', '商品编码', '商品条码',
            '单据编号', '变更类型', '良品出入库数量', '残次品出入库数量', '业务时间', '操作员', '创建时间'];
        $title = ['商品库存明细', '商品库存明细导出' . time(), '生成时间：' . date('Y-m-d H:i:s', time())];
        $filename = '商品库存明细导出_' . date('YmdHis', time());
        $export = [];
        $filekey = [];
        if (!empty($data)) {
            $i = 0;
            /** @var StoreProductStockOrderServices $stockInOrderServices */
            $stockInOrderServices = app()->make(StoreProductStockOrderServices::class);
            /** @var StoreProductStockDetailServices $stockDetailServices */
            $stockDetailServices = app()->make(StoreProductStockDetailServices::class);
            $inOrderType = $stockInOrderServices->inOrderType;
            $outOrderType = $stockInOrderServices->outOrderType;
            foreach ($data as $key => $item) {
                $one_data = [];
                $stockDetailList = $stockDetailServices->getList(['stock_type' => [1, 2], 'order_id' => $item['id']]);
                if ($stockDetailList) {
                    if ($item['stock_type'] == 1) {//入库
                        $orderType = $inOrderType;
                    } else {
                        $orderType = $outOrderType;
                    }
                    foreach ($stockDetailList as $detail) {
                        $one_data['product_id'] = $detail['product_id'];
                        $one_data['product_name'] = $detail['product_name'];
                        $one_data['sku'] = $detail['sku'];
                        $one_data['code'] = $detail['code'];
                        $one_data['bar_code'] = $detail['bar_code'];
                        $one_data['order_id'] = $item['order_id'];
                        $one_data['order_type'] = $orderType[$item['order_type']] ?? '';
                        $one_data['stock'] = $detail['stock'];
                        $one_data['defective_stock'] = $detail['defective_stock'];
                        $one_data['stock_time'] = $item['stock_time'];
                        $one_data['admin_name'] = $item['admin_name'] ?? '';
                        $one_data['add_time'] = $item['add_time'];
                        if ($type == 1) {
                            $export[] = $one_data;
                            if ($i == 0) {
                                $filekey = array_keys($one_data);
                            }
                        } else {
                            $export[] = array_values($one_data);
                        }
                        $i++;
                    }
                }
            }
        }
        if ($type == 1) {
            return compact('header', 'filekey', 'export', 'filename');
        } else {
            return $this->export($header, $title, $export, $filename);
        }
    }


    /**
     * 商品库存盘点导出
     * @param int $stockType
     * @param $data
     * @param $exportType
     * @return array|mixed
     */
    public function productStockOrderStatistics(int $stockType = 1, $data = [], $exportType = 1)
    {
        switch ($stockType) {
            case 1://入库
                $header = ['商品ID', '商品名称', '商品规格', '商品编码', '商品条码',
                    '总入库数量', '采购入库数量', '其他入库是数量', '退货入库数量', '残次品转良数量', '盘点入库数量'];
                $title = ['商品入库统计', '商品入库统计导出' . time(), '生成时间：' . date('Y-m-d H:i:s', time())];
                $filename = '商品入库统计导出_' . date('YmdHis', time());
                break;
            case 2://出库
                $header = ['商品ID', '商品名称', '商品规格', '商品编码', '商品条码',
                    '总出库数量', '销售出库数量', '过期退货数量', '试用出库数量', '报废出库数量', '良品转残次品数量', '其他出库数量', '盘亏出库数量'];
                $title = ['商品出库统计', '商品出库统计导出' . time(), '生成时间：' . date('Y-m-d H:i:s', time())];
                $filename = '商品出库统计导出_' . date('YmdHis', time());
                break;
            default:
                $header = ['商品ID', '商品名称', '商品规格', '商品编码', '商品条码',
                    '总出入库数量', '采购入库数量', '退货入库数量', '其他入库是数量', '残次品转良数量', '盘点入库数量',
                    '销售出库数量', '过期退货数量', '试用出库数量', '报废出库数量', '良品转残次品数量', '其他出库数量', '盘亏出库数量'];
                $title = ['商品出入库统计', '商品出入库统计导出' . time(), '生成时间：' . date('Y-m-d H:i:s', time())];
                $filename = '商品出入库统计导出_' . date('YmdHis', time());
                break;
        }
        $export = [];
        $filekey = [];
        if (!empty($data)) {
            $i = 0;
            /** @var StoreProductStockDetailServices $stockDetailServices */
            $stockDetailServices = app()->make(StoreProductStockDetailServices::class);
            // 定义入库和出库类型
            $inStockTypes = $stockDetailServices->inStockTypes;
            $outStockTypes = $stockDetailServices->outStockTypes;
            foreach ($data as $key => $item) {
                $one_data['product_id'] = $item['product_id'];
                $one_data['product_name'] = $item['product_name'];
                $one_data['sku'] = $item['sku'];
                $one_data['code'] = $item['code'];
                $one_data['bar_code'] = $item['bar_code'];
                $one_data['total_stock'] = $item['total_stock'];
                // 根据查询类型添加对应的统计字段
                if ($stockType == 1) {
                    // 入库统计
                    foreach ($inStockTypes as $type) {
                        $key = $type . '_stock';
                        $one_data[$key] = $item[$key] ?? 0;
                    }
                } elseif ($stockType == 2) {
                    // 出库统计
                    foreach ($outStockTypes as $type) {
                        $key = $type . '_stock';
                        $one_data[$key] = $item[$key] ?? 0;
                    }
                } else {
                    // 如果没有指定类型，统计所有类型
                    // 入库统计
                    foreach ($inStockTypes as $type) {
                        $key = $type . '_stock';
                        $one_data[$key] = $item[$key] ?? 0;
                    }
                    foreach ($outStockTypes as $type) {
                        $key = $type . '_stock';
                        $one_data[$key] = $item[$key] ?? 0;
                    }
                }
                if ($exportType == 1) {
                    $export[] = $one_data;
                    if ($i == 0) {
                        $filekey = array_keys($one_data);
                    }
                } else {
                    $export[] = array_values($one_data);
                }
                $i++;
            }
        }
        if ($exportType == 1) {
            return compact('header', 'filekey', 'export', 'filename');
        } else {
            return $this->export($header, $title, $export, $filename);
        }
    }

    /**
     * 配送数据统计导出
     * @param $data
     * @param $name
     * @param $type
     * @return array|mixed
     */
    public function deliveryStatistics(array $data = [], string $name = '配送数据导出', int $type = 1)
    {
        $header = ['订单号', '用户信息', '订单实际支付', '配送员信息', '配送员电话', '配送费', '配送费实际支付', '支付方式', '送达时间'];
        $title = [$name, $name . time(), '生成时间：' . date('Y-m-d H:i:s', time())];
        $filename = $name . date('YmdHis', time());
        $export = [];
        $filekey = [];
        if (!empty($data)) {
            $i = 0;
            foreach ($data as $key => $item) {
                $one_data = [
                    'order_id' => $item['order_id'],
                    'nickname' => $item['nickname'],
                    'pay_price' => $item['pay_price'],
                    'delivery_name' => $item['delivery_name'] . '|' . $item['delivery_uid'],
                    'delivery_id' => $item['delivery_id'],
                    'total_postage' => $item['total_postage'],
                    'pay_postage' => $item['pay_postage'],
                    'pay_type_name' => $item['pay_type_name'],
                    'delivery_time' => date('Y-m-d H:i:s', $item['delivery_time'])
                ];
                if ($type == 1) {
                    $export[] = $one_data;
                    if ($i == 0) {
                        $filekey = array_keys($one_data);
                    }
                } else {
                    $export[] = array_values($one_data);
                }
                $i++;
            }
        }
        if ($type == 1) {
            return compact('header', 'filekey', 'export', 'filename');
        } else {
            return $this->export($header, $title, $export, $filename);
        }
    }
}
