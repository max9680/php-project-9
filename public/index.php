<?php

require __DIR__ . '/../vendor/autoload.php';

use PostgreSQLTutorial\Connection;
use PostgreSQLTutorial\PostgreSQLCreateTable;
use Carbon\Carbon;
use Slim\Factory\AppFactory;
use DI\Container;

try {
    $pdo = Connection::get()->connect();
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


$app->get('/urls', function ($request, $response) {

    $pdo = Connection::get()->connect();

    $urls = $pdo->query("SELECT * FROM urls ORDER BY id DESC")->fetchAll(\PDO::FETCH_ASSOC);

    $params = [
        'urls' => $urls
    ];

    return $this->get('renderer')->render($response, 'urls/index.phtml', $params);
})->setName('urls');

$app->get('/urls/{id}', function ($request, $response, array $args) {
    $pdo = Connection::get()->connect();

    $messages = $this->get('flash')->getMessages();

    $id = $args['id'];
    $urls = $pdo->query("SELECT * FROM urls")->fetchAll();

    foreach ($urls as $item) {
        if ($item['id'] == $id) {
            $url = $item;
        }
    }

    $params = [
        'url' => $url,
        'flash' => $messages
    ];

    return $this->get('renderer')->render($response, 'urls/show.phtml', $params);
})->setName('showUrl');

$router = $app->getRouteCollector()->getRouteParser();

$app->post('/urls', function ($request, $response) use ($router) {
    $pdo = Connection::get()->connect();
    $url = $request->getParsedBodyParam('url');

    $v = new Valitron\Validator(['website' => $url['name']]);

    $v->rules([
        'url' => [
            ['website']
        ]
    ]);

    if (($v->validate()) & ($url['name'] != null)) {
        $parsedUrl = parse_url($url['name']);
        $urlForInput = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];

        $urls = $pdo->query("SELECT id FROM urls WHERE name = '$urlForInput'")->fetchAll(\PDO::FETCH_COLUMN);

        if (count($urls) > 0) {
            $this->get('flash')->addMessage('success', 'Страница уже существует');

            $id = $urls[0];

            return $response->withHeader('Location', $router->urlFor('showUrl', ['id' => $id]))->withStatus(301);
        } else {
            $nowTime = Carbon::now();
            $arrVars = [$urlForInput, $nowTime];
            $values = implode(', ', array_map(function ($item) use ($pdo) {
                return $pdo->quote($item);
            }, $arrVars));

            $pdo->exec("insert into urls (name, created_at) values ($values)");
            $id = $pdo->lastInsertId();

            $this->get('flash')->addMessage('success', 'Страница успешно добавлена');

            return $response->withHeader('Location', $router->urlFor('showUrl', ['id' => $id]))->withStatus(301);
        }
    } else {
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
})->setName('postUrls');

$app->run();
