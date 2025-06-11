/**
 * Message types for WebSocket communication with the server.
 * This enum must be kept in sync with the PHP enum in app/Game/Core/MessageType.php
 */
export enum MessageType {
    AUTH = 'auth',
    AUTH_SUCCESS = 'auth_success',
    MATCHMAKING_JOIN = 'matchmaking_join',
    MATCHMAKING_LEAVE = 'matchmaking_leave',
    MATCHMAKING_SUCCESS = 'matchmaking_success',
    SET_READY = 'set_ready',
    PLAYER_READY = 'player_ready',
    PLAYER_NOT_READY = 'player_not_ready',
    PLAYER_READY_STATUS = 'player_ready_status',
    PLAYER_JOINED = 'player_joined',
    PLAYER_LEFT = 'player_left',
    GAME_START = 'game_start',
    ERROR = 'error',
    PING = 'ping',
    PONG = 'pong',
    MATCHMAKING_LEAVE_SUCCESS = "matchmaking_leave_success"
} 