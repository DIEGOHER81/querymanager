<?php
namespace Services;

class VirtualQueryEngine
{
    const MAX_RESULT_ROWS = 50000;

    private array $tables;

    public function __construct(array $tables)
    {
        $this->tables = $tables;
    }

    // ─── Public entry point ──────────────────────────────────────────────

    /**
     * Execute a SQL query against virtual tables.
     * @return array ['columns' => [...], 'rows' => [...], 'row_count' => int]
     */
    public function execute(string $sql): array
    {
        $sql = trim($sql);
        if ($sql === '') {
            throw new \RuntimeException("La consulta SQL esta vacia");
        }

        $tokens = $this->tokenize($sql);
        if (empty($tokens)) {
            throw new \RuntimeException("No se pudieron obtener tokens de la consulta");
        }

        $ast = $this->parse($tokens);
        return $this->executeAst($ast);
    }

    // ─── Tokenizer ──────────────────────────────────────────────────────

    private function tokenize(string $sql): array
    {
        $tokens = [];
        $len = strlen($sql);
        $i = 0;

        while ($i < $len) {
            // Skip whitespace
            if (ctype_space($sql[$i])) {
                $i++;
                continue;
            }

            // Single-quoted string literal
            if ($sql[$i] === "'") {
                $i++;
                $str = '';
                while ($i < $len) {
                    if ($sql[$i] === "'" && $i + 1 < $len && $sql[$i + 1] === "'") {
                        $str .= "'";
                        $i += 2;
                    } elseif ($sql[$i] === "'") {
                        $i++;
                        break;
                    } else {
                        $str .= $sql[$i];
                        $i++;
                    }
                }
                $tokens[] = ['type' => 'STRING', 'value' => $str];
                continue;
            }

            // Numbers
            if (ctype_digit($sql[$i]) || ($sql[$i] === '.' && $i + 1 < $len && ctype_digit($sql[$i + 1]))) {
                $num = '';
                $hasDot = false;
                while ($i < $len && (ctype_digit($sql[$i]) || ($sql[$i] === '.' && !$hasDot))) {
                    if ($sql[$i] === '.') $hasDot = true;
                    $num .= $sql[$i];
                    $i++;
                }
                $tokens[] = ['type' => 'NUMBER', 'value' => $hasDot ? (float)$num : (int)$num];
                continue;
            }

            // Two-character operators
            if ($i + 1 < $len) {
                $two = $sql[$i] . $sql[$i + 1];
                if (in_array($two, ['<>', '!=', '>=', '<='])) {
                    $tokens[] = ['type' => 'OP', 'value' => $two];
                    $i += 2;
                    continue;
                }
            }

            // Single-character operators / symbols
            if (in_array($sql[$i], ['(', ')', ',', '.', '=', '<', '>', '+', '-', '*', '/'])) {
                $tokens[] = ['type' => 'OP', 'value' => $sql[$i]];
                $i++;
                continue;
            }

            // Keywords and identifiers
            if (ctype_alpha($sql[$i]) || $sql[$i] === '_' || $sql[$i] === '`') {
                $quoted = ($sql[$i] === '`');
                if ($quoted) {
                    $i++;
                    $word = '';
                    while ($i < $len && $sql[$i] !== '`') {
                        $word .= $sql[$i];
                        $i++;
                    }
                    if ($i < $len) $i++; // skip closing backtick
                    $tokens[] = ['type' => 'IDENT', 'value' => $word];
                } else {
                    $word = '';
                    while ($i < $len && (ctype_alnum($sql[$i]) || $sql[$i] === '_')) {
                        $word .= $sql[$i];
                        $i++;
                    }
                    $upper = strtoupper($word);
                    $keywords = [
                        'SELECT', 'FROM', 'WHERE', 'JOIN', 'INNER', 'LEFT', 'RIGHT', 'CROSS',
                        'ON', 'AND', 'OR', 'NOT', 'IN', 'LIKE', 'BETWEEN', 'IS', 'NULL',
                        'AS', 'ORDER', 'BY', 'ASC', 'DESC', 'LIMIT', 'GROUP', 'HAVING',
                        'DISTINCT', 'UNION', 'ALL', 'INTERSECT', 'EXCEPT',
                        'COUNT', 'SUM', 'AVG', 'MAX', 'MIN',
                        'UPPER', 'LOWER', 'TRIM', 'CONCAT',
                        'CASE', 'WHEN', 'THEN', 'ELSE', 'END',
                    ];
                    if (in_array($upper, $keywords)) {
                        $tokens[] = ['type' => 'KEYWORD', 'value' => $upper];
                    } else {
                        $tokens[] = ['type' => 'IDENT', 'value' => $word];
                    }
                }
                continue;
            }

            // Unknown character – skip
            $i++;
        }

        return $tokens;
    }

    // ─── Parser helpers ─────────────────────────────────────────────────

    private function peek(array &$tokens, int $pos): ?array
    {
        return $tokens[$pos] ?? null;
    }

    private function peekValue(array &$tokens, int $pos): ?string
    {
        return isset($tokens[$pos]) ? (string)$tokens[$pos]['value'] : null;
    }

    private function peekUpper(array &$tokens, int $pos): ?string
    {
        $v = $this->peekValue($tokens, $pos);
        return $v !== null ? strtoupper($v) : null;
    }

    private function expect(array &$tokens, int &$pos, string $value): void
    {
        $tok = $this->peek($tokens, $pos);
        if ($tok === null || strtoupper((string)$tok['value']) !== strtoupper($value)) {
            $found = $tok ? $tok['value'] : 'fin de la consulta';
            throw new \RuntimeException("Se esperaba '{$value}' pero se encontro '{$found}'");
        }
        $pos++;
    }

    private function isKeyword(array &$tokens, int $pos, string $kw): bool
    {
        $tok = $this->peek($tokens, $pos);
        return $tok !== null && strtoupper((string)$tok['value']) === strtoupper($kw);
    }

    private function isOneOfKeywords(array &$tokens, int $pos, array $kws): bool
    {
        $tok = $this->peek($tokens, $pos);
        if ($tok === null) return false;
        return in_array(strtoupper((string)$tok['value']), $kws);
    }

    // ─── Main parser ────────────────────────────────────────────────────

