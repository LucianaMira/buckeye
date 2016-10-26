<?php

$app['debug'] = true;
$app['charset'] = "iso-8859-1";

$app->register(new Silex\Provider\DoctrineServiceProvider(), array(
    'db.options' => array(
            'driver'    => 'pdo_mysql',
	        'host'      => '',
	        'dbname'    => '',
	        'user'      => '',
	        'password'  => '',
        ),
));

$app->register(new Silex\Provider\SessionServiceProvider());

$app->register(new Silex\Provider\SecurityServiceProvider(), array(
        'security.firewalls' => array(
            'login_path' => array(
                'pattern' => '^/login$',
                'anonymous' => true
            ),
            'new_client_path' => array(
                'pattern' => '^/registrar-cliente$',
                'anonymous' => true
            ),
            'default' => array(
                'pattern' => '^/.*$',
                'anonymous' => false,
                'form' => array(
                    'login_path' => '/login',
                    'check_path' => '/login_check',
                    'always_use_default_target_path' => true,
                    'default_target_path' => '/login/redirect'
                ),
                'logout' => array(
                    'logout_path' => '/logout',
                    'invalidate_session' => false
                ),
                'users' => $app->share(function($app) { 
                    return new App\User\CustomerUserProvider($app['db']); 
                }),
            )
        ),
        'security.access_rules' => array(
            array('^/login$', 'IS_AUTHENTICATED_ANONYMOUSLY'),
            array('^/registrar-cliente$', 'IS_AUTHENTICATED_ANONYMOUSLY'),
            array('^/recuperar-senha$', 'IS_AUTHENTICATED_ANONYMOUSLY'),
            array('^/.*$', 'ROLE_CUSTOMER')
        )
    ));

$app->register(new Silex\Provider\RememberMeServiceProvider());

$app->register(new Silex\Provider\TwigServiceProvider(), array(
    'twig.path' => __DIR__ . '/views',
    'twig.options'=>array(
        'cache'     => __DIR__.'/../cache',
    ),
    'twig.form.templates' => array(
        'form_div_layout.html.twig', 
        'theme/form_div_layout.twig'
    ),
));

$app->register(new Silex\Provider\UrlGeneratorServiceProvider());

$app->register(new Silex\Provider\MonologServiceProvider(), array(
    'monolog.logfile' => sys_get_temp_dir() . '/buckeye.log',
    'monolog.level' => Monolog\Logger::DEBUG,
    'monolog.name' => 'buckeye'
));

$app->register(new Silex\Provider\FormServiceProvider());

$app->register(new Silex\Provider\TranslationServiceProvider(), array(
	'locale' => 'sr_Latn',
    'translator.domains' => array(),
));

return $app;
