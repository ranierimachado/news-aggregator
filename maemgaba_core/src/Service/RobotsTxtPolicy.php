<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;

/**
 * Decides whether an article page may be fetched, per the site's robots.txt.
 *
 * Acquisition policy: fetch only publicly served
 * pages within robots.txt, with an identified user agent. Implements the
 * RFC 9309 rules the engine needs: groups matched by product token (the
 * part of the User-Agent before "/"), falling back to "*"; longest matching
 * Allow/Disallow path wins, Allow on a tie; "*" and "$" in paths; a 4xx
 * robots.txt means "no restrictions", a 5xx or unreachable one means "fetch
 * nothing" from that host.
 *
 * Off unless maemgaba_core.settings:respect_robots_txt is TRUE. Results are
 * cached per host for a day.
 */
class RobotsTxtPolicy {

  /**
   * Cache lifetime for a host's robots.txt, in seconds.
   */
  protected const TTL = 86400;

  /**
   * Hosts resolved in this request: host => robots.txt body, or NULL = deny.
   *
   * @var array<string, string|null>
   */
  protected array $bodies = [];

  public function __construct(
    protected ClientInterface $httpClient,
    protected CacheBackendInterface $cache,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Whether the policy is switched on for this site.
   */
  public function enabled(): bool {
    return (bool) $this->configFactory->get('maemgaba_core.settings')->get('respect_robots_txt');
  }

  /**
   * Whether $url may be fetched with $userAgent.
   */
  public function allowed(string $url, string $userAgent): bool {
    if (!$this->enabled()) {
      return TRUE;
    }
    $parts = parse_url($url);
    if (empty($parts['host'])) {
      return FALSE;
    }
    $origin = ($parts['scheme'] ?? 'https') . '://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
    $body = $this->robotsBody($origin, $userAgent);
    if ($body === NULL) {
      return FALSE;
    }
    $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    return self::isAllowed($body, self::productToken($userAgent), $path);
  }

  /**
   * Product token of a User-Agent: "ExampleNews/1.0 (+url)" → "examplenews".
   */
  public static function productToken(string $userAgent): string {
    return strtolower((string) preg_replace('/[\/\s].*$/', '', trim($userAgent)));
  }

  /**
   * Pure RFC 9309 evaluation of one robots.txt body.
   *
   * @param string $robots
   *   The robots.txt body.
   * @param string $token
   *   Lower-case product token.
   * @param string $path
   *   URL path (plus query) to test.
   */
  public static function isAllowed(string $robots, string $token, string $path): bool {
    $groups = [];
    $current = NULL;
    $lastWasAgent = FALSE;
    foreach (preg_split('/\r\n|\r|\n/', $robots) as $line) {
      $line = trim((string) preg_replace('/#.*$/', '', $line));
      if ($line === '' || !str_contains($line, ':')) {
        continue;
      }
      [$field, $value] = array_map('trim', explode(':', $line, 2));
      $field = strtolower($field);
      if ($field === 'user-agent') {
        if (!$lastWasAgent) {
          $groups[] = ['agents' => [], 'rules' => []];
          $current = count($groups) - 1;
        }
        $groups[$current]['agents'][] = strtolower($value);
        $lastWasAgent = TRUE;
        continue;
      }
      $lastWasAgent = FALSE;
      if ($current !== NULL && ($field === 'allow' || $field === 'disallow')) {
        $groups[$current]['rules'][] = [$field === 'allow', $value];
      }
    }

    $rules = [];
    foreach ($groups as $group) {
      if (in_array($token, $group['agents'], TRUE)) {
        $rules = array_merge($rules, $group['rules']);
      }
    }
    if (!$rules) {
      foreach ($groups as $group) {
        if (in_array('*', $group['agents'], TRUE)) {
          $rules = array_merge($rules, $group['rules']);
        }
      }
    }

    $best = -1;
    $allowed = TRUE;
    foreach ($rules as [$allow, $pattern]) {
      if ($pattern === '') {
        // "Disallow:" with no path allows everything; it never wins a match.
        continue;
      }
      $regex = '/^' . str_replace(['\*', '\$'], ['.*', '$'], preg_quote($pattern, '/')) . '/';
      if (preg_match($regex, $path)) {
        $length = strlen($pattern);
        if ($length > $best || ($length === $best && $allow)) {
          $best = $length;
          $allowed = $allow;
        }
      }
    }
    return $allowed;
  }

  /**
   * The robots.txt body for an origin: '' = no rules, NULL = fetch nothing.
   */
  protected function robotsBody(string $origin, string $userAgent): ?string {
    if (array_key_exists($origin, $this->bodies)) {
      return $this->bodies[$origin];
    }
    $cid = 'maemgaba_core:robots:' . $origin;
    if ($cached = $this->cache->get($cid)) {
      return $this->bodies[$origin] = $cached->data;
    }

    $body = NULL;
    try {
      $response = $this->httpClient->request('GET', $origin . '/robots.txt', [
        'connect_timeout' => 5,
        'timeout' => 8,
        'headers' => ['User-Agent' => $userAgent],
        'http_errors' => FALSE,
      ]);
      $status = $response->getStatusCode();
      if ($status >= 200 && $status < 300) {
        $body = (string) $response->getBody();
      }
      elseif ($status >= 400 && $status < 500) {
        $body = '';
      }
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('maemgaba_ingestion')->notice('robots.txt unreachable for @origin: @msg', [
        '@origin' => $origin,
        '@msg' => $e->getMessage(),
      ]);
    }

    // An unreachable robots.txt blocks the host, but only for an hour.
    $this->cache->set($cid, $body, time() + ($body === NULL ? 3600 : self::TTL));
    return $this->bodies[$origin] = $body;
  }

}
