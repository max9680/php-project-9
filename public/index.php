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

$pdo = Connection::get();

try {
    $pdo = $pdo->connect();
    // $pdo = Connection::get()->connect();
    // echo 'A connection to the PostgreSQL database sever has been established successfully.';
} catch (\PDOException $e) {
    echo $e->getMessage();
}

session_start();

$container = new Container();
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
});


$app->get('/urls', function ($request, $response) use ($pdo) {

    $urls = $pdo->query("SELECT * FROM urls ORDER BY id DESC")->fetchAll(\PDO::FETCH_ASSOC);

    $urlsWCheck = array_reduce($urls, function ($acc, $url) use ($pdo) {
        $id = $url['id'];

        $sqlForCheck = "SELECT status_code, created_at
                        FROM url_checks
                        WHERE url_id = $id
                        ORDER BY created_at
                        DESC LIMIT 1";

        $lastcheck = $pdo->query($sqlForCheck)->fetchAll();

        if (isset($lastcheck[0]['created_at'])) {
            $url['lastcheck'] = $lastcheck[0]['created_at'];
        } else {
            $url['lastcheck'] = null;
        }

        if (isset($lastcheck[0]['status_code'])) {
            $url['status_code'] = $lastcheck[0]['status_code'];
        } else {
            $url['status_code'] = null;
        }

        $acc[] = $url;

        return $acc;
    }, []);

    $params = [
        'urls' => $urlsWCheck
    ];

    return $this->get('renderer')->render($response, 'urls/index.phtml', $params);
})->setName('urls.index');

$app->get('/urls/{id}', function ($request, $response, array $args) use ($pdo) {
    $messages = $this->get('flash')->getMessages();

    $id = $args['id'];

    $url = $pdo->query("SELECT * FROM urls WHERE id = $id", PDO::FETCH_ASSOC)->fetch();

    $checks = $pdo->query("SELECT * FROM url_checks WHERE url_id = $id ORDER BY id DESC")->fetchAll();

    $params = [
        'url' => $url,
        'flash' => $messages,
        'checks' => $checks
    ];

    return $this->get('renderer')->render($response, 'urls/show.phtml', $params);
})->setName('urls.show');

$router = $app->getRouteCollector()->getRouteParser();

$app->post('/urls', function ($request, $response) use ($router, $pdo) {
    $url = $request->getParsedBodyParam('url');

    $v = new Valitron\Validator(['website' => $url['name']]);

    $v->rules([
        'url' => [
            ['website']
        ],
        'required' => ['website']
    ]);

    if (!$v->validate()) {
        if ($url['name'] == null) {
            $error = "URL не должен быть пустым";
        } else {
            $error = "Некорректный URL";
        }
        $params = [
            'error' => $error,
            'url' => $url
        ];

        return $this->get('renderer')->render($response->withStatus(422), 'index.phtml', $params);
    }
    
    $parsedUrl = parse_url($url['name']);
    $urlForInput = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];

    $urls = $pdo->query("SELECT id FROM urls WHERE name = '$urlForInput'")->fetchAll(\PDO::FETCH_COLUMN);

    if (count($urls) > 0) {
        $this->get('flash')->addMessage('success', 'Страница уже существует');

        $id = $urls[0];

        return $response->withHeader('Location', $router->urlFor('urls.show', ['id' => $id]))->withStatus(301);
    }
    
    $nowTime = Carbon::now();
    $arrVars = [$urlForInput, $nowTime];
    $values = implode(', ', array_map(function ($item) use ($pdo) {
        return $pdo->quote($item);
    }, $arrVars));

    $pdo->exec("INSERT INTO urls (name, created_at) VALUES ($values)");
    $id = $pdo->lastInsertId();

    $this->get('flash')->addMessage('success', 'Страница успешно добавлена');

    return $response->withHeader('Location', $router->urlFor('urls.show', ['id' => $id]))->withStatus(301);
})->setName('urls.store');

$app->post('/urls/{id}/checks', function ($request, $response, array $args) use ($router, $pdo) {
    $id = $args['id'];
    $urlName = $pdo->query("SELECT name FROM urls WHERE id = $id")->fetchAll(\PDO::FETCH_COLUMN);

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

            if (isset($document->find('h1')[0])) {
                $h1 = optional($document->find('h1')[0])->text();
            } else {
                $h1 = null;
            }

            if (isset($document->find('title')[0])) {
                $title = optional($document->find('title')[0])->text();
            } else {
                $title = null;
            }

            if (isset($document->find('meta[name=description]')[0])) {
                $description = optional($document->find('meta[name=description]')[0])->getAttribute('content');
            } else {
                $description = null;
            }

            $arrVars = [$id, $nowTime, $statusCode, $h1, $title, $description];

            $stm = $pdo->prepare("INSERT INTO
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

    if (isset($document->find('h1')[0])) {
        $h1 = optional($document->find('h1')[0])->text();
    } else {
        $h1 = null;
    }

    if (isset($document->find('title')[0])) {
        $title = optional($document->find('title')[0])->text();
    } else {
        $title = null;
    }

    if (isset($document->find('meta[name=description]')[0])) {
        $description = optional($document->find('meta[name=description]')[0])->getAttribute('content');
    } else {
        $description = null;
    }

    $arrVars = [$id, $nowTime, $statusCode, $h1, $title, $description];

    $stm = $pdo->prepare("INSERT INTO
                        url_checks (url_id, created_at, status_code, h1, title, description)
                        VALUES (?, ?, ?, ?, ?, ?)");
    $stm->execute($arrVars);

    $this->get('flash')->addMessage('success', 'Страница успешно проверена');

    return $response->withHeader('Location', $router->urlFor('urls.show', ['id' => $id]))->withStatus(301);
})->setName('checks.store');

$app->run();
