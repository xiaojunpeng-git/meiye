<script setup>
import { computed, inject, onBeforeUnmount, reactive, ref, watch } from 'vue'
import { useCashierV3State } from '@/services/cashierV3Bridge'
import { queryStoreProfile, queryStoreProfileCities, saveStoreProfile, uploadStoreProfileImage } from '@/services/storeProfileSettingsApi'
import { readStoreV3SessionToken } from '@/services/storeV3SessionToken'

const state = useCashierV3State()
const navigation = inject('store-settings-navigation', null)
const form = reactive({}), loaded = ref(null), loading = ref(false), saving = ref(false), uploading = ref(''), error = ref(''), message = ref('')
const regions = ref([[], [], [], []]), mapOpen = ref(false)
// Resolve the draft's selected region labels; never show the old saved address
// after a province/city selection has changed.
const regionLabel = computed(() => (form.address_ids || []).map((id, level) => regions.value[level].find(row => Number(row.id) === Number(id))?.name || '').join(''))
const editable = computed(() => loaded.value?.can_edit === true)
const busy = computed(() => loading.value || saving.value || Boolean(uploading.value))
let epoch = 0, regionEpoch = 0, submission = null
const fields = ['image', 'background_image', 'name', 'space_num', 'introduction', 'is_show', 'phone', 'address_ids', 'detailed_address', 'longitude', 'latitude', 'day_time']

/** Draft and region hydration belong to one session context; late old-store data
 * cannot overwrite the selected store or a newer address selection.
 */
async function hydrateRegions(ids) {
  const request = ++regionEpoch, context = epoch, session = readStoreV3SessionToken()
  regions.value = [[], [], [], []]
  try {
    for (let level = 0; level < 4; level++) {
      const rows = await queryStoreProfileCities(level ? ids[level - 1] : 0)
      if (request !== regionEpoch || context !== epoch || session !== readStoreV3SessionToken()) return
      regions.value[level] = Array.isArray(rows) ? rows : []
      if (!ids[level]) break
    }
  } catch (e) { if (request === regionEpoch && context === epoch) error.value = e.message }
}
function adopt(data) {
  // HTTP DTOs are JSON values. Cancel also passes Vue's reactive saved DTO;
  // copying through JSON avoids structuredClone rejecting that proxy.
  const snapshot = JSON.parse(JSON.stringify(data))
  loaded.value = snapshot
  if (navigation) navigation.value = { ...data, contextId: state.stateContextId }
  Object.keys(form).forEach(key => delete form[key])
  Object.assign(form, structuredClone(snapshot.profile))
  submission = null
  void hydrateRegions([...form.address_ids])
}
async function load() {
  const request = ++epoch, session = readStoreV3SessionToken(); regionEpoch++; loading.value = true; error.value = ''; message.value = ''; loaded.value = null; mapOpen.value = false
  if (navigation) navigation.value = null
  try { const data = await queryStoreProfile(); if (request === epoch && session === readStoreV3SessionToken()) adopt(data) }
  catch (e) { if (request === epoch) error.value = e.message }
  finally { if (request === epoch) loading.value = false }
}
function cancel() { if (loaded.value && !busy.value) { adopt(loaded.value); error.value = ''; message.value = '' } }
function selectRegion(level, value) {
  form.address_ids = [...form.address_ids.slice(0, level), ...(value ? [Number(value)] : [])]
  void hydrateRegions([...form.address_ids])
}
async function upload(field, event) {
  const file = event.target.files?.[0]; event.target.value = ''
  if (!file || !editable.value || busy.value) return
  if (!['image/jpeg', 'image/png', 'image/gif', 'image/webp'].includes(file.type) || file.size > 10 * 1024 * 1024) { error.value = '请选择10MB以内的JPG、PNG、GIF或WebP图片。'; return }
  const context = epoch, session = readStoreV3SessionToken(); uploading.value = field; error.value = ''; message.value = ''
  try { const data = await uploadStoreProfileImage(file); if (context === epoch && session === readStoreV3SessionToken()) form[field] = data.src }
  catch (e) { if (context === epoch) error.value = e.message }
  finally { if (context === epoch) uploading.value = '' }
}
async function save() {
  if (!editable.value || busy.value) return
  const profile = Object.fromEntries(fields.map(key => [key, form[key]]))
  const fingerprint = JSON.stringify([profile, loaded.value.expected_version])
  // Reuse the same idempotency key after a transport failure; changed drafts get a new key.
  if (!submission || submission.fingerprint !== fingerprint) submission = { fingerprint, token: crypto.randomUUID() }
  const context = epoch, session = readStoreV3SessionToken(); saving.value = true; error.value = ''; message.value = ''
  try { const data = await saveStoreProfile(profile, loaded.value.expected_version, submission.token); if (context === epoch && session === readStoreV3SessionToken()) { adopt(data); message.value = '门店资料已保存。' } }
  catch (e) { if (context === epoch) error.value = e.message }
  finally { if (context === epoch) saving.value = false }
}

