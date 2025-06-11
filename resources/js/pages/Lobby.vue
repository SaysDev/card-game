<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';

import { useAuth } from '@/composables/useAuth';
import { useToast } from '@/components/ui/toast';
import GameBoard from '@/components/game/GameBoard.vue';
import { webSocketService, standardizePlayerObject } from '@/Services/WebSocketService';
import { MessageType } from '@/types/messageTypes';

interface Player {
  user_id: number;
  username: string;
  status: string;
  ready: boolean;
  score: number;
  cards_count: number;
}

interface GameState {
  roomId: string | null;
  roomName: string | null;
  players: Player[];
  hand: any[];
  playArea: any[];
  lastCard: any | null;
  deckCount: number;
  isYourTurn: boolean;
}

const { user } = useAuth();
console.log(user);
const { toast } = useToast();

const roomSizes = [2, 3, 4, 6];
const selectedSize = ref(4);
const isPrivate = ref(false);
const privateCode = ref('');
const joined = ref(false);
const gameStarted = ref(false);
const onlineCount = ref(0);

const gameState = ref<GameState>({
  roomId: null,
  roomName: null,
  players: [],
  hand: [],
  playArea: [],
  lastCard: null,
  deckCount: 0,
  isYourTurn: false
});


const ready = ref(false);

const playerReady = computed({
  get: () => {
    const userId = user.value?.id;
    if (typeof userId === 'undefined' || userId === null) return false;
    
    const myPlayer = gameState.value.players.find(player => player.user_id === userId);
    return myPlayer?.ready === true || myPlayer?.status === 'ready' || false;
  },
  set: (value) => {
    webSocketService.send({
      type: MessageType.SET_READY,
      data: {
        ready: !ready.value
      }
    });
  }
});

function toggleReady() {
  webSocketService.send({
    type: MessageType.SET_READY,
    data: {
      ready: !ready.value
    }
  });

  ready.value = !ready.value;
}

function leaveRoom() {
  webSocketService.send({
    type: MessageType.LEAVE_ROOM,
    data: {
      room_id: gameState.value.roomId
    }
  });
  joined.value = false;
  gameStarted.value = false;
  gameState.value.roomId = null;
  gameState.value.roomName = null;
  gameState.value.players = [];
}

function joinQueue() {
  webSocketService.send({
    type: MessageType.MATCHMAKING_JOIN,
    data: {
      user_id: user.value?.id,
      username: user.value?.name || user.value?.username,
      game_type: 'card_game',
      size: selectedSize.value,
      is_private: isPrivate.value,
      private_code: privateCode.value
    }
  });
}

// Add WebSocket event handlers
webSocketService.on(MessageType.MATCHMAKING_SUCCESS, (data) => {
  console.log('Matchmaking success:', data);
  joined.value = true;
  gameState.value.roomId = data.room_id;
  gameState.value.roomName = data.room_name;
  
  if (data.players && Array.isArray(data.players)) {
    console.log('Setting players:', data.players);
    gameState.value.players = data.players.map(standardizePlayerObject);
  }
});

webSocketService.on(MessageType.PLAYER_READY, (data) => {
  if (gameState.value.roomId === data.room_id) {
    const playerIndex = gameState.value.players.findIndex(p => p.user_id === data.player_id);
    if (playerIndex !== -1) {
      gameState.value.players[playerIndex].status = 'ready';
      gameState.value.players[playerIndex].ready = true;
    }
  }
});

webSocketService.on(MessageType.PLAYER_READY_STATUS, (data) => {
  if (gameState.value.roomId === data.room_id) {
    gameState.value.players = data.players.map(standardizePlayerObject);
  }
});

webSocketService.on(MessageType.SET_READY, (data) => {
  if (data.success) {
    ready.value = data.ready;
  }
});

webSocketService.on(MessageType.PLAYER_JOINED, (data) => {
  console.log('Player joined:', data);
  if (gameState.value.roomId === data.room_id) {
    if (!gameState.value.players.some(p => p.user_id === data.player_id)) {
      gameState.value.players.push(standardizePlayerObject({
        user_id: data.player_id,
        username: data.player_name,
        status: 'not_ready',
        ready: false
      }));
    }
  }
});

