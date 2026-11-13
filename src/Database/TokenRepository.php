<?php

namespace FullSmack\EnvironmentGate\Database;

use DateTimeInterface;
use FullSmack\EnvironmentGate\Contracts\CredentialProvider;
use FullSmack\EnvironmentGate\Credential;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Access tokens stored in the database, managed from the CLI.
 *
 * Only a SHA-256 hash of each token is stored. Tokens are high-entropy
 * random strings, so a plain hash is sufficient and allows an indexed
 * lookup. The hash doubles as the cookie signing secret, so deleting or
 * replacing a token invalidates every cookie issued for it.
 */
class TokenRepository implements CredentialProvider
{
    /** How often last_used_at is refreshed, to avoid a write per request. */
    protected const TOUCH_INTERVAL = 900;

    public function __construct(
        protected ConnectionResolverInterface $db,
        protected ?string $connection,
        protected string $table,
        protected ?Cache $cache = null,
        protected int $cacheSeconds = 0,
        protected ?LoggerInterface $logger = null,
    ) {}

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function match(string $input): ?Credential
    {
        if ($input === '')
        {
            return null;
        }

        try {
            $row = $this->query()->where('token_hash', static::hash($input))->first();

            if ($row === null || $this->isExpired($row))
            {
                return null;
            }

            $this->touch($row);

            return $this->toCredential($row);
        }
        catch (Throwable $e)
        {
            $this->failed($e);

            return null;
        }
    }

    public function find(string $label): ?Credential
    {
        try {
            $row = $this->cachedRow($label);
        }
        catch (Throwable $e)
        {
            $this->failed($e);

            return null;
        }

        return $row === null || $this->isExpired($row) ? null : $this->toCredential($row);
    }

    public function exists(string $label): bool
    {
        return $this->query()->where('label', $label)->exists();
    }

    /**
     * Store a token, replacing any existing token with the same label.
     */
    public function store(string $label, string $token, ?DateTimeInterface $expiresAt = null, ?string $description = null): void
    {
        $now = Carbon::now();

        $this->query()->where('label', $label)->delete();
        $this->query()->insert([
            'label' => $label,
            'token_hash' => static::hash($token),
            'description' => $description,
            'expires_at' => $expiresAt ? Carbon::instance($expiresAt) : null,
            'last_used_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->forget($label);
    }

    public function revoke(string $label): bool
    {
        $deleted = $this->query()->where('label', $label)->delete() > 0;

        $this->forget($label);

        return $deleted;
    }

    /**
     * @return list<string> the labels of the removed tokens
     */
    public function purgeExpired(): array
    {
        $labels = $this->query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', Carbon::now())
            ->pluck('label')
            ->all();

        if ($labels !== [])
        {
            $this->query()->whereIn('label', $labels)->delete();
            array_map(fn (string $label) => $this->forget($label), $labels);
        }

        return $labels;
    }

    /**
     * @return list<object{label: string, description: ?string, expires_at: ?string, last_used_at: ?string, created_at: ?string}>
     */
    public function all(): array
    {
        return $this->query()
            ->orderBy('label')
            ->get(['label', 'description', 'expires_at', 'last_used_at', 'created_at'])
            ->all();
    }

    protected function cachedRow(string $label): ?object
    {
        if (! $this->cache || $this->cacheSeconds <= 0)
        {
            return $this->query()->where('label', $label)->first();
        }

        $key = $this->cacheKey($label);
        $cached = $this->cache->get($key);

        if ($cached !== null)
        {
            return $cached === false ? null : (object) $cached;
        }

        $row = $this->query()->where('label', $label)->first(['label', 'token_hash', 'expires_at']);

        // Misses are cached too, so revoked cookies don't query on every request.
        $this->cache->put($key, $row ? (array) $row : false, $this->cacheSeconds);

        return $row;
    }

    protected function touch(object $row): void
    {
        $lastUsed = $row->last_used_at ?? null;

        if ($lastUsed !== null && Carbon::parse($lastUsed)->getTimestamp() > time() - static::TOUCH_INTERVAL)
        {
            return;
        }

        $this->query()->where('label', $row->label)->update(['last_used_at' => Carbon::now()]);
    }

    protected function isExpired(object $row): bool
    {
        return $row->expires_at !== null && Carbon::parse($row->expires_at)->getTimestamp() <= time();
    }

    protected function toCredential(object $row): Credential
    {
        return new Credential(
            $row->label,
            $row->token_hash,
            Credential::SOURCE_DATABASE,
            $row->expires_at !== null ? Carbon::parse($row->expires_at)->getTimestamp() : null,
        );
    }

    protected function forget(string $label): void
    {
        $this->cache?->forget($this->cacheKey($label));
    }

    protected function cacheKey(string $label): string
    {
        return 'environment-gate:token:'.sha1(($this->connection ?? '').'|'.$this->table.'|'.$label);
    }

    protected function failed(Throwable $e): void
    {
        // Fail closed: a missing table or unreachable database locks the
        // environment (configured codes keep working) instead of a 500.
        $this->logger?->warning('Environment gate: token lookup failed', ['exception' => $e]);
    }

    protected function query(): Builder
    {
        return $this->db->connection($this->connection)->table($this->table);
    }
}
