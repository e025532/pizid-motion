# Exploitation et diagnostic

## Contrôles rapides

```bash
curl -fsS https://health.example.org/api/v1/health
sudo systemctl --no-pager --full status nginx php8.4-fpm postgresql
sudo journalctl -u nginx -u php8.4-fpm --since '30 minutes ago'
```

Pour Google Health :

```bash
systemctl list-timers health-connect-google-health.timer
sudo journalctl -u health-connect-google-health.service -n 100 --no-pager
```

## Contrôles PostgreSQL

```sql
SELECT count(*) FROM health.records WHERE NOT is_deleted;
SELECT record_type, count(*)
FROM health.records
WHERE NOT is_deleted
GROUP BY record_type
ORDER BY count(*) DESC;

SELECT device_name, last_seen_at
FROM api.devices
WHERE enabled
ORDER BY last_seen_at DESC NULLS LAST;

SELECT status, count(*), max(received_at) AS derniere_reception
FROM api.sync_batches
GROUP BY status;
```

## Sauvegardes

Les données à sauvegarder sont :

1. la base PostgreSQL `health_connect` ;
2. `/etc/health-connect/app.env` dans un coffre chiffré séparé ;
3. les exports Health Connect originaux, si vous souhaitez conserver une source
   brute indépendante ;
4. la configuration du reverse proxy.

Exemple de sauvegarde logique :

```bash
sudo -u postgres pg_dump --format=custom --file=/srv/backups/health_connect.dump health_connect
```

Testez régulièrement la restauration dans une base isolée. Une sauvegarde non
testée ne constitue pas une procédure de reprise fiable.

## Incident : dashboard lent et téléphone bloqué

Lorsque les deux symptômes apparaissent ensemble, contrôlez d'abord Nginx et ses
limites de requêtes. Le trafic Web et les lots Android ne doivent pas s'affamer
mutuellement.

Ordre de diagnostic :

1. `/api/v1/health` et temps de réponse ;
2. codes HTTP 429/502/503 dans les journaux Nginx ;
3. saturation PHP-FPM ;
4. connexions et requêtes PostgreSQL ;
5. taille et fréquence des lots Android ;
6. curseur local du téléphone comparé au dernier lot serveur.

## Mise à jour

```bash
git pull --ff-only
find src public bin infra -name '*.php' -print0 | xargs -0 -n1 php -l
sudo rsync -a --delete --exclude='.git' --exclude='android' --exclude='.env*' ./ /var/www/health-connect/
sudo nginx -t
sudo systemctl reload nginx php8.4-fpm
```

Appliquez uniquement les nouvelles migrations SQL, après sauvegarde. Ne rejouez
pas la migration historique de snapshot sur une base existante.

## Rotation d'un jeton Android

1. générer un nouveau jeton et son empreinte SHA-256 ;
2. insérer ou remplacer l'empreinte en base ;
3. provisionner le nouveau jeton sur le téléphone ;
4. vérifier une synchronisation ;
5. désactiver l'ancien terminal/jeton.

Ne journalisez jamais le jeton en clair.