/** Map picker uses the platform's existing Tencent public browser key. It only
 * changes geographic draft fields, never requests the device's live location.
 */
const mapContainer = ref(null), mapError = ref('')
let map = null, marker = null, mapLoad = null, mapEpoch = 0
function loadMapSdk(key) {
  if (window.qq?.maps) return Promise.resolve(window.qq)
  if (mapLoad) return mapLoad
  mapLoad = new Promise((resolve, reject) => {
    const callback = `storeMapReady_${crypto.randomUUID().replaceAll('-', '')}`
    const script = document.createElement('script')
    const timer = setTimeout(() => finish(new Error('地图加载超时，请稍后重试。')), 15000)
    function finish(failure) { clearTimeout(timer); delete window[callback]; if (failure) { script.remove(); mapLoad = null; reject(failure) } else resolve(window.qq) }
    window[callback] = () => finish()
    script.src = `https://map.qq.com/api/js?v=2.exp&callback=${callback}&key=${encodeURIComponent(key)}`
    script.onerror = () => finish(new Error('地图暂时不可用，可直接填写经纬度。'))
    document.head.appendChild(script)
  })
  return mapLoad
}
function locate(lat, lng) {
  const qq = window.qq, point = new qq.maps.LatLng(Number(lat), Number(lng))
  map.setCenter(point); if (marker) marker.setMap(null)
  marker = new qq.maps.Marker({ position: point, map })
  form.latitude = Number(lat).toFixed(6); form.longitude = Number(lng).toFixed(6)
}
watch(mapOpen, async open => {
  const request = ++mapEpoch, session = readStoreV3SessionToken(); mapError.value = ''
  if (!open) { if (marker) marker.setMap(null); marker = null; map = null; return }
  try {
    const qq = await loadMapSdk(loaded.value.map_key)
    if (request !== mapEpoch || session !== readStoreV3SessionToken() || !mapContainer.value) return
    const lat = Number(form.latitude) || 34.34127, lng = Number(form.longitude) || 108.93984
    map = new qq.maps.Map(mapContainer.value, { center: new qq.maps.LatLng(lat, lng), zoom: 15 })
    // Opening a map must not silently save the fallback center as this store's location.
    marker = new qq.maps.Marker({ position: new qq.maps.LatLng(lat, lng), map })
    qq.maps.event.addListener(map, 'click', event => { if (request === mapEpoch && session === readStoreV3SessionToken()) locate(event.latLng.getLat(), event.latLng.getLng()) })
  } catch (e) { if (request === mapEpoch) mapError.value = e.message }
}, { flush: 'post' })
async function searchAddress() {
  if (!map) return
  const request = mapEpoch, session = readStoreV3SessionToken(), address = regionLabel.value + form.detailed_address
  // Same JSONP geocoder endpoint as the platform map. Callback lifetime is bounded.
  const callback = `storeGeo_${crypto.randomUUID().replaceAll('-', '')}`, script = document.createElement('script')
  const cleanup = () => { clearTimeout(timer); delete window[callback]; script.remove() }
  const timer = setTimeout(() => { cleanup(); if (request === mapEpoch) mapError.value = '查找位置超时，请重试或直接点击地图。' }, 15000)
  window[callback] = data => { cleanup(); if (request !== mapEpoch || session !== readStoreV3SessionToken()) return; if (Number(data.status) === 0 && data.result?.location) { mapError.value = ''; locate(data.result.location.lat, data.result.location.lng) } else mapError.value = '未找到该地址，请调整地址或直接点击地图。' }
  script.src = `https://apis.map.qq.com/ws/geocoder/v1/?address=${encodeURIComponent(address)}&key=${encodeURIComponent(loaded.value.map_key)}&output=jsonp&callback=${callback}`
  script.onerror = () => { cleanup(); if (request === mapEpoch) mapError.value = '位置查询失败，请稍后重试。' }
  document.head.appendChild(script)
}
watch(() => state.stateContextId, load, { immediate: true })
onBeforeUnmount(() => { epoch++; regionEpoch++; mapEpoch++; if (navigation) navigation.value = null })
</script>

