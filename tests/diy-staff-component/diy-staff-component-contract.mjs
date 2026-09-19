import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { resolve } from 'node:path';

const root = resolve(fileURLToPath(new URL('.', import.meta.url)), '../..');
const read = (path) => readFileSync(resolve(root, path), 'utf8');

const route = read('后端代码/route/api.php');
const controller = read('后端代码/app/controller/api/v1/store/Store.php');
const reservationController = read('后端代码/app/controller/api/v1/reservation/ReservationStaff.php');
const adminStaffController = read('后端代码/app/controller/admin/v1/merchant/SystemStoreStaff.php');
const service = read('后端代码/app/services/store/SystemStoreStaffServices.php');
const staffModel = read('后端代码/app/model/store/SystemStoreStaff.php');
const memberApi = read('前端代码/uniapp/api/store.js');
const memberIndex = read('前端代码/uniapp/pages/index/index.vue');
const memberComponent = read('前端代码/uniapp/pages/index/components/staffList.vue');
const therapistList = read('前端代码/uniapp/pages/activity/therapist_list/index.vue');
const adminPreview = read('前端代码/admin/src/components/mobilePage/home_staff_list.vue');
const adminConfig = read('前端代码/admin/src/components/mobileConfig/c_home_staff_list.vue');
const positionMultiple = read('前端代码/admin/src/components/mobileConfigRight/c_position_multiple.vue');

assert.match(route, /home\/staff_list.*getHomeStaffList/);
assert.match(controller, /function getHomeStaffList/);
assert.match(service, /function getHomeDisplayStaffList/);

const homeMethod = service.slice(
  service.indexOf('public function getHomeDisplayStaffList'),
  service.indexOf('public function getReservationStaffList')
);
assert.match(homeMethod, /'is_reservable'\s*=>\s*1/);
assert.match(homeMethod, /'status'\s*=>\s*1/);
assert.match(homeMethod, /'is_del'\s*=>\s*0/);
assert.match(homeMethod, /staff_intro/);
assert.match(homeMethod, /\$where\['position_ids'\]\s*=\s*\$selectedPositionIds/);
assert.match(homeMethod, /'position_id'\s*=>\s*\(int\)/);
assert.match(homeMethod, /'position_ids'\s*=>\s*array_values/);
assert.doesNotMatch(homeMethod, /'phone'\s*=>/);
assert.doesNotMatch(homeMethod, /'uid'\s*=>/);
assert.doesNotMatch(homeMethod, /getReservationStaffList\(/);

const reservationMethod = service.slice(
  service.indexOf('public function getReservationStaffList'),
  service.indexOf('protected function enrichStaffPerformanceRow')
);
assert.match(reservationMethod, /\$this->enrichStaffPositionLabels\(\$list\)/);

assert.match(adminPreview, /name:\s*'home_staff_list'/);
assert.match(adminPreview, /defaultName:\s*'staffList'/);
assert.match(adminPreview, /cname:\s*'员工展示'/);
assert.match(adminConfig, /name:\s*'c_home_staff_list'/);
assert.match(adminConfig, /c_position_multiple/);
assert.match(adminPreview, /positionConfig/);
assert.match(positionMultiple, /multiple/);
assert.match(positionMultiple, /position\(\)/);
assert.match(positionMultiple, /Number\(item\.value\)/);
assert.match(positionMultiple, /不选择表示全部岗位/);
assert.match(adminStaffController, /function staffPosition/);
assert.match(adminStaffController, /name\('position'\)[\s\S]*where\('status', 1\)[\s\S]*field\('id,name'\)/);

assert.match(staffModel, /function searchPositionIdsAttr/);
assert.match(staffModel, /name\('staff_job_position'\)/);
assert.match(staffModel, /whereIn\('position_id', \$ids\)/);
assert.doesNotMatch(staffModel, /whereIn\('position', \$ids\)/);
assert.match(controller, /position_ids/);
assert.match(reservationController, /position_ids/);

assert.match(memberApi, /home\/staff_list/);
assert.match(memberIndex, /item\.name == 'staffList'/);
assert.match(memberIndex, /import staffList from '\.\/components\/staffList'/);
assert.match(memberComponent, /selected_teacher/);
assert.match(memberComponent, /pages\/users\/user_card_list\/index/);
assert.match(memberComponent, /pages\/activity\/therapist_list\/index/);
assert.match(memberComponent, /selectedPositionIds/);
assert.match(memberComponent, /position_ids:/);
assert.match(therapistList, /this\.positionIds = String\(options\.position_ids/);
assert.match(therapistList, /position_ids: this\.positionIds\.join\(','\)/);
assert.doesNotMatch(memberComponent, /belong\/staff/);

console.log('diy staff component static contract: PASS');
