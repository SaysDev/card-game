import WebSocketService, { gameState } from './WebSocketService';
import { reactive } from 'vue';

// Store information about available rooms
export const roomsState = reactive({
  availableRooms: [],
  isLoading: false,
  lastUpdated: Date|null,
});

export class GameRoomService {
  /**
   * Create a new game room
   */
  public static createRoom(roomName: string, maxPlayers: number = 4): void {
    if (!gameState.isAuthenticated) {
      console.error('Cannot create room: User not authenticated');
      return;
    }

    WebSocketService.createRoom(roomName, maxPlayers);
  }

  /**
   * Join a game room by ID
   * This adds proper validation and prefixes the room ID if needed
   */
      public static joinRoom(roomId: string | number): void {
    if (!gameState.isAuthenticated) {
      console.error('Cannot join room: User not authenticated');

      // If connection exists but not authenticated, try to authenticate first
      if (WebSocketService.isConnected()) {
        console.log('Attempting to authenticate before joining room');

        // Set up listener for authentication success
        const authListener = () => {
          console.log('Authentication successful, now joining room');
          WebSocketService.joinRoom(String(roomId));
          WebSocketService.off('authenticated', authListener);
        };

        WebSocketService.on('authenticated', authListener);

        // Trigger authentication
        WebSocketService.authenticate();
      }
      return;
    }

    if (!roomId) {
      console.error('Cannot join room: Invalid room ID');
      return;
    }

    // Always try with just the original room ID - server now handles prefix detection
    console.log(`GameRoomService: Joining room ${roomId}`);
    WebSocketService.joinRoom(String(roomId));
  }

  /**
   * Fetch available rooms from the server
   */
  public static fetchRooms(): void {
    roomsState.isLoading = true;

    // Register a one-time listener for room list
    const roomListHandler = (data: any) => {
      roomsState.availableRooms = data.rooms || [];
      roomsState.lastUpdated = new Date();
      roomsState.isLoading = false;

      // Remove this one-time listener
      WebSocketService.off('room_list', roomListHandler);
    };

    WebSocketService.on('room_list', roomListHandler);
    WebSocketService.listRooms();
  }

  /**
   * Leave the current room
   */
  public static leaveRoom(): void {
    if (gameState.roomId) {
      WebSocketService.leaveRoom();
    }
  }
}

// Add off method to WebSocketService if it doesn't exist
if (!WebSocketService.off) {
  WebSocketService.off = function(event: string, callback: Function): void {
    // Implementation depends on how eventListeners are structured
    // This is a basic implementation that might need adjustment
    if (this.eventListeners && this.eventListeners.has(event)) {
      const listeners = this.eventListeners.get(event);
      if (listeners) {
        const index = listeners.indexOf(callback);
        if (index !== -1) {
          listeners.splice(index, 1);
        }
      }
    }
  };
}
