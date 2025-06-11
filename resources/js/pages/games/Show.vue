<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ref, onMounted, computed, onBeforeUnmount } from 'vue';
import GameBoard from '@/components/game/GameBoard.vue';
import { useWebSocket } from '@/composables/useWebSocket';
import { useToast } from '@/components/ui/toast';
import { useAuth } from '@/composables/useAuth';
import { useInitials } from '@/composables/useInitials';
import { webSocketService, gameState, standardizePlayerObject } from '@/Services/WebSocketService';
import { GameRoomService } from '@/Services/GameRoomService';
import { MessageType } from '@/types/messageTypes';

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
const { user } = useAuth();
const { getInitials } = useInitials();

const gameStarted = ref(props.game.game_data.game_started);
const isCurrentPlayerInGame = ref(false);
isCurrentPlayerInGame.value = props.isPlayer;
const playerReady = computed({
  get: () => gameState.players.find(player => player.user_id === user.value?.id)?.status === 'ready' || false,
  set: (value) => {
    webSocketService.send({
      type: MessageType.SET_READY,
      data: { ready: value }
    });
  }
});

const isCurrentUserTurn = computed(() => {
  if (!user.value || !props.game.game_data.players) return false;
  const playerIndex = props.game.game_data.players.indexOf(user.value.id);
  return playerIndex === props.game.game_data.current_turn;
});

// Count ready players for the Start Game button
const readyPlayersCount = computed(() => {
  if (!gameState.players || !gameState.players.length) return 0;
  return gameState.players.filter(player => player.status === 'ready').length;
});

const isRoomCreator = computed(() => {
  if (!props.game.players || !props.game.players.length || !user.value) return false;
  const creatorId = props.game.players[0].user_id ?? props.game.players[0].id;
  return user.value.id === creatorId;
});

const handleStartGame = () => {
  if (!webSocketService.isConnected()) {
    toast({
      title: 'Błąd połączenia',
      description: 'Nie można rozpocząć gry - brak połączenia z serwerem.',
      variant: 'destructive',
    });
    return;
  }
  if (!isRoomCreator.value) {
    toast({
      title: 'Błąd',
      description: 'Tylko twórca pokoju może rozpocząć grę.',
      variant: 'destructive',
    });
    return;
  }
  const readyPlayers = gameState.players.filter(p => p.status === 'ready').length;
  if (readyPlayers < 2) {
    toast({
      title: 'Za mało graczy',
      description: 'Do rozpoczęcia gry potrzeba co najmniej 2 gotowych graczy.',
      variant: 'destructive',
    });
    return;
  }
  webSocketService.send({
    type: MessageType.START_GAME,
    room_id: gameState.roomId
  });
  toast({
    title: 'Rozpoczynanie gry',
    description: 'Rozpoczynanie gry w toku...',
  });
};

