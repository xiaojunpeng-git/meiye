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

namespace mohe\services\product\storage;

use mohe\basic\BaseProduct;
use mohe\services\CacheService;
use mohe\services\HttpService;
use think\exception\ValidateException;


/**
 * Class NineNineApi
 * @package mohe\services\product\storage
 */
class NineNineApi extends BaseProduct
{
	/**
	 * 99apiKey
	 * @var string
	 */
	protected string $apiKey = '';//996EF05B079F8706345938A0CD7339BB

	/**
	 * @var string[]
	 */
	protected array $host = ['taobao', 'tmall', 'jd', 'pinduoduo', 'suning', 'yangkeduo', '1688'];

	//接口地址
	protected array $api = [
		'taobao' => 'https://api03.6bqb.com/taobao/detail', //https://api03.6bqb.com/app/taobao/detail
		'tmall' => 'https://api03.6bqb.com/tmall/detail',
		'jd' => 'https://api03.6bqb.com/jd/detail',
		'pdd' => 'https://api03.6bqb.com/pdd/detail',
		'suning' => 'https://api03.6bqb.com/suning/detail',
		'1688' => 'https://api03.6bqb.com/alibaba/detail'
	];

	/**
	 * 商品默认字段
	 * @var array
	 */
	protected array $productInfo = [
		'cate_id' => '',
		'store_name' => '',
		'store_info' => '',
		'unit_name' => '件',
		'price' => 0,
		'keyword' => '',
		'ficti' => 0,
		'ot_price' => 0,
		'give_integral' => 0,
		'postage' => 0,
		'cost' => 0,
		'image' => '',
		'slider_image' => '',
		'video_link' => '',
		'add_time' => 0,
		'stock' => 0,
		'description' => '',
		'description_images' => [],
		'soure_link' => '',
		'temp_id' => '',
		'items' => [],
		'attrs' => [],
		'info' => [],
	];

	/** 初始化
	 * @param array $config
	 */
	protected function initialize(array $config = [])
	{
		parent::initialize($config);
		$this->apiKey = $config['api_key'] ?? '';
	}

	/**
	 * 复制商品
	 * @param string $url
	 * @return array|mixed
	 */
	public function goods(string $url)
	{
		if (!$this->apiKey) {
			throw new ValidateException('请先去设置99Api采集商品apiKey');
		}
		try {
			[$type, $id, $shopid] = $this->getUrlParamId($url);
			return $this->getInfo($type, ['itemid' => $id, 'shopid' => $shopid]);
		} catch (\Throwable $e) {
			\think\facade\Log::error('99Api采集商品，Url:' . $url . '，采集失败，原因：' . $e->getMessage());
			return [];
		}
	}

	/**
	 * 处理url成平台类型
	 * @param string $url
	 * @return mixed|string
	 */
	public function getType(string $url)
	{
		$url_arr = parse_url($url);
		$type = '';
		if (isset($url_arr['host'])) {
			foreach ($this->host as $name) {
				if (strpos($url_arr['host'], $name) !== false) {
					$type = $name;
				}
			}
		}
		return ($type == 'pinduoduo' || $type == 'yangkeduo') ? 'pdd' : $type;
	}

	/**
	 * 处理url成商品ID
	 * @param string $url
	 * @return array
	 */
	public function getUrlParamId(string $url)
	{
		$type = $this->getType($url);
		$url_arr = parse_url($url);
		$id = $shopid = 0;
		switch ($type) {
			case 'taobao':
			case 'tmall':
				$params = [];
				if (isset($url_arr['query']) && $url_arr['query']) {
					$queryParts = explode('&', $url_arr['query']);
					foreach ($queryParts as $param) {
						$item = explode('=', $param);
						if (isset($item[0]) && $item[1]) $params[$item[0]] = $item[1];
					}
				}
				$id = $params['id'] ?? '';
				break;
			case 'jd':
				$params = [];
				if (isset($url_arr['path']) && $url_arr['path']) {
					$path = str_replace('.html', '', $url_arr['path']);
					$params = explode('/', $path);
				}
				$id = $params[1] ?? '';
				break;
			case 'pdd':
				$params = [];
				if (isset($url_arr['query']) && $url_arr['query']) {
					$queryParts = explode('&', $url_arr['query']);
					foreach ($queryParts as $param) {
						$item = explode('=', $param);
						if (isset($item[0]) && $item[1]) $params[$item[0]] = $item[1];
					}
				}
				$id = $params['goods_id'] ?? $params['goodsId'] ?? '';
				break;
			case 'suning':
				$params = [];
				if (isset($url_arr['path']) && $url_arr['path']) {
					$path = str_replace('.html', '', $url_arr['path']);
					$params = explode('/', $path);
				}
				$id = $params[2] ?? '';
				$shopid = $params[1] ?? '';
				break;
			case '1688':
				$params = [];
				if (isset($url_arr['query']) && $url_arr['query']) {
					$path = str_replace('.html', '', $url_arr['path']);
					$params = explode('/', $path);
				}
				$id = $params[2] ?? '';
				$shopid = $params[1] ?? '';
				break;
		}
		return [$type, $id, $shopid];
	}