    private function parse(array $tokens): array
    {
        $pos = 0;
        $ast = $this->parseSelect($tokens, $pos);

        // Check for set operations (UNION, INTERSECT, EXCEPT)
        while ($pos < count($tokens)) {
            $upper = $this->peekUpper($tokens, $pos);
            if (in_array($upper, ['UNION', 'INTERSECT', 'EXCEPT'])) {
                $op = $upper;
                $pos++;
                $unionAll = false;
                if ($op === 'UNION' && $this->isKeyword($tokens, $pos, 'ALL')) {
                    $unionAll = true;
                    $pos++;
                }
                $right = $this->parseSelect($tokens, $pos);
                $ast = [
                    'type' => 'SET_OPERATION',
                    'op' => $unionAll ? 'UNION ALL' : $op,
                    'left' => $ast,
                    'right' => $right,
                ];
            } else {
                break;
            }
        }

        return $ast;
    }

    // ─── Parse SELECT ───────────────────────────────────────────────────

    private function parseSelect(array &$tokens, int &$pos): array
    {
        $this->expect($tokens, $pos, 'SELECT');

        $distinct = false;
        if ($this->isKeyword($tokens, $pos, 'DISTINCT')) {
            $distinct = true;
            $pos++;
        }

        $selectExprs = $this->parseSelectList($tokens, $pos);
        $from = [];
        $joins = [];
        $where = null;
        $groupBy = [];
        $having = null;
        $orderBy = [];
        $limit = null;

        if ($this->isKeyword($tokens, $pos, 'FROM')) {
            $pos++;
            $from = $this->parseFrom($tokens, $pos);
            $joins = $this->parseJoins($tokens, $pos);
        }

        if ($this->isKeyword($tokens, $pos, 'WHERE')) {
            $pos++;
            $where = $this->parseCondition($tokens, $pos);
        }

        if ($this->isKeyword($tokens, $pos, 'GROUP')) {
            $pos++;
            $this->expect($tokens, $pos, 'BY');
            $groupBy = $this->parseGroupBy($tokens, $pos);
        }

        if ($this->isKeyword($tokens, $pos, 'HAVING')) {
            $pos++;
            $having = $this->parseCondition($tokens, $pos);
        }

        if ($this->isKeyword($tokens, $pos, 'ORDER')) {
            $pos++;
            $this->expect($tokens, $pos, 'BY');
            $orderBy = $this->parseOrderBy($tokens, $pos);
        }

        if ($this->isKeyword($tokens, $pos, 'LIMIT')) {
            $pos++;
            $limit = $this->parseLimit($tokens, $pos);
        }

        return [
            'type' => 'SELECT',
            'distinct' => $distinct,
            'columns' => $selectExprs,
            'from' => $from,
            'joins' => $joins,
            'where' => $where,
            'groupBy' => $groupBy,
            'having' => $having,
            'orderBy' => $orderBy,
            'limit' => $limit,
        ];
    }

    private function parseSelectList(array &$tokens, int &$pos): array
    {
        $exprs = [];
        $exprs[] = $this->parseSelectExpression($tokens, $pos);

        while ($this->peekValue($tokens, $pos) === ',') {
            $pos++;
            $exprs[] = $this->parseSelectExpression($tokens, $pos);
        }

        return $exprs;
    }

    private function parseSelectExpression(array &$tokens, int &$pos): array
    {
        // Check for *
        if ($this->peekValue($tokens, $pos) === '*') {
            $pos++;
            return ['type' => 'STAR', 'alias' => null];
        }

        // Check for aggregate/string functions
        $upper = $this->peekUpper($tokens, $pos);
        $aggFunctions = ['COUNT', 'SUM', 'AVG', 'MAX', 'MIN'];
        $strFunctions = ['UPPER', 'LOWER', 'TRIM', 'CONCAT'];

        if (in_array($upper, $aggFunctions) || in_array($upper, $strFunctions)) {
            $func = $this->parseFunctionCall($tokens, $pos);
            $alias = null;
            if ($this->isKeyword($tokens, $pos, 'AS')) {
                $pos++;
                $alias = $tokens[$pos]['value'];
                $pos++;
            }
            $func['alias'] = $alias;
            return $func;
        }

        // Column reference: could be alias.column, alias.*, or just column
        $expr = $this->parseValueExpression($tokens, $pos);

        // Check for alias
        $alias = null;
        if ($this->isKeyword($tokens, $pos, 'AS')) {
            $pos++;
            $alias = $tokens[$pos]['value'];
            $pos++;
        } elseif ($this->peek($tokens, $pos) !== null
            && $this->peek($tokens, $pos)['type'] === 'IDENT'
            && !$this->isOneOfKeywords($tokens, $pos, ['FROM', 'WHERE', 'JOIN', 'INNER', 'LEFT', 'RIGHT', 'CROSS', 'ON', 'ORDER', 'GROUP', 'HAVING', 'LIMIT', 'UNION', 'INTERSECT', 'EXCEPT'])
        ) {
            // Implicit alias (identifier without AS)
            $alias = $tokens[$pos]['value'];
            $pos++;
        }

        if ($alias !== null) {
            $expr['alias'] = $alias;
        }

        return $expr;
    }

    private function parseFunctionCall(array &$tokens, int &$pos): array
    {
        $funcName = strtoupper($tokens[$pos]['value']);
        $pos++;
        $this->expect($tokens, $pos, '(');

        $args = [];

        // COUNT(*)
        if ($funcName === 'COUNT' && $this->peekValue($tokens, $pos) === '*') {
            $args[] = ['type' => 'STAR'];
            $pos++;
        } else {
            if ($this->peekValue($tokens, $pos) !== ')') {
                $args[] = $this->parseValueExpression($tokens, $pos);
                while ($this->peekValue($tokens, $pos) === ',') {
                    $pos++;
                    $args[] = $this->parseValueExpression($tokens, $pos);
                }
            }
        }

        $this->expect($tokens, $pos, ')');

        return [
            'type' => 'FUNCTION',
            'name' => $funcName,
            'args' => $args,
            'alias' => null,
        ];
    }

