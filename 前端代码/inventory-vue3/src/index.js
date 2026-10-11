import './styles.css'

export { default as InventoryWorkbench } from './App.vue'
// 库存和赠送直接引用同一选品实现，不复制模板或维护终端专属版本。
export { default as InventoryProductSelector } from './components/InventoryProductSelector.vue'
export { default } from './App.vue'
export {
  clearInventoryEmbeddedSessionToken,
  setInventoryEmbeddedSessionToken
} from './services/inventoryApi.js'
