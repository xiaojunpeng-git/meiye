<?php


namespace mohe\services\wechat;

use app\services\user\UserRechargeServices;
use mohe\services\wechat\config\MiniProgramConfig;
use app\model\user\UserRecharge;
use mohe\exceptions\PayException;
use mohe\services\wechat\config\OfficialAccountConfig;
use think\facade\Event;
use think\facade\Log;
use think\facade\Db;
use EasyWeChat\Factory;
/**
 * 富友支付对接
 * @package mohe\services
 */
class HwcPayService
{
    /**
     * 配置
     * @var array
     */
    protected $config = [
        'mchntCd' => '',//商户号
        'mchnt_key' => '',//商户秘钥
        'fuyou_name'=>''
    ];
    protected static $instance;

    protected function __construct(array $config = [])
    {
        $this->config=[
                'mchntCd'=>sys_config('fuyou_id'),
                'mchnt_key'=>sys_config('fuyou_key'),
                'fuyou_name'=>sys_config('fuyou_name'),
        ];
    }

    /**
     * 实例化
     * @param array $config
     * @return static
     */
    public static function instance(array $config = [])
    {
        if (is_null(self::$instance)) {
            self::$instance = new static($config);
        }
        return self::$instance;
    }
    public function getRandom($param){
        $str="0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ";
        $key = "";
        for($i=0;$i<$param;$i++)
        {
            $key .= $str[mt_rand(0,32)];    //生成php随机数
        }
        return $key;
    }
    //生成小程序url_link
    public function makUrl($qrcode,$price){
        //获取access_token
        $tokenData=Db::name("wechat_accesstoken")->where("id",1)->find();
        $time=time();
        $token="";
        if(!empty($tokenData) && $tokenData['expires_time'] > $time+7000){
             $token=$tokenData['access_token'];
        }
        if(empty($token)) {
            $make = app()->make(MiniProgramConfig::class);
            $config = $make->all();
            $app = Factory::officialAccount($config);
            $accessToken = $app->access_token;
            $tokenAttr = $accessToken->getToken(true);
            $token = $tokenAttr['access_token'] ?? '';
            if(empty($token)){
                throw new PayException('支付失败');
            }
            $save=[];
            $save['expires_time']=$time+$tokenAttr['expires_in'];
            $save['access_token']=$token;
            if(empty($tokenData)){
                Db::name("wechat_accesstoken")->insert($save);
            }else{
                Db::name("wechat_accesstoken")->where("id",$tokenData['id'])->update($save);
            }
        }
        $url="https://api.weixin.qq.com/wxa/generate_urllink?access_token=".$token;
        $path="/pages/app_pay/index";
//        $path="/pages/users/user_payment/index";
        $query='qrcode='.$qrcode."&price=".$price;
        $response=$this->http_post_json($url,json_encode([
            'path' => $path,
            'query' =>$query,
            'env_version'=>'release'
        ]));
        Log::error('支付失败：'.$response[1]);
        $response=json_decode($response[1],true);
        $link=$response['url_link'] ?? '';
        if(empty($link)){
            throw new PayException('支付失败:');
        }else {
            return $link;
        }
    }

