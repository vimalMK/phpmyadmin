<?php
/**
 * Main interface for database interactions
 */

declare(strict_types=1);

namespace PhpMyAdmin;

use PhpMyAdmin\Config\Settings\Server;
use PhpMyAdmin\ConfigStorage\Relation;
use PhpMyAdmin\Dbal\DatabaseName;
use PhpMyAdmin\Dbal\DbalInterface;
use PhpMyAdmin\Dbal\DbiExtension;
use PhpMyAdmin\Dbal\DbiMysqli;
use PhpMyAdmin\Dbal\ResultInterface;
use PhpMyAdmin\Dbal\Warning;
use PhpMyAdmin\Html\Generator;
use PhpMyAdmin\Query\Cache;
use PhpMyAdmin\Query\Compatibility;
use PhpMyAdmin\Query\Generator as QueryGenerator;
use PhpMyAdmin\Query\Utilities;
use PhpMyAdmin\SqlParser\Context;
use PhpMyAdmin\Utils\SessionCache;
use stdClass;

use function __;
use function array_diff;
use function array_keys;
use function array_map;
use function array_multisort;
use function array_reverse;
use function array_shift;
use function array_slice;
use function basename;
use function closelog;
use function count;
use function defined;
use function explode;
use function implode;
use function is_array;
use function is_int;
use function is_object;
use function is_string;
use function mb_strtolower;
use function microtime;
use function openlog;
use function reset;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function stripos;
use function strlen;
use function strtolower;
use function strtoupper;
use function strtr;
use function substr;
use function syslog;
use function trigger_error;
use function uasort;
use function uksort;
use function usort;

use const E_USER_WARNING;
use const LOG_INFO;
use const LOG_NDELAY;
use const LOG_PID;
use const LOG_USER;
use const SORT_ASC;
use const SORT_DESC;

/**
 * Main interface for database interactions
 */
class DatabaseInterface implements DbalInterface
{
    /**
     * Force STORE_RESULT method, ignored by classic MySQL.
     */
    public const QUERY_BUFFERED = 0;
    /**
     * Do not read all rows immediately.
     */
    public const QUERY_UNBUFFERED = 2;
    /**
     * Get session variable.
     */
    public const GETVAR_SESSION = 1;
    /**
     * Get global variable.
     */
    public const GETVAR_GLOBAL = 2;

    /**
     * User connection.
     */
    public const CONNECT_USER = 0x100;
    /**
     * Control user connection.
     */
    public const CONNECT_CONTROL = 0x101;
    /**
     * Auxiliary connection.
     *
     * Used for example for replication setup.
     */
    public const CONNECT_AUXILIARY = 0x102;

    /** @var DbiExtension */
    private $extension;

    /**
     * Opened database links
     *
     * @var array<int, object>
     */
    private $links;

    /** @var array<int, string>|null */
    private $currentUserAndHost = null;

    /**
     * @var int|null lower_case_table_names value cache
     * @psalm-var 0|1|2|null
     */
    private $lowerCaseTableNames = null;

    /** @var bool Whether connection is MariaDB */
    private $isMariaDb = false;
    /** @var bool Whether connection is Percona */
    private $isPercona = false;
    /** @var int Server version as number */
    private $versionInt = 55000;
    /** @var string Server version */
    private $versionString = '5.50.0';
    /** @var string Server version comment */
    private $versionComment = '';

    /** @var Types MySQL types data */
    public $types;

    /** @var Cache */
    private $cache;

    /** @var float */
    public $lastQueryExecutionTime = 0;

    /** @var ListDatabase|null */
    private $databaseList = null;

    /**
     * @param DbiExtension $ext Object to be used for database queries
     */
    public function __construct(DbiExtension $ext)
    {
        $this->extension = $ext;
        $this->links = [];
        if (defined('TESTSUITE')) {
            $this->links[self::CONNECT_USER] = new stdClass();
            $this->links[self::CONNECT_CONTROL] = new stdClass();
        }

        $this->cache = new Cache();
        $this->types = new Types($this);
    }

    /**
     * runs a query
     *
     * @param string $query             SQL query to execute
     * @param int    $link              optional database link to use
     * @param int    $options           optional query options
     * @param bool   $cacheAffectedRows whether to cache affected rows
     */
    public function query(
        string $query,
        int $link = self::CONNECT_USER,
        int $options = self::QUERY_BUFFERED,
        bool $cacheAffectedRows = true
    ): ResultInterface {
        $result = $this->tryQuery($query, $link, $options, $cacheAffectedRows);

        if (! $result) {
            // The following statement will exit
            Generator::mysqlDie($this->getError($link), $query);

            exit;
        }

        return $result;
    }

    public function getCache(): Cache
    {
        return $this->cache;
    }

    /**
     * runs a query and returns the result
     *
     * @param string $query             query to run
     * @param int    $link              link type
     * @param int    $options           if DatabaseInterface::QUERY_UNBUFFERED
     *                                  is provided, it will instruct the extension
     *                                  to use unbuffered mode
     * @param bool   $cacheAffectedRows whether to cache affected row
     *
     * @return ResultInterface|false
     */
    public function tryQuery(
        string $query,
        int $link = self::CONNECT_USER,
        int $options = self::QUERY_BUFFERED,
        bool $cacheAffectedRows = true
    ) {
        $debug = isset($GLOBALS['cfg']['DBG']) && $GLOBALS['cfg']['DBG']['sql'];
        if (! isset($this->links[$link])) {
            return false;
        }

        $time = microtime(true);

        $result = $this->extension->realQuery($query, $this->links[$link], $options);

        if ($cacheAffectedRows) {
            $GLOBALS['cached_affected_rows'] = $this->affectedRows($link, false);
        }

        $this->lastQueryExecutionTime = microtime(true) - $time;
        if ($debug) {
            $errorMessage = $this->getError($link);
            Utilities::debugLogQueryIntoSession(
                $query,
                $errorMessage !== '' ? $errorMessage : null,
                $result,
                $this->lastQueryExecutionTime
            );
            if ($GLOBALS['cfg']['DBG']['sqllog']) {
                $warningsCount = 0;
                if (isset($this->links[$link]->warning_count)) {
                    $warningsCount = $this->links[$link]->warning_count;
                }

                openlog('phpMyAdmin', LOG_NDELAY | LOG_PID, LOG_USER);

                syslog(
                    LOG_INFO,
                    sprintf(
                        'SQL[%s?route=%s]: %0.3f(W:%d,C:%s,L:0x%02X) > %s',
                        basename($_SERVER['SCRIPT_NAME']),
                        Common::getRequest()->getRoute(),
                        $this->lastQueryExecutionTime,
                        $warningsCount,
                        $cacheAffectedRows ? 'y' : 'n',
                        $link,
                        $query
                    )
                );
                closelog();
            }
        }

        if ($result !== false && Tracker::isActive()) {
            Tracker::handleQuery($query);
        }

        return $result;
    }

    /**
     * Send multiple SQL queries to the database server and execute the first one
     *
     * @param string $multiQuery multi query statement to execute
     * @param int    $link       index of the opened database link
     */
    public function tryMultiQuery(
        string $multiQuery = '',
        int $link = self::CONNECT_USER
    ): bool {
        if (! isset($this->links[$link])) {
            return false;
        }

        return $this->extension->realMultiQuery($this->links[$link], $multiQuery);
    }

