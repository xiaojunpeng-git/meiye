<?php
namespace app\services\other;

/** 海报转码只读可信媒体来源；不能把客户端 URL 或历史图片域名变成任意地址代理。 */
class PosterImageSourcePolicy
{
    /** 将配置与请求地址按 origin 精确比较，保留端口，拒绝凭证及非 HTTP 协议。 */
    private function origin(string $url): string
    {
        $parts = parse_url($url);
        if (!$parts || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return '';
        }
        $scheme = strtolower($parts['scheme']);
        $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        return $scheme . '://' . strtolower($parts['host']) . ':' . $port;
    }

    /** 空素材维持原可选语义；非空素材必须是可信源的图片或微信二维码/头像接口。 */
    public function allows(string $url, array $origins): bool
    {
        if ($url === '') return true;
        $origin = $this->origin($url);
        if ($origin === '' || !in_array($origin, array_filter(array_map([$this, 'origin'], $origins)), true)) return false;
        $parts = parse_url($url);
        $path = $parts['path'] ?? '';
        if (preg_match('/\.(png|jpe?g|gif)$/i', $path)) return true;
        // 微信图片接口没有扩展名，只接受原有明确路径，不能用查询串伪装来源。
        return ($origin === 'https://mp.weixin.qq.com:443' && $path === '/cgi-bin/showqrcode')
            || ($origin === 'https://thirdwx.qlogo.cn:443' && strpos($path, '/mmopen/') === 0);
    }
}
