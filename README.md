# YouTube Analyse — Dashboard Symfony

Dashboard d'analyse de chaîne YouTube avec synchronisation automatique, analyses IA (Claude) et visualisations avancées.

---

## Variables d'environnement requises

Copier `.env` et créer `.env.local` pour les surcharges :

```
# Base de données (MariaDB/MySQL)
DATABASE_URL="mysql://user:password@host:3306/youtube_analyse?serverVersion=8.0&charset=utf8mb4"

# Google OAuth2 (YouTube Data API v3 + Analytics API v2)
GOOGLE_CLIENT_ID=xxxx.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=GOCSPX-xxx
GOOGLE_REDIRECT_URI=https://votre-domaine.com/auth/google/callback
GOOGLE_API_KEY=AIzaSy...

# Anthropic (Claude)
ANTHROPIC_API_KEY=sk-ant-api03-...

# Seuil CTR pour déclencher l'optimisation de titre (défaut : 4%)
AI_CTR_THRESHOLD=4.0
```

---

## Commandes Symfony

### Synchronisation YouTube

```bash
# Synchronise vidéos, métriques, commentaires pour tous les utilisateurs connectés
# Puis lance automatiquement l'analyse IA
php bin/console app:youtube:sync
```

### Analyse IA

```bash
# Exécute toutes les analyses IA (sauf upload_schedule)
php bin/console app:ai:analyze

# Exécuter un seul type d'analyse
php bin/console app:ai:analyze --type=title_optimization
php bin/console app:ai:analyze --type=comment_analysis
php bin/console app:ai:analyze --type=anomaly
php bin/console app:ai:analyze --type=prediction
php bin/console app:ai:analyze --type=upload_schedule
```

Types disponibles :
| Type | Déclencheur |
|------|------------|
| `title_optimization` | Vidéos < 7j avec CTR < seuil |
| `comment_analysis` | Vidéos avec nouveaux commentaires |
| `anomaly` | Vidéos actives < 90j avec écart > 2σ |
| `prediction` | Vidéos publiées il y a exactement 48h |
| `upload_schedule` | Manuel / CRON dominical |

### Autres commandes utiles

```bash
# Promouvoir un utilisateur admin
php bin/console app:promote-admin email@exemple.com

# Reset du quota guard YouTube API
php bin/console cache:pool:clear cache.app

# Migrations
php bin/console doctrine:migrations:migrate

# Vider le cache
php bin/console cache:clear
```

---

## Procédure OAuth YouTube au premier lancement

1. Aller sur `https://votre-domaine.com/login`
2. Cliquer **Se connecter avec Google**
3. Autoriser l'accès à YouTube Analytics (scope requis)
4. Sélectionner la chaîne YouTube à analyser
5. Le premier compte devient automatiquement administrateur
6. Les comptes suivants nécessitent une approbation admin sur `/admin`

---

## Planification des CRONs sur Synology DS1522+

### Procédure dans le Planificateur de tâches Synology

1. Ouvrir le **Panneau de configuration** du DSM
2. Aller dans **Planificateur de tâches**
3. Cliquer **Créer** → **Tâche planifiée** → **Script défini par l'utilisateur**

#### Tâche 1 — Synchronisation quotidienne (06h00)

