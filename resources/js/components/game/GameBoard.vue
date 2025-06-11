<script setup lang="ts">
import { ref, onMounted, computed, watch } from 'vue';
import CardPicker from '@/components/game/CardPicker.vue';
import PlayerHand from '@/components/game/PlayerHand.vue';
import PickerCard from '@/components/game/PickerCard.vue';
import PlayerInfo from '@/components/game/PlayerInfo.vue';
import { webSocketService } from '@/Services/WebSocketService';
import { useToast } from '@/components/ui/toast';
import { useAuth } from '@/composables/useAuth';
import { MessageType } from "@/types/messageTypes";

interface CardData {
    suit: 'hearts' | 'diamonds' | 'clubs' | 'spades';
    value: string;
    header: string;
    cardRank: string;
    cardSymbol: string;
    symbolColorClass: string;
    rankValue: number;
}

interface Player {
    id: number;
    name: string;
    cards: CardData[];
    isMainPlayer: boolean;
    level: number;
    avatarUrl?: string;
}

// Define props for WebSocket integration
const props = defineProps({
  hand: {
    type: Array,
    default: () => []
  },
  playArea: {
    type: Array,
    default: () => []
  },
  lastCard: {
    type: Object,
    default: null
  },
  deckCount: {
    type: Number,
    default: 0
  },
  isYourTurn: {
    type: Boolean,
    default: false
  },
  players: {
    type: Array,
    default: () => []
  }
});

// Define emits for game actions
const emit = defineEmits(['play-card', 'draw-card', 'pass-turn']);

const mainPlayerCards = ref<CardData[]>([]);
const player2Cards = ref<CardData[]>([]);
const player3Cards = ref<CardData[]>([]);
const player4Cards = ref<CardData[]>([]);
const deckPile = ref<CardData[]>([]);

const selectedMainPlayerCards = ref<CardData[]>([]);
const lastPlayedCardRankValue = ref<number | null>(null);
const isConnecting = ref(false);
const currentRoom = ref<string | null>(null);
const gameMessage = ref<string>('');
const { toast } = useToast();
const { user, isLoggedIn } = useAuth();

// Add room ID from URL or props
const roomId = ref<string | null>(null);

const getRankValue = (rankKey: string): number => {
    const rankMap: { [key: string]: number } = {
        '9': 9, '10': 10, 'J': 11, 'Q': 12, 'K': 13, 'A': 14
    };
    return rankMap[rankKey] || 0;
};

const generateFullDeck = (): CardData[] => {
    const suits = [
        { key: 'H', name: 'Kier', symbol: '❤️', colorClass: 'text-red-600' },
        { key: 'D', name: 'Karo', symbol: '♦️', colorClass: 'text-red-600' },
        { key: 'C', name: 'Trefl', symbol: '♣️', colorClass: 'text-gray-900' },
        { key: 'S', name: 'Pik', symbol: '♠️', colorClass: 'text-gray-900' }
    ];
    const ranks = [
        { key: '9', name: 'Dziewiątka' },
        { key: '10', name: 'Dziesiątka' },
        { key: 'J', name: 'Walet' },
        { key: 'Q', name: 'Dama' },
        { key: 'K', name: 'Król' },
        { key: 'A', name: 'As' }
    ];

    const deck: CardData[] = [];
    suits.forEach(suit => {
        ranks.forEach(rank => {
            deck.push({
                value: `${suit.key}_${rank.key}`,
                suit: suit.key as 'hearts' | 'diamonds' | 'clubs' | 'spades',
                rankValue: getRankValue(rank.key),
                header: `${rank.name} ${suit.name}`,
                cardRank: rank.name,
                cardSymbol: suit.symbol,
                symbolColorClass: suit.colorClass,
            });
        });
    });
    return deck;
};

const shuffleDeck = (deck: CardData[]): CardData[] => {
    for (let i = deck.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [deck[i], deck[j]] = [deck[j], deck[i]];
    }
    return deck;
};

