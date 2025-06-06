<script setup lang="ts">
import { defineProps, computed } from 'vue';
import PickerCard from '@/components/game/PickerCard.vue';

interface CardData {
    value: string;
    header: string;
    cardRank: string;
    cardSymbol: string;
    symbolColorClass: string;
    rankValue: number;
}

interface PlayerHandProps {
    cards: CardData[];
    isFacingAway?: boolean;
}

const props = defineProps<PlayerHandProps>();

const getCardStyle = (index: number, totalCards: number) => {
    const rotationDegrees = [-10, -5, 0, 5, 10];
    const translateYPixels = [10, 5, 0, 5, 10];
    const translateXValues = [-25, -12, 0, 12, 25];
    if (index < totalCards) {
        return {
            transform: `translateX(${translateXValues[index]}px) rotateZ(${rotationDegrees[index]}deg) translateY(${translateYPixels[index]}px)`,
            transformOrigin: 'bottom center',
            zIndex: index,
            width: '60px',
            height: '80px',
        };
    }
    return {};
};
</script>

<template>
    <div
        :class="[
      'player-hand-container',
      'flex',
      'justify-center',
      'relative',
      'py-4',
    ]"
    >
        <picker-card
            v-for="(card, index) in props.cards"
            :key="card.value"
            :value="card.value"
            :card-index="index"
            :card-rank="card.cardRank"
            :card-symbol="card.cardSymbol"
            :symbol-color-class="card.symbolColorClass"
            :is-face-down="props.isFacingAway"
            :is-selected="false"
            @selected="() => {}"
            :style="getCardStyle(index, props.cards.length)"
            class="player-card"
        />
    </div>
</template>

<style scoped>
.player-hand-container {
}

.player-card {
    width: 60px;
    height: 80px;
    margin-left: -20px;
    transition: transform 0.2s ease;
}

.player-card:first-of-type {
    margin-left: 0;
}
</style>
