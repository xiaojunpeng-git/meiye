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

namespace app\services;

use mohe\utils\JwtAuth;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Config;
use think\facade\Route as Url;

/**
 * Class BaseServices
 * @package app\services
 */
abstract class BaseServices
{
    /**
     * 模型注入
     * @var object
     */
    protected $dao;

    /**
     * 获取分页配置
     * @param bool $isPage
     * @param bool $isRelieve
     * @return int[]
     */
    public function getPageValue(bool $isPage = true, bool $isRelieve = true)
    {
        $page = $limit = 0;
        if ($isPage) {
            $page = app()->request->param(Config::get('database.page.pageKey', 'page') . '/d', 0);
            $limit = app()->request->param(Config::get('database.page.limitKey', 'limit') . '/d', 0);
        }
        $limitMax = Config::get('database.page.limitMax');
        $defaultLimit = Config::get('database.page.defaultLimit', 10);
        if ($limit > $limitMax && $isRelieve) {
            $limit = $limitMax;
        }
        return [(int)$page, (int)$limit, (int)$defaultLimit];
    }

    /**
     * 数据库事务操作
     * @param callable $closure
     * @return mixed
     */
    public function transaction(callable $closure, bool $isTran = true)
    {
        return $isTran ? Db::transaction($closure) : $closure();
    }

    /**
     * 创建token
     * @param int $id
     * @param $type
     * @return array
     */
    public function createToken(int $id, $type, string $pwd = '')
    {
        /** @var JwtAuth $jwtAuth */
        $jwtAuth = app()->make(JwtAuth::class);
        return $jwtAuth->createToken($id, $type, ['auth' => md5($pwd)]);
    }

	/**
	 * 获取路由地址
	 * @param string $path
	 * @param array $params
	 * @param bool $suffix
	 * @param bool $isDomain
	 * @return string
	 */
    public function url(string $path, array $params = [], bool $suffix = false, bool $isDomain = false)
    {
        return Url::buildUrl($path, $params)->suffix($suffix)->domain($isDomain)->build();
    }

    /**
     * 密码hash加密
     * @param string $password
     * @return false|string|null
     */
    public function passwordHash(string $password)
    {
        return password_hash($password, PASSWORD_BCRYPT);
    }

	/**
	 * 根据雪花❄️算法获取唯一ID
	 * @param string $prefix
	 * @return string
	 * @throws \Exception
	 */
	public function getUniqueId(string $prefix = 'wx')
	{
		$snowflake = new \Godruoyi\Snowflake\Snowflake();
		$is_callable = function ($currentTime) {
//            if (is_win()) {
			$redis = Cache::store('redis');
			$swooleSequenceResolver = new \Godruoyi\Snowflake\RedisSequenceResolver($redis->handler());
			return $swooleSequenceResolver->sequence($currentTime);
//            } else {
//                $swooleSequenceResolver = new \Godruoyi\Snowflake\SwooleSequenceResolver();
//                return $swooleSequenceResolver->sequence($currentTime);
//            }
		};
		//32位
		if (PHP_INT_SIZE == 4) {
			$id = abs($snowflake->setSequenceResolver($is_callable)->id());
		} else {
			$id = $snowflake->setStartTimeStamp(strtotime('2020-06-05') * 1000)->setSequenceResolver($is_callable)->id();
		}
		return $prefix . $id;
	}

