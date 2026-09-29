<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\Dto\HostnameFilterDto;
use Drupal\ai\Dto\StructuredOutputSchema;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\maemgaba_core\Entity\AiPromptInterface;
use Drupal\maemgaba_core\Service\AiCallLogger;
use Drupal\maemgaba_core\Service\OutletRedactor;
use Drupal\maemgaba_core\Service\RubricService;
use Drupal\maemgaba_core\Service\SpectrumService;
use Drupal\maemgaba_core\Service\TopicService;
use Drupal\maemgaba_core\Service\VerbatimOverlapGuard;

/**
 * Classifies and clusters news articles through the Drupal AI abstraction.
 *
 * The model call is provider-agnostic: each operation resolves its
 * provider/model from the maemgaba_core.settings:operation_models map (so
 * bulk triage can run on a cheap/local model while synthesis stays on a
 * frontier one), falling back to the site-wide default chat provider
 * (ai.settings), via the ai.provider plugin manager. Prompts are
 * editor-managed maemgaba_prompt config entities, so wording can change
 * without code edits.
 *
 * Every provider attempt — success or failure, including internal retries —
 * is logged to maemgaba_ai_call_log via AiCallLogger (Phase 1: evals &
 * observability), so pipeline, consensus, backfill and eval runs all leave
 * a measurable trail that matches what the provider actually saw.
 */
class AiAnalyzerService {

  /**
   * Operation key used to look up the prompt.
   */
  protected const OPERATION = 'classify_cluster';

  /**
   * Attempts (1 initial + retries) for a transient chat failure.
   */
  protected const MAX_ATTEMPTS = 3;

  /**
   * Fallback chars-per-token ratio when a provider reports no usage.
   */
  protected const ESTIMATE_CHARS_PER_TOKEN = 4;

  /**
   * Max common/disputed points kept per consensus synthesis.
   */
  protected const CONSENSUS_MAX_POINTS = 4;

  /**
   * Operations preflight() can probe, in pipeline order.
   */
  public const PREFLIGHT_OPERATIONS = [
    'classify_cluster',
    'classify_bias',
    'relevance',
    'review_cards',
    'synthesize_consensus',
    'ask_sql',
    'ask_answer',
  ];

  /**
   * Operations of "Ask the data" (off unless maemgaba_core.settings:ask).
   */
  public const ASK_OPERATIONS = ['ask_sql', 'ask_answer'];

  /**
   * Attempts for the interactive ask operations (a visitor is waiting).
   */
  protected const ASK_ATTEMPTS = 2;

  /**
   * Error message of the last failed chat() attempt, for preflight reports.
   */
  protected string $lastError = '';

  /**
   * The call_group of the last chat() call, to price it from the call log.
   */
  protected string $lastCallGroup = '';

  public function __construct(
    protected AiProviderPluginManager $aiProvider,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected AiCallLogger $callLogger,
    protected ConfigFactoryInterface $configFactory,
    protected TopicService $topics,
    protected SpectrumService $spectrum,
    protected RubricService $rubric,
    protected OutletRedactor $redactor,
    protected VerbatimOverlapGuard $overlapGuard,
  ) {}

  /**
   * Classifies an article's bias and clusters it into a factual event.
   *
   * @param string $articleText
   *   The cleaned article body.
   * @param array $existingEvents
   *   Map of candidate event id => ['title' => string, 'similarity' =>
   *   float|null], for clustering context. 'similarity' is NULL for the
   *   recency-window candidate source and a 0-1 score (higher = closer) for
   *   the vector-retrieval source — see QueueProcessor::processBatch() and
   *   EventCandidateFinder.
   * @param \Drupal\maemgaba_core\Entity\AiPromptInterface|null $promptOverride
   *   Use this prompt entity instead of the enabled one for the operation.
   *   Lets the eval runner replay the golden set through a specific prompt
   *   version without changing which one is "enabled" in the UI.
   * @param array $context
   *   Optional business-context attribution for the call log — keys 'type'
   *   (inbound_queue|card|event|eval), 'id' (entity id) and 'label' (title).
   *   Lets cost reports tie tokens/cost back to the object being analyzed.
   * @param array $source
   *   Optional article metadata — 'headline' (fills [headline]) and 'outlet'
   *   (redacted from the inputs when the prompt is blind; never sent).
   *
   * @return array
   *   Decoded model response, or an empty array on failure. Keys:
   *   matched_event_id (int, 0 = new), bias_score (int −2..+2 or NULL),
   *   bias (derived legacy left/center/right), bias_confidence (0..1|null),
   *   bias_evidence (string[]), framing_line (string, '' if rejected),
   *   partial_text (bool, computed from the body length — see
   *   isPartialText()), micro_summary, event_title, neutral_summary,
   *   social_relevance, topic, relevance_reason.
   */
  public function classifyAndCluster(string $articleText, array $existingEvents, ?AiPromptInterface $promptOverride = NULL, array $context = [], array $source = []): array {
    $logger = $this->loggerFactory->get('maemgaba_ai');

    $prompt = $promptOverride ?? $this->getPrompt(self::OPERATION);
    if (!$prompt) {
      $logger->error('No enabled AI prompt found for operation @op.', ['@op' => self::OPERATION]);
      $this->callLogger->log(['operation' => self::OPERATION, 'error_message' => 'No enabled prompt for operation.'] + $this->contextFields($context));
      return [];
    }

    $eventsContext = '';
    foreach ($existingEvents as $id => $candidate) {
      $eventsContext .= $this->fragment('candidate_line', ['id' => $id, 'title' => $candidate['title']]);
      if (isset($candidate['similarity'])) {
        $eventsContext .= $this->fragment('candidate_similarity', ['similarity' => sprintf('%.2f', $candidate['similarity'])]);
      }
      $eventsContext .= "\n";
    }
    $eventsContext = $eventsContext ?: $this->fragment('no_candidates');

    $userPrompt = $this->renderPrompt($prompt, [
      'existing_events' => $eventsContext,
    ] + $this->sourceTokens($prompt, $articleText, $source));

    $default = $this->resolveProvider(self::OPERATION);
    if (empty($default['provider_id']) || empty($default['model_id'])) {
      $logger->error('No AI provider resolved for operation @op (maemgaba_core.settings:operation_models or ai.settings:default_providers.chat).', ['@op' => self::OPERATION]);
      $this->callLogger->log([
        'operation' => self::OPERATION,
        'prompt_config_id' => $prompt->id(),
        'error_message' => 'No provider resolved for operation.',
      ] + $this->contextFields($context));
      return [];
    }

    $json = $this->chat(
      $default['provider_id'],
      $default['model_id'],
      $this->systemPrompt($prompt),
      $userPrompt,
      $this->responseSchema(),
      'classification',
      self::OPERATION,
      $prompt->id(),
      $context,
    );

    if ($json === '') {
      return [];
    }

    $data = json_decode($json, TRUE);
    if (!is_array($data)) {
      return [];
    }
    $data = $this->refineBiasTopic($this->normalizeBias($data), $articleText, $context, $source);
    return $this->finalizeArticleOutput($data, $articleText);
  }

