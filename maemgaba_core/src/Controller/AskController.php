<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\Core\Site\Settings;
use Drupal\Core\Url;
use Drupal\maemgaba_core\Ask\AskService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The /ask page: "Ask the data".
 *
 * Questions arrive by POST only, so a crawler following links never
 * triggers a model call; the example chips are submit buttons. A GET with
 * ?q= only fills in the box. The page is never cached. Anyone without the
 * admin permission is held to a per-IP flood limit (ask.flood), counted
 * before the model is called and only on cache misses. The flood table gets
 * an HMAC of the IP (keyed with the site's hash salt), never the IP itself.
 * The route answers 404 unless maemgaba_core.settings:ask.enabled is TRUE.
 */
class AskController extends ControllerBase {

  /**
   * Flood event name.
   */
  public const FLOOD_EVENT = 'maemgaba_core.ask';

  public function __construct(
    protected AskService $ask,
    protected FloodInterface $flood,
    protected KillSwitch $pageCacheKillSwitch,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('maemgaba_core.ask'),
      $container->get('flood'),
      $container->get('page_cache_kill_switch'),
    );
  }

  /**
   * Renders the page and, for a POSTed question, its answer.
   */
  public function page(Request $request): array {
    if (!$this->ask->enabled()) {
      throw new NotFoundHttpException();
    }
    $this->pageCacheKillSwitch->trigger();
    $settings = $this->ask->settings();

    $question = '';
    $result = NULL;
    $state = 'intro';
    $build = [
      '#theme' => 'ask_page',
      '#cache' => ['max-age' => 0],
    ];

    if ($request->isMethod('POST')) {
      $question = $this->ask->normalize((string) ($request->request->get('example') ?: $request->request->get('q', '')));
    }
    else {
      $question = $this->ask->normalize((string) $request->query->get('q', ''));
    }

    if ($request->isMethod('POST') && $question !== '') {
      $result = $this->ask->cached($question);
      if (!$result) {
        if (!$this->ask->available()) {
          $result = $this->ask->answer($question);
        }
        elseif (!$this->floodAllows($request, $settings['flood'])) {
          $result = $this->ask->rateLimited($question);
          $build['#attached']['http_header'][] = ['Status', 429];
          $build['#attached']['http_header'][] = ['Retry-After', (string) $settings['flood']['window']];
        }
        else {
          $result = $this->ask->answer($question);
        }
      }
      $state = $result['outcome'];
    }

    $build += [
      '#state' => $state,
      '#question' => $question,
      '#examples' => array_values(array_filter((array) $settings['examples'])),
      '#result' => $result ? $this->present($result) : NULL,
      '#max_length' => $settings['max_question_length'],
      '#max_rows' => $settings['max_rows'],
      '#form_action' => Url::fromRoute('maemgaba_core.ask')->toString(),
      '#methodology_url' => Url::fromRoute('maemgaba_core.methodology', [], ['fragment' => 'ask'])->toString(),
      '#flood_limit' => $settings['flood']['limit'],
      '#flood_minutes' => (int) ceil($settings['flood']['window'] / 60),
    ];
    $build['#attached']['library'][] = 'maemgaba_core/engine';
    return $build;
  }

  /**
   * The parts of a result the template may show (no log detail).
   */
  protected function present(array $result): array {
    $single = count($result['columns']) === 1 && count($result['rows']) === 1;
    return [
      'outcome' => $result['outcome'],
      'reason' => $result['reason'],
      'sentence' => $result['sentence'],
      'explanation' => $result['explanation'],
      'sql' => $result['sql'],
      'columns' => $result['columns'],
      'rows' => array_map(fn (array $row) => array_map(fn ($value) => $value === NULL ? '—' : (string) $value, $row), $result['rows']),
      'row_count' => (int) $result['row_count'],
      'row_cap' => $this->ask->settings()['max_rows'],
      'single_value' => $single ? (string) ($result['rows'][0][0] ?? '') : NULL,
      'cached' => $result['cached'],
      'latency_s' => number_format($result['latency_ms'] / 1000, 1),
      'model' => $result['model'],
      'refusal' => $this->refusalMessage($result),
    ];
  }

  /**
   * A friendly line for a refused or failed question.
   */
  protected function refusalMessage(array $result): string {
    return (string) match ($result['outcome']) {
      'refused_model' => $result['explanation'] !== ''
        ? $this->t('The assistant could not turn this into a question about the data: @why', ['@why' => $result['explanation']])
        : $this->t('The assistant could not turn this into a question about the data.'),
      'refused_validator' => $this->t('The query the assistant wrote was blocked by the safety check (@reason), so nothing was run. Try asking in a different way.', ['@reason' => str_replace('_', ' ', $result['reason'])]),
      'unavailable' => $this->t('Ask the data is switched off for maintenance right now. Please try again later.'),
      'rate_limited' => $this->t('You have asked a lot of questions in a short time. Please wait a while and try again.'),
      'error' => $result['reason'] === 'timeout'
        ? $this->t('That question took too long to answer, so it was stopped. Try narrowing it down, for example to one week or one outlet.')
        : $this->t('Something went wrong while answering. Please try again, or ask in a different way.'),
      default => '',
    };
  }

  /**
   * Registers one uncached question against the per-IP flood limit.
   */
  protected function floodAllows(Request $request, array $flood): bool {
    if ($this->currentUser()->hasPermission('administer news engine semantic search')) {
      return TRUE;
    }
    $identifier = hash_hmac('sha256', (string) $request->getClientIp(), Settings::getHashSalt());
    $limit = max(1, (int) $flood['limit']);
    $window = max(1, (int) $flood['window']);
    if (!$this->flood->isAllowed(self::FLOOD_EVENT, $limit, $window, $identifier)) {
      return FALSE;
    }
    $this->flood->register(self::FLOOD_EVENT, $window, $identifier);
    return TRUE;
  }

}
