<?php

use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

use Odan\Session\PhpSession;
use Odan\Session\Middleware\SessionStartMiddleware;

use Omnipay\Common\CreditCard;
use Omnipay\Omnipay;

require __DIR__ . '/vendor/autoload.php';


// Create App
$app = AppFactory::create();

$settings = [
    'name' => 'app',
];

// Create Twig & Sessions
$twig = Twig::create('./views', ['cache' => false, 'debug' => true,]);
$session = new PhpSession($settings);


$app->add(TwigMiddleware::create($app, $twig));
$app->add(new SessionStartMIddleware($session));



// root route DONE
$app->get('/', function ($request, $response, $args) {
    $view = Twig::fromRequest($request);

    $gateways = array_map(function($name) {
        return Omnipay::create($name);
    }, require __DIR__ .'/gateways.php');

    return $view->render($response, 'index.twig', array(
        'gateways' => $gateways,
    ));
})->setName('profile');

// gateway settings
$app->get('/gateways/{name}', function ($request, $response, $args) use ($session) {
    $view = Twig::fromRequest($request);

    $name = $args['name'];

    $gateway = Omnipay::create($name);
    $sessionVar = 'omnipay.'.$gateway->getShortName();
    $gateway->initialize((array) $session->get($sessionVar));

    return $view->render($response, 'gateway.twig', array(
        'gateway' => $gateway,
        'settings' => $gateway->getParameters(),
    ));
});

// save gateway settings
$app->post('/gateways/{name}', function ($request, $response, $args) use ($session) {
    $name = $args['name'];
    
    $gateway = Omnipay::create($name);
    $sessionVar = 'omnipay.'.$gateway->getShortName();

    if (empty($request->getParsedBody())) {
        $gateway->initialize();
    } else {
        $gateway->initialize((array) $request->getParsedBody()['gateway']);
    }
    

    $session->set($sessionVar, $gateway->getParameters());
    $session->getFlash()->add('success', 'Gateway settings updated!');
    
    return $response->withHeader('Location', 'http://' . $request->getUri()->getHost() . ':8000' . $request->getUri()->getPath())->withStatus(302);
});

// create gateway authorize
$app->get('/gateways/{name}/authorize', function ($request, $response, $args) use ($session) {
    $name = $args['name'];
    $view = Twig::fromRequest($request);

    $gateway = Omnipay::create($name);
    $sessionVar = 'omnipay.'.$gateway->getShortName();

    $gateway->initialize((array) $session->get($sessionVar));
    
    $params = $session->get($sessionVar.'.authorize', array());

    $params['returnUrl'] = str_replace('/authorize', '/completeAuthorize', 'http://localhost:8000/gateways/{name}/authorize');
    $params['cancelUrl'] = 'http://localhost:8000/gateways/{name}/authorize';

    $card = new CreditCard($session->get($sessionVar.'card'));


    return $view->render($response, 'request.twig', array(
        'gateway' => $gateway,
        'method' => 'authorize',
        'params' => $params,
        'card' => $card->getParameters(),
    ));
});


$app->post('/gateways/{name}/authorize', function ($request, $response, $args) use ($session) {
    $name = $args['name'];
    $view = Twig::fromRequest($request);

    $gateway = Omnipay::create($name);
    $sessionVar = 'omnipay.'.$gateway->getShortName();
    $gateway->initialize((array) $session->get($sessionVar));

    $params = $request->getParsedBody()['params'];
    $card = $request->getParsedBody()['card'];

    $session->set($sessionVar.'.authorize', $params);
    $session->set($sessionVar.'.card', $card);

    $params['card'] = $card;
    $params['clientIp'] = $request->getServerParams()['REMOTE_ADDR'];

    $gateway_response = $gateway->authorize($params)->send();

    return $view->render($response, 'response.twig', array(
        'gateway' => $gateway,
        'response' => $gateway_response,
    ));
});

$app->get('/gateways/{name}/completeAuthorize', function ($request, $response, $args) use ($session) {
    $name = $args['name'];
    $view = Twig::fromRequest($request);

    $gateway = Omnipay::create($name);
    $sessionVar = 'omnipay.'.$gateway->getShortName();
    $gateway->initialize((array) $session->get($sessionVar));

    $params = $session->get($sessionVar.'.authorize');
    $gateway_response = $gateway->completeAuthorize($params)->send();

    return $view->render($response, 'response.twig', array(
        'gateway' => $gateway,
        'response' => $gateway_response,
    ));
});

