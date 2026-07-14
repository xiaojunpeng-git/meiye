<?php

use app\Request;
use mohe\services\CacheService;
use mohe\services\UploadService;
use Fastknife\Service\BlockPuzzleCaptchaService;
use Fastknife\Service\ClickWordCaptchaService;
use mohe\services\SystemConfigService;
use Joypack\Tencent\Map\Bundle\Location;
use Joypack\Tencent\Map\Bundle\LocationOption;
use think\exception\ValidateException;
use think\facade\Config;
use think\facade\Log;

if (!function_exists('pem_path')) {
    /**
     * 获取web根目录
     *
     * @param string $path
     * @return string
     */
    function pem_path($path = '')
    {
        return app()->getRootPath() . 'pem' . ($path ? $path . DIRECTORY_SEPARATOR : $path);
    }
}

if (!function_exists('get_tree_value')) {
    /**
     * 获取
     * @param array $data
     * @param int|string $value
     * @return array
     */
    function get_tree_value(array $data, $value, array &$childrenValue = [])
    {
        foreach ($data as &$item) {
            if ($item['value'] == $value) {
                $childrenValue[] = $item['value'];
                if ($item['pid']) {
                    $value = $item['pid'];
                    unset($item);
                    return get_tree_value($data, $value, $childrenValue);
                }
            }
        }
        return $childrenValue;
    }
}

if (!function_exists('is_brokerage_statu')) {

    /**
     * 是否能成为推广人
     * @param float $price
     * @return bool
     */
    function is_brokerage_statu(float $price)
    {
        if (!sys_config('brokerage_func_status')) {
            return false;
        }
        $storeBrokerageStatus = sys_config('store_brokerage_statu', 1);
        if ($storeBrokerageStatus == 1) {
            return false;
        } else if ($storeBrokerageStatus == 2) {
            return false;
        } else {
            $storeBrokeragePrice = sys_config('store_brokerage_price', 0);
            return $price >= $storeBrokeragePrice;
        }
    }
}

if (!function_exists('time_tran')) {
    /**
     * 时间戳人性化转化
     * @param $time
     * @return string
     */
    function time_tran($time)
    {
        $t = time() - $time;
        $f = array(
            '31536000' => '年',
            '2592000'  => '个月',
            '604800'   => '星期',
            '86400'    => '天',
            '3600'     => '小时',
            '60'       => '分钟',
            '1'        => '秒'
        );
        foreach ($f as $k => $v) {
            if (0 != $c = floor($t / (int)$k)) {
                return $c . $v . '前';
            }
        }
    }
}

if (!function_exists('url_to_path')) {
    /**
     * url转换路径
     * @param $url
     * @return string
     */
    function url_to_path($url)
    {
        $path = trim(str_replace('/', DS, $url), DS);
        if (0 !== strripos($path, 'public'))
            $path = 'public' . DS . $path;
        return app()->getRootPath() . $path;
    }
}

if (!function_exists('path_to_url')) {
    /**
     * 路径转url路径
     * @param $path
     * @return string
     */
    function path_to_url($path)
    {
        return trim(str_replace(DS, '/', $path), '.');
    }
}

if (!function_exists('get_image_thumb')) {
    /**
     * 获取缩略图
     * @param $filePath
     * @param string $type all|big|mid|small
     * @param bool $is_remote_down
     * @return mixed|string|string[]
     */
    function get_image_thumb($filePath, string $type = 'all', bool $is_remote_down = false)
    {
		if (!sys_config('image_thumb_status')) return $filePath;
        if (!$filePath || !is_string($filePath) || strpos($filePath, '?') !== false) return $filePath;
        try {
			$arr = explode('.', $filePath);
			$ext_name = trim($arr[count($arr) - 1]);
			if (!in_array($ext_name, ['png', 'jpg', 'jpeg'])) {
				return $filePath;
			}
            $upload = UploadService::getOssInit($filePath, $is_remote_down);
            $data   = $upload->thumb('', $type);
            $image  = $type == 'all' ? $data : $data[$type] ?? $filePath;
        } catch (\Throwable $e) {
            $image = $filePath;
            //            throw new ValidateException($e->getMessage());
            \think\facade\Log::error('获取缩略图失败，原因：' . $e->getMessage() . '----' . $e->getFile() . '----' . $e->getLine() . '----' . $filePath);
        }
        $data = parse_url($image);
        if (!isset($data['host']) && (substr($image, 0, 2) == './' || substr($image, 0, 1) == '/')) {//不是完整地址
            $image = sys_config('site_url') . $image;
        }
        //请求是https 图片是http 需要改变图片地址
        if (strpos(request()->domain(), 'https:') !== false && strpos($image, 'https:') === false) {
            $image = str_replace('http:', 'https:', $image);
        }
        return $image;
    }
}

