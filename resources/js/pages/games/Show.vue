<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ref, onMounted, computed, onBeforeUnmount } from 'vue';
import GameBoard from '@/components/game/GameBoard.vue';
import { useWebSocket } from '@/composables/useWebSocket';
import { useToast } from '@/components/ui/toast';
import { useAuth } from '@/composables/useAuth';
import { useInitials } from '@/composables/useInitials';
import WebSocketService, { gameState } from '@/Services/WebSocketService';
import { GameRoomService } from '@/Services/GameRoomService';

// Define the CardData interface to match GameBoard.vue expectations
interface CardData {
  value: string;
  header: string;
  cardRank: string;
  cardSymbol: string;
  symbolColorClass: string;
  rankValue: number;
}

// Function to transform basic card data into CardData interface
const transformCardData = (card: { suit: string; value: string }): CardData => {
  // Map the string value to a rank value
  const rankMap: { [key: string]: number } = {
      '9': 9,
      '10': 10,
      'J': 11,
      'Q': 12,
      'K': 13,
      'A': 14
  };
  const rankValue = rankMap[card.value] || 0;

  // Determine card symbol and color class based on suit
  let cardSymbol = '';
  let symbolColorClass = '';
  switch (card.suit) {
      case 'hearts':
          cardSymbol = '♥';
          symbolColorClass = 'text-red-600'; // Adjust class as needed
          break;
      case 'diamonds':
          cardSymbol = '♦';
          symbolColorClass = 'text-red-600'; // Adjust class as needed
          break;
      case 'clubs':
          cardSymbol = '♣';
          symbolColorClass = 'text-gray-800 dark:text-gray-200'; // Adjust class as needed
          break;
      case 'spades':
          cardSymbol = '♠';
          symbolColorClass = 'text-gray-800 dark:text-gray-200'; // Adjust class as needed
          break;
      default:
          // Handle unknown suit
          cardSymbol = '?';
          symbolColorClass = '';
  }

  // Determine card rank (same as value for 9, 10, J, Q, K, A)
  const cardRank = card.value.toUpperCase();

  // Determine header (usually rank and symbol)
  const header = `${cardRank}${cardSymbol}`;

  return {
      value: card.value,
      header: header,
      cardRank: cardRank,
      cardSymbol: cardSymbol,
      symbolColorClass: symbolColorClass,
      rankValue: rankValue,
  };
};

// Get Inertia shared data
const page = usePage();
const inertiaUser = computed(() => page.props.auth?.user);

// Track if current player is in game (reactive to WebSocket events)
const isCurrentPlayerInGame = ref(false);

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
// Initialize based on the prop value from the server
isCurrentPlayerInGame.value = props.isPlayer;
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

// Check if current user is the room creator
const isRoomCreator = computed(() => {
  return gameState.isRoomCreator;
});

// Count ready players for the Start Game button
const readyPlayersCount = computed(() => {
  if (!gameState.players || !gameState.players.length) return 0;
  return gameState.players.filter(player => player.status === 'ready').length;
});

