<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): Response
    {
        return Inertia::render('admin/users/index', [
            'users' => User::query()
                ->with('roles:id,name')
                ->latest('id')
                ->paginate(10)
                ->through(fn (User $user): array => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'roles' => $user->roles->pluck('name')->values(),
                    'created_at' => $user->created_at?->toISOString(),
                    'can_delete' => auth()->id() !== $user->id,
                ]),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): Response
    {
        return Inertia::render('admin/users/create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreUserRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $user = User::query()->create(Arr::only($validated, ['name', 'email', 'password']));

        $this->syncAdminRole($user, (bool) ($validated['is_admin'] ?? false));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User created.')]);

        return to_route('admin.users.show', $user);
    }

    /**
     * Display the specified resource.
     */
    public function show(User $user): Response
    {
        $user->loadMissing('roles:id,name');

        return Inertia::render('admin/users/show', [
            'user' => $this->userPayload($user),
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(User $user): Response
    {
        $user->loadMissing('roles:id,name');

        return Inertia::render('admin/users/edit', [
            'user' => $this->userPayload($user),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $validated = $request->validated();
        $attributes = Arr::only($validated, ['name', 'email']);

        if (filled($validated['password'] ?? null)) {
            $attributes['password'] = $validated['password'];
        }

        $user->update($attributes);
        $this->syncAdminRole($user, (bool) ($validated['is_admin'] ?? false));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User updated.')]);

        return to_route('admin.users.show', $user);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(User $user): RedirectResponse
    {
        abort_if(auth()->id() === $user->id, 403);

        $user->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User deleted.')]);

        return to_route('admin.users.index');
    }

    private function syncAdminRole(User $user, bool $isAdmin): void
    {
        $adminRole = Role::findOrCreate('admin', 'web');

        if ($isAdmin) {
            $user->assignRole($adminRole);

            return;
        }

        if ($user->hasRole($adminRole)) {
            $user->removeRole($adminRole);
        }
    }

    /**
     * @return array{id: int, name: string, email: string, roles: list<string>, is_admin: bool, can_delete: bool, created_at: string|null, updated_at: string|null}
     */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->roles->pluck('name')->values()->all(),
            'is_admin' => $user->hasRole('admin'),
            'can_delete' => auth()->id() !== $user->id,
            'created_at' => $user->created_at?->toISOString(),
            'updated_at' => $user->updated_at?->toISOString(),
        ];
    }
}