if (!function_exists('timeConverter')) {

    /**
     * 一小时内【*分钟前】
     * 1天内显示【*小时前】
     * 跨天显示【*天前】
     * 跨月显示【月份-日期】
     * 跨年显示【年份-月份-日期】
     * @param int $timestamp
     * @return string
     */
    function timeConverter(int $timestamp): string
    {
        $currentTime = time();
        $timeDifference = $currentTime - $timestamp;

        // 获取当前时间和时间戳对应的时间
        $currentYear = date('Y', $currentTime);
        $currentMonth = date('m', $currentTime);
        $currentDay = date('d', $currentTime);

        $timestampYear = date('Y', $timestamp);
        $timestampMonth = date('m', $timestamp);
        $timestampDay = date('d', $timestamp);

        // 刚刚
        if ($timeDifference < 60) {
            return "刚刚";
        }

        // 几分钟前
        if ($timeDifference < 3600) {
            $minutesAgo = floor($timeDifference / 60);
            return "{$minutesAgo}分钟前";
        }

        // 1天内：显示 *小时前
        if ($timeDifference < 86400 && $currentDay == $timestampDay) {
            $hoursAgo = floor($timeDifference / 3600);
            return "{$hoursAgo}小时前";
        }

        // 跨天但在同一个月内：显示 *天前
        if ($currentMonth == $timestampMonth && $currentYear == $timestampYear) {
            $daysAgo = floor($timeDifference / 86400);
            $daysAgo = $daysAgo > 0 ? $daysAgo : 1;
            return "{$daysAgo}天前";
        }

        // 跨月但在同一年内：显示 月份-日期
        if ($currentYear == $timestampYear) {
            return date('m-d', $timestamp);
        }

        // 跨年：显示 年份-月份-日期
        return date('Y-m-d', $timestamp);
    }
}

if (!function_exists('get_thumb_water')) {
    /**
     * 处理数组获取缩略图、水印
     * @param $list
     * @param string $type
     * @param array|string[] $field 1、['image','images'] type 取值参数:type 2、['small'=>'image','mid'=>'images'] type 取field数组的key
     * @param bool $is_remote_down
     * @return array|mixed|string|string[]
     */
    function get_thumb_water($list, string $type = 'small', array $field = ['image'], bool $is_remote_down = false)
    {
        if (!$list || !$field) return $list;
        $baseType = $type;
        $data     = $list;
        if (is_string($list)) {
            $field = [$type => 'image'];
            $data  = ['image' => $list];
        }
        if (is_array($data)) {
            foreach ($field as $type => $key) {
                if (is_integer($type)) {//索引数组，默认type
                    $type = $baseType;
                }
                //一维数组
                if (isset($data[$key])) {
                    if (is_array($data[$key])) {
                        $path_data = [];
                        foreach ($data[$key] as $k => $path) {
                            $path_data[] = get_image_thumb($path, $type, $is_remote_down);
                        }
                        $data[$key] = $path_data;
                    } else {
                        $data[$key] = get_image_thumb($data[$key], $type, $is_remote_down);
                    }
                } else {
                    foreach ($data as &$item) {
                        if (!isset($item[$key]))
                            continue;
                        if (is_array($item[$key])) {
                            $path_data = [];
                            foreach ($item[$key] as $k => $path) {
                                $path_data[] = get_image_thumb($path, $type, $is_remote_down);
                            }
                            $item[$key] = $path_data;
                        } else {
                            $item[$key] = get_image_thumb($item[$key], $type, $is_remote_down);
                        }
                    }
                }
            }
        }
        return is_string($list) ? ($data['image'] ?? '') : $data;
    }
}