const dealCards = () => {
    const fullDeck = generateFullDeck();
    let shuffledDeck = shuffleDeck(fullDeck);

    const nineOfHearts = shuffledDeck.find(card => card.value === 'H_9');
    const tempDeckPile: CardData[] = [];

    if (nineOfHearts) {
        shuffledDeck = shuffledDeck.filter(card => card.value !== 'H_9');
        tempDeckPile.push(nineOfHearts);
    }

    mainPlayerCards.value = shuffledDeck.splice(0, 5);
    player2Cards.value = shuffledDeck.splice(0, 5);
    player3Cards.value = shuffledDeck.splice(0, 5);
    player4Cards.value = shuffledDeck.splice(0, 5);

    deckPile.value = tempDeckPile.concat(shuffledDeck);
};

// Funkcja pomocnicza do generowania losowych URL-i z Lorem Picsum
const getRandomAvatarUrl = (seed: number) => {
    return `https://picsum.photos/seed/${seed}/60/60`; // Użyjemy seed, żeby awatary były stałe
};

// Dane graczy z awatarami z Lorem Picsum API
const playersData = ref<Player[]>([
    { id: 1, name: 'Gracz Główny (Ty)', cards: [], isMainPlayer: true, level: 15, avatarUrl: getRandomAvatarUrl(1001) },
    { id: 2, name: 'Borgir', cards: [], isMainPlayer: false, level: 8, avatarUrl: getRandomAvatarUrl(1002) },
    { id: 3, name: 'Katarzyna', cards: [], isMainPlayer: false, level: 23, avatarUrl: getRandomAvatarUrl(1003) },
    { id: 4, name: 'SmoczaKrew', cards: [], isMainPlayer: false, level: 12, avatarUrl: getRandomAvatarUrl(1004) },
]);

// Update player names from gameState when available
const updatePlayerNames = () => {
  if (props.players && props.players.length > 0) {
    props.players.forEach((player, index) => {
      if (index < playersData.value.length) {
        playersData.value[index].name = player.username || player.name || 'Gracz';
      }
    });
  }
};

watch([mainPlayerCards, player2Cards, player3Cards, player4Cards], () => {
    playersData.value[0].cards = mainPlayerCards.value; // Gracz Główny (Ty)
    playersData.value[1].cards = player2Cards.value;     // Borgir (Gracz 2)
    playersData.value[2].cards = player3Cards.value;     // Katarzyna (Gracz 3)
    playersData.value[3].cards = player4Cards.value;     // SmoczaKrew (Gracz 4)
}, { immediate: true });

// Watch for changes to players prop to update player names
watch(() => props.players, () => {
  updatePlayerNames();
}, { immediate: true, deep: true });


const mainPlayer = computed(() => playersData.value.find(p => p.isMainPlayer));
const player2 = computed(() => playersData.value.find(p => p.id === 2));
const player3 = computed(() => playersData.value.find(p => p.id === 3));
const player4 = computed(() => playersData.value.find(p => p.id === 4));


const handleCardSelection = (selectedCards: CardData[]) => {
  selectedMainPlayerCards.value = selectedCards;
};

const handleCardPlay = () => {
  if (selectedMainPlayerCards.value.length !== 1) {
    toast({
      title: 'Invalid selection',
      description: 'Please select exactly one card to play',
      variant: 'destructive'
    });
    return;
  }

  // Find the card index in the player's hand
  const cardToPlay = selectedMainPlayerCards.value[0]; // Use first selected card for now
  const cardIndex = mainPlayerCards.value.findIndex(card => card.value === cardToPlay.value);

  if (cardIndex === -1) {
    toast({
      title: 'Invalid card',
      description: 'The selected card is not in your hand',
      variant: 'destructive'
    });
    return;
  }

  // Use send with game_action type instead of non-existent playCard method
  webSocketService.send({
    type: MessageType.GAME_ACTION,
    action_type: 'play_card',
    card_index: cardIndex
  });
  
  selectedMainPlayerCards.value = [];
};

