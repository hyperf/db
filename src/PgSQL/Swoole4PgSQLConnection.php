<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */
namespace Hyperf\DB\PgSQL;

use Closure;
use Hyperf\DB\AbstractConnection;
use Hyperf\DB\Exception\QueryException;
use Hyperf\DB\Exception\RuntimeException;
use Hyperf\Pool\Pool;
use Psr\Container\ContainerInterface;
use Swoole\Coroutine\PostgreSQL;

class Swoole4PgSQLConnection extends AbstractConnection
{
    /**
     * @var PostgreSQL
     */
    protected $connection;

    protected array $config = [
        'driver' => 'pgsql',
        'host' => '127.0.0.1',
        'port' => 5432,
        'database' => 'postgres',
        'username' => 'postgres',
        'password' => '',
        'pool' => [
            'min_connections' => 1,
            'max_connections' => 32,
            'connect_timeout' => 10.0,
            'wait_timeout' => 3.0,
            'heartbeat' => -1,
            'max_idle_time' => 60.0,
        ],
    ];

    public function __construct(ContainerInterface $container, Pool $pool, array $config)
    {
        parent::__construct($container, $pool);
        $this->config = array_replace_recursive($this->config, $config);
        $this->reconnect();
    }

    public function reconnect(): bool
    {
        $connection = new PostgreSQL();
        $result = $connection->connect(sprintf(
            'host=%s port=%s dbname=%s user=%s password=%s',
            $this->config['host'],
            $this->config['port'],
            $this->config['database'],
            $this->config['username'],
            $this->config['password']
        ));

        if ($result === false) {
            throw new RuntimeException($connection->error);
        }

        $this->connection = $connection;
        $this->lastUseTime = microtime(true);
        $this->transactions = 0;
        return true;
    }

    public function close(): bool
    {
        unset($this->connection);

        return true;
    }

    public function insert(string $query, array $bindings = []): int
    {
        throw new QueryException('cannot support insert.');
    }

    public function execute(string $query, array $bindings = []): int
    {
        $statement = $this->prepare($query);

        $result = $this->connection->execute($statement, $bindings);
        if ($result === false) {
            throw new QueryException($this->connection->error);
        }

        return $this->connection->affectedRows($result);
    }

    public function exec(string $sql): int
    {
        $result = $this->connection->query($sql);

        return $this->connection->affectedRows($result);
    }

    public function query(string $query, array $bindings = []): array
    {
        $statement = $this->prepare($query);

        $result = $this->connection->execute($statement, $bindings);
        if ($result === false) {
            throw new QueryException($this->connection->error);
        }

        return $this->connection->fetchAll($result) ?: [];
    }

    public function fetch(string $query, array $bindings = [])
    {
        $records = $this->query($query, $bindings);

        return array_shift($records);
    }

    public function call(string $method, array $argument = [])
    {
        switch ($method) {
            case 'beginTransaction':
                return $this->connection->query('BEGIN');
            case 'rollBack':
                return $this->connection->query('ROLLBACK');
            case 'commit':
                return $this->connection->query('COMMIT');
        }

        return $this->connection->{$method}(...$argument);
    }

    public function run(Closure $closure)
    {
        return $closure->call($this, $this->connection);
    }

    protected function prepare(string $query): string
    {
        $id = uniqid();

        $res = $this->connection->prepare($id, $query);
        if ($res === false) {
            throw new QueryException($this->connection->error);
        }

        return $id;
    }
}