	/**
	 * 格式化时间
	 * @param $time
	 * @param bool $is_time_key 是否获取每一天日期
	 * @param bool $is_before_time 是否获取上一期时间段
	 * @return array
	 */
    public function timeHandle($time, bool $is_time_key = false, bool $is_before_time = false)
    {
        switch ($time) {
            case 'today':
                $start = date('Y-m-d 00:00:00');
                $end = date('Y-m-d 23:59:59');
				if ($is_before_time) {//昨天
					$beforeStart = date('Y-m-d 00:00:00', strtotime("-1 day"));
					$beforeEnd = date('Y-m-d 23:59:59', strtotime("-1 day"));
				}
                break;
            case 'yesterday':
                $start = date('Y-m-d 00:00:00', strtotime("-1 day"));
                $end = date('Y-m-d 23:59:59', strtotime("-1 day"));
				if ($is_before_time) {
					$beforeStart = date('Y-m-d 00:00:00', strtotime("-2 day"));
					$beforeEnd = date('Y-m-d 23:59:59', strtotime("-2 day"));
				}
                break;
            case 'sevenday':
                $start = date('Y-m-d 00:00:00', strtotime('-6 day'));
                $end = date('Y-m-d 23:59:59');
				if ($is_before_time) {
					$beforeStart = date('Y-m-d 00:00:00', strtotime("-13 day"));
					$beforeEnd = date('Y-m-d 23:59:59', strtotime("-7 day"));
				}
                break;
            case 'thirtyday':
                $start = date('Y-m-d 00:00:00', strtotime('-29 day'));
                $end = date('Y-m-d 23:59:59');
				if ($is_before_time) {
					$beforeStart = date('Y-m-d 00:00:00', strtotime("-60 day"));
					$beforeEnd = date('Y-m-d 23:59:59', strtotime("-30 day"));
				}
                break;
			case 'last month':
				$start = date('Y-m-01 00:00:00', strtotime('first Day of last month 00:00:00'));
				$end = date("Y-m-d 23:59:59", strtotime('first Day of this month 00:00:00 -1second'));
				if ($is_before_time) {
					$beforeStart = date('Y-m-01 00:00:00', strtotime('first Day of -2 months'));
					$beforeEnd = date("Y-m-d 23:59:59", strtotime('first Day of last month 00:00:00 -1second'));
				}
				break;
            case 'month':
                $start = date('Y-m-01 00:00:00');
                $end = date('Y-m-d 23:59:59', mktime(23, 59, 59, date('m'), date('t'), date('Y')));
				if ($is_before_time) {
					$beforeStart = date('Y-m-01 00:00:00', strtotime('first Day of last month 00:00:00'));
					$beforeEnd = date("Y-m-d 23:59:59", strtotime('first Day of this month 00:00:00 -1second'));
				}
                break;
            case 'year':
                $start = date('Y-01-01 00:00:00');
                $end = date('Y-12-31 23:59:59');
				if ($is_before_time) {
					$beforeStart = date('Y-m-01 00:00:00', strtotime('-1 year'));
					$beforeEnd = date("Y-12-31 23:59:59", strtotime('-1 year'));
				}
                break;
            default:
                $start = date("Y-m-d", strtotime("-30 days", time()));
                $end = date("Y-m-d 23:59:59", time());
                if (strstr($time, '-') !== false) {
                    [$start_r, $end_r] = explode('-', $time);
                    if ($start_r || $end_r) {
						$start = $start_r;
						$end = $end_r;
                        $end_time = strtotime($end_r);
                        //没选具体时分秒 加上86400
                        if ($end_time == strtotime(date('Y/m/d', $end_time))) {
                            $end = date('Y/m/d H:i:s', $end_time + 86399);
                        }
                    }
                }
				if ($is_before_time) {
					$start_s = $start ? strtotime($start) : 0;
					$end_s = $end ? strtotime($end) : 0;
					$dayCount = ceil(($end_s - $start_s) / 86400);
					$beforeStart = date('Y-m-01 00:00:00', strtotime('-'.($dayCount * 2). ' day'));
					$beforeEnd = date("Y-m-d 23:59:59", strtotime('-'.($dayCount - 1). ' day'));
				}

                break;
        }
        $start = $start ? strtotime($start) : 0;
        $end = $end ? strtotime($end) : 0;
        if ($is_time_key) {
            $dayCount = ceil(($end - $start) / 86400);
            $s_start = $start;
            $timeKey = [];
            if ($dayCount == 1) {
                $timeType = 'hour';
                $timeKey = ['00', '01', '02', '03', '04', '05', '06', '07', '08', '09', '10', '11', '12', '13', '14', '15', '16', '17', '18', '19', '20', '21', '22', '23'];
            } elseif ($dayCount <= 31) {
                $timeType = 'day';
                for ($i = 0; $i < $dayCount; $i++) {
                    $timeKey[] = date('Y-m-d', $s_start);
                    $s_start = strtotime("+1 day", $s_start);
                }
            } elseif ($dayCount <= 92) {
                $timeType = 'weekly';
                for ($i = 0; $i < $dayCount; $i = $i + 7) {
                    $timeKey[] = '第' . date('W', $s_start) . '周';
                    $s_start = strtotime("+1 week", $s_start);
                }
            } else {
                $timeType = 'year';
                while ($s_start <= $end) {
                    $timeKey[] = date('Y-m', $s_start);
                    $s_start = strtotime("+1 month", $s_start);
                }
            }
            return $is_before_time ? [$start, $end, $timeType, $timeKey, $beforeStart, $beforeEnd] : [$start, $end, $timeType, $timeKey];
        }
        return $is_before_time ? [$start, $end, $beforeStart, $beforeEnd] : [$start, $end];
    }