    private function parseValueExpression(array &$tokens, int &$pos): array
    {
        $tok = $this->peek($tokens, $pos);
        if ($tok === null) {
            throw new \RuntimeException("Se esperaba una expresion pero se encontro el fin de la consulta");
        }

        // Function call
        $upper = $this->peekUpper($tokens, $pos);
        $allFunctions = ['COUNT', 'SUM', 'AVG', 'MAX', 'MIN', 'UPPER', 'LOWER', 'TRIM', 'CONCAT'];
        if (in_array($upper, $allFunctions)) {
            return $this->parseFunctionCall($tokens, $pos);
        }

        // NULL literal
        if ($upper === 'NULL') {
            $pos++;
            return ['type' => 'NULL', 'alias' => null];
        }

        // Number
        if ($tok['type'] === 'NUMBER') {
            $pos++;
            return ['type' => 'LITERAL', 'value' => $tok['value'], 'alias' => null];
        }

        // String
        if ($tok['type'] === 'STRING') {
            $pos++;
            return ['type' => 'LITERAL', 'value' => $tok['value'], 'alias' => null];
        }

        // Parenthesized expression (subquery not supported, just grouping)
        if ($tok['value'] === '(') {
            $pos++;
            $expr = $this->parseValueExpression($tokens, $pos);
            $this->expect($tokens, $pos, ')');
            return $expr;
        }

        // Identifier or alias.column or alias.*
        if ($tok['type'] === 'IDENT' || $tok['type'] === 'KEYWORD') {
            $name = $tok['value'];
            $pos++;
            // Check for dot notation
            if ($this->peekValue($tokens, $pos) === '.') {
                $pos++;
                $next = $this->peek($tokens, $pos);
                if ($next !== null && $next['value'] === '*') {
                    $pos++;
                    return ['type' => 'TABLE_STAR', 'table' => $name, 'alias' => null];
                }
                $col = $next['value'] ?? '';
                $pos++;
                return ['type' => 'COLUMN', 'table' => $name, 'column' => $col, 'alias' => null];
            }
            return ['type' => 'COLUMN', 'table' => null, 'column' => $name, 'alias' => null];
        }

        // Operator like * for star
        if ($tok['value'] === '*') {
            $pos++;
            return ['type' => 'STAR', 'alias' => null];
        }

        throw new \RuntimeException("Token inesperado: '{$tok['value']}'");
    }

    // ─── Parse FROM ─────────────────────────────────────────────────────

    private function parseFrom(array &$tokens, int &$pos): array
    {
        $tables = [];
        $tables[] = $this->parseTableRef($tokens, $pos);

        while ($this->peekValue($tokens, $pos) === ',') {
            $pos++;
            $tables[] = $this->parseTableRef($tokens, $pos);
        }

        return $tables;
    }

    private function parseTableRef(array &$tokens, int &$pos): array
    {
        $tok = $this->peek($tokens, $pos);
        if ($tok === null) {
            throw new \RuntimeException("Se esperaba un nombre de tabla");
        }
        $name = $tok['value'];
        $pos++;

        $alias = $name;
        if ($this->isKeyword($tokens, $pos, 'AS')) {
            $pos++;
            $alias = $tokens[$pos]['value'];
            $pos++;
        } elseif ($this->peek($tokens, $pos) !== null
            && $this->peek($tokens, $pos)['type'] === 'IDENT'
            && !$this->isOneOfKeywords($tokens, $pos, ['WHERE', 'JOIN', 'INNER', 'LEFT', 'RIGHT', 'CROSS', 'ON', 'ORDER', 'GROUP', 'HAVING', 'LIMIT', 'UNION', 'INTERSECT', 'EXCEPT'])
        ) {
            $alias = $tokens[$pos]['value'];
            $pos++;
        }

        return ['name' => $name, 'alias' => $alias];
    }

    // ─── Parse JOINs ────────────────────────────────────────────────────

    private function parseJoins(array &$tokens, int &$pos): array
    {
        $joins = [];

        while ($pos < count($tokens)) {
            $joinType = null;
            $upper = $this->peekUpper($tokens, $pos);

            if ($upper === 'JOIN') {
                $joinType = 'INNER';
                $pos++;
            } elseif ($upper === 'INNER' && $this->isKeyword($tokens, $pos + 1, 'JOIN')) {
                $joinType = 'INNER';
                $pos += 2;
            } elseif ($upper === 'LEFT' && $this->isKeyword($tokens, $pos + 1, 'JOIN')) {
                $joinType = 'LEFT';
                $pos += 2;
            } elseif ($upper === 'RIGHT' && $this->isKeyword($tokens, $pos + 1, 'JOIN')) {
                $joinType = 'RIGHT';
                $pos += 2;
            } elseif ($upper === 'CROSS' && $this->isKeyword($tokens, $pos + 1, 'JOIN')) {
                $joinType = 'CROSS';
                $pos += 2;
            } else {
                break;
            }

            $table = $this->parseTableRef($tokens, $pos);

            $condition = null;
            if ($joinType !== 'CROSS' && $this->isKeyword($tokens, $pos, 'ON')) {
                $pos++;
                $condition = $this->parseCondition($tokens, $pos);
            }

            $joins[] = [
                'type' => $joinType,
                'table' => $table,
                'condition' => $condition,
            ];
        }

        return $joins;
    }

    // ─── Parse WHERE / conditions ───────────────────────────────────────

    private function parseWhere(array &$tokens, int &$pos): ?array
    {
        if (!$this->isKeyword($tokens, $pos, 'WHERE')) {
            return null;
        }
        $pos++;
        return $this->parseCondition($tokens, $pos);
    }

    private function parseCondition(array &$tokens, int &$pos): array
    {
        return $this->parseOr($tokens, $pos);
    }

    private function parseOr(array &$tokens, int &$pos): array
    {
        $left = $this->parseAnd($tokens, $pos);

        while ($this->isKeyword($tokens, $pos, 'OR')) {
            $pos++;
            $right = $this->parseAnd($tokens, $pos);
            $left = ['type' => 'LOGIC', 'op' => 'OR', 'left' => $left, 'right' => $right];
        }

        return $left;
    }

    private function parseAnd(array &$tokens, int &$pos): array
    {
        $left = $this->parseNot($tokens, $pos);

        while ($this->isKeyword($tokens, $pos, 'AND')) {
            $pos++;
            $right = $this->parseNot($tokens, $pos);
            $left = ['type' => 'LOGIC', 'op' => 'AND', 'left' => $left, 'right' => $right];
        }

        return $left;
    }

    private function parseNot(array &$tokens, int &$pos): array
    {
        if ($this->isKeyword($tokens, $pos, 'NOT')) {
            $pos++;
            $expr = $this->parseNot($tokens, $pos);
            return ['type' => 'NOT', 'expr' => $expr];
        }

        return $this->parseComparison($tokens, $pos);
    }

