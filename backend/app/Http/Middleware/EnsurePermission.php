<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    /**
     * Gate a route by ability (App\Support\Permissions), used as `->middleware('perm:desk.operate')`.
     * Several abilities mean "any of them": `perm:desk.operate,master_data.manage` lets the desk
     * read shelter areas and the Data Admin manage them. There's no role that clears every gate.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        $user = $request->user();

        foreach ($abilities as $ability) {
            if ($user?->hasPermission($ability)) {
                return $next($request);
            }
        }

        return response()->json(['message' => 'Forbidden'], 403);
    }
}
