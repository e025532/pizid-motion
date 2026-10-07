# Installation du serveur

Ce guide cible une distribution Debian récente avec Nginx, PHP-FPM et
PostgreSQL. Adapte les noms de paquets et versions à ta distribution.

## 1. Prérequis

- un nom DNS pointant vers ton serveur ou reverse proxy ;
- un certificat TLS valide ;
- PostgreSQL non exposé directement à Internet ;
- PHP avec `pdo_pgsql`, `curl`, `mbstring` et Sodium ;
- suffisamment d'espace pour les données et les sauvegardes.

```bash
sudo apt update
sudo apt install nginx postgresql php-fpm php-pgsql php-curl php-mbstring git
```

## 2. Compte et base PostgreSQL

Génère un mot de passe fort, puis crée le rôle et la base :

```bash
sudo -u postgres createuser --pwprompt healthconnect
sudo -u postgres createdb --owner=healthconnect health_connect
```

Applique les migrations dans cet ordre :

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

La numérotation conserve volontairement un trou : l'ancienne migration 003
était spécifique à une instance historique et n'appartient pas à la
distribution réutilisable.

## 3. Configuration de l'application

```bash
sudo install -d -m 0750 -o root -g www-data /etc/health-connect
sudo install -m 0640 -o root -g www-data .env.example /etc/health-connect/app.env
sudoedit /etc/health-connect/app.env
```

Encode le mot de passe PostgreSQL sans retour à la ligne :

```bash
printf '%s' 'MOT_DE_PASSE' | base64
```

Pour protéger le dashboard, génère `WEB_PASSWORD_HASH_B64` localement :

```bash
php -r 'echo base64_encode(password_hash($argv[1], PASSWORD_DEFAULT)), PHP_EOL;' 'MOT_DE_PASSE_WEB'
```

Une valeur Web vide désactive l'authentification. Ne l'utilise que pour un test
local temporaire sur un réseau de confiance.

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

Adapte `server_name`, la version PHP-FPM et les limites dans
`infra/nginx/health-connect.conf`, puis :

```bash
sudo cp infra/nginx/health-connect-api-limit.conf /etc/nginx/conf.d/
sudo cp infra/nginx/health-connect.conf /etc/nginx/sites-available/health-connect
sudo ln -s /etc/nginx/sites-available/health-connect /etc/nginx/sites-enabled/health-connect
sudo nginx -t
sudo systemctl reload nginx
```

Si tu utilises un reverse proxy externe, il doit conserver l'hôte, terminer TLS
et ne pas mettre en cache les routes `/api/`.

## 5. Enregistrer un terminal Android

Génère un jeton, puis son empreinte SHA-256 :

```bash
DEVICE_TOKEN="$(openssl rand -hex 32)"
TOKEN_HASH="$(printf '%s' "$DEVICE_TOKEN" | sha256sum | cut -d' ' -f1)"
printf 'Conserve ce jeton dans un gestionnaire de secrets : %s\n' "$DEVICE_TOKEN"
```

Insère uniquement l'empreinte dans PostgreSQL :

```sql
INSERT INTO api.devices (device_name, token_hash)
VALUES ('Mon téléphone', 'EMPREINTE_SHA256');
```

Compile ensuite l'application avec l'URL publique de ton API :

```bash
cd android
export PIZID_API_BASE_URL=https://health.example.org/api/v1
./gradlew :app:assembleDebug
```

Le jeton en clair est provisionné dans l'application puis chiffré avec Android
Keystore. Consulte [ANDROID.md](ANDROID.md).

## 6. Google Health facultatif

Crée un client OAuth Web avec une URI de redirection correspondant exactement à
ton domaine, puis installe les valeurs sans committer le JSON OAuth :

```bash
sudo php infra/scripts/configure_google_health_env.php \
  /chemin/client_secret.json \
  /etc/health-connect/app.env \
  https://health.example.org/api/v1/google-health/callback

sudo cp infra/systemd/health-connect-google-health.* /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now health-connect-google-health.timer
```

Le fichier OAuth reste hors du dépôt et doit être protégé comme un secret.

## 7. Contrôles

```bash
curl -fsS https://health.example.org/api/v1/health
curl -I https://health.example.org/dashboard
systemctl status nginx postgresql
```

Le premier appel doit renvoyer `{"status":"ready"}`. Vérifie également que le
dashboard utilise `Cache-Control: no-store`, qu'il n'est pas indexé et que
l'authentification Web est activée avant toute exposition publique.
