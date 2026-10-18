<?php

declare(strict_types=1);

namespace Geni\Laravel;

/**
 * Reads and checks the isolated docs credentials in config('geni.docs_auth').
 *
 * The gate is switched on as soon as either value is filled. When only one
 * of them is filled the gate fails closed instead of letting a blank value
 * match a blank submission.
 */
final class DocsCredentials
{
    /**
     * Determine whether the docs auth gate should protect the docs routes.
     */
    public static function configured(): bool
    {
        return filled(config('geni.docs_auth.username')) || filled(config('geni.docs_auth.password'));
    }

    /**
     * Compare submitted credentials against config in constant time.
     */
    public static function matches(?string $username, ?string $password): bool
    {
        $expectedUsername = (string) config('geni.docs_auth.username');
        $expectedPassword = (string) config('geni.docs_auth.password');

        if ($expectedUsername === '' || $expectedPassword === '') {
            return false;
        }

        $usernameMatches = hash_equals($expectedUsername, (string) $username);
        $passwordMatches = hash_equals($expectedPassword, (string) $password);

        return $usernameMatches && $passwordMatches;
    }
}
