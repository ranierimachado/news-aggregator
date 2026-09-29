<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Migration;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Decodes HTML entities that drupal/ai's HostnameFilter wrote into model text.
 *
 * Before v0.8.1 every chat response went through the filter, which parses the
 * text as HTML and serializes it back, so "Q&A" was stored as "Q&amp;A" and
 * Twig escaped it again on output. This decodes (ENT_QUOTES | ENT_HTML5) the
 * model-written fields and event/card titles, only for values that contain
 * &amp; &lt; &gt; &quot; or &#039;, and saves through the Entity API so the
 * search indexes pick the new text up.
 *
 * The saves are marked as syncing (the "changed" time stays) and update the
 * current revision instead of adding one. The two summaries are basic_html,
 * so a value whose decoded form would contain a tag ("&lt;b&gt;" → "<b>") is
 * left alone and reported as skipped. Idempotent: a second run finds nothing.
 *
 * Runs from maemgaba_core_deploy_model_text_decode().
 */
class ModelTextDecode {

  /**
   * Targets: field name => [bundles, is HTML (text format) field].
   *
   * 'title' is the node base field; the rest are field tables.
   */
  public const TARGETS = [
    'title' => [['event', 'card'], FALSE],
    'field_neutral_summary' => [['event'], TRUE],
    'field_common_points' => [['event'], FALSE],
    'field_disputed_points' => [['event'], FALSE],
    'field_framing_line' => [['card'], FALSE],
    'field_micro_summary' => [['card'], TRUE],
  ];

  /**
   * The encoded forms the filter produces.
   */
  public const ENTITIES = ['&amp;', '&lt;', '&gt;', '&quot;', '&#039;'];

  public function __construct(
    protected Connection $database,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Counts stored values that still contain one of the entities.
   *
   * @return array<string, int>
   *   Values (field items, or title rows) per target that exists on the site.
   */
  public function count(): array {
    $counts = [];
    foreach (self::TARGETS as $field => [$bundles]) {
      $query = $this->query($field, $bundles);
      if ($query) {
        $counts[$field] = (int) $query->countQuery()->execute()->fetchField();
      }
    }
    return $counts;
  }

  /**
   * Decodes and saves every affected node.
   *
   * @return array
   *   ['before' => counts, 'after' => counts, 'nodes' => nodes saved,
   *    'values' => values decoded per target, 'skipped' => ["nid field"]].
   */
  public function run(): array {
    $result = [
      'before' => $this->count(),
      'nodes' => 0,
      'values' => [],
      'skipped' => [],
    ];
    $ids = [];
    foreach (self::TARGETS as $field => [$bundles]) {
      $query = $this->query($field, $bundles);
      if ($query) {
        $ids += array_flip($query->execute()->fetchCol());
      }
    }
    $ids = array_keys($ids);
    sort($ids);

    $storage = $this->entityTypeManager->getStorage('node');
    foreach (array_chunk($ids, 50) as $chunk) {
      foreach ($storage->loadMultiple($chunk) as $node) {
        if ($this->decodeNode($node, $result)) {
          $node->setSyncing(TRUE);
          $node->setNewRevision(FALSE);
          $node->save();
          $result['nodes']++;
        }
      }
      $storage->resetCache($chunk);
    }
    $result['after'] = $this->count();
    return $result;
  }

  /**
   * Decodes one entity-encoded string, or returns NULL to leave it alone.
   *
   * @param string $value
   *   The stored value.
   * @param bool $html
   *   TRUE for a text-format field, where a decoded "<tag" would be markup.
   */
  public static function decode(string $value, bool $html): ?string {
    if (str_replace(self::ENTITIES, '', $value) === $value) {
      return NULL;
    }
    $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($html && preg_match('#<[a-zA-Z/!?]#', $decoded) && !preg_match('#<[a-zA-Z/!?]#', $value)) {
      return NULL;
    }
    return $decoded === $value ? NULL : $decoded;
  }

  /**
   * Decodes the targeted values on every translation of a node.
   *
   * @return bool
   *   TRUE when something changed and the node needs saving.
   */
  protected function decodeNode(NodeInterface $node, array &$result): bool {
    $changed = FALSE;
    foreach ($node->getTranslationLanguages() as $langcode => $language) {
      $translation = $node->getTranslation($langcode);
      foreach (self::TARGETS as $field => [$bundles, $html]) {
        if (!in_array($node->bundle(), $bundles, TRUE) || !$translation->hasField($field)) {
          continue;
        }
        foreach ($translation->get($field) as $item) {
          $value = (string) $item->value;
          $decoded = self::decode($value, $html);
          if ($decoded !== NULL) {
            $item->value = $decoded;
            $result['values'][$field] = ($result['values'][$field] ?? 0) + 1;
            $changed = TRUE;
          }
          elseif (str_replace(self::ENTITIES, '', $value) !== $value) {
            $result['skipped'][] = $node->id() . ' ' . $field;
          }
        }
      }
    }
    return $changed;
  }

  /**
   * Selects the rows of one target whose value contains an entity.
   *
   * @return \Drupal\Core\Database\Query\SelectInterface|null
   *   Selects entity_id (DISTINCT is left to the caller), or NULL when the
   *   field does not exist on this site.
   */
  protected function query(string $field, array $bundles) {
    if ($field === 'title') {
      $query = $this->database->select('node_field_data', 't')->fields('t', ['nid']);
      $query->condition('t.type', $bundles, 'IN');
      $column = 't.title';
    }
    else {
      $table = "node__{$field}";
      if (!$this->database->schema()->tableExists($table)) {
        return NULL;
      }
      $query = $this->database->select($table, 't')->fields('t', ['entity_id']);
      $query->condition('t.bundle', $bundles, 'IN');
      $query->condition('t.deleted', 0);
      $column = "t.{$field}_value";
    }
    $any = $query->orConditionGroup();
    foreach (self::ENTITIES as $entity) {
      $any->condition($column, '%' . $this->database->escapeLike($entity) . '%', 'LIKE');
    }
    return $query->condition($any);
  }

}
