# CardGame Development Guidelines

This document provides guidelines and information for developers working on the CardGame project.

## Build/Configuration Instructions

### Requirements

- PHP 8.2+
- Composer
- Node.js and NPM
- OpenSwoole PHP extension

### Installation

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

### Running the Application

1. Start the Laravel development server:
   ```bash
   php artisan serve
   ```

2. Start the WebSocket server in a separate terminal window using one of these methods:

   - Standard method (may cause issues with Xdebug):
     ```bash
     php artisan websocket:start
     ```

   - Safe method (bypassing Xdebug):
     ```bash
     php artisan websocket:start:safe
     ```

   - Using the shell script (recommended):
     ```bash
     chmod +x start-websocket-server.sh
     ./start-websocket-server.sh
     ```

   - Specify a custom port:
     ```bash
     ./start-websocket-server.sh -p 9505
     ```

3. Access the application in your browser at: http://localhost:8000

### Installing OpenSwoole

#### Ubuntu/Debian:
```bash
pecl install openswoole
echo "extension=openswoole.so" > /etc/php/8.2/cli/conf.d/50-openswoole.ini
```

#### macOS (with Homebrew):
```bash
pecl install openswoole
echo "extension=openswoole.so" > $(php -i | grep php.ini | head -n 1 | awk '{print $5}')/conf.d/50-openswoole.ini
```

#### Windows:
It's recommended to use WSL (Windows Subsystem for Linux) for OpenSwoole development on Windows.

### WebSocket Troubleshooting

#### Xdebug Issues
If you see an error related to Xdebug:
```
PHP Fatal error: Uncaught ErrorException: OpenSwoole\Server::start(): Using Xdebug in coroutines is extremely dangerous, please notice that it may lead to coredump!
```
Use one of the safe methods for starting the WebSocket server described above.

#### Port Issues
If port 9502 (default) is already in use, you can specify a different port:
```bash
php artisan websocket:start --port=9503
```
Remember to also update the port in the frontend client configuration (in the useWebSocket.ts file).

#### Testing WebSocket Connection
You can check if the server is working correctly using the WebSocket CLI tool:
```bash
wscat -c ws://localhost:9502
```
After connecting, try sending a JSON message:
```json
{"action":"list_rooms"}
```
You should receive a response with a list of available rooms.

## Testing Information

### Testing Framework

The project uses Pest, a testing framework built on top of PHPUnit that provides a more expressive syntax. Tests are located in the `tests` directory, which contains:

- `Feature`: For feature/integration tests
- `Unit`: For unit tests

### Running Tests

To run all tests:
```bash
./vendor/bin/pest
```

To run a specific test file:
```bash
./vendor/bin/pest tests/path/to/test/file.php
```

To run tests with coverage report (requires Xdebug or PCOV):
```bash
./vendor/bin/pest --coverage
```

### Writing Tests

#### Test Structure

Tests are written using Pest's syntax. Here's an example:

```php
<?php

use App\Models\Game;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('can create a game', function () {
    $game = Game::create([
        'name' => 'Test Game',
        'status' => 'waiting',
        'max_players' => 4,
        'current_players' => 1,
        'game_data' => ['deck' => ['card1', 'card2']]
    ]);

    expect($game)->toBeInstanceOf(Game::class)
        ->and($game->name)->toBe('Test Game')
        ->and($game->status)->toBe('waiting')
        ->and($game->max_players)->toBe(4)
        ->and($game->current_players)->toBe(1)
        ->and($game->game_data)->toBe(['deck' => ['card1', 'card2']]);
});
```

#### Important Traits

- `RefreshDatabase`: Resets the database after each test
- `WithFaker`: Provides access to the Faker library for generating test data

#### Database Testing

The project uses SQLite in-memory database for testing, as configured in `phpunit.xml`. This ensures tests run quickly and don't affect your development database.

## Code Style Guidelines

### PHP

- Follow PSR-12 coding standards
- Use type hints for method parameters and return types
- Document classes and methods with PHPDoc comments

### JavaScript/TypeScript

The project uses ESLint and Prettier for code formatting:

- Single quotes for strings
- Semicolons are required
- 4-space indentation (2 spaces for YAML files)
- 150 character line length limit

### Vue.js

- Component names don't need to be multi-word (the `vue/multi-word-component-names` rule is disabled)
- Follow the Vue.js Style Guide for component structure

### Editor Configuration

The project includes configuration files for consistent code style:

- `.editorconfig`: Basic editor settings
- `.prettierrc`: JavaScript/TypeScript formatting
- `eslint.config.js`: JavaScript/TypeScript linting

### Running Linters

To check and fix JavaScript/TypeScript code style:
```bash
npm run lint
```

To format code with Prettier:
```bash
npm run format
```

## Development Workflow

For hot-module reloading during frontend development:
```bash
npm run dev
```

This will start Vite's development server, which provides fast hot module replacement.