<template>
  <section class="store-settings-page" aria-label="门店设置">
    <header class="store-settings-heading"><h2>基础信息</h2><button class="button button--secondary" type="button" :disabled="busy" @click="load">重新加载</button></header>
    <p v-if="error" class="store-settings-error" role="alert">{{ error }}</p>
    <p v-if="message" class="store-settings-success" role="status">{{ message }}</p>
    <p v-if="loading">正在加载门店资料…</p>
    <form v-else-if="loaded" class="store-settings-form" @submit.prevent="save">
      <p v-if="!editable" class="store-settings-readonly">当前为查看模式，不能修改门店资料。</p>
      <fieldset :disabled="!editable || busy">
        <div v-for="photo in [{ key: 'image', label: '门店照片', size: '70 × 70px' }, { key: 'background_image', label: '门头照片', size: '375 × 192px' }]" :key="photo.key" class="store-settings-row">
          <span>{{ photo.label }} <span class="store-settings-required">*</span></span>
          <div><label class="store-settings-photo"><img v-if="form[photo.key]" :src="form[photo.key]" :alt="photo.label"><span v-else>上传照片</span><input type="file" accept="image/jpeg,image/png,image/gif,image/webp" :aria-label="`上传${photo.label}`" @change="upload(photo.key, $event)"></label><p class="store-settings-hint">{{ uploading === photo.key ? '正在上传…' : `建议尺寸：${photo.size}，点击图片更换` }}</p></div>
        </div>
        <label class="store-settings-row"><span>门店名称 <span class="store-settings-required">*</span></span><input v-model="form.name" maxlength="20" required></label>
        <label class="store-settings-row"><span>床位数</span><input v-model="form.space_num" type="number" min="0" max="2147483647" step="1" required></label>
        <label class="store-settings-row"><span>门店简介</span><textarea v-model="form.introduction" maxlength="100" rows="4" placeholder="请输入门店简介"></textarea></label>
        <div class="store-settings-row"><span>所属组织</span><div class="store-settings-organization">{{ loaded.organization.name || '未设置' }}<p class="store-settings-hint">组织归属由平台维护。</p></div></div>
        <label class="store-settings-row"><span>营业状态</span><select v-model="form.is_show"><option :value="1">开启</option><option :value="0">关闭</option></select></label>
        <div class="store-settings-row"><span>营业时间</span><div class="store-settings-time"><input v-model="form.day_time[0]" type="time" step="1" aria-label="营业开始时间" :required="form.is_show === 1"><span>至</span><input v-model="form.day_time[1]" type="time" step="1" aria-label="营业结束时间" :required="form.is_show === 1"></div></div>
        <label class="store-settings-row"><span>门店电话 <span class="store-settings-required">*</span></span><input v-model="form.phone" maxlength="25" required></label>
        <div class="store-settings-row"><span>门店地址 <span class="store-settings-required">*</span></span><div><div class="store-settings-regions"><select v-for="(rows, level) in regions" :key="level" :value="form.address_ids[level] || ''" :aria-label="['省份', '城市', '区县', '街道'][level]" :disabled="!rows.length" @change="selectRegion(level, $event.target.value)"><option value="">{{ ['选择省份', '选择城市', '选择区县', '街道（可选）'][level] }}</option><option v-for="row in rows" :key="row.id" :value="row.id">{{ row.name }}</option></select></div><p v-if="regionLabel" class="store-settings-hint">{{ regionLabel }}</p></div></div>
        <label class="store-settings-row"><span>详细地址 <span class="store-settings-required">*</span></span><input v-model="form.detailed_address" maxlength="255" required></label>
        <div class="store-settings-row"><span>门店定位</span><div><div class="store-settings-coordinates"><label>经度<input v-model="form.longitude" type="number" min="-180" max="180" step="any" required></label><label>纬度<input v-model="form.latitude" type="number" min="-90" max="90" step="any" required></label><button class="button button--secondary" type="button" :disabled="!loaded.map_key" @click="mapOpen = true">地图定位</button></div><p v-if="!loaded.map_key" class="store-settings-hint">地图服务暂未配置，可填写经纬度。</p></div></div>
      </fieldset>
      <footer v-if="editable" class="store-settings-footer"><button type="button" class="button button--secondary" :disabled="busy" @click="cancel">取消修改</button><button type="submit" class="button button--primary" :disabled="busy">{{ saving ? '保存中…' : '保存' }}</button></footer>
    </form>
    <div v-if="mapOpen" class="store-settings-map-backdrop" role="dialog" aria-modal="true" aria-label="选择门店定位"><section class="store-settings-map-panel"><header><h3>选择门店定位</h3><button type="button" class="button button--secondary" @click="mapOpen = false">关闭</button></header><p>点击地图选择门店位置，确认后保存门店资料。</p><button class="button button--secondary" type="button" @click="searchAddress">按填写地址查找位置</button><p v-if="mapError" role="alert" class="store-settings-error">{{ mapError }}</p><div ref="mapContainer" class="store-settings-map"></div><footer><span>经度 {{ form.longitude }} · 纬度 {{ form.latitude }}</span><button class="button button--primary" type="button" @click="mapOpen = false">确认位置</button></footer></section></div>
  </section>
