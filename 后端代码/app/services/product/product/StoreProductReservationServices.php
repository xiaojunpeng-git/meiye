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
namespace app\services\product\product;

use app\dao\product\product\StoreProductDao;
use app\services\BaseServices;
use app\services\order\StoreReservationOrderServices;
use app\services\product\sku\StoreProductAttrServices;
use app\services\product\sku\StoreProductAttrValueServices;
use app\services\product\sku\StoreProductReservationTimeServices;
use app\services\store\SystemStoreServices;
use mohe\exceptions\AdminException;
use mohe\traits\ServicesTrait;
use mohe\traits\OptionTrait;
use think\exception\ValidateException;

/**
 * 预售商品处理
 * Class StoreProductReservationServices
 * @package app\services\product\product
 * @mixin StoreProductDao
 */
class StoreProductReservationServices extends BaseServices
{
    use OptionTrait, ServicesTrait;

    /** 项目服务时长默认值（分钟） */
    public const DEFAULT_PROJECT_SERVICE_DURATION = 60;

    /** 加项服务时长默认值（分钟） */
    public const DEFAULT_ADDON_SERVICE_DURATION = 30;

    /**
     * StoreProductServices constructor.
     * @param StoreProductDao $dao
     */
    public function __construct(StoreProductDao $dao)
    {
        $this->dao = $dao;
    }

	/**
	 * 	验证商品预约相关数据
	 * @param array $data
	 * @return array
	 */
	public function validateData(array $data)
	{
		switch ($data['sale_time_type']) {
			case 1://每天
				break;
			case 2://每周
				if (!$data['sale_time_week']) {
					throw new AdminException('请选择每（周几）销售');
				}
				break;
			case 3://自定义时间
				if (!$data['sale_time_data'] || !is_array($data['sale_time_data']) || count($data['sale_time_data']) != 2) {
					throw new AdminException('请选择自定义销售时间端');
				}
				[$sale_time_start, $sale_time_end] = $data['sale_time_data'];
				$data['sale_time_start'] = strtotime($sale_time_start);
				$data['sale_time_end'] = strtotime($sale_time_end);
				break;
			default:
				throw new AdminException('请选择有效的销售日期类型');
				break;
		}
		if ($data['show_reservation_days_type'] == 2 && !$data['show_reservation_days']) {
			throw new AdminException('请选择显示多少天内可预约日期（天）');
		}
		if ($data['is_advance'] && !$data['advance_time']) {
			throw new AdminException('请设置需要提前多少小时预约（小时）');
		}
		if ($data['is_cancel_reservation'] && !$data['cancel_reservation_time']) {
			throw new AdminException('请设置服务开始前多少小时允许取消（小时）');
		}
		$data['project_service_duration'] = max(0, (int)($data['project_service_duration'] ?? 0));
		$data['addon_service_duration'] = max(0, (int)($data['addon_service_duration'] ?? 0));
		return $data;
	}

	/**
	 * 门店商品服务时长为空时，回退读取平台母商品配置，再应用默认值
	 */
	public function fillServiceDuration(array $productInfo): array
	{
		$project = (int)($productInfo['project_service_duration'] ?? 0);
		$addon = (int)($productInfo['addon_service_duration'] ?? 0);
		if ($project <= 0 || $addon <= 0) {
			$pid = (int)($productInfo['pid'] ?? 0);
			if ($pid > 0) {
				/** @var StoreProductServices $productServices */
				$productServices = app()->make(StoreProductServices::class);
				$parent = $productServices->getOne(['id' => $pid], 'project_service_duration,addon_service_duration');
				if ($parent) {
					$parent = $parent->toArray();
					if ($project <= 0) {
						$project = (int)($parent['project_service_duration'] ?? 0);
					}
					if ($addon <= 0) {
						$addon = (int)($parent['addon_service_duration'] ?? 0);
					}
				}
			}
		}
		$productInfo['project_service_duration'] = $project > 0 ? $project : self::DEFAULT_PROJECT_SERVICE_DURATION;
		$productInfo['addon_service_duration'] = $addon > 0 ? $addon : self::DEFAULT_ADDON_SERVICE_DURATION;
		return $productInfo;
	}

