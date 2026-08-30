# Hébergement sur le NAS Synology (Synonyx)

## Adresse officielle (à mettre en favori)

Même domaine DDNS qu’ONYX — **avec un point** avant `synology` :

| App | URL |
|-----|-----|
| **Vol&Mat Gestion** | **https://onyx-office.synology.me/voletmat_gestion/** |
| ONYX (référence) | https://onyx-office.synology.me/onyx_app_dev_2026/ |

Portail Web Station : type **alias** `voletmat_gestion` → service dont la racine est déjà `…/public`  
→ **pas** de `/public/` dans l’URL du navigateur.

Sur le LAN : `http://192.168.11.11/voletmat_gestion/`

> Ne pas utiliser `onyx-office-synology.me` (sans point) : ce nom **n’existe pas** en DNS.

## État

- Code + base dans `web/voletmat_gestion/`
- Base : `storage/data/voletmat_gestion.sqlite` (factures, banque, etc.)
- Domaine OK : `https://onyx-office.synology.me/…`
- **Diagnostic 500** : le HTML statique répond (200), mais **PHP n’est actif que sous** `onyx_app_dev_2026/` (testé : `PING` PHP = 200 là, 500 partout ailleurs). Ce n’est pas un bug de l’app : il manque le **portail / service PHP** pour Vol&Mat.

## Service + portail Web Station (obligatoire)

Même schéma qu’ONYX — service **et** portail qui le publie sur le domaine.

### A. Service web

1. **Web Station** → **Service web** → Créer (ou éditer `voletmat-gestion`)
2. **Racine des documents** : `web/voletmat_gestion/public` (uniquement `public`)
3. **Backend** : Nginx (comme ONYX)
4. **PHP** : profil **8.2** ou **8.4** (pas « Aucun »)

### B. Portail (c’est souvent ça qui manque)

1. **Web Station** → **Portail** (ou **Portail web**)
2. Regarder le portail d’**ONYX** (hostname `onyx-office.synology.me` + chemin `/onyx_app_dev_2026`)
3. **Créer** un portail pour Vol&Mat :
   - **Nom d’hôte** : `onyx-office.synology.me` (le même)
   - **Port** : 443 (HTTPS) — comme ONYX
   - **Chemin d’accès** (si demandé) : `/voletmat_gestion/public`  
     *ou* un chemin court `/gestion` pointant vers le même service
   - **Service web** : `voletmat-gestion`

Sans cette étape B, le navigateur sert les fichiers en statique et **tout `.php` renvoie 500**.

### C. Contrôle rapide

Après enregistrement, ouvrir :

`https://onyx-office.synology.me/voletmat_gestion/public/_ping.php`

- Attendu : texte `PING_OK`
- Puis : `…/public/` → page de connexion
- Ensuite supprimer `_ping.php` et `_static.html`

## Droits (File Station)

Sur `voletmat_gestion` :

1. Propriétés → Autorisation
2. Utilisateur **`http`** : lecture sur le projet, **lecture + écriture** sur `storage/`

## Connexion

Compte créé à l’init (`sabine` + votre mot de passe).  
Après OK : supprimer ou renommer `public/setup.php`.

## Sécurité

- Racine web = `public` seulement
- Le DDNS est joignable hors LAN : HTTPS déjà en place via Synology ; garder un mot de passe fort ; VPN recommandé hors bureau