onMounted(() => {
  // Check if this game is the user's active game
  const inThisGame = props.isPlayer;

  // Get the authenticated user ID from Inertia shared data
  let authenticatedUserId = usePage().props?.user?.id;


  try {
    // Use the computed inertiaUser instead of directly accessing page.props
    if (inertiaUser.value) {
      authenticatedUserId = inertiaUser.value.id;
      console.log('Using authenticated user ID from Inertia shared data:', authenticatedUserId);
    }
    // Fall back to user from composable if needed
    else if (user.value) {
      authenticatedUserId = user.value.id;
      console.log('Using authenticated user ID from composable:', authenticatedUserId);
    }

    if (!authenticatedUserId) {
      console.warn('No authenticated user ID found from any source');
    }
  } catch (error) {
    console.warn('Could not access authenticated user data:', error);
  }

  // Connect to WebSocket server using the WebSocketService singleton
  if (authenticatedUserId) {
    // First register event handlers
    WebSocketService.on('connected', () => {
      console.log('WebSocket connected, authenticated next');
    });

    WebSocketService.on('authenticated', () => {
      console.log('Successfully authenticated');

      // Po uwierzytelnieniu:
      // Jeśli użytkownik NIE jest graczem (props.isPlayer false) i strona ma ID gry, próbujemy dołączyć.
      // Jeśli użytkownik JEST graczem (props.isPlayer true), zakładamy, że jest już w pokoju
      // i synchronizujemy gameState z props.game (dane z serwera/Inertii).
      if (!props.isPlayer && props.game?.id) { // Jeśli NIE jest graczem i jest ID gry
        console.log('User is not currently a player in this game. They need to join.');
        handleJoinRoom(); // Wywołaj funkcję dołączania dla nowego gracza
      } else if (props.isPlayer && props.game?.id) { // Jeśli JEST graczem i jest ID gry (scenariusz odświeżenia)
        console.log('User is already a player according to server state. Synchronizing gameState...');
        // Synchronizuj gameState z danymi z props.game przekazanymi przez Inertię
        gameState.roomId = props.game.room_id?.toString() || props.game.id.toString();
        gameState.roomName = props.game.name;
        gameState.status = props.game.status as 'waiting' | 'playing' | 'ended';

        // Mapowanie danych graczy z props.game do formatugameState.players
        if (props.game.players && Array.isArray(props.game.players)) {
          gameState.players = props.game.players.map(player => ({
            user_id: player.user?.id || player.user_id, // Użyj user.id lub user_id
            username: player.user?.name || player.name, // Użyj user.name lub name
            status: player.status || 'waiting', // Ensure status is always a string
            ready: player.status === 'ready',
            score: player.score || 0, // Ensure score is always a number
            cards_count: player.cards ? player.cards.length : 0
          }));
        }

        // Ustaw flagę, że bieżący gracz jest w grze
        isCurrentPlayerInGame.value = true;

        // Możesz tutaj też zsynchronizować inne początkowe dane gry z props.game.game_data
        // gameState.currentTurn = props.game.game_data.current_turn;
        // gameState.currentPlayerId = props.game.game_data.players[props.game.game_data.current_turn]; // Przykład
        // ... inne dane z game_data ...

      } else {
         console.log('User is not a player and game ID is missing.');
      }
    });

    WebSocketService.on('room_joined', (data: { room_id: string; room_name: string; players: any[] }) => {
      toast({
        title: 'Dołączono do pokoju',
        description: `Dołączono do pokoju: ${data.room_name}`,
      });

      // Update local state to reflect that the player has joined
      isCurrentPlayerInGame.value = true;

      // Log players data for debugging
      console.log('Room joined, received players data:', data.players);
      console.log('Current gameState.players:', gameState.players);

       // Update gameState players list with full player objects
       // Assuming data.players in room_joined are full player objects
       if (data.players && Array.isArray(data.players)) {
         gameState.players = data.players.map(p => ({
            user_id: p.user_id,
            username: p.username,
            status: p.status || 'waiting', // Ensure status is always a string
            ready: p.ready || false, // Ensure ready is always a boolean
            score: p.score || 0, // Ensure score is always a number
            cards_count: p.cards_count || 0 // Ensure cards_count is always a number
         }));
       }

      // Check for duplicate user IDs in players array (logic moved/simplified if needed)
      // ... existing code ...
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

    WebSocketService.on('room_reconnected', (data: { room_id: string; room_name: string; game_status: 'waiting' | 'playing' | 'ended'; players_list: any[]; game_state: any; }) => {
      toast({
        title: 'Ponowne połączenie z pokojem',
        description: `Ponownie połączono z pokojem: ${data.room_name}`,
      });

      // Update gameState with synchronized data from the server
      gameState.roomId = data.room_id;
      gameState.roomName = data.room_name;
      gameState.status = data.game_status;

      // Update gameState players list using players_list
      if (data.players_list && Array.isArray(data.players_list)) {
        gameState.players = data.players_list.map(player => ({
          user_id: player.user_id,
          username: player.username,
          status: player.status || 'waiting', // Ensure status is always a string
          ready: player.ready || false, // Ensure ready is always a boolean
          score: player.score || 0, // Ensure score is always a number
          cards_count: player.cards_count || 0 // Ensure cards_count is always a number
        }));
      }

      // Update other game state properties from game_state
      if (data.game_state) {
         gameState.currentTurn = data.game_state.current_turn;
         // Zakładam, że currentPlayerId jest user_id gracza
         gameState.currentPlayerId = data.game_state.players[data.game_state.current_turn]; // Użyj players z game_state
         // Use transformCardData for hand, playArea, and lastCard
         gameState.hand = data.game_state.hand ? data.game_state.hand.map(transformCardData) : [];
         gameState.playArea = data.game_state.play_area ? data.game_state.play_area.map(transformCardData) : [];
         gameState.lastCard = data.game_state.last_card ? transformCardData(data.game_state.last_card) : null;
         gameState.deckCount = data.game_state.deck_remaining; // Update deck count
         // Aktualizacja isYourTurn na podstawie currentPlayerId
         gameState.isYourTurn = gameState.currentPlayerId === (inertiaUser.value?.id || WebSocketService.userId);
      }

      isCurrentPlayerInGame.value = true; // User is now confirmed to be in the game
    });

    // Dodaj handler player_status_changed, jeśli go brakuje
    WebSocketService.on('player_status_changed', (data: { user_id: number; status: 'waiting' | 'ready' | 'playing' | 'disconnected'; ready: boolean; }) => {
        console.log(`Player status changed: User ${data.user_id} is now ${data.status} (Ready: ${data.ready})`);
        // Find the player in gameState.players and update their status and ready state
        const playerIndex = gameState.players.findIndex(p => p.user_id === data.user_id);
        if (playerIndex !== -1) {
            gameState.players[playerIndex].status = data.status;
            gameState.players[playerIndex].ready = data.ready;
        }
    });

    // Dodaj handler turn_changed
    WebSocketService.on('turn_changed', (data: { current_player_id: number; current_turn: number; }) => {
        console.log(`Turn changed: current_player_id = ${data.current_player_id}, current_turn = ${data.current_turn}`);
        gameState.currentTurn = data.current_turn; // Zaktualizuj indeks tury
        gameState.currentPlayerId = data.current_player_id; // Zaktualizuj ID gracza, którego jest tura
        // Zaktualizuj isYourTurn na podstawie nowego currentPlayerId
        gameState.isYourTurn = gameState.currentPlayerId === (inertiaUser.value?.id || WebSocketService.userId);
    });

    // Dodaj handler game_state_updated (ogólna aktualizacja stanu gry)
    WebSocketService.on('game_state_updated', (data: any) => {
        console.log('Received game_state_updated:', data);
        // Log the hand data specifically to inspect its structure
        console.log('Received hand data:', data.game_state?.hand);
        // Aktualizuj odpowiednie pola gameState na podstawie danych z serwera
        if (data.players_list) {
             gameState.players = data.players_list.map((p: any) => ({
                user_id: p.user_id,
                username: p.username,
                status: p.status || 'waiting', // Ensure status is always a string
                ready: p.ready || false, // Ensure ready is always a boolean
                score: p.score || 0, // Ensure score is always a number
                cards_count: p.cards_count || 0 // Ensure cards_count is always a number
             }));
        }
        if (data.game_state) {
            gameState.currentTurn = data.game_state.current_turn;
            gameState.currentPlayerId = data.game_state.currentPlayerId; // Assuming this is included
            gameState.hand = data.game_state.hand ? data.game_state.hand.map(transformCardData) : [];
            gameState.playArea = data.game_state.play_area ? data.game_state.play_area.map(transformCardData) : [];
            gameState.lastCard = data.game_state.last_card ? transformCardData(data.game_state.last_card) : null;
            gameState.deckCount = data.game_state.deck_remaining; // Update deck count
            // Aktualizacja isYourTurn na podstawie currentPlayerId
            gameState.isYourTurn = gameState.currentPlayerId === (inertiaUser.value?.id || WebSocketService.userId);
        }
         // Dodaj aktualizację innych pól stanu gry, które mogą być wysyłane
         gameState.status = data.game_status; // Aktualizuj status gry
         // ... inne pola ...
    });
  } else {
    // Handle case where no authenticated user is available
    console.error('No authenticated user available to connect to WebSocket');
    toast({
      title: 'Błąd autoryzacji',
      description: 'Nie można nawiązać połączenia - brak zalogowanego użytkownika',
      variant: 'destructive',
    });
  }

  // Use inertiaUser from usePage() directly
  const userName = inertiaUser.value ? inertiaUser.value.name : 'Guest';

  // Wywołaj connect bez jawnego podawania tokenu - zostanie on pobrany z page.props.user
  if (inertiaUser.value?.id) {
    console.log('Connecting to WebSocket with user ID from Inertia:', inertiaUser.value.id);
    // Nie przekazujemy tokenu, będzie pobrany z page.props.user.ws_token
    WebSocketService.connect(String(inertiaUser.value.id), userName);
  } else if (authenticatedUserId) {
    console.log('Connecting to WebSocket with fallback user ID:', authenticatedUserId);
    WebSocketService.connect(String(authenticatedUserId), userName);
  } else {
    console.error('Cannot connect to WebSocket: No valid user ID available');
    toast({
      title: 'Błąd połączenia',
      description: 'Nie można połączyć się z serwerem - brak identyfikatora użytkownika',
      variant: 'destructive',
    });
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

// Handle starting the game (only room creator can do this)
const handleStartGame = () => {
  if (!WebSocketService || !WebSocketService.isConnectedAndReady()) {
    toast({
      title: 'Błąd połączenia',
      description: 'Nie można rozpocząć gry - brak połączenia z serwerem.',
      variant: 'destructive',
    });
    return;
  }

  // Check if we're the room creator
  if (!gameState.isRoomCreator) {
    toast({
      title: 'Błąd',
      description: 'Tylko twórca pokoju może rozpocząć grę.',
      variant: 'destructive',
    });
    return;
  }

  // Check if there are enough players who are ready
  const readyPlayers = gameState.players.filter(p => p.status === 'ready').length;
  if (readyPlayers < 2) {
    toast({
      title: 'Za mało graczy',
      description: 'Do rozpoczęcia gry potrzeba co najmniej 2 gotowych graczy.',
      variant: 'destructive',
    });
    return;
  }

  // Start the game
  WebSocketService.startGame();

  toast({
    title: 'Rozpoczynanie gry',
    description: 'Rozpoczynanie gry w toku...',
  });
};

// Handle joining a game room
const handleJoinRoom = () => {
  if (!WebSocketService || !WebSocketService.isConnectedAndReady()) {
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
    WebSocketService.joinRoomWithUserInfo(String(roomIdToJoin)); // Użyj metody z user info
  }
};

// Connection refresh method
const refreshConnection = () => {
  // Get user ID from our computed inertiaUser or useAuth
  const userId = inertiaUser.value?.id || user.value?.id;

  if (!userId) {
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
        const userName = inertiaUser.value?.name || user.value?.name || 'User';
        // Nie przekazujemy tokenu, zostanie pobrany z page.props.user.ws_token lub localStorage
        WebSocketService.connect(String(userId), userName);
      }, 500);
    } else {
      toast({
        title: 'Połączenie',
        description: 'Połączenie z serwerem aktywne',
      });
    }
  }
};

// Handle player leaving a room
const handleLeaveRoom = () => {
  if (gameState.roomId) {
    WebSocketService.leaveRoom();
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

                          <template v-if="isCurrentPlayerInGame">
              <button
                @click="handleLeaveRoom"
                class="inline-flex items-center px-4 py-2 bg-red-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-500 active:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 transition ease-in-out duration-150"
              >
                Opuść grę
              </button>

              <!-- Ready status button for all players -->
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

              <!-- Start game button only for room creator -->
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
                    <span v-if="user && player.user_id === user.id" class="text-xs text-gray-500 dark:text-gray-400">(Ty)</span>
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