function handleLeaveRoom() {
  // Use send with leave_room type instead of non-existent leaveRoom method
  webSocketService.send({
    type: MessageType.LEAVE_ROOM
  });
  window.location.href = '/lobby';
}

onMounted(() => {
    // Get room_id from URL query parameter if not provided in props
    const urlParams = new URLSearchParams(window.location.search);
    roomId.value = urlParams.get('room_id');
    
    if (roomId.value) {
        // Set up event listeners for game events
        setupGameEventListeners();
    }
    
    // Initialize the game (temporary - for development)
    dealCards();
    updatePlayerNames();

    // Handle WebSocket events
    webSocketService.on('auth_success', (data: any) => {
        toast({
            title: 'Połączono z serwerem gry',
            description: `Witaj ${data.username}!`,
        });
    });

    webSocketService.on('room_created', (data: any) => {
        toast({
            title: 'Utworzono nowy pokój',
            description: `Utworzono pokój: ${data.room_name}`,
        });
        currentRoom.value = data.room_id;
    });

    webSocketService.on('room_joined', (data: any) => {
        toast({
            title: 'Dołączono do pokoju',
            description: `Dołączono do pokoju: ${data.room_name}`,
        });
        currentRoom.value = data.room_id;

        // Update players data with real players from the room
        if (data.players && Array.isArray(data.players)) {
            // Update player information
            console.log('Room players received:', data.players);
        }
    });

    webSocketService.on('game_started', (data: any) => {
        toast({
            title: 'Gra rozpoczęta',
            description: 'Rozpoczęto nową grę',
        });
        gameMessage.value = 'Gra się rozpoczęła! Twój ruch.';
    });

    webSocketService.on('your_cards', (data: any) => {
        // Update main player's cards with data from server
        if (data.cards && Array.isArray(data.cards)) {
            mainPlayerCards.value = data.cards;
        }
    });

    webSocketService.on('card_played', (data: any) => {
        toast({
            title: 'Zagranie karty',
            description: `Gracz ${data.username} zagrał kartę`,
        });

        // Update top card on deck pile
        if (data.card) {
            deckPile.value = [data.card, ...deckPile.value.slice(0, 1)];
            lastPlayedCardRankValue.value = data.card.rankValue;
        }
    });

    webSocketService.on('turn_changed', (data: any) => {
        gameMessage.value = `Ruch gracza: ${data.current_player_username}`;
    });

    webSocketService.on('game_over', (data: any) => {
        toast({
            title: 'Koniec gry',
            description: `Gracz ${data.winner.username} wygrał!`,
        });
        gameMessage.value = `Koniec gry! Wygrał gracz ${data.winner.username}`;
    });

    // If user is logged in, authenticate with WebSocket server
    if (isLoggedIn.value && user.value) {
        // Get user ID directly from the user object and ensure it's correctly typed
        const userId = user.value.id;
        console.log('Authenticating with WebSocket using user ID:', userId, 'Type:', typeof userId);

        // Send auth message directly
        if (userId !== undefined && userId !== null) {
            webSocketService.send({
                type: MessageType.AUTH,
                token: user.value.ws_token || 'demo-token',
                user_id: userId,
                username: user.value.name
            });
        } else {
            console.error('Cannot authenticate: user.value.id is undefined or null');
            toast({
                title: 'Błąd autoryzacji',
                description: 'Nie można połączyć się z serwerem - nieprawidłowy identyfikator użytkownika',
                variant: 'destructive',
            });
        }
    }
});

