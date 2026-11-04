// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2021 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------

module.exports = {

	// 小程序配置
	// #ifdef MP || APP-PLUS
	// 瑞昊服务器 rh.cc3798.com → 库 ruihao
	HTTP_REQUEST_URL: `https://rh.cc3798.com`,
	// 长连接 格式：wss://您的域名
	VUE_APP_WS_URL: `wss://rh.cc3798.com/ws`,
	// #endif
	// H5配置：接口域名跟随浏览器访问域名，换域名无需重新打包
	// #ifdef H5
	HTTP_REQUEST_URL: (function () {
		var host = window.location.host || '';
		var hostname = window.location.hostname || '';
		var protocol = window.location.protocol;
		// HBuilderX 本机端口 → 后端 Docker 8080
		if (/^(127\.0\.0\.1|localhost)$/.test(hostname) && host !== '127.0.0.1:8080' && host !== 'localhost:8080') {
			return protocol + '//127.0.0.1:8080';
		}
		// 真机用局域网 IP 访问 HBuilderX 端口时，接口仍走同机 8080
		if (/^(192\.168\.|10\.|172\.(1[6-9]|2\d|3[01])\.)/.test(hostname) && !/:8080$/.test(host)) {
			return protocol + '//' + hostname + ':8080';
		}
		return protocol + '//' + host;
	})(),
	// 长连接地址，非单独部署不用修改
	VUE_APP_WS_URL: (function () {
		var host = window.location.host || '';
		var hostname = window.location.hostname || '';
		var proto = window.location.protocol === 'https:' ? 'wss' : 'ws';
		if (/^(127\.0\.0\.1|localhost)$/.test(hostname) && host !== '127.0.0.1:8080' && host !== 'localhost:8080') {
			return proto + '://127.0.0.1:8080/ws';
		}
		if (/^(192\.168\.|10\.|172\.(1[6-9]|2\d|3[01])\.)/.test(hostname) && !/:8080$/.test(host)) {
			return proto + '://' + hostname + ':8080/ws';
		}
		return proto + '://' + host + '/ws';
	})(),
	// #endif


	// 以下配置在不做二开的前提下,不需要做任何的修改
	HEADER: {
		'content-type': 'application/json',
		'X-Source': 'f76d38d0ee4f854f',
		//#ifdef H5
		'Form-type': navigator.userAgent.toLowerCase().indexOf("micromessenger") !== -1 ? 'wechat' : 'h5',
		//#endif
		//#ifdef MP
		'Form-type': 'routine',
		//#endif
		//#ifdef APP-PLUS
		'Form-type': 'app',
		//#endif
	},
	// 回话密钥名称 请勿修改此配置
	TOKENNAME: 'Authori-zation',
	// 缓存时间 0 永久
	EXPIRE: 0,
	//分页最多显示条数
	LIMIT: 100
}