	/**
	 *
	 * @param string $type
	 * @param array $data
	 * @return array
	 */
	public function getInfo(string $type = 'taobao', array $data = [])
	{
		$url = $this->api[$type] ?? '';
		$type = $type == '1688' ? 'alibaba' : $type;
		$action = $type . 'Info';
		$deal_action = $type . 'Deal';
		$method = 'get';
		if (!$data || !$url || !is_callable(self::class, $action) || !is_callable(self::class, $deal_action)) {
			throw new ValidateException('暂不支持该平台商品复制');
		}
		switch ($type) {
			case 'taobao':
			case 'tmall':
			case 'jd':
			case 'pdd':
			case 'alibaba':
				$method = 'get';
				if (!isset($data['itemid']) || !$data['itemid'])
					throw new ValidateException('缺少商品ID');
				break;
			case 'suning':
				$method = 'get';
				if (!isset($data['itemid']) || !$data['itemid'])
					throw new ValidateException('缺少商品ID');
				if (!isset($data['shopid']) || !$data['shopid'])
					throw new ValidateException('缺少商户ID');
				break;
		}
		$url = $this->makeUrl($url, $method, $data);
		if ($cache_info = CacheService::get(md5($url))) {
			return $cache_info;
		}
		$info = $this->$action($url, $data);
		if (!$info) throw new ValidateException('获取商品失败');
		$info = json_decode($info, true);
		if (!$info || (!in_array($info['retcode'], ['0000']))) {
			throw new ValidateException( $info['message'] ?? '获取商品失败');
		}
		$result = $info['data'];
		//可能存在下一页  但是api中没有分页参数 暂留
//        if (isset($info['hasNext']) && $info['hasNext']) {
//            $data['page'] = $info['page'] + 1;
//        }
		$result = $this->$deal_action($result);
		//过滤采集到的规格 删除其中的空值
		if ($result['items']) {
			foreach ($result['items'] as $k => $item) {
				if (isset($item['value'])) {
					if ($item['value'] == '') unset($result['items'][$k]);
					if (!$item['detail'] || !isset($item['detail'][0]) || $item['detail'][0] == '') unset($result['items'][$k]);
				} else {
					unset($result['items'][$k]);
				}
			}
		}
		if (!$result['items']) {
			$result['items'] = [
				[
					'value' => '默认',
					'detail' => [
						'默认'
					]
				]
			];
		}
		$result['info'] = $this->formatAttr(array_values($result['items']));
		if (!$result['image'] && $result['slider_image'])
			$result['image'] = $result['slider_image'][0] ?? '';
		if ($result['description']) {
			$result['description'] = str_replace('data-lazyload', 'src', $result['description']);
		}
		CacheService::set(md5($url), $result, 3600 * 24);
		return $result;
	}

	/**
	 * 整合
	 * @param $url
	 * @param $method
	 * @param $data
	 * @return string
	 */
	public function makeUrl(string $url, string $method, array $data)
	{
		$param = '';
		if (strtolower($method) == 'get' && $data) {
			foreach ($data as $key => $value) {
				$param .= '&' . $key . '=' . $value;
			}
		}
		return $url . '?apikey=' . $this->apiKey . $param;
	}


	/**
	 * 获取淘宝商品
	 * @param $url
	 * @param $data
	 * @param string $method
	 * @return bool|string
	 */
	public function taobaoInfo(string $url, array $data, string $method = 'get')
	{
		$info = HttpService::request($url, $method, $data);
		$result = false;
		if ($info) {
			$result = $info;
		}
		return $result;
	}

	/**
	 * 处理获取淘宝的商品
	 * @param $data
	 * @return mixed
	 */
	public function taobaoDeal(array $data)
	{
		$info = $data['item'] ?? [];
		$result = $this->productInfo;
		if ($info) {
			$result['store_name'] = $info['title'] ?? '';
			$result['store_info'] = $info['subTitle'] ?? '';
			$result['slider_image'] = $info['images'] ?? '';
			$result['description'] = $info['desc'] ?? '';
			$result['description_images'] = $info['descImgs'] ?? [];
			$items = [];
			if (isset($info['props']) && $info['props']) {
				foreach ($info['props'] as $key => $prop) {
					$item['value'] = $prop['name'];
					$item['detail'] = [];
					foreach ($prop['values'] as $name) {
						$item['detail'][] = $name['name'];
					}
					$items[] = $item;
				}
			}
			$result['items'] = $items;
		}
		return $result;
	}