    private function parseComparison(array &$tokens, int &$pos): array
    {
        // Parenthesized condition
        if ($this->peekValue($tokens, $pos) === '(') {
            $pos++;
            $cond = $this->parseCondition($tokens, $pos);
            $this->expect($tokens, $pos, ')');
            return $cond;
        }

        $left = $this->parseValueExpression($tokens, $pos);

        // IS NULL / IS NOT NULL
        if ($this->isKeyword($tokens, $pos, 'IS')) {
            $pos++;
            $not = false;
            if ($this->isKeyword($tokens, $pos, 'NOT')) {
                $not = true;
                $pos++;
            }
            $this->expect($tokens, $pos, 'NULL');
            return [
                'type' => 'COMPARISON',
                'op' => $not ? 'IS NOT NULL' : 'IS NULL',
                'left' => $left,
                'right' => null,
            ];
        }

        // NOT LIKE, NOT IN, NOT BETWEEN
        if ($this->isKeyword($tokens, $pos, 'NOT')) {
            $pos++;
            $upper = $this->peekUpper($tokens, $pos);
            if ($upper === 'LIKE') {
                $pos++;
                $right = $this->parseValueExpression($tokens, $pos);
                return ['type' => 'COMPARISON', 'op' => 'NOT LIKE', 'left' => $left, 'right' => $right];
            }
            if ($upper === 'IN') {
                $pos++;
                $list = $this->parseInList($tokens, $pos);
                return ['type' => 'COMPARISON', 'op' => 'NOT IN', 'left' => $left, 'right' => $list];
            }
            if ($upper === 'BETWEEN') {
                $pos++;
                return $this->parseBetween($left, true, $tokens, $pos);
            }
            throw new \RuntimeException("Se esperaba LIKE, IN o BETWEEN despues de NOT");
        }

        // LIKE
        if ($this->isKeyword($tokens, $pos, 'LIKE')) {
            $pos++;
            $right = $this->parseValueExpression($tokens, $pos);
            return ['type' => 'COMPARISON', 'op' => 'LIKE', 'left' => $left, 'right' => $right];
        }

        // IN
        if ($this->isKeyword($tokens, $pos, 'IN')) {
            $pos++;
            $list = $this->parseInList($tokens, $pos);
            return ['type' => 'COMPARISON', 'op' => 'IN', 'left' => $left, 'right' => $list];
        }

        // BETWEEN
        if ($this->isKeyword($tokens, $pos, 'BETWEEN')) {
            $pos++;
            return $this->parseBetween($left, false, $tokens, $pos);
        }

        // Standard operators: =, <>, !=, <, >, <=, >=
        $tok = $this->peek($tokens, $pos);
        if ($tok !== null && $tok['type'] === 'OP' && in_array($tok['value'], ['=', '<>', '!=', '<', '>', '<=', '>='])) {
            $op = $tok['value'];
            $pos++;
            $right = $this->parseValueExpression($tokens, $pos);
            return ['type' => 'COMPARISON', 'op' => $op, 'left' => $left, 'right' => $right];
        }

        // Bare expression used as boolean (e.g., column reference)
        return $left;
    }

    private function parseInList(array &$tokens, int &$pos): array
    {
        $this->expect($tokens, $pos, '(');
        $items = [];
        $items[] = $this->parseValueExpression($tokens, $pos);
        while ($this->peekValue($tokens, $pos) === ',') {
            $pos++;
            $items[] = $this->parseValueExpression($tokens, $pos);
        }
        $this->expect($tokens, $pos, ')');
        return $items;
    }

    private function parseBetween(array $left, bool $not, array &$tokens, int &$pos): array
    {
        $low = $this->parseValueExpression($tokens, $pos);
        $this->expect($tokens, $pos, 'AND');
        $high = $this->parseValueExpression($tokens, $pos);
        return [
            'type' => 'COMPARISON',
            'op' => $not ? 'NOT BETWEEN' : 'BETWEEN',
            'left' => $left,
            'right' => ['low' => $low, 'high' => $high],
        ];
    }

    // ─── Parse GROUP BY ─────────────────────────────────────────────────

    private function parseGroupBy(array &$tokens, int &$pos): array
    {
        $cols = [];
        $cols[] = $this->parseValueExpression($tokens, $pos);

        while ($this->peekValue($tokens, $pos) === ',') {
            $pos++;
            $cols[] = $this->parseValueExpression($tokens, $pos);
        }

        return $cols;
    }

    // ─── Parse ORDER BY ─────────────────────────────────────────────────

    private function parseOrderBy(array &$tokens, int &$pos): array
    {
        $items = [];
        $items[] = $this->parseOrderByItem($tokens, $pos);

        while ($this->peekValue($tokens, $pos) === ',') {
            $pos++;
            $items[] = $this->parseOrderByItem($tokens, $pos);
        }

        return $items;
    }

    private function parseOrderByItem(array &$tokens, int &$pos): array
    {
        $expr = $this->parseValueExpression($tokens, $pos);
        $dir = 'ASC';
        if ($this->isKeyword($tokens, $pos, 'ASC')) {
            $pos++;
        } elseif ($this->isKeyword($tokens, $pos, 'DESC')) {
            $dir = 'DESC';
            $pos++;
        }
        return ['expr' => $expr, 'dir' => $dir];
    }

    // ─── Parse LIMIT ────────────────────────────────────────────────────

    private function parseLimit(array &$tokens, int &$pos): ?int
    {
        $tok = $this->peek($tokens, $pos);
        if ($tok === null || $tok['type'] !== 'NUMBER') {
            throw new \RuntimeException("Se esperaba un numero despues de LIMIT");
        }
        $pos++;
        return (int)$tok['value'];
    }

    // ─── AST Executor ───────────────────────────────────────────────────

    private function executeAst(array $ast): array
    {
        if ($ast['type'] === 'SET_OPERATION') {
            return $this->executeSetOperation(
                $this->executeAst($ast['left']),
                $ast['op'],
                $this->executeAst($ast['right'])
            );
        }

        return $this->executeSelect($ast);
    }

