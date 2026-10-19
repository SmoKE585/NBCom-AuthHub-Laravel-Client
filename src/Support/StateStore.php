<?php

declare(strict_types=1);

namespace Nbcom\SsoAuth\Support;

/**
 * Хранит выданные state в сессии.
 *
 * state одноразовый: callback изымает запись через pull(). Просроченные
 * записи вычищаются при каждой записи, поэтому сессия не растёт бесконечно.
 */
final class StateStore
{
    /**
     * @param array<string, mixed> $payload
     */
    public function put(string $state, array $payload): void
    {
        $key = $this->key();
        $max = max(1, (int) config('sso-auth.session.max_states', 10));
        $now = time();

        $states = $this->all();

        $states = array_filter(
            $states,
            static fn (mixed $item): bool => is_array($item)
                && isset($item['exp'])
                && (int) $item['exp'] >= $now,
        );

        $states[$state] = $payload;

        if (count($states) > $max) {
            $states = array_slice($states, -$max, null, true);
        }

        session()->put($key, $states);
    }

    /**
     * Возвращает запись и удаляет её из сессии.
     *
     * @return array<string, mixed>|null
     */
    public function pull(string $state): ?array
    {
        $key = $this->key();
        $states = $this->all();
        $record = $states[$state] ?? null;

        unset($states[$state]);
        session()->put($key, $states);

        if (! is_array($record)) {
            return null;
        }

        $exp = (int) ($record['exp'] ?? 0);

        if ($exp <= 0 || $exp < time()) {
            return null;
        }

        return $record;
    }

    /**
     * @return array<string, mixed>
     */
    private function all(): array
    {
        $states = session()->get($this->key(), []);

        return is_array($states) ? $states : [];
    }

    private function key(): string
    {
        return (string) config('sso-auth.session.states_key', 'sso-auth.states');
    }
}
