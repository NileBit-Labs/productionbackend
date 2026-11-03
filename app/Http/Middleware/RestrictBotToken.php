<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/** A bot credential can trade within existing roles, but cannot administer identities. */
class RestrictBotToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();
        if ($token instanceof PersonalAccessToken && in_array('pos:bot', $token->abilities, true)) {
            abort_unless($request->user()->status === 'active', 403, 'This account has been deactivated.');
            $allowed = $request->is('api/auth/me', 'api/auth/logout', 'api/bot/*', 'api/pos/*', 'api/products*',
                'api/customers*', 'api/suppliers*', 'api/purchases*', 'api/sales*', 'api/inventory*',
                'api/expenses*', 'api/shifts*', 'api/reports*', 'api/recipes*', 'api/production*',
                'api/wastage*', 'api/units*', 'api/delivery*', 'api/fulfillment*', 'api/categories*');
            abort_unless($allowed, 403, 'Reconnect through the web app to manage accounts, security or business settings.');
        }

        return $next($request);
    }
}
