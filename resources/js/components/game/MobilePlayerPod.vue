<script setup lang="ts">
interface Player {
  id: number;
  name: string;
  avatar: string;
  hand: any[];
  isHuman?: boolean;
}

const props = defineProps({
  player: {
    type: Object as () => Player,
    required: true
  },
  isCurrentTurn: {
    type: Boolean,
    default: false
  }
});

// Position classes for each player position (0 = human player, 1-3 = opponents)
const positionClasses: Record<number, string> = {
  0: 'bottom-32 left-1/2 -translate-x-1/2',
  1: 'top-1/2 -translate-y-1/2 left-2',
  2: 'top-20 left-1/2 -translate-x-1/2',
  3: 'top-1/2 -translate-y-1/2 right-2'
};

const positionClass = positionClasses[props.player.id] || 'bottom-32 left-1/2 -translate-x-1/2';
</script>

<template>
  <div 
    class="player-pod absolute flex flex-col items-center gap-1" 
    :class="[positionClass, {'is-turn': isCurrentTurn}]"
  >
    <div class="relative avatar-container w-16 h-16 rounded-full border-4 border-green-700 flex items-center justify-center text-3xl bg-gray-600 z-10">
      {{ player.avatar }}
      <div class="card-count absolute -top-2 -right-2 bg-red-600 text-white text-xs font-bold w-6 h-6 rounded-full flex items-center justify-center border-2 border-white">
        {{ player.hand.length }}
      </div>
    </div>
    <div class="text-center text-white bg-black/30 px-2 py-1 rounded-lg">
      <p class="font-semibold text-sm leading-tight">{{ player.name }}</p>
    </div>
  </div>
</template> 