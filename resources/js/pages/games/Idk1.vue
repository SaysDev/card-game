<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount, computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import WebSocketService, { gameState } from '@/Services/WebSocketService';
import MainLayout from '@/layouts/MainLayout.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardFooter, CardHeader } from '@/components/ui/card';
import { useToast } from '@/components/ui/use-toast';

interface Props {
  game: {
    id: number;
    name: string;
    status: 'waiting' | 'playing' | 'ended';
    max_players: number;
    current_players: number;
    created_at: string;
  };
  isPlayer: boolean;
  canJoin: boolean;
  auth: {
    user: {
      id: number;
      name: string;
      email: string;
    };
  };
}

const props = defineProps<Props>();
const { toast } = useToast();

// Refs
const connected = ref(false);
const connecting = ref(false);
const showRules = ref(false);

// Computed
const isYourTurn = computed(() => gameState.isYourTurn);
const statusMessage = computed(() => {
  if (gameState.status === 'waiting') {
    return 'Waiting for players...';
  } else if (gameState.status === 'playing') {
    return isYourTurn.value ? 'Your turn!' : `${getCurrentPlayerName()} is playing...`;
  } else {
    return gameState.winner
      ? `Game over! ${gameState.winner.username} won!`
      : 'Game ended';
  }
});

// Methods
function connectToServer() {
  if (connecting.value) return;
  connecting.value = true;

  // In a real app, you would get a proper auth token
  const token = 'example-auth-token';

  WebSocketService.connect(
    props.auth.user.id,
    props.auth.user.name,
    token
  );

  // Event listeners
  WebSocketService.on('connected', () => {
    connected.value = true;
    connecting.value = false;
    toast({
      title: 'Connected!',
      description: 'Successfully connected to the game server.',
    });
  });

  WebSocketService.on('authenticated', () => {
    if (props.isPlayer) {
      joinExistingGame();
    }
  });

  WebSocketService.on('error', (error) => {
    connecting.value = false;
    toast({
      title: 'Connection Error',
      description: 'Failed to connect to the game server.',
      variant: 'destructive',
    });
  });

  WebSocketService.on('disconnected', () => {
    connected.value = false;
    toast({
      title: 'Disconnected',
      description: 'Connection to the game server was lost.',
      variant: 'destructive',
    });
  });

  WebSocketService.on('room_created', () => {
    toast({
      title: 'Room Created',
      description: `You've created room "${gameState.roomName}"`,
    });
  });

  WebSocketService.on('room_joined', () => {
    toast({
      title: 'Room Joined',
      description: `You've joined room "${gameState.roomName}"`,
    });
  });

  WebSocketService.on('game_started', () => {
    toast({
      title: 'Game Started!',
      description: 'The game has begun. Good luck!',
    });
  });

  WebSocketService.on('turn_changed', (data) => {
    if (data.current_player_id === props.auth.user.id) {
      toast({
        title: 'Your Turn',
        description: 'It\'s your turn to play!',
      });
    }
  });

  WebSocketService.on('game_over', () => {
    toast({
      title: 'Game Over',
      description: gameState.winner ? `${gameState.winner.username} has won the game!` : 'The game has ended.',
    });
  });

  WebSocketService.on('server_error', (error) => {
    toast({
      title: 'Server Error',
      description: error.message,
      variant: 'destructive',
    });
  });
}

function joinExistingGame() {
  WebSocketService.joinRoom(props.game.id.toString());
}

function createNewRoom() {
  if (!connected.value) return;

  WebSocketService.createRoom(
    props.game.name,
    props.game.max_players
  );
}

function playCard(cardIndex: number) {
  if (!isYourTurn.value) return;
  WebSocketService.playCard(cardIndex);
}

function drawCard() {
  if (!isYourTurn.value) return;
  WebSocketService.drawCard();
}

function passTurn() {
  if (!isYourTurn.value) return;
  WebSocketService.passTurn();
}

function leaveGame() {
  WebSocketService.leaveRoom();
  window.location.href = '/games';
}

function getCurrentPlayerName(): string {
  const currentPlayer = gameState.players.find(p => p.user_id === gameState.currentPlayerId);
  return currentPlayer?.username || 'Unknown player';
}

function getCardColor(card) {
  return card.suit === 'hearts' || card.suit === 'diamonds' ? 'text-red-600' : 'text-gray-900';
}

function getCardSymbol(card) {
  const symbols = {
    'hearts': '♥',
    'diamonds': '♦',
    'clubs': '♣',
    'spades': '♠'
  };
  return symbols[card.suit] || '';
}

// Lifecycle hooks
onMounted(() => {
  connectToServer();
});

onBeforeUnmount(() => {
  if (connected.value) {
    WebSocketService.disconnect();
  }
});
</script>

