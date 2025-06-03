import { useAuth } from "@/composables/useAuth";

interface GameMessage {
    type: string;
    action?: string;
    message?: string;
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

    private constructor() {
        // Private constructor for singleton pattern
    }

    public static getInstance(): WebSocketService {
        if (!WebSocketService.instance) {
            WebSocketService.instance = new WebSocketService();
        }
        return WebSocketService.instance;
    }

    public connect(url: string = `ws://127.0.0.1:5555`): Promise<void> {
        if (this.socket?.readyState === WebSocket.OPEN) {
            return Promise.resolve();
        }

        if (this.isConnecting) {
            return Promise.reject(new Error('Connection already in progress'));
        }

        this.isConnecting = true;

        return new Promise((resolve, reject) => {
            try {
                this.socket = new WebSocket(url);
                this.setupSocketListeners(resolve, reject);
            } catch (error) {
                this.isConnecting = false;
                reject(error);
            }
        });
    }

    public disconnect(): void {
        if (this.socket) {
            this.socket.close();
            this.socket = null;
        }
    }

    public on(type: string, handler: (data: any) => void): void {
        if (!this.messageHandlers.has(type)) {
            this.messageHandlers.set(type, new Set());
        }
        this.messageHandlers.get(type)?.add(handler);
    }

    public off(type: string, handler: (data: any) => void): void {
        this.messageHandlers.get(type)?.delete(handler);
    }

    public joinMatchmaking(): void {
        this.send({
            type: 'matchmaking_join'
        });
    }

    public send(data: GameMessage): void {
        if (!this.socket || this.socket.readyState !== WebSocket.OPEN) {
            throw new Error('WebSocket not connected. Call connect() first.');
        }

        this.socket.send(JSON.stringify(data));
    }

    private setupSocketListeners(resolve: () => void, reject: (error: any) => void): void {
        if (!this.socket) return;

        this.socket.onopen = () => {
            console.log('WebSocket connected to LobbyServer');
            this.reconnectAttempts = 0;
            this.isConnecting = false;

            try {
                const { user } = useAuth();
                console.log('Authenticating with user:', user);

                // Make sure we have a valid user
                if (user && user.id) {
                    this.send({
                        type: 'auth',
                        token: user.ws_token || ''
                    });
                    console.log('Authentication message sent');
                } else {
                    console.error('No valid user found for authentication');
                }
            } catch (error) {
                console.error('Error during authentication:', error);
            }

            resolve();
        };

        this.socket.onclose = () => {
            console.log('WebSocket disconnected from LobbyServer');
            this.isConnecting = false;
            this.handleReconnect();
        };

        this.socket.onerror = (error) => {
            console.error('WebSocket error:', error);
            this.isConnecting = false;
            reject(error);
        };

        this.socket.onmessage = (event) => {
            try {
                const data = JSON.parse(event.data) as GameMessage;
                
                // Handle authentication response
                if (data.type === 'auth_success') {
                    console.log('Authentication successful:', data);
                }
                
                const handlers = this.messageHandlers.get(data.type);
                if (handlers) {
                    handlers.forEach(handler => handler(data));
                }

                // Always handle error messages
                if (data.type === 'error') {
                    console.error('Server error:', data.message);
                }
            } catch (error) {
                console.error('Failed to parse WebSocket message:', error);
            }
        };
    }

    private handleReconnect(): void {
        if (this.reconnectAttempts < this.maxReconnectAttempts) {
            this.reconnectAttempts++;
            console.log(`Attempting to reconnect (${this.reconnectAttempts}/${this.maxReconnectAttempts})...`);
            setTimeout(() => this.connect(), this.reconnectDelay * this.reconnectAttempts);
        } else {
            console.error('Max reconnection attempts reached');
        }
    }

    public isConnected(): boolean {
        return this.socket?.readyState === WebSocket.OPEN;
    }
}

// Export a singleton instance
export const webSocketService = WebSocketService.getInstance();

// Add default export
export default WebSocketService;
