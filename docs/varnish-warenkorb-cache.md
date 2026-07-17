# ⛔ Warenkorb-Cache: Vorschlag VERWORFEN — nicht umsetzen

**Stand:** 2026-07-17 · **Status:** ❌ **abgelehnt nach Gegenprüfung (Codex)**
**Betrifft:** espressoperfetto.de (Cloudways, Varnish 6.0.18 + Breeze)

> **Wer nur eine Zeile liest:** Der ursprünglich hier vorgeschlagene VCL-Block
> (Warenkorb-Cookies strippen, damit Cart-Kunden gecachte Seiten bekommen) ist
> **nicht deploymentfähig**. Er hätte Warenkörbe geleert, `add-to-cart`
> zerschossen und in den Fremdsprachen Warenkorb-Seiten cachebar gemacht.
> Codex-Session: `019f6fac-00e1-76b2-823a-9122007e0166`.

## Was stimmt (bestätigt)

- **Der Bypass existiert und kostet Tempo.** `/etc/varnish/recv/woocommerce.vcl:66-69`
  piped bei `woocommerce_(cart|session)` und `wp_woocommerce_session`.
  Gemessen: `woocommerce_cart_hash` allein → 3,40 s ohne HIT; `woocommerce_items_in_cart`
  allein → 0,075 s **mit** HIT. Kunden mit Warenkorb surfen also spürbar langsamer.
