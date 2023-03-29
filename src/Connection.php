<?php

namespace Analyzer;

/**
 * Создание класса Connection
 */
final class Connection
{
    /**
     * Connection
     * тип @var
     */
    private static ?Connection $conn = null;

    /**
     * Подключение к базе данных и возврат экземпляра объекта \PDO
     * @return \PDO
     * @throws \Exception
     */
    public function connect()
    {
        // чтение параметров в файле конфигурации ini
        $params = parse_ini_file('database.ini');

        // $dbUrl = parse_url(getenv('DATABASE_URL'));
        // $params = parse_url($_ENV['DATABASE_URL']);

        if ($params === false) {
            // throw new \Exception("Error reading database configuration file");
            throw new \Exception("Error reading environment variable DATABASE_URL");
        }

        // $dbName = ltrim($dbUrl['path'], '/');
        // $host = $dbUrl['host'];
        // $port = $dbUrl['port'];
        // $user = $dbUrl['user'];
        // $pass = $dbUrl['pass'];
        // подключение к базе данных postgresql
        $conStr = sprintf(
            "pgsql:host=%s;port=%d;dbname=%s;user=%s;password=%s",
            // $host,
            // $port,
            // $dbName,
            // $user,
            // $pass
            $params['host'],
            $params['port'],
            $params['database'],
            $params['user'],
            $params['pass']
        );

//         var_dump($conStr);
// die;
        $pdo = new \PDO($conStr);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        return $pdo;
    }

    /**
     * возврат экземпляра объекта Connection
     * тип @return
     */
    public static function get()
    {
        if (null === static::$conn) {
            static::$conn = new self();
        }

        return static::$conn;
    }

    protected function __construct()
    {

    }
}