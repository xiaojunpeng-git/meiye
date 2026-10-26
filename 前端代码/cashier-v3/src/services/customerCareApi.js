import { createCashierV3HttpAdapter } from './cashierV3HttpAdapter'

let adapter = null

function careAdapter() {
  if (!adapter) adapter = createCashierV3HttpAdapter({ endpoint: '/cashierapi/v3/customer-care/actions' })
  return adapter
}

export async function requestCustomerCareAction(action, payload = {}) {
  const response = await careAdapter().request(action, { ...payload, action })
  return response?.data && typeof response.data === 'object' ? response.data : response
}
