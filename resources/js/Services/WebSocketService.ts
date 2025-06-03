import { reactive } from 'vue';

interface GameState {
    connected: boolean;
    connectionId: string | null;
    roomId: string | null;
    roomName: string | null;
    players: Player[];
    currentTurn: number;
    currentPlayerId: number | null;
    hand: Card[];
    playArea: Card[];
    lastCard: Card | null;
    deckCount: number;
    status: 'waiting' | 'playing' | 'ended';
    winner: Player | null;
    isYourTurn: boolean;
    // Track if the user is authenticated
    isAuthenticated: boolean;
}

interface Player {
    user_id: number;
    username: string;
    cards_count?: number;
    score?: number;
    is_current?: boolean;
}

interface Card {
    suit: 'hearts' | 'diamonds' | 'clubs' | 'spades';
    value: string;
}

interface GameAction {
    action: string;
    [key: string]: any;
}

// Create a reactive game state
export const gameState = reactive<GameState>({
    connected: false,
    connectionId: null,
    roomId: null,
    roomName: null,
    players: [],
    currentTurn: -1,
    currentPlayerId: null,
    hand: [],
    playArea: [],
    lastCard: null,
    deckCount: 0,
    status: 'waiting',
    winner: null,
    isYourTurn: false,
    isAuthenticated: false,
    playerReady: false
});

export class WebSocketService {
    private ws: WebSocket | null = null;
    private reconnectAttempts: number = 0;
    private maxReconnectAttempts: number = 5;
    private reconnectTimeout: number = 1000; // Start with 1s, will increase
    private eventListeners: Map<string, Function[]> = new Map();
    private url: string;
    private userId: number | null = null;
    private username: string | null = null;
    private authToken: string | null = null;

    constructor(url: string = '') {
        this.url = url || `ws://${window.location.hostname}:9502`;
        // Setup event listeners for player status updates
        this.setupPlayerStatusListener();
    }

    /**
     * Connect to the WebSocket server
     */
    public connect(userId: number, username: string, token: string): void {
        console.log('Connecting to WebSocket server...');
        // Even if already connected, update credentials in case the user changed
        this.userId = userId;
        this.username = username;
        this.authToken = token;

        if (this.ws && this.ws.readyState === WebSocket.OPEN) {
            console.log('WebSocket is already connected - re-authenticating with new credentials');
            this.authenticate();
            return;
        }

        this.userId = userId;
        this.username = username;
        this.authToken = token;

        try {
            this.ws = new WebSocket(this.url);

            this.ws.onopen = () => {
                console.log('WebSocket connection established');
                gameState.connected = true;
                this.reconnectAttempts = 0;
                this.reconnectTimeout = 1000;
                this.emit('connected');

                // Authenticate after connection
                this.authenticate();
            };

            this.ws.onmessage = (event) => {
                this.handleMessage(event.data);
            };

            this.ws.onclose = () => {
                console.log('WebSocket connection closed');
                gameState.connected = false;
                this.emit('disconnected');
                this.attemptReconnect();
            };

            this.ws.onerror = (error) => {
                console.error('WebSocket error:', error);
                this.emit('error', error);
            };
        } catch (error) {
            console.error('Failed to create WebSocket connection:', error);
            this.emit('error', error);
        }
    }

    /**
     * Authenticate with the server
     */
    private authenticate(): void {
        if (!this.userId || !this.username || !this.authToken) {
            console.error('Missing authentication information');
            this.emit('auth_error', { message: 'Missing authentication information' });
            return;
        }

        console.log(`Authenticating user ${this.username} (${this.userId})`);
        this.send({
            action: 'authenticate',
            user_id: this.userId.toString(), // Ensure user_id is sent as string
            username: this.username,
            token: this.authToken
        });
    }

    /**
     * Attempt to reconnect to the server
     */
    private attemptReconnect(): void {
        if (this.reconnectAttempts >= this.maxReconnectAttempts) {
            console.log('Maximum reconnect attempts reached');
            this.emit('reconnect_failed');
            return;
        }

        this.reconnectAttempts++;
        console.log(`Attempting to reconnect (${this.reconnectAttempts}/${this.maxReconnectAttempts}) in ${this.reconnectTimeout / 1000}s...`);

        // Store current room info before reconnecting
        const currentRoomId = gameState.roomId;
        const currentRoomName = gameState.roomName;

        setTimeout(() => {
            if (this.userId && this.username && this.authToken) {
                this.connect(this.userId, this.username, this.authToken);

                // If we were in a room before, try to rejoin after authentication
                if (currentRoomId) {
                    // Wait for authentication before trying to rejoin
                    const authHandler = () => {
                        console.log(`Attempting to rejoin room ${currentRoomId} after reconnection`);
                        this.joinRoom(currentRoomId);
                        this.off('authenticated', authHandler);
                    };

                    this.on('authenticated', authHandler);
                }
            }
        }, this.reconnectTimeout);

        // Exponential backoff
        this.reconnectTimeout = Math.min(this.reconnectTimeout * 2, 30000); // Max 30s
    }

