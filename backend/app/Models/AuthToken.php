<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class AuthToken extends Model
{
    public const ACCESS_TOKEN_TTL_MINUTES = 60;

    public const REFRESH_TOKEN_TTL_DAYS = 30;

    protected $table = 'auth_tokens';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $guarded = [];

    protected $casts = [
        'access_token_expires_at' => 'datetime',
        'refresh_token_expires_at' => 'datetime',
        'last_used_at' => 'datetime',
    ];

    public static function issueForUser(User $user): array
    {
        $token = new self();
        $token->id = (string) Str::uuid();
        $token->user()->associate($user);

        [$accessToken, $refreshToken] = $token->generateTokens();

        $token->save();

        return [
            'accessToken' => $accessToken,
            'refreshToken' => $refreshToken,
        ];
    }

    public static function resolveValidAccessToken(string $token): ?self
    {
        return self::query()
            ->with('user')
            ->where('access_token_hash', self::hashToken($token))
            ->where(function ($query): void {
                $query->whereNull('access_token_expires_at')
                    ->orWhere('access_token_expires_at', '>', Carbon::now());
            })
            ->first();
    }

    public static function resolveValidRefreshToken(string $token): ?self
    {
        return self::query()
            ->with('user')
            ->where('refresh_token_hash', self::hashToken($token))
            ->where(function ($query): void {
                $query->whereNull('refresh_token_expires_at')
                    ->orWhere('refresh_token_expires_at', '>', Carbon::now());
            })
            ->first();
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function generatePlainToken(): string
    {
        return Str::random(64);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function rotateTokens(): array
    {
        [$accessToken, $refreshToken] = $this->generateTokens();

        $this->save();

        return [
            'accessToken' => $accessToken,
            'refreshToken' => $refreshToken,
        ];
    }

    public function markAccessTokenUsed(): void
    {
        $this->forceFill([
            'last_used_at' => Carbon::now(),
        ])->save();
    }

    protected function generateTokens(): array
    {
        $accessToken = self::generatePlainToken();
        $refreshToken = self::generatePlainToken();

        $this->access_token_hash = self::hashToken($accessToken);
        $this->refresh_token_hash = self::hashToken($refreshToken);
        $this->access_token_expires_at = Carbon::now()->addMinutes(self::ACCESS_TOKEN_TTL_MINUTES);
        $this->refresh_token_expires_at = Carbon::now()->addDays(self::REFRESH_TOKEN_TTL_DAYS);

        return [$accessToken, $refreshToken];
    }
}
