import request from '@/utils/request';
// 二维码读取允许匿名，提交、领奖与提醒均由会员认证接口保护。
export const surveyEntry = code => request.get('survey/entry/' + encodeURIComponent(code), {}, { noAuth: true });
export const surveyDetail = id => request.get('survey/' + id);
export const surveySubmit = (id, answers, send_round) => request.post('survey/' + id + '/submit', { answers, send_round });
export const surveyRetry = id => request.post('survey/' + id + '/reward/retry');
export const surveyPending = () => request.get('survey/pending');
export const surveyClaim = id => request.post('survey/' + id + '/claim', { repeat_on_entry: 1 });
