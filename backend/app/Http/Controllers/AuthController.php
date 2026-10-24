<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => 'required|string|max:120', 'company' => 'required|string|max:150',
            'email' => 'required|email|max:200|unique:users,email', 'password' => ['required', 'confirmed', Password::min(12)]]);
        [$user, $companyId] = DB::transaction(function () use ($data) {
            $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password']]);
            $id = DB::table('companies')->insertGetId(['name' => $data['company'], 'trial_ends_at' => now()->addDays(14), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('memberships')->insert(['company_id' => $id, 'user_id' => $user->id, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);

            return [$user, $id];
        });
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('company_id', $companyId);

        return $this->me($request);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        if (! Auth::attempt($data)) {
            throw ValidationException::withMessages(['email' => 'Adresse email ou mot de passe incorrect.']);
        }
        $request->session()->regenerate();
        $request->session()->put('company_id', DB::table('memberships')->where('user_id', Auth::id())->where('is_active', true)->value('company_id'));

        return $this->me($request);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $request->user(), 'company' => Tenant::company($request),
            'role' => Tenant::role($request), 'role_name' => collect(Tenant::roleOptions(Tenant::company($request)->id))->firstWhere('key', Tenant::role($request))['name'] ?? 'Accès personnalisé', 'permissions' => Tenant::effectivePermissions($request)]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['ok' => true]);
    }
}
