#!/bin/bash

# This script starts the WebSocket server with Xdebug disabled

# Get the path to the PHP binary
PHP_BIN="$(which php)"

# Check if PHP was found
if [ -z "$PHP_BIN" ]; then
    echo "Error: PHP executable not found in PATH"
    exit 1
fi

# Default port
PORT=9502

# Parse command line arguments
while getopts ":p:" opt; do
  case $opt in
    p) PORT="$OPTARG"
    ;;
    \?) echo "Invalid option -$OPTARG" >&2
    exit 1
    ;;
  esac
done

echo "Starting WebSocket server on port $PORT (with Xdebug disabled)"

# Run PHP with Xdebug disabled but OpenSwoole enabled
$PHP_BIN -n -d extension=openswoole artisan websocket:start --port=$PORT

# Exit with the same code as the PHP process
exit $?