onMounted(() => {
  const authInfo = getAuthInfo();
  if (!authInfo) {
    console.error('No authenticated user available to connect to WebSocket');
    toast({
      title: 'Błąd autoryzacji',
      description: 'Nie można nawiązać połączenia - brak zalogowanego użytkownika lub tokenu',
      variant: 'destructive',
    });
    return;
  }
  webSocketService.connect();

  // Prevent duplicate listeners
  webSocketService.off('authenticated', handleAuthenticated);
  webSocketService.off('room_joined', handleRoomJoined);

  function handleAuthenticated() {
    if (!props.isPlayer && props.game?.id) {
      handleJoinRoom();
    } else if (props.isPlayer && props.game?.id) {
      gameState.roomId = props.game.room_id?.toString() || props.game.id.toString();
      gameState.roomName = props.game.name;
      gameState.status = props.game.status as 'waiting' | 'playing' | 'ended';
      if (props.game.players && Array.isArray(props.game.players)) {
        gameState.players = props.game.players.map(player => ({
          user_id: player.user?.id ?? player.user_id ?? 0,
          username: player.user?.name ?? player.name ?? 'Gracz',
          status: player.status === 'ready' ? 'ready' : 'not_ready',
          ready: player.status === 'ready',
          score: player.score ?? 0,
          cards_count: player.cards ? player.cards.length : 0
        }));
      }
      isCurrentPlayerInGame.value = true;
      const roomId = props.game.room_id?.toString() || props.game.id.toString();
      webSocketService.send({
        type: MessageType.JOIN_ROOM,
        data: { room_id: roomId }
      });
    } else {
      console.log('User is not a player and game ID is missing.');
    }
  }

  function handleRoomJoined(data: { room_id: string; room_name: string; players: any[] }) {
    console.log('[room_joined] Setting gameState.roomId to', data.room_id);
    toast({
      title: 'Dołączono do pokoju',
      description: `Dołączono do pokoju: ${data.room_name}`,
    });
    isCurrentPlayerInGame.value = true;
    if (data.players && Array.isArray(data.players)) {
      gameState.players = data.players.map(standardizePlayerObject);
    }
    gameState.roomId = data.room_id;
    gameState.roomName = data.room_name;
    webSocketService.send({
      type: MessageType.SET_READY,
      data: { ready: true }
    });
  }

  webSocketService.on('authenticated', handleAuthenticated);
  webSocketService.on('room_joined', handleRoomJoined);

  webSocketService.on('game_started', () => {
    gameStarted.value = true;
    toast({
      title: 'Gra rozpoczęta',
      description: 'Gra została rozpoczęta!',
    });
  });

  webSocketService.on('player_joined', (data: any) => {
    toast({
      title: 'Nowy gracz',
      description: `${data.player.username} dołączył do gry`,
    });
  });

  webSocketService.on('player_left', (data: any) => {
    toast({
      title: 'Gracz opuścił grę',
      description: `${data.username} opuścił grę`,
    });
  });

  webSocketService.on('server_error', (data: any) => {
    toast({
      title: 'Błąd serwera',
      description: data.message,
      variant: 'destructive',
    });
  });

  webSocketService.on('room_not_found', (data: any) => {
    console.error('Room not found error:', data);
    toast({
      title: 'Nie znaleziono pokoju',
      description: 'Pokój gry nie istnieje lub został zamknięty',
      variant: 'destructive',
    });
  });

  webSocketService.on('room_reconnected', (data: { room_id: string; room_name: string; game_status: 'waiting' | 'playing' | 'ended'; players_list: any[]; game_state: any; }) => {
    toast({
      title: 'Ponowne połączenie z pokojem',
      description: `Ponownie połączono z pokojem: ${data.room_name}`,
    });
    gameState.roomId = data.room_id;
    gameState.roomName = data.room_name;
    gameState.status = data.game_status;
    if (data.players_list && Array.isArray(data.players_list)) {
      gameState.players = data.players_list.map(standardizePlayerObject);
    }
    if (data.game_state) {
      gameState.currentTurn = data.game_state.current_turn;
      gameState.currentPlayerId = data.game_state.players[data.game_state.current_turn];
      gameState.hand = data.game_state.hand ? data.game_state.hand.map(transformCardData) : [];
      gameState.playArea = data.game_state.play_area ? data.game_state.play_area.map(transformCardData) : [];
      gameState.lastCard = data.game_state.last_card ? transformCardData(data.game_state.last_card) : null;
      gameState.deckCount = data.game_state.deck_remaining;
      gameState.isYourTurn = gameState.currentPlayerId === authInfo.id;
    }
    isCurrentPlayerInGame.value = true;
  });

  webSocketService.on('player_status_changed', (data) => {
    console.log('[player_status_changed]', data, gameState.players);
    const playerIndex = gameState.players.findIndex(p => p.user_id === data.player_id);
    if (playerIndex !== -1) {
      gameState.players[playerIndex].status = data.status === 'ready' ? 'ready' : 'not_ready';
      gameState.players[playerIndex].ready = data.status === 'ready';
    }
  });

  webSocketService.on('ready_status_updated', (data: { ready: boolean; status: string; }) => {
    const myId = user.value?.id;
    const playerIndex = gameState.players.findIndex(p => p.user_id === myId);
    if (playerIndex !== -1) {
      gameState.players[playerIndex].status = data.status;
      gameState.players[playerIndex].ready = data.ready;
    }
  });

  webSocketService.on('turn_changed', (data: { current_player_id: number; current_turn: number; }) => {
    gameState.currentTurn = data.current_turn;
    gameState.currentPlayerId = data.current_player_id;
    gameState.isYourTurn = gameState.currentPlayerId === authInfo.id;
  });

  webSocketService.on('game_state_updated', (data: any) => {
    if (data.players_list) {
      gameState.players = data.players_list.map(standardizePlayerObject);
    }
    if (data.game_state) {
      gameState.currentTurn = data.game_state.current_turn;
      gameState.currentPlayerId = data.game_state.currentPlayerId;
      gameState.hand = data.game_state.hand ? data.game_state.hand.map(transformCardData) : [];
      gameState.playArea = data.game_state.play_area ? data.game_state.play_area.map(transformCardData) : [];
      gameState.lastCard = data.game_state.last_card ? transformCardData(data.game_state.last_card) : null;
      gameState.deckCount = data.game_state.deck_remaining;
      gameState.isYourTurn = gameState.currentPlayerId === authInfo.id;
    }
    gameState.status = data.game_status;
  });
});

