<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Rafraîchie toute seule tant que le ticket est en cours. --}}
    @if (in_array($t['statut'], ['emis', 'appele', 'en_service'], true))
        <meta http-equiv="refresh" content="15">
    @endif
    <title>Ticket {{ $t['code'] }} · Ngoni Santé</title>
    <style>
        :root { --nuit: #0F2A5C; --jaune: #FFCC1F; --fond: #EEF2F8; --texte: #111B30; --doux: #5A6478; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; background: var(--fond); color: var(--texte); }
        main { max-width: 440px; margin: 0 auto; padding: 24px 16px; }
        .carte { background: linear-gradient(135deg, #22498F, #0E1F4A); color: #fff; border-radius: 22px; padding: 24px; text-align: center;
                 box-shadow: 0 10px 22px rgba(15, 42, 92, .3); }
        .etab { opacity: .85; font-size: 14px; }
        .code { font-size: 64px; font-weight: 800; color: var(--jaune); letter-spacing: 2px; margin: 8px 0; }
        .service { font-size: 16px; }
        .etat { background: #fff; border-radius: 18px; padding: 20px; margin-top: 16px; text-align: center; box-shadow: 0 6px 18px rgba(15, 42, 92, .1); }
        .grand { font-size: 28px; font-weight: 800; color: var(--nuit); }
        .doux { color: var(--doux); font-size: 14px; margin-top: 6px; }
        .appel { background: var(--jaune); color: var(--nuit); }
        footer { text-align: center; color: var(--doux); font-size: 12px; margin-top: 24px; }
    </style>
</head>
<body>
<main>
    <div class="carte">
        <div class="etab">{{ $t['etablissement']['nom'] }}</div>
        <div class="code">{{ $t['code'] }}</div>
        <div class="service">{{ $t['service']['nom'] }}</div>
    </div>

    @if ($t['statut'] === 'emis')
        <div class="etat">
            @if ($t['position'] === 0)
                <div class="grand">Vous êtes le prochain</div>
            @else
                <div class="grand">{{ $t['position'] }} {{ $t['position'] > 1 ? 'personnes' : 'personne' }} avant vous</div>
            @endif
            <div class="doux">Attente estimée : environ {{ $t['attente_min'] }} min</div>
            <div class="doux">Vous pouvez attendre dehors : cette page se met à jour toute seule.</div>
        </div>
    @elseif (in_array($t['statut'], ['appele', 'en_service'], true))
        <div class="etat appel">
            <div class="grand">C'est à vous !</div>
            @if ($t['point'])
                <div style="font-size: 20px; font-weight: 700; margin-top: 6px">→ {{ $t['point'] }}</div>
            @endif
        </div>
    @else
        <div class="etat">
            <div class="grand">{{ $t['statut_label'] }}</div>
            <div class="doux">Ce ticket n'est plus dans la file.</div>
        </div>
    @endif

    <footer>Ngoni Santé</footer>
</main>
</body>
</html>
