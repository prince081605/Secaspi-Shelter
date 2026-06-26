<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    /**
     * Gate a route by minimum role rank. Used as `->middleware('role:staff')`, meaning
     * "staff or higher" — admin clears every threshold automatically. Mirrors the 403
     * shape of EnsureAdmin so authorization failures look identical everywhere.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $user = $request->user();

        if (! $user || ! $user->hasRoleAtLeast($role)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return $next($request);
    }
}