// Set up event listeners for WebSocket game events
const setupGameEventListeners = () => {
  webSocketService.on('game_started', (data) => {
    console.log('[GameBoard] Received game_started event:', data);
    if (data.room_id === roomId.value) {
      toast({
        title: 'Gra rozpoczęta!',
        description: 'Przygotuj się do gry'
      });
    }
  });
  
  webSocketService.on('your_cards', (data) => {
    if (data.cards && Array.isArray(data.cards)) {
      mainPlayerCards.value = data.cards;
    }
  });
  
  webSocketService.on('game_state', (data) => {
    // Update game state based on the received data
    if (data.last_card) {
      deckPile.value = [data.last_card];
    }
    if (data.is_your_turn !== undefined) {
      props.isYourTurn = data.is_your_turn;
    }
    updatePlayerNames();
  });
};

const getPlayerCards = (player: Player) => {
  return player.cards.map(card => ({
    ...card,
    isMainPlayer: player.isMainPlayer
  }));
};
</script>

<template>
    <div class="game-board-container relative min-h-screen bg-green-700 text-white flex flex-col justify-between items-center p-8 overflow-hidden">
        <div class="game-table bg-green-900 border-8 border-yellow-800 rounded-full w-3/4 h-3/4 absolute top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 flex items-center justify-center">
            <div v-if="deckPile.length > 0" class="deck-pile absolute top-1/3 left-1/2 transform -translate-x-1/2 -translate-y-1/2">
                <picker-card
                    :value="deckPile[0].value"
                    :card-index="0"
                    :is-face-down="false"
                    :card-rank="deckPile[0].cardRank"
                    :card-symbol="deckPile[0].cardSymbol"
                    :symbol-color-class="deckPile[0].symbolColorClass"
                    class="!m-0 !p-0 shadow-2xl"
                    :style="{
            transform: 'rotateZ(5deg)',
            zIndex: 100
          }"
                />
                <div v-for="i in 2" :key="i"
                     class="absolute inset-0 bg-red-800 border-2 border-red-900 rounded-xl shadow-lg"
                     :style="{
            transform: `translate(${i * 2}px, ${i * 2}px) rotateZ(${5 + i * 2}deg)`,
            zIndex: 99 - i
          }">
                </div>
            </div>

            <!-- Game Action Buttons -->
            <div class="absolute bottom-[10rem] left-1/2 transform -translate-x-1/2 flex gap-4">
                <button
                    @click="handleCardPlay"
                    :disabled="selectedMainPlayerCards.length === 0"
                    :class="[
                'play-button',
                'bg-blue-600',
                'hover:bg-blue-700',
                'text-white',
                'font-extrabold',
                'py-4',
                'px-8',
                'rounded-full',
                'shadow-lg',
                'text-2xl',
                'transition-all',
                'duration-300',
                'transform',
                'hover:scale-105',
                'active:scale-95',
                { 'opacity-50 cursor-not-allowed': selectedMainPlayerCards.length === 0 }
            ]"
                >
                    Rozegraj!
                </button>

                <button
                    @click="emit('draw-card')"
                    class="bg-green-600 hover:bg-green-700 text-white font-extrabold py-4 px-8 rounded-full shadow-lg text-2xl transition-all duration-300 transform hover:scale-105 active:scale-95"
                >
                    Dobierz
                </button>

                <button
                    @click="emit('pass-turn')"
                    class="bg-yellow-600 hover:bg-yellow-700 text-white font-extrabold py-4 px-8 rounded-full shadow-lg text-2xl transition-all duration-300 transform hover:scale-105 active:scale-95"
                >
                    Pas
                </button>
            </div>

        </div>

        <div class="player-area-top absolute top-8 left-1/2 -translate-x-1/2 flex flex-col items-center">
            <player-info v-if="player3" :name="player3.name" :level="player3.level" :avatar-url="player3.avatarUrl" />
            <player-hand :cards="player3Cards" :is-facing-away="true" />
        </div>

        <div class="player-area-left absolute left-4 top-1/2 -translate-y-1/2 flex items-center">
            <player-info v-if="player2" :name="player2.name" :level="player2.level" :avatar-url="player2.avatarUrl" />
            <player-hand :cards="player2Cards" :is-facing-away="true" class="transform rotate-90" />
        </div>

        <div class="player-area-right absolute right-4 top-1/2 -translate-y-1/2 flex items-center">
            <player-hand :cards="player4Cards" :is-facing-away="true" class="transform -rotate-90" />
            <player-info v-if="player4" :name="player4.name" :level="player4.level" :avatar-url="player4.avatarUrl" />
        </div>

        <div class="main-player-hand-area absolute bottom-8 left-1/2 -translate-x-1/2 flex flex-col items-center">
            <CardSelection
                :cards="mainPlayerCards"
                :multiple="true"
                :initial-selected="[]"
                @update:selected="handleCardSelection"
                :last-played-rank-value="lastPlayedCardRankValue"
            />
        </div>

        <div class="main-player-info-corner absolute bottom-8 right-8">
            <player-info v-if="mainPlayer" :name="mainPlayer.name" :level="mainPlayer.level" :avatar-url="mainPlayer.avatarUrl" :is-main-player="true" />

            <!-- WebSocket Connection Status -->
            <div
                class="mt-2 px-2 py-1 text-xs font-semibold rounded-full flex items-center gap-1"
                :class="{
                    'bg-green-600 text-white': gameState.connected,
                    'bg-red-600 text-white': !gameState.connected,
                }"
            >
                <span class="w-2 h-2 rounded-full" :class="gameState.connected ? 'bg-green-300' : 'bg-red-300'"></span>
                {{ gameState.connected ? 'Połączono' : 'Rozłączono' }}
            </div>
        </div>

        <div v-if="selectedMainPlayerCards.length > 0" class="selected-info absolute top-2 right-2 p-4 bg-gray-800 rounded-lg shadow-xl z-20">
            <h4 class="text-lg font-bold mb-2">Wybrane Twoje karty:</h4>
            <ul class="list-disc list-inside text-sm">
                <li v-for="cardValue in selectedMainPlayerCards" :key="cardValue">
                    {{ mainPlayerCards.find(c => c.value === cardValue)?.header }}
                </li>
            </ul>
        </div>

        <!-- Room Management Controls -->
        <div class="absolute top-2 left-2 p-4 bg-gray-800 rounded-lg shadow-xl z-20 flex flex-col gap-2">
            <div v-if="!gameState.roomId" class="flex flex-col gap-2">
                <button
                    @click="webSocketService.createRoom('Nowa Gra', 4)"
                    class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded"
                >
                    Stwórz pokój
                </button>
                <button
                    @click="webSocketService.listRooms()"
                    class="bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-4 rounded"
                >
                    Pokaż dostępne pokoje
                </button>
            </div>

            <div v-else class="flex flex-col gap-2">
                <button
                    @click="handleLeaveRoom"
                    class="bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-4 rounded"
                >
                    Opuść pokój
                </button>
            </div>
        </div>

        <!-- Game Status Message -->
        <div v-if="gameMessage" class="absolute top-20 left-1/2 transform -translate-x-1/2 p-3 bg-black bg-opacity-80 text-white font-bold rounded-lg z-30">
            {{ gameMessage }}
        </div>
    </div>
</template>

<style scoped>
/* Te style pozostają bez zmian z poprzedniej wersji, aby utrzymać rozmieszczenie i odstępy */
.player-area-top,
.main-player-hand-area { /* Zmieniono nazwę klasy z main-player-area na main-player-hand-area */
    z-index: 10;
    gap: 10px; /* Odstęp między info o graczu a jego ręką */
    display: flex;
    flex-direction: column;
    align-items: center;
}

.player-area-left,
.player-area-right {
    z-index: 10;
    gap: 10px; /* Odstęp między info o graczu a jego ręką */
    display: flex;
    align-items: center;
}

/* Nowa klasa dla boxa informacyjnego gracza głównego w rogu */
.main-player-info-corner {
    z-index: 20; /* Upewnij się, że jest na wierzchu */
}
</style>
