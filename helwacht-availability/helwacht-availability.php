<?php
/**
 * Plugin Name: Helwacht Availability
 * Description: Members can toggle availability; exposes a JSON REST endpoint for Helwacht.
 * Version: 0.3.1
 */

if (!defined('ABSPATH')) exit;

class Helwacht_Availability {
  const META_AVAILABLE           = 'helwacht_available';
  const META_INNUNG_NAME         = 'innung_name';
  const META_PHONE               = 'phone';
  const META_POSTAL_CODE         = 'postal_code';
  const META_CITY                = 'city';
  const META_ADDRESS             = 'address';
  const META_WEBSITE             = 'website';
  const META_LASTUPDATE          = 'last_update';
  const META_INNUNG_ADDRESS      = 'innung_address';
  const META_LATITUDE            = 'helwacht_latitude';
  const META_LONGITUDE           = 'helwacht_longitude';
  const META_GEOCODED_AT         = 'helwacht_geocoded_at';
  const META_GEOCODED_HASH       = 'helwacht_geocoded_hash';
  const META_GEOCODED_SOURCE     = 'helwacht_geocoded_source';
  const OPTION_API_KEY           = 'helwacht_api_key';
  const OPTION_MAPBOX_TOKEN      = 'helwacht_mapbox_token';
  const DEFAULT_COUNTRY          = 'Österreich';
  const DEFAULT_COUNTRY_CODE     = 'at';
  const MAX_QUERY_LENGTH        = 200;

  public function __construct() {
    add_action('rest_api_init', [$this, 'register_routes']);
    add_shortcode('helwacht_toggle', [$this, 'shortcode_toggle']);
    register_activation_hook(__FILE__, [$this, 'on_activate']);
  }

  public function on_activate() {
    if (!get_option(self::OPTION_API_KEY)) {
      $key = wp_generate_password(32, false, false);
      add_option(self::OPTION_API_KEY, $key);
    }
  }

  public function register_routes() {
    register_rest_route('helwacht/v1', '/availability', [
      'methods'  => ['GET', 'POST'],
      'callback' => [$this, 'rest_get_availability'],
      'permission_callback' => [$this, 'rest_permission'],
    ]);

    register_rest_route('helwacht/v1', '/toggle', [
      'methods'  => 'POST',
      'callback' => [$this, 'rest_toggle_availability'],
      'permission_callback' => function () {
        return is_user_logged_in();
      },
    ]);
  }

  public function rest_permission(\WP_REST_Request $request) {
    $stored = get_option(self::OPTION_API_KEY);
    $key = $request->get_header('x-api-key');

    if (!$key) {
      $key = $request->get_param('key');
    }

    return is_string($stored) && $stored !== '' && hash_equals($stored, (string) $key);
  }

  public function rest_get_availability(\WP_REST_Request $request) {
    $filters = $this->get_filters($request);
    $search_query = $this->get_search_query($request);
    $user_query_filters = $this->get_user_query_filters($filters);

    $args = [
      'fields' => ['ID', 'display_name', 'user_email'],
    ];

    $meta_query = [
      'relation' => 'AND',
      [
        'key'   => self::META_AVAILABLE,
        'value' => '1',
      ],
    ];

    foreach ($user_query_filters['meta'] as $filter) {
      $meta_query[] = $filter;
    }

    $args['meta_query'] = $meta_query;

    if (!empty($user_query_filters['search'])) {
      $args['search'] = '*' . esc_attr($user_query_filters['search']) . '*';
      $args['search_columns'] = ['user_email', 'display_name'];
    }

    $users = get_users($args);
    $available = [];
    $search_coordinates = null;

    if ($search_query !== '') {
      $search_coordinates = $this->geocode_address($search_query);

      if (is_wp_error($search_coordinates)) {
        return [
          'code'    => 'helwacht_geocode_failed',
          'message' => 'Adresse konnte nicht verarbeitet werden.',
        ];
      }
    }

    foreach ($users as $u) {
      $record = $this->build_availability_record($u, $search_coordinates);

      if (!$this->record_matches_filters($record, $filters)) {
        continue;
      }

      $available[] = $record;
    }

    if ($search_coordinates) {
      usort($available, function ($a, $b) {
        $a_distance = array_key_exists('distance_km', $a) ? $a['distance_km'] : null;
        $b_distance = array_key_exists('distance_km', $b) ? $b['distance_km'] : null;

        if ($a_distance === null && $b_distance === null) {
          return 0;
        }

        if ($a_distance === null) {
          return 1;
        }

        if ($b_distance === null) {
          return -1;
        }

        return $a_distance <=> $b_distance;
      });
    }

    return [
      'generated_at'       => current_time('c'),
      'count'              => count($available),
      'filters'            => !empty($filters) ? $filters : null,
      'query'              => $search_query !== '' ? $search_query : null,
      'query_coordinates'  => $search_coordinates ? [
        'latitude'  => $search_coordinates['latitude'],
        'longitude' => $search_coordinates['longitude'],
      ] : null,
      'data'               => $available,
    ];
  }

