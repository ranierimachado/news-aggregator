<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core\Ask;

/**
 * Decides whether a model-written SQL statement may run.
 *
 * The read-only database user (SELECT on the four ask_* views only) is the
 * real boundary; this validator is the second one, and the one that turns a
 * bad statement into a logged, friendly refusal instead of a database error.
 * It works on tokens from its own lexer, not on substrings, and is strict on
 * purpose:
 *
 * - Lexical: one statement; no comments of any kind (including MariaDB's
 *   executable comments); no double quotes (Drupal connects in ANSI mode,
 *   where "x" names a table); no backticks, {braces} or [brackets] (Drupal
 *   rewrites those even inside strings); no @variables, placeholders or
 *   backslashes outside strings; ASCII only outside strings.
 * - Shape: starts with SELECT or WITH (not WITH RECURSIVE).
 * - Keywords: a deny-list (writes, DDL, file access, locks, sleeps, system
 *   schemas, session statements).
 * - Functions: anything called like a function must be on an allow-list.
 *   MariaDB runs with IGNORE_SPACE, so "SLEEP (1)" is a call too.
 * - Tables: FROM/JOIN may only name an allow-listed view or a CTE defined in
 *   the statement; no schema.table.
 * - Identifiers: every other name must be a view column, a declared alias
 *   (AS name) or a CTE column.
 * - Rows: the outer statement gets LIMIT <max> when it has none; a LIMIT
 *   above the maximum is rejected.
 *
 * Pure PHP with no Drupal services, so every rule has a unit test.
 */
final class AskSqlValidator {

  /**
   * Machine reason codes for rejections.
   */
  public const REASONS = [
    'empty',
    'too_long',
    'non_ascii',
    'comment',
    'double_quote',
    'backtick',
    'brace',
    'variable',
    'placeholder',
    'backslash',
    'bad_char',
    'unterminated_string',
    'unbalanced_parens',
    'multiple_statements',
    'not_select',
    'denied_keyword',
    'function_not_allowed',
    'table_not_allowed',
    'cte_shadows_view',
    'qualified_name',
    'unknown_identifier',
    'limit_too_high',
    'limit_invalid',
  ];

  /**
   * Longest statement accepted, in bytes.
   */
  public const MAX_LENGTH = 4000;

  /**
   * Keywords and names that may never appear as an identifier token.
   */
  public const DENIED = [
    // The spec's list.
    'INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'GRANT', 'INTO', 'OUTFILE',
    'LOAD', 'SLEEP', 'BENCHMARK', 'INFORMATION_SCHEMA', 'MYSQL',
    'PERFORMANCE_SCHEMA',
    // Other writes, DDL and admin statements.
    'REVOKE', 'CREATE', 'REPLACE', 'RENAME', 'TRUNCATE', 'HANDLER', 'CALL',
    'PREPARE', 'EXECUTE', 'DEALLOCATE', 'DO', 'SET', 'LOCK', 'UNLOCK', 'SHOW',
    'DESCRIBE', 'EXPLAIN', 'ANALYZE', 'KILL', 'SHUTDOWN', 'FLUSH', 'RESET',
    'PURGE', 'OPTIMIZE', 'REPAIR', 'CHECKSUM', 'INSTALL', 'UNINSTALL', 'BINLOG',
    'SIGNAL', 'RESIGNAL', 'XA', 'BEGIN', 'COMMIT', 'ROLLBACK', 'SAVEPOINT',
    'START', 'TRANSACTION', 'USE', 'TABLE', 'VALUES', 'DATABASE', 'SCHEMA',
    // File access, locks, waits and sequences.
    'DUMPFILE', 'LOAD_FILE', 'GET_LOCK', 'RELEASE_LOCK', 'RELEASE_ALL_LOCKS',
    'IS_FREE_LOCK', 'IS_USED_LOCK', 'MASTER_POS_WAIT', 'MASTER_GTID_WAIT',
    'WAIT', 'NOWAIT', 'SKIP', 'LOCKED', 'NEXTVAL', 'LASTVAL', 'SETVAL',
    // SELECT options that lock, loop, examine or change plans.
    'FOR', 'PROCEDURE', 'FETCH', 'RECURSIVE', 'EXAMINED', 'FORCE', 'IGNORE',
    'HIGH_PRIORITY', 'LOW_PRIORITY', 'DELAYED',
    // Session and server introspection.
    'SYS', 'USER', 'PASSWORD', 'CURRENT_USER', 'SESSION_USER', 'SYSTEM_USER',
    'CURRENT_ROLE', 'VERSION', 'CONNECTION_ID', 'LAST_INSERT_ID', 'FOUND_ROWS',
    'ROW_COUNT',
  ];

