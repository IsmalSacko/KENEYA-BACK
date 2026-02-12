<?php

namespace App\Policies;

use App\Models\Etablissement;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class EtablissementPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
        // ou $user->role === 'admin'
    }

    public function view(User $user, Etablissement $etablissement): bool
    {
        return $user->etablissement_id === $etablissement->id;
    }

    public function create(User $user): bool
    {
        return $user->role === 'admin';
    }

    public function update(User $user, Etablissement $etablissement): bool
    {
        return $user->role === 'admin'
            && $user->etablissement_id === $etablissement->id;
    }

    public function delete(User $user, Etablissement $etablissement): bool
    {
        return $user->role === 'admin'
            && $user->etablissement_id === $etablissement->id;
    }
}