webSocketService.on(MessageType.PLAYER_LEFT, (data) => {
  console.log('Player left:', data);
  if (gameState.value.roomId === data.room_id) {
    gameState.value.players = gameState.value.players.filter(p => p.user_id !== data.player_id);
  }
});

webSocketService.on(MessageType.ROOM_UPDATE, (data) => {
  console.log('Room update:', data);
  if (gameState.value.roomId === data.room_id) {
    gameState.value.players = data.players.map(standardizePlayerObject);
  }
});

webSocketService.on(MessageType.ROOM_CLOSED, (data) => {
  if (gameState.value.roomId === data.room_id) {
    joined.value = false;
    gameStarted.value = false;
    gameState.value.roomId = null;
    gameState.value.roomName = null;
    gameState.value.players = [];
    toast({ 
      title: 'Pokój zamknięty', 
      description: 'Pokój został zamknięty.', 
      variant: 'destructive' 
    });
  }
});

webSocketService.on(MessageType.GAME_STARTED, (data) => {
  console.log('[WebSocket] Received game_started message:', data);
  if (gameState.value.roomId === data.room_id) {
    gameStarted.value = true;
    toast({
      title: 'Gra rozpoczęta!',
      description: 'Przekierowywanie do stołu gry...'
    });
    
    // Redirect to the game table page with room ID
    window.location.href = `/game/table?room_id=${data.room_id}`;
  }
});

webSocketService.on(MessageType.ERROR, (data) => {
  toast({ 
    title: 'Błąd', 
    description: data.message || 'Wystąpił nieznany błąd', 
    variant: 'destructive' 
  });
  joined.value = false;
  gameStarted.value = false;
  gameState.value.roomId = null;
  gameState.value.roomName = null;
  gameState.value.players = [];
});

onMounted(async () => await webSocketService.connect());
</script>

