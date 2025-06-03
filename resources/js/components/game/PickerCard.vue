<script setup lang="ts">
import { defineProps, defineEmits, ref } from 'vue';

interface PickerCardProps {
    value: string | number;
    isSelected?: boolean;
    cardIndex?: number;
    cardRank?: string;
    cardSymbol?: string;
    symbolColorClass?: string;
    isFaceDown?: boolean;
    rankValue?: number; // DODANE: wartośc numeryczna rangi
}

const props = defineProps<PickerCardProps>();
const emit = defineEmits<{
    (e: 'selected', value: string | number): void;
}>();

const isHovered = ref(false);

const toggleSelection = () => {
    if (!props.isFaceDown) { // Możesz kliknąć tylko na karty, które nie są rewersem
        emit('selected', props.value);
    }
};

const handleMouseEnter = () => {
    isHovered.value = true;
};

const handleMouseLeave = () => {
    isHovered.value = false;
};
</script>

<template>
    <div
        :class="[
      'picker-card',
      'border-2',
      'rounded-xl',
      'p-2',
      'm-2',
      'cursor-pointer',
      'transition-all',
      'duration-300',
      'ease-in-out',
      'bg-white',
      'shadow-md',
      'flex',
      'flex-col',
      'justify-between',
      'items-center',
      'text-center',
      'relative',
      'transform-gpu',
      'w-32', // Ustalona szerokość karty
      'h-48', // Ustalona wysokość karty
      { '-mr-20': !props.isFaceDown }, // Ujemny margines, aby karty zachodziły (tylko awers)
      { 'hover:z-50': !props.isFaceDown }, // Wysoki z-index na najechanie (tylko awers)
      { 'hover:scale-105': !props.isFaceDown }, // Lekkie powiększenie (tylko awers)
      { 'hover:!relative': !props.isFaceDown }, // Usunięcie względnego pozycjonowania (tylko awers)
      isSelected && !props.isFaceDown ? 'border-blue-500 ring-4 ring-blue-200 bg-blue-50' : 'border-gray-200',
      // Klasy dla rewersu
      { 'bg-gradient-to-br from-red-800 to-red-900': props.isFaceDown }, // Ciemniejszy gradient
      { 'shadow-inner': props.isFaceDown },
      { 'hover:scale-100': props.isFaceDown }, // Wyłącz skalowanie na hover dla rewersu
    ]"
        :style="{
      'z-index': isHovered && !props.isFaceDown ? 100 : (props.cardIndex !== undefined ? props.cardIndex : 1),
      'top': isHovered && !props.isFaceDown ? '-10px' : '0px',
      'transform': isHovered && !props.isFaceDown ? 'translateY(-10px)' : 'translateY(0px)'
    }"
        @click="toggleSelection"
        @mouseenter="handleMouseEnter"
        @mouseleave="handleMouseLeave"
    >
        <div v-if="props.isFaceDown" class="absolute inset-0 flex items-center justify-center text-white text-5xl font-extrabold select-none pointer-events-none">
            <div class="absolute inset-0 bg-pattern"></div> <span class="z-10 text-red-300 transform rotate-12">♣️</span> <span class="absolute top-2 left-2 text-red-400 text-2xl opacity-75">♠️</span>
            <span class="absolute bottom-2 right-2 text-red-400 text-2xl opacity-75 transform rotate-180">♠️</span>
        </div>

        <template v-else>
            <div class="flex flex-col items-start w-full px-1">
                <span class="font-bold text-2xl" :class="props.symbolColorClass">{{ props.cardRank }}</span>
                <span class="text-xl" :class="props.symbolColorClass">{{ props.cardSymbol }}</span>
            </div>

            <div class="flex-grow flex items-center justify-center">
                <span class="text-6xl" :class="props.symbolColorClass">{{ props.cardSymbol }}</span>
            </div>

            <div class="flex flex-col items-end w-full px-1 transform rotate-180">
                <span class="font-bold text-2xl" :class="props.symbolColorClass">{{ props.cardRank }}</span>
                <span class="text-xl" :class="props.symbolColorClass">{{ props.cardSymbol }}</span>
            </div>
        </template>
    </div>
</template>

<style scoped>
/* Dodajemy wzorek dla rewersu karty */
.bg-pattern {
    background-image:
        linear-gradient(45deg, rgba(255,255,255,0.1) 25%, transparent 25%, transparent 75%, rgba(255,255,255,0.1) 75%, rgba(255,255,255,0.1) 100%),
        linear-gradient(45deg, rgba(255,255,255,0.1) 25%, transparent 25%, transparent 75%, rgba(255,255,255,0.1) 75%, rgba(255,255,255,0.1) 100%);
    background-size: 20px 20px; /* Rozmiar pojedynczego elementu wzoru */
    background-position: 0 0, 10px 10px; /* Przesunięcie drugiego gradientu dla siatki */
    opacity: 0.15; /* Lekka przezroczystość wzoru */
    z-index: 0;
}
</style>