    /**
     * Executes a query as controluser.
     * The result is always buffered and never cached
     *
     * @param string $sql the query to execute
     *
     * @return ResultInterface the result set
     */
    public function queryAsControlUser(string $sql): ResultInterface
    {
        // Avoid caching of the number of rows affected; for example, this function
        // is called for tracking purposes but we want to display the correct number
        // of rows affected by the original query, not by the query generated for
        // tracking.
        return $this->query($sql, self::CONNECT_CONTROL, self::QUERY_BUFFERED, false);
    }

    /**
     * Executes a query as controluser.
     * The result is always buffered and never cached
     *
     * @param string $sql the query to execute
     *
     * @return ResultInterface|false the result set, or false if the query failed
     */
    public function tryQueryAsControlUser(string $sql)
    {
        // Avoid caching of the number of rows affected; for example, this function
        // is called for tracking purposes but we want to display the correct number
        // of rows affected by the original query, not by the query generated for
        // tracking.
        return $this->tryQuery($sql, self::CONNECT_CONTROL, self::QUERY_BUFFERED, false);
    }

    /**
     * returns array with table names for given db
     *
     * @param string $database name of database
     * @param int    $link     mysql link resource|object
     *
     * @return array   tables names
     */
    public function getTables(string $database, int $link = self::CONNECT_USER): array
    {
        if ($database === '') {
            return [];
        }

        $tables = $this->fetchResult(
            'SHOW TABLES FROM ' . Util::backquote($database) . ';',
            null,
            0,
            $link
        );
        if ($GLOBALS['cfg']['NaturalOrder']) {
            usort($tables, 'strnatcasecmp');
        }

        return $tables;
    }

    /**
     * returns array of all tables in given db or dbs
     * this function expects unquoted names:
     * RIGHT: my_database
     * WRONG: `my_database`
     * WRONG: my\_database
     * if $tbl_is_group is true, $table is used as filter for table names
     *
     * <code>
     * $dbi->getTablesFull('my_database');
     * $dbi->getTablesFull('my_database', 'my_table'));
     * $dbi->getTablesFull('my_database', 'my_tables_', true));
     * </code>
     *
     * @param string       $database     database
     * @param string|array $table        table name(s)
     * @param bool         $tableIsGroup $table is a table group
     * @param int          $limitOffset  zero-based offset for the count
     * @param bool|int     $limitCount   number of tables to return
     * @param string       $sortBy       table attribute to sort by
     * @param string       $sortOrder    direction to sort (ASC or DESC)
     * @param string|null  $tableType    whether table or view
     * @param int          $link         link type
     *
     * @return array           list of tables in given db(s)
     *
     * @todo    move into Table
     */
    public function getTablesFull(
        string $database,
        $table = '',
        bool $tableIsGroup = false,
        int $limitOffset = 0,
        $limitCount = false,
        string $sortBy = 'Name',
        string $sortOrder = 'ASC',
        ?string $tableType = null,
        int $link = self::CONNECT_USER
    ): array {
        if ($limitCount === true) {
            $limitCount = $GLOBALS['cfg']['MaxTableList'];
        }

        $tables = [];

        if (! $GLOBALS['cfg']['Server']['DisableIS']) {
            $sqlWhereTable = QueryGenerator::getTableCondition(
                is_array($table) ? array_map([$this, 'escapeString'], $table) : $this->escapeString($table),
                $tableIsGroup,
                $tableType
            );

            // for PMA bc:
            // `SCHEMA_FIELD_NAME` AS `SHOW_TABLE_STATUS_FIELD_NAME`
            //
            // on non-Windows servers,
            // added BINARY in the WHERE clause to force a case sensitive
            // comparison (if we are looking for the db Aa we don't want
            // to find the db aa)

            $sql = QueryGenerator::getSqlForTablesFull([$this->escapeString($database)], $sqlWhereTable);

            // Sort the tables
            $sql .= ' ORDER BY ' . $sortBy . ' ' . $sortOrder;

            if ($limitCount) {
                $sql .= ' LIMIT ' . $limitCount . ' OFFSET ' . $limitOffset;
            }

            /** @var array<string, array<string, array<string, mixed>>> $tables */
            $tables = $this->fetchResult(
                $sql,
                [
                    'TABLE_SCHEMA',
                    'TABLE_NAME',
                ],
                null,
                $link
            );

            // here, we check for Mroonga engine and compute the good data_length and index_length
            // in the StructureController only we need to sum the two values as the other engines
            foreach ($tables as $oneDatabaseName => $oneDatabaseTables) {
                foreach ($oneDatabaseTables as $oneTableName => $oneTableData) {
                    if ($oneTableData['Engine'] !== 'Mroonga') {
                        continue;
                    }

                    if (! StorageEngine::hasMroongaEngine()) {
                        continue;
                    }

                    [
                        $tables[$oneDatabaseName][$oneTableName]['Data_length'],
                        $tables[$oneDatabaseName][$oneTableName]['Index_length'],
                    ] = StorageEngine::getMroongaLengths($oneDatabaseName, $oneTableName);
                }
            }

            if ($sortBy === 'Name' && $GLOBALS['cfg']['NaturalOrder']) {
                // here, the array's first key is by schema name
                foreach ($tables as $oneDatabaseName => $oneDatabaseTables) {
                    uksort($oneDatabaseTables, 'strnatcasecmp');

                    if ($sortOrder === 'DESC') {
                        $oneDatabaseTables = array_reverse($oneDatabaseTables);
                    }

                    $tables[$oneDatabaseName] = $oneDatabaseTables;
                }
            } elseif ($sortBy === 'Data_length') {
                // Size = Data_length + Index_length
                foreach ($tables as $oneDatabaseName => $oneDatabaseTables) {
                    uasort(
                        $oneDatabaseTables,
                        /**
                         * @param array $a
                         * @param array $b
                         */
                        static function ($a, $b) {
                            $aLength = $a['Data_length'] + $a['Index_length'];
                            $bLength = $b['Data_length'] + $b['Index_length'];

                            return $aLength <=> $bLength;
                        }
                    );

                    if ($sortOrder === 'DESC') {
                        $oneDatabaseTables = array_reverse($oneDatabaseTables);
                    }

                    $tables[$oneDatabaseName] = $oneDatabaseTables;
                }
            }
        }

        // If permissions are wrong on even one database directory,
        // information_schema does not return any table info for any database
        // this is why we fall back to SHOW TABLE STATUS even for MySQL >= 50002
        if ($tables === []) {
            $sql = 'SHOW TABLE STATUS FROM ' . Util::backquote($database);
            if ($table || ($tableIsGroup === true) || $tableType) {
                $sql .= ' WHERE';
                $needAnd = false;
                if ($table || ($tableIsGroup === true)) {
                    if (is_array($table)) {
                        $sql .= ' `Name` IN ('
                            . implode(
                                ', ',
                                array_map(
                                    [
                                        $this,
                                        'quoteString',
                                    ],
                                    $table,
                                    $link
                                )
                            ) . ')';
                    } else {
                        $sql .= " `Name` LIKE '"
                            . $this->escapeMysqlLikeString($table, $link)
                            . "%'";
                    }

                    $needAnd = true;
                }

                if ($tableType) {
                    if ($needAnd) {
                        $sql .= ' AND';
                    }

                    if ($tableType === 'view') {
                        $sql .= " `Comment` = 'VIEW'";
                    } elseif ($tableType === 'table') {
                        $sql .= " `Comment` != 'VIEW'";
                    }
                }
            }

            $eachTables = $this->fetchResult($sql, 'Name', null, $link);

            // here, we check for Mroonga engine and compute the good data_length and index_length
            // in the StructureController only we need to sum the two values as the other engines
            foreach ($eachTables as $tableName => $tableData) {
                if ($tableData['Engine'] !== 'Mroonga') {
                    continue;
                }

                if (! StorageEngine::hasMroongaEngine()) {
                    continue;
                }

                [
                    $eachTables[$tableName]['Data_length'],
                    $eachTables[$tableName]['Index_length'],
                ] = StorageEngine::getMroongaLengths($database, $tableName);
            }

            // Sort naturally if the config allows it and we're sorting
            // the Name column.
            if ($sortBy === 'Name' && $GLOBALS['cfg']['NaturalOrder']) {
                uksort($eachTables, 'strnatcasecmp');

                if ($sortOrder === 'DESC') {
                    $eachTables = array_reverse($eachTables);
                }
            } else {
                // Prepare to sort by creating array of the selected sort
                // value to pass to array_multisort

                // Size = Data_length + Index_length
                $sortValues = [];
                if ($sortBy === 'Data_length') {
                    foreach ($eachTables as $tableName => $tableData) {
                        $sortValues[$tableName] = strtolower(
                            (string) ($tableData['Data_length']
                            + $tableData['Index_length'])
                        );
                    }
                } else {
                    foreach ($eachTables as $tableName => $tableData) {
                        $sortValues[$tableName] = strtolower($tableData[$sortBy] ?? '');
                    }
                }

                if ($sortValues) {
                    if ($sortOrder === 'DESC') {
                        array_multisort($sortValues, SORT_DESC, $eachTables);
                    } else {
                        array_multisort($sortValues, SORT_ASC, $eachTables);
                    }
                }

                // cleanup the temporary sort array
                unset($sortValues);
            }

            if ($limitCount) {
                $eachTables = array_slice($eachTables, $limitOffset, $limitCount);
            }

            $tables[$database] = Compatibility::getISCompatForGetTablesFull($eachTables, $database);
        }

        // cache table data
        // so Table does not require to issue SHOW TABLE STATUS again
        $this->cache->cacheTableData($tables, $table);

        if (isset($tables[$database])) {
            return $tables[$database];
        }

        if (isset($tables[mb_strtolower($database)])) {
            // on windows with lower_case_table_names = 1
            // MySQL returns
            // with SHOW DATABASES or information_schema.SCHEMATA: `Test`
            // but information_schema.TABLES gives `test`
            // see https://github.com/phpmyadmin/phpmyadmin/issues/8402
            return $tables[mb_strtolower($database)];
        }

        return $tables;
    }

