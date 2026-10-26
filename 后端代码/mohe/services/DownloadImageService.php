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

namespace mohe\services;


use think\exception\ValidateException;
use think\Image;

class DownloadImageService
{
    //是否生成缩略图
    protected $thumb = false;
    //缩略图宽度
    protected $thumbWidth = 300;
    //缩略图高度
    protected $thumHeight = 300;
    //存储位置
    protected $path = 'attach';

    protected $rules = ['thumb', 'thumbWidth', 'thumHeight', 'path'];

    /**
     * 获取即将要下载的图片扩展名
     * @param string $url
     * @param string $ex
     * @return array|string[]
     */
    public function getImageExtname($url = '', $ex = 'jpg')
    {
        $_empty = ['file_name' => '', 'ext_name' => $ex];
        if (!$url) return $_empty;
        if (strpos($url, '?')) {
            $_tarr = explode('?', $url);
            $url = trim($_tarr[0]);
        }
        $arr = explode('.', $url);
        if (!is_array($arr) || count($arr) <= 1) return $_empty;
        $ext_name = trim($arr[count($arr) - 1]);
        $ext_name = !$ext_name ? $ex : $ext_name;
        return ['file_name' => md5($url) . '.' . $ext_name, 'ext_name' => $ext_name];
    }

    /**
     * 下载图片
     * @param string $url
     * @param string $name
     * @param int $upload_type
     * @return mixed
     */
    public function downloadImage(string $url, $name = '')
    {
        if (!$name) {
            // 获取要下载的文件名称
            $downloadImageInfo = $this->getImageExtname($url);
			$ext = $downloadImageInfo['ext_name'];
            $name = $downloadImageInfo['file_name'];
            if (!$name) throw new ValidateException('上传图片不存在');
        } else {
			$ext = $this->getImageExtname($name)['ext_name'];
		}
		if (!in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bpm', 'tif', 'pcx', 'tga', 'exif', 'fpx', 'svg', 'apng', 'xlsx'])) {
            $headerArray = get_headers(str_replace('\\', '/', $url), true);
            if (is_array($headerArray['Content-Type']) && count($headerArray['Content-Type']) == 2) {
                $type = $headerArray['Content-Type'][1];
            } else {
                $type = $headerArray['Content-Type'];
            }
            if(!$type) throw new ValidateException('格式错误，文件后缀不允许');
            $img = explode('/',$type);
            if($img[0]!= 'image') throw new ValidateException('格式错误，文件后缀不允许');
		}
        if (strstr($url, 'http://') === false && strstr($url, 'https://') === false) {
            $url = 'http:' . $url;
        }
        $url = str_replace('https://', 'http://', $url);
        if ($this->path == 'attach') {
            $date_dir = date('Y') . DIRECTORY_SEPARATOR . date('m') . DIRECTORY_SEPARATOR . date('d');
            $to_path = $this->path . '/' . $date_dir;
        } else {
            $to_path = $this->path;
        }
        $upload = UploadService::init(1);
        if (!file_exists($upload->uploadDir($to_path) . '/' . $name)) {
            ob_start();
            readfile($url);
            $content = ob_get_contents();
            ob_end_clean();
            $size = strlen(trim($content));
            if (!$content || $size <= 2) throw new ValidateException('图片流获取失败');
            if ($upload->to($to_path)->down($content, $name) === false) {
                throw new ValidateException('图片下载失败');
            }
            $imageInfo = $upload->getDownloadInfo();
            $path = $imageInfo['dir'];
            if ($this->thumb) {
                Image::open(root_path() . 'public' . $path)->thumb($this->thumbWidth, $this->thumHeight)->save(root_path() . 'public' . $path);
                $this->thumb = false;
            }
        } else {
            $path = '/uploads/' . $to_path . '/' . $name;
            $imageInfo['name'] = $name;
        }
        $date['path'] = $path;
        $date['name'] = $imageInfo['name'];
        $date['size'] = $imageInfo['size'] ?? '';
        $date['mime'] = $imageInfo['type'] ?? '';
        $date['image_type'] = 1;
        $date['is_exists'] = false;
        return $date;
    }

