<?php

namespace App\Support\LegacyImport\Sources;

use App\Support\LegacyImport\SourceUnreadable;
use Closure;
use PDO;
use PDOException;

/**
 * Rows from an ODBC data source — Ken's checklist, "Migration 2 (IN)".
 *
 * The super admin names a DSN that MISD has configured on the server and
 * either a table or a SELECT whose columns are aliased to the template's names
 * (`SELECT biz_id AS legacy_business_id, …`). From there it is the same
 * pipeline as a CSV: the same header rules (MapsTemplateColumns), the same
 * dry run, the same validation.
 *
 * ── Credentials never come from the browser ────────────────────────────────
 *
 * The username and password are read from the server's environment
 * (LEGACY_ODBC_USERNAME / LEGACY_ODBC_PASSWORD, or embedded in the DSN by
 * MISD), never typed into the import screen and never stored on the import
 * row. A password in a form field is a password in a request log.
 *
 * ── pdo_odbc may not be installed ──────────────────────────────────────────
 *
 * It is not bundled with PHP on most servers, and this machine does not have
 * it. `available()` asks PDO which drivers it has; a missing driver is said
 * plainly — which extension, and who installs it — instead of surfacing as
 * "could not find driver" or a fatal error.
 *
 * `$connect` exists for the tests: no ODBC database exists here, so they hand
 * in a PDO over an in-memory SQLite table and the rest of this class runs
 * exactly as it would against the real thing.
 */
class OdbcSource implements RowSource
{
    use MapsTemplateColumns;

    /** @var Closure(): PDO */
    private Closure $connect;

    /**
     * @param  (Closure(): PDO)|null  $connect
     */
    public function __construct(
        private string $dsn,
        private ?string $table = null,
        private ?string $query = null,
        ?Closure $connect = null,
    ) {
        $this->connect = $connect ?? function (): PDO {
            if (! self::available()) {
                throw new SourceUnreadable(self::unavailableMessage());
            }

            return new PDO(
                'odbc:'.$this->dsn,
                config('services.legacy_odbc.username'),
                config('services.legacy_odbc.password'),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
        };
    }

    /**
     * Tests only: pretend the driver is (or is not) installed, so the
     * "missing pdo_odbc" answer can be proved on a machine that has it.
     */
    public static ?bool $assumeAvailable = null;

    public static function available(): bool
    {
        return self::$assumeAvailable ?? in_array('odbc', PDO::getAvailableDrivers(), true);
    }

    public static function unavailableMessage(): string
    {
        return 'This server cannot read ODBC sources yet: the PHP extension pdo_odbc is not installed. '
            .'MISD needs to install it (and unixODBC with the source’s driver) — see docs/odbc.md. '
            .'Until then, export the data to CSV and import the file instead.';
    }

    public function label(): string
    {
        return 'ODBC '.$this->dsn.' — '.($this->table ?? 'query');
    }

    /** The statement that is run: the query as given, or every row of the table. */
    public function sql(): string
    {
        return self::statementFor($this->table, $this->query);
    }

    /**
     * Refuse anything but a single read.
     *
     * The source is the city's old database, and the account MISD gives this
     * server should be read-only anyway — but the import screen should not be
     * a way to send an UPDATE to it if that account turns out not to be. One
     * statement, beginning SELECT or WITH, no semicolon inside it. A table
     * name is an identifier, optionally schema-qualified, and nothing else.
     */
    public static function statementFor(?string $table, ?string $query): string
    {
        $table = $table !== null ? trim($table) : null;
        $query = $query !== null ? trim(rtrim(trim($query), ';')) : null;

        if (filled($table) && filled($query)) {
            throw new SourceUnreadable('Give a table or a query, not both.');
        }
        if (filled($table)) {
            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_$]*(\.[A-Za-z_][A-Za-z0-9_$]*)?$/', $table)) {
                throw new SourceUnreadable('That is not a table name. Use letters, digits and underscores, optionally as schema.table.');
            }

            return 'SELECT * FROM '.$table;
        }
        if (filled($query)) {
            if (! preg_match('/^(select|with)\b/i', $query) || str_contains($query, ';')) {
                throw new SourceUnreadable('The query must be one SELECT statement. Alias each column to its template name, e.g. SELECT biz_id AS legacy_business_id, …');
            }

            return $query;
        }

        throw new SourceUnreadable('Name the table to read, or write a SELECT query.');
    }

    public function rows(): iterable
    {
        $sql = $this->sql();

        try {
            $pdo = ($this->connect)();
            $statement = $pdo->query($sql);
        } catch (PDOException $e) {
            // The driver's own message names the DSN and the failure, which is
            // what MISD needs; it never contains the password.
            throw new SourceUnreadable('The ODBC source could not be read: '.$e->getMessage());
        }

        $header = [];
        for ($i = 0; $i < $statement->columnCount(); $i++) {
            $header[] = $statement->getColumnMeta($i)['name'] ?? '';
        }
        $map = $this->mapHeader($header);

        $record = 0;
        while (($values = $statement->fetch(PDO::FETCH_NUM)) !== false) {
            $record++;
            yield $record => $this->mapRow($values, $map);
        }
    }
}
