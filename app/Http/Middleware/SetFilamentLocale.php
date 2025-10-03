<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SetFilamentLocale
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->is('admin*')) {
            app()->setLocale('ar');
        }
        return $next($request);
    }
}
