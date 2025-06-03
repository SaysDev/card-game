# WebSocket Server dla gry CardGame

## Instalacja OpenSwoole

Najpierw musisz zainstalować rozszerzenie OpenSwoole dla PHP:

### Ubuntu/Debian:

```bash
pecl install openswoole
echo "extension=openswoole.so" > /etc/php/8.2/cli/conf.d/50-openswoole.ini
```

### macOS (z Homebrew):

```bash
pecl install openswoole
echo "extension=openswoole.so" > $(php -i | grep php.ini | head -n 1 | awk '{print $5}')/conf.d/50-openswoole.ini
```

### Windows:

Zalecanym podejściem jest użycie WSL (Windows Subsystem for Linux) i zainstalowanie OpenSwoole jak dla Ubuntu/Debian.

## Uruchamianie serwera WebSocket

W projekcie CardGame istnieje kilka sposobów uruchomienia serwera WebSocket:

### 1. Standardowe uruchomienie (może powodować problemy z Xdebug):

```bash
php artisan websocket:start
```

### 2. Bezpieczne uruchomienie (omijające Xdebug):

```bash
php artisan websocket:start:safe
```

### 3. Przy użyciu skryptu shell (zalecane):

```bash
chmod +x start-websocket-server.sh
./start-websocket-server.sh
```

Możesz również określić port:

```bash
./start-websocket-server.sh -p 9505
```

## Testowanie połączenia

Po uruchomieniu serwera WebSocket, możesz przetestować połączenie otwierając przeglądarkę i przechodząc do sekcji gier w aplikacji. W konsoli przeglądarki powinieneś zobaczyć komunikaty dotyczące połączenia z serwerem WebSocket.

## Rozwiązywanie problemów

### Problem z Xdebug

Jeśli widzisz błąd związany z Xdebug:

```
PHP Fatal error: Uncaught ErrorException: OpenSwoole\Server::start(): Using Xdebug in coroutines is extremely dangerous, please notice that it may lead to coredump!
```

Użyj jednej z bezpiecznych metod uruchamiania serwera opisanych powyżej.

### Problemy z portami

Jeśli port 9502 (domyślny) jest już zajęty, możesz określić inny port:

```bash
php artisan websocket:start --port=9503
```

Pamiętaj, że musisz również zaktualizować port w konfiguracji klienta po stronie frontendu (w pliku useWebSocket.ts).

### Sprawdzanie działania serwera

Możesz sprawdzić, czy serwer działa poprawnie za pomocą narzędzia WebSocket CLI:

```bash
wscat -c ws://localhost:9502
```

Po podłączeniu, spróbuj wysłać wiadomość JSON:

```json
{"action":"list_rooms"}
```

Powinieneś otrzymać odpowiedź z listą dostępnych pokojów.
