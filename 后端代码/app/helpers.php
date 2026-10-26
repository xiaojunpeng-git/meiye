<?php

use app\model\order\StoreOrder;
use think\facade\Route as Url;

if (!function_exists('get_mohe_version')) {
    function get_mohe_version()
    {
        return 5.0;
    }
}
if (!function_exists('substrUTf8')) {
    function substrUTf8($string,$length,$code="UTF-8",$begin="")
    {
        return mb_substr($string, 0, $length, 'UTF-8');
    }
}
if (!function_exists('curl_file_exist')) {
    function curl_file_exist($url,$timeout=5)
    {
        // 过滤非法URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        // 设置超时（PHP 5.1+ 支持）
        $context = stream_context_create([
            'http' => [
                'timeout' => $timeout,
                'method'  => 'HEAD' // 只请求头信息，不下载文件内容，提升效率
            ]
        ]);

        // 获取响应头
        $headers = @get_headers($url, 0, $context);

        // 无响应头直接返回不存在
        if ($headers === false) {
            return false;
        }

        // 解析HTTP状态码（200=存在，404=不存在，301/302=重定向需跟进）
        $statusCode = substr($headers[0], 9, 3);
        // 常见成功状态码：200（正常）、301/302（重定向，文件实际存在）
        return in_array($statusCode, ['200', '301', '302']);
    }
}
if (!function_exists('generateUnique32Str')) {
    function generateUnique32Str()
    {
        $unique32Str = md5(uniqid(rand(), true));
        $count = StoreOrder::where("unique", $unique32Str)->count();
        if ($count > 0) {
            $unique32Str = generateUnique32Str();
        }
        return $unique32Str;
    }
}
/**
 * 将本地或远程图片转换为 Base64 编码
 * @param string $imageUrl 图片路径（本地路径或远程 URL）
 * @return string|false 成功返回 Base64 编码字符串，失败返回 false
 */
