<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ref, onMounted, computed, onBeforeUnmount } from 'vue';
import GameBoard from '@/components/game/GameBoard.vue';
import { useWebSocket } from '@/composables/useWebSocket';
import { useToast } from '@/components/ui/toast';
import { useAuth } from '@/composables/useAuth';
import { useInitials } from '@/composables/useInitials';
import WebSocketService, { gameState } from '@/Services/WebSocketService';

import { GameRoomService } from '@/Services/GameRoomService';
const props = defineProps<{
  game: {
    id: number;
    name: string;
    status: string;
    max_players: number;
    current_players: number;
    created_at: string;
    room_id?: string; // Optional room_id property
    game_data: {
      players: number[];
      deck: any[];
      current_turn: number;
      game_started: boolean;
      last_updated: number;
    };
    players: {
      id: number;
      name: string;
      email: string;
      status: string;
      score: number;
      cards: any[];
      user?: {
        id: number;
        name: string;
      };
      user_id?: number;
    }[];
  };
  isPlayer: boolean;
  canJoin: boolean;
}>();

const { toast } = useToast();
const { isConnected, connect, joinRoom, leaveRoom, on } = useWebSocket();
const { user } = useAuth();
const { getInitials } = useInitials();

const gameStarted = ref(props.game.game_data.game_started);
const playerReady = computed({
  get: () => gameState.playerReady,
  set: (value) => { gameState.playerReady = value; }
});

const isCurrentUserTurn = computed(() => {
  if (!user.value || !props.game.game_data.players) return false;

  // Find the current player's index in the players array
  const playerIndex = props.game.game_data.players.indexOf(user.value.id);
  return playerIndex === props.game.game_data.current_turn;
});

onMounted(() => {
  // Check if this game is the user's active game
  const inThisGame = props.isPlayer;

  // Connect to WebSocket server using the WebSocketService singleton
  if (user.value) {
    // First register event handlers
    WebSocketService.on('connected', () => {
      console.log('WebSocket connected, authenticated next');
    });

    WebSocketService.on('authenticated', () => {
      console.log('Successfully authenticated');
      // Only join room after authentication is successful
      if (props.isPlayer && props.game?.id) {
        const roomId = props.game.id.toString();
        console.log('Joining game room:', roomId);
        console.log('Game object:', JSON.stringify(props.game, null, 2));

        // Make sure the room exists in the backend
        // Server prefixes room IDs with 'room_'
        // If game.room_id exists, use it instead of the game.id
        if (props.game.room_id !== undefined) {
          console.log('Using explicit room_id from game object:', props.game.room_id);
          WebSocketService.joinRoom(props.game.room_id);
        } else {
          // Otherwise use the game ID
          // Import and use GameRoomService for better room handling
          GameRoomService.joinRoom(roomId);
        }
      } else {
        console.log('Not joining a room - not a player or no game ID', {
          isPlayer: props.isPlayer,
          gameId: props.game?.id
        });
      }
    });

    WebSocketService.on('room_joined', (data: any) => {
      toast({
        title: 'Dołączono do pokoju',
        description: `Dołączono do pokoju: ${data.room_name}`,
      });

      // Log players data for debugging
      console.log('Room joined, received players data:', data.players);
      console.log('Current gameState.players:', gameState.players);

      // Check for duplicate user IDs in players array
      if (data.players && Array.isArray(data.players)) {
        const userIds = data.players.map(p => p.user_id);
        const uniqueUserIds = [...new Set(userIds)];

        if (userIds.length !== uniqueUserIds.length) {
          console.warn('Duplicate user IDs detected in players array:',
            userIds.filter((id, index) => userIds.indexOf(id) !== index));

          // Print out the full player information for debugging
          const idCounts = {};
          const duplicates = [];

          data.players.forEach(player => {
            const userId = player.user_id;
            idCounts[userId] = (idCounts[userId] || 0) + 1;

            if (idCounts[userId] > 1) {
              duplicates.push(player);
            }
          });

          console.log('Duplicate player details:', duplicates);
        }
      }
    });

    WebSocketService.on('game_started', () => {
      gameStarted.value = true;
      toast({
        title: 'Gra rozpoczęta',
        description: 'Gra została rozpoczęta!',
      });
    });

    WebSocketService.on('player_joined', (data: any) => {
      toast({
        title: 'Nowy gracz',
        description: `${data.player.username} dołączył do gry`,
      });
    });

    WebSocketService.on('player_left', (data: any) => {
      toast({
        title: 'Gracz opuścił grę',
        description: `${data.username} opuścił grę`,
      });
    });

    WebSocketService.on('server_error', (data: any) => {
      toast({
        title: 'Błąd serwera',
        description: data.message,
        variant: 'destructive',
      });
    });

    WebSocketService.on('room_not_found', (data: any) => {
      console.error('Room not found error:', data);
      toast({
        title: 'Nie znaleziono pokoju',
        description: 'Pokój gry nie istnieje lub został zamknięty',
        variant: 'destructive',
      });

      // If you want to redirect to a list of games or create a new room
      // You can do it here
    });

    // Now connect to the WebSocket server
    console.log('Connecting to WebSocket with user ID:', user.value.id, 'Type:', typeof user.value.id);
    WebSocketService.connect(user.value.id, user.value.name, 'token');
  }
});

