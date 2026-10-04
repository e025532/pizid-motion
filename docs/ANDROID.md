# Application Android

## Rôle

L'application `org.pizid.healthconnectsync` lit Health Connect et pousse les
données autorisées vers l'API personnelle. Elle sait aussi recevoir un export
RENPHO via le menu Android **Partager**.

Le projet cible l'API Android 37 et requiert un JDK 17.

## Configurer l'URL

L'URL de l'API est définie dans `android/app/build.gradle.kts` par
`BuildConfig.API_BASE_URL`. Remplacez-la par votre URL HTTPS avant compilation.

## Compiler et installer

```bash
cd android
export JAVA_HOME=/path/to/jdk-17
./gradlew :app:assembleDebug
adb install -r app/build/outputs/apk/debug/app-debug.apk
```

`adb install -r` conserve les préférences, le jeton et les autorisations de
l'installation existante.

## Provisionner le jeton

Le provisionnement ADB n'est accepté que par une version `DEBUG` :

```bash
adb shell am start \
  -n org.pizid.healthconnectsync/.MainActivity \
  --es api_token 'JETON_DU_TERMINAL'
```

Le jeton est immédiatement chiffré avec une clé non exportable Android Keystore
et retiré de l'intent. Évitez de laisser cette commande dans l'historique du
shell ; préférez une variable temporaire ou une saisie sécurisée.

## Autorisations

Le bouton **Autoriser toutes les données** ouvre le dialogue Health Connect.
L'application ne demande que des lectures. Android peut traiter l'accès aux
routes d'exercice séparément.

## Modes de synchronisation

### Synchronisation ordinaire

- lit une fois le baseline depuis la date configurée ;
- conserve un checkpoint par type de donnée ;
- consomme ensuite le journal de changements Health Connect ;
- envoie les enregistrements et suppressions par lots ;
- fonctionne avec WorkManager lorsque le réseau est disponible.

### Synchronisation prioritaire

Le bouton correspondant autorise une alarme exacte. Elle réveille brièvement
un foreground service environ chaque heure, y compris après une longue période
en arrière-plan. La notification est silencieuse et disparaît en fin d'envoi.

Cette planification est relancée après redémarrage du téléphone.

### Routes GPS

Le bouton **Importer les 5 dernières sorties** relit les cinq sessions les plus
récentes en ordre décroissant et force le renvoi des routes accessibles. Cette
limite évite de matérialiser puis téléverser tout l'historique GPS.

Ce flux ne remplace pas la synchronisation ordinaire : une session peut être
synchronisée avant que sa route GPS ne soit autorisée.

## Import RENPHO

Dans RENPHO Health, exportez les mesures, choisissez **Partager**, puis
sélectionnez Health Sync Pizid. L'application transmet le fichier au endpoint
authentifié `/api/v1/sync/renpho` et affiche le bilan des insertions/mises à jour.

## Diagnostic ADB

```bash
adb logcat -s SyncWorker SyncEngine ExerciseRouteImporter
adb shell dumpsys package org.pizid.healthconnectsync | grep -E 'versionCode|versionName'
adb shell dumpsys alarm | grep org.pizid.healthconnectsync
adb shell dumpsys jobscheduler | grep -A10 org.pizid.healthconnectsync
```

En cas d'échec, comparer trois informations : le message affiché dans
l'application, le curseur local et la dernière activité reçue par le serveur.