  /**
   * Functions a statement may call.
   */
  public const FUNCTIONS = [
    // Aggregates and window functions.
    'COUNT', 'SUM', 'AVG', 'MIN', 'MAX', 'GROUP_CONCAT', 'STD', 'STDDEV',
    'STDDEV_POP', 'STDDEV_SAMP', 'VARIANCE', 'VAR_POP', 'VAR_SAMP',
    'ROW_NUMBER', 'RANK', 'DENSE_RANK', 'NTILE', 'LAG', 'LEAD', 'FIRST_VALUE',
    'LAST_VALUE', 'PERCENT_RANK', 'CUME_DIST',
    // Math.
    'ROUND', 'FLOOR', 'CEIL', 'CEILING', 'ABS', 'SIGN', 'POW', 'POWER', 'SQRT',
    'LN', 'LOG', 'LOG10', 'EXP', 'GREATEST', 'LEAST',
    // Conditionals and casts.
    'COALESCE', 'IFNULL', 'NULLIF', 'IF', 'CAST',
    // Strings.
    'CONCAT', 'CONCAT_WS', 'LOWER', 'UPPER', 'LCASE', 'UCASE', 'LENGTH',
    'CHAR_LENGTH', 'TRIM', 'LTRIM', 'RTRIM', 'SUBSTRING', 'SUBSTR', 'LEFT',
    'RIGHT', 'LOCATE', 'INSTR', 'LPAD', 'RPAD',
    // Dates.
    'DATE', 'DATE_FORMAT', 'DATE_ADD', 'DATE_SUB', 'ADDDATE', 'SUBDATE',
    'DATEDIFF', 'TIMESTAMPDIFF', 'TIMESTAMPADD', 'CURDATE', 'CURRENT_DATE',
    'NOW', 'CURRENT_TIMESTAMP', 'DAYNAME', 'DAYOFWEEK', 'DAYOFMONTH',
    'DAYOFYEAR', 'WEEKDAY', 'WEEK', 'WEEKOFYEAR', 'YEARWEEK', 'MONTH',
    'MONTHNAME', 'YEAR', 'QUARTER', 'HOUR', 'MINUTE', 'DAY', 'LAST_DAY',
    'MAKEDATE', 'STR_TO_DATE', 'EXTRACT', 'TO_DAYS',
  ];

  /**
   * SQL words that are neither identifiers nor function names.
   */
  public const KEYWORDS = [
    'SELECT', 'DISTINCT', 'ALL', 'FROM', 'WHERE', 'GROUP', 'BY', 'HAVING',
    'ORDER', 'ASC', 'DESC', 'LIMIT', 'OFFSET', 'JOIN', 'INNER', 'LEFT',
    'RIGHT', 'OUTER', 'CROSS', 'ON', 'USING', 'AS', 'AND', 'OR', 'NOT', 'XOR',
    'IN', 'IS', 'NULL', 'TRUE', 'FALSE', 'UNKNOWN', 'LIKE', 'BETWEEN', 'CASE',
    'WHEN', 'THEN', 'ELSE', 'END', 'EXISTS', 'ANY', 'SOME', 'UNION',
    'INTERSECT', 'EXCEPT', 'WITH', 'INTERVAL', 'MICROSECOND', 'SECOND',
    'MINUTE', 'HOUR', 'DAY', 'WEEK', 'MONTH', 'QUARTER', 'YEAR', 'DAY_HOUR',
    'DAY_MINUTE', 'DAY_SECOND', 'HOUR_MINUTE', 'HOUR_SECOND', 'MINUTE_SECOND',
    'YEAR_MONTH', 'OVER', 'PARTITION', 'ROWS', 'RANGE', 'UNBOUNDED',
    'PRECEDING', 'FOLLOWING', 'CURRENT', 'ROW', 'SEPARATOR', 'DIV', 'MOD',
    'ESCAPE', 'SIGNED', 'UNSIGNED', 'INTEGER', 'INT', 'DECIMAL', 'CHAR',
    'DATE', 'DATETIME', 'TIME', 'DOUBLE', 'FLOAT', 'CURRENT_DATE',
    'CURRENT_TIMESTAMP', 'LEADING', 'TRAILING', 'BOTH',
  ];

