<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="index,follow">
    <title>Suppression de compte et de données — KENEYA</title>
    <style>
        :root { --primary:#0E9F6E; --dark:#0B3D2E; --border:#E2ECE7; --muted:#4B6358; --bg:#F5FAF8; }
        * { box-sizing: border-box; }
        body { margin:0; background:var(--bg); color:#1a2b24;
               font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
               line-height:1.6; }
        .wrap { max-width:720px; margin:0 auto; padding:24px 18px 64px; }
        header { display:flex; align-items:center; gap:12px; margin:16px 0 8px; }
        .logo { width:44px; height:44px; border-radius:12px; background:var(--primary);
                color:#fff; display:flex; align-items:center; justify-content:center;
                font-weight:800; font-size:20px; }
        h1 { font-size:24px; color:var(--dark); margin:14px 0 4px; }
        h2 { font-size:17px; color:var(--dark); margin:26px 0 8px; }
        p, li { color:#26382f; }
        .card { background:#fff; border:1px solid var(--border); border-radius:16px;
                padding:20px; margin-top:18px; }
        .muted { color:var(--muted); font-size:14px; }
        label { display:block; font-weight:600; color:var(--dark); margin:14px 0 6px; font-size:14px; }
        input[type=text], input[type=tel], select, textarea {
            width:100%; padding:12px 14px; border:1px solid var(--border);
            border-radius:12px; font-size:15px; font-family:inherit; background:#fff; }
        textarea { min-height:90px; resize:vertical; }
        .check { display:flex; gap:10px; align-items:flex-start; margin-top:16px; }
        .check input { margin-top:5px; }
        button { margin-top:20px; width:100%; padding:14px; border:0; border-radius:12px;
                 background:var(--primary); color:#fff; font-size:16px; font-weight:700;
                 cursor:pointer; }
        button:hover { background:#0B7D57; }
        .ok { background:#E7F6EF; border:1px solid #A7E0C7; color:#0B7D57;
              padding:16px; border-radius:12px; margin-top:18px; }
        .err { color:#DC2626; font-size:13px; margin-top:4px; }
        ul { padding-left:20px; }
        .foot { margin-top:28px; font-size:13px; color:var(--muted); }
    </style>
</head>
<body>
<div class="wrap">
    <header>
        <div class="logo">K</div>
        <strong style="color:var(--dark);font-size:18px;">KENEYA</strong>
    </header>

    <h1>Suppression de compte et de données</h1>
    <p class="muted">Cette page permet aux utilisateurs des applications KENEYA
        (application professionnelle pour les établissements de santé, et
        application patient) de demander la suppression de leur compte et des
        données personnelles associées.</p>

    <div class="card">
        <h2>Quelles données sont supprimées ?</h2>
        <ul>
            <li><strong>Application professionnelle</strong> : votre compte
                utilisateur (nom, téléphone, rôle) et vos informations personnelles associées.</li>
            <li><strong>Application patient</strong> : vos demandes de rendez-vous
                et les coordonnées transmises (nom, téléphone).</li>
        </ul>

        <h2>Données éventuellement conservées</h2>
        <p>Certaines données peuvent être conservées de façon anonymisée ou pour
            répondre à des obligations légales et comptables (par exemple les
            enregistrements de paiements ou d'actes médicaux), pour la durée
            imposée par la réglementation. Elles ne sont plus rattachées à votre
            identité au-delà de ce qui est légalement requis.</p>

        <h2>Délai de traitement</h2>
        <p>Les demandes sont traitées sous <strong>30 jours</strong>. Vous pouvez
            être recontacté au numéro fourni pour vérifier votre identité.</p>
    </div>

    <div class="card">
        <h2>Demander la suppression</h2>

        @if (session('success'))
            <div class="ok">
                Votre demande a bien été enregistrée. Elle sera traitée sous 30 jours.
            </div>
        @endif

        <form method="POST" action="{{ route('suppression-compte.store') }}">
            @csrf

            <label for="type_compte">Type de compte concerné</label>
            <select id="type_compte" name="type_compte">
                <option value="patient" @selected(old('type_compte') === 'patient')>Application patient (rendez-vous)</option>
                <option value="pro" @selected(old('type_compte') === 'pro')>Application professionnelle (personnel de santé)</option>
            </select>
            @error('type_compte') <div class="err">{{ $message }}</div> @enderror

            <label for="nom">Nom (facultatif)</label>
            <input type="text" id="nom" name="nom" value="{{ old('nom') }}">
            @error('nom') <div class="err">{{ $message }}</div> @enderror

            <label for="telephone">Téléphone associé au compte</label>
            <input type="tel" id="telephone" name="telephone" value="{{ old('telephone') }}" required>
            @error('telephone') <div class="err">{{ $message }}</div> @enderror

            <label for="motif">Motif (facultatif)</label>
            <textarea id="motif" name="motif">{{ old('motif') }}</textarea>
            @error('motif') <div class="err">{{ $message }}</div> @enderror

            <div class="check">
                <input type="checkbox" id="confirmation" name="confirmation" value="1">
                <label for="confirmation" style="font-weight:400;margin:0;color:#26382f;">
                    Je confirme demander la suppression de mon compte et des données
                    personnelles associées.
                </label>
            </div>
            @error('confirmation') <div class="err">{{ $message }}</div> @enderror

            <button type="submit">Envoyer ma demande</button>
        </form>
    </div>

    <p class="foot">KENEYA — Gestion et accès aux services de santé.
        Pour toute question relative à vos données, utilisez le formulaire ci-dessus.</p>
</div>
</body>
</html>