	/**
	 * 获取商品详情
	 * @param int $id
	 * @param int $store_id
	 * @param string $field
	 * @return array
	 */
	public function getProductInfo(int $id, int $store_id = 0, string $field = 'id,pid,type,product_type,spec_type,image,store_name,reservation_type,reservation_timing_type,sale_time_type,sale_time_week,sale_time_start,sale_time_end,show_reservation_days_type,show_reservation_days,is_advance,advance_time,is_cancel_reservation,cancel_reservation_time,reservation_time_type,customize_time_period,reservation_time_start,reservation_time_end,reservation_time_interval,is_show_stock,project_service_duration,addon_service_duration')
	{
		/** @var StoreProductServices $productServices */
		$productServices = app()->make(StoreProductServices::class);
		$productInfo = $productServices->getOne(['id' => $id], $field);
		if (!$productInfo) {
			throw new ValidateException('商品不存在');
		}
		if ($productInfo['type'] == 0 && $store_id) {//平台商品 取当前同步到该门店商品
			$productInfo = $productServices->getOne(['pid' => $id, 'type' => 1,'relation_id' => $store_id], $field);
			if (!$productInfo) {
				throw new ValidateException('商品不存在');
			}
		}
		$productInfo = $this->fillServiceDuration($productInfo->toArray());
		return [$productInfo['id'], $productInfo];
	}