<template>
  <div class="min-h-screen flex flex-col items-center justify-center bg-gradient-to-br from-indigo-900 via-purple-900 to-gray-900">
    <div class="w-full max-w-2xl p-8 bg-white/90 dark:bg-gray-900/90 rounded-2xl shadow-2xl border border-indigo-200 dark:border-gray-800 mt-10 mb-8">
      <div class="flex items-center justify-between mb-6">
        <h2 class="text-3xl font-extrabold text-indigo-800 dark:text-indigo-200 tracking-tight flex items-center gap-2">
          <svg class="w-8 h-8 animate-pulse text-yellow-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2"/></svg>
          Gra w Chuja
        </h2>
        <div class="flex items-center gap-2 bg-indigo-100 dark:bg-indigo-800 px-3 py-1 rounded-full text-indigo-800 dark:text-indigo-100 text-sm font-semibold shadow">
          <svg class="w-4 h-4 animate-pulse text-green-500" fill="currentColor" viewBox="0 0 20 20"><circle cx="10" cy="10" r="10"/></svg>
          <span>Online: {{ onlineCount }}</span>
        </div>
      </div>
      <div v-if="!joined">
        <div class="mb-6">
          <label class="block font-semibold mb-2 text-lg text-indigo-700 dark:text-indigo-200">Wybierz tryb pokoju:</label>
          <div class="flex flex-wrap gap-4">
            <label v-for="size in roomSizes" :key="size" class="flex items-center gap-2 cursor-pointer px-4 py-2 rounded-lg border border-indigo-300 dark:border-indigo-700 bg-indigo-50 dark:bg-indigo-800 hover:bg-indigo-100 dark:hover:bg-indigo-700 transition w-full">
              <input type="radio" v-model="selectedSize" :value="size" class="accent-indigo-600" />
              <span class="font-semibold text-indigo-900 dark:text-indigo-100">Pokój {{ size }}-osobowy</span>
            </label>
          </div>
        </div>
        <div class="mb-6 flex items-center gap-4">
          <label class="flex items-center gap-2 text-indigo-700 dark:text-indigo-200">
            <input type="checkbox" v-model="isPrivate" class="accent-indigo-600" /> Prywatny pokój
          </label>
          <input v-if="isPrivate" v-model="privateCode" placeholder="Kod pokoju" class="border border-indigo-300 dark:border-indigo-700 rounded px-3 py-2 bg-white dark:bg-gray-800 text-indigo-900 dark:text-indigo-100 focus:ring-2 focus:ring-indigo-400 outline-none" />
        </div>
        <button @click="joinQueue" class="w-full py-3 mt-2 bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-700 hover:to-purple-700 text-white font-bold rounded-xl shadow-lg text-lg transition disabled:opacity-50 disabled:cursor-not-allowed">
          Dołącz do kolejki
        </button>
      </div>
      <div v-else-if="!gameStarted">
        <h3 class="text-2xl font-bold text-indigo-700 dark:text-indigo-200 mb-4">Oczekiwanie na graczy ({{ gameState.players.length }}/{{ selectedSize }})</h3>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
          <div v-for="player in gameState.players" :key="player.user_id" 
               class="flex items-center gap-4 p-4 rounded-xl bg-gradient-to-br from-indigo-100 via-purple-100 to-white dark:from-indigo-800 dark:via-purple-900 dark:to-gray-900 border border-indigo-200 dark:border-indigo-700 shadow">
            <div class="w-12 h-12 rounded-full bg-gradient-to-br from-yellow-400 to-pink-500 flex items-center justify-center text-white text-xl font-extrabold shadow-inner">
              {{ player.username.slice(0,2).toUpperCase() }}
            </div>
            <div class="flex-1">
              <div class="font-bold text-indigo-900 dark:text-indigo-100 text-lg flex items-center gap-2">
                {{ player.username }}
                <span v-if="player.user_id === user?.id" class="text-xs text-blue-500 font-semibold">(Ty)</span>
              </div>
              <div class="text-sm text-gray-600 dark:text-gray-300 flex items-center gap-2">
                <span :class="player.status === 'ready' ? 'text-green-600 font-bold' : 'text-gray-500'">
                  {{ player.status === 'ready' ? 'Gotowy' : 'Nie gotowy' }}
                </span>
                <span v-if="player.score !== undefined" class="ml-2">• Punkty: {{ player.score }}</span>
              </div>
            </div>
            <div class="flex items-center">
              <div v-if="player.status === 'ready'" class="w-3 h-3 rounded-full bg-green-500 animate-pulse"></div>
              <div v-else class="w-3 h-3 rounded-full bg-gray-400"></div>
            </div>
          </div>
        </div>
        <div class="flex flex-col gap-3">
          <button @click="toggleReady" 
                  :class="playerReady ? 'bg-yellow-500 hover:bg-yellow-600' : 'bg-green-600 hover:bg-green-700'" 
                  class="w-full py-3 text-white font-bold rounded-xl shadow-lg text-lg transition">
            {{ playerReady ? 'Nie jestem gotowy' : 'Jestem gotowy' }}
          </button>
          <button @click="leaveRoom" 
                  class="w-full py-3 bg-red-600 hover:bg-red-700 text-white font-bold rounded-xl shadow-lg text-lg transition">
            Opuść pokój
          </button>
        </div>
      </div>
      <div v-else>
        <GameBoard
          :hand="gameState.hand"
          :play-area="gameState.playArea"
          :last-card="gameState.lastCard || undefined"
          :deck-count="gameState.deckCount"
          :is-your-turn="gameState.isYourTurn"
          :players="gameState.players"
        />
      </div>
      <div class="hidden mt-8 p-6 rounded-xl bg-gradient-to-br from-indigo-50 via-purple-50 to-white dark:from-indigo-900 dark:via-purple-900 dark:to-gray-900 border border-indigo-200 dark:border-indigo-800 shadow">
        <h4 class="text-lg font-bold text-indigo-700 dark:text-indigo-200 mb-2">Jak grać?</h4>
        <ul class="list-disc list-inside text-gray-700 dark:text-gray-300 space-y-1">
          <li>Wybierz tryb pokoju i dołącz do gry.</li>
          <li>Poczekaj na innych graczy i kliknij "Jestem gotowy".</li>
          <li>Gdy wszyscy będą gotowi, gra automatycznie się rozpocznie.</li>
          <li>Wygrywa gracz, który jako pierwszy pozbędzie się wszystkich kart.</li>
        </ul>
      </div>
    </div>
  </div>
</template> 