    /**
     * Get VIEWs in a particular database
     *
     * @param string $db Database name to look in
     *
     * @return Table[] Set of VIEWs inside the database
     */
    public function getVirtualTables(string $db): array
    {
        $tablesFull = array_keys($this->getTablesFull($db));
        $views = [];

        foreach ($tablesFull as $table) {
            $table = $this->getTable($db, (string) $table);
            if (! $table->isView()) {
                continue;
            }

            $views[] = $table;
        }

        return $views;
    }

    /**
     * returns array with databases containing extended infos about them
     *
     * @param string|null $database    database
     * @param bool        $forceStats  retrieve stats also for MySQL < 5
     * @param int         $link        link type
     * @param string      $sortBy      column to order by
     * @param string      $sortOrder   ASC or DESC
     * @param int         $limitOffset starting offset for LIMIT
     * @param bool|int    $limitCount  row count for LIMIT or true for $GLOBALS['cfg']['MaxDbList']
     *
     * @return array
     *
     * @todo    move into ListDatabase?
     */
    public function getDatabasesFull(
        ?string $database = null,
        bool $forceStats = false,
        int $link = self::CONNECT_USER,
        string $sortBy = 'SCHEMA_NAME',
        string $sortOrder = 'ASC',
        int $limitOffset = 0,
        $limitCount = false
    ): array {
        $sortOrder = strtoupper($sortOrder);

        if ($limitCount === true) {
            $limitCount = $GLOBALS['cfg']['MaxDbList'];
        }

        $applyLimitAndOrderManual = true;

        if (! $GLOBALS['cfg']['Server']['DisableIS']) {
            /**
             * if $GLOBALS['cfg']['NaturalOrder'] is enabled, we cannot use LIMIT
             * cause MySQL does not support natural ordering,
             * we have to do it afterward
             */
            $limit = '';
            if (! $GLOBALS['cfg']['NaturalOrder']) {
                if ($limitCount) {
                    $limit = ' LIMIT ' . $limitCount . ' OFFSET ' . $limitOffset;
                }

                $applyLimitAndOrderManual = false;
            }

            // get table information from information_schema
            $sqlWhereSchema = '';
            if ($database !== null) {
                $sqlWhereSchema = 'WHERE `SCHEMA_NAME` LIKE \''
                    . $this->escapeString($database, $link) . '\'';
            }

            $sql = QueryGenerator::getInformationSchemaDatabasesFullRequest(
                $forceStats,
                $sqlWhereSchema,
                $sortBy,
                $sortOrder,
                $limit
            );

            $databases = $this->fetchResult($sql, 'SCHEMA_NAME', null, $link);

            $mysqlError = $this->getError($link);
            if (! count($databases) && isset($GLOBALS['errno'])) {
                Generator::mysqlDie($mysqlError, $sql);
            }

            // display only databases also in official database list
            // f.e. to apply hide_db and only_db
            $drops = array_diff(
                array_keys($databases),
                (array) $this->getDatabaseList()
            );
            foreach ($drops as $drop) {
                unset($databases[$drop]);
            }
        } else {
            $databases = [];
            foreach ($this->getDatabaseList() as $databaseName) {
                // Compatibility with INFORMATION_SCHEMA output
                $databases[$databaseName]['SCHEMA_NAME'] = $databaseName;

                $databases[$databaseName]['DEFAULT_COLLATION_NAME'] = $this->getDbCollation($databaseName);

                if (! $forceStats) {
                    continue;
                }

                // get additional info about tables
                $databases[$databaseName]['SCHEMA_TABLES'] = 0;
                $databases[$databaseName]['SCHEMA_TABLE_ROWS'] = 0;
                $databases[$databaseName]['SCHEMA_DATA_LENGTH'] = 0;
                $databases[$databaseName]['SCHEMA_MAX_DATA_LENGTH'] = 0;
                $databases[$databaseName]['SCHEMA_INDEX_LENGTH'] = 0;
                $databases[$databaseName]['SCHEMA_LENGTH'] = 0;
                $databases[$databaseName]['SCHEMA_DATA_FREE'] = 0;

                $res = $this->query(
                    'SHOW TABLE STATUS FROM '
                    . Util::backquote($databaseName) . ';'
                );

                while ($row = $res->fetchAssoc()) {
                    $databases[$databaseName]['SCHEMA_TABLES']++;
                    $databases[$databaseName]['SCHEMA_TABLE_ROWS'] += $row['Rows'];
                    $databases[$databaseName]['SCHEMA_DATA_LENGTH'] += $row['Data_length'];
                    $databases[$databaseName]['SCHEMA_MAX_DATA_LENGTH'] += $row['Max_data_length'];
                    $databases[$databaseName]['SCHEMA_INDEX_LENGTH'] += $row['Index_length'];

                    // for InnoDB, this does not contain the number of
                    // overhead bytes but the total free space
                    if ($row['Engine'] !== 'InnoDB') {
                        $databases[$databaseName]['SCHEMA_DATA_FREE'] += $row['Data_free'];
                    }

                    $databases[$databaseName]['SCHEMA_LENGTH'] += $row['Data_length'] + $row['Index_length'];
                }

                unset($res);
            }
        }

        /**
         * apply limit and order manually now
         * (caused by older MySQL < 5 or $GLOBALS['cfg']['NaturalOrder'])
         */
        if ($applyLimitAndOrderManual) {
            usort(
                $databases,
                static function ($a, $b) use ($sortBy, $sortOrder) {
                    return Utilities::usortComparisonCallback($a, $b, $sortBy, $sortOrder);
                }
            );

            /**
             * now apply limit
             */
            if ($limitCount) {
                $databases = array_slice($databases, $limitOffset, $limitCount);
            }
        }

        return $databases;
    }

