import request from '@/plugins/request';

export const trainingDocumentList = (params) => request({ url: 'training/document/list', method: 'get', params });
export const trainingDocumentSave = (data) => request({ url: 'training/document/save', method: 'post', data });
export const trainingDocumentStatus = (id, status) => request({ url: `training/document/status/${id}`, method: 'post', data: { status } });
export const trainingDocumentUploadUrl = () => 'training/document/upload';