    /**
     * Close the WebSocket connection
     */
    public disconnect(): void {
        if (this.ws) {
            this.ws.close();
            this.ws = null;
        }

        // Reset game state
        gameState.connected = false;
        gameState.connectionId = null;
        gameState.roomId = null;
        gameState.roomName = null;
        gameState.players = [];
        gameState.hand = [];
        gameState.status = 'waiting';
        gameState.isAuthenticated = false;
    }

    /**
     * Send data to the server
     */
    public send(data: GameAction): void {
        if (!this.ws) {
            console.error('WebSocket is null');
            this.emit('server_error', { message: 'Błąd połączenia: Brak inicjalizacji WebSocket' });
            return;
        }

        if (this.ws.readyState !== WebSocket.OPEN) {
            console.error(`WebSocket is not open, current state: ${this.ws.readyState}`);
            this.emit('server_error', { message: 'Błąd połączenia: WebSocket nie jest gotowy do wysyłania' });
            return;
        }

        try {
            this.ws.send(JSON.stringify(data));
        } catch (error) {
            console.error('Error sending message:', error);
            this.emit('server_error', { message: 'Błąd wysyłania wiadomości do serwera' });
        }
    }

    /**
     * Register event listener
     */
    public on(event: string, callback: Function): void {
        if (!this.eventListeners.has(event)) {
            this.eventListeners.set(event, []);
        }

        this.eventListeners.get(event)?.push(callback);
    }

    /**
     * Remove event listener
     */
    public off(event: string, callback: Function): void {
        if (this.eventListeners.has(event)) {
            const listeners = this.eventListeners.get(event);
            if (listeners) {
                const index = listeners.indexOf(callback);
                if (index !== -1) {
                    listeners.splice(index, 1);
                }
            }
        }
    }

    /**
     * Trigger event listeners
     */
    private emit(event: string, ...args: any[]): void {
        if (this.eventListeners.has(event)) {
            this.eventListeners.get(event)?.forEach(callback => {
                callback(...args);
            });
        }
    }

