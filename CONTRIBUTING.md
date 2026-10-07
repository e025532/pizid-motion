# Contribuer à Pizid Motion

Merci de contribuer au projet.

## Avant une contribution

- Ne committe jamais de données Health Connect, RENPHO, GPX/TCX, dumps de base,
  journaux de production, secrets OAuth, jetons, mots de passe ou clés privées.
- Utilise uniquement des données synthétiques dans les tests et exemples.
- Garde les paramètres propres à une instance dans le fichier d'environnement
  du serveur ou dans les paramètres de build locaux.
- Vérifie qu'aucune URL, adresse IP ou identité personnelle n'est introduite en
  dur sans raison fonctionnelle.

## Validation minimale

Pour le PHP :

```bash
find src public bin infra -name '*.php' -print0 | xargs -0 -n1 php -l
```

Pour Android :

```bash
cd android
export JAVA_HOME=/path/to/jdk-17
export PIZID_API_BASE_URL=https://health.example.org/api/v1
./gradlew :app:assembleDebug
```

Avant d'ouvrir une pull request, relis également `docs/SECURITY.md`.

## Licence

Toute contribution acceptée est distribuée sous la licence MIT du dépôt.