    /**
     * returns detailed array with all columns for sql
     *
     * @param string $sqlQuery    target SQL query to get columns
     * @param array  $viewColumns alias for columns
     *
     * @return array
     * @psalm-return list<array<string, mixed>>
     */
    public function getColumnMapFromSql(string $sqlQuery, array $viewColumns = []): array
    {
        $result = $this->tryQuery($sqlQuery);

        if ($result === false) {
            return [];
        }

        $meta = $this->getFieldsMeta($result);

        $columnMap = [];
        $nbColumns = count($viewColumns);

        foreach ($meta as $i => $field) {
            $map = [
                'table_name' => $field->table,
                'refering_column' => $field->name,
            ];

            if ($nbColumns >= $i) {
                $map['real_column'] = $viewColumns[$i];
            }

            $columnMap[] = $map;
        }

        return $columnMap;
    }

    /**
     * returns detailed array with all columns for given table in database,
     * or all tables/databases
     *
     * @param string|null $database name of database
     * @param string|null $table    name of table to retrieve columns from
     * @param string|null $column   name of specific column
     * @param int         $link     mysql link resource
     *
     * @return array
     */
    public function getColumnsFull(
        ?string $database = null,
        ?string $table = null,
        ?string $column = null,
        int $link = self::CONNECT_USER
    ): array {
        if (! $GLOBALS['cfg']['Server']['DisableIS']) {
            $sql = QueryGenerator::getInformationSchemaColumnsFullRequest(
                $database !== null ? $this->quoteString($database, $link) : null,
                $table !== null ? $this->quoteString($table, $link) : null,
                $column !== null ? $this->quoteString($column, $link) : null
            );
            $arrayKeys = QueryGenerator::getInformationSchemaColumns($database, $table, $column);

            return $this->fetchResult($sql, $arrayKeys, null, $link);
        }

        $columns = [];
        if ($database === null) {
            foreach ($this->getDatabaseList() as $database) {
                $columns[$database] = $this->getColumnsFull($database, null, null, $link);
            }

            return $columns;
        }

        if ($table === null) {
            $tables = $this->getTables($database);
            foreach ($tables as $table) {
                $columns[$table] = $this->getColumnsFull($database, $table, null, $link);
            }

            return $columns;
        }

        $sql = 'SHOW FULL COLUMNS FROM '
            . Util::backquote($database) . '.' . Util::backquote($table);
        if ($column !== null) {
            $sql .= " LIKE '" . $this->escapeString($column, $link) . "'";
        }

        $columns = $this->fetchResult($sql, 'Field', null, $link);

        $columns = Compatibility::getISCompatForGetColumnsFull($columns, $database, $table);

        if ($column !== null) {
            return reset($columns);
        }

        return $columns;
    }

    /**
     * Returns description of a $column in given table
     *
     * @param string $database name of database
     * @param string $table    name of table to retrieve columns from
     * @param string $column   name of column
     * @param bool   $full     whether to return full info or only column names
     * @param int    $link     link type
     *
     * @return array flat array description
     */
    public function getColumn(
        string $database,
        string $table,
        string $column,
        bool $full = false,
        int $link = self::CONNECT_USER
    ): array {
        $sql = QueryGenerator::getColumnsSql(
            $database,
            $table,
            $this->escapeMysqlLikeString($column),
            $full
        );
        /** @var array<string, array> $fields */
        $fields = $this->fetchResult($sql, 'Field', null, $link);

        $columns = $this->attachIndexInfoToColumns($database, $table, $fields);

        return array_shift($columns) ?? [];
    }

    /**
     * Returns descriptions of columns in given table
     *
     * @param string $database name of database
     * @param string $table    name of table to retrieve columns from
     * @param bool   $full     whether to return full info or only column names
     * @param int    $link     link type
     *
     * @return array<string, array> array indexed by column names
     */
    public function getColumns(
        string $database,
        string $table,
        bool $full = false,
        int $link = self::CONNECT_USER
    ): array {
        $sql = QueryGenerator::getColumnsSql(
            $database,
            $table,
            null,
            $full
        );
        /** @var array<string, array> $fields */
        $fields = $this->fetchResult($sql, 'Field', null, $link);

        return $this->attachIndexInfoToColumns($database, $table, $fields);
    }

    /**
     * Attach index information to the column definition
     *
     * @param string               $database name of database
     * @param string               $table    name of table to retrieve columns from
     * @param array<string, array> $fields   column array indexed by their names
     *
     * @return array<string, array> Column defintions with index information
     */
    private function attachIndexInfoToColumns(
        string $database,
        string $table,
        array $fields
    ): array {
        if (! $fields) {
            return [];
        }

        // Check if column is a part of multiple-column index and set its 'Key'.
        $indexes = Index::getFromTable($this, $table, $database);
        foreach ($fields as $field => $fieldData) {
            if (! empty($fieldData['Key'])) {
                continue;
            }

            foreach ($indexes as $index) {
                if (! $index->hasColumn($field)) {
                    continue;
                }

                $indexColumns = $index->getColumns();
                if ($indexColumns[$field]->getSeqInIndex() <= 1) {
                    continue;
                }

                if ($index->isUnique()) {
                    $fields[$field]['Key'] = 'UNI';
                } else {
                    $fields[$field]['Key'] = 'MUL';
                }
            }
        }

        return $fields;
    }

    /**
     * Returns all column names in given table
     *
     * @param string $database name of database
     * @param string $table    name of table to retrieve columns from
     * @param int    $link     mysql link resource
     *
     * @return string[]
     */
    public function getColumnNames(
        string $database,
        string $table,
        int $link = self::CONNECT_USER
    ): array {
        $sql = QueryGenerator::getColumnsSql($database, $table);

        // We only need the 'Field' column which contains the table's column names
        return $this->fetchResult($sql, null, 'Field', $link);
    }

