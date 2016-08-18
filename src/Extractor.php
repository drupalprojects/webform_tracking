<?php

namespace Drupal\webform_tracking;

class Extractor {
  public static $parameters = [
    'user_id' => '',
    'tags' => [],
    'external_referer' => '',
    'source' => '',
    'medium' => '',
    'version' => '',
    'other' => '',
    'term' => '',
    'campaign' => '',
    'refsid' => '',
  ];

  protected $cookieData;
  protected $query;

  public static function fromEnv() {
    $cookie_data = [];
    if (isset($_COOKIE['webform_tracking'])) {
      $cookie_data = drupal_json_decode($_COOKIE['webform_tracking']);
    }
    return new static($cookie_data, drupal_get_query_parameters());
  }

  public function __construct($cookie_data, $query) {
    $this->cookieData = $cookie_data;
    $this->query = $query;
  }

  protected function getIP() {
    return ip_address();
  }

  protected function getCountry($ip) {
    if (function_exists('geoip_country_code_by_name')) {
      // Use @, see: https://bugs.php.net/bug.php?id=59753
      return @geoip_country_code_by_name($ip);
    }
  }

  public function extractParameters($data) {
    $parameters = static::$parameters;
    foreach (static::$parameters as $name => $default) {
      if ($name != 'tags') {
        if (isset($data[$name]) && !is_array($data[$name])) {
          $parameters[$name] = check_plain($data[$name]);
        }
      }
    }
    if (isset($data['tags']) && is_array($data['tags'])) {
      foreach ($data['tags'] as $t) {
        if (!is_array($t)) {
          $parameters['tags'][] = check_plain($t);
        }
      }
    }
    $parameters['tags'] = serialize(array_unique($parameters['tags']));

    if (!$parameters['user_id']) {
      $parameters['user_id'] = hash('adler32', rand() . microtime());
    }
    $parameters['refsid'] = $parameters['refsid'] ? (int) $parameters['refsid'] : NULL;

    return $parameters;
  }

  protected function urls($cookie_history) {
    $history = [];
    if ($cookie_history) {
      foreach ($cookie_history as $url) {
        if (!is_array($url)) {
          $history[] = check_plain($url);
        }
      }
    }
    if (!$history) {
      $history[] = url(NULL, [
        'absolute' => TRUE,
        'query' => $this->query,
      ]);
    }
    $length = count($history);

    return [
      'entry_url' => $history[0],
      // The only situation when $history should be < 3 appears if the user opens
      // the form directly, in this case referer and form_url are the same.
      'referer'   => $length >= 3 ? $history[$length - 3] : $history[0],
      'form_url'  => $length >= 2 ? $history[$length - 2] : $history[0],
    ];
  }

  public function saveVars($submission) {
    $cookie_data = $this->cookieData + [
      'history' => [],
    ];

    $parameters = $this->extractParameters($cookie_data);

    $ip = $this->getIP();
    $server_data = array(
      'ip_address' => $ip,
      'country' => $this->getCountry($ip),
    );

    $urls = $this->urls($cookie_data['history']);

    $data = array(
      'nid' => $submission->nid,
      'sid' => $submission->sid,
    ) + $urls + $parameters + $server_data;
    $submission->tracking = (object) $data;

    db_insert('webform_tracking')->fields($data)->execute();
    return $parameters['user_id'];
  }
}
