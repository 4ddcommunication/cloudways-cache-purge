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

    public function __construct() {
        add_action('admin_bar_menu', [$this, 'add_purge_button'], 999);
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_init', [$this, 'handle_purge_request']);
        add_action('admin_notices', [$this, 'show_admin_notices']);
        add_action('wp_head', [$this, 'add_button_styles']);
        add_action('admin_head', [$this, 'add_button_styles']);
        add_action(self::CRON_HOOK, [$this, 'run_prewarm'], 10, 1);

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
        foreach ($ids as $id) {
            $link = get_permalink($id);
            if (!$link) {
                continue;
            }
            foreach ($this->url_variants($link) as $variant) {
                $urls[$variant] = true;
            }
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