  /**
   * Classifies bias only, through a given prompt (calibration harness).
   *
   * Same inputs and post-processing as the pipeline's classify pass, with
   * the bias/topic schema, routed like classify_bias (falling back to the
   * site default when that operation is unrouted).
   *
   * @return array
   *   Keys as classifyAndCluster()'s bias part (bias_score, bias,
   *   bias_confidence, bias_evidence, framing_line, partial_text, topic),
   *   or [] on failure.
   */
  public function classifyBias(string $articleText, AiPromptInterface $prompt, array $source = [], array $context = []): array {
    $route = $this->resolveProvider('classify_bias');
    if (empty($route['provider_id']) || empty($route['model_id'])) {
      return [];
    }
    $json = $this->chat(
      $route['provider_id'],
      $route['model_id'],
      $this->systemPrompt($prompt),
      $this->renderPrompt($prompt, $this->sourceTokens($prompt, $articleText, $source)),
      $this->biasTopicSchema(),
      'bias_topic',
      $prompt->getOperation(),
      $prompt->id(),
      $context,
    );
    $data = $json !== '' ? json_decode($json, TRUE) : NULL;
    return is_array($data) ? $this->finalizeArticleOutput($this->normalizeBias($data), $articleText) : [];
  }

  /**
   * Whether an article body is too short to read framing from.
   *
   * Driven by maemgaba_core.settings:partial_text.min_words (0 or unset =
   * never partial). Ingest stores the feed abstract as the body when the
   * page is paywalled, disallowed or unparseable (IngestionEngine), so a
   * short body is exactly the "headline + abstract only" case.
   */
  public function isPartialText(string $articleText): bool {
    $min = (int) $this->configFactory->get('maemgaba_core.settings')->get('partial_text.min_words');
    return $min > 0 && TextStats::wordCount($articleText) < $min;
  }

  /**
   * Prompt tokens describing the article, shared by every article pass.
   *
   * Blind prompts get the outlet's identity redacted from the body and the
   * headline (OutletRedactor); the outlet itself never becomes a token.
   */
  public function sourceTokens(AiPromptInterface $prompt, string $articleText, array $source = []): array {
    $headline = (string) ($source['headline'] ?? '');
    $outlet = (string) ($source['outlet'] ?? '');
    $partial = $this->isPartialText($articleText);
    if ($prompt->isBlind() && $outlet !== '') {
      $articleText = $this->redactor->redact($articleText, $outlet);
      $headline = $this->redactor->redact($headline, $outlet);
    }
    return [
      'article_text' => $articleText,
      'headline' => $headline,
      'text_status' => $this->fragment($partial ? 'partial_text_notice' : 'full_text_notice', [
        'max_confidence' => sprintf('%.1f', $this->partialMaxConfidence()),
      ]),
      'anchors' => $prompt->renderAnchors($this->scoreLabels()),
    ];
  }

  /**
   * Applies the site's caps and policy checks to an article-level answer.
   *
   * - partial_text is recomputed from the body, never trusted from the
   *   model; partial answers get bias_confidence capped.
   * - Evidence quotes are capped at evidence_max_words words each.
   * - framing_line must be a paraphrase: blanked if it contains a quotation
   *   or (overlap guard on) a copied run from the article; capped at
   *   framing_line_max_words.
   */
  protected function finalizeArticleOutput(array $data, string $articleText): array {
    $settings = $this->configFactory->get('maemgaba_core.settings');
    $data['partial_text'] = $this->isPartialText($articleText);
    if ($data['partial_text'] && isset($data['bias_confidence'])) {
      $data['bias_confidence'] = min((float) $data['bias_confidence'], $this->partialMaxConfidence());
    }

    $maxEvidenceWords = (int) $settings->get('evidence_max_words');
    if ($maxEvidenceWords > 0 && !empty($data['bias_evidence'])) {
      $data['bias_evidence'] = array_map(fn ($q) => TextStats::capWords($q, $maxEvidenceWords), $data['bias_evidence']);
    }

    $framing = strip_tags((string) ($data['framing_line'] ?? ''));
    $framing = trim((string) preg_replace('/^[\s"\'“”‘’]+|[\s"\'“”‘’]+$/u', '', $framing));
    if ($framing !== '' && self::quotesAtLeast($framing, 4)) {
      $this->loggerFactory->get('maemgaba_ai')->notice('framing_line dropped: it quotes the article.');
      $framing = '';
    }
    if ($framing !== '' && $this->overlapGuard->enabled()) {
      $run = $this->overlapGuard->check([$framing], [$articleText]);
      if ($run !== NULL) {
        $this->loggerFactory->get('maemgaba_ai')->notice('framing_line dropped: copies "@run" from the article.', ['@run' => $run]);
        $framing = '';
      }
    }
    $maxFramingWords = (int) $settings->get('framing_line_max_words');
    $data['framing_line'] = $maxFramingWords > 0 ? TextStats::capWords($framing, $maxFramingWords) : $framing;
    return $data;
  }

