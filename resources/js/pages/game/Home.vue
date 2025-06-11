<template>
  <MobileGameLayout>
    <div class="flex-grow flex flex-col items-center justify-center relative w-full h-full">
      <!-- Animated background elements -->
      <div class="absolute inset-0 overflow-hidden">
        <div class="bg-pattern"></div>
        <div class="bg-glow"></div>
        <div class="bg-particles"></div>
      </div>

      <div v-if="isInMatchmaking" class="absolute inset-0 flex items-center justify-center z-50">
        <div class="bg-gray-900/95 p-6 rounded-2xl w-screen h-screen flex justify-center items-center border border-indigo-500/30 shadow-2xl backdrop-blur-sm w-80">
          <!-- <div class="window-title-bar mb-6">
            <span class="window-icon text-2xl animate-bounce">🎮</span>
             <span class="text-xl font-bold bg-gradient-to-r from-indigo-400 to-purple-400 bg-clip-text text-transparent">Wyszukiwanie gry</span> 
          </div> -->
          <div class="dialog-content-inner text-center">
            <div class="flex justify-center mb-4">
              <div class="w-12 h-12 border-4 border-indigo-500 border-t-transparent rounded-full animate-spin"></div>
            </div>
            <p class="text-indigo-200 mb-6">Szukamy przeciwników...</p>
            <p class="text-indigo-200 mb-6">Znaleziono graczy: {{ matchmakingStatus.players.length }}</p>

            <div class="flex flex-col gap-3 mb-6 w-full">
              <div v-for="player in matchmakingStatus.players" :key="player.user_id"  
                   class="inline-flex items-center justify-start bg-indigo-900/50 p-2 rounded-lg border border-indigo-500/30">
                
                <div class="w-full flex justify-between items-center">
                  <div class="text-indigo-100 font-medium">{{ player.username }}</div>
                  <div class="text-indigo-100 font-medium">{{ player.status }}</div>
                </div>
              </div>
            </div>


            <div class="flex flex-col gap-2">
              <p>DEBUG</p>
              <p>room_id: {{ matchmakingStatus.room_id }}</p>
              <p>game_type: {{ matchmakingStatus.game_type }}</p>
            </div>

            <div class="animate-pulse text-sm text-indigo-300/70 mb-8">
              Średni czas oczekiwania: ~30s
            </div>
            <div class="flex flex-col gap-4">
              <button @click="toggleReady" 
                      :class="{
                        'w-full py-2 px-4 rounded-lg transition-colors font-semibold': true,
                        'bg-green-500 hover:bg-green-600 text-white': !isReady,
                        'bg-yellow-500 hover:bg-yellow-600 text-white': isReady
                      }">
                {{ isReady ? 'Nie jestem gotowy' : 'Jestem gotowy' }}
              </button>

              <button @click="cancelMatchmaking" 
                      class="w-full py-2 px-4 bg-red-500 hover:bg-red-600 text-white rounded-lg transition-colors font-semibold">
                Anuluj
              </button>
            </div>
          </div>
        </div>
      </div>

      <transition-group name="v">
        <div key="home" class="w-full h-full p-4 relative z-10">
          <h2 class="text-3xl font-bold text-center text-white animate-fade-in drop-shadow-lg">Lobby</h2>
          
          <div class="flex items-center justify-center gap-6 mt-40">
            <button @click="joinMatchmaking" 
                    class="game-mode-btn group relative overflow-hidden animate-slide-up">
              <div class="absolute inset-0 bg-gradient-to-br from-blue-500 to-purple-600 opacity-90 group-hover:opacity-100 transition-opacity"></div>
              <div class="relative z-10 flex flex-col items-center justify-center p-6">
                <div class="text-4xl mb-2 animate-bounce-slow">⚔️</div>
                <span class="text-sm font-bold text-white">ZAGRAJ</span>
              </div>
            </button>

            <button class="game-mode-btn group relative overflow-hidden animate-slide-up animation-delay-200">
              <div class="absolute inset-0 bg-gradient-to-br from-cyan-500 to-blue-600 opacity-90 group-hover:opacity-100 transition-opacity"></div>
              <div class="relative z-10 flex flex-col items-center justify-center p-6">
                <div class="text-4xl mb-2 animate-bounce-slow">🏆</div>
                <span class="text-sm font-bold text-white">TURNIEJ</span>
              </div>
            </button>
          </div>

          <div class="flex items-center justify-center gap-6 mt-5">
            <button @click="startGameFlow" 
                    class="game-mode-btn duel-btn group relative overflow-hidden animate-slide-up animation-delay-400">
              <div class="absolute inset-0 bg-gradient-to-br from-yellow-500 to-red-600 opacity-90 group-hover:opacity-100 transition-opacity"></div>
              <div class="relative z-10 flex flex-col items-center justify-center p-6">
                <div class="text-4xl mb-2 animate-bounce-slow">🫂</div>
                <span class="text-sm font-bold text-white">ZNAJOMI</span>
              </div>
            </button>
          </div>
        </div>
      </transition-group>
    </div>
  </MobileGameLayout>
