<script setup lang="ts">
import { ref, defineProps, defineEmits, watch, computed } from 'vue';
import PickerCard from '@/components/game/PickerCard.vue';

interface CardOption {
    value: string | number;
    header: string;
    cardRank: string;
    cardSymbol: string;
    symbolColorClass: string;
    rankValue: number;
}

interface CardPickerProps {
    options: CardOption[];
    title?: string;
    multiple?: boolean;
    initialSelected?: (string | number)[];
    lastPlayedRankValue?: number | null;
}

const props = defineProps<CardPickerProps>();
const emit = defineEmits<{
    (e: 'update:selected', value: (string | number)[]): void;
}>();

const selectedCards = ref<(string | number)[]>(props.initialSelected || []);
const errorMessage = ref<string>('');

const currentlySelectedCardObjects = computed<CardOption[]>(() => {
    return selectedCards.value
        .map(val => props.options.find(opt => opt.value === val))
        .filter((card): card is CardOption => card !== undefined);
});

const handleCardSelection = (selectedValue: string | number) => {
    errorMessage.value = '';

    const cardToToggle = props.options.find(opt => opt.value === selectedValue);
    if (!cardToToggle) return;

    const isAlreadySelected = selectedCards.value.includes(selectedValue);

    if (isAlreadySelected) {
        selectedCards.value = selectedCards.value.filter(val => val !== selectedValue);
    } else {
        const currentSelectionCount = selectedCards.value.length;

        if (props.lastPlayedRankValue !== null && cardToToggle.rankValue < props.lastPlayedRankValue) {
            errorMessage.value = 'Nie możesz wybrać karty niższej od ostatnio zagranej!';
            return;
        }

        if (currentSelectionCount === 0) {
            selectedCards.value.push(selectedValue);
        } else if (currentSelectionCount === 1) {
            const firstCard = currentlySelectedCardObjects.value[0];
            if (firstCard.cardRank === cardToToggle.cardRank) {
                selectedCards.value.push(selectedValue);
            } else {
                errorMessage.value = 'Możesz wybrać tylko 1 kartę, chyba że wybierasz 3 o tej samej randze.';
            }
        } else if (currentSelectionCount === 2) {
            const firstCard = currentlySelectedCardObjects.value[0];
            if (firstCard.cardRank === cardToToggle.cardRank) {
                selectedCards.value.push(selectedValue);
            } else {
                errorMessage.value = 'Musisz wybrać 3 karty o tej samej randze lub tylko 1 kartę.';
            }
        } else if (currentSelectionCount === 3) {
            errorMessage.value = 'Wybrano już maksymalną liczbę kart (3).';
        }
    }
};

watch(selectedCards, (newVal) => {
    emit('update:selected', newVal);
}, { deep: true });

const isCardSelected = (value: string | number): boolean => {
    return selectedCards.value.includes(value);
};

const getOptionHeaderByValue = (value: string | number): string => {
    const option = props.options.find(opt => opt.value === value);
    return option ? option.header : 'Nieznana karta';
};

const clearSelection = () => {
    selectedCards.value = [];
    errorMessage.value = '';
};

// Obliczone style dla zakrzywionego efektu kart
const getCardStyle = (index: number, totalCards: number) => {
    // Dla 5 kart
    const rotationDegrees = [-8, -4, 0, 4, 8]; // Stopnie obrotu dla każdej z 5 kart
    const translateYPixels = [20, 10, 0, 10, 20]; // Przesunięcie Y dla każdej z 5 kart (środek najniżej)

    // Upewnij się, że index jest w zakresie dla zdefiniowanych stopni
    if (index < rotationDegrees.length && index < translateYPixels.length) {
        return {
            transform: `rotateZ(${rotationDegrees[index]}deg) translateY(${translateYPixels[index]}px)`,
            transformOrigin: 'bottom center', // Punkt obrotu na dole karty
            zIndex: index // Zwiększanie z-indexu dla efektu nakładania
        };
    }
    return {}; // Domyślne style, jeśli poza zakresem
};
</script>

<template>
    <div class="card-picker-container">
        <h2 v-if="props.title" class="text-3xl font-semibold text-center text-gray-800 mb-6">{{ props.title }}</h2>

        <div
            :class="[
        'cards-wrapper',
        'flex',
        'justify-center',
        'relative',
        'py-4',
      ]"
        >
            <picker-card
                v-for="(option, index) in props.options"
                :key="option.value"
                :value="option.value"
                :is-selected="isCardSelected(option.value)"
                :show-footer="false"
                :card-index="index"
                :card-rank="option.cardRank"
                :card-symbol="option.cardSymbol"
                :symbol-color-class="option.symbolColorClass"
                @selected="handleCardSelection"
                :style="getCardStyle(index, props.options.length)" />
        </div>

        <div v-if="errorMessage" class="text-red-600 font-bold mt-4 text-center">
            {{ errorMessage }}
        </div>

        <div v-if="selectedCards.length > 0" class="selected-info mt-8 pt-6 border-t border-dashed border-gray-300 text-center">
            <h3 class="text-xl font-medium text-gray-700 mb-4">Wybrane karty:</h3>
            <ul class="list-none p-0 inline-flex flex-wrap justify-center gap-2">
                <li
                    v-for="cardValue in selectedCards"
                    :key="cardValue"
                    class="bg-blue-100 text-blue-800 px-3 py-1 rounded-full text-sm font-medium"
                >
                    {{ getOptionHeaderByValue(cardValue) }}
                </li>
            </ul>
            <button
                @click="clearSelection"
                class="mt-4 bg-red-500 hover:bg-red-600 text-white font-semibold py-2 px-4 rounded-lg cursor-pointer transition-colors duration-200"
            >
                Wyczyść wybór
            </button>
        </div>
    </div>
</template>
