<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! method_exists($user, 'role') || ! $user->role->equals(UserRole::Admin())) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
