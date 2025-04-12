<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request; // <-- Import the Request class

class Authenticate extends Middleware
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return string|null
     */
    protected function redirectTo(Request $request): ?string // <-- Add Request type hint and nullable return type
    {
        // If the request does NOT expect JSON (e.g., a regular browser request)
        // then redirect to the login route name (if one exists for web).
        // If it DOES expect JSON (an API request), return null.
        if (! $request->expectsJson()) {
            // You might not even have a web login route named 'login',
            // in which case this could still error for web routes, but it fixes the API issue.
            // If you have no web login, you might just return null always,
            // but checking expectsJson() is the standard practice.
            return route('login');
        }

        // Returning null for JSON requests tells Laravel not to redirect,
        // allowing the default exception handler to return a 401 JSON response.
        return null;
    }
}