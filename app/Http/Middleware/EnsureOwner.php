<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isOwner()) {
            return response()->json(['message' => 'This action requires owner access.'], 403);
        }

        return $next($request);
    }
}
