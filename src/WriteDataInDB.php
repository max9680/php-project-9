<?php

namespace Analyzer;

function writeDataInDB(\PDO $pdo, \DiDom\Document $document, int $id, \Carbon\Carbon $nowTime, int $statusCode)
{
    $h1 = optional($document->first('h1'))->text();
    $title = optional($document->first('title'))->text();
    $description = optional($document->first('meta[name=description]'))->getAttribute('content');

    $arrVars = [$id, $nowTime, $statusCode, $h1, $title, $description];

    $stm = $pdo->prepare("INSERT INTO
                        url_checks (url_id, created_at, status_code, h1, title, description)
                        VALUES (?, ?, ?, ?, ?, ?)");
    $stm->execute($arrVars);

    return $pdo->lastInsertId();
}
