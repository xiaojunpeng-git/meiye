import request from '@/plugins/request';

export const questionnaireList = params => request({ url: 'user/survey/questionnaires', method: 'get', params });
export const questionnaireOptions = keyword => request({ url: 'user/survey/questionnaire-options', method: 'get', params: { keyword } });
export const questionnaireRead = id => request({ url: `user/survey/questionnaires/${id}`, method: 'get' });
export const questionnaireAnalysis = id => request({ url: `user/survey/questionnaires/${id}/analysis`, method: 'get' });
export const questionnaireExportData = id => request({ url: `user/survey/questionnaires/${id}/export-data`, method: 'get' });
export const questionnaireTextAnswers = (id, params) => request({ url: `user/survey/questionnaires/${id}/analysis/text`, method: 'get', params });
export const questionnaireCreate = data => request({ url: 'user/survey/questionnaires', method: 'post', data });
export const questionnaireUpdate = (id, data) => request({ url: `user/survey/questionnaires/${id}`, method: 'put', data });
export const questionnaireGifts = id => request({ url: `user/survey/questionnaires/${id}/gifts`, method: 'get' });
export const questionnaireUpdateGifts = (id, data) => request({ url: `user/survey/questionnaires/${id}/gifts`, method: 'put', data });

export const surveyList = params => request({ url: 'user/survey', method: 'get', params });
export const surveyRead = id => request({ url: `user/survey/${id}`, method: 'get' });
export const surveyGifts = id => request({ url: `user/survey/${id}/gifts`, method: 'get' });
export const surveyUpdateGifts = (id, data) => request({ url: `user/survey/${id}/gifts`, method: 'put', data });
export const surveyCreate = data => request({ url: 'user/survey', method: 'post', data });
export const surveyUpdate = (id, data) => request({ url: `user/survey/${id}`, method: 'put', data });
export const surveyTargets = id => request({ url: `user/survey/${id}/targets`, method: 'get' });
export const surveyUpdateTargets = (id, filters) => request({ url: `user/survey/${id}/targets`, method: 'put', data: { filters }});
export const surveyPreview = filters => request({ url: 'user/survey/preview', method: 'post', data: { filters }});
export const surveySearchMembers = keyword => request({ url: 'user/survey/members', method: 'get', params: { keyword }});
export const surveyPublish = id => request({ url: `user/survey/${id}/publish`, method: 'post' });
export const surveyStop = id => request({ url: `user/survey/${id}/stop`, method: 'post' });
export const surveyResend = id => request({ url: `user/survey/${id}/resend`, method: 'post' });
export const surveyResults = (id, params) => request({ url: `user/survey/${id}/results`, method: 'get', params });
// 单任务全部轮次的门店汇总和明细由后端统一生成，不复用问卷跨任务分析接口。
export const surveyExportData = id => request({ url: `user/survey/${id}/export-data`, method: 'get' });

// 任务二维码与010奖励目录，均使用当前平台会话。
export const surveyQr = id => request({ url: `user/survey/${id}/qr`, method: 'get' });
export const surveyGiftOptions = (store_id, keyword = '') => request({ url: 'user/survey/gift-options', method: 'get', params: { store_id, keyword } });
export const surveyGiftCatalog = (store_id, params) => request({ url: 'user/survey/gift-catalog', method: 'get', params: { ...params, store_id } });
export const surveyRetryRewards = id => request({ url: `user/survey/${id}/retry-rewards`, method: 'post' });
