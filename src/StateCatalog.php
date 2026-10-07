<?php
declare(strict_types=1);

namespace IamAngusU\MagicLink;

use RuntimeException;

final class StateCatalog
{
    /** @var array<string,string> */
    private array $states;

    public function __construct(string $root, string $locale)
    {
        $path = rtrim($root, '/\\') . '/resources/states/' . $locale . '.json';
        $states = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($states)) {
            throw new RuntimeException('State catalog is invalid.');
        }
        $this->states = array_map('strval', $states);
    }

    public function message(string $state): string
    {
        return $this->states[$state] ?? $this->states[MagicLinkState::Failed->value] ?? 'State unavailable.';
    }
}