if (!function_exists('image_to_base64')) {
    function image_to_base64($imageUrl)
    {
        // 读取图片内容（支持本地和远程）
        $context = stream_context_create([
            'http' => [
                'timeout' => 10 // 超时时间（秒）
            ]
        ]);
        $imageContent = file_get_contents($imageUrl, false, $context);
        if ($imageContent === false) {
            return false;
        }

        // 获取 MIME 类型
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->buffer($imageContent);

        // 检查是否为图片
        if (strpos($mimeType, 'image/') !== 0) {
            return false;
        }

        // 拼接 Base64 字符串
        return 'data:' . $mimeType . ';base64,' . base64_encode($imageContent);
    }
}
if (!function_exists('get_file_link')) {
    function get_file_link()
    {
        return "https://007.cc3798.com";
    }
}
if (!function_exists('anonymity')) {
    /**
     * 匿名处理处理用户昵称
     * @param $name
     * @return string
     */
    function anonymity($name)
    {
        $strLen = mb_strlen($name, 'UTF-8');
        $min = 3;
        if ($strLen <= 1)
            return '*';
        if ($strLen <= $min)
            return mb_substr($name, 0, 1, 'UTF-8') . str_repeat('*', $min - 1);
        else
            return mb_substr($name, 0, 1, 'UTF-8') . str_repeat('*', $strLen - 1) . mb_substr($name, -1, 1, 'UTF-8');
    }
}
if (!function_exists('check_link')) {
    function check_link()
    {
        return true;
    }
}
if (!function_exists('set_file_url')) {
    function set_file_url($url)
    {
        if(strstr($url,"http") != false){
            return $url;
        }
        return "https://007.cc3798.com" . $url;
    }
}
if (!function_exists('get_tree_children')) {
    function get_tree_children($list)
    {
        $append['sub_id'] = "value";
        $append['pid'] = "pid";
        $append['children'] = "children";
        $list = buildData($list, $append);
        $list = getCategoryTree(0, $list, $append);
        return $list;
    }
}
if (!function_exists('getCategoryTree')) {
    function getCategoryTree($index, $data, $append)
    {
        $children = $append["children"];
        $r = [];
        if(!isset($data[$index])){
            return $r;
        }
        foreach ($data[$index] as $id => $item) {
            if (isset($data[$id])) {
                $item[$children] = getCategoryTree($id, $data, $append);
            }
            $r[] = $item;
        }
        return $r;
    }
}
if (!function_exists('buildData')) {
    function buildData($data, $append)
    {
        $subId = $append["sub_id"];
        $pid = $append["pid"];
        $r = array();
        foreach ($data as $item) {
            $id = isset($item[$subId])?$item[$subId]:$item["id"];
            $parent_id = $item[$pid];
            $r[$parent_id][$id] = $item;
        }
        return $r;
    }
}
if (!function_exists('sort_list_tier')) {
    function sort_list_tier($data)
    {
        $sortAttr = [];
        $value = [];
        $isKey = true;
        foreach ($data as $nk => $nv) {
            if (!isset($nv['sort'])) {
                $isKey = false;
            } else {
                $sortAttr[$nk] = $nv['sort'];
            }
        }
        if (!$isKey) {
            return $data;
        } else {
            arsort($sortAttr);
            foreach ($sortAttr as $k => $v) {
                $value[] = $data[$k];
            }
            return $value;
        }
    }
}
// 应用公共文件
if (!function_exists('attr_format')) {
    /**
     * 格式化属性
     * @param $arr
     * @return array
     */
    function attr_format($arr)
    {
        $data = [];
        $res = [];
        $count = count($arr);
        if ($count > 1) {
            for ($i = 0; $i < $count - 1; $i++) {
                if ($i == 0) $data = $arr[$i]['detail'];
                //替代变量1
                $rep1 = [];
                foreach ($data as $v) {
                    foreach ($arr[$i + 1]['detail'] as $g) {
                        //替代变量2
                        $rep2 = ($i != 0 ? '' : $arr[$i]['value'] . '_$_') . $v . '-$-' . $arr[$i + 1]['value'] . '_$_' . $g;
                        $tmp[] = $rep2;
                        if ($i == $count - 2) {
                            foreach (explode('-$-', $rep2) as $k => $h) {
                                //替代变量3
                                $rep3 = explode('_$_', $h);
                                //替代变量4
                                $rep4['detail'][$rep3[0]] = isset($rep3[1]) ? $rep3[1] : '';
                            }
                            if($count == count($rep4['detail']))
                                $res[] = $rep4;
                        }
                    }
                }
                $data = isset($tmp) ? $tmp : [];
            }
        } else {
            $dataArr = [];
            foreach ($arr as $k => $v) {
                foreach ($v['detail'] as $kk => $vv) {
                    $dataArr[$kk] = $v['value'] . '_' . $vv;
                    $res[$kk]['detail'][$v['value']] = $vv;
                }
            }
            $data[] = implode('-', $dataArr);
        }
        return [$data, $res];
    }
}
// 应用公共文件
if (!function_exists('create_form')) {
    /**
     * 格式化属性
     * @param $arr
     * @return array
     */
    function create_form($title,$form,$action,$method="post")
    {
        $url=$action;
        if(is_array($url) || is_object($url)) {
            $url = $action->suffix(false)->domain(false)->build();
        }
        $res['action']=$url;
        $res['info']="";
        $res['method']=$method;
        $res['rules']=$form;
        $res['status']=true;
        $res['title']=$title;
        return $res;
    }
}
if (!function_exists('sys_data')) {
    /**
     * 获取系统单个配置
     * @param string $name
     * @return string
     */
    function sys_data(string $name, int $limit = 0)
    {
        return app('sysGroupData')->getData($name, $limit);
    }
}
if (!function_exists('sys_config')) {
    /**
     * 获取系统单个配置
     * @param string $name
     * @param string $default
     * @return string
     */
    function sys_config(string $name, $default = '')
    {
        if (empty($name))
            return $default;

        $config = app('sysConfig')->get($name);
        if ($config === '' || $config === false) {
            return $default;
        } else {
            return $config;
        }
    }
}
if (!function_exists('getFrontTime')) {
    function getFrontTime($start,$stop){
        return [$start,$stop,$start];
    }
}
if (!function_exists('check_card')) {
    /**
     * 身份证验证
     * @param $card
     * @return bool
     */
    function check_card($card)
    {
        $city = [11 => "北京", 12 => "天津", 13 => "河北", 14 => "山西", 15 => "内蒙古", 21 => "辽宁", 22 => "吉林", 23 => "黑龙江 ", 31 => "上海", 32 => "江苏", 33 => "浙江", 34 => "安徽", 35 => "福建", 36 => "江西", 37 => "山东", 41 => "河南", 42 => "湖北 ", 43 => "湖南", 44 => "广东", 45 => "广西", 46 => "海南", 50 => "重庆", 51 => "四川", 52 => "贵州", 53 => "云南", 54 => "西藏 ", 61 => "陕西", 62 => "甘肃", 63 => "青海", 64 => "宁夏", 65 => "新疆", 71 => "台湾", 81 => "香港", 82 => "澳门", 91 => "国外 "];
        $tip = "";
        $match = "/^\d{6}(18|19|20)?\d{2}(0[1-9]|1[012])(0[1-9]|[12]\d|3[01])\d{3}(\d|X)$/";
        $pass = true;
        if (!$card || !preg_match($match, $card)) {
            //身份证格式错误
            $pass = false;
        } else if (!$city[substr($card, 0, 2)]) {
            //地址错误
            $pass = false;
        } else {
            //18位身份证需要验证最后一位校验位
            if (strlen($card) == 18) {
                $card = str_split($card);
                //∑(ai×Wi)(mod 11)
                //加权因子
                $factor = [7, 9, 10, 5, 8, 4, 2, 1, 6, 3, 7, 9, 10, 5, 8, 4, 2];
                //校验位
                $parity = [1, 0, 'X', 9, 8, 7, 6, 5, 4, 3, 2];
                $sum = 0;
                $ai = 0;
                $wi = 0;
                for ($i = 0; $i < 17; $i++) {
                    $ai = $card[$i];
                    $wi = $factor[$i];
                    $sum += $ai * $wi;
                }
                $last = $parity[$sum % 11];
                if ($parity[$sum % 11] != $card[17]) {
                    //                        $tip = "校验位错误";
                    $pass = false;
                }
            } else {
                $pass = false;
            }
        }
        if (!$pass) return false;/* 身份证格式错误*/
        return true;/* 身份证格式正确*/
    }
}
if (!function_exists('filter_emoji')) {
    function filter_emoji($str)
    {
        $str = preg_replace_callback(    //执行一个正则表达式搜索并且使用一个回调进行替换
            '/./u',
            function (array $match) {
                return strlen($match[0]) >= 4 ? '' : $match[0];
            },
            $str);
        return $str;
    }
}
