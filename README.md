# Jelmoo Planner

Plant het wisselen van een link, het vervangen van een bestand of het tonen van een verborgen element op een vast moment, op elke WordPress-site. Bedoeld voor menukaarten die per seizoen wisselen en emblemen die op een datum online moeten.

## Gebruik

**Extra > Planner > Nieuwe taak.** Vul de URL's en het moment in, klik op **Scannen** en controleer de preview. Klik daarna op **Inplannen**.

- **Link wisselen:** elke knop, menulink en afbeelding met de oude URL gaat naar de nieuwe URL. Kies beide bestanden uit de mediabibliotheek, dan gaan ook het bestands-ID, de bestandsnaam en de kleinere versies mee en toont ook de builder het nieuwe bestand.
- **Bestand vervangen:** de URL blijft gelijk en krijgt nieuwe inhoud, voor een geprinte QR-code. Upload het nieuwe bestand eerst via de mediabibliotheek. Het oude bestand wordt bewaard in `uploads/jelmoo-planner-backups`.
- **Element tonen:** zet het element in de builder op `display: none` en plan het tonen in met zijn ID (`#brxe-abc123`) of class. De plugin zet op dat moment een CSS-regel in de pagina en verandert niets in de builder. Het element staat dus vooraf al in de broncode. De plugin moet actief blijven, tenzij je het element later in de builder zelf zichtbaar maakt. Met **Tegelijk verbergen** verdwijnt op hetzelfde moment een ander element, bijvoorbeeld het oude embleem.

Gebruik **Bestand vervangen** niet voor afbeeldingen: de kleinere versies die WordPress maakte blijven dan oud. Gebruik daarvoor Link wisselen.

Alle tijden in de Planner zijn Nederlandse tijd, ook als WordPress op de site op iets anders staat. Een site in een ander land past dit aan met het filter `jelmoo_planner_timezone`.

Na de wissel krijg je een mail met het resultaat. Op de taakpagina staat per plek wat er is gebeurd, en daar kun je de wissel 90 dagen lang ongedaan maken.

## Hoe het werkt

De plugin koppelt niet aan een builder, maar zoekt de URL in de database en vervangt hem in dezelfde codering (HTML, JSON, serialized). Dat gebeurt in `post_content`, `post_excerpt`, postmeta, termmeta en options. Revisies blijven ongemoeid.

Veel builders renderen via het bestands-ID en negeren de URL ernaast (Bricks: `{id, filename, url}`). Staan beide bestanden in de mediabibliotheek, dan wisselt de plugin daarom ook het ID en de bestandsnaam, en alle kleinere versies. Een ID wisselt alleen als in hetzelfde object de URL of bestandsnaam van het oude bestand staat, zodat een willekeurig gelijk getal ongemoeid blijft.

Daarna leegt de plugin de caches van Elementor en LiteSpeed, maakt de CSS-bestanden van Bricks opnieuw aan (bij External Files) en haalt de geraakte pagina's op om te controleren of de oude link weg is. Na 1 uur en na 24 uur controleert hij dat opnieuw. Andere caches kun je koppelen via de actie `jelmoo_planner_after_swap`.

Niet gevonden worden: eigen tabellen van plugins (zoals sliders), themabestanden, Code Snippets, gecodeerde blokken (zoals raw HTML in WPBakery) en bestands-ID's zonder URL of bestandsnaam ernaast. De preview meldt het als het bestand ook de uitgelichte afbeelding van een bericht is; die wisselt niet mee. Ondertekende code (Bricks, Oxygen) wordt bewust overgeslagen en gemeld, omdat die anders stopt met werken.

## Op tijd draaien

Elke taak krijgt een eigen WP-Cron-event op het geplande moment, met daarnaast een controle elke 5 minuten. WP-Cron loopt alleen als een bezoek PHP aanroept, en pagina's uit de cache doen dat niet. Moet een wissel op tijd gebeuren, stel dan zeker op een site met paginacache een servercron in:

```
* * * * * curl -s https://voorbeeld.nl/wp-cron.php >/dev/null
```

Loopt een taak meer dan 10 minuten achter, dan staat dat op de taakpagina.

## Ontwikkelen

```
composer install
vendor/bin/phpunit
```

De integratietests draaien op de Docker-site in `Documents/Wordpress/planner-test`, waar deze map als plugin is gemount:

```
cd ~/Documents/Wordpress/planner-test && docker compose up -d
~/Documents/Plugins/Planner/tests/integration/run.sh
docker compose exec -T -u www-data wordpress php /var/www/html/wp-content/plugins/jelmoo-planner/tests/integration/failures.php
docker compose run --rm -T cli eval-file /var/www/html/wp-content/plugins/jelmoo-planner/tests/integration/corpus.php
docker compose exec -T -u www-data wordpress php /var/www/html/wp-content/plugins/jelmoo-planner/tests/integration/show.php
docker compose exec -T -u www-data wordpress php /var/www/html/wp-content/plugins/jelmoo-planner/tests/integration/media.php
```

`run.sh` leegt de testsite en test het actieve thema. Bricks test je met `docker compose run --rm -T cli theme activate bricks`.

## Uitbrengen

1. Zet het nieuwe versienummer in de header en in `JELMOO_PLANNER_VERSION` van `jelmoo-planner.php`.
2. Maak een tag: `git tag v0.2.0 && git push --tags`.

De GitHub Action draait de tests, bouwt de zip en zet die bij de release. Sites met de plugin zien de update daarna in wp-admin. Iedereen met schrijfrechten op deze repo kan dus code naar klantsites sturen.

Wordt de repo ooit private, dan moet op elke site een token in `wp-config.php` staan:

```php
define('JELMOO_PLANNER_GITHUB_TOKEN', '...');
```

Een zip lokaal bouwen kan met `bin/build-zip.sh`.
