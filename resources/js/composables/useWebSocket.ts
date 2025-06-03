import { ref, onMounted, onUnmounted, computed } from 'vue';
import { useToast } from '@/components/ui/toast';
import { webSocketService } from '../Services/WebSocketService';

export interface WebSocketMessage {
  type: string;
  [key: string]: any;
}

// export function useWebSocket() {
//   const socket = ref<WebSocket | null>(null);
//   const isConnected = computed(() => webSocketService.isConnected.value);
//   const messages = computed(() => webSocketService.messages.value);
//   const lastMessage = ref<WebSocketMessage | null>(null);
//   const reconnectAttempts = ref(0);
//   const maxReconnectAttempts = 5;
//   const { toast } = useToast();

//   // Event callbacks storage
//   const eventHandlers: Record<string, ((data: any) => void)[]> = {};

//   const connect = (userId?: number, username?: string, token?: string) => {
//     // Get the WebSocket URL from environment variables or use a default
//     const wsProtocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
//     const wsUrl = `${wsProtocol}//${window.location.hostname}:9502`;

//     socket.value = new WebSocket(wsUrl);

//     socket.value.onopen = () => {
//       isConnected.value = true;
//       reconnectAttempts.value = 0;
//       console.log('WebSocket connection established');

//       // If credentials are provided, authenticate immediately
//       if (userId && username && token) {
//         authenticate(userId, username, token);
//       }
//     };

//     socket.value.onmessage = (event) => {
//       try {
//         const data = JSON.parse(event.data) as WebSocketMessage;
//         lastMessage.value = data;

//         // Call specific event handlers
//         if (data.type && eventHandlers[data.type]) {
//           eventHandlers[data.type].forEach(handler => handler(data));
//         }

//         // Call general message handlers
//         if (eventHandlers['message']) {
//           eventHandlers['message'].forEach(handler => handler(data));
//         }

//         // Handle specific message types
//         switch (data.type) {
//           case 'auth_success':
//             toast({
//               title: 'Połączono z serwerem gry',
//               description: `Witaj ${data.username}!`,
//               variant: 'default',
//             });
//             break;

//           case 'auth_error':
//             toast({
//               title: 'Błąd uwierzytelniania',
//               description: data.message,
//               variant: 'destructive',
//             });
//             break;

//           case 'error':
//             toast({
//               title: 'Błąd serwera',
//               description: data.message,
//               variant: 'destructive',
//             });
//             break;
//         }
//       } catch (error) {
//         console.error('Failed to parse WebSocket message:', error);
//       }
//     };

//     socket.value.onclose = (event) => {
//       isConnected.value = false;
//       console.log(`WebSocket connection closed: ${event.code} ${event.reason}`);

//       // Attempt to reconnect if not a normal closure
//       if (event.code !== 1000 && reconnectAttempts.value < maxReconnectAttempts) {
//         reconnectAttempts.value++;
//         const delay = Math.min(1000 * reconnectAttempts.value, 5000);
//         setTimeout(() => connect(userId, username, token), delay);
//       }
//     };

//     socket.value.onerror = (error) => {
//       console.error('WebSocket error:', error);
//       toast({
//         title: 'Błąd połączenia',
//         description: 'Nie można połączyć się z serwerem gry.',
//         variant: 'destructive',
//       });
//     };
//   };

//   const disconnect = () => {
//     if (socket.value && isConnected.value) {
//       socket.value.close();
//     }
//     webSocketService.disconnect();
//   };

//   const sendMessage = (message: any) => {
//     if (socket.value && isConnected.value) {
//       socket.value.send(JSON.stringify(message));
//     } else {
//       console.error('Cannot send message, WebSocket is not connected');
//       toast({
//         title: 'Błąd połączenia',
//         description: 'Nie można wysłać wiadomości - brak połączenia.',
//         variant: 'destructive',
//       });
//     }
//     webSocketService.sendMessage(message);
//   };

//   // Authentication
//   const authenticate = (userId: number, username: string, token: string) => {
//     sendMessage({
//       action: 'authenticate',
//       user_id: userId,
//       username: username,
//       token: token
//     });
//   };

//   // Room operations
//   const createRoom = (roomName: string, maxPlayers: number) => {
//     sendMessage({
//       action: 'create_room',
//       room_name: roomName,
//       max_players: maxPlayers
//     });
//   };

//   const joinRoom = (roomId: string) => {
//     sendMessage({
//       action: 'join_room',
//       room_id: roomId
//     });
//   };

//   const leaveRoom = () => {
//     sendMessage({
//       action: 'leave_room'
//     });
//   };

//   const listRooms = () => {
//     sendMessage({
//       action: 'list_rooms'
//     });
//   };

//   // Game actions
//   const playCard = (cardIndex: number) => {
//     sendMessage({
//       action: 'game_action',
//       action_type: 'play_card',
//       card_index: cardIndex
//     });
//   };

//   const drawCard = () => {
//     sendMessage({
//       action: 'game_action',
//       action_type: 'draw_card'
//     });
//   };

//   const passTurn = () => {
//     sendMessage({
//       action: 'game_action',
//       action_type: 'pass_turn'
//     });
//   };

//   // Event handlers
//   const on = (eventType: string, callback: (data: any) => void) => {
//     if (!eventHandlers[eventType]) {
//       eventHandlers[eventType] = [];
//     }
//     eventHandlers[eventType].push(callback);
//   };

//   const off = (eventType: string, callback: (data: any) => void) => {
//     if (eventHandlers[eventType]) {
//       eventHandlers[eventType] = eventHandlers[eventType].filter(handler => handler !== callback);
//     }
//   };

//   onMounted(() => {
//     // Auto-connect when component using this composable is mounted
//     // Note: authenticate will be called separately with user data
//     connect();
//   });

//   onUnmounted(() => {
//     disconnect();
//   });

//   return {
//     isConnected,
//     messages,
//     lastMessage,
//     connect,
//     disconnect,
//     sendMessage,
//     authenticate,
//     createRoom,
//     joinRoom,
//     leaveRoom,
//     listRooms,
//     playCard,
//     drawCard,
//     passTurn,
//     on,
//     off
//   };
// }


export function useWebSocket() {
  const isConnected = ref(false);
  const messages = ref<WebSocketMessage[]>([]);
  const lastMessage = ref<WebSocketMessage | null>(null);
  const reconnectAttempts = ref(0);
  const maxReconnectAttempts = 5;
  const { toast } = useToast();

  const connect = () => {
    webSocketService.connect();
  }

  const disconnect = () => {
    webSocketService.disconnect();
  }

  const sendMessage = (message: WebSocketMessage) => {
    webSocketService.send(message);
  }
}