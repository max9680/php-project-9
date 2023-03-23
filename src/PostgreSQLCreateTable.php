<?php

namespace PostgreSQLTutorial;

/**
 * Создание в PostgreSQL таблицы из демонстрации PHP
 */
class PostgreSQLCreateTable
{
    /**
     * объект PDO
     * @var \PDO
     */
    private $pdo;

    /**
     * инициализация объекта с объектом \PDO
     * @тип параметра $pdo
     */
    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * создание таблиц
     */
    public function createTables()
    {
        $sql = 'CREATE TABLE urls_test (
            -- id bigint PRIMARY KEY,
            name varchar(255)
            );';

        $this->pdo->exec($sql);

        return $this;
    }

    public function insertName($name)
    {
        // подготовка запроса для добавления данных
        $sql = 'INSERT INTO urls_test(name) VALUES(:name)';
        $stmt = $this->pdo->prepare($sql);

        $stmt->bindValue(':name', $name);

        $stmt->execute();

        // возврат полученного значения id
        return $this->pdo->lastInsertId('name');
    }
}