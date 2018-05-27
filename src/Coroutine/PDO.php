<?php

/**
 * Copyright: Swlib
 * Author: Twosee <twose@qq.com>
 * Modifier: Albert Chen
 * License: Apache 2.0
 */

namespace SwooleTW\Http\Coroutine;

use PDO as BasePDO;
use SwooleTW\Http\Coroutine\PDOStatement;

class PDO extends BasePDO
{
    public static $keyMap = [
        'dbname' => 'database'
    ];

    private static $defaultOptions = [
        'host' => '',
        'port' => 3306,
        'user' => '',
        'password' => '',
        'database' => '',
        'charset' => 'utf8mb4',
        'timeout' => 1.000,
        'strict_type' => true
    ];

    /** @var \Swoole\Coroutine\Mysql */
    public $client;

    public $inTransaction = false;

    public function __construct(
        string $dsn,
        string $username = '',
        string $password = '',
        array $driverOptions = []
    ) {
        $options = $this->getOptions(...func_get_args());
        $this->setClient($options);
    }

    public function setClient(array $options = [], $client = null)
    {
        $this->client = $client ?: new \Swoole\Coroutine\Mysql();
        $this->client->connect($options);

        return $this;
    }

    private function getOptions($dsn, $username, $password, $driverOptions)
    {
        $dsn = explode(':', $dsn);
        $driver = ucwords(array_shift($dsn));
        $dsn = explode(';', implode(':', $dsn));
        $options = [];

        static::checkDriver($driver);

        foreach ($dsn as $kv) {
            $kv = explode('=', $kv);
            if ($kv) {
                $options[$kv[0]] = $kv[1] ?? '';
            }
        }

        $authorization = [
            'user' => $username,
            'password' => $password,
        ];

        $options = $driverOptions + $authorization + $options;

        foreach (static::$keyMap as $pdoKey => $swpdoKey) {
            if (isset($options[$pdoKey])) {
                $options[$swpdoKey] = $options[$pdoKey];
                unset($options[$pdoKey]);
            }
        }

        return $options + static::$defaultOptions;
    }

    public static function checkDriver(string $driver)
    {
        if (! in_array($driver, static::getAvailableDrivers())) {
            throw new \InvalidArgumentException("{$driver} driver is not supported yet.");
        }
    }

    public static function getAvailableDrivers()
    {
        return ['Mysql'];
    }

    public function beginTransaction()
    {
        $this->client->begin();
        $this->inTransaction = true;
    }

    public function rollBack()
    {
        $this->client->rollback();
        $this->inTransaction = false;
    }

    public function commit()
    {
        $this->client->commit();
        $this->inTransaction = true;
    }

    public function inTransaction()
    {
        return $this->client->connect_errno;
    }

    public function lastInsertId($seqname = null)
    {
        return $this->client->insert_id;
    }

    public function errorCode()
    {
        $this->client->errno;
    }

    public function errorInfo()
    {
        return $this->client->errno;
    }

    public function exec($statement): int
    {
        $this->query($statement);

        return $this->client->affected_rows;
    }

    public function query(string $statement, float $timeout = 1.000)
    {
        return new PDOStatement($this, $statement, ['timeout' => $timeout]);
    }

    private function rewriteToPosition(string $statement)
    {
        //
    }

    public function prepare($statement, $driverOptions = null)
    {
        $driverOptions = is_null($driverOptions) ? [] : $driverOptions;
        if (strpos($statement, ':') !== false) {
            $i = 0;
            $bindKeyMap = [];
            $statement = preg_replace_callback(
                '/:(\w+)\b/',
                function ($matches) use (&$i, &$bindKeyMap) {
                    $bindKeyMap[$matches[1]] = $i++;

                    return '?';
                },
                $statement
            );
        }

        $stmtObj = $this->client->prepare($statement);

        if ($stmtObj) {
            $stmtObj->bindKeyMap = $bindKeyMap ?? [];
            return new PDOStatement($this, $stmtObj, $driverOptions);
        } else {
            return false;
        }
    }

    public function getAttribute($attribute)
    {
        switch ($attribute) {
            case \PDO::ATTR_AUTOCOMMIT:
                return true;
            case \PDO::ATTR_CASE:
            case \PDO::ATTR_CLIENT_VERSION:
            case \PDO::ATTR_CONNECTION_STATUS:
                return $this->client->connected;
            case \PDO::ATTR_DRIVER_NAME:
            case \PDO::ATTR_ERRMODE:
                return 'Swoole Style';
            case \PDO::ATTR_ORACLE_NULLS:
            case \PDO::ATTR_PERSISTENT:
            case \PDO::ATTR_PREFETCH:
            case \PDO::ATTR_SERVER_INFO:
                return $this->serverInfo['timeout'] ?? self::$defaultOptions['timeout'];
            case \PDO::ATTR_SERVER_VERSION:
                return 'Swoole Mysql';
            case \PDO::ATTR_TIMEOUT:
            default:
                throw new \InvalidArgumentException('Not implemented yet!');
        }
    }

    public function quote($string, $paramtype = null)
    {
        throw new \BadMethodCallException(<<<TXT
If you are using this function to build SQL statements,
you are strongly recommended to use PDO::prepare() to prepare SQL statements
with bound parameters instead of using PDO::quote() to interpolate user input into an SQL statement.
Prepared statements with bound parameters are not only more portable, more convenient,
immune to SQL injection, but are often much faster to execute than interpolated queries,
as both the server and client side can cache a compiled form of the query.
TXT
        );
    }
}
