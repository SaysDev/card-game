<?php

namespace App\Game\Core;

enum MessageType: string
{
    // Client to Server
    case JOIN_GAME = 'join_game';
    case LEAVE_GAME = 'leave_game';
    case GAME_ACTION = 'game_action';
    
    // Server to Client
    case ERROR = 'error';
    case PLAYER_JOINED = 'player_joined';
    case PLAYER_LEFT = 'player_left';
    case GAME_STARTED = 'game_started';
    case GAME_ENDED = 'game_ended';
    case GAME_STATE = 'game_state';
    
    // Lobby Server Messages
    case REGISTER = 'register';
    case UNREGISTER = 'unregister';
    case PING = 'ping';
    case PONG = 'pong';
    
    // Authentication
    case AUTH = 'auth';
    case AUTH_SUCCESS = 'auth_success';
    case AUTH_FAILED = 'auth_failed';
    
    // Matchmaking
    case MATCHMAKING_JOIN = 'matchmaking_join';
    case MATCHMAKING_LEAVE = 'matchmaking_leave';
    case MATCHMAKING_JOINED = 'matchmaking_joined';
    case LEAVE_ROOM = 'leave_room';
    case SET_READY = 'set_ready';
    case PLAYER_READY = 'player_ready';
    case PLAYER_NOT_READY = 'player_not_ready';
} 