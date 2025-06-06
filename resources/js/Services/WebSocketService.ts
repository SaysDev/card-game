import { usePage } from '@inertiajs/vue3';
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
    status: 'waiting' | 'playing' | 'ended' | 'creating';
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
    status?: string; // 'ready' | 'waiting' | 'playing' | 'disconnected'
    ready?: boolean;
}

interface Card {
    suit: 'hearts' | 'diamonds' | 'clubs' | 'spades';
    value: string;
}

interface GameAction {
    type: string;
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
    private reconnectTimeout: number = 1000;
    private eventListeners: Map<string, Function[]> = new Map();
    private url: string;
    private userId: string | number | null = null;
    private username: string | null = null;
    private authToken: string | null = null;
    private heartbeatInterval: number | null = null;
    private isAuthenticating: boolean = false;

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
    public connect(userId: number | string | null = null, username: string | null = null, token: string | null = null): void {
        const page = usePage();
        // Jeśli argumenty nie są przekazane, pobierz z usePage
        if (!userId) userId = (page.props as any)?.auth?.user?.id;
        if (!username) username = (page.props as any)?.auth?.user?.name;
        if (!token) token = (page.props as any)?.auth?.user?.ws_token;
        if (!userId || userId === 0 || userId === '0') {
            console.error('[WebSocket] Invalid user ID provided:', userId);
            this.emit('auth_error', { message: 'Invalid user ID provided' });
            return;
        }
        this.userId = userId;
        this.username = username || `User_${userId}`;
        this.authToken = token;
        console.log('[WebSocket] Connecting to WebSocket with user ID:', this.userId, 'Type:', typeof this.userId);
        if (this.ws && this.ws.readyState === WebSocket.OPEN) {
            console.log('[WebSocket] WebSocket is already connected - re-authenticating with new credentials');
            this.authenticate();
            return;
        }
        try {
            this.ws = new WebSocket(this.url);
            this.ws.onopen = () => {
                console.log('[WebSocket] Connection established');
                gameState.connected = true;
                this.reconnectAttempts = 0;
                this.reconnectTimeout = 1000;
                this.emit('connected');
                this.authenticate();
                this.startHeartbeat();
            };
            this.ws.onmessage = (event) => {
                this.handleMessage(event.data);
            };
            this.ws.onclose = () => {
                console.log('[WebSocket] Connection closed');
                gameState.connected = false;
                this.stopHeartbeat();
                this.emit('disconnected');
                this.attemptReconnect();
            };
            this.ws.onerror = (error) => {
                console.error('[WebSocket] Error:', error);
                this.emit('error', error);
            };
        } catch (error) {
            console.error('[WebSocket] Failed to create connection:', error);
            this.emit('error', error);
        }
    }

    /**
     * Authenticate with the server
     * Changed to public to allow manual authentication after connection
     */
    public authenticate(): void {
        if (this.isAuthenticating) {
            console.log('[WebSocket] Already authenticating, skipping...');
            return;
        }

        this.isAuthenticating = true;
        const page = usePage();
        console.log('[WebSocket] Page props:', page.props);
        
        // Get fresh auth data from page props
        const authData = (page.props as any)?.auth?.user;
        if (!authData) {
            console.error('[WebSocket] No auth data available in page props');
            this.emit('auth_error', { message: 'No auth data available' });
            this.isAuthenticating = false;
            return;
        }

        this.userId = authData.id;
        this.username = authData.name;
        this.authToken = authData.ws_token;

        console.log('[WebSocket] Authenticating with data:', {
            userId: this.userId,
            username: this.username,
            hasToken: !!this.authToken
        });

        if (!this.userId || !this.username || !this.authToken) {
            console.error('[WebSocket] Missing authentication information:', {
                userId: this.userId,
                username: this.username,
                hasToken: !!this.authToken
            });
            this.emit('auth_error', { message: 'Missing authentication information' });
            this.isAuthenticating = false;
            return;
        }

        if (!this.ws || this.ws.readyState !== WebSocket.OPEN) {
            console.error('[WebSocket] Not connected, cannot authenticate');
            this.emit('auth_error', { message: 'WebSocket not connected' });
            this.isAuthenticating = false;
            return;
        }

        console.log(`[WebSocket] Authenticating user ${this.username} (${this.userId})`);
        this.send({
            type: 'authenticate',
            user_id: this.userId,
            username: this.username,
            token: this.authToken
        });
    }