</template>

<script setup lang="ts">
import MobileGameLayout from '@/layouts/MobileGameLayout.vue';
import { webSocketService } from '@/Services/WebSocketService';
import { router } from '@inertiajs/vue3';
import { MessageType } from '@/types/messageTypes';
import { usePage } from '@inertiajs/vue3';
import Dialog from '@/components/ui/dialog/Dialog.vue';
import DialogContent from '@/components/ui/dialog/DialogContent.vue';
import DialogTitle from '@/components/ui/dialog/DialogTitle.vue';
import { onMounted, ref, computed } from 'vue';
import { toast, type ToastOptions } from 'vue3-toastify';

interface Player {
  user_id: number;
  username: string;
  status: string;
  ready: boolean;
}

interface MatchmakingStatus {
  room_id: string | null;
  game_type: string | null;
  players: Player[];
}

interface PageProps {
  auth: {
    user: {
      id: number;
      name: string;
      username: string;
    };
  };
}

const page = usePage<PageProps>();
const waitingPlayers = ref<Player[]>([]);
const matchmakingStatus = ref<MatchmakingStatus>({
  room_id: null,
  game_type: null,
  players: []
});

const isInMatchmaking = ref(false);
const isReady = ref(false);

const playerReady = computed({
  get: () => {
    const userId = page.props?.auth?.user?.id;
    if (!userId) return false;
    
    const myPlayer = matchmakingStatus.value.players.find(player => player.user_id === userId);
    return myPlayer?.ready || false;
  },
  set: (value) => {
    webSocketService.send({
      type: MessageType.SET_READY,
      data: {
        ready: !isReady.value
      }
    });
  }
});

function setupWebSocketHandlers() {
  webSocketService.on(MessageType.MATCHMAKING_SUCCESS, (data) => {
    matchmakingStatus.value.room_id = data.room_id;
    matchmakingStatus.value.game_type = data.game_type;
    matchmakingStatus.value.players = data.players;
  });

  webSocketService.on(MessageType.MATCHMAKING_LEAVE_SUCCESS, (data) => {
    isInMatchmaking.value = false;
    isReady.value = false;
    toast("Anulowano matchmaking", { type: 'success' });
  });

  webSocketService.on(MessageType.PLAYER_JOINED, (data) => {
    if (matchmakingStatus.value.room_id !== data.room_id) return;
    
    const existingPlayer = matchmakingStatus.value.players.find(
      player => player.user_id === data.player.user_id
    );
    
    if (!existingPlayer) {
      matchmakingStatus.value.players.push(data.player);
    }
  });

  webSocketService.on(MessageType.PLAYER_LEFT, (data) => {
    matchmakingStatus.value.players = matchmakingStatus.value.players.filter(
      player => player.user_id !== data.player.user_id
    );
  });

  webSocketService.on(MessageType.PLAYER_READY_STATUS, (data) => {
    if (matchmakingStatus.value.room_id === data.room_id) {
      matchmakingStatus.value.players = data.players;
    }
  });

  webSocketService.on(MessageType.SET_READY, (data) => {
    if (data.success) {
      isReady.value = data.ready;
    }
  });

  webSocketService.on(MessageType.ERROR, (data) => {
    toast(data.message, { type: 'error' });
    isInMatchmaking.value = false;
    isReady.value = false;
  });
}

function joinMatchmaking() {
  isInMatchmaking.value = true;
  webSocketService.send({
    type: MessageType.MATCHMAKING_JOIN,
    data: {
      user_id: page.props?.auth?.user?.id,
      username: page.props?.auth?.user?.name || page.props?.auth?.user?.username,
      game_type: 'card_game',
      is_private: false,
      private_code: null
    }
  });
}

