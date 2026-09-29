<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Search;

/**
 * Builds the Solr query for keyword, vector and hybrid search (pure logic).
 *
 * Search_api_solr turns the visitor's words into an expanded Lucene query
 * (every term required, per-field boosts). The modes:
 *
 * - keyword: that query, untouched (BM25).
 * - vector: only documents whose dense vector clears a similarity floor,
 *   ranked by similarity (`{!vectorSimilarity}`, Solr 9.6+). With cosine,
 *   Solr's similarity is (1 + cos) / 2, so 0.8 matches cosine distance 0.4,
 *   the ceiling /search uses on MariaDB.
 * - hybrid: the union of both, scored keyword score + weight x vector
 *   similarity (`{!bool should=… should=…}`). Keyword hits keep their BM25
 *   order; documents only the vector finds (paraphrases) still come back,
 *   and a document both find is lifted by both.
 *
 * Local params such as `{!bool}` in `q` are ignored while defType is edismax,
 * so the caller removes search_api_solr's (empty) edismax component and the
 * keyword query moves into a parameter parsed as Lucene.
 */
final class SolrHybridQuery {

  public const MODES = ['keyword', 'vector', 'hybrid'];

  /**
   * Returns the main query and extra parameters for a mode.
   *
   * @param string $mode
   *   One of self::MODES.
   * @param string $keywordQuery
   *   The Lucene query search_api_solr built from the keys.
   * @param float[] $vector
   *   The query embedding (same model and size as the index).
   * @param string $field
   *   The Solr dense-vector field.
   * @param float $weight
   *   Hybrid: multiplier on the vector similarity.
   * @param float $minSimilarity
   *   Similarity floor for vector matches.
   *
   * @return array{q: string, params: array<string, string>}
   *   The new `q` and the parameters it dereferences.
   */
  public static function build(string $mode, string $keywordQuery, array $vector, string $field, float $weight, float $minSimilarity): array {
    if (!in_array($mode, self::MODES, TRUE)) {
      throw new \InvalidArgumentException(sprintf('Unknown search mode "%s".', $mode));
    }
    if ($mode === 'keyword') {
      return ['q' => $keywordQuery, 'params' => []];
    }
    if (!preg_match('/^[A-Za-z0-9_]+$/', $field)) {
      throw new \InvalidArgumentException(sprintf('Unexpected Solr field name "%s".', $field));
    }
    $vector_query = sprintf('{!vectorSimilarity f=%s minReturn=%s}%s', $field, self::number($minSimilarity), self::vectorLiteral($vector));
    if ($mode === 'vector') {
      return ['q' => '{!query v=$mg_vq}', 'params' => ['mg_vq' => $vector_query]];
    }
    return [
      'q' => '{!bool should=$mg_kw should=$mg_vb}',
      'params' => [
        'mg_kw' => $keywordQuery,
        'mg_vq' => $vector_query,
        'mg_vb' => sprintf('{!boost b=%s v=$mg_vq}', self::number($weight)),
      ],
    ];
  }

  /**
   * A vector as Solr's literal: [0.1,-0.2,…].
   */
  public static function vectorLiteral(array $vector): string {
    return '[' . implode(',', array_map(static fn ($x): string => self::number((float) $x), $vector)) . ']';
  }

  /**
   * A float without exponent notation or locale surprises.
   */
  private static function number(float $x): string {
    $s = rtrim(rtrim(sprintf('%.7F', $x), '0'), '.');
    return $s === '-0' || $s === '' ? '0' : $s;
  }

}