	/**
	 * 获取天猫商品
	 * @param $url
	 * @param $data
	 * @param string $method
	 * @return bool|string
	 */
	public function tmallInfo(string $url, array $data, string $method = 'get')
	{
		$info = HttpService::request($url, $method, $data);
		$result = false;
		if ($info) {
			$result = $info;
		}
		return $result;
	}

	/**
	 * 处理天猫商品
	 * @param $data
	 * @return mixed
	 */
	public function tmallDeal(array $data)
	{
		$info = $data['item'] ?? [];
		$result = $this->productInfo;
		if ($info) {
			$result['store_name'] = $info['title'] ?? '';
			$result['store_info'] = $info['subTitle'] ?? '';
			$result['slider_image'] = $this->lingByHttp($info['images'] ?? '');
			$result['description_images'] = $this->lingByHttp($info['descImgs'] ?? []);
			$description = '';
			foreach ($result['description_images'] as $item) {
				$description .= '<img src="' . $item . '">';
			}
			unset($item);
			$result['description'] = $description;
			$items = [];
			if (isset($info['props']) && $info['props']) {
				foreach ($info['props'] as $key => $prop) {
					$item['value'] = $prop['name'];
					$item['detail'] = [];
					foreach ($prop['values'] as $name) {
						$item['detail'][] = $name['name'];
					}
					$items[] = $item;
				}
			}
			$result['items'] = $items;
		}
		return $result;
	}

	/**
	 * 获取京东商品
	 * @param $url
	 * @param $data
	 * @param string $method
	 * @return bool|string
	 */
	public function jdInfo(string $url, array $data, string $method = 'get')
	{
		$info = HttpService::request($url, $method, $data);
		$result = false;
		if ($info) {
			$result = $info;
		}
		return $result;
	}

	/**
	 * 处理京东商品
	 * @param $data
	 * @return mixed
	 */
	public function jdDeal(array $data)
	{
		$info = $data['item'] ?? [];
		$result = $this->productInfo;
		if ($info) {
			$result['store_name'] = $info['name'] ?? '';
			$result['store_info'] = $result['store_name'];
			$result['price'] = $info['price'] ?? 0;
			$result['ot_price'] = $info['originalPrice'] ?? 0;
			$result['slider_image'] = $info['images'] ?? [];
			$result['description'] = $info['desc'] ?? '';
			$result['description_images'] = $info['descImgs'] ?? [];
			$result['description_images'] = array_map(function ($item) {
				if (strstr($item, 'http') === false) {
					$item = 'http:' . $item;
				}
				return $item;
			}, $result['description_images']);
			if (strstr($result['description'], '<style>') !== false && strstr($result['description'], '<img') === false) {
				$content = '';
				foreach ($result['description_images'] as $item) {
					$content .= '<p><img src="' . $item . '"></p>';
				}
				$result['description'] = $content;
			}

			$items = [];
			if (isset($info['skuProps']) && $info['skuProps']) {
				foreach ($info['skuProps'] as $key => $prop) {
					$item = [];
					$item['value'] = $info['saleProp'][$key] ?? '';
					$item['detail'] = $prop;
					$items[] = $item;
				}
			}
			$result['items'] = $items;
		}
		return $result;
	}

	/**
	 * @param $data
	 * @return array|string
	 */
	public function lingByHttp($data)
	{
		if (is_array($data)) {
			foreach ($data as &$item) {
				if (strstr($item, 'http://') === false && strstr($item, 'https://') === false) {
					$item = 'http:' . $item;
				}
			}
			return $data;
		} else {
			if (strstr($data, 'http://') === false && strstr($data, 'https://') === false) {
				$data = 'http:' . $data;
			}
			return $data;
		}
	}

	/**
	 * 获取拼多多商品
	 * @param $url
	 * @param $data
	 * @param string $method
	 * @return bool|string
	 */
	public function pddInfo(string $url, array $data, string $method = 'get')
	{
		$info = HttpService::request($url, $method, $data);
		$result = false;
		if ($info) {
			$result = $info;
		}
		return $result;
	}