const toggleReady = () => {
  if (!gameState.roomId) {
    console.error('Not in a room');
    return;
  }
  playerReady.value = !playerReady.value;
};

// Game action handlers
const handlePlayCard = (cardIndex: number) => {
  if (!webSocketService.isConnected()) {
    toast({
      title: 'Błąd połączenia',
      description: 'Nie można zagrać karty - brak połączenia z serwerem.',
      variant: 'destructive',
    });
    return;
  }
  webSocketService.send({
    type: MessageType.PLAY_CARD,
    data: { card_index: cardIndex }
  });
};

const handleDrawCard = () => {
  if (!webSocketService.isConnected()) {
    toast({
      title: 'Błąd połączenia',
      description: 'Nie można dobrać karty - brak połączenia z serwerem.',
      variant: 'destructive',
    });
    return;
  }
  webSocketService.send({
    type: MessageType.DRAW_CARD,
    data: {}
  });
};

const handlePassTurn = () => {
  if (!webSocketService.isConnected()) {
    toast({
      title: 'Błąd połączenia',
      description: 'Nie można spasować - brak połączenia z serwerem.',
      variant: 'destructive',
    });
    return;
  }
  webSocketService.send({
    type: MessageType.PASS_TURN,
    data: {}
  });
};

// Handle joining a game room
const handleJoinRoom = () => {
  if (!webSocketService || !webSocketService.isConnected()) {
    toast({
      title: 'Błąd połączenia',
      description: 'Nie można dołączyć do gry - brak połączenia z serwerem.',
      variant: 'destructive',
    });
    return;
  }

  if (props.game?.id) {
    // Start joining process
    toast({
      title: 'Dołączanie do gry',
      description: 'Trwa dołączanie do pokoju gry...',
    });

    // Use room_id if available in props.game, otherwise use game.id
    const roomIdToJoin = props.game.room_id || props.game.id.toString();
    webSocketService.send({
      type: MessageType.JOIN_ROOM,
      data: { room_id: roomIdToJoin }
    });
  }
};

