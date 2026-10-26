// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2021 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------

import { spread } from "@/api/user";
import Cache from "@/utils/cache";

/**
 * 绑定用户授权
 * @param {Object} puid
 */
export function silenceBindingSpread(app)
{
	
	let puid = 0,code = 0;
	
	// #ifdef H5
	puid = Cache.get('spid');
	// #endif
	// #ifndef H5
	if (app === undefined) {
		app = getApp();
	}
	puid = app.globalData.spid;
	code = app.globalData.code;
	// #endif
	
	puid = Number(puid);
	if(isNaN(puid)){
		puid = 0;
	}
	if(puid){
		spread({puid,code}).then(res=>{
			//#ifdef H5
			 Cache.clear('spid');
			//#endif
			// #ifndef H5
			app.globalData.spid = 0;
			app.globalData.code = 0;
			// #endif
		}).catch(res=>{
		});
	}
}

export function isWeixin() {
  return navigator.userAgent.toLowerCase().indexOf("micromessenger") !== -1;
}

export function parseQuery() {
  const res = {};

  const query = (location.href.split("?")[1] || "")
    .trim()
    .replace(/^(\?|#|&)/, "");

  if (!query) {
    return res;
  }

  query.split("&").forEach(param => {
    const parts = param.replace(/\+/g, " ").split("=");
    const key = decodeURIComponent(parts.shift());
    const val = parts.length > 0 ? decodeURIComponent(parts.join("=")) : null;

    if (res[key] === undefined) {
      res[key] = val;
    } else if (Array.isArray(res[key])) {
      res[key].push(val);
    } else {
      res[key] = [res[key], val];
    }
  });

  return res;
}

// #ifdef H5
	const VUE_APP_WS_URL = process.env.VUE_APP_WS_URL || `ws://${location.hostname}`;
	export {VUE_APP_WS_URL}
// #endif



export default parseQuery;
