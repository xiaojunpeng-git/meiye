import request from '@/plugins/request';

// 大屏、下钻与导出使用同一已提交筛选；权限与业绩计算始终由服务端完成。
const base = 'marketing/combination/dashboard';
export const getCombinationDashboard = params => request({ url: base, method: 'get', params });
export const getCombinationDashboardSettings = () => request({ url: `${base}/settings`, method: 'get' });
export const saveCombinationDashboardSettings = data => request({ url: `${base}/settings`, method: 'post', data });
export const getCombinationDashboardActivities = () => request({ url: `${base}/activities`, method: 'get' });
export const getCombinationDashboardDetails = params => request({ url: `${base}/details`, method: 'get', params });
export const exportCombinationDashboardDetails = params => request({ url: `${base}/export`, method: 'get', params });
