<?php
/**
 * Plugin Name: KEO User Search Map
 * Description: Public search widget – find the nearest available Helwacht businesses.
 *              Requires the Helwacht Availability plugin on the same installation.
 * Version:     1.5.0
 */

if (!defined('ABSPATH')) exit;

class KEO_User_Search_Map {

  const VERSION                = '1.5.0';
  const SUGGEST_RL_IP          = 300;
  const SUGGEST_RL_GLOBAL      = 3000;
  const SUGGEST_RL_GLOBAL_MONTHLY = 80000;
  const MAX_RESULTS            = 3;

  public function __construct() {
    add_action('rest_api_init', [$this, 'register_routes']);
    add_shortcode('helwacht_search', [$this, 'shortcode']);
    add_action('admin_menu', [$this, 'admin_menu']);
  }

  private function get_mapbox_token() {
    if (defined('HELWACHT_MAPBOX_TOKEN') && is_string(HELWACHT_MAPBOX_TOKEN) && trim(HELWACHT_MAPBOX_TOKEN) !== '') {
      return trim(HELWACHT_MAPBOX_TOKEN);
    }
    $env = getenv('HELWACHT_MAPBOX_TOKEN');
    if (is_string($env) && trim($env) !== '') return trim($env);
    $token = get_option('helwacht_mapbox_token', '');
    return is_string($token) ? trim($token) : '';
  }

  private function get_api_key() {
    if (defined('HELWACHT_API_KEY') && is_string(HELWACHT_API_KEY) && trim(HELWACHT_API_KEY) !== '') {
      return trim(HELWACHT_API_KEY);
    }
    $env = getenv('HELWACHT_API_KEY');
    if (is_string($env) && trim($env) !== '') return trim($env);
    $key = get_option('helwacht_api_key', '');
    return is_string($key) ? trim($key) : '';
  }

  public function register_routes() {
    register_rest_route('helwacht-search/v1', '/suggest', [
      'methods'             => 'GET',
      'callback'            => [$this, 'rest_suggest'],
      'permission_callback' => '__return_true',
      'args'                => ['q' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field']],
    ]);
    register_rest_route('helwacht-search/v1', '/nearby', [
      'methods'             => 'GET',
      'callback'            => [$this, 'rest_nearby'],
      'permission_callback' => '__return_true',
    ]);
  }

  public function rest_suggest(\WP_REST_Request $request) {
    $q = trim((string) $request->get_param('q'));
    if ($q === '' || mb_strlen($q) > 200) {
      return new \WP_Error('helwacht_search_invalid_query', 'Query must not be empty or exceed 200 characters.', ['status' => 400]);
    }
    $limit_error = $this->check_rate_limit('suggest', self::SUGGEST_RL_IP, self::SUGGEST_RL_GLOBAL);
    if (is_wp_error($limit_error)) return $limit_error;

    $token = $this->get_mapbox_token();
    if ($token === '') {
      return new \WP_Error('helwacht_search_no_token', 'Mapbox token is not configured.', ['status' => 500]);
    }
    $url = add_query_arg([
      'access_token' => $token,
      'autocomplete' => 'true',
      'limit'        => 5,
      'country'      => 'at',
      'language'     => 'de',
    ], 'https://api.mapbox.com/geocoding/v5/mapbox.places/' . rawurlencode($q) . '.json');

    $response = wp_remote_get($url, ['timeout' => 10]);
    if (is_wp_error($response)) {
      return new \WP_Error('helwacht_search_suggest_failed', 'Autocomplete request failed.', ['status' => 502]);
    }
    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($data) || empty($data['features'])) return new \WP_REST_Response([], 200);

    $suggestions = [];
    foreach ($data['features'] as $feature) {
      if (empty($feature['center']) || count($feature['center']) < 2) continue;
      $suggestions[] = [
        'label' => $feature['place_name'] ?? $feature['text'] ?? '',
        'lng'   => (float) $feature['center'][0],
        'lat'   => (float) $feature['center'][1],
      ];
    }
    return new \WP_REST_Response($suggestions, 200);
  }

