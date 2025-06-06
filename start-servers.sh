#!/bin/bash

# Default values
WS_PORT=9502
GAME_PORT=9503
WS_WORKERS=4
GAME_WORKERS=2
GAME_CAPACITY=1000

# Parse command line arguments
while getopts "w:g:W:G:c:h" opt; do
  case $opt in
    w) WS_PORT="$OPTARG" ;;
    g) GAME_PORT="$OPTARG" ;;
    W) WS_WORKERS="$OPTARG" ;;
    G) GAME_WORKERS="$OPTARG" ;;
    c) GAME_CAPACITY="$OPTARG" ;;
    h)
      echo "Usage: $0 [-w ws_port] [-g game_port] [-W ws_workers] [-G game_workers] [-c game_capacity]"
      echo "Default values:"
      echo "  WebSocket port: $WS_PORT"
      echo "  Game port: $GAME_PORT"
      echo "  WebSocket workers: $WS_WORKERS"
      echo "  Game workers: $GAME_WORKERS"
      echo "  Game capacity: $GAME_CAPACITY"
      exit 0
      ;;
    \?)
      echo "Invalid option: -$OPTARG" >&2
      exit 1
      ;;
  esac
done

# Check if ports are available
check_port() {
    if lsof -Pi :$1 -sTCP:LISTEN -t >/dev/null ; then
        echo "Port $1 is already in use"
        exit 1
    fi
}

check_port $WS_PORT
check_port $GAME_PORT

# Start the servers
echo "Starting servers..."
php artisan servers:start \
    --ws-port=$WS_PORT \
    --game-port=$GAME_PORT \
    --ws-workers=$WS_WORKERS \
    --game-workers=$GAME_WORKERS \
    --game-capacity=$GAME_CAPACITY

# Store the process ID
echo $! > .servers.pid

echo "Servers started successfully"
echo "WebSocket server running on port $WS_PORT"
echo "Game server running on port $GAME_PORT"
echo "Press Ctrl+C to stop the servers"

# Handle cleanup on script termination
trap 'kill $(cat .servers.pid) 2>/dev/null; rm -f .servers.pid; echo "Servers stopped"' EXIT

# Keep the script running
wait 