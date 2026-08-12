<?php

declare(strict_types=1);

namespace Sloop\Database\Query;

/**
 * The layer every kind of statement shares below Query.
 *
 * Query knows how to compile and run; this is where the parts that only a
 * builder needs belong — the helpers for clauses that appear in more than one
 * kind of statement, such as a join, which reads the same whether it is being
 * selected from or updated through.
 *
 * It sits between Query and BuilderWhere so that a statement without a WHERE
 * clause still reaches those helpers: an INSERT extends this class directly
 * rather than inheriting a clause it has no use for.
 */
abstract class Builder extends Query
{
}