    private function executeSelect(array $ast): array
    {
        // 1. Resolve FROM tables
        $dataset = $this->resolveFrom($ast['from']);
        $rows = $dataset['rows'];
        $availableColumns = $dataset['columns'];

        // 2. Execute JOINs
        if (!empty($ast['joins'])) {
            $joinResult = $this->executeJoins($rows, $availableColumns, $ast['joins']);
            $rows = $joinResult['rows'];
            $availableColumns = $joinResult['columns'];
        }

        // 3. Apply WHERE filter
        if ($ast['where'] !== null) {
            $filtered = [];
            foreach ($rows as $row) {
                if ($this->evaluateCondition($ast['where'], $row, $availableColumns)) {
                    $filtered[] = $row;
                }
            }
            $rows = $filtered;
        }

        // 4. GROUP BY and aggregates
        $hasAggregates = $this->hasAggregateFunctions($ast['columns']);
        if (!empty($ast['groupBy']) || $hasAggregates) {
            $rows = $this->executeAggregates($rows, $ast['groupBy'], $ast['columns'], $availableColumns);

            // Apply HAVING
            if ($ast['having'] !== null) {
                $filtered = [];
                $havingCols = array_keys($rows[0] ?? []);
                foreach ($rows as $row) {
                    if ($this->evaluateCondition($ast['having'], $row, $havingCols)) {
                        $filtered[] = $row;
                    }
                }
                $rows = $filtered;
            }

            // Build final columns
            $resultColumns = [];
            foreach ($ast['columns'] as $expr) {
                $resultColumns[] = $this->getExpressionOutputName($expr, $availableColumns);
            }

            return $this->finalizeResult($rows, $resultColumns, $ast);
        }

        // 5. Build SELECT output (no aggregation)
        $resultRows = [];
        $resultColumns = null;

        foreach ($rows as $row) {
            $outputRow = [];
            $colNames = [];

            foreach ($ast['columns'] as $selExpr) {
                if ($selExpr['type'] === 'STAR') {
                    foreach ($availableColumns as $col) {
                        $outputRow[$col] = $row[$col] ?? null;
                        $colNames[] = $col;
                    }
                } elseif ($selExpr['type'] === 'TABLE_STAR') {
                    $prefix = $selExpr['table'] . '.';
                    foreach ($availableColumns as $col) {
                        if (strpos($col, $prefix) === 0) {
                            $outputRow[$col] = $row[$col] ?? null;
                            $colNames[] = $col;
                        }
                    }
                } else {
                    $outputName = $this->getExpressionOutputName($selExpr, $availableColumns);
                    $outputRow[$outputName] = $this->resolveValue($selExpr, $row, $availableColumns);
                    $colNames[] = $outputName;
                }
            }

            if ($resultColumns === null) {
                $resultColumns = $colNames;
            }
            $resultRows[] = $outputRow;
        }

        if ($resultColumns === null) {
            $resultColumns = [];
        }

        // 6. DISTINCT
        if ($ast['distinct']) {
            $seen = [];
            $unique = [];
            foreach ($resultRows as $row) {
                $key = serialize($row);
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $unique[] = $row;
                }
            }
            $resultRows = $unique;
        }

