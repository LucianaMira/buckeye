<?php

date_default_timezone_set("America/Sao_Paulo");

require_once __DIR__.'/../vendor/autoload.php';

$app = new Silex\Application();

require_once __DIR__.'/../src/app.php';

require_once __DIR__.'/../src/controllers.php';

$app->run();
