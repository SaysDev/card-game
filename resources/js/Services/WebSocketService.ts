import { useAuth } from "@/composables/useAuth";
import { MessageType } from "@/types/messageTypes";
import { reactive } from 'vue';

interface GameMessage {
    type: string;
    action?: string;
    message?: string;
    data?: any;
    game_type?: string;
    max_players?: number;
    is_private?: boolean;
    private_code?: string;
    user_id?: number;
    username?: string;
    room_id?: number;
    exp?: number;
    [key: string]: any;
}

interface Player {
  user_id: number;
  username: string;
  status: 'ready' | 'not_ready' | 'waiting';
  ready: boolean;
  score: number;
  cards_count: number;
}

export const gameState = reactive({
  isAuthenticated: false,
  roomId: null as string | null,
  roomName: null as string | null,
  status: 'waiting' as 'waiting' | 'playing' | 'ended',
  players: [] as Player[],
  currentTurn: 0,
  currentPlayerId: null as number | null,
  hand: [] as any[],
  playArea: [] as any[],
  lastCard: null as any,
  deckCount: 0,
  isYourTurn: false,
  winner: null as { username: string } | null
});

// Utility function to standardize player object format
export function standardizePlayerObject(player: any): Player {
  return {
    user_id: player.user_id ?? player.id ?? 0,
    username: player.username ?? player.name ?? 'Gracz',
    status: player.status === 'ready' ? 'ready' : 'not_ready',
    ready: player.ready ?? player.status === 'ready',
    score: player.score ?? 0,
    cards_count: player.cards_count ?? (player.cards ? player.cards.length : 0)
  };
}

export class WebSocketService {
    private static instance: WebSocketService;
    private socket: WebSocket | null = null;
    private messageHandlers: Map<string, Set<(data: any) => void>> = new Map();
    private reconnectAttempts: number = 0;
    private maxReconnectAttempts: number = 5;
    private reconnectDelay: number = 1000;
    private isConnecting: boolean = false;
    private debug: boolean = true;
    private keepAliveInterval: ReturnType<typeof setInterval> | null = null;
    private tokenRefreshInterval: ReturnType<typeof setInterval> | null = null;
    private tokenExpirationTime: number | null = null;

    private constructor() {
        // Private constructor for singleton pattern
    }

    private log(message: string, data?: any) {
        if (this.debug) {
            if (data) {
                console.log(`[WebSocket] ${message}`, data);
            } else {
                console.log(`[WebSocket] ${message}`);
            }
        }
    }

    private error(message: string, error?: any) {
        if (this.debug) {
            if (error) {
                console.error(`[WebSocket Error] ${message}`, error);
            } else {
                console.error(`[WebSocket Error] ${message}`);
            }
        }
    }

    public static getInstance(): WebSocketService {
        if (!WebSocketService.instance) {
            WebSocketService.instance = new WebSocketService();
        }
        return WebSocketService.instance;
    }

    public connect(url: string = `ws://127.0.0.1:5555`): Promise<void> {
        if (this.socket?.readyState === WebSocket.OPEN) {
            this.log('Already connected');
            return Promise.resolve();
        }

        if (this.isConnecting) {
            this.error('Connection already in progress');
            return Promise.reject(new Error('Connection already in progress'));
        }

        this.isConnecting = true;
        this.log(`Connecting to ${url}`);

        return new Promise((resolve, reject) => {
            try {
                this.socket = new WebSocket(url);
                this.setupSocketListeners(resolve, reject);
                this.setupKeepAlive();
            } catch (error) {
                this.isConnecting = false;
                this.error('Failed to create WebSocket connection', error);
                reject(error);
            }
        });
    }

    public disconnect(): void {
        if (this.socket) {
            this.log('Disconnecting...');
            this.socket.close();
            this.socket = null;
            this.clearKeepAlive();
            this.clearTokenRefresh();
        }
    }

    public on(type: string, handler: (data: any) => void): void {
        this.log(`Registering handler for event: ${type}`);
        if (!this.messageHandlers.has(type)) {
            this.messageHandlers.set(type, new Set());
        }
        this.messageHandlers.get(type)?.add(handler);
    }

    public off(type: string, handler: (data: any) => void): void {
        this.log(`Removing handler for event: ${type}`);
        this.messageHandlers.get(type)?.delete(handler);
    }

    public joinMatchmaking(): void {
        this.send({
            type: MessageType.MATCHMAKING_JOIN
        });
    }

    private normalizeMessage(data: any): GameMessage {
        // Convert camelCase to snake_case for server communication
        const normalized: any = {};
        for (const [key, value] of Object.entries(data)) {
            const snakeKey = key.replace(/[A-Z]/g, letter => `_${letter.toLowerCase()}`);
            normalized[snakeKey] = value;
        }
        return normalized as GameMessage;
    }

    public send(data: GameMessage): void {
        if (!this.socket || this.socket.readyState !== WebSocket.OPEN) {
            this.error('Cannot send message - WebSocket not connected');
            throw new Error('WebSocket not connected. Call connect() first.');
        }

        const normalizedData = this.normalizeMessage(data);
        const message = JSON.stringify(normalizedData);
        this.log('Sending message', normalizedData);
        this.socket.send(message);
    }

