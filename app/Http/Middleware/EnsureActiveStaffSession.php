<?php

namespace App\Http\Middleware;

use App\Support\StaffSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveStaffSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user !== null && ($user->suspended_at !== null
            || ($request->session()->has(Auth::guard()->getName())
                && $request->session()->get(StaffSession::VERSION) !== $user->staff_session_version))) {
            StaffSession::logout($request);

            if ($request->expectsJson()) {
                abort(401);
            }

            return redirect()->guest(route('login'));
        }

        $response = $next($request);
        if ($request->user() !== null) {
            $response->headers->set('Cache-Control', 'private, no-store');
        }

        return $response;
    }
}
