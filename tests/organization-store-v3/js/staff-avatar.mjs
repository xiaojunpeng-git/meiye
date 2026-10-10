import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const admin = path.join(root, '前端代码/admin');
const require = createRequire(path.join(admin, 'package.json'));
const babel = require('@babel/core');
const compiler = require('vue-template-compiler');
const setting = { apiBaseURL: 'https://api.example.test/adminapi' };
const images = {};
for (const type of ['male', 'female']) {
  const bytes = fs.readFileSync(path.join(admin, `public/static/images/staff/avatar_${type}.png`));
  assert.equal(bytes.subarray(1, 4).toString(), 'PNG');
  images[type] = `data:image/png;base64,${bytes.toString('base64')}`;
}

// Run the real modules/component methods, replacing only external APIs; never save real staff or upload files.
function load(source, filename, mocks) {
  const module = { exports: {} };
  const { code } = babel.transformSync(source, {
    filename, babelrc: false, configFile: false,
    plugins: [require.resolve('@babel/plugin-transform-modules-commonjs')],
  });
  vm.runInNewContext(code, { module, exports: module.exports, require: mocks }, { filename });
  return module.exports;
}
const utility = load(fs.readFileSync(path.join(admin, 'src/utils/staffAvatar.js'), 'utf8'), 'staffAvatar.js', id => {
  if (id === '@/setting') return setting;
  if (id.endsWith('avatar_male.png')) return images.male;
  if (id.endsWith('avatar_female.png')) return images.female;
  throw Error(`Unexpected dependency ${id}`);
});
const { DEFAULT_STAFF_AVATAR, resolveStaffAvatar, onStaffAvatarError, normalizeStaffAvatarValue } = utility;
for (const value of [undefined, null, '', '   ']) assert.equal(resolveStaffAvatar(value), images.male);
console.log('PASS empty employee avatar uses bundled blue image, not database or API');
for (const type of ['male', 'female']) {
  for (const value of [`/static/images/staff/avatar_${type}.png`, `https://old.test/static/images/staff/avatar_${type}.svg?v=1#avatar`]) {
    assert.equal(resolveStaffAvatar(value), images[type]);
    assert.equal(normalizeStaffAvatarValue(value), `/static/images/staff/avatar_${type}.png`);
  }
}
console.log('PASS historical male/female defaults resolve locally and serialize stable identifiers');
assert.equal(resolveStaffAvatar('/uploads/person.png'), 'https://api.example.test/uploads/person.png');
assert.equal(resolveStaffAvatar('http://127.0.0.1/static/person.png'), 'https://api.example.test/static/person.png');
assert.equal(resolveStaffAvatar('https://cdn.test/avatar_male.png'), 'https://cdn.test/avatar_male.png');
assert.equal(resolveStaffAvatar('//cdn.test/person.png'), '//cdn.test/person.png');
setting.apiBaseURL = '/adminapi';
assert.equal(resolveStaffAvatar('/uploads/person.png'), '/uploads/person.png');
setting.apiBaseURL = 'https://store.example.test/storeapi/';
assert.equal(resolveStaffAvatar('/uploads/person.png'), 'https://store.example.test/uploads/person.png');
assert.equal(resolveStaffAvatar(null), images.male);
console.log('PASS real uploads preserve URL handling; default is independent of API host');

let current = '/uploads/missing.png';
let replacements = 0;
const image = { getAttribute: () => current, set src(value) { current = value; replacements++; } };
onStaffAvatarError({ currentTarget: image });
onStaffAvatarError({ currentTarget: image });
onStaffAvatarError(null);
assert.equal(current, images.male);
assert.equal(replacements, 1);
assert.equal(normalizeStaffAvatarValue('/uploads/missing.png'), '/uploads/missing.png');
console.log('PASS broken images fall back once without modifying persisted avatar');

