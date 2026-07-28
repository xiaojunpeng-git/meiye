<script setup>
import { requestCashierV3Action } from '@/services/cashierV3Bridge'

const existingReservation = false

function saveReservation() {
  return requestCashierV3Action(
    existingReservation ? 'update-reservation' : 'create-reservation',
    { permission: 'cashier.v3.reservation' }
  )
}

function selectWriteoffMember() {
  return requestCashierV3Action(
    'select-writeoff-member',
    { permission: 'cashier.v3.writeoff' }
  )
}

function selectReservationMember() {
  return requestCashierV3Action(
    'select-reservation-member',
    { permission: 'cashier.v3.reservation' }
  )
}
</script>

<template>
  <button @click="saveReservation">save</button>
  <button @click="selectWriteoffMember">writeoff</button>
  <button @click="selectReservationMember">reservation</button>
</template>
