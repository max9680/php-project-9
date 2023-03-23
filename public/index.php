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

    $urlsWCheck = array_reduce($urls, function ($acc, $url) use ($pdo) {
        $id = $url['id'];

        $sql = "SELECT url_checks.created_at AS url_checks_created_at,
                         url_checks.status_code AS url_checks_status_code
                         FROM urls JOIN url_checks ON urls.id = url_checks.url_id
                         WHERE urls.id = $id ORDER BY url_checks.created_at DESC LIMIT 1";
        $lastcheck = $pdo->query($sql)->fetchAll(\PDO::FETCH_COLUMN);
        $url['lastcheck'] = $lastcheck[0];
        // print_r($url);
        $acc[] = $url;
        return $acc;
    }, []);

    // print_r($urlsWCheck);

    $params = [
        'urls' => $urlsWCheck
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

    $checks = $pdo->query("SELECT * FROM url_checks WHERE url_id = $id ORDER BY id DESC")->fetchAll();

    $params = [
        'url' => $url,
        'flash' => $messages,
        'checks' => $checks
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

            $pdo->exec("INSERT INTO urls (name, created_at) VALUES ($values)");
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

$app->post('/urls/{id}/checks', function ($request, $response, array $args) use ($router) {
    $pdo = Connection::get()->connect();

    $id = $args['id'];
    $nowTime = Carbon::now();
    $arrVars = [$id, $nowTime];

    $stm = $pdo->prepare("INSERT INTO url_checks (url_id, created_at) VALUES (?, ?)");
    $stm->execute($arrVars);
    // $pdo->exec("INSERT INTO url_checks (url_id, created_at) VALUES($id, $nowTime)");

    // $params = [];

    // return $this->get('renderer')->render($response->withStatus(422), 'index.phtml', $params);
    return $response->withHeader('Location', $router->urlFor('showUrl', ['id' => $id]))->withStatus(301);
})->setName('postChecks');

$app->run();
