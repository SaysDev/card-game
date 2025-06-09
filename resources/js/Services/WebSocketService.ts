import { useAuth } from "@/composables/useAuth";

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
    [key: string]: any;
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
            type: 'matchmaking_join'
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

    private setupSocketListeners(resolve: () => void, reject: (error: any) => void): void {
        if (!this.socket) return;

        this.socket.onopen = () => {
            this.log('Connection established');
            this.reconnectAttempts = 0;
            this.isConnecting = false;

            try {
                const { user } = useAuth();
                this.log('Authenticating with user', user);

                if (user && user.id) {
                    this.send({
                        type: 'auth',
                        token: user.ws_token || ''
                    });
                    this.log('Authentication message sent');
                } else {
                    this.error('No valid user found for authentication');
                }
            } catch (error) {
                this.error('Error during authentication', error);
            }

            resolve();
        };

        this.socket.onclose = (event) => {
            this.log(`Connection closed. Code: ${event.code}, Reason: ${event.reason}`);
            this.isConnecting = false;
            this.handleReconnect();
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
                
                if (data.type === 'auth_success') {
                    this.log('Authentication successful', data);
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

                if (data.type === 'error') {
                    this.error('Server error', data.message);
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
}

// Export a singleton instance
export const webSocketService = WebSocketService.getInstance();

// Add default export
export default WebSocketService;
