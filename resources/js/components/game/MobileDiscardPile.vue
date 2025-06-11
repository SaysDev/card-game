<script setup lang="ts">
import CardComponent from './MobileCard.vue';

interface CardData {
  rank: string;
  suit: string;
  isValid?: boolean;
}

defineProps({
  cards: {
    type: Array as () => CardData[],
    default: () => []
  }
});

const getCardStyle = (cardData: CardData, index: number) => {
  const seed = (cardData.rank.charCodeAt(0) + cardData.suit.charCodeAt(0) + index) * 1.618;
  const rotation = Math.sin(seed) * 15;
  const xOffset = Math.cos(seed) * 30;
  const yOffset = Math.sin(index * 0.8) * 20;
  
  return { 
    transform: `translate(-50%, -50%) translate(${xOffset}px, ${yOffset}px) rotate(${rotation}deg)`,
    zIndex: index 
  };
};
</script>

<template>
  <div class="discard-pile">
    <card-component
      v-for="(card, index) in cards"
      :key="index"
      :card="card"
      :style="getCardStyle(card, index)"
    />
  </div>
</template> 