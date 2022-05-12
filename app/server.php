<?php

if (file_exists(__DIR__.'/../vendor/autoload.php')) {
    require __DIR__.'/../vendor/autoload.php';
}

use Utopia\App;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Adapter\Redis as RedisCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Database;
use Utopia\Registry\Registry;
use Utopia\Swoole\Request;
use Utopia\Swoole\Response;
use Utopia\Swoole\Files;
use Utopia\CLI\Console;
use Swoole\Http\Server;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;

$http = new Server("0.0.0.0", 8080);

Files::load(__DIR__ . '/../public'); // Static files location

/*
    The init and shutdown methods take three params:
    1. Callback function
    2. Array of resources required by the callback 
    3. The endpoint group for which the callback is intended to run

    In the following, the init method is called on all groups with 
    the wildcard permission '*', modifying the $response object
    for each route.

    The shutdown method uses the Utopia CLI lib to log api to the console;
    this is done for routes in the 'api' group. These logs will appear 
    in docker logs. 
    
*/

App::init(function($response) {
    $response
        ->addHeader('Cache-control', 'no-cache, no-store, must-revalidate')
        ->addHeader('Expires', '-1')
        ->addHeader('Pragma', 'no-cache')
        ->addHeader('X-XSS-Protection', '1;mode=block');
}, ['response'], '*');


App::shutdown(function($request) {
    $date = new DateTime();
    Console::success($date->format('c').' '.$request->getURI());
}, ['request'], 'api');


App::error(function(Exception $error) {

    var_dump($error->getCode());
    var_dump($error->getLine());
    var_dump($error->getMessage());
}, ['error'], 'api');

/*
    The routes are defined before the Swoole server is turned on.
    Resources are modified in the routes via the inject method,
    which is an alternate syntax to the middleware methods above. 
*/

App::get('/')
    ->groups(['home'])
    ->inject('request')
    ->inject('response')
    ->action(
        function($request, $response) {
            // Return a static file
            $response->send(Files::getFileContents('/index.html'));
        }
    );


App::get('/create-collection')
    ->groups(['api'])
    ->inject('request')
    ->inject('response')
    ->inject('db')
    ->action(
        function($request, $response, Database $db) {
            $collection = $db->getCollection('tasks');

            if($collection->isEmpty()){
                $db->createAttribute('tasks', 'title', Database::VAR_STRING, 1000000, true);
                $db->createAttribute('tasks', 'time', Database::VAR_INTEGER, 0, true);
                $db->createAttribute('tasks', 'is_active', Database::VAR_BOOLEAN, 0, true);
                $db->createAttribute('tasks', 'string_list', Database::VAR_STRING, 0, true, null, true, true);
                $collection = $db->getCollection('tasks');
            }

             $response->json([$collection]);

        }
    );


App::get('/hello')
    ->groups(['api'])
    ->inject('request')
    ->inject('response')
    ->action(
        function($request, $response) {
            $response->json(['Hello' => 'World4']);
        }
    );


App::get('/goodbye')
    ->groups(['api'])
    ->inject('request')
    ->inject('response')
    ->action(
        function($request, $response) {
            $response->json(['Goodbye' => 'World1']);
        }
    );

/*
    Configure the Swoole server to respond with the Utopia app.    
*/

$registry = new Registry;

$registry->set('db', function () { // This is usually for our workers or CLI commands scope
    $dbHost = 'mariadb';
    $dbPort = '3306';
    $dbUser = 'root';
    $dbPass = 'password';
    $dbScheme = 'mydb';

    $pdo = new PDO("mysql:host={$dbHost};port={$dbPort};dbname={$dbScheme};charset=utf8mb4", $dbUser, $dbPass, array(
        PDO::ATTR_TIMEOUT => 3, // Seconds
        PDO::ATTR_PERSISTENT => true,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ));

    $dbScheme = 'shimo';

    $cache = new Cache(new None());
    $database = new Database(new MariaDB($pdo), $cache);
    $database->setNamespace('ns');
    $database->exists($dbScheme) || $database->create($dbScheme);
    $database->setDefaultDatabase($dbScheme);

    return $database;

});


App::setResource('registry', function () use ($registry) {
    return  $registry;
});


App::setResource('db', function ($registry) {
    return  $registry->get('db');
}, ['registry']);


$http->on('request', function (SwooleRequest $swooleRequest, SwooleResponse $swooleResponse) {
    $request = new Request($swooleRequest);
    $response = new Response($swooleResponse);
    $app = new App('Asia/Jerusalem');

    try {
        $app->run($request, $response);
    } catch (\Throwable $th) {
        Console::error('There\'s a problem with '.$request->getURI());
        $swooleResponse->end('500: Server Error');
    }
});

$http->start();
