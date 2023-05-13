<?php

require __DIR__ . '/../vendor/autoload.php';

use Carbon\Carbon;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use DI\Container;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ClientException;
use DiDom\Document;

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

session_start();

Valitron\Validator::lang('ru');

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->safeload();

$container = new Container();

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

try {
    $pdo = new \PDO($conStr);
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
} catch (\PDOException $e) {
    echo $e->getMessage();
}

if (isset($pdo)) {
    $container->set('pdo', $pdo);
}

$container->set('view', function () {
    return Twig::create(__DIR__ . '/../templates');
});

$container->set('flash', function () {
    return new \Slim\Flash\Messages();
});

$app = AppFactory::createFromContainer($container);

$app->addErrorMiddleware(true, true, true);

$app->get('/', function ($request, $response) {

    return $this->get('view')->render($response, 'index.twig.html');
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
    $url['name'] = strtolower($url['name']);

    $v = new Valitron\Validator(['URL' => $url['name']]);

    $v->rule('required', 'URL')->message('{field} не должен быть пустым');
    $v->rule('url', 'URL')->message('Некорректный {field}');

    if (!$v->validate()) {
        $params = [
            'error' => $v->errors(),
            'url' => $url
        ];

        return $this->get('view')->render($response->withStatus(422), 'index.twig.html', $params);
    }

    $parsedUrl = parse_url($url['name']);
    $urlForInput = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];

    $sql = "SELECT id FROM urls WHERE name = ?";
    $stm = $this->get('pdo')->prepare($sql);
    $stm->execute([$urlForInput]);
    $urlInDB = $stm->fetch(\PDO::FETCH_COLUMN);

    if (isset($urlInDB) && ($urlInDB !== false)) {
        $this->get('flash')->addMessage('success', 'Страница уже существует');

        $id = $urlInDB;

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

            writeDataInDB($this->get('pdo'), $document, $id, $nowTime, $statusCode);
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

    writeDataInDB($this->get('pdo'), $document, $id, $nowTime, $statusCode);

    $this->get('flash')->addMessage('success', 'Страница успешно проверена');

    return $response->withHeader('Location', $router->urlFor('urls.show', ['id' => $id]))->withStatus(301);
})->setName('checks.store');

$app->run();
