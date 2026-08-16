<?php

namespace Tests\Feature;

use App\Support\TemporaryPassword;
use Tests\TestCase;

/**
 * Guards the fix for a real, reported failure (2026-08-16): a school
 * approved for a trial could not sign in with the temporary password
 * they were emailed.
 *
 * Cause: Str::password() includes symbols, and the credentials box in
 * the email escapes what it prints, so a password containing & < > "
 * was DISPLAYED differently from what was actually stored. Measured
 * against the real generator, 75 of 200 passwords were affected. The
 * recipient copied "M55u!&amp;~BR-rJ" for a password that was really
 * "M55u!&~BR-rJ".
 */
class TemporaryPasswordTest extends TestCase
{
    public function test_a_generated_password_is_never_altered_by_html_escaping(): void
    {
        // The whole bug in one assertion: what the email prints has to
        // be byte-for-byte what was stored.
        for ($i = 0; $i < 300; $i++) {
            $password = TemporaryPassword::generate();
            $this->assertSame($password, e($password),
                "Generated password [{$password}] renders differently once escaped, so it cannot be copied from an email.");
        }
    }

    public function test_it_avoids_characters_people_confuse_when_reading_one_out(): void
    {
        // Applicants are routinely read their login over the phone, and
        // 0/O and 1/l/I are where that goes wrong.
        for ($i = 0; $i < 100; $i++) {
            $this->assertDoesNotMatchRegularExpression('/[0O1lI]/', TemporaryPassword::generate());
        }
    }

    public function test_it_always_contains_both_letters_and_numbers(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $password = TemporaryPassword::generate();
            $this->assertMatchesRegularExpression('/[0-9]/', $password);
            $this->assertMatchesRegularExpression('/[a-zA-Z]/', $password);
        }
    }

    public function test_it_honours_the_requested_length_and_does_not_repeat_itself(): void
    {
        $this->assertSame(20, strlen(TemporaryPassword::generate(20)));

        $seen = [];
        for ($i = 0; $i < 200; $i++) {
            $seen[] = TemporaryPassword::generate();
        }
        $this->assertCount(200, array_unique($seen), 'Generated passwords repeated, which should not happen.');
    }
}
