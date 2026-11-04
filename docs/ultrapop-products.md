# Produits ULTRAPOP B2B

Les produits de la boutique sont lus dans les tables `shop_product`, `shop_product_translation` et `shop_product_image`.

En production, déployer le code et les images, puis exécuter :

```sh
php bin/console doctrine:migrations:migrate --env=prod --no-debug --no-interaction
php bin/console asset-map:compile --env=prod --no-debug
```

La migration `Version20261104150000` crée les tables. La migration `Version20261104160000` y ajoute les 55 références, 275 traductions et 146 images à partir de l’instantané figé `migrations/data/ultrapop-products-20261104.json`, sans dépendre des services applicatifs ni de l’export dans `var/`. Elle conserve intégralement les références déjà présentes, y compris leurs prix, publication, traductions et images. L’ajout est transactionnel ; son annulation automatique est bloquée pour préserver les éventuelles modifications faites dans l’admin. L’import séparé n’est donc pas nécessaire au déploiement.

Le fichier `assets/data/ultrapop-products.json` reste disponible pour un import manuel :

```sh
php bin/console app:products:import
```

L’import crée les références absentes et préserve les produits déjà administrés. Pour compléter uniquement les images générées sans toucher aux prix ou aux textes :

```sh
php bin/console app:products:import --images-only
php bin/console asset-map:compile --env=prod
```

L’administration se trouve dans `/admin/produits`, derrière l’authentification administrateur existante. Elle permet de créer une référence, modifier ses informations, saisir un prix HT ou TTC, ajuster la TVA et le PCB, gérer le stock en cartons, les traductions, les images, l’ordre et la publication.

Le prix unitaire HT est conservé en `DECIMAL(14,6)` ; le taux de TVA est un entier en centièmes de pourcentage (550 = 5,50 %). Le prix HT du carton est calculé sur le prix précis multiplié par le PCB, puis arrondi au centime. La TVA est arrondie sur ce montant HT ; le TTC est la somme HT + TVA. Les calculs PHP et l’aperçu de l’admin utilisent des entiers.

Les prix et taux initiaux viennent de l’export SQL fourni. Les quantités unitaires de cet export ne sont pas assimilées à un stock B2B : le stock en cartons reste optionnel et se renseigne dans l’admin. Une valeur zéro rend la référence indisponible. Les traductions existantes sont reprises à l’import ; les champs localisés vides utilisent le français. Le regroupement des variantes utilise la gamme et le format canoniques, indépendamment des traductions.

Les images ajoutées dans l’admin sont validées et enregistrées en WebP dans `public/uploads/products`. Les visuels de carton générés sont dans `assets/images/shop/cartons`, avec les prompts et PCB dans `docs/ultrapop-cartons-prompts.json`.

Tests ciblés sous Windows, lorsque SQLite est fourni mais désactivé dans le PHP CLI :

```sh
php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/phpunit tests/Product tests/Catalog/ShopControllerTest.php
```