    /**
     * Returns indexes of a table
     *
     * @param string $database name of database
     * @param string $table    name of the table whose indexes are to be retrieved
     * @param int    $link     mysql link resource
     *
     * @return array<int, array<string, string|null>>
     * @psalm-return array<int, array{
     *   Table: string,
     *   Non_unique: '0'|'1',
     *   Key_name: string,
     *   Seq_in_index: string,
     *   Column_name: string|null,
     *   Collation: 'A'|'D'|null,
     *   Cardinality: string,
     *   Sub_part: string|null,
     *   Packed: string|null,
     *   Null: string|null,
     *   Index_type: 'BTREE'|'FULLTEXT'|'HASH'|'RTREE',
     *   Comment: string,
     *   Index_comment: string,
     *   Ignored?: string,
     *   Visible?: string,
     *   Expression?: string|null
     * }>
     */
    public function getTableIndexes(
        string $database,
        string $table,
        int $link = self::CONNECT_USER
    ): array {
        $sql = QueryGenerator::getTableIndexesSql($database, $table);

        return $this->fetchResult($sql, null, null, $link);
    }

    /**
     * returns value of given mysql server variable
     *
     * @param string $var  mysql server variable name
     * @param int    $type DatabaseInterface::GETVAR_SESSION |
     *                     DatabaseInterface::GETVAR_GLOBAL
     * @param int    $link mysql link resource|object
     *
     * @return false|string|null value for mysql server variable
     */
    public function getVariable(
        string $var,
        int $type = self::GETVAR_SESSION,
        int $link = self::CONNECT_USER
    ) {
        switch ($type) {
            case self::GETVAR_SESSION:
                $modifier = ' SESSION';
                break;
            case self::GETVAR_GLOBAL:
                $modifier = ' GLOBAL';
                break;
            default:
                $modifier = '';
        }

        return $this->fetchValue('SHOW' . $modifier . ' VARIABLES LIKE \'' . $var . '\';', 1, $link);
    }

    /**
     * Sets new value for a variable if it is different from the current value
     *
     * @param string $var   variable name
     * @param string $value value to set
     * @param int    $link  mysql link resource|object
     */
    public function setVariable(
        string $var,
        string $value,
        int $link = self::CONNECT_USER
    ): bool {
        $currentValue = $this->getVariable($var, self::GETVAR_SESSION, $link);
        if ($currentValue == $value) {
            return true;
        }

        return (bool) $this->query('SET ' . $var . ' = ' . $value . ';', $link);
    }

    /**
     * Function called just after a connection to the MySQL database server has
     * been established. It sets the connection collation, and determines the
     * version of MySQL which is running.
     */
    public function postConnect(): void
    {
        $version = $this->fetchSingleRow('SELECT @@version, @@version_comment');

        if (is_array($version)) {
            $this->setVersion($version);
        }

        if ($this->versionInt > 50503) {
            $defaultCharset = 'utf8mb4';
            $defaultCollation = 'utf8mb4_general_ci';
        } else {
            $defaultCharset = 'utf8';
            $defaultCollation = 'utf8_general_ci';
        }

        $GLOBALS['collation_connection'] = $defaultCollation;
        $GLOBALS['charset_connection'] = $defaultCharset;
        $this->query(sprintf('SET NAMES \'%s\' COLLATE \'%s\';', $defaultCharset, $defaultCollation));

        /* Locale for messages */
        $locale = LanguageManager::getInstance()->getCurrentLanguage()->getMySQLLocale();
        if ($locale) {
            $this->query("SET lc_messages = '" . $locale . "';");
        }

        // Set timezone for the session, if required.
        if ($GLOBALS['cfg']['Server']['SessionTimeZone'] != '') {
            $sqlQueryTz = 'SET ' . Util::backquote('time_zone') . ' = '
                . $this->quoteString($GLOBALS['cfg']['Server']['SessionTimeZone']);

            if (! $this->tryQuery($sqlQueryTz)) {
                $errorMessageTz = sprintf(
                    __(
                        'Unable to use timezone "%1$s" for server %2$d. '
                        . 'Please check your configuration setting for '
                        . '[em]$cfg[\'Servers\'][%3$d][\'SessionTimeZone\'][/em]. '
                        . 'phpMyAdmin is currently using the default time zone '
                        . 'of the database server.'
                    ),
                    $GLOBALS['cfg']['Server']['SessionTimeZone'],
                    $GLOBALS['server'],
                    $GLOBALS['server']
                );

                trigger_error($errorMessageTz, E_USER_WARNING);
            }
        }

        /* Loads closest context to this version. */
        Context::loadClosest(($this->isMariaDb ? 'MariaDb' : 'MySql') . $this->versionInt);

        $this->databaseList = null;
    }

    /**
     * Sets collation connection for user link
     *
     * @param string $collation collation to set
     */
    public function setCollation(string $collation): void
    {
        $charset = $GLOBALS['charset_connection'];
        /* Automatically adjust collation if not supported by server */
        if ($charset === 'utf8' && str_starts_with($collation, 'utf8mb4_')) {
            $collation = 'utf8_' . substr($collation, 8);
        }

        $result = $this->tryQuery(
            'SET collation_connection = '
            . $this->quoteString($collation)
            . ';'
        );

        if ($result === false) {
            trigger_error(
                __('Failed to set configured collation connection!'),
                E_USER_WARNING
            );

            return;
        }

        $GLOBALS['collation_connection'] = $collation;
    }

    /**
     * Function called just after a connection to the MySQL database server has
     * been established. It sets the connection collation, and determines the
     * version of MySQL which is running.
     */
    public function postConnectControl(Relation $relation): void
    {
        // If Zero configuration mode enabled, check PMA tables in current db.
        if (! $GLOBALS['cfg']['ZeroConf']) {
            return;
        }

        $this->databaseList = null;

        $relation->initRelationParamsCache();
    }

    /**
     * returns a single value from the given result or query,
     * if the query or the result has more than one row or field
     * the first field of the first row is returned
     *
     * <code>
     * $sql = 'SELECT `name` FROM `user` WHERE `id` = 123';
     * $user_name = $dbi->fetchValue($sql);
     * // produces
     * // $user_name = 'John Doe'
     * </code>
     *
     * @param string     $query The query to execute
     * @param int|string $field field to fetch the value from,
     *                          starting at 0, with 0 being default
     * @param int        $link  link type
     *
     * @return string|false|null value of first field in first row from result or false if not found
     */
    public function fetchValue(
        string $query,
        $field = 0,
        int $link = self::CONNECT_USER
    ) {
        $result = $this->tryQuery($query, $link, self::QUERY_BUFFERED, false);
        if ($result === false) {
            return false;
        }

        return $result->fetchValue($field);
    }

    /**
     * Returns only the first row from the result or null if result is empty.
     *
     * <code>
     * $sql = 'SELECT * FROM `user` WHERE `id` = 123';
     * $user = $dbi->fetchSingleRow($sql);
     * // produces
     * // $user = array('id' => 123, 'name' => 'John Doe')
     * </code>
     *
     * @param string $query The query to execute
     * @param string $type  NUM|ASSOC|BOTH returned array should either numeric
     *                      associative or both
     * @param int    $link  link type
     * @psalm-param  DatabaseInterface::FETCH_NUM|DatabaseInterface::FETCH_ASSOC $type
     */
    public function fetchSingleRow(
        string $query,
        string $type = DbalInterface::FETCH_ASSOC,
        int $link = self::CONNECT_USER
    ): ?array {
        $result = $this->tryQuery($query, $link, self::QUERY_BUFFERED, false);
        if ($result === false) {
            return null;
        }

        return $this->fetchByMode($result, $type) ?: null;
    }