const toggleReady = () => {
  // First check if WebSocketService is available and connected
  if (!WebSocketService || !WebSocketService.isConnectedAndReady()) {
    console.error('Cannot set ready state: WebSocket is not connected');
    toast({
      title: 'Błąd połączenia',
      description: 'Nie można zmienić statusu gotowości - utracono połączenie z serwerem.',
      variant: 'destructive',
    });
    return;
  }

  playerReady.value = !playerReady.value;

  if (gameState.connected && gameState.roomId) {
    // Use the dedicated method to set ready status
    console.log(`Setting player ready status to: ${playerReady.value}`);
    WebSocketService.setReadyStatus(playerReady.value);
  } else {
    console.error('Cannot set ready state: Not connected to a game room');
    toast({
      title: 'Błąd',
      description: 'Nie jesteś połączony z pokojem gry',
      variant: 'destructive',
    });
    // Revert the ready state since we couldn't send it
    playerReady.value = !playerReady.value;
    return;
  }

  toast({
    title: playerReady.value ? 'Gotowy' : 'Niegotowy',
    description: playerReady.value ? 'Jesteś gotowy do gry' : 'Oczekujesz na rozpoczęcie gry',
  });
};

// Game action handlers
const handlePlayCard = (cardIndex: number) => {
  // First, check if service exists and method is available
  if (!WebSocketService || typeof WebSocketService.playCard !== 'function') {
    console.error('WebSocketService.playCard is not available');
    toast({
      title: 'Błąd',
      description: 'Nie można zagrać karty - problem z serwisem WebSocket',
      variant: 'destructive',
    });
    return;
  }

  // Then check connection status
  if (!WebSocketService.isConnectedAndReady()) {
    const status = WebSocketService.getConnectionStatus();
    console.error('Cannot play card: WebSocket connection issue', status);
    toast({
      title: 'Błąd połączenia',
      description: 'Nie można zagrać karty - utracono połączenie z serwerem. Spróbuj odświeżyć stronę.',
      variant: 'destructive',
    });
    return;
  }

  // If all checks pass, play the card
  WebSocketService.playCard(cardIndex);
};

const handleDrawCard = () => {
  // First, check if service exists and method is available
  if (!WebSocketService || typeof WebSocketService.drawCard !== 'function') {
    console.error('WebSocketService.drawCard is not available');
    toast({
      title: 'Błąd',
      description: 'Nie można dobrać karty - problem z serwisem WebSocket',
      variant: 'destructive',
    });
    return;
  }

  // Then check connection status
  if (!WebSocketService.isConnectedAndReady()) {
    console.error('Cannot draw card: WebSocket connection issue');
    toast({
      title: 'Błąd połączenia',
      description: 'Nie można dobrać karty - utracono połączenie z serwerem. Spróbuj odświeżyć stronę.',
      variant: 'destructive',
    });
    return;
  }

  // If all checks pass, draw the card
  WebSocketService.drawCard();
};