  private function build_availability_record($u, $search_coordinates = null) {
    $address     = get_user_meta($u->ID, self::META_ADDRESS, true);
    $postal_code = get_user_meta($u->ID, self::META_POSTAL_CODE, true);
    $city        = get_user_meta($u->ID, self::META_CITY, true);
    $country     = self::DEFAULT_COUNTRY;
    $full_address = $this->build_full_address($address, $postal_code, $city, $country);

    $record = [
      'innung_id'              => (string) $u->ID,
      'innung_name'            => $this->get_innung_name($u->ID, $u->display_name),
      'innung_billing_address' => $this->build_innung_billing_address($u->ID),
      'phone'                  => $this->format_phone_international(get_user_meta($u->ID, self::META_PHONE, true)),
      'first_name'             => get_user_meta($u->ID, 'first_name', true),
      'last_name'              => get_user_meta($u->ID, 'last_name', true),
      'address'                => $address,
      'postal_code'            => $postal_code,
      'city'                   => $city,
      'country'                => $country,
      'full_address'           => $full_address,
            'website'                => get_user_meta($u->ID, self::META_WEBSITE, true),
      'available'              => true,
      'last_update'            => get_user_meta($u->ID, self::META_LASTUPDATE, true),
    ];

    if ($search_coordinates) {
      $business_coordinates = $this->get_or_geocode_user_coordinates($u->ID, $full_address);

      if (is_wp_error($business_coordinates)) {
        $record['distance_km'] = null;
      } else {
        $record['distance_km'] = $this->calculate_distance_km(
          $search_coordinates['latitude'],
          $search_coordinates['longitude'],
          $business_coordinates['latitude'],
          $business_coordinates['longitude']
        );
      }
    }

    return $record;
  }

  private function get_filterable_fields() {
    return [
      'innung_id',
      'innung_name',
      'innung_billing_address',
      'phone',
      'first_name',
      'last_name',
      'address',
      'postal_code',
      'city',
      'country',
      'full_address',
            'website',
      'available',
      'last_update',
    ];
  }

  private function get_filters($request) {
    $filters = [];
    $json = $request->get_json_params();

    if (!is_array($json)) {
      $json = [];
    }

    $raw_body = trim((string) $request->get_body());
    if (empty($json) && $raw_body !== '' && strpos($raw_body, '{') === 0) {
      $decoded = json_decode($raw_body, true);
      if (is_array($decoded)) {
        $json = $decoded;
      }
    }

    foreach ($this->get_filterable_fields() as $field) {
      $value = $request->get_param($field);

      if (($value === null || $value === '') && array_key_exists($field, $json)) {
        $value = $json[$field];
      }

      if ($value === null || $value === '') {
        continue;
      }

      $filters[$field] = $this->sanitize_filter_value($value);
    }

    return $filters;
  }

  private function get_search_query($request) {
    $json = $request->get_json_params();
    if (!is_array($json)) {
      $json = [];
    }

    $value = $request->get_param('q');

    if (($value === null || $value === '') && array_key_exists('q', $json)) {
      $value = $json['q'];
    }

    if ($value === null) {
      return '';
    }

    $value = trim(sanitize_text_field((string) $value));

    if ($value === '') {
      return '';
    }

    if (mb_strlen($value) > self::MAX_QUERY_LENGTH) {
      return '';
    }

    return $value;
  }

  private function sanitize_filter_value($value) {
    if (is_bool($value)) {
      return $value ? 'true' : 'false';
    }

    if (is_array($value)) {
      $value = reset($value);
    }

    return trim(sanitize_text_field((string) $value));
  }