function toggleReady() {
  webSocketService.send({
    type: MessageType.SET_READY,
    data: {
      ready: !isReady.value
    }
  });
}

const cancelMatchmaking = () => {
  if (!webSocketService.isConnected) {
    toast("Nie jesteś połączony z serwerem");
    return;
  }
  
  webSocketService.send({
    type: MessageType.MATCHMAKING_LEAVE
  });
  isInMatchmaking.value = false;
  isReady.value = false;
};

onMounted(() => {
  setupWebSocketHandlers();
});
</script>

<style scoped>
.game-mode-btn {
  width: 110px;
  height: 110px;
  border-radius: 20px;
  box-shadow: 0 8px 20px rgba(0, 0, 0, 0.5);
  transition: all 0.3s ease;
  backdrop-filter: blur(8px);
}

.game-mode-btn:hover {
  transform: translateY(-5px) scale(1.05);
  box-shadow: 0 12px 25px rgba(0, 0, 0, 0.3);
}

.game-mode-btn:active {
  transform: translateY(-2px) scale(0.98);
}

.duel-btn {
  background: linear-gradient(135deg, #3B82F6 0%, #8B5CF6 100%);
}

.tournament-btn {
  background: linear-gradient(135deg, #F59E0B 0%, #DC2626 100%);
}

/* Background effects */
.bg-pattern {
  position: absolute;
  inset: 0;
  background: 
    radial-gradient(circle at 20% 20%, rgba(59, 130, 246, 0.1) 0%, transparent 50%),
    radial-gradient(circle at 80% 80%, rgba(139, 92, 246, 0.1) 0%, transparent 50%),
    radial-gradient(circle at 50% 50%, rgba(245, 158, 11, 0.1) 0%, transparent 50%);
  animation: patternMove 20s linear infinite;
}

.bg-glow {
  position: absolute;
  inset: 0;
  background: 
    radial-gradient(circle at 50% 0%, rgba(59, 130, 246, 0.15) 0%, transparent 50%),
    radial-gradient(circle at 0% 50%, rgba(139, 92, 246, 0.15) 0%, transparent 50%),
    radial-gradient(circle at 100% 50%, rgba(245, 158, 11, 0.15) 0%, transparent 50%);
  filter: blur(60px);
  animation: glowPulse 8s ease-in-out infinite;
}

.bg-particles {
  position: absolute;
  inset: 0;
  background-image: 
    radial-gradient(circle at 25% 25%, rgba(255, 255, 255, 0.1) 1px, transparent 1px),
    radial-gradient(circle at 75% 75%, rgba(255, 255, 255, 0.1) 1px, transparent 1px);
  background-size: 50px 50px;
  animation: particleFloat 15s linear infinite;
}

@keyframes patternMove {
  0% {
    transform: scale(1) rotate(0deg);
  }
  50% {
    transform: scale(1.1) rotate(1deg);
  }
  100% {
    transform: scale(1) rotate(0deg);
  }
}

@keyframes glowPulse {
  0%, 100% {
    opacity: 0.5;
    transform: scale(1);
  }
  50% {
    opacity: 0.8;
    transform: scale(1.1);
  }
}

@keyframes particleFloat {
  0% {
    background-position: 0 0;
  }
  100% {
    background-position: 50px 50px;
  }
}

/* Animation classes */
.animate-fade-in {
  animation: fadeIn 0.8s ease-out;
}

.animate-slide-up {
  animation: slideUp 0.6s ease-out forwards;
  opacity: 0;
}

.animate-bounce-slow {
  animation: bounce 2s infinite;
}

.animation-delay-200 {
  animation-delay: 200ms;
}

.animation-delay-400 {
  animation-delay: 400ms;
}

@keyframes fadeIn {
  from {
    opacity: 0;
  }
  to {
    opacity: 1;
  }
}

@keyframes slideUp {
  from {
    opacity: 0;
    transform: translateY(30px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

@keyframes bounce {
  0%, 100% {
    transform: translateY(0);
  }
  50% {
    transform: translateY(-10px);
  }
}

.v-enter-active,
.v-leave-active {
  transition: opacity 0.3s ease;
}

.v-enter-from,
.v-leave-to {
  opacity: 0;
}
</style> 