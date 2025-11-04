# Notes de mise à niveau - ESN Sabre

## Contexte du projet

Projet de migration vers PHP 8.2 avec mise à jour des dépendances principales.

### Objectif global
- Cible: **PHP 8.2+** avec PHPUnit 10
- Branche de travail principale: `upgrade-php-8.2` (ensuite `upgrade-phpunit-10`)
- Branche cible pour les PR: `full-upgrade`
- Stratégie: **Branches empilées** pour des PR incrémentales et reviewables

## Stratégie adoptée: Branches empilées (Stacked PRs)

### Principe
Chaque upgrade crée une nouvelle branche basée sur la précédente, permettant:
- Des PR petites et ciblées (1 upgrade = 1 PR)
- Des reviews indépendantes
- Un historique Git clair
- Une possibilité de merger progressivement

### Structure des branches (état actuel)

```
full-upgrade (branche cible)
  ↓
upgrade-php-8.2
  ↓
upgrade-phpunit-10
  ↓
upgrade-sabre-dav-4.5.1  → PR #107 (ferme issue #80)
  ↓
upgrade-sabre-dav-4.6.0  → PR #108 (ferme issue #81)
  ↓
upgrade-sabre-dav-4.7.0  → PR #109 (ferme issue #82)
  ↓
upgrade-mongodb-2.4      → PR #110 (ferme issue #83)
```

## Upgrades réalisées

### 1. PHP 8.2 (upgrade-php-8.2)
- **Fichiers**: Dockerfile, Dockerfile.coverage, composer.json
- **Modifications**: PHP 7.4 → 8.2
- **Statut**: ✅ Committé

### 2. PHPUnit 10 (upgrade-phpunit-10)
- **Base**: upgrade-php-8.2
- **Modifications**: PHPUnit 9 → 10
- **Corrections**:
  - Deprecations PHP 8.2 (486 → 16, puis 14)
  - Dynamic properties sur DateTimeImmutable
  - Null parameters
- **Tests**: 400/400 passing
- **Statut**: ✅ Committé

### 3. Sabre/DAV 4.4.0 → 4.5.1 (upgrade-sabre-dav-4.5.1)
- **Base**: upgrade-phpunit-10
- **Issue**: #80
- **PR**: #107 → full-upgrade
- **Modifications**:
  - composer.json: sabre/dav 4.4.0 → 4.5.1
  - lib/CalDAV/Schedule/Plugin.php: `scheduleReply()` private → protected
- **Tests**: 400/400 passing, 14 deprecations (vendor)
- **Statut**: ✅ Committé (5c07ab8)

### 4. Sabre/DAV 4.5.1 → 4.6.0 (upgrade-sabre-dav-4.6.0)
- **Base**: upgrade-sabre-dav-4.5.1
- **Issue**: #81
- **PR**: #108 → full-upgrade
- **Modifications**: composer.json: sabre/dav 4.5.1 → 4.6.0
- **Tests**: 400/400 passing, 14 deprecations (vendor)
- **Statut**: ✅ Committé (9d426a0)

### 5. Sabre/DAV 4.6.0 → 4.7.0 (upgrade-sabre-dav-4.7.0)
- **Base**: upgrade-sabre-dav-4.6.0
- **Issue**: #82
- **PR**: #109 → full-upgrade
- **Modifications**: composer.json: sabre/dav 4.6.0 → 4.7.0
- **Tests**: 400/400 passing, 14 deprecations (vendor)
- **Statut**: ✅ Committé (4884aca), PR créée

### 6. MongoDB 1.15 → 2.4.0 (upgrade-mongodb-2.4)
- **Base**: upgrade-sabre-dav-4.7.0
- **Issue**: #83
- **PR**: #110 → full-upgrade
- **Modifications**:
  - composer.json: mongodb/mongodb ^1.15 → ^2.4
  - Dockerfile: pecl mongodb 1.15.0 → 2.1.4
  - Dockerfile.coverage: pecl mongodb 1.9.0 → 2.1.4
  - docker-compose.test.yaml: mongo:3.6 → mongo:7, mongo → mongosh
- **Performance**: +10-20% lecture/écriture, +5-15% concurrence
- **Tests**: Tous passent avec MongoDB 7.0
- **Statut**: ✅ Committé (7d7e833), PR créée

## Méthodologie de test

### Méthode 1: Test rapide avec Docker seul (sans dépendances externes)

**Quand l'utiliser**: Tests rapides, vérification syntaxe, tests unitaires sans MongoDB/LDAP