  private function get_user_query_filters($filters) {
    $meta_map = [
      'innung_name'            => self::META_INNUNG_NAME,
      'phone'                  => self::META_PHONE,
      'first_name'             => 'first_name',
      'last_name'              => 'last_name',
      'address'                => self::META_ADDRESS,
      'postal_code'            => self::META_POSTAL_CODE,
      'city'                   => self::META_CITY,
      'website'                => self::META_WEBSITE,
      'last_update'            => self::META_LASTUPDATE,
      'innung_billing_address' => self::META_INNUNG_ADDRESS,
    ];

    $query_filters = [
      'meta'   => [],
      'search' => '',
    ];

    foreach ($meta_map as $field => $meta_key) {
      if (!isset($filters[$field])) {
        continue;
      }

      $query_filters['meta'][] = [
        'key'     => $meta_key,
        'value'   => $filters[$field],
        'compare' => 'LIKE',
      ];
    }

    if (isset($filters['email'])) {
      $query_filters['search'] = $filters['email'];
    }

    return $query_filters;
  }

  private function record_matches_filters($record, $filters) {
    foreach ($filters as $field => $expected) {
      if (!array_key_exists($field, $record)) {
        return false;
      }

      $actual = $record[$field];

      if (is_bool($actual)) {
        $expected_bool = filter_var($expected, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($expected_bool === null || $actual !== $expected_bool) {
          return false;
        }
        continue;
      }

      if (stripos((string) $actual, (string) $expected) === false) {
        return false;
      }
    }

    return true;
  }

  public function rest_toggle_availability(\WP_REST_Request $request) {
    $uid = get_current_user_id();

    if (!$uid) {
      return new \WP_REST_Response([
        'success' => false,
        'data'    => ['message' => 'Not logged in'],
      ], 401);
    }

    try {
      $current = get_user_meta($uid, self::META_AVAILABLE, true) === '1';
      $new = $current ? '0' : '1';

      update_user_meta($uid, self::META_AVAILABLE, $new);
      update_user_meta($uid, self::META_LASTUPDATE, current_time('c'));

      return new \WP_REST_Response([
        'success' => true,
        'data'    => [
          'available'   => $new === '1',
          'last_update' => get_user_meta($uid, self::META_LASTUPDATE, true),
        ],
      ], 200);
    } catch (\Throwable $e) {
      return new \WP_REST_Response([
        'success' => false,
        'data'    => [
          'message' => $e->getMessage(),
        ],
      ], 500);
    }
  }

  private function get_innung_name($user_id, $fallback = '') {
    $value = trim((string) get_user_meta($user_id, self::META_INNUNG_NAME, true));

    if ($value === '') {
      $value = trim((string) get_user_meta($user_id, 'company', true));
    }

    return $value !== '' ? $value : $fallback;
  }

  private function build_full_address($address, $postal_code, $city, $country = '') {
    $parts = array_filter([
      trim((string) $address),
      trim((string) $postal_code),
      trim((string) $city),
      trim((string) $country),
    ]);

    return implode(' ', $parts);
  }

  private function build_innung_billing_address($user_id) {
    $manual = trim((string) get_user_meta($user_id, self::META_INNUNG_ADDRESS, true));
    if ($manual !== '') {
      return $manual;
    }

    $address     = trim((string) get_user_meta($user_id, self::META_ADDRESS, true));
    $postal_code = trim((string) get_user_meta($user_id, self::META_POSTAL_CODE, true));
    $city        = trim((string) get_user_meta($user_id, self::META_CITY, true));
    $country     = self::DEFAULT_COUNTRY;

    $parts = array_filter([
      $address,
      trim($postal_code . ' ' . $city),
      $country,
    ]);

    return implode(' ', $parts);
  }

  private function format_phone_international($phone, $default_country_code = '+43') {
    $phone = trim((string) $phone);

    if ($phone === '') {
      return '';
    }

    $phone = preg_replace('/[^\d+]/', '', $phone);

    if (strpos($phone, '00') === 0) {
      $phone = '+' . substr($phone, 2);
    }

    if (strpos($phone, '+') === 0) {
      return preg_replace('/(?!^)\+/', '', $phone);
    }

    $digits = preg_replace('/\D+/', '', $phone);

    if ($digits === '') {
      return '';
    }

    if (strpos($digits, '43') === 0) {
      return '+' . $digits;
    }

    if (strpos($digits, '0') === 0) {
      $digits = substr($digits, 1);
    }

    return $default_country_code . $digits;
  }

  private function get_mapbox_token() {
    if (defined('HELWACHT_MAPBOX_TOKEN') && is_string(HELWACHT_MAPBOX_TOKEN) && trim(HELWACHT_MAPBOX_TOKEN) !== '') {
      return trim(HELWACHT_MAPBOX_TOKEN);
    }

    $env_token = getenv('HELWACHT_MAPBOX_TOKEN');
    if (is_string($env_token) && trim($env_token) !== '') {
      return trim($env_token);
    }

    $token = get_option(self::OPTION_MAPBOX_TOKEN);
    if (is_string($token) && trim($token) !== '') {
      return trim($token);
    }

    return '';
  }

  private function geocode_address($address) {
    $token = $this->get_mapbox_token();

    if ($token === '') {
      return new \WP_Error('helwacht_missing_mapbox_token', 'Mapbox token is missing.', ['status' => 500]);
    }

    $url = add_query_arg([
      'access_token' => $token,
      'limit'        => 1,
      'country'      => self::DEFAULT_COUNTRY_CODE,
      'autocomplete' => 'false',
      'language'     => 'de',
    ], 'https://api.mapbox.com/geocoding/v5/mapbox.places/' . rawurlencode($address) . '.json');

    $response = wp_remote_get($url, [
      'timeout' => 15,
      'headers' => [
        'Accept' => 'application/json',
      ],
    ]);

    if (is_wp_error($response)) {
      return new \WP_Error('helwacht_geocode_request_failed', $response->get_error_message(), ['status' => 502]);
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if ($status_code < 200 || $status_code >= 300) {
      $message = 'Mapbox geocoding failed.';
      if (is_array($data) && !empty($data['message'])) {
        $message = sanitize_text_field((string) $data['message']);
      }

      return new \WP_Error('helwacht_geocode_http_error', $message, ['status' => 502]);
    }

    if (!is_array($data) || empty($data['features'][0]['center']) || count($data['features'][0]['center']) < 2) {
      return new \WP_Error('helwacht_geocode_no_result', 'Address could not be geocoded.', ['status' => 400]);
    }

    return [
      'longitude' => (float) $data['features'][0]['center'][0],
      'latitude'  => (float) $data['features'][0]['center'][1],
    ];
  }

  private function get_or_geocode_user_coordinates($user_id, $full_address) {
    $hash = md5($full_address);
    $stored_hash = (string) get_user_meta($user_id, self::META_GEOCODED_HASH, true);
    $stored_lat = get_user_meta($user_id, self::META_LATITUDE, true);
    $stored_lng = get_user_meta($user_id, self::META_LONGITUDE, true);

    if (
      $stored_hash === $hash &&
      $stored_lat !== '' &&
      $stored_lng !== '' &&
      is_numeric($stored_lat) &&
      is_numeric($stored_lng)
    ) {
      return [
        'latitude'  => (float) $stored_lat,
        'longitude' => (float) $stored_lng,
      ];
    }

    $coordinates = $this->geocode_address($full_address);

    if (is_wp_error($coordinates)) {
      return $coordinates;
    }

    update_user_meta($user_id, self::META_LATITUDE, $coordinates['latitude']);
    update_user_meta($user_id, self::META_LONGITUDE, $coordinates['longitude']);
    update_user_meta($user_id, self::META_GEOCODED_HASH, $hash);
    update_user_meta($user_id, self::META_GEOCODED_AT, current_time('c'));
    update_user_meta($user_id, self::META_GEOCODED_SOURCE, 'mapbox');

    return $coordinates;
  }

  private function calculate_distance_km($lat1, $lng1, $lat2, $lng2) {
    $earth_radius_km = 6371;

    $d_lat = deg2rad($lat2 - $lat1);
    $d_lng = deg2rad($lng2 - $lng1);

    $a = sin($d_lat / 2) * sin($d_lat / 2)
      + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
      * sin($d_lng / 2) * sin($d_lng / 2);

    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return round($earth_radius_km * $c, 2);
  }

  public function shortcode_toggle() {
    if (!is_user_logged_in()) {
      $login_url = wp_login_url(get_permalink());

      return '
        <div style="display:flex;justify-content:center;">
          <div style="padding:20px;border:1px solid #ddd;border-radius:12px;max-width:420px;width:100%;text-align:center;box-shadow:0 4px 12px rgba(0,0,0,0.05);">
            <p style="margin-bottom:20px;">Bitte einloggen, um die Verfügbarkeit zu ändern.</p>
            <p>
              <a href="' . esc_url($login_url) . '" style="display:inline-block;padding:10px 14px;background:#cc0000;color:#fff;text-decoration:none;border-radius:4px;">
                Zum Login
              </a>
            </p>
          </div>
        </div>
      ';
    }

    $uid = get_current_user_id();
    $current = get_user_meta($uid, self::META_AVAILABLE, true) === '1';
    $nonce = wp_create_nonce('wp_rest');

    ob_start(); ?>
      <div style="display:flex;justify-content:center;">
        <div style="padding:20px;border:1px solid #ddd;border-radius:12px;max-width:420px;width:100%;text-align:center;box-shadow:0 4px 12px rgba(0,0,0,0.05);">
          
          <p style="margin-bottom:20px;">
            <strong>Ihre aktuelle Verfügbarkeit:</strong><br>
            <span id="hw-status"><?php echo $current ? 'Verfügbar' : 'Nicht verfügbar'; ?></span>
          </p>

          <label style="position:relative;display:inline-block;width:60px;height:34px;">
            <input type="checkbox" id="hw-toggle" <?php echo $current ? 'checked' : ''; ?> style="opacity:0;width:0;height:0;">
            <span style="
              position:absolute;
              cursor:pointer;
              top:0;left:0;right:0;bottom:0;
              background-color:<?php echo $current ? '#4CAF50' : 'var(--global-palette1)'; ?>;
              transition:.3s;
              border-radius:34px;
            " id="hw-slider"></span>
            <span style="
              position:absolute;
              content:'';
              height:26px;width:26px;
              left:4px;
              bottom:4px;
              background-color:white;
              transition:.3s;
              border-radius:50%;
              transform:<?php echo $current ? 'translateX(26px)' : 'translateX(0)'; ?>;
            " id="hw-knob"></span>
          </label>

          <p id="hw-msg" style="margin-top:15px;font-size:14px;color:#666;"></p>

        </div>
      </div>

      <script>
        (function() {
          const checkbox = document.getElementById('hw-toggle');
          const status = document.getElementById('hw-status');
          const msg = document.getElementById('hw-msg');
          const slider = document.getElementById('hw-slider');
          const knob = document.getElementById('hw-knob');

          if (!checkbox || !status || !msg || !slider || !knob) {
            return;
          }

          checkbox.addEventListener('change', async () => {
            msg.textContent = 'Speichere...';

            const previousChecked = !checkbox.checked;

            try {
              const res = await fetch('<?php echo esc_url(rest_url('helwacht/v1/toggle')); ?>', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                  'Accept': 'application/json',
                  'X-WP-Nonce': '<?php echo esc_js($nonce); ?>'
                },
                body: ''
              });

              const raw = await res.text();
              let data;

              try {
                data = JSON.parse(raw);
              } catch (parseError) {
                throw new Error('Server liefert kein JSON (HTTP ' + res.status + ', URL: ' + res.url + '). Antwort beginnt mit: ' + raw.slice(0, 120));
              }

              if (!res.ok) {
                throw new Error((data && data.data && (data.data.message || data.data)) || ('HTTP ' + res.status));
              }

              if (!data.success) {
                throw new Error((data.data && (data.data.message || data.data)) || 'Fehler');
              }

              const available = !!data.data.available;

              status.textContent = available ? 'Verfügbar' : 'Nicht verfügbar';
              slider.style.backgroundColor = available ? '#4CAF50' : 'var(--global-palette1)';
              knob.style.transform = available ? 'translateX(26px)' : 'translateX(0)';
              msg.textContent = 'Gespeichert (' + data.data.last_update + ')';
            } catch (e) {
              checkbox.checked = previousChecked;
              status.textContent = previousChecked ? 'Verfügbar' : 'Nicht verfügbar';
              slider.style.backgroundColor = previousChecked ? '#4CAF50' : 'var(--global-palette1)';
              knob.style.transform = previousChecked ? 'translateX(26px)' : 'translateX(0)';
              msg.textContent = 'Fehler: ' + e.message;
            }
          });
        })();
      </script>
    <?php
    return ob_get_clean();
  }
}

