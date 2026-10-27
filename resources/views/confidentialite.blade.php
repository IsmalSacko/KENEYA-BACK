<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="index,follow">
    <title>Confidentialité — Ngoni Santé</title>
    <style>
        :root { --primary:#0F2A5C; --jaune:#FFCC1F; --border:#E3E8F0; --muted:#5A6478; --bg:#EEF2F8; }
        * { box-sizing: border-box; }
        body { margin:0; background:var(--bg); color:#111B30;
               font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
               line-height:1.6; }
        .wrap { max-width:760px; margin:0 auto; padding:24px 18px 64px; }
        header { display:flex; align-items:center; gap:12px; margin:16px 0 8px; }
        .logo { width:44px; height:44px; border-radius:12px; background:var(--primary);
                color:var(--jaune); display:flex; align-items:center; justify-content:center;
                font-weight:800; font-size:24px; }
        h1 { font-size:24px; color:var(--primary); margin:14px 0 4px; }
        h2 { font-size:17px; color:var(--primary); margin:26px 0 8px; }
        .card { background:#fff; border:1px solid var(--border); border-radius:16px; padding:20px; margin-top:18px; }
        .muted { color:var(--muted); font-size:14px; }
        ul { padding-left:20px; }
        a { color:var(--primary); }
    </style>
</head>
<body>
<div class="wrap">
    <header><div class="logo">+</div><strong>Ngoni Santé</strong></header>
    <h1>Politique de confidentialité</h1>
    <p class="muted">Application Ngoni Santé (anciennement KENEYA+), éditée par Ismaël Dev.</p>

    <div class="card">
        <h2>Ce que l'application recueille</h2>
        <ul>
            <li><strong>Le nom et le numéro de téléphone</strong> : pour ouvrir un compte, identifier le patient à l'accueil et envoyer les SMS (ticket, rendez-vous, résultats prêts).</li>
            <li><strong>Les informations de santé</strong> notées par les soignants : constantes, consultations, analyses, grossesse, vaccinations, hospitalisations, ordonnances.</li>
            <li><strong>Les données de l'établissement</strong> : actes, paiements, stock, présence du personnel.</li>
            <li><strong>La position approximative</strong>, si vous l'autorisez, pour trouver un centre de santé près de vous. Elle sert à la recherche et n'est pas enregistrée.</li>
        </ul>
        <p>La caméra sert à lire les QR (ticket, carte santé, ordonnance, bracelet) : aucune image n'est envoyée.</p>

        <h2>Qui voit le dossier médical</h2>
        <ul>
            <li>L'établissement qui a reçu le patient voit ce qu'il a lui-même noté, et seulement les membres qui en ont le droit (la caisse ne lit pas les dossiers).</li>
            <li>Un autre établissement ne voit l'historique qu'avec l'accord du patient (QR de son application ou code reçu par SMS), pour 7 jours. Le patient peut retirer cet accès.</li>
            <li>Chaque ouverture du dossier est tracée, et le patient la voit dans son application.</li>
        </ul>

        <h2>Ce que nous ne faisons pas</h2>
        <ul>
            <li>Nous ne vendons ni ne partageons vos données avec des annonceurs ou des tiers.</li>
            <li>L'application ne contient pas de publicité.</li>
            <li>Les SMS passent par un prestataire d'envoi qui ne reçoit que le numéro et le texte du message, jamais un résultat médical.</li>
        </ul>

        <h2>Sécurité</h2>
        <p>Les échanges sont chiffrés (HTTPS). L'accès se fait par un compte personnel protégé par un code PIN, et les droits suivent la fonction de chacun.</p>

        <h2>Vos droits</h2>
        <p>Vous pouvez demander l'accès, la correction ou la suppression de vos données. La suppression du compte se demande ici :
            <a href="{{ url('/suppression-compte') }}">{{ url('/suppression-compte') }}</a>.
            Les données médicales qu'un établissement doit conserver par la loi peuvent l'être pendant la durée légale.</p>

        <p class="muted">Dernière mise à jour : {{ \Illuminate\Support\Carbon::parse('2026-10-28')->translatedFormat('j F Y') }}.</p>
    </div>
</div>
</body>
</html>