    /**
     * 计算环比增长率
     * @param $nowValue
     * @param $lastValue
     * @return float|int|string
     */
    public function countRate($nowValue, $lastValue)
    {
        if ($lastValue == 0 && $nowValue == 0) return 0;
        if ($lastValue == 0) return round(bcmul(bcdiv($nowValue, 1, 4), 100, 2), 2);
        if ($nowValue == 0) return -round(bcmul(bcdiv($lastValue, 1, 4), 100, 2), 2);
        return bcmul(bcdiv((bcsub($nowValue, $lastValue, 2)), $lastValue, 4), 100, 2);
    }

    /**
     * tree处理 分类、标签数据(这一类数据)
     * @param array $cate
     * @param array $label
     * @return array
     */
    public function get_tree_children(array $cate, array $label)
    {
        if ($cate) {
            foreach ($cate as $key => $value) {
                if ($label) {
                    foreach ($label as $k => $item) {
                        if ($value['id'] == $item['label_cate']) {
                            $cate[$key]['children'][] = $item;
                            unset($label[$k]);
                        }
                    }
                } else {
                    $cate[$key]['children'] = [];
                }
            }
        }
        return $cate;
    }

    /**
     * 处理城市数据
     * @param $address
     * @return array
     */
    public function addressHandle($address)
    {
        if ($address) {
            try {
                preg_match('/(.*?(省|自治区|北京市|天津市|上海市|重庆市|澳门特别行政区|香港特别行政区))/', $address, $matches);
                if (count($matches) > 1) {
                    $province = $matches[count($matches) - 2];
                    $address = preg_replace('/(.*?(省|自治区|北京市|天津市|上海市|重庆市|澳门特别行政区|香港特别行政区))/', '', $address, 1);
                }
                preg_match('/(.*?(市|自治州|地区|区划|县))/', $address, $matches);
                if (count($matches) > 1) {
                    $city = $matches[count($matches) - 2];
                    $address = str_replace($city, '', $address);
                }
                preg_match('/(.*?(区|县|镇|乡|街道))/', $address, $matches);
                if (count($matches) > 1) {
                    $area = $matches[count($matches) - 2];
                    $address = str_replace($area, '', $address);
                }
            } catch (\Throwable $e) {
            }
        }
        return [
            'province' => trim($province ?? ''),
            'city' => trim($city ?? ''),
            'district' => trim($area ?? ''),
            "address" => trim($address)
        ];
    }

    /**
     * @param $name
     * @param $arguments
     * @return mixed
     */
    public function __call($name, $arguments)
    {
        return call_user_func_array([$this->dao, $name], $arguments);
    }
}
