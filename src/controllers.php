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

$app->mount('/login/redirect', new App\Controller\LoginRedirect());

$app->get('/', function() use ($app) {
    return $app->redirect('/home');
});

$app->get('/home', function() use ($app) {
    $token = $app['security']->getToken();
    
    $sql = "SELECT o.id, o.created_at AS abertura, ip.quantidade, ip.defeito, ";
    $sql .= "sip.nome AS status_nome, p.produto FROM itens_pedido ip INNER JOIN status_item_pedido sip ON ip.status = sip.id ";
    $sql .= "INNER JOIN produtos p ON ip.id_produto = p.id INNER JOIN pedidos o ON o.id = ip.id_pedido WHERE o.id_cliente = ? ";
    $sql .= "ORDER BY o.created_at DESC, ip.id DESC";
    $pedidos = $app['db']->fetchAll($sql, array((int)getUserId($app)));

    $orders = array();
    $idPedido = -1;

    foreach ($pedidos as $pedido) {
        if($idPedido == -1) 
            $idPedido = $pedido['id'];

        $idPedido = ($idPedido <> $pedido['id'])?$pedido['id']:$idPedido;
        $orders[$idPedido]['itens'][] = array_intersect_key($pedido, array_flip(array('quantidade', 'defeito', 'status_nome', 'produto')));
        $orders[$idPedido]['abertura'] = $pedido['abertura'];
    }

    $nomeUsuario = $app['db']->fetchColumn("SELECT nome FROM clientes WHERE email = ?", array((string)$token->getUser()->getUsername()), 0);

    //return print_r($orders, true);
    return $app['twig']->render('home.html', array('pedidos' => $orders, 'nomeUsuario' => $nomeUsuario));
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

    if(empty($idPedido)) { //se o pedido ainda n�o existir, eh criado um novo
        $token = $app['security']->getToken();
        $idCliente = getUserId($app);
        $app['db']->insert('pedidos', array('id_cliente' => $idCliente));
        $idPedido = $app['db']->lastInsertId();
    }

    $produto = iconv('UTF-8', 'ISO-8859-15//TRANSLIT', trim($request->get('produto')));
    $descProduto = iconv('UTF-8', 'ISO-8859-15//TRANSLIT', trim($request->get('descricao-produto')));
    $numSerieProduto = trim($request->get('numero-serie-produto'));
    $modeloProduto = iconv('UTF-8', 'ISO-8859-15//TRANSLIT', trim($request->get('modelo-produto')));
    $tipoProduto = intval($request->get('tipo-produto'));

    $quantidade = $request->get('quantidade');
    $defeito = iconv('UTF-8', 'ISO-8859-15//TRANSLIT', trim($request->get('defeito')));
    //$garantia = $request->get('garantia');

    //inserindo novo produto
    $app['db']->insert('produtos', array('produto' => $produto, 'descricao' => $descProduto, 'numero_serie' => $numSerieProduto, 'modelo' => $modeloProduto, 'tipo_produto' => $tipoProduto));
    $idProduto = $app['db']->lastInsertId(); //id do ultimo produto cadastrado

    $status = $app['db']->fetchColumn("SELECT id FROM status_item_pedido WHERE nome = ?", array((string)"Aguardando atendimento"), 0);
    $app['db']->insert('itens_pedido', array('quantidade' => $quantidade, 'defeito' => $defeito, 'id_produto' => $idProduto, 'id_pedido' => $idPedido, 'status' => $status));

    return new Response($idPedido, 201);

});

$app->get('/itens-chamado/{idPedido}', function(Request $request, $idPedido) use ($app) {
    $sql = "SELECT ip.id AS cod_item, ip.quantidade, ip.valor, ip.defeito, ip.valor_maodeobra, ip.prazo_entrega, ip.garantia, ip.fatura, ip.recebido_por, ";
    $sql .= "sip.nome AS status_nome, p.produto, ip.created_at FROM itens_pedido ip INNER JOIN status_item_pedido sip ON ip.status = sip.id INNER JOIN produtos p ON ";
    $sql .= "ip.id_produto = p.id INNER JOIN pedidos o ON o.id = ip.id_pedido WHERE ip.id_pedido = ? AND o.id_cliente = ? ORDER BY ip.created_at DESC";

    $itensPedido = $app['db']->fetchAll($sql, array((int)$idPedido, (int)getUserId($app)));

    return $app['twig']->render('itens-pedido.html', array(
        'itens_pedido' => $itensPedido,
    ));
});

