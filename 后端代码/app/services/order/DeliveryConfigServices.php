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

namespace app\services\order;


use app\dao\order\StoreDeliveryOrderDao;
use app\dao\order\DeliveryConfigDao;
use app\jobs\order\OrderStatusJob;
use app\jobs\order\OrderTakeJob;
use app\jobs\store\StoreFinanceJob;
use app\services\BaseServices;
use app\services\other\CityAreaServices;
use app\services\store\SystemStoreServices;
use app\services\supplier\SystemSupplierServices;
use mohe\services\DeliverySevices;
use mohe\services\FormBuilder as Form;
use mohe\services\SystemConfigService;
use mohe\traits\OptionTrait;
use think\exception\ValidateException;
use think\facade\Log;
use think\facade\Route as Url;

/**
 * 门店同城配送配置
 * Class DeliveryConfigServices
 * @package app\services\order
 * @mixin DeliveryConfigDao
 */
class DeliveryConfigServices extends BaseServices
{
    use OptionTrait;

    /**
     * 构造方法
     * DeliveryConfigServices constructor.
     * @param DeliveryConfigDao $dao
     */
    public function __construct(DeliveryConfigDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 获取距离
     *
     * @param float $merLat
     * @param float $merLong
     * @param array|string $address
     * @return float
     */
    public function getAddressDistance(float $merLat, float $merLong, $address): float
    {
        if (is_string($address)) {
            $addressDetail = $address;
        } else {
            if (isset($address['addressInfo']) && $address['addressInfo']) {
                $addressDetail = $address['addressInfo'];
            } else {
                $addressDetail = ($address['province'] ?? '') . ($address['city'] ?? '') . ($address['district'] ?? '') . ($address['street'] ?? '') . ($address['detail'] ?? '');
            }
        }
        $addressLatAndLong = lbs_address($addressDetail);
        if (!$addressLatAndLong || !$merLat || !$merLong) {
            return false;
        }
        // 根据经纬度计算距离
        return getDistance($addressLatAndLong['location']['lat'] ?? '', $addressLatAndLong['location']['lng'] ?? '', $merLat, $merLong);
    }

    /**
     * 距离溢价计算
     * @param int $store_id
     * @param array $config
     * @param $address
     * @return float
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function distanceStackFee(int $store_id, array $config, $address): float
    {
        $fee = 0;
        if (!$config) {
            return $fee;
        }
        /** @var SystemStoreServices $StoreServices */
        $StoreServices = app()->make(SystemStoreServices::class);
        $store = $StoreServices->get($store_id);
        if (isset($address['addressInfo']) && $address['addressInfo']) {
            $address = $address['addressInfo'];
        }
        // 配送距离
        $distance = $this->getAddressDistance($store['latitude'], $store['longitude'], $address);
        // 小于第一等级距离
        if (isset($config['level_first']['lt_distance']) && $distance <= $config['level_first']['lt_distance']) {
            return $fee;
        }
        // 阶梯范围
        if (isset($config['level_stairs']) && !empty($config['level_stairs'])) {
            foreach ($config['level_stairs'] as $level) {
                if (!isset($level['start_distance']) || !isset($level['end_distance'])) continue;
                if ($distance >= $level['end_distance']) {
                    $exceedDistance = bcsub($level['end_distance'], $level['start_distance'], 2);
                    $fee += (float)$level['add_distance'] > 0 ? bcmul(ceil(bcdiv($exceedDistance, $level['add_distance'], 2)), $level['add_amount']) : 0;
                }
                if ($distance > $level['start_distance'] && $distance <= $level['end_distance']) {
                    $exceedDistance = bcsub($distance, $level['start_distance'], 2);
                    $fee += (float)$level['add_distance'] > 0 ? bcmul(ceil(bcdiv($exceedDistance, $level['add_distance'], 2)), $level['add_amount']) : 0;
                }
            }
        }
        // 最后等级距离
        if (isset($config['level_last']['gt_distance']) && $distance > $config['level_last']['gt_distance']) {
            $exceedDistance = bcsub($distance, $config['level_last']['gt_distance'], 2);
            $fee += (float)$config['level_last']['add_distance'] > 0 ? bcmul(ceil(bcdiv($exceedDistance, $config['level_last']['add_distance'], 2)), $config['level_last']['add_amount']) : 0;
        }

        return $fee;
    }

