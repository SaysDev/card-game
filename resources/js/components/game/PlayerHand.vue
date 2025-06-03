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

// Obliczone style dla zakrzywionego efektu kart
const getCardStyle = (index: number, totalCards: number) => {
    // Nowe, bardziej zwarte wartości dla zakrzywienia
    const rotationDegrees = [-10, -5, 0, 5, 10]; // Zwiększono obrót, ale zmniejszono odstępy
    const translateYPixels = [10, 5, 0, 5, 10]; // Mniejsze przesunięcie Y

    // Przesunięcie X dla efektu "rozłożenia" kart
    const translateXValues = [-25, -12, 0, 12, 25]; // Przesunięcie X w pixelach

    if (index < totalCards) { // Upewnij się, że index jest prawidłowy
        return {
            transform: `translateX(${translateXValues[index]}px) rotateZ(${rotationDegrees[index]}deg) translateY(${translateYPixels[index]}px)`,
            transformOrigin: 'bottom center', // Punkt obrotu na dole karty
            zIndex: index, // Zwiększanie z-indexu dla efektu nakładania
            // Możesz zmniejszyć szerokość kart dla graczy AI, jeśli są zbyt duże
            width: '60px', /* Domyślna szerokość karty */
            height: '80px', /* Domyślna wysokość karty */
            // Zmniejsz rozmiar kart dla graczy bocznych (nie dla głównego gracza)
            // W GameBoard.vue będziemy renderować PlayerHand z odpowiednimi rozmiarami.
        };
    }
    return {}; // Domyślne style, jeśli poza zakresem
};
</script>

<template>
    <div
        :class="[
      'player-hand-container', // Zmieniono nazwę klasy, aby uniknąć kolizji z GameBoard.vue
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
    /* Upewnij się, że ten kontener jest elastyczny i może być obracany w GameBoard */
}

.player-card {
    /* Nadaj stałą szerokość i wysokość, aby karty nie były zbyt duże */
    width: 60px; /* Standardowa szerokość karty */
    height: 80px; /* Standardowa wysokość karty */
    margin-left: -20px; /* Zmniejsz odstęp między kartami */
    transition: transform 0.2s ease; /* Płynna animacja przy wybieraniu */
}

/* Pierwsza karta nie ma lewego marginesu */
.player-card:first-of-type {
    margin-left: 0;
}
</style>