    /**
     * Returns row or element of a row
     *
     * @param array|string    $row   Row to process
     * @param string|int|null $value Which column to return
     *
     * @return mixed
     */
    private function fetchValueOrValueByIndex($row, $value)
    {
        return $value === null ? $row : $row[$value];
    }

    /**
     * returns array of rows with numeric or associative keys
     *
     * @param ResultInterface $result result set identifier
     * @param string          $mode   either self::FETCH_NUM, self::FETCH_ASSOC or self::FETCH_BOTH
     * @psalm-param self::FETCH_NUM|self::FETCH_ASSOC $mode
     */
    private function fetchByMode(ResultInterface $result, string $mode): array
    {
        if ($mode === self::FETCH_NUM) {
            return $result->fetchRow();
        }

        return $result->fetchAssoc();
    }

    /**
     * returns all rows in the resultset in one array
     *
     * <code>
     * $sql = 'SELECT * FROM `user`';
     * $users = $dbi->fetchResult($sql);
     * // produces
     * // $users[] = array('id' => 123, 'name' => 'John Doe')
     *
     * $sql = 'SELECT `id`, `name` FROM `user`';
     * $users = $dbi->fetchResult($sql, 'id');
     * // produces
     * // $users['123'] = array('id' => 123, 'name' => 'John Doe')
     *
     * $sql = 'SELECT `id`, `name` FROM `user`';
     * $users = $dbi->fetchResult($sql, 0);
     * // produces
     * // $users['123'] = array(0 => 123, 1 => 'John Doe')
     *
     * $sql = 'SELECT `id`, `name` FROM `user`';
     * $users = $dbi->fetchResult($sql, 'id', 'name');
     * // or
     * $users = $dbi->fetchResult($sql, 0, 1);
     * // produces
     * // $users['123'] = 'John Doe'
     *
     * $sql = 'SELECT `name` FROM `user`';
     * $users = $dbi->fetchResult($sql);
     * // produces
     * // $users[] = 'John Doe'
     *
     * $sql = 'SELECT `group`, `name` FROM `user`'
     * $users = $dbi->fetchResult($sql, array('group', null), 'name');
     * // produces
     * // $users['admin'][] = 'John Doe'
     *
     * $sql = 'SELECT `group`, `name` FROM `user`'
     * $users = $dbi->fetchResult($sql, array('group', 'name'), 'id');
     * // produces
     * // $users['admin']['John Doe'] = '123'
     * </code>
     *
     * @param string                $query query to execute
     * @param string|int|array|null $key   field-name or offset
     *                                     used as key for array
     *                                     or array of those
     * @param string|int|null       $value value-name or offset
     *                                     used as value for array
     * @param int                   $link  link type
     *
     * @return array resultrows or values indexed by $key
     */
    public function fetchResult(
        string $query,
        $key = null,
        $value = null,
        int $link = self::CONNECT_USER
    ): array {
        $resultRows = [];

        $result = $this->tryQuery($query, $link, self::QUERY_BUFFERED, false);

        // return empty array if result is empty or false
        if ($result === false) {
            return $resultRows;
        }

        $fetchFunction = self::FETCH_ASSOC;

        if ($key === null) {
            // no nested array if only one field is in result
            if ($result->numFields() === 1) {
                $value = 0;
                $fetchFunction = self::FETCH_NUM;
            }

            while ($row = $this->fetchByMode($result, $fetchFunction)) {
                $resultRows[] = $this->fetchValueOrValueByIndex($row, $value);
            }
        } elseif (is_array($key)) {
            while ($row = $this->fetchByMode($result, $fetchFunction)) {
                $resultTarget =& $resultRows;
                foreach ($key as $keyIndex) {
                    if ($keyIndex === null) {
                        $resultTarget =& $resultTarget[];
                        continue;
                    }

                    if (! isset($resultTarget[$row[$keyIndex]])) {
                        $resultTarget[$row[$keyIndex]] = [];
                    }

                    $resultTarget =& $resultTarget[$row[$keyIndex]];
                }

                $resultTarget = $this->fetchValueOrValueByIndex($row, $value);
            }
        } else {
            // if $key is an integer use non associative mysql fetch function
            if (is_int($key)) {
                $fetchFunction = self::FETCH_NUM;
            }

            while ($row = $this->fetchByMode($result, $fetchFunction)) {
                $resultRows[$row[$key]] = $this->fetchValueOrValueByIndex($row, $value);
            }
        }

        return $resultRows;
    }

    /**
     * Get supported SQL compatibility modes
     *
     * @return array supported SQL compatibility modes
     */
    public function getCompatibilities(): array
    {
        return [
            'NONE',
            'ANSI',
            'DB2',
            'MAXDB',
            'MYSQL323',
            'MYSQL40',
            'MSSQL',
            'ORACLE',
            // removed; in MySQL 5.0.33, this produces exports that
            // can't be read by POSTGRESQL (see our bug #1596328)
            // 'POSTGRESQL',
            'TRADITIONAL',
        ];
    }

    /**
     * returns warnings for last query
     *
     * @param int $link link type
     *
     * @return Warning[] warnings
     */
    public function getWarnings(int $link = self::CONNECT_USER): array
    {
        $result = $this->tryQuery('SHOW WARNINGS', $link, 0, false);
        if ($result === false) {
            return [];
        }

        $warnings = [];
        while ($row = $result->fetchAssoc()) {
            $warnings[] = Warning::fromArray($row);
        }

        return $warnings;
    }

    /**
     * gets the current user with host
     *
     * @return string the current user i.e. user@host
     */
    public function getCurrentUser(): string
    {
        if (SessionCache::has('mysql_cur_user')) {
            return SessionCache::get('mysql_cur_user');
        }

        $user = $this->fetchValue('SELECT CURRENT_USER();');
        if ($user !== false) {
            SessionCache::set('mysql_cur_user', $user);

            return $user;
        }

        return '@';
    }

    public function isSuperUser(): bool
    {
        if (SessionCache::has('is_superuser')) {
            return (bool) SessionCache::get('is_superuser');
        }

        if (! $this->isConnected()) {
            return false;
        }

        $result = $this->tryQuery('SELECT 1 FROM mysql.user LIMIT 1');
        $isSuperUser = false;

        if ($result) {
            $isSuperUser = (bool) $result->numRows();
        }

        SessionCache::set('is_superuser', $isSuperUser);

        return $isSuperUser;
    }

    public function isGrantUser(): bool
    {
        if (SessionCache::has('is_grantuser')) {
            return (bool) SessionCache::get('is_grantuser');
        }

        if (! $this->isConnected()) {
            return false;
        }

        $hasGrantPrivilege = false;

        if ($GLOBALS['cfg']['Server']['DisableIS']) {
            $grants = $this->getCurrentUserGrants();

            foreach ($grants as $grant) {
                if (str_contains($grant, 'WITH GRANT OPTION')) {
                    $hasGrantPrivilege = true;
                    break;
                }
            }

            SessionCache::set('is_grantuser', $hasGrantPrivilege);

            return $hasGrantPrivilege;
        }

        [$user, $host] = $this->getCurrentUserAndHost();
        $query = QueryGenerator::getInformationSchemaDataForGranteeRequest($user, $host);
        $result = $this->tryQuery($query);

        if ($result) {
            $hasGrantPrivilege = (bool) $result->numRows();
        }

        SessionCache::set('is_grantuser', $hasGrantPrivilege);

        return $hasGrantPrivilege;
    }

