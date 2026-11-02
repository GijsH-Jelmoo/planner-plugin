# Jelmoo Planner

Plant het wisselen van een link, het vervangen van een bestand of het tonen van een verborgen element op een vast moment, op elke WordPress-site. Bedoeld voor menukaarten die per seizoen wisselen en emblemen die op een datum online moeten.

## Gebruik

**Extra > Planner > Nieuwe taak.** Vul de URL's en het moment in, klik op **Scannen** en controleer de preview. Klik daarna op **Inplannen**.

- **Link wisselen:** elke knop en menulink naar de oude URL gaat naar de nieuwe URL.
- **Bestand vervangen:** de URL blijft gelijk en krijgt nieuwe inhoud, voor een geprinte QR-code. Upload het nieuwe bestand eerst via de mediabibliotheek. Het oude bestand wordt bewaard in `uploads/jelmoo-planner-backups`.
- **Element tonen:** zet het element in de builder op `display: none` en plan het tonen in met zijn ID (`#brxe-abc123`) of class. De plugin zet op dat moment een CSS-regel in de pagina en verandert niets in de builder. Het element staat dus vooraf al in de broncode. De plugin moet actief blijven, tenzij je het element later in de builder zelf zichtbaar maakt.

Na de wissel krijg je een mail met het resultaat. Op de taakpagina staat per plek wat er is gebeurd, en daar kun je de wissel 90 dagen lang ongedaan maken.

## Hoe het werkt

De plugin koppelt niet aan een builder, maar zoekt de URL in de database en vervangt hem in dezelfde codering (HTML, JSON, serialized). Dat gebeurt in `post_content`, `post_excerpt`, postmeta, termmeta en options. Revisies blijven ongemoeid.

Daarna leegt de plugin de caches van Elementor en LiteSpeed en haalt de geraakte pagina's op om te controleren of de oude link weg is. Na 1 uur en na 24 uur controleert hij dat opnieuw. Andere caches kun je koppelen via de actie `jelmoo_planner_after_swap`.

Niet gevonden worden: eigen tabellen van plugins (zoals sliders), themabestanden, Code Snippets, verwijzingen via een bestands-ID en gecodeerde blokken (zoals raw HTML in WPBakery). Ondertekende code (Bricks, Oxygen) wordt bewust overgeslagen en gemeld, omdat die anders stopt met werken.

## Op tijd draaien

De planner draait op WP-Cron, elke 5 minuten. WP-Cron loopt alleen als een bezoek PHP aanroept, en pagina's uit de cache doen dat niet. Moet een wissel op tijd gebeuren op een rustige site, stel dan een servercron in:

```
*/5 * * * * curl -s https://voorbeeld.nl/wp-cron.php >/dev/null
```

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
