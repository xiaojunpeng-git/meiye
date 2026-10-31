/**
 * 商城导入图与上传图片属于当前客户服务器资源，不属于小程序包内文件。
 * 只解析这两个命名空间；包内海报素材、CDN、data 和原生临时文件保持原义。
 * 域名必须来自合法客户配置，不能回退到另一客户或把路径当成域名。
 */
export function resolveServerAssetUrl(value, origin) {
	if (typeof value !== 'string' || !/^\/(?:static\/mall\/|\/*uploads\/)/.test(value)) return value;
	const base = typeof origin === 'string' ? origin.replace(/\/+$/, '') : '';
	return /^https?:\/\/[a-z0-9.-]+(?::\d+)?$/i.test(base) ? base + '/' + value.replace(/^\/+/, '') : value;
}