// Connection refresh method
const refreshConnection = () => {
  const authInfo = getAuthInfo();
  if (!authInfo) {
    toast({
      title: 'Błąd autoryzacji',
      description: 'Nie można nawiązać połączenia - brak zalogowanego użytkownika',
      variant: 'destructive',
    });
    return;
  }
  if (webSocketService) {
    if (!webSocketService.isConnected()) {
      toast({
        title: 'Połączenie',
        description: 'Próba ponownego nawiązania połączenia z serwerem...',
      });
      webSocketService.disconnect();
      setTimeout(() => {
        webSocketService.connect();
      }, 500);
    } else {
      toast({
        title: 'Połączenie aktywne',
        description: 'Połączenie z serwerem jest aktywne.',
      });
    }
  }
};

// Handle player leaving a room
const handleLeaveRoom = () => {
  if (gameState.roomId) {
    webSocketService.send({
      type: MessageType.LEAVE_ROOM,
      data: { room_id: gameState.roomId }
    });
    // Update local state to reflect that the player has left
    isCurrentPlayerInGame.value = false;

    toast({
      title: 'Opuszczono pokój',
      description: 'Opuściłeś pokój gry',
    });
  } else {
      // Jeśli gameState.roomId jest null, ale props.isPlayer jest true (np. po odświeżeniu i przed połączeniem)
      // możemy chcieć wymusić opuszczenie pokoju na serwerze.
      // Potrzebna dodatkowa logika lub pole na serwerze do opuszczenia po user_id zamiast fd.
      console.warn('Attempted to leave room but gameState.roomId is null. User might be in a room according to props.');
       // Można rozważyć wywołanie akcji LeaveRoom z user_id zamiast fd, jeśli serwer to obsługuje.
       // Na razie po prostu logujemy.
  }
};

// Clean up WebSocket connections when component is unmounted
onBeforeUnmount(() => {
  if (gameState.roomId) {
    webSocketService.send({
      type: MessageType.LEAVE_ROOM,
      data: { room_id: gameState.roomId }
    });
  }
  // Remove event listeners to prevent memory leaks
  webSocketService.disconnect();
});

function transformCardData(card: any) {
  const rankMap: { [key: string]: number } = { '9': 9, '10': 10, 'J': 11, 'Q': 12, 'K': 13, 'A': 14 };
  const rankValue = rankMap[card.value] || 0;
  let cardSymbol = '';
  let symbolColorClass = '';
  switch (card.suit) {
    case 'hearts': cardSymbol = '♥'; symbolColorClass = 'text-red-600'; break;
    case 'diamonds': cardSymbol = '♦'; symbolColorClass = 'text-red-600'; break;
    case 'clubs': cardSymbol = '♣'; symbolColorClass = 'text-gray-800 dark:text-gray-200'; break;
    case 'spades': cardSymbol = '♠'; symbolColorClass = 'text-gray-800 dark:text-gray-200'; break;
    default: cardSymbol = '?'; symbolColorClass = '';
  }
  const cardRank = card.value?.toUpperCase?.() || '';
  const header = `${cardRank}${cardSymbol}`;
  return {
    ...card,
    value: card.value,
    header,
    cardRank,
    cardSymbol,
    symbolColorClass,
    rankValue,
  };
}

function getAuthInfo() {
  const page = usePage();
  if (user.value && user.value.id && user.value.name) {
    return {
      id: user.value.id,
      name: user.value.name,
      token: null,
    };
  }
  const pageUser = (page.props && (page.props as any).user) ? (page.props as any).user : null;
  if (pageUser && pageUser.id && pageUser.name) {
    return {
      id: pageUser.id,
      name: pageUser.name,
      ws_token: pageUser.ws_token,
    };
  }
  return null;
}

// Update the connection status check
const connectionStatus = computed(() => {
  if (!webSocketService.isConnected()) {
    return 'disconnected';
  }
  if (!gameState.isAuthenticated) {
    return 'connecting';
  }
  return 'connected';
});
</script>

