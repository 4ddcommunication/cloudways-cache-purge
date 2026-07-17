<?php
/**
 * Plugin Name: Cloudways Cache Purge
 * Plugin URI: https://github.com/4ddcommunication/cloudways-cache-purge
 * Description: Leert Breeze + Cloudways Server-Cache (Varnish) per Knopfdruck und wärmt danach die wichtigsten Seiten vor. Purged zusätzlich automatisch einzelne URLs bei Produkt-/Seiten-Änderungen (z.B. Preis/Bestand aus JTL-Wawi) und frischt nachts per WP-CLI den kompletten deutschen Bestand auf.
 * Version: 1.3.0
 * Author: 4DD Communication GmbH
 * Author URI: https://4dd.de
 * License: GPL v2 or later
 * Text Domain: cw-cache-purge
 */

if (!defined('ABSPATH')) {
    exit;
}

class Cloudways_Cache_Purge {

    private $option_name = 'cw_cache_purge_settings';
    private $api_base    = 'https://api.cloudways.com/api/v2';
    const CRON_HOOK      = 'cw_cache_purge_prewarm';

    /**
     * Meta-Keys, deren Aenderung eine Seite inhaltlich veraltet macht.
     *
     * WICHTIG: Der JTL-Wawi-Connector schreibt Preis und Bestand ueber
     * update_post_meta() bzw. wc_update_product_stock() — NICHT ueber die
     * WC-CRUD-Hooks allein. Breeze haengt nur an `transition_post_status`
     * und bekommt davon nichts mit. Deshalb ist `updated_post_meta` hier
     * das eigentliche Fangnetz, ohne das JTL-Aenderungen unsichtbar bleiben.
     */
    const WATCHED_META = [
        '_price', '_regular_price', '_sale_price',
        '_stock', '_stock_status', '_manage_stock', '_backorders',
    ];

    /** Post-Types, deren URLs bei Aenderung gepurged werden. */
    const PURGE_TYPES = ['product', 'page', 'post', 'ep_faq'];

    /** Weglot-Sprachpraefixe. Deutsch = kein Praefix (95,4 % des echten Traffics). */
    const LANG_PREFIXES = ['en', 'fr', 'es', 'nl', 'it', 'pl'];

    /** Gesammelte Post-IDs; wird einmal pro Request auf `shutdown` abgearbeitet. */
    private $purge_queue = [];

    /**
     * Produkte, bei denen sich der Streichpreis geaendert hat.
     *
     * Noetig, weil das ENTFERNEN eines Sale-Preises das Produkt von /sale/
     * verschwinden laesst — dann hat es kein _sale_price mehr, die Sale-Seiten
     * muessen aber trotzdem gepurged werden. Ohne dieses Merken wuerde ein
     * beendeter Sale bis zum Nacht-Refresh weiter beworben.
     */
    private $sale_touched = [];

