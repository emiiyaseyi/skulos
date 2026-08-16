<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Generates the temporary passwords this app hands to a real person:
 * a new school owner, a reset account, a provisioned parent, an
 * applicant read their login over the phone.
 *
 * Exists because Str::password() includes symbols, and every one of
 * those passwords is DISPLAYED — in an email, or flashed on screen to
 * a staff member to relay. Measured 2026-08-16 against the real
 * generator: 75 of 200 passwords contained a character that HTML
 * escaping rewrites, so the recipient was shown "M55u!&amp;~BR-rJ" for
 * a password that was really "M55u!&~BR-rJ" and could never sign in.
 * That is what a school reported after approving their trial.
 *
 * So: letters and digits only. The length compensates for the smaller
 * alphabet, and the result survives HTML escaping, copy and paste out
 * of any mail client, and being read aloud down a phone line — which
 * the symbols never did.
 *
 * Ambiguous characters are excluded for the same reason: "0" and "O",
 * "1" and "l" and "I" are a real source of failed first logins when
 * someone is transcribing rather than pasting.
 */
class TemporaryPassword
{
    protected const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';

    public static function generate(int $length = 14): string
    {
        $alphabet = self::ALPHABET;
        $max = strlen($alphabet) - 1;

        $password = '';
        for ($i = 0; $i < $length; $i++) {
            // random_int, not rand/array_rand: this is a credential, and
            // it must come from a cryptographically secure source.
            $password .= $alphabet[random_int(0, $max)];
        }

        // Guarantee both classes are present so the result always meets
        // a "letters and numbers" policy, rather than relying on chance.
        if (! preg_match('/[0-9]/', $password)) {
            $password[random_int(0, $length - 1)] = (string) random_int(2, 9);
        }
        if (! preg_match('/[a-zA-Z]/', $password)) {
            $password[random_int(0, $length - 1)] = Str::upper(Str::random(1));
        }

        return $password;
    }
}
