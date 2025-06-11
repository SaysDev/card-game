<script setup lang="ts">
import { ref, reactive, computed, onMounted, watch } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { webSocketService } from '@/Services/WebSocketService';
import { useAuth } from '@/composables/useAuth';
import { MessageType } from "@/types/messageTypes";
import { useToast } from '@/components/ui/toast';
import CardComponent from '@/components/game/MobileCard.vue';
import PlayerHand from '@/components/game/MobilePlayerHand.vue';
import DiscardPile from '@/components/game/MobileDiscardPile.vue';
import PlayerPod from '@/components/game/MobilePlayerPod.vue';
import { Dialog, DialogContent, DialogTitle, DialogClose } from '@/components/ui/dialog';
import { router, usePage } from '@inertiajs/vue3';

const { toast } = useToast();
const { user, isLoggedIn } = useAuth();

const messages = ref([
  {
    sender: 'Admin',
    content: 'Zapraszamy do nowego sezonu rozgrywek! Sprawdź nowe nagrody w zakładce Rewards.',
    date: 'wczoraj, 15:20',
    isSystem: true,
    hasReward: false,
    rewardClaimed: false,
    expanded: false
  },
  {
    sender: 'Admin',
    content: "Witaj w grze! Twoja skrzynka odbiorcza jest gotowa na wiadomości od innych graczy.",
    date: 'dzisiaj, 10:45',
    isSystem: true,
    hasReward: false,
    rewardClaimed: false,
    expanded: false
  }
]);

const currentRoute = computed(() => usePage().url);

interface Card {
  rank: string;
  suit: string;
  isValid?: boolean;
}

interface Player {
  id: number;
  name: string;
  avatar: string;
  hand: Card[];
  isHuman: boolean;
}

interface GameState {
  players: Player[];
  currentPlayerIndex: number;
  discardPile: Card[];
  deck: Card[];
  roomId: string | null;
  connected: boolean;
}

// Default empty player object
const emptyPlayer: Player = {
  id: 0,
  name: 'Unknown',
  avatar: '🧑‍💻',
  hand: [],
  isHuman: false
};

// Game state
const screen = ref('menu');
const activeTab = ref('home');
const loadingProgress = ref(0);
const loadingStatusText = ref('Inicjalizacja...');
const gameState = reactive<GameState>({
  players: [],
  currentPlayerIndex: 0,
  discardPile: [],
  deck: [],
  roomId: null,
  connected: false
});

// Game flow
const gameOverMessage = ref('');
const players = computed(() => gameState.players);
const humanPlayer = computed(() => gameState.players.find((p: Player) => p.isHuman) || emptyPlayer);
const currentPlayer = computed(() => gameState.players[gameState.currentPlayerIndex] || emptyPlayer);
const isHumanTurn = computed(() => currentPlayer.value.isHuman);

// Card validation functions
const isMoveValid = (card: Card) => {
  const topCard = gameState.discardPile[gameState.discardPile.length - 1];
  if (!topCard) return true;
  return card.rank === topCard.rank || card.suit === topCard.suit;
};

const hasValidMove = computed(() => {
  if (!isHumanTurn.value) return false;
  return humanPlayer.value.hand.some(isMoveValid);
});

// Game flow control
const changeScreen = (newScreen: string) => {
  screen.value = newScreen;
};

const startGameFlow = () => {
  if (gameState.connected) {
    router.visit('/game/matchmaking');
  }
};

const setupNewGame = () => {
  const createdPlayers = [
    { id: 0, name: 'Ty', avatar: '🧑‍💻', hand: [], isHuman: true },
    { id: 1, name: 'Mark K.', avatar: '🧑‍🚀', hand: [], isHuman: false },
    { id: 2, name: 'Kevin J.', avatar: '🦹‍♀️', hand: [], isHuman: false },
    { id: 3, name: 'Helen J.', avatar: '🤖', hand: [], isHuman: false }
  ];
  const suits = ['♥', '♦', '♣', '♠'];
  const ranks = ['2', '3', '4', '5', '6', '7', '8', '9', '10', 'J', 'Q', 'K', 'A'];
  let deck: Card[] = [];
  for (const suit of suits) {
    for (const rank of ranks) {
      deck.push({ rank, suit });
    }
  }
  for (let i = deck.length - 1; i > 0; i--) {
    const j = Math.floor(Math.random() * (i + 1));
    [deck[i], deck[j]] = [deck[j], deck[i]];
  }
  for (let i = 0; i < 5; i++) {
    for (let p of createdPlayers) {
      p.hand.push(deck.pop()!);
    }
  }
  gameState.players = createdPlayers;
  gameState.currentPlayerIndex = 0;
  gameState.discardPile = [deck.pop()!];
  gameState.deck = deck;
  updateValidCards();
};

const advanceTurn = () => {
  gameState.currentPlayerIndex = (gameState.currentPlayerIndex + 1) % gameState.players.length;
  updateValidCards();
  if (!isHumanTurn.value) {
    setTimeout(playBotTurn, 1500);
  }
};

const updateValidCards = () => {
  if (humanPlayer.value && humanPlayer.value.hand) {
    humanPlayer.value.hand.forEach((card: Card) => {
      card.isValid = isMoveValid(card);
    });
  }
};

