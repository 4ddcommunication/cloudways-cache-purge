# Warenkorb-Kunden bekommen ungecachte Seiten (3,5 s statt 0,07 s)

**Stand:** 2026-07-17 · **Status:** analysiert, **nicht umgesetzt** — braucht bewusste Freigabe
**Betrifft:** espressoperfetto.de (Cloudways, Varnish + Breeze)

## Das Problem

Sobald ein Kunde etwas in den Warenkorb legt, setzt WooCommerce die Cookies
`woocommerce_cart_hash`, `woocommerce_items_in_cart` und `wp_woocommerce_session_*`.
Ab diesem Moment umgeht **jeder weitere Seitenaufruf den Varnish-Cache komplett**.

Gemessen am 17.07.2026 (`curl -sI`, Header `x-cache`):

| Request | TTFB | x-cache |
|---|---|---|
| Startseite anonym | **0,07 s** | `HIT`, age 422 |
| Startseite mit `woocommerce_items_in_cart=1` allein | 0,075 s | `HIT` |
| Startseite mit `woocommerce_cart_hash` | **3,40 s** | kein HIT |
| Startseite mit `wp_woocommerce_session_*` | **3,61 s** | kein HIT |
| Startseite mit `wordpress_logged_in_*` | **3,37 s** | kein HIT |

Reproduzierbar über mehrere Messungen, auch mit nie gesehenen Cookie-Werten →
**echter Bypass, kein Cache-Miss.**

**Der Kunde surft also ab dem ersten „In den Warenkorb" mit ~3,5 s pro Seite —
also genau im Kaufprozess.** Anonyme Besucher sind mit 0,07 s unbetroffen.

## Die verantwortliche Regel

`/etc/varnish/recv/woocommerce.vcl:66-69` (Cloudways-verwaltet):

```vcl
# EXCLUDE CACHE IF WORDPRESS/WOOCOMMERCE COOKIES ARE FOUND
if (req.http.Cookie ~ "wordpress_logged_in|resetpass|wp-postpass|wordpress_(?!test_)[a-zA-Z0-9_]+|comment_|woocommerce_(cart|session)|wp_woocommerce_session") {
        return (pipe);
}
```

`woocommerce_(cart|session)` und `wp_woocommerce_session` sind die relevanten Teile.

## Warum der Bypass hier überflüssig ist

Der Bypass existiert, damit Kunden nicht die Warenkorb-Anzeige anderer Kunden
sehen. **Auf dieser Site gibt es aber gar keine serverseitige Personalisierung.**

Nachgewiesen per HTML-Diff (identische URL, einmal anonym, einmal mit Cookies;
Rauschpegel durch zwei anonyme Abrufe kalibriert):

| Seite | anon vs anon (Rauschen) | anon vs Warenkorb |
|---|---|---|
| `/espressomaschine/alle-maschinen/` | 70 Zeilen | **70 Zeilen** |
| `/produkt/rocket-wassertank/` | 332 Zeilen | **334 Zeilen** |
| Startseite | — | **68 Zeilen** |

Der Unterschied liegt exakt auf dem Rauschpegel (Nonces). Zusätzlich: Im HTML
existiert **kein** `cart-contents-count`, `mini-cart` oder `widget_shopping_cart` —
der Warenkorb wird vollständig clientseitig aufgebaut. Passt dazu, dass die
Cart-Fragments bewusst abgeschaltet sind (`mu-plugins/disable-cart-fragments.php`).

**Ergebnis: Warenkorb-Kunden zahlen 3,5 s für exakt die Seite, die anonym aus dem
Cache in 0,07 s käme.**

## ⚠ Die Falle: /warenkorb/ und /mein-konto/ hängen NUR am Cookie

Die URL-Ausschlussregel darüber (`woocommerce.vcl:62`) matcht auf
`cart|my-account|checkout` — **die deutschen Slugs greifen da nicht:**

