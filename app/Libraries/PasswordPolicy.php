<?php

namespace App\Libraries;

/**
 * One place for the password rules, so every endpoint
 * (reset, change, create user, register patient) checks the same thing.
 *
 * Rules: at least 8 characters, with an uppercase letter, a lowercase
 * letter, a number, and a special character.
 */
class PasswordPolicy
{
    public const MESSAGE = 'Password must be at least 8 characters and include an uppercase letter, a lowercase letter, a number, and a special character.';

    /**
     * Returns null when the password is acceptable,
     * or an error message string when it is not.
     */
    public static function check(string $password): ?string
    {
        if (strlen($password) < 8
            || strlen($password) > 72                 // bcrypt only reads the first 72 bytes
            || !preg_match('/[A-Z]/', $password)
            || !preg_match('/[a-z]/', $password)
            || !preg_match('/[0-9]/', $password)
            || !preg_match('/[^A-Za-z0-9]/', $password)
        ) {
            return self::MESSAGE;
        }

        return null;
    }
}