const handlePassTurn = () => {
  // First, check if service exists and method is available
  if (!WebSocketService || typeof WebSocketService.passTurn !== 'function') {
    console.error('WebSocketService.passTurn is not available');
    toast({
      title: 'Błąd',
      description: 'Nie można spasować - problem z serwisem WebSocket',
      variant: 'destructive',
    });
    return;
  }

  // Then check connection status
  if (!WebSocketService.isConnectedAndReady()) {
    console.error('Cannot pass turn: WebSocket connection issue');
    toast({
      title: 'Błąd połączenia',
      description: 'Nie można spasować - utracono połączenie z serwerem. Spróbuj odświeżyć stronę.',
      variant: 'destructive',
    });
    return;
  }

  // If all checks pass, pass the turn
  WebSocketService.passTurn();
};

// Connection refresh method
const refreshConnection = () => {
  if (!user.value) {
    toast({
      title: 'Błąd',
      description: 'Brak informacji o użytkowniku do ponownego połączenia',
      variant: 'destructive',
    });
    return;
  }

  // Check current connection status
  if (WebSocketService) {
    const status = WebSocketService.getConnectionStatus();
    console.log('Current WebSocket status:', status);

    // If disconnected, try to reconnect
    if (!WebSocketService.isConnectedAndReady()) {
      toast({
        title: 'Połączenie',
        description: 'Próba ponownego nawiązania połączenia z serwerem...',
      });

      // Disconnect first to clean up any existing connection
      WebSocketService.disconnect();

      // Then reconnect
      setTimeout(() => {
        WebSocketService.connect(user.value.id, user.value.name, 'token');
      }, 500);
    } else {
      toast({
        title: 'Połączenie',
        description: 'Połączenie z serwerem aktywne',
      });
    }
  }
};

// Clean up WebSocket connections when component is unmounted
onBeforeUnmount(() => {
  if (gameState.roomId) {
    WebSocketService.leaveRoom();
  }
  // Remove event listeners to prevent memory leaks
  WebSocketService.disconnect();
});
</script>

