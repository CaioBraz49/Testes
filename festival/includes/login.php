<?php 
// Redireciona usuários já logados
if (isset($_SESSION['user_id'])) {
    $redirectPath = ($_SESSION['user_tipo'] == 'admin' ? 'admin/index.php' : 'avaliador/index.php');
    header('Location: ' . $redirectPath);
    exit();
}

// Gera token CSRF se não existir
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Conexão ao banco de dados
include(__DIR__ . '/config.php');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acesso ao Sistema - Festival de Vídeos de Matemática</title>
    <!-- Inclua seus CSS aqui -->
    <link rel="stylesheet" href="includes/estilo.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
</head>
<body>
<header></header>
<div class="content-wrapper">
<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">
            <!-- Mostrar mensagens de erro/sucesso -->
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($_SESSION['error'], ENT_QUOTES, 'UTF-8'); ?>
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <?php unset($_SESSION['error']); ?>
            <?php elseif (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($_SESSION['success'], ENT_QUOTES, 'UTF-8'); ?>
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <?php unset($_SESSION['success']); ?>
            <?php endif; ?>

            <div class="card shadow-sm">
                <div class="card-header text-white card-header-custom-purple">
                    <h4 class="mb-0 text-center"><i class="fas fa-sign-in-alt mr-2"></i>Acesso ao Sistema</h4>
                </div>
                <div class="card-body">
                    <form action="includes/auth.php" method="post" id="loginForm">
                        <div class="form-group">
                            <label for="email"><i class="fas fa-envelope mr-1"></i> Email</label>
                            <input type="email" class="form-control" id="email" name="email" 
                                   required autofocus autocomplete="username"
                                   placeholder="Digite seu e-mail cadastrado">
                        </div>
                        <div class="form-group">
                            <label for="senha"><i class="fas fa-lock mr-1"></i> Senha</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="senha" name="senha" 
                                       required autocomplete="current-password"
                                       placeholder="Digite sua senha">
                                <div class="input-group-append">
                                    <button class="btn btn-outline-secondary" type="button" id="togglePassword">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                            <small class="form-text text-right">
                                    <a href="javascript:void(0);" onclick="alert('Função de recuperação de senha temporariamente desabilitada. Por favor, entre em contato com o administrador.');">
                                        Esqueceu sua senha?
                                    </a>
                            </small>
                        </div>
                        
                        <!-- Proteção CSRF -->
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                        
                        <button type="submit" class="btn btn-block py-2 btn-custom-purple">
                            <i class="fas fa-sign-in-alt mr-2"></i> Entrar
                        </button>
                    </form>
                </div>
                <div class="card-footer text-center bg-light">
                    <small class="text-muted">© <?php echo date('Y'); ?> Festival de Vídeos de Matemática</small>
                </div>
            </div>
        </div>
    </div>
</div>
</div>
<!-- Inclua seus scripts JS aqui -->
<script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>

<script>
// Mostrar/esconder senha
document.getElementById('togglePassword').addEventListener('click', function() {
    const passwordInput = document.getElementById('senha');
    const icon = this.querySelector('i');
    if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        icon.classList.replace('fa-eye', 'fa-eye-slash');
    } else {
        passwordInput.type = 'password';
        icon.classList.replace('fa-eye-slash', 'fa-eye');
    }
});

// Validação básica do formulário antes do envio
document.getElementById('loginForm').addEventListener('submit', function(e) {
    const email = document.getElementById('email').value;
    if (!email.includes('@') || !email.includes('.')) {
        alert('Por favor, insira um endereço de e-mail válido.');
        e.preventDefault();
    }
});
</script>

<!-- Footer -->
<?php include(__DIR__ . '/footer.php'); ?>

</body>
</html>