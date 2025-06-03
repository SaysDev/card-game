# Multiplayer Card Game with OpenSwoole

A real-time multiplayer card game built with Laravel, Vue.js, and OpenSwoole WebSockets.

## Features

- Real-time multiplayer card game
- WebSocket communication using OpenSwoole
- Game room creation and management
- Player authentication and session management
- Turn-based gameplay with card drawing and playing

## Requirements

- PHP 8.2+
- Composer
- Node.js and NPM
- OpenSwoole PHP extension

## Installation

1. Clone the repository:

```bash
git clone https://github.com/yourusername/card-game.git
cd card-game
```

2. Install PHP dependencies:

```bash
composer install
```

3. Install JavaScript dependencies:

```bash
npm install
```

4. Copy the environment file:

```bash
cp .env.example .env
```

5. Generate application key:

```bash
php artisan key:generate
```

6. Run migrations:

```bash
php artisan migrate
```

7. Build frontend assets:

```bash
npm run build
```

## Running the Application

1. Start the Laravel development server:

```bash
php artisan serve
```

2. Start the WebSocket server in a separate terminal window:

```bash
php artisan websocket:start
```

3. Access the application in your browser at: http://localhost:8000

## Installing OpenSwoole

To use OpenSwoole, you need to install the PHP extension. 

### Ubuntu/Debian:

```bash
pecl install openswoole
```

Add `extension=openswoole.so` to your php.ini file.

### MacOS (with Homebrew):

```bash
pecl install openswoole
```

Add `extension=openswoole.so` to your php.ini file.

### Windows:

It's recommended to use WSL (Windows Subsystem for Linux) for OpenSwoole development on Windows.

## Usage

1. Register or log in to your account
2. Create a new game room or join an existing one
3. Wait for players to join (2-8 players)
4. Once enough players join, the game will start automatically
5. Take turns playing cards and follow the game rules
6. First player to get rid of all their cards wins!

## Game Rules

- Each player starts with 7 cards
- Players take turns clockwise
- On your turn, you can play a card matching the suit or value of the last card played
- If you cannot play a card, you must draw one from the deck
- First player to use all their cards wins

## Development

For hot-module reloading during development:

```bash
npm run dev
```

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## License

This project is open-source, licensed under the MIT license.
