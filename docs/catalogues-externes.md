# Catalogues externes

Accès administrateur : `/admin/catalogues-externes`. Le titre ouvre la gestion des PDF et des pages ; « Personnaliser » ouvre l’éditeur des zones de liens et de vidéos. Les nouveaux catalogues sont activés par défaut. Ils deviennent consultables après ajout de pages et création d’un lien de partage.

La création demande uniquement le nom du catalogue. L’utilisateur gère lui-même le contenu de ses PDF et de ses pages, sans paramètre de langue. Ces catalogues ne sont pas affichés dans le catalogue public du site ; ils sont destinés à être envoyés par lien.

Les actions de chaque ligne apparaissent au survol et au focus clavier ; elles restent visibles sur mobile. « Partager » ouvre un lien à copier, avec une page de départ facultative. Le menu « Plus » permet de renommer, gérer les documents ou supprimer le catalogue. Le switch met tous ses liens en pause sans supprimer les documents.

L’éditeur conserve les modifications pendant la navigation entre pages. « Enregistrer et terminer » enregistre les pages modifiées. Les zones peuvent être déplacées et redimensionnées ; les vidéos YouTube, Vimeo, Wistia et les fichiers HTTPS MP4 / WebM s’affichent directement dans la page du catalogue. L’éditeur montre leur aperçu ; le lecteur client permet la lecture à cet emplacement et l’arrête lorsque la page est quittée.

Les liens de partage ne sont pas référencés par les moteurs de recherche et sont accessibles sans compte aux personnes qui les possèdent. La gestion des PDF permet aussi de créer et désactiver plusieurs liens indépendants. Les médias sont stockés dans `public/uploads/catalogue` ; conserver ce dossier entre déploiements.

Déploiement Symfony habituel, avec les migrations `Version20261110120000` (catalogues externes et partages) et `Version20261110130000` (éléments interactifs) :

```sh
php bin/console doctrine:migrations:migrate --env=prod --no-debug --no-interaction
php bin/console cache:clear --env=prod --no-debug
php bin/console asset-map:compile --env=prod --no-debug
```

Tests ciblés : `tests/Catalog/ExternalCatalogControllerTest.php` et `tests/Catalog/AdminCatalogControllerTest.php`.
