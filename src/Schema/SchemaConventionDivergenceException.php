<?php

namespace Blutrixx\GeneratorEngine\Schema;

/**
 * SchemaConventionDivergenceException
 *
 * Thrown by SchemaIntrospector::meta() when a LIVE table's actual audit
 * column layout disagrees with SchemaConventions::DEFAULT_FLAGS.
 *
 * Chosen over the codebase's existing warning channel
 * (SchemaIntrospector::setIssueHandler()/issueWarning(), used for
 * "FK column has no index" advisories) deliberately: that channel is a
 * no-op unless a consumer explicitly registers a handler, which makes it
 * fundamentally opt-in/silent-by-default -- fine for an advisory a caller
 * may or may not care about, wrong for a signal the project explicitly
 * wants LOUD (see the class-level problem statement this closes: silent
 * flag loss shipped to production multiple times before anyone noticed).
 * An exception can never be silently dropped by an unconfigured caller, so
 * it is the correct default for this specific check. A table that
 * genuinely doesn't follow convention opts out explicitly via
 * SchemaConventions::SKIP_CHECK_META_KEY instead of this exception being
 * swallowed by omission.
 */
final class SchemaConventionDivergenceException extends \RuntimeException
{
    /**
     * @param string   $table            The live table name that diverged.
     * @param string   $flag             The convention flag that diverged, e.g. 'has_soft_deletes'.
     * @param string[] $expectedColumns  The column(s) SchemaConventions::COLUMNS_BY_FLAG associates with $flag.
     * @param bool     $conventionValue  What the convention expects $flag to be (SchemaConventions::DEFAULT_FLAGS[$flag]).
     */
    public static function forFlag(string $table, string $flag, array $expectedColumns, bool $conventionValue): self
    {
        $columns = implode('`, `', $expectedColumns);

        $situation = $conventionValue
            ? "is missing the conventional column(s) `{$columns}`"
            : "unexpectedly has the column(s) `{$columns}`, which convention says it should NOT have";

        $message = sprintf(
            "Schema convention divergence on table `%s`: %s (this project's convention expects `%s` = %s for every generated table -- see SchemaConventions). ".
            "Either add the missing column(s) to match convention, or if `%s` is a genuine legacy/third-party table that deliberately does not follow this " .
            "convention, opt out explicitly by passing ['%s' => true] in the \$meta array given to SchemaIntrospector::meta() for this table/module.",
            $table,
            $situation,
            $flag,
            $conventionValue ? 'true' : 'false',
            $table,
            SchemaConventions::SKIP_CHECK_META_KEY
        );

        return new self($message);
    }
}
