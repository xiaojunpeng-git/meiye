<?php


namespace app\services\product\sku;


use app\dao\product\sku\StoreProductReservationTimeDao;
use app\services\BaseServices;

/**
 * 预约商品规格时段划分库存信息
 * Class StoreProductReservationTimeServices
 * @package app\services\product\sku
 * @mixin StoreProductReservationTimeDao
 */
class StoreProductReservationTimeServices extends BaseServices
{


    public function __construct(StoreProductReservationTimeDao $dao)
    {
        $this->dao = $dao;
    }

	/**
	 * 规格中获取预约时间段库存列表
	 * @param string $unique
	 * @param int $product_id
	 * @param string $field
	 * @return array
	 */
	public function getProductReservationTimes(string $unique, int $product_id, string $field = 'id,show_time,start,end,stock')
	{
		$where = ['product_id' => $product_id];
		if ($unique)  $where['sku_unique'] = $unique;
		return $this->dao->getColumn($where, $field);
	}

	/**
	 * 保存预约商品时间段
	 * @param int $product_id
	 * @param array $valueGroup
	 * @return bool
	 */
	public function setProductReservationTime(int $product_id, array $valueGroup)
	{
		$this->dao->delete(['product_id' => $product_id]);
		foreach ($valueGroup as &$item) {
			if (isset($item['product_type']) && $item['product_type'] == 6 && isset($item['reservation_time_data']) && count($item['reservation_time_data'])) {
				$dataAll = [];
				foreach ($item['reservation_time_data'] as $time) {
						$dataAll[] = [
							'product_id' => $product_id,
							'sku_unique' => $item['unique'],
							'show_time' => $time['start']. '-' . $time['end'],
							'start' => $time['start'],
							'end' => $time['end'],
							'stock' => $time['stock'] ?? 0,
							'service_price' => $time['service_price'] ?? 0.00
						];

				}
				if ($dataAll) {
					$this->dao->saveAll($dataAll);
				}
			}
		}

		return true;
	}

}