    /**
     * 重量溢价计算
     *
     * @param float $totalWeight
     * @param array $config
     * @return float
     */
    public function weightStackFee(float $totalWeight, array $config): float
    {
        $fee = 0;
        if (!$config) {
            return $fee;
        }
        // 小于第一等级重量
        if (isset($config['level_first']['lt_weight']) && $totalWeight <= $config['level_first']['lt_weight']) {
            return $fee;
        }
        // 阶梯范围
        if (isset($config['level_stairs']) && !empty($config['level_stairs'])) {
            foreach ($config['level_stairs'] as $level) {
                if (!isset($level['start_weight']) || !isset($level['end_weight'])) continue;
                if ($totalWeight >= $level['end_weight']) {
                    $exceedWeight = bcsub($level['end_weight'], $level['start_weight'], 2);
                    $fee += (float)$level['add_weight'] > 0 ? bcmul(ceil(bcdiv($exceedWeight, $level['add_weight'], 2)), $level['add_amount']) : 0;
                }
                if ($totalWeight > $level['start_weight'] && $totalWeight <= $level['end_weight']) {
                    $exceedWeight = bcsub($totalWeight, $level['start_weight'], 2);
                    $fee += (float)$level['add_weight'] > 0 ? bcmul(ceil(bcdiv($exceedWeight, $level['add_weight'], 2)), $level['add_amount']) : 0;
                }
            }
        }
        // 最后等级重量
        if (isset($config['level_last']['gt_weight']) && $totalWeight > $config['level_last']['gt_weight']) {
            $exceedWeight = bcsub($totalWeight, $config['level_last']['gt_weight'], 2);
            $fee += (float)$config['level_last']['add_weight'] > 0 ? bcmul(ceil(bcdiv($exceedWeight, $config['level_last']['add_weight'], 2)), $config['level_last']['add_amount']) : 0;
        }

        return $fee;
    }

    /**
     * 检查一个点是否在多边形内部
     * 使用射线法判断点是否在多边形内
     * 从该点向右发射一条射线，计算与多边形边的交点数
     * 如果交点数为奇数，则点在多边形内部；如果为偶数，则在外部
     *
     * @param array $fence 围栏数据,包含多边形的顶点坐标
     * @param array $addressLatAndLong 需要判断的点的坐标
     * @return bool 返回点是否在多边形内
     */
    public function checkInPolygon(array $fence, array $addressLatAndLong): bool
    {
        // 获取多边形的所有顶点
        $paths = $fence['data']['paths'];
        // 获取需要判断的点的坐标
        $lat = $addressLatAndLong['location']['lat'] ?? '';
        $lng = $addressLatAndLong['location']['lng'] ?? '';
        // 初始化判断结果
        $inside = false;
        // 遍历多边形的所有边
        $j = count($paths) - 1;
        foreach ($paths as $i => $path) {
            // 获取当前点的纬度和经度
            $xi = $path['lat'];  // 当前点的纬度
            $yi = $path['lng'];  // 当前点的经度
            // 获取上一个点的纬度和经度
            $xj = $paths[$j]['lat'];  // 上一个点的纬度
            $yj = $paths[$j]['lng'];  // 上一个点的经度
            // 判断射线是否与当前边相交
            // 1: 边的两个端点一个在射线上方一个在下方; 2: 点的水平坐标小于边在该点纵坐标处的水平坐标
            $intersect = (($yi > $lng) != ($yj > $lng)) &&
                ($lat < (bcsub($xj, $xi, 8)) * bcdiv(bcsub($lng, $yi, 8), bcsub($yj, $yi, 8), 8) + $xi);
            // 如果相交,则改变内外性
            if ($intersect) {
                $inside = !$inside;
            }
            $j = $i;
        }

        return $inside;
    }

    /**
     * 检查一个点是否在圆形配送范围内
     * 使用欧几里得距离公式计算点到圆心的距离，判断是否在圆的半径范围内
     *
     * @param array $fence 圆形围栏数据,包含半径和圆心坐标
     * @param array $addressLatAndLong 需要判断的点的坐标
     * @return bool 返回点是否在圆内
     */
    public function checkInCircle(array $fence, array $addressLatAndLong): bool
    {
        // 获取圆的半径(米)
        $radius = $fence['data']['radius'];
        // 获取圆心坐标
        $center = $fence['data']['center'];

        // 获取需要判断的点的坐标
        $lat = $addressLatAndLong['location']['lat'] ?? '';
        $lng = $addressLatAndLong['location']['lng'] ?? '';
        // 使用getDistance方法计算两点之间的实际距离(米)
        $distance = getDistance(
                $lat,
                $lng,
                $center['lat'],
                $center['lng']
            ) * 1000;
        // 如果实际距离小于等于半径,则点在圆内
        return $distance <= $radius;
    }