| Champ | Valeur |
|-------|--------|
| Nom de la tâche | YouTube Sync Quotidienne |
| Utilisateur | `http` (ou l'utilisateur qui a les droits PHP) |
| Calendrier | Quotidien |
| Heure | 06:00 |
| Répétition | Aucune |

**Script :**
```bash
/usr/local/bin/php /volume1/web/youtube-analyse/bin/console app:youtube:sync >> /volume1/logs/youtube_sync.log 2>&1
```

#### Tâche 2 — Recommandations publication (dimanche 07h00)

| Champ | Valeur |
|-------|--------|
| Nom de la tâche | YouTube AI Upload Schedule |
| Utilisateur | `http` |
| Calendrier | Hebdomadaire |
| Jour | Dimanche |
| Heure | 07:00 |

**Script :**
```bash
/usr/local/bin/php /volume1/web/youtube-analyse/bin/console app:ai:analyze --type=upload_schedule >> /volume1/logs/ai_schedule.log 2>&1
```

> **Important :** Adapter `/volume1/web/youtube-analyse/` au chemin réel du projet sur le NAS.  
> Vérifier le chemin PHP : `which php` dans le terminal SSH.

---

## Déploiement sur Synology DS1522+

### Prérequis

- Paquet **PHP 8.2** installé via le Centre de paquets Synology
- Paquet **MariaDB 10** installé
- Paquet **Web Station** avec serveur virtuel Nginx configuré
- Accès SSH activé

### Étapes de déploiement

```bash
# 1. Cloner le repo sur le NAS (via SSH)
cd /volume1/web
git clone https://github.com/votre-repo/youtube-analyse.git

# 2. Installer les dépendances (sans dev)
cd youtube-analyse
composer install --no-dev --optimize-autoloader

# 3. Configurer l'environnement
cp .env .env.local
# Éditer .env.local avec les vraies valeurs (DB, clés API, etc.)
nano .env.local

# 4. Créer la base de données et lancer les migrations
php bin/console doctrine:database:create --if-not-exists
php bin/console doctrine:migrations:migrate --no-interaction

# 5. Compiler les assets
npm ci
npm run build

# 6. Configurer les permissions
chmod -R 775 var/ public/uploads/
chown -R http:http var/ public/uploads/

# 7. Vider le cache prod
APP_ENV=prod php bin/console cache:clear
APP_ENV=prod php bin/console cache:warmup
```

### Configuration Web Station (Nginx)

Dans Web Station → Portail basé sur le nom de domaine :
- Dossier racine : `/volume1/web/youtube-analyse/public`
- PHP : version 8.2
- Activer le support HTTPS

---

## Reset du quota guard en cas de blocage

Si la limite YouTube API (9 000 units/jour) est atteinte par erreur :

```bash
# Vider le cache applicatif (réinitialise le compteur quota)
php bin/console cache:pool:clear cache.app

# Vérifier l'état du quota dans les logs
tail -f var/log/dev.log | grep quota
```

Le compteur se remet à zéro automatiquement à minuit heure du Pacifique (heure de réinitialisation des quotas YouTube).

---

## Architecture

```
src/
├── Command/
│   ├── AiAnalyzeCommand.php          # app:ai:analyze
│   ├── SyncAnalyticsCommand.php      # app:youtube:sync
│   └── PromoteAdminCommand.php       # app:promote-admin
├── Controller/
│   ├── DashboardController.php       # /, /sync, /videos, /api/chart-data
│   ├── VideoController.php           # /analytics/videos, /alerts, /ai-costs
│   ├── AdminController.php           # /admin/*
│   └── AuthController.php            # /auth/*
├── Entity/
│   ├── User.php, GoogleToken.php     # Auth
│   ├── ChannelStats.php, VideoStats.php  # Legacy snapshots
│   ├── Video.php                     # Catalogue vidéos
│   ├── DailyMetric.php               # Métriques journalières
│   ├── RetentionPoint.php            # Courbe de rétention
│   ├── Comment.php                   # Commentaires YouTube
│   └── AiReport.php                  # Rapports IA
├── Enum/
│   ├── AiReportType.php              # 5 types d'analyse
│   └── AiReportStatus.php            # pending / done / failed
└── Service/
    ├── GoogleAuthService.php          # OAuth2 Google
    ├── YouTubeDataService.php         # Sync legacy dashboard
    ├── YouTubeSyncService.php         # Sync pipeline analytique
    ├── AiAnalysisService.php          # Orchestration 5 analyses
    ├── AnthropicService.php           # Client Claude API
    └── QuotaGuardService.php          # Garde-fou quota YouTube

config/prompts/                        # Prompts Claude versionnés
    title_optimization.txt
    comment_analysis.txt
    anomaly.txt
    prediction.txt
    upload_schedule.txt
```

---

## Logs

| Fichier | Contenu |
|---------|---------|
| `var/log/dev.log` | Tous les logs en développement |
| `var/log/youtube_sync.log` | Logs sync YouTube (channel `youtube_sync`) |
| `var/log/ai_analysis.log` | Logs appels Claude (channel `ai_analysis`) |