const requiredCheck = load(fs.readFileSync(path.join(admin, 'src/utils/requiredCheck.js'), 'utf8'), 'requiredCheck.js', () => { throw Error('Unexpected dependency'); });
const addSource = fs.readFileSync(path.join(admin, 'src/pages/setting/staff/add.vue'), 'utf8');
const parsed = compiler.parseComponent(addSource);
const compiled = compiler.compile(parsed.template.content);
assert.deepEqual(compiled.errors, []);
assert.equal((parsed.template.content.match(/class="avatar-option upload"/g) || []).length, 1);
assert.doesNotMatch(parsed.template.content, /selectDefaultAvatar|avatar-male|avatar-female/);
assert.match(parsed.template.content, /@error="onStaffAvatarError"/);
const submitted = [];
const response = payload => { submitted.push(payload); return Promise.resolve({ data: {}, msg: 'saved' }); };
const component = load(parsed.script.content, 'staff-add.js', id => {
  if (id === 'vuex') return { mapState: () => ({}) };
  if (id === '@/utils/staffAvatar') return utility;
  if (id === '@/utils/requiredCheck') return requiredCheck;
  if (id === '@/api/staff.js') return { postStaff: response, postPersonComplete: response };
  return {};
}).default;
function context() {
  const state = {
    editId: 0, scene: '', canSaveStaff: true, canEditEmploymentType: true,
    hasAppointmentStore: false, $refs: {}, $emit() {}, $nextTick(callback) { callback(); },
    $Message: { success() {}, required(message) { throw Error(message); }, error(message) { throw Error(message); } },
  };
  Object.assign(state, component.data.call(state));
  for (const [name, method] of Object.entries(component.methods)) state[name] = method.bind(state);
  Object.assign(state.formInline, { org_id: 1, staff_name: 'Avatar fixture', phone: '19900007104', position_ids: [1] });
  return state;
}
const state = context();
assert.equal(state.formInline.avatar, DEFAULT_STAFF_AVATAR);
assert.equal(state.resolveStaffAvatar(state.formInline.avatar), images.male);
state.ruleValidate.avatar[0].validator(null, state.formInline.avatar, error => assert.equal(error, undefined));
console.log('PASS new staff starts with valid default preview and only one upload control');

state.modalPicTap('单选', 'avatar');
assert.equal(state.modalPic, true);
assert.equal(state.picTit, 'avatar');
state.getPic({ att_dir: '/uploads/person.png' });
assert.equal(state.modalPic, false);
assert.equal(state.formInline.avatar, '/uploads/person.png');
assert.equal(state.resolveStaffAvatar(state.formInline.avatar), 'https://store.example.test/uploads/person.png');
console.log('PASS existing image picker still replaces the preview and closes normally');

state.resetForm();
assert.equal(state.formInline.avatar, DEFAULT_STAFF_AVATAR);
for (const avatar of [undefined, '/uploads/person.png', '/static/images/staff/avatar_female.svg']) {
  state.applyPersonComplete({ employee_id: 101, avatar });
  assert.equal(state.formInline.avatar, avatar || DEFAULT_STAFF_AVATAR);
  assert.equal(state.resolveStaffAvatar(state.formInline.avatar), resolveStaffAvatar(avatar));
}
console.log('PASS reopening resets uploads; edit keeps real photos and historical female defaults');

for (const avatar of [DEFAULT_STAFF_AVATAR, 'https://old.test/static/images/staff/avatar_female.svg', '/uploads/person.png']) {
  const form = context();
  form.formInline.avatar = avatar;
  form.handleSubmit();
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(submitted.at(-1).avatar, normalizeStaffAvatarValue(avatar));
  assert.doesNotMatch(submitted.at(-1).avatar, /^data:|view_admin/);
}
console.log('PASS actual submit serializes defaults/upload URL, never bundled data URI');
const list = fs.readFileSync(path.join(admin, 'src/pages/setting/staff/index.vue'), 'utf8');
assert.match(list, /import \{ resolveStaffAvatar, onStaffAvatarError \} from '@\/utils\/staffAvatar'/);
assert.match(list, /class="staff-avatar"[^>]*@error="onStaffAvatarError"/);
assert.deepEqual(compiler.compile(compiler.parseComponent(list).template.content).errors, []);
console.log('PASS list and editor share fallback behavior; both Vue templates compile');