$app->get('/visualizar-item/{id}', function(Request $request, $id) use ($app) {

    $sql = "SELECT ip.quantidade, IF(ip.valor IS NULL, '', ip.valor * ip.quantidade) AS valor_total_item, ";
    $sql .= "ip.defeito, IF(ip.valor_maodeobra IS NULL, '', ip.valor_maodeobra) AS maodeobra, ";
    $sql .= "ip.prazo_entrega, IF(ip.garantia IS NULL, '', ip.garantia) AS garantia, ";
    $sql .= "IF(ip.fatura IS NULL, '', ip.fatura) AS fatura, IF(ip.recebido_por IS NULL, '', ip.recebido_por) AS recebido_por, ";
    $sql .= "IF(ip.chamado IS NULL, '', ip.chamado) AS chamado, s.nome AS status, p.produto, tp.tipo AS tipo_produto ";
    $sql .= "FROM itens_pedido ip INNER JOIN status_item_pedido s ON ip.status = s.id INNER JOIN produtos p ON ip.id_produto = p.id ";
    $sql .= "INNER JOIN tipo_produto tp ON p.tipo_produto = tp.id INNER JOIN pedidos o ON o.id = ip.id_pedido WHERE ip.id = ? AND o.id_cliente = ?";

    $item = $app['db']->fetchAssoc($sql, array((int)$id, (int)getUserId($app)));

    return $app['twig']->render('item-pedido.html', array(
        'item' => $item,
    ));

});