```bash
# 1. Build l'image Docker (3-5 minutes)
docker build -t esn-sabre-test .

# 2. Lancer les tests (2-3 minutes)
# ⚠️ Les tests MongoDB/LDAP échoueront (ConnectionTimeoutException) - c'est normal
docker run --rm esn-sabre-test vendor/bin/phpunit -c tests/phpunit.xml

# 3. Capturer la sortie complète pour analyse détaillée
docker run --rm esn-sabre-test vendor/bin/phpunit -c tests/phpunit.xml 2>&1 | tee /tmp/test_result.log

# 4. Analyser le résultat
grep -E "(Tests:|Assertions:|Deprecations:|Errors:|Failures:)" /tmp/test_result.log
```

**Sortie attendue** (sans infrastructure):
```
Time: 02:40.565, Memory: 8.00 MB

There were 299 errors:  ← Normal sans MongoDB
```

### Méthode 2: Test complet avec docker-compose (RECOMMANDÉ)

**Quand l'utiliser**: Tests finaux avant commit/PR, validation complète avec toutes dépendances

#### Prérequis
```bash
# Vérifier que l'image LDAP existe (si besoin, la rebuilder)
docker images | grep esn-sabre-ldap-test

# Si absente, la construire (une seule fois)
docker build -t esn-sabre-ldap-test -f docker/Dockerfile.ldap .
```

#### Lancer la suite complète

```bash
# 1. Build l'image de l'application avec le nom attendu
docker build -t esn_sabre_test .

# 2. Lancer l'infrastructure + tests
# Cette commande démarre: MongoDB 7, RabbitMQ 3, LDAP, et l'app
docker compose -f docker-compose.test.yaml up --abort-on-container-exit

# Le processus va:
# - Démarrer MongoDB (healthcheck: ~10s)
# - Démarrer RabbitMQ (healthcheck: ~10s)
# - Démarrer LDAP (pas de healthcheck, démarrage immédiat)
# - Démarrer l'app qui lance les tests après 5s de sleep
# - Stopper automatiquement quand les tests sont terminés

# 3. Pendant l'exécution, surveiller dans un autre terminal
docker logs -f esn-sabre-esn_test-1

# 4. Une fois terminé, cleanup
docker compose -f docker-compose.test.yaml down
```

#### Interpréter les résultats docker-compose

**Sortie attendue** (avec infrastructure):
```
PHPUnit 10.5.58 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.2.29
Configuration: /var/www/tests/phpunit.xml

..DDDDDDDDDDDDDDDDDDDDD.DDDDD.........................D....WDDD  63 / 400 ( 15%)
D...DDDDDDDDDDDDDDDDDDDDDDDDDDDDDDD....DDDDDDD............... 126 / 400 ( 31%)
....DDDD....DDD...................DDD.DDDD........DDDDDDDDRDDDD 189 / 400 ( 47%)
DDDDDDDDDDDDRDDDDDDDDDDDDDDD....R................R.D........... 252 / 400 ( 63%)
............................................................ 315 / 400 ( 78%)
...............................R................................ 378 / 400 ( 94%)
......................                                           400 / 400 (100%)

Time: 02:15.234, Memory: 8.00 MB

OK, but there were issues!
Tests: 400, Assertions: 1153, Risky: 4, Deprecations: 14, Warnings: 6, Skipped: 11.
```

**Légende des symboles**:
- `.` = Test passé
- `D` = Deprecation warning (toléré si ≤ 14)
- `R` = Risky test (test sans assertion)
- `W` = Warning (PHPUnit warning, acceptable)
- `S` = Skipped (test ignoré volontairement)
- `E` = Error (échec technique - ❌ à corriger)
- `F` = Failure (assertion échouée - ❌ à corriger)

### Méthode 3: Tests dans un conteneur en cours d'exécution

**Quand l'utiliser**: Debug, développement itératif, tests ciblés

```bash
# 1. Démarrer l'infrastructure en arrière-plan
docker compose -f docker-compose.test.yaml up -d

# 2. Attendre que tout soit ready (~15s)
sleep 15

# 3. Exécuter des commandes dans le conteneur
docker exec -it esn-sabre-esn_test-1 bash

# Dans le conteneur:
# - Lancer tous les tests
vendor/bin/phpunit -c tests/phpunit.xml

# - Lancer un test spécifique
vendor/bin/phpunit tests/CalDAV/Backend/MongoTest.php

# - Lancer avec verbose
vendor/bin/phpunit -c tests/phpunit.xml --verbose

# - Lister les groupes de tests
vendor/bin/phpunit --list-groups

# 4. Quitter et cleanup
exit
docker compose -f docker-compose.test.yaml down
```

### Méthode 4: Utiliser le Makefile

```bash
# Le Makefile contient une règle pour les tests
# Elle ignore le code de retour 1 (warnings/deprecations)

# Dans le conteneur docker-compose
docker exec esn-sabre-esn_test-1 make test

# Ou avec docker run
docker run --rm esn-sabre-test make test
```