	/**
	 * 处理拼多多商品
	 * @param $data
	 * @return mixed
	 */
	public function pddDeal(array $data)
	{
		$info = $data['item'] ?? [];
		$result = $this->productInfo;
		if ($info) {
			$result['store_name'] = $info['goodsName'] ?? '';
			$result['store_info'] = $info['goodsDesc'] ?? '';
			$result['image'] = $info['thumbUrl'] ?? '';
			$result['slider_image'] = $info['banner'] ?? [];
			$image = [];
			foreach ($result['slider_image'] as &$item) {
				if (is_array($item) && isset($item['url'])) {
					$image[] = $item['url'];
				}
			}
			if ($image) {
				$result['slider_image'] = $image;
			}
			$result['video_link'] = $info['video']['videoUrl'] ?? '';
			$result['price'] = $info['maxNormalPrice'] ?? 0;
			$result['ot_price'] = $info['marketPrice'] ?? 0;
			$descImgs = [];
			if (isset($info['detail']) && $info['detail']) {
				foreach ($info['detail'] as $img) {
					if (isset($img['url']) && $img['url']) $descImgs[] = $img['url'];
				}
			}
			$result['description_images'] = $descImgs;

			$desc = '<p>';
			foreach ($descImgs as $item) {
				$desc .= '<img src="' . $item . '"/>';
			}
			$desc .= '</p>';
			$result['description'] = $desc;

			$items = [];
			if (isset($info['skus']) && $info['skus']) {
				$i = 0;
				foreach ($info['skus'] as $sku) {
					foreach ($sku['specs'] as $key => $spec) {
						if ($i == 0) $items[$key]['value'] = $spec['spec_key'];
						$items[$key]['detail'][] = $spec['spec_value'];
					}
					$i++;
				}
			}
			foreach ($items as $k => $item) {
				$items[$k]['detail'] = array_unique($item['detail']);
			}
			$result['items'] = $items;
		}
		return $result;
	}

	/**
	 * 获取苏宁商品
	 * @param $url
	 * @param $data
	 * @param string $method
	 * @return bool|string
	 */
	public function suningInfo(string $url, array $data, string $method = 'get')
	{
		$info = HttpService::request($url, $method, $data);
		$result = false;
		if ($info) {
			$result = $info;
		}
		return $result;
	}

	/**
	 *
	 * @param string $url
	 * @param array $data
	 * @param string $method
	 * @return bool|string
	 */
	public function alibabaInfo(string $url, array $data, string $method = 'get')
	{
		$info = HttpService::request($url, $method, $data);
		$result = false;
		if ($info) {
			$result = $info;
		}
		return $result;
	}

	/**
	 * @param array $data
	 * @return array
	 */
	public function alibabaDeal(array $data)
	{
		$result = $this->productInfo;
		if ($data) {
			$result['store_name'] = $data['title'] ?? '';
			$result['store_info'] = $result['store_name'];
			$result['slider_image'] = $data['images'] ?? [];
			$result['price'] = $data['price'] ?? 0;
			$result['description'] = $data['desc'] ?? '';
			$items = [];
			if (isset($data['skuProps']) && $data['skuProps']) {
				$i = 0;
				foreach ($data['skuProps'] as $passSUb) {
					$items[$i]['value'] = $passSUb['prop'];
					$items[$i]['detail'] = array_column($passSUb['value'], 'name');
					$i++;
				}
			}
			foreach ($items as $k => $item) {
				$items[$k]['detail'] = array_unique($item['detail']);
			}
			$result['items'] = $items;
		}
		return $result;
	}

	/**
	 * 处理苏宁商品
	 * @param $data
	 * @return mixed
	 */
	public function suningDeal(array $data)
	{
		$result = $this->productInfo;
		if ($data) {
			$result['store_name'] = $data['title'] ?? '';
			$result['store_info'] = $result['store_name'];
			$result['slider_image'] = $data['images'] ?? [];
			$result['price'] = $data['price'] ?? 0;
			$result['description'] = $data['desc'] ?? '';
			$items = [];
			if (isset($data['passSubList']) && $data['passSubList']) {
				$i = 0;
				foreach ($data['passSubList'] as $passSUb) {
					$j = 0;
					foreach ($passSUb as $key => $sub) {
						if ($i == 0) $items[$j]['value'] = $key;
						foreach ($sub as $value) {
							if (isset($value['characterValueDisplayName']) && $value['characterValueDisplayName'])
								$items[$j]['detail'][] = $value['characterValueDisplayName'];
						}
						$j++;
					}
					$i++;
				}
			}
			foreach ($items as $k => $item) {
				$items[$k]['detail'] = array_unique($item['detail']);
			}
			$result['items'] = $items;
		}
		return $result;
	}