$app->get('/gateways/{name}/capture', function ($request, $response, $args) use ($session) {
    $name = $args['name'];
    $view = Twig::fromRequest($request);

    $gateway = Omnipay::create($name);
    $sessionVar = 'omnipay.'.$gateway->getShortName();
    $gateway->initialize((array) $session->get($sessionVar));

    $params = $session->get($sessionVar.'.capture', array());

    return $view->render($response, 'request.twig', array(
        'gateway' => $gateway,
        'method' => 'capture',
        'params' => $params,
    ));
});

$app->post('/gateways/{name}/capture', function ($request, $response, $args) use ($session) {
    $name = $args['name'];
    $view = Twig::fromRequest($request);

    $gateway = Omnipay::create($name);
    $sessionVar = 'omnipay.'.$gateway->getShortName();
    $gateway->initialize((array) $session->get($sessionVar));

    $params = $request->getParsedBody()['params'];

    $session->set($sessionVar.'.capture', $params);
    $params['clientIp'] = $request->getServerParams()['REMOTE_ADDR'];

    $gateway_response = $gateway->capture($params)->send();
    
    return $view->render($response, 'response.twig', array(
        'gateway' => $gateway,
        'response' => $gateway_response,
    ));
});

$app->get('/gateways/{name}/purchase', function ($request, $response, $args) use ($session) {
    $name = $args['name'];
    $view = Twig::fromRequest($request);

    $gateway = Omnipay::create($name);
    $sessionVar = 'omnipay.'.$gateway->getShortName();
    $gateway->initialize((array) $session->get($sessionVar));

    $params = $session->get($sessionVar.'.purchase', array());
    $params['returnUrl'] = str_replace('/purchase', '/completePurchase', 'http://localhost:8000/gateways/{name}/authorize');
    $params['cancelUrl'] = 'http://localhost:8000/gateways/{name}/purchase';

    $card = new CreditCard($session->get($sessionVar.'card'));

    return $view->render($response, 'request.twig', array(
        'gateway' => $gateway,
        'method' => 'purchase',
        'params' => $params,
        'card' => $card->getParameters(),
    ));
});

$app->post('/gateways/{name}/purchase', function ($request, $response, $args) use ($session) {
    $name = $args['name'];
    $view = Twig::fromRequest($request);

    $gateway = Omnipay::create($name);
    $sessionVar = 'omnipay.'.$gateway->getShortName();
    $gateway->initialize((array) $session->get($sessionVar));

    $params = $request->getParsedBody()['params'];
    $card = $request->getParsedBody()['card'];

    $session->set($sessionVar.'.purchase', $params);
    $session->set($sessionVar.'.card', $card);

    $params['card'] = $card;
    $params['clientIp'] = $request->getServerParams()['REMOTE_ADDR'];

    $gateway_response = $gateway->purchase($params)->send();

    return $view->render($response, 'response.twig', array(
        'gateway' => $gateway,
        'response' => $gateway_response,
    ));
});

$app->get('/gateways/{name}/completePurchase', function ($request, $response, $args) use ($session) {
    $name = $args['name'];
    $view = Twig::fromRequest($request);

    $gateway = Omnipay::create($name);
    $sessionVar = 'omnipay.'.$gateway->getShortName();
    $gateway->initialize((array) $session->get($sessionVar));

    $params = $session->get($sessionVar.'.purchase');
    $gateway_response = $gateway->completePurchase($params)->send();

    return $view->render($response, 'response.twig', array(
        'gateway' => $gateway,
        'response' => $gateway_response,
    ));
});

$app->get('/gateways/{name}/create-card', function ($request, $response, $args) use ($session) {
    $name = $args['name'];
    $view = Twig::fromRequest($request);

    $gateway = Omnipay::create($name);
    $sessionVar = 'omnipay.'.$gateway->getShortName();
    $gateway->initialize((array) $session->get($sessionVar));

    $params = $session->get($sessionVar.'.create', array());
    $card = new CreditCard($session->get($sessionVar.'.card'));

    return $view->render($response, 'request.twig', array(
        'gateway' => $gateway,
        'method' => 'createCard',
        'params' => $params,
        'card' => $card->getParameters(),
    ));
});

