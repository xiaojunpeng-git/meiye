<template>
  <svg class="icon" :class="className" aria-hidden="true">
    <use :href="`#i-${name}`" />
  </svg>
</template>

<script>
export default {
  name: "SvgIcon",
  props: {
    name: { type: String, required: true },
    className: { type: String, default: "" },
  },
};
</script>
