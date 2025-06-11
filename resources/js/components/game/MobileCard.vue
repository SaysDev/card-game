<script setup lang="ts">
defineProps({
  card: {
    type: Object,
    required: true
  },
  style: {
    type: Object,
    default: () => ({})
  },
  isInvalid: {
    type: Boolean,
    default: false
  }
});
</script>

<template>
  <div 
    class="card" 
    :class="[
      {'red': card.suit === '♥' || card.suit === '♦'}, 
      {'black': card.suit === '♣' || card.suit === '♠'}, 
      {'invalid-card': isInvalid}
    ]" 
    :style="style"
  >
    <div class="w-full text-left text-lg font-sans font-bold">
      {{ card.rank }}
    </div>
    <div class="text-4xl font-sans" v-html="card.suit"></div>
    <div class="w-full text-right text-lg font-sans font-bold" style="transform: rotate(180deg);">
      {{ card.rank }}
    </div>
  </div>
</template> 