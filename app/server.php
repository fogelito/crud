<?php

if (file_exists(__DIR__.'/../vendor/autoload.php')) {
    require __DIR__.'/../vendor/autoload.php';
}


use Utopia\App;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Key;
use Utopia\Registry\Registry;
use Utopia\Response as ResponseAlias;
use Utopia\Swoole\Request;
use Utopia\Swoole\Response;
use Utopia\Swoole\Files;
use Utopia\CLI\Console;
use Swoole\Http\Server;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Utopia\Validator;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Boolean;
use Utopia\Validator\Numeric;
use Utopia\Validator\Text;

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


App::error(function(Exception $error, Response $response) {

    $response->json(
        [
            'code' => $error->getCode(),
            'getLine' => $error->getLine() ,
            'getFile' => $error->getFile(),
            'getMessage' => $error->getMessage()
        ]);

}, ['error', 'response'], 'api');


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


App::get('/init')
    ->groups(['api'])
    ->inject('request')
    ->inject('response')
    ->inject('db')
    ->action(
        function($request, $response, Database $db) {
            if(!$db->exists($db->getDefaultDatabase())){
                $db->create($db->getDefaultDatabase());
            }
            $response->json([$db->getDefaultDatabase() . ' created']);
        }
    );


App::get('/create-collection')
    ->groups(['api'])
    ->inject('request')
    ->inject('response')
    ->inject('db')
    ->action(
        function($request,Response $response, Database $db) {
            $collection = $db->getCollection('tasks');

            if($collection->isEmpty()){
                $db->createCollection('tasks');
                $db->createAttribute('tasks', 'title', Database::VAR_STRING, 1000000, true);
                $db->createAttribute('tasks', 'time', Database::VAR_INTEGER, 0, true);
                $db->createAttribute('tasks', 'is_active', Database::VAR_BOOLEAN, 0, true);
                $db->createAttribute('tasks', 'string_list', Database::VAR_STRING, 0, true, null, true, true);
                $collection = $db->getCollection('tasks');
            }

             $response->json([$collection]);
        }
    );


App::get('/doc/:id')
    ->groups(['api'])
    ->param('id', '', new Text(128), 'title', false)
    ->inject('request')
    ->inject('response')
    ->inject('db')
    ->action(
        function($id, Request $request, Response $response, Database $db) {

           // $collection = $db->createCollection('tasks');

            $document = $db->getDocument('tasks', $id);
            if($document->isEmpty()){
                throw new Exception('Not found', ResponseAlias::STATUS_CODE_NOT_FOUND);
            }

            $response->json([$document]);
        }
    );



App::get('/tasks/add')
    ->groups(['api'])
    ->param('title', '', new Text(128), 'title', false)
    ->param('string_list', '', new ArrayList(new Text(128)), 'string_list', false)
    ->inject('request')
    ->inject('response')
    ->inject('db')
    ->action(
        function($title, array $stringList, Request $request, Response $response, Database $db) {
            $collection = $db->getCollection('tasks');

            if($collection->isEmpty()){
                throw new Exception('not found', ResponseAlias::STATUS_CODE_NOT_FOUND);
            }

            $doc = $db->createDocument('tasks', new Document([
                '$read' => ['role:all', 'yosi', 'ben:123'],
                '$write' => ['role:all'],
                'title' => $title,
                'time' => time(),
                'is_active' => true,
                'string_list' => $stringList,
            ]));

            $response->json([$doc, $stringList]);

        }
    );


App::get('/tasks/update')
    ->groups(['api'])
    ->param('id', null, new Key(), 'id to update', false)
    ->param('title', null, new Text(128), 'title', false)
    ->param('string_list', null, new ArrayList(new Text(128)), 'string_list', false)
    ->param('is_active', null, new Boolean(true), 'is_active', false)
    ->inject('request')
    ->inject('response')
    ->inject('db')
    ->action(
        function($id, $title, array $stringList, $isActive, Request $request, Response $response, Database $db) {
            $isActive = $isActive === "true";
            $collection = $db->getCollection('tasks');

            if($collection->isEmpty()){
                throw new Exception('not found', ResponseAlias::STATUS_CODE_NOT_FOUND);
            }

            $doc = $db->updateDocument('tasks', $id, new Document([
                '$id' => $id,
                '$collection' => 'tasks',
                '$read' => ['role:all'],
                '$write' => ['role:all'],
                'title' => $title,
                'time' => time(),
                'is_active' => $isActive,
                'string_list' => $stringList,
            ]));

            $response->json([$doc]);
        }
    );



App::get('/tasks/delete')
    ->groups(['api'])
    ->param('id', null, new Key(), 'id to update', false)
    ->inject('request')
    ->inject('response')
    ->inject('db')
    ->action(
        function($id, Request $request, Response $response, Database $db) {
            $res = $db->deleteDocument('tasks', $id);
            $response->json([$res]);
        }
    );


App::get('/tasks/list')
    ->groups(['api'])
    ->inject('request')
    ->inject('response')
    ->inject('db')
    ->action(
        function(Request $request, Response $response, Database $db) {

            $documents = $db->find('tasks', [
                new Query('is_active', Query::TYPE_EQUAL, [false]),
            ]);

            $response->json([$documents]);

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
        PDO::ATTR_EMULATE_PREPARES => true,
        PDO::ATTR_STRINGIFY_FETCHES => true
    ));

    $cache = new Cache(new None());

    $dbScheme = 'shimo';
    $database = new Database(new MariaDB($pdo), $cache);
    $database->setDefaultDatabase($dbScheme);
    $database->setNamespace('ns');

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
