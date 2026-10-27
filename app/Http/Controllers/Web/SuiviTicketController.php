<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Sante\File\FileAttente;
use Illuminate\View\View;

/**
 * Page ouverte par le QR imprimé sur le ticket : le patient, même sans
 * l'app, voit sa place dans la file. Jamais de nom.
 */
class SuiviTicketController extends Controller
{
    public function __construct(private readonly FileAttente $file) {}

    public function show(string $uuid): View
    {
        $ticket = Ticket::where('uuid', $uuid)->firstOrFail();

        return view('suivi_ticket', ['t' => $this->file->pourPublic($ticket)]);
    }
}