| URL | von der URL-Regel geschützt? |
|---|---|
| `/kasse-checkout/` | ja (enthält „checkout") |
| `/warenkorb/` | **NEIN** |
| `/mein-konto/` | **NEIN** |

Diese beiden Seiten sind heute **ausschließlich** durch den Cookie-Bypass geschützt.
Wer die Cookies strippt, ohne die URLs auszuschließen, macht sie cachebar —
**dann bekäme ein Kunde den Warenkorb eines anderen ausgeliefert.**

Entwarnung bei der Sprach-Enumerierung: **Weglot übersetzt die Slugs nicht.**
Der Warenkorb ist in allen 7 Sprachen `/…/warenkorb/` (geprüft für en/fr/es/nl/it/pl).
Eine Regel deckt also alle Sprachen ab.

## Vorschlag

Die App-eigene `custom-recv.vcl` wird in `cloudways.vcl:63`
(`additional_vcls/recv.vcl`) eingebunden — also **vor** `recv/woocommerce.vcl:69`.
Dort lassen sich die Cookies entfernen, bevor die Bypass-Regel sie sieht. Die
Cloudways-Datei bleibt unangetastet.

Datei: `/home/1312124.cloudwaysapps.com/mnpttwzgfv/conf/custom-recv.vcl`

```vcl
# Warenkorb-Kunden sollen gecachte Seiten bekommen: Die Seiten sind
# personalisierungsfrei (HTML-Diff anon vs. Warenkorb = Rauschpegel), der
# Bypass kostet nur 3,5 s statt 0,07 s.
#
# NICHT anfassen bei:
#  - eingeloggten Usern (Adminbar, evtl. B2B-Preise)
#  - Warenkorb/Kasse/Konto — die deutschen Slugs greifen NICHT in der
#    URL-Regel von woocommerce.vcl:62 und haengen nur am Cookie!
if (req.url !~ "/(warenkorb|mein-konto|kasse)" &&
    req.http.Cookie !~ "wordpress_logged_in") {
    set req.http.Cookie = regsuball(req.http.Cookie,
        "(^|; ?)(woocommerce_cart_hash|woocommerce_items_in_cart|wp_woocommerce_session_[^=]*)=[^;]*", "");
    if (req.http.Cookie ~ "^\s*$") { unset req.http.Cookie; }
}
```

### Warum das mit Set-Cookie sicher ist

Gemessen: Der **anonyme** Pfad sendet **kein** `Set-Cookie` (`x-cache: MISS`,
echter Render, keine Set-Cookie-Header). Erst wenn ein Session-Cookie
mitkommt, antwortet die Site mit `set-cookie: mailchimp_landing_site=…` und
räumt ungültige Cart-Cookies ab.

Da das Strippen den Request **anonym** macht, entsteht auch kein Set-Cookie →
nichts Personalisiertes landet im Cache. Zusätzlich hat `cloudways.vcl`
(vcl_backend_response, Z. 106/110) bereits `beresp.uncacheable`-Logik als Netz.

## Erwarteter Gewinn

**Warenkorb-Kunden: 3,5 s → 0,07 s pro Seite.** Faktor ~50, und zwar genau im
Kaufprozess. Zusätzlich sinkt die PHP-Last spürbar — ein Teil der 43.375
PHP-Requests/8 h (Ø 3,20 s) entfällt.

## Testplan vor dem Scharfschalten

1. **Kein Cart-Leak:** Zwei verschiedene Sessions Produkte in den Warenkorb legen,
   `/warenkorb/` beidseitig aufrufen → jeder sieht NUR seinen Warenkorb.
   `curl -sI /warenkorb/` darf **nie** `x-cache: HIT` liefern.
2. **Checkout unberührt:** `/kasse-checkout/` mit Session → kein HIT, Bestellung
   testweise bis zur Zahlungsauswahl durchklicken.
3. **Eingeloggt unberührt:** Als Admin im Frontend → kein HIT (Adminbar sichtbar).
4. **Gewinn belegen:** `curl -sI -b "woocommerce_cart_hash=abc" /` → muss `HIT`
   und < 0,2 s liefern.
5. **Sprachen:** dieselben Tests auf `/en/`, `/fr/`.
6. **Rollback:** Zeilen aus `custom-recv.vcl` entfernen, `varnishadm vcl.load` bzw.
   Cloudways-Cache-Purge. ⚠ Cloudways kann `custom-recv.vcl` bei Änderungen über
   das Panel („Exclude URL from Varnish") **überschreiben** — nach solchen
   Aktionen prüfen, ob der Block noch drinsteht.

## Offene Risiken

- **Cloudways überschreibt `custom-recv.vcl`** bei Panel-Aktionen → Block wäre weg,
  Verhalten fiele auf „langsam aber sicher" zurück. Unschön, aber ungefährlich.
- **Wenn später ein serverseitiger Mini-Warenkorb eingebaut wird** (z.B. Cart-Fragments
  reaktiviert), wird dieser Block **sofort gefährlich**. Dann muss er weg. Deshalb
  hier dokumentiert und im VCL kommentiert.
- **B2B-Preise:** Falls es je rollenabhängige Preise für nicht eingeloggte User gäbe,
  wäre der Diff-Nachweis hinfällig. Aktuell: nicht der Fall.