    /**
     * 网络图片下载
     * @param $url
     * @param $name
     * @param $type
     * @param $timeout
     * @param $w
     * @param $h
     * @return array|string
     */
    public function downloadOnlineImage($url = '', $name = '', $type = 0, $timeout = 30, $w = 0, $h = 0)
    {
        if (!strlen(trim($url))) return '';
        if (!strlen(trim($name))) {
            //TODO 获取要下载的文件名称
            $downloadImageInfo = $this->getImageExtname($url);
            $ext = $downloadImageInfo['ext_name'];
            $name = $downloadImageInfo['file_name'];
            if (!strlen(trim($name))) throw new ValidateException('上传图片不存在');
        } else {
            $ext = $this->getImageExtname($name)['ext_name'];
        }
        if (!in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bpm', 'tif', 'pcx', 'tga', 'exif', 'fpx', 'svg', 'apng'])) {
            $headerArray = get_headers(str_replace('\\', '/', $url), true);
            if (is_array($headerArray['Content-Type']) && count($headerArray['Content-Type']) == 2) {
                $contentType = $headerArray['Content-Type'][1];
            } else {
                $contentType = $headerArray['Content-Type'];
            }
            if(!$contentType) throw new ValidateException('格式错误，文件后缀不允许');
            $img = explode('/',$contentType);
            if($img[0]!= 'image') throw new ValidateException('格式错误，文件后缀不允许');
        }
        if (strstr($url, 'http://') === false && strstr($url, 'https://') === false) {
            $url = 'http:' . $url;
        }
        $url = str_replace('https://', 'http://', $url);
        if ($this->path == 'attach') {
            $date_dir = date('Y') . DIRECTORY_SEPARATOR . date('m') . DIRECTORY_SEPARATOR . date('d');
            $to_path = $this->path . '/' . $date_dir;
        } else {
            $to_path = $this->path;
        }
        //TODO 获取远程文件所采用的方法
        if ($type) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); //TODO 跳过证书检查
            if (stripos($url, "https://") !== FALSE) curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);  //TODO 从证书中检查SSL加密算法是否存在
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('user-agent:' . $_SERVER['HTTP_USER_AGENT']));
            if (ini_get('open_basedir') == '' && ini_get('safe_mode') == 'Off') curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);//TODO 是否采集301、302之后的页面
            $content = curl_exec($ch);
            curl_close($ch);
        } else {
            try {
                ob_start();
                if (substr($url, 0, 2) == '//') {
                    $url = "https:" . $url;
                }
                readfile($url);
                $content = ob_get_contents();
                ob_end_clean();
            } catch (\Exception $e) {
                throw new ValidateException($e->getMessage());
            }
        }
        $size = strlen(trim($content));
        if (!$content || $size <= 2) throw new ValidateException('图片流获取失败');
        $upload_type = sys_config('upload_type', 1);
        $upload = UploadService::init($upload_type);
        if ($upload->to($to_path)->validate()->setAuthThumb(false)->stream($content, $name) === false) {
            throw new ValidateException($upload->getError());
        }
        $imageInfo = $upload->getUploadInfo();
        $date['path'] = $imageInfo['dir'];
        $date['name'] = $imageInfo['name'];
        $date['size'] = $imageInfo['size'];
        $date['mime'] = $imageInfo['type'];
        $date['image_type'] = $upload_type;
        $date['is_exists'] = false;
        return $date;
    }

    /**
     * @param $name
     * @param $arguments
     * @return $this
     */
    public function __call($name, $arguments)
    {
        if (in_array($name, $this->rules)) {
            if ($name === 'path') {
                $this->{$name} = $arguments[0] ?? 'attach';
            } else {
                $this->{$name} = $arguments[0] ?? null;
            }
            return $this;
        } else {
            throw new \RuntimeException('Method does not exist' . __CLASS__ . '->' . $name . '()');
        }
    }
}