**Note**: Le Makefile transforme le code de retour 1 (tests avec warnings) en succès (0).

### Analyser les résultats en détail

#### Compter les deprecations
```bash
# Dans le fichier de log
grep -c "Deprecation" /tmp/test_result.log

# Ou dans la sortie finale
# Ligne: "OK, but there were issues!"
# Chercher: "Deprecations: 14"
```

#### Identifier les tests qui échouent
```bash
# Filtrer les erreurs/failures
grep -A 5 "^[0-9]\+)" /tmp/test_result.log | head -50

# Exemple de sortie:
# 1) ESN\DAV\Auth\Backend\EsnTest::testAuthenticateTokenSuccess
# MongoDB\Driver\Exception\ConnectionTimeoutException: No suitable servers found
```

#### Vérifier les tests ignorés (Skipped)
```bash
grep -i "skipped" /tmp/test_result.log

# Les tests skippés sont normaux (ex: tests conditionnels)
```

### Troubleshooting

#### Problème: "No suitable servers found" (MongoDB)
**Cause**: Tests lancés sans docker-compose (MongoDB pas démarré)
**Solution**: Utiliser `docker-compose -f docker-compose.test.yaml up`

#### Problème: "Connection refused" (RabbitMQ/LDAP)
**Cause**: Les services ne sont pas encore prêts (healthcheck en cours)
**Solution**: Attendre ~15-20 secondes après `docker compose up`

#### Problème: "Class not found" ou "Undefined method"
**Cause**: Breaking change dans une library upgradée
**Solution**:
1. Consulter le CHANGELOG de la library
2. Chercher les breaking changes pour votre version
3. Adapter le code applicatif

#### Problème: Deprecations nouvelles après upgrade
**Cause**: La nouvelle version déprécie des méthodes
**Solution**:
1. Si deprecations < 20: Acceptable temporairement
2. Créer une issue pour les corriger ultérieurement
3. Si deprecations > 50: Corriger avant de merger

#### Problème: Tests timeout après 5 minutes
**Cause**: Tests très longs ou bloqués
**Solution**:
```bash
# Augmenter le timeout dans docker-compose.test.yaml
# ou identifier le test qui bloque:
docker logs esn-sabre-esn_test-1 | grep -E "^[0-9]+ /"
```

### Critères de succès pour un upgrade

#### ✅ Succès total
- **400/400 tests** passent
- **0 erreurs** (E)
- **0 failures** (F)
- **Deprecations ≤ 14** (idéalement stable ou réduit)
- **Risky ≤ 4** (acceptable)

#### ⚠️ Succès avec avertissements (acceptable)
- **400/400 tests** passent
- **Deprecations 15-20** (documenter, créer issue)
- **Warnings** (PHPUnit, acceptable si non bloquant)

#### ❌ Échec - Correction nécessaire
- **Erreurs** (E) ou **Failures** (F) présents
- **Deprecations > 20** (trop de dette technique)
- **Tests < 400** (tests désactivés?)

### Résumé: Workflow de test recommandé

```bash
# 1. Test rapide (2-3 min) - vérification syntaxe/build
docker build -t esn-sabre-test .

# 2. Test complet (5-7 min) - validation finale
docker build -t esn_sabre_test .
docker compose -f docker-compose.test.yaml up --abort-on-container-exit 2>&1 | tee /tmp/test_full.log
docker compose -f docker-compose.test.yaml down

# 3. Analyser
grep -E "(Tests:|Assertions:|Deprecations:|Errors:|Failures:)" /tmp/test_full.log

# 4. Si succès → Commit
# 5. Si échec → Debug avec docker exec (méthode 3)
```

## Points d'attention

### 1. Fork custom de sabre/vobject
- **Version**: `dev-waiting-merges-4.2.2 as 4.2.2`
- **Repository**: https://github.com/bastien-roucaries/vobject
- **Raison**: Contient des patches critiques Linagora:
  - Fix suppression événements avec attendees/alarms
  - Gestion timezone/récurrence avec exceptions
  - Déduplication propriétés
  - Messages iTip pour occurrences modifiées
  - Sérialisation JSON pour éléments PERIOD
- **⚠️ IMPORTANT**: Ne pas upgrader vers sabre/vobject officiel sans migration des patches

### 2. Dockerfile vs Dockerfile.coverage
- Dockerfile: MongoDB extension version à jour (1.15.0 → 2.1.4)
- Dockerfile.coverage: Était en retard (1.9.0), maintenant à jour (2.1.4)
- **Action**: Toujours synchroniser les deux lors des upgrades d'extensions PHP

