<script setup>
import { ref } from 'vue'
import Printer from '@lucide/vue/dist/esm/icons/printer.mjs'
import X from '@lucide/vue/dist/esm/icons/x.mjs'
import {
  SALES_ORDER_RECEIPT_PAPER_PROFILES,
  buildSalesOrderReceiptTestOrder,
  loadSalesOrderReceiptPrintSettings,
  openSalesOrderReceiptPrint,
  saveSalesOrderReceiptPrintSettings
} from '@/services/salesOrderReceiptPrint'

const props = defineProps({
  storeName: {
    type: String,
    default: ''
  }
})

const emit = defineEmits(['close'])
const paperSize = ref(loadSalesOrderReceiptPrintSettings().paperSize)
const errorMessage = ref('')

function selectPaperSize(value) {
  paperSize.value = saveSalesOrderReceiptPrintSettings({ paperSize: value }).paperSize
}

function printTestReceipt() {
  errorMessage.value = ''
  const result = openSalesOrderReceiptPrint(
    buildSalesOrderReceiptTestOrder({ storeName: props.storeName }),
    window,
    { paperSize: paperSize.value }
  )
  if (!result.ok) errorMessage.value = result.message
}
</script>

<template>
  <div class="receipt-printer-setup" @click.self="$emit('close')">
    <section class="receipt-printer-setup__dialog" role="dialog" aria-modal="true" aria-label="打印设置">
      <header>
        <div>
          <h2>打印设置</h2>
          <span>系统打印窗口</span>
        </div>
        <button type="button" class="receipt-printer-setup__icon-button" title="关闭" aria-label="关闭" @click="$emit('close')">
          <X :size="20" />
        </button>
      </header>

      <main>
        <fieldset>
          <legend>纸张宽度</legend>
          <div class="receipt-printer-setup__segments" role="group" aria-label="纸张宽度">
            <button
              v-for="profile in SALES_ORDER_RECEIPT_PAPER_PROFILES"
              :key="profile.key"
              type="button"
              :class="{ 'receipt-printer-setup__segment--active': paperSize === profile.key }"
              @click="selectPaperSize(profile.key)"
            >{{ profile.label }}</button>
          </div>
        </fieldset>

        <dl>
          <div><dt>打印机</dt><dd>由当前电脑管理</dd></div>
          <div><dt>驱动</dt><dd>使用系统已安装驱动</dd></div>
        </dl>
        <p v-if="errorMessage" class="receipt-printer-setup__error" role="alert">{{ errorMessage }}</p>
      </main>

      <footer>
        <button type="button" class="receipt-printer-setup__secondary" @click="$emit('close')">完成</button>
        <button type="button" class="receipt-printer-setup__primary" @click="printTestReceipt">
          <Printer :size="17" />
          打印测试小票
        </button>
      </footer>
    </section>
  </div>
</template>

<style scoped>
.receipt-printer-setup {
  position: absolute;
  inset: 0;
  z-index: 50;
  display: grid;
  place-items: center;
  padding: 24px;
  background: rgba(15, 23, 42, .34);
}

.receipt-printer-setup__dialog {
  width: min(520px, 100%);
  overflow: hidden;
  border: 1px solid #dce3eb;
  border-radius: 8px;
  background: #fff;
  box-shadow: 0 20px 48px rgba(15, 23, 42, .2);
}

.receipt-printer-setup__dialog > header,
.receipt-printer-setup__dialog > footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 18px 20px;
}

.receipt-printer-setup__dialog > header { border-bottom: 1px solid #e6ebf1; }
.receipt-printer-setup__dialog > footer { justify-content: flex-end; border-top: 1px solid #e6ebf1; }
.receipt-printer-setup__dialog h2 { margin: 0; color: #1f2937; font-size: 19px; letter-spacing: 0; }
.receipt-printer-setup__dialog header span { display: block; margin-top: 3px; color: #667085; font-size: 13px; }
.receipt-printer-setup__dialog > main { display: grid; gap: 22px; padding: 22px 20px; }
.receipt-printer-setup__dialog fieldset { min-width: 0; margin: 0; padding: 0; border: 0; }
.receipt-printer-setup__dialog legend { margin-bottom: 10px; color: #344054; font-size: 14px; font-weight: 700; }

.receipt-printer-setup__segments {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  overflow: hidden;
  border: 1px solid #cfd8e3;
  border-radius: 7px;
}

.receipt-printer-setup__segments button {
  min-height: 42px;
  border: 0;
  border-right: 1px solid #cfd8e3;
  background: #fff;
  color: #475467;
  font-weight: 700;
  letter-spacing: 0;
}

.receipt-printer-setup__segments button:last-child { border-right: 0; }
.receipt-printer-setup__segments button:hover { background: #f4f8fc; }
.receipt-printer-setup__segments .receipt-printer-setup__segment--active { background: #eaf4ff; color: #175cd3; }

.receipt-printer-setup__dialog dl { display: grid; gap: 10px; margin: 0; }
.receipt-printer-setup__dialog dl div { display: flex; justify-content: space-between; gap: 20px; }
.receipt-printer-setup__dialog dt { color: #667085; }
.receipt-printer-setup__dialog dd { margin: 0; color: #344054; font-weight: 600; text-align: right; }
.receipt-printer-setup__error { margin: 0; color: #b42318; font-size: 13px; }

.receipt-printer-setup__icon-button {
  display: inline-grid;
  width: 36px;
  height: 36px;
  place-items: center;
  padding: 0;
  border: 1px solid #d7dee7;
  border-radius: 7px;
  background: #fff;
  color: #475467;
}

.receipt-printer-setup__secondary,
.receipt-printer-setup__primary {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 7px;
  min-height: 40px;
  padding: 0 16px;
  border-radius: 7px;
  font-weight: 700;
  letter-spacing: 0;
}

.receipt-printer-setup__secondary { border: 1px solid #cfd8e3; background: #fff; color: #344054; }
.receipt-printer-setup__primary { border: 1px solid #175cd3; background: #175cd3; color: #fff; }
</style>
