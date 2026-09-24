# LNS Trade — site Symfony

Landing page bilingue LNS Trade / ULTRAPOP sous Symfony 7.4 LTS, Twig, AssetMapper et Symfony UX Turbo.

## Lancer le site en local

```bash
composer install
symfony server:start --no-tls
```

Le site est alors disponible sur l’adresse affichée par Symfony CLI. En développement, AssetMapper sert directement les fichiers de `assets/` : aucune compilation n’est nécessaire.

## Préparer la production

```bash
composer install --no-dev --optimize-autoloader
php bin/console cache:clear --env=prod
php bin/console asset-map:compile --env=prod
```

Créer un fichier `.env.local` non versionné avec au minimum :

```dotenv
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=remplacer-par-un-secret-long-et-aleatoire
DEFAULT_URI=https://lnstrade.fr
HUBSPOT_ACCESS_TOKEN=pat-votre-token-prive
HUBSPOT_OWNER_ID=65157022
```

Le token HubSpot doit autoriser la lecture et l’écriture des entreprises ainsi que l’écriture des contacts. Le formulaire crée ou met à jour le contact par e-mail, réutilise une entreprise portant le même nom, associe les deux fiches et ajoute une note avec le message reçu. Le client secret HubSpot n’est pas nécessaire avec ce token privé et ne doit pas être exposé côté navigateur.

## Apache

Le `DocumentRoot` doit pointer vers le dossier `public/`, jamais vers la racine du projet. Le fichier `public/.htaccess` est déjà installé ; Apache doit autoriser sa lecture et avoir `mod_rewrite` activé.

```apache
<VirtualHost *:80>
    ServerName lnstrade.fr
    ServerAlias www.lnstrade.fr
    DocumentRoot /var/www/lnstrade/public

    <Directory /var/www/lnstrade/public>
        AllowOverride All
        Require all granted
        FallbackResource /index.php
    </Directory>
</VirtualHost>
```

Le dossier `var/` doit être inscriptible par l’utilisateur PHP/Apache. Le site vitrine actuel n’utilise pas encore la base de données.

## SEO

- `/` détecte `en` ou `fr`, avec l’anglais comme repli.
- Les pages ont des URL localisées, canonical et `hreflang`.
- `/sitemap.xml` est généré automatiquement depuis les options des routes.
- `/robots.txt` et `/llms.txt` sont servis depuis `public/`.
- Les anciennes URL `.html` redirigent définitivement vers leurs équivalents français.
