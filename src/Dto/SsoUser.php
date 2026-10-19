<?php

declare(strict_types=1);

namespace Nbcom\SsoAuth\Dto;

use Nbcom\SsoAuth\Exceptions\InvalidCallbackException;

/**
 * Профиль пользователя, полученный от ЕЦА NBCom.
 *
 * Пакет ничего не пишет в БД и не логинит пользователя: DTO отдаётся
 * приложению, а решение остаётся за ним.
 */
final readonly class SsoUser
{
    /**
     * @param list<int|string> $departments
     * @param list<string> $departmentsName
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public int $bitrixId,
        public string $name,
        public ?string $firstName,
        public ?string $lastName,
        public ?string $workPosition,
        public ?string $avatar,
        public ?string $gender,
        public ?string $birthday,
        public array $departments,
        public array $departmentsName,
        public array $raw = [],
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(array $payload, ?int $fallbackBitrixId = null): self
    {
        $bitrixId = (int) ($payload['bitrix_id'] ?? $fallbackBitrixId ?? 0);

        if ($bitrixId <= 0) {
            throw new InvalidCallbackException('В ответе авторизации отсутствует идентификатор пользователя.');
        }

        return new self(
            bitrixId: $bitrixId,
            name: self::string($payload['name'] ?? null) ?? '',
            firstName: self::string($payload['firstName'] ?? null),
            lastName: self::string($payload['lastName'] ?? null),
            workPosition: self::string($payload['workPosition'] ?? null),
            avatar: self::string($payload['avatar'] ?? null),
            gender: self::string($payload['gender'] ?? null),
            birthday: self::string($payload['birthday'] ?? null),
            departments: self::scalarList($payload['departments'] ?? []),
            departmentsName: self::stringList($payload['departments_name'] ?? []),
            raw: $payload,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'bitrix_id' => $this->bitrixId,
            'name' => $this->name,
            'firstName' => $this->firstName,
            'lastName' => $this->lastName,
            'workPosition' => $this->workPosition,
            'avatar' => $this->avatar,
            'gender' => $this->gender,
            'birthday' => $this->birthday,
            'departments' => $this->departments,
            'departments_name' => $this->departmentsName,
        ];
    }

    public function displayName(): string
    {
        if ($this->name !== '') {
            return $this->name;
        }

        $parts = array_filter([$this->lastName, $this->firstName], static fn (?string $v): bool => $v !== null && $v !== '');

        return implode(' ', $parts);
    }

    private static function string(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @return list<int|string>
     */
    private static function scalarList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];

        foreach ($value as $item) {
            if (is_int($item) || (is_string($item) && trim($item) !== '')) {
                $out[] = $item;
            }
        }

        return array_values($out);
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];

        foreach ($value as $item) {
            if (! is_scalar($item)) {
                continue;
            }

            $item = trim((string) $item);

            if ($item !== '') {
                $out[] = $item;
            }
        }

        return array_values($out);
    }
}
