# Sécurité et confidentialité

Ce projet traite des données de santé, des habitudes de vie et des traces GPS.
Une fuite peut révéler le domicile, les horaires, l'état de santé et les
déplacements d'une personne.

## Ne jamais committer

- fichiers `.env` ;
- exports Health Connect, RENPHO, TCX ou GPX personnels ;
- dumps PostgreSQL ;
- JSON OAuth Google ;
- jetons Android, mots de passe ou clés privées ;
- APK signés et keystores ;
- journaux contenant des payloads de production.

Les motifs correspondants sont couverts par `.gitignore`, mais cette protection
ne remplace pas une revue avant commit.

## Checklist de mise en production

- [ ] HTTPS valide de bout en bout jusqu'au reverse proxy
- [ ] dashboard protégé avec `WEB_PASSWORD_HASH_B64`
- [ ] jeton différent par terminal et uniquement son SHA-256 en base
- [ ] PostgreSQL lié à localhost ou au réseau privé
- [ ] pare-feu limité aux ports nécessaires
- [ ] permissions `0640` sur `/etc/health-connect/app.env`
- [ ] sauvegardes chiffrées et restauration testée
- [ ] limites de corps et de taux Nginx activées
- [ ] mises à jour de sécurité automatiques ou planifiées
- [ ] aucune donnée de production dans le dépôt Git

## Authentifications distinctes

Le mot de passe Web ne protège pas les endpoints de synchronisation. Ceux-ci
exigent un jeton Bearer associé à un terminal actif. Inversement, un jeton Android
ne doit pas ouvrir une session Web.

Le mode Web sans mot de passe est une facilité de test, pas une configuration
de production. `noindex` et `robots.txt` ne sont pas des contrôles d'accès.

## OAuth Google Health

Les jetons OAuth sont chiffrés en base avec Sodium. La clé de chiffrement doit
rester exclusivement dans le fichier d'environnement du serveur. Sauvegardez-la
séparément : sans elle, les jetons ne sont plus déchiffrables ; avec elle et une
copie de la base, ils le deviennent.

## En cas de fuite

1. désactiver immédiatement le terminal concerné dans `api.devices` ;
2. révoquer les jetons OAuth Google ;
3. changer les mots de passe Web et PostgreSQL ;
4. renouveler `GOOGLE_HEALTH_CRYPTO_KEY_B64` après purge/reconnexion OAuth ;
5. rechercher le secret dans tout l'historique Git, pas uniquement dans HEAD ;
6. examiner les journaux d'accès et informer les personnes concernées.

Supprimer un secret d'un dernier commit ne le révoque pas. Il faut toujours le
faire tourner.