$app->get('/visualizar-chamado/{id}', function(Request $request, $id) use ($app) {

    $sql = "SELECT * FROM pedidos WHERE id = ?";
    $pedido = $app['db']->fetchAssoc($sql, array((int)$id));

    $sql = "SELECT ip.id AS id_item, ip.quantidade, ip.valor, ip.valor_maodeobra, (ip.quantidade * ip.valor + ip.valor_maodeobra) AS valor_total, ip.garantia, ip.fatura, ip.recebido_por, ip.chamado, ip.created_at, s.nome AS status, p.* FROM itens_pedido ip INNER JOIN produtos p ON ip.id_produto = p.id INNER JOIN status_item_pedido s ON ip.status = s.id INNER JOIN pedidos o ON o.id = ip.id_pedido WHERE ip.id_pedido = ? AND o.id_cliente = ?";
    $itens_pedido = $app['db']->fetchAll($sql, array((int)$id, (int)getUserId($app)));

    return $app['twig']->render('pedido.html', array(
        'pedido_id' => $id,
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
            'choices' => array("AC"=>"Acre", "AL"=>"Alagoas", "AM"=>"Amazonas", "AP"=>"Amap�","BA"=>"Bahia","CE"=>"Cear�","DF"=>"Distrito Federal","ES"=>"Esp�rito Santo","GO"=>"Goi�s","MA"=>"Maranh�o","MT"=>"Mato Grosso","MS"=>"Mato Grosso do Sul","MG"=>"Minas Gerais","PA"=>"Par�","PB"=>"Para�ba","PR"=>"Paran�","PE"=>"Pernambuco","PI"=>"Piau�","RJ"=>"Rio de Janeiro","RN"=>"Rio Grande do Norte","RO"=>"Rond�nia","RS"=>"Rio Grande do Sul","RR"=>"Roraima","SC"=>"Santa Catarina","SE"=>"Sergipe","SP"=>"S�o Paulo","TO"=>"Tocantins")
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

$app->match('/alterar-dados', function (Request $request) use ($app) {

    $sql = "SELECT id, bairro, cep, cidade, email, endereco, estado, nome, telefone FROM clientes WHERE id = ?";
    $cliente = $app['db']->fetchAssoc($sql, array((int)getUserId($app)));

    $form = $app['form.factory']->createBuilder('form', $cliente)
        ->add('id', 'hidden')
        ->add('nome')
        ->add('email', 'email')
        ->add('senha_atual', 'password')
        ->add('nova_senha', 'password')
        ->add('confirmar_senha', 'password')
        ->add('telefone')
        ->add('endereco')
        ->add('bairro')
        ->add('cidade')
        ->add('estado', ChoiceType::class, array(
            'choices' => array("AC"=>"Acre", "AL"=>"Alagoas", "AM"=>"Amazonas", "AP"=>"Amap�","BA"=>"Bahia","CE"=>"Cear�","DF"=>"Distrito Federal","ES"=>"Esp�rito Santo","GO"=>"Goi�s","MA"=>"Maranh�o","MT"=>"Mato Grosso","MS"=>"Mato Grosso do Sul","MG"=>"Minas Gerais","PA"=>"Par�","PB"=>"Para�ba","PR"=>"Paran�","PE"=>"Pernambuco","PI"=>"Piau�","RJ"=>"Rio de Janeiro","RN"=>"Rio Grande do Norte","RO"=>"Rond�nia","RS"=>"Rio Grande do Sul","RR"=>"Roraima","SC"=>"Santa Catarina","SE"=>"Sergipe","SP"=>"S�o Paulo","TO"=>"Tocantins")
        ))
        ->add('cep')
        ->getForm();

    #$form->handleRequest($request);
    if ($request->isMethod('POST')) {
        $form->bind($request);
        if ($form->isValid()) {
            $data = $form->getData();

            $user = new AdvancedUser(trim($data['email']), trim($data['senha_atual']));
            $encoder = $app['security.encoder_factory']->getEncoder($user);
            $encodedPassword = $encoder->encodePassword(trim($data['senha_atual']), $user->getSalt());

            $sql = "SELECT * FROM clientes WHERE senha = ? AND id = ?";
            $numRows = $app['db']->executeQuery($sql, array((string)$encodedPassword, (int)getUserId($app)))->rowCount();

            //$app['monolog']->addDebug($encodedPassword . ", " . getUserId($app) . ", " . $numRows);

            $mensagem = "";

            if($numRows != 1)
                $mensagem = 'A senha atual n�o confere!';    
            else if(trim($data['confirmar_senha']) != trim($data['nova_senha']))
                $mensagem = 'A nova senha � diferente da confirma��o da nova senha!';

            if($mensagem <> "") {
                $app['session']->getFlashBag()->add('error', $mensagem);
                return $app['twig']->render('form-bootstrap.html', array('form' => $form->createView(), 'titulo' => 'Edite seus dados'));
            }

            $newUser = new AdvancedUser(trim($data['email']), trim($data['nova_senha']));
            $encoderNewUser = $app['security.encoder_factory']->getEncoder($newUser);
            $newEncodedPassword = $encoder->encodePassword(trim($data['nova_senha']), $newUser->getSalt());

            $app['db']->update('clientes', array(
                'telefone' => trim($data['telefone']), 'senha' => $newEncodedPassword,
                'endereco' => trim($data['endereco']), 'nome' => trim($data['nome']), 'email' => trim($data['email']),
                'bairro' => trim($data['bairro']), 'estado' => trim($data['estado']), 'cep' => trim($data['cep']), 'cidade' => trim($data['cidade'])), array('id' => trim($data['id']), 'senha' => $encodedPassword));
            
            $app['session']->getFlashBag()->add('message', 'Seus dados foram atualizados com sucesso!');

        }
    }

    // display the form
    return $app['twig']->render('form-bootstrap.html', array('form' => $form->createView(), 'titulo' => 'Edite seus dados'));
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

//fun��es auxiliares

function getUserId($app) {
    $token = $app['security']->getToken();
    return $app['db']->fetchColumn("SELECT id FROM clientes WHERE email = ?", array((string)$token->getUser()->getUsername()), 0);
}