    private async handleExpiredToken(): Promise<void> {
        this.log('Handling expired token');
        try {
            // First try to get a new token directly from the server
            const response = await fetch('/api/ws/token', {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                },
                credentials: 'include' // Important for session cookies
            });

            if (!response.ok) {
                throw new Error('Failed to get new token');
            }

            const data = await response.json();
            if (!data.token) {
                throw new Error('No token in response');
            }

            // Update token in auth store
            const { user } = useAuth();
            if (user.value) {
                user.value.ws_token = data.token;
            }

            // After successful token refresh, reconnect
            if (this.socket) {
                this.socket.close();
                await this.connect();
            }
        } catch (error) {
            this.error('Failed to handle expired token', error);
            // If token refresh fails, redirect to login
            window.location.href = '/login';
        }
    }

    private async refreshToken(): Promise<void> {
        try {
            // First try to get a new token directly
            const response = await fetch('/api/ws/token', {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                },
                credentials: 'include' // Important for session cookies
            });

            if (!response.ok) {
                throw new Error('Failed to get new token');
            }

            const data = await response.json();
            if (!data.token) {
                throw new Error('No token in response');
            }

            // Update token in auth store
            const { user } = useAuth();
            if (user.value) {
                user.value.ws_token = data.token;
            }
            this.log('Token refreshed successfully');
        } catch (error) {
            this.error('Failed to refresh token', error);
            throw error; // Re-throw to handle in caller
        }
    }

    private setupTokenRefresh(expirationTime: number): void {
        this.clearTokenRefresh();
        this.tokenExpirationTime = expirationTime;

        // Refresh token 5 minutes before expiration
        const refreshTime = (expirationTime - 300) * 1000; // Convert to milliseconds
        const now = Date.now();
        const timeUntilRefresh = Math.max(0, refreshTime - now);

        this.tokenRefreshInterval = setTimeout(() => {
            this.refreshToken();
        }, timeUntilRefresh);
    }

    private clearTokenRefresh(): void {
        if (this.tokenRefreshInterval) {
            clearTimeout(this.tokenRefreshInterval);
            this.tokenRefreshInterval = null;
        }
        this.tokenExpirationTime = null;
    }

    private setupSocketListeners(resolve: () => void, reject: (error: any) => void): void {
        if (!this.socket) return;

        this.socket.onopen = async () => {
            this.log('Connection established');
            this.reconnectAttempts = 0;
            this.isConnecting = false;
            this.setupKeepAlive();

            try {
                // Get a fresh token before authenticating
                await this.refreshToken();
                const { user } = useAuth();
                
                if (!user.value) {
                    throw new Error('No valid user found for authentication');
                }

                this.send({
                    type: MessageType.AUTH,
                    token: user.value.ws_token
                });
                this.log('Authentication message sent');
                resolve();
            } catch (error) {
                this.error('Failed to get token for authentication', error);
                reject(error);
            }
        };

        this.socket.onclose = (event) => {
            this.log(`Connection closed. Code: ${event.code}, Reason: ${event.reason}`);
            this.isConnecting = false;
            this.handleReconnect();
            this.clearKeepAlive();
            this.clearTokenRefresh();
        };

        this.socket.onerror = (error) => {
            this.error('WebSocket error occurred', error);
            this.isConnecting = false;
            reject(error);
        };

        this.socket.onmessage = (event) => {
            try {
                const data = JSON.parse(event.data) as GameMessage;
                this.log('Received message', data);
                
                if (data.type === MessageType.AUTH_SUCCESS) {
                    this.log('Authentication successful', data);
                    // Setup token refresh if expiration time is provided
                    if (data.exp) {
                        this.setupTokenRefresh(data.exp);
                    }
                } else if (data.type === MessageType.ERROR) {
                    this.error('Server error', data.message);
                    
                    // Handle expired token error
                    if (data.message?.includes('Expired token')) {
                        this.handleExpiredToken();
                    }
                }
                
                const handlers = this.messageHandlers.get(data.type);
                if (handlers) {
                    this.log(`Found ${handlers.size} handlers for event: ${data.type}`);
                    handlers.forEach(handler => {
                        try {
                            handler(data);
                        } catch (error) {
                            this.error(`Error in handler for ${data.type}`, error);
                        }
                    });
                } else {
                    this.log(`No handlers registered for event: ${data.type}`);
                }
            } catch (error) {
                this.error('Failed to parse WebSocket message', error);
                this.error('Raw message:', event.data);
            }
        };
    }

    private handleReconnect(): void {
        if (this.reconnectAttempts < this.maxReconnectAttempts) {
            this.reconnectAttempts++;
            this.log(`Attempting to reconnect (${this.reconnectAttempts}/${this.maxReconnectAttempts})...`);
            setTimeout(() => this.connect(), this.reconnectDelay * this.reconnectAttempts);
        } else {
            this.error('Max reconnection attempts reached');
        }
    }

    public isConnected(): boolean {
        return this.socket?.readyState === WebSocket.OPEN;
    }

    public setDebug(enabled: boolean): void {
        this.debug = enabled;
    }

    private setupKeepAlive(): void {
        this.clearKeepAlive();
        if (this.socket && this.socket.readyState === WebSocket.OPEN) {
            this.keepAliveInterval = setInterval(() => {
                if (this.socket && this.socket.readyState === WebSocket.OPEN) {
                    this.send({ type: 'ping' });
                    this.log('Sent keepalive ping');
                }
            }, 30000); // 30 seconds
        }
    }

    private clearKeepAlive(): void {
        if (this.keepAliveInterval) {
            clearInterval(this.keepAliveInterval);
            this.keepAliveInterval = null;
            this.log('Cleared keepalive interval');
        }
    }
}

// Export a singleton instance
export const webSocketService = WebSocketService.getInstance();

// Add default export
export default WebSocketService;
