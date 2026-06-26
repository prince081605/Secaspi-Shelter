<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActive
{
    /**
     * Reject any authenticated request from a suspended account. This is the
     * defence-in-depth backstop to the login block: it stops a user who already
     * holds a valid token (issued before they were suspended) from continuing to
     * use the API, and returns the same suspended message + reason the login does.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->isSuspended()) {
            return response()->json([
                'message' => $user->suspensionMessage(),
                'status'  => 'suspended',
                'reason'  => $user->suspension_reason,
            ], 403);
        }

        return $next($request);
    }
}