        return $this->finalizeResult($resultRows, $resultColumns, $ast);
    }

    private function finalizeResult(array $rows, array $columns, array $ast): array
    {
        // ORDER BY
        if (!empty($ast['orderBy'])) {
            $rows = $this->applyOrderBy($rows, $ast['orderBy'], $columns);
        }

        // LIMIT
        if ($ast['limit'] !== null) {
            $rows = array_slice($rows, 0, $ast['limit']);
        }

        // Enforce max rows
        if (count($rows) > self::MAX_RESULT_ROWS) {
            throw new \RuntimeException(
                "El resultado excede el limite de " . self::MAX_RESULT_ROWS . " filas. " .
                "Agregue un LIMIT o filtre los datos."
            );
        }

        return [
            'columns' => $columns,
            'rows' => array_values($rows),
            'row_count' => count($rows),
        ];
    }

    // ─── FROM resolver ──────────────────────────────────────────────────

    private function resolveFrom(array $from): array
    {
        if (empty($from)) {
            // No FROM clause – single empty row for expressions like SELECT 1+1
            return ['columns' => [], 'rows' => [[] ]];
        }

        $allColumns = [];
        $allRows = null;

        foreach ($from as $tableRef) {
            $name = $tableRef['name'];
            $alias = $tableRef['alias'];

            if (!isset($this->tables[$name]) && !isset($this->tables[$alias])) {
                throw new \RuntimeException("Tabla virtual '{$name}' no encontrada. Tablas disponibles: " . implode(', ', array_keys($this->tables)));
            }

            $source = $this->tables[$name] ?? $this->tables[$alias];
            $srcColumns = $source['columns'] ?? [];
            $srcRows = $source['rows'] ?? [];

            // Prefix columns with alias
            $prefixedCols = [];
            foreach ($srcColumns as $col) {
                $prefixedCols[] = $alias . '.' . $col;
            }

            $prefixedRows = [];
            foreach ($srcRows as $row) {
                $prefixed = [];
                foreach ($srcColumns as $col) {
                    $prefixed[$alias . '.' . $col] = $row[$col] ?? null;
                }
                $prefixedRows[] = $prefixed;
            }

            if ($allRows === null) {
                $allColumns = $prefixedCols;
                $allRows = $prefixedRows;
            } else {
                // Implicit CROSS JOIN for multiple tables in FROM
                $crossSize = count($allRows) * count($prefixedRows);
                if ($crossSize > self::MAX_RESULT_ROWS) {
                    throw new \RuntimeException(
                        "El producto cartesiano de las tablas en FROM produciria {$crossSize} filas (limite: " . self::MAX_RESULT_ROWS . ")"
                    );
                }
                $newRows = [];
                foreach ($allRows as $leftRow) {
                    foreach ($prefixedRows as $rightRow) {
                        $newRows[] = array_merge($leftRow, $rightRow);
                    }
                }
                $allColumns = array_merge($allColumns, $prefixedCols);
                $allRows = $newRows;
            }
        }

        return ['columns' => $allColumns, 'rows' => $allRows ?? []];
    }

    // ─── JOIN executor ──────────────────────────────────────────────────

    private function executeJoins(array $rows, array $columns, array $joins): array
    {
        foreach ($joins as $join) {
            $tableName = $join['table']['name'];
            $tableAlias = $join['table']['alias'];

            if (!isset($this->tables[$tableName]) && !isset($this->tables[$tableAlias])) {
                throw new \RuntimeException("Tabla virtual '{$tableName}' no encontrada para JOIN");
            }

            $source = $this->tables[$tableName] ?? $this->tables[$tableAlias];
            $rightCols = [];
            $rightRows = [];

            foreach ($source['columns'] as $col) {
                $rightCols[] = $tableAlias . '.' . $col;
            }
            foreach ($source['rows'] as $row) {
                $prefixed = [];
                foreach ($source['columns'] as $col) {
                    $prefixed[$tableAlias . '.' . $col] = $row[$col] ?? null;
                }
                $rightRows[] = $prefixed;
            }

            $result = $this->executeJoin($rows, $columns, $rightRows, $rightCols, $join['type'], $join['condition']);
            $rows = $result['rows'];
            $columns = $result['columns'];
        }

        return ['columns' => $columns, 'rows' => $rows];
    }

    private function executeJoin(array $leftRows, array $leftCols, array $rightRows, array $rightCols, string $type, ?array $condition): array
    {
        $allCols = array_merge($leftCols, $rightCols);

        $nullRight = [];
        foreach ($rightCols as $col) {
            $nullRight[$col] = null;
        }
        $nullLeft = [];
        foreach ($leftCols as $col) {
            $nullLeft[$col] = null;
        }

        $result = [];

        if ($type === 'CROSS') {
            $crossSize = count($leftRows) * count($rightRows);
            if ($crossSize > self::MAX_RESULT_ROWS) {
                throw new \RuntimeException(
                    "CROSS JOIN produciria {$crossSize} filas (limite: " . self::MAX_RESULT_ROWS . ")"
                );
            }
            foreach ($leftRows as $lr) {
                foreach ($rightRows as $rr) {
                    $result[] = array_merge($lr, $rr);
                }
            }
        } elseif ($type === 'INNER') {
            foreach ($leftRows as $lr) {
                foreach ($rightRows as $rr) {
                    $merged = array_merge($lr, $rr);
                    if ($condition === null || $this->evaluateCondition($condition, $merged, $allCols)) {
                        $result[] = $merged;
                    }
                }
            }
        } elseif ($type === 'LEFT') {
            foreach ($leftRows as $lr) {
                $matched = false;
                foreach ($rightRows as $rr) {
                    $merged = array_merge($lr, $rr);
                    if ($condition === null || $this->evaluateCondition($condition, $merged, $allCols)) {
                        $result[] = $merged;
                        $matched = true;
                    }
                }
                if (!$matched) {
                    $result[] = array_merge($lr, $nullRight);
                }
            }
        } elseif ($type === 'RIGHT') {
            foreach ($rightRows as $rr) {
                $matched = false;
                foreach ($leftRows as $lr) {
                    $merged = array_merge($lr, $rr);
                    if ($condition === null || $this->evaluateCondition($condition, $merged, $allCols)) {
                        $result[] = $merged;
                        $matched = true;
                    }
                }
                if (!$matched) {
                    $result[] = array_merge($nullLeft, $rr);
                }
            }
        }

        return ['columns' => $allCols, 'rows' => $result];
    }

    // ─── Condition evaluator ────────────────────────────────────────────

    private function evaluateCondition(array $condition, array $row, array $availableColumns): bool
    {
        if ($condition['type'] === 'LOGIC') {
            $left = $this->evaluateCondition($condition['left'], $row, $availableColumns);
            if ($condition['op'] === 'OR') {
                return $left || $this->evaluateCondition($condition['right'], $row, $availableColumns);
            }
            // AND
            return $left && $this->evaluateCondition($condition['right'], $row, $availableColumns);
        }

        if ($condition['type'] === 'NOT') {
            return !$this->evaluateCondition($condition['expr'], $row, $availableColumns);
        }

        if ($condition['type'] === 'COMPARISON') {
            return $this->evaluateComparison($condition, $row, $availableColumns);
        }

        // Bare value expression used as boolean
        $val = $this->resolveValue($condition, $row, $availableColumns);
        return !empty($val);
    }

    private function evaluateComparison(array $comp, array $row, array $availableColumns): bool
    {
        $op = $comp['op'];
        $leftVal = $this->resolveValue($comp['left'], $row, $availableColumns);

        // IS NULL / IS NOT NULL
        if ($op === 'IS NULL') {
            return $leftVal === null;
        }
        if ($op === 'IS NOT NULL') {
            return $leftVal !== null;
        }

        // IN / NOT IN
        if ($op === 'IN' || $op === 'NOT IN') {
            $list = [];
            foreach ($comp['right'] as $item) {
                $list[] = $this->resolveValue($item, $row, $availableColumns);
            }
            $found = in_array($leftVal, $list, false);
            return $op === 'IN' ? $found : !$found;
        }

        // BETWEEN / NOT BETWEEN
        if ($op === 'BETWEEN' || $op === 'NOT BETWEEN') {
            $low = $this->resolveValue($comp['right']['low'], $row, $availableColumns);
            $high = $this->resolveValue($comp['right']['high'], $row, $availableColumns);
            $between = ($this->compareValues($leftVal, $low) >= 0 && $this->compareValues($leftVal, $high) <= 0);
            return $op === 'BETWEEN' ? $between : !$between;
        }

        $rightVal = $this->resolveValue($comp['right'], $row, $availableColumns);

        // LIKE / NOT LIKE
        if ($op === 'LIKE' || $op === 'NOT LIKE') {
            if ($leftVal === null) return false;
            $matched = $this->matchLike((string)$leftVal, (string)$rightVal);
            return $op === 'LIKE' ? $matched : !$matched;
        }

        // NULL comparisons: any comparison with NULL yields false (SQL semantics)
        if ($leftVal === null || $rightVal === null) {
            return false;
        }

        // Numeric coercion
        $leftNum = $this->toNumericIfPossible($leftVal);
        $rightNum = $this->toNumericIfPossible($rightVal);
        $useNumeric = (is_int($leftNum) || is_float($leftNum)) && (is_int($rightNum) || is_float($rightNum));

        if ($useNumeric) {
            $leftVal = $leftNum;
            $rightVal = $rightNum;
        }

        switch ($op) {
            case '=':
                return $leftVal == $rightVal;
            case '<>':
            case '!=':
                return $leftVal != $rightVal;
            case '<':
                return $leftVal < $rightVal;
            case '>':
                return $leftVal > $rightVal;
            case '<=':
                return $leftVal <= $rightVal;
            case '>=':
                return $leftVal >= $rightVal;
            default:
                throw new \RuntimeException("Operador de comparacion no soportado: {$op}");
        }
    }

    // ─── Value resolver ─────────────────────────────────────────────────

    private function resolveValue(array $expr, array $row, array $availableColumns)
    {
        switch ($expr['type']) {
            case 'LITERAL':
                return $expr['value'];

            case 'NULL':
                return null;

            case 'COLUMN':
                $colName = $this->resolveColumnName(
                    $expr['table'] !== null ? $expr['table'] . '.' . $expr['column'] : $expr['column'],
                    $availableColumns
                );
                return $row[$colName] ?? null;

            case 'FUNCTION':
                return $this->evaluateFunction($expr, $row, $availableColumns);

            case 'STAR':
                return null;

            default:
                throw new \RuntimeException("Tipo de expresion no soportado: {$expr['type']}");
        }
    }

    private function evaluateFunction(array $expr, array $row, array $availableColumns)
    {
        $name = $expr['name'];
        $args = $expr['args'];

        switch ($name) {
            case 'UPPER':
                $val = $this->resolveValue($args[0], $row, $availableColumns);
                return $val === null ? null : mb_strtoupper((string)$val);

            case 'LOWER':
                $val = $this->resolveValue($args[0], $row, $availableColumns);
                return $val === null ? null : mb_strtolower((string)$val);

            case 'TRIM':
                $val = $this->resolveValue($args[0], $row, $availableColumns);
                return $val === null ? null : trim((string)$val);

            case 'CONCAT':
                $parts = [];
                foreach ($args as $arg) {
                    $v = $this->resolveValue($arg, $row, $availableColumns);
                    if ($v === null) return null; // SQL CONCAT with NULL returns NULL
                    $parts[] = (string)$v;
                }
                return implode('', $parts);

            // Aggregate functions in non-grouped context (single row)
            case 'COUNT':
            case 'SUM':
            case 'AVG':
            case 'MAX':
            case 'MIN':
                // When used per-row (outside aggregation), try to resolve from pre-computed aggregate key
                $aggKey = $this->getAggregateFunctionKey($expr);
                if (array_key_exists($aggKey, $row)) {
                    return $row[$aggKey];
                }
                // Fallback: resolve the argument value
                if (!empty($args) && $args[0]['type'] !== 'STAR') {
                    return $this->resolveValue($args[0], $row, $availableColumns);
                }
                return null;

            default:
                throw new \RuntimeException("Funcion no soportada: {$name}");
        }
    }

    // ─── Aggregation engine ─────────────────────────────────────────────

    private function hasAggregateFunctions(array $selectExprs): bool
    {
        foreach ($selectExprs as $expr) {
            if ($this->exprHasAggregate($expr)) {
                return true;
            }
        }
        return false;
    }

    private function exprHasAggregate(array $expr): bool
    {
        if ($expr['type'] === 'FUNCTION' && in_array($expr['name'], ['COUNT', 'SUM', 'AVG', 'MAX', 'MIN'])) {
            return true;
        }
        return false;
    }

    private function executeAggregates(array $rows, array $groupByExprs, array $selectExprs, array $availableColumns): array
    {
        // Group rows
        $groups = [];
        if (empty($groupByExprs)) {
            // All rows in a single group
            $groups['__all__'] = $rows;
        } else {
            foreach ($rows as $row) {
                $keyParts = [];
                foreach ($groupByExprs as $gExpr) {
                    $val = $this->resolveValue($gExpr, $row, $availableColumns);
                    $keyParts[] = $val === null ? "\0NULL\0" : (string)$val;
                }
                $key = implode("\0", $keyParts);
                $groups[$key][] = $row;
            }
        }

        // Compute aggregate results per group
        $result = [];
        foreach ($groups as $groupRows) {
            $outputRow = [];
            $sampleRow = $groupRows[0];

            foreach ($selectExprs as $selExpr) {
                $outputName = $this->getExpressionOutputName($selExpr, $availableColumns);

                if ($selExpr['type'] === 'FUNCTION' && in_array($selExpr['name'], ['COUNT', 'SUM', 'AVG', 'MAX', 'MIN'])) {
                    $aggValue = $this->computeAggregate($selExpr, $groupRows, $availableColumns);
                    $outputRow[$outputName] = $aggValue;
                } elseif ($selExpr['type'] === 'STAR') {
                    foreach ($availableColumns as $col) {
                        $outputRow[$col] = $sampleRow[$col] ?? null;
                    }
                } elseif ($selExpr['type'] === 'TABLE_STAR') {
                    $prefix = $selExpr['table'] . '.';
                    foreach ($availableColumns as $col) {
                        if (strpos($col, $prefix) === 0) {
                            $outputRow[$col] = $sampleRow[$col] ?? null;
                        }
                    }
                } else {
                    $outputRow[$outputName] = $this->resolveValue($selExpr, $sampleRow, $availableColumns);
                }
            }

            $result[] = $outputRow;
        }

        return $result;
    }

    private function computeAggregate(array $funcExpr, array $groupRows, array $availableColumns)
    {
        $name = $funcExpr['name'];
        $args = $funcExpr['args'];

        if ($name === 'COUNT') {
            if (!empty($args) && $args[0]['type'] === 'STAR') {
                return count($groupRows);
            }
            // COUNT(col) – count non-null values
            $count = 0;
            foreach ($groupRows as $row) {
                $val = $this->resolveValue($args[0], $row, $availableColumns);
                if ($val !== null) $count++;
            }
            return $count;
        }

        // Collect values for SUM/AVG/MAX/MIN
        $values = [];
        foreach ($groupRows as $row) {
            $val = $this->resolveValue($args[0], $row, $availableColumns);
            if ($val !== null) {
                $values[] = $this->toNumericIfPossible($val);
            }
        }

        if (empty($values)) {
            return null;
        }

        switch ($name) {
            case 'SUM':
                return array_sum($values);
            case 'AVG':
                return array_sum($values) / count($values);
            case 'MAX':
                return max($values);
            case 'MIN':
                return min($values);
            default:
                return null;
        }
    }

    private function getAggregateFunctionKey(array $expr): string
    {
        $name = $expr['name'];
        if (!empty($expr['args']) && $expr['args'][0]['type'] === 'STAR') {
            return $name . '(*)';
        }
        if (!empty($expr['args'])) {
            $arg = $expr['args'][0];
            if ($arg['type'] === 'COLUMN') {
                $col = $arg['table'] ? $arg['table'] . '.' . $arg['column'] : $arg['column'];
                return $name . '(' . $col . ')';
            }
        }
        return $name . '()';
    }

    // ─── Expression output name ─────────────────────────────────────────

    private function getExpressionOutputName(array $expr, array $availableColumns): string
    {
        // Explicit alias takes priority
        if (!empty($expr['alias'])) {
            return $expr['alias'];
        }

        switch ($expr['type']) {
            case 'COLUMN':
                if ($expr['table'] !== null) {
                    return $expr['table'] . '.' . $expr['column'];
                }
                // Try to resolve unqualified column
                try {
                    return $this->resolveColumnName($expr['column'], $availableColumns);
                } catch (\RuntimeException $e) {
                    return $expr['column'];
                }

            case 'FUNCTION':
                return $this->getAggregateFunctionKey($expr);

            case 'LITERAL':
                return (string)$expr['value'];

            case 'NULL':
                return 'NULL';

            case 'STAR':
                return '*';

            case 'TABLE_STAR':
                return $expr['table'] . '.*';

            default:
                return '?';
        }
    }

    // ─── ORDER BY ───────────────────────────────────────────────────────

    private function applyOrderBy(array $rows, array $orderBy, array $availableColumns): array
    {
        usort($rows, function (array $a, array $b) use ($orderBy, $availableColumns) {
            foreach ($orderBy as $item) {
                $expr = $item['expr'];
                $dir = $item['dir'];

                $valA = $this->resolveValue($expr, $a, $availableColumns);
                $valB = $this->resolveValue($expr, $b, $availableColumns);

                $cmp = $this->compareValues($valA, $valB);
                if ($cmp !== 0) {
                    return $dir === 'DESC' ? -$cmp : $cmp;
                }
            }
            return 0;
        });

        return $rows;
    }

    private function compareValues($a, $b): int
    {
        // NULLs sort last
        if ($a === null && $b === null) return 0;
        if ($a === null) return 1;
        if ($b === null) return -1;

        $numA = $this->toNumericIfPossible($a);
        $numB = $this->toNumericIfPossible($b);

        if ((is_int($numA) || is_float($numA)) && (is_int($numB) || is_float($numB))) {
            return $numA <=> $numB;
        }

        return strcmp((string)$a, (string)$b);
    }

    // ─── Set operations ─────────────────────────────────────────────────

    private function executeSetOperation(array $left, string $op, array $right): array
    {
        $leftCols = $left['columns'];
        $rightCols = $right['columns'];

        if (count($leftCols) !== count($rightCols)) {
            throw new \RuntimeException(
                "Las consultas del {$op} deben tener el mismo numero de columnas. " .
                "Izquierda: " . count($leftCols) . ", Derecha: " . count($rightCols)
            );
        }

        // Normalize right rows to use left column names
        $normalizedRight = [];
        foreach ($right['rows'] as $row) {
            $newRow = [];
            $rightVals = array_values($row);
            foreach ($leftCols as $i => $col) {
                $newRow[$col] = $rightVals[$i] ?? null;
            }
            $normalizedRight[] = $newRow;
        }

        $leftRows = $left['rows'];
        $resultRows = [];

        switch ($op) {
            case 'UNION ALL':
                $resultRows = array_merge($leftRows, $normalizedRight);
                break;

            case 'UNION':
                $seen = [];
                foreach (array_merge($leftRows, $normalizedRight) as $row) {
                    $key = serialize($row);
                    if (!isset($seen[$key])) {
                        $seen[$key] = true;
                        $resultRows[] = $row;
                    }
                }
                break;

            case 'INTERSECT':
                $rightKeys = [];
                foreach ($normalizedRight as $row) {
                    $rightKeys[serialize($row)] = true;
                }
                foreach ($leftRows as $row) {
                    if (isset($rightKeys[serialize($row)])) {
                        $resultRows[] = $row;
                    }
                }
                break;

            case 'EXCEPT':
                $rightKeys = [];
                foreach ($normalizedRight as $row) {
                    $rightKeys[serialize($row)] = true;
                }
                foreach ($leftRows as $row) {
                    if (!isset($rightKeys[serialize($row)])) {
                        $resultRows[] = $row;
                    }
                }
                break;

            default:
                throw new \RuntimeException("Operacion de conjuntos no soportada: {$op}");
        }

        if (count($resultRows) > self::MAX_RESULT_ROWS) {
            throw new \RuntimeException(
                "El resultado de {$op} excede el limite de " . self::MAX_RESULT_ROWS . " filas"
            );
        }

        return [
            'columns' => $leftCols,
            'rows' => $resultRows,
            'row_count' => count($resultRows),
        ];
    }

    // ─── LIKE pattern matching ──────────────────────────────────────────

    private function matchLike(string $value, string $pattern): bool
    {
        // Case-insensitive LIKE matching
        // Convert SQL LIKE pattern to regex: % -> .*, _ -> ., escape regex special chars
        $regex = '';
        $len = strlen($pattern);
        for ($i = 0; $i < $len; $i++) {
            $ch = $pattern[$i];
            if ($ch === '%') {
                $regex .= '.*';
            } elseif ($ch === '_') {
                $regex .= '.';
            } elseif ($ch === '\\' && $i + 1 < $len) {
                // Escaped character
                $i++;
                $regex .= preg_quote($pattern[$i], '/');
            } else {
                $regex .= preg_quote($ch, '/');
            }
        }

        return (bool)preg_match('/^' . $regex . '$/iu', $value);
    }

    // ─── Column name resolution ─────────────────────────────────────────

    private function resolveColumnName(string $col, array $availableColumns): string
    {
        // Exact match (qualified or matching an existing column key)
        if (in_array($col, $availableColumns, true)) {
            return $col;
        }

        // Unqualified column – search across all available columns
        $matches = [];
        foreach ($availableColumns as $avail) {
            $parts = explode('.', $avail);
            $shortName = end($parts);
            if (strcasecmp($shortName, $col) === 0) {
                $matches[] = $avail;
            }
        }

        if (count($matches) === 1) {
            return $matches[0];
        }

        if (count($matches) > 1) {
            throw new \RuntimeException(
                "La columna '{$col}' es ambigua. Encontrada en: " . implode(', ', $matches) .
                ". Use el formato alias.columna para desambiguar."
            );
        }

        // Try case-insensitive match for qualified name
        foreach ($availableColumns as $avail) {
            if (strcasecmp($avail, $col) === 0) {
                return $avail;
            }
        }

        throw new \RuntimeException(
            "Columna '{$col}' no encontrada. Columnas disponibles: " . implode(', ', $availableColumns)
        );
    }

    // ─── Utility ────────────────────────────────────────────────────────

    private function toNumericIfPossible($value)
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return strpos($value, '.') !== false ? (float)$value : (int)$value;
        }
        return $value;
    }
}