$app->post('/gateways/{name}/create-card', function ($request, $response, $args) use ($session) {
    $name = $args['name'];
    $view = Twig::fromRequest($request);

    $gateway = Omnipay::create($name);
    $sessionVar = 'omnipay.'.$gateway->getShortName();
    $gateway->initialize((array) $session->get($sessionVar));

    $params = $request->getParsedBody()['params'];
    $card = $request->getParsedBody()['card'];

    $session->set($sessionVar.'.create', $params);
    $session->set($sessionVar.'.card', $card);

    $params['card'] = $card;
    $params['clientIp'] = $request->getServerParams()['REMOTE_ADDR'];

    $gateway_response = $gateway->createCard($params)->send();

    return $view->render($response, 'response.twig', array(
        'gateway' => $gateway,
        'response' => $gateway_response,
    ));
});

$app->get('/gateways/{name}/update-card', function ($request, $response, $args) use ($session) {
    $name = $args['name'];
    $view = Twig::fromRequest($request);

    $gateway = Omnipay::create($name);
    $sessionVar = 'omnipay.'.$gateway->getShortName();
    $gateway->initialize((array) $session->get($sessionVar));

    $params = $session->get($sessionVar.'.update', array());
    $card = new CreditCard($session->get($sessionVar.'.card'));

    return $view->render($response, 'request.twig', array(
        'gateway' => $gateway,
        'method' => 'updateCard',
        'params' => $params,
        'card' => $card->getParameters(),
    ));
});

$app->post('/gateways/{name}/update-card', function ($request, $response, $args) use ($session) {
    $name = $args['name'];
    $view = Twig::fromRequest($request);

    $gateway = Omnipay::create($name);
    $sessionVar = 'omnipay.'.$gateway->getShortName();
    $gateway->initialize((array) $session->get($sessionVar));

    $params = $request->getParsedBody()['params'];
    $card = $request->getParsedBody()['card'];

    $session->set($sessionVar.'.update', $params);
    $session->set($sessionVar.'.card', $card);

    $params['card'] = $card;
    $params['clientIp'] = $request->getServerParams()['REMOTE_ADDR'];

    $gateway_response = $gateway->updateCard($params)->send();

    return $view->render($response, 'response.twig', array(
        'gateway' => $gateway,
        'response' => $gateway_response,
    ));
});

$app->get('/gateways/{name}/delete-card', function ($request, $response, $args) use ($session) {
    $name = $args['name'];
    $view = Twig::fromRequest($request);

    $gateway = Omnipay::create($name);
    $sessionVar = 'omnipay.'.$gateway->getShortName();
    $gateway->initialize((array) $session->get($sessionVar));

    $params = $session->get($sessionVar.'.delete', array());
    $card = new CreditCard($session->get($sessionVar.'.card'));

    return $view->render($response, 'request.twig', array(
        'gateway' => $gateway,
        'method' => 'updateCard',
        'params' => $params,
        'card' => $card->getParameters(),
    ));
});

$app->post('/gateways/{name}/delete-card', function ($request, $response, $args) use ($session) {
    $name = $args['name'];
    $view = Twig::fromRequest($request);

    $gateway = Omnipay::create($name);
    $sessionVar = 'omnipay.'.$gateway->getShortName();
    $gateway->initialize((array) $session->get($sessionVar));

    $params = $request->getParsedBody()['params'];
    $card = $request->getParsedBody()['card'];

    $session->set($sessionVar.'.delete', $params);
    $session->set($sessionVar.'.card', $card);

    $params['card'] = $card;
    $params['clientIp'] = $request->getServerParams()['REMOTE_ADDR'];

    $gateway_response = $gateway->deleteCard($params)->send();

    return $view->render($response, 'response.twig', array(
        'gateway' => $gateway,
        'response' => $gateway_response,
    ));
});





$app->get('/hi/{name}', function ($request, $response, $args) {
    $view = Twig::fromRequest($request);
    $str = $view->fetchFromString(
        '<p>Hi, my name is {{ name }}.</p>',
        [
            'name' => $args['name']
        ]
    );
    $response->getBody()->write($str);
    return $response;
});

$app->run();