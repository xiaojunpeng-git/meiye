import request from '@/plugins/request';

export function getScheduleCalendar(params) {
  return request({
    url: 'schedule/calendar',
    method: 'get',
    params,
  });
}

export function getScheduleDayDetail(params) {
  return request({
    url: 'schedule/day',
    method: 'get',
    params,
  });
}

export function saveScheduleDay(data) {
  return request({
    url: 'schedule/day/save',
    method: 'post',
    data,
  });
}

export function getSelectableScheduleStaff(params) {
  return request({
    url: 'schedule/staff/selectable',
    method: 'get',
    params,
  });
}

export function getScheduleExportList(params) {
  return request({
    url: 'schedule/export',
    method: 'get',
    params,
  });
}

export function getShiftList() {
  return request({
    url: 'shift/list',
    method: 'get',
  });
}

export function saveShift(data) {
  return request({
    url: 'shift/save',
    method: 'post',
    data,
  });
}

export function deleteShift(id) {
  return request({
    url: `shift/del/${id}`,
    method: 'delete',
  });
}