  /**
   * Functions whose parentheses may contain a FROM that is not a table.
   */
  protected const FROM_FUNCTIONS = ['EXTRACT', 'TRIM', 'SUBSTRING', 'SUBSTR'];

  /**
   * Builds a validator for one schema.
   *
   * @param array<string, string[]> $views
   *   Allow-listed view name => its column names (AskSchema::columns()).
   * @param int $maxRows
   *   The row cap: added as LIMIT when missing, and the highest LIMIT
   *   accepted.
   */
  public function __construct(
    protected array $views,
    protected int $maxRows = 200,
  ) {}

  /**
   * Validates one statement.
   */
  public function validate(string $sql): AskValidation {
    $sql = trim($sql);
    if ($sql === '') {
      return AskValidation::reject('empty');
    }
    if (strlen($sql) > self::MAX_LENGTH) {
      return AskValidation::reject('too_long', (string) strlen($sql));
    }

    $tokens = $this->lex($sql);
    if ($tokens instanceof AskValidation) {
      return $tokens;
    }
    // A trailing semicolon is dropped by the lexer; cut it from the text.
    $sql = rtrim(rtrim($sql), ';');
    $sql = rtrim($sql);
    if (!$tokens) {
      return AskValidation::reject('empty');
    }

    $parens = $this->matchParens($tokens);
    if ($parens === NULL) {
      return AskValidation::reject('unbalanced_parens');
    }

    $first = $tokens[0];
    if ($first['t'] !== 'id' || !in_array($first['u'], ['SELECT', 'WITH'], TRUE)) {
      return AskValidation::reject('not_select', $first['v']);
    }

    foreach ($tokens as $token) {
      if ($token['t'] === 'id' && in_array($token['u'], self::DENIED, TRUE)) {
        return AskValidation::reject('denied_keyword', $token['u']);
      }
    }

    $viewNames = array_keys($this->views);
    $viewUpper = array_map('strtoupper', $viewNames);

    // CTE names and column lists.
    $ctes = [];
    $declared = [];
    if ($first['u'] === 'WITH') {
      $i = 1;
      while (TRUE) {
        $name = $tokens[$i] ?? NULL;
        if (!$name || $name['t'] !== 'id' || $this->isReserved($name['u'])) {
          return AskValidation::reject('not_select', 'malformed WITH');
        }
        if (in_array($name['u'], $viewUpper, TRUE)) {
          return AskValidation::reject('cte_shadows_view', $name['v']);
        }
        $ctes[] = $name['u'];
        $i++;
        if ($this->isOp($tokens[$i] ?? NULL, '(')) {
          $close = $parens[$i];
          for ($k = $i + 1; $k < $close; $k++) {
            if ($tokens[$k]['t'] === 'id') {
              $declared[] = $tokens[$k]['u'];
            }
          }
          $i = $close + 1;
        }
        if (!(($tokens[$i]['u'] ?? '') === 'AS') || !$this->isOp($tokens[$i + 1] ?? NULL, '(')) {
          return AskValidation::reject('not_select', 'malformed WITH');
        }
        $i = $parens[$i + 1] + 1;
        if ($this->isOp($tokens[$i] ?? NULL, ',')) {
          $i++;
          continue;
        }
        if (($tokens[$i]['u'] ?? '') !== 'SELECT') {
          return AskValidation::reject('not_select', 'malformed WITH');
        }
        break;
      }
    }

    // Which paren each token sits in, and the function that opened it.
    $opener = [];
    $stack = [];
    foreach ($tokens as $i => $token) {
      $opener[$i] = $stack ? end($stack) : '';
      if ($this->isOp($token, '(')) {
        $prev = $tokens[$i - 1] ?? NULL;
        $stack[] = ($prev && $prev['t'] === 'id') ? $prev['u'] : '';
      }
      elseif ($this->isOp($token, ')')) {
        array_pop($stack);
      }
    }

    // Roles: table references, aliases, function names, qualified names.
    $role = [];
    $n = count($tokens);
    for ($i = 0; $i < $n; $i++) {
      $token = $tokens[$i];
      if ($token['t'] !== 'id') {
        continue;
      }
      // Declared names: anything after AS that isn't a type, keyword or
      // denied word ("COUNT(*) AS count" is fine; "CAST(x AS DATE)" is not a
      // declaration).
      if ($token['u'] === 'AS') {
        $next = $tokens[$i + 1] ?? NULL;
        if ($next && $next['t'] === 'id' && !in_array($next['u'], self::KEYWORDS, TRUE) && !in_array($next['u'], self::DENIED, TRUE)) {
          $declared[] = $next['u'];
          $role[$i + 1] = 'alias';
        }
        continue;
      }
      if (($token['u'] === 'FROM' && !in_array($opener[$i], self::FROM_FUNCTIONS, TRUE)) || $token['u'] === 'JOIN') {
        $j = $i + 1;
        while (TRUE) {
          $ref = $tokens[$j] ?? NULL;
          if ($this->isOp($ref, '(')) {
            // Derived table: its alias follows the closing paren.
            $j = $parens[$j] + 1;
          }
          elseif ($ref && $ref['t'] === 'id' && (in_array($ref['u'], $viewUpper, TRUE) || in_array($ref['u'], $ctes, TRUE))) {
            if ($this->isOp($tokens[$j + 1] ?? NULL, '.')) {
              return AskValidation::reject('qualified_name', $ref['v'] . '.');
            }
            $role[$j] = 'table';
            $j++;
          }
          else {
            return AskValidation::reject('table_not_allowed', $ref['v'] ?? '');
          }
          // Optional alias.
          $next = $tokens[$j] ?? NULL;
          if ($next && $next['t'] === 'id' && $next['u'] === 'AS') {
            $j++;
            $next = $tokens[$j] ?? NULL;
          }
          if ($next && $next['t'] === 'id' && !$this->isReserved($next['u'])) {
            $declared[] = $next['u'];
            $role[$j] = 'alias';
            $j++;
          }
          // FROM a, b: another table reference at the same depth.
          if ($token['u'] === 'FROM' && $this->isOp($tokens[$j] ?? NULL, ',') && $tokens[$j]['depth'] === $token['depth']) {
            $j++;
            continue;
          }
          break;
        }
      }
    }

    $allColumns = [];
    foreach ($this->views as $columns) {
      foreach ($columns as $column) {
        $allColumns[] = strtoupper($column);
      }
    }
    $qualifiers = array_merge($declared, $ctes, $viewUpper);
    $known = array_merge($allColumns, $declared, $ctes);

    for ($i = 0; $i < $n; $i++) {
      $token = $tokens[$i];
      if ($token['t'] !== 'id' || isset($role[$i])) {
        continue;
      }
      $u = $token['u'];
      $next = $tokens[$i + 1] ?? NULL;
      $prev = $tokens[$i - 1] ?? NULL;
      if ($this->isOp($next, '(')) {
        if (!in_array($u, self::FUNCTIONS, TRUE) && !in_array($u, self::KEYWORDS, TRUE) && !in_array($u, $ctes, TRUE)) {
          return AskValidation::reject('function_not_allowed', $token['v']);
        }
        continue;
      }
      if ($this->isOp($next, '.')) {
        if (!in_array($u, $qualifiers, TRUE)) {
          return AskValidation::reject('qualified_name', $token['v'] . '.');
        }
        continue;
      }
      if ($this->isOp($prev, '.')) {
        if (!in_array($u, $known, TRUE)) {
          return AskValidation::reject('unknown_identifier', $token['v']);
        }
        continue;
      }
      if (in_array($u, self::KEYWORDS, TRUE) || in_array($u, $viewUpper, TRUE)) {
        continue;
      }
      if (!in_array($u, $known, TRUE)) {
        return AskValidation::reject('unknown_identifier', $token['v']);
      }
    }

    // Row cap on the outer statement.
    $limit = $this->checkLimit($tokens);
    if ($limit instanceof AskValidation) {
      return $limit;
    }
    $display = $sql . ($limit ? '' : ' LIMIT ' . $this->maxRows);

    // Executable copy: view names in {braces} for the connection prefix.
    $executable = '';
    $offset = 0;
    foreach ($tokens as $token) {
      if ($token['t'] === 'id' && in_array($token['u'], $viewUpper, TRUE)) {
        $canonical = $viewNames[array_search($token['u'], $viewUpper, TRUE)];
        $executable .= substr($sql, $offset, $token['pos'] - $offset) . '{' . $canonical . '}';
        $offset = $token['pos'] + strlen($token['v']);
      }
    }
    $executable .= substr($sql, $offset) . ($limit ? '' : ' LIMIT ' . $this->maxRows);

    return new AskValidation(TRUE, $display, $executable);
  }