<template>
  <Head :title="`Gra: ${game.name}`" />

  <div v-if="gameStarted" class="game-container">
    <GameBoard
      :hand="gameState.hand"
      :play-area="gameState.playArea"
      :last-card="gameState.lastCard"
      :deck-count="gameState.deckCount"
      :is-your-turn="gameState.isYourTurn"
      :players="gameState.players"
      @play-card="handlePlayCard"
      @draw-card="handleDrawCard"
      @pass-turn="handlePassTurn"
    />
  </div>

  <div v-else class="py-12">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
      <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow sm:rounded-lg">
        <div class="flex justify-between items-center mb-6">
          <div>
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ game.name }}</h2>
            <p class="text-sm text-gray-600 dark:text-gray-400">
              Status:
              <span
                :class="{
                  'px-2 py-1 rounded text-xs font-semibold': true,
                  'bg-yellow-100 text-yellow-800': game.status === 'waiting' && gameState.status === 'waiting',
                  'bg-green-100 text-green-800': game.status === 'in_progress' || gameState.status === 'playing',
                  'bg-red-100 text-red-800': game.status === 'ended' || gameState.status === 'ended'
                }"
              >
                {{ (gameState.status === 'waiting' || game.status === 'waiting') ? 'Oczekuje' :
                   (gameState.status === 'playing' || game.status === 'in_progress') ? 'W trakcie' : 'Zakończona' }}
              </span>
            </p>
          </div>

          <div class="flex items-center space-x-4">
            <!-- WebSocket status indicator -->
            <div
              class="px-2 py-1 rounded text-xs font-semibold flex items-center gap-1"
              :class="{
                'bg-green-100 text-green-800': WebSocketService?.isConnectedAndReady() && gameState.isAuthenticated,
                'bg-yellow-100 text-yellow-800': gameState.connected && !gameState.isAuthenticated,
                'bg-red-100 text-red-800': !WebSocketService?.isConnectedAndReady()
              }"
              @click="refreshConnection"
              title="Kliknij, aby odświeżyć połączenie"
              style="cursor: pointer;"
            >
              <span
                class="w-2 h-2 rounded-full"
                :class="{
                  'bg-green-500': WebSocketService?.isConnectedAndReady() && gameState.isAuthenticated,
                  'bg-yellow-500': gameState.connected && !gameState.isAuthenticated,
                  'bg-red-500': !WebSocketService?.isConnectedAndReady()
                }"
              ></span>
              {{ !WebSocketService?.isConnectedAndReady() ? 'Rozłączono' :
                 !gameState.isAuthenticated ? 'Łączenie' : 'Połączono' }}
            </div>

            <Link
              :href="route('games.index')"
              class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700 focus:bg-gray-700 active:bg-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150"
            >
              Powrót do listy gier
            </Link>

            <template v-if="isPlayer">
              <button
                @click="WebSocketService.leaveRoom()"
                class="inline-flex items-center px-4 py-2 bg-red-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-500 active:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 transition ease-in-out duration-150"
              >
                Opuść grę
              </button>

              <button
                @click="toggleReady"
                :class="{
                  'inline-flex items-center px-4 py-2 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest focus:outline-none focus:ring-2 focus:ring-offset-2 transition ease-in-out duration-150': true,
                  'bg-green-600 hover:bg-green-500 active:bg-green-700 focus:ring-green-500': !playerReady,
                  'bg-yellow-600 hover:bg-yellow-500 active:bg-yellow-700 focus:ring-yellow-500': playerReady
                }"
              >
                {{ playerReady ? 'Nie jestem gotowy' : 'Jestem gotowy' }}
              </button>
            </template>

            <template v-else-if="canJoin">
              <button
                @click="WebSocketService.joinRoom(game.id.toString())"
                class="inline-flex items-center px-4 py-2 bg-green-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-green-500 active:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2 transition ease-in-out duration-150"
              >
                Dołącz do gry
              </button>
            </template>
          </div>
        </div>

        <div class="mb-6">
          <h3 class="text-md font-medium text-gray-900 dark:text-gray-100 mb-2">Gracze ({{ gameState.players.length || game.current_players }}/{{ game.max_players }}):</h3>

          <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            <div
              v-for="player in gameState.players.length ? gameState.players : game.players"
              :key="player.user_id || player.id"
              class="p-4 bg-gray-100 dark:bg-gray-700 rounded-lg"
            >
              <div class="flex items-center space-x-3">
                <div class="flex-shrink-0">
                  <div class="w-10 h-10 rounded-full bg-blue-500 flex items-center justify-center text-white font-bold">
                    {{ getInitials(player.username || player.name || 'Gracz') }}
                  </div>
                </div>
                <div>
                  <h4 class="text-sm font-medium text-gray-900 dark:text-gray-100">
                    {{ player.username || player.name || (player.user ? player.user.name : 'Gracz') }}
                    <span v-if="user && (player.user_id === user.id || player.id === user.id)" class="text-xs text-gray-500 dark:text-gray-400">(Ty)</span>
                  </h4>
                  <p class="text-xs text-gray-500 dark:text-gray-400">
                    Status: {{ player.status === 'ready' ? 'Gotowy' : 'Oczekuje' }}
                  </p>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="mt-8 p-4 bg-gray-100 dark:bg-gray-700 rounded-lg">
          <h3 class="text-md font-medium text-gray-900 dark:text-gray-100 mb-2">Zasady gry:</h3>
          <ul class="list-disc list-inside text-sm text-gray-700 dark:text-gray-300 space-y-1">
            <li>Gra przeznaczona jest dla 2-8 graczy</li>
              <li>Gracz z 9❤️ zaczyna </li>
            <li>Gracze w swojej turze mogą zagrać jedną kartę lub 4 tego samego typu</li>
            <li>Karty wyższego rzędu biją karty niższego rzędu</li>
            <li>Wygrywa gracz, który jako pierwszy pozbędzie się wszystkich kart</li>
          </ul>
        </div>
      </div>
    </div>
  </div>
</template>