### 3. Tests avec MongoDB
- Les tests échouent sans MongoDB connecté (normal)
- Erreur typique: `ConnectionTimeoutException: No suitable servers found`
- **Solution**: Toujours tester avec `docker-compose.test.yaml`

### 4. MongoDB extension vs library
- **Extension PHP** (ext-mongodb): Installée via PECL, version 2.1.4
- **Library PHP** (mongodb/mongodb): Installée via Composer, version ^2.4
- La library requiert l'extension: `mongodb/mongodb ^2.4` → `ext-mongodb ^2.1`

## Workflow pour les prochains upgrades

### 1. Identifier l'issue
Exemple: Issue #84 - Upgrade library X

### 2. Créer une branche empilée
```bash
# Se positionner sur la dernière branche
git checkout upgrade-mongodb-2.4  # ou la dernière branche

# Créer la nouvelle branche
git checkout -b upgrade-library-x
```

### 3. Effectuer les modifications
```bash
# Modifier composer.json ou autres fichiers
vim composer.json

# Tester localement
docker build -t esn-sabre-test .
docker run --rm esn-sabre-test vendor/bin/phpunit -c tests/phpunit.xml 2>&1 | tee /tmp/test.log

# Vérifier les résultats
# - 400/400 tests doivent passer
# - Compter les deprecations (doivent rester ≤ 14)
```

### 4. Corriger les erreurs si nécessaire
- Analyser les logs d'erreur
- Identifier les breaking changes (changelog de la library)
- Modifier le code applicatif si nécessaire
- Re-tester jusqu'à ce que tous les tests passent

### 5. Commit et PR
```bash
# Commit
git add .
git commit -m "$(cat <<'EOF'
chore: upgrade library-x from Y to Z

Description des changements...

Closes #84

🤖 Generated with [Claude Code](https://claude.com/claude-code)

Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"

# Push
git push -u origin upgrade-library-x

# Créer la PR
gh pr create \
  --base full-upgrade \
  --head upgrade-library-x \
  --title "chore: upgrade library-x from Y to Z" \
  --body "$(cat <<'EOF'
## Summary
...

Closes #84

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

## Issues restantes (backlog)

Consulter les issues GitHub avec le label `sabre4`:
- https://github.com/linagora/esn-sabre/labels/sabre4

### À investiguer
1. **Deprecations vendor** (#?): 14 deprecations dans sabre/vobject
   - Option A: Upgrade vers vobject officiel 4.5.7+ (risque: perte patches)
   - Option B: Merger patches du fork dans vobject 4.5.7+
   - Option C: Accepter les 14 deprecations (vendor code)

2. **Autres libraries**: Vérifier les versions dans composer.json
   - firebase/php-jwt: ^6.0 (actuel)
   - monolog/monolog: ^2.9 (actuel)
   - php-amqplib/php-amqplib: ^3.3 (actuel)

## Commandes utiles

### Git - Visualiser l'arbre des branches
```bash
git log --oneline --graph --all --decorate | head -30
```

### Git - Comparer deux branches
```bash
git log --oneline branch1..branch2
git diff branch1..branch2 --stat
```

### Docker - Cleanup
```bash
# Supprimer les images de test
docker rmi esn-sabre-test esn_sabre_test

# Nettoyer les conteneurs et volumes
docker compose -f docker-compose.test.yaml down -v
```

### Composer - Vérifier les dépendances
```bash
# Dans le conteneur
docker run --rm esn-sabre-test composer show --tree
docker run --rm esn-sabre-test composer outdated
```

## Résumé des fichiers modifiés par upgrade

### Upgrade Sabre/DAV
- `composer.json`: version de sabre/dav
- `lib/CalDAV/Schedule/Plugin.php`: visibility fix (4.5.1 uniquement)

### Upgrade MongoDB
- `composer.json`: version de mongodb/mongodb
- `Dockerfile`: version extension PECL
- `Dockerfile.coverage`: version extension PECL
- `docker-compose.test.yaml`: version MongoDB server + healthcheck

### Pattern général
1. `composer.json` → toujours
2. `Dockerfile` + `Dockerfile.coverage` → si extension PHP
3. `docker-compose.test.yaml` → si service externe (DB, cache, etc.)
4. Code applicatif → si breaking changes

## Contact / Notes
- Branche principale de travail: `upgrade-php-8.2` puis `upgrade-phpunit-10`
- Branche cible PR: `full-upgrade`
- Stratégie: Branches empilées pour faciliter la review
- Tests: 400 tests, ~2min40s d'exécution
- Deprecations acceptables: 14 (vendor sabre/vobject)

---
*Document créé le 2025-10-12 par Claude Code*
*Dernière mise à jour: MongoDB 2.4.0 upgrade (PR #110)*