  /**
   * Checks the outer LIMIT.
   *
   * @return bool|\Drupal\maemgaba_core\Ask\AskValidation
   *   TRUE when an acceptable LIMIT exists, FALSE when one must be added, or
   *   a rejection.
   */
  protected function checkLimit(array $tokens): bool|AskValidation {
    $lastSetOp = -1;
    $lastLimit = -1;
    foreach ($tokens as $i => $token) {
      if ($token['depth'] !== 0 || $token['t'] !== 'id') {
        continue;
      }
      if (in_array($token['u'], ['UNION', 'INTERSECT', 'EXCEPT'], TRUE)) {
        $lastSetOp = $i;
      }
      if ($token['u'] === 'LIMIT') {
        $lastLimit = $i;
      }
    }
    if ($lastLimit < 0 || $lastLimit < $lastSetOp) {
      return FALSE;
    }
    $rest = array_slice($tokens, $lastLimit + 1);
    $shape = implode(' ', array_map(fn ($t) => $t['t'] === 'num' ? 'N' : ($t['t'] === 'id' ? $t['u'] : $t['v']), $rest));
    $numbers = array_values(array_filter($rest, fn ($t) => $t['t'] === 'num'));
    foreach ($numbers as $number) {
      if (!ctype_digit($number['v'])) {
        return AskValidation::reject('limit_invalid', $number['v']);
      }
    }
    $count = match ($shape) {
      'N' => (int) $numbers[0]['v'],
      'N , N' => (int) $numbers[1]['v'],
      'N OFFSET N' => (int) $numbers[0]['v'],
      default => NULL,
    };
    if ($count === NULL) {
      return AskValidation::reject('limit_invalid', $shape);
    }
    if ($count > $this->maxRows) {
      return AskValidation::reject('limit_too_high', (string) $count);
    }
    return TRUE;
  }