const playCard = (player: any, cardData: Card) => {
  const cardIndex = player.hand.findIndex((c: Card) => c.rank === cardData.rank && c.suit === cardData.suit);
  if (cardIndex > -1) {
    const [playedCard] = player.hand.splice(cardIndex, 1);
    gameState.discardPile.push(playedCard);
    if (player.hand.length === 0) {
      return endGame(player);
    }
    advanceTurn();
  }
};

const handleCardPlay = (card: Card) => {
  if (!isHumanTurn.value || !isMoveValid(card)) return;
  if (gameState.connected) {
    const cardIndex = humanPlayer.value.hand.findIndex((c: Card) => c.rank === card.rank && c.suit === card.suit);
    webSocketService.send({
      type: MessageType.GAME_ACTION,
      action_type: 'play_card',
      card_index: cardIndex
    });
  } else {
    playCard(humanPlayer.value, card);
  }
};

const handlePass = (player: any) => {
  if (gameState.deck.length > 0) {
    player.hand.push(gameState.deck.pop()!);
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
  if (gameState.connected) {
    webSocketService.send({
      type: MessageType.GAME_ACTION,
      action_type: 'pass'
    });
  } else {
    handlePass(humanPlayer.value);
    updateValidCards();
    advanceTurn();
  }
};

const playBotTurn = () => {
  const bot = currentPlayer.value;
  const validCard = bot.hand.find(isMoveValid);
  if (validCard) {
    playCard(bot, validCard);
  } else {
    handlePass(bot);
    advanceTurn();
  }
};

const endGame = (winner: any) => {
  gameOverMessage.value = winner.isHuman ? "Wygrałeś!" : `${winner.name} wygrał!`;
  changeScreen('game-over');
};

const setupWebSocketHandlers = () => {
  webSocketService.on('auth_success', (data) => {
    gameState.connected = true;
    toast({
      title: "Connected",
      description: "Successfully connected to game server",
    });
  });

  webSocketService.on('auth_error', (data) => {
    toast({
      title: "Connection Error",
      description: data.message || "Failed to connect to game server",
      variant: "destructive"
    });
  });

  webSocketService.on('matchmaking_error', (data) => {
    toast({
      title: "Matchmaking Error",
      description: data.message || "Failed to join matchmaking",
      variant: "destructive"
    });
  });

  webSocketService.on('room_created', (data) => {
    gameState.roomId = data.room_id;
  });

  webSocketService.on('game_state_update', (data: any) => {
    // Standardize player data before updating game state
    if (data.players && Array.isArray(data.players)) {
      data.players = data.players.map((player: any) => ({
        id: player.user_id ?? player.id ?? 0,
        name: player.username ?? player.name ?? 'Gracz',
        avatar: player.avatar ?? '🧑‍💻',
        hand: player.cards ?? [],
        isHuman: player.user_id === user.value?.id
      }));
    }
    Object.assign(gameState, data);
  });

  webSocketService.on('game_over', (data) => {
    router.visit('/game/over', {
      data: {
        winner: data.winner,
        players: data.players,
        duration: data.duration,
        points: data.points
      }
    });
  });
};

const showMail = ref(false);
const showSettings = ref(false);
const showOffers = ref(false);
const showRewards = ref(false);
const showEvents = ref(false);
const showChat = ref(false);

onMounted(async () => {
  await webSocketService.connect();
  setupWebSocketHandlers();
});
</script>

<template>
  <div>
    <transition>
      <div v-if="screen === 'loader'"
        class="game-screen z-50 flex flex-col items-center justify-center bg-dynamic-screen text-white p-8">
        <div class="flex-grow flex items-center justify-center">
          <h1 class="font-bangers text-6xl text-white" style="text-shadow: 0 0 15px #ff512f, 0 0 25px #dd2476;">Card
            Clash</h1>
        </div>
        <div class="w-full max-w-xs mt-auto">
          <div class="w-full bg-black/30 rounded-full h-2.5 border border-white/10">
            <div class="progress-bar bg-gradient-to-r from-pink-500 to-orange-500 h-full rounded-full"
              :style="{ width: loadingProgress + '%' }"></div>
          </div>
          <p class="text-center text-sm text-gray-300 mt-2 h-5">{{ loadingStatusText }}</p>
        </div>
      </div>
    </transition>
    <transition>
      <div v-if="screen === 'menu'" class="game-screen z-40 flex flex-col bg-dynamic-screen text-white">
        <div class="p-2 flex flex-col flex-grow h-full relative">
          <!-- Top Bar: Player Info + Resources -->
          <div class="flex justify-between items-center glass-bar p-2 rounded-xl mt-2">
            <div class="flex items-center gap-2">
              <div
                class="w-12 h-12 rounded-full bg-purple-900 border-2 border-purple-500 flex items-center justify-center text-3xl flex-shrink-0">
                🧑‍💻
              </div>
              <div>
                <h2 class="font-bold text-md leading-tight">{{ user?.name || 'Gracz123' }}</h2>
                <div class="w-full bg-gray-900/50 rounded-full h-1.5 mt-1">
                  <div class="bg-yellow-400 h-1.5 rounded-full" style="width: 45%"></div>
                </div>
              </div>
            </div>
            <div class="flex items-center gap-1 flex-shrink-0">
              <div class="resource-item flex items-center p-1 pr-2 rounded-full">
                <!-- Moneta SVG -->
                <svg class="w-6 h-6" viewBox="0 0 24 24" fill="none">
                  <circle cx="12" cy="12" r="10" fill="#FFD700" stroke="#E6B800" stroke-width="2" />
                  <text x="12" y="16" text-anchor="middle" font-size="12" fill="#fff" font-weight="bold">M</text>
                </svg>
                <span class="font-bold text-sm ml-1">999 999 999</span>
              </div>
              <div class="resource-item flex items-center p-1 pr-2 rounded-full">
                <!-- Diament SVG -->
                <svg class="w-6 h-6" viewBox="0 0 24 24" fill="none">
                  <polygon points="12,2 22,9 12,22 2,9" fill="#00BFFF" stroke="#0099CC" stroke-width="2" />
                  <polygon points="12,2 17,9 12,22 7,9" fill="#B3E6FF" stroke="#0099CC" stroke-width="1" />
                </svg>
                <span class="font-bold text-sm ml-1">999 999</span>
              </div>
            </div>
          </div>
          <!-- Left Floating Buttons -->
          <div v-if="currentRoute === '/menu'" class="absolute left-2 top-30 flex flex-col gap-3 z-20">
            <button class="floating-btn" @click="showMail = true">
              <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z">
                </path>
              </svg>
              <div class="notification-dot"></div>
            </button>
            <button class="floating-btn" @click="showRewards = true">
              <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
              </svg>
            </button>
            <button class="floating-btn" @click="showChat = true">
              <svg class="w-8 h-8" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path
                  d="M7 9H17M7 13H17M21 20L17.6757 18.3378C17.4237 18.2118 17.2977 18.1488 17.1656 18.1044C17.0484 18.065 16.9277 18.0365 16.8052 18.0193C16.6672 18 16.5263 18 16.2446 18H6.2C5.07989 18 4.51984 18 4.09202 17.782C3.71569 17.5903 3.40973 17.2843 3.21799 16.908C3 16.4802 3 15.9201 3 14.8V7.2C3 6.07989 3 5.51984 3.21799 5.09202C3.40973 4.71569 3.71569 4.40973 4.09202 4.21799C4.51984 4 5.0799 4 6.2 4H17.8C18.9201 4 19.4802 4 19.908 4.21799C20.2843 4.40973 20.5903 4.71569 20.782 5.09202C21 5.51984 21 6.0799 21 7.2V20Z"
                  stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
              </svg>
            </button>
          </div>
          <!-- Right Floating Buttons -->
          <div v-if="currentRoute === '/menu'" class="absolute right-2 top-30 flex flex-col gap-3 z-20">
            <button class="floating-btn" @click="showOffers = true">
              <svg class="w-8 h-8" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M12 8v13m0-13V6a2 2 0 112 2h-2zm0 0V5.5A2.5 2.5 0 109.5 8H12zm-7 4h14M5 12a2 2 0 110-4h14a2 2 0 110 4M5 12v7a2 2 0 002 2h10a2 2 0 002-2v-7" />
              </svg>

              <div class="notification-dot"></div>
            </button>
            <button class="floating-btn" @click="showSettings = true">
              <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z">
                </path>
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
              </svg>
            </button>

            <button class="floating-btn" @click="showEvents = true">
              <!-- Wydarzenia -->
              <svg class="w-8 h-8" viewBox="0 0 24 24" fill="currentColor">
                <path
                  d="M19 19H5V8h14m-3-7v2H8V1H6v2H5c-1.11 0-1.99.9-1.99 2L3 19a2 2 0 002 2h14a2 2 0 002-2V5c0-1.1-.9-2-2-2h-1V1m-1 11h-5v5h5v-5z" />
              </svg>
            </button>
          </div>
          <!-- Main Content Area: Dynamic based on active tab -->
          <slot />
        </div>
        <!-- Bottom Navigation Bar -->
        <div class="flex justify-around items-center p-2 rounded-t-2xl glass-bar mt-auto">
          <Link :href="route('mobile.home')" class="nav-item flex flex-col items-center text-center w-20"
            :class="{ 'active': currentRoute === '/menu' }">
          <svg class="w-8 h-8 nav-icon" fill="currentColor" viewBox="0 0 20 20">
            <path
              d="M10.707 2.293a1 1 0 00-1.414 0l-7 7a1 1 0 001.414 1.414L4 10.414V17a1 1 0 001 1h2a1 1 0 001-1v-2a1 1 0 011-1h2a1 1 0 011 1v2a1 1 0 001 1h2a1 1 0 001-1v-6.586l.293.293a1 1 0 001.414-1.414l-7-7z">
            </path>
          </svg>
          <span class="text-xs font-bold">Graj</span>
          </Link>
          <!-- <Link :href="route('mobile.events')" class="nav-item flex flex-col items-center text-center w-20 relative" :class="{ 'active': currentRoute === '/events' }">
            <svg class="w-8 h-8 nav-icon" viewBox="0 0 24 24" fill="currentColor">
              <path d="M19 19H5V8h14m-3-7v2H8V1H6v2H5c-1.11 0-1.99.9-1.99 2L3 19a2 2 0 002 2h14a2 2 0 002-2V5c0-1.1-.9-2-2-2h-1V1m-1 11h-5v5h5v-5z"/>
            </svg>
            <span class="text-xs font-bold">Wydarzenia</span>
            <div class="notification-dot"></div>  
          </Link> -->
          <Link :href="route('mobile.clubs')" class="nav-item flex flex-col items-center text-center w-20"
            :class="{ 'active': currentRoute === '/clubs' }">
          <svg class="w-8 h-8 nav-icon" viewBox="0 0 24 24" fill="currentColor">
            <path d="M4 14h4v7H4zm6 0h4v7h-4zm6 3h4v4h-4zM4 3h4v10H4zm6 0h4v5h-4zm6 0h4v3h-4z" />
          </svg>
          <span class="text-xs font-bold">Kluby</span>
          </Link>
          <Link :href="route('mobile.friends')" class="nav-item flex flex-col items-center text-center w-20"
            :class="{ 'active': currentRoute === '/friends' }">
          <svg class="w-8 h-8 nav-icon" fill="currentColor" viewBox="0 0 24 24">
            <path
              d="M12 5.5A3.5 3.5 0 0115.5 9a3.5 3.5 0 01-3.5 3.5A3.5 3.5 0 018.5 9 3.5 3.5 0 0112 5.5M5 8.5A3.5 3.5 0 018.5 12a3.5 3.5 0 01-3.5 3.5A3.5 3.5 0 011.5 12 3.5 3.5 0 015 8.5m14 0a3.5 3.5 0 013.5 3.5 3.5 3.5 0 01-3.5 3.5A3.5 3.5 0 0115.5 12a3.5 3.5 0 013.5-3.5M12 14c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z">
            </path>
          </svg>
          <span class="text-xs font-bold">Znajomi</span>
          </Link>
          <Link :href="route('mobile.shop')" class="nav-item flex flex-col items-center text-center w-20"
            :class="{ 'active': currentRoute === '/shop' }">
          <svg class="w-8 h-8 nav-icon" viewBox="0 0 24 24" fill="currentColor">
            <path
              d="M16 11V3H8v6H2v12h20V11h-6zm-6-6h4v1h-4V5zm0 3h4v1h-4V8zM4 13h4v-1H4v1zm0 2h4v-1H4v1zm0 2h4v-1H4v1zm14 2h-4v-1h4v1zm0-2h-4v-1h4v1zm0-2h-4v-1h4v1z" />
          </svg>
          <span class="text-xs font-bold">Sklep</span>
          </Link>
        </div>
      </div>
    </transition>
    <!-- Matchmaking Screen -->
    <transition>
      <div v-if="screen === 'matchmaking'"
        class="game-screen z-30 flex flex-col items-center justify-center p-4 bg-gray-900">
        <div class="text-center text-white">
          <h2 class="text-5xl font-bold text-white">Szukanie gry...</h2>
          <p class="text-lg text-gray-400 mt-4">Przygotowuję stół...</p>
          <button @click="cancelMatchmaking" 
            class="mt-8 bg-red-600 hover:bg-red-700 text-white px-6 py-3 rounded-xl text-lg font-semibold transition-all duration-200 transform hover:scale-105">
            Anuluj
          </button>
        </div>
      </div>
    </transition>
    <!-- Game Screen -->
    <transition>
      <div v-if="screen === 'game'" id="gameTableScreen"
        class="game-screen z-20 flex flex-col items-center justify-between p-2 bg-game-table">
        <div class="absolute inset-0">
          <player-pod v-for="player in players" :key="player.id" :player="player"
            :is-current-turn="player.id === currentPlayer.id" />
        </div>
        <div class="w-full flex-grow flex flex-col items-center justify-center z-0">
          <discard-pile :cards="gameState.discardPile" />
        </div>
        <div class="w-full flex flex-col items-center pb-4 z-10">
          <player-hand :cards="humanPlayer.hand" :is-turn="isHumanTurn" @card-played="handleCardPlay" />
          <div id="action-buttons" class="flex justify-center space-x-4 mt-2 w-full px-4">
            <button @click="playerPass" :disabled="!isHumanTurn || hasValidMove"
              class="action-button w-1/2 bg-gray-700 hover:bg-gray-600 text-white py-3 px-4">
              <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <line x1="5" y1="12" x2="19" y2="12"></line>
                <polyline points="12 5 19 12 12 19"></polyline>
              </svg>
              Pasuj
            </button>
          </div>
        </div>
      </div>
    </transition>
    <!-- Game Over Screen -->
    <transition>
      <div v-if="screen === 'game-over'"
        class="game-screen z-50 flex flex-col items-center justify-center p-4 bg-black/70 backdrop-blur-sm">
        <div class="text-center text-white">
          <h2 id="gameOverMessage" class="text-5xl font-bold text-white">{{ gameOverMessage }}</h2>
          <button @click="startGameFlow"
            class="w-full max-w-xs mx-auto mt-16 text-2xl text-white bg-green-600 rounded-xl py-3 shadow-lg">
            Zagraj Ponownie
          </button>
        </div>
      </div>
    </transition>
    <!-- Dialogs -->
    <Dialog v-model:open="showMail">
      <DialogContent>
        <div class="window-title-bar">
          <span class="window-icon">📨</span>
          <DialogTitle>Poczta</DialogTitle>
        </div>
        <div class="dialog-content-inner">
          <div class="flex flex-col gap-3">
            <div v-for="(message, index) in messages" :key="index" :class="['bg-black/20 p-3 rounded-lg transition-all',
              { 'opacity-50': message.read }]" @click="toggleMessage(index)">
              <div class="flex items-center justify-between mb-2 border-b border-white/10 pb-2">
                <div class="flex items-center gap-2">
                  <div class="font-bold">{{ message.sender }}</div>
                  <div v-if="message.isSystem" class="text-xs bg-blue-500/30 text-blue-200 px-2 py-0.5 rounded-full">
                    System
                  </div>
                  <div v-if="message.hasReward && !message.rewardClaimed"
                    class="text-xs bg-yellow-500/30 text-yellow-200 px-2 py-0.5 rounded-full">
                    Nagroda
                  </div>
                </div>
                <div class="text-xs opacity-60">{{ message.date }}</div>
              </div>
              <div :class="{ 'max-h-0': !message.expanded, 'max-h-96': message.expanded }"
                class="overflow-hidden transition-all duration-300">
                <p class="text-sm mb-3">{{ message.content }}</p>
                <div v-if="message.hasReward && !message.rewardClaimed"
                  class="flex justify-between items-center bg-yellow-500/10 p-2 rounded-lg">
                  <span class="text-sm text-yellow-200">🎁 {{ message.reward }}</span>
                  <button @click.stop="claimReward(index)"
                    class="bg-yellow-500 hover:bg-yellow-400 text-black px-3 py-1 rounded-lg text-sm">
                    Odbierz
                  </button>
                </div>
              </div>
              <div class="flex justify-end mt-2">
                <button v-if="message.expanded" @click.stop="deleteMessage(index)"
                  class="text-red-400 hover:text-red-300 text-sm">
                  Usuń
                </button>
              </div>
            </div>
          </div>
        </div>
      </DialogContent>
    </Dialog>
    <Dialog v-model:open="showRewards">
      <DialogContent>
        <div class="window-title-bar">
          <span class="window-icon">🏆</span>
          <DialogTitle>Nagrody</DialogTitle>
        </div>
        <div class="dialog-content-inner">
          <div class="flex items-center justify-between mb-3 pb-2 border-b border-white/10">
            <div class="font-bold">Dostępne nagrody</div>
          </div>
          <div class="flex flex-col gap-3">
            <div class="bg-black/20 p-3 rounded-lg flex items-center justify-between">
              <div class="flex items-center gap-2">
                <!-- Moneta SVG -->
                <svg class="w-6 h-6" viewBox="0 0 24 24" fill="none">
                  <circle cx="12" cy="12" r="10" fill="#FFD700" stroke="#E6B800" stroke-width="2" />
                  <text x="12" y="16" text-anchor="middle" font-size="12" fill="#fff" font-weight="bold">M</text>
                </svg>
                <span>500 monet</span>
              </div>
              <button
                class="bg-gradient-to-r from-[#FF512F] to-[#DD2476] text-white px-3 py-1 text-sm rounded-lg">Odbierz</button>
            </div>
            <div class="bg-black/20 p-3 rounded-lg flex items-center justify-between opacity-50">
              <div class="flex items-center gap-2">
                <!-- Diament SVG -->
                <svg class="w-6 h-6" viewBox="0 0 24 24" fill="none">
                  <polygon points="12,2 22,9 12,22 2,9" fill="#00BFFF" stroke="#0099CC" stroke-width="2" />
                  <polygon points="12,2 17,9 12,22 7,9" fill="#B3E6FF" stroke="#0099CC" stroke-width="1" />
                </svg>
                <span>50 gemów</span>
              </div>
              <button class="bg-gray-600 text-white px-3 py-1 text-sm rounded-lg">Zablokowane</button>
            </div>
          </div>
        </div>
      </DialogContent>
    </Dialog>
    <Dialog v-model:open="showOffers">
      <DialogContent>
        <div class="window-title-bar">
          <span class="window-icon">🎁</span>
          <DialogTitle>Oferty</DialogTitle>
        </div>
        <div class="dialog-content-inner">
          <div class="flex items-center justify-between mb-3 pb-2 border-b border-white/10">
            <div class="font-bold">Specjalne oferty</div>
            <div class="text-xs bg-yellow-500 text-black px-2 py-0.5 rounded-full">Limitowane</div>
          </div>
          <div
            class="relative overflow-hidden bg-gradient-to-br from-purple-900/50 to-indigo-900/50 p-4 rounded-lg mb-3">
            <div class="absolute -right-4 -top-4 text-4xl opacity-20 rotate-12">💎</div>
            <h3 class="font-bold text-lg mb-1">Pakiet Startowy</h3>
            <p class="text-sm mb-3 opacity-80">1000 monet + 100 gemów + karta specjalna</p>
            <div class="flex items-center justify-between">
              <div class="line-through opacity-60">29.99zł</div>
              <div class="font-bold text-lg">9.99zł</div>
            </div>
            <button class="bg-gradient-to-r from-[#FF512F] to-[#DD2476] text-white px-4 py-2 rounded-lg w-full mt-3">Kup
              teraz</button>
          </div>
          <div
            class="relative overflow-hidden bg-gradient-to-br from-purple-900/50 to-indigo-900/50 p-4 rounded-lg mb-3">
            <div class="absolute -right-4 -top-4 text-4xl opacity-20 rotate-12">💎</div>
            <h3 class="font-bold text-lg mb-1">Pakiet Startowy</h3>
            <p class="text-sm mb-3 opacity-80">1000 monet + 100 gemów + karta specjalna</p>
            <div class="flex items-center justify-between">
              <div class="line-through opacity-60">29.99zł</div>
              <div class="font-bold text-lg">9.99zł</div>
            </div>
            <button class="bg-gradient-to-r from-[#FF512F] to-[#DD2476] text-white px-4 py-2 rounded-lg w-full mt-3">Kup
              teraz</button>
          </div>
          <div
            class="relative overflow-hidden bg-gradient-to-br from-purple-900/50 to-indigo-900/50 p-4 rounded-lg mb-3">
            <div class="absolute -right-4 -top-4 text-4xl opacity-20 rotate-12">💎</div>
            <h3 class="font-bold text-lg mb-1">Pakiet Startowy</h3>
            <p class="text-sm mb-3 opacity-80">1000 monet + 100 gemów + karta specjalna</p>
            <div class="flex items-center justify-between">
              <div class="line-through opacity-60">29.99zł</div>
              <div class="font-bold text-lg">9.99zł</div>
            </div>
            <button class="bg-gradient-to-r from-[#FF512F] to-[#DD2476] text-white px-4 py-2 rounded-lg w-full mt-3">Kup
              teraz</button>
          </div>
          <div
            class="relative overflow-hidden bg-gradient-to-br from-purple-900/50 to-indigo-900/50 p-4 rounded-lg mb-3">
            <div class="absolute -right-4 -top-4 text-4xl opacity-20 rotate-12">💎</div>
            <h3 class="font-bold text-lg mb-1">Pakiet Startowy</h3>
            <p class="text-sm mb-3 opacity-80">1000 monet + 100 gemów + karta specjalna</p>
            <div class="flex items-center justify-between">
              <div class="line-through opacity-60">29.99zł</div>
              <div class="font-bold text-lg">9.99zł</div>
            </div>
            <button class="bg-gradient-to-r from-[#FF512F] to-[#DD2476] text-white px-4 py-2 rounded-lg w-full mt-3">Kup
              teraz</button>
          </div>
        </div>
      </DialogContent>
    </Dialog>
    <Dialog v-model:open="showSettings">
      <DialogContent>
        <div class="window-title-bar">
          <span class="window-icon">⚙️</span>
          <DialogTitle>Ustawienia</DialogTitle>
        </div>
        <div class="p-4">
          <div class="dialog-content-inner">
            <div class="flex flex-col gap-4">
              <div class="flex justify-between items-center">
                <label class="font-medium">Dźwięk</label>
                <div class="relative w-12 h-6 bg-black/20 rounded-full">
                  <div
                    class="absolute w-5 h-5 bg-gradient-to-r from-[#FF512F] to-[#DD2476] rounded-full top-0.5 right-0.5">
                  </div>
                </div>
              </div>
              <div class="flex justify-between items-center">
                <label class="font-medium">Muzyka</label>
                <div class="relative w-12 h-6 bg-black/20 rounded-full">
                  <div
                    class="absolute w-5 h-5 bg-gradient-to-r from-[#FF512F] to-[#DD2476] rounded-full top-0.5 left-0.5">
                  </div>
                </div>
              </div>
              <div class="flex justify-between items-center">
                <label class="font-medium">Wibracje</label>
                <div class="relative w-12 h-6 bg-black/20 rounded-full">
                  <div
                    class="absolute w-5 h-5 bg-gradient-to-r from-[#FF512F] to-[#DD2476] rounded-full top-0.5 right-0.5">
                  </div>
                </div>
              </div>
              <div class="h-px bg-white/10 my-2"></div>
              <button
                class="bg-gradient-to-r from-[#FF512F] to-[#DD2476] text-white px-4 py-2 rounded-lg">Zapisz</button>
            </div>
          </div>
        </div>
      </DialogContent>
    </Dialog>
    <Dialog v-model:open="showEvents">
      <DialogContent>
        <div class="window-title-bar">
          <span class="window-icon">🎉</span>
          <DialogTitle>Wydarzenia</DialogTitle>
        </div>
        <div class="dialog-content-inner">
          Brak wydarzeń
        </div>
      </DialogContent>
    </Dialog>

    <Dialog v-model:open="showChat">
      <DialogContent>
        <div class="window-title-bar">
          <span class="window-icon">💬</span>
          <DialogTitle>Chat</DialogTitle>
        </div>
        <div class="dialog-content-inner">
          <div class="flex flex-col gap-4">
            <div class="flex items-center justify-between">
              <div class="flex flex-col w-full gap-3">
                <div class="flex items-start gap-2">
                  <div
                    class="w-8 h-8 rounded-full bg-gradient-to-br from-purple-500 to-pink-500 flex items-center justify-center text-sm">
                    M</div>
                  <div class="bg-black/20 rounded-lg p-2 max-w-[80%]">
                    <p class="text-sm text-white/90">Hej, ktoś chętny na szybką grę?</p>
                    <span class="text-xs text-white/50">12:34</span>
                  </div>
                </div>

                <div class="flex items-start gap-2 flex-row-reverse">
                  <div
                    class="w-8 h-8 rounded-full bg-gradient-to-br from-blue-500 to-cyan-500 flex items-center justify-center text-sm">
                    T</div>
                  <div class="bg-gradient-to-r from-[#FF512F] to-[#DD2476] rounded-lg p-2 max-w-[80%]">
                    <p class="text-sm">Ja mogę zagrać!</p>
                    <span class="text-xs text-white/70">12:35</span>
                  </div>
                </div>

                <div class="flex items-start gap-2">
                  <div
                    class="w-8 h-8 rounded-full bg-gradient-to-br from-green-500 to-emerald-500 flex items-center justify-center text-sm">
                    K</div>
                  <div class="bg-black/20 rounded-lg p-2 max-w-[80%]">
                    <p class="text-sm text-white/90">Super! Zróbmy turniej 2v2</p>
                    <span class="text-xs text-white/50">12:36</span>
                  </div>
                </div>

                <div class="flex items-start gap-2">
                  <div
                    class="w-8 h-8 rounded-full bg-gradient-to-br from-yellow-500 to-orange-500 flex items-center justify-center text-sm">
                    A</div>
                  <div class="bg-black/20 rounded-lg p-2 max-w-[80%]">
                    <p class="text-sm text-white/90">Dołączam się! 🎮</p>
                    <span class="text-xs text-white/50">12:37</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </DialogContent>
    </Dialog>
  </div>