  public function rest_nearby(\WP_REST_Request $request) {
    $lat = $request->get_param('lat');
    $lng = $request->get_param('lng');
    $q   = trim((string) $request->get_param('q'));
    $has_coords = is_numeric($lat) && is_numeric($lng);
    $has_query  = $q !== '' && mb_strlen($q) <= 200;

    if (!$has_coords && !$has_query) {
      return new \WP_Error('helwacht_search_missing_params', 'Provide either lat+lng or q.', ['status' => 400]);
    }
    $api_key = $this->get_api_key();
    if ($api_key === '') {
      return new \WP_Error('helwacht_search_no_key', 'Helwacht API key is not configured.', ['status' => 500]);
    }
    $api_request = new \WP_REST_Request('GET', '/helwacht/v1/availability');
    $api_request->set_param('key', $api_key);
    if ($has_coords) {
      $api_request->set_param('lat', (float) $lat);
      $api_request->set_param('lng', (float) $lng);
    } else {
      $api_request->set_param('q', $q);
    }
    $api_response = rest_do_request($api_request);
    $data         = $api_response->get_data();
    if ($api_response->get_status() >= 400) return new \WP_REST_Response($data, $api_response->get_status());
    if (isset($data['data']) && is_array($data['data'])) {
      $data['data']  = array_slice($data['data'], 0, self::MAX_RESULTS);
      $data['count'] = count($data['data']);
    }
    return new \WP_REST_Response($data, 200);
  }

  private function get_client_ip() {
    if (defined('HELWACHT_TRUST_PROXY') && HELWACHT_TRUST_PROXY && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
      $parts     = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']);
      $candidate = trim($parts[0]);
      if (filter_var($candidate, FILTER_VALIDATE_IP)) return $candidate;
    }
    $remote = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : 'unknown';
  }

  private function check_rate_limit($prefix, $ip_limit, $global_limit) {
    $day         = current_time('Ymd');
    $month       = current_time('Ym');
    $ip          = $this->get_client_ip();
    $ip_key      = 'hwsearch_rl_' . $prefix . '_ip_' . md5($ip) . '_' . $day;
    $global_key  = 'hwsearch_rl_' . $prefix . '_global_' . $day;
    $monthly_key = 'hwsearch_rl_' . $prefix . '_global_monthly_' . $month;

    $ip_count      = (int) get_transient($ip_key);
    $global_count  = (int) get_transient($global_key);
    $monthly_count = (int) get_transient($monthly_key);

    if ($ip_count >= $ip_limit) {
      return new \WP_Error('helwacht_search_rate_limited', 'Reached daily limit. Try again later.', ['status' => 429]);
    }
    if ($global_count >= $global_limit) {
      return new \WP_Error('helwacht_search_rate_limited_global', 'Service temporarily unavailable. Please try again later.', ['status' => 429]);
    }
    if ($monthly_count >= self::SUGGEST_RL_GLOBAL_MONTHLY) {
      return new \WP_Error('helwacht_search_rate_limited_monthly', 'Service temporarily unavailable. Please try again later.', ['status' => 429]);
    }
    set_transient($ip_key, $ip_count + 1, DAY_IN_SECONDS);
    set_transient($global_key, $global_count + 1, DAY_IN_SECONDS);
    set_transient($monthly_key, $monthly_count + 1, 31 * DAY_IN_SECONDS);
    return null;
  }

  public function admin_menu() {
    add_options_page('Helwacht Search', 'Helwacht Search', 'manage_options', 'helwacht-search', [$this, 'admin_page']);
  }

  public function admin_page() {
    $token = $this->get_mapbox_token();
    $key   = $this->get_api_key();
    $ok    = '<span style="color:green;">✔ konfiguriert</span>';
    $nok   = '<span style="color:red;">✘ fehlt – bitte im Helwacht Availability Plugin eintragen</span>';
    ?>
    <div class="wrap">
      <h1>Helwacht Search</h1>
      <p>Dieses Plugin liest die Einstellungen des <strong>Helwacht Availability</strong> Plugins.</p>
      <table class="widefat" style="max-width:600px;">
        <tbody>
          <tr><th style="width:200px;">API-Key</th><td><?php echo $key !== '' ? $ok : $nok; ?></td></tr>
          <tr><th>Mapbox Token</th><td><?php echo $token !== '' ? $ok : $nok; ?></td></tr>
          <tr><th>Shortcode</th><td><code>[helwacht_search]</code></td></tr>
          <tr><th>Ergebnisse pro Suche</th><td>Die <?php echo self::MAX_RESULTS; ?> nächsten verfügbaren Betriebe</td></tr>
          <tr><th>Autocomplete-Limit</th><td><?php echo self::SUGGEST_RL_IP; ?> pro IP / <?php echo self::SUGGEST_RL_GLOBAL; ?> global pro Tag</td></tr>
          <tr><th>Karte</th><td>MapLibre GL JS + OpenFreeMap (token-frei)</td></tr>
        </tbody>
      </table>
    </div>
    <?php
  }