    public function isCreateUser(): bool
    {
        if (SessionCache::has('is_createuser')) {
            return (bool) SessionCache::get('is_createuser');
        }

        if (! $this->isConnected()) {
            return false;
        }

        $hasCreatePrivilege = false;

        if ($GLOBALS['cfg']['Server']['DisableIS']) {
            $grants = $this->getCurrentUserGrants();

            foreach ($grants as $grant) {
                if (str_contains($grant, 'ALL PRIVILEGES ON *.*') || str_contains($grant, 'CREATE USER')) {
                    $hasCreatePrivilege = true;
                    break;
                }
            }

            SessionCache::set('is_createuser', $hasCreatePrivilege);

            return $hasCreatePrivilege;
        }

        [$user, $host] = $this->getCurrentUserAndHost();
        $query = QueryGenerator::getInformationSchemaDataForCreateRequest($user, $host);
        $result = $this->tryQuery($query);

        if ($result) {
            $hasCreatePrivilege = (bool) $result->numRows();
        }

        SessionCache::set('is_createuser', $hasCreatePrivilege);

        return $hasCreatePrivilege;
    }

    public function isConnected(): bool
    {
        return isset($this->links[self::CONNECT_USER]);
    }

    /**
     * @return string[]
     */
    private function getCurrentUserGrants(): array
    {
        /** @var string[] $grants */
        $grants = $this->fetchResult('SHOW GRANTS FOR CURRENT_USER();');

        return $grants;
    }

    /**
     * Get the current user and host
     *
     * @return array<int, string> array of username and hostname
     */
    public function getCurrentUserAndHost(): array
    {
        if ($this->currentUserAndHost === null) {
            $user = $this->getCurrentUser();
            $this->currentUserAndHost = explode('@', $user);
        }

        return $this->currentUserAndHost;
    }

    /**
     * Returns value for lower_case_table_names variable
     *
     * @see https://mariadb.com/kb/en/server-system-variables/#lower_case_table_names
     * @see https://dev.mysql.com/doc/refman/en/server-system-variables.html#sysvar_lower_case_table_names
     *
     * @psalm-return 0|1|2
     */
    public function getLowerCaseNames(): int
    {
        if ($this->lowerCaseTableNames === null) {
            $value = (int) $this->fetchValue('SELECT @@lower_case_table_names');
            $this->lowerCaseTableNames = $value >= 0 && $value <= 2 ? $value : 0;
        }

        return $this->lowerCaseTableNames;
    }

    /**
     * connects to the database server
     *
     * @param int        $mode   Connection mode on of CONNECT_USER, CONNECT_CONTROL
     *                           or CONNECT_AUXILIARY.
     * @param array|null $server Server information like host/port/socket/persistent
     * @param int|null   $target How to store connection link, defaults to $mode
     *
     * @return object|false false on error or a connection object on success
     */
    public function connect(int $mode, ?array $server = null, ?int $target = null)
    {
        [$user, $password, $server] = Config::getConnectionParams($mode, $server);

        if ($target === null) {
            $target = $mode;
        }

        if ($user === null || $password === null || ! is_array($server)) {
            trigger_error(
                __('Missing connection parameters!'),
                E_USER_WARNING
            );

            return false;
        }

        $server['host'] = ! is_string($server['host']) || $server['host'] === '' ? 'localhost' : $server['host'];

        // Do not show location and backtrace for connection errors
        $GLOBALS['errorHandler']->setHideLocation(true);
        $result = $this->extension->connect($user, $password, new Server($server));
        $GLOBALS['errorHandler']->setHideLocation(false);

        if (is_object($result)) {
            $this->links[$target] = $result;
            /* Run post connect for user connections */
            if ($target == self::CONNECT_USER) {
                $this->postConnect();
            }

            return $result;
        }

        if ($mode == self::CONNECT_CONTROL) {
            trigger_error(
                __(
                    'Connection for controluser as defined in your configuration failed.'
                ),
                E_USER_WARNING
            );

            return false;
        }

        if ($mode == self::CONNECT_AUXILIARY) {
            // Do not go back to main login if connection failed
            // (currently used only in unit testing)
            return false;
        }

        return $result;
    }

    /**
     * selects given database
     *
     * @param string|DatabaseName $dbname database name to select
     * @param int                 $link   link type
     */
    public function selectDb($dbname, int $link = self::CONNECT_USER): bool
    {
        if (! isset($this->links[$link])) {
            return false;
        }

        return $this->extension->selectDb($dbname, $this->links[$link]);
    }

    /**
     * Check if there are any more query results from a multi query
     *
     * @param int $link link type
     */
    public function moreResults(int $link = self::CONNECT_USER): bool
    {
        if (! isset($this->links[$link])) {
            return false;
        }

        return $this->extension->moreResults($this->links[$link]);
    }

    /**
     * Prepare next result from multi_query
     *
     * @param int $link link type
     */
    public function nextResult(int $link = self::CONNECT_USER): bool
    {
        if (! isset($this->links[$link])) {
            return false;
        }

        return $this->extension->nextResult($this->links[$link]);
    }

    /**
     * Store the result returned from multi query
     *
     * @param int $link link type
     *
     * @return ResultInterface|false false when empty results / result set when not empty
     */
    public function storeResult(int $link = self::CONNECT_USER)
    {
        if (! isset($this->links[$link])) {
            return false;
        }

        return $this->extension->storeResult($this->links[$link]);
    }

    /**
     * Returns a string representing the type of connection used
     *
     * @param int $link link type
     *
     * @return string|bool type of connection used
     */
    public function getHostInfo(int $link = self::CONNECT_USER)
    {
        if (! isset($this->links[$link])) {
            return false;
        }

        return $this->extension->getHostInfo($this->links[$link]);
    }

    /**
     * Returns the version of the MySQL protocol used
     *
     * @param int $link link type
     *
     * @return int|bool version of the MySQL protocol used
     */
    public function getProtoInfo(int $link = self::CONNECT_USER)
    {
        if (! isset($this->links[$link])) {
            return false;
        }

        return $this->extension->getProtoInfo($this->links[$link]);
    }

    /**
     * returns a string that represents the client library version
     *
     * @return string MySQL client library version
     */
    public function getClientInfo(): string
    {
        return $this->extension->getClientInfo();
    }

    /**
     * Returns last error message or an empty string if no errors occurred.
     *
     * @param int $link link type
     */
    public function getError(int $link = self::CONNECT_USER): string
    {
        if (! isset($this->links[$link])) {
            return '';
        }

        return $this->extension->getError($this->links[$link]);
    }

    /**
     * returns the number of rows returned by last query
     * used with tryQuery as it accepts false
     *
     * @param string $query query to run
     *
     * @return string|int
     * @psalm-return int|numeric-string
     */
    public function queryAndGetNumRows(string $query)
    {
        $result = $this->tryQuery($query);

        if (! $result) {
            return 0;
        }

        return $result->numRows();
    }