<template>
  <Head :title="`Gra: ${game.name}`" />

  <div v-if="gameStarted" class="game-container">
    <GameBoard
      :hand="gameState.hand"
      :play-area="gameState.playArea"
      :last-card="gameState.lastCard || undefined"
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
            <div
              class="px-2 py-1 rounded text-xs font-semibold flex items-center gap-1"
              :class="{
                'bg-green-100 text-green-800': connectionStatus === 'connected',
                'bg-yellow-100 text-yellow-800': connectionStatus === 'connecting',
                'bg-red-100 text-red-800': connectionStatus === 'disconnected'
              }"
              @click="refreshConnection"
              title="Kliknij, aby odświeżyć połączenie"
              style="cursor: pointer;"
            >
              <span
                class="w-2 h-2 rounded-full"
                :class="{
                  'bg-green-500': connectionStatus === 'connected',
                  'bg-yellow-500': connectionStatus === 'connecting',
                  'bg-red-500': connectionStatus === 'disconnected'
                }"
              ></span>
              {{ connectionStatus === 'disconnected' ? 'Rozłączono' :
                 connectionStatus === 'connecting' ? 'Łączenie' : 'Połączono' }}
            </div>

            <Link
              :href="route('games.index')"
              class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700 focus:bg-gray-700 active:bg-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150"
            >
              Powrót do listy gier
            </Link>

            <template v-if="isCurrentPlayerInGame">
              <button
                @click="handleLeaveRoom"
                class="inline-flex items-center px-4 py-2 bg-red-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-500 active:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 transition ease-in-out duration-150"
              >
                Opuść grę
              </button>

              <button
                @click="toggleReady"
                :disabled="!gameState.roomId || !isCurrentPlayerInGame"
                :class="{
                  'inline-flex items-center px-4 py-2 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest focus:outline-none focus:ring-2 focus:ring-offset-2 transition ease-in-out duration-150': true,
                  'bg-green-600 hover:bg-green-500 active:bg-green-700 focus:ring-green-500': !playerReady,
                  'bg-yellow-600 hover:bg-yellow-500 active:bg-yellow-700 focus:ring-yellow-500': playerReady,
                  'opacity-50 cursor-not-allowed': !gameState.roomId || !isCurrentPlayerInGame
                }"
              >
                {{ playerReady ? 'Nie jestem gotowy' : 'Jestem gotowy' }}
              </button>

              <button
                v-if="isRoomCreator"
                @click="handleStartGame"
                class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-500 active:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150"
                :disabled="readyPlayersCount < 2"
                :title="readyPlayersCount < 2 ? 'Potrzeba co najmniej 2 gotowych graczy' : 'Rozpocznij grę'"
                :class="{ 'opacity-50 cursor-not-allowed': readyPlayersCount < 2 }"
              >
                Rozpocznij grę ({{ readyPlayersCount }}/{{ gameState.players.length }})
              </button>
            </template>

            <template v-else-if="canJoin && !isCurrentPlayerInGame">
              <button
                @click="handleJoinRoom"
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
              v-for="player in gameState.players"
              :key="player.user_id"
              class="p-4 bg-gray-100 dark:bg-gray-700 rounded-lg"
            >
              <div class="flex items-center space-x-3">
                <div class="flex-shrink-0">
                  <div class="w-10 h-10 rounded-full bg-blue-500 flex items-center justify-center text-white font-bold">
                    {{ getInitials(player.username || 'Gracz') }}
                  </div>
                </div>
                <div>
                  <h4 class="text-sm font-medium text-gray-900 dark:text-gray-100">
                    {{ player.username || 'Gracz' }}
                    <span v-if="user && (player.user_id === user.id || player.id === user.id)" class="text-xs text-gray-500 dark:text-gray-400">(Ty)</span>
                  </h4>
                  <p class="text-xs text-gray-500 dark:text-gray-400">
                    Status: {{ player.status === 'ready' ? 'Gotowy' : 'Nie gotowy' }}
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