  public function shortcode() {
    // MapLibre GL JS + OpenFreeMap vector tiles (no token required in the browser)
    if (!wp_style_is('maplibre-gl', 'enqueued')) {
      wp_enqueue_style('maplibre-gl', 'https://unpkg.com/maplibre-gl/dist/maplibre-gl.css', [], '1.5.0');
      wp_enqueue_script('maplibre-gl', 'https://unpkg.com/maplibre-gl/dist/maplibre-gl.js', [], '1.5.0', true);
    }

    $uid         = 'hws-' . wp_unique_id();
    $map_id      = $uid . '-map';
    $suggest_url = esc_url(rest_url('helwacht-search/v1/suggest'));
    $nearby_url  = esc_url(rest_url('helwacht-search/v1/nearby'));

    ob_start();
    ?>
    <div id="<?php echo esc_attr($uid); ?>" class="hws-widget">

      <div class="hws-input-row">
        <div class="hws-input-wrap">
          <input type="text" class="hws-input" placeholder="Adresse eingeben …"
                 autocomplete="off" aria-label="Adresse eingeben" aria-autocomplete="list">
          <ul class="hws-suggestions" role="listbox" aria-label="Adressvorschläge" hidden></ul>
        </div>
        <button class="hws-search-btn" title="Adresse suchen" aria-label="Adresse suchen">
          <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"
               fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
          </svg>
        </button>
        <button class="hws-gps-btn" title="Aktuellen Standort verwenden" aria-label="Aktuellen Standort verwenden">
          <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"
               fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/>
          </svg>
        </button>
      </div>

      <p class="hws-status" role="status" hidden></p>

      <div class="hws-content">
        <div id="<?php echo esc_attr($map_id); ?>" class="hws-map"></div>
        <div class="hws-results"></div>
      </div>

    </div>

    <style>
    .hws-widget { font-family: inherit; width: 100%; }

    .hws-input-row { display: flex; gap: 8px; align-items: stretch; }
    .hws-input-wrap { position: relative; flex: 1; }
    .hws-input {
      width: 100%; box-sizing: border-box; padding: 10px 14px;
      border: 1px solid #ccc; border-radius: 6px; font-size: 16px; font-family: inherit;
      transition: border-color .2s, box-shadow .2s;
    }
    .hws-input:focus { outline: none; border-color: var(--global-palette1); box-shadow: 0 0 0 2px rgba(204,0,0,.12); }

    .hws-suggestions {
      position: absolute; top: 100%; left: 0; right: 0; z-index: 9999;
      margin: 0; padding: 0; list-style: none;
      background: #fff; border: 1px solid #ccc; border-top: none;
      border-radius: 0 0 6px 6px; box-shadow: 0 6px 16px rgba(0,0,0,.12); overflow: hidden;
    }
    .hws-suggestions li {
      padding: 10px 14px; font-size: 14px; line-height: 1.4; cursor: pointer;
      border-bottom: 1px solid #f0f0f0; color: var(--global-palette8);
    }
    .hws-suggestions li:last-child { border-bottom: none; }
    .hws-suggestions li:hover, .hws-suggestions li.hws-active { background: #f8f8f8; }

    .hws-search-btn, .hws-gps-btn {
      padding: 10px 13px; background: var(--global-palette1); color: #fff;
      border: none; border-radius: 6px; cursor: pointer;
      display: flex; align-items: center; justify-content: center; flex-shrink: 0;
      transition: background .2s;
    }
    .hws-search-btn:hover, .hws-gps-btn:hover { background: #aa0000; }
    .hws-search-btn:focus, .hws-gps-btn:focus { outline: 2px solid var(--global-palette1); outline-offset: 2px; }
    .hws-search-btn[disabled], .hws-gps-btn[disabled] { opacity: .55; cursor: not-allowed; }

    .hws-status {
      margin: 8px 0 0; padding: 10px 14px;
      background: #f9f9f9; border-left: 3px solid #ccc; border-radius: 0 4px 4px 0;
      font-size: 14px; color: #555;
    }
    .hws-status.hws-error { border-left-color: var(--global-palette1); background: #fff5f5; color: var(--global-palette1); }

    /* Content: flex column by default (map on top, results below with gap).
       At >=1100px with results: row-reverse (map right, cards left), equal heights. */
    .hws-content {
      margin-top: 16px;
      display: flex;
      flex-direction: column;
      gap: 16px;
    }
    .hws-map {
      height: 350px; border-radius: 8px; overflow: hidden; border: 1px solid #e0e0e0;
    }
    /* Results as grid so all cards share equal height in the wide layout */
    .hws-results { display: grid; gap: 6px; }
    .hws-results:empty { display: none; }

    @media (min-width: 1100px) {
      .hws-content.hws-has-results {
        flex-direction: row-reverse;
        align-items: stretch;       /* map stretches to match the cards' height */
      }
      .hws-content.hws-has-results .hws-map {
        flex: 0 0 calc(65% - 8px);  /* -8px so map + cards + 16px gap = 100% */
        height: auto;               /* grows/shrinks with the cards */
        min-height: 200px;
      }
      .hws-content.hws-has-results .hws-results {
        flex: 0 0 calc(35% - 8px);
        grid-auto-rows: 1fr;        /* all cards equal height */
      }
    }

    .hws-card {
      display: flex; align-items: flex-start; gap: 10px;
      padding: 8px 12px;
      border: 2px solid #fff; border-radius: 8px; background: #fff;
      transition: border-color .15s;
    }
    .hws-card:hover { border-color: var(--global-palette1); }
    .hws-card--clickable { cursor: pointer; }

    /* Reset theme paragraph margins — !important beats .entry-content p etc. */
    .hws-widget .hws-card p {
      margin: 0 0 10px 0 !important;
      padding: 0 !important;
    }
    .hws-widget .hws-card p:last-of-type {
      margin-bottom: 0 !important;
    }

    .hws-card-num {
      flex-shrink: 0; width: 26px; height: 26px; box-sizing: border-box;
      background: #fff; color: var(--global-palette1);
      border: 2px solid var(--global-palette1); border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      font-size: 12px; font-weight: bold; margin-top: 2px;
    }
    /* Selected card: invert the number circle */
    .hws-card--selected .hws-card-num {
      background: var(--global-palette1);
      color: #fff;
    }
    .hws-card-body { flex: 1; min-width: 0; line-height: 1.3; }
    .hws-card-name { font-weight: bold; font-size: 14px; color: var(--global-palette8); }
    .hws-card-addr { font-size: 12px; color: #555; }
    .hws-card-phone { font-size: 12px; }
    .hws-card-phone a, .hws-card-site a { color: var(--global-palette1); text-decoration: none; }
    .hws-card-site { font-size: 12px; }
    .hws-card-dist {
      flex-shrink: 0; font-size: 12px; font-weight: bold;
      color: #555; white-space: nowrap; padding-top: 2px;
    }

    /* Map markers (custom HTML elements for MapLibre) */
    .hws-marker-search {
      font-size: 24px; line-height: 1; cursor: pointer;
      filter: drop-shadow(0 2px 3px rgba(0,0,0,.3));
      transition: opacity .15s;
    }
    .hws-marker-search:hover { opacity: .75; }
    .hws-marker-num {
      width: 28px; height: 28px; box-sizing: border-box;
      background: var(--global-palette1); color: #fff;
      border-radius: 50%; border: 2px solid #fff; cursor: pointer;
      display: flex; align-items: center; justify-content: center;
      font-size: 13px; font-weight: bold; box-shadow: 0 2px 6px rgba(0,0,0,.3);
      transition: opacity .15s;
    }
    .hws-marker-num:hover { opacity: .75; }
 
    /* MapLibre popup styling */
    .hws-widget .maplibregl-popup-content {
      color: #1a1a1a;
      font-family: inherit;
      font-size: 13px;
      line-height: 1.4;
      padding: 10px 28px 10px 12px;
      border-radius: 6px;
      box-shadow: 0 3px 14px rgba(0,0,0,.2);
    }
    .hws-widget .maplibregl-popup-close-button {
      color: #1a1a1a;
      font-size: 18px;
      font-weight: bold;
      right: 6px;
      top: 5px;
      line-height: 1;
      padding: 0 3px;
      background: none;
      border: none;
      cursor: pointer;
      transition: opacity .15s;
    }
    .hws-widget .maplibregl-popup-close-button:hover {
      opacity: .45;
      background: none; /* opacity only, no gradient or background change */
    }
    /* Phone link inside map popups: bold + brand colour */
    .hws-widget .hws-popup-phone { color: var(--global-palette1); font-weight: bold; text-decoration: none; }
    </style>

    <script>
    (function () {
      function onReady(fn) {
        if (document.readyState !== 'loading') { fn(); }
        else { document.addEventListener('DOMContentLoaded', fn); }
      }

      onReady(function () {
        if (typeof maplibregl === 'undefined') { setTimeout(onReady, 50); return; }

        const widget    = document.getElementById(<?php echo json_encode($uid); ?>);
        const input     = widget.querySelector('.hws-input');
        const suggestions = widget.querySelector('.hws-suggestions');
        const searchBtn = widget.querySelector('.hws-search-btn');
        const gpsBtn    = widget.querySelector('.hws-gps-btn');
        const statusEl  = widget.querySelector('.hws-status');
        const contentEl = widget.querySelector('.hws-content');
        const mapEl     = document.getElementById(<?php echo json_encode($map_id); ?>);
        const resultsEl = widget.querySelector('.hws-results');

        const SUGGEST_URL = <?php echo json_encode($suggest_url); ?>;
        const NEARBY_URL  = <?php echo json_encode($nearby_url); ?>;
        const MAP_STYLE   = 'https://tiles.openfreemap.org/styles/liberty';

        let debounceTimer   = null;
        let map             = null;
        let markers         = [];   // all markers currently on the map
        let businessMarkers = [];   // markers indexed to match the result cards
        let selectedIndex   = -1;   // currently selected card, -1 = none
        let lastBounds      = null; // overview bounds to zoom back to on deselect

        // Initialize map with Austria overview (note: MapLibre uses [lng, lat])
        map = new maplibregl.Map({
          container: mapEl,
          style: MAP_STYLE,
          center: [14.1, 47.6],
          zoom: 6.4,
          attributionControl: { compact: true },
        });
        map.addControl(new maplibregl.NavigationControl({ showCompass: false }), 'top-left');

        // Re-render map on window resize (e.g. crossing the 1100px breakpoint)
        let resizeTimer = null;
        window.addEventListener('resize', function () {
          clearTimeout(resizeTimer);
          resizeTimer = setTimeout(function () { if (map) map.resize(); }, 200);
        });

        // --- Autocomplete ---

        input.addEventListener('input', function () {
          clearTimeout(debounceTimer);
          const q = input.value.trim();
          if (q.length < 3) { hideSuggestions(); return; }
          debounceTimer = setTimeout(function () { fetchSuggestions(q); }, 300);
        });

        input.addEventListener('keydown', function (e) {
          if (e.key === 'Enter') { e.preventDefault(); hideSuggestions(); searchByText(input.value); }
          if (e.key === 'Escape') hideSuggestions();
        });

        searchBtn.addEventListener('click', function () { hideSuggestions(); searchByText(input.value); });

        document.addEventListener('click', function (e) { if (!widget.contains(e.target)) hideSuggestions(); });

        async function fetchSuggestions(q) {
          try {
            const res  = await fetch(SUGGEST_URL + '?q=' + encodeURIComponent(q));
            const data = await res.json();
            if (!Array.isArray(data) || !data.length) { hideSuggestions(); return; }
            renderSuggestions(data);
          } catch (err) { hideSuggestions(); }
        }

        async function searchByText(text) {
          text = (text || '').trim();
          if (text.length < 2) return;
          showStatus('Adresse wird gesucht …');
          hideSuggestions();
          try {
            const res  = await fetch(SUGGEST_URL + '?q=' + encodeURIComponent(text));
            const data = await res.json();
            if (!Array.isArray(data) || !data.length) { showStatus('Adresse nicht gefunden.', true); return; }
            searchNearby(data[0].lat, data[0].lng, data[0].label);
          } catch (err) { showStatus('Verbindungsfehler. Bitte später erneut versuchen.', true); }
        }

        function renderSuggestions(items) {
          suggestions.innerHTML = '';
          items.forEach(function (item) {
            const li = document.createElement('li');
            li.setAttribute('role', 'option');
            li.textContent = item.label;
            li.addEventListener('click', function () {
              input.value = item.label;
              hideSuggestions();
              searchNearby(item.lat, item.lng, item.label);
            });
            suggestions.appendChild(li);
          });
          suggestions.removeAttribute('hidden');
        }

        function hideSuggestions() { suggestions.setAttribute('hidden', ''); suggestions.innerHTML = ''; }

        // --- GPS ---

        gpsBtn.addEventListener('click', function () {
          if (!navigator.geolocation) { showStatus('Standortermittlung wird von diesem Browser nicht unterstützt.', true); return; }
          gpsBtn.disabled = true;
          showStatus('Standort wird ermittelt …');
          navigator.geolocation.getCurrentPosition(
            function (pos) {
              gpsBtn.disabled = false;
              input.value = '';
              hideSuggestions();
              searchNearby(pos.coords.latitude, pos.coords.longitude, null);
            },
            function () {
              gpsBtn.disabled = false;
              showStatus('Standort konnte nicht ermittelt werden. Bitte prüfen Sie die Standortfreigabe im Browser.', true);
            },
            { timeout: 10000, maximumAge: 60000 }
          );
        });

        // --- Search + results ---

        async function searchNearby(lat, lng, label) {
          showStatus('Suche nach verfügbaren Betrieben …');
          clearResults();
          try {
            const url = NEARBY_URL + '?lat=' + encodeURIComponent(lat) + '&lng=' + encodeURIComponent(lng);
            const res  = await fetch(url);
            const data = await res.json();
            if (!res.ok) { showStatus((data && data.message) || 'Fehler bei der Suche.', true); return; }
            if (!data.data || !data.data.length) { showStatus('Keine verfügbaren Betriebe in der Nähe gefunden.', true); return; }
            hideStatus();
            renderResults(data.data);
            contentEl.classList.add('hws-has-results');
            renderMap(data.data, lat, lng, label);
            // Let the layout settle (grid heights, flex stretch) before MapLibre measures
            setTimeout(function () { map.resize(); }, 80);
          } catch (err) { showStatus('Verbindungsfehler. Bitte später erneut versuchen.', true); }
        }

        // Build an address with commas from the individual fields
        // e.g. "Andersengasse 21, 1120 Wien, Österreich"
        function formatAddress(b) {
          const parts = [];
          if (b.address) parts.push(b.address);
          const cityLine = [b.postal_code, b.city].filter(Boolean).join(' ');
          if (cityLine) parts.push(cityLine);
          if (b.country) parts.push(b.country);
          return parts.join(', ');
        }

        function renderResults(businesses) {
          resultsEl.innerHTML = '';
          selectedIndex = -1;
          businesses.forEach(function (b, i) {
            const dist = formatDist(b.distance_km);
            const card = document.createElement('div');
            card.className = 'hws-card';
            card.innerHTML =
              '<div class="hws-card-num">' + (i + 1) + '</div>'
              + '<div class="hws-card-body">'
              +   '<p class="hws-card-name">' + h(b.innung_name || '–') + '</p>'
              +   '<p class="hws-card-addr">' + h(formatAddress(b)) + '</p>'
              +   (b.phone ? '<p class="hws-card-phone"><a href="tel:' + a(b.phone) + '">' + h(b.phone) + '</a></p>' : '')
              +   (b.website ? '<p class="hws-card-site"><a href="' + a(b.website) + '" target="_blank" rel="noopener noreferrer">' + h(b.website.replace(/^https?:\/\//, '')) + '</a></p>' : '')
              + '</div>'
              + (dist ? '<div class="hws-card-dist">' + h(dist) + '</div>' : '');

            // Click toggles selection: select → zoom in; click again → deselect → overview
            if (b.latitude && b.longitude) {
              card.classList.add('hws-card--clickable');
              card.addEventListener('click', function (e) {
                if (e.target.closest('a')) return;

                if (selectedIndex === i) {
                  deselectCards();
                  closeMarkerPopup(businessMarkers[i]);
                  if (lastBounds) map.fitBounds(lastBounds, { padding: 50, maxZoom: 14 });
                } else {
                  selectCard(i);
                  map.flyTo({ center: [b.longitude, b.latitude], zoom: 16 });
                  openMarkerPopup(businessMarkers[i]);
                }

                if (window.innerWidth < 1100) {
                  mapEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }
              });
            }

            resultsEl.appendChild(card);
          });
        }

        function selectCard(i) {
          resultsEl.querySelectorAll('.hws-card').forEach(function (c, idx) {
            c.classList.toggle('hws-card--selected', idx === i);
          });
          selectedIndex = i;
        }

        function deselectCards() {
          resultsEl.querySelectorAll('.hws-card').forEach(function (c) {
            c.classList.remove('hws-card--selected');
          });
          selectedIndex = -1;
        }

        // --- Map (MapLibre GL JS) ---

        function openMarkerPopup(marker) {
          if (!marker) return;
          const p = marker.getPopup();
          if (p && !p.isOpen()) marker.togglePopup();
        }
        function closeMarkerPopup(marker) {
          if (!marker) return;
          const p = marker.getPopup();
          if (p && p.isOpen()) marker.togglePopup();
        }

        function renderMap(businesses, searchLat, searchLng, searchLabel) {
          markers.forEach(function (m) { m.remove(); });
          markers         = [];
          businessMarkers = [];

          // Search-location pin
          const searchEl = document.createElement('div');
          searchEl.className = 'hws-marker-search';
          searchEl.textContent = '📍';
          const searchPopup = new maplibregl.Popup({ offset: 28, closeButton: true })
            .setHTML(searchLabel ? '<strong>' + h(searchLabel) + '</strong>' : '<strong>Ihr Standort</strong>');
          const searchMarker = new maplibregl.Marker({ element: searchEl, anchor: 'bottom' })
            .setLngLat([searchLng, searchLat])
            .setPopup(searchPopup)
            .addTo(map);
          markers.push(searchMarker);

          // Business markers + bounds
          const bounds = new maplibregl.LngLatBounds();
          bounds.extend([searchLng, searchLat]);
          let businessCount = 0;

          businesses.forEach(function (b, i) {
            if (!b.latitude || !b.longitude) { businessMarkers[i] = null; return; }

            const el = document.createElement('div');
            el.className = 'hws-marker-num';
            el.textContent = (i + 1);

            const addr = formatAddress(b);
            const html = '<strong>' + h(b.innung_name || '') + '</strong>'
              + (addr ? '<br>' + h(addr) : '')
              + (b.phone ? '<br><a class="hws-popup-phone" href="tel:' + a(b.phone) + '">' + h(b.phone) + '</a>' : '');
            const popup = new maplibregl.Popup({ offset: 32, closeButton: true }).setHTML(html);

            const m = new maplibregl.Marker({ element: el, anchor: 'bottom' })
              .setLngLat([b.longitude, b.latitude])
              .setPopup(popup)
              .addTo(map);

            markers.push(m);
            businessMarkers[i] = m;
            bounds.extend([b.longitude, b.latitude]);
            businessCount++;
          });

          if (businessCount > 0) {
            lastBounds = bounds;
            map.fitBounds(bounds, { padding: 50, maxZoom: 14 });
          } else {
            lastBounds = null;
            map.flyTo({ center: [searchLng, searchLat], zoom: 13 });
          }
        }

        // --- UI helpers ---

        function showStatus(msg, isError) {
          statusEl.textContent = msg;
          statusEl.classList.toggle('hws-error', !!isError);
          statusEl.removeAttribute('hidden');
        }
        function hideStatus() {
          statusEl.setAttribute('hidden', '');
          statusEl.textContent = '';
          statusEl.classList.remove('hws-error');
        }
        function clearResults() { resultsEl.innerHTML = ''; }

        function formatDist(km) {
          if (km === null || km === undefined) return '';
          return km < 1 ? Math.round(km * 1000) + '\u202fm' : km.toFixed(1) + '\u202fkm';
        }

        function h(s) {
          return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }
        function a(s) {
          return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/'/g,'&#x27;');
        }
      });
    })();
    </script>

    <?php
    return ob_get_clean();
  }
}

new KEO_User_Search_Map();