</template>

<style>
body {
  font-family: 'Poppins', sans-serif;
  -webkit-font-smoothing: antialiased;
  -moz-osx-font-smoothing: grayscale;
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 100vh;
  background: #1a1a1a;
}

.font-bangers {
  font-family: 'Bangers', cursive;
}

.font-montserrat {
  font-family: 'Montserrat', sans-serif;
}

.phone-frame {
  width: 390px;
  height: 844px;
  background: #111;
  border-radius: 54px;
  padding: 12px;
  box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4), inset 0 0 0 3px #333, inset 0 0 10px rgba(0, 0, 0, 0.5);
  position: relative;
}

.phone-screen {
  background: #000;
  width: 100%;
  height: 100%;
  border-radius: 42px;
  overflow: hidden;
  position: relative;
}

.phone-notch {
  position: absolute;
  top: 12px;
  left: 50%;
  transform: translateX(-50%);
  width: 160px;
  height: 30px;
  background: #111;
  border-radius: 0 0 15px 15px;
  z-index: 250;
}

.game-screen {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
}

/* --- Splash & Menu Screen Styles --- */
.bg-dynamic-screen {
  background-color: #0f0c29;
  background-image: linear-gradient(160deg, #24243e, #302b63, #0f0c29);
  overflow: hidden;
}

.bg-dynamic-screen::before,
.bg-dynamic-screen::after {
  content: '';
  position: absolute;
  border-radius: 50%;
  filter: blur(90px);
  opacity: 0.25;
  z-index: 0;
}

.bg-dynamic-screen::before {
  width: 350px;
  height: 350px;
  background: #8E2DE2;
  top: -80px;
  left: -150px;
  animation: moveShape1 15s infinite alternate ease-in-out;
}

.bg-dynamic-screen::after {
  width: 300px;
  height: 300px;
  background: #4A00E0;
  bottom: -100px;
  right: -120px;
  animation: moveShape2 12s infinite alternate ease-in-out;
}

@keyframes moveShape1 {
  to {
    transform: translate(50px, 80px) scale(1.2);
  }
}

@keyframes moveShape2 {
  to {
    transform: translate(-60px, -40px) scale(0.8);
  }
}

/* Glassmorphism Effect */
.glass-bar {
  background: rgba(255, 255, 255, 0.05);
  backdrop-filter: blur(12px);
  -webkit-backdrop-filter: blur(12px);
  border: 1px solid rgba(255, 255, 255, 0.1);
  position: relative;
  z-index: 10;
}

.resource-item {
  background-color: rgba(0, 0, 0, 0.3);
  border: 1px solid rgba(255, 255, 255, 0.1);
}

.play-btn {
  font-family: 'Montserrat', sans-serif;
  color: white;
  border: none;
  text-shadow: 0 1px 3px rgba(0, 0, 0, 0.5);
  transition: all 0.2s ease;
  position: relative;
  z-index: 1;
}

.play-btn:active {
  transform: translateY(2px);
}

.play-now-btn {
  background: linear-gradient(45deg, #FF512F, #DD2476);
  box-shadow: 0 0 25px rgba(221, 36, 118, 0.5), 0 5px 0 #a11a54;
}

.play-now-btn:active {
  box-shadow: 0 0 15px rgba(221, 36, 118, 0.5), 0 3px 0 #a11a54;
}

.tournament-btn {
  background: linear-gradient(45deg, #4A00E0, #8E2DE2);
  box-shadow: 0 0 25px rgba(142, 45, 226, 0.5), 0 5px 0 #4a00e0;
}

.tournament-btn:active {
  box-shadow: 0 0 15px rgba(142, 45, 226, 0.5), 0 3px 0 #4a00e0;
}

.notification-dot {
  position: absolute;
  top: 0px;
  right: 0px;
  width: 14px;
  height: 14px;
  background-color: #e74c3c;
  border-radius: 50%;
  border: 2px solid #302b63;
  animation: pulse-dot 1.5s infinite;
}

@keyframes pulse-dot {
  0% {
    transform: scale(0.95);
    box-shadow: 0 0 0 0 rgba(231, 76, 60, 0.7);
  }

  70% {
    transform: scale(1);
    box-shadow: 0 0 0 10px rgba(231, 76, 60, 0);
  }

  100% {
    transform: scale(0.95);
    box-shadow: 0 0 0 0 rgba(231, 76, 60, 0);
  }
}

.nav-item {
  transition: transform 0.2s ease, color 0.2s ease;
}

.nav-item.active,
.nav-item:hover {
  transform: translateY(-4px);
  color: #f1c40f;
}

.nav-item.active .nav-icon,
.nav-item:hover .nav-icon {
  filter: drop-shadow(0 0 8px #f1c40f);
}

.floating-btn {
  width: 56px;
  height: 56px;
  border-radius: 16px;
  background: rgba(40, 30, 80, 0.7);
  backdrop-filter: blur(8px);
  -webkit-backdrop-filter: blur(8px);
  border: 2px solid rgba(255, 255, 255, 0.2);
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.2s ease-out;
  position: relative;
}

.floating-btn:hover {
  transform: scale(1.1);
  border-color: rgba(255, 255, 255, 0.5);
}

.progress-bar {
  transition: width 0.1s linear;
}

/* Game related styles (unchanged) */
.bg-game-table {
  background-color: #0d2e1e;
  background-image: radial-gradient(ellipse at center, #1C4A33, #0A281A 70%);
  box-shadow: inset 0 0 30px rgba(0, 0, 0, 0.7);
}

.player-hand {
  perspective: 1200px;
  height: 160px;
  position: relative;
}

.card {
  width: 70px;
  height: 105px;
  border-radius: 8px;
  background-color: #F8F9FA;
  border: 1px solid #DEE2E6;
  box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  padding: 4px;
  font-weight: 600;
  transition: all 0.4s cubic-bezier(0.25, 1, 0.5, 1);
  position: absolute;
  bottom: 0;
  left: 50%;
  cursor: pointer;
}

.card.red {
  color: #FF4757;
}

.card.black {
  color: #2F3542;
}

.player-hand .card {
  transform: translateX(-50%) translateY(150%) scale(0.9);
  opacity: 0;
}

.player-hand .card:hover:not(.invalid-card) {
  transform: var(--hover-transform, translateY(-30px) scale(1.1));
  z-index: 100;
}

@keyframes deal-card {
  from {
    transform: translateX(-50%) translateY(150%) scale(0.9);
    opacity: 0;
  }

  to {
    transform: var(--end-transform);
    opacity: 1;
  }
}

.discard-pile {
  width: 250px;
  height: 250px;
  position: relative;
}

.discard-pile .card {
  position: absolute;
  top: 50%;
  left: 50%;
  transform-origin: center center;
  cursor: default;
}

@keyframes pulse-glow {

  0%,
  100% {
    box-shadow: 0 0 15px 2px #FFEB3B;
    transform: scale(1);
  }

  50% {
    box-shadow: 0 0 25px 8px #FFEB3B;
    transform: scale(1.05);
  }
}

.is-turn .avatar-container {
  animation: pulse-glow 1.5s infinite;
}

.invalid-card {
  filter: grayscale(90%) brightness(0.6);
  cursor: not-allowed !important;
}

.action-button {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  font-weight: 600;
  border-radius: 12px;
  box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
  transition: all 0.2s ease;
}

.action-button:hover:not(:disabled) {
  transform: translateY(-2px);
  box-shadow: 0 6px 20px rgba(0, 0, 0, 0.25);
}

.action-button:disabled {
  filter: grayscale(80%);
  cursor: not-allowed !important;
  opacity: 0.6;
}

.v-enter-active,
.v-leave-active {
  transition: opacity 0.5s ease;
}

.v-enter-from,
.v-leave-to {
  opacity: 0;
}

/* Window title bar - match the dialog's gradient */
.window-title-bar {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin: -1.5rem -1.5rem 0.5rem -1.5rem;
  padding: 0.75rem 1rem;
  border-bottom: 1px solid rgba(255, 81, 47, 0.3);
  background: linear-gradient(to right, rgba(255, 81, 47, 0.15), rgba(221, 36, 118, 0.15));
}

.window-icon {
  font-size: 1.5rem;
  filter: drop-shadow(0 1px 2px rgba(0, 0, 0, 0.2));
}

/* Dialog content styles */
.dialog-content-inner {
  /* background: rgba(0,0,0,0.1); */
  /* border-radius: 0.5rem; */
  /* padding: 1rem; */
}

/* Remaining previous styles */
.game-screen {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  margin: 0 auto;
}

.bg-dynamic-screen {
  background-color: #0f0c29;
  background-image: linear-gradient(160deg, #24243e, #302b63, #0f0c29);
  overflow: hidden;
}

.bg-dynamic-screen::before,
.bg-dynamic-screen::after {
  content: '';
  position: absolute;
  border-radius: 50%;
  filter: blur(90px);
  opacity: 0.25;
  z-index: 0;
}

.bg-dynamic-screen::before {
  width: 350px;
  height: 350px;
  background: #8E2DE2;
  top: -80px;
  left: -150px;
  animation: moveShape1 15s infinite alternate ease-in-out;
}

.bg-dynamic-screen::after {
  width: 300px;
  height: 300px;
  background: #4A00E0;
  bottom: -100px;
  right: -120px;
  animation: moveShape2 12s infinite alternate ease-in-out;
}

@keyframes moveShape1 {
  to {
    transform: translate(50px, 80px) scale(1.2);
  }
}

@keyframes moveShape2 {
  to {
    transform: translate(-60px, -40px) scale(0.8);
  }
}

/* Rest of your existing styles */
</style>