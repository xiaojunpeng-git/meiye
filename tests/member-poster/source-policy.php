<?php
// 真实来源策略的边界回归，不读取生产库、不下载任意地址。
require dirname(__DIR__, 2).'/后端代码/app/services/other/PosterImageSourcePolicy.php';
$policy = new \app\services\other\PosterImageSourcePolicy();
$origins = ['https://media.example.com', 'http://127.0.0.1:8080', 'https://mp.weixin.qq.com', 'https://thirdwx.qlogo.cn'];
$cases = [
    ['', true],
    ['https://media.example.com/item.png', true],
    ['https://MEDIA.example.com:443/item.JPG?cache=1', true],
    ['https://media.example.com/item.png?text=https://other.example.com', true],
    ['http://127.0.0.1:8080/uploads/code.jpg', true],
    ['http://127.0.0.1:8081/uploads/code.jpg', false],
    ['https://mp.weixin.qq.com/cgi-bin/showqrcode?ticket=example', true],
    ['https://thirdwx.qlogo.cn/mmopen/example/132', true],
    ['https://thirdwx.qlogo.cn/other/132', false],
    ['https://media.example.com.evil.example/item.png', false],
    ['https://media.example.com@evil.example/item.png', false],
    ['https://user@media.example.com/item.png', false],
    ['https://media.example.com:8443/item.png', false],
    ['http://media.example.com/item.png', false],
    ['file:///etc/image.png', false],
    ['data:image/png;base64,aaa', false],
    ['https://evil.example/item.png?url=https://mp.weixin.qq.com/cgi-bin/showqrcode', false],
    ['https://media.example.com/index.php?file=item.png', false],
];
foreach ($cases as [$url, $expected]) {
    if ($policy->allows($url, $origins) !== $expected) throw new RuntimeException('Unexpected source decision: '.$url);
}
if ($policy->allows('https://media.example.com/item.png', [])) throw new RuntimeException('Empty configuration allowed external image');
echo 'POSTER SOURCE PASS '.(count($cases)+1)."\n";
