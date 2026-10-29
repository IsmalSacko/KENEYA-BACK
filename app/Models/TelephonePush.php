<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un téléphone où la personne reçoit ses notifications : le jeton Firebase
 * que l'app donne à la connexion.
 *
 * @property int $id
 * @property int $personne_id
 * @property string $jeton
 * @property string $plateforme
 */
class TelephonePush extends Model
{
    protected $table = 'telephones_push';

    protected $fillable = ['personne_id', 'jeton', 'plateforme'];
}
