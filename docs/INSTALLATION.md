# Installation du serveur

Ce guide cible Debian 13 avec Nginx, PHP 8.4 et PostgreSQL 17. Adaptez les noms
de paquets aux versions de votre distribution.

## 1. Prérequis

- un nom DNS pointant vers le reverse proxy ;
- un certificat TLS valide ;
- un serveur non exposé directement sur le port PostgreSQL ;
- PHP avec `pdo_pgsql`, `curl`, `mbstring` et Sodium ;
- au moins quelques gigaoctets libres pour les données et sauvegardes.

```bash
sudo apt update
sudo apt install nginx postgresql php-fpm php-pgsql php-curl php-mbstring git
```

## 2. Compte et base PostgreSQL

Générez un mot de passe fort, puis créez le rôle et la base :

```bash
sudo -u postgres createuser --pwprompt healthconnect
sudo -u postgres createdb --owner=healthconnect health_connect
```

Appliquez les migrations génériques dans cet ordre :

```bash
for migration in \
  infra/sql/001_ingestion_snapshots.sql \
  infra/sql/002_analytics_schema.sql \
  infra/sql/004_sync_api.sql \
  infra/sql/005_dashboard_indexes.sql \
  infra/sql/006_renpho_measurements.sql \
  infra/sql/007_google_health.sql
do
  sudo -u postgres psql health_connect -f "$migration"
done
```

`003_import_snapshot_20260912.sql` est une migration historique d'instance. Ne
l'exécutez que si vous avez préparé la base brute correspondante.

## 3. Configuration de l'application

```bash
sudo install -d -m 0750 -o root -g www-data /etc/health-connect
sudo install -m 0640 -o root -g www-data .env.example /etc/health-connect/app.env
sudoedit /etc/health-connect/app.env
```

Encodez le mot de passe PostgreSQL sans retour à la ligne :

```bash
printf '%s' 'MOT_DE_PASSE' | base64
```

Pour protéger le dashboard, générez `WEB_PASSWORD_HASH_B64` localement :

```bash
php -r 'echo base64_encode(password_hash($argv[1], PASSWORD_DEFAULT)), PHP_EOL;' 'MOT_DE_PASSE_WEB'
```

Une valeur Web vide désactive l'authentification. Cette option n'est acceptable
que pendant un test sur un réseau de confiance.

## 4. Déploiement PHP/Nginx

```bash
sudo install -d -m 0755 /var/www/health-connect
sudo rsync -a --delete \
  --exclude='.git' --exclude='android' --exclude='.env*' \
  ./ /var/www/health-connect/
sudo chown -R root:www-data /var/www/health-connect
sudo find /var/www/health-connect -type d -exec chmod 0755 {} +
sudo find /var/www/health-connect -type f -exec chmod 0644 {} +
```

Adaptez `server_name`, la version PHP-FPM et les limites dans
`infra/nginx/health-connect.conf`, puis :

```bash
sudo cp infra/nginx/health-connect-api-limit.conf /etc/nginx/conf.d/
sudo cp infra/nginx/health-connect.conf /etc/nginx/sites-available/health-connect
sudo ln -s /etc/nginx/sites-available/health-connect /etc/nginx/sites-enabled/health-connect
sudo nginx -t
sudo systemctl reload nginx php8.4-fpm
```

Le reverse proxy externe doit conserver l'hôte, terminer TLS et ne pas mettre en
cache les routes `/api/`.

## 5. Enregistrer un terminal Android

Générez un jeton compatible, puis son empreinte :

```bash
DEVICE_TOKEN="$(openssl rand -hex 32)"
TOKEN_HASH="$(printf '%s' "$DEVICE_TOKEN" | sha256sum | cut -d' ' -f1)"
printf 'Conservez ce jeton dans un gestionnaire de secrets : %s\n' "$DEVICE_TOKEN"
```

Insérez uniquement l'empreinte dans PostgreSQL :

```sql
INSERT INTO api.devices (device_name, token_hash)
VALUES ('Pixel', 'EMPREINTE_SHA256');
```

Le jeton en clair est provisionné dans l'application Android et chiffré avec
Android Keystore. Consultez [ANDROID.md](ANDROID.md).

## 6. Google Health facultatif

Créez un client OAuth Web avec une URI de redirection correspondant exactement
à votre domaine. Installez ensuite ses valeurs sans committer le JSON OAuth :

```bash
sudo php infra/scripts/configure_google_health_env.php \
  /chemin/client_secret.json /etc/health-connect/app.env
sudo cp infra/systemd/health-connect-google-health.* /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now health-connect-google-health.timer
```

Vérifiez et adaptez l'URI de redirection codée dans le script avant exécution.

## 7. Contrôles

```bash
curl -fsS https://health.example.org/api/v1/health
curl -I https://health.example.org/dashboard
systemctl status nginx php8.4-fpm postgresql
```

Le premier appel doit renvoyer `{"status":"ready"}` et le dashboard doit
envoyer `Cache-Control: no-store` ainsi que `X-Robots-Tag: noindex`.
