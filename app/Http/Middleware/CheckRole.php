<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  ...$roles
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        // 1. Ensure the user is actually logged in
        if (!auth()->check()) {
            return redirect()->route('login');
        }

        // 2. Check if the user's role matches any of the allowed roles passed to the route
        if (!in_array(auth()->user()->role, $roles)) {
            // If they don't belong here, throw a 403 Forbidden error
            abort(403, 'Unauthorized Access. You do not have the correct permissions to view this page.');
        }

        // 3. User is authorized, let them proceed
        return $next($request);
    }
}