    /**
     * Handle incoming messages
     */
    private handleMessage(data: string): void {
        try {
            const message = JSON.parse(data);
            console.log('Received message:', message.type);

            // Update game state based on message type
            switch (message.type) {
                case 'connection':
                    // Connection established message
                    break;

                case 'auth_success':
                    // Store user ID from server response, which could be different from client-side ID
                    this.userId = message.user_id;
                    gameState.connectionId = message.user_id.toString();
                    gameState.isAuthenticated = true;
                    console.log('Authentication successful. User ID from server:', message.user_id);
                    this.emit('authenticated', message);
                    break;

                case 'room_created':
                    gameState.roomId = message.room_id;
                    gameState.roomName = message.room_name;
                    gameState.status = 'waiting';
                    this.emit('room_created', message);
                    break;

                case 'room_joined':
                    gameState.roomId = message.room_id;
                    gameState.roomName = message.room_name;
                    // Make sure player data is normalized
                    if (message.players && Array.isArray(message.players)) {
                        // Filter out duplicate players (same user_id) and normalize properties
                        const uniquePlayers = {};
                        message.players.forEach(player => {
                            const userId = player.user_id || player.id;
                            // Only keep the first instance of each user_id
                            if (!uniquePlayers[userId]) {
                                uniquePlayers[userId] = {
                                    user_id: userId,
                                    username: player.username || player.name || 'Gracz',
                                    status: player.status || 'waiting',
                                    score: player.score || 0,
                                    cards_count: player.cards_count || 0,
                                    ready: player.ready || false
                                };
                            }
                        });

                        // Convert the object of unique players back to an array
                        gameState.players = Object.values(uniquePlayers);
                        console.log('Room joined, normalized players (unique by user_id):', gameState.players);
                    } else {
                        gameState.players = [];
                    }
                    gameState.status = 'waiting';
                    this.emit('room_joined', message);
                    break;

                case 'player_joined':
                    // Add the new player to the players array with proper field mapping
                    const newPlayer = {
                        user_id: message.player.user_id || message.player.id,
                        username: message.player.username || message.player.name || 'Gracz',
                        status: message.player.status || 'waiting',
                        score: message.player.score || 0,
                        cards_count: message.player.cards_count || 0,
                        ready: message.player.ready || false
                    };

                    // Check if player with this user_id already exists in gameState.players
                    const existingPlayerIndex = gameState.players.findIndex(p => p.user_id === newPlayer.user_id);
                    if (existingPlayerIndex !== -1) {
                        // Update existing player instead of adding a duplicate
                        gameState.players[existingPlayerIndex] = newPlayer;
                        console.log('Updated existing player in gameState.players:', newPlayer);
                    } else {
                        // Add new player if they don't already exist
                        gameState.players.push(newPlayer);
                        console.log('Added new player to gameState.players:', newPlayer);
                    }

                    console.log('Player joined, updated players array:', gameState.players);
                    this.emit('player_joined', message);
                    break;

                case 'player_left':
                    // Remove player from the array
                    gameState.players = gameState.players.filter(
                        player => player.user_id !== message.user_id
                    );
                    this.emit('player_left', message);
                    break;

                case 'left_room':
                    // We've left the room
                    gameState.roomId = null;
                    gameState.roomName = null;
                    gameState.players = [];
                    gameState.hand = [];
                    gameState.status = 'waiting';
                    this.emit('left_room', message);
                    break;

                                    case 'ready_status_updated':
                    // Our own ready status was updated
                    gameState.playerReady = message.ready;
                    this.emit('ready_status_updated', message);
                    break;

                case 'game_started':
                    gameState.status = 'playing';
                    gameState.players = message.players;
                    gameState.currentTurn = message.current_player_index;
                    gameState.currentPlayerId = message.current_player?.user_id || null;
                    gameState.deckCount = message.deck_remaining;
                    gameState.isYourTurn = message.current_player?.user_id === this.userId;
                    this.emit('game_started', message);
                    break;

                case 'your_cards':
                    gameState.hand = message.cards;
                    this.emit('cards_updated', message);
                    break;

                case 'card_played':
                    // Update last card played
                    gameState.lastCard = message.card;
                    gameState.playArea = [...gameState.playArea, message.card];

                    // Update player's cards count
                    const playerIndex = gameState.players.findIndex(p => p.user_id === message.player_id);
                    if (playerIndex !== -1) {
                        gameState.players[playerIndex].cards_count = message.remaining_cards;
                    }

                    this.emit('card_played', message);
                    break;

                case 'card_drawn':
                    gameState.hand = message.hand;
                    gameState.deckCount--;
                    this.emit('card_drawn', message);
                    break;

                case 'player_drew_card':
                    // Update player's cards count
                    const drawingPlayerIndex = gameState.players.findIndex(p => p.user_id === message.player_id);
                    if (drawingPlayerIndex !== -1) {
                        gameState.players[drawingPlayerIndex].cards_count = message.cards_count;
                    }
                    gameState.deckCount = message.deck_remaining;
                    this.emit('player_drew_card', message);
                    break;

                case 'turn_changed':
                    gameState.currentTurn = message.turn_index;
                    gameState.currentPlayerId = message.current_player_id;
                    gameState.isYourTurn = message.current_player_id === this.userId;
                    this.emit('turn_changed', message);
                    break;

                case 'game_over':
                    gameState.status = 'ended';
                    gameState.winner = message.winner;
                    gameState.isYourTurn = false;
                    this.emit('game_over', message);
                    break;

                case 'error':
                    console.error('Server error:', message.message, message);

                    // Check for specific error types and handle accordingly
                    if (message.message.includes('Room not found')) {
                        // Reset room state as we couldn't join
                        gameState.roomId = null;
                        gameState.roomName = null;
                        gameState.players = [];

                        // Get the attempted room ID from the last sent message if available
                        let attemptedRoomId = 'unknown';
                        try {
                            // Attempt to extract room_id from the error context if available
                            if (message.context && message.context.room_id) {
                                attemptedRoomId = message.context.room_id;
                            }
                        } catch (e) {
                            console.error('Error extracting room ID from error message:', e);
                        }

                        // Emit a specific room_not_found event
                        this.emit('room_not_found', {
                            message: `The requested game room does not exist: ${attemptedRoomId}`,
                            original: message,
                            roomId: attemptedRoomId
                        });

                        console.warn(`Attempted to join a non-existent room: ${attemptedRoomId}`);
                    } else if (message.message.includes('Room is full')) {
                        this.emit('room_full', message);
                    } else if (message.message.includes('Cannot join a game')) {
                        this.emit('game_already_started', message);
                    }

                    // Always emit the general server_error event

                    this.emit('server_error', message);
                    break;

                default:
                    console.log('Unknown message type:', message.type);
            }
        } catch (error) {
            console.error('Error parsing message:', error, data);
        }
    }

