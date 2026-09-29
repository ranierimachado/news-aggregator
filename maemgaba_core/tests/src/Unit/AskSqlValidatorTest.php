<?php

declare(strict_types=1);

namespace Drupal\Tests\maemgaba_core\Unit;

use Drupal\maemgaba_core\Ask\AskSqlValidator;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Every deny rule of the "Ask the data" SQL validator, and what it allows.
 */
#[Group('maemgaba_core')]
class AskSqlValidatorTest extends UnitTestCase {

  /**
   * The validator under test, with a five-bucket site's view columns.
   */
  protected AskSqlValidator $validator;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->validator = new AskSqlValidator(self::views(), 200);
  }

  /**
   * A five-bucket site's view columns (AskSchema::columns() with five buckets).
   */
  public static function views(): array {
    $buckets = [
      'left_count',
      'lean_left_count',
      'center_count',
      'lean_right_count',
      'right_count',
    ];
    return [
      'ask_sources' => [
        'source_id', 'story_id', 'outlet', 'section', 'score', 'bucket',
        'confidence', 'partial_text', 'syndicated', 'is_reprint', 'is_opinion',
        'is_perspective', 'topic', 'published_at', 'day',
      ],
      'ask_stories' => array_merge([
        'story_id', 'title', 'published_at', 'day', 'topic', 'relevance',
        'source_count', 'perspective_count', 'opinion_count', 'reprint_count',
        'outlet_count',
      ], $buckets, ['has_summary', 'has_consensus']),
      'ask_outlets' => [
        'outlet', 'section', 'prior_score', 'prior_bucket', 'prior_source',
        'active', 'partial_text',
      ],
      'ask_daily' => array_merge(['day', 'stories', 'sources', 'perspectives', 'opinion'], $buckets),
    ];
  }

  /**
   * Statements that must be rejected, with the reason code.
   */
  public static function rejected(): array {
    return [
      // Lexical rules.
      'empty' => ['', 'empty'],
      'whitespace only' => ["  \n ", 'empty'],
      'too long' => ['SELECT outlet FROM ask_sources WHERE outlet = \'' . str_repeat('a', 4100) . '\'', 'too_long'],
      'non-ascii outside a string' => ["SELECT outlet FROM ask_sources WHERE score \u{2264} 0", 'non_ascii'],
      'dash comment' => ['SELECT outlet FROM ask_sources -- hi', 'comment'],
      'hash comment' => ['SELECT outlet FROM ask_sources # hi', 'comment'],
      'block comment' => ['SELECT /* x */ outlet FROM ask_sources', 'comment'],
      'executable comment' => ['SELECT outlet FROM ask_sources /*!50000 UNION SELECT 1 */', 'comment'],
      'double-quoted identifier (ANSI mode)' => ['SELECT * FROM "mysql"."user"', 'double_quote'],
      'double-quoted string' => ['SELECT outlet FROM ask_sources WHERE outlet = "Fox News"', 'double_quote'],
      'backtick' => ['SELECT `outlet` FROM ask_sources', 'backtick'],
      'brace prefix token' => ['SELECT nid FROM {node}', 'brace'],
      'brace inside a string' => ["SELECT outlet FROM ask_sources WHERE outlet = '{node}'", 'brace'],
      'bracket identifier' => ['SELECT [outlet] FROM ask_sources', 'brace'],
      'user variable' => ['SELECT @a FROM ask_sources', 'variable'],
      'system variable' => ['SELECT @@version', 'variable'],
      'positional placeholder' => ['SELECT outlet FROM ask_sources WHERE score = ?', 'placeholder'],
      'named placeholder' => ['SELECT outlet FROM ask_sources WHERE score = :s', 'placeholder'],
      'backslash outside a string' => ['SELECT outlet FROM ask_sources WHERE score = \\N', 'backslash'],
      'bitwise operator' => ['SELECT score & 1 FROM ask_sources', 'bad_char'],
      'unterminated string' => ["SELECT outlet FROM ask_sources WHERE outlet = 'Fox", 'unterminated_string'],
      'escaped quote keeps the string open' => [
        "SELECT outlet FROM ask_sources WHERE outlet = 'a\\' OR 1=1",
        'unterminated_string',
      ],
      'unbalanced parens' => ['SELECT COUNT(* FROM ask_sources', 'unbalanced_parens'],
      'second statement' => ['SELECT 1; DROP TABLE ask_sources', 'multiple_statements'],
      'semicolon inside a string' => ["SELECT outlet FROM ask_sources WHERE outlet = 'a;b'", 'multiple_statements'],
      // Shape.
      'not a select' => ['UPDATE ask_sources SET score = 2', 'not_select'],
      'leading paren' => ['(SELECT outlet FROM ask_sources)', 'not_select'],
      'malformed with' => ['WITH SELECT outlet FROM ask_sources', 'not_select'],
      // The spec's deny-list, each as a statement that starts with SELECT.
      'insert' => ['SELECT 1 FROM ask_sources WHERE 1 = 1 UNION INSERT INTO x VALUES (1)', 'denied_keyword'],
      'update' => ['SELECT 1 FROM ask_sources UPDATE', 'denied_keyword'],
      'delete' => ['SELECT 1 FROM ask_sources DELETE', 'denied_keyword'],
      'drop' => ['SELECT 1 FROM ask_sources DROP', 'denied_keyword'],
      'alter' => ['SELECT 1 FROM ask_sources ALTER', 'denied_keyword'],
      'grant' => ['SELECT 1 FROM ask_sources GRANT', 'denied_keyword'],
      'into outfile' => ["SELECT outlet FROM ask_sources INTO OUTFILE '/tmp/x'", 'denied_keyword'],
      'load' => ["SELECT LOAD_FILE('/etc/passwd')", 'denied_keyword'],
      'sleep' => ['SELECT SLEEP(5)', 'denied_keyword'],
      'sleep with a space (IGNORE_SPACE)' => ['SELECT SLEEP (5)', 'denied_keyword'],
      'benchmark' => ['SELECT BENCHMARK(1000000, 1)', 'denied_keyword'],
      'information_schema' => ['SELECT TABLE_NAME FROM information_schema.TABLES', 'denied_keyword'],
      'mysql schema' => ['SELECT user FROM mysql.user', 'denied_keyword'],
      'performance_schema' => ['SELECT 1 FROM performance_schema.threads', 'denied_keyword'],
      // Other denied words.
      'for update' => ['SELECT outlet FROM ask_sources FOR UPDATE', 'denied_keyword'],
      'lock in share mode' => ['SELECT outlet FROM ask_sources LOCK IN SHARE MODE', 'denied_keyword'],
      'with recursive' => ['WITH RECURSIVE r AS (SELECT 1 AS n) SELECT n FROM r', 'denied_keyword'],
      'rows examined' => ['SELECT outlet FROM ask_sources LIMIT ROWS EXAMINED 10', 'denied_keyword'],
      'set statement' => ['SELECT 1 FROM ask_sources WHERE 1 SET', 'denied_keyword'],
      'database()' => ['SELECT DATABASE()', 'denied_keyword'],
      'current_user' => ['SELECT CURRENT_USER', 'denied_keyword'],
      'version()' => ['SELECT VERSION()', 'denied_keyword'],
      'get_lock' => ["SELECT GET_LOCK('x', 10)", 'denied_keyword'],
      'force index' => ['SELECT outlet FROM ask_sources FORCE INDEX (x)', 'denied_keyword'],
      'values' => ['SELECT outlet FROM ask_sources UNION VALUES (1)', 'denied_keyword'],
      'sys schema' => ['SELECT 1 FROM sys.version', 'denied_keyword'],
      // Functions.
      'unlisted function' => ['SELECT UUID() FROM ask_sources', 'function_not_allowed'],
      'unlisted function with a space' => ['SELECT MD5 (outlet) FROM ask_sources', 'function_not_allowed'],
      // Tables.
      'base table' => ['SELECT nid FROM node_field_data', 'table_not_allowed'],
      'base table in a join' => [
        'SELECT s.outlet FROM ask_sources s JOIN users u ON u.uid = s.source_id',
        'table_not_allowed',
      ],
      'base table after a comma' => ['SELECT s.outlet FROM ask_sources s, users', 'table_not_allowed'],
      'base table in a subquery' => [
        'SELECT outlet FROM ask_sources WHERE source_id IN (SELECT nid FROM node)',
        'table_not_allowed',
      ],
      'dual' => ['SELECT 1 FROM DUAL', 'table_not_allowed'],
      'cte shadowing a view' => ['WITH ask_sources AS (SELECT 1 AS n) SELECT n FROM ask_sources', 'cte_shadows_view'],
      'schema-qualified view' => ['SELECT outlet FROM othersite.ask_sources', 'table_not_allowed'],
      'view followed by a dot' => ['SELECT outlet FROM ask_sources.x', 'qualified_name'],
      'unknown qualifier' => ['SELECT othersite.outlet FROM ask_sources', 'qualified_name'],
      // Identifiers.
      'unknown column' => ['SELECT uid FROM ask_sources', 'unknown_identifier'],
      'unknown qualified column' => ['SELECT s.uid FROM ask_sources s', 'unknown_identifier'],
      'implicit select alias' => ['SELECT COUNT(*) n FROM ask_sources', 'unknown_identifier'],
      'hex literal' => ['SELECT 0x41 FROM ask_sources', 'unknown_identifier'],
      'charset introducer' => ["SELECT _utf8'x' FROM ask_sources", 'unknown_identifier'],
      // LIMIT.
      'limit too high' => ['SELECT outlet FROM ask_sources LIMIT 999', 'limit_too_high'],
      'offset form too high' => ['SELECT outlet FROM ask_sources LIMIT 0, 999', 'limit_too_high'],
      'offset keyword too high' => ['SELECT outlet FROM ask_sources LIMIT 999 OFFSET 5', 'limit_too_high'],
      'limit expression' => ['SELECT outlet FROM ask_sources LIMIT 10 + 5', 'limit_invalid'],
      'limit decimal' => ['SELECT outlet FROM ask_sources LIMIT 1.5', 'limit_invalid'],
    ];
  }

  /**
   * Rejected statements report the expected reason.
   */
  #[DataProvider('rejected')]
  public function testRejected(string $sql, string $reason): void {
    $result = $this->validator->validate($sql);
    $this->assertFalse($result->ok, "Accepted: $sql");
    $this->assertSame($reason, $result->reason, "Wrong reason ({$result->detail}) for: $sql");
    $this->assertContains($reason, AskSqlValidator::REASONS);
    $this->assertSame('', $result->sql);
    $this->assertSame('', $result->executableSql);
  }

  /**
   * Every reason code has at least one rejection case above.
   */
  public function testEveryReasonIsCovered(): void {
    $covered = array_unique(array_column(self::rejected(), 1));
    $this->assertEqualsCanonicalizing(AskSqlValidator::REASONS, $covered);
  }

  /**
   * Every word of the spec's deny-list is on the denied list.
   */
  public function testSpecDenyList(): void {
    $spec = [
      'INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'GRANT', 'INTO', 'OUTFILE',
      'LOAD', 'SLEEP', 'BENCHMARK', 'INFORMATION_SCHEMA', 'MYSQL',
      'PERFORMANCE_SCHEMA',
    ];
    foreach ($spec as $word) {
      $this->assertContains($word, AskSqlValidator::DENIED);
    }
  }

  /**
   * Statements that must pass, with the SQL that will be shown.
   */
  public static function accepted(): array {
    return [
      'count adds a limit' => [
        'SELECT COUNT(*) AS stories FROM ask_stories',
        'SELECT COUNT(*) AS stories FROM ask_stories LIMIT 200',
      ],
      'trailing semicolon is dropped' => [
        "SELECT outlet FROM ask_outlets WHERE section = 'opinion';",
        "SELECT outlet FROM ask_outlets WHERE section = 'opinion' LIMIT 200",
      ],
      'outlet ranking with aliases' => [
        "SELECT s.outlet, COUNT(*) AS stories FROM ask_sources AS s WHERE s.topic = 'immigration' AND s.day >= CURDATE() - INTERVAL 7 DAY GROUP BY s.outlet ORDER BY stories DESC LIMIT 5",
        "SELECT s.outlet, COUNT(*) AS stories FROM ask_sources AS s WHERE s.topic = 'immigration' AND s.day >= CURDATE() - INTERVAL 7 DAY GROUP BY s.outlet ORDER BY stories DESC LIMIT 5",
      ],
      'bare table alias and join' => [
        'SELECT o.prior_bucket, AVG(s.score) AS avg_score FROM ask_sources s JOIN ask_outlets o ON o.outlet = s.outlet AND o.section = s.section GROUP BY o.prior_bucket',
        'SELECT o.prior_bucket, AVG(s.score) AS avg_score FROM ask_sources s JOIN ask_outlets o ON o.outlet = s.outlet AND o.section = s.section GROUP BY o.prior_bucket LIMIT 200',
      ],
      'both sides' => [
        'SELECT COUNT(*) AS stories FROM ask_stories WHERE (left_count + lean_left_count) > 0 AND (right_count + lean_right_count) > 0',
        'SELECT COUNT(*) AS stories FROM ask_stories WHERE (left_count + lean_left_count) > 0 AND (right_count + lean_right_count) > 0 LIMIT 200',
      ],
      'cte with a column list' => [
        'WITH per_topic (topic, avg_score) AS (SELECT topic, AVG(score) FROM ask_sources WHERE is_perspective = 1 GROUP BY topic) SELECT topic, ROUND(avg_score, 2) AS avg_score FROM per_topic ORDER BY avg_score',
        'WITH per_topic (topic, avg_score) AS (SELECT topic, AVG(score) FROM ask_sources WHERE is_perspective = 1 GROUP BY topic) SELECT topic, ROUND(avg_score, 2) AS avg_score FROM per_topic ORDER BY avg_score LIMIT 200',
      ],
      'derived table' => [
        'SELECT MAX(n) AS most FROM (SELECT story_id, COUNT(*) AS n FROM ask_sources GROUP BY story_id) AS t',
        'SELECT MAX(n) AS most FROM (SELECT story_id, COUNT(*) AS n FROM ask_sources GROUP BY story_id) AS t LIMIT 200',
      ],
      'extract from is not a table' => [
        'SELECT EXTRACT(HOUR FROM published_at) AS hour_of_day, COUNT(*) AS sources FROM ask_sources GROUP BY hour_of_day',
        'SELECT EXTRACT(HOUR FROM published_at) AS hour_of_day, COUNT(*) AS sources FROM ask_sources GROUP BY hour_of_day LIMIT 200',
      ],
      'union gets an outer limit' => [
        "SELECT outlet FROM ask_outlets WHERE prior_bucket = 'left' UNION SELECT outlet FROM ask_outlets WHERE prior_bucket = 'right'",
        "SELECT outlet FROM ask_outlets WHERE prior_bucket = 'left' UNION SELECT outlet FROM ask_outlets WHERE prior_bucket = 'right' LIMIT 200",
      ],
      'limit inside a subquery does not count' => [
        'SELECT title FROM ask_stories WHERE story_id IN (SELECT story_id FROM ask_stories ORDER BY source_count DESC LIMIT 3)',
        'SELECT title FROM ask_stories WHERE story_id IN (SELECT story_id FROM ask_stories ORDER BY source_count DESC LIMIT 3) LIMIT 200',
      ],
      'limit with offset' => [
        'SELECT title FROM ask_stories ORDER BY source_count DESC LIMIT 10, 20',
        'SELECT title FROM ask_stories ORDER BY source_count DESC LIMIT 10, 20',
      ],
      'limit offset keyword' => [
        'SELECT title FROM ask_stories LIMIT 20 OFFSET 10',
        'SELECT title FROM ask_stories LIMIT 20 OFFSET 10',
      ],
      'alias named like a function' => [
        'SELECT topic, COUNT(*) AS count FROM ask_stories GROUP BY topic ORDER BY count DESC',
        'SELECT topic, COUNT(*) AS count FROM ask_stories GROUP BY topic ORDER BY count DESC LIMIT 200',
      ],
      'escaped quotes in a string' => [
        "SELECT outlet FROM ask_outlets WHERE outlet = 'O''Reilly' OR outlet = 'It\\'s'",
        "SELECT outlet FROM ask_outlets WHERE outlet = 'O''Reilly' OR outlet = 'It\\'s' LIMIT 200",
      ],
      'non-ascii inside a string' => [
        "SELECT outlet FROM ask_outlets WHERE outlet = 'Folha de São Paulo'",
        "SELECT outlet FROM ask_outlets WHERE outlet = 'Folha de São Paulo' LIMIT 200",
      ],
      'window function' => [
        'SELECT day, sources, SUM(sources) OVER (ORDER BY day) AS running FROM ask_daily',
        'SELECT day, sources, SUM(sources) OVER (ORDER BY day) AS running FROM ask_daily LIMIT 200',
      ],
      'case and cast' => [
        "SELECT CASE WHEN score < 0 THEN 'left' ELSE 'other' END AS side, CAST(AVG(confidence) AS DECIMAL(4,2)) AS conf FROM ask_sources GROUP BY side",
        "SELECT CASE WHEN score < 0 THEN 'left' ELSE 'other' END AS side, CAST(AVG(confidence) AS DECIMAL(4,2)) AS conf FROM ask_sources GROUP BY side LIMIT 200",
      ],
      'lower-case keywords and pipes concat' => [
        "select outlet || ' (' || section || ')' as label from ask_outlets",
        "select outlet || ' (' || section || ')' as label from ask_outlets LIMIT 200",
      ],
    ];
  }

  /**
   * Accepted statements come back with the row cap applied.
   */
  #[DataProvider('accepted')]
  public function testAccepted(string $sql, string $expected): void {
    $result = $this->validator->validate($sql);
    $this->assertTrue($result->ok, "Rejected ({$result->reason}: {$result->detail}): $sql");
    $this->assertSame($expected, $result->sql);
  }

  /**
   * The executable copy braces view names (and only view names).
   */
  public function testExecutableSqlBracesViews(): void {
    $result = $this->validator->validate("SELECT ask_sources.outlet FROM ask_sources JOIN ask_outlets o ON o.outlet = ask_sources.outlet WHERE ask_sources.topic = 'ask_sources'");
    $this->assertTrue($result->ok, $result->reason . ' ' . $result->detail);
    $this->assertSame("SELECT {ask_sources}.outlet FROM {ask_sources} JOIN {ask_outlets} o ON o.outlet = {ask_sources}.outlet WHERE {ask_sources}.topic = 'ask_sources' LIMIT 200", $result->executableSql);
  }

  /**
   * View names are matched case-insensitively and canonicalized.
   */
  public function testViewNameCase(): void {
    $result = $this->validator->validate('SELECT COUNT(*) AS n FROM ASK_Stories');
    $this->assertTrue($result->ok);
    $this->assertSame('SELECT COUNT(*) AS n FROM {ask_stories} LIMIT 200', $result->executableSql);
  }

  /**
   * A lower row cap is honored.
   */
  public function testCustomRowCap(): void {
    $validator = new AskSqlValidator(['ask_daily' => ['day', 'sources']], 50);
    $this->assertSame('SELECT day FROM ask_daily LIMIT 50', $validator->validate('SELECT day FROM ask_daily')->sql);
    $this->assertSame('limit_too_high', $validator->validate('SELECT day FROM ask_daily LIMIT 51')->reason);
  }

}
