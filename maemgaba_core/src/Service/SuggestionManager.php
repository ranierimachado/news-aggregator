<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\user\UserInterface;

/**
 * Reads and writes "suggest a correction" submissions (maemgaba_suggestion).
 *
 * The insert() method is used by the public SuggestionForm; list()/decide()
 * back the moderation queue at /admin/config/services/news-engine/suggestions.
 * decide() is also where the renewal loop lives: accepting a suggestion
 * extends the submitter's maemgaba_search_until by 10 days from whichever
 * is later, now or their current expiry — so a still-active evaluator's
 * trial simply pushes out, it doesn't reset to a shorter window.
 */
class SuggestionManager {

  /**
   * Correction types: stored key => English source label (see typeOptions()).
   *
   * The keys are stored in maemgaba_suggestion.type; never rename them.
   */
  public const TYPES = [
    'classificacao' => 'Wrong classification',
    'agrupamento' => 'Wrong grouping',
    'fato' => 'Factual error',
    'fonte' => 'Source problem',
    'outro' => 'Other',
  ];

  /**
   * Correction types with translated labels, for forms, lists and mail.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Labels keyed by correction type.
   */
  public static function typeOptions(): array {
    // phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString
    return array_map(fn (string $label) => new TranslatableMarkup($label), self::TYPES);
  }

  /**
   * Renewal grant on an accepted suggestion, in seconds.
   */
  protected const RENEWAL_SECONDS = 10 * 86400;

  /**
   * Cache tag invalidated on every decide(), consumed by /metodologia.
   */
  public const CACHE_TAG = 'maemgaba_core:suggestions';

  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected MailManagerInterface $mailManager,
    protected CacheTagsInvalidatorInterface $cacheTagsInvalidator,
  ) {}

  /**
   * Records a new suggestion. Returns its id.
   */
  public function insert(array $values): int {
    $id = (int) $this->database->insert('maemgaba_suggestion')
      ->fields([
        'event_nid' => $values['event_nid'] ?? NULL,
        'type' => $values['type'],
        'body' => $values['body'],
        'url' => empty($values['url']) ? NULL : $values['url'],
        'email' => empty($values['email']) ? NULL : $values['email'],
        'credit_name' => empty($values['credit_name']) ? NULL : $values['credit_name'],
        'uid' => empty($values['uid']) ? NULL : $values['uid'],
        'ip' => $values['ip'],
        'status' => 'pending',
        'created' => $this->time->getRequestTime(),
      ])
      ->execute();
    $this->cacheTagsInvalidator->invalidateTags([self::CACHE_TAG]);
    return $id;
  }

  /**
   * Loads one suggestion row, or NULL if it doesn't exist.
   */
  public function load(int $id): ?object {
    return $this->database->select('maemgaba_suggestion', 's')
      ->fields('s')
      ->condition('id', $id)
      ->execute()
      ->fetchObject() ?: NULL;
  }

  /**
   * Lists suggestions, most recent first.
   *
   * @param string $status
   *   One of 'pending', 'accepted', 'rejected', or 'all'.
   */
  public function list(string $status = 'pending'): array {
    $query = $this->database->select('maemgaba_suggestion', 's')->fields('s');
    if ($status !== 'all') {
      $query->condition('status', $status);
    }
    $query->orderBy('created', 'DESC');
    return $query->execute()->fetchAll();
  }

  /**
   * Names credited on accepted suggestions, for the /metodologia page.
   */
  public function acceptedCreditNames(int $limit = 100): array {
    return $this->database->select('maemgaba_suggestion', 's')
      ->fields('s', ['credit_name'])
      ->condition('status', 'accepted')
      ->isNotNull('credit_name')
      ->condition('credit_name', '', '<>')
      ->distinct()
      ->orderBy('decided', 'DESC')
      ->range(0, $limit)
      ->execute()
      ->fetchCol();
  }

  /**
   * Accepts or rejects a pending suggestion.
   *
   * On acceptance: renews the submitter's /busca access (matched by uid,
   * falling back to their submitted email against an existing account) and
   * emails them if an address is known. No-ops (returns FALSE) if the
   * suggestion isn't pending — decisions aren't revisable from this method.
   */
  public function decide(int $id, string $decision, string $note, int $decidedUid): bool {
    $row = $this->load($id);
    if (!$row || $row->status !== 'pending') {
      return FALSE;
    }

    $now = $this->time->getRequestTime();
    $row->status = $decision === 'accept' ? 'accepted' : 'rejected';
    $row->moderation_note = $note ?: NULL;
    $row->decided = $now;
    $row->decided_uid = $decidedUid;

    $this->database->update('maemgaba_suggestion')
      ->fields([
        'status' => $row->status,
        'moderation_note' => $row->moderation_note,
        'decided' => $row->decided,
        'decided_uid' => $row->decided_uid,
      ])
      ->condition('id', $id)
      ->execute();

    if ($decision === 'accept') {
      $this->renewAccess($row, $now);
    }

    $this->cacheTagsInvalidator->invalidateTags([self::CACHE_TAG]);
    return TRUE;
  }

  /**
   * Extends the submitter's search access and emails them, if resolvable.
   */
  protected function renewAccess(object $row, int $now): void {
    $user = $this->resolveUser($row);
    $renewed = FALSE;
    if ($user) {
      $until = (int) $user->get('maemgaba_search_until')->value;
      $user->set('maemgaba_search_until', max($now, $until) + self::RENEWAL_SECONDS)->save();
      $renewed = TRUE;
    }

    $to = $user?->getEmail() ?: $row->email;
    if ($to) {
      $this->mailManager->mail('maemgaba_core', 'suggestion_accepted', $to, \Drupal::languageManager()->getDefaultLanguage()->getId(), [
        'suggestion' => $row,
        'renewed' => $renewed,
      ]);
    }
  }

  /**
   * Resolves the account to credit: the submitting uid, or a mail match.
   */
  protected function resolveUser(object $row): ?UserInterface {
    $storage = $this->entityTypeManager->getStorage('user');
    if ($row->uid) {
      $user = $storage->load($row->uid);
      if ($user instanceof UserInterface) {
        return $user;
      }
    }
    if ($row->email) {
      $matches = $storage->loadByProperties(['mail' => $row->email]);
      if ($matches) {
        return reset($matches);
      }
    }
    return NULL;
  }

}