    /**
     * 检查一个点是否在矩形配送范围内
     * 通过判断点的经纬度是否在矩形的边界范围内来确定
     *
     * @param array $fence 矩形围栏数据,包含矩形的最小最大经纬度边界
     * @param array $addressLatAndLong 需要判断的点的坐标
     * @return bool 返回点是否在矩形内
     */
    public function checkInRectangle(array $fence, array $addressLatAndLong): bool
    {
        // 获取矩形的中心点和宽高数据
        $center = $fence['data']['center'];
        $width = $fence['data']['width'];
        $height = $fence['data']['height'];
        // 获取需要判断的点的纬度和经度
        $lat = $addressLatAndLong['location']['lat'] ?? '';
        $lng = $addressLatAndLong['location']['lng'] ?? '';
        // 将米转换为经纬度差
        // 纬度1度约等于111000米，经度1度约等于111000*cos(纬度)米
        $latPerMeter = 1 / 111000;  // 每米对应的纬度差
        $lngPerMeter = 1 / (111000 * cos(deg2rad((float)$center['lat']))); // 每米对应的经度差
        // 计算矩形的边界范围
        $halfWidthDegree = bcdiv($width, 2, 8) * $lngPerMeter;
        $halfHeightDegree = bcdiv($height, 2, 8) * $latPerMeter;
        $minLat = bcsub($center['lat'], $halfHeightDegree, 8);
        $maxLat = bcadd($center['lat'], $halfHeightDegree, 8);
        $minLng = bcsub($center['lng'], $halfWidthDegree, 8);
        $maxLng = bcadd($center['lng'], $halfWidthDegree, 8);
        // 判断点的坐标是否在矩形的边界范围内
        return $lat >= $minLat &&
            $lat <= $maxLat &&
            $lng >= $minLng &&
            $lng <= $maxLng;
    }

    /**
     * 检查一个点是否在椭圆配送范围内
     * 使用椭圆标准方程 (x-x0)²/a² + (y-y0)²/b² = 1 判断点是否在椭圆内
     * 其中(x0,y0)为椭圆中心点坐标，a和b分别为椭圆的长半轴和短半轴
     *
     * @param array $fence 椭圆围栏数据,包含椭圆的中心点坐标和长短半轴长度
     * @param array $addressLatAndLong 需要判断的点的坐标
     * @return bool 返回点是否在椭圆内
     */
    public function checkInEllipse(array $fence, array $addressLatAndLong): bool
    {
        // 获取椭圆的参数数据
        $ellipse = $fence['data'];
        // 获取需要判断的点的纬度和经度
        $lat = $addressLatAndLong['location']['lat'] ?? '';
        $lng = $addressLatAndLong['location']['lng'] ?? '';
        // 获取椭圆中心点的坐标
        $x0 = $ellipse['center']['lat'];
        $y0 = $ellipse['center']['lng'];
        // 计算经度每米对应的度数(与纬度有关)
        $lngPerMeter = 1 / (111000 * cos(deg2rad((float)$x0)));
        // 获取椭圆的长半轴和短半轴(单位:米转换为度)
        $latRadius = bcdiv($ellipse['minorRadius'], 111000, 8); // 纬度方向半径(短半轴)
        $lngRadius = $lngPerMeter * $ellipse['majorRadius']; // 经度方向半径(长半轴)
        // 使用椭圆方程判断点是否在椭圆内
        // 如果 (x-x0)²/a² + (y-y0)²/b² <= 1，则点在椭圆内或椭圆上
        return bcadd(bcpow(bcdiv(bcsub($lat, $x0, 8), $latRadius, 8), 2, 8), bcpow(bcdiv(bcsub($lng, $y0, 8), $lngRadius, 8), 2, 8), 8) <= 1;
    }

    /**
     * 配送半径验证
     *
     * @param array $address
     * @param array $deliveryStation
     * @return boolean
     */
    public function checkRadius(array $address, array $deliveryStation): bool
    {
        // 根据经纬度计算距离
        $distance = $this->getAddressDistance($deliveryStation['latitude'], $deliveryStation['longitude'], $address);
        // 配送半径验证
        if ($deliveryStation['radius'] < $distance) {
            return false;
        }

        return true;
    }

    /**
     * 配送区域验证
     *
     * @param array $address
     * @param array $deliveryStation
     * @return boolean
     */
    public function checkRegion(array $address, array $deliveryStation): bool
    {
        $isRegion = false;
        $addresString = $address['province_id'] . ',' . $address['city_id'] . ',' . $address['district_id'];
        foreach ($deliveryStation['region'] as $region) {
            $regionString = implode(',', $region);
            if (strpos($addresString, $regionString) !== false) {
                $isRegion = true;
                break;
            }
        }

        return $isRegion;
    }

    /**
     * 配送围栏验证
     *
     * @param array $address
     * @param array $deliveryStation
     * @return boolean
     */
    public function checkFence(array $address, array $deliveryStation): bool
    {
        $isFence = false;
        $addressDetail = $address['province'] . $address['city'] . $address['district'] . $address['street'] . $address['detail'];
        $addressLatAndLong = lbs_address($addressDetail);
        foreach ($deliveryStation['fence'] as $fence) {
            $method = 'checkIn' . ucfirst($fence['type']);
            if (method_exists($this, $method)) {
                if ($this->$method($fence, $addressLatAndLong)) {
                    $isFence = true;
                    break;
                }
            }
        }

        return $isFence;
    }
}
