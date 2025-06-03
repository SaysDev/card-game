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
    // Track player ready status
    playerReady: boolean;
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
    private userId: string | number | null = null;
    private username: string | null = null;
    private authToken: string | null = null;

    constructor(url: string = '') {
        this.url = url || `ws://${window.location.hostname}:9502`;
        // Setup event listeners for player status updates
        this.setupPlayerStatusListener();
    }

    /**
     * Setup listeners for player status updates
     */

    /**
     * Connect to the WebSocket server
     */
            public connect(userId: number | string, username: string, token: string): void {
        // Try to use a more reliable user ID source if available
        try {
            // @ts-ignore - Inertia might not be available in all contexts
            if (window.Inertia && window.Inertia.page && window.Inertia.page.props.auth && window.Inertia.page.props.auth.user) {
                // @ts-ignore
                const inertiaUserId = window.Inertia.page.props.auth.user.id;
                if (inertiaUserId) {
                    console.log('Using user ID from Inertia page props in connect method:', inertiaUserId);
                    userId = inertiaUserId;
                    // @ts-ignore
                    username = window.Inertia.page.props.auth.user.name || username;
                }
            }
        } catch (error) {
            console.warn('Could not access Inertia page props in connect method:', error);
        }

        // Validate userId to ensure it's not 0, undefined, null, or an empty string
        if (!userId || userId === 0 || userId === '0') {
            console.error('Invalid user ID provided:', userId);
            this.emit('auth_error', { message: 'Invalid user ID provided' });
            return;
        }

        // Use the explicitly provided userId - don't try to override it
        // This ensures we use the ID passed from the calling component
        this.userId = userId;
        this.username = username || `User_${userId}`;
        this.authToken = token;

        // Log the user ID we're using
        console.log('Connecting to WebSocket with user ID:', this.userId, 'Type:', typeof this.userId);

        if (this.ws && this.ws.readyState === WebSocket.OPEN) {
            console.log('WebSocket is already connected - re-authenticating with new credentials');
            this.authenticate();
            return;
        }

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
     * Changed to public to allow manual authentication after connection
     */
    public authenticate(): void {
        try {
            // Try to get user ID from Inertia directly (more reliable method)
            const usePage = () => {
                // @ts-ignore - Inertia might not be available in all contexts
                if (window.Inertia && window.Inertia.page) {
                    // @ts-ignore
                    return window.Inertia.page;
                }
                return { props: { auth: { user: null } } };
            };

            const page = usePage();
            if (page.props.auth && page.props.auth.user && page.props.auth.user.id) {
                this.userId = page.props.auth.user.id;
                this.username = page.props.auth.user.name || `User_${this.userId}`;
                console.log('Using user ID from Inertia page props:', this.userId);
            }
        } catch (error) {
            console.warn('Could not access Inertia page props:', error);
        }

        if (!this.userId || !this.username || !this.authToken) {
            console.error('Missing authentication information');
            this.emit('auth_error', { message: 'Missing authentication information' });
            return;
        }

        // Don't provide a default user ID - it must be explicitly provided
        // This prevents unexpected authentication with wrong user IDs
        if (!this.userId) {
            console.error('No valid user ID for authentication');
            this.emit('auth_error', { message: 'No valid user ID for authentication' });
            return;
        }

        // Use the directly provided user ID and username
        // This is more reliable than trying to access global auth objects
        console.log(`Authenticating user ${this.username} (${this.userId})`);
        this.send({
            action: 'authenticate',
            user_id: String(this.userId), // Always send as string
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
     * Create a new game room
     */
    public createRoom(roomName: string, maxPlayers: number = 4): void {
        if (!gameState.isAuthenticated) {
            console.error('Cannot create room: Not authenticated');
            return;
        }

        this.send({
            action: 'create_room',
            room_name: roomName,
            max_players: maxPlayers
        });
    }

    /**
     * Join a game room by ID
     */
    public joinRoom(roomId: string): void {
        if (!gameState.isAuthenticated) {
            console.error('Cannot join room: Not authenticated');
            return;
        }

        this.send({
            action: 'join_room',
            room_id: roomId
        });
    }

    /**
     * Leave the current room
     */
    public leaveRoom(): void {
        if (!gameState.roomId) {
            console.error('Cannot leave room: Not in a room');
            return;
        }

        this.send({
            action: 'leave_room'
        });
    }

    /**
     * List available game rooms
     */
    public listRooms(): void {
        if (!gameState.isAuthenticated) {
            console.error('Cannot list rooms: Not authenticated');
            return;
        }

        this.send({
            action: 'list_rooms'
        });
    }

    /**
     * Set player ready status
     */
    public setReadyStatus(ready: boolean): void {
        if (!gameState.roomId) {
            console.error('Cannot set ready status: Not in a room');
            return;
        }

        this.send({
            action: 'set_ready_status',
            ready: ready,
            room_id: gameState.roomId
        });
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
     * Check if WebSocket is connected and authenticated
     */
    public isConnectedAndReady(): boolean {
        return this.ws !== null &&
               this.ws.readyState === WebSocket.OPEN &&
               gameState.isAuthenticated;
    }

    /**
     * Get current connection status information
     */
    public getConnectionStatus(): object {
        return {
            hasWebSocket: this.ws !== null,
            readyState: this.ws ? this.ws.readyState : null,
            isAuthenticated: gameState.isAuthenticated,
            connected: gameState.connected,
            roomId: gameState.roomId
        };
    }

    /**
     * Check if WebSocket is connected
     */
    public isConnected(): boolean {
        return this.ws !== null && this.ws.readyState === WebSocket.OPEN;
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
     * Play a card
     */
    public playCard(cardIndex: number): void {
        if (!gameState.roomId) {
            console.error('Cannot play card: Not in a room');
            return;
        }

        this.send({
            action: 'play_card',
            card_index: cardIndex
        });
    }

    /**
     * Start the game (only room creator can do this)
     */
    startGame(): void {
        if (!this.socket || this.socket.readyState !== WebSocket.OPEN) {
            console.error('Cannot start game: WebSocket is not connected');
            return;
        }

        if (!gameState.isRoomCreator) {
            console.error('Cannot start game: User is not the room creator');
            return;
        }

        this.socket.send(JSON.stringify({
            action: 'start_game',
            room_id: gameState.roomId
        }));
    }

    /**
     * Draw a card from the deck
     */
    public drawCard(): void {
        if (!gameState.roomId) {
            console.error('Cannot draw card: Not in a room');
            return;
        }

        this.send({
            action: 'draw_card'
        });
    }

    /**
     * Pass the turn
     */
    public passTurn(): void {
        if (!gameState.roomId) {
            console.error('Cannot pass turn: Not in a room');
            return;
        }

        this.send({
            action: 'pass_turn'
        });
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
                    // Parse the message to ensure we have access to the user_id
                    const parsedMessage = typeof message === 'string' ? JSON.parse(message) : message;
                    console.log('Received auth_success with data:', JSON.stringify(parsedMessage));

                    // Always use the user ID returned by the server - this is the authoritative source
                    if (parsedMessage && typeof parsedMessage === 'object') {
                        // The server sends user_id directly in the response object
                        if (parsedMessage.user_id !== undefined) {
                            // Make sure to convert to number for consistent handling
                            this.userId = parseInt(String(parsedMessage.user_id), 10);
                            console.log(`Using authoritative user ID from server: ${this.userId} (converted to number)`);
                        }
                    }

                    gameState.connectionId = this.userId ? String(this.userId) : null;
                    gameState.isAuthenticated = true;
                    console.log('Authentication successful with user ID:', this.userId, 'Type:', typeof this.userId);
                    this.emit('authenticated', parsedMessage);
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
                    gameState.isPlayer = false; // Reset isPlayer state
                    gameState.isRoomCreator = false; // Reset isRoomCreator state
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

    // Game action methods - implementations are already provided above
    // The methods below are enhanced versions of the ones defined earlier

    /**
     * Enhanced version of joinRoom with additional checks and user info
     * @param roomId The ID of the room to join
     */
    public joinRoomWithUserInfo(roomId: string): void {
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

    // Note: isConnectedAndReady is already defined above

    // Note: getConnectionStatus is already defined above

    /**
     * Play a card from your hand - enhanced version with turn checking
     */
    public playCardInTurn(cardIndex: number): void {
        if (!gameState.isYourTurn) {
            console.warn('Cannot play card: Not your turn');
            this.emit('server_error', { message: 'Nie możesz zagrać karty: To nie jest twoja tura' });
            return;
        }

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

    // Note: setReadyStatus is already defined above

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