	/**
	 * 获取预约商品详情
	 * @param int $uid
	 * @param int $id
	 * @param int $store_id
	 * @param string $unique
	 * @return array
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function getReservationProductInfo(int $uid, int $id, int $store_id, string $unique = '')
	{
		$result = [];
		[$id, $productInfo] = $this->getProductInfo($id, $store_id);
		$result['productInfo'] = $productInfo;
		$storeInfo = [];
		if ($store_id) {
			/** @var SystemStoreServices $storeServices */
			$storeServices = app()->make(SystemStoreServices::class);
			$storeInfo = $storeServices->get($store_id, ['id', 'name', 'phone']);
		}
		if (!$storeInfo) {
			throw new ValidateException('请重新选择门店');
		}
		$result['storeInfo'] = $storeInfo;
		/** @var StoreProductAttrValueServices $storeProductAttrValueServices */
		$storeProductAttrValueServices = app()->make(StoreProductAttrValueServices::class);
		if (!$productInfo['spec_type']) {//单规格
			$productAttr = [];
			$productValue = [];
			$unique = $unique ?: $storeProductAttrValueServices->value(['product_id' => $id, 'type' => 0], 'unique');
		} else {
			/** @var StoreProductAttrServices $storeProductAttrServices */
			$storeProductAttrServices = app()->make(StoreProductAttrServices::class);
			[$productAttr, $productValue] = $storeProductAttrServices->getProductAttrDetailCache($id, $uid);
			$unique = $unique ?: $storeProductAttrValueServices->value(['product_id' => $id, 'type' => 0, 'is_default_select' => 1], 'unique');
		}
		$selectAttrValue = [];
		if ($unique) {
			//原商品sku
			$selectAttrValue = $storeProductAttrValueServices->get(['unique' => $unique, 'type' => 0], ['id', 'suk', 'unique', 'product_id']);
			if ($selectAttrValue && $selectAttrValue['product_id'] != $id) {
				//门店商品sku unique
				$selectAttrValue = $storeProductAttrValueServices->get(['suk' => $selectAttrValue['suk'], 'product_id' => $id, 'type' => 0], ['id', 'suk', 'unique', 'product_id']);
			}
		}
		if ($selectAttrValue) $unique = $selectAttrValue['unique'];
		$result['productAttr'] = $productAttr;
		$result['productValue'] = $productValue;
		$result['selectAttrValue'] = $selectAttrValue ?? [];
		//当月可选日期
		$defaultDate = $this->getReservationProductDate($id, $store_id, '', $productInfo);
		//默认可选日期
		$result['reservationDefaultDate'] = $defaultDate[0]['date'] ?? date('Y-m-d');
		$result['reservationTimeData'] = $this->getReservationProductTimeStock($id, $unique ?? '', $result['reservationDefaultDate'], $productInfo);
		return $result;
	}

	/**
	 * 获取预约商品可预约日期时间
	 * @param int $id
	 * @param int $store_id
	 * @param string $month
	 * @param array $productInfo
	 * @return array
	 */
	public function getReservationProductDate(int $id, int $store_id, string $month = '', array $productInfo = [])
	{
		if (!$productInfo) {
			[$id, $productInfo] = $this->getProductInfo($id, $store_id);
		}
		$thisMonth = date('Y-m');
		if (!$month) {
			$month = $thisMonth;
		}
		$today = date('Y-m-d');
		$monthStart = date('Y-m-01', strtotime($month));
		$monthEnd = date('Y-m-d', strtotime($monthStart . ' +1 month, -1 day'));
		if (strtotime($month) == strtotime($thisMonth)) {//当前月，展示今天之后日期
			$monthStart = $today;
		}
		$start = strtotime($monthStart);
		$end = strtotime($monthEnd);
		//当月多少天
		$dayCount = ($end - $start) / 86400 + 1;
		//显示可预约日期类型1：全部展示，2：自定义展示时间'
		$showReservationDaysType = $productInfo['show_reservation_days_type'];
		$showStart = $showEnd = 0;
		if ($showReservationDaysType == 2) {
			//显示多少天内可预约日期（天）
			$showReservationDays = $productInfo['show_reservation_days'];
			$showStart = strtotime(date('Y-m-d'));
			$showEnd = strtotime("+". $showReservationDays ." day", $showStart);
		}
		//可售日期类型1：每天，2:每周，3：自定义时间
		$saleTimeType = $productInfo['sale_time_type'];
		//循环日期
		$result = [];
		$s_start = $start;
		for ($i = 0; $i < $dayCount; $i++) {
			//超出展示时间
			if ($showReservationDaysType == 2 && ($s_start < $showStart || $s_start > $showEnd)) {
				break;
			}
			switch ($saleTimeType) {
				case 1://每天
					$result[] = ['date' => date('Y-m-d', $s_start), 'day' => (int)date('d', $s_start)];
					break;
				case 2://每周
					$salesWeeks = $productInfo['sale_time_week'];
					$salesWeeks = $salesWeeks ? (is_string($salesWeeks) ? explode(',', $salesWeeks) : $salesWeeks) : [];
					$week = date('w', $s_start);
					if ($salesWeeks && is_array($salesWeeks) && in_array($week, $salesWeeks)) {
						$result[] = ['date' => date('Y-m-d', $s_start), 'day' => (int)date('d', $s_start)];
					}
					break;
				case 3://自定义时间
					$saleTimeStart = $productInfo['sale_time_start'];
					$saleTimeEnd = $productInfo['sale_time_end'];
					if ($s_start >= $saleTimeStart && $s_start <= $saleTimeEnd) {
						$result[] = ['date' => date('Y-m-d', $s_start), 'day' => (int)date('d', $s_start)];
					}
					break;
				default://默认每天
					$result[] = ['date' => date('Y-m-d', $s_start), 'day' => (int)date('d', $s_start)];
					break;
			}
			$s_start = strtotime("+1 day", $s_start);
		}
		return $result;
	}


	/**
	 * 获取预约商品时段库存数据
	 * @param int $id
	 * @param string $unique
	 * @param string $date
	 * @return array
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function getReservationProductTimeStock(int $id, string $unique, string $date = '', array $productInfo = []): array
	{
		$result = [];
		if (!$productInfo) {
			[$id, $productInfo] = $this->getProductInfo($id);
		}
		$today = date('Y-m-d');
		if (!$date) {//默认今天
			$date = $today;
		}
		$now = date('Hi');
		//是否需要提前预约
		$isAdvance = $productInfo['is_advance'] ?? 0;
		$advanceTime = (int)$productInfo['advance_time'] ?? 0;
		//提前预约天数
		$advanceDate = 0;
		if ($isAdvance && $advanceTime) {//提前预约时间（单位小时）
			$advanceDate = floor($advanceTime / 24);
			$surplusTime = $advanceTime - $advanceDate * 24;
			$surplusTime = $surplusTime ? $surplusTime . '00' : $surplusTime;
			$now = $now + $surplusTime;
		}
		//显示可预约日期类型1：全部展示，2：自定义展示时间'
		$showReservationDaysType = $productInfo['show_reservation_days_type'];
		$showStart = $showEnd = 0;
		if ($showReservationDaysType == 2) {
			//显示多少天内可预约日期（天）
			$showReservationDays = $productInfo['show_reservation_days'];
			$showStart = strtotime(date('Y-m-d'));
			$showEnd = strtotime("+". $showReservationDays ." day", $showStart);
		}
		//可售日期类型1：每天，2:每周，3：自定义时间
		$saleTimeType = $productInfo['sale_time_type'];
		$salesWeeks = $productInfo['sale_time_week'];
		$salesWeeks = $salesWeeks ? (is_string($salesWeeks) ? explode(',', $salesWeeks) : $salesWeeks) : [];
		$saleTimeStart = $productInfo['sale_time_start'];
		$saleTimeEnd = $productInfo['sale_time_end'];

		$today = strtotime($today);
		//需要提前预约超过一天的
		$today = $today + $advanceDate * 24 * 3600;
		$dateStart = strtotime($date);
		$week = date('w', $dateStart);
		$dateEnd = $dateStart + 86400;
		/** @var StoreProductReservationTimeServices $reservationTimeServices */
		$reservationTimeServices = app()->make(StoreProductReservationTimeServices::class);
		$reservationTimeData = $reservationTimeServices->getList(['product_id' => $id, 'sku_unique' => $unique]);

		foreach ($reservationTimeData as &$item) {
			$item['is_valid'] = 1;
			if ($showReservationDaysType == 2 && ($dateStart < $showStart || $dateStart > $showEnd)) {
				$item['is_valid'] = 0;
				continue;
			}
			if ($advanceDate) {//需要提前预约超过一天时间的
				if ($dateStart < $today) {
					$item['is_valid'] = 0;
					continue;
				}
			}
			if (in_array($saleTimeType, [2, 3])) {//验证今天日期在不在可售日期内
				if ($saleTimeType == 2) {
					if (!is_array($salesWeeks) || !$salesWeeks || !in_array($week, $salesWeeks)) {
						$item['is_valid'] = 0;
						continue;
					}
				} else {
					if ($dateStart < $saleTimeStart || $dateStart > $saleTimeEnd) {
						$item['is_valid'] = 0;
						continue;
					}
				}
			}
			$start = str_replace(':', '', $item['start']);
			$end = str_replace(':', '', $item['end']);
			if ($dateStart < $today || ($dateStart == $today  && ($start <= $now || $end <= $now))) {
				$item['is_valid'] = 0;
			}
			// 预约不限制时段库存
			$item['stock'] = 999999;
		}
		return $reservationTimeData;
	}

	/**
	 * 购买验证预约时段是否有效、库存
	 * @param int $id
	 * @param string $unique
	 * @param int $cart_num
	 * @param string $date
	 * @param int $reservation_time_id
	 * @param array $productInfo
	 * @return array|\think\Model
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function checkReservationProductTimeStock(int $id, string $unique, int $cart_num, string $date = '', int $reservation_time_id = 0, array $productInfo = [], bool $is_check_reservation_time = true, string $reservation_start = '')
	{
		if (!$id) {
			throw new ValidateException('该商品已下架或删除');
		}
		if (!$productInfo) {
			[$id, $productInfo] = $this->getProductInfo($id);
		}
		if (!$productInfo) {
			throw new ValidateException('该商品已下架或删除');
		}
		if ($productInfo['reservation_timing_type'] == 2 && (!$date || (!$reservation_time_id && !trim($reservation_start))) && $is_check_reservation_time) {//购买时预约
			throw new ValidateException('请确认选择预约日期时段');
		}
		$reservationTimeInfo = [];
		//选择了预约时间日期 收银台操作不验证
		if ($date && $is_check_reservation_time) {
			$date = strtotime($date);
			$dateEnd = $date + 86400;
			//显示可预约日期类型1：全部展示，2：自定义展示时间'
			$showReservationDaysType = $productInfo['show_reservation_days_type'];
			if ($showReservationDaysType == 2) {
				//显示多少天内可预约日期（天）
				$showReservationDays = $productInfo['show_reservation_days'];
				$showStart = strtotime(date('Y-m-d'));
				$showEnd = strtotime("+". $showReservationDays ." day", $showStart);
				if ($date < $showStart || $date > $showEnd) {
					throw new ValidateException('您选择日期超出可预约日期');
				}
			}
			//可售日期类型1：每天，2:每周，3：自定义时间
			$saleTimeType = $productInfo['sale_time_type'];
			switch ($saleTimeType) {
				case 1://每天
					break;
				case 2://每周
					$salesWeeks = $productInfo['sale_time_week'];
					$salesWeeks = $salesWeeks ? (is_string($salesWeeks) ? explode(',', $salesWeeks) : $salesWeeks) : [];
					$week = date('w', $date);
					if (!$salesWeeks || !is_array($salesWeeks) || !in_array($week, $salesWeeks)) {
						throw new ValidateException('您选择日期不再可售时间（周）内');
					}
					break;
				case 3://自定义时间
					$saleTimeStart = $productInfo['sale_time_start'];
					$saleTimeEnd = $productInfo['sale_time_end'];
					if ($date < $saleTimeStart || $date > $saleTimeEnd) {
						throw new ValidateException('您选择日期超出可预约日期');
					}
					break;
			}
		}
		//时段
		if ($reservation_time_id) {
			/** @var StoreProductReservationTimeServices $reservationTimeServices */
			$reservationTimeServices = app()->make(StoreProductReservationTimeServices::class);
			$reservationTimeInfo = $reservationTimeServices->get(['product_id' => $id, 'sku_unique' => $unique, 'id' => $reservation_time_id]);
			if (!$reservationTimeInfo) {
				throw new ValidateException('选择的时段无效');
			}
		}
		return $reservationTimeInfo;
	}



}
