<?php

namespace Analyzer;

use Dotenv;

class Connection
{
    /**
     * Подключение к базе данных и возврат экземпляра объекта \PDO
     * @return \PDO
     * @throws \Exception
     */
    public function connect()
    {
        $params = parse_url($_ENV['DATABASE_URL']);

        $dbName = ltrim($params['path'], '/');
        $host = $params['host'];
        $port = $params['port'];
        $user = $params['user'];
        $pass = $params['pass'];

        if ($host === "") {
            throw new \Exception("Error reading environment variable DATABASE_URL");
        }

        // подключение к базе данных postgresql
        $conStr = sprintf(
            "pgsql:host=%s;port=%d;dbname=%s;user=%s;password=%s",
            $host,
            $port,
            $dbName,
            $user,
            $pass
        );

        $pdo = new \PDO($conStr);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        return $pdo;
    }

    public function __construct()
    {
    }
}
