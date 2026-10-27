<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Personne;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Auth;
use Tymon\JWTAuth\JWTGuard;

abstract class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    /**
     * Utilisateur authentifié, typé App\Models\User (routes protégées par auth:api).
     */
    protected function currentUser(): User
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            abort(401, 'Non authentifié.');
        }

        return $user;
    }

    /**
     * Guard JWT typé (login()/refresh()/factory() correctement reconnus par PHPStan).
     */
    protected function jwtGuard(): JWTGuard
    {
        $guard = auth('api');

        if (! $guard instanceof JWTGuard) {
            throw new \RuntimeException('Le guard "api" doit utiliser le driver jwt.');
        }

        return $guard;
    }

    /**
     * Personne authentifiée (routes protégées par auth:personne).
     */
    protected function currentPersonne(): Personne
    {
        $personne = Auth::guard('personne')->user();

        if (! $personne instanceof Personne) {
            abort(401, 'Non authentifié.');
        }

        return $personne;
    }

    /**
     * Guard JWT des personnes (espace « Ma santé »).
     */
    protected function personneGuard(): JWTGuard
    {
        $guard = auth('personne');

        if (! $guard instanceof JWTGuard) {
            throw new \RuntimeException('Le guard "personne" doit utiliser le driver jwt.');
        }

        return $guard;
    }

    /**
     * Restreint une requête aux enregistrements de l'établissement de l'utilisateur courant.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected function scopedToEtablissement(Builder $query): Builder
    {
        return $query->where('etablissement_id', $this->currentUser()->etablissement_id);
    }

    /**
     * Interrompt la requête (403) si le modèle n'appartient pas à l'établissement courant.
     */
    protected function authorizeSameEtablissement(Model $model): void
    {
        if ($model->getAttribute('etablissement_id') !== $this->currentUser()->etablissement_id) {
            abort(403, 'Accès refusé.');
        }
    }
}
