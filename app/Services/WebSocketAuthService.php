<?php

namespace App\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Models\User;

class WebSocketAuthService
{
    private string $secret;
    private string $algorithm;
    private array $blacklistedTokens = [];
    private ?string $serverToken = null;
    private LoggerService $logger;
    private const SERVER_TOKEN = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpYXQiOjE3NDkxNzI4OTQsImV4cCI6MTc0OTI1OTI5NCwidHlwZSI6InNlcnZlciJ9.mwLbFDbTN2OG2iD-0HHQuwxVRGmijHvIssB4BIwJP4k';

    public function __construct()
    {
        $this->secret = config('app.key');
        $this->algorithm = 'HS256';
        $this->logger = new LoggerService('websocket_auth.log');
    }

    public function generateUserToken(User $user): array
    {
        $issuedAt = time();
        $expirationTime = $issuedAt + (60 * 60 * 24); // 24 hours

        $payload = [
            'iat' => $issuedAt,
            'exp' => $expirationTime,
            'user_id' => $user->id,
            'username' => $user->name,
            'type' => 'user'
        ];

        $token = JWT::encode($payload, $this->secret, $this->algorithm);
        
        // $this->logger->info("User token generated", [
        //     'user_id' => $user->id,
        //     'username' => $user->name
        // ]);

        return [
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email
            ]
        ];
    }

    public function generateServerToken(): string
    {
        return self::SERVER_TOKEN;
    }

    public function validateToken(string $token): ?array
    {
        if (in_array($token, $this->blacklistedTokens)) {
            $this->logger->warning("Blacklisted token used");
            return null;
        }

        try {
            // First try to validate as JWT token
            try {
                $decoded = JWT::decode($token, new Key($this->secret, $this->algorithm));
                $data = (array) $decoded;

                // Check if it's a server token
                if (isset($data['type']) && $data['type'] === 'server') {
                    // For server tokens, just check if it's our fixed token
                    if ($token === $this->generateServerToken()) {
                        return $data;
                    }
                    $this->logger->warning("Invalid server token");
                    return null;
                }

                // For user tokens, verify with Laravel's auth
                if (isset($data['type']) && $data['type'] === 'user') {
                    // Check if user exists
                    $user = User::find($data['user_id']);
                    if (!$user) {
                        $this->logger->warning("Invalid user", ['user_id' => $data['user_id']]);
                        return null;
                    }

                    return [
                        'user_id' => $user->id,
                        'username' => $user->name,
                        'type' => 'user'
                    ];
                }
            } catch (\Exception $e) {
                // If JWT validation fails, try to validate as ws_token
                $user = User::where('ws_token', $token)->first();
                if ($user) {
                    return [
                        'user_id' => $user->id,
                        'username' => $user->name,
                        'type' => 'user'
                    ];
                }
            }

            return null;
        } catch (\Exception $e) {
            $this->logger->error("Token validation failed", [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    public function blacklistToken(string $token): void
    {
        $this->blacklistedTokens[] = $token;
        $this->logger->info("Token blacklisted");
    }

    public function refreshToken(string $token): ?string
    {
        $data = $this->validateToken($token);
        if ($data === null) {
            return null;
        }

        if (isset($data['type']) && $data['type'] === 'server') {
            // For server tokens, generate a new one
            return $this->generateServerToken();
        }

        // For user tokens, generate a new one with user data
        $user = User::find($data['user_id']);
        if (!$user) {
            return null;
        }
        return $this->generateUserToken($user)['token'];
    }

    public function revokeServerToken(): void
    {
        if ($this->serverToken) {
            $this->blacklistToken($this->serverToken);
            $this->serverToken = null;
            $this->logger->info("Server token revoked");
        }
    }

    public function getServerToken(): ?string
    {
        return $this->serverToken;
    }

    public function validateUserCredentials(string $email, string $password): ?User
    {
        if (Auth::attempt(['email' => $email, 'password' => $password])) {
            return Auth::user();
        }
        return null;
    }

    public function generateTokenForUser(User $user): array
    {
        $token = $this->generateUserToken($user);
        return [
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email
            ]
        ];
    }

    public function validateServerToken(string $token): bool
    {
        $this->logger->info("Validating server token", [
            'token_length' => strlen($token),
            'expected_length' => strlen(self::SERVER_TOKEN)
        ]);

        // For server tokens, we just check if it matches our constant
        if ($token === self::SERVER_TOKEN) {
            $this->logger->info("Server token validated successfully");
            return true;
        }

        $this->logger->warning("Invalid server token");
        return false;
    }
} 