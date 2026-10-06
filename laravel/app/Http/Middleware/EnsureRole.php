<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureRole
{
    public function handle(Request $request, Closure $next, string $role = 'admin')
    {
        abort_unless($request->user()?->role === $role, 403);

        return $next($request);
    }
}
