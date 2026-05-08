<?php
/**
 * Plugin Name: Helwacht Availability
 * Description: Members can toggle availability; exposes a JSON REST endpoint for Helwacht.
 * Version: 0.2.2
 */

if (!defined('ABSPATH')) exit;

class Helwacht_Availability {
  const META_AVAILABLE      = 'helwacht_available';
  const META_INNUNG_NAME    = 'innung_name';
  const META_PHONE          = 'phone';
  const META_POSTAL_CODE    = 'postal_code';
  const META_CITY           = 'city';
  const META_ADDRESS        = 'address';
  const META_WEBSITE        = 'website';
  const META_LASTUPDATE     = 'last_update';
  const META_INNUNG_ADDRESS = 'innung_address';
  const OPTION_API_KEY      = 'helwacht_api_key';

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
      'args' => [
        'postal_code' => [
          'required' => false,
          'sanitize_callback' => 'sanitize_text_field',
        ],
      ],
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
    $postal_code_filter = $this->get_postal_code_filter($request);

    $meta_query = [
      [
        'key'   => self::META_AVAILABLE,
        'value' => '1',
      ],
    ];

    if ($postal_code_filter !== '') {
      $meta_query[] = [
        'key'     => self::META_POSTAL_CODE,
        'value'   => $postal_code_filter,
        'compare' => '=',
      ];
    }

    $users = get_users([
      'meta_query' => $meta_query,
      'fields'     => ['ID', 'display_name', 'user_email'],
    ]);

    $available = [];

    foreach ($users as $u) {
      $address     = get_user_meta($u->ID, self::META_ADDRESS, true);
      $postal_code = get_user_meta($u->ID, self::META_POSTAL_CODE, true);
      $city        = get_user_meta($u->ID, self::META_CITY, true);
      $country     = 'Österreich';

      $available[] = [
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
        'full_address'           => $this->build_full_address($address, $postal_code, $city, $country),
        'email'                  => $u->user_email,
        'website'                => get_user_meta($u->ID, self::META_WEBSITE, true),
        'available'              => true,
        'last_update'            => get_user_meta($u->ID, self::META_LASTUPDATE, true),
      ];
    }

    return [
      'generated_at'        => current_time('c'),
      'count'               => count($available),
      'postal_code_filter'  => $postal_code_filter !== '' ? $postal_code_filter : null,
      'data'                => $available,
    ];
  }

  private function get_postal_code_filter(\WP_REST_Request $request) {
    $postal_code = $request->get_param('postal_code');

    if ($postal_code === null || $postal_code === '') {
      $json = $request->get_json_params();
      if (is_array($json) && isset($json['postal_code'])) {
        $postal_code = $json['postal_code'];
      }
    }

    $postal_code = sanitize_text_field((string) $postal_code);
    $postal_code = preg_replace('/[^0-9A-Za-z\- ]/', '', $postal_code);

    return trim($postal_code);
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
    $country     = 'Österreich';

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
            <strong>Aktuelle Verfügbarkeit:</strong><br>
            <span id="hw-status"><?php echo $current ? 'Verfügbar' : 'Nicht verfügbar'; ?></span>
          </p>

          <label style="position:relative;display:inline-block;width:60px;height:34px;">
            <input type="checkbox" id="hw-toggle" <?php echo $current ? 'checked' : ''; ?> style="opacity:0;width:0;height:0;">
            <span style="
              position:absolute;
              cursor:pointer;
              top:0;left:0;right:0;bottom:0;
              background-color:<?php echo $current ? '#4CAF50' : '#ccc'; ?>;
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
              slider.style.backgroundColor = available ? '#4CAF50' : '#ccc';
              knob.style.transform = available ? 'translateX(26px)' : 'translateX(0)';
              msg.textContent = 'Gespeichert (' + data.data.last_update + ')';
            } catch (e) {
              checkbox.checked = previousChecked;
              status.textContent = previousChecked ? 'Verfügbar' : 'Nicht verfügbar';
              slider.style.backgroundColor = previousChecked ? '#4CAF50' : '#ccc';
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

  $innung_name = sanitize_text_field($_POST['innung_name'] ?? '');
  update_user_meta($user_id, 'innung_name', $innung_name);
  update_user_meta($user_id, 'company', $innung_name);
  update_user_meta($user_id, 'phone', sanitize_text_field($_POST['phone'] ?? ''));
  update_user_meta($user_id, 'address', sanitize_text_field($_POST['address'] ?? ''));
  update_user_meta($user_id, 'postal_code', sanitize_text_field($_POST['postal_code'] ?? ''));
  update_user_meta($user_id, 'city', sanitize_text_field($_POST['city'] ?? ''));
  update_user_meta($user_id, 'website', esc_url_raw($_POST['website'] ?? ''));
  update_user_meta($user_id, 'innung_address', sanitize_text_field($_POST['innung_address'] ?? ''));
}

new Helwacht_Availability();
