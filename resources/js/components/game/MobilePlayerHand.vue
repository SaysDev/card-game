<script setup lang="ts">
import CardComponent from './MobileCard.vue';

interface CardData {
  rank: string;
  suit: string;
  isValid?: boolean;
}

const props = defineProps({
  cards: {
    type: Array as () => CardData[],
    default: () => []
  },
  isTurn: {
    type: Boolean,
    default: false
  }
});

const emit = defineEmits(['card-played']);

const getCardStyle = (index: number, count: number) => {
  const baseOverlap = 35;
  const maxRotation = 15;
  const curveHeight = 15;
  const normalizedIndex = index - (count - 1) / 2;
  
  const xOffset = normalizedIndex * baseOverlap;
  const rotation = normalizedIndex * maxRotation / ((count - 1) / 2 || 1);
  const yOffset = Math.abs(normalizedIndex) * curveHeight / ((count - 1) / 2 || 1);
  
  const transform = `translateX(calc(-50% + ${xOffset}px)) translateY(${yOffset}px) rotate(${rotation}deg)`;
  
  return {
    '--end-transform': transform,
    '--hover-transform': `translateX(calc(-50% + ${xOffset}px)) translateY(${yOffset - 30}px) rotate(${rotation}deg) scale(1.1)`,
    zIndex: index,
    animation: `deal-card 0.5s ease-out ${0.1 + index * 0.05}s forwards`
  };
};

const playCard = (card: CardData) => {
  emit('card-played', card);
};
</script>

<template>
  <div class="player-hand w-full flex justify-center items-center">
    <card-component
      v-for="(card, index) in cards"
      :key="`${card.rank}-${card.suit}-${index}`"
      :card="card"
      :style="getCardStyle(index, cards.length)"
      :is-invalid="!card.isValid"
      @click="playCard(card)"
    />
  </div>
</template> 