    //支付宝支付
    public function payAlipay($orderId, $price,$successAction){
        $url="https://aipay.fuioupay.com/aggregatePay/preCreate";
        $post['version']='1.10';
        $post['mchnt_cd']=$this->config['mchntCd'];
        $post['random_str']=$this->getRandom(32);
        $post['order_type']="ALIPAY";
        $post['order_amt']=bcmul($price,100);//分为单位
        $post['mchnt_order_no']="13883".$orderId;;
        $post['txn_begin_ts']=date("YmdHis");
        $post['goods_des']=sys_config("fuyou_name");
        $post['term_id']=$this->getRandom(8);
        $post['term_ip']=app('request')->ip();
        $make = app()->make(MiniProgramConfig::class);
        $post['notify_url'] = trim($make->getConfig(DefaultConfig::COMMENT_URL)).'/api/pay/fynotify/'.$successAction;
        $post['sign']=$post['mchnt_cd']."|".$post['order_type']."|".$post['order_amt']."|".$post['mchnt_order_no']."|"
                .$post['txn_begin_ts']."|".$post['goods_des']."|".$post['term_id']."|".$post['term_ip']."|".$post['notify_url']."|"
                .$post['random_str']."|".$post['version']."|".$this->config['mchnt_key'];
        $post['sign'] = md5($post['sign']);
        $result=$this->http_post_json($url,json_encode($post));
        $result=json_decode($result[1],true);
        if($result['result_code'] == '000000'){
            //请求成功
            $return['url_link']=$result['qr_code'];
            return $return;
        }else{
            Log::error('支付宝失败：' . $result['resultMsg']);
            throw new PayException('失败原因:' . $result['resultMsg']);
        }
    }
    //微信APP支付
    public function payWxApp($orderId, $price,$openid="",$successAction,$type="mini"){
        $url="https://aipay.fuioupay.com/aggregatePay/wxPreCreate";
        switch ($type){
            case 'mini':
            case "app":
                $url="https://aipay.fuioupay.com/aggregatePay/preCreate";
                $make = app()->make(MiniProgramConfig::class);
                $post['notify_url'] = trim($make->getConfig(DefaultConfig::COMMENT_URL)).'/api/pay/fynotify/'.$successAction;
                $post['sub_appid'] = sys_config('fuyou_sub_appid');
                $post['order_type']='FLYPAY';
            break;
            case 'wechat':
            default:
               $make = app()->make(OfficialAccountConfig::class);
               $post['notify_url'] = trim($make->getConfig(DefaultConfig::COMMENT_URL)).'/api/pay/fynotify/'.$successAction;
               $post['sub_appid'] = $make->get('appId');
               $post['trade_type']='JSAPI';
               $post['sub_openid']=$openid;
            break;
        }
        $post['version']='1.10';
        $post['mchnt_cd']=$this->config['mchntCd'];
        $post['random_str']=$this->getRandom(32);
        $post['order_amt']=bcmul($price,100);//分为单位
        $post['mchnt_order_no']="13883".$orderId;
        $post['txn_begin_ts']=date("YmdHis");
        $post['goods_des']=sys_config("fuyou_name");
        $post['term_id']=$this->getRandom(8);
        $post['term_ip']=app('request')->ip();
        if($type == 'mini' || $type == 'app'){
            $post['sign']=$post['mchnt_cd']."|".$post['order_type']."|".$post['order_amt']."|".$post['mchnt_order_no']."|"
                .$post['txn_begin_ts']."|".$post['goods_des']."|".$post['term_id']."|".$post['term_ip']."|".$post['notify_url']."|"
                .$post['random_str']."|".$post['version']."|".$this->config['mchnt_key'];
        }else {
            $post['sign'] = $post['mchnt_cd'] . "|" . $post['trade_type'] . "|" . $post['order_amt'] . "|" . $post['mchnt_order_no'] . "|"
                . $post['txn_begin_ts'] . "|" . $post['goods_des'] . "|" . $post['term_id'] . "|" . $post['term_ip'] . "|" . $post['notify_url'] . "|"
                . $post['random_str'] . "|" . $post['version'] . "|" . $this->config['mchnt_key'];
        }
        $post['sign'] = md5($post['sign']);
        $result=$this->http_post_json($url,json_encode($post));
        $result=json_decode($result[1],true);
        if($result['result_code'] == '000000'){
            //请求成功
            if($type == 'mini'){
                $return['qr_code']=$result['qr_code'];
                $return['app_id']=sys_config("fuyou_sub_appid");
            }else if($type == 'app'){
                $return['url_link']=$this->makUrl($result['qr_code'],$price);
            } else {
                $return['app_id'] = $result['sdk_appid'];
                $return['timestamp'] = $result['sdk_timestamp'];
                $return['nonceStr'] = $result['sdk_noncestr'];
                $return['package'] = $result['sdk_package'];
                $return['package'] = $result['sdk_package'];
                $return['signType'] = $result['sdk_signtype'];
                $return['paySign'] = $result['sdk_paysign'];
            }
            return $return;
        }else{
            var_dump($result);
            die();
            Log::error('支付失败：' . $result['resultMsg']);
            throw new PayException('失败原因:' . $result['resultMsg']);
        }
    }

    public function notify($data,$type){
          $json=json_encode($data,true);
          if($data['result_code'] == 000000){
              //支付成功
              $order_id=$data['mchnt_order_no'];
              $trade_no=$data['transaction_id'];
              $sign= $data['mchnt_cd']."|".$data['mchnt_order_no']."|".$data['settle_order_amt']."|".$data['order_amt']."|"
                  .$data['txn_fin_ts']."|".$data['reserved_fy_settle_dt']."|".$data['random_str']."|".$this->config['mchnt_key'];
              $sign=md5($sign);
              $order_id=str_replace("13883wx","wx",$order_id);
              if($sign == $data['sign']) {
                     $notify['attach']=$type;
                     $notify['out_trade_no'] = $order_id;
                     $notify['trade_no'] = $trade_no;
                    $res = Event::until('pay.notify', [$notify]);
                    if ($res) {
                        return $res;
                    } else {
                        return false;
                    }
              }else{
                  Log::error('支付签名不正确！');
              }
          }else{
              Log::error('支付失败回调：' . $json);
          }
          return true;
    }
    public function http_post_json($url, $jsonStr)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonStr);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); //这个是重点,规避ssl的证书检查。
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/json; charset=utf-8',
                'Content-Length: ' . strlen($jsonStr)
            )
        );
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return array($httpCode, $response);
    }
}
