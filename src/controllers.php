<?php

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Silex\Provider\FormServiceProvider;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Security\Core\User\User as AdvancedUser;

$app->before(function () use ($app) {
    $app['translator']->addLoader('xlf', new Symfony\Component\Translation\Loader\XliffFileLoader());
    $app['translator']->addResource('xlf', __DIR__.'/vendor/symfony/validator/Symfony/Component/Validator/Resources/translations/validators/validators.sr_Latn.xlf', 'sr_Latn', 'validators');
});

$app->get('/', function() use ($app) {
    return $app->redirect('/home');
});

$app->get('/home', function() use ($app) {
    $token = $app['security']->getToken();
    //$sql = "SELECT p.id, p.created_at, it.num_pedidos FROM pedidos p INNER JOIN (SELECT COUNT(id_pedido) , id FROM itens_pedido GROUP BY id_pedido ORDER BY id) it ON p.id = it.id WHERE p.id_cliente = (SELECT id_cliente FROM clientes WHERE email = ?) ORDER BY created_at DESC";
    $sql = "SELECT id, created_at FROM pedidos WHERE id_cliente = (SELECT id FROM clientes WHERE email = ?) ORDER BY created_at DESC";
    $pedidos = $app['db']->fetchAll($sql, array((string)$token->getUser()->getUsername()));

    $nomeUsuario = $app['db']->fetchColumn("SELECT nome FROM clientes WHERE email = ?", array((string)$token->getUser()->getUsername()), 0);

    //return print_r($pedidos, true);
    return $app['twig']->render('home.html', array('pedidos' => $pedidos, 'nomeUsuario' => $nomeUsuario));
})
->bind('home');

$app->get('/interno', function(Request $request) use ($app) {
    return $app['twig']->render('login.html', array(
        'error' => $app['security.last_error']($request),
        'last_username' => $app['session']->get('_security.last_username'),
    ));
});

$login = function(Request $request) use ($app) {
    return $app['twig']->render('login_.html', array(
        'error' => $app['security.last_error']($request),
        'last_username' => $app['session']->get('_security.last_username'),
    ));
};

$app->get('/login', $login);

$app->match('/novo-chamado', function(Request $request) use ($app) {

    $tipos_produto = $app['db']->fetchAll("SELECT * FROM tipo_produto");

    return $app['twig']->render('novo-chamado.html', array(
        'tipos_produto' => $tipos_produto
    ));

});

$app->post('/insere-item', function(Request $request) use ($app) {

    $idPedido = intval($request->get('id_pedido'));

    if(empty($idPedido)) {
        $token = $app['security']->getToken();
        $idCliente = $app['db']->fetchColumn("SELECT id FROM clientes WHERE email = ?", array((string)$token->getUser()->getUsername()), 0);
        $app['db']->insert('pedidos', array('id_cliente' => $idCliente));
        $idPedido = $app['db']->lastInsertId();
    }

    $produto = trim($request->get('produto'));
    $descProduto = trim($request->get('descricao-produto'));
    $numSerieProduto = trim($request->get('numero-serie-produto'));
    $modeloProduto = trim($request->get('modelo-produto'));
    $tipoProduto = intval($request->get('tipo-produto'));

    $quantidade = $request->get('quantidade');
    $defeito = $request->get('defeito');
    //$garantia = $request->get('garantia');

    //inserindo novo produto
    $app['db']->insert('produtos', array('produto' => $produto, 'descricao' => $descProduto, 'numero_serie' => $numSerieProduto, 'modelo' => $modeloProduto, 'tipo_produto' => $tipoProduto));
    $idProduto = $app['db']->lastInsertId(); //id do ultimo produto cadastrado

    $status = $app['db']->fetchColumn("SELECT id FROM status_item_pedido WHERE nome = ?", array((string)"Aguardando atendimento"), 0);
    $app['db']->insert('itens_pedido', array('quantidade' => $quantidade, 'defeito' => $defeito, 'id_produto' => $idProduto, 'id_pedido' => $idPedido, 'status' => $status));

    return new Response($idPedido, 201);

});

$app->get('/itens-pedido/{idPedido}', function(Request $request, $idPedido) use ($app) {
    $sql = "SELECT ip.id, ip.quantidade, ip.valor, ip.defeito, ip.valor_maodeobra, ip.prazo_entrega, ip.garantia, ip.fatura, ip.recebido_por, ";
    $sql .= "sip.nome AS status_nome, p.produto, ip.created_at FROM itens_pedido ip INNER JOIN status_item_pedido sip ON ip.status = sip.id INNER JOIN produtos p ON ";
    $sql .= "ip.id_produto = p.id WHERE ip.id_pedido = ? ORDER BY ip.created_at DESC";

    $itensPedido = $app['db']->fetchAll($sql, array((int)$idPedido));

    return $app['twig']->render('itens-pedido.html', array(
        'itens_pedido' => $itensPedido,
    ));
});

$app->mount('/login/redirect', new App\Controller\LoginRedirect());

