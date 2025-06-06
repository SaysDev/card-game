#!/bin/bash

# Default values
NUM_GAME_SERVERS=1
CAPACITY=100
WATCH_DIRS="app"
WATCH_EXT="php"
USE_NODEMON=true

# Parse command line arguments
while getopts ":n:c:w:e:d" opt; do
  case $opt in
    n) NUM_GAME_SERVERS="$OPTARG"
    ;;
    c) CAPACITY="$OPTARG"
    ;;
    w) WATCH_DIRS="$OPTARG"
    ;;
    e) WATCH_EXT="$OPTARG"
    ;;
    d) USE_NODEMON=false
    ;;
    \?) echo "Invalid option -$OPTARG" >&2
    exit 1
    ;;
  esac
done

PIDFILE_WS=".ws.pid"
PIDFILE_GAME_PREFIX=".game"
LOG_WS="websocket-server.log"
LOG_GAME="game-server.log"
NODEMON_PID_FILE=".nodemon.pid"

# Function to kill a process and wait for it to exit
kill_and_wait() {
  local pid=$1
  local name=$2
  local timeout=10
  local count=0

  echo "Killing $name process: $pid"
  kill $pid 2>/dev/null

  # Wait for process to exit (max timeout seconds)
  while ps -p $pid > /dev/null 2>&1; do
    sleep 1
    count=$((count + 1))
    if [ $count -ge $timeout ]; then
      echo "$name process $pid did not exit, sending SIGKILL"
      kill -9 $pid 2>/dev/null
      break
    fi
  done

  echo "$name process $pid stopped"
}

# Function to stop all running servers
stop_all_servers() {
  echo "Stopping all servers..."
  
  # Kill nodemon if running
  if [ -f "$NODEMON_PID_FILE" ]; then
    PID=$(cat $NODEMON_PID_FILE)
    if ps -p $PID > /dev/null 2>&1; then
      kill_and_wait $PID "nodemon"
    fi
    rm -f $NODEMON_PID_FILE
  fi

  # Kill websocket server if running
  if [ -f "$PIDFILE_WS" ]; then
    PID=$(cat $PIDFILE_WS)
    if ps -p $PID > /dev/null 2>&1; then
      kill_and_wait $PID "websocket"
    fi
    rm -f $PIDFILE_WS
  fi

  # Kill all game servers if running
  for i in $(seq 1 $NUM_GAME_SERVERS); do
    PIDFILE="${PIDFILE_GAME_PREFIX}-${i}.pid"
    if [ -f "$PIDFILE" ]; then
      PID=$(cat $PIDFILE)
      if ps -p $PID > /dev/null 2>&1; then
        kill_and_wait $PID "game server $i"
      fi
      rm -f $PIDFILE
    fi
  done

  # Kill tail process if running
  if [ -n "$TAIL_PID" ] && ps -p $TAIL_PID > /dev/null 2>&1; then
    echo "Killing tail process: $TAIL_PID"
    kill $TAIL_PID 2>/dev/null
  fi

  echo "All servers stopped"
}

# Function to handle SIGINT (Ctrl+C)
handle_sigint() {
  echo "Received interrupt signal. Stopping all servers..."
  stop_all_servers
  exit 0
}

# Function to start all servers
start_servers() {
  echo "=== Starting servers $(date) ==="
  echo "Number of game servers: $NUM_GAME_SERVERS"
  echo "Server capacity: $CAPACITY"

  # Clean up any existing processes first
  stop_all_servers

  # Clear log files
  > $LOG_WS
  > $LOG_GAME

  # Start websocket server
  echo "Starting WebSocket server..."
  php artisan websocket:start --server-id=sock-server-1 > $LOG_WS 2>&1 &
  WS_PID=$!
  echo $WS_PID > $PIDFILE_WS
  echo "WebSocket server started with PID: $WS_PID"

  # Give websocket server time to initialize
  sleep 3

  # Start game servers
  GAME_PIDS=()
  for i in $(seq 1 $NUM_GAME_SERVERS); do
    echo "Starting Game server $i..."
    PIDFILE="${PIDFILE_GAME_PREFIX}-${i}.pid"
    SERVER_ID="game-server-${i}"
    if [ $i -eq 1 ]; then
      php artisan game:start --ws-url=ws://localhost:9502 --server-id=$SERVER_ID --capacity=$CAPACITY > $LOG_GAME 2>&1 &
    else
      php artisan game:start --ws-url=ws://localhost:9502 --server-id=$SERVER_ID --capacity=$CAPACITY >> $LOG_GAME 2>&1 &
    fi
    PID=$!
    GAME_PIDS+=($PID)
    echo $PID > $PIDFILE
    echo "Game server $i started with PID: $PID"
    sleep 1
  done

  echo "All servers started. Showing logs..."

  # Monitor logs without filenames
  tail -q -f $LOG_WS $LOG_GAME &
  TAIL_PID=$!

  # Set up trap to clean up on exit
  trap handle_sigint INT TERM
  echo "Press Ctrl+C to stop all servers"
  wait $TAIL_PID
}

# Main execution
if [ "$USE_NODEMON" = true ]; then
  # Check if nodemon is installed
  if ! command -v nodemon &> /dev/null; then
    echo "Error: nodemon is not installed. Please install it using 'npm install -g nodemon'"
    exit 1
  fi

  echo "Starting development server with auto-reload"
  echo "Number of game servers: $NUM_GAME_SERVERS"
  echo "Server capacity: $CAPACITY"
  echo "Watching directories: $WATCH_DIRS"
  echo "Watching extensions: $WATCH_EXT"

  # Set up trap for the main script
  trap handle_sigint INT TERM

  # Define a function that will be called by nodemon
  start_with_nodemon() {
    # Start nodemon to watch for changes
    nodemon --watch $WATCH_DIRS --ext $WATCH_EXT --exec "bash -c 'bash $0 -n $NUM_GAME_SERVERS -c $CAPACITY -d'" &
    NODEMON_PID=$!
    echo $NODEMON_PID > $NODEMON_PID_FILE
    
    wait $NODEMON_PID
  }

  # Start with nodemon
  start_with_nodemon
else
  # Start servers directly
  start_servers
fi 