- **`custom-recv.vcl` läuft vor `recv/woocommerce.vcl`** (`cloudways.vcl:63` vs. `:69`).
  Technisch wäre ein Eingriff dort also möglich — genau das ist aber das Problem
  (s. „Reihenfolgefehler").
- **Deutsche Slugs greifen nicht in der Cloudways-URL-Regel:** `/warenkorb/` matcht
  kein `cart`, `/mein-konto/` kein `my-account`. Sie hängen wirklich nur am Cookie.

## Warum der Vorschlag trotzdem falsch war — 5 widerlegte Annahmen

### 1. ❌ „Die Seiten sind personalisierungsfrei"

**Der HTML-Diff war methodisch wertlos.** Er wurde mit einem *erfundenen* Cookie
(`woocommerce_cart_hash=abc123`) gemacht — dahinter lag keine echte Session, also
sah WooCommerce einen **leeren Warenkorb** und personalisierte nichts. Erwartungsgemäß
war das HTML identisch. Der Test hat schlicht zwei anonyme Seiten verglichen.

**Real personalisiert wird sehr wohl.** `wp option get woo-discount-config-v2`:
```json
"modify_price_at_shop_page":"1",
"modify_price_at_product_page":"1",
"modify_price_at_category_page":"1"
```
Aktive Regel 103 (`wpuk_wdr_rules`): 10 % Rabatt bei `cart_subtotal >= 300` bzw.
Coupon `sxoahelvrz`. WDR rechnet das laut `ManageDiscount.php:256-298` **auf Produkt-,
Shop- und Kategorieseiten**. Ein Kunde mit >300 € im Korb sieht dort also **andere
Preise** — genau das, was ein geteilter Cache verbreiten würde.

Dazu: `woocommerce_tax_based_on = shipping` → Steuer hängt am Kundenland
(`class-wc-customer.php:185-207`).

Und die Behauptung „Cart-Fragments sind aus" stimmt nur halb: `disable-cart-fragments.php`
dequeued mit Prio 999, **CheckoutWC hängt es mit Prio 10000 wieder ein**
(`SideCart.php:175-212`); `wc-cart-fragments-js` ist live im HTML.

### 2. ❌ „Weglot übersetzt die Slugs nicht"

Falsch — der Test sah `HTTP 301` und folgte dem Redirect nicht:
```
/en/warenkorb/  → 301 → /en/card/
/en/mein-konto/ → 301 → /en/my-account/
                        /en/checkout-checkout/
```
**`/en/card/` matcht weder den Vorschlag noch die Cloudways-Regel `cart`.** Der
Vorschlag hätte dort die Cookies gestrippt → **englische Warenkorb-Seite cachebar**.

### 3. ❌ „Anonym kommt kein Set-Cookie"

Der Test lief auf einer *Produktseite*. Die Warenkorb-Pfade sehr wohl:
```
HEAD /warenkorb/       → Set-Cookie: wp_woocommerce_session_...
HEAD /kasse-checkout/  → Set-Cookie: wp_woocommerce_session_...
HEAD /en/card/         → Set-Cookie: wp_woocommerce_session_...
```

### 4. ❌ „cloudways.vcl:106/110 sind ein Cookie-Netz"

Sind sie nicht: Z. 106 markiert **Fehlerstatus** uncachebar, Z. 110 nur
`Content-Length: 0`. Der reguläre Woo-Pfad setzt dagegen pauschal
`beresp.ttl = 30d` (`backend_response/woocommerce.vcl:7`, `varnish_default_ttl.vcl:2`).

### 5. ❌ Kritischer Reihenfolgefehler — der Block hätte Warenkörbe zerstört

`custom-recv.vcl` läuft **vor** `woocommerce.vcl:49-52` (POST → pipe) und `:62`
(`add-to-cart`/`wc-ajax` → pipe). Ablauf bei `POST /?wc-ajax=add_to_cart`:
1. Block entfernt `wp_woocommerce_session_*`
2. **erst danach** greift POST → pipe
3. WooCommerce bekommt den POST **ohne bestehenden Warenkorb**

→ Warenkorb geleert, neue Session, Produkt landet im falschen Korb. Der Block hatte
keine Methodenbeschränkung.

Weitere Fehlklassifikationen (Codex-Simulation gegen die Regex):
```
STRIPPED  /en/card/                 ← Leak
STRIPPED  /en/checkout-checkout/    ← Leak
STRIPPED  /en/my-account/orders/    ← Leak
STRIPPED  /WARENKORB/               ← Gross-/Kleinschreibung
STRIPPED  /%77arenkorb/             ← URL-kodiert
STRIPPED  /?add-to-cart=123         ← zerschiesst Warenkorb
```

## ⚠ Nebenbefund: möglicher Breeze-Poisoning-Pfad (BESTEHT SCHON HEUTE)

Unabhängig vom Vorschlag: **Breeze prüft nur `wordpress_logged_in_*`**
(`inc/cache/execute-cache.php:141-171`), **nicht** die Woo-Cookies. Alle nicht
eingeloggten Anfragen teilen sich einen `?guest`-Cachekey (Z. 128-139, 493-508).
`DONOTCACHEPAGE` setzt WooCommerce nur für Cart/Checkout/My-Account
(`class-wc-cache-helper.php:48-58`), **nicht** für Besucher mit aktivem Cart-Cookie.

→ Ein cart-/couponabhängiger WDR-Preis (Regel 103) könnte als gemeinsamer
Breeze-Body gespeichert und an andere Gäste ausgeliefert werden.

**Status: Hypothese, nicht nachgewiesen.** Codex hat 1.301 Breeze-Objekte geprüft:
kein serverseitiger Mini-Cart-Marker gefunden (CheckoutWC lädt den Side-Cart
clientseitig). Der Pfad für personalisierte **Preise** bleibt aber offen.
**Das gehört unabhängig geprüft.**

Breeze-Ausschlüsse enthalten zudem nur die deutschen Slugs
(`/warenkorb/*`, `/kasse-checkout/*`, `/mein-konto/*`) — **nicht** `/en/card/` & Co.

## Falls das Thema je wieder aufgegriffen wird

Vorbedingungen, die **vorher** erfüllt sein müssen:

1. **Beweisen, dass die Seiten personalisierungsfrei sind — mit ECHTER Session.**
   Zwei gültige WooCommerce-Sessions aufbauen (eine mit >300 € Warenkorb, eine leer),
   dann HTML vergleichen. Der Fake-Cookie-Test ist wertlos.
2. **WDR-Preisänderung auf Shop-/Kategorie-/Produktseiten abschalten**
   (`modify_price_at_*` = 0) oder nachweisen, dass keine Regel cart-/couponabhängig ist.
3. **Alle Weglot-Slugs enumerieren** — über die tatsächlichen Sprachlinks, nicht geraten.
   Bekannt: `/en/card/`, `/en/checkout-checkout/`, `/en/my-account/`.
4. **Methoden- und Query-Schutz:** nur GET, und `add-to-cart`/`wc-ajax`/`s=` vorher
   ausschliessen — der Block läuft vor den Cloudways-Pipes.
5. **Breeze zuerst klären** (s. Nebenbefund), sonst verlagert sich das Problem nur.
6. **Erst dann** Testplan: zwei echte Sessions, Cart-Leak-Test, Checkout-Durchlauf.

## Lehren

- **Ein erfundenes Cookie ist keine Session.** Der Diff verglich zwei anonyme Seiten.
- **301 ist kein Ergebnis.** Der Weglot-Test hat den Redirect nicht verfolgt.
- **Reihenfolge in der VCL ist Semantik.** „Läuft vorher" war als Vorteil notiert —
  es war der Grund für den schlimmsten Fehler.
- Bei allem, was Warenkörbe berührt: **gegenprüfen lassen.** Diese Analyse sah
  sauber aus und war es an fünf Stellen nicht.
