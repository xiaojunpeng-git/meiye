import request from '@/plugins/request';

// 查询、下钻与导出只传筛选条件，授权范围和净业绩均由同一服务端事实查询决定。
const base = 'marketing/combination/promoter-report';
export const getPromoterOptions = () => request({ url: `${base}/options`, method: 'get' });
export const getPromoterSummary = params => request({ url: `${base}/summary`, method: 'get', params });
export const getPromoterDetails = params => request({ url: `${base}/details`, method: 'get', params });
export const exportPromoterSummary = params => request({ url: `${base}/export-summary`, method: 'get', params, responseType: 'blob' });
export const exportPromoterDetails = params => request({ url: `${base}/export-details`, method: 'get', params });