    public function __construct() {
        add_action('admin_bar_menu', [$this, 'add_purge_button'], 999);
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_init', [$this, 'handle_purge_request']);
        add_action('admin_notices', [$this, 'show_admin_notices']);
        add_action('wp_head', [$this, 'add_button_styles']);
        add_action('admin_head', [$this, 'add_button_styles']);
        add_action(self::CRON_HOOK, [$this, 'run_prewarm'], 10, 1);
        add_action('wp_ajax_cw_suggest_rules', [$this, 'ajax_suggest_rules']);

        // --- Auto-Purge einzelner URLs (v1.3.0) ---
        if ($this->auto_purge_enabled()) {
            add_action('updated_post_meta', [$this, 'maybe_queue_by_meta'], 10, 3);
            add_action('added_post_meta', [$this, 'maybe_queue_by_meta'], 10, 3);
            add_action('woocommerce_update_product', [$this, 'queue_product_id'], 10, 1);
            add_action('woocommerce_product_set_stock', [$this, 'queue_product_object'], 10, 1);
            add_action('woocommerce_variation_set_stock', [$this, 'queue_product_object'], 10, 1);
            add_action('save_post', [$this, 'queue_saved_post'], 10, 2);
            add_action('shutdown', [$this, 'flush_purge_queue'], 999);
        }

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('cw-cache refresh', [$this, 'cli_refresh']);
            \WP_CLI::add_command('cw-cache refresh-listings', [$this, 'cli_refresh_listings']);
            \WP_CLI::add_command('cw-cache suggest-rules', [$this, 'cli_suggest_rules']);
            \WP_CLI::add_command('cw-cache purge-url', [$this, 'cli_purge_url']);
        }
    }

    private function auto_purge_enabled() {
        $settings = get_option($this->option_name, []);
        // Default AN: ohne Auto-Purge bleiben JTL-Preise bis zu 30 Tage alt (Varnish-TTL).
        return !isset($settings['auto_purge_enabled']) || !empty($settings['auto_purge_enabled']);
    }

    // =====================================================================
    // Auto-Purge (v1.3.0)
    // =====================================================================

    /**
     * Fangnetz fuer JTL: Preis-/Bestands-Meta wird direkt geschrieben,
     * ohne dass WC- oder Breeze-Hooks feuern.
     */
    public function maybe_queue_by_meta($meta_id, $object_id, $meta_key) {
        // Billigster Check zuerst — dieser Hook feuert bei JEDEM Meta-Write.
        if (!in_array($meta_key, self::WATCHED_META, true)) {
            return;
        }
        $type = get_post_type($object_id);
        if ($type === 'product') {
            $this->purge_queue[(int) $object_id] = true;
            if ($meta_key === '_sale_price') {
                $this->sale_touched[(int) $object_id] = true;
            }
        } elseif ($type === 'product_variation') {
            // Varianten haben keine eigene URL — das Elternprodukt purgen.
            $parent = wp_get_post_parent_id($object_id);
            if ($parent) {
                $this->purge_queue[(int) $parent] = true;
            }
        }
    }

    public function queue_product_id($product_id) {
        if ($product_id) {
            $this->purge_queue[(int) $product_id] = true;
        }
    }

    public function queue_product_object($product) {
        if (!is_object($product) || !method_exists($product, 'get_id')) {
            return;
        }
        $id = $product->get_id();
        if (method_exists($product, 'get_parent_id') && $product->get_parent_id()) {
            $id = $product->get_parent_id();
        }
        $this->purge_queue[(int) $id] = true;
    }

    /** Deckt u.a. „Banner auf eine Unterseite" ab: Seite speichern = URL frisch. */
    public function queue_saved_post($post_id, $post) {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        if (!is_object($post) || $post->post_status !== 'publish') {
            return;
        }
        if (!in_array($post->post_type, self::PURGE_TYPES, true)) {
            return;
        }
        $this->purge_queue[(int) $post_id] = true;
    }

    /**
     * Einmal pro Request auf `shutdown`: dedupliziert purgen.
     *
     * Warum gesammelt statt sofort: Ein JTL-Bulk-Sync schreibt pro Produkt
     * mehrere Metas (_price, _stock, _stock_status ...). Sofort-Purge wuerde
     * dieselbe URL vielfach purgen und Varnishs Ban-Liste aufblaehen.
     */
    public function flush_purge_queue() {
        if (empty($this->purge_queue)) {
            return;
        }

        $ids = array_keys($this->purge_queue);
        $this->purge_queue = [];

        // Schutz gegen Massen-Importe: lieber ein paar URLs stehen lassen,
        // als Varnish mit tausenden Bans zu fluten. Der Nacht-Cron holt den Rest.
        $max = (int) apply_filters('cw_cache_max_auto_purge', 100);
        $capped = count($ids) > $max;
        if ($capped) {
            $ids = array_slice($ids, 0, $max);
        }

        $urls = [];
        $product_changed = false;
        foreach ($ids as $id) {
            if (get_post_type($id) === 'product') {
                $product_changed = true;
            }
            $link = get_permalink($id);
            if (!$link) {
                continue;
            }
            foreach ($this->url_variants($link) as $variant) {
                $urls[$variant] = true;
            }
        }

        // Uebersichtsseiten (/espressomaschine/alle-maschinen/ etc.) zeigen Preis UND
        // Lagerstatus, liegen aber unter eigenen URLs — der Produkt-Purge oben erwischt
        // sie nicht. Zwei Wege, absichtlich kombiniert:
        //
        // 1. Purge-Regeln (Einstellungen): „marke:ecm => /espressomaschine/ecm/, ..."
        //    Greift eine Regel, werden GENAU diese Seiten sofort gepurged. Praezise
        //    und billig — statt 105 Seiten nur die betroffenen.
        // 2. Sicherheitsnetz: Greift fuer ein geaendertes Produkt KEINE Regel, wird
        //    der Sammellauf markiert (Cron alle 15 Min, alle 105 Menue-URLs). So ist
        //    nichts ungeschuetzt, solange die Regeln noch unvollstaendig sind.
        //
        // Direkt-Purge aller Uebersichten bei jeder Aenderung waere keine Option:
        // JTL aendert 45-131 Produkte/Werktag, die Seiten waeren dauernd kalt (~7 s).
        if ($product_changed) {
            $rule_urls   = [];
            $needs_bulk  = false;

            foreach ($ids as $id) {
                if (get_post_type($id) !== 'product') {
                    continue;
                }
                $matched = $this->urls_for_product($id);
                if ($matched === null) {
                    $needs_bulk = true; // keine Regel -> Sammellauf muss ran
                    continue;
                }
                foreach ($matched as $u) {
                    $rule_urls[$u] = true;
                }
            }

            foreach (array_keys($rule_urls) as $url) {
                $this->purge_url($url);
            }

            if ($rule_urls) {
                // ⚠ Dieser Code laeuft im shutdown-Hook — also INNERHALB des
                // JTL-Connector-Requests. Purgen ist billig (URLPURGE = Ban, ~10 ms),
                // Rewarming aber NICHT: ~7 s pro Seite, und ein Bohnen-Produkt trifft
                // 15 Seiten. Gemessen 17.07.: 34 s fuer 18 URLs. Wuerde die Wawi so
                // lange blockiert, drohen Connector-Timeouts.
                //
                // fastcgi_finish_request() sendet die Antwort und schliesst die
                // Verbindung — PHP arbeitet danach weiter. Nur WENN das geht (oder
                // wir ohnehin in der CLI sind), waermen wir hier. Sonst uebernimmt
                // der Sammellauf-Cron. Damit kann dieser Hook den Connector
                // konstruktiv nicht ausbremsen, egal wie die PHP-SAPI konfiguriert ist.
                $can_block = php_sapi_name() === 'cli' || function_exists('fastcgi_finish_request');

                if ($can_block) {
                    if (function_exists('fastcgi_finish_request')) {
                        fastcgi_finish_request();
                    }
                    // In Bloecken statt alles auf einmal: sonst startet ein grosser
                    // JTL-Sync dutzende Renders parallel und bremst den Server —
                    // genau die 7-s-Seiten, die wir vermeiden wollen.
                    $chunk = max(1, (int) apply_filters('cw_cache_rewarm_concurrency', 4));
                    foreach (array_chunk(array_keys($rule_urls), $chunk) as $batch) {
                        $this->fetch_parallel($batch);
                    }
                } else {
                    $needs_bulk = true; // gepurged ist schon; Waermen macht der Cron
                }
            }

            if ($needs_bulk) {
                update_option('cw_listings_dirty', time(), false);
            }

            update_option('cw_last_rule_purge', [
                'time'       => current_time('mysql'),
                'rule_urls'  => count($rule_urls),
                'bulk_noetig' => $needs_bulk,
            ], false);
        }

        $done = 0;
        foreach (array_keys($urls) as $url) {
            if ($this->purge_url($url)) {
                $done++;
            }
        }

        update_option('cw_last_auto_purge', [
            'time'    => current_time('mysql'),
            'posts'   => count($ids),
            'urls'    => $done,
            'capped'  => $capped,
        ], false);
    }

    /** AJAX: Regelvorschlaege fuer den Button „Vorschlaege laden" im Backend. */
    public function ajax_suggest_rules() {
        check_ajax_referer('cw_suggest_rules', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Keine Berechtigung');
        }
        wp_send_json_success($this->build_suggested_rules());
    }

    /**
     * Purge-Regeln aus den Einstellungen parsen.
     *
     * Syntax, eine Regel pro Zeile:
     *   marke:ecm => /espressomaschine/alle-maschinen/, /espressomaschine/ecm/
     *   kategorie:espressomaschinen => /espressomaschine/alle-maschinen/
     *
     * Bewusst manuell gepflegt statt automatisch aus dem Seiteninhalt geparst:
     * Die Uebersichtsseiten listen Produkte auf drei verschiedene Arten
     * ([ep_products], [ep_filtered_products], Uncode-loop mit tax_query) — teils
     * base64+urlencoded in [vc_raw_html] versteckt. Ein Parser dafuer haette einen
     * STILLEN Fehlermodus: uebersieht er eine Seite, zeigt sie unbemerkt falsche
     * Bestaende. Eine gepflegte Regel kann ein Mensch dagegen korrigieren.
     *
     * @return array Liste von ['type' => 'brand'|'cat', 'slug' => string, 'urls' => string[]]
     */
    private function parse_purge_rules() {
        $settings = get_option($this->option_name, []);
        $raw      = $settings['purge_rules'] ?? '';
        $rules    = [];

        foreach ($this->parse_url_lines($raw) as $line) {
            if (strpos($line, '=>') === false) {
                continue;
            }
            list($trigger, $targets) = array_map('trim', explode('=>', $line, 2));

            // „sale" hat keinen Slug — der Ausloeser ist der Streichpreis selbst.
            if (preg_match('/^(sale|streichpreis|angebot)$/i', $trigger)) {
                $type = 'sale';
                $slug = '*';
            } elseif (preg_match('/^(marke|brand|kategorie|cat|category)\s*:\s*(.+)$/i', $trigger, $m)) {
                $type = in_array(strtolower($m[1]), ['marke', 'brand'], true) ? 'brand' : 'cat';
                $slug = sanitize_title(trim($m[2]));
            } else {
                continue;
            }

            $urls = [];
            foreach (explode(',', $targets) as $u) {
                $u = trim($u);
                if ($u === '') {
                    continue;
                }
                // Relative Pfade auf die Domain heben.
                if (strpos($u, 'http') !== 0) {
                    $u = home_url('/' . ltrim($u, '/'));
                }
                $urls[] = $u;
            }
            if ($slug !== '' && $urls) {
                $rules[] = ['type' => $type, 'slug' => $slug, 'urls' => $urls];
            }
        }

        return $rules;
    }

    /**
     * URLs, die wegen dieses Produkts mitgepurged werden muessen.
     *
     * @return string[]|null  null = keine Regel greift (Fallback auf Sammellauf)
     */
    private function urls_for_product($product_id) {
        $rules = $this->parse_purge_rules();
        if (!$rules) {
            return null;
        }

        // Marken-Slugs des Produkts
        $slugs = ['brand' => [], 'cat' => []];
        foreach (wp_get_post_terms($product_id, 'pwb-brand', ['fields' => 'slugs']) as $s) {
            $slugs['brand'][$s] = true;
        }
        // Kategorien inkl. Eltern — eine Seite kann auf die Oberkategorie gefiltert sein
        // (z.B. Seite auf „Ersatzteile", Produkt in „Ecm Ersatzteile").
        foreach (wp_get_post_terms($product_id, 'product_cat', ['fields' => 'all']) as $term) {
            if (is_wp_error($term) || !is_object($term)) {
                continue;
            }
            $slugs['cat'][$term->slug] = true;
            foreach (get_ancestors($term->term_id, 'product_cat') as $anc_id) {
                $anc = get_term($anc_id, 'product_cat');
                if ($anc && !is_wp_error($anc)) {
                    $slugs['cat'][$anc->slug] = true;
                }
            }
        }

        // Sale-Auslöser: greift, wenn das Produkt AKTUELL einen Streichpreis hat
        // ODER wenn sich _sale_price gerade geaendert hat. Der zweite Fall ist der
        // wichtige: Wird ein Sale beendet, ist _sale_price leer — die Sale-Seiten
        // muessen aber gepurged werden, sonst bewerben sie das Produkt weiter.
        $is_sale = (string) get_post_meta($product_id, '_sale_price', true) !== ''
            || isset($this->sale_touched[$product_id]);

        $urls    = [];
        $matched = false;
        foreach ($rules as $rule) {
            $hit = false;
            if ($rule['type'] === 'sale') {
                $hit = $is_sale;
            } elseif (isset($slugs[$rule['type']][$rule['slug']])) {
                $hit = true;
            }
            if ($hit) {
                $matched = true;
                foreach ($rule['urls'] as $u) {
                    $urls[$u] = true;
                }
            }
        }

        return $matched ? array_keys($urls) : null;
    }

    /** Deutsche URL + alle Weglot-Sprachvarianten. */
    private function url_variants($url) {
        $variants = [$url];
        $home = home_url('/');
        foreach (self::LANG_PREFIXES as $lang) {
            $variants[] = str_replace($home, $home . $lang . '/', $url);
        }
        return $variants;
    }

    /**
     * Purge EINER URL — beide Page-Cache-Schichten.
     *
     * ⚠ NIEMALS die HTTP-Methode PURGE benutzen: Cloudways' VCL macht daraus
     * ban("req.http.host ~ ...") und loescht den Cache der GANZEN Domain
     * (/etc/varnish/recv/default.vcl). Nur URLPURGE bannt host+url exakt
     * (/etc/varnish/recv/woocommerce.vcl:16-24).
     *
     * Breeze-only reicht nicht: gemessen 17.07. liefert Varnish danach weiter
     * HIT mit altem Inhalt aus. Erst Breeze + Varnish ergibt einen echten Render.
     */
    public function purge_url($url) {
        if (empty($url)) {
            return false;
        }

        if (function_exists('breeze_varnish_purge_cache')) {
            // Loescht die Breeze-Datei UND schickt URLPURGE an Varnish.
            breeze_varnish_purge_cache($url, true);
            return true;
        }

        // Fallback, falls Breeze mal weg ist: URLPURGE direkt an Varnish.
        $parts = wp_parse_url($url);
        if (empty($parts['host'])) {
            return false;
        }
        $response = wp_remote_request('http://127.0.0.1' . ($parts['path'] ?? '/'), [
            'method'    => 'URLPURGE',
            'headers'   => ['Host' => $parts['host']],
            'timeout'   => 5,
            'sslverify' => false,
        ]);
        return !is_wp_error($response);
    }

    /**
     * Button in der Admin-Bar
     */
    public function add_purge_button($wp_admin_bar) {
        $settings = get_option($this->option_name, []);
        $min_role = $settings['min_role'] ?? 'manage_options';

        if (!current_user_can($min_role)) {
            return;
        }

        $purge_url = wp_nonce_url(
            admin_url('?cw_purge_cache=1'),
            'cw_purge_cache_nonce',
            'cw_nonce'
        );

        $wp_admin_bar->add_node([
            'id'    => 'cw-cache-purge',
            'title' => '<span class="ab-icon dashicons dashicons-update" style="margin-top:2px;"></span> Server-Cache leeren',
            'href'  => $purge_url,
            'meta'  => [
                'title' => 'Breeze + Cloudways Cache leeren und Pre-Warm starten',
                'class' => 'cw-purge-btn',
            ],
        ]);
    }

    public function add_button_styles() {
        if (!is_admin_bar_showing()) return;
        ?>
        <style>
            #wp-admin-bar-cw-cache-purge > a {
                background: #d63638 !important;
                color: #fff !important;
                transition: background 0.2s ease;
            }
            #wp-admin-bar-cw-cache-purge > a:hover {
                background: #b32d2e !important;
            }
            #wp-admin-bar-cw-cache-purge .ab-icon:before {
                color: #fff !important;
                top: 2px;
            }
        </style>
        <?php
    }

    /**
     * Cache-Purge-Trigger (Klick auf Admin-Bar-Button)
     */
    public function handle_purge_request() {
        if (!isset($_GET['cw_purge_cache']) || $_GET['cw_purge_cache'] !== '1') {
            return;
        }

        if (!isset($_GET['cw_nonce']) || !wp_verify_nonce($_GET['cw_nonce'], 'cw_purge_cache_nonce')) {
            wp_die('Sicherheitsüberprüfung fehlgeschlagen.');
        }

        $settings = get_option($this->option_name, []);
        $min_role = $settings['min_role'] ?? 'manage_options';

        if (!current_user_can($min_role)) {
            wp_die('Keine Berechtigung.');
        }

        $report = $this->purge_all();

        set_transient('cw_purge_notice', $report, 30);

        $redirect = remove_query_arg(['cw_purge_cache', 'cw_nonce']);
        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * Hauptlogik: Breeze leeren → Cloudways purgen → Pre-Warm planen
     */
    private function purge_all() {
        $report = [
            'breeze'   => null,
            'cw'       => null,
            'prewarm'  => null,
            'time'     => current_time('mysql'),
            'user'     => wp_get_current_user()->display_name ?: 'system',
        ];

        // 1. Breeze lokal leeren (Minify + Page-Cache)
        $report['breeze'] = $this->purge_breeze();

        // 2. Cloudways API: Application-Cache (Varnish) leeren
        $report['cw'] = $this->purge_cloudways();

        // 3. Pre-Warm planen (etwas verzögert, damit Varnish zuerst fertig ist)
        $settings = get_option($this->option_name, []);
        if (!empty($settings['prewarm_enabled'])) {
            $urls = $this->collect_prewarm_urls();
            if (!empty($urls)) {
                wp_schedule_single_event(time() + 10, self::CRON_HOOK, [array_values($urls)]);
                $report['prewarm'] = ['scheduled' => count($urls)];
            } else {
                $report['prewarm'] = ['scheduled' => 0, 'note' => 'Keine URLs zum Vorwärmen gefunden.'];
            }
        }

        // 4. Voll-Refresh im Hintergrund anstossen (Szenario „Banner auf alle Seiten"):
        // Nach einem Komplett-Purge ist ALLES kalt und jeder Kunde zahlt ~7 s pro
        // erster Seite. Das Menue-Prewarming oben deckt nur die Einstiegsseiten ab.
        if (!empty($settings['refresh_after_purge'])) {
            $report['refresh'] = $this->spawn_background_refresh();
        }

        update_option('cw_last_purge', $report);

        return $report;
    }

    /**
     * Startet `wp cw-cache refresh` losgeloest im Hintergrund.
     *
     * Warum exec statt wp-cron: spawn_cron() funktioniert auf Cloudways nicht
     * (DISABLE_WP_CRON + PHP-FPM killt lange Requests). flock verhindert, dass
     * mehrfaches Klicken mehrere Laeufe parallel startet.
     * Niedrigere Parallelitaet als nachts, weil das hier zur Tageszeit laufen kann.
     */
    private function spawn_background_refresh($concurrency = 4) {
        if (!function_exists('exec')) {
            return ['started' => false, 'note' => 'exec() nicht verfuegbar'];
        }

        $lock = WP_CONTENT_DIR . '/cache/cw-refresh.lock';
        $log  = WP_CONTENT_DIR . '/cache/cw-refresh.log';
        $wp   = '/usr/local/bin/wp';

        if (!file_exists($wp)) {
            return ['started' => false, 'note' => 'wp-cli nicht gefunden'];
        }

        $cmd = sprintf(
            'nohup flock -n %s %s --path=%s cw-cache refresh --concurrency=%d >> %s 2>&1 &',
            escapeshellarg($lock),
            escapeshellarg($wp),
            escapeshellarg(ABSPATH),
            (int) $concurrency,
            escapeshellarg($log)
        );

        exec($cmd);

        return ['started' => true, 'concurrency' => $concurrency];
    }

    /**
     * Breeze-Cache leeren (Minify + Page-Cache)
     */
    private function purge_breeze() {
        $done = [];

        if (function_exists('breeze_clear_all_cache')) {
            breeze_clear_all_cache();
            $done[] = 'breeze_clear_all_cache';
        }

        if (class_exists('Breeze_PurgeCache')) {
            if (method_exists('Breeze_PurgeCache', 'breeze_cache_flush')) {
                \Breeze_PurgeCache::breeze_cache_flush();
                $done[] = 'Breeze_PurgeCache::breeze_cache_flush';
            }
        }

        if (class_exists('Breeze_MinificationCache') && method_exists('Breeze_MinificationCache', 'clear_minification')) {
            \Breeze_MinificationCache::clear_minification();
            $done[] = 'Breeze_MinificationCache::clear_minification';
        }

        // Object-Cache (Redis) für Konsistenz auch leeren
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
            $done[] = 'wp_cache_flush';
        }

        return [
            'success' => !empty($done),
            'methods' => $done,
        ];
    }

    /**
     * Cloudways API v2: Application-Cache (Varnish) leeren
     */
    private function purge_cloudways() {
        $settings = get_option($this->option_name, []);

        $email     = $settings['email'] ?? '';
        $api_key   = $settings['api_key'] ?? '';
        $server_id = $settings['server_id'] ?? '';
        $app_id    = $settings['app_id'] ?? '';

        if (empty($email) || empty($api_key) || empty($server_id) || empty($app_id)) {
            return [
                'success' => false,
                'message' => 'API-Einstellungen unvollständig.',
            ];
        }

        $token_response = wp_remote_post($this->api_base . '/oauth/access_token', [
            'body'    => ['email' => $email, 'api_key' => $api_key],
            'timeout' => 15,
        ]);

        if (is_wp_error($token_response)) {
            return ['success' => false, 'message' => 'Verbindung fehlgeschlagen: ' . $token_response->get_error_message()];
        }

        $token_body = json_decode(wp_remote_retrieve_body($token_response), true);

        if (empty($token_body['access_token'])) {
            $msg = $token_body['error_description'] ?? $token_body['message'] ?? 'Authentifizierung fehlgeschlagen.';
            return ['success' => false, 'message' => $msg];
        }

        $purge_response = wp_remote_post($this->api_base . '/app/cache/purge', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token_body['access_token'],
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ],
            'body'    => [
                'server_id' => intval($server_id),
                'app_id'    => intval($app_id),
            ],
            'timeout' => 30,
        ]);

        if (is_wp_error($purge_response)) {
            return ['success' => false, 'message' => 'Purge fehlgeschlagen: ' . $purge_response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($purge_response);
        $body = json_decode(wp_remote_retrieve_body($purge_response), true);

        if ($code >= 200 && $code < 300) {
            return ['success' => true, 'message' => 'Cloudways Cache geleert.'];
        }

        $msg = $body['message'] ?? $body['error_description'] ?? 'HTTP ' . $code;
        return ['success' => false, 'message' => $msg];
    }

    /**
     * Pre-Warm-URLs sammeln: konfiguriertes Menü + Startseite + Extras minus Excludes
     *
     * @return string[]
     */
    public function collect_prewarm_urls() {
        $settings = get_option($this->option_name, []);

        $location = $settings['prewarm_menu_location'] ?? 'primary';
        $extra    = $this->parse_url_lines($settings['prewarm_extra_urls'] ?? '');
        $exclude  = $this->parse_url_lines($settings['prewarm_exclude'] ?? "/shop/\n#");

        $urls = [home_url('/')];

        // Menü-URLs ziehen
        $locations = get_nav_menu_locations();
        if (!empty($locations[$location])) {
            $menu_items = wp_get_nav_menu_items($locations[$location]);
            if ($menu_items) {
                foreach ($menu_items as $item) {
                    $url = $item->url ?? '';
                    if ($url) {
                        $urls[] = $url;
                    }
                }
            }
        }

        $urls = array_merge($urls, $extra);

        // Filter: nur eigene Domain, kein # / leer, keine Excludes, dedupliziert
        $home_host = wp_parse_url(home_url('/'), PHP_URL_HOST);
        $clean = [];

        foreach ($urls as $url) {
            $url = trim($url);
            if ($url === '' || $url === '#') continue;

            $host = wp_parse_url($url, PHP_URL_HOST);
            if ($host && $host !== $home_host) continue; // externe Links überspringen

            // Excludes (Substring-Match)
            $excluded = false;
            foreach ($exclude as $pattern) {
                $pattern = trim($pattern);
                if ($pattern !== '' && strpos($url, $pattern) !== false) {
                    $excluded = true;
                    break;
                }
            }
            if ($excluded) continue;

            $clean[$url] = true;
        }

        return array_keys($clean);
    }

    private function parse_url_lines($text) {
        if (!is_string($text) || $text === '') return [];
        $lines = preg_split('/\r\n|\r|\n/', $text);
        return array_values(array_filter(array_map('trim', $lines), function ($l) {
            return $l !== '' && strpos($l, '#') !== 0; // Kommentar-Zeilen erlaubt mit führendem #
        }));
    }

    /**
     * Cron-Callback: Pre-Warm in Batches mit non-blocking HTTP-Requests
     */
    public function run_prewarm($urls) {
        if (!is_array($urls) || empty($urls)) return;

        $settings   = get_option($this->option_name, []);
        $batch_size = max(1, intval($settings['prewarm_batch_size'] ?? 5));
        $batch_pause = 200000; // 0.2s zwischen Batches in Mikrosekunden

        $count    = 0;
        $started  = microtime(true);

        foreach (array_chunk($urls, $batch_size) as $batch) {
            foreach ($batch as $url) {
                wp_remote_get($url, [
                    'blocking'   => false,
                    'timeout'    => 1,
                    'sslverify'  => false,
                    'user-agent' => 'CW-Cache-Prewarmer/1.2',
                    'headers'    => ['X-CW-Prewarm' => '1'],
                ]);
                $count++;
            }
            usleep($batch_pause);
        }

        update_option('cw_last_prewarm', [
            'time'     => current_time('mysql'),
            'count'    => $count,
            'duration' => round(microtime(true) - $started, 2),
        ]);
    }

    // =====================================================================
    // WP-CLI: naechtlicher Refresh (v1.3.0)
    // =====================================================================

    /**
     * `wp cw-cache purge-url <url>` — einzelne URL purgen (Debug/manuell).
     */
    public function cli_purge_url($args) {
        if (empty($args[0])) {
            \WP_CLI::error('URL fehlt. Beispiel: wp cw-cache purge-url https://espressoperfetto.de/produkt/xy/');
        }
        $this->purge_url($args[0]);
        \WP_CLI::success('Gepurged: ' . $args[0]);
    }

    /**
     * `wp cw-cache suggest-rules` — schlaegt Purge-Regeln aus den Seiteninhalten vor.
     *
     * Liest, wie die Seiten ihre Produkte listen, und leitet daraus Regelzeilen ab.
     * Ausgabe ist ein VORSCHLAG zum Pruefen und Einfuegen — bewusst nicht automatisch
     * angewendet: die Erkennung deckt drei Mechanismen ab ([ep_products],
     * [ep_filtered_products], Uncode-loop) und kann Sonderfaelle uebersehen. Ein
     * Mensch, der die Seite kennt, korrigiert das; ein stiller Parser nicht.
     *
     * ⚠ [vc_raw_html] ist base64 UND urlencoded — beides muss dekodiert werden,
     * sonst findet man die Shortcodes nicht (Fehler bei der Erstanalyse 17.07.).
     */
    public function cli_suggest_rules() {
        $by_slug = $this->build_suggested_rules();

        if (!$by_slug) {
            \WP_CLI::warning('Keine Regeln ableitbar.');
            return;
        }

        \WP_CLI::log('# Vorschlag — pruefen und in Einstellungen → Cloudways Cache einfuegen:');
        \WP_CLI::log('');
        foreach ($by_slug as $trigger => $paths) {
            \WP_CLI::log($trigger . ' => ' . implode(', ', $paths));
        }
        \WP_CLI::log('');
        \WP_CLI::log(sprintf('# %d Regeln vorgeschlagen. Nicht erkannte Seiten faengt der Sammellauf ab.', count($by_slug)));
    }

    /**
     * Leitet Regelvorschlaege aus den Seiteninhalten ab.
     *
     * Gemeinsame Quelle fuer CLI und Admin-UI.
     *
     * @return array  'marke:slug'|'kategorie:slug' => string[] (Pfade)
     */
    public function build_suggested_rules() {
        $pages = get_posts([
            'post_type'      => 'page',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
        ]);

        // Test-/Copy-Altlasten, die zwar publish sind, aber keine echten Zielseiten.
        $junk = apply_filters('cw_cache_suggest_junk', [
            '/startseite_neu/', '/startseite_neu-copy/', '/startseite_neu-copy-copy/',
            '/maschinen-filter-test/', '/test-ajaaxo/', '/sale-filter-test/',
        ]);

        $by_slug = []; // slug => ['brand'|'cat' => true], url => true

        foreach ($pages as $page) {
            $content = $page->post_content;
            if (preg_match_all('/\[vc_raw_html\](.*?)\[\/vc_raw_html\]/s', $content, $m)) {
                foreach ($m[1] as $b64) {
                    $content .= ' ' . urldecode(base64_decode(urldecode($b64)));
                }
            }

            $url = get_permalink($page->ID);
            if (!$url) {
                continue;
            }

            // [ep_products ...] und [ep_filtered_products ...]
            if (preg_match_all('/\[ep_(?:filtered_)?products([^\]]*)\]/', $content, $sc)) {
                foreach ($sc[1] as $attrs) {
                    // sale="true" -> Seite lebt vom Streichpreis, nicht von einer Kategorie
                    if (preg_match('/\bsale\s*=\s*"(true|1)"/i', $attrs)) {
                        $by_slug['sale'][$url] = true;
                    }
                    if (preg_match('/\bbrand\s*=\s*"([^"]+)"/', $attrs, $b)) {
                        foreach (explode(',', $b[1]) as $name) {
                            $term = get_term_by('name', trim($name), 'pwb-brand');
                            if ($term) {
                                $by_slug['marke:' . $term->slug][$url] = true;
                            }
                        }
                    }
                    if (preg_match('/\bcategory\s*=\s*"([^"]+)"/', $attrs, $c)) {
                        foreach (explode(',', $c[1]) as $slug) {
                            $by_slug['kategorie:' . sanitize_title(trim($slug))][$url] = true;
                        }
                    }
                }
            }

            // Uncode: loop="...post_type:product|tax_query:IDs..."
            if (preg_match_all('/loop="([^"]*post_type:product[^"]*)"/', $content, $lp)) {
                foreach ($lp[1] as $loop) {
                    if (!preg_match('/tax_query:([0-9,]+)/', $loop, $tq)) {
                        continue;
                    }
                    foreach (explode(',', $tq[1]) as $term_id) {
                        $term = get_term((int) $term_id);
                        if (!$term || is_wp_error($term)) {
                            continue;
                        }
                        if ($term->taxonomy === 'pwb-brand') {
                            $by_slug['marke:' . $term->slug][$url] = true;
                        } elseif ($term->taxonomy === 'product_cat') {
                            $by_slug['kategorie:' . $term->slug][$url] = true;
                        }
                        // andere Taxonomien (z.B. pa_accessoires, page_category) bewusst
                        // ignoriert — daraus laesst sich keine saubere Regel ableiten.
                    }
                }
            }
        }

        ksort($by_slug);

        $result = [];
        foreach ($by_slug as $trigger => $urls) {
            $paths = [];
            foreach (array_keys($urls) as $u) {
                $path = wp_parse_url($u, PHP_URL_PATH);
                if ($path && !in_array($path, $junk, true)) {
                    $paths[] = $path;
                }
            }
            if ($paths) {
                $result[$trigger] = $paths;
            }
        }

        return $result;
    }

    /**
     * `wp cw-cache refresh-listings` — frischt die Uebersichtsseiten auf, aber nur
     * wenn seit dem letzten Lauf ein Produkt geaendert wurde.
     *
     * Hintergrund: Uebersichtsseiten wie /espressomaschine/alle-maschinen/ zeigen
     * „Auf Lager"/„Ausverkauft" und Preise, haben aber eigene URLs — der Auto-Purge
     * des Produkts erwischt sie nicht. Ohne das stand ein wieder lieferbares Produkt
     * dort bis zum Nacht-Refresh auf „Ausverkauft" = verlorene Verkaeufe.
     *
     * Gedrosselt statt sofort, weil JTL 45-131 Produkte pro Werktag aendert: bei
     * Sofort-Purge waeren die Uebersichten dauerhaft kalt.
     *
     * ## OPTIONS
     * [--force]            : Auch laufen, wenn nichts als geaendert markiert ist.
     * [--concurrency=<n>]  : Parallele Requests (Default 6).
     */
    public function cli_refresh_listings($args, $assoc = []) {
        $dirty = get_option('cw_listings_dirty', 0);
        if (!$dirty && !isset($assoc['force'])) {
            \WP_CLI::log('Nichts geaendert — nichts zu tun.');
            return;
        }

        $concurrency = max(1, (int) ($assoc['concurrency'] ?? 6));
        $urls = $this->collect_prewarm_urls();
        if (empty($urls)) {
            \WP_CLI::warning('Keine Uebersichts-URLs gefunden (Menue-Einstellung pruefen).');
            return;
        }

        // Flag VOR der Arbeit loeschen: Aenderungen waehrend des Laufs sollen
        // den naechsten Lauf ausloesen, nicht verschluckt werden.
        delete_option('cw_listings_dirty');

        $started = microtime(true);
        $ok = 0;
        foreach (array_chunk($urls, $concurrency) as $chunk) {
            foreach ($chunk as $url) {
                $this->purge_url($url);
            }
            $ok += $this->fetch_parallel($chunk);
        }

        $duration = round(microtime(true) - $started, 1);
        update_option('cw_last_listings_refresh', [
            'time'     => current_time('mysql'),
            'urls'     => count($urls),
            'ok'       => $ok,
            'duration' => $duration,
        ], false);

        \WP_CLI::success(sprintf('%d/%d Uebersichtsseiten aufgefrischt in %s s.', $ok, count($urls), $duration));
    }

    /**
     * `wp cw-cache refresh` — purged + waermt den kompletten deutschen Bestand.
     *
     * Bewusst als WP-CLI und nicht als wp-cron: Auf Cloudways funktioniert
     * spawn_cron() nicht (DISABLE_WP_CRON + PHP-FPM killt lange Requests).
     * Aufruf per Server-Crontab nachts.
     *
     * Pro URL wird erst gepurged, dann SOFORT neu geholt — das Kaltfenster ist
     * damit sekundenkurz statt „ganze Nacht kalt". Ein Komplett-Purge vorab
     * waere gefaehrlich: 30 Tage warmer Cache weg, und alles was bis morgens
     * nicht durch ist, trifft echte Kunden mit ~7-s-Renders.
     *
     * ## OPTIONS
     * [--concurrency=<n>]  : Parallele Requests (Default 8).
     * [--limit=<n>]        : Nur die ersten n URLs (Test).
     * [--types=<liste>]    : Post-Types, Default "page,product".
     * [--dry-run]          : Nur zeigen, was passieren wuerde.
     */
    public function cli_refresh($args, $assoc = []) {
        $concurrency = max(1, (int) ($assoc['concurrency'] ?? 8));
        $limit       = (int) ($assoc['limit'] ?? 0);
        $types       = array_filter(array_map('trim', explode(',', $assoc['types'] ?? 'page,product')));
        $dry         = isset($assoc['dry-run']);

        $urls = $this->collect_refresh_urls($types);
        if ($limit > 0) {
            $urls = array_slice($urls, 0, $limit);
        }

        $total = count($urls);
        if (!$total) {
            \WP_CLI::warning('Keine URLs gefunden.');
            return;
        }

        \WP_CLI::log(sprintf('%d URLs, Parallelitaet %d%s', $total, $concurrency, $dry ? ' (DRY RUN)' : ''));
        if ($dry) {
            foreach (array_slice($urls, 0, 10) as $u) {
                \WP_CLI::log('  ' . $u);
            }
            \WP_CLI::success('Dry Run — nichts veraendert.');
            return;
        }

        $started  = microtime(true);
        $progress = \WP_CLI\Utils\make_progress_bar('Refresh', $total);
        $ok = 0;

        foreach (array_chunk($urls, $concurrency) as $chunk) {
            foreach ($chunk as $url) {
                $this->purge_url($url);
            }
            $ok += $this->fetch_parallel($chunk);
            foreach ($chunk as $_) {
                $progress->tick();
            }
        }
        $progress->finish();

        $duration = round(microtime(true) - $started, 1);
        update_option('cw_last_refresh', [
            'time'     => current_time('mysql'),
            'urls'     => $total,
            'ok'       => $ok,
            'duration' => $duration,
        ], false);

        \WP_CLI::success(sprintf('%d/%d URLs neu gewaermt in %s s.', $ok, $total, $duration));
    }

    /** Alle veroeffentlichten URLs der angegebenen Types (deutsch) + Startseite. */
    private function collect_refresh_urls($types) {
        $urls = [home_url('/')];

        // WooCommerce-Funktionsseiten nie waermen: Varnish pipet sie ohnehin
        // durch (recv/woocommerce.vcl), sie sind personalisiert, und ein
        // Prewarm-Request wuerde nur eine sinnlose Session erzeugen.
        // Ueber Page-IDs statt Slugs — funktioniert sprachunabhaengig.
        $skip_ids = [];
        if (function_exists('wc_get_page_id')) {
            foreach (['cart', 'checkout', 'myaccount'] as $wc_page) {
                $id = wc_get_page_id($wc_page);
                if ($id > 0) {
                    $skip_ids[$id] = true;
                }
            }
        }

        // Zusaetzlich die Exclude-Liste aus den Einstellungen (Substring-Match).
        // Faengt Altlasten, die WC nicht mehr kennt — z.B. die verwaiste Seite
        // /kasse/ (ID 85129), die nicht die echte Checkout-Seite ist
        // (das ist /kasse-checkout/, ID 85153).
        $settings = get_option($this->option_name, []);
        $exclude  = $this->parse_url_lines($settings['refresh_exclude'] ?? "/kasse/\n/danke/\n/bestellbestaetigung/");

        $ids = get_posts([
            'post_type'              => $types,
            'post_status'            => 'publish',
            'posts_per_page'         => -1,
            'fields'                 => 'ids',
            'orderby'                => 'ID',
            'order'                  => 'ASC',
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ]);

        foreach ($ids as $id) {
            if (isset($skip_ids[$id])) {
                continue;
            }
            $link = get_permalink($id);
            if (!$link) {
                continue;
            }
            foreach ($exclude as $pattern) {
                if ($pattern !== '' && strpos($link, $pattern) !== false) {
                    continue 2;
                }
            }
            $urls[$link] = $link;
        }

        return array_values(array_unique(array_values($urls)));
    }

    /**
     * Parallel holen via curl_multi.
     *
     * Bewusst BLOCKIEREND (anders als run_prewarm mit blocking=false): Nur so
     * wissen wir, ob die Seite wirklich gerendert und gecacht wurde.
     */
    private function fetch_parallel($urls) {
        if (!function_exists('curl_multi_init')) {
            foreach ($urls as $url) {
                wp_remote_get($url, ['timeout' => 30, 'sslverify' => false]);
            }
            return count($urls);
        }

        $mh      = curl_multi_init();
        $handles = [];

        foreach ($urls as $url) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_NOBODY         => false,
                CURLOPT_TIMEOUT        => 60,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT      => 'CW-Cache-Prewarmer/1.3',
                CURLOPT_HTTPHEADER     => ['X-CW-Prewarm: 1'],
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[] = $ch;
        }

        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running && $status === CURLM_OK);

        $ok = 0;
        foreach ($handles as $ch) {
            if (curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200) {
                $ok++;
            }
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);

        return $ok;
    }

    /**
     * Visueller Regel-Editor.
     *
     * Speicherformat bleibt die Textzeile („marke:ecm => /a/, /b/") — der Editor
     * liest sie ein und schreibt sie beim Speichern in ein verstecktes Textarea
     * zurueck. Backend/CLI bleiben damit unveraendert, und wer will, kann die
     * Rohansicht weiter nutzen.
     */
    private function render_rules_editor($raw) {
        $brands = get_terms(['taxonomy' => 'pwb-brand', 'hide_empty' => true, 'orderby' => 'count', 'order' => 'DESC']);
        $cats   = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => true, 'orderby' => 'count', 'order' => 'DESC']);

        $terms = ['brand' => [], 'cat' => []];
        if (!is_wp_error($brands)) {
            foreach ($brands as $t) {
                $terms['brand'][] = ['slug' => $t->slug, 'name' => $t->name, 'count' => (int) $t->count];
            }
        }
        if (!is_wp_error($cats)) {
            foreach ($cats as $t) {
                $terms['cat'][] = ['slug' => $t->slug, 'name' => $t->name, 'count' => (int) $t->count];
            }
        }

        $pages = [];
        foreach (get_posts(['post_type' => 'page', 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC']) as $p) {
            $path = wp_parse_url(get_permalink($p->ID), PHP_URL_PATH);
            if ($path) {
                $pages[] = ['path' => $path, 'title' => $p->post_title];
            }
        }
        ?>
        <h2>Purge-Regeln: welche Seiten hängen an welchen Produkten?</h2>
        <p class="description" style="max-width:900px;margin-bottom:12px;">
            Ändert die JTL-Wawi ein Produkt einer Marke/Kategorie, werden <strong>genau die hier hinterlegten Seiten</strong>
            sofort gepurged und neu vorgewärmt.
            <strong>Sicherheitsnetz:</strong> Greift für ein geändertes Produkt keine Regel, läuft automatisch der
            Sammellauf über alle Menü-URLs (max. 15 Min Verzögerung) — <em>unvollständige Regeln sind also unkritisch,
            sie machen es nur präziser und billiger</em>.
            Kategorien wirken <strong>inklusive Unterkategorien</strong>: Eine ECM-Maschine
            (<code>ecm-maschinen-espressomaschinen</code>) löst deshalb auch <code>espressomaschinen</code> aus
            und purged die Übersicht mit.
        </p>

        <p>
            <button type="button" class="button" id="cw-add-rule">+ Regel hinzufügen</button>
            <button type="button" class="button" id="cw-suggest">Vorschläge aus Seiteninhalten laden</button>
            <button type="button" class="button-link" id="cw-toggle-raw" style="margin-left:10px;">Rohansicht ein/aus</button>
            <span id="cw-rule-count" style="margin-left:10px;color:#666;"></span>
        </p>

        <table class="widefat striped" id="cw-rules-table" style="max-width:1100px;">
            <thead>
                <tr>
                    <th style="width:110px;">Auslöser</th>
                    <th style="width:280px;">Marke / Kategorie</th>
                    <th>Diese Seiten purgen</th>
                    <th style="width:40px;"></th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>

        <textarea name="<?php echo $this->option_name; ?>[purge_rules]" id="cw-rules-raw" rows="10" class="large-text code" style="display:none;margin-top:10px;"><?php echo esc_textarea($raw); ?></textarea>

        <script>
        (function(){
            var TERMS = <?php echo wp_json_encode($terms); ?>;
            var PAGES = <?php echo wp_json_encode($pages); ?>;
            var AJAX  = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var NONCE = <?php echo wp_json_encode(wp_create_nonce('cw_suggest_rules')); ?>;

            var raw   = document.getElementById('cw-rules-raw');
            var tbody = document.querySelector('#cw-rules-table tbody');
            var count = document.getElementById('cw-rule-count');

            function parseRaw(text) {
                var rules = [];
                (text || '').split('\n').forEach(function(line){
                    line = line.trim();
                    if (!line || line.indexOf('=>') === -1) return;
                    var parts = line.split('=>');
                    var pages = parts.slice(1).join('=>').split(',').map(function(s){ return s.trim(); }).filter(Boolean);
                    var head  = parts[0].trim();
                    if (/^(sale|streichpreis|angebot)$/i.test(head)) {
                        rules.push({type:'sale', slug:'', pages:pages});
                        return;
                    }
                    var trig = head.split(':');
                    if (trig.length < 2) return;
                    rules.push({
                        type: /^(marke|brand)$/i.test(trig[0].trim()) ? 'brand' : 'cat',
                        slug: trig.slice(1).join(':').trim(),
                        pages: pages
                    });
                });
                return rules;
            }

            function serialize() {
                var lines = [];
                Array.prototype.forEach.call(tbody.querySelectorAll('tr'), function(tr){
                    var type = tr.querySelector('.cw-type').value;
                    var slug = tr.querySelector('.cw-slug').value;
                    var pages = Array.prototype.map.call(tr.querySelectorAll('.cw-chip'), function(c){ return c.dataset.path; });
                    if (!pages.length) return;
                    if (type === 'sale') {
                        lines.push('sale => ' + pages.join(', '));
                    } else if (slug) {
                        lines.push((type === 'brand' ? 'marke:' : 'kategorie:') + slug + ' => ' + pages.join(', '));
                    }
                });
                raw.value = lines.join('\n');
                count.textContent = lines.length + ' Regel(n) aktiv';
            }

            function chip(path) {
                var page = PAGES.find(function(p){ return p.path === path; });
                var s = document.createElement('span');
                s.className = 'cw-chip';
                s.dataset.path = path;
                s.style.cssText = 'display:inline-flex;align-items:center;gap:6px;background:#f0f0f1;border:1px solid #c3c4c7;border-radius:3px;padding:2px 6px;margin:2px 4px 2px 0;font-size:12px;';
                s.innerHTML = '<span title="' + (page ? page.title.replace(/"/g,'&quot;') : '') + '">' + path + '</span>';
                var x = document.createElement('a');
                x.href = '#'; x.textContent = '×';
                x.style.cssText = 'text-decoration:none;color:#b32d2e;font-weight:700;';
                x.onclick = function(e){ e.preventDefault(); s.remove(); serialize(); };
                s.appendChild(x);
                return s;
            }

            function fillSlugs(sel, type, current) {
                sel.innerHTML = '';
                (TERMS[type] || []).forEach(function(t){
                    var o = document.createElement('option');
                    o.value = t.slug;
                    o.textContent = t.name + ' (' + t.count + ')';
                    if (t.slug === current) o.selected = true;
                    sel.appendChild(o);
                });
                if (current && !(TERMS[type] || []).some(function(t){ return t.slug === current; })) {
                    var o = document.createElement('option');
                    o.value = current; o.textContent = current + ' (unbekannt)'; o.selected = true;
                    sel.appendChild(o);
                }
            }

            function addRow(rule) {
                rule = rule || {type:'brand', slug:'', pages:[]};
                var tr = document.createElement('tr');

                var td1 = document.createElement('td');
                var type = document.createElement('select');
                type.className = 'cw-type';
                type.innerHTML = '<option value="cat">Kategorie</option><option value="brand">Marke</option><option value="sale">Sale / Streichpreis</option>';
                type.value = rule.type;
                td1.appendChild(type);

                var td2 = document.createElement('td');
                var slug = document.createElement('select');
                slug.className = 'cw-slug';
                slug.style.maxWidth = '270px';
                var saleHint = document.createElement('em');
                saleHint.textContent = 'jedes Produkt mit Streichpreis';
                saleHint.style.color = '#666';
                td2.appendChild(slug); td2.appendChild(saleHint);

                function syncType() {
                    var isSale = type.value === 'sale';
                    slug.style.display = isSale ? 'none' : '';
                    saleHint.style.display = isSale ? '' : 'none';
                    if (!isSale) { fillSlugs(slug, type.value, ''); }
                }

                var td3 = document.createElement('td');
                var box = document.createElement('div');
                box.className = 'cw-chips';
                rule.pages.forEach(function(p){ box.appendChild(chip(p)); });
                var add = document.createElement('select');
                add.style.cssText = 'max-width:320px;margin-top:4px;';
                add.innerHTML = '<option value="">+ Seite hinzufügen …</option>' + PAGES.map(function(p){
                    return '<option value="' + p.path + '">' + p.title.replace(/</g,'&lt;') + ' — ' + p.path + '</option>';
                }).join('');
                add.onchange = function(){
                    if (!add.value) return;
                    if (!box.querySelector('[data-path="' + add.value + '"]')) { box.appendChild(chip(add.value)); }
                    add.value = ''; serialize();
                };
                td3.appendChild(box); td3.appendChild(add);

                var td4 = document.createElement('td');
                var del = document.createElement('a');
                del.href = '#'; del.textContent = '🗑'; del.title = 'Regel löschen';
                del.style.textDecoration = 'none';
                del.onclick = function(e){ e.preventDefault(); tr.remove(); serialize(); };
                td4.appendChild(del);

                type.onchange = function(){ syncType(); serialize(); };
                slug.onchange = serialize;

                // Initialzustand. Das fillSlugs() hier ist zwingend: syncType()
                // laeuft nur bei onchange, ohne diesen Aufruf blieben die
                // Dropdowns beim Laden leer.
                if (rule.type === 'sale') {
                    slug.style.display = 'none';
                } else {
                    saleHint.style.display = 'none';
                    fillSlugs(slug, rule.type, rule.slug);
                }

                tr.appendChild(td1); tr.appendChild(td2); tr.appendChild(td3); tr.appendChild(td4);
                tbody.appendChild(tr);
            }

            parseRaw(raw.value).forEach(addRow);
            serialize();

            document.getElementById('cw-add-rule').onclick = function(){ addRow(); serialize(); };
            document.getElementById('cw-toggle-raw').onclick = function(){
                raw.style.display = raw.style.display === 'none' ? 'block' : 'none';
            };
            document.getElementById('cw-suggest').onclick = function(){
                var btn = this;
                btn.disabled = true; btn.textContent = 'Lade …';
                var body = new URLSearchParams({action:'cw_suggest_rules', nonce:NONCE});
                fetch(AJAX, {method:'POST', credentials:'same-origin', body:body})
                    .then(function(r){ return r.json(); })
                    .then(function(res){
                        if (!res.success) { alert('Fehler: ' + res.data); return; }
                        var n = Object.keys(res.data).length;
                        if (!confirm(n + ' Vorschläge gefunden. Bestehende Regeln ersetzen?')) return;
                        tbody.innerHTML = '';
                        Object.keys(res.data).forEach(function(trig){
                            var p = trig.split(':');
                            addRow({
                                type: /^(marke|brand)$/i.test(p[0]) ? 'brand' : 'cat',
                                slug: p.slice(1).join(':'),
                                pages: res.data[trig]
                            });
                        });
                        serialize();
                    })
                    .catch(function(e){ alert('Fehler: ' + e); })
                    .finally(function(){ btn.disabled = false; btn.textContent = 'Vorschläge aus Seiteninhalten laden'; });
            };

            var form = raw.closest('form');
            if (form) form.addEventListener('submit', serialize);
        })();
        </script>
        <?php
    }

    /**
     * Admin-Notice nach Purge
     */
    public function show_admin_notices() {
        $report = get_transient('cw_purge_notice');
        if (!$report) return;

        delete_transient('cw_purge_notice');

        if (!is_array($report)) return;

        $breeze_ok = !empty($report['breeze']['success']);
        $cw_ok     = !empty($report['cw']['success']);
        $prewarm   = $report['prewarm'] ?? null;

        $class = ($breeze_ok && $cw_ok) ? 'notice-success' : 'notice-warning';

        echo '<div class="notice ' . $class . ' is-dismissible"><p>';
        echo '<strong>🚀 Cache-Purge ausgeführt:</strong><br>';

        echo '• <strong>Breeze:</strong> ' . ($breeze_ok ? '✅ geleert (' . esc_html(implode(', ', $report['breeze']['methods'])) . ')' : '⚠️ nichts gefunden — Plugin aktiv?');
        echo '<br>';

        echo '• <strong>Cloudways:</strong> ' . ($cw_ok
            ? '✅ ' . esc_html($report['cw']['message'])
            : '❌ ' . esc_html($report['cw']['message'] ?? 'unbekannter Fehler'));
        echo '<br>';

        if ($prewarm !== null) {
            echo '• <strong>Pre-Warm:</strong> ' . intval($prewarm['scheduled']) . ' URLs in ~10s';
            if (!empty($prewarm['note'])) {
                echo ' — ' . esc_html($prewarm['note']);
            }
        } else {
            echo '• <strong>Pre-Warm:</strong> deaktiviert';
        }

        echo '</p></div>';
    }

    /**
     * Settings-Seite
     */
    public function add_settings_page() {
        add_options_page(
            'Cloudways Cache Purge',
            'Cloudways Cache',
            'manage_options',
            'cw-cache-purge',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings() {
        register_setting($this->option_name, $this->option_name, [
            'sanitize_callback' => [$this, 'sanitize_settings'],
        ]);
    }

    public function sanitize_settings($input) {
        return [
            'email'                 => sanitize_email($input['email'] ?? ''),
            'api_key'               => sanitize_text_field($input['api_key'] ?? ''),
            'server_id'             => absint($input['server_id'] ?? 0),
            'app_id'                => absint($input['app_id'] ?? 0),
            'min_role'              => sanitize_text_field($input['min_role'] ?? 'manage_options'),
            'prewarm_enabled'       => !empty($input['prewarm_enabled']) ? 1 : 0,
            'prewarm_menu_location' => sanitize_key($input['prewarm_menu_location'] ?? 'primary'),
            'prewarm_extra_urls'    => sanitize_textarea_field($input['prewarm_extra_urls'] ?? ''),
            'prewarm_exclude'       => sanitize_textarea_field($input['prewarm_exclude'] ?? "/shop/\n#"),
            'prewarm_batch_size'    => max(1, min(20, intval($input['prewarm_batch_size'] ?? 5))),
            // v1.3.0
            'auto_purge_enabled'    => !empty($input['auto_purge_enabled']) ? 1 : 0,
            'refresh_after_purge'   => !empty($input['refresh_after_purge']) ? 1 : 0,
            'refresh_exclude'       => sanitize_textarea_field($input['refresh_exclude'] ?? "/kasse/\n/danke/\n/bestellbestaetigung/"),
            'purge_rules'           => sanitize_textarea_field($input['purge_rules'] ?? ''),
        ];
    }

    public function render_settings_page() {
        $s           = get_option($this->option_name, []);
        $last_purge  = get_option('cw_last_purge', []);
        $last_prewarm = get_option('cw_last_prewarm', []);
        $locations   = get_registered_nav_menus();
        $preview     = $this->collect_prewarm_urls();

        $get = function($k, $default = '') use ($s) {
            return $s[$k] ?? $default;
        };
        ?>
        <div class="wrap" style="max-width:820px;">
            <h1>🚀 Cloudways Cache Purge <small style="color:#666;">v1.2.0</small></h1>
            <p>Leert Breeze + Cloudways Varnish per Knopfdruck und wärmt die wichtigsten Seiten danach vor — damit Besucher keine 404-Fehler auf veralteten Asset-URLs sehen.</p>

            <?php if (!empty($last_purge['time'])): ?>
                <div style="background:#f0f6fc;border-left:4px solid #2271b1;padding:12px 16px;margin:16px 0;">
                    <strong>Letzter Purge:</strong> <?php echo esc_html($last_purge['time']); ?> von <strong><?php echo esc_html($last_purge['user'] ?? '–'); ?></strong>
                    <?php if (!empty($last_prewarm['time'])): ?>
                        <br><strong>Letzter Pre-Warm:</strong> <?php echo esc_html($last_prewarm['time']); ?> –
                        <?php echo intval($last_prewarm['count']); ?> URLs in <?php echo esc_html($last_prewarm['duration']); ?>s
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields($this->option_name); ?>

                <h2>Cloudways API v2 – Zugangsdaten</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label>Cloudways E-Mail</label></th>
                        <td><input type="email" name="<?php echo $this->option_name; ?>[email]" value="<?php echo esc_attr($get('email')); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label>API Key</label></th>
                        <td><input type="password" name="<?php echo $this->option_name; ?>[api_key]" value="<?php echo esc_attr($get('api_key')); ?>" class="regular-text">
                        <p class="description">Cloudways Dashboard → Profil → API</p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Server ID</label></th>
                        <td><input type="text" name="<?php echo $this->option_name; ?>[server_id]" value="<?php echo esc_attr($get('server_id')); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label>App ID</label></th>
                        <td><input type="text" name="<?php echo $this->option_name; ?>[app_id]" value="<?php echo esc_attr($get('app_id')); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Berechtigung</label></th>
                        <td>
                            <select name="<?php echo $this->option_name; ?>[min_role]">
                                <?php
                                $roles = [
                                    'manage_options'    => 'Nur Administratoren',
                                    'edit_others_posts' => 'Redakteure & höher',
                                    'publish_posts'     => 'Autoren & höher',
                                ];
                                $current = $get('min_role', 'manage_options');
                                foreach ($roles as $cap => $label) {
                                    echo '<option value="' . esc_attr($cap) . '" ' . selected($current, $cap, false) . '>' . esc_html($label) . '</option>';
                                }
                                ?>
                            </select>
                        </td>
                    </tr>
                </table>

                <?php $this->render_rules_editor($get('purge_rules', '')); ?>

                <h2>Automatischer Purge bei Änderungen</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label>Auto-Purge aktiviert</label></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo $this->option_name; ?>[auto_purge_enabled]" value="1" <?php checked(!empty($get('auto_purge_enabled', 1))); ?>>
                                Bei Produkt- und Seiten-Änderungen automatisch nur die betroffene URL purgen
                            </label>
                            <p class="description">
                                Fängt auch Preis- und Bestands-Updates aus der <strong>JTL-Wawi</strong> ab (die schreiben
                                direkt per <code>update_post_meta</code> und lösen sonst keinerlei Purge aus).
                                Gepurged werden Breeze <em>und</em> Varnish per <code>URLPURGE</code> — jeweils nur die eine
                                URL plus ihre Sprachvarianten, nicht der ganze Shop.
                                <br><strong>Ohne diese Option bleiben JTL-Preise bis zu 30 Tage alt</strong> (Varnish-TTL).
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Voll-Refresh nach manuellem Purge</label></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo $this->option_name; ?>[refresh_after_purge]" value="1" <?php checked(!empty($get('refresh_after_purge', 0))); ?>>
                                Nach dem großen Purge-Button alle Seiten im Hintergrund neu vorwärmen
                            </label>
                            <p class="description">
                                Für Fälle wie „Banner auf allen Seiten“: Nach einem Komplett-Purge ist der ganze Shop kalt
                                und jeder Kunde zahlt ~7 s pro erster Seite. Diese Option startet im Hintergrund
                                <code>wp cw-cache refresh</code> (~1.670 URLs, dauert ~1–2 h bei geringer Parallelität).
                                <br><strong>Achtung:</strong> erzeugt Last. Bei kleinen Änderungen unnötig — dort reicht das
                                Speichern der Seite selbst, das purged die URL bereits automatisch.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Vom Refresh ausschließen</label></th>
                        <td>
                            <textarea name="<?php echo $this->option_name; ?>[refresh_exclude]" rows="3" class="large-text code"><?php echo esc_textarea($get('refresh_exclude', "/kasse/\n/danke/\n/bestellbestaetigung/")); ?></textarea>
                            <p class="description">
                                Ein Muster pro Zeile (Teilstring-Match). Warenkorb, Kasse und Mein-Konto werden bereits
                                automatisch über die WooCommerce-Seiten-IDs ausgeschlossen — hier nur Altlasten eintragen,
                                die WooCommerce nicht mehr kennt (z.B. die verwaiste Seite <code>/kasse/</code>).
                            </p>
                        </td>
                    </tr>
                </table>

                <h2>Pre-Warm Konfiguration</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label>Pre-Warm aktiviert</label></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo $this->option_name; ?>[prewarm_enabled]" value="1" <?php checked(!empty($get('prewarm_enabled', 1))); ?>>
                                Nach jedem Cache-Purge die wichtigsten Seiten automatisch vorwärmen
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Menü als Quelle</label></th>
                        <td>
                            <select name="<?php echo $this->option_name; ?>[prewarm_menu_location]">
                                <?php
                                $current = $get('prewarm_menu_location', 'primary');
                                foreach ($locations as $loc => $label) {
                                    echo '<option value="' . esc_attr($loc) . '" ' . selected($current, $loc, false) . '>' . esc_html($label) . ' (' . esc_html($loc) . ')</option>';
                                }
                                ?>
                            </select>
                            <p class="description">Alle URLs aus diesem Menü werden vorgewärmt (alle Ebenen). Die Startseite kommt automatisch dazu.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Zusätzliche URLs</label></th>
                        <td>
                            <textarea name="<?php echo $this->option_name; ?>[prewarm_extra_urls]" rows="4" cols="60" class="large-text code" placeholder="https://espressoperfetto.de/wichtige-seite/"><?php echo esc_textarea($get('prewarm_extra_urls')); ?></textarea>
                            <p class="description">Eine URL pro Zeile. Zeilen mit führendem <code>#</code> werden ignoriert (Kommentare).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label>URLs ausschließen</label></th>
                        <td>
                            <textarea name="<?php echo $this->option_name; ?>[prewarm_exclude]" rows="4" cols="60" class="large-text code"><?php echo esc_textarea($get('prewarm_exclude', "/shop/\n#")); ?></textarea>
                            <p class="description">Substring-Patterns, eine pro Zeile. URL wird übersprungen, wenn das Pattern enthalten ist. Default: <code>/shop/</code> (leitet weiter) und <code>#</code> (Hide-Items).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Batch-Größe</label></th>
                        <td>
                            <input type="number" min="1" max="20" name="<?php echo $this->option_name; ?>[prewarm_batch_size]" value="<?php echo esc_attr($get('prewarm_batch_size', 5)); ?>" class="small-text">
                            <p class="description">Wie viele URLs parallel angefragt werden, dann 0.2s Pause. 5 ist konservativ.</p>
                        </td>
                    </tr>
                </table>

                <?php submit_button('Einstellungen speichern'); ?>
            </form>

            <hr>
            <h2>Vorschau Pre-Warm-URLs</h2>
            <p><?php echo count($preview); ?> URLs werden bei jedem Purge vorgewärmt:</p>
            <ol style="background:#f6f7f7;padding:12px 32px;border-radius:4px;max-height:400px;overflow:auto;">
                <?php foreach ($preview as $url): ?>
                    <li><a href="<?php echo esc_url($url); ?>" target="_blank"><?php echo esc_html($url); ?></a></li>
                <?php endforeach; ?>
            </ol>

            <hr>
            <h2>Test</h2>
            <p>
                <a href="<?php echo esc_url(wp_nonce_url(admin_url('?cw_purge_cache=1'), 'cw_purge_cache_nonce', 'cw_nonce')); ?>"
                   class="button button-primary" style="background:#d63638;border-color:#d63638;">
                    🗑️ Jetzt Cache leeren + Pre-Warm starten
                </a>
            </p>
        </div>
        <?php
    }
}

new Cloudways_Cache_Purge();