$app->get('/visualizar-pedido/{id}', function(Request $request, $id) use ($app) {

    $sql = "SELECT * FROM pedidos WHERE id = ?";
    $pedido = $app['db']->fetchAssoc($sql, array((int)$id));

    $sql = "SELECT ip.quantidade, ip.valor, ip.valor_maodeobra, (ip.quantidade * ip.valor + ip.valor_maodeobra) AS valor_total, ip.garantia, ip.fatura, ip.recebido_por, ip.chamado, ip.created_at, s.nome AS status, p.* FROM itens_pedido ip INNER JOIN produtos p ON ip.id_produto = p.id INNER JOIN status_item_pedido s ON ip.status = s.id WHERE ip.id_pedido = ?";
    $itens_pedido = $app['db']->fetchAll($sql, array((int)$id));

    return $app['twig']->render('pedido.html', array(
        'pedido' => $pedido,
        'itens_pedido' => $itens_pedido,
    ));
});

//$app->get('/interno/login', $login);

/*$app->match('/interno/registrar-usuario', function (Request $request) use ($app) {

    $form = $app['form.factory']->createBuilder('form')
        ->add('nome')
        ->add('login')
        ->add('email', 'email')
        ->add('senha', 'password')
        ->add('tipo_usuario', 'choice', array(
        	'choices' => array(1 => 'Operador', 2 => 'Administrador'),
            'expanded' => true,
        ))
        ->getForm();

    #$form->handleRequest($request);
	if ($request->isMethod('POST')) {
		$form->bind($request);
    	if ($form->isValid()) {
        	$data = $form->getData();
			$user = new AdvancedUser($data['login'], $data['senha']);
			$encoder = $app['security.encoder_factory']->getEncoder($user);
			$encodedPassword = $encoder->encodePassword($data['senha'], $user->getSalt());
			$app['db']->insert('usuarios', array(
				'login' => $data['login'], 'senha' => $encodedPassword,
				'tipo_usuario' => $data['tipo_usuario'], 'nome' => $data['nome'], 'email' => $data['email']));
			
			$message = 'Salvo!';
		}
    }

    // display the form
    return $app['twig']->render('form.html', array('form' => $form->createView()));
});*/

$app->match('/registrar-cliente', function (Request $request) use ($app) {

    $form = $app['form.factory']->createBuilder('form')
        ->add('nome')
        ->add('email', 'email')
        ->add('senha', 'password')
        ->add('telefone')
        ->add('endereco')
        ->add('bairro')
        ->add('cidade')
        ->add('estado', ChoiceType::class, array(
            'choices' => array("AC"=>"Acre", "AL"=>"Alagoas", "AM"=>"Amazonas", "AP"=>"Amapá","BA"=>"Bahia","CE"=>"Ceará","DF"=>"Distrito Federal","ES"=>"Espírito Santo","GO"=>"Goiás","MA"=>"Maranhão","MT"=>"Mato Grosso","MS"=>"Mato Grosso do Sul","MG"=>"Minas Gerais","PA"=>"Pará","PB"=>"Paraíba","PR"=>"Paraná","PE"=>"Pernambuco","PI"=>"Piauí","RJ"=>"Rio de Janeiro","RN"=>"Rio Grande do Norte","RO"=>"Rondônia","RS"=>"Rio Grande do Sul","RR"=>"Roraima","SC"=>"Santa Catarina","SE"=>"Sergipe","SP"=>"São Paulo","TO"=>"Tocantins")
        ))
        ->add('cep')
        ->getForm();

    #$form->handleRequest($request);
    if ($request->isMethod('POST')) {
        $form->bind($request);
        if ($form->isValid()) {
            $data = $form->getData();
            $user = new AdvancedUser($data['email'], $data['senha']);
            $encoder = $app['security.encoder_factory']->getEncoder($user);
            $encodedPassword = $encoder->encodePassword($data['senha'], $user->getSalt());
            $app['db']->insert('clientes', array(
                'telefone' => $data['telefone'], 'senha' => $encodedPassword,
                'endereco' => $data['endereco'], 'nome' => $data['nome'], 'email' => $data['email'],
                'bairro' => $data['bairro'], 'estado' => $data['estado'], 'cep' => $data['cep'], 'cidade' => $data['cidade']));
            
            $app['session']->getFlashBag()->add('message', 'Cliente registrado com sucesso!');

            //return $app->redirect($request->getBasePath() . '/login');
        }
    }

    // display the form
    return $app['twig']->render('form-bootstrap-anon.html', array('form' => $form->createView()));
});

/*$app->match('/interno/registrar-produto', function (Request $request) use ($app) {

    $tipos_produto = $app['db']->fetchAll('SELECT id, tipo FROM tipo_produto');

    $tipos = array();
    foreach($tipos_produto as $tipo_prod)
        $tipos[$tipo_prod['id']] = $tipo_prod['tipo'];

    $form = $app['form.factory']->createBuilder('form')
        ->add('produto')
        ->add('descricao', 'textarea')
        ->add('numero_serie')
        ->add('modelo')
        ->add('tipo', ChoiceType::class, array("choices" => $tipos))
        ->getForm();

    #$form->handleRequest($request);
    if ($request->isMethod('POST')) {
        $form->bind($request);
        if ($form->isValid()) {
            $data = $form->getData();

            $app['db']->insert('produtos', array(
                'produto' => $data['produto'], 'descricao' => $data['descricao'],
                'numero_serie' => $data['numero_serie'], 'modelo' => $data['modelo'], 'tipo_produto' => $data['tipo']));
            
            $message = 'Salvo!';
        }
    }

    // display the form
    return $app['twig']->render('form.html', array('form' => $form->createView()));
});*/