if (!function_exists('put_image')) {
    /**
     * 获取图片转为base64
     * @param string $avatar
     * @return bool|string
     */
    function put_image($url, $filename = '')
    {

        if ($url == '') {
            return false;
        }
        try {
            if ($filename == '') {

                $ext = pathinfo($url);
                if ($ext['extension'] != "jpg" && $ext['extension'] != "png" && $ext['extension'] != "jpeg") {
                    return false;
                }
                $filename = time() . "." . $ext['extension'];
            }

			$pathArr = parse_url($url);
			$path = $pathArr['path'] ?? '';
			if ($path && file_exists(public_path() . trim($path, '/'))) {
				return $path;
			} else {
				//文件保存路径
				ob_start();
				$url = str_replace('phar://', '', $url);
				readfile($url);
				$img = ob_get_contents();
				ob_end_clean();
				$path = 'uploads/qrcode';
				$fp2 = fopen(public_path() . $path . '/' . $filename, 'a');
				fwrite($fp2, $img);
				fclose($fp2);
				return $path . '/' . $filename;
			}
        } catch (\Exception $e) {
            return false;
        }
    }
}

if (!function_exists('make_path')) {

    /**
     * 上传路径转化,默认路径
     * @param $path
     * @param int $type
     * @param bool $force
     * @return string
     */
    function make_path($path, int $type = 2, bool $force = false)
    {
        $path = DS . ltrim(rtrim($path));
        switch ($type) {
            case 1:
                $path .= DS . date('Y');
                break;
            case 2:
                $path .= DS . date('Y') . DS . date('m');
                break;
            case 3:
                $path .= DS . date('Y') . DS . date('m') . DS . date('d');
                break;
        }
        try {
            if (is_dir(app()->getRootPath() . 'public' . DS . 'uploads' . $path) == true || mkdir(app()->getRootPath() . 'public' . DS . 'uploads' . $path, 0777, true) == true) {
                return trim(str_replace(DS, '/', $path), '.');
            } else return '';
        } catch (\Exception $e) {
            if ($force)
                throw new \Exception($e->getMessage());
            return '无法创建文件夹，请检查您的上传目录权限：' . app()->getRootPath() . 'public' . DS . 'uploads' . DS . 'attach' . DS;
        }

    }
}

if (!function_exists('check_phone')) {
    /**
     * 手机号验证
     * @param $phone
     * @return false|int
     */
    function check_phone($phone)
    {
        return preg_match("/^1[3456789]\d{9}$/", $phone);
    }
}

if (!function_exists('check_mail')) {
    /**
     * 邮箱验证
     * @param $mail
     * @return false|int
     */
    function check_mail($mail)
    {
        if (filter_var($mail, FILTER_VALIDATE_EMAIL)) {
           return true;
        } else {
           return false;
        }
    }
}

if (!function_exists('aj_captcha_check_one')) {
    /**
     * 验证滑块1次验证
     * @param string $token
     * @param string $pointJson
     * @return bool
     */
    function aj_captcha_check_one(string $captchaType, string $token, string $pointJson)
    {
		try {
			aj_get_serevice($captchaType)->check($token, $pointJson);
		} catch (\Throwable $e) {
			throw new ValidateException($e->getMessage());
		}
        return true;
    }
}

if (!function_exists('aj_captcha_check_two')) {
    /**
     * 验证滑块2次验证
     * @param string $token
     * @param string $pointJson
     * @return bool
     */
    function aj_captcha_check_two(string $captchaType, string $captchaVerification )
    {
		try {
			aj_get_serevice($captchaType)->verificationByEncryptCode($captchaVerification);
		} catch (\Throwable $e) {
			throw new ValidateException($e->getMessage());
		}
        return true;
    }
}

if (!function_exists('aj_captcha_create')) {
    /**
     * 创建验证码
     * @return array
     */
    function aj_captcha_create(string $captchaType)
    {
        return aj_get_serevice($captchaType)->get();
    }
}

if (!function_exists('aj_get_serevice')) {

    /**
     * @param string $captchaType
     * @return ClickWordCaptchaService|BlockPuzzleCaptchaService
     */
    function aj_get_serevice(string $captchaType)
    {
        $config = Config::get('ajcaptcha');
        switch ($captchaType) {
            case "clickWord":
                $service = new ClickWordCaptchaService($config);
                break;
            case "blockPuzzle":
                $service = new BlockPuzzleCaptchaService($config);
                break;
            default:
                throw new ValidateException('captchaType参数不正确！');
        }
        return $service;
    }
}