  /**
   * Whether $text contains a quotation of at least $words words.
   *
   * Short quoted names ("the “Big Beautiful Bill”") are allowed; a quoted
   * clause is not, since the framing line is shown to readers and must be a
   * paraphrase.
   */
  public static function quotesAtLeast(string $text, int $words): bool {
    preg_match_all('/["“]([^"“”]+)["”]/u', $text, $m);
    foreach ($m[1] as $quoted) {
      if (TextStats::wordCount($quoted) >= $words) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Confidence cap for partial-text answers (default 0.5).
   */
  protected function partialMaxConfidence(): float {
    $cap = $this->configFactory->get('maemgaba_core.settings')->get('partial_text.max_confidence');
    return is_numeric($cap) ? max(0.0, min(1.0, (float) $cap)) : 0.5;
  }

  /**
   * Map of score => display bucket label, for anchor lines.
   */
  protected function scoreLabels(): array {
    $labels = [];
    for ($score = BiasScore::MIN; $score <= BiasScore::MAX; $score++) {
      $labels[$score] = (string) ($this->spectrum->bucketFor($score)['label'] ?? '');
    }
    return $labels;
  }

  /**
   * Optionally re-classifies bias/topic with a dedicated second call.
   *
   * Split-model flow (Phase 6): when operation_models.classify_bias routes
   * to a provider, the framing-sensitive fields (bias, topic) are
   * re-derived from the full article text by that model — typically a
   * frontier model — and overwrite the primary call's values, while the
   * primary (typically local/cheap) model keeps clustering, summaries and
   * relevance. Measured rationale: on the balanced golden slice gemini
   * leads on bias (83.3% vs 68.3%) and topic (95.0% vs 85.0%) while
   * gemma3:27b leads on relevance — see 99-metrics-log.md, 2026-08-20.
   *
   * Fails soft: with no route, no enabled 'classify_bias' prompt, or a
   * failed call, the primary model's bias/topic stand — a degraded answer
   * beats a skipped article. The second call must happen while the raw
   * article text still exists (it is deleted with the queue item), which
   * is why this lives here and not in a later backfill.
   */
  protected function refineBiasTopic(array $data, string $articleText, array $context, array $source = []): array {
    $route = $this->configFactory->get('maemgaba_core.settings')->get('operation_models.classify_bias');
    if (empty($route['provider']) || empty($route['model'])) {
      return $data;
    }

    $logger = $this->loggerFactory->get('maemgaba_ai');
    $prompt = $this->getPrompt('classify_bias');
    if (!$prompt) {
      $logger->warning('operation_models.classify_bias is routed but no enabled classify_bias prompt exists — keeping the primary model\'s bias/topic.');
      return $data;
    }

    $json = $this->chat(
      $route['provider'],
      $route['model'],
      $this->systemPrompt($prompt),
      $this->renderPrompt($prompt, $this->sourceTokens($prompt, $articleText, $source)),
      $this->biasTopicSchema(),
      'bias_topic',
      'classify_bias',
      $prompt->id(),
      $context,
    );

    if ($json === '') {
      $logger->warning('classify_bias refinement call failed — keeping the primary model\'s bias/topic.');
      return $data;
    }

    $refined = json_decode($json, TRUE);
    if (is_array($refined)) {
      $refined = $this->normalizeBias($refined);
      if ($refined['bias_score'] !== NULL) {
        foreach (['bias_score', 'bias', 'bias_confidence', 'bias_evidence'] as $key) {
          $data[$key] = $refined[$key];
        }
        if (!empty($refined['framing_line'])) {
          $data['framing_line'] = $refined['framing_line'];
        }
      }
      if (!empty($refined['topic'])) {
        $data['topic'] = $refined['topic'];
      }
    }
    return $data;
  }

  /**
   * Normalizes the bias part of a model response.
   *
   * The bias_score value is clamped to −2..+2 (NULL if missing/non-integer),
   * bias_confidence to 0..1, bias_evidence to BiasScore::MAX_EVIDENCE quotes
   * (caps enforced here: the schema can't carry them portably). 'bias' is
   * the derived legacy left/center/right value, kept for the one-release
   * list field and for golden-set evals scored against it.
   */
  protected function normalizeBias(array $data): array {
    $data['bias_score'] = BiasScore::fromModel($data['bias_score'] ?? NULL);
    $data['bias'] = $data['bias_score'] !== NULL ? BiasScore::toLegacy($data['bias_score']) : NULL;
    $data['bias_confidence'] = BiasScore::normalizeConfidence($data['bias_confidence'] ?? NULL);
    $data['bias_evidence'] = BiasScore::normalizeEvidence($data['bias_evidence'] ?? NULL);
    return $data;
  }

  /**
   * Classifies only social relevance, topic and reason for a single card.
   *
   * Lightweight pass used to backfill/re-tag existing cards from their title
   * and summary (the raw article text is gone once the queue item is deleted).
   *
   * @param string $title
   *   The card title.
   * @param string $summary
   *   The card's micro summary.
   * @param \Drupal\maemgaba_core\Entity\AiPromptInterface|null $promptOverride
   *   Use this prompt entity instead of the enabled one for 'relevance'.
   * @param array $context
   *   Optional business-context attribution — see classifyAndCluster().
   *
   * @return array
   *   Keys: social_relevance, topic, relevance_reason — or [] on failure.
   */
  public function classifyRelevance(string $title, string $summary, ?AiPromptInterface $promptOverride = NULL, array $context = []): array {
    $logger = $this->loggerFactory->get('maemgaba_ai');

    $prompt = $promptOverride ?? $this->getPrompt('relevance');
    if (!$prompt) {
      $logger->error('No enabled AI prompt found for operation @op.', ['@op' => 'relevance']);
      $this->callLogger->log(['operation' => 'relevance', 'error_message' => 'No enabled prompt for operation.'] + $this->contextFields($context));
      return [];
    }

    $userPrompt = $this->renderPrompt($prompt, [
      'card_title' => $title,
      'card_summary' => $summary,
    ]);

    $default = $this->resolveProvider('relevance');
    if (empty($default['provider_id']) || empty($default['model_id'])) {
      $logger->error('No AI provider resolved for operation @op (maemgaba_core.settings:operation_models or ai.settings:default_providers.chat).', ['@op' => 'relevance']);
      $this->callLogger->log([
        'operation' => 'relevance',
        'prompt_config_id' => $prompt->id(),
        'error_message' => 'No provider resolved for operation.',
      ] + $this->contextFields($context));
      return [];
    }

    $json = $this->chat(
      $default['provider_id'],
      $default['model_id'],
      $this->systemPrompt($prompt),
      $userPrompt,
      $this->relevanceSchema(),
      'relevance',
      'relevance',
      $prompt->id(),
      $context,
    );

    if ($json === '') {
      return [];
    }

    $data = json_decode($json, TRUE);
    return is_array($data) ? $data : [];
  }

  /**
   * Re-reviews a single already-published card's bias/topic.
   *
   * Second-layer audit (Phase 6 Step B): unlike classifyAndCluster(), this
   * reads ONLY node data — the raw article text is gone once the queue item
   * that created the card is deleted. It shows the model the card's CURRENT
   * bias/topic (anchored review) and asks it to confirm or correct them;
   * measured to beat a blind (no-current-labels) review on this same task —
   * see 99-metrics-log.md, 2026-08-20 ("gemma3-solo + Gemini node-review
   * layer"). Intended to run a stronger model over the output of a cheaper
   * primary classification.
   *
   * @param string $title
   *   The card title.
   * @param string $summary
   *   The card's micro summary.
   * @param int|null $currentScore
   *   The card's current field_bias_score (NULL if unscored).
   * @param string $currentTopic
   *   The card's current field_topic value.
   * @param \Drupal\maemgaba_core\Entity\AiPromptInterface|null $promptOverride
   *   Use this prompt entity instead of the enabled one for 'review_cards'.
   * @param array $context
   *   Optional business-context attribution — see classifyAndCluster().
   *
   * @return array
   *   Keys: bias_score (int|null), bias (legacy), bias_confidence,
   *   bias_evidence, topic — or [] on failure.
   */
  public function reviewCard(string $title, string $summary, ?int $currentScore, string $currentTopic, ?AiPromptInterface $promptOverride = NULL, array $context = []): array {
    $logger = $this->loggerFactory->get('maemgaba_ai');

    $prompt = $promptOverride ?? $this->getPrompt('review_cards');
    if (!$prompt) {
      $logger->error('No enabled AI prompt found for operation @op.', ['@op' => 'review_cards']);
      $this->callLogger->log(['operation' => 'review_cards', 'error_message' => 'No enabled prompt for operation.'] + $this->contextFields($context));
      return [];
    }

    $userPrompt = $this->renderPrompt($prompt, [
      'card_title' => $title,
      'card_summary' => $summary,
      'current_bias_score' => $currentScore !== NULL ? sprintf('%+d', $currentScore) : '?',
      'current_bias' => $currentScore !== NULL ? BiasScore::toLegacy($currentScore) : '',
      'current_topic' => $currentTopic,
    ]);

    $default = $this->resolveProvider('review_cards');
    if (empty($default['provider_id']) || empty($default['model_id'])) {
      $logger->error('No AI provider resolved for operation @op (maemgaba_core.settings:operation_models or ai.settings:default_providers.chat).', ['@op' => 'review_cards']);
      $this->callLogger->log([
        'operation' => 'review_cards',
        'prompt_config_id' => $prompt->id(),
        'error_message' => 'No provider resolved for operation.',
      ] + $this->contextFields($context));
      return [];
    }

    $json = $this->chat(
      $default['provider_id'],
      $default['model_id'],
      $this->systemPrompt($prompt),
      $userPrompt,
      $this->biasTopicSchema(),
      'bias_topic',
      'review_cards',
      $prompt->id(),
      $context,
    );

    if ($json === '') {
      return [];
    }

    $data = json_decode($json, TRUE);
    return is_array($data) ? $this->normalizeBias($data) : [];
  }

  /**
   * Synthesises an event's consensus (common) and disputed points.
   *
   * A per-event pass distinct from the per-article classifier: it reads how
   * every source framed the same fact and separates what they agree on from
   * what they contest. Only run for the featured ("Destaque") event.
   *
   * @param string $eventTitle
   *   The event's factual title.
   * @param string $neutralSummary
   *   The event's neutral summary.
   * @param string $perspectives
   *   Pre-formatted block of "bias · source · summary" lines for every card.
   * @param array $context
   *   Optional business-context attribution — see classifyAndCluster().
   *
   * @return array
   *   Keys: common_points (string[]), disputed_points (string[]), and
   *   neutral_summary (string) when synthesis_neutral_summary is on — or []
   *   on failure.
   */
  public function synthesizeConsensus(string $eventTitle, string $neutralSummary, string $perspectives, array $context = []): array {
    $logger = $this->loggerFactory->get('maemgaba_ai');

    $prompt = $this->getPrompt('synthesize_consensus');
    if (!$prompt) {
      $logger->error('No enabled AI prompt found for operation @op.', ['@op' => 'synthesize_consensus']);
      $this->callLogger->log([
        'operation' => 'synthesize_consensus',
        'error_message' => 'No enabled prompt for operation.',
      ] + $this->contextFields($context));
      return [];
    }

    $userPrompt = $this->renderPrompt($prompt, [
      'event_title' => $eventTitle,
      'neutral_summary' => $neutralSummary !== '' ? $neutralSummary : $this->fragment('no_summary'),
      'perspectives' => $perspectives,
    ]);

    $default = $this->resolveProvider('synthesize_consensus');
    if (empty($default['provider_id']) || empty($default['model_id'])) {
      $logger->error('No AI provider resolved for operation @op (maemgaba_core.settings:operation_models or ai.settings:default_providers.chat).', ['@op' => 'synthesize_consensus']);
      $this->callLogger->log([
        'operation' => 'synthesize_consensus',
        'prompt_config_id' => $prompt->id(),
        'error_message' => 'No provider resolved for operation.',
      ] + $this->contextFields($context));
      return [];
    }

    $json = $this->chat(
      $default['provider_id'],
      $default['model_id'],
      $this->systemPrompt($prompt),
      $userPrompt,
      $this->consensusSchema(),
      'consensus',
      'synthesize_consensus',
      $prompt->id(),
      $context,
    );

    if ($json === '') {
      return [];
    }

    $data = json_decode($json, TRUE);
    if (!is_array($data)) {
      return [];
    }
    foreach (['common_points', 'disputed_points'] as $field) {
      if (isset($data[$field]) && is_array($data[$field])) {
        $data[$field] = array_slice($data[$field], 0, self::CONSENSUS_MAX_POINTS);
      }
    }
    return $data;
  }

  /**
   * Runs a structured-JSON chat call with light retry on transient errors.
   *
   * Logs one row to maemgaba_ai_call_log PER ATTEMPT, not per call to this
   * method: every retry is a real request the provider saw (and may have
   * billed or rate-limited), so "N rows" needs to mean "N requests", not "N
   * logical AiAnalyzerService calls". All attempts of one logical call share
   * a random call_group so they can still be grouped back together (e.g.
   * COUNT(DISTINCT call_group) for "logical calls" vs COUNT(*) for "attempts,
   * including retries").
   */
  protected function chat(string $provider_id, string $model_id, string $system_prompt, string $user_prompt, array $schema, string $schema_name, string $operation, ?string $prompt_config_id, array $context = [], int $maxAttempts = self::MAX_ATTEMPTS): string {
    $logger = $this->loggerFactory->get('maemgaba_ai');
    $this->lastError = '';
    $backoff = 1;
    $call_group = bin2hex(random_bytes(12));
    $this->lastCallGroup = $call_group;
    $context_fields = $this->contextFields($context);
    $maxAttempts = max(1, $maxAttempts);

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
      $attempt_start = microtime(TRUE);
      try {
        $provider = $this->aiProvider->createInstance($provider_id);
        if ($system_prompt !== '') {
          $provider->setChatSystemRole($system_prompt);
        }

        $input = new ChatInput([new ChatMessage('user', $user_prompt)]);
        // drupal/ai's HostnameFilter parses every response as HTML and
        // serializes it back, which turns "&" into "&amp;", ">" into "&gt;"
        // and drops "< 2" as a broken tag. Every engine call returns JSON
        // that is read as data (plain text, SQL), and the engine never
        // prints model text as raw HTML: Twig escapes it, and the two
        // basic_html summary fields render through the text format's
        // filter_html. So no call goes through the filter.
        $input->setHostnameFilter(new HostnameFilterDto(fullTrust: TRUE));
        $input->setChatStructuredJsonSchema(new StructuredOutputSchema(
          name: $schema_name,
          description: 'Structured output of the news classifier.',
          strict: TRUE,
          json_schema: $schema,
        ));

        $output = $provider->chat($input, $model_id, ['maemgaba_classify']);
        $text = trim($output->getNormalized()->getText());
        $latency_ms = (int) round((microtime(TRUE) - $attempt_start) * 1000);

        $usage = $this->resolveTokenUsage($output->getTokenUsage(), $user_prompt, $text);
        $this->callLogger->log([
          'operation' => $operation,
          'provider' => $provider_id,
          'model' => $model_id,
          'prompt_config_id' => $prompt_config_id,
          'call_group' => $call_group,
          'attempt' => $attempt,
          'input_tokens' => $usage['input'],
          'output_tokens' => $usage['output'],
          'reasoning_tokens' => $usage['reasoning'],
          'cached_tokens' => $usage['cached'],
          'estimated' => $usage['estimated'],
          'latency_ms' => $latency_ms,
          'success' => TRUE,
        ] + $context_fields);

        return $text;
      }
      catch (\Throwable $e) {
        $this->lastError = $e->getMessage();
        $latency_ms = (int) round((microtime(TRUE) - $attempt_start) * 1000);
        $this->callLogger->log([
          'operation' => $operation,
          'provider' => $provider_id,
          'model' => $model_id,
          'prompt_config_id' => $prompt_config_id,
          'call_group' => $call_group,
          'attempt' => $attempt,
          'latency_ms' => $latency_ms,
          'error_message' => $e->getMessage(),
        ] + $context_fields);

        if ($attempt < $maxAttempts) {
          $logger->warning('AI chat transient failure (attempt @a/@m): @msg — retrying in @w s.', [
            '@a' => $attempt,
            '@m' => $maxAttempts,
            '@msg' => $e->getMessage(),
            '@w' => $backoff,
          ]);
          sleep($backoff);
          $backoff *= 2;
          continue;
        }
        $logger->error('AI chat failed (@provider/@model): @msg', [
          '@provider' => $provider_id,
          '@model' => $model_id,
          '@msg' => $e->getMessage(),
        ]);
      }
    }

    return '';
  }

  /**
   * Ask the data, step 1: writes one SELECT for a visitor's question.
   *
   * @param string $question
   *   The visitor's question (never spliced into SQL; the model writes
   *   constants).
   * @param array $tokens
   *   Prompt tokens from AskService: ask_schema, today, timezone, dialect,
   *   max_rows, bucket_list.
   * @param array|null $route
   *   An array with provider_id and model_id that overrides
   *   operation_models (the eval compares models this way).
   *
   * @return array
   *   Keys: ok (bool: the call returned valid JSON), answerable (bool), sql,
   *   explanation, chart_hint, provider, model, call_group, error.
   */
  public function askSql(string $question, array $tokens, ?array $route = NULL): array {
    $data = $this->askCall('ask_sql', ['question' => $question] + $tokens, $this->askSqlSchema(), 'ask_sql', $question, $route);
    $json = $data['json'] ?? [];
    unset($data['json']);
    return $data + [
      'answerable' => (bool) ($json['answerable'] ?? FALSE),
      'sql' => trim((string) ($json['sql'] ?? '')),
      'explanation' => trim((string) ($json['explanation'] ?? '')),
      'chart_hint' => (string) ($json['chart_hint'] ?? 'table'),
    ];
  }

  /**
   * Ask the data, step 2: one plain sentence about the result rows.
   *
   * AskService never calls this with zero rows; it answers "no data" itself.
   *
   * @return array
   *   Keys: ok, sentence, provider, model, call_group, error.
   */
  public function askAnswer(string $question, string $sql, string $rows, int $rowCount, ?array $route = NULL): array {
    $data = $this->askCall('ask_answer', [
      'question' => $question,
      'sql' => $sql,
      'rows' => $rows,
      'row_count' => (string) $rowCount,
    ], $this->askAnswerSchema(), 'ask_answer', $question, $route);
    $json = $data['json'] ?? [];
    unset($data['json']);
    return $data + ['sentence' => trim((string) ($json['sentence'] ?? ''))];
  }

  /**
   * Shared plumbing of the two ask operations.
   */
  protected function askCall(string $operation, array $vars, array $schema, string $schemaName, string $question, ?array $route): array {
    $out = ['ok' => FALSE, 'provider' => '', 'model' => '', 'call_group' => '', 'error' => ''];
    $prompt = $this->getPrompt($operation);
    if (!$prompt) {
      $out['error'] = 'No enabled prompt for operation.';
      return $out;
    }
    $resolved = $route ?: $this->resolveProvider($operation);
    if (empty($resolved['provider_id']) || empty($resolved['model_id'])) {
      $out['error'] = 'No provider resolved for operation.';
      return $out;
    }
    $out['provider'] = (string) $resolved['provider_id'];
    $out['model'] = (string) $resolved['model_id'];
    $text = $this->chat(
      $out['provider'],
      $out['model'],
      $this->systemPrompt($prompt),
      $this->renderPrompt($prompt, $vars),
      $schema,
      $schemaName,
      $operation,
      $prompt->id(),
      ['type' => 'ask', 'label' => mb_substr($question, 0, 255)],
      self::ASK_ATTEMPTS,
    );
    $out['call_group'] = $this->lastCallGroup;
    if ($text === '') {
      $out['error'] = $this->lastError !== '' ? $this->lastError : 'Empty response.';
      return $out;
    }
    $json = json_decode($text, TRUE);
    if (!is_array($json)) {
      $out['error'] = 'Response is not valid JSON.';
      return $out;
    }
    $out['ok'] = TRUE;
    $out['json'] = $json;
    return $out;
  }

  /**
   * Response schema of ask_sql.
   */
  protected function askSqlSchema(): array {
    return [
      'type' => 'object',
      'properties' => [
        'answerable' => [
          'type' => 'boolean',
          'description' => 'FALSE when the question cannot be answered with one read-only SELECT over the four views (or asks to change data or read anything else).',
        ],
        'sql' => [
          'type' => 'string',
          'description' => 'One MariaDB SELECT (or WITH ... SELECT) statement over the allowed views, or an empty string when not answerable.',
        ],
        'explanation' => [
          'type' => 'string',
          'description' => 'One short sentence: how the query answers the question, or why it cannot be answered.',
        ],
        'chart_hint' => [
          'type' => 'string',
          'enum' => ['table', 'bar', 'line', 'number'],
          'description' => 'The best way to show the result.',
        ],
      ],
      'required' => ['answerable', 'sql', 'explanation', 'chart_hint'],
      'additionalProperties' => FALSE,
    ];
  }

  /**
   * Response schema of ask_answer.
   */
  protected function askAnswerSchema(): array {
    return [
      'type' => 'object',
      'properties' => [
        'sentence' => [
          'type' => 'string',
          'description' => $this->written('One plain sentence that answers the question from the rows only.'),
        ],
      ],
      'required' => ['sentence'],
      'additionalProperties' => FALSE,
    ];
  }

  /**
   * Makes one tiny real call for an operation to prove its routing works.
   *
   * Uses the operation's enabled prompt, resolved provider/model and real
   * response schema with a minimal fixture input, so a pass means "this
   * operation will work in the pipeline right now" — key readable, provider
   * reachable from this host, model id valid, JSON parseable with every
   * required field. Logged to maemgaba_ai_call_log with context type
   * 'preflight' so the (small) cost is attributable.
   *
   * classify_bias has no default-provider fallback (see refineBiasTopic()):
   * when unrouted it reports status 'off' rather than making a call.
   *
   * @param string $operation
   *   One of self::PREFLIGHT_OPERATIONS.
   *
   * @return array
   *   Keys: operation, provider, model, status ('ok'|'fail'|'off'),
   *   latency_ms, message.
   */
  public function preflight(string $operation): array {
    $result = [
      'operation' => $operation,
      'provider' => '',
      'model' => '',
      'status' => 'fail',
      'latency_ms' => 0,
      'message' => '',
    ];

    // Deliberately neutral, language-agnostic fixture: preflight proves
    // routing and schema, not classification quality.
    $article = 'The Senate approved on Tuesday, by 52 votes to 18, a bill that raises the income-tax exemption threshold. The bill now goes to the head of state for signature.';
    $title = 'Senate approves higher income-tax exemption';
    $buckets = $this->spectrum->buckets();
    $firstLabel = $buckets ? reset($buckets)['label'] : 'Left';
    $lastLabel = $buckets ? end($buckets)['label'] : 'Right';
    $topics = $this->topics->keys();
    $fixtures = [
      'classify_cluster' => [
        'vars' => ['article_text' => $article, 'existing_events' => $this->fragment('no_candidates')],
        'schema' => $this->responseSchema(),
        'schema_name' => 'classification',
      ],
      'classify_bias' => [
        'vars' => ['article_text' => $article],
        'schema' => $this->biasTopicSchema(),
        'schema_name' => 'bias_topic',
      ],
      'relevance' => [
        'vars' => ['card_title' => $title, 'card_summary' => $article],
        'schema' => $this->relevanceSchema(),
        'schema_name' => 'relevance',
      ],
      'review_cards' => [
        'vars' => [
          'card_title' => $title,
          'card_summary' => $article,
          'current_bias_score' => '+0',
          'current_bias' => 'center',
          'current_topic' => $topics[1] ?? ($topics[0] ?? ''),
        ],
        'schema' => $this->biasTopicSchema(),
        'schema_name' => 'bias_topic',
      ],
      'ask_sql' => [
        'vars' => [
          'question' => 'How many stories are there?',
          'ask_schema' => "ask_stories: one row per story.\n- story_id: story id",
          'today' => 'Monday, January 5, 2026',
          'timezone' => 'UTC',
          'dialect' => 'MariaDB 11',
          'max_rows' => '200',
          'bucket_list' => 'left, center, right',
        ],
        'schema' => $this->askSqlSchema(),
        'schema_name' => 'ask_sql',
      ],
      'ask_answer' => [
        'vars' => [
          'question' => 'How many stories are there?',
          'sql' => 'SELECT COUNT(*) AS stories FROM ask_stories LIMIT 200',
          'rows' => '[{"stories": 42}]',
          'row_count' => '1',
        ],
        'schema' => $this->askAnswerSchema(),
        'schema_name' => 'ask_answer',
      ],
      'synthesize_consensus' => [
        'vars' => [
          'event_title' => $title,
          'neutral_summary' => $article,
          'perspectives' => "{$firstLabel} · Outlet A · A win for tax fairness.\n{$lastLabel} · Outlet B · Widens the deficit with no offset.",
        ],
        'schema' => $this->consensusSchema(),
        'schema_name' => 'consensus',
      ],
    ];
    if (!isset($fixtures[$operation])) {
      $result['message'] = 'Unknown operation.';
      return $result;
    }
    $fixture = $fixtures[$operation];
    $fixturePrompt = $this->getPrompt($operation);
    if ($fixturePrompt && in_array($operation, ['classify_cluster', 'classify_bias'], TRUE)) {
      $fixture['vars'] += $this->sourceTokens($fixturePrompt, $article, ['headline' => $title]);
    }

    if (in_array($operation, self::ASK_OPERATIONS, TRUE) && !$this->configFactory->get('maemgaba_core.settings')->get('ask.enabled')) {
      $result['status'] = 'off';
      $result['message'] = 'Ask the data is off (maemgaba_core.settings:ask.enabled).';
      return $result;
    }
    if ($operation === 'classify_bias') {
      $route = $this->configFactory->get('maemgaba_core.settings')->get('operation_models.classify_bias');
      $resolved = (!empty($route['provider']) && !empty($route['model']))
        ? ['provider_id' => $route['provider'], 'model_id' => $route['model']]
        : [];
      if (!$resolved) {
        $result['status'] = 'off';
        $result['message'] = 'Not routed — refinement pass disabled.';
        return $result;
      }
    }
    else {
      $resolved = $this->resolveProvider($operation);
    }
    $result['provider'] = (string) ($resolved['provider_id'] ?? '');
    $result['model'] = (string) ($resolved['model_id'] ?? '');
    if ($result['provider'] === '' || $result['model'] === '') {
      $result['message'] = 'No provider/model resolved.';
      return $result;
    }

    $prompt = $this->getPrompt($operation);
    if (!$prompt) {
      $result['message'] = 'No enabled prompt.';
      return $result;
    }

    $start = microtime(TRUE);
    $json = $this->chat(
      $result['provider'],
      $result['model'],
      $this->systemPrompt($prompt),
      $this->renderPrompt($prompt, $fixture['vars']),
      $fixture['schema'],
      $fixture['schema_name'],
      $operation,
      $prompt->id(),
      ['type' => 'preflight', 'label' => 'ai-preflight'],
    );
    $result['latency_ms'] = (int) round((microtime(TRUE) - $start) * 1000);

    if ($json === '') {
      $result['message'] = $this->lastError !== '' ? $this->lastError : 'Empty response.';
      return $result;
    }
    $data = json_decode($json, TRUE);
    if (!is_array($data)) {
      $result['message'] = 'Response is not valid JSON: ' . mb_substr($json, 0, 120);
      return $result;
    }
    $missing = array_diff($fixture['schema']['required'], array_keys($data));
    if ($missing) {
      $result['message'] = 'Missing required fields: ' . implode(', ', $missing);
      return $result;
    }

    $result['status'] = 'ok';
    return $result;
  }

  /**
   * Resolves which provider/model serves one prompt operation.
   *
   * Per-operation routing (Phase 6 Step B1): the
   * maemgaba_core.settings:operation_models map may pin an operation to a
   * specific provider/model — e.g. bulk 'relevance' triage on a local
   * Ollama model, 'synthesize_consensus' on a frontier model. An operation
   * with no (complete) entry falls back to the site-wide default chat
   * provider from ai.settings, so an empty map preserves the old behavior.
   *
   * @param string $operation
   *   The prompt operation key ('classify_cluster', 'relevance',
   *   'synthesize_consensus').
   *
   * @return array
   *   Keys provider_id and model_id, either possibly empty when nothing is
   *   configured — same contract as getDefaultProviderForOperationType().
   */
  protected function resolveProvider(string $operation): array {
    $override = $this->configFactory->get('maemgaba_core.settings')->get('operation_models.' . $operation);
    if (!empty($override['provider']) && !empty($override['model'])) {
      return ['provider_id' => $override['provider'], 'model_id' => $override['model']];
    }
    return $this->aiProvider->getDefaultProviderForOperationType('chat') ?: [];
  }

  /**
   * Maps a caller's context array onto the call-log column names.
   *
   * @param array $context
   *   Keys 'type', 'id', 'label' — all optional.
   *
   * @return array
   *   Keys context_type, context_id, context_label ready for AiCallLogger.
   */
  protected function contextFields(array $context): array {
    return [
      'context_type' => (string) ($context['type'] ?? ''),
      'context_id' => isset($context['id']) ? (int) $context['id'] : NULL,
      'context_label' => (string) ($context['label'] ?? ''),
    ];
  }

  /**
   * Resolves token counts, estimating input/output if the provider omits them.
   *
   * @param \Drupal\ai\Dto\TokenUsageDto $usage
   *   The provider's reported token usage (fields may be NULL).
   * @param string $user_prompt
   *   The rendered user prompt sent to the model.
   * @param string $response_text
   *   The raw text returned by the model.
   *
   * @return array
   *   Keys: input, output, reasoning, cached (all int|null), estimated
   *   (bool). reasoning/cached are never estimated — there's no way to guess
   *   "thinking"/cached-context tokens from prompt/response text length, so
   *   they're just whatever the provider reported (often NULL/inapplicable).
   */
  protected function resolveTokenUsage(mixed $usage, string $user_prompt, string $response_text): array {
    $input = $usage->input ?? NULL;
    $output = $usage->output ?? NULL;
    $reasoning = $usage->reasoning ?? NULL;
    $cached = $usage->cached ?? NULL;

    if ($input !== NULL || $output !== NULL) {
      return [
        'input' => $input,
        'output' => $output,
        'reasoning' => $reasoning,
        'cached' => $cached,
        'estimated' => FALSE,
      ];
    }

    // Provider didn't report usage (e.g. a non-Gemini provider): fall back to
    // a rough strlen/4 estimate and flag the row so the dashboard can exclude
    // or caveat it.
    $estimated_input = (int) ceil(mb_strlen($user_prompt) / self::ESTIMATE_CHARS_PER_TOKEN);
    $estimated_output = (int) ceil(mb_strlen($response_text) / self::ESTIMATE_CHARS_PER_TOKEN);
    return [
      'input' => $estimated_input,
      'output' => $estimated_output,
      'reasoning' => NULL,
      'cached' => NULL,
      'estimated' => TRUE,
    ];
  }

  /**
   * The JSON schema the model must return.
   */
  protected function responseSchema(): array {
    return [
      'type' => 'object',
      'additionalProperties' => FALSE,
      'properties' => [
        'matched_event_id' => [
          'type' => 'integer',
          'description' => 'ID of the matching candidate event, or 0 for a new event.',
        ],
      ] + $this->biasSchemaProperties() + [
        'micro_summary' => [
          'type' => 'string',
          'description' => $this->written('Summary of this outlet\'s framing.'),
        ],
        'event_title' => [
          'type' => 'string',
          'description' => $this->written('Factual title for a new event, or empty.'),
        ],
        'neutral_summary' => [
          'type' => 'string',
          'description' => $this->written('Neutral summary for a new event, or empty.'),
        ],
        'social_relevance' => [
          'type' => 'string',
          'enum' => ['irrelevant', 'maybe', 'relevant'],
          'description' => 'Social relevance: irrelevant, maybe or relevant.',
        ],
        'topic' => $this->topicSchema(),
        'relevance_reason' => [
          'type' => 'string',
          'description' => $this->written('One short sentence justifying the relevance.'),
        ],
      ],
      'required' => [
        'matched_event_id', 'bias_score', 'bias_confidence', 'bias_evidence', 'framing_line', 'partial_text',
        'micro_summary', 'event_title', 'neutral_summary', 'social_relevance', 'topic', 'relevance_reason',
      ],
    ];
  }

  /**
   * The JSON schema for the dedicated bias/topic refinement pass.
   */
  protected function biasTopicSchema(): array {
    return [
      'type' => 'object',
      'additionalProperties' => FALSE,
      'properties' => $this->biasSchemaProperties() + [
        'topic' => $this->topicSchema(),
      ],
      'required' => ['bias_score', 'bias_confidence', 'bias_evidence', 'framing_line', 'partial_text', 'topic'],
    ];
  }

  /**
   * Schema properties for the numeric bias answer (shared by all passes).
   *
   * No enum/minimum/maximum on the integer and no maxItems on the array:
   * Anthropic structured output rejects the bounds keywords and Gemini only
   * allows string enums. Range and caps are enforced in normalizeBias().
   */
  protected function biasSchemaProperties(): array {
    return [
      'bias_score' => [
        'type' => 'integer',
        'description' => 'Integer from -2 to 2 as defined in the instructions: -2 strongly left, -1 leans left, 0 center/neutral, 1 leans right, 2 strongly right.',
      ],
      'bias_confidence' => [
        'type' => 'number',
        'description' => 'Confidence in bias_score, from 0 to 1.',
      ],
      'bias_evidence' => [
        'type' => 'array',
        'description' => 'Up to 3 short verbatim quotes from the text that support bias_score.',
        'items' => ['type' => 'string'],
      ],
      'framing_line' => [
        'type' => 'string',
        'description' => $this->written('One sentence, in your own words and without quoting, on how this article frames the story (what it stresses, whom it relies on, what it leaves out).'),
      ],
      'partial_text' => [
        'type' => 'boolean',
        'description' => 'True when only the headline and a short abstract were available instead of the article body.',
      ],
    ];
  }

  /**
   * The JSON schema for the relevance-only pass.
   */
  protected function relevanceSchema(): array {
    return [
      'type' => 'object',
      'additionalProperties' => FALSE,
      'properties' => [
        'social_relevance' => [
          'type' => 'string',
          'enum' => ['irrelevant', 'maybe', 'relevant'],
        ],
        'topic' => $this->topicSchema(),
        'relevance_reason' => [
          'type' => 'string',
          'description' => $this->written('One short sentence justifying the relevance.'),
        ],
      ],
      'required' => ['social_relevance', 'topic', 'relevance_reason'],
    ];
  }

  /**
   * The JSON schema for the consensus-synthesis pass.
   *
   * Two arrays of short factual sentences: what the sources agree on and what
   * they contest. Kept loose so the model can return fewer items when the
   * coverage gives it little to work with. No maxItems: Anthropic's
   * structured output rejects it on arrays ("property 'maxItems' is not
   * supported", caught by maemgaba:ai-preflight 2026-09-23) — the cap of
   * CONSENSUS_MAX_POINTS is enforced in synthesizeConsensus() instead.
   */
  protected function consensusSchema(): array {
    $schema = [
      'type' => 'object',
      'additionalProperties' => FALSE,
      'properties' => [
        'common_points' => [
          'type' => 'array',
          'description' => $this->written('Points the sources factually agree on.'),
          'minItems' => 0,
          'items' => ['type' => 'string'],
        ],
        'disputed_points' => [
          'type' => 'array',
          'description' => $this->written('Points where the sources\' framing diverges.'),
          'minItems' => 0,
          'items' => ['type' => 'string'],
        ],
      ],
      'required' => ['common_points', 'disputed_points'],
    ];
    if ($this->configFactory->get('maemgaba_core.settings')->get('synthesis_neutral_summary')) {
      $schema['properties']['neutral_summary'] = [
        'type' => 'string',
        'description' => $this->written('Short neutral summary of the event itself (not of any one article).'),
      ];
      $schema['required'][] = 'neutral_summary';
    }
    return $schema;
  }

  /**
   * Topic property: an enum of the site's topic keys (maemgaba_core.topics).
   */
  protected function topicSchema(): array {
    return [
      'type' => 'string',
      'enum' => $this->topics->keys(),
      'description' => 'Main topic of the article.',
    ];
  }

  /**
   * Appends the site's output language to a schema field description.
   */
  protected function written(string $description): string {
    return $description . ' Written in ' . $this->outputLanguage() . '.';
  }

  /**
   * Language reader-facing AI text is written in (maemgaba_core.locale).
   */
  protected function outputLanguage(): string {
    return (string) ($this->configFactory->get('maemgaba_core.locale')->get('output_language') ?: 'English');
  }

  /**
   * Tokens every prompt may use, filled from site config.
   *
   * [output_language] (maemgaba_core.locale), [site_name] (system.site),
   * [topic_list] (maemgaba_core.topics, as "key1", "key2", …), [rubric] and
   * [rubric_version] (maemgaba_core.rubric, '' when the site has none).
   * Call-specific tokens with the same name win.
   */
  public function globalTokens(): array {
    return [
      'output_language' => $this->outputLanguage(),
      'site_name' => (string) $this->configFactory->get('system.site')->get('name'),
      'topic_list' => $this->topics->promptList(),
      'rubric' => $this->rubric->render(),
      'rubric_version' => $this->rubric->version(),
    ];
  }

  /**
   * Renders a prompt template with call tokens plus the global tokens.
   */
  public function renderPrompt(AiPromptInterface $prompt, array $tokens): string {
    return $prompt->render($tokens + $this->globalTokens());
  }

  /**
   * The prompt's system prompt with the global tokens filled in.
   */
  public function systemPrompt(AiPromptInterface $prompt): string {
    $tokens = $this->globalTokens();
    return str_replace(array_map(fn ($name) => '[' . $name . ']', array_keys($tokens)), array_values($tokens), $prompt->getSystemPrompt());
  }

  /**
   * A configured prompt fragment (maemgaba_core.locale) with tokens filled.
   */
  protected function fragment(string $name, array $tokens = []): string {
    $text = (string) $this->configFactory->get('maemgaba_core.locale')->get('prompt_fragments.' . $name);
    foreach ($tokens as $token => $value) {
      $text = str_replace('[' . $token . ']', (string) $value, $text);
    }
    return $text;
  }

  /**
   * Loads the enabled prompt entity for an operation.
   *
   * Public because the eval runner (MaemgabaEvalCommands) also needs to
   * resolve "which prompt would be used" to hash/record it, without
   * duplicating this lookup.
   */
  public function getPrompt(string $operation): ?AiPromptInterface {
    $storage = $this->entityTypeManager->getStorage('maemgaba_prompt');
    $ids = $storage->getQuery()
      ->condition('operation', $operation)
      ->condition('status', TRUE)
      ->accessCheck(FALSE)
      ->range(0, 1)
      ->execute();

    if (!$ids) {
      return NULL;
    }
    /** @var \Drupal\maemgaba_core\Entity\AiPromptInterface $prompt */
    $prompt = $storage->load(reset($ids));
    return $prompt;
  }

}
