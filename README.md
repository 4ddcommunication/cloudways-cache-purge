# Cloudways Cache Purge

WordPress-Plugin von 4DD Communication GmbH.

Leert mit einem Klick:
1. **Breeze** (Minify + Page-Cache, lokal)
2. **Cloudways Varnish** (Server-Cache via API v2)

und wärmt anschließend automatisch die wichtigsten Seiten vor — damit Besucher keine 404-Fehler auf veralteten Asset-URLs sehen.

## Warum

Beim Cache-Leeren tritt ohne Pre-Warm regelmäßig folgender Bug auf:

- Breeze löscht alle Bundle-Hashes (`breeze_<page-id>-<hash>-...de.js`)
- Cloudways Varnish liefert noch altes HTML mit den eben gelöschten URLs
- Browser bekommt 404 auf jQuery, Borlabs, Cart-Fragments → Site optisch tot

Das Plugin koordiniert beide Caches und warmt anschließend die Hauptmenü-URLs.

## Funktionen

- **Admin-Bar-Button** "Server-Cache leeren" (rot, sichtbar konfigurierbar nach Rolle)
- **Settings-Seite** unter `Einstellungen → Cloudways Cache`
- **Pre-Warmer** mit konfigurierbarer Menü-Quelle, zusätzlichen URLs und Exclude-Patterns
- **Vorschau** der Pre-Warm-URLs auf der Settings-Seite
- **Logs**: letzter Purge & letzter Pre-Warm-Run

## Installation

Plugin-Ordner nach `wp-content/plugins/cloudways-cache-purge/` kopieren und im Admin aktivieren.

## Konfiguration

Unter `Einstellungen → Cloudways Cache`:

- **API:** Cloudways E-Mail, API Key, Server-ID, App-ID
- **Pre-Warm:** Menü-Location (default `primary`), zusätzliche URLs, Exclude-Patterns (default `/shop/` und `#`)

## Cloudways API v2 Setup

```bash
# Token holen
curl -X POST "https://api.cloudways.com/api/v2/oauth/access_token" \
     -d "email=DEINE_MAIL&api_key=DEIN_KEY"

# Server + App IDs ermitteln
curl -X GET "https://api.cloudways.com/api/v2/server" \
     -H "Authorization: Bearer DEIN_TOKEN"
```

## Changelog

- **1.2.0** — Breeze-Mit-Purge + Pre-Warmer mit Menü-Quelle und Exclude-Patterns
- **1.1.0** — Admin-Bar-Button + Settings-Seite (nur Cloudways API)
- **1.0.0** — Initial release