add_action('show_user_profile', 'helwacht_user_fields');
add_action('edit_user_profile', 'helwacht_user_fields');

function helwacht_user_fields($user) {
  ?>
  <h2>Helwacht Daten</h2>
  <table class="form-table">
    <tr>
      <th><label for="innung_name">Innung Name</label></th>
      <td>
        <input type="text" name="innung_name" id="innung_name"
          value="<?php echo esc_attr(get_user_meta($user->ID, 'innung_name', true) ?: get_user_meta($user->ID, 'company', true)); ?>"
          class="regular-text" />
      </td>
    </tr>

    <tr>
      <th><label for="phone">Telefon</label></th>
      <td>
        <input type="text" name="phone" id="phone"
          value="<?php echo esc_attr(get_user_meta($user->ID, 'phone', true)); ?>"
          class="regular-text" />
        <p class="description">Wird über die API im internationalen Format ausgegeben, z. B. +436641234567.</p>
      </td>
    </tr>

    <tr>
      <th><label for="address">Adresse</label></th>
      <td>
        <input type="text" name="address" id="address"
          value="<?php echo esc_attr(get_user_meta($user->ID, 'address', true)); ?>"
          class="regular-text" />
      </td>
    </tr>

    <tr>
      <th><label for="postal_code">Postleitzahl</label></th>
      <td>
        <input type="text" name="postal_code" id="postal_code"
          value="<?php echo esc_attr(get_user_meta($user->ID, 'postal_code', true)); ?>"
          class="regular-text" />
      </td>
    </tr>

    <tr>
      <th><label for="city">Stadt</label></th>
      <td>
        <input type="text" name="city" id="city"
          value="<?php echo esc_attr(get_user_meta($user->ID, 'city', true)); ?>"
          class="regular-text" />
      </td>
    </tr>

    <tr>
      <th><label for="website">Webseite</label></th>
      <td>
        <input type="text" name="website" id="website"
          value="<?php echo esc_attr(get_user_meta($user->ID, 'website', true)); ?>"
          class="regular-text" />
      </td>
    </tr>

    <tr>
      <th><label for="innung_address">Innung Billing Adresse</label></th>
      <td>
        <input type="text" name="innung_address" id="innung_address"
          value="<?php echo esc_attr(get_user_meta($user->ID, 'innung_address', true)); ?>"
          class="regular-text" />
        <p class="description">Optionaler Override. Wenn leer, wird die Adresse automatisch als Ein-String gebaut: Straße PLZ Stadt Österreich.</p>
      </td>
    </tr>
  </table>
  <p><em>Vorname, Nachname und E-Mail können im normalen WordPress-Benutzerprofil gepflegt werden.</em></p>
  <?php
}

