<?php

require __DIR__ . '/../vendor/autoload.php';

use Analyzer\Connection;
use Carbon\Carbon;
use Slim\Factory\AppFactory;
use DI\Container;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ClientException;
use DiDom\Document;

Valitron\Validator::lang('ru');

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->safeload();

$container = new Container();

session_start();

$container->set('pdo', function () {

    $pdo = new Connection();

    try {
        $pdo = $pdo->connect();
        // echo 'A connection to the PostgreSQL database sever has been established successfully.';
    } catch (\PDOException $e) {
        echo $e->getMessage();
    }
    return $pdo;
});

$container->set('renderer', function () {
    return new \Slim\Views\PhpRenderer(__DIR__ . '/../templates');
});
$container->set('flash', function () {
    return new \Slim\Flash\Messages();
});

$app = AppFactory::createFromContainer($container);

$app->addErrorMiddleware(true, true, true);

$app->get('/', function ($request, $response) {

    return $this->get('renderer')->render($response, 'index.phtml');
})->setName('index');


$app->get('/urls', function ($request, $response) {

    $urls = $this->get('pdo')->query("SELECT * FROM urls ORDER BY id DESC")->fetchAll();

    $checks = $this->get('pdo')->query("SELECT * FROM url_checks ORDER BY id DESC")->fetchAll();
    $collectionChecks = collect($checks);

    $urlsWCheck = array_map(function ($url) use ($collectionChecks) {
        $resultArray = $url;
        $check = $collectionChecks->firstWhere('url_id', $url['id']);
        $resultArray['lastcheck'] = $check['created_at'] ?? null;
        $resultArray['status_code'] = $check['status_code'] ?? null;
        return $resultArray;
    }, $urls);

    $params = [
        'urls' => $urlsWCheck
    ];

    return $this->get('renderer')->render($response, 'urls/index.phtml', $params);
})->setName('urls.index');

$app->get('/urls/{id}', function ($request, $response, array $args) {
    $messages = $this->get('flash')->getMessages();

    $id = $args['id'];

    $sql = "SELECT * FROM urls WHERE id = ?";
    $stm = $this->get('pdo')->prepare($sql);
    $stm->execute([$id]);
    $url = $stm->fetch();

    $sql = "SELECT * FROM url_checks WHERE url_id = ? ORDER BY id DESC";
    $stm = $this->get('pdo')->prepare($sql);
    $stm->execute([$id]);
    $checks = $stm->fetchAll();

    $params = [
        'url' => $url,
        'flash' => $messages,
        'checks' => $checks
    ];

    return $this->get('renderer')->render($response, 'urls/show.phtml', $params);
})->setName('urls.show');

$router = $app->getRouteCollector()->getRouteParser();

$app->post('/urls', function ($request, $response) use ($router) {
    $url = $request->getParsedBodyParam('url');

    $v = new Valitron\Validator(['URL' => $url['name']]);

    $v->rule('required', 'URL')->message('{field} не должен быть пустым');
    $v->rule('url', 'URL')->message('Некорректный {field}');

    if (!$v->validate()) {
        $params = [
            'error' => $v->errors(),
            'url' => $url
        ];

        return $this->get('renderer')->render($response->withStatus(422), 'index.phtml', $params);
    }

    $parsedUrl = parse_url($url['name']);
    $urlForInput = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];

    $sql = "SELECT id FROM urls WHERE name = ?";
    $stm = $this->get('pdo')->prepare($sql);
    $stm->execute([$urlForInput]);
    $urls = $stm->fetchAll(\PDO::FETCH_COLUMN);

    if (count($urls) > 0) {
        $this->get('flash')->addMessage('success', 'Страница уже существует');

        $id = $urls[0];

        return $response->withHeader('Location', $router->urlFor('urls.show', ['id' => $id]))->withStatus(301);
    }

    $nowTime = Carbon::now();
    $arrVars = [$urlForInput, $nowTime];

    $sql = "INSERT INTO urls (name, created_at) VALUES (?,?)";
    $stm = $this->get('pdo')->prepare($sql);
    $stm->execute($arrVars);

    $id = $this->get('pdo')->lastInsertId();

    $this->get('flash')->addMessage('success', 'Страница успешно добавлена');

    return $response->withHeader('Location', $router->urlFor('urls.show', ['id' => $id]))->withStatus(301);
})->setName('urls.store');

$app->post('/urls/{id}/checks', function ($request, $response, array $args) use ($router) {
    $id = $args['id'];

    $sql = "SELECT name FROM urls WHERE id = ?";
    $stm = $this->get('pdo')->prepare($sql);
    $stm->execute([$id]);
    $urlName = $stm->fetchAll(\PDO::FETCH_COLUMN);

    $client = new Client([
        'timeout'  => 3.0,
    ]);

    $nowTime = Carbon::now();

    try {
        $answer = $client->request('GET', $urlName[0]);
    } catch (RequestException $e) {
        $statusCode = null;

        if ($e->getResponse() !== null) {
            $statusCode = $e->getResponse()->getStatusCode();
            $content = $e->getResponse()->getBody()->getContents();
            $document = new Document($content);

            $h1 = optional($document->first('h1'))->text();
            $title = optional($document->first('title'))->text();
            $description = optional($document->first('meta[name=description]'))->getAttribute('content');

            $arrVars = [$id, $nowTime, $statusCode, $h1, $title, $description];

            $stm = $this->get('pdo')->prepare("INSERT INTO
                                url_checks (url_id, created_at, status_code, h1, title, description)
                                VALUES (?, ?, ?, ?, ?, ?)");
            $stm->execute($arrVars);
        }

        $this->get('flash')->addMessage('warning', 'Проверка была выполнена успешно, но сервер ответил с ошибкой');
        return $response->withHeader('Location', $router->urlFor('urls.show', ['id' => $id]))->withStatus(301);
    } catch (ConnectException $e) {
        $this->get('flash')->addMessage('error', 'Произошла ошибка при проверке, не удалось подключиться');

        return $response->withHeader('Location', $router->urlFor('urls.show', ['id' => $id]))->withStatus(301);
    }

    $statusCode = $answer->getStatusCode();
    $html = $answer->getBody()->getContents();

    $document = new Document($html, false);

    $h1 = optional($document->first('h1'))->text();

    if (!is_null($h1) && strlen($h1) > 255) {
        $h1 = substr($h1, 0, 255);
    }

    $title = optional($document->first('title'))->text();

    if (!is_null($title) && strlen($title) > 255) {
        $title = substr($title, 0, 255);
    }

    $description = optional($document->first('meta[name=description]'))->getAttribute('content');

    $arrVars = [$id, $nowTime, $statusCode, $h1, $title, $description];

    $stm = $this->get('pdo')->prepare("INSERT INTO
                        url_checks (url_id, created_at, status_code, h1, title, description)
                        VALUES (?, ?, ?, ?, ?, ?)");
    $stm->execute($arrVars);

    $this->get('flash')->addMessage('success', 'Страница успешно проверена');

    return $response->withHeader('Location', $router->urlFor('urls.show', ['id' => $id]))->withStatus(301);
})->setName('checks.store');

$app->run();
