<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\SsoManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\Response;

/**
 * OAuth redirect + callback for enabled SSO providers. A disabled or
 * unconfigured provider returns 404 so its existence is not advertised.
 */
class SsoController extends Controller
{
    public function __construct(protected SsoManager $sso)
    {
    }

    public function redirect(string $provider): Response
    {
        abort_unless($this->sso->isAvailable($provider), 404);

        return Socialite::driver($provider)->redirect();
    }

    public function callback(string $provider): RedirectResponse
    {
        abort_unless($this->sso->isAvailable($provider), 404);

        $identity = Socialite::driver($provider)->user();
        $user = $this->sso->resolveUser($provider, $identity);

        Auth::login($user, remember: true);
        request()->session()?->regenerate();

        return redirect()->intended('/');
    }
}