    /**
     * Attempt to reconnect to the server
     */
    private attemptReconnect(): void {
        if (this.reconnectAttempts >= this.maxReconnectAttempts) {
            console.log('[WebSocket] Maximum reconnect attempts reached');
            this.emit('reconnect_failed');
            return;
        }
        this.reconnectAttempts++;
        console.log(`[WebSocket] Attempting to reconnect (${this.reconnectAttempts}/${this.maxReconnectAttempts}) in ${this.reconnectTimeout / 1000}s...`);
        const currentRoomId = gameState.roomId;
        const currentRoomName = gameState.roomName;
        setTimeout(() => {
            if (this.userId && this.username && this.authToken) {
                this.connect(this.userId, this.username, this.authToken);
                if (currentRoomId) {
                    const authHandler = () => {
                        console.log(`[WebSocket] Attempting to rejoin room ${currentRoomId} after reconnection`);
                        this.joinRoom(currentRoomId);
                        this.off('authenticated', authHandler);
                    };
                    this.on('authenticated', authHandler);
                }
            }
        }, this.reconnectTimeout);
        this.reconnectTimeout = Math.min(this.reconnectTimeout * 2, 30000);
    }

    /**
     * Close the WebSocket connection
     */
    public disconnect(): void {
        this.stopHeartbeat();
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
            type: 'create_room',
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
            type: 'join_room',
            room_id: roomId
        });
    }

    /**
     * Leave the current room
     */
    public leaveRoom(): void {
        if (!gameState.roomId) return;
        
        // Make sure we have the user ID
        if (!this.userId) {
            console.error('[WebSocket] Cannot leave room: No user ID');
            return;
        }
        
        this.send({ 
            type: 'leave_room', 
            room_id: gameState.roomId,
            player_id: this.userId
        });
        
        gameState.roomId = null;
        gameState.roomName = null;
        gameState.players = [];
        gameState.status = 'waiting';
        gameState.hand = [];
        gameState.isYourTurn = false;
        gameState.playerReady = false;
        this.emit('left_room');
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
            type: 'list_rooms'
        });
    }

    /**
     * Set player ready status
     */
    public setReadyStatus(ready: boolean): void {
        if (!gameState.roomId) {
            console.error('[WebSocket] Cannot set ready status: Not in a room');
            return;
        }
        
        console.log(`[WebSocket] Setting ready status to ${ready ? 'ready' : 'not ready'}`);

        this.send({
            type: 'player_ready',
            room_id: gameState.roomId,
            ready: ready
        });
        
        // Optimistically update local state
        const myUserId = this.userId;
        if (gameState.players && myUserId) {
            const myPlayer = gameState.players.find(p => p.user_id === myUserId);
            if (myPlayer) {
                myPlayer.status = ready ? 'ready' : 'not_ready';
                myPlayer.ready = ready;
            }
        }
    }

    /**
     * Send data to the server
     */
    public send(data: GameAction): void {
        if (!this.ws) {
            console.error('[WebSocket] WebSocket is null');
            this.emit('server_error', { message: 'Błąd połączenia: Brak inicjalizacji WebSocket' });
            return;
        }

        if (this.ws.readyState !== WebSocket.OPEN) {
            console.error(`[WebSocket] WebSocket is not open, current state: ${this.ws.readyState}`);
            this.emit('server_error', { message: 'Błąd połączenia: WebSocket nie jest gotowy do wysyłania' });
            return;
        }

        try {
            this.ws.send(JSON.stringify(data));
        } catch (error) {
            console.error('[WebSocket] Error sending message:', error);
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
            type: 'game_action',
            action_type: 'play_card',
            card_index: cardIndex
        });
    }

    /**
     * Start the game (only room creator can do this)
     */
    startGame(): void {
        if (!this.ws || this.ws.readyState !== WebSocket.OPEN) {
            console.error('Cannot start game: WebSocket is not connected');
            return;
        }
        this.ws.send(JSON.stringify({
            type: 'start_game',
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
            type: 'game_action',
            action_type: 'draw_card'
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
            type: 'game_action',
            action_type: 'pass_turn'
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
                    console.log('Received auth_success with data:', message);
                    gameState.isAuthenticated = true;
                    this.isAuthenticating = false;
                    // Update user info from auth response
                    if (message.user) {
                        this.userId = message.user.id;
                        this.username = message.user.username;
                    }
                    this.emit('authenticated', message);
                    break;

                case 'auth_error':
                    console.error('Authentication error:', message);
                    gameState.isAuthenticated = false;
                    this.isAuthenticating = false;
                    this.emit('auth_error', message);
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
                    if (message.players && Array.isArray(message.players)) {
                        // Filter out duplicate players (same user_id) and normalize properties
                        const uniquePlayers: Record<string, Player> = {};
                        message.players.forEach((player: any) => {
                            const userId = player.user_id || player.id;
                            if (!uniquePlayers[userId]) {
                                uniquePlayers[userId] = {
                                    user_id: userId,
                                    username: player.username || player.name || 'Gracz',
                                    status: player.status || 'not_ready',
                                    ready: player.status === 'ready',
                                    score: player.score || 0,
                                    cards_count: player.cards_count || 0
                                };
                            }
                        });
                        gameState.players = Object.values(uniquePlayers);
                        console.log('Room joined, normalized players (unique by user_id):', gameState.players);
                    } else {
                        gameState.players = [];
                    }
                    gameState.status = 'waiting';
                    this.emit('room_joined', message);
                    break;

                case 'creating_room':
                    console.log('Room is being created:', message);
                    // Store room ID but mark status as creating
                    gameState.roomId = message.room_id;
                    gameState.status = 'creating';
                    // Let the UI know that the room is being created
                    this.emit('creating_room', message);
                    break;

                case 'player_joined':
                    // Add the new player to the players array with proper field mapping
                    const newPlayer = {
                        user_id: message.player.user_id || message.player.id,
                        username: message.player.username || message.player.name || 'Gracz',
                        status: message.player.status || 'not_ready',
                        score: message.player.score || 0,
                        cards_count: message.player.cards_count || 0,
                        ready: message.player.ready || message.player.status === 'ready'
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

                case 'game_start':
                    gameState.status = 'playing';
                    gameState.players = message.players;
                    gameState.currentTurn = message.current_player_index;
                    gameState.currentPlayerId = message.current_player?.user_id || null;
                    gameState.deckCount = message.deck_remaining;
                    gameState.isYourTurn = message.current_player?.user_id === this.userId;
                    this.emit('game_start', message);
                    break;

                case 'your_cards':
                    gameState.hand = message.cards;
                    this.emit('cards_updated', message);
                    break;

                case 'game_action':
                    this.handleGameAction(message);
                    break;

                case 'heartbeat_ack':
                    // Just acknowledge the heartbeat
                    break;

                case 'game_ended':
                    gameState.status = 'ended';
                    gameState.winner = message.winner;
                    gameState.isYourTurn = false;
                    this.emit('game_ended', message);
                    break;

                case 'error':
                    console.error('Server error:', message);
                    if (message.message === 'Authentication required') {
                        gameState.isAuthenticated = false;
                        // Try to re-authenticate only if not already authenticating
                        if (!this.isAuthenticating) {
                            console.log('Authentication required, attempting to re-authenticate...');
                            this.authenticate();
                        } else {
                            console.log('Already authenticating, skipping re-authentication...');
                        }
                    }
                    this.emit('error', message);
                    break;

                case 'player_status_changed':
                    this.emit('player_status_changed', message);
                    break;

                case 'online_count':
                    this.emit('online_count', message.count);
                    break;

                case 'room_full':
                    this.emit('room_full', message);
                    break;

                case 'room_not_found':
                    this.emit('room_not_found', message);
                    break;

                case 'server_error':
                    this.emit('server_error', message);
                    break;

                default:
                    console.log('Unknown message type:', message.type);
            }
        } catch (error) {
            console.error('Error parsing message:', error, data);
        }
    }

    private handleGameAction(message: any): void {
        switch (message.action) {
            case 'card_played':
                gameState.playArea = message.play_area;
                gameState.lastCard = message.last_card;
                gameState.currentPlayerId = message.next_player_id;
                gameState.isYourTurn = message.next_player_id === this.userId;
                this.emit('card_played', message);
                break;

            case 'card_drawn':
                gameState.hand = message.hand;
                gameState.deckCount = message.deck_count;
                gameState.currentPlayerId = message.next_player_id;
                gameState.isYourTurn = message.next_player_id === this.userId;
                this.emit('card_drawn', message);
                break;

            case 'game_ended':
                gameState.status = 'ended';
                gameState.winner = message.winner;
                this.emit('game_ended', message);
                break;

            default:
                console.warn('Unknown game action:', message.action);
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
            type: 'join_room',
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
            type: 'game_action',
            action_type: 'play_card',
            card_index: cardIndex
        });
    }

    // Note: setReadyStatus is already defined above

    // Listen for player status updates
    private setupPlayerStatusListener(): void {
        // Handle player status changed events (for other players)
        this.on('player_status_changed', (data: any) => {
            console.log('Player status changed:', data);
            if (gameState.players) {
                const player = gameState.players.find((p: Player) => p.user_id === data.player_id);
                if (player) {
                    player.status = data.status;
                    player.ready = data.ready;
                    console.log(`Updated player ${player.username} status to ${data.status}`);
                } else {
                    console.warn(`Player with ID ${data.player_id} not found in gameState`);
                }
            }
        });

        // Handle own ready status update confirmation
        this.on('ready_status_updated', (data: any) => {
            console.log('Your ready status updated:', data);
            gameState.playerReady = data.ready;
            
            // Also update your status in the players array
            const myUserId = this.userId;
            if (gameState.players && myUserId) {
                const myPlayer = gameState.players.find((p: Player) => p.user_id === myUserId);
                if (myPlayer) {
                    myPlayer.status = data.status;
                    myPlayer.ready = data.ready;
                    console.log(`Updated your status to ${data.status}`);
                }
            }
        });
    }

    /**
     * Join matchmaking queue (public/private, size, code)
     */
    public joinMatchmaking(size: number, isPrivate: boolean, privateCode: string | null = null): void {
        if (!this.ws || this.ws.readyState !== WebSocket.OPEN) {
            console.error('Cannot join matchmaking: WebSocket is not connected');
            this.emit('error', { message: 'Cannot join matchmaking: WebSocket is not connected' });
            return;
        }

        if (!gameState.isAuthenticated || !this.userId) {
            console.error('Cannot join matchmaking: Not authenticated');
            this.emit('error', { message: 'Cannot join matchmaking: Not authenticated' });
            // Try to re-authenticate only if not already authenticating
            if (!this.isAuthenticating) {
                console.log('Not authenticated, attempting to authenticate...');
                this.authenticate();
            } else {
                console.log('Already authenticating, skipping authentication...');
            }
            return;
        }

        // Reset game state before joining new queue
        gameState.roomId = null;
        gameState.roomName = null;
        gameState.players = [];
        gameState.status = 'waiting';
        gameState.playerReady = false;

        console.log(`Joining matchmaking as user ${this.username} (${this.userId})`);
        this.send({
            type: 'matchmaking_join',
            size,
            is_private: isPrivate,
            private_code: privateCode
        });
    }

    public leaveMatchmaking(): void {
        if (!this.ws || this.ws.readyState !== WebSocket.OPEN) {
            console.error('Cannot leave matchmaking: WebSocket is not connected');
            return;
        }

        this.send({
            type: 'matchmaking_leave'
        });

        // Reset game state
        gameState.roomId = null;
        gameState.roomName = null;
        gameState.players = [];
        gameState.status = 'waiting';
        gameState.playerReady = false;
    }

    public getOnlineCount(): void {
        this.send({ type: 'get_online_count' });
    }

    /**
     * Start performance demo (tworzy wiele pokoi z botami po stronie serwera)
     */
    public startPerformanceDemo(rooms: number = 100, players: number = 4): void {
        if (!this.ws || this.ws.readyState !== WebSocket.OPEN) {
            alert('WebSocket nie jest połączony!');
            return;
        }
        this.send({
            type: 'start_performance_demo',
            rooms,
            players
        });
        const handler = (data: any) => {
            if (data.type === 'performance_demo_started') {
                alert(`Performance demo uruchomione!\nPokoje: ${data.rooms}\nBotów w pokoju: ${data.players_per_room}`);
                this.off('performance_demo_started', handler);
            }
        };
        this.on('performance_demo_started', handler);
    }

    private startHeartbeat(): void {
        if (this.heartbeatInterval) {
            clearInterval(this.heartbeatInterval);
        }

        this.heartbeatInterval = window.setInterval(() => {
            if (this.ws && this.ws.readyState === WebSocket.OPEN) {
                this.send({ type: 'heartbeat' });
                // No need to expect heartbeat_ack anymore
            }
        }, 30000); // Send heartbeat every 30 seconds
    }

    private stopHeartbeat(): void {
        if (this.heartbeatInterval) {
            clearInterval(this.heartbeatInterval);
            this.heartbeatInterval = null;
        }
    }
}

// Create a singleton instance
export default new WebSocketService();