</template>

<style scoped>
.store-settings-page{display:flex;flex-direction:column;gap:16px;min-height:0;overflow:auto;padding:20px;background:#f5f7fa}.store-settings-heading{display:flex;align-items:center;justify-content:space-between}.store-settings-heading h2{margin:0;font-size:20px}.store-settings-form{background:#fff;border:1px solid #dce4ee;border-radius:8px;padding:24px}.store-settings-form fieldset{border:0;margin:0;padding:0;min-width:0}.store-settings-row{display:grid;grid-template-columns:112px minmax(0,650px);align-items:start;gap:18px;margin-bottom:22px;font-size:14px}.store-settings-row>span{padding-top:9px;text-align:right;color:#536780}.store-settings-row input:not([type=file]),.store-settings-row select,.store-settings-row textarea{width:100%;min-width:0;border:1px solid #dce4ee;border-radius:5px;padding:8px 10px;background:#fff;color:#334861;font:inherit;box-sizing:border-box}.store-settings-row input:disabled,.store-settings-row select:disabled,.store-settings-row textarea:disabled{background:#f6f8fb;color:#7c8797}.store-settings-required,.store-settings-error{color:#c43737}.store-settings-success{color:#238354}.store-settings-error,.store-settings-success{margin:0;font-size:14px}.store-settings-hint{margin:7px 0 0;color:#8290a3;font-size:12px;line-height:1.5}.store-settings-photo{display:flex;width:78px;height:78px;border:1px dashed #cbd6e4;border-radius:4px;align-items:center;justify-content:center;position:relative;overflow:hidden;cursor:pointer;color:#63768d}.store-settings-photo img{width:100%;height:100%;object-fit:contain}.store-settings-photo input{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%}.store-settings-time,.store-settings-coordinates,.store-settings-regions{display:flex;align-items:center;gap:10px}.store-settings-coordinates label{display:flex;align-items:center;gap:8px;min-width:0;flex:1}.store-settings-regions{flex-wrap:wrap}.store-settings-regions select{flex:1;min-width:130px}.store-settings-time input{max-width:180px}.store-settings-organization{padding-top:9px}.store-settings-readonly{color:#6e7e93;margin:0 0 20px}.store-settings-footer{display:flex;justify-content:flex-end;gap:10px;border-top:1px solid #e7edf4;padding-top:18px;margin-top:4px}.store-settings-map-backdrop{position:fixed;inset:0;background:#18243a66;z-index:1000;display:grid;place-items:center}.store-settings-map-panel{width:min(850px,94vw);background:#fff;padding:20px;border-radius:10px;max-height:90vh;overflow:auto}.store-settings-map-panel header,.store-settings-map-panel footer{display:flex;align-items:center;justify-content:space-between;gap:12px}.store-settings-map-panel h3{margin:0}.store-settings-map-panel p{font-size:13px;color:#6e7e93}.store-settings-map{height:380px;max-height:50vh;margin:12px 0}.store-settings-coordinates button{white-space:nowrap}@media(max-width:700px){.store-settings-page{padding:12px}.store-settings-form{padding:16px}.store-settings-row{grid-template-columns:90px minmax(0,1fr);gap:10px}.store-settings-coordinates{flex-wrap:wrap}.store-settings-coordinates label{flex:1 1 160px}}
</style>