if (!function_exists('mb_substr_str')) {

    /**
     * 截取制定长度,并使用填充
     * @param string $value
     * @param int $length
     * @param string $str
     * @return string
     * @author 等风来
     * @email 136327134@qq.com
     * @date 2022/12/1
     */
    function mb_substr_str(string $value, int $length, string $str = '...', int $type = 0)
    {
        if (mb_strlen($value) > $length) {
            $value = mb_substr($value, 0, $length - mb_strlen($str)) . $str;
        }

        //等于1时去掉数组
        if ($type === 1) {
            $value = preg_replace('/[0-9]/', '', $value);
        }

        return $value;
    }
}
if (!function_exists('msectime')) {
	/**
	 * 毫秒时间戳
	 *
	 * @return float
	 */
	function msectime()
	{
		[$mSec, $sec] = explode(' ', microtime());

		return (float)sprintf('%.0f', (floatval($mSec) + floatval($sec)) * 1000);
	}
}

if (!function_exists('response_log_write')) {

	/**
	 * 日志写入
	 * @param array $data
	 * @param string $logType
	 * @param string $type
	 * @param $id
	 * @return void
	 */
	function response_log_write(array $data, string $logType = \think\Log::ERROR, string $type = '', int $id = 0)
	{
		$request = app()->make(Request::class);

		try {

			if (!$type || !$id) {
				foreach ([
							 'adminId' => 'admin',
							 'kefuId' => 'kefu',
							 'uid' => 'user',
							 'supplierId' => 'supplier',
							 'cashierId' => 'cashier',
							 'storeId' => 'store',
							 'outId' => 'out',
						 ] as $value => $vv) {
					if ($request->hasMacro($value)) {
						$id = $request->{$value}();
						$type = $vv;
					}
				}
			}

			//日志内容
			$log = [
				$id,//管理员ID
				$type,
				$request->ip(),//客户ip
				ceil(msectime() - ($request->time(true) * 1000)),//耗时（毫秒）
				$request->method(true),//请求类型
				str_replace("/", "", $request->rootUrl()),//应用
				$request->baseUrl(),//路由
				json_encode($request->param(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),//请求参数
				json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),//报错数据
			];

			Log::write(implode("|", $log), $logType);
		} catch (\Throwable $e) {

			$data = [
				'file' => $e->getFile(),
				'line' => $e->getLine(),
				'trace' => $e->getTrace(),
				'previous' => $e->getPrevious(),
			];
			Log::error(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

		}
	}
}

if (!function_exists('supplier_config')) {

	/**
	 * @param int $supplierId
	 * @param string $name
	 * @param null $default
	 * @return array|string|null
	 */
	function supplier_config(int $supplierId, string $name, $default = null)
	{
		if (empty($name)) {
			return $default;
		}
		/** @var SystemConfigService $configService */
		$configService = app('sysConfig');
		$configService->setSupplier($supplierId);
		$sysConfig = $configService->get($name);
		if (is_array($sysConfig)) {
			foreach ($sysConfig as &$item) {
				if (strpos($item, '/uploads/system/') !== false) {
					$item = set_file_url($item);
				}
			}
		} else {
			if (strpos($sysConfig, '/uploads/system/') !== false) {
				$sysConfig = set_file_url($sysConfig);
			}
		}
		$config = is_array($sysConfig) ? $sysConfig : trim($sysConfig);
		if ($config === '' || $config === false) {
			return $default;
		} else {
			return $config;
		}
	}
}

if (!function_exists('stringToIntArray')) {

	/**
	 * 处理ids等并过滤参数
	 * @param string $string
	 * @param string $separator
	 * @return array
	 */
	function stringToIntArray(string $string, string $separator = ',')
	{
		return !empty($string) ? array_unique(array_diff(array_map('intval', explode($separator, $string)), [0])) : [];
	}
}

if (!function_exists('getFileHeaders')) {

	/**
	 * 获取文件大小头部信息
	 * @param string $url
	 * @param $isData
	 * @return array
	 */
	function getFileHeaders(string $url, $isData = true)
	{
		stream_context_set_default(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
		$header['size'] = 0;
		$header['type'] = 'image/jpeg';
		if (!$isData) {
			return $header;
		}
		try {
			$headerArray = get_headers(str_replace('\\', '/', $url), true);
			if (!isset($headerArray['Content-Length'])) {
				$header['size'] = 0;
			} else {
				if (is_array($headerArray['Content-Length']) && count($headerArray['Content-Length']) == 2) {
					$header['size'] = $headerArray['Content-Length'][1];
				} else {
					$header['size'] = $headerArray['Content-Length'] ?? 0;
				}
			}
			if (!isset($headerArray['Content-Type'])) {
				$header['type'] = 'image/jpeg';
			} else {
				if (is_array($headerArray['Content-Type']) && count($headerArray['Content-Type']) == 2) {
					$header['type'] = $headerArray['Content-Type'][1];
				} else {
					$header['type'] = $headerArray['Content-Type'] ?? 'image/jpeg';
				}
			}
		} catch (\Exception $e) {
		}
		return $header;
	}
}

if (!function_exists('formatFileSize')) {

	/**
	 * 格式化文件大小
	 * @param $size
	 * @return mixed|string|null
	 */
	function formatFileSize($size)
	{
		if (!$size) {
			return '0KB';
		}
		try {
			$toKb = 1024;
			$toMb = $toKb * 1024;
			$toGb = $toMb * 1024;
			if ($size >= $toGb) {
				return round($size / $toGb, 2) . 'GB';
			} elseif ($size >= $toMb) {
				return round($size / $toMb, 2) . 'MB';
			} elseif ($size >= $toKb) {
				return round($size / $toKb, 2) . 'KB';
			} else {
				return $size . 'B';
			}
		} catch (\Exception $e) {
			return '0KB';
		}
	}

}


if (!function_exists('checkCoordinates')) {
	/**
	 * 检测经纬度数据
	 * @param $longitude
	 * @param $latitude
	 * @return bool
	 */
	function checkCoordinates($longitude, $latitude)
	{
		if ($longitude) {
			$longitudePattern = '/^(-?\d{1,3}(?:\.\d+)?)$/'; // 经度，允许1到3位整数，后面跟着小数
			if (!preg_match($longitudePattern, $longitude)) {
				return false; // 经度格式不正确
			}
			// 检查经纬度是否在有效范围内
			if (($longitude < -180) || ($longitude > 180)) {
				return false; // 经度超出范围
			}
		}
		if ($latitude) {
			$latitudePattern = '/^[-+]?([0-8]?\d(\.\d+)?|90(\.0+)?)$/'; // 纬度，允许-90到90，包括小数部分
			if (!preg_match($latitudePattern, $latitude)) {
				return false; // 纬度格式不正确
			}
			if (($latitude < -90) || ($latitude > 90)) {
				return false; // 纬度超出范围
			}
		}
		// 如果所有检查都通过，则返回true
		return true;
	}
}

if (!function_exists('convertIpToCity')) {
	/**
	 * ip转城市数据
	 * @param $ip
	 * @return array|false|mixed|string|string[]|null
	 */
	function convertIpToCity($ip)
	{
		try {
			$ip1num = 0;
			$ip2num = 0;
			$ipAddr1 = "";
			$ipAddr2 = "";
			$dat_path = public_path() . 'statics/ip.dat';
			if (!preg_match("/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/", $ip)) {
				return '';
			}
			if (!$fd = @fopen($dat_path, 'rb')) {
				return '';
			}
			$ip = explode('.', $ip);
			$ipNum = $ip[0] * 16777216 + $ip[1] * 65536 + $ip[2] * 256 + $ip[3];
			$DataBegin = fread($fd, 4);
			$DataEnd = fread($fd, 4);
			$ipbegin = implode('', unpack('L', $DataBegin));
			if ($ipbegin < 0) $ipbegin += pow(2, 32);
			$ipend = implode('', unpack('L', $DataEnd));
			if ($ipend < 0) $ipend += pow(2, 32);
			$ipAllNum = ($ipend - $ipbegin) / 7 + 1;
			$BeginNum = 0;
			$EndNum = $ipAllNum;
			while ($ip1num > $ipNum || $ip2num < $ipNum) {
				$Middle = intval(($EndNum + $BeginNum) / 2);
				fseek($fd, $ipbegin + 7 * $Middle);
				$ipData1 = fread($fd, 4);
				if (strlen($ipData1) < 4) {
					fclose($fd);
					return '';
				}
				$ip1num = implode('', unpack('L', $ipData1));
				if ($ip1num < 0) $ip1num += pow(2, 32);

				if ($ip1num > $ipNum) {
					$EndNum = $Middle;
					continue;
				}
				$DataSeek = fread($fd, 3);
				if (strlen($DataSeek) < 3) {
					fclose($fd);
					return '';
				}
				$DataSeek = implode('', unpack('L', $DataSeek . chr(0)));
				fseek($fd, $DataSeek);
				$ipData2 = fread($fd, 4);
				if (strlen($ipData2) < 4) {
					fclose($fd);
					return '';
				}
				$ip2num = implode('', unpack('L', $ipData2));
				if ($ip2num < 0) $ip2num += pow(2, 32);
				if ($ip2num < $ipNum) {
					if ($Middle == $BeginNum) {
						fclose($fd);
						return '';
					}
					$BeginNum = $Middle;
				}
			}
			$ipFlag = fread($fd, 1);
			if ($ipFlag == chr(1)) {
				$ipSeek = fread($fd, 3);
				if (strlen($ipSeek) < 3) {
					fclose($fd);
					return '';
				}
				$ipSeek = implode('', unpack('L', $ipSeek . chr(0)));
				fseek($fd, $ipSeek);
				$ipFlag = fread($fd, 1);
			}
			if ($ipFlag == chr(2)) {
				$AddrSeek = fread($fd, 3);
				if (strlen($AddrSeek) < 3) {
					fclose($fd);
					return '';
				}
				$ipFlag = fread($fd, 1);
				if ($ipFlag == chr(2)) {
					$AddrSeek2 = fread($fd, 3);
					if (strlen($AddrSeek2) < 3) {
						fclose($fd);
						return '';
					}
					$AddrSeek2 = implode('', unpack('L', $AddrSeek2 . chr(0)));
					fseek($fd, $AddrSeek2);
				} else {
					fseek($fd, -1, SEEK_CUR);
				}
				while (($char = fread($fd, 1)) != chr(0))
					$ipAddr2 .= $char;
				$AddrSeek = implode('', unpack('L', $AddrSeek . chr(0)));
				fseek($fd, $AddrSeek);
				while (($char = fread($fd, 1)) != chr(0))
					$ipAddr1 .= $char;
			} else {
				fseek($fd, -1, SEEK_CUR);
				while (($char = fread($fd, 1)) != chr(0))
					$ipAddr1 .= $char;
				$ipFlag = fread($fd, 1);
				if ($ipFlag == chr(2)) {
					$AddrSeek2 = fread($fd, 3);
					if (strlen($AddrSeek2) < 3) {
						fclose($fd);
						return '';
					}
					$AddrSeek2 = implode('', unpack('L', $AddrSeek2 . chr(0)));
					fseek($fd, $AddrSeek2);
				} else {
					fseek($fd, -1, SEEK_CUR);
				}
				while (($char = fread($fd, 1)) != chr(0)) {
					$ipAddr2 .= $char;
				}
			}
			fclose($fd);
			if (preg_match('/http/i', $ipAddr2)) {
				$ipAddr2 = '';
			}
			$ipaddr = $ipAddr1;
			$ipaddr = preg_replace('/CZ88.NET/is', '', $ipaddr);
			$ipaddr = preg_replace('/^s*/is', '', $ipaddr);
			$ipaddr = preg_replace('/s*$/is', '', $ipaddr);

			if (preg_match('/http/i', $ipaddr) || $ipaddr == '') {
				$ipaddr = '';
			}
			return strToUtf8($ipaddr);

		} catch (\Throwable $e) {
			return '';
		}
	}
}

if (!function_exists('strToUtf8')) {
	/**
	 * @param $str
	 * @return array|false|mixed|string|string[]|null
	 */
	function strToUtf8($str)
	{
		$encode = mb_detect_encoding($str, array("ASCII", 'UTF-8', "GB2312", "GBK", 'BIG5'));
		if ($encode == 'UTF-8') {
			return $str;
		} else {
			return mb_convert_encoding($str, 'UTF-8', $encode);
		}
	}
}


if (!function_exists('filter_str')) {
	/**
	 * 过滤字符串敏感字符
	 * @param $str
	 * @return array|mixed|string|string[]|null
	 * @throws Exception
	 */
	function filter_str($str)
	{
		$param_filter_data = sys_config('param_filter_data');
		$param_filter_type = sys_config('param_filter_type', 3);
		$rules = preg_split('/\r\n|\r|\n/', base64_decode($param_filter_data));
		if ($param_filter_data) {
			switch ($param_filter_type) {
				case 1://防火墙关闭
					return $str;
				case 2://报错
					foreach ($rules as $rule) {
						if (preg_match($rule, $str)) {
							throw new \Exception('您的参数存在非要请求,已被拦截');
						}
					}
					break;
				case 3://过滤
					if (filter_var($str, FILTER_VALIDATE_URL)) {
						$url = parse_url($str);
						if (!isset($url['scheme'])) return $str;
						$host = $url['scheme'] . '://' . $url['host'];
						$str = $host . preg_replace($rules, '', str_replace($host, '', $str));
					} else {
						$str = preg_replace($rules, '', $str);
					}
					return $str;
			}
		}
		return $str;
	}
}

if (!function_exists('check_sms_code')) {
	/**
	 * 检测短信验证码
	 * @param $phone
	 * @param $captcha
	 * @return bool
	 * @throws \Psr\SimpleCache\InvalidArgumentException
	 */
	function check_sms_code($phone, $captcha)
	{
		//验证验证码
		$verifyCode = CacheService::get('code_' . $phone);
		if (!$verifyCode)
			throw new ValidateException('请先获取验证码');
		$verifyError = (int)CacheService::get('code_error_' . $phone);
		if ($verifyError >= 10) {
			throw new ValidateException('请稍后在获取');
		}
		$verifyCode = substr($verifyCode, 0, 6);
		if ($verifyCode != $captcha) {
			CacheService::delete('code_' . $phone);
			CacheService::set('code_error_' . $phone, $verifyError + 1, 180);
			throw new ValidateException('验证码错误');
		}
		return true;
	}
}

if (!function_exists('hideString')) {
	/**
	 * 昵称等字符串隐私处理
	 * @param $str
	 * @return string
	 */
	function hideString($str) {
		if (empty($str)) return '';
		$length = mb_strlen($str, 'UTF-8');
		$first = mb_substr($str, 0, 1, 'UTF-8');
		if ($length <= 2) return $first . '****';
		$last = mb_substr($str, -1, 1, 'UTF-8');
		return $first . '***' . $last;
	}
}

if (!function_exists('getDistance')) {

    function getDistance($lat1, $lng1, $lat2, $lng2)
    {
        //将角度转为狐度
        $radLat1 = deg2rad($lat1); //deg2rad()函数将角度转换为弧度
        $radLat2 = deg2rad($lat2);
        $radLng1 = deg2rad($lng1);
        $radLng2 = deg2rad($lng2);
        $a = $radLat1 - $radLat2;
        $b = $radLng1 - $radLng2;
        $s = 2 * asin(sqrt(pow(sin($a / 2), 2) + cos($radLat1) * cos($radLat2) * pow(sin($b / 2), 2))) * 6371;
        return round($s, 1);
    }
}

if (!function_exists('lbs_address')) {

	/**
	 * 地址解析经纬度
	 * @param string $address
	 * @param string $region
	 * @return array|mixed|null
	 */
    function lbs_address(string $address, string $region = '')
    {
		if (!$address) return [];
		$key = sys_config('tengxun_map_key', '');
		if (!$key) {
			return [];
		}
		try {
			$locationOption = new \Joypack\Tencent\Map\Bundle\AddressOption($key);
			$locationOption->setAddress($address);
			if ($region) $locationOption->setRegion($region);
			$location = new \Joypack\Tencent\Map\Bundle\Address($locationOption);
			$res = $location->request();
			if ($res->error) {
				return [];
			}
			if ($res->status) {
				return [];
			}
			if (!$res->result) {
				return [];
			}
			return $res->result;
		} catch (\Throwable $e) {
			return [];
		}
    }
}

if (!function_exists('geoLbscoder')) {
	/**
	 * 经纬度解析地址
	 * @param string $latitude
	 * @param string $longitude
	 * @return array|mixed|null
	 */
	function geoLbscoder(string $latitude, string $longitude)
	{
		if (!$latitude || !$longitude) {
			return [];
		}
		$mapKey = sys_config('tengxun_map_key');
		if (!$mapKey) {
			return [];
		}
		try {
			$locationOption = new LocationOption($mapKey);
			$locationOption->setLocation($latitude, $longitude);
			$location = new Location($locationOption);
			$res = $location->request();
			if ($res->error) {
				return [];
			}
			if ($res->status) {
				return [];
			}
			if (!$res->result) {
				return [];
			}
			return $res->result;
		} catch (\Throwable $e) {
			return [];
		}
	}
}