<template>
  <Head :title="`Game: ${game.name}`" />

  <MainLayout>
    <div class="container mx-auto py-6">
      <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold">{{ game.name }}</h1>

        <div class="space-x-2">
          <Button @click="showRules = !showRules" variant="outline">
            {{ showRules ? 'Hide Rules' : 'Game Rules' }}
          </Button>

          <Button @click="leaveGame" variant="destructive">
            Leave Game
          </Button>
        </div>
      </div>

      <div v-if="showRules" class="mb-6 p-4 bg-gray-100 dark:bg-gray-800 rounded-lg">
        <h2 class="text-xl font-semibold mb-2">Card Game Rules</h2>
        <ul class="list-disc pl-5 space-y-1">
          <li>Each player is dealt 7 cards at the start</li>
          <li>Players take turns playing a card or drawing from the deck</li>
          <li>You can play a card of the same suit or same value as the last card</li>
          <li>If you can't play, you must draw a card</li>
          <li>First player to get rid of all their cards wins</li>
        </ul>
      </div>

      <!-- Connection Status -->
      <div v-if="!connected" class="mb-6">
        <Card>
          <CardHeader class="pb-2">
            <h2 class="text-xl font-semibold">Game Server</h2>
          </CardHeader>
          <CardContent>
            <p class="mb-4">Connect to the game server to start playing.</p>
            <Button @click="connectToServer" :disabled="connecting">
              {{ connecting ? 'Connecting...' : 'Connect' }}
            </Button>
          </CardContent>
        </Card>
      </div>

      <!-- Game Board -->
      <div v-else class="grid grid-cols-1 gap-6">
        <!-- Game Status -->
        <Card>
          <CardHeader class="pb-2">
            <h2 class="text-xl font-semibold">Game Status</h2>
          </CardHeader>
          <CardContent>
            <div class="flex items-center">
              <div class="w-3 h-3 rounded-full mr-2"
                   :class="gameState.status === 'playing' ? 'bg-green-500' : (gameState.status === 'waiting' ? 'bg-yellow-500' : 'bg-red-500')"></div>
              <span>{{ statusMessage }}</span>
            </div>
          </CardContent>
        </Card>

        <!-- Players -->
        <Card>
          <CardHeader class="pb-2">
            <h2 class="text-xl font-semibold">Players</h2>
          </CardHeader>
          <CardContent>
            <div v-if="gameState.players.length === 0" class="text-gray-500">
              No players have joined yet.
            </div>
            <div v-else class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
              <div v-for="player in gameState.players" :key="player.user_id"
                   class="p-3 rounded-lg"
                   :class="player.user_id === gameState.currentPlayerId ? 'bg-blue-100 dark:bg-blue-900' : 'bg-gray-100 dark:bg-gray-800'">
                <div class="font-medium">{{ player.username }}</div>
                <div class="text-sm text-gray-500 dark:text-gray-400">
                  Cards: {{ player.cards_count || 0 }}
                </div>
                <div v-if="player.user_id === gameState.currentPlayerId"
                     class="text-xs font-semibold text-blue-600 dark:text-blue-400 mt-1">
                  Current Turn
                </div>
              </div>
            </div>
          </CardContent>
        </Card>

        <!-- Play Area -->
        <Card v-if="gameState.status === 'playing'">
          <CardHeader class="pb-2">
            <h2 class="text-xl font-semibold">Play Area</h2>
          </CardHeader>
          <CardContent>
            <div class="flex items-center gap-4">
              <div class="relative w-20 h-28 bg-gray-200 dark:bg-gray-700 rounded-lg flex items-center justify-center text-center">
                <span class="text-sm">Deck<br/>{{ gameState.deckCount }} cards</span>
                <Button v-if="isYourTurn" @click="drawCard"
                        class="absolute bottom-1 left-1 right-1 py-1 text-xs h-auto" size="sm">
                  Draw
                </Button>
              </div>

              <div v-if="gameState.lastCard"
                   class="w-20 h-28 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-lg flex flex-col items-center justify-center">
                <div :class="getCardColor(gameState.lastCard)" class="text-2xl font-bold">{{ gameState.lastCard.value }}</div>
                <div :class="getCardColor(gameState.lastCard)" class="text-2xl">{{ getCardSymbol(gameState.lastCard) }}</div>
              </div>
              <div v-else class="w-20 h-28 bg-gray-100 dark:bg-gray-800 border border-dashed border-gray-300 dark:border-gray-600 rounded-lg flex items-center justify-center">
                <span class="text-gray-400 text-sm">No card played</span>
              </div>

              <Button v-if="isYourTurn" @click="passTurn" variant="outline" size="sm">
                Pass Turn
              </Button>
            </div>
          </CardContent>
        </Card>

        <!-- Your Hand -->
        <Card v-if="gameState.status === 'playing'">
          <CardHeader class="pb-2">
            <h2 class="text-xl font-semibold">Your Hand</h2>
          </CardHeader>
          <CardContent>
            <div v-if="gameState.hand.length === 0" class="text-gray-500">
              No cards in your hand.
            </div>
            <div v-else class="flex flex-wrap gap-2">
              <div v-for="(card, index) in gameState.hand" :key="index"
                   class="w-20 h-28 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-lg flex flex-col items-center justify-center cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors"
                   @click="playCard(index)">
                <div :class="getCardColor(card)" class="text-2xl font-bold">{{ card.value }}</div>
                <div :class="getCardColor(card)" class="text-2xl">{{ getCardSymbol(card) }}</div>
              </div>
            </div>
          </CardContent>
          <CardFooter v-if="isYourTurn" class="text-sm text-blue-600 dark:text-blue-400">
            It's your turn! Click a card to play it.
          </CardFooter>
        </Card>

        <!-- Game Over -->
        <Card v-if="gameState.status === 'ended' && gameState.winner">
          <CardHeader>
            <h2 class="text-xl font-semibold text-center">Game Over</h2>
          </CardHeader>
          <CardContent>
            <div class="text-center py-4">
              <h3 class="text-2xl font-bold mb-2">{{ gameState.winner.username }} Won!</h3>
              <p class="text-gray-600 dark:text-gray-400">The game has ended. You can join another game or create a new one.</p>
            </div>
          </CardContent>
          <CardFooter class="flex justify-center gap-4">
            <Button @click="leaveGame" variant="default">Back to Lobby</Button>
          </CardFooter>
        </Card>
      </div>
    </div>
  </MainLayout>
</template>