add_action('personal_options_update', 'helwacht_save_user_fields');
add_action('edit_user_profile_update', 'helwacht_save_user_fields');

function helwacht_save_user_fields($user_id) {
  if (!current_user_can('edit_user', $user_id)) {
    return false;
  }

  $old_address = trim((string) get_user_meta($user_id, 'address', true));
  $old_postal_code = trim((string) get_user_meta($user_id, 'postal_code', true));
  $old_city = trim((string) get_user_meta($user_id, 'city', true));

  $innung_name = sanitize_text_field($_POST['innung_name'] ?? '');
  $new_address = sanitize_text_field($_POST['address'] ?? '');
  $new_postal_code = sanitize_text_field($_POST['postal_code'] ?? '');
  $new_city = sanitize_text_field($_POST['city'] ?? '');

  update_user_meta($user_id, 'innung_name', $innung_name);
  update_user_meta($user_id, 'company', $innung_name);
  update_user_meta($user_id, 'phone', sanitize_text_field($_POST['phone'] ?? ''));
  update_user_meta($user_id, 'address', $new_address);
  update_user_meta($user_id, 'postal_code', $new_postal_code);
  update_user_meta($user_id, 'city', $new_city);
  update_user_meta($user_id, 'website', esc_url_raw($_POST['website'] ?? ''));
  update_user_meta($user_id, 'innung_address', sanitize_text_field($_POST['innung_address'] ?? ''));

  if ($old_address !== $new_address || $old_postal_code !== $new_postal_code || $old_city !== $new_city) {
    delete_user_meta($user_id, 'helwacht_latitude');
    delete_user_meta($user_id, 'helwacht_longitude');
    delete_user_meta($user_id, 'helwacht_geocoded_at');
    delete_user_meta($user_id, 'helwacht_geocoded_hash');
    delete_user_meta($user_id, 'helwacht_geocoded_source');
  }
}

new Helwacht_Availability();