  /**
   * Splits a statement into tokens, or rejects it.
   *
   * @return array|\Drupal\maemgaba_core\Ask\AskValidation
   *   Tokens with keys t (id|num|str|op), v (text), u (upper-case text),
   *   pos (byte offset) and depth (paren depth), or a rejection.
   */
  protected function lex(string $sql): array|AskValidation {
    $tokens = [];
    $depth = 0;
    $n = strlen($sql);
    $i = 0;
    while ($i < $n) {
      $c = $sql[$i];
      $next = $i + 1 < $n ? $sql[$i + 1] : '';
      if (ord($c) > 127) {
        return AskValidation::reject('non_ascii');
      }
      if (ctype_space($c)) {
        $i++;
        continue;
      }
      if ($c === "'") {
        $j = $i + 1;
        while (TRUE) {
          if ($j >= $n) {
            return AskValidation::reject('unterminated_string');
          }
          $d = $sql[$j];
          if ($d === '\\') {
            $j += 2;
            continue;
          }
          if ($d === "'") {
            if ($j + 1 < $n && $sql[$j + 1] === "'") {
              $j += 2;
              continue;
            }
            break;
          }
          $j++;
        }
        $value = substr($sql, $i, $j - $i + 1);
        // Drupal rewrites {x} and [x] even inside literals, and refuses ';'.
        if (strpbrk($value, '{}[]') !== FALSE) {
          return AskValidation::reject('brace', $value);
        }
        if (str_contains($value, ';')) {
          return AskValidation::reject('multiple_statements', $value);
        }
        $tokens[] = ['t' => 'str', 'v' => $value, 'u' => '', 'pos' => $i, 'depth' => $depth];
        $i = $j + 1;
        continue;
      }
      if (($c === '-' && $next === '-') || ($c === '/' && $next === '*') || $c === '#') {
        return AskValidation::reject('comment');
      }
      $simple = [
        '"' => 'double_quote',
        '`' => 'backtick',
        '{' => 'brace',
        '}' => 'brace',
        '[' => 'brace',
        ']' => 'brace',
        '@' => 'variable',
        '?' => 'placeholder',
        ':' => 'placeholder',
        '\\' => 'backslash',
      ];
      if (isset($simple[$c])) {
        return AskValidation::reject($simple[$c], $c);
      }
      if ($c === ';') {
        if (trim(substr($sql, $i + 1)) !== '') {
          return AskValidation::reject('multiple_statements');
        }
        break;
      }
      if (ctype_alpha($c) || $c === '_') {
        preg_match('/\G[A-Za-z_][A-Za-z0-9_]*/', $sql, $m, 0, $i);
        $tokens[] = ['t' => 'id', 'v' => $m[0], 'u' => strtoupper($m[0]), 'pos' => $i, 'depth' => $depth];
        $i += strlen($m[0]);
        continue;
      }
      if (ctype_digit($c) || ($c === '.' && ctype_digit($next))) {
        preg_match('/\G(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?/', $sql, $m, 0, $i);
        $tokens[] = ['t' => 'num', 'v' => $m[0], 'u' => '', 'pos' => $i, 'depth' => $depth];
        $i += strlen($m[0]);
        continue;
      }
      foreach (['<=>', '<=', '>=', '<>', '!=', '||'] as $op) {
        if (substr($sql, $i, strlen($op)) === $op) {
          $tokens[] = ['t' => 'op', 'v' => $op, 'u' => '', 'pos' => $i, 'depth' => $depth];
          $i += strlen($op);
          continue 2;
        }
      }
      if (str_contains('(),.*+-/%=<>', $c)) {
        if ($c === ')') {
          $depth--;
        }
        $tokens[] = ['t' => 'op', 'v' => $c, 'u' => '', 'pos' => $i, 'depth' => $depth];
        if ($c === '(') {
          $depth++;
        }
        $i++;
        continue;
      }
      return AskValidation::reject('bad_char', $c);
    }
    return $tokens;
  }

  /**
   * Maps each '(' token index to its ')' index, or NULL when unbalanced.
   */
  protected function matchParens(array $tokens): ?array {
    $map = [];
    $stack = [];
    foreach ($tokens as $i => $token) {
      if ($this->isOp($token, '(')) {
        $stack[] = $i;
      }
      elseif ($this->isOp($token, ')')) {
        if (!$stack) {
          return NULL;
        }
        $map[array_pop($stack)] = $i;
      }
    }
    return $stack ? NULL : $map;
  }

  /**
   * Whether a token is a given operator.
   */
  protected function isOp(?array $token, string $op): bool {
    return $token !== NULL && $token['t'] === 'op' && $token['v'] === $op;
  }

  /**
   * Whether a word is a keyword, function name or denied word.
   */
  protected function isReserved(string $upper): bool {
    return in_array($upper, self::KEYWORDS, TRUE) || in_array($upper, self::FUNCTIONS, TRUE) || in_array($upper, self::DENIED, TRUE);
  }

}
