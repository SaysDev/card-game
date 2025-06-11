<script setup lang="ts">
import { ref, computed } from 'vue';
import { webSocketService } from '@/Services/WebSocketService';
import { MessageType } from "@/types/messageTypes";
import { useToast } from '@/components/ui/toast';
import CardComponent from '@/components/game/MobileCard.vue';
import PlayerHand from '@/components/game/MobilePlayerHand.vue';
import DiscardPile from '@/components/game/MobileDiscardPile.vue';
import PlayerPod from '@/components/game/MobilePlayerPod.vue';

const { toast } = useToast();

// Game state
const gameState = ref({
  players: [],
  currentPlayerIndex: 0,
  discardPile: [],
  deck: [],
  roomId: null,
  connected: false
});

// Game flow
const players = computed(() => gameState.value.players);
const humanPlayer = computed(() => gameState.value.players.find((p: any) => p.isHuman) || { hand: [] });
const currentPlayer = computed(() => gameState.value.players[gameState.value.currentPlayerIndex] || {});
const isHumanTurn = computed(() => currentPlayer.value.isHuman);

// Card validation functions
type Card = { rank: string; suit: string; isValid?: boolean };
const isMoveValid = (card: Card) => {
  const topCard = gameState.value.discardPile[gameState.value.discardPile.length - 1];
  if (!topCard) return true;
  return card.rank === topCard.rank || card.suit === topCard.suit;
};

const hasValidMove = computed(() => {
  if (!isHumanTurn.value) return false;
  return humanPlayer.value.hand.some(isMoveValid);
});

const handleCardPlay = (card: Card) => {
  if (!isHumanTurn.value || !isMoveValid(card)) return;
  if (gameState.value.connected) {
    const cardIndex = humanPlayer.value.hand.findIndex((c: Card) => c.rank === card.rank && c.suit === card.suit);
    webSocketService.send({
      type: MessageType.GAME_ACTION,
      action_type: 'play_card',
      card_index: cardIndex
    });
  }
};

const playerPass = () => {
  if (!isHumanTurn.value || hasValidMove.value) {
    if (hasValidMove.value) {
      toast({
        title: "Invalid Move",
        description: "Masz kartę do zagrania!",
        variant: "destructive"
      });
    }
    return;
  }
  if (gameState.value.connected) {
    webSocketService.send({
      type: MessageType.GAME_ACTION,
      action_type: 'pass'
    });
  }
};
</script>

<template>
  <div id="gameTableScreen" class="game-screen flex flex-col items-center justify-between p-2 bg-game-table">
    <div class="absolute inset-0">
      <player-pod 
        v-for="player in players" 
        :key="player.id" 
        :player="player" 
        :is-current-turn="player.id === currentPlayer.id" 
      />
    </div>
    <div class="w-full flex-grow flex flex-col items-center justify-center z-0">
      <discard-pile :cards="gameState.discardPile" />
    </div>
    <div class="w-full flex flex-col items-center pb-4 z-10">
      <player-hand 
        :cards="humanPlayer.hand" 
        :is-turn="isHumanTurn" 
        @card-played="handleCardPlay" 
      />
      <div id="action-buttons" class="flex justify-center space-x-4 mt-2 w-full px-4">
        <button 
          @click="playerPass" 
          :disabled="!isHumanTurn || hasValidMove" 
          class="action-button w-1/2 bg-gray-700 hover:bg-gray-600 text-white py-3 px-4"
        >
          <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <line x1="5" y1="12" x2="19" y2="12"></line>
            <polyline points="12 5 19 12 12 19"></polyline>
          </svg>
          Pasuj
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.game-screen {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
}

.bg-game-table {
  background-color: #0d2e1e;
  background-image: radial-gradient(ellipse at center, #1C4A33, #0A281A 70%);
  box-shadow: inset 0 0 30px rgba(0,0,0,0.7);
}

.action-button {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  font-weight: 600;
  border-radius: 12px;
  box-shadow: 0 4px 15px rgba(0,0,0,0.2);
  transition: all 0.2s ease;
}

.action-button:hover:not(:disabled) {
  transform: translateY(-2px);
  box-shadow: 0 6px 20px rgba(0,0,0,0.25);
}

.action-button:disabled {
  filter: grayscale(80%);
  cursor: not-allowed !important;
  opacity: 0.6;
}
</style> 