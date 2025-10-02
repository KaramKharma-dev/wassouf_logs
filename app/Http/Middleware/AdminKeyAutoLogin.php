<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AdminKeyAutoLogin
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->is('admin*') && !Auth::check()) {
            $key = $request->query('admin_key');
            if ($key && hash_equals((string) env('ADMIN_KEY', ''), (string) $key)) {
                $email = env('ADMIN_EMAIL', 'admin@example.com');
                $pass  = env('ADMIN_PASSWORD', 'Strong#Pass123');

                $user = User::updateOrCreate(
                    ['email' => $email],
                    ['name' => 'Admin', 'password' => Hash::make($pass), 'is_admin' => true]
                );

                Auth::guard('web')->login($user, remember: true);

                // احذف المفتاح من العنوان بعد النجاح
                return redirect()->to($request->fullUrlWithoutQuery('admin_key'));
            }
        }
        return $next($request);
    }
}
