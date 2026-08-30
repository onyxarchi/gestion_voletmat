# Vol&Mat Gestion

Application web de gestion pour Vol&Mat — **indépendante d’ONYX** (dossier, config et base séparés).

Référence fonctionnelle : classeurs Excel dans `aides/` (`_gestion_n4_24-25.xlsm`, `_gestion_n5_25-26.xlsm`). Ne pas modifier ces fichiers.

## Périmètre (feuilles reprises)

- CA  
- Planning facturation  
- facturation  
- Prévisionnel  
- COMPTA ANALYTIQUE (N5) / Suivi BQ (N4)  
- BANQUE (N5) / Pointage BQ (N4)  

Hors projet pour l’instant : prévi SL LD, Prospect, calcul salaire, Feuil2, marge collaborateur.

## Prérequis locaux (Mac)

- PHP **8.2+** (CLI) avec extensions : `pdo_sqlite`, `sqlite3`, `mbstring`, `zip`, `simplexml`
- Le serveur intégré PHP suffit pour démarrer

Vérification :

```bash
php -v
php -m | grep -i 'pdo_sqlite\|zip\|mbstring'
```

## Démarrage local

> **Important (Mac + partage SMB)** : SQLite ne fonctionne pas bien sur un volume
> monté (`/Volumes/web/...`). Pour développer depuis le Mac, pointez la base vers
> un fichier **local** :
>
> ```bash
> export VOLETMAT_SQLITE_PATH="$HOME/voletmat_gestion.sqlite"
> ```
>
> Sur le NAS (Web Station, disque local), `storage/data/` convient.

```bash
cd /Volumes/web/voletmat_gestion
export VOLETMAT_SQLITE_PATH="$HOME/voletmat_gestion.sqlite"
export VOLETMAT_LOGIN=sabine
export VOLETMAT_PASSWORD='choisissez-un-mot-de-passe'
php scripts/init_db.php
php -S localhost:8088 -t public
```

Ouvrir <http://localhost:8088/> et se connecter.

Base : le chemin défini par `VOLETMAT_SQLITE_PATH` (ou `storage/data/` sur le NAS).

## Hébergement NAS (adresse unique portable + Mac Studio)

**URL officielle** (même DDNS qu’ONYX) :

**https://onyx-office.synology.me/voletmat_gestion/**

Détails Web Station / droits : **[docs/NAS.md](docs/NAS.md)**.  
Base : `storage/data/voletmat_gestion.sqlite` sur le NAS.

## Import des classeurs N4 / N5

Remplit factures, banque, planning (affaires + échéances), budgets et CA historique
depuis `aides/_gestion_n4_24-25.xlsm` et `aides/_gestion_n5_25-26.xlsm` (lecture seule).

Prérequis : Python 3 + `openpyxl` (ex. venv `/tmp/voletmat_xlsm_analysis/venv`).

```bash
export VOLETMAT_SQLITE_PATH="$HOME/voletmat_gestion.sqlite"
/tmp/voletmat_xlsm_analysis/venv/bin/python scripts/import_classeurs.py
# ou : php scripts/import_classeurs.php
```

Puis rechargez Factures / Banque dans le navigateur.

## Import relevé CIC

1. Menu **Banque** → **Importer un relevé CIC** → déposer un `.xlsx` **ou** un `.pdf` CIC
2. Prévisualisation : OK / doublons / incertains  
3. Validation : seules les lignes OK sont enregistrées — **aucun montant inventé**

PDF : texte natif (pas un scan image). Si l’extraction échoue, installez `pdftotext` (poppler) sur le NAS, ou exportez en Excel depuis CIC.

Test CLI :

```bash
php scripts/test_cic_import.php
```

## Hébergement NAS Synology (Synonyx)

Déjà vérifié dans Web Station :

| Paquet | État |
|--------|------|
| Nginx / Apache 2.4 | Normal |
| PHP 8.2 / 8.4 | Normal |
| phpMyAdmin | Normal |
| Python 3.9 | optionnel (non requis pour l’app) |

### À faire sur le NAS (quand vous hébergerez)

1. **Service web** Web Station pointant vers ce dossier `voletmat_gestion/public` (pas le dossier ONYX).
2. Profil **PHP 8.2** ou **8.4**.
3. Base **MariaDB dédiée** + utilisateur dédié (via phpMyAdmin), *ou* rester en SQLite dans `storage/data/`.
4. Copier `config/config.local.php.example` → `config.local.php` et renseigner MySQL si besoin.
5. Droits d’écriture sur `storage/`.
6. Accès protégé (connexion déjà dans l’app).
7. Sauvegarde du dossier + de la base.

**Séparation d’ONYX** = autre dossier + autre base (autre utilisateur MariaDB). Même langage PHP, projets isolés. Pas d’o2switch.

## Structure

```
public/          ← racine web
config/          ← config (+ config.local.php hors dépôt)
src/             ← PHP métier
templates/       ← vues FR
sql/             ← schéma SQLite
scripts/         ← init, tests
storage/         ← base, uploads, backups
aides/           ← Excel de référence (lecture seule)
```

## Prochaines étapes de développement

1. Import des données N4 / N5 depuis les classeurs  
2. Écrans Planning (couleurs légende), Analytique, Prévisionnel  
3. Rapprochement banque ↔ factures  
4. Saisie factures / export / sauvegarde guidée  
5. Branchement Web Station + MariaDB sur le NAS  