    // Game action methods

    /**
     * Create a new game room
     */
    public createRoom(roomName: string, maxPlayers: number): void {
        this.send({
            action: 'create_room',
            room_name: roomName,
            max_players: maxPlayers
        });
    }

    /**
     * Join an existing game room
     */
    public joinRoom(roomId: string): void {
        if (!this.ws || this.ws.readyState !== WebSocket.OPEN) {
            console.error('Cannot join room: WebSocket is not connected');
            this.emit('error', { message: 'Cannot join room: WebSocket is not connected' });
            // Also emit a more specific event that the UI can handle
            this.emit('server_error', { message: 'Nie można dołączyć do pokoju: Brak połączenia z serwerem WebSocket' });
            return;
        }

        if (!this.userId || !this.username) {
            console.error('Cannot join room: User not authenticated');
            this.emit('error', { message: 'Cannot join room: User not authenticated' });
            return;
        }

        console.log(`Attempting to join room: ${roomId} as user ${this.username} (${this.userId})`);
        this.send({
            action: 'join_room',
            room_id: roomId,
            // Adding explicit user info to ensure server can identify the player
            user_id: this.userId,
            username: this.username
        });
    }

    /**
     * Leave the current game room
     */
    public leaveRoom(): void {
        if (!gameState.roomId) return;

        this.send({
            action: 'leave_room'
        });
    }

    /**
     * List available game rooms
     */
    public listRooms(): void {
        this.send({
            action: 'list_rooms'
        });
    }

    /**
     * Check if WebSocket is connected and ready
     */
    public isConnectedAndReady(): boolean {
        return !!this.ws && this.ws.readyState === WebSocket.OPEN;
    }

    /**
     * Get WebSocket connection status details
     */
    public getConnectionStatus(): { connected: boolean, readyState: number | null, authenticated: boolean } {
        return {
            connected: !!this.ws,
            readyState: this.ws ? this.ws.readyState : null,
            authenticated: gameState.isAuthenticated
        };
    }

    /**
     * Play a card from your hand
     */
    public playCard(cardIndex: number): void {
        if (!gameState.isYourTurn) return;

        if (!this.ws || this.ws.readyState !== WebSocket.OPEN) {
            console.error('Cannot play card: WebSocket is not connected');
            return;
        }

        this.send({
            action: 'game_action',
            action_type: 'play_card',
            card_index: cardIndex
        });
    }

    /**
     * Draw a card from the deck
     */
    public drawCard(): void {
        if (!gameState.isYourTurn) return;

        if (!this.ws || this.ws.readyState !== WebSocket.OPEN) {
            console.error('Cannot draw card: WebSocket is not connected');
            return;
        }

        this.send({
            action: 'game_action',
            action_type: 'draw_card'
        });
    }

    /**
     * Pass your turn
     */
    public passTurn(): void {
        if (!this.ws || this.ws.readyState !== WebSocket.OPEN) {
            console.error('Cannot pass turn: WebSocket is not connected');
            return;
        }

        if (!gameState.isYourTurn) return;

        this.send({
            action: 'game_action',
            action_type: 'pass_turn'
        });
    }

    /**
     * Set player ready status
     */
    public setReadyStatus(isReady: boolean): void {
        if (!this.ws || this.ws.readyState !== WebSocket.OPEN) {
            console.error('Cannot set ready status: WebSocket is not connected');
            return;
        }

        if (!gameState.roomId) {
            console.error('Cannot set ready status: Not in a room');
            return;
        }

        this.send({
            action: 'set_ready_status',
            ready: isReady,
            room_id: gameState.roomId // Add room_id to the request
        });

        // Don't update local state immediately, wait for server confirmation
        // The state will be updated when we receive the 'ready_status_updated' event
    }

    // Listen for player status updates
    private setupPlayerStatusListener(): void {
        // Handle player status changed events (for other players)
        this.on('player_status_changed', (data) => {
            console.log('Player status changed:', data);
            if (gameState.players) {
                const player = gameState.players.find(p => p.user_id === data.player_id);
                if (player) {
                    player.status = data.status;
                    player.ready = data.ready;
                }
            }
        });

        // Handle own ready status update confirmation
        this.on('ready_status_updated', (data) => {
            console.log('Your ready status updated:', data);
            gameState.playerReady = data.ready;
        });

    }
}

// Create a singleton instance
export default new WebSocketService();
