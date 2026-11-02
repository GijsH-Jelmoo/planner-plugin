#!/usr/bin/env bash
# Wissel + undo op de lokale testsite, via de echte wp-cron.php.
# Gebruik: tests/integration/run.sh   (vanuit een draaiende Documents/Wordpress/planner-test)
set -u

SITE_DIR="${SITE_DIR:-$HOME/Documents/Wordpress/planner-test}"
SITE=http://localhost:8002
P=/var/www/html/wp-content/plugins/jelmoo-planner/tests/integration

cd "$SITE_DIR" || exit 1

wp() { docker compose run --rm -T cli "$@" 2>&1 | grep -v -E '^(Container|\s*Container|Network)'; }

fixture() {
    docker compose exec -T wordpress cat /var/www/html/wp-content/uploads/planner-fixtures.json |
        python3 -c "import json,sys;print(json.load(sys.stdin).get('$1',''))"
}

show_html() {
    for key in "$@"; do
        id=$(fixture "$key")
        [ -z "$id" ] && continue
        printf '%-20s ' "$key"
        curl -sL "$SITE/?page_id=$id" | grep -oE 'testmenu/[A-Za-z0-9_-]+\.pdf' | sort | uniq -c | tr -s ' ' | tr '\n' ';'
        echo
    done
}

job_status() {
    docker compose exec -T db mariadb -uwordpress -pwordpress wordpress -N -e "SELECT status FROM wp_jelmoo_planner_jobs ORDER BY id DESC LIMIT 1"
}

# Laat WordPress zichzelf aanroepen zoals bij een bezoek: wp_cron() spawnt wp-cron.php.
# Dit moet in de webcontainer, want de CLI-container kan localhost:8002 niet bereiken
# en zet bij elk wp-commando een cron-lock die dan nergens op uitkomt.
fire_cron() {
    wp cron event schedule jelmoo_planner_continue now | tail -1
    docker compose exec -T wordpress php -r '$_SERVER["HTTP_HOST"]="localhost:8002"; $_SERVER["REQUEST_URI"]="/"; require "/var/www/html/wp-load.php"; delete_transient("doing_cron");' 2>/dev/null
    for _ in $(seq 1 60); do
        status=$(job_status)
        case "$status" in
            done|warning|failed) echo "Taakstatus: $status"; return ;;
        esac
        /bin/sleep 1
    done
    echo "Taak is na 60s nog niet klaar, status: $status"
}

PAGES=(gutenberg elementor beaver bricks bricks_signed)

echo "== Schone start"
wp site empty --yes | tail -1
wp db query "TRUNCATE wp_jelmoo_planner_jobs; TRUNCATE wp_jelmoo_planner_rows;" | tail -1

echo "== Inhoud aanmaken"
wp eval-file "$P/seed.php" | tail -1

echo "== HTML voor de wissel"
show_html "${PAGES[@]}"

echo "== Taak inplannen en wp-cron.php aanroepen"
wp eval-file "$P/plan-job.php" | tail -1
fire_cron

echo "== Database na de wissel"
wp eval-file "$P/assert.php" swapped

echo "== HTML na de wissel"
show_html "${PAGES[@]}"

echo "== Mail"
docker compose exec -T wordpress cat /var/www/html/wp-content/uploads/jp-test-mail.log

echo "== Ongedaan maken"
wp eval 'use Jelmoo\Planner\{Store,Runner}; $job = Store::list_jobs(1)[0]; echo Runner::undo($job["id"]), "\n";'

echo "== Database na ongedaan maken"
wp eval-file "$P/assert.php" undone | grep -vE '^  |^\{|^\}|^    '

echo "== HTML na ongedaan maken"
show_html "${PAGES[@]}"