	/**
	 * 格式化规格
	 * @param $attr
	 * @return array
	 */
	public function formatAttr(array $attr)
	{
		foreach ($attr as  $k => $v){
			foreach ($v['detail'] as $key => $item){
				if(isset($item['value'])){
					$attr[$k]['detail'][$key] = $item['value'];
				}
			}
		}
		$value = attr_format($attr)[1];
		$valueNew = [];
		$count = 0;
		foreach ($value as $key => $item) {
			$detail = $item['detail'];
//            sort($item['detail'], SORT_STRING);
			$suk = implode(',', $item['detail']);
			$sukValue[$suk]['pic'] = '';
			$sukValue[$suk]['price'] = 0;
			$sukValue[$suk]['cost'] = 0;
			$sukValue[$suk]['ot_price'] = 0;
			$sukValue[$suk]['stock'] = 0;
			$sukValue[$suk]['bar_code'] = '';
			$sukValue[$suk]['weight'] = 0;
			$sukValue[$suk]['volume'] = 0;
			$sukValue[$suk]['brokerage'] = 0;
			$sukValue[$suk]['brokerage_two'] = 0;

			foreach (array_keys($detail) as $k => $title) {
				if ($title == '') continue;
				$header[$k]['title'] = $title;
				$header[$k]['align'] = 'center';
				$header[$k]['minWidth'] = 120;
			}
			foreach (array_values($detail) as $k => $v) {
				if ($v == '') continue;
				$valueNew[$count]['value' . ($k + 1)] = $v;
				$header[$k]['key'] = 'value' . ($k + 1);
			}
			$valueNew[$count]['detail'] = $detail;
			$valueNew[$count]['pic'] = $sukValue[$suk]['pic'] ?? '';
			$valueNew[$count]['price'] = $sukValue[$suk]['price'] ? floatval($sukValue[$suk]['price']) : 0;
			$valueNew[$count]['cost'] = $sukValue[$suk]['cost'] ? floatval($sukValue[$suk]['cost']) : 0;
			$valueNew[$count]['ot_price'] = isset($sukValue[$suk]['ot_price']) ? floatval($sukValue[$suk]['ot_price']) : 0;
			$valueNew[$count]['vip_price'] = isset($sukValue[$suk]['vip_price']) ? floatval($sukValue[$suk]['vip_price']) : 0;
			$valueNew[$count]['stock'] = $sukValue[$suk]['stock'] ? intval($sukValue[$suk]['stock']) : 0;
			$valueNew[$count]['bar_code'] = $sukValue[$suk]['bar_code'] ?? '';
			$valueNew[$count]['weight'] = $sukValue[$suk]['weight'] ? floatval($sukValue[$suk]['weight']) : 0;
			$valueNew[$count]['volume'] = $sukValue[$suk]['volume'] ? floatval($sukValue[$suk]['volume']) : 0;
			$valueNew[$count]['brokerage'] = $sukValue[$suk]['brokerage'] ? floatval($sukValue[$suk]['brokerage']) : 0;
			$valueNew[$count]['brokerage_two'] = $sukValue[$suk]['brokerage_two'] ? floatval($sukValue[$suk]['brokerage_two']) : 0;
			$count++;
		}
		$header[] = ['title' => '图片', 'slot' => 'pic', 'align' => 'center', 'minWidth' => 80];
		$header[] = ['title' => '售价', 'slot' => 'price', 'align' => 'center', 'minWidth' => 95];
		$header[] = ['title' => '成本价', 'slot' => 'cost', 'align' => 'center', 'minWidth' => 95];
		$header[] = ['title' => '划线价', 'slot' => 'ot_price', 'align' => 'center', 'minWidth' => 95];
		$header[] = ['title' => '会员价', 'slot' => 'vip_price', 'align' => 'center', 'minWidth' => 140];
		$header[] = ['title' => '库存', 'slot' => 'stock', 'align' => 'center', 'minWidth' => 95];
		$header[] = ['title' => '商品编号', 'slot' => 'bar_code', 'align' => 'center', 'minWidth' => 120];
		$header[] = ['title' => '重量(KG)', 'slot' => 'weight', 'align' => 'center', 'minWidth' => 95];
		$header[] = ['title' => '体积(m³)', 'slot' => 'volume', 'align' => 'center', 'minWidth' => 95];
		$header[] = ['title' => '操作', 'slot' => 'action', 'align' => 'center', 'minWidth' => 70];
		$info = ['attr' => $attr, 'value' => $valueNew, 'header' => $header];
		return $info;
	}


    /**
	 * 是否开通复制
     * @return mixed
     */
    public function open()
    {

    }



}
