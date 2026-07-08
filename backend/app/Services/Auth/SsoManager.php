<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Auditing\Recorder;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use RuntimeException;

/**
 * Single sign on policy and account linking (Google, Microsoft/Outlook).
 *
 * A provider is only "available" when it is BOTH enabled (config/skulos.php)
 * and configured with credentials (config/services.php). SSO ties to the
 * verified email, which is the platform identity key (parent unique ID is
 * email), so signing in with Google and with Outlook using the same email
 * resolves to the same account. New SSO accounts are created without a
 * usable password and are not super admins.
 */
class SsoManager
{
    public const PROVIDERS = ['google', 'microsoft'];

    public function available(): array
    {
        return array_values(array_filter(self::PROVIDERS, fn ($p) => $this->isAvailable($p)));
    }

    public function isAvailable(string $provider): bool
    {
        if (! in_array($provider, self::PROVIDERS, true)) {
            return false;
        }

        $enabled = (bool) config("skulos.sso.{$provider}.enabled");
        $configured = filled(config("services.{$provider}.client_id"))
            && filled(config("services.{$provider}.client_secret"));

        return $enabled && $configured;
    }

    public function label(string $provider): string
    {
        return (string) config("skulos.sso.{$provider}.label", Str::headline($provider));
    }

    /**
     * Find or create the platform user for a verified SSO identity.
     */
    public function resolveUser(string $provider, SocialiteUser $identity): User
    {
        $email = $identity->getEmail();
        if (! $email) {
            // Without a verified email we cannot safely tie the identity to
            // an account, so we refuse rather than guess.
            throw new RuntimeException('The sign in provider did not return an email address.');
        }

        $email = Str::lower($email);
        $user = User::where('email', $email)->first();

        if (! $user) {
            $user = new User([
                'name' => $identity->getName() ?: Str::before($email, '@'),
                'email' => $email,
            ]);
            // No usable password: SSO accounts sign in through the provider.
            $user->password = bcrypt(Str::random(40));
            $user->email_verified_at = now();
            $user->save();

            app(Recorder::class)->record('sso.user_provisioned', $user, [], ['provider' => $provider]);
        }

        app(Recorder::class)->record('sso.login', $user, [], ['provider' => $provider]);

        return $user;
    }
}
