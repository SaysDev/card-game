<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import WebSocketService, { gameState } from '@/Services/WebSocketService';
import { useAuth } from '@/composables/useAuth';
import { useToast } from '@/components/ui/toast';
import GameBoard from '@/components/game/GameBoard.vue';

const { user } = useAuth();
const { toast } = useToast();

const roomSizes = [2, 3, 4, 6];
const selectedSize = ref(2);
const isPrivate = ref(false);
const privateCode = ref('');
const joined = ref(false);
const gameStarted = ref(false);
const onlineCount = ref(0);

const playerReady = computed({
  get: () => {
    // Safely access user ID with optional chaining and type checking
    const userId = user.value?.id;
    if (typeof userId === 'undefined' || userId === null) return false;
    
    const myPlayer = gameState.players.find(player => player.user_id === userId);
    return myPlayer?.ready === true || myPlayer?.status === 'ready' || false;
  },
  set: (value) => {
    WebSocketService.setReadyStatus(value);
  }
});

function joinQueue() {
  WebSocketService.joinMatchmaking(selectedSize.value, isPrivate.value, isPrivate.value ? privateCode.value : null);
}

function toggleReady() {
  WebSocketService.setReadyStatus(!playerReady.value);
}

WebSocketService.on('room_joined', (data: any) => {
  joined.value = true;
  gameState.roomId = data.room_id;
  gameState.roomName = data.room_name;
  
  console.log('Room joined data:', data);
  
  if (data.players && Array.isArray(data.players)) {
    gameState.players = data.players.map((p: any) => {
      // Ensure we have valid user_id and username
      const userId = p.user_id || p.id || 0;
      const username = p.username || p.name || 'Gracz';
      const status = p.status || 'not_ready';
      const ready = p.ready || status === 'ready';
      
      return {
        user_id: userId,
        username: username,
        status: status,
        ready: ready,
        score: p.score ?? 0,
        cards_count: p.cards_count ?? (p.cards ? p.cards.length : 0)
      };
    });
  }
});

WebSocketService.on('player_status_changed', (data: { player_id: number; status: string; ready: boolean; }) => {
  const playerIndex = gameState.players.findIndex(p => p.user_id === data.player_id);
  if (playerIndex !== -1) {
    gameState.players[playerIndex].status = data.status;
    gameState.players[playerIndex].ready = data.ready;
  }
});

WebSocketService.on('ready_status_updated', (data: { ready: boolean; status: string; }) => {
  const myId = user.value?.id;
  const playerIndex = gameState.players.findIndex(p => p.user_id === myId);
  if (playerIndex !== -1) {
    gameState.players[playerIndex].status = data.status;
    gameState.players[playerIndex].ready = data.ready;
  }
});

WebSocketService.on('game_started', () => {
  gameStarted.value = true;
  toast({
    title: 'Gra rozpoczęta',
    description: 'Gra została rozpoczęta!',
  });
});

WebSocketService.on('online_count', (count: number) => {
  onlineCount.value = count;
});

WebSocketService.on('room_full', () => {
  toast({ title: 'Pokój pełny', description: 'Wybrany pokój jest już pełny.', variant: 'destructive' });
  joined.value = false;
  gameStarted.value = false;
  gameState.roomId = null;
  gameState.roomName = null;
  gameState.players = [];
});

WebSocketService.on('room_not_found', () => {
  toast({ title: 'Nie znaleziono pokoju', description: 'Pokój nie istnieje lub został zamknięty.', variant: 'destructive' });
  joined.value = false;
  gameStarted.value = false;
  gameState.roomId = null;
  gameState.roomName = null;
  gameState.players = [];
});

WebSocketService.on('server_error', (err: { message: string }) => {
  toast({ title: 'Błąd serwera', description: err.message, variant: 'destructive' });
  joined.value = false;
  gameStarted.value = false;
  gameState.roomId = null;
  gameState.roomName = null;
  gameState.players = [];
});

function leaveRoom() {
  WebSocketService.leaveRoom();
  joined.value = false;
  gameStarted.value = false;
  gameState.roomId = null;
  gameState.roomName = null;
  gameState.players = [];
}

onMounted(() => {
  if (!WebSocketService.isConnectedAndReady()) {
    WebSocketService.connect();
  }
  // setInterval(() => {
  //   if (WebSocketService.isConnectedAndReady()) {
  //     WebSocketService.getOnlineCount();
  //   }
  // }, 1000);
});
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
            <label v-for="size in roomSizes" :key="size" class="flex items-center gap-2 cursor-pointer px-4 py-2 rounded-lg border border-indigo-300 dark:border-indigo-700 bg-indigo-50 dark:bg-indigo-800 hover:bg-indigo-100 dark:hover:bg-indigo-700 transition">
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
        <button @click="joinQueue" :disabled="!gameState.isAuthenticated" class="w-full py-3 mt-2 bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-700 hover:to-purple-700 text-white font-bold rounded-xl shadow-lg text-lg transition disabled:opacity-50 disabled:cursor-not-allowed">
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
      <div class="mt-8 p-6 rounded-xl bg-gradient-to-br from-indigo-50 via-purple-50 to-white dark:from-indigo-900 dark:via-purple-900 dark:to-gray-900 border border-indigo-200 dark:border-indigo-800 shadow">
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