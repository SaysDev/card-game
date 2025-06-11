<?php

namespace App\Game\Core;

enum MessageType: string
{
    // Client to Server
    case JOIN_GAME = 'join_game';
    case LEAVE_GAME = 'leave_game';
    case GAME_ACTION = 'game_action';
    case CREATE_ROOM = 'create_room';
    case JOIN_ROOM = 'join_room';
    case START_GAME = 'start_game';
    
    // Server to Client
    case ERROR = 'error';
    case PLAYER_JOINED = 'player_joined';
    case PLAYER_LEFT = 'player_left';
    case GAME_STARTED = 'game_started';
    case GAME_ENDED = 'game_ended';
    case GAME_STATE = 'game_state';
    case CREATE_ROOM_SUCCESS = 'create_room_success';
    case CREATE_ROOM_ERROR = 'create_room_error';
    case ROOM_JOINED = 'room_joined';
    case MATCHMAKING_SUCCESS = 'matchmaking_success';
    case ROOM_UPDATE = 'room_update';
    case ROOM_CLOSED = 'room_closed';
    case YOUR_CARDS = 'your_cards';
    
    // Lobby Server Messages
    case REGISTER = 'register';
    case REGISTER_SUCCESS = 'register_success';
    case UNREGISTER = 'unregister';
    case PING = 'ping';
    case PONG = 'pong';
    case STATUS_UPDATE = 'status_update';
    case STATUS_UPDATE_SUCCESS = 'status_update_success';
    
    // Authentication
    case AUTH = 'auth';
    case AUTH_SUCCESS = 'auth_success';
    case AUTH_FAILED = 'auth_failed';
    case AUTH_ERROR = 'auth_error';
    
    // Matchmaking
    case MATCHMAKING_JOIN = 'matchmaking_join';
    case MATCHMAKING_LEAVE = 'matchmaking_leave';
    case MATCHMAKING_JOINED = 'matchmaking_joined';
    case LEAVE_ROOM = 'leave_room';
    case SET_READY = 'set_ready';
    case PLAYER_READY = 'player_ready';
    case PLAYER_NOT_READY = 'player_not_ready';
} 