    /**
     * returns last inserted auto_increment id for given $link
     * or $GLOBALS['userlink']
     *
     * @param int $link link type
     */
    public function insertId(int $link = self::CONNECT_USER): int
    {
        // If the primary key is BIGINT we get an incorrect result
        // (sometimes negative, sometimes positive)
        // and in the present function we don't know if the PK is BIGINT
        // so better play safe and use LAST_INSERT_ID()
        //
        // When no controluser is defined, using mysqli_insert_id($link)
        // does not always return the last insert id due to a mixup with
        // the tracking mechanism, but this works:
        return (int) $this->fetchValue('SELECT LAST_INSERT_ID();', 0, $link);
    }

    /**
     * returns the number of rows affected by last query
     *
     * @param int  $link         link type
     * @param bool $getFromCache whether to retrieve from cache
     *
     * @return int|string
     * @psalm-return int|numeric-string
     */
    public function affectedRows(
        int $link = self::CONNECT_USER,
        bool $getFromCache = true
    ) {
        if (! isset($this->links[$link])) {
            return -1;
        }

        if ($getFromCache) {
            return $GLOBALS['cached_affected_rows'];
        }

        return $this->extension->affectedRows($this->links[$link]);
    }

    /**
     * returns metainfo for fields in $result
     *
     * @param ResultInterface $result result set identifier
     *
     * @return FieldMetadata[] meta info for fields in $result
     */
    public function getFieldsMeta(ResultInterface $result): array
    {
        $fields = $result->getFieldsMeta();

        if ($this->getLowerCaseNames() === 2) {
            /**
             * Fixup orgtable for lower_case_table_names = 2
             *
             * In this setup MySQL server reports table name lower case
             * but we still need to operate on original case to properly
             * match existing strings
             */
            foreach ($fields as $value) {
                if (
                    strlen($value->orgtable) === 0 ||
                        mb_strtolower($value->orgtable) !== mb_strtolower($value->table)
                ) {
                    continue;
                }

                $value->orgtable = $value->table;
            }
        }

        return $fields;
    }

    /**
     * Returns properly quoted string for use in MySQL queries.
     *
     * @param string $str  string to be quoted
     * @param int    $link optional database link to use
     *
     * @psalm-return non-empty-string
     *
     * @psalm-taint-escape sql
     */
    public function quoteString(string $str, int $link = self::CONNECT_USER): string
    {
        return "'" . $this->extension->escapeString($this->links[$link], $str) . "'";
    }

    /**
     * returns properly escaped string for use in MySQL queries
     *
     * @deprecated Use {@see quoteString()} instead.
     *
     * @param string $str  string to be escaped
     * @param int    $link optional database link to use
     *
     * @return string a MySQL escaped string
     */
    public function escapeString(string $str, int $link = self::CONNECT_USER): string
    {
        if (isset($this->links[$link])) {
            return $this->extension->escapeString($this->links[$link], $str);
        }

        return $str;
    }

    /**
     * returns properly escaped string for use in MySQL LIKE clauses
     *
     * @param string $str  string to be escaped
     * @param int    $link optional database link to use
     *
     * @return string a MySQL escaped LIKE string
     */
    public function escapeMysqlLikeString(string $str, int $link = self::CONNECT_USER)
    {
        return $this->escapeString(strtr($str, ['\\' => '\\\\', '_' => '\\_', '%' => '\\%']), $link);
    }

    /**
     * Checks if this database server is running on Amazon RDS.
     */
    public function isAmazonRds(): bool
    {
        if (SessionCache::has('is_amazon_rds')) {
            return (bool) SessionCache::get('is_amazon_rds');
        }

        $sql = 'SELECT @@basedir';
        $result = (string) $this->fetchValue($sql);
        $rds = str_starts_with($result, '/rdsdbbin/');
        SessionCache::set('is_amazon_rds', $rds);

        return $rds;
    }

    /**
     * Gets SQL for killing a process.
     *
     * @param int $process Process ID
     */
    public function getKillQuery(int $process): string
    {
        if ($this->isAmazonRds()) {
            return 'CALL mysql.rds_kill(' . $process . ');';
        }

        return 'KILL ' . $process . ';';
    }

    /**
     * Get the phpmyadmin database manager
     */
    public function getSystemDatabase(): SystemDatabase
    {
        return new SystemDatabase($this);
    }

    /**
     * Get a table with database name and table name
     *
     * @param string $dbName    DB name
     * @param string $tableName Table name
     */
    public function getTable(string $dbName, string $tableName): Table
    {
        return new Table($tableName, $dbName, $this);
    }

    /**
     * returns collation of given db
     *
     * @param string $db name of db
     *
     * @return string  collation of $db
     */
    public function getDbCollation(string $db): string
    {
        if (Utilities::isSystemSchema($db)) {
            // We don't have to check the collation of the virtual
            // information_schema database: We know it!
            return 'utf8_general_ci';
        }

        if (! $GLOBALS['cfg']['Server']['DisableIS']) {
            // this is slow with thousands of databases
            $sql = 'SELECT DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA'
                . ' WHERE SCHEMA_NAME = ' . $this->quoteString($db)
                . ' LIMIT 1';

            return (string) $this->fetchValue($sql);
        }

        $this->selectDb($db);
        $return = (string) $this->fetchValue('SELECT @@collation_database');
        if ($db !== $GLOBALS['db']) {
            $this->selectDb($GLOBALS['db']);
        }

        return $return;
    }

    /**
     * returns default server collation from show variables
     */
    public function getServerCollation(): string
    {
        return (string) $this->fetchValue('SELECT @@collation_server');
    }

    /**
     * Server version as number
     *
     * @example 80011
     */
    public function getVersion(): int
    {
        return $this->versionInt;
    }

    /**
     * Server version
     */
    public function getVersionString(): string
    {
        return $this->versionString;
    }

    /**
     * Server version comment
     */
    public function getVersionComment(): string
    {
        return $this->versionComment;
    }

    /**
     * Whether connection is MariaDB
     */
    public function isMariaDB(): bool
    {
        return $this->isMariaDb;
    }

    /**
     * Whether connection is PerconaDB
     */
    public function isPercona(): bool
    {
        return $this->isPercona;
    }

    /**
     * Set version
     *
     * @param array $version Database version information
     * @phpstan-param array<array-key, mixed> $version
     */
    public function setVersion(array $version): void
    {
        $this->versionString = $version['@@version'] ?? '';
        $this->versionInt = Utilities::versionToInt($this->versionString);
        $this->versionComment = $version['@@version_comment'] ?? '';

        $this->isMariaDb = stripos($this->versionString, 'mariadb') !== false;
        $this->isPercona = stripos($this->versionComment, 'percona') !== false;
    }

    /**
     * Load correct database driver
     *
     * @param DbiExtension|null $extension Force the use of an alternative extension
     */
    public static function load(?DbiExtension $extension = null): self
    {
        if ($extension !== null) {
            return new self($extension);
        }

        return new self(new DbiMysqli());
    }

    /**
     * Prepare an SQL statement for execution.
     *
     * @param string $query The query, as a string.
     * @param int    $link  Link type.
     *
     * @return object|false A statement object or false.
     */
    public function prepare(string $query, int $link = self::CONNECT_USER)
    {
        return $this->extension->prepare($this->links[$link], $query);
    }

    public function getDatabaseList(): ListDatabase
    {
        if ($this->databaseList === null) {
            $this->databaseList = new ListDatabase();
        }

        return $this->databaseList;
    }
}
