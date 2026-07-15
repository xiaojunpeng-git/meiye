import request from '@/plugins/request';
import axios from 'axios';
import Setting from '@/setting';
import util from '@/libs/util';

/** 门店可见培训资料列表（已发布 + 端/门店/角色可见性） */
export function trainingDocumentListApi(params) {
  return request({
    url: 'training/document/list',
    method: 'get',
    params,
  });
}

/**
 * 受控下载：带门店 token 拉文件流（不走 JSON 拦截器）
 * @returns {Promise<{ blob: Blob, fileName: string }>}
 */
export function trainingDocumentDownloadApi(id, fileName) {
  const token = util.cookies.get('token');
  return axios({
    url: `${Setting.apiBaseURL}/training/document/download/${id}`,
    method: 'get',
    responseType: 'blob',
    headers: {
      'Authori-zation': token ? `Bearer ${token}` : '',
      'X-Source': 'f76d38d0ee4f854f',
    },
    withCredentials: true,
  }).then((res) => {
    const disposition = (res.headers && (res.headers['content-disposition'] || res.headers['Content-Disposition'])) || '';
    let name = fileName || `training-${id}`;
    const matched = /filename\*?=(?:UTF-8'')?["']?([^"';]+)/i.exec(disposition);
    if (matched && matched[1]) {
      try {
        name = decodeURIComponent(matched[1].replace(/['"]/g, '').trim());
      } catch (e) {
        name = matched[1].replace(/['"]/g, '').trim();
      }
    }
    const contentType = (res.headers && res.headers['content-type']) || '';
    if (contentType.indexOf('application/json') !== -1) {
      return res.data.text().then((text) => {
        let msg = '下载失败';
        try {
          const json = JSON.parse(text);
          msg = json.msg || json.message || msg;
        } catch (e) {
          /* ignore */
        }
        return Promise.reject({ msg });
      });
    }
    return { blob: res.data, fileName: name };
  });
}
