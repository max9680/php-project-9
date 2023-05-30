<?php

use Carbon\Carbon;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;
use DI\Container;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ClientException;
use DiDom\Document;
use Illuminate\Support\Arr;

require __DIR__ . '/../vendor/autoload.php';

session_start();

Valitron\Validator::lang('ru');

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->safeload();

$container = new Container();
AppFactory::setContainer($container);

$container->set('pdo', function () {
    $databaseURL = Arr::get($_ENV, 'DATABASE_URL', null);

    if ($databaseURL === null) {
        throw new \Exception("Error reading environment variable DATABASE_URL");
    }

    $params = parse_url($databaseURL);

    $dbName = ltrim($params['path'], '/');
    $host = Arr::get($params, 'host', null);
    $port = Arr::get($params, 'port', null);
    $user = Arr::get($params, 'user', null);
    $pass = Arr::get($params, 'pass', null);

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
});

$container->set('flash', function () {
    return new \Slim\Flash\Messages();
});

$container->set('view', function () {
    return Twig::create(__DIR__ . '/../templates', ['cache' => false]);
});

$app = AppFactory::create();

$app->addErrorMiddleware(true, true, true);

$app->add(TwigMiddleware::createFromContainer($app));

$app->get('/', function ($request, $response) {

    return $this->get('view')->render($response, 'index.twig.html');
})->setName('index');


$app->get('/urls', function ($request, $response) {
    $urls = $this->get('pdo')->query("SELECT * FROM urls ORDER BY id DESC")->fetchAll();
    $collectionUrls = collect($urls);

    $checks = $this->get('pdo')->query("SELECT * FROM url_checks ORDER BY id DESC")->fetchAll();
    $collectionChecks = collect($checks);

    $urlsWCheck = $collectionUrls->map(function ($item) use ($collectionChecks) {
        // $result = $item;
        $check = $collectionChecks->firstWhere('url_id', $item['id']);
        $item['last_check_timestamp'] = Arr::get($check, 'created_at', null);
        $item['status_code'] = Arr::get($check, 'status_code', null);

        return $item;
    });

    $params = [
        'urls' => $urlsWCheck
    ];

    return $this->get('view')->render($response, 'urls/index.twig.html', $params);
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

    return $this->get('view')->render($response, 'urls/show.twig.html', $params);
})->setName('urls.show');

$router = $app->getRouteCollector()->getRouteParser();

$app->post('/urls', function ($request, $response) use ($router) {
    $url = $request->getParsedBodyParam('url');

    $validator = new Valitron\Validator(['URL' => $url['name']]);

    $validator->rule('required', 'URL')->message('{field} не должен быть пустым');
    $validator->rule('url', 'URL')->message('Некорректный {field}');

    if (!$validator->validate()) {
        $params = [
            'error' => $validator->errors(),
            'url' => $url
        ];

        return $this->get('view')
            ->render($response->withStatus(422), 'index.twig.html', $params);
    }

    $url['name'] = strtolower($url['name']);
    $parsedUrl = parse_url($url['name']);
    $urlForInput = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];

    $sql = "SELECT id FROM urls WHERE name = ?";
    $stm = $this->get('pdo')->prepare($sql);
    $stm->execute([$urlForInput]);
    $urlInDB = $stm->fetch(\PDO::FETCH_COLUMN);

    if ($urlInDB) {
        $this->get('flash')->addMessage('success', 'Страница уже существует');

        return $response->withHeader('Location', $router->urlFor('urls.show', ['id' => $urlInDB]))->withStatus(301);
    }

    $nowTime = Carbon::now();

    $sql = "INSERT INTO urls (name, created_at) VALUES (?,?)";
    $stm = $this->get('pdo')->prepare($sql);
    $stm->execute([$urlForInput, $nowTime]);

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
        $this->get('flash')->addMessage('success', 'Страница успешно проверена');
    } catch (ConnectException $e) {
        $this->get('flash')->addMessage('error', 'Произошла ошибка при проверке, не удалось подключиться');

        return $response->withHeader('Location', $router->urlFor('urls.show', ['id' => $id]))->withStatus(301);
    } catch (RequestException $e) {
        $answer = $e->getResponse();

        $this->get('flash')->addMessage('warning', 'Проверка была выполнена успешно, но сервер ответил с ошибкой');
    }

    $statusCode = $answer ? $answer->getStatusCode() : null;
    $html = $answer ? $answer->getBody()->getContents() : null;
    $document = new Document($html, false);

    $h1 = optional($document->first('h1'))->text();
    $title = optional($document->first('title'))->text();
    $description = optional($document->first('meta[name=description]'))->getAttribute('content');

    $stm = $this->get('pdo')->prepare("INSERT INTO
                        url_checks (url_id, created_at, status_code, h1, title, description)
                        VALUES (?, ?, ?, ?, ?, ?)");
    $stm->execute([$id, $nowTime, $statusCode, $h1, $title, $description]);

    return $response->withHeader('Location', $router->urlFor('urls.show', ['id' => $id]))->withStatus(301);
})->setName('checks.store